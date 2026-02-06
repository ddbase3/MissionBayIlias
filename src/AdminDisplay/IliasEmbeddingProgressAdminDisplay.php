<?php declare(strict_types=1);

namespace MissionBayIlias\AdminDisplay;

use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\Configuration\Api\IConfiguration;
use Base3\Database\Api\IDatabase;
use UiFoundation\Api\IAdminDisplay;

/**
 * IliasEmbeddingProgressAdminDisplay
 *
 * Progress view based on base3_embedding_seen (stable universe) PLUS delete jobs that are still in-flight.
 *
 * Problem addressed:
 * When a source_kind is disabled, the enqueue job may quickly remove/mark seen rows,
 * but delete jobs exist (pending/running/error). We must keep the bar visible until deletes are done.
 *
 * Correct behavior:
 * - Show kind if it exists in base3_embedding_seen (universe A)
 * - OR if there are delete jobs NOT DONE (universe B)
 * - Once all delete jobs are done AND no seen rows remain => hide the kind completely.
 *
 * Buckets:
 * - done (upsert done or no job needed)
 * - running (upsert/delete running)
 * - error (any error)
 * - pending_upsert (upsert pending)
 * - pending_delete (delete pending / missing_since set)
 */
final class IliasEmbeddingProgressAdminDisplay implements IAdminDisplay {

	public function __construct(
		private readonly IRequest $request,
		private readonly IMvcView $view,
		private readonly IConfiguration $config,
		private readonly IDatabase $db
	) {}

	public static function getName(): string {
		return 'iliasembeddingprogressadmindisplay';
	}

	public function setData($data) {
		// no-op
	}

	public function getHelp(): string {
		return 'ILIAS embedding progress by source_kind as one 100% stacked bar. Uses base3_embedding_seen as universe and keeps disabled-kinds visible while delete jobs are pending/running/error. Hides kind once deletes are done and seen rows are gone.';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		$out = strtolower($out);

		if ($out === 'json') {
			return $this->handleJson();
		}

		return $this->handleHtml();
	}

	private function handleHtml(): string {
		$this->view->setPath(DIR_PLUGIN . 'MissionBayIlias');
		$this->view->setTemplate('AdminDisplay/IliasEmbeddingProgressAdminDisplay.php');

		$baseEndpoint = (string)($this->config->get('base')['endpoint'] ?? '');
		$endpoint = $this->buildEndpointBase($baseEndpoint);

		$this->view->assign('endpoint', $endpoint);

		return $this->view->loadTemplate();
	}

	private function handleJson(): string {
		$action = (string)($this->request->get('action') ?? '');

		try {
			return match ($action) {
				'progress' => $this->jsonSuccess($this->loadProgress()),
				default => $this->jsonError("Unknown action '$action'. Use: progress"),
			};
		} catch (\Throwable $e) {
			return $this->jsonError('Exception: ' . $e->getMessage());
		}
	}

	private function loadProgress(): array {
		$this->db->connect();

		$rows = $this->db->multiQuery("
			WITH latest_job AS (
				SELECT
					j.content_id,
					j.state,
					j.job_type,
					ROW_NUMBER() OVER (
						PARTITION BY j.content_id
						ORDER BY j.updated_at DESC, j.job_id DESC
					) AS rn
				FROM base3_embedding_job j
				WHERE j.state <> 'superseded'
			),

			/* Universe A: seen rows (normal progress while content exists) */
			classified_seen AS (
				SELECT
					s.source_kind,
					CASE
						WHEN s.missing_since IS NOT NULL THEN
							CASE
								WHEN lj.state = 'running' THEN 'running'
								WHEN lj.state = 'error' THEN 'error'
								ELSE 'pending_delete'
							END
						ELSE
							CASE
								WHEN lj.state IS NULL THEN 'done'
								WHEN lj.state = 'pending' THEN 'pending_upsert'
								WHEN lj.state = 'running' THEN 'running'
								WHEN lj.state = 'error' THEN 'error'
								WHEN lj.state = 'done' THEN 'done'
								ELSE 'pending_upsert'
							END
					END AS bucket,
					COUNT(*) AS cnt
				FROM base3_embedding_seen s
				LEFT JOIN latest_job lj
					ON lj.content_id = s.content_id AND lj.rn = 1
				WHERE s.source_system='ilias'
				  AND s.deleted_at IS NULL
				  AND s.source_kind IS NOT NULL AND s.source_kind <> ''
				GROUP BY s.source_kind, bucket
			),

			/* Universe B: delete jobs that are still in-flight.
			   IMPORTANT: we do NOT count delete done here.
			   This ensures: when all deletes are done AND seen rows are gone => kind disappears. */
			classified_delete_jobs_inflight AS (
				SELECT
					j.source_kind,
					CASE
						WHEN j.state = 'pending' THEN 'pending_delete'
						WHEN j.state = 'running' THEN 'running'
						WHEN j.state = 'error' THEN 'error'
						ELSE NULL
					END AS bucket,
					COUNT(*) AS cnt
				FROM base3_embedding_job j
				WHERE j.job_type = 'delete'
				  AND j.state <> 'superseded'
				  AND j.state <> 'done'
				  AND j.source_kind IS NOT NULL AND j.source_kind <> ''
				GROUP BY j.source_kind, bucket
			),

			merged AS (
				SELECT source_kind, bucket, cnt FROM classified_seen
				UNION ALL
				SELECT source_kind, bucket, cnt FROM classified_delete_jobs_inflight WHERE bucket IS NOT NULL
			)

			SELECT
				source_kind,
				bucket,
				SUM(cnt) AS cnt
			FROM merged
			GROUP BY source_kind, bucket
		");

		$bucketOrder = ['done', 'error', 'running', 'pending_upsert', 'pending_delete'];

		$byKind = [];
		foreach ($rows as $r) {
			$kind = (string)($r['source_kind'] ?? '');
			$bucket = (string)($r['bucket'] ?? '');
			$cnt = (int)($r['cnt'] ?? 0);

			if ($kind === '' || $bucket === '') continue;
			if ($cnt <= 0) continue;

			if (!isset($byKind[$kind])) $byKind[$kind] = [];
			$byKind[$kind][$bucket] = ($byKind[$kind][$bucket] ?? 0) + $cnt;
		}

		$items = [];
		foreach ($byKind as $kind => $buckets) {
			$total = 0;
			foreach ($buckets as $c) $total += (int)$c;
			if ($total <= 0) continue;

			$normalized = [];
			foreach ($bucketOrder as $b) $normalized[$b] = (int)($buckets[$b] ?? 0);

			foreach ($buckets as $b => $c) {
				if (isset($normalized[$b])) continue;
				$normalized[(string)$b] = (int)$c;
			}

			$segments = [];
			foreach ($normalized as $b => $c) {
				if ($c <= 0) continue;
				$segments[] = [
					'bucket' => (string)$b,
					'count' => (int)$c,
					'percent' => (float)round(($c / $total) * 100.0, 2),
				];
			}

			$items[] = [
				'source_kind' => (string)$kind,
				'total' => (int)$total,
				'segments' => $segments,
			];
		}

		usort($items, function(array $a, array $b): int {
			return strcmp((string)$a['source_kind'], (string)$b['source_kind']);
		});

		return [
			'timestamp' => gmdate('Y-m-d H:i:s') . ' UTC',
			'legend' => [
				['bucket' => 'done', 'label' => 'done', 'color' => '#2f9e44'],
				['bucket' => 'error', 'label' => 'error', 'color' => '#d64545'],
				['bucket' => 'running', 'label' => 'running', 'color' => '#2f7dd1'],
				['bucket' => 'pending_upsert', 'label' => 'pending upsert', 'color' => '#9aa3ad'],
				['bucket' => 'pending_delete', 'label' => 'pending delete', 'color' => '#f08c00'],
			],
			'items' => $items,
		];
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
