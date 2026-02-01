<?php declare(strict_types=1);

namespace MissionBayIlias\Provider;

use Base3\Database\Api\IDatabase;
use MissionBayIlias\Api\IContentProvider;
use MissionBayIlias\Api\IObjectTreeResolver;
use MissionBayIlias\Dto\ContentBatchDto;
use MissionBayIlias\Dto\ContentCursorDto;
use MissionBayIlias\Dto\ContentUnitDto;

final class FileContentProvider extends AbstractContentProvider {

	private const SOURCE_SYSTEM = 'ilias';
	private const SOURCE_KIND = 'file';

	public function __construct(
		private readonly IDatabase $db,
		private readonly IObjectTreeResolver $objectTreeResolver
	) {}

	public static function getName(): string {
		return 'filecontentprovider';
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

		// IMPORTANT: do NOT join il_resource_info here (collation mismatch in your DB).
		$rows = $this->queryAll(
			"SELECT
				o.obj_id,
				o.title,
				o.description,
				o.last_update,
				o.create_date,
				fd.file_name,
				fd.rid
			FROM object_data o
			INNER JOIN file_data fd ON fd.file_id = o.obj_id
			WHERE o.type = 'file'
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
			$desc = trim((string)($row['description'] ?? ''));
			$fileName = trim((string)($row['file_name'] ?? ''));

			$description = null;
			if ($fileName !== '' && $desc !== '') {
				$description = $fileName . "\n" . $desc;
			} elseif ($fileName !== '') {
				$description = $fileName;
			} elseif ($desc !== '') {
				$description = $desc;
			}

			$locator = 'file:' . $objId;

			$units[] = new ContentUnitDto(
				self::SOURCE_SYSTEM,
				self::SOURCE_KIND,
				$locator,
				$objId,
				$objId,
				$title !== '' ? $title : null,
				$description,
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
			  AND o.type = 'file'
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

		// IMPORTANT: no il_resource_info join (collation mismatch in your DB).
		$rows = $this->queryAll(
			"SELECT
				o.obj_id,
				o.title,
				o.description,
				o.last_update,
				o.create_date,
				fd.file_name,
				fd.rid
			FROM object_data o
			INNER JOIN file_data fd ON fd.file_id = o.obj_id
			WHERE o.obj_id = " . (int)$objId . "
			  AND o.type = 'file'
			LIMIT 1"
		);

		$r = $rows[0] ?? null;
		if (!$r) {
			return [];
		}

		$title = trim((string)($r['title'] ?? ''));
		$fileName = trim((string)($r['file_name'] ?? ''));
		$rid = trim((string)($r['rid'] ?? ''));

		// Safe ref_id resolution: same strategy as CategoryContentProvider (via object tree resolver).
		$refId = $this->getFirstRefIdByObjId((int)($r['obj_id'] ?? 0));

		// Minimal overhead (read + getFile), no binary load here.
		$filePath = null;

		try {
			$fileObj = new \ilObjFile((int)($r['obj_id'] ?? 0), false);
			$fileObj->read();

			$path = $fileObj->getFile();
			if (is_string($path) && $path !== '' && file_exists($path)) {
				$filePath = $path;
			}
		} catch (\Throwable) {
			$filePath = null;
		}

		return [
			'type' => 'file',
			'obj_id' => (int)($r['obj_id'] ?? 0),
			'source_locator' => $sourceLocator,
			'title' => $title,
			'description' => trim((string)($r['description'] ?? '')),
			'content' => '',
			'meta' => [
				'file_name' => $fileName,
				'rid' => $rid !== '' ? $rid : null,

				// required: filename + location(path) for parser stage
				'location' => $filePath,
				'file_path' => $filePath,

				// for direct link (stable/safe like CategoryContentProvider)
				'ref_id' => $refId > 0 ? $refId : null,

				'last_update' => trim((string)($r['last_update'] ?? '')) ?: null,
				'create_date' => trim((string)($r['create_date'] ?? '')) ?: null
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

		$readOpsId = $this->getReadOpsIdForType('file');
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

		// Safe ref_id resolution: same as CategoryContentProvider
		$refId = $this->getFirstRefIdByObjId($objId);
		if ($refId <= 0) {
			return '';
		}

		// Example: goto.php/file/12345 (ref_id)
		return 'goto.php/file/' . $refId;
	}

	/* ---------- Helpers ---------- */

	private function parseObjIdFromLocator(string $locator): int {
		$p = explode(':', trim($locator));
		if (($p[0] ?? '') === 'file') {
			return (int)($p[1] ?? 0);
		}
		return 0;
	}

	private function getFirstRefIdByObjId(int $objId): int {
		if ($objId <= 0) {
			return 0;
		}

		// Primary path: object tree resolver (same contract used by CategoryContentProvider).
		try {
			$refIds = $this->objectTreeResolver->getRefIdsByObjId($objId);
			$refId = (int)($refIds[0] ?? 0);
			if ($refId > 0) {
				return $refId;
			}
		} catch (\Throwable) {
			// ignore and try fallback
		}

		// Fallback: direct ILIAS API (keeps behavior working even if resolver is not available for some reason).
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
