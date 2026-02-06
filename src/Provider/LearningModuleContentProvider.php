<?php declare(strict_types=1);

namespace MissionBayIlias\Provider;

use Base3\Database\Api\IDatabase;
use MissionBayIlias\Api\IObjectTreeResolver;
use MissionBayIlias\Dto\ContentBatchDto;
use MissionBayIlias\Dto\ContentCursorDto;
use MissionBayIlias\Dto\ContentUnitDto;

final class LearningModuleContentProvider extends AbstractContentProvider {

	private const SOURCE_SYSTEM = 'ilias';
	private const SOURCE_KIND = 'lm';

	public function __construct(
		IDatabase $db,
		private readonly IObjectTreeResolver $objectTreeResolver
	) {
		parent::__construct($db);
	}

	public static function getName(): string {
		return 'learningmodulecontentprovider';
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

	public function fetchChanged(ContentCursorDto $cursor, int $limit): ContentBatchDto {
		$limit = max(1, (int)$limit);

		$sinceTs = trim((string)($cursor->changedAt ?? ''));
		$sinceId = (int)($cursor->changedId ?? 0);

		// Provider must be robust even if cursor is empty/invalid.
		if (!$this->isValidTimestamp($sinceTs)) {
			$sinceTs = '1970-01-01 00:00:00';
			$sinceId = 0;
		}
		if ($sinceId < 0) {
			$sinceId = 0;
		}

		$rows = $this->queryAll(
			"SELECT
				o.obj_id,
				o.title,
				o.description,
				o.last_update,
				o.create_date
			FROM object_data o
			WHERE o.type = 'lm'
			  AND o.last_update IS NOT NULL
			  AND (
					o.last_update > '" . $this->esc($sinceTs) . "'
					OR (
						o.last_update = '" . $this->esc($sinceTs) . "'
						AND o.obj_id > " . (int)$sinceId . "
					)
			  )
			ORDER BY o.last_update ASC, o.obj_id ASC
			LIMIT " . $limit
		);

		if (!$rows) {
			return new ContentBatchDto([], new ContentCursorDto($sinceTs, $sinceId));
		}

		$units = [];
		$maxTs = $sinceTs;
		$maxId = $sinceId;

		foreach ($rows as $row) {
			$objId = (int)($row['obj_id'] ?? 0);
			$lastUpdate = trim((string)($row['last_update'] ?? ''));

			if ($objId <= 0 || $lastUpdate === '') {
				continue;
			}

			$title = trim((string)($row['title'] ?? ''));
			$desc  = trim((string)($row['description'] ?? ''));

			$locator = 'lm:' . $objId;

			$units[] = new ContentUnitDto(
				self::SOURCE_SYSTEM,
				self::SOURCE_KIND,
				$locator,
				$objId,
				$objId,
				$title !== '' ? $title : null,
				$desc !== '' ? $desc : null,
				$lastUpdate,
				null
			);

			if ($lastUpdate > $maxTs) {
				$maxTs = $lastUpdate;
				$maxId = $objId;
			} elseif ($lastUpdate === $maxTs && $objId > $maxId) {
				$maxId = $objId;
			}
		}

		return new ContentBatchDto(
			$units,
			new ContentCursorDto($maxTs, $maxId)
		);
	}

	public function fetchMissingSourceIntIds(int $limit): array {
		$limit = max(1, (int)$limit);

		$rows = $this->queryAll(
			"SELECT s.source_int_id
			 FROM base3_embedding_seen s
			 LEFT JOIN object_data o
			   ON o.obj_id = s.source_int_id
			  AND o.type = 'lm'
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
		// Prefer container obj id, then source int id, then locator
		$objId = $containerObjId !== null ? (int)$containerObjId : (int)($sourceIntId ?? 0);
		if ($objId <= 0) {
			$objId = $this->parseObjIdFromLocator($sourceLocator);
		}
		if ($objId <= 0) {
			return [];
		}

		$rows = $this->queryAll(
			"SELECT
				o.obj_id,
				o.title,
				o.description,
				o.last_update,
				o.create_date
			FROM object_data o
			WHERE o.obj_id = " . (int)$objId . "
			  AND o.type = 'lm'
			LIMIT 1"
		);

		$r = $rows[0] ?? null;
		if (!$r) {
			return [];
		}

		// CONTRACT: title MUST be string
		$title = trim((string)($r['title'] ?? ''));

		// Safe ref_id resolution: same strategy as FileContentProvider / CategoryContentProvider.
		$refId = $this->getFirstRefIdByObjId((int)($r['obj_id'] ?? 0));

		return [
			'type' => 'lm',
			'obj_id' => (int)($r['obj_id'] ?? 0),
			'source_locator' => $sourceLocator,
			'title' => $title,
			'description' => trim((string)($r['description'] ?? '')),
			// Learning module content lives in page objects; here: container metadata only.
			'content' => '',
			'meta' => [
				// for direct link (stable/safe like FileContentProvider)
				'ref_id' => $refId > 0 ? $refId : null,

				'last_update' => trim((string)($r['last_update'] ?? '')) ?: null,
				'create_date' => trim((string)($r['create_date'] ?? '')) ?: null,
			]
		];
	}

	public function fetchReadRoles(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): array {
		$objId = $containerObjId !== null ? (int)$containerObjId : (int)($sourceIntId ?? 0);
		if ($objId <= 0) {
			$objId = $this->parseObjIdFromLocator($sourceLocator);
		}
		if ($objId <= 0) {
			return [];
		}

		$refIds = \ilObject::_getAllReferences($objId);
		if (!$refIds) {
			return [];
		}

		global $DIC;
		$review = $DIC->rbac()->review();

		$readOpsId = $this->getReadOpsIdForType('lm');
		if ($readOpsId <= 0) {
			return [];
		}

		$roleIds = [];

		foreach ($refIds as $refId) {
			$refId = (int)$refId;
			if ($refId <= 0) {
				continue;
			}

			foreach ($review->getParentRoleIds($refId) as $r) {
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

	public function getDirectLink(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): string {
		$objId = $containerObjId !== null ? (int)$containerObjId : (int)($sourceIntId ?? 0);
		if ($objId <= 0) {
			$objId = $this->parseObjIdFromLocator($sourceLocator);
		}
		if ($objId <= 0) {
			return '';
		}

		// Safe ref_id resolution: same as FileContentProvider
		$refId = $this->getFirstRefIdByObjId($objId);
		if ($refId <= 0) {
			return '';
		}

		// Same style as FileContentProvider (no dependency on containerObjId being set)
		return 'goto.php/lm/' . $refId;
	}

	/* ---------- Helpers ---------- */

	private function parseObjIdFromLocator(string $locator): int {
		$p = explode(':', trim($locator));
		if (($p[0] ?? '') === 'lm') {
			return (int)($p[1] ?? 0);
		}
		return 0;
	}

	private function getFirstRefIdByObjId(int $objId): int {
		if ($objId <= 0) {
			return 0;
		}

		// Primary path: object tree resolver (same contract used by other providers).
		try {
			$refIds = $this->objectTreeResolver->getRefIdsByObjId($objId);
			$refId = (int)($refIds[0] ?? 0);
			if ($refId > 0) {
				return $refId;
			}
		} catch (\Throwable) {
			// ignore and try fallback
		}

		// Fallback: direct ILIAS API.
		try {
			$refIds = \ilObject::_getAllReferences($objId);
			return (int)($refIds[0] ?? 0);
		} catch (\Throwable) {
			return 0;
		}
	}

	private function isValidTimestamp(string $ts): bool {
		$ts = trim($ts);
		if ($ts === '') {
			return false;
		}
		return (bool)preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $ts);
	}

	private function getReadOpsIdForType(string $type): int {
		static $cache = [];

		if (isset($cache[$type])) {
			return (int)$cache[$type];
		}

		$readOpsId = 0;
		foreach (\ilRbacReview::_getOperationList($type) as $op) {
			if (($op['operation'] ?? '') === 'read') {
				$readOpsId = (int)($op['ops_id'] ?? 0);
				break;
			}
		}

		return $cache[$type] = $readOpsId;
	}
}
