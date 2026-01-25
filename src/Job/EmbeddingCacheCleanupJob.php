<?php declare(strict_types=1);

namespace MissionBayIlias\Job;

use Base3\Worker\Api\IJob;
use Base3\Configuration\Api\IConfiguration;
use Base3\Database\Api\IDatabase;
use Base3\State\Api\IStateStore;

/**
 * EmbeddingCacheCleanupJob
 *
 * Deletes old rows from the embedding cache table.
 *
 * Policy:
 * - Default retention: 7 days (168 hours) since last access.
 * - If last_accessed_at is NULL, created_at is used.
 *
 * Scheduling:
 * - The worker calls this job regularly.
 * - The job runs at most once per day, only within a time window.
 * - Default window: 02:00 (inclusive) to 04:00 (exclusive).
 *
 * Behavior:
 * - If the cache table does not exist, the job skips (nothing to clean).
 * - The job does not create or migrate schema (no magic).
 *
 * State keys:
 * - missionbayilias.job.embeddingcachecleanup.last_run_at
 * - missionbayilias.job.embeddingcachecleanup.retention_hours (default 168)
 * - missionbayilias.job.embeddingcachecleanup.delete_batch (default 20000)
 * - missionbayilias.job.embeddingcachecleanup.table (default base3_embedding_cache)
 */
final class EmbeddingCacheCleanupJob implements IJob {

	private const STATE_PREFIX = 'missionbayilias.job.embeddingcachecleanup.';

	private const DEFAULT_LAST_RUN_AT = '1970-01-01 00:00:00';

	private const DEFAULT_TABLE = 'base3_embedding_cache';
	private const DEFAULT_RETENTION_HOURS = 168; // 7 days
	private const DEFAULT_DELETE_BATCH = 20000;
	private const DEFAULT_PRIORITY = 1;

	// Run window (application/server timezone)
	private const WINDOW_START = '02:00';
	private const WINDOW_END = '04:00';

	private ?array $missionbayIliasConf = null;

	public function __construct(
		private readonly IDatabase $db,
		private readonly IConfiguration $configuration,
		private readonly IStateStore $state
	) {}

	public static function getName(): string {
		return 'embeddingcachecleanupjob';
	}

	public function isActive() {
		$conf = $this->getMissionbayIliasConf();
		return ((int)($conf['embeddingcachecleanupjob.active'] ?? 0)) === 1;
	}

	public function getPriority() {
		$conf = $this->getMissionbayIliasConf();
		return (int)($conf['embeddingcachecleanupjob.priority'] ?? self::DEFAULT_PRIORITY);
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

		$table = $this->getTableName();
		if (!$this->tableExists($table)) {
			return 'Skip (' . $table . ' does not exist)';
		}

		$retentionHours = $this->getRetentionHours();
		$deleteBatch = $this->getDeleteBatch();

		$cutoff = $this->cutoffSqlString($retentionHours);

		$this->deleteOldRows($table, $cutoff, $deleteBatch);

		$this->touchLastRunAt();

		return 'Embedding cache cleanup done (table: ' . $table . ', cutoff: ' . $cutoff . ', limit: ' . $deleteBatch . ')';
	}

	private function getMissionbayIliasConf(): array {
		if ($this->missionbayIliasConf === null) {
			$this->missionbayIliasConf = (array)$this->configuration->get('job');
		}
		return $this->missionbayIliasConf;
	}

	/* ---------- Cleanup ---------- */

	private function deleteOldRows(string $table, string $cutoff, int $limit): void {
		$tableIdent = $this->escapeIdent($table);

		// Delete by last access (fallback: created_at). Oldest first for stable execution.
		$sql = "DELETE FROM {$tableIdent}
			WHERE COALESCE(last_accessed_at, created_at) < '" . $this->esc($cutoff) . "'
			ORDER BY COALESCE(last_accessed_at, created_at) ASC
			LIMIT " . (int)$limit;

		$this->exec($sql);
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

		// Only run within the window.
		if ($nowHm < self::WINDOW_START || $nowHm >= self::WINDOW_END) {
			return false;
		}

		$lastRunRaw = (string)($checkpoint['last_run_at'] ?? self::DEFAULT_LAST_RUN_AT);
		$lastRunRaw = trim($lastRunRaw) !== '' ? $lastRunRaw : self::DEFAULT_LAST_RUN_AT;

		// Never ran -> run (we are already in the window).
		if ($lastRunRaw === self::DEFAULT_LAST_RUN_AT) {
			return true;
		}

		$lastRunTs = strtotime($lastRunRaw);
		if ($lastRunTs === false) {
			return true;
		}

		$lastRunDate = date('Y-m-d', $lastRunTs);

		// Run once per day: if last run is yesterday or earlier, run.
		return $lastRunDate < $nowDate;
	}

	private function touchLastRunAt(): void {
		$this->state->set($this->stateKey('last_run_at'), $this->nowSqlString());
	}

	/* ---------- Config via state ---------- */

	private function getTableName(): string {
		$raw = (string)$this->state->get($this->stateKey('table'), self::DEFAULT_TABLE);
		$raw = trim($raw);
		return $raw !== '' ? $raw : self::DEFAULT_TABLE;
	}

	private function getRetentionHours(): int {
		$raw = $this->state->get($this->stateKey('retention_hours'), self::DEFAULT_RETENTION_HOURS);
		$hours = (int)$raw;
		return $hours > 0 ? $hours : self::DEFAULT_RETENTION_HOURS;
	}

	private function getDeleteBatch(): int {
		$raw = $this->state->get($this->stateKey('delete_batch'), self::DEFAULT_DELETE_BATCH);
		$batch = (int)$raw;
		return $batch > 0 ? $batch : self::DEFAULT_DELETE_BATCH;
	}

	private function cutoffSqlString(int $retentionHours): string {
		$cutoffTs = time() - ($retentionHours * 3600);
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
		// MySQL/MariaDB. Adjust if needed for other backends.
		$row = $this->db->singleQuery("SHOW TABLES LIKE '" . $this->esc($table) . "'");
		return !empty($row);
	}

	private function escapeIdent(string $name): string {
		$clean = preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?? '';
		if ($clean === '') {
			$clean = self::DEFAULT_TABLE;
		}
		return '`' . $clean . '`';
	}

	/* ---------- DB helpers ---------- */

	private function exec(string $sql): void {
		$this->db->nonQuery($sql);
	}

	private function esc(string $value): string {
		return (string)$this->db->escape($value);
	}
}
