<?php declare(strict_types=1);

namespace MissionBayIlias\Provider;

use Base3\Database\Api\IDatabase;
use MissionBayIlias\Api\IObjectTreeResolver;
use MissionBayIlias\Dto\ContentBatchDto;
use MissionBayIlias\Dto\ContentCursorDto;
use MissionBayIlias\Dto\ContentUnitDto;

final class CourseContentProvider extends AbstractContentProvider {

	private const SOURCE_SYSTEM = 'ilias';
	private const SOURCE_KIND = 'crs';

	public function __construct(
		private readonly IDatabase $db,
		private readonly IObjectTreeResolver $objectTreeResolver
	) {}

	public static function getName(): string {
		return 'coursecontentprovider';
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
				s.syllabus,
				s.important,
				s.period_start,
				s.period_end
			FROM object_data o
			LEFT JOIN crs_settings s ON s.obj_id = o.obj_id
			WHERE o.type = 'crs'
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

			$syllabus = trim((string)($row['syllabus'] ?? ''));
			$important = trim((string)($row['important'] ?? ''));

			// Prefer a meaningful short description (syllabus/important are often richer than object_data.description)
			$description = null;
			if ($important !== '') {
				$description = $important;
			} elseif ($syllabus !== '') {
				$description = $syllabus;
			} elseif ($desc !== '') {
				$description = $desc;
			}

			$locator = 'crs:' . $objId;

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
			  AND o.type = 'crs'
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
				s.syllabus,
				s.important,
				s.contact_name,
				s.contact_email,
				s.period_start,
				s.period_end
			FROM object_data o
			LEFT JOIN crs_settings s ON s.obj_id = o.obj_id
			WHERE o.obj_id = " . (int)$objId . "
			  AND o.type = 'crs'
			LIMIT 1"
		);

		$r = $rows[0] ?? null;
		if (!$r) {
			return [];
		}

		// CONTRACT: title MUST be string
		$title = trim((string)($r['title'] ?? ''));

		$syllabus = trim((string)($r['syllabus'] ?? ''));
		$important = trim((string)($r['important'] ?? ''));

		// "content" should be the richest long text field (course settings)
		$content = $important !== '' ? $important : $syllabus;

		return [
			'type' => 'crs',
			'obj_id' => (int)$r['obj_id'],
			'source_locator' => $sourceLocator,
			'title' => $title,
			'description' => trim((string)($r['description'] ?? '')),
			'content' => $content,
			'meta' => [
				'last_update' => trim((string)($r['last_update'] ?? '')),
				'create_date' => trim((string)($r['create_date'] ?? '')),

				// course settings meta (keep raw; parser will strip html/xml later)
				'syllabus' => $syllabus !== '' ? $syllabus : null,
				'important' => $important !== '' ? $important : null,
				'contact_name' => trim((string)($r['contact_name'] ?? '')) !== '' ? trim((string)($r['contact_name'] ?? '')) : null,
				'contact_email' => trim((string)($r['contact_email'] ?? '')) !== '' ? trim((string)($r['contact_email'] ?? '')) : null,

				'period_start' => trim((string)($r['period_start'] ?? '')) !== '' ? trim((string)($r['period_start'] ?? '')) : null,
				'period_end' => trim((string)($r['period_end'] ?? '')) !== '' ? trim((string)($r['period_end'] ?? '')) : null,
			]
		];
	}

	public function fetchReadRoles(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): array {
		$objId = $containerObjId !== null ? (int)$containerObjId : (int)($sourceIntId ?? 0);
		if ($objId <= 0) {
			return [];
		}

		$refIds = \ilObject::_getAllReferences($objId);
		if (!$refIds) {
			return [];
		}

		global $DIC;
		$review = $DIC->rbac()->review();

		$readOpsId = $this->getReadOpsIdForType('crs');
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
		if ($containerObjId === null) {
			return '';
		}

		// Prefer resolver-based mount ref ids (fast, cacheable)
		$refIds = $this->objectTreeResolver->getRefIdsByObjId($containerObjId);
		if ($refIds !== []) {
			return 'goto.php/cat/' . (int)$refIds[0];
		}

		// Fallback: DB lookup (object_reference), first non-deleted ref
		$rows = $this->queryAll(
			"SELECT r.ref_id
			 FROM object_reference r
			 WHERE r.obj_id = " . (int)$containerObjId . "
			   AND r.deleted IS NULL
			 ORDER BY r.ref_id ASC
			 LIMIT 1"
		);

		$refId = (int)($rows[0]['ref_id'] ?? 0);
		if ($refId <= 0) {
			return '';
		}

		return 'goto.php/cat/' . $refId;
	}

	/* ---------- Helpers ---------- */

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
