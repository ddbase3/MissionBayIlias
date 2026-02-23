<?php declare(strict_types=1);

namespace MissionBayIlias\Provider;

use Base3\Database\Api\IDatabase;
use MissionBayIlias\Api\IObjectTreeResolver;
use MissionBayIlias\Dto\ContentBatchDto;
use MissionBayIlias\Dto\ContentCursorDto;
use MissionBayIlias\Dto\ContentUnitDto;

final class SurveyContentProvider extends AbstractContentProvider {

	private const SOURCE_SYSTEM = 'ilias';
	private const SOURCE_KIND = 'svy';

	public function __construct(
		IDatabase $db,
		private readonly IObjectTreeResolver $objectTreeResolver
	) {
		parent::__construct($db);
	}

	public static function getName(): string {
		return 'surveycontentprovider';
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

		// English comment: Provider must be robust even if cursor is empty/invalid.
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
			WHERE o.type = 'svy'
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

			$title = $this->nullIfEmpty((string)($row['title'] ?? ''));
			$desc = $this->nullIfEmpty((string)($row['description'] ?? ''));

			$locator = 'svy:' . $objId;

			$units[] = new ContentUnitDto(
				self::SOURCE_SYSTEM,
				self::SOURCE_KIND,
				$locator,
				$objId,
				$objId,
				$title,
				$desc,
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
				AND o.type = 'svy'
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
				AND o.type = 'svy'
			LIMIT 1"
		);

		$r = $rows[0] ?? null;
		if (!$r) {
			return [];
		}

		// CONTRACT: title MUST be string
		$title = trim((string)($r['title'] ?? ''));
		if ($title === '') {
			$title = 'Survey #' . $objId;
		}

		$desc = trim((string)($r['description'] ?? ''));

		// English comment: Resolve survey_id (internal) from svy_svy by obj_id.
		$surveyId = $this->getSurveyIdByObjId($objId);

		// English comment: Aggregate as much readable text as possible into "content".
		$content = $this->buildSurveyContent($desc, $surveyId);

		$refId = $this->getFirstRefIdByObjId($objId);

		return [
			'type' => 'svy',
			'obj_id' => (int)($r['obj_id'] ?? $objId),
			'source_locator' => $sourceLocator,
			'title' => $title,
			'description' => $desc !== '' ? $desc : null,
			'content' => $content,
			'meta' => [
				'ref_id' => $refId > 0 ? $refId : null,
				'survey_id' => $surveyId > 0 ? $surveyId : null,

				'last_update' => $this->nullIfEmpty((string)($r['last_update'] ?? '')),
				'create_date' => $this->nullIfEmpty((string)($r['create_date'] ?? '')),
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

		$readOpsId = $this->getReadOpsIdForType('svy');
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

		$refId = $this->getFirstRefIdByObjId($objId);
		if ($refId <= 0) {
			return '';
		}

		return 'goto.php/svy/' . $refId;
	}

	/* ---------- Survey content builders ---------- */

	private function buildSurveyContent(string $desc, int $surveyId): string {
		$parts = [];

		// English comment: Description is useful text (do not include title; pipeline usually adds it).
		if (trim($desc) !== '') {
			$parts[] = trim($desc);
		}

		if ($surveyId > 0) {
			$questionsText = $this->buildQuestionsText($surveyId);
			if ($questionsText !== '') {
				$parts[] = $questionsText;
			}
		}

		return trim(implode("\n\n", $parts));
	}

	private function buildQuestionsText(int $surveyId): string {
		// English comment: Typical ILIAS schema is:
		// svy_svy.survey_id (or similar) + svy_svy.obj_fi
		// svy_svy_qst.survey_fi + svy_svy_qst.question_fi + svy_svy_qst.sequence + svy_svy_qst.heading
		// svy_question.question_id + svy_question.title + svy_question.questiontext
		//
		// If your field names differ, adjust here only.
		$rows = $this->queryAll(
			"SELECT
				sq.sequence,
				sq.heading,
				q.question_id,
				q.title AS question_title,
				q.questiontext
			FROM svy_svy_qst sq
			INNER JOIN svy_question q ON q.question_id = sq.question_fi
			WHERE sq.survey_fi = " . (int)$surveyId . "
			ORDER BY sq.sequence ASC, q.question_id ASC"
		);

		if (!$rows) {
			return '';
		}

		$lines = [];
		$lastHeading = null;

		foreach ($rows as $row) {
			$heading = trim((string)($row['heading'] ?? ''));
			$qTitle = trim((string)($row['question_title'] ?? ''));
			$qText = trim((string)($row['questiontext'] ?? ''));

			if ($heading !== '' && $heading !== $lastHeading) {
				$lines[] = '## ' . $heading;
				$lastHeading = $heading;
			}

			// English comment: Add question title and text (best-effort, avoid empty noise).
			if ($qTitle !== '') {
				$lines[] = '### ' . $qTitle;
			}

			if ($qText !== '') {
				$lines[] = $qText;
			}

			if ($qTitle !== '' || $qText !== '') {
				$lines[] = '';
			}
		}

		return trim(implode("\n", $lines));
	}

	private function getSurveyIdByObjId(int $objId): int {
		if ($objId <= 0) {
			return 0;
		}

		$rows = $this->queryAll(
			"SELECT
				s.survey_id
			FROM svy_svy s
			WHERE s.obj_fi = " . (int)$objId . "
			LIMIT 1"
		);

		$r = $rows[0] ?? null;
		if (!$r) {
			return 0;
		}

		return (int)($r['survey_id'] ?? 0);
	}

	/* ---------- Helpers ---------- */

	private function parseObjIdFromLocator(string $locator): int {
		$parts = explode(':', trim($locator));
		return ($parts[0] ?? '') === 'svy' ? (int)($parts[1] ?? 0) : 0;
	}

	private function getFirstRefIdByObjId(int $objId): int {
		if ($objId <= 0) {
			return 0;
		}

		// English comment: Primary path via object tree resolver (consistent with other providers).
		try {
			$refIds = $this->objectTreeResolver->getRefIdsByObjId($objId);
			$refId = (int)($refIds[0] ?? 0);
			if ($refId > 0) {
				return $refId;
			}
		} catch (\Throwable) {
			// ignore and try fallback
		}

		// English comment: Fallback via ILIAS API.
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

	private function nullIfEmpty(string $v): ?string {
		$v = trim($v);
		return $v !== '' ? $v : null;
	}
}
