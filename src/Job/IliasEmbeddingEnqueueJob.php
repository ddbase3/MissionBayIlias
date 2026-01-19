<?php declare(strict_types=1);

namespace MissionBayIlias\Job;

use Base3\Worker\Api\IJob;
use Base3\Database\Api\IDatabase;
use Base3\State\Api\IStateStore;

/**
 * IliasEmbeddingEnqueueJob
 *
 * Worker A (enqueue):
 * - Scans ILIAS object_data incrementally (cursor: last_update)
 * - Enqueues upsert jobs and delete jobs
 * - Persists last run timestamps and cursors in IStateStore (no checkpoint table)
 *
 * Notes:
 * - source_uuid => source_obj_id (INT)
 * - source_version => source_version (DATETIME, based on object_data.last_update)
 * - collection_key is derived from object_data.type (char(4)) and normalized
 */
final class IliasEmbeddingEnqueueJob implements IJob {

	private const CHECKPOINT_NAME = 'object_data';
	private const MIN_INTERVAL_SECONDS = 900;

	private const CHANGED_BATCH = 5000;
	private const DELETE_BATCH = 2000;

	private const DEFAULT_COLLECTION_KEY = 'default';

	private const DEFAULT_LAST_CHANGED = '1970-01-01 00:00:00';
	private const DEFAULT_LAST_RUN_AT = '1970-01-01 00:00:00';

	public function __construct(
		private readonly IDatabase $db,
		private readonly IStateStore $state
	) {}

	public static function getName(): string {
		return 'iliasembeddingenqueuejob';
	}

	public function isActive() {
		return false;
	}

	public function getPriority() {
		return 1;
	}

	public function go() {
		$this->db->connect();
		if (!$this->db->connected()) {
			return 'DB not connected';
		}

		$this->ensureTables();

		$checkpoint = $this->loadCheckpointFromState();
		if (!$this->shouldRun($checkpoint)) {
			return 'Skip (min interval not reached)';
		}

		$changed = $this->enqueueChanged(self::CHANGED_BATCH, (string)$checkpoint['last_changed']);
		$deleted = $this->enqueueDeletes(self::DELETE_BATCH);

		$this->touchRunAt();

		return 'Enqueue done - changed: ' . $changed . ', deletes: ' . $deleted;
	}

	/* ---------- Enqueue logic ---------- */

	private function enqueueChanged(int $limit, string $lastChanged): int {
		$rows = $this->queryAll(
			"SELECT
				o.obj_id,
				o.last_update,
				o.type AS type_alias
			FROM object_data o
			WHERE o.last_update IS NOT NULL
				AND o.last_update > '" . $this->esc($lastChanged) . "'
			ORDER BY o.last_update ASC
			LIMIT " . (int)$limit
		);

		if (!$rows) {
			return 0;
		}

		$maxChanged = $lastChanged;
		$processed = 0;

		foreach ($rows as $row) {
			$objId = (int)($row['obj_id'] ?? 0);
			$lastUpdate = (string)($row['last_update'] ?? '');

			if ($objId <= 0 || $lastUpdate === '') {
				continue;
			}

			$collectionKey = $this->normalizeCollectionKey((string)($row['type_alias'] ?? ''));

			$this->upsertSeen($objId, $lastUpdate, $collectionKey);

			// Supersede only pending upserts (do not touch running jobs)
			$this->supersedePendingUpserts($objId, $collectionKey);

			$this->insertUpsertJob($objId, $lastUpdate, $collectionKey);

			if ($lastUpdate > $maxChanged) {
				$maxChanged = $lastUpdate;
			}

			$processed++;
		}

		$this->updateLastChanged($maxChanged);

		return $processed;
	}

	private function enqueueDeletes(int $limit): int {
		$rows = $this->queryAll(
			"SELECT
				s.source_obj_id,
				s.last_seen_version,
				s.last_seen_collection_key
			FROM base3_embedding_seen s
			LEFT JOIN object_data o ON o.obj_id = s.source_obj_id
			WHERE o.obj_id IS NULL
				AND s.missing_since IS NULL
			LIMIT " . (int)$limit
		);

		if (!$rows) {
			return 0;
		}

		$processed = 0;

		foreach ($rows as $row) {
			$objId = (int)($row['source_obj_id'] ?? 0);
			$lastSeenVersion = (string)($row['last_seen_version'] ?? '');
			$collectionKey = $this->normalizeCollectionKey((string)($row['last_seen_collection_key'] ?? ''));

			if ($objId <= 0 || $lastSeenVersion === '') {
				continue;
			}

			$this->markMissing($objId);

			$jobId = $this->insertDeleteJobAndGetId($objId, $lastSeenVersion, $collectionKey);
			if ($jobId !== null) {
				$this->setDeleteJobId($objId, $jobId);
			}

			$processed++;
		}

		return $processed;
	}

	/* ---------- Supersede ---------- */

	private function supersedePendingUpserts(int $objId, string $collectionKey): void {
		$this->exec(
			"UPDATE base3_embedding_job
			SET state = 'superseded',
				updated_at = NOW()
			WHERE source_obj_id = " . (int)$objId . "
				AND collection_key = '" . $this->esc($collectionKey) . "'
				AND job_type = 'upsert'
				AND state = 'pending'"
		);
	}

	/* ---------- Seen / Job helpers ---------- */

	private function upsertSeen(int $objId, string $lastUpdate, string $collectionKey): void {
		$this->exec(
			"INSERT INTO base3_embedding_seen
				(source_obj_id, last_seen_version, last_seen_changed, last_seen_at, last_seen_collection_key, missing_since, delete_job_id, deleted_at)
			VALUES
				(" . (int)$objId . ", '" . $this->esc($lastUpdate) . "', '" . $this->esc($lastUpdate) . "', NOW(), '" . $this->esc($collectionKey) . "', NULL, NULL, NULL)
			ON DUPLICATE KEY UPDATE
				last_seen_version = VALUES(last_seen_version),
				last_seen_changed = VALUES(last_seen_changed),
				last_seen_at = NOW(),
				last_seen_collection_key = VALUES(last_seen_collection_key),
				missing_since = NULL,
				delete_job_id = NULL,
				deleted_at = NULL"
		);
	}

	private function insertUpsertJob(int $objId, string $lastUpdate, string $collectionKey): void {
		$this->exec(
			"INSERT IGNORE INTO base3_embedding_job
				(source_obj_id, source_version, collection_key, job_type, state, priority, attempts, locked_until, claim_token, claimed_at, created_at, updated_at, error_message)
			VALUES
				(" . (int)$objId . ", '" . $this->esc($lastUpdate) . "', '" . $this->esc($collectionKey) . "', 'upsert', 'pending', 1, 0, NULL, NULL, NULL, NOW(), NOW(), NULL)"
		);
	}

	private function markMissing(int $objId): void {
		$this->exec(
			"UPDATE base3_embedding_seen
			SET missing_since = NOW()
			WHERE source_obj_id = " . (int)$objId . "
				AND missing_since IS NULL"
		);
	}

	private function insertDeleteJobAndGetId(int $objId, string $lastSeenVersion, string $collectionKey): ?int {
		$this->exec(
			"INSERT IGNORE INTO base3_embedding_job
				(source_obj_id, source_version, collection_key, job_type, state, priority, attempts, locked_until, claim_token, claimed_at, created_at, updated_at, error_message)
			VALUES
				(" . (int)$objId . ", '" . $this->esc($lastSeenVersion) . "', '" . $this->esc($collectionKey) . "', 'delete', 'pending', 1, 0, NULL, NULL, NULL, NOW(), NOW(), NULL)"
		);

		$row = $this->queryOne(
			"SELECT job_id
			FROM base3_embedding_job
			WHERE source_obj_id = " . (int)$objId . "
				AND source_version = '" . $this->esc($lastSeenVersion) . "'
				AND collection_key = '" . $this->esc($collectionKey) . "'
				AND job_type = 'delete'
			LIMIT 1"
		);

		return isset($row['job_id']) ? (int)$row['job_id'] : null;
	}

	private function setDeleteJobId(int $objId, int $jobId): void {
		$this->exec(
			"UPDATE base3_embedding_seen
			SET delete_job_id = " . (int)$jobId . "
			WHERE source_obj_id = " . (int)$objId
		);
	}

	/* ---------- State (checkpoint replacement) ---------- */

	private function loadCheckpointFromState(): array {
		$lastChanged = (string)$this->state->get($this->stateKey('last_changed'), self::DEFAULT_LAST_CHANGED);
		$lastRunAt = (string)$this->state->get($this->stateKey('last_run_at'), self::DEFAULT_LAST_RUN_AT);

		$lastChanged = trim($lastChanged) !== '' ? $lastChanged : self::DEFAULT_LAST_CHANGED;
		$lastRunAt = trim($lastRunAt) !== '' ? $lastRunAt : self::DEFAULT_LAST_RUN_AT;

		return [
			'last_changed' => $lastChanged,
			'last_run_at' => $lastRunAt
		];
	}

	private function touchRunAt(): void {
		$this->state->set($this->stateKey('last_run_at'), $this->nowSqlString());
	}

	private function updateLastChanged(string $lastChanged): void {
		$lastChanged = trim($lastChanged);
		if ($lastChanged === '') {
			return;
		}

		$this->state->set($this->stateKey('last_changed'), $lastChanged);
	}

	private function stateKey(string $suffix): string {
		return 'missionbay.ilias.embedding.' . self::CHECKPOINT_NAME . '.' . $suffix;
	}

	private function nowSqlString(): string {
		return date('Y-m-d H:i:s');
	}

	private function shouldRun(array $checkpoint): bool {
		$lastRunRaw = (string)($checkpoint['last_run_at'] ?? '');

		if ($lastRunRaw === '' || $lastRunRaw === self::DEFAULT_LAST_RUN_AT) {
			return true;
		}

		$lastRunAt = strtotime($lastRunRaw);
		if ($lastRunAt === false) {
			return true;
		}

		return (time() - $lastRunAt) >= self::MIN_INTERVAL_SECONDS;
	}

	/* ---------- Schema ---------- */

	private function ensureTables(): void {
		$this->exec($this->getJobTableSql());
		$this->exec($this->getSeenTableSql());
	}

	private function getJobTableSql(): string {
		return "CREATE TABLE IF NOT EXISTS base3_embedding_job (
			job_id BIGINT NOT NULL AUTO_INCREMENT,
			source_obj_id INT NOT NULL,
			source_version DATETIME NULL,
			collection_key VARCHAR(64) NOT NULL,
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
			UNIQUE KEY uq_job (source_obj_id, source_version, collection_key, job_type),
			KEY ix_claim (state, priority, locked_until, updated_at),
			KEY ix_claim_token (claim_token),
			KEY ix_source (source_obj_id, job_type),
			KEY ix_collection (collection_key)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
	}

	private function getSeenTableSql(): string {
		return "CREATE TABLE IF NOT EXISTS base3_embedding_seen (
			source_obj_id INT NOT NULL,
			last_seen_version DATETIME NOT NULL,
			last_seen_changed DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			last_seen_collection_key VARCHAR(64) NOT NULL,
			missing_since DATETIME NULL,
			delete_job_id BIGINT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY (source_obj_id),
			KEY ix_missing (missing_since),
			KEY ix_delete_job (delete_job_id),
			KEY ix_seen_collection (last_seen_collection_key)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
	}

	/* ---------- Helpers ---------- */

	private function normalizeCollectionKey(string $key): string {
		$key = trim($key);
		return $key !== '' ? $key : self::DEFAULT_COLLECTION_KEY;
	}

	/* ---------- DB helpers ---------- */

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
