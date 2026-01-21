<?php declare(strict_types=1);

namespace MissionBayIlias\Job;

use Base3\Worker\Api\IJob;
use Base3\Database\Api\IDatabase;
use Base3\State\Api\IStateStore;
use Base3\Api\IClassMap;
use MissionBayIlias\Api\IContentProvider;
use MissionBayIlias\Api\ContentCursor;
use MissionBayIlias\Api\ContentUnit;

final class IliasEmbeddingEnqueueJob implements IJob {

	private const MIN_INTERVAL_SECONDS = 900;

	private const CHANGED_BATCH = 5000;
	private const DELETE_BATCH = 2000;

	private const DEFAULT_LAST_RUN_AT = '1970-01-01 00:00:00';

	/** later config */
	private const SOURCE_KIND_WHITELIST = [
		'wiki' => true,
		'wiki_page' => true,
	];

	public function __construct(
		private readonly IDatabase $db,
		private readonly IStateStore $state,
		private readonly IClassMap $classMap
	) {}

	public static function getName(): string {
		return 'iliasembeddingenqueuejob';
	}

	public function isActive() {
		return true;
	}

	public function getPriority() {
		return 2;
	}

	public function go() {
		$this->db->connect();
		if (!$this->db->connected()) {
			return 'DB not connected';
		}

		$this->ensureTables();

		$lastRunAt = (string)$this->state->get($this->stateKeyGlobal('last_run_at'), self::DEFAULT_LAST_RUN_AT);
		if (!$this->shouldRun($lastRunAt)) {
			return 'Skip';
		}

		$providers = $this->getProviders();

		$changed = 0;
		$deleted = 0;
		$errors = 0;

		foreach ($providers as $provider) {
			if (!$provider->isActive()) {
				continue;
			}

			$kind = $provider->getSourceKind();
			if (!$this->isWhitelisted($kind)) {
				continue;
			}

			try {
				$changed += $this->processProviderChanged($provider, self::CHANGED_BATCH);
				$deleted += $this->processProviderDeletes($provider, self::DELETE_BATCH);
			} catch (\Throwable $e) {
				$errors++;
				// intentionally keep going; failure isolation per provider
			}
		}

		$this->state->set($this->stateKeyGlobal('last_run_at'), $this->now());

		return "enqueue done - changed:$changed deleted:$deleted providers:" . count($providers) . " errors:$errors";
	}

	/* ================= Providers ================= */

	/**
	 * @return IContentProvider[]
	 */
	private function getProviders(): array {
		$instances = $this->classMap->getInstances([
			'interface' => IContentProvider::class
		]);

		$providers = [];
		foreach ($instances as $instance) {
			if ($instance instanceof IContentProvider) {
				$providers[] = $instance;
			}
		}

		return $providers;
	}

	/* ================= Changed ================= */

	private function processProviderChanged(IContentProvider $provider, int $limit): int {
		$cursor = $this->loadCursor($provider);

		$batch = $provider->fetchChanged($cursor, $limit);
		$units = $batch->units;

		if (!$units) {
			$this->saveCursor($provider, $batch->nextCursor);
			return 0;
		}

		// Build deterministic content_ids and prefetch seen states in one query
		$hexIds = [];
		$unitByHex = [];

		foreach ($units as $u) {
			if (!$u instanceof ContentUnit) {
				continue;
			}

			if (!$this->isWhitelisted($u->sourceKind)) {
				continue;
			}

			$hex = $this->contentIdHex($u->sourceSystem, $u->sourceKind, $u->sourceLocator);
			$hexIds[$hex] = true;
			$unitByHex[$hex] = $u;
		}

		if (!$hexIds) {
			$this->saveCursor($provider, $batch->nextCursor);
			return 0;
		}

		$seenMap = $this->loadSeenMap(array_keys($hexIds));

		$cnt = 0;

		foreach ($unitByHex as $cidHex => $u) {
			$version = $this->normalizeVersion($u->contentUpdatedAt);
			$token = $u->contentVersionToken !== null && trim($u->contentVersionToken) !== '' ? trim($u->contentVersionToken) : null;

			$seen = $seenMap[$cidHex] ?? null;

			// HARD VERSION CHECK
			// - if token is present and matches, require both version+token match
			// - if token missing, fall back to version equality
			if ($seen !== null) {
				$seenVersion = (string)($seen['last_seen_version'] ?? '');
				$seenToken = (string)($seen['last_seen_version_token'] ?? '');

				if ($token !== null && $token !== '') {
					if ($seenVersion === $version && $seenToken !== '' && $seenToken === $token) {
						continue;
					}
				} else {
					if ($seenVersion === $version) {
						continue;
					}
				}
			}

			$this->upsertSeenFromUnit($cidHex, $u, $version, $token);
			$this->supersedePendingUpserts($cidHex, $u->sourceKind);
			$this->insertUpsertJob($cidHex, $u->sourceKind, $u->sourceLocator, $u->containerObjId, $version, $token);

			$cnt++;
		}

		$this->saveCursor($provider, $batch->nextCursor);

		return $cnt;
	}

	private function loadSeenMap(array $hexIds): array {
		$chunks = array_chunk($hexIds, 800); // avoid giant IN() lists
		$map = [];

		foreach ($chunks as $chunk) {
			$in = $this->buildUnhexInList($chunk);

			$rows = $this->queryAll(
				"SELECT
					HEX(content_id) cid,
					last_seen_version,
					last_seen_version_token,
					missing_since,
					deleted_at
				 FROM base3_embedding_seen
				 WHERE content_id IN ($in)"
			);

			foreach ($rows as $r) {
				$cid = strtoupper((string)($r['cid'] ?? ''));
				if ($cid !== '' && strlen($cid) === 32) {
					$map[$cid] = $r;
				}
			}
		}

		return $map;
	}

	/* ================= Deletes ================= */

	private function processProviderDeletes(IContentProvider $provider, int $limit): int {
		$missingIds = $provider->fetchMissingSourceIntIds($limit);
		if (!$missingIds) {
			return 0;
		}

		$kind = $provider->getSourceKind();
		if (!$this->isWhitelisted($kind)) {
			return 0;
		}

		$ids = [];
		foreach ($missingIds as $id) {
			$id = (int)$id;
			if ($id > 0) {
				$ids[$id] = true;
			}
		}

		if (!$ids) {
			return 0;
		}

		$rows = $this->queryAll(
			"SELECT
				HEX(content_id) cid,
				source_locator,
				container_obj_id,
				last_seen_version
			 FROM base3_embedding_seen
			 WHERE source_system = '" . $this->esc($provider->getSourceSystem()) . "'
			   AND source_kind = '" . $this->esc($kind) . "'
			   AND source_int_id IN (" . implode(',', array_map('intval', array_keys($ids))) . ")
			   AND missing_since IS NULL
			   AND deleted_at IS NULL
			 LIMIT " . (int)$limit
		);

		$cnt = 0;

		foreach ($rows as $r) {
			$cid = strtoupper((string)($r['cid'] ?? ''));
			if ($cid === '' || strlen($cid) !== 32) {
				continue;
			}

			$containerObjId = isset($r['container_obj_id']) ? (int)$r['container_obj_id'] : null;
			if ($containerObjId !== null && $containerObjId <= 0) {
				$containerObjId = null;
			}

			$this->supersedePendingUpserts($cid, $kind);
			$this->markMissing($cid);

			$this->insertDeleteJob(
				$cid,
				$kind,
				(string)($r['source_locator'] ?? ''),
				$containerObjId,
				(string)($r['last_seen_version'] ?? '')
			);

			$cnt++;
		}

		return $cnt;
	}

	/* ================= Seen / Jobs ================= */

	private function supersedePendingUpserts(string $cidHex, string $kind): void {
		$this->exec(
			"UPDATE base3_embedding_job
			 SET state='superseded', updated_at=NOW()
			 WHERE content_id = UNHEX('" . $this->esc($cidHex) . "')
			   AND source_kind = '" . $this->esc($kind) . "'
			   AND job_type='upsert'
			   AND state='pending'"
		);
	}

	private function upsertSeenFromUnit(string $cidHex, ContentUnit $u, string $version, ?string $token): void {
		$this->exec(
			"INSERT INTO base3_embedding_seen
					(content_id, source_system, source_kind, source_locator, container_obj_id, source_int_id,
					 last_seen_version, last_seen_version_token, last_seen_at, missing_since, delete_job_id, deleted_at)
			 VALUES
					(UNHEX('" . $this->esc($cidHex) . "'),
					 '" . $this->esc($u->sourceSystem) . "',
					 '" . $this->esc($u->sourceKind) . "',
					 '" . $this->esc($u->sourceLocator) . "',
					 " . ($u->containerObjId !== null ? (int)$u->containerObjId : "NULL") . ",
					 " . ($u->sourceIntId !== null ? (int)$u->sourceIntId : "NULL") . ",
					 '" . $this->esc($version) . "',
					 " . ($token !== null ? "'" . $this->esc($token) . "'" : "NULL") . ",
					 NOW(),
					 NULL,
					 NULL,
					 NULL)
			 ON DUPLICATE KEY UPDATE
					 source_system = VALUES(source_system),
					 source_kind = VALUES(source_kind),
					 source_locator = VALUES(source_locator),
					 container_obj_id = VALUES(container_obj_id),
					 source_int_id = VALUES(source_int_id),
					 last_seen_version = VALUES(last_seen_version),
					 last_seen_version_token = VALUES(last_seen_version_token),
					 last_seen_at = NOW(),
					 missing_since = NULL,
					 delete_job_id = NULL,
					 deleted_at = NULL"
		);
	}

	private function insertUpsertJob(string $cidHex, string $kind, string $locator, ?int $containerObjId, string $version, ?string $token): void {
		$this->exec(
			"INSERT IGNORE INTO base3_embedding_job
					(content_id, source_kind, source_locator, container_obj_id,
					 source_version, source_version_token,
					 job_type, state, priority, attempts, locked_until, claim_token, claimed_at,
					 created_at, updated_at, error_message)
			 VALUES
					(UNHEX('" . $this->esc($cidHex) . "'),
					 '" . $this->esc($kind) . "',
					 '" . $this->esc($locator) . "',
					 " . ($containerObjId !== null ? (int)$containerObjId : "NULL") . ",
					 '" . $this->esc($version) . "',
					 " . ($token !== null ? "'" . $this->esc($token) . "'" : "NULL") . ",
					 'upsert','pending',1,0,NULL,NULL,NULL,
					 NOW(),NOW(),NULL)"
		);
	}

	private function insertDeleteJob(string $cidHex, string $kind, string $locator, ?int $containerObjId, string $version): void {
		if (trim($version) === '') {
			$version = $this->now();
		}

		$this->exec(
			"INSERT IGNORE INTO base3_embedding_job
					(content_id, source_kind, source_locator, container_obj_id,
					 source_version,
					 job_type, state, priority, attempts, locked_until, claim_token, claimed_at,
					 created_at, updated_at, error_message)
			 VALUES
					(UNHEX('" . $this->esc($cidHex) . "'),
					 '" . $this->esc($kind) . "',
					 '" . $this->esc($locator) . "',
					 " . ($containerObjId !== null ? (int)$containerObjId : "NULL") . ",
					 '" . $this->esc($version) . "',
					 'delete','pending',1,0,NULL,NULL,NULL,
					 NOW(),NOW(),NULL)"
		);
	}

	private function markMissing(string $cidHex): void {
		$this->exec(
			"UPDATE base3_embedding_seen
			 SET missing_since = NOW()
			 WHERE content_id = UNHEX('" . $this->esc($cidHex) . "')
			   AND missing_since IS NULL"
		);
	}

	/* ================= Cursor / State ================= */

	private function loadCursor(IContentProvider $provider): ContentCursor {
		$raw = (string)$this->state->get($this->stateKeyProvider($provider, 'cursor'), '');
		return ContentCursor::fromString($raw);
	}

	private function saveCursor(IContentProvider $provider, ContentCursor $cursor): void {
		$this->state->set($this->stateKeyProvider($provider, 'cursor'), $cursor->toString());
	}

	private function stateKeyGlobal(string $s): string {
		return 'missionbay.embedding.enqueue.' . $s;
	}

	private function stateKeyProvider(IContentProvider $p, string $s): string {
		return 'missionbay.embedding.enqueue.provider.' . $p::getName() . '.' . $p->getSourceSystem() . '.' . $p->getSourceKind() . '.' . $s;
	}

	private function shouldRun(string $raw): bool {
		$ts = strtotime($raw);
		return $ts === false || (time() - $ts) >= self::MIN_INTERVAL_SECONDS;
	}

	private function now(): string {
		return date('Y-m-d H:i:s');
	}

	/* ================= Whitelist ================= */

	private function isWhitelisted(string $kind): bool {
		return isset(self::SOURCE_KIND_WHITELIST[$kind]) && self::SOURCE_KIND_WHITELIST[$kind] === true;
	}

	/* ================= Schema ================= */

	private function ensureTables(): void {
		$this->exec($this->getSeenTableSql());
		$this->exec($this->getJobTableSql());
	}

	private function getSeenTableSql(): string {
		return "CREATE TABLE IF NOT EXISTS base3_embedding_seen (
			content_id BINARY(16) NOT NULL,
			source_system VARCHAR(32) NOT NULL,
			source_kind VARCHAR(32) NOT NULL,
			source_locator VARCHAR(255) NOT NULL,
			container_obj_id INT NULL,
			source_int_id INT NULL,

			last_seen_version DATETIME NOT NULL,
			last_seen_version_token VARCHAR(64) NULL,
			last_seen_at DATETIME NOT NULL,

			missing_since DATETIME NULL,
			delete_job_id BIGINT NULL,
			deleted_at DATETIME NULL,

			PRIMARY KEY (content_id),
			UNIQUE KEY uq_source (source_system, source_kind, source_locator),
			KEY ix_kind (source_kind),
			KEY ix_source_int (source_kind, source_int_id),
			KEY ix_container (container_obj_id),
			KEY ix_missing (missing_since),
			KEY ix_delete_job (delete_job_id),
			KEY ix_last_seen (last_seen_at)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
	}

	private function getJobTableSql(): string {
		return "CREATE TABLE IF NOT EXISTS base3_embedding_job (
			job_id BIGINT NOT NULL AUTO_INCREMENT,
			content_id BINARY(16) NOT NULL,
			source_kind VARCHAR(32) NOT NULL,
			source_locator VARCHAR(255) NULL,
			container_obj_id INT NULL,
			source_version DATETIME NULL,
			source_version_token VARCHAR(64) NULL,
			job_type ENUM('upsert','delete') NOT NULL,
			state ENUM('pending','running','done','error','superseded') NOT NULL DEFAULT 'pending',
			priority TINYINT NOT NULL DEFAULT 1,
			attempts INT NOT NULL DEFAULT 0,
			locked_until DATETIME NULL,
			claim_token CHAR(36) NULL,
			claimed_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			error_message TEXT NULL,
			PRIMARY KEY (job_id),
			UNIQUE KEY uq_job (content_id, source_version, job_type),
			KEY ix_claim (state, priority, locked_until, updated_at),
			KEY ix_claim_token (claim_token),
			KEY ix_kind (source_kind),
			KEY ix_content (content_id, job_type),
			KEY ix_container (container_obj_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
	}

	/* ================= Helpers ================= */

	private function normalizeVersion(?string $raw): string {
		$raw = trim((string)$raw);
		return $raw !== '' ? $raw : $this->now();
	}

	private function contentIdHex(string $sourceSystem, string $sourceKind, string $sourceLocator): string {
		// Deterministic 16 bytes id (32 hex chars) from canonical identity
		$base = $sourceSystem . '|' . $sourceKind . '|' . $sourceLocator;
		return strtoupper(md5($base));
	}

	private function buildUnhexInList(array $hexIds): string {
		$parts = [];
		foreach ($hexIds as $hex) {
			$hex = strtoupper(trim((string)$hex));
			if ($hex !== '' && strlen($hex) === 32 && ctype_xdigit($hex)) {
				$parts[] = "UNHEX('" . $this->esc($hex) . "')";
			}
		}

		return $parts ? implode(',', $parts) : "UNHEX('00000000000000000000000000000000')";
	}

	private function exec(string $sql): void {
		$this->db->nonQuery($sql);
	}

	private function queryAll(string $sql): array {
		return $this->db->multiQuery($sql) ?: [];
	}

	private function esc(string $v): string {
		return (string)$this->db->escape($v);
	}
}
