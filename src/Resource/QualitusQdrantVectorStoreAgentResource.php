<?php declare(strict_types=1);

namespace MissionBayIlias\Resource;

use MissionBay\Api\IAgentVectorStore;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBay\Api\IAgentRagPayloadNormalizer;
use MissionBay\Dto\AgentEmbeddingChunk;
use MissionBay\Resource\AbstractAgentResource;

/**
 * QualitusQdrantVectorStoreAgentResource
 *
 * Qdrant VectorStore via Qualitus HTTP proxy:
 * - endpoint is the proxy base URL (e.g. https://.../base3.php?name=...)
 * - authentication header is "x-proxy-token"
 * - all Qdrant paths are forwarded using query param: &path=/collections/...
 *
 * Collection routing remains normalizer-driven (multi-collection).
 */
final class QualitusQdrantVectorStoreAgentResource extends AbstractAgentResource implements IAgentVectorStore {

	protected IAgentConfigValueResolver $resolver;
	protected IAgentRagPayloadNormalizer $normalizer;

	protected array|string|null $endpointConfig = null;
	protected array|string|null $apikeyConfig = null;
	protected mixed $createPayloadIndexesConfig = null;

	protected ?string $endpoint = null;
	protected ?string $apikey = null;

	protected bool $createPayloadIndexes = false;

	/** @var array<string,bool> cache by BACKEND collection name */
	private array $ensuredCollections = [];

	/** @var array<string,array<string,bool>> cache by BACKEND collection name */
	private array $ensuredIndexes = [];

	public function __construct(
		IAgentConfigValueResolver $resolver,
		IAgentRagPayloadNormalizer $normalizer,
		?string $id = null
	) {
		parent::__construct($id);
		$this->resolver = $resolver;
		$this->normalizer = $normalizer;
	}

	public static function getName(): string {
		return 'qualitusqdrantvectorstoreagentresource';
	}

	public function getDescription(): string {
		return 'Provides vector upsert, search, and duplicate detection for Qdrant via Qualitus proxy (multi-collection).';
	}

	public function setConfig(array $config): void {
		parent::setConfig($config);

		$this->endpointConfig = $config['endpoint'] ?? null;
		$this->apikeyConfig = $config['apikey'] ?? null;
		$this->createPayloadIndexesConfig = $config['create_payload_indexes'] ?? true;

		$endpoint = (string)$this->resolver->resolveValue($this->endpointConfig);
		$apikey = (string)$this->resolver->resolveValue($this->apikeyConfig);

		$endpoint = trim($endpoint);

		if ($endpoint === '') {
			throw new \InvalidArgumentException('QualitusQdrantVectorStore: endpoint is required.');
		}
		if ($apikey === '') {
			throw new \InvalidArgumentException('QualitusQdrantVectorStore: apikey (proxy token) is required.');
		}

		// Keep query string intact. Only remove trailing '&' to avoid "&&path="
		$endpoint = rtrim($endpoint, '&');

		$this->endpoint = $endpoint;
		$this->apikey = $apikey;

		$flag = $this->resolver->resolveValue($this->createPayloadIndexesConfig);
		if (is_bool($flag)) {
			$this->createPayloadIndexes = $flag;
		} else if (is_string($flag)) {
			$this->createPayloadIndexes = in_array(strtolower(trim($flag)), ['1', 'true', 'yes', 'on'], true);
		} else if (is_int($flag)) {
			$this->createPayloadIndexes = $flag === 1;
		}
	}

	// ---------------------------------------------------------
	// UPSERT
	// ---------------------------------------------------------

	public function upsert(AgentEmbeddingChunk $chunk): void {
		$this->assertReady();

		$this->normalizer->validate($chunk);

		$collectionKey = (string)$chunk->collectionKey;
		$this->ensureCollection($collectionKey);

		$collection = $this->normalizer->getBackendCollectionName($collectionKey);
		$url = $this->proxyUrl("/collections/{$collection}/points?wait=true");

		$payload = $this->normalizer->buildPayload($chunk);
		$pointId = $this->buildPointId($chunk);

		$body = [
			"points" => [
				[
					"id" => $pointId,
					"vector" => $chunk->vector,
					"payload" => $payload
				]
			]
		];

		$r = $this->curlJson('PUT', $url, $body);

		$http = (int)($r['http'] ?? 0);
		if ($http < 200 || $http >= 300) {
			throw new \RuntimeException("QualitusQdrant upsert failed HTTP {$http}: " . ($r['error'] ?? '') . ' ' . ($r['raw'] ?? ''));
		}
	}

	// ---------------------------------------------------------
	// EXISTS BY HASH
	// ---------------------------------------------------------

	public function existsByHash(string $collectionKey, string $hash): bool {
		$hash = trim($hash);
		if ($hash === '') {
			return false;
		}
		return $this->existsByFilter($collectionKey, ['hash' => $hash]);
	}

	// ---------------------------------------------------------
	// EXISTS BY FILTER
	// ---------------------------------------------------------

	public function existsByFilter(string $collectionKey, array $filter): bool {
		$this->assertReady();

		$this->ensureCollection($collectionKey);

		$collection = $this->normalizer->getBackendCollectionName($collectionKey);
		$url = $this->proxyUrl("/collections/{$collection}/points/scroll");

		$body = [
			"filter" => $this->buildQdrantFilter($filter),
			"limit" => 1,
			"with_payload" => false,
			"with_vector" => false
		];

		$r = $this->curlJson('POST', $url, $body);

		$http = (int)($r['http'] ?? 0);
		if ($http < 200 || $http >= 300) {
			throw new \RuntimeException("QualitusQdrant existsByFilter failed HTTP {$http}: " . ($r['error'] ?? '') . ' ' . ($r['raw'] ?? ''));
		}

		$data = json_decode((string)($r['raw'] ?? ''), true);
		return isset($data['result']['points']) && is_array($data['result']['points']) && count($data['result']['points']) > 0;
	}

	// ---------------------------------------------------------
	// DELETE BY FILTER
	// ---------------------------------------------------------

	public function deleteByFilter(string $collectionKey, array $filter): int {
		$this->assertReady();

		$this->ensureCollection($collectionKey);

		$collection = $this->normalizer->getBackendCollectionName($collectionKey);
		$url = $this->proxyUrl("/collections/{$collection}/points/delete?wait=true");

		$body = [
			"filter" => $this->buildQdrantFilter($filter)
		];

		$r = $this->curlJson('POST', $url, $body);

		$http = (int)($r['http'] ?? 0);
		if ($http < 200 || $http >= 300) {
			throw new \RuntimeException("QualitusQdrant deleteByFilter failed HTTP {$http}: " . ($r['error'] ?? '') . ' ' . ($r['raw'] ?? ''));
		}

		$data = json_decode((string)($r['raw'] ?? ''), true);

		$deleted = $data['result']['deleted'] ?? null;
		if (is_int($deleted)) {
			return $deleted;
		}

		$points = $data['result']['points'] ?? null;
		if (is_array($points)) {
			return count($points);
		}

		return 0;
	}

	// ---------------------------------------------------------
	// SEARCH
	// ---------------------------------------------------------

	public function search(string $collectionKey, array $vector, int $limit = 3, ?float $minScore = null, ?array $filterSpec = null): array {
		$this->assertReady();

		$this->ensureCollection($collectionKey);

		$collection = $this->normalizer->getBackendCollectionName($collectionKey);
		$url = $this->proxyUrl("/collections/{$collection}/points/search");

		$body = [
			"vector" => $vector,
			"limit" => $limit,
			"with_payload" => true,
			"with_vector" => false
		];

		$qdrantFilter = $this->buildQdrantFilterFromSpec($filterSpec);
		if ($qdrantFilter !== null) {
			$body['filter'] = $qdrantFilter;
		}

		$r = $this->curlJson('POST', $url, $body);

		$http = (int)($r['http'] ?? 0);
		if ($http < 200 || $http >= 300) {
			throw new \RuntimeException("QualitusQdrant search failed HTTP {$http}: " . ($r['error'] ?? '') . ' ' . ($r['raw'] ?? ''));
		}

		$data = json_decode((string)($r['raw'] ?? ''), true);
		if (!isset($data['result']) || !is_array($data['result'])) {
			return [];
		}

		$out = [];
		foreach ($data['result'] as $hit) {
			$score = $hit['score'] ?? null;
			if (!is_numeric($score)) {
				continue;
			}
			$score = (float)$score;
			if ($minScore !== null && $score < $minScore) {
				continue;
			}

			$out[] = [
				'id' => $hit['id'] ?? null,
				'score' => $score,
				'payload' => $hit['payload'] ?? []
			];
		}

		return $out;
	}

	// ---------------------------------------------------------
	// CREATE COLLECTION
	// ---------------------------------------------------------

	public function createCollection(string $collectionKey): void {
		$this->assertReady();

		$collection = $this->normalizer->getBackendCollectionName($collectionKey);

		$vectorSize = $this->normalizer->getVectorSize($collectionKey);
		$distance = $this->normalizer->getDistance($collectionKey);

		$url = $this->proxyUrl("/collections/{$collection}");

		$body = [
			"vectors" => [
				"size" => $vectorSize,
				"distance" => $distance
			]
		];

		$r = $this->curlJson('PUT', $url, $body);

		$http = (int)($r['http'] ?? 0);
		if ($http < 200 || $http >= 300) {
			throw new \RuntimeException("QualitusQdrant createCollection HTTP {$http}: " . ($r['error'] ?? '') . ' ' . ($r['raw'] ?? ''));
		}

		if ($this->createPayloadIndexes) {
			$this->createPayloadIndexes($collectionKey);
		}
	}

	public function deleteCollection(string $collectionKey): void {
		$this->assertReady();

		$collection = $this->normalizer->getBackendCollectionName($collectionKey);
		$url = $this->proxyUrl("/collections/{$collection}");

		$r = $this->curlJson('DELETE', $url, null);

		$http = (int)($r['http'] ?? 0);
		if ($http < 200 || $http >= 300) {
			throw new \RuntimeException("QualitusQdrant deleteCollection HTTP {$http}: " . ($r['raw'] ?? ''));
		}
	}

	public function getInfo(string $collectionKey): array {
		$this->assertReady();

		$collection = $this->normalizer->getBackendCollectionName($collectionKey);
		$url = $this->proxyUrl("/collections/{$collection}");

		$r = $this->curlJson('GET', $url, null);
		$data = json_decode((string)($r['raw'] ?? ''), true);

		return [
			'collection_key' => $collectionKey,
			'collection' => $collection,
			'vector_size' => $this->normalizer->getVectorSize($collectionKey),
			'distance' => $this->normalizer->getDistance($collectionKey),
			'payload_schema' => $data['result']['payload_schema'] ?? [],
			'qdrant_raw' => $data
		];
	}

	// ---------------------------------------------------------
	// PAYLOAD INDEX CREATION (schema-driven, explicit)
	// ---------------------------------------------------------

	protected function createPayloadIndexes(string $collectionKey): void {
		$schema = $this->normalizer->getSchema($collectionKey);
		if (empty($schema) || !is_array($schema)) {
			return;
		}

		foreach ($schema as $field => $def) {
			$type = $this->extractIndexTypeFromSchemaDef($def);
			if ($type === null) {
				continue;
			}

			$index = $this->extractIndexFlagFromSchemaDef($def);
			if (!$index) {
				continue;
			}

			$this->ensureIndex($collectionKey, (string)$field, $type);
		}
	}

	private function extractIndexFlagFromSchemaDef(mixed $def): bool {
		if (!is_array($def)) {
			return false;
		}
		$flag = $def['index'] ?? false;
		return $flag === true;
	}

	// ---------------------------------------------------------
	// FILTER BUILDER (exists/delete)
	// ---------------------------------------------------------

	protected function buildQdrantFilter(array $filter): array {
		$must = [];

		foreach ($filter as $key => $value) {
			$key = trim((string)$key);
			if ($key === '') {
				continue;
			}

			// IMPORTANT:
			// array value means OR on same key => use Qdrant match.any (single condition)
			if (is_array($value)) {
				$vals = [];
				foreach ($value as $v) {
					if ($v === null) {
						continue;
					}
					if (is_array($v) || is_object($v)) {
						continue;
					}
					$vals[] = $v;
				}

				$vals = array_values(array_unique($vals, SORT_REGULAR));
				if (!$vals) {
					continue;
				}

				$must[] = [
					"key" => $key,
					"match" => ["any" => $vals]
				];
				continue;
			}

			$must[] = [
				"key" => $key,
				"match" => ["value" => $value]
			];
		}

		return ["must" => $must];
	}

	private function buildQdrantFilterFromSpec(?array $spec): ?array {
		if ($spec === null) {
			return null;
		}
		if (!is_array($spec) || empty($spec)) {
			return null;
		}

		$must = $this->buildQdrantConditionsFromMap($spec['must'] ?? null);
		$should = $this->buildQdrantConditionsFromMap($spec['any'] ?? null);
		$mustNot = $this->buildQdrantConditionsFromMap($spec['must_not'] ?? null);

		$out = [];
		if (!empty($must)) {
			$out['must'] = $must;
		}
		if (!empty($should)) {
			$out['should'] = $should;
		}
		if (!empty($mustNot)) {
			$out['must_not'] = $mustNot;
		}

		if (empty($out)) {
			return null;
		}

		return $out;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function buildQdrantConditionsFromMap(mixed $map): array {
		if ($map === null) {
			return [];
		}
		if (!is_array($map)) {
			return [];
		}

		$out = [];
		foreach ($map as $key => $value) {
			$key = trim((string)$key);
			if ($key === '') {
				continue;
			}

			// IMPORTANT:
			// array value means OR on same key => use Qdrant match.any (single condition)
			if (is_array($value)) {
				$vals = [];
				foreach ($value as $v) {
					if ($v === null) {
						continue;
					}
					if (is_array($v) || is_object($v)) {
						continue;
					}
					$vals[] = $v;
				}

				$vals = array_values(array_unique($vals, SORT_REGULAR));
				if (!$vals) {
					continue;
				}

				$out[] = [
					'key' => $key,
					'match' => ['any' => $vals]
				];
				continue;
			}

			$out[] = [
				'key' => $key,
				'match' => ['value' => $value]
			];
		}

		return $out;
	}

	// ---------------------------------------------------------
	// ENSURE COLLECTION (create if missing)
	// ---------------------------------------------------------

	private function ensureCollection(string $collectionKey): void {
		$collectionKey = trim($collectionKey);
		if ($collectionKey === '') {
			throw new \InvalidArgumentException('QualitusQdrantVectorStore: collectionKey is required.');
		}

		$collection = $this->normalizer->getBackendCollectionName($collectionKey);
		$cacheKey = strtolower(trim($collection));

		if ($cacheKey === '') {
			throw new \RuntimeException('QualitusQdrantVectorStore: backend collection name is empty.');
		}

		if (isset($this->ensuredCollections[$cacheKey])) {
			return;
		}

		$url = $this->proxyUrl("/collections/{$collection}");

		$r = $this->curlJson('GET', $url, null);
		$http = (int)($r['http'] ?? 0);

		if ($http === 404) {
			$this->createCollection($collectionKey);
		} else if ($http < 200 || $http >= 300) {
			throw new \RuntimeException("QualitusQdrant ensureCollection HTTP {$http}: " . ($r['raw'] ?? ''));
		}

		$this->ensuredCollections[$cacheKey] = true;

		if ($this->createPayloadIndexes) {
			$this->createPayloadIndexes($collectionKey);
		}
	}

	// ---------------------------------------------------------
	// INDEX CREATION (explicit only)
	// ---------------------------------------------------------

	private function ensureIndex(string $collectionKey, string $field, string $type): void {
		$collectionKey = trim($collectionKey);
		$field = trim($field);
		$type = trim($type);

		if ($collectionKey === '' || $field === '' || $type === '') {
			return;
		}

		$collection = $this->normalizer->getBackendCollectionName($collectionKey);
		$cacheKey = strtolower(trim($collection));

		if ($cacheKey === '') {
			return;
		}

		if (isset($this->ensuredIndexes[$cacheKey][$field])) {
			return;
		}

		$url = $this->proxyUrl("/collections/{$collection}/index");

		$body = [
			"field_name" => $field,
			"field_schema" => $type
		];

		$r = $this->curlJson('PUT', $url, $body);
		$http = (int)($r['http'] ?? 0);
		$raw = (string)($r['raw'] ?? '');

		if ($http >= 200 && $http < 300) {
			$this->ensuredIndexes[$cacheKey][$field] = true;
			return;
		}

		if ($http === 409 || stripos($raw, 'already exists') !== false) {
			$this->ensuredIndexes[$cacheKey][$field] = true;
			return;
		}

		throw new \RuntimeException("QualitusQdrant ensureIndex '{$field}' failed HTTP {$http}: " . ($r['error'] ?? '') . ' ' . $raw);
	}

	private function extractIndexTypeFromSchemaDef(mixed $def): ?string {
		if (!is_array($def)) {
			return null;
		}
		$type = $def['type'] ?? null;
		if (!is_string($type)) {
			return null;
		}

		$type = strtolower(trim($type));

		if ($type === 'keyword') return 'keyword';
		if ($type === 'integer') return 'integer';
		if ($type === 'float') return 'float';
		if ($type === 'bool') return 'bool';
		if ($type === 'text') return 'text';
		if ($type === 'uuid') return 'uuid';

		return null;
	}

	// ---------------------------------------------------------
	// POINT ID (deterministic, Qdrant-valid UUID)
	// ---------------------------------------------------------

	private function buildPointId(AgentEmbeddingChunk $chunk): string {
		$hash = trim((string)$chunk->hash);
		$idx = (int)$chunk->chunkIndex;

		if ($hash !== '') {
			$base = $hash . ':' . $idx;
			return $this->uuidV5('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $base);
		}

		return $this->generateUuid();
	}

	private function uuidV5(string $namespaceUuid, string $name): string {
		$nsHex = str_replace('-', '', strtolower(trim($namespaceUuid)));
		if (strlen($nsHex) !== 32 || !ctype_xdigit($nsHex)) {
			throw new \InvalidArgumentException('uuidV5: invalid namespace UUID.');
		}

		$nsBin = hex2bin($nsHex);
		if ($nsBin === false) {
			throw new \InvalidArgumentException('uuidV5: cannot decode namespace UUID.');
		}

		$hash = sha1($nsBin . $name);

		$timeLow = substr($hash, 0, 8);
		$timeMid = substr($hash, 8, 4);
		$timeHi = substr($hash, 12, 4);
		$clkSeq = substr($hash, 16, 4);
		$node = substr($hash, 20, 12);

		$timeHiVal = (hexdec($timeHi) & 0x0fff) | 0x5000;
		$clkSeqVal = (hexdec($clkSeq) & 0x3fff) | 0x8000;

		return sprintf(
			'%s-%s-%04x-%04x-%s',
			$timeLow,
			$timeMid,
			$timeHiVal,
			$clkSeqVal,
			$node
		);
	}

	protected function generateUuid(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand(0, 0xffff), mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0x0fff) | 0x4000,
			mt_rand(0, 0x3fff) | 0x8000,
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
		);
	}

	// ---------------------------------------------------------
	// PROXY URL + CURL
	// ---------------------------------------------------------

	private function proxyUrl(string $path): string {
		$path = trim($path);
		if ($path === '' || $path[0] !== '/') {
			$path = '/' . ltrim($path, '/');
		}

		$base = (string)$this->endpoint;

		// If base has no "?" yet, we must start query string first.
		// Your base already has "?name=..." so we append with "&".
		if (strpos($base, '?') === false) {
			return $base . '?path=' . $path;
		}

		// Avoid duplicate & if base ends with ? or &
		$sep = (substr($base, -1) === '?' || substr($base, -1) === '&') ? '' : '&';
		return $base . $sep . 'path=' . $path;
	}

	protected function curlJson(string $method, string $url, ?array $body): array {
		$ch = curl_init($url);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			"Content-Type: application/json",
			"x-proxy-token: {$this->apikey}"
		]);

		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
		} else if ($method !== 'GET') {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		}

		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		}

		$raw = curl_exec($ch);
		$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);

		curl_close($ch);

		return [
			'raw' => $raw,
			'http' => $http,
			'error' => $error
		];
	}

	private function assertReady(): void {
		if (!$this->endpoint || trim($this->endpoint) === '') {
			throw new \RuntimeException('QualitusQdrantVectorStore not configured: endpoint missing.');
		}
		if (!$this->apikey || trim($this->apikey) === '') {
			throw new \RuntimeException('QualitusQdrantVectorStore not configured: apikey (proxy token) missing.');
		}
	}
}
