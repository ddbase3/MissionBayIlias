<?php declare(strict_types=1);

namespace MissionBayIlias\Provider;

use MissionBayIlias\Api\IContentProvider;
use MissionBayIlias\Api\ContentCursor;
use MissionBayIlias\Api\ContentBatch;
use MissionBayIlias\Api\ContentUnit;
use Base3\Database\Api\IDatabase;

final class WikiContentProvider implements IContentProvider {

	private const SOURCE_SYSTEM = 'ilias';
	private const SOURCE_KIND = 'wiki';

	public function __construct(
		private readonly IDatabase $db
	) {}

	public static function getName(): string {
		return 'wikicontentprovider';
	}

	public function isActive() {
		return true;
	}

	public function getSourceSystem(): string {
		return self::SOURCE_SYSTEM;
	}

	public function getSourceKind(): string {
		return self::SOURCE_KIND;
	}

	public function fetchChanged(ContentCursor $cursor, int $limit): ContentBatch {
		$limit = max(1, (int)$limit);

		$rows = $this->queryAll(
			"SELECT
				o.obj_id,
				o.title,
				o.description,
				o.last_update,
				o.create_date,
				w.introduction
			FROM object_data o
			LEFT JOIN il_wiki_data w ON w.id = o.obj_id
			WHERE o.type = 'wiki'
				AND o.last_update IS NOT NULL
				AND (
					o.last_update > '" . $this->esc($cursor->changedAt) . "'
					OR (o.last_update = '" . $this->esc($cursor->changedAt) . "' AND o.obj_id > " . (int)$cursor->changedId . ")
				)
			ORDER BY o.last_update ASC, o.obj_id ASC
			LIMIT " . $limit
		);

		if (!$rows) {
			return new ContentBatch([], $cursor);
		}

		$units = [];
		$maxTs = $cursor->changedAt;
		$maxId = $cursor->changedId;

		foreach ($rows as $row) {
			$objId = (int)($row['obj_id'] ?? 0);
			$lastUpdate = (string)($row['last_update'] ?? '');

			if ($objId <= 0 || $lastUpdate === '') {
				continue;
			}

			$title = $this->nullIfEmpty((string)($row['title'] ?? ''));
			$intro = $this->nullIfEmpty((string)($row['introduction'] ?? ''));
			$desc = $this->nullIfEmpty((string)($row['description'] ?? ''));

			$description = $intro !== null ? $intro : $desc;

			$locator = 'wiki:' . (string)$objId;

			$units[] = new ContentUnit(
				self::SOURCE_SYSTEM,
				self::SOURCE_KIND,
				$locator,
				$objId,
				$objId,
				$title,
				$description,
				$lastUpdate,
				null
			);

			if ($lastUpdate > $maxTs) {
				$maxTs = $lastUpdate;
				$maxId = $objId;
			} else if ($lastUpdate === $maxTs && $objId > $maxId) {
				$maxId = $objId;
			}
		}

		$nextCursor = new ContentCursor($maxTs, $maxId);

		return new ContentBatch($units, $nextCursor);
	}

	public function fetchMissingSourceIntIds(int $limit): array {
		$limit = max(1, (int)$limit);

		$rows = $this->queryAll(
			"SELECT
				s.source_int_id
			FROM base3_embedding_seen s
			LEFT JOIN object_data o
				ON o.obj_id = s.source_int_id
				AND o.type = 'wiki'
			WHERE s.source_system = '" . $this->esc(self::SOURCE_SYSTEM) . "'
				AND s.source_kind = '" . $this->esc(self::SOURCE_KIND) . "'
				AND s.source_int_id IS NOT NULL
				AND s.missing_since IS NULL
				AND s.deleted_at IS NULL
				AND o.obj_id IS NULL
			LIMIT " . $limit
		);

		$ids = [];
		foreach ($rows as $row) {
			$id = (int)($row['source_int_id'] ?? 0);
			if ($id > 0) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	public function fetchContent(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): array {
		$objId = $containerObjId !== null ? (int)$containerObjId : (int)($sourceIntId ?? 0);
		if ($objId <= 0) {
			return [];
		}

		$rows = $this->queryAll(
			"SELECT
				o.obj_id,
				o.title,
				o.description,
				o.last_update,
				o.create_date,
				o.owner,
				o.offline,
				o.import_id,
				w.startpage,
				w.introduction,
				w.is_online
			FROM object_data o
			LEFT JOIN il_wiki_data w ON w.id = o.obj_id
			WHERE o.obj_id = " . (int)$objId . "
				AND o.type = 'wiki'
			LIMIT 1"
		);

		$r = $rows[0] ?? null;
		if (!$r) {
			return [];
		}

		$title = $this->nullIfEmpty((string)($r['title'] ?? ''));
		$desc = $this->nullIfEmpty((string)($r['description'] ?? ''));
		$intro = $this->nullIfEmpty((string)($r['introduction'] ?? ''));

		return [
			'type' => 'wiki',
			'obj_id' => (int)($r['obj_id'] ?? $objId),
			'source_locator' => $sourceLocator,
			'title' => $title,
			'description' => $desc,
			'content' => $intro ?? '',
			'meta' => [
				'startpage' => $this->nullIfEmpty((string)($r['startpage'] ?? '')),
				'is_online' => isset($r['is_online']) ? (int)$r['is_online'] : null,
				'last_update' => $this->nullIfEmpty((string)($r['last_update'] ?? '')),
				'create_date' => $this->nullIfEmpty((string)($r['create_date'] ?? '')),
				'owner' => isset($r['owner']) ? (int)$r['owner'] : null,
				'offline' => isset($r['offline']) ? (int)$r['offline'] : null,
				'import_id' => $this->nullIfEmpty((string)($r['import_id'] ?? '')),
			]
		];
	}

	public function fetchReadRoles(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): array {
		$objId = $containerObjId !== null ? (int)$containerObjId : (int)($sourceIntId ?? 0);
		if ($objId <= 0) {
			return [];
		}

		// ILIAS RBAC is ref_id based; object can be linked multiple times => merge roles across all refs.
		$refIds = \ilObject::_getAllReferences($objId);
		if (!$refIds) {
			return [];
		}

		global $DIC;
		$review = $DIC->rbac()->review();

		$readOpsId = $this->getReadOpsIdForType('wiki');
		if ($readOpsId <= 0) {
			return [];
		}

		$roleIds = [];

		foreach ($refIds as $refId) {
			$refId = (int)$refId;
			if ($refId <= 0) {
				continue;
			}

			$roles = $review->getParentRoleIds($refId);
			foreach ($roles as $r) {
				$rolId = (int)($r['rol_id'] ?? 0);
				if ($rolId <= 0) {
					continue;
				}

				$ops = $review->getActiveOperationsOfRole($refId, $rolId);
				if ($ops && in_array($readOpsId, $ops, true)) {
					$roleIds[$rolId] = true;
				}
			}
		}

		return array_keys($roleIds);
	}

	/* ---------- Helpers ---------- */

	private function getReadOpsIdForType(string $type): int {
		static $cache = [];

		$type = trim($type);
		if ($type === '') {
			return 0;
		}

		if (isset($cache[$type])) {
			return (int)$cache[$type];
		}

		$readOpsId = 0;

		// Operation list is per object type (e.g. 'wiki').
		$ops = \ilRbacReview::_getOperationList($type);
		foreach ($ops as $op) {
			if (($op['operation'] ?? '') === 'read') {
				$readOpsId = (int)($op['ops_id'] ?? 0);
				break;
			}
		}

		$cache[$type] = $readOpsId;
		return $readOpsId;
	}

	private function nullIfEmpty(string $v): ?string {
		$v = trim($v);
		return $v !== '' ? $v : null;
	}

	private function queryAll(string $sql): array {
		return $this->db->multiQuery($sql) ?: [];
	}

	private function esc(string $value): string {
		return (string)$this->db->escape($value);
	}
}
