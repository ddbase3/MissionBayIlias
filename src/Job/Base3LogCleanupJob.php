<?php declare(strict_types=1);

namespace MissionBayIlias\Job;

use Base3\Worker\Api\IJob;
use Base3\Configuration\Api\IConfiguration;
use Base3\Database\Api\IDatabase;
use Base3\State\Api\IStateStore;

/**
 * Base3LogCleanupJob
 *
 * Deletes old rows from "base3_log" based on a retention window.
 *
 * Scheduling:
 * - The worker calls this job regularly.
 * - The job runs at most once per day, only within a time window.
 * - Default window: 02:00 (inclusive) to 04:00 (exclusive).
 *
 * Retention:
 * - Default: 48 hours (configurable via state key "retention_hours").
 *
 * Behavior:
 * - If the log table does not exist, the job skips silently (nothing to clean).
 * - The job does not create or migrate schema (no magic).
 */
final class Base3LogCleanupJob implements IJob {

	private const STATE_PREFIX = 'missionbayilias.job.base3logcleanup.';

	private const DEFAULT_LAST_RUN_AT = '1970-01-01 00:00:00';
	private const DEFAULT_RETENTION_HOURS = 48;

	// Run window (application/server timezone)
	private const WINDOW_START = '02:00';
	private const WINDOW_END = '04:00';

	// Safety cap per run (avoid long table locks; tune as needed)
	private const DEFAULT_DELETE_BATCH = 20000;
	private const DEFAULT_PRIORITY = 1;

	private ?array $missionbayIliasConf = null;

	public function __construct(
		private readonly IDatabase $db,
		private readonly IConfiguration $configuration,
		private readonly IStateStore $state
	) {}

	public static function getName(): string {
		return 'base3logcleanupjob';
	}

	public function isActive() {
		$conf = $this->getMissionbayIliasConf();
		return ((int)($conf['base3logcleanupjob.active'] ?? 0)) === 1;
	}

	public function getPriority() {
		$conf = $this->getMissionbayIliasConf();
		return (int)($conf['base3logcleanupjob.priority'] ?? self::DEFAULT_PRIORITY);
	}

	public function go() {
		$this->db->connect();
		if (!$this->db->connected()) {
			return 'DB not connected';
		}

		if (!$this->logTableExists()) {
			return 'Skip (base3_log does not exist)';
		}

		$checkpoint = $this->loadCheckpointFromState();
		if (!$this->shouldRun($checkpoint)) {
			return 'Skip (not in window / already ran today)';
		}

		$retentionHours = $this->getRetentionHours();
		$deleteBatch = $this->getDeleteBatch();

		$cutoff = $this->cutoffSqlString($retentionHours);

		$this->deleteOldLogs($cutoff, $deleteBatch);

		$this->touchLastRunAt();

		return 'Log cleanup done (cutoff: ' . $cutoff . ', limit: ' . $deleteBatch . ')';
	}

	private function getMissionbayIliasConf(): array {
		if ($this->missionbayIliasConf === null) {
			$this->missionbayIliasConf = (array)$this->configuration->get('job');
		}
		return $this->missionbayIliasConf;
	}

	/* ---------- Cleanup ---------- */

	private function deleteOldLogs(string $cutoff, int $limit): void {
		// Delete oldest first for predictable range deletes.
		$this->exec(
			"DELETE FROM base3_log
			WHERE `timestamp` < '" . $this->esc($cutoff) . "'
			ORDER BY id ASC
			LIMIT " . (int)$limit
		);
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

	/* ---------- Retention / config ---------- */

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

	private function logTableExists(): bool {
		// Works on MySQL/MariaDB. If your DB layer uses a different backend, adjust here.
		$row = $this->db->singleQuery("SHOW TABLES LIKE 'base3_log'");
		return !empty($row);
	}

	/* ---------- DB helpers ---------- */

	private function exec(string $sql): void {
		$this->db->nonQuery($sql);
	}

	private function esc(string $value): string {
		return (string)$this->db->escape($value);
	}
}
