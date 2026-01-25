<?php declare(strict_types=1);

namespace MissionBayIlias\Job;

use Base3\Worker\Api\IJob;
use Base3\Configuration\Api\IConfiguration;
use Base3\Database\Api\IDatabase;
use Base3\State\Api\IStateStore;

/**
 * EmbeddingQueueCleanupJob
 *
 * Conservative cleanup for embedding queue tables.
 *
 * Policy (default TTL: 48 hours):
 * - base3_embedding_job:
 *   - Delete ONLY "superseded" rows if updated_at is older than TTL.
 *     (Done/Error/Running/Pending are kept to avoid unintended re-queue behavior and for audit/debugging.)
 *
 * - base3_embedding_seen (optional, disabled by default):
 *   - Delete rows that represent completed deletes:
 *       - delete_job_id IS NOT NULL
 *       - deleted_at IS NOT NULL
 *       - deleted_at is older than TTL
 *     Enable via state: cleanup_seen_deletes = 1
 *
 * Scheduling:
 * - Runs at most once per day, only within 02:00-04:00 (inclusive/exclusive).
 *
 * State keys:
 * - missionbayilias.job.embeddingqueuecleanup.last_run_at
 * - missionbayilias.job.embeddingqueuecleanup.ttl_hours (default 48)
 * - missionbayilias.job.embeddingqueuecleanup.delete_batch (default 20000)
 * - missionbayilias.job.embeddingqueuecleanup.cleanup_seen_deletes (default 0)
 */
final class EmbeddingQueueCleanupJob implements IJob {

	private const STATE_PREFIX = 'missionbayilias.job.embeddingqueuecleanup.';

	private const DEFAULT_LAST_RUN_AT = '1970-01-01 00:00:00';

	private const DEFAULT_TTL_HOURS = 48;
	private const DEFAULT_DELETE_BATCH = 20000;

	private const WINDOW_START = '02:00';
	private const WINDOW_END = '04:00';

	private const TABLE_JOB = 'base3_embedding_job';
	private const TABLE_SEEN = 'base3_embedding_seen';

	private const DEFAULT_PRIORITY = 1;

	private ?array $missionbayIliasConf = null;

	public function __construct(
		private readonly IDatabase $db,
		private readonly IConfiguration $configuration,
		private readonly IStateStore $state
	) {}

	public static function getName(): string {
		return 'embeddingqueuecleanupjob';
	}

	public function isActive() {
		$conf = $this->getMissionbayIliasConf();
		return ((int)($conf['embeddingqueuecleanupjob.active'] ?? 0)) === 1;
	}

	public function getPriority() {
		$conf = $this->getMissionbayIliasConf();
		return (int)($conf['embeddingqueuecleanupjob.priority'] ?? self::DEFAULT_PRIORITY);
	}

	public function go() {
		$this->db->connect();
		if (!$this->db->connected()) {
			return 'DB not connected';
		}

		$checkpoint = $this->loadCheckpointFromState();
		if (!$this->shouldRun($checkpoint)) {
			return 'Skip (not in window / already ran today)';
		}

		$ttlHours = $this->getTtlHours();
		$deleteBatch = $this->getDeleteBatch();
		$cutoff = $this->cutoffSqlString($ttlHours);

		$deletedJob = 0;
		$deletedSeen = 0;

		if ($this->tableExists(self::TABLE_JOB)) {
			$deletedJob = $this->cleanupSupersededJobs($cutoff, $deleteBatch);
		}

		if ($this->shouldCleanupSeenDeletes() && $this->tableExists(self::TABLE_SEEN)) {
			$deletedSeen = $this->cleanupSeenDeletes($cutoff, $deleteBatch);
		}

		$this->touchLastRunAt();

		return 'Embedding queue cleanup done (cutoff: ' . $cutoff . ', jobs_superseded_deleted: ' . $deletedJob . ', seen_deleted_deleted: ' . $deletedSeen . ')';
	}

	private function getMissionbayIliasConf(): array {
		if ($this->missionbayIliasConf === null) {
			$this->missionbayIliasConf = (array)$this->configuration->get('job');
		}
		return $this->missionbayIliasConf;
	}

	/* ---------- Cleanup: base3_embedding_job ---------- */

	private function cleanupSupersededJobs(string $cutoff, int $limit): int {
		if ($limit <= 0) {
			return 0;
		}

		$where = "state = 'superseded' AND updated_at < '" . $this->esc($cutoff) . "'";

		$before = $this->countRows(self::TABLE_JOB, $where);

		$this->exec(
			"DELETE FROM " . $this->escapeIdent(self::TABLE_JOB) . "
			WHERE {$where}
			ORDER BY updated_at ASC, job_id ASC
			LIMIT " . (int)$limit
		);

		$after = $this->countRows(self::TABLE_JOB, $where);

		$delta = $before - $after;
		return $delta > 0 ? $delta : 0;
	}

	/* ---------- Cleanup: base3_embedding_seen (optional) ---------- */

	private function cleanupSeenDeletes(string $cutoff, int $limit): int {
		if ($limit <= 0) {
			return 0;
		}

		$where = "delete_job_id IS NOT NULL
			AND deleted_at IS NOT NULL
			AND deleted_at < '" . $this->esc($cutoff) . "'";

		$before = $this->countRows(self::TABLE_SEEN, $where);

		$this->exec(
			"DELETE FROM " . $this->escapeIdent(self::TABLE_SEEN) . "
			WHERE {$where}
			ORDER BY deleted_at ASC, source_obj_id ASC
			LIMIT " . (int)$limit
		);

		$after = $this->countRows(self::TABLE_SEEN, $where);

		$delta = $before - $after;
		return $delta > 0 ? $delta : 0;
	}

	private function countRows(string $table, string $where): int {
		$row = $this->queryOne(
			"SELECT COUNT(*) AS cnt
			FROM " . $this->escapeIdent($table) . "
			WHERE {$where}"
		);

		return isset($row['cnt']) ? (int)$row['cnt'] : 0;
	}

	/* ---------- Scheduling ---------- */

	private function loadCheckpointFromState(): array {
		$lastRunAt = (string)$this->state->get($this->stateKey('last_run_at'), self::DEFAULT_LAST_RUN_AT);
		$lastRunAt = trim($lastRunAt) !== '' ? $lastRunAt : self::DEFAULT_LAST_RUN_AT;

		return [
			'last_run_at' => $lastRunAt
		];
	}

	private function shouldRun(array $checkpoint): bool {
		$nowDate = date('Y-m-d');
		$nowHm = date('H:i');

		if ($nowHm < self::WINDOW_START || $nowHm >= self::WINDOW_END) {
			return false;
		}

		$lastRunRaw = (string)($checkpoint['last_run_at'] ?? self::DEFAULT_LAST_RUN_AT);
		$lastRunRaw = trim($lastRunRaw) !== '' ? $lastRunRaw : self::DEFAULT_LAST_RUN_AT;

		if ($lastRunRaw === self::DEFAULT_LAST_RUN_AT) {
			return true;
		}

		$lastRunTs = strtotime($lastRunRaw);
		if ($lastRunTs === false) {
			return true;
		}

		$lastRunDate = date('Y-m-d', $lastRunTs);

		return $lastRunDate < $nowDate;
	}

	private function touchLastRunAt(): void {
		$this->state->set($this->stateKey('last_run_at'), $this->nowSqlString());
	}

	/* ---------- Config via state ---------- */

	private function getTtlHours(): int {
		$raw = $this->state->get($this->stateKey('ttl_hours'), self::DEFAULT_TTL_HOURS);
		$ttl = (int)$raw;
		return $ttl > 0 ? $ttl : self::DEFAULT_TTL_HOURS;
	}

	private function getDeleteBatch(): int {
		$raw = $this->state->get($this->stateKey('delete_batch'), self::DEFAULT_DELETE_BATCH);
		$batch = (int)$raw;
		return $batch > 0 ? $batch : self::DEFAULT_DELETE_BATCH;
	}

	private function shouldCleanupSeenDeletes(): bool {
		$raw = $this->state->get($this->stateKey('cleanup_seen_deletes'), 0);
		return (int)$raw === 1;
	}

	private function cutoffSqlString(int $ttlHours): string {
		$cutoffTs = time() - ($ttlHours * 3600);
		return date('Y-m-d H:i:s', $cutoffTs);
	}

	private function nowSqlString(): string {
		return date('Y-m-d H:i:s');
	}

	private function stateKey(string $suffix): string {
		return self::STATE_PREFIX . $suffix;
	}

	/* ---------- Table existence ---------- */

	private function tableExists(string $table): bool {
		$row = $this->db->singleQuery("SHOW TABLES LIKE '" . $this->esc($table) . "'");
		return !empty($row);
	}

	private function escapeIdent(string $name): string {
		$clean = preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?? '';
		if ($clean === '') {
			$clean = $name;
		}
		return '`' . $clean . '`';
	}

	/* ---------- DB helpers ---------- */

	private function exec(string $sql): void {
		$this->db->nonQuery($sql);
	}

	private function queryOne(string $sql): ?array {
		return $this->db->singleQuery($sql);
	}

	private function esc(string $value): string {
		return (string)$this->db->escape($value);
	}
}
