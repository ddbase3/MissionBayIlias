<?php declare(strict_types=1);

namespace MissionBayIlias\AdminDisplay;

use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\Configuration\Api\IConfiguration;
use UiFoundation\Api\IAdminDisplay;

final class IliasVectorPointsAdminDisplay implements IAdminDisplay {

	/** @var string canonical logical collection key for ILIAS */
	private const COLLECTION_KEY = 'ilias';

	/** @var string physical backend collection name in Qdrant */
	private const BACKEND_COLLECTION = 'base3ilias_content_v1';

	/** @var string section of vectordb configuration values */
	private const CONNECTION_CONFIG_SECTION = 'qualitusvectordb';

	private const DEFAULT_SAMPLE_LIMIT = 3;
	private const MAX_KINDS_SCAN = 5000;

	public function __construct(
		private readonly IRequest $request,
		private readonly IMvcView $view,
		private readonly IConfiguration $config
	) {}

	public static function getName(): string {
		return 'iliasvectorpointsadmindisplay';
	}

	public function setData($data) {
		// no-op
	}

	public function getHelp() {
		return 'ILIAS VectorStore points inspector (filter by source_kind, show sample points).';
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
		$this->view->setTemplate('AdminDisplay/IliasVectorPointsAdminDisplay.php');

		$baseEndpoint = (string)($this->config->get('base')['endpoint'] ?? '');
		$endpoint = $this->buildEndpointBase($baseEndpoint);

		$this->view->assign('endpoint', $endpoint);
		$this->view->assign('collectionKey', self::COLLECTION_KEY);
		$this->view->assign('backendCollection', self::BACKEND_COLLECTION);

		return $this->view->loadTemplate();
	}

	private function handleJson(): string {
		$action = (string)($this->request->get('action') ?? '');

		try {
			return match ($action) {
				'kinds' => $this->jsonSuccess([
					'collectionKey' => self::COLLECTION_KEY,
					'backendCollection' => self::BACKEND_COLLECTION,
					'kinds' => $this->loadSourceKinds(),
				]),
				'sample' => $this->jsonSuccess([
					'collectionKey' => self::COLLECTION_KEY,
					'backendCollection' => self::BACKEND_COLLECTION,
					'filter' => [
						'source_kind' => $this->normalizeString((string)($this->request->get('source_kind') ?? '')),
					],
					'points' => $this->loadSamplePoints(
						$this->normalizeString((string)($this->request->get('source_kind') ?? '')),
						$this->normalizeLimit((int)($this->request->get('limit') ?? self::DEFAULT_SAMPLE_LIMIT))
					),
				]),
				default => $this->jsonError("Unknown action '$action'. Use: kinds|sample"),
			};
		} catch (\Throwable $e) {
			return $this->jsonError('Exception: ' . $e->getMessage());
		}
	}

	// ---------------------------------------------------------
	// Qdrant proxy calls
	// ---------------------------------------------------------

	private function loadSourceKinds(): array {
		$endpoint = (string)($this->config->get(self::CONNECTION_CONFIG_SECTION)['endpoint'] ?? '');
		$apikey = (string)($this->config->get(self::CONNECTION_CONFIG_SECTION)['apikey'] ?? '');

		if (trim($endpoint) === '') {
			throw new \RuntimeException('Missing vectordb endpoint config: ' . self::CONNECTION_CONFIG_SECTION . '.endpoint');
		}
		if (trim($apikey) === '') {
			throw new \RuntimeException('Missing vectordb apikey config: ' . self::CONNECTION_CONFIG_SECTION . '.apikey');
		}

		$kinds = [];
		$seen = [];
		$scanned = 0;

		$offset = null;

		while (true) {
			$limit = min(250, self::MAX_KINDS_SCAN - $scanned);
			if ($limit <= 0) break;

			$body = [
				'limit' => $limit,
				'with_payload' => true,
				'with_vector' => false,
			];

			if ($offset !== null) {
				$body['offset'] = $offset;
			}

			$res = $this->qdrantProxyPost(
				$endpoint,
				$apikey,
				'/collections/' . rawurlencode(self::BACKEND_COLLECTION) . '/points/scroll',
				$body
			);

			$points = $res['result']['points'] ?? [];
			if (!is_array($points) || count($points) === 0) break;

			foreach ($points as $p) {
				$payload = $p['payload'] ?? null;
				if (!is_array($payload)) continue;

				$sk = $payload['source_kind'] ?? null;
				if (!is_string($sk) || trim($sk) === '') continue;

				$sk = trim($sk);
				if (!isset($seen[$sk])) {
					$seen[$sk] = true;
					$kinds[] = $sk;
				}
			}

			$scanned += count($points);

			$offset = $res['result']['next_page_offset'] ?? null;
			if ($offset === null) break;
		}

		sort($kinds, SORT_NATURAL | SORT_FLAG_CASE);
		return $kinds;
	}

	private function loadSamplePoints(string $sourceKind, int $limit): array {
		$endpoint = (string)($this->config->get(self::CONNECTION_CONFIG_SECTION)['endpoint'] ?? '');
		$apikey = (string)($this->config->get(self::CONNECTION_CONFIG_SECTION)['apikey'] ?? '');

		if (trim($endpoint) === '') {
			throw new \RuntimeException('Missing vectordb endpoint config: ' . self::CONNECTION_CONFIG_SECTION . '.endpoint');
		}
		if (trim($apikey) === '') {
			throw new \RuntimeException('Missing vectordb apikey config: ' . self::CONNECTION_CONFIG_SECTION . '.apikey');
		}

		$body = [
			'limit' => $limit,
			'with_payload' => true,
			'with_vector' => false,
		];

		if ($sourceKind !== '') {
			$body['filter'] = [
				'must' => [
					[
						'key' => 'source_kind',
						'match' => ['value' => $sourceKind],
					],
				],
			];
		}

		$res = $this->qdrantProxyPost(
			$endpoint,
			$apikey,
			'/collections/' . rawurlencode(self::BACKEND_COLLECTION) . '/points/scroll',
			$body
		);

		$points = $res['result']['points'] ?? [];
		if (!is_array($points)) return [];

		$out = [];
		foreach ($points as $p) {
			if (!is_array($p)) continue;

			$id = $p['id'] ?? null;
			$payload = $p['payload'] ?? null;

			if (!is_array($payload)) continue;

			$out[] = [
				'id' => $id,
				'payload' => $payload,
			];
		}

		return $out;
	}

	private function qdrantProxyPost(string $baseEndpoint, string $apikey, string $path, array $jsonBody): array {
		$url = $this->buildQdrantProxyUrl($baseEndpoint, $path);

		$ch = curl_init($url);
		if ($ch === false) {
			throw new \RuntimeException('curl_init failed.');
		}

		$payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
		if ($payload === false) {
			throw new \RuntimeException('json_encode failed.');
		}

		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $payload,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Accept: application/json',
				'x-proxy-token: ' . $apikey,
			],
			CURLOPT_TIMEOUT => 20,
		]);

		$raw = curl_exec($ch);
		$err = curl_error($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($raw === false) {
			throw new \RuntimeException('Qdrant request failed: ' . $err);
		}

		$data = json_decode((string)$raw, true);
		if (!is_array($data)) {
			throw new \RuntimeException('Invalid JSON from Qdrant proxy. HTTP ' . $code . ' Body: ' . substr((string)$raw, 0, 2000));
		}

		if ($code >= 400) {
			$msg = $data['status'] ?? null;
			throw new \RuntimeException('Qdrant error HTTP ' . $code . ($msg ? (': ' . $msg) : ''));
		}

		return $data;
	}

	private function buildQdrantProxyUrl(string $baseEndpoint, string $path): string {
		$baseEndpoint = trim($baseEndpoint);
		if ($baseEndpoint === '') {
			throw new \RuntimeException('Vectordb endpoint is empty.');
		}

		$sep = str_contains($baseEndpoint, '?') ? '&' : '?';
		return $baseEndpoint . $sep . 'path=' . rawurlencode($path);
	}

	// ---------------------------------------------------------

	private function buildEndpointBase(string $baseEndpoint): string {
		$baseEndpoint = trim($baseEndpoint);

		if ($baseEndpoint === '') {
			$baseEndpoint = 'base3.php';
		}

		$sep = str_contains($baseEndpoint, '?') ? '&' : '?';
		return $baseEndpoint . $sep . 'name=' . rawurlencode(self::getName()) . '&out=json&action=';
	}

	private function normalizeLimit(int $n): int {
		if ($n <= 0) return self::DEFAULT_SAMPLE_LIMIT;
		if ($n > 12) return 12;
		return $n;
	}

	private function normalizeString(string $s): string {
		return trim($s);
	}

	private function jsonSuccess(array $data): string {
		return json_encode([
			'status' => 'ok',
			'timestamp' => gmdate('c'),
			'data' => $data,
		], JSON_UNESCAPED_UNICODE);
	}

	private function jsonError(string $message): string {
		return json_encode([
			'status' => 'error',
			'timestamp' => gmdate('c'),
			'message' => $message,
		], JSON_UNESCAPED_UNICODE);
	}
}
