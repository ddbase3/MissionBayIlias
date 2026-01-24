<?php declare(strict_types=1);

namespace MissionBayIlias\Provider;

use Base3\Database\Api\IDatabase;
use MissionBayIlias\Api\IContentProvider;
use MissionBayIlias\Api\IObjectTreeResolver;
use MissionBayIlias\Dto\ContentBatchDto;
use MissionBayIlias\Dto\ContentCursorDto;
use MissionBayIlias\Dto\ContentUnitDto;

final class CategoryContentProvider implements IContentProvider {

	private const SOURCE_SYSTEM = 'ilias';
	private const SOURCE_KIND = 'cat';

	private const PARENT_TYPE = 'cat';

	public function __construct(
		private readonly IDatabase $db,
		private readonly IObjectTreeResolver $objectTreeResolver
	) {}

	public static function getName(): string {
		return 'categorycontentprovider';
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
				p.page_id,
				p.parent_id,
				p.last_change,
				p.render_md5,
				p.rendered_content
			FROM object_data o
			LEFT JOIN page_object p
				ON p.page_id = o.obj_id
				AND p.parent_id = o.obj_id
				AND p.parent_type = '" . $this->esc(self::PARENT_TYPE) . "'
			WHERE o.type = '" . $this->esc(self::SOURCE_KIND) . "'
			  AND o.last_update IS NOT NULL
			  AND (
					o.last_update > '" . $this->esc($cursor->changedAt) . "'
					OR (
						o.last_update = '" . $this->esc($cursor->changedAt) . "'
						AND o.obj_id > " . (int)$cursor->changedId . "
					)
			  )
			ORDER BY o.last_update ASC, o.obj_id ASC
			LIMIT " . $limit
		);

		if (!$rows) {
			return new ContentBatchDto([], $cursor);
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

			$title = trim((string)($row['title'] ?? ''));
			$desc = trim((string)($row['description'] ?? ''));

			// Prefer rendered page title if present (some installs keep category "page" titles there)
			$rendered = (string)($row['rendered_content'] ?? '');
			$pageTitle = $this->extractPageTitle($rendered);

			$finalTitle = $pageTitle !== null ? $pageTitle : ($title !== '' ? $title : 'Category #' . $objId);
			$description = $desc !== '' ? $desc : null;

			$locator = 'cat:' . $objId;

			// CONTRACT:
			// - containerObjId MUST be the original obj_id from object_data
			// - sourceIntId MUST be the original primary id of this unit (also obj_id for categories)
			$units[] = new ContentUnitDto(
				self::SOURCE_SYSTEM,
				self::SOURCE_KIND,
				$locator,
				$objId,
				$objId,
				$finalTitle,
				$description,
				$lastUpdate,
				$row['render_md5'] ?? null
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
			  AND o.type = '" . $this->esc(self::SOURCE_KIND) . "'
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
		// Prefer the original obj_id (object_data) from containerObjId, then sourceIntId, then locator
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
				o.owner,
				o.offline,
				o.import_id,
				o.create_date,
				o.last_update,
				p.page_id,
				p.parent_id,
				p.parent_type,
				p.last_change,
				p.created,
				p.active,
				p.is_empty,
				p.lang,
				p.render_md5,
				p.rendered_content
			FROM object_data o
			LEFT JOIN page_object p
				ON p.page_id = o.obj_id
				AND p.parent_id = o.obj_id
				AND p.parent_type = '" . $this->esc(self::PARENT_TYPE) . "'
			WHERE o.obj_id = " . (int)$objId . "
			  AND o.type = '" . $this->esc(self::SOURCE_KIND) . "'
			LIMIT 1"
		);

		$r = $rows[0] ?? null;
		if (!$r) {
			return [];
		}

		$rendered = (string)($r['rendered_content'] ?? '');

		// CONTRACT: title MUST be string
		$title = trim((string)($r['title'] ?? ''));
		$pageTitle = $this->extractPageTitle($rendered);
		if ($pageTitle !== null) {
			$title = $pageTitle;
		}
		if ($title === '') {
			$title = 'Category #' . (int)($r['obj_id'] ?? $objId);
		}

		return [
			'type' => 'cat',
			'obj_id' => (int)($r['obj_id'] ?? 0),
			'source_locator' => $sourceLocator,
			'title' => $title,
			'description' => trim((string)($r['description'] ?? '')),
			'content' => $rendered,
			'meta' => [
				'last_update' => trim((string)($r['last_update'] ?? '')),
				'create_date' => trim((string)($r['create_date'] ?? '')),
				'owner' => isset($r['owner']) ? (int)$r['owner'] : null,
				'offline' => isset($r['offline']) ? (int)$r['offline'] : null,
				'import_id' => trim((string)($r['import_id'] ?? '')),
				'page_id' => isset($r['page_id']) ? (int)$r['page_id'] : null,
				'parent_id' => isset($r['parent_id']) ? (int)$r['parent_id'] : null,
				'parent_type' => trim((string)($r['parent_type'] ?? '')),
				'last_change' => trim((string)($r['last_change'] ?? '')),
				'created' => trim((string)($r['created'] ?? '')),
				'active' => isset($r['active']) ? (int)$r['active'] : null,
				'is_empty' => isset($r['is_empty']) ? (int)$r['is_empty'] : null,
				'lang' => trim((string)($r['lang'] ?? '')),
				'render_md5' => trim((string)($r['render_md5'] ?? '')),
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

		$readOpsId = $this->getReadOpsIdForType('cat');
		if ($readOpsId <= 0) {
			// Fallback: some installs might use 'fold' or still resolve cat operations through type mapping
			$readOpsId = $this->getReadOpsIdForType('fold');
		}
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

		$refIds = $this->objectTreeResolver->getRefIdsByObjId($objId);
		if ($refIds === []) {
			return '';
		}

		// Example: goto.php/cat/4599 (ref_id)
		return 'goto.php/cat/' . (int)$refIds[0];
	}

	/* ---------- Helpers ---------- */

	private function extractPageTitle(string $renderedContent): ?string {
		if ($renderedContent === '') {
			return null;
		}

		if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $renderedContent, $m)) {
			$t = trim(strip_tags((string)($m[1] ?? '')));
			return $t !== '' ? $t : null;
		}

		return null;
	}

	private function parseObjIdFromLocator(string $locator): int {
		$p = explode(':', trim($locator));
		if (($p[0] ?? '') === 'cat') {
			return (int)($p[1] ?? 0);
		}
		return 0;
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

	private function queryAll(string $sql): array {
		return $this->db->multiQuery($sql) ?: [];
	}

	private function esc(string $value): string {
		return (string)$this->db->escape($value);
	}
}
