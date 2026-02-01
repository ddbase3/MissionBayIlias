<?php declare(strict_types=1);

namespace MissionBayIlias\Provider;

use Base3\Database\Api\IDatabase;
use MissionBayIlias\Api\IObjectTreeResolver;
use MissionBayIlias\Dto\ContentBatchDto;
use MissionBayIlias\Dto\ContentCursorDto;
use MissionBayIlias\Dto\ContentUnitDto;

final class BlogContentProvider extends AbstractContentProvider {

        private const SOURCE_SYSTEM = 'ilias';
        private const SOURCE_KIND = 'blog';

        public function __construct(
                private readonly IDatabase $db,
                private readonly IObjectTreeResolver $objectTreeResolver
        ) {}

        public static function getName(): string {
                return 'blogcontentprovider';
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
                                o.create_date
                        FROM object_data o
                        WHERE o.type = 'blog'
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

                        $title = $this->nullIfEmpty((string)($row['title'] ?? ''));
                        $desc  = $this->nullIfEmpty((string)($row['description'] ?? ''));

                        $locator = 'blog:' . $objId;

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

                return new ContentBatchDto($units, new ContentCursorDto($maxTs, $maxId));
        }

        public function fetchMissingSourceIntIds(int $limit): array {
                $limit = max(1, (int)$limit);

                $rows = $this->queryAll(
                        "SELECT s.source_int_id
                         FROM base3_embedding_seen s
                         LEFT JOIN object_data o
                           ON o.obj_id = s.source_int_id
                          AND o.type = 'blog'
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
                          AND o.type = 'blog'
                        LIMIT 1"
                );

                $r = $rows[0] ?? null;
                if (!$r) {
                        return [];
                }

                // CONTRACT: title MUST be string
                $title = trim((string)($r['title'] ?? ''));
                if ($title === '') {
                        $title = 'Blog #' . $objId;
                }

                return [
                        'type' => 'blog',
                        'obj_id' => (int)$r['obj_id'],
                        'source_locator' => $sourceLocator,
                        'title' => $title,
                        'description' => $this->nullIfEmpty((string)($r['description'] ?? '')),
                        'content' => '',
                        'meta' => [
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
                if ($containerObjId === null) {
                        return '';
                }

                $refIds = $this->objectTreeResolver->getRefIdsByObjId($containerObjId);
                if ($refIds === []) {
                        return '';
                }

                return 'goto.php/blog/' . (int)$refIds[0];
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

        private function parseObjIdFromLocator(string $locator): int {
                $parts = explode(':', trim($locator));
                return ($parts[0] ?? '') === 'blog' ? (int)($parts[1] ?? 0) : 0;
        }

        private function nullIfEmpty(string $v): ?string {
                $v = trim($v);
                return $v !== '' ? $v : null;
        }
}
