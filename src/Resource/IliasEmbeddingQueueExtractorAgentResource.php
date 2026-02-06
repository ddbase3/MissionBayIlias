<?php declare(strict_types=1);

namespace MissionBayIlias\Resource;

use Base3\Api\IClassMap;
use Base3\Database\Api\IDatabase;
use MissionBay\Api\IAgentContext;
use MissionBay\Api\IAgentContentExtractor;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Resource\AbstractAgentResource;
use MissionBayIlias\Api\IContentProvider;
use MissionBayIlias\Api\IObjectTreeResolver;

final class IliasEmbeddingQueueExtractorAgentResource extends AbstractAgentResource implements IAgentContentExtractor {

	private const DEFAULT_CLAIM_LIMIT = 5;
	private const LOCK_MINUTES = 10;
	private const MAX_ATTEMPTS = 5;

	private const COLLECTION_KEY = 'ilias';

	/** canonical delete/replace key */
	private const META_CONTENT_UUID = 'content_uuid';

	/** direct link to the source unit (UI link) */
	private const META_DIRECT_LINK = 'direct_link';

	/** explicit filterable content type (indexed top-level as payload.type) */
	private const META_TYPE = 'type';

	private int $claimLimit = self::DEFAULT_CLAIM_LIMIT;

	public function __construct(
		private readonly IDatabase $db,
		private readonly IAgentConfigValueResolver $resolver,
		private readonly IClassMap $classMap,
		private readonly IObjectTreeResolver $treeResolver,
		?string $id = null
	) {
		parent::__construct($id);
	}

	public static function getName(): string {
		return 'iliasembeddingqueueextractoragentresource';
	}

	public function getDescription(): string {
		return 'Claims embedding jobs from base3_embedding_job and returns AgentContentItem work units (ILIAS).';
	}

	public function setConfig(array $config): void {
		parent::setConfig($config);

		$value = $this->resolver->resolveValue($config['claim_limit'] ?? self::DEFAULT_CLAIM_LIMIT);
		$limit = (int)$value;

		if ($limit <= 0) {
			$limit = self::DEFAULT_CLAIM_LIMIT;
		}

		$this->claimLimit = $limit;
	}

	public function extract(IAgentContext $context): array {
		$this->db->connect();
		if (!$this->db->connected()) {
			return [];
		}

		$ids = $this->claimJobIds($this->claimLimit);
		if (!$ids) {
			return [];
		}

		$jobs = $this->loadClaimedJobs($ids);
		if (!$jobs) {
			return [];
		}

		$providers = $this->getProvidersByKind();
		$out = [];

		foreach ($jobs as $job) {
			$jobType = strtolower((string)($job['job_type'] ?? ''));
			if ($jobType !== 'upsert' && $jobType !== 'delete') {
				$this->failFromJobRow($job, "Extractor: unsupported job_type '{$jobType}'", false);
				continue;
			}

			$jobId = (int)($job['job_id'] ?? 0);
			$kind = trim((string)($job['source_kind'] ?? ''));

			// content_id/content_uuid is validated in buildItemFromJob(); here we only enforce minimal invariants
			if ($jobId <= 0 || $kind === '') {
				$this->failFromJobRow($job, 'Extractor: missing job_id/source_kind', false);
				continue;
			}

			$item = $this->buildItemFromJob($job, $providers);
			if ($item === null) {
				$this->failFromJobRow($job, 'Extractor: missing provider/payload', true);
				continue;
			}

			$out[] = $item;
		}

		return $out;
	}

	public function ack(AgentContentItem $item, array $result = []): void {
		$this->db->connect();
		if (!$this->db->connected()) {
			return;
		}

		$jobId = (int)$item->id;
		if ($jobId <= 0) {
			return;
		}

		$this->markDone($jobId);

		if ($item->isDelete()) {
			$uuidHex = strtoupper(trim((string)($item->metadata[self::META_CONTENT_UUID] ?? '')));
			if ($this->isHex32($uuidHex)) {
				$this->markSeenDeletedAt($uuidHex);
			}
		}
	}

	public function fail(AgentContentItem $item, string $errorMessage, bool $retryHint = true): void {
		$this->db->connect();
		if (!$this->db->connected()) {
			return;
		}

		$jobId = (int)$item->id;
		if ($jobId <= 0) {
			return;
		}

		$attempts = $this->loadAttempts($jobId);
		$this->markFailed($jobId, $attempts, $errorMessage, $retryHint);
	}

	// ---------------------------------------------------------
	// Claiming
	// ---------------------------------------------------------

	private function claimJobIds(int $limit): array {
		$rows = $this->queryAll(
			"SELECT job_id
			 FROM base3_embedding_job
			 WHERE state = 'pending'
			   AND (locked_until IS NULL OR locked_until < NOW())
			 ORDER BY priority DESC, job_id ASC
			 LIMIT " . (int)$limit
		);

		if (!$rows) {
			return [];
		}

		$ids = array_map(static fn($r) => (int)$r['job_id'], $rows);
		if (!$ids) {
			return [];
		}

		$this->exec(
			"UPDATE base3_embedding_job
			 SET state = 'running',
				 locked_until = DATE_ADD(NOW(), INTERVAL " . self::LOCK_MINUTES . " MINUTE),
				 attempts = attempts + 1,
				 updated_at = NOW()
			 WHERE state = 'pending'
			   AND job_id IN (" . implode(',', $ids) . ")"
		);

		return $ids;
	}

	private function loadClaimedJobs(array $ids): array {
		return $this->queryAll(
			"SELECT
					job_id,
					job_type,
					attempts,
					HEX(content_id) AS content_id_hex,
					source_kind,
					source_locator,
					container_obj_id,
					source_int_id,
					source_version,
					source_version_token
			 FROM base3_embedding_job
			 WHERE job_id IN (" . implode(',', array_map('intval', $ids)) . ")
			   AND state = 'running'"
		);
	}

	// ---------------------------------------------------------
	// Item building
	// ---------------------------------------------------------

	private function buildItemFromJob(array $job, array $providersByKind): ?AgentContentItem {
		$jobId = (int)$job['job_id'];
		$jobType = strtolower((string)$job['job_type']);

		$cidHex = strtoupper((string)($job['content_id_hex'] ?? ''));
		$kind = trim((string)($job['source_kind'] ?? ''));
		$locator = trim((string)($job['source_locator'] ?? ''));

		if (!$this->isHex32($cidHex) || $kind === '') {
			return null;
		}

		$contentUuidHex = $cidHex;

		$containerObjId = isset($job['container_obj_id']) ? (int)$job['container_obj_id'] : null;
		if ($containerObjId !== null && $containerObjId <= 0) {
			$containerObjId = null;
		}

		$sourceIntId = isset($job['source_int_id']) ? (int)$job['source_int_id'] : null;
		if ($sourceIntId !== null && $sourceIntId <= 0) {
			$sourceIntId = null;
		}

		$version = trim((string)($job['source_version'] ?? ''));
		$token = trim((string)($job['source_version_token'] ?? '')) ?: null;

		$hash = hash(
			'sha256',
			self::COLLECTION_KEY . ':' . $contentUuidHex . ':' . ($version ?: '-') . ':' . ($token ?: '-')
		);

		$meta = [
			self::META_CONTENT_UUID => $contentUuidHex,
			'source_kind' => $kind,
			'source_locator' => $locator ?: null,
			'container_obj_id' => $containerObjId,
			'source_int_id' => $sourceIntId,
			'source_version' => $version ?: null,
			'source_version_token' => $token,
			self::META_TYPE => $kind,
		];

		if ($jobType === 'delete') {
			return new AgentContentItem(
				action: 'delete',
				collectionKey: self::COLLECTION_KEY,
				id: (string)$jobId,
				hash: $hash,
				contentType: 'application/x-embedding-job-delete',
				content: '',
				isBinary: false,
				size: 0,
				metadata: $meta
			);
		}

		$provider = $providersByKind[$kind] ?? null;
		if (!$provider) {
			return null;
		}

		$contentPayload = $provider->fetchContent($locator, $containerObjId, $sourceIntId);
		if (!$contentPayload) {
			return null;
		}

		// Enforce indexed title contract
		$title = $contentPayload['title'] ?? null;
		if (is_string($title) && trim($title) !== '') {
			$meta['title'] = trim($title);
		}

		$readRoles = $provider->fetchReadRoles($locator, $containerObjId, $sourceIntId);

		$mountRefIds = $containerObjId ? $this->treeResolver->getRefIdsByObjId($containerObjId) : [];
		$ancestorRefIds = $containerObjId ? $this->treeResolver->getAllAncestorPathRefIdsByObjId($containerObjId) : [];

		$directLink = '';
		try {
			$directLink = $provider->getDirectLink($locator, $containerObjId, $sourceIntId);
		} catch (\Throwable) {
			$directLink = '';
		}
		$directLink = trim((string)$directLink) ?: null;

		$meta['read_roles'] = $readRoles;
		$meta['mount_ref_ids'] = $mountRefIds;
		$meta['ancestor_ref_ids'] = $ancestorRefIds;
		$meta[self::META_DIRECT_LINK] = $directLink;

		$content = [
			'system' => 'ilias',
			'kind' => $kind,
			'locator' => $locator,
			'content_uuid' => $contentUuidHex,
			'container_obj_id' => $containerObjId,
			'source_int_id' => $sourceIntId,
			'version' => $version ?: null,
			'version_token' => $token,
			'read_roles' => $readRoles,
			'mount_ref_ids' => $mountRefIds,
			'ancestor_ref_ids' => $ancestorRefIds,
			'direct_link' => $directLink,
			'content' => $contentPayload,
		];

		$json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return new AgentContentItem(
			action: 'upsert',
			collectionKey: self::COLLECTION_KEY,
			id: (string)$jobId,
			hash: $hash,
			contentType: 'application/x-ilias-content-json',
			content: $content,
			isBinary: false,
			size: is_string($json) ? strlen($json) : 0,
			metadata: $meta
		);
	}

	// ---------------------------------------------------------
	// Helpers / state
	// ---------------------------------------------------------

	private function isHex32(string $hex): bool {
		return strlen($hex) === 32 && ctype_xdigit($hex);
	}

	private function loadAttempts(int $jobId): int {
		$row = $this->queryOne("SELECT attempts FROM base3_embedding_job WHERE job_id = {$jobId} LIMIT 1");
		return (int)($row['attempts'] ?? 0);
	}

	private function markDone(int $jobId): void {
		$this->exec(
			"UPDATE base3_embedding_job
			 SET state = 'done', locked_until = NULL, updated_at = NOW(), error_message = NULL
			 WHERE job_id = {$jobId}"
		);
	}

	private function markFailed(int $jobId, int $attempts, string $msg, bool $retryHint): void {
		$msg = mb_substr($msg, 0, 4000);
		$final = (!$retryHint) || ($attempts >= self::MAX_ATTEMPTS);

		$this->exec(
			"UPDATE base3_embedding_job
			 SET state = '" . ($final ? 'error' : 'pending') . "',
				 locked_until = NULL,
				 updated_at = NOW(),
				 error_message = '" . $this->esc($msg) . "'
			 WHERE job_id = {$jobId}"
		);
	}

	private function markSeenDeletedAt(string $uuidHex): void {
		$this->exec(
			"UPDATE base3_embedding_seen
			 SET deleted_at = NOW()
			 WHERE content_id = UNHEX('" . $this->esc($uuidHex) . "')"
		);
	}

	private function failFromJobRow(array $job, string $msg, bool $retryHint): void {
		$this->markFailed((int)$job['job_id'], (int)($job['attempts'] ?? 0), $msg, $retryHint);
	}

	private function getProvidersByKind(): array {
		$instances = $this->classMap->getInstances(['interface' => IContentProvider::class]);
		$out = [];

		foreach ($instances as $instance) {
			if ($instance instanceof IContentProvider && $instance->isActive()) {
				$out[$instance->getSourceKind()] = $instance;
			}
		}

		return $out;
	}

	private function exec(string $sql): void {
		$this->db->nonQuery($sql);
	}

	private function queryAll(string $sql): array {
		return $this->db->multiQuery($sql) ?: [];
	}

	private function queryOne(string $sql): ?array {
		return $this->db->singleQuery($sql);
	}

	private function esc(string $value): string {
		return (string)$this->db->escape($value);
	}
}
