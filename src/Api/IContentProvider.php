<?php declare(strict_types=1);

namespace MissionBayIlias\Api;

use Base3\Api\IBase;

/**
 * Interface IContentProvider
 *
 * A provider enumerates "true content units" for one source_kind (e.g. wiki_page).
 * It MUST support incremental scanning via a cursor and MUST provide deletion detection.
 *
 * Additionally it MUST be able to resolve:
 * - the actual content body for embedding/retrieval
 * - the read roles (ACL) for that unit
 *
 * The extractor will call these methods using the data already stored in base3_embedding_job
 * (source_locator, container_obj_id, source_int_id). No duplicate DTO required.
 */
interface IContentProvider extends IBase {

	public function isActive();

	/**
	 * Usually "ilias".
	 */
	public function getSourceSystem(): string;

	/**
	 * Canonical taxonomy key, e.g. "wiki_page", "blog_post", "file".
	 */
	public function getSourceKind(): string;

	/**
	 * Returns a batch of changed/new content units after $cursor.
	 * Providers must use a stable ordering and tie-breaker for equal timestamps.
	 */
	public function fetchChanged(ContentCursor $cursor, int $limit): ContentBatch;

	/**
	 * Returns source-int-ids that are missing in the source system but were seen before.
	 * The provider typically queries the provider-specific source table(s) and the shared
	 * base3_embedding_seen table.
	 *
	 * @return int[] list of missing source_int_id values
	 */
	public function fetchMissingSourceIntIds(int $limit): array;

	/**
	 * Resolve the actual content body for one content unit.
	 * Return value is provider-defined; extractor will normalize/flatten as needed.
	 *
	 * @return array arbitrary payload (assoc or numeric)
	 */
	public function fetchContent(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): array;

	/**
	 * Resolve read roles (ACL) for one content unit.
	 * Returned values MUST be stable IDs usable by your permission system.
	 *
	 * @return int[] role_ids
	 */
	public function fetchReadRoles(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): array;

	/**
	 * Resolve a direct, user-facing link to the original content unit.
	 *
	 * The link MUST be stable and point to the canonical UI location
	 * (e.g. ILIAS permalink, wiki page view, file download page, etc.).
	 *
	 * @return string absolute or relative URL
	 */
	public function getDirectLink(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): string;
}
