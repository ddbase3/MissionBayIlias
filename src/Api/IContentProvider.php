<?php declare(strict_types=1);

namespace MissionBayIlias\Api;

use Base3\Api\IBase;
use MissionBayIlias\Dto\ContentBatchDto;
use MissionBayIlias\Dto\ContentCursorDto;

/**
 * Interface IContentProvider
 *
 * A provider enumerates "true content units" for exactly one source_kind
 * (e.g. "wiki_page", "blog", "blog_posting").
 *
 * It MUST:
 * - support incremental scanning via a cursor
 * - provide deletion detection
 *
 * Additionally it MUST be able to resolve:
 * - the actual content body for embedding / retrieval
 * - the read roles (ACL) for that unit
 *
 * The extractor will call these methods using the data already stored in
 * base3_embedding_job (source_locator, container_obj_id, source_int_id).
 * No duplicate DTO is required.
 *
 * IMPORTANT CONTRACT for fetchContent():
 * The returned payload MUST include a human-readable title:
 *
 *   [
 *     'title'   => string,   // REQUIRED, non-empty if available
 *     'content' => mixed,    // REQUIRED, provider-defined content body
 *     ...
 *   ]
 *
 * The title is promoted by the extractor into embedding metadata and written
 * as payload.title (indexed) by the RAG payload normalizer.
 */
interface IContentProvider extends IBase {

        public function isActive();

        /**
         * Usually "ilias".
         */
        public function getSourceSystem(): string;

        /**
         * Canonical taxonomy key, e.g. "wiki_page", "blog_posting", "file".
         */
        public function getSourceKind(): string;

        /**
         * Returns a batch of changed/new content units after $cursor.
         * Providers MUST use a stable ordering and a deterministic tie-breaker
         * for equal timestamps.
         */
        public function fetchChanged(ContentCursorDto $cursor, int $limit): ContentBatchDto;

        /**
         * Returns source_int_ids that are missing in the source system
         * but were seen before.
         *
         * The provider typically queries its own source tables and the shared
         * base3_embedding_seen table.
         *
         * @return int[] list of missing source_int_id values
         */
        public function fetchMissingSourceIntIds(int $limit): array;

        /**
         * Resolve the actual content body for one content unit.
         *
         * The returned array MUST contain at least:
         * - 'title'   => string
         * - 'content' => mixed
         *
         * Additional fields are provider-defined.
         */
        public function fetchContent(
                string $sourceLocator,
                ?int $containerObjId,
                ?int $sourceIntId
        ): array;

        /**
         * Resolve read roles (ACL) for one content unit.
         *
         * Returned values MUST be stable role IDs usable by the permission system.
         *
         * @return int[] role_ids
         */
        public function fetchReadRoles(
                string $sourceLocator,
                ?int $containerObjId,
                ?int $sourceIntId
        ): array;

        /**
         * Resolve a direct, user-facing link to the original content unit.
         *
         * The link MUST be stable and point to the canonical UI location
         * (e.g. ILIAS permalink, wiki page view, blog posting view, file download).
         */
        public function getDirectLink(
                string $sourceLocator,
                ?int $containerObjId,
                ?int $sourceIntId
        ): string;
}
