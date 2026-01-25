<?php declare(strict_types=1);

namespace MissionBayIlias\Job;

use Base3\Worker\Api\IJob;
use Base3\Configuration\Api\IConfiguration;
use Base3\Database\Api\IDatabase;
use Base3\State\Api\IStateStore;
use Base3\Api\IClassMap;
use MissionBayIlias\Api\IContentProvider;
use MissionBayIlias\Dto\ContentCursorDto;
use MissionBayIlias\Dto\ContentUnitDto;

final class IliasEmbeddingEnqueueJob implements IJob {

	private const MIN_INTERVAL_SECONDS = 900;

	private const CHANGED_BATCH = 5000;
	private const DELETE_BATCH = 2000;

	private const DEFAULT_LAST_RUN_AT = '1970-01-01 00:00:00';
	private const DEFAULT_PRIORITY = 2;

	private const BULK_DELETE_BATCH = 5000;
	private const STATE_BULK_DELETE_AFTER_HEX = 'bulk_delete_after_hex';

	private ?array $missionbayIliasConf = null;

	/** later config */
	private const SOURCE_KIND_WHITELIST = [
		'cat' => false,
		'crs' => false,
		'blog' => false,
		'blog_posting' => false,
		'glo' => false,
		'glo_term' => false,
		'wiki' => true,
		'wiki_page' => true,
	];

	public function __construct(
		private readonly IConfiguration $configuration,
		private readonly IDatabase $db,
		private readonly IStateStore $state,
		private readonly IClassMap $classMap
	) {}

	public static function getName(): string {
		return 'iliasembeddingenqueuejob';
	}

	public function isActive() {
		$conf = $this->getMissionbayIliasConf();
		return ((int)($conf['iliasembeddingenqueuejob.active'] ?? 0)) === 1;
	}

	public function getPriority() {
		$conf = $this->getMissionbayIliasConf();
		return (int)($conf['iliasembeddingenqueuejob.priority'] ?? self::DEFAULT_PRIORITY);
	}

	public function go() {
		$this->db->connect();
		if (!$this->db->connected()) {
			return 'DB not connected';
		}

		$this->ensureTables();

		$lastRunAt = (string)$this->state->get(
			$this->stateKeyGlobal('last_run_at'),
			self::DEFAULT_LAST_RUN_AT
		);

		if (!$this->shouldRun($lastRunAt)) {
			return 'Skip';
		}

		$providers = $this->getProviders();

		// Handle config toggles (enable / disable kinds)
		$toggles = $this->syncKindEnabledTransitions($providers);

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
			} catch (\Throwable $e) {
				$errors++;
			}

			try {
				$deleted += $this->processProviderDeletes($provider, self::DELETE_BATCH);
			} catch (\Throwable $e) {
				$errors++;
			}
		}

		$this->state->set($this->stateKeyGlobal('last_run_at'), $this->now());

		return "enqueue done - changed:$changed deleted:$deleted providers:" . count($providers) . " errors:$errors toggles:$toggles";
	}

	private function getMissionbayIliasConf(): array {
		if ($this->missionbayIliasConf === null) {
			$this->missionbayIliasConf = (array)$this->configuration->get('job');
		}
		return $this->missionbayIliasConf;
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

	/* ================= Toggle handling ================= */

	private function syncKindEnabledTransitions(array $providers): int {
		$kinds = [];

		foreach ($providers as $p) {
			if ($p instanceof IContentProvider) {
				$kinds[$p->getSourceKind()] = true;
			}
		}

		// Also include whitelist keys, so toggles can act even without a provider instance.
		foreach (array_keys(self::SOURCE_KIND_WHITELIST) as $k) {
			$kinds[$k] = true;
		}

		$actions = 0;

		foreach (array_keys($kinds) as $kind) {
			$nowEnabled = $this->isWhitelisted($kind);

			$stateKey = $this->stateKeyKind($kind, 'enabled');
			$prevRaw = $this->state->get($stateKey, null);

			// First run: initialize without triggering a transition.
			if ($prevRaw === null) {
				$this->state->set($stateKey, $nowEnabled ? '1' : '0');
				continue;
			}

			$prevEnabled = ((string)$prevRaw) === '1';
			if ($prevEnabled === $nowEnabled) {
				continue;
			}

			if ($prevEnabled && !$nowEnabled) {
				// Disable => immediately supersede pending upserts for this kind.
				$this->supersedeAllPendingUpsertsForKind($kind);

				// Disable => delete everything already known for this kind (stable paging).
				$this->resetBulkDeleteCursorForKind($kind);
				$this->bulkEnqueueDeletesForKindStable('ilias', $kind, $kind, self::BULK_DELETE_BATCH);

				// Remove kind from system state.
				$this->resetProviderCursorsForKind($providers, $kind);
				$this->deleteSeenRowsForKind('ilias', $kind);
				$this->resetBulkDeleteCursorForKind($kind);

				$actions++;
			}

			if (!$prevEnabled && $nowEnabled) {
				// Re-enable => clean existing job rows for this kind first.
				// Reason: uq_job(content_id, source_version, job_type) + INSERT IGNORE would otherwise
				// silently prevent re-enqueueing the same items (same version) after a disable/enable cycle.
				$this->deleteJobsForKind($kind);

				// Enable => start a full rebuild from scratch.
				$this->resetProviderCursorsForKind($providers, $kind);

				$actions++;
			}

			$this->state->set($stateKey, $nowEnabled ? '1' : '0');
		}

		return $actions;
	}

	private function stateKeyKind(string $kind, string $s): string {
		return 'missionbay.embedding.enqueue.kind.' . $kind . '.' . $s;
	}

	private function resetProviderCursorsForKind(array $providers, string $kind): void {
		foreach ($providers as $p) {
			if (!$p instanceof IContentProvider) {
				continue;
			}
			if ($p->getSourceKind() !== $kind) {
				continue;
			}
			$this->stateDelete($this->stateKeyProvider($p, 'cursor'));
		}
	}

	private function resetBulkDeleteCursorForKind(string $kind): void {
		$this->stateDelete($this->stateKeyKind($kind, self::STATE_BULK_DELETE_AFTER_HEX));
	}

	private function supersedeAllPendingUpsertsForKind(string $kind): void {
		$this->exec(
			"UPDATE base3_embedding_job
			 SET state='superseded', updated_at=NOW()
			 WHERE source_kind = '" . $this->esc($kind) . "'
			   AND job_type = 'upsert'
			   AND state = 'pending'"
		);
	}

	private function deleteSeenRowsForKind(string $sourceSystem, string $kind): void {
		$this->exec(
			"DELETE FROM base3_embedding_seen
			 WHERE source_system = '" . $this->esc($sourceSystem) . "'
			   AND source_kind = '" . $this->esc($kind) . "'"
		);
	}

	private function deleteJobsForKind(string $kind): void {
		$this->exec(
			"DELETE FROM base3_embedding_job
			 WHERE source_kind = '" . $this->esc($kind) . "'"
		);
	}

	private function bulkEnqueueDeletesForKindStable(string $sourceSystem, string $kind, string $stateKind, int $batchSize): void {
		$batchSize = $batchSize > 0 ? $batchSize : self::BULK_DELETE_BATCH;

		$afterHex = strtoupper(trim((string)$this->state->get($this->stateKeyKind($stateKind, self::STATE_BULK_DELETE_AFTER_HEX), '')));
		if ($afterHex !== '' && (!ctype_xdigit($afterHex) || strlen($afterHex) !== 32)) {
			$afterHex = '';
		}

		while (true) {
			$whereAfter = '';
			if ($afterHex !== '') {
				$whereAfter = " AND content_id > UNHEX('" . $this->esc($afterHex) . "')";
			}

			$rows = $this->queryAll(
				"SELECT
						HEX(content_id) cid,
						source_locator,
						container_obj_id,
						source_int_id,
						last_seen_version
				 FROM base3_embedding_seen
				 WHERE source_system = '" . $this->esc($sourceSystem) . "'
				   AND source_kind = '" . $this->esc($kind) . "'
				   AND deleted_at IS NULL
				 $whereAfter
				 ORDER BY content_id ASC
				 LIMIT " . (int)$batchSize
			);

			if (!$rows) {
				break;
			}

			foreach ($rows as $r) {
				$cid = strtoupper((string)($r['cid'] ?? ''));
				if ($cid === '' || strlen($cid) !== 32) {
					continue;
				}

				$containerObjId = isset($r['container_obj_id']) ? (int)$r['container_obj_id'] : null;
				if ($containerObjId !== null && $containerObjId <= 0) {
					$containerObjId = null;
				}

				$sourceIntId = isset($r['source_int_id']) ? (int)$r['source_int_id'] : null;
				if ($sourceIntId !== null && $sourceIntId <= 0) {
					$sourceIntId = null;
				}

				$this->markMissing($cid);
				$this->supersedePendingUpserts($cid, $kind);

				$this->insertDeleteJob(
					$cid,
					$kind,
					(string)($r['source_locator'] ?? ''),
					$containerObjId,
					$sourceIntId,
					(string)($r['last_seen_version'] ?? '')
				);

				$afterHex = $cid;
			}

			$this->state->set($this->stateKeyKind($stateKind, self::STATE_BULK_DELETE_AFTER_HEX), $afterHex);

			if (count($rows) < $batchSize) {
				break;
			}
		}
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

		// Provider kind is authoritative (strict separation of kinds).
		$kind = $provider->getSourceKind();
		if (!$this->isWhitelisted($kind)) {
			$this->saveCursor($provider, $batch->nextCursor);
			return 0;
		}

		$hexIds = [];
		$unitByHex = [];

		foreach ($units as $u) {
			if (!$u instanceof ContentUnitDto) {
				continue;
			}

			// IMPORTANT: content id must match the kind we later delete/toggle.
			$hex = $this->contentIdHex($u->sourceSystem, $kind, $u->sourceLocator);
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

			// IMPORTANT: seen rows use provider-kind (strict separation).
			$this->upsertSeenFromUnit($cidHex, $kind, $u, $version, $token);
			$this->supersedePendingUpserts($cidHex, $kind);

			$sourceIntId = $u->sourceIntId !== null ? (int)$u->sourceIntId : null;
			if ($sourceIntId !== null && $sourceIntId <= 0) {
				$sourceIntId = null;
			}

			$this->insertUpsertJob(
				$cidHex,
				$kind,
				$u->sourceLocator,
				$u->containerObjId,
				$sourceIntId,
				$version,
				$token
			);

			$cnt++;
		}

		$this->saveCursor($provider, $batch->nextCursor);

		return $cnt;
	}

	private function loadSeenMap(array $hexIds): array {
		$chunks = array_chunk($hexIds, 800);
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
					source_int_id,
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

			$sourceIntId = isset($r['source_int_id']) ? (int)$r['source_int_id'] : null;
			if ($sourceIntId !== null && $sourceIntId <= 0) {
				$sourceIntId = null;
			}

			$this->supersedePendingUpserts($cid, $kind);
			$this->markMissing($cid);

			$this->insertDeleteJob(
				$cid,
				$kind,
				(string)($r['source_locator'] ?? ''),
				$containerObjId,
				$sourceIntId,
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

	private function upsertSeenFromUnit(string $cidHex, string $kind, ContentUnitDto $u, string $version, ?string $token): void {
		$this->exec(
			"INSERT INTO base3_embedding_seen
					(content_id, source_system, source_kind, source_locator, container_obj_id, source_int_id,
					 last_seen_version, last_seen_version_token, last_seen_at, missing_since, delete_job_id, deleted_at)
			 VALUES
					(UNHEX('" . $this->esc($cidHex) . "'),
					 '" . $this->esc($u->sourceSystem) . "',
					 '" . $this->esc($kind) . "',
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

	private function insertUpsertJob(
		string $cidHex,
		string $kind,
		string $locator,
		?int $containerObjId,
		?int $sourceIntId,
		string $version,
		?string $token
	): void {
		$this->exec(
			"INSERT IGNORE INTO base3_embedding_job
					(content_id, source_kind, source_locator, container_obj_id, source_int_id,
					 source_version, source_version_token,
					 job_type, state, priority, attempts, locked_until, claim_token, claimed_at,
					 created_at, updated_at, error_message)
			 VALUES
					(UNHEX('" . $this->esc($cidHex) . "'),
					 '" . $this->esc($kind) . "',
					 '" . $this->esc($locator) . "',
					 " . ($containerObjId !== null ? (int)$containerObjId : "NULL") . ",
					 " . ($sourceIntId !== null ? (int)$sourceIntId : "NULL") . ",
					 '" . $this->esc($version) . "',
					 " . ($token !== null ? "'" . $this->esc($token) . "'" : "NULL") . ",
					 'upsert','pending',1,0,NULL,NULL,NULL,
					 NOW(),NOW(),NULL)"
		);
	}

	private function insertDeleteJob(
		string $cidHex,
		string $kind,
		string $locator,
		?int $containerObjId,
		?int $sourceIntId,
		string $version
	): void {
		if (trim($version) === '') {
			$version = $this->now();
		}

		$this->exec(
			"INSERT IGNORE INTO base3_embedding_job
					(content_id, source_kind, source_locator, container_obj_id, source_int_id,
					 source_version,
					 job_type, state, priority, attempts, locked_until, claim_token, claimed_at,
					 created_at, updated_at, error_message)
			 VALUES
					(UNHEX('" . $this->esc($cidHex) . "'),
					 '" . $this->esc($kind) . "',
					 '" . $this->esc($locator) . "',
					 " . ($containerObjId !== null ? (int)$containerObjId : "NULL") . ",
					 " . ($sourceIntId !== null ? (int)$sourceIntId : "NULL") . ",
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

	private function loadCursor(IContentProvider $provider): ContentCursorDto {
		$raw = (string)$this->state->get($this->stateKeyProvider($provider, 'cursor'), '');
		return ContentCursorDto::fromString($raw);
	}

	private function saveCursor(IContentProvider $provider, ContentCursorDto $cursor): void {
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

	private function stateDelete(string $key): void {
		// Compat: some IStateStore implementations support delete(), others only set().
		if (method_exists($this->state, 'delete')) {
			$this->state->delete($key);
			return;
		}
		$this->state->set($key, '');
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
			source_int_id INT NULL,
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
			KEY ix_container (container_obj_id),
			KEY ix_source_int (source_kind, source_int_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
	}

	/* ================= Helpers ================= */

	private function normalizeVersion(?string $raw): string {
		$raw = trim((string)$raw);
		return $raw !== '' ? $raw : $this->now();
	}

	private function contentIdHex(string $sourceSystem, string $sourceKind, string $sourceLocator): string {
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
