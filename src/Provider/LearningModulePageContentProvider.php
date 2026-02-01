<?php declare(strict_types=1);

namespace MissionBayIlias\Provider;

use Base3\Database\Api\IDatabase;
use MissionBayIlias\Api\IObjectTreeResolver;
use MissionBayIlias\Dto\ContentBatchDto;
use MissionBayIlias\Dto\ContentCursorDto;
use MissionBayIlias\Dto\ContentUnitDto;

final class LearningModulePageContentProvider extends AbstractContentProvider {

	private const SOURCE_SYSTEM = 'ilias';
	private const SOURCE_KIND = 'lm_page';

	/**
	 * IMPORTANT:
	 * page_object.parent_type is a *PageObject* parent marker, NOT the repository object type.
	 * For Learning Modules, this is typically "lm".
	 */
	private const PAGE_PARENT_TYPE = 'lm';

	public function __construct(
		private readonly IDatabase $db,
		private readonly IObjectTreeResolver $objectTreeResolver
	) {}

	public static function getName(): string {
		return 'learningmodulepagecontentprovider';
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

		if (!$this->isValidTimestamp($sinceTs)) {
			$sinceTs = '1970-01-01 00:00:00';
			$sinceId = 0;
		}
		if ($sinceId < 0) {
			$sinceId = 0;
		}

		// Only real LM pages:
		// - page_object.parent_type must be "lm"
		// - page_object.parent_id must be an object_data entry of type "lm" (repository LM object)
		// - lm_data must have a "pg" row for that page_id within that lm_id
		$rows = $this->queryAll(
			"SELECT
				p.page_id,
				p.parent_id AS lm_obj_id,
				p.last_change,
				p.render_md5,
				p.rendered_content,
				d.title AS lm_page_title
			FROM page_object p
			INNER JOIN object_data lm
				ON lm.obj_id = p.parent_id
				AND lm.type = 'lm'
			INNER JOIN lm_data d
				ON d.obj_id = p.page_id
				AND d.type = 'pg'
				AND d.lm_id = p.parent_id
			WHERE p.parent_type = '" . $this->esc(self::PAGE_PARENT_TYPE) . "'
				AND p.last_change IS NOT NULL
				AND (
					p.last_change > '" . $this->esc($sinceTs) . "'
					OR (
						p.last_change = '" . $this->esc($sinceTs) . "'
						AND p.page_id > " . (int)$sinceId . "
					)
				)
			ORDER BY p.last_change ASC, p.page_id ASC
			LIMIT " . $limit
		);

		if (!$rows) {
			return new ContentBatchDto([], new ContentCursorDto($sinceTs, $sinceId));
		}

		$units = [];
		$maxTs = $sinceTs;
		$maxId = $sinceId;

		foreach ($rows as $row) {
			$pageId = (int)($row['page_id'] ?? 0);
			$lmObjId = (int)($row['lm_obj_id'] ?? 0);
			$lastChange = trim((string)($row['last_change'] ?? ''));

			if ($pageId <= 0 || $lmObjId <= 0 || $lastChange === '') {
				continue;
			}

			$rendered = (string)($row['rendered_content'] ?? '');
			$title = $this->extractPageTitle($rendered) ?? trim((string)($row['lm_page_title'] ?? ''));

			if ($title === '') {
				$title = 'LM Page #' . $pageId;
			}

			$locator = 'lm:' . $lmObjId . ':' . $pageId;

			$units[] = new ContentUnitDto(
				self::SOURCE_SYSTEM,
				self::SOURCE_KIND,
				$locator,
				$lmObjId,
				$pageId,
				$title,
				null,
				$lastChange,
				$row['render_md5'] ?? null
			);

			if ($lastChange > $maxTs) {
				$maxTs = $lastChange;
				$maxId = $pageId;
			} elseif ($lastChange === $maxTs && $pageId > $maxId) {
				$maxId = $pageId;
			}
		}

		return new ContentBatchDto($units, new ContentCursorDto($maxTs, $maxId));
	}

	public function fetchMissingSourceIntIds(int $limit): array {
		$limit = max(1, (int)$limit);

		// "Missing" means:
		// - seen row exists for lm_page
		// - referenced page_object no longer exists OR is no longer an LM page OR its LM container is gone OR lm_data row is gone
		$rows = $this->queryAll(
			"SELECT s.source_int_id
			FROM base3_embedding_seen s
			LEFT JOIN page_object p
				ON p.page_id = s.source_int_id
				AND p.parent_type = '" . $this->esc(self::PAGE_PARENT_TYPE) . "'
			LEFT JOIN object_data lm
				ON lm.obj_id = p.parent_id
				AND lm.type = 'lm'
			LEFT JOIN lm_data d
				ON d.obj_id = s.source_int_id
				AND d.type = 'pg'
				AND d.lm_id = p.parent_id
			WHERE s.source_system = '" . $this->esc(self::SOURCE_SYSTEM) . "'
				AND s.source_kind = '" . $this->esc(self::SOURCE_KIND) . "'
				AND s.source_int_id IS NOT NULL
				AND s.missing_since IS NULL
				AND s.deleted_at IS NULL
				AND (
					p.page_id IS NULL
					OR lm.obj_id IS NULL
					OR d.obj_id IS NULL
				)
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
		$pageId = (int)($sourceIntId ?? 0);
		$lmObjId = (int)($containerObjId ?? 0);

		if ($pageId <= 0) {
			$pageId = $this->parsePageIdFromLocator($sourceLocator);
		}
		if ($lmObjId <= 0) {
			$lmObjId = $this->parseLmObjIdFromLocator($sourceLocator);
		}
		if ($pageId <= 0 || $lmObjId <= 0) {
			return [];
		}

		// HARD FILTER:
		// - parent_type must be lm
		// - parent_id must be exactly the LM object id (container)
		// - lm object must exist in object_data as type lm
		// - lm_data must confirm this page belongs to that lm
		$r = $this->queryOne(
			"SELECT
				p.page_id,
				p.parent_id AS lm_obj_id,
				p.last_change,
				p.created,
				p.lang,
				p.render_md5,
				p.content AS pageobject_content,
				p.rendered_content,
				d.title AS lm_page_title
			FROM page_object p
			INNER JOIN object_data lm
				ON lm.obj_id = p.parent_id
				AND lm.type = 'lm'
			INNER JOIN lm_data d
				ON d.obj_id = p.page_id
				AND d.type = 'pg'
				AND d.lm_id = p.parent_id
			WHERE p.page_id = " . (int)$pageId . "
				AND p.parent_type = '" . $this->esc(self::PAGE_PARENT_TYPE) . "'
				AND p.parent_id = " . (int)$lmObjId . "
			LIMIT 1"
		);

		if (!$r) {
			return [];
		}

		// Canonical LM page text is in page_object.content (PageObject XML / PageObject markup).
		$pageObjectXml = (string)($r['pageobject_content'] ?? '');
		$rendered = (string)($r['rendered_content'] ?? '');

		$content = trim($pageObjectXml) !== '' ? $pageObjectXml : $rendered;

		$title = $this->extractPageTitle($rendered) ?? trim((string)($r['lm_page_title'] ?? ''));
		if ($title === '') {
			$title = 'LM Page #' . $pageId;
		}

		$path = $this->getLmPathMeta($lmObjId, $pageId);

		// IMPORTANT CONTRACT for IliasPageParserAgentResource:
		// Return payload with keys:
		// - type (lm_page)
		// - title (string)
		// - content (string XML/HTML)
		return [
			'type' => 'lm_page',
			'title' => $title,
			'content' => $content,

			// keep useful ids for later filters/debug
			'page_id' => (int)($r['page_id'] ?? 0),
			'lm_obj_id' => (int)($r['lm_obj_id'] ?? 0),
			'source_locator' => $sourceLocator,

			'meta' => [
				'last_change' => trim((string)($r['last_change'] ?? '')),
				'created' => trim((string)($r['created'] ?? '')),
				'lang' => trim((string)($r['lang'] ?? '')),
				'render_md5' => trim((string)($r['render_md5'] ?? '')),
				'has_pageobject_content' => trim($pageObjectXml) !== '',
				'has_rendered_content' => trim($rendered) !== '',
				'lm_path' => $path,
			]
		];
	}

	public function fetchReadRoles(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): array {
		$lmObjId = $containerObjId > 0
			? (int)$containerObjId
			: $this->parseLmObjIdFromLocator($sourceLocator);

		if ($lmObjId <= 0) {
			return [];
		}

		$refIds = \ilObject::_getAllReferences($lmObjId);
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

				if (in_array($readOpsId, $review->getActiveOperationsOfRole($refId, $rolId), true)) {
					$roleIds[$rolId] = true;
				}
			}
		}

		return array_keys($roleIds);
	}

	public function getDirectLink(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): string {
		$lmObjId = $containerObjId > 0
			? (int)$containerObjId
			: $this->parseLmObjIdFromLocator($sourceLocator);

		$pageId = (int)($sourceIntId ?? 0);
		if ($pageId <= 0) {
			$pageId = $this->parsePageIdFromLocator($sourceLocator);
		}

		if ($lmObjId <= 0 || $pageId <= 0) {
			return '';
		}

		$refIds = $this->objectTreeResolver->getRefIdsByObjId($lmObjId);
		if ($refIds === []) {
			return '';
		}

		// Keep it simple and deterministic. Caller can turn it into absolute URL.
		return 'goto.php?target=lm_' . (int)$refIds[0] . '_' . (int)$pageId;
	}

	/* ---------- Helpers ---------- */

	private function extractPageTitle(string $renderedContent): ?string {
		// rendered_content uses h1.ilc_page_title_PageTitle for LM pages
		if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $renderedContent, $m)) {
			$t = trim(strip_tags((string)($m[1] ?? '')));
			return $t !== '' ? $t : null;
		}
		return null;
	}

	private function parseLmObjIdFromLocator(string $locator): int {
		$p = explode(':', trim($locator));
		return ($p[0] ?? '') === 'lm' ? (int)($p[1] ?? 0) : 0;
	}

	private function parsePageIdFromLocator(string $locator): int {
		$p = explode(':', trim($locator));
		return (int)($p[2] ?? 0);
	}

	private function getLmPathMeta(int $lmObjId, int $pageId): array {
		$rows = $this->queryAll(
			"SELECT
				n.child AS node_id,
				n.depth,
				d.title,
				d.type
			FROM lm_tree n
			INNER JOIN lm_tree cur
				ON cur.lm_id = n.lm_id
				AND cur.child = " . (int)$pageId . "
			LEFT JOIN lm_data d
				ON d.obj_id = n.child
				AND d.lm_id = n.lm_id
			WHERE n.lm_id = " . (int)$lmObjId . "
				AND n.lft <= cur.lft
				AND n.rgt >= cur.rgt
			ORDER BY n.depth ASC"
		);

		$nodes = [];
		foreach ($rows as $r) {
			$nodeId = (int)($r['node_id'] ?? 0);
			if ($nodeId <= 0) {
				continue;
			}

			$nodes[] = [
				'id' => $nodeId,
				'depth' => (int)($r['depth'] ?? 0),
				'type' => (string)($r['type'] ?? ''),
				'title' => trim((string)($r['title'] ?? '')),
			];
		}

		// Drop dummy root node if present (obj_id 1, empty title)
		if (isset($nodes[0]) && (int)($nodes[0]['id'] ?? 0) === 1 && trim((string)($nodes[0]['title'] ?? '')) === '') {
			array_shift($nodes);
		}

		$breadcrumbTitles = [];
		$breadcrumbIds = [];
		foreach ($nodes as $n) {
			$breadcrumbIds[] = (int)$n['id'];
			$breadcrumbTitles[] = (string)$n['title'];
		}

		return [
			'lm_obj_id' => $lmObjId,
			'page_id' => $pageId,
			'nodes' => $nodes,
			'breadcrumb_ids' => $breadcrumbIds,
			'breadcrumb_titles' => $breadcrumbTitles,
		];
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

		foreach (\ilRbacReview::_getOperationList($type) as $op) {
			if (($op['operation'] ?? '') === 'read') {
				return $cache[$type] = (int)($op['ops_id'] ?? 0);
			}
		}

		return $cache[$type] = 0;
	}
}
