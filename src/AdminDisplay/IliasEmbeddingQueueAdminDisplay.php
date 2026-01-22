<?php declare(strict_types=1);

namespace MissionBayIlias\AdminDisplay;

use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\Configuration\Api\IConfiguration;
use Base3\Database\Api\IDatabase;
use UiFoundation\Api\IAdminDisplay;

final class IliasEmbeddingQueueAdminDisplay implements IAdminDisplay {

	public function __construct(
		private readonly IRequest $request,
		private readonly IMvcView $view,
		private readonly IConfiguration $config,
		private readonly IDatabase $db
	) {}

	public static function getName(): string {
		return 'iliasembeddingqueueadmindisplay';
	}

	public function setData($data) {
		// no-op
	}

	public function getHelp() {
		return 'ILIAS embedding queue overview (4 cards + last 10 jobs).';
	}

	public function getOutput($out = 'html') {
		$out = strtolower((string)$out);

		if ($out === 'json') {
			return $this->handleJson();
		}

		return $this->handleHtml();
	}

	private function handleHtml(): string {
		$this->view->setPath(DIR_PLUGIN . 'MissionBayIlias');
		$this->view->setTemplate('AdminDisplay/IliasEmbeddingQueueAdminDisplay.php');

		$baseEndpoint = (string)($this->config->get('base')['endpoint'] ?? '');
		$endpoint = $this->buildEndpointBase($baseEndpoint);

		$this->view->assign('endpoint', $endpoint);

		return $this->view->loadTemplate();
	}

	private function handleJson(): string {
		$action = (string)($this->request->get('action') ?? '');

		try {
			return match ($action) {
				'stats' => $this->jsonSuccess($this->loadStats()),
				default => $this->jsonError("Unknown action '$action'. Use: stats"),
			};
		} catch (\Throwable $e) {
			return $this->jsonError('Exception: ' . $e->getMessage());
		}
	}

	private function loadStats(): array {
		$this->db->connect();

		$pendingTotal = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_job WHERE state='pending'");
		$pendingUpsert = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_job WHERE state='pending' AND job_type='upsert'");
		$pendingDelete = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_job WHERE state='pending' AND job_type='delete'");
		$pendingHighPrio = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_job WHERE state='pending' AND priority >= 2");

		$runningTotal = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_job WHERE state='running'");
		$lockedActive = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_job WHERE state='running' AND locked_until IS NOT NULL AND locked_until > UTC_TIMESTAMP()");
		$lockedExpired = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_job WHERE state='running' AND locked_until IS NOT NULL AND locked_until <= UTC_TIMESTAMP()");
		$claimed15m = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_job WHERE claimed_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)");
		$avgAttemptsRunning = (string)$this->scalar("SELECT IFNULL(ROUND(AVG(attempts), 2), 0) FROM base3_embedding_job WHERE state='running'");

		$done15m = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_job WHERE state='done' AND updated_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)");
		$done24h = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_job WHERE state='done' AND updated_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)");
		$created15m = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_job WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)");
		$superseded24h = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_job WHERE state='superseded' AND updated_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)");

		$errorTotal = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_job WHERE state='error'");
		$error24h = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_job WHERE state='error' AND updated_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)");
		$pendingRetries = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_job WHERE state='pending' AND attempts > 0");
		$errorMaxAttempts = (int)$this->scalar("SELECT IFNULL(MAX(attempts), 0) FROM base3_embedding_job WHERE state='error'");

		$oldestPendingAge = $this->ageSecondsToHuman((int)$this->scalar("
			SELECT IFNULL(TIMESTAMPDIFF(SECOND, MIN(created_at), UTC_TIMESTAMP()), 0)
			FROM base3_embedding_job
			WHERE state='pending'
		"));

		$oldestRunningAge = $this->ageSecondsToHuman((int)$this->scalar("
			SELECT IFNULL(TIMESTAMPDIFF(SECOND, MIN(claimed_at), UTC_TIMESTAMP()), 0)
			FROM base3_embedding_job
			WHERE state='running' AND claimed_at IS NOT NULL
		"));

		$lastErrorAt = (string)$this->scalar("
			SELECT IFNULL(MAX(updated_at), '')
			FROM base3_embedding_job
			WHERE state='error'
		");

		$lastErrorMessage = (string)$this->scalar("
			SELECT IFNULL(SUBSTRING(error_message, 1, 120), '')
			FROM base3_embedding_job
			WHERE state='error'
			ORDER BY updated_at DESC
			LIMIT 1
		");

		$missingTotal = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_seen WHERE missing_since IS NOT NULL AND deleted_at IS NULL");
		$missingWithDeleteJob = (int)$this->scalar("SELECT COUNT(*) FROM base3_embedding_seen WHERE missing_since IS NOT NULL AND deleted_at IS NULL AND delete_job_id IS NOT NULL");

		$recentJobs = $this->loadRecentJobs(10);

		$badges = $this->computeBadges([
			'pendingTotal' => $pendingTotal,
			'runningTotal' => $runningTotal,
			'lockedExpired' => $lockedExpired,
			'created15m' => $created15m,
			'done15m' => $done15m,
			'error24h' => $error24h,
			'errorTotal' => $errorTotal,
		]);

		return [
			'timestamp' => gmdate('Y-m-d H:i:s') . ' UTC',
			'jobs' => [
				'pending_total' => $pendingTotal,
				'pending_upsert' => $pendingUpsert,
				'pending_delete' => $pendingDelete,
				'pending_high_prio' => $pendingHighPrio,
				'oldest_pending_age' => $oldestPendingAge,

				'running_total' => $runningTotal,
				'locked_active' => $lockedActive,
				'locked_expired' => $lockedExpired,
				'claimed_15m' => $claimed15m,
				'avg_attempts_running' => $avgAttemptsRunning,
				'oldest_running_age' => $oldestRunningAge,

				'done_15m' => $done15m,
				'done_24h' => $done24h,
				'created_15m' => $created15m,
				'superseded_24h' => $superseded24h,

				'error_total' => $errorTotal,
				'error_24h' => $error24h,
				'pending_retries' => $pendingRetries,
				'error_max_attempts' => $errorMaxAttempts,
				'last_error_at' => $lastErrorAt === '' ? null : $lastErrorAt,
				'last_error_message' => $lastErrorMessage === '' ? null : $lastErrorMessage,
			],
			'seen' => [
				'missing_total' => $missingTotal,
				'missing_with_delete_job' => $missingWithDeleteJob,
			],
			'recent_jobs' => $recentJobs,
			'badges' => $badges
		];
	}

	private function loadRecentJobs(int $limit): array {
		$limit = max(1, min(50, $limit));

		$rows = $this->db->multiQuery("
			SELECT
				job_id,
				state,
				job_type,
				attempts,
				priority,
				source_kind,
				source_locator,
				updated_at,
				SUBSTRING(IFNULL(error_message, ''), 1, 200) AS error_message
			FROM base3_embedding_job
			ORDER BY updated_at DESC
			LIMIT " . (int)$limit
		);

		$out = [];
		foreach ($rows as $r) {
			$out[] = [
				'job_id' => (int)($r['job_id'] ?? 0),
				'state' => (string)($r['state'] ?? ''),
				'job_type' => (string)($r['job_type'] ?? ''),
				'attempts' => (int)($r['attempts'] ?? 0),
				'priority' => (int)($r['priority'] ?? 0),
				'source_kind' => (string)($r['source_kind'] ?? ''),
				'source_locator' => $r['source_locator'] !== null ? (string)$r['source_locator'] : null,
				'updated_at' => (string)($r['updated_at'] ?? ''),
				'error_message' => (string)($r['error_message'] ?? ''),
			];
		}

		return $out;
	}

	private function computeBadges(array $m): array {
		$backlogState = 'ok';
		$backlogLabel = 'OK';

		if ($m['pendingTotal'] >= 500 || $m['created15m'] > $m['done15m']) {
			$backlogState = 'warn';
			$backlogLabel = 'Backlog';
		}
		if ($m['pendingTotal'] >= 2000) {
			$backlogState = 'err';
			$backlogLabel = 'Stau';
		}

		$runningState = 'ok';
		$runningLabel = 'OK';
		if ($m['lockedExpired'] > 0) {
			$runningState = 'err';
			$runningLabel = 'Stuck';
		} elseif ($m['runningTotal'] > 0) {
			$runningState = 'ok';
			$runningLabel = 'Aktiv';
		}

		$throughputState = 'ok';
		$throughputLabel = 'OK';
		if ($m['created15m'] > 0 && $m['done15m'] === 0) {
			$throughputState = 'warn';
			$throughputLabel = 'Langsam';
		}

		$errorsState = 'ok';
		$errorsLabel = 'OK';
		if ($m['error24h'] >= 10) {
			$errorsState = 'warn';
			$errorsLabel = 'Fehler';
		}
		if ($m['error24h'] >= 50 || $m['errorTotal'] >= 200) {
			$errorsState = 'err';
			$errorsLabel = 'Alarm';
		}

		return [
			'backlog' => ['state' => $backlogState, 'label' => $backlogLabel],
			'running' => ['state' => $runningState, 'label' => $runningLabel],
			'throughput' => ['state' => $throughputState, 'label' => $throughputLabel],
			'errors' => ['state' => $errorsState, 'label' => $errorsLabel],
		];
	}

	private function ageSecondsToHuman(int $seconds): string {
		if ($seconds <= 0) return '–';

		$days = intdiv($seconds, 86400);
		$seconds -= $days * 86400;

		$hours = intdiv($seconds, 3600);
		$seconds -= $hours * 3600;

		$mins = intdiv($seconds, 60);
		$seconds -= $mins * 60;

		if ($days > 0) return $days . 'd ' . $hours . 'h';
		if ($hours > 0) return $hours . 'h ' . $mins . 'm';
		if ($mins > 0) return $mins . 'm ' . $seconds . 's';
		return $seconds . 's';
	}

	private function scalar(string $sql) {
		return $this->db->scalarQuery($sql);
	}

	private function buildEndpointBase(string $baseEndpoint): string {
		$baseEndpoint = trim($baseEndpoint);

		if ($baseEndpoint === '') {
			$baseEndpoint = 'base3.php';
		}

		$sep = str_contains($baseEndpoint, '?') ? '&' : '?';

		return $baseEndpoint . $sep . 'name=' . rawurlencode(self::getName()) . '&out=json&action=';
	}

	private function jsonSuccess(array $data): string {
		return json_encode([
			'status' => 'ok',
			'timestamp' => gmdate('c'),
			'data' => $data
		], JSON_UNESCAPED_UNICODE);
	}

	private function jsonError(string $message): string {
		return json_encode([
			'status' => 'error',
			'timestamp' => gmdate('c'),
			'message' => $message
		], JSON_UNESCAPED_UNICODE);
	}
}
