<?php declare(strict_types=1);

namespace MissionBayIlias\Provider;

use Base3\Database\Api\IDatabase;
use MissionBayIlias\Api\IObjectTreeResolver;
use MissionBayIlias\Dto\ContentBatchDto;
use MissionBayIlias\Dto\ContentCursorDto;
use MissionBayIlias\Dto\ContentUnitDto;

/**
 * GlossaryTermContentProvider
 *
 * Child terms of a glossary object.
 *
 * Locator:
 * - glo:<GLO_OBJ_ID>:<TERM_ID>
 *
 * Title:
 * - glossary_term.term
 *
 * Content:
 * - page_object.rendered_content / content for parent_type='term' and page_id = glossary_term.id
 *
 * Direct link:
 * - always parent glossary link (no per-term link)
 */
final class GlossaryTermContentProvider extends AbstractContentProvider {

	private const SOURCE_SYSTEM = 'ilias';
	private const SOURCE_KIND = 'glo_term';

	/** page_object.parent_type for glossary term pages */
	private const PARENT_TYPE = 'term';

	public function __construct(
		IDatabase $db,
		private readonly IObjectTreeResolver $objectTreeResolver
	) {
		parent::__construct($db);
	}

	public static function getName(): string {
		return 'glossarytermcontentprovider';
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

		// Change token: prefer page_object.last_change (content change),
		// fallback to glossary_term.last_update (exists in data model).
		$rows = $this->queryAll(
			"SELECT
				t.id AS term_id,
				t.glo_id,
				t.term,
				t.language,
				t.create_date AS term_created,
				t.last_update AS term_last_update,
				t.short_text,
				t.short_text_dirty,
				p.last_change,
				p.render_md5,
				p.rendered_content
			FROM glossary_term t
			INNER JOIN object_data o ON o.obj_id = t.glo_id AND o.type = 'glo'
			LEFT JOIN page_object p
				ON p.page_id = t.id
				AND p.parent_id = t.glo_id
				AND p.parent_type = '" . $this->esc(self::PARENT_TYPE) . "'
			WHERE COALESCE(p.last_change, t.last_update) IS NOT NULL
				AND (
					COALESCE(p.last_change, t.last_update) > '" . $this->esc($cursor->changedAt) . "'
					OR (COALESCE(p.last_change, t.last_update) = '" . $this->esc($cursor->changedAt) . "' AND t.id > " . (int)$cursor->changedId . ")
				)
			ORDER BY COALESCE(p.last_change, t.last_update) ASC, t.id ASC
			LIMIT " . $limit
		);

		if (!$rows) {
			return new ContentBatchDto([], $cursor);
		}

		$units = [];
		$maxTs = $cursor->changedAt;
		$maxId = $cursor->changedId;

		foreach ($rows as $row) {
			$termId = (int)($row['term_id'] ?? 0);
			$gloId = (int)($row['glo_id'] ?? 0);

			$ts = (string)($row['last_change'] ?? '');
			if ($ts === '') {
				$ts = (string)($row['term_last_update'] ?? '');
			}

			if ($termId <= 0 || $gloId <= 0 || $ts === '') {
				continue;
			}

			$title = $this->nullIfEmpty((string)($row['term'] ?? ''));
			if ($title === null) {
				$title = 'Glossary Term #' . (string)$termId;
			}

			$locator = 'glo:' . (string)$gloId . ':' . (string)$termId;

			$renderMd5 = $this->nullIfEmpty((string)($row['render_md5'] ?? ''));

			$units[] = new ContentUnitDto(
				self::SOURCE_SYSTEM,
				self::SOURCE_KIND,
				$locator,
				$gloId,     // container obj id (glossary)
				$termId,    // source int id (term id / page_id)
				$title,
				null,
				$ts,
				$renderMd5
			);

			if ($ts > $maxTs) {
				$maxTs = $ts;
				$maxId = $termId;
			} else if ($ts === $maxTs && $termId > $maxId) {
				$maxId = $termId;
			}
		}

		return new ContentBatchDto($units, new ContentCursorDto($maxTs, $maxId));
	}

	public function fetchMissingSourceIntIds(int $limit): array {
		$limit = max(1, (int)$limit);

		$rows = $this->queryAll(
			"SELECT
				s.source_int_id
			FROM base3_embedding_seen s
			LEFT JOIN glossary_term t ON t.id = s.source_int_id
			WHERE s.source_system = '" . $this->esc(self::SOURCE_SYSTEM) . "'
				AND s.source_kind = '" . $this->esc(self::SOURCE_KIND) . "'
				AND s.source_int_id IS NOT NULL
				AND s.missing_since IS NULL
				AND s.deleted_at IS NULL
				AND t.id IS NULL
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
		$termId = (int)($sourceIntId ?? 0);
		$gloId = (int)($containerObjId ?? 0);

		if ($termId <= 0) {
			$termId = $this->parseTermIdFromLocator($sourceLocator);
		}
		if ($gloId <= 0) {
			$gloId = $this->parseGloObjIdFromLocator($sourceLocator);
		}
		if ($termId <= 0 || $gloId <= 0) {
			return [];
		}

		$rows = $this->queryAll(
			"SELECT
				t.id AS term_id,
				t.glo_id,
				t.term,
				t.language,
				t.create_date AS term_created,
				t.last_update AS term_last_update,
				t.short_text,
				t.short_text_dirty,
				p.parent_type,
				p.last_change,
				p.created AS page_created,
				p.lang,
				p.render_md5,
				p.rendered_content,
				p.content,
				o.title AS glo_title,
				o.description AS glo_description
			FROM glossary_term t
			LEFT JOIN page_object p
				ON p.page_id = t.id
				AND p.parent_id = t.glo_id
				AND p.parent_type = '" . $this->esc(self::PARENT_TYPE) . "'
			LEFT JOIN object_data o
				ON o.obj_id = t.glo_id
				AND o.type = 'glo'
			WHERE t.id = " . (int)$termId . "
				AND t.glo_id = " . (int)$gloId . "
			LIMIT 1"
		);

		$r = $rows[0] ?? null;
		if (!$r) {
			return [];
		}

		$title = $this->nullIfEmpty((string)($r['term'] ?? ''));
		if ($title === null) {
			$title = 'Glossary Term #' . (string)$termId;
		}

		$rendered = (string)($r['rendered_content'] ?? '');
		$raw = (string)($r['content'] ?? '');

		// NOTE: Cleanup of HTML/XML happens in parser stage (shared PageObject parser).
		$content = $rendered !== '' ? $rendered : $raw;

		return [
			'type' => 'glo_term',
			'term_id' => (int)($r['term_id'] ?? $termId),
			'glo_obj_id' => (int)($r['glo_id'] ?? $gloId),
			'source_locator' => $sourceLocator,
			'title' => $title,
			'content' => $content,
			'meta' => [
				'language' => $this->nullIfEmpty((string)($r['language'] ?? '')),
				'term_created' => $this->nullIfEmpty((string)($r['term_created'] ?? '')),
				'term_last_update' => $this->nullIfEmpty((string)($r['term_last_update'] ?? '')),
				'short_text' => $this->nullIfEmpty((string)($r['short_text'] ?? '')),
				'short_text_dirty' => isset($r['short_text_dirty']) ? (int)$r['short_text_dirty'] : null,

				'parent_type' => $this->nullIfEmpty((string)($r['parent_type'] ?? self::PARENT_TYPE)),
				'last_change' => $this->nullIfEmpty((string)($r['last_change'] ?? '')),
				'page_created' => $this->nullIfEmpty((string)($r['page_created'] ?? '')),
				'lang' => $this->nullIfEmpty((string)($r['lang'] ?? '')),
				'render_md5' => $this->nullIfEmpty((string)($r['render_md5'] ?? '')),

				'glo_title' => $this->nullIfEmpty((string)($r['glo_title'] ?? '')),
				'glo_description' => $this->nullIfEmpty((string)($r['glo_description'] ?? '')),
			]
		];
	}

	public function fetchReadRoles(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): array {
		// Terms are not repository objects -> ACL anchored at the glossary container object.
		$gloId = (int)($containerObjId ?? 0);
		if ($gloId <= 0) {
			$gloId = $this->parseGloObjIdFromLocator($sourceLocator);
		}
		if ($gloId <= 0) {
			return [];
		}

		$refIds = \ilObject::_getAllReferences($gloId);
		if (!$refIds) {
			return [];
		}

		global $DIC;
		$review = $DIC->rbac()->review();

		$readOpsId = $this->getReadOpsIdForType('glo');
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

	public function getDirectLink(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): string {
		// No per-term link -> always parent glossary link.
		$gloId = (int)($containerObjId ?? 0);
		if ($gloId <= 0) {
			$gloId = $this->parseGloObjIdFromLocator($sourceLocator);
		}
		if ($gloId <= 0) {
			return '';
		}

		$refIds = $this->objectTreeResolver->getRefIdsByObjId($gloId);
		if ($refIds === []) {
			return '';
		}

		return 'goto.php/glo/' . (int)$refIds[0];
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

	private function parseGloObjIdFromLocator(string $locator): int {
		$locator = trim($locator);
		if ($locator === '') return 0;

		$parts = explode(':', $locator);
		if (count($parts) < 2) return 0;
		if (trim((string)$parts[0]) !== 'glo') return 0;

		$id = (int)trim((string)$parts[1]);
		return $id > 0 ? $id : 0;
	}

	private function parseTermIdFromLocator(string $locator): int {
		$locator = trim($locator);
		if ($locator === '') return 0;

		$parts = explode(':', $locator);
		if (count($parts) < 3) return 0;

		$id = (int)trim((string)$parts[2]);
		return $id > 0 ? $id : 0;
	}

	private function nullIfEmpty(string $v): ?string {
		$v = trim($v);
		return $v !== '' ? $v : null;
	}
}
