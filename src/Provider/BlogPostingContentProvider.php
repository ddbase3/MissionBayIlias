<?php declare(strict_types=1);

namespace MissionBayIlias\Provider;

use Base3\Database\Api\IDatabase;
use MissionBayIlias\Api\IObjectTreeResolver;
use MissionBayIlias\Dto\ContentBatchDto;
use MissionBayIlias\Dto\ContentCursorDto;
use MissionBayIlias\Dto\ContentUnitDto;

final class BlogPostingContentProvider extends AbstractContentProvider {

        private const SOURCE_SYSTEM = 'ilias';
        private const SOURCE_KIND = 'blog_posting';

        /** page_object.parent_type for blog postings */
        private const PARENT_TYPE = 'blp';

        public function __construct(
                IDatabase $db,
                private readonly IObjectTreeResolver $objectTreeResolver
	) {
		parent::__construct($db);
	}

        public static function getName(): string {
                return 'blogpostingcontentprovider';
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
                                b.id AS posting_id,
                                b.blog_id,
                                b.title AS posting_title,
                                b.created AS posting_created,
                                p.last_change,
                                p.render_md5,
                                p.rendered_content
                        FROM il_blog_posting b
                        INNER JOIN object_data o ON o.obj_id = b.blog_id AND o.type = 'blog'
                        LEFT JOIN page_object p
                                ON p.page_id = b.id
                                AND p.parent_id = b.blog_id
                                AND p.parent_type = '" . $this->esc(self::PARENT_TYPE) . "'
                        WHERE COALESCE(p.last_change, b.created) IS NOT NULL
                          AND (
                                COALESCE(p.last_change, b.created) > '" . $this->esc($cursor->changedAt) . "'
                                OR (
                                        COALESCE(p.last_change, b.created) = '" . $this->esc($cursor->changedAt) . "'
                                        AND b.id > " . (int)$cursor->changedId . "
                                )
                          )
                        ORDER BY COALESCE(p.last_change, b.created) ASC, b.id ASC
                        LIMIT " . $limit
                );

                if (!$rows) {
                        return new ContentBatchDto([], $cursor);
                }

                $units = [];
                $maxTs = $cursor->changedAt;
                $maxId = $cursor->changedId;

                foreach ($rows as $row) {
                        $postingId = (int)($row['posting_id'] ?? 0);
                        $blogObjId = (int)($row['blog_id'] ?? 0);

                        $ts = (string)($row['last_change'] ?? '');
                        if ($ts === '') {
                                $ts = (string)($row['posting_created'] ?? '');
                        }

                        if ($postingId <= 0 || $blogObjId <= 0 || $ts === '') {
                                continue;
                        }

                        $title = $this->nullIfEmpty((string)($row['posting_title'] ?? ''));
                        if ($title === null) {
                                $title = 'Blog Posting #' . $postingId;
                        }

                        $locator = 'blog:' . $blogObjId . ':' . $postingId;

                        $units[] = new ContentUnitDto(
                                self::SOURCE_SYSTEM,
                                self::SOURCE_KIND,
                                $locator,
                                $blogObjId,
                                $postingId,
                                $title,
                                null,
                                $ts,
                                $this->nullIfEmpty((string)($row['render_md5'] ?? ''))
                        );

                        if ($ts > $maxTs) {
                                $maxTs = $ts;
                                $maxId = $postingId;
                        } elseif ($ts === $maxTs && $postingId > $maxId) {
                                $maxId = $postingId;
                        }
                }

                return new ContentBatchDto($units, new ContentCursorDto($maxTs, $maxId));
        }

        public function fetchMissingSourceIntIds(int $limit): array {
                $limit = max(1, (int)$limit);

                $rows = $this->queryAll(
                        "SELECT s.source_int_id
                         FROM base3_embedding_seen s
                         LEFT JOIN il_blog_posting b ON b.id = s.source_int_id
                         WHERE s.source_system = '" . $this->esc(self::SOURCE_SYSTEM) . "'
                           AND s.source_kind = '" . $this->esc(self::SOURCE_KIND) . "'
                           AND s.source_int_id IS NOT NULL
                           AND s.missing_since IS NULL
                           AND s.deleted_at IS NULL
                           AND b.id IS NULL
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
                $postingId = (int)($sourceIntId ?? 0);
                $blogObjId = (int)($containerObjId ?? 0);

                if ($postingId <= 0) {
                        $postingId = $this->parsePostingIdFromLocator($sourceLocator);
                }
                if ($blogObjId <= 0) {
                        $blogObjId = $this->parseBlogObjIdFromLocator($sourceLocator);
                }
                if ($postingId <= 0) {
                        return [];
                }

                $rows = $this->queryAll(
                        "SELECT
                                b.id AS posting_id,
                                b.blog_id,
                                b.title AS posting_title,
                                b.created AS posting_created,
                                p.rendered_content,
                                p.content
                        FROM il_blog_posting b
                        LEFT JOIN page_object p
                                ON p.page_id = b.id
                                AND p.parent_id = b.blog_id
                                AND p.parent_type = '" . $this->esc(self::PARENT_TYPE) . "'
                        WHERE b.id = " . (int)$postingId . "
                        LIMIT 1"
                );

                $r = $rows[0] ?? null;
                if (!$r) {
                        return [];
                }

                // CONTRACT: title MUST be string
                $title = trim((string)($r['posting_title'] ?? ''));
                if ($title === '') {
                        $title = 'Blog Posting #' . $postingId;
                }

                $rendered = (string)($r['rendered_content'] ?? '');
                $raw = (string)($r['content'] ?? '');

                return [
                        'type' => 'blog_posting',
                        'posting_id' => (int)$r['posting_id'],
                        'blog_obj_id' => (int)($r['blog_id'] ?? $blogObjId),
                        'source_locator' => $sourceLocator,
                        'title' => $title,
                        'content' => $rendered !== '' ? $rendered : $raw,
                        'meta' => [
                                'posting_created' => $this->nullIfEmpty((string)($r['posting_created'] ?? '')),
                        ]
                ];
        }

        public function fetchReadRoles(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): array {
                $blogObjId = $containerObjId > 0
                        ? (int)$containerObjId
                        : $this->parseBlogObjIdFromLocator($sourceLocator);

                if ($blogObjId <= 0) {
                        return [];
                }

                $refIds = \ilObject::_getAllReferences($blogObjId);
                if (!$refIds) {
                        return [];
                }

                global $DIC;
                $review = $DIC->rbac()->review();

                $readOpsId = $this->getReadOpsIdForType('blog');
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

                return 'goto.php/blog/' . (int)$refIds[0] . '/' . (int)$sourceIntId;
        }

        /* ---------- Helpers ---------- */

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

        private function parseBlogObjIdFromLocator(string $locator): int {
                $p = explode(':', trim($locator));
                return ($p[0] ?? '') === 'blog' ? (int)($p[1] ?? 0) : 0;
        }

        private function parsePostingIdFromLocator(string $locator): int {
                $p = explode(':', trim($locator));
                return (int)($p[2] ?? 0);
        }

        private function nullIfEmpty(string $v): ?string {
                $v = trim($v);
                return $v !== '' ? $v : null;
        }
}
