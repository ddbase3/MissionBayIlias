<?php declare(strict_types=1);

namespace MissionBayIlias\Provider;

use MissionBayIlias\Api\IContentProvider;
use MissionBayIlias\Api\ContentCursor;
use MissionBayIlias\Api\ContentBatch;
use MissionBayIlias\Api\ContentUnit;
use MissionBayIlias\Api\IObjectTreeResolver;
use Base3\Database\Api\IDatabase;

final class WikiPageContentProvider implements IContentProvider {

	private const SOURCE_SYSTEM = 'ilias';
	private const SOURCE_KIND = 'wiki_page';

	private const PARENT_TYPE = 'wpg';

	public function __construct(
		private readonly IDatabase $db,
		private readonly IObjectTreeResolver $objectTreeResolver
	) {}

	public static function getName(): string {
		return 'wikipagecontentprovider';
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
				p.page_id,
				p.parent_id AS wiki_obj_id,
				p.last_change,
				p.render_md5,
				p.rendered_content,
				o.title AS wiki_title,
				o.description AS wiki_description,
				w.introduction AS wiki_introduction
			FROM page_object p
			INNER JOIN object_data o ON o.obj_id = p.parent_id AND o.type = 'wiki'
			LEFT JOIN il_wiki_data w ON w.id = p.parent_id
			WHERE p.parent_type = '" . $this->esc(self::PARENT_TYPE) . "'
				AND p.last_change IS NOT NULL
				AND (
					p.last_change > '" . $this->esc($cursor->changedAt) . "'
					OR (p.last_change = '" . $this->esc($cursor->changedAt) . "' AND p.page_id > " . (int)$cursor->changedId . ")
				)
			ORDER BY p.last_change ASC, p.page_id ASC
			LIMIT " . $limit
		);

		if (!$rows) {
			return new ContentBatch([], $cursor);
		}

		$units = [];
		$maxTs = $cursor->changedAt;
		$maxId = $cursor->changedId;

		foreach ($rows as $row) {
			$pageId = (int)($row['page_id'] ?? 0);
			$wikiObjId = (int)($row['wiki_obj_id'] ?? 0);
			$lastChange = (string)($row['last_change'] ?? '');
			$renderMd5 = (string)($row['render_md5'] ?? '');
			$rendered = (string)($row['rendered_content'] ?? '');

			if ($pageId <= 0 || $wikiObjId <= 0 || $lastChange === '') {
				continue;
			}

			$locator = 'wiki:' . (string)$wikiObjId . ':' . (string)$pageId;

			$title = $this->extractPageTitle($rendered);
			if ($title === null || trim($title) === '') {
				$title = 'Wiki Page #' . (string)$pageId;
			}

			$description = null;

			$units[] = new ContentUnit(
				self::SOURCE_SYSTEM,
				self::SOURCE_KIND,
				$locator,
				$wikiObjId,
				$pageId,
				$title,
				$description,
				$lastChange,
				$renderMd5 !== '' ? $renderMd5 : null
			);

			if ($lastChange > $maxTs) {
				$maxTs = $lastChange;
				$maxId = $pageId;
			} else if ($lastChange === $maxTs && $pageId > $maxId) {
				$maxId = $pageId;
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
			LEFT JOIN page_object p ON p.page_id = s.source_int_id
			WHERE s.source_system = '" . $this->esc(self::SOURCE_SYSTEM) . "'
				AND s.source_kind = '" . $this->esc(self::SOURCE_KIND) . "'
				AND s.source_int_id IS NOT NULL
				AND s.missing_since IS NULL
				AND s.deleted_at IS NULL
				AND p.page_id IS NULL
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
		$wikiObjId = (int)($containerObjId ?? 0);

		if ($pageId <= 0) {
			$pageId = $this->parsePageIdFromLocator($sourceLocator);
		}
		if ($wikiObjId <= 0) {
			$wikiObjId = $this->parseWikiObjIdFromLocator($sourceLocator);
		}

		if ($pageId <= 0) {
			return [];
		}

		$rows = $this->queryAll(
			"SELECT
				p.page_id,
				p.parent_id AS wiki_obj_id,
				p.parent_type,
				p.last_change,
				p.created,
				p.active,
				p.is_empty,
				p.lang,
				p.render_md5,
				p.rendered_content,
				wp.title AS page_title,
				wp.lang AS page_lang,
				o.title AS wiki_title,
				o.description AS wiki_description
			FROM page_object p
			LEFT JOIN il_wiki_page wp ON wp.id = p.page_id
			LEFT JOIN object_data o ON o.obj_id = p.parent_id AND o.type = 'wiki'
			WHERE p.page_id = " . (int)$pageId . "
				AND p.parent_type = '" . $this->esc(self::PARENT_TYPE) . "'
			LIMIT 1"
		);

		$r = $rows[0] ?? null;
		if (!$r) {
			return [];
		}

		$rendered = (string)($r['rendered_content'] ?? '');
		$title = $this->extractPageTitle($rendered);
		if ($title === null || trim($title) === '') {
			$title = $this->nullIfEmpty((string)($r['page_title'] ?? ''));
		}
		if ($title === null || trim($title) === '') {
			$title = 'Wiki Page #' . (string)$pageId;
		}

		$wikiTitle = $this->nullIfEmpty((string)($r['wiki_title'] ?? ''));
		$wikiDesc = $this->nullIfEmpty((string)($r['wiki_description'] ?? ''));

		$wikiObjIdDb = (int)($r['wiki_obj_id'] ?? 0);
		if ($wikiObjId <= 0 && $wikiObjIdDb > 0) {
			$wikiObjId = $wikiObjIdDb;
		}

		return [
			'type' => 'wiki_page',
			'page_id' => (int)($r['page_id'] ?? $pageId),
			'wiki_obj_id' => $wikiObjId > 0 ? $wikiObjId : $wikiObjIdDb,
			'source_locator' => $sourceLocator,
			'title' => $title,
			'content' => $rendered !== '' ? $rendered : (string)($r['content'] ?? ''),
			'meta' => [
				'parent_type' => (string)($r['parent_type'] ?? self::PARENT_TYPE),
				'last_change' => $this->nullIfEmpty((string)($r['last_change'] ?? '')),
				'created' => $this->nullIfEmpty((string)($r['created'] ?? '')),
				'active' => isset($r['active']) ? (int)$r['active'] : null,
				'is_empty' => isset($r['is_empty']) ? (int)$r['is_empty'] : null,
				'lang' => $this->nullIfEmpty((string)($r['lang'] ?? '')),
				'page_lang' => $this->nullIfEmpty((string)($r['page_lang'] ?? '')),
				'render_md5' => $this->nullIfEmpty((string)($r['render_md5'] ?? '')),
				'wiki_title' => $wikiTitle,
				'wiki_description' => $wikiDesc,
			]
		];
	}

	public function fetchReadRoles(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): array {
		// Pages are not repository objects -> ACL is anchored at the wiki container object.
		$wikiObjId = (int)($containerObjId ?? 0);
		if ($wikiObjId <= 0) {
			$wikiObjId = $this->parseWikiObjIdFromLocator($sourceLocator);
		}
		if ($wikiObjId <= 0) {
			return [];
		}

		$refIds = \ilObject::_getAllReferences($wikiObjId);
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

	public function getDirectLink(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): string {
		if ($containerObjId === null || $sourceIntId === null) return '';

		$refIds = $this->objectTreeResolver->getRefIdsByObjId($containerObjId);
		if ($refIds === []) return '';

		$refId = $refIds[0];

		return 'goto.php/wiki/wpage_' . $sourceIntId . '_' . $refId;
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

	private function extractPageTitle(string $renderedContent): ?string {
		$renderedContent = trim($renderedContent);
		if ($renderedContent === '') {
			return null;
		}

		if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $renderedContent, $m)) {
			$title = trim(strip_tags((string)($m[1] ?? '')));
			return $title !== '' ? $title : null;
		}

		return null;
	}

	private function nullIfEmpty(string $v): ?string {
		$v = trim($v);
		return $v !== '' ? $v : null;
	}

	private function parseWikiObjIdFromLocator(string $locator): int {
		$locator = trim($locator);
		if ($locator === '') {
			return 0;
		}

		$parts = explode(':', $locator);
		if (count($parts) < 3) {
			return 0;
		}

		if (trim((string)$parts[0]) !== 'wiki') {
			return 0;
		}

		$id = (int)trim((string)$parts[1]);
		return $id > 0 ? $id : 0;
	}

	private function parsePageIdFromLocator(string $locator): int {
		$locator = trim($locator);
		if ($locator === '') {
			return 0;
		}

		$parts = explode(':', $locator);
		if (count($parts) < 3) {
			return 0;
		}

		$id = (int)trim((string)$parts[2]);
		return $id > 0 ? $id : 0;
	}

	private function queryAll(string $sql): array {
		return $this->db->multiQuery($sql) ?: [];
	}

	private function esc(string $value): string {
		return (string)$this->db->escape($value);
	}
}
