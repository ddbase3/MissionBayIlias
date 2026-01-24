<?php declare(strict_types=1);

namespace MissionBayIlias\Provider;

use Base3\Database\Api\IDatabase;
use MissionBayIlias\Api\IContentProvider;
use MissionBayIlias\Api\IObjectTreeResolver;
use MissionBayIlias\Dto\ContentBatchDto;
use MissionBayIlias\Dto\ContentCursorDto;
use MissionBayIlias\Dto\ContentUnitDto;

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

        public function fetchChanged(ContentCursorDto $cursor, int $limit): ContentBatchDto {
                $limit = max(1, (int)$limit);

                $rows = $this->queryAll(
                        "SELECT
                                p.page_id,
                                p.parent_id AS wiki_obj_id,
                                p.last_change,
                                p.render_md5,
                                p.rendered_content
                        FROM page_object p
                        WHERE p.parent_type = '" . $this->esc(self::PARENT_TYPE) . "'
                          AND p.last_change IS NOT NULL
                          AND (
                                p.last_change > '" . $this->esc($cursor->changedAt) . "'
                                OR (
                                        p.last_change = '" . $this->esc($cursor->changedAt) . "'
                                        AND p.page_id > " . (int)$cursor->changedId . "
                                )
                          )
                        ORDER BY p.last_change ASC, p.page_id ASC
                        LIMIT " . $limit
                );

                if (!$rows) {
                        return new ContentBatchDto([], $cursor);
                }

                $units = [];
                $maxTs = $cursor->changedAt;
                $maxId = $cursor->changedId;

                foreach ($rows as $row) {
                        $pageId = (int)($row['page_id'] ?? 0);
                        $wikiObjId = (int)($row['wiki_obj_id'] ?? 0);
                        $lastChange = (string)($row['last_change'] ?? '');

                        if ($pageId <= 0 || $wikiObjId <= 0 || $lastChange === '') {
                                continue;
                        }

                        $rendered = (string)($row['rendered_content'] ?? '');
                        $title = $this->extractPageTitle($rendered);
                        if ($title === null || trim($title) === '') {
                                $title = 'Wiki Page #' . $pageId;
                        }

                        $locator = 'wiki:' . $wikiObjId . ':' . $pageId;

                        $units[] = new ContentUnitDto(
                                self::SOURCE_SYSTEM,
                                self::SOURCE_KIND,
                                $locator,
                                $wikiObjId,
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
                                p.last_change,
                                p.created,
                                p.active,
                                p.is_empty,
                                p.lang,
                                p.render_md5,
                                p.rendered_content,
                                wp.title AS page_title,
                                wp.lang AS page_lang
                        FROM page_object p
                        LEFT JOIN il_wiki_page wp ON wp.id = p.page_id
                        WHERE p.page_id = " . (int)$pageId . "
                          AND p.parent_type = '" . $this->esc(self::PARENT_TYPE) . "'
                        LIMIT 1"
                );

                $r = $rows[0] ?? null;
                if (!$r) {
                        return [];
                }

                $rendered = (string)($r['rendered_content'] ?? '');

                // CONTRACT: title MUST be string
                $title =
                        $this->extractPageTitle($rendered)
                        ?? trim((string)($r['page_title'] ?? ''))
                        ?? '';

                if ($title === '') {
                        $title = 'Wiki Page #' . $pageId;
                }

                return [
                        'type' => 'wiki_page',
                        'page_id' => (int)$r['page_id'],
                        'wiki_obj_id' => (int)$r['wiki_obj_id'],
                        'source_locator' => $sourceLocator,
                        'title' => $title,
                        'content' => $rendered,
                        'meta' => [
                                'last_change' => trim((string)($r['last_change'] ?? '')),
                                'created' => trim((string)($r['created'] ?? '')),
                                'active' => isset($r['active']) ? (int)$r['active'] : null,
                                'is_empty' => isset($r['is_empty']) ? (int)$r['is_empty'] : null,
                                'lang' => trim((string)($r['lang'] ?? '')),
                                'page_lang' => trim((string)($r['page_lang'] ?? '')),
                                'render_md5' => trim((string)($r['render_md5'] ?? '')),
                        ]
                ];
        }

        public function fetchReadRoles(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): array {
                $wikiObjId = $containerObjId > 0
                        ? (int)$containerObjId
                        : $this->parseWikiObjIdFromLocator($sourceLocator);

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
                        foreach ($review->getParentRoleIds((int)$refId) as $r) {
                                $rolId = (int)($r['rol_id'] ?? 0);
                                if ($rolId <= 0) {
                                        continue;
                                }

                                if (in_array($readOpsId, $review->getActiveOperationsOfRole((int)$refId, $rolId), true)) {
                                        $roleIds[$rolId] = true;
                                }
                        }
                }

                return array_keys($roleIds);
        }

        public function getDirectLink(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): string {
                if ($containerObjId === null || $sourceIntId === null) {
                        return '';
                }

                $refIds = $this->objectTreeResolver->getRefIdsByObjId($containerObjId);
                if ($refIds === []) {
                        return '';
                }

                return 'goto.php/wiki/wpage_' . (int)$sourceIntId . '_' . (int)$refIds[0];
        }

        /* ---------- Helpers ---------- */

        private function extractPageTitle(string $renderedContent): ?string {
                if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $renderedContent, $m)) {
                        $t = trim(strip_tags((string)($m[1] ?? '')));
                        return $t !== '' ? $t : null;
                }
                return null;
        }

        private function parseWikiObjIdFromLocator(string $locator): int {
                $p = explode(':', trim($locator));
                return ($p[0] ?? '') === 'wiki' ? (int)($p[1] ?? 0) : 0;
        }

        private function parsePageIdFromLocator(string $locator): int {
                $p = explode(':', trim($locator));
                return (int)($p[2] ?? 0);
        }

        private function getReadOpsIdForType(string $type): int {
                static $cache = [];

                if (isset($cache[$type])) {
                        return (int)$cache[$type];
                }

                foreach (\ilRbacReview::_getOperationList($type) as $op) {
                        if (($op['operation'] ?? '') === 'read') {
                                return $cache[$type] = (int)$op['ops_id'];
                        }
                }

                return $cache[$type] = 0;
        }

        private function queryAll(string $sql): array {
                return $this->db->multiQuery($sql) ?: [];
        }

        private function esc(string $value): string {
                return (string)$this->db->escape($value);
        }
}
