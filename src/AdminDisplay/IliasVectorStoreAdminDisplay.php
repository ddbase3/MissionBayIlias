<?php declare(strict_types=1);

namespace MissionBayIlias\AdminDisplay;

use Base3\Api\IClassMap;
use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\Configuration\Api\IConfiguration;
use MissionBay\Api\IAgentVectorStore;
use UiFoundation\Api\IAdminDisplay;

final class IliasVectorStoreAdminDisplay implements IAdminDisplay {

	/** @var string canonical logical collection key for ILIAS */
	private const COLLECTION_KEY = 'ilias';

	/** @var string resource technical name (as registered in classmap) */
	private const RESOURCE_NAME = 'qualitusqdrantvectorstoreagentresource';

	/** @var string section of vectordb configuration values */
	private const CONNECTION_CONFIG_SECTION = 'qualitusvectordb';

	public function __construct(
		private readonly IRequest $request,
		private readonly IClassMap $classmap,
		private readonly IMvcView $view,
		private readonly IConfiguration $config
	) {}

	public static function getName(): string {
		return 'iliasvectorstoreadmindisplay';
	}

	public function setData($data) {
		// no-op (can be used later for embedding into other UIs)
	}

	public function getHelp() {
		return 'ILIAS VectorStore admin display (html UI + json endpoint).';
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
		$this->view->setTemplate('AdminDisplay/IliasVectorStoreAdminDisplay.php');

		$baseEndpoint = (string)($this->config->get('base')['endpoint'] ?? '');
		$endpoint = $this->buildEndpointBase($baseEndpoint);

		$this->view->assign('endpoint', $endpoint);
		$this->view->assign('collectionKey', self::COLLECTION_KEY);
		$this->view->assign('resourceName', self::RESOURCE_NAME);

		return $this->view->loadTemplate();
	}

	private function handleJson(): string {
		$action = (string)($this->request->get('action') ?? '');

		$store = $this->loadVectorStore();
		if (!$store) {
			return $this->jsonError('VectorStore resource not found: ' . self::RESOURCE_NAME);
		}

		try {
			return match ($action) {
				'create' => $this->jsonSuccess([
					'message' => 'Collection created successfully.',
					'collectionKey' => self::COLLECTION_KEY
				], fn() => $store->createCollection(self::COLLECTION_KEY)),

				'delete' => $this->jsonSuccess([
					'message' => 'Collection deleted successfully.',
					'collectionKey' => self::COLLECTION_KEY
				], fn() => $store->deleteCollection(self::COLLECTION_KEY)),

				'info' => $this->jsonSuccess([
					'collectionKey' => self::COLLECTION_KEY,
					'info' => $store->getInfo(self::COLLECTION_KEY)
				]),

				'stats' => $this->jsonSuccess([
					'collectionKey' => self::COLLECTION_KEY,
					'stats' => $this->buildStats($store->getInfo(self::COLLECTION_KEY))
				]),

				default => $this->jsonError("Unknown action '$action'. Use: create|delete|info|stats"),
			};
		} catch (\Throwable $e) {
			return $this->jsonError('Exception: ' . $e->getMessage());
		}
	}

	private function buildStats(array $info): array {
		$qraw = $info['qdrant_raw']['result'] ?? null;

		$status = (string)($qraw['status'] ?? '');
		$optimizer = (string)($qraw['optimizer_status'] ?? '');
		$points = (int)($qraw['points_count'] ?? 0);
		$indexed = (int)($qraw['indexed_vectors_count'] ?? 0);
		$segments = (int)($qraw['segments_count'] ?? 0);

		$timeSec = (float)($info['qdrant_raw']['time'] ?? 0.0);
		$timeMs = (int)round($timeSec * 1000.0);

		$config = $qraw['config'] ?? [];
		$params = $config['params'] ?? [];
		$vectors = $params['vectors'] ?? [];
		$hnsw = $config['hnsw_config'] ?? [];
		$strict = $config['strict_mode_config'] ?? [];

		$onDiskPayload = $params['on_disk_payload'] ?? null;
		$shardNumber = $params['shard_number'] ?? null;
		$replicationFactor = $params['replication_factor'] ?? null;

		$payloadSchema = $qraw['payload_schema'] ?? ($info['payload_schema'] ?? []);
		$fieldCount = is_array($payloadSchema) ? count($payloadSchema) : 0;

		$zeroFields = [];
		$nonZeroFields = [];
		if (is_array($payloadSchema)) {
			foreach ($payloadSchema as $k => $v) {
				$p = (int)($v['points'] ?? 0);
				if ($p <= 0) {
					$zeroFields[] = (string)$k;
				} else {
					$nonZeroFields[] = (string)$k;
				}
			}
		}

		// erwartete Felder (aus deinem Pipeline-Kontext)
		$expected = [
			'content_uuid',
			'collection_key',
			'container_obj_id',
			'source_kind',
			'type',
			'chunktoken',
			'chunk_index',
			'hash',
			'read_roles',
			'ancestor_ref_ids',
			'mount_ref_ids',
			'title',
		];

		$missingExpected = [];
		foreach ($expected as $k) {
			if (!is_array($payloadSchema) || !array_key_exists($k, $payloadSchema)) {
				$missingExpected[] = $k;
			}
		}

		$topKeysPreview = '';
		if (count($nonZeroFields) > 0) {
			$top = array_slice($nonZeroFields, 0, 6);
			$topKeysPreview = implode(', ', $top);
			if (count($nonZeroFields) > 6) {
				$topKeysPreview .= ', …';
			}
		} else {
			$topKeysPreview = '–';
		}

		// Badges (bewusst einfache, robuste Heuristik)
		$badgeHealth = ['state' => 'ok', 'label' => 'OK'];
		if ($status !== 'green' || $optimizer !== 'ok') {
			$badgeHealth = ['state' => 'warn', 'label' => 'Degraded'];
		}

		$badgeSize = ['state' => 'ok', 'label' => 'OK'];
		if ($points <= 0) {
			$badgeSize = ['state' => 'warn', 'label' => 'Leer'];
		}

		$badgeSchema = ['state' => 'ok', 'label' => 'OK'];
		if (count($missingExpected) > 0) {
			$badgeSchema = ['state' => 'warn', 'label' => 'Lücken'];
		}
		// "title points = 0" ist bei Qdrant oft normal (Text index), wir werten das nicht als err.

		$badgeConfig = ['state' => 'ok', 'label' => 'OK'];
		$vecSize = $vectors['size'] ?? null;
		$dist = $vectors['distance'] ?? null;
		if ((int)$vecSize !== 1024 || (string)$dist !== 'Cosine') {
			$badgeConfig = ['state' => 'warn', 'label' => 'Abweichung'];
		}

		return [
			'timestamp' => gmdate('Y-m-d H:i:s') . ' UTC',
			'health' => [
				'status' => $status === '' ? null : $status,
				'optimizer_status' => $optimizer === '' ? null : $optimizer,
				'segments_count' => $segments,
				'info_time_ms' => $timeMs,
				'note' => $this->healthNote($status, $optimizer, $timeMs),
			],
			'size' => [
				'collection' => (string)($info['collection'] ?? ''),
				'points_count' => $points,
				'indexed_vectors_count' => $indexed,
				'on_disk_payload' => $onDiskPayload === null ? null : ($onDiskPayload ? 'yes' : 'no'),
				'shard_number' => $shardNumber,
				'replication_factor' => $replicationFactor,
			],
			'schema' => [
				'field_count' => $fieldCount,
				'zero_point_fields_count' => count($zeroFields),
				'expected_missing_count' => count($missingExpected),
				'top_keys_preview' => $topKeysPreview,
				'note' => $this->schemaNote($missingExpected, $zeroFields),
			],
			'config' => [
				'vector_size' => $vecSize,
				'distance' => $dist,
				'hnsw_m' => $hnsw['m'] ?? null,
				'hnsw_ef_construct' => $hnsw['ef_construct'] ?? null,
				'full_scan_threshold' => $hnsw['full_scan_threshold'] ?? null,
				'strict_mode_enabled' => ($strict['enabled'] ?? null) === null ? null : (($strict['enabled'] ?? false) ? 'yes' : 'no'),
				'note' => $this->configNote($vecSize, (string)$dist, $onDiskPayload),
			],
			'badges' => [
				'health' => $badgeHealth,
				'size' => $badgeSize,
				'schema' => $badgeSchema,
				'config' => $badgeConfig,
			],
		];
	}

	private function healthNote(string $status, string $optimizer, int $timeMs): string {
		if ($status !== 'green') {
			return 'Qdrant meldet keinen "green" Status.';
		}
		if ($optimizer !== 'ok') {
			return 'Optimizer ist nicht OK (kann auf laufende Optimierungen oder Probleme hindeuten).';
		}
		if ($timeMs >= 500) {
			return 'Info-Call ist relativ langsam (Latenz prüfen).';
		}
		return 'Gesund (status=green, optimizer=ok).';
	}

	private function schemaNote(array $missingExpected, array $zeroFields): string {
		if (count($missingExpected) > 0) {
			return 'Fehlende erwartete Keys: ' . implode(', ', array_slice($missingExpected, 0, 6)) . (count($missingExpected) > 6 ? ', …' : '');
		}

		// zeroFields ist informational (z.B. title points=0 ist OK)
		if (count($zeroFields) > 0) {
			return 'Hinweis: Keys mit 0 Punkten: ' . implode(', ', array_slice($zeroFields, 0, 6)) . (count($zeroFields) > 6 ? ', …' : '');
		}

		return 'Schema vollständig und befüllt.';
	}

	private function configNote($vecSize, string $dist, $onDiskPayload): string {
		$parts = [];
		if ((int)$vecSize === 1024 && $dist === 'Cosine') {
			$parts[] = 'Vector config passt.';
		} else {
			$parts[] = 'Vector config weicht ab.';
		}
		if ($onDiskPayload !== null) {
			$parts[] = 'Payload on-disk: ' . ($onDiskPayload ? 'yes' : 'no');
		}
		return implode(' ', $parts);
	}

	private function loadVectorStore(): ?IAgentVectorStore {
		/** @var IAgentVectorStore|null $store */
		$store = $this->classmap->getInstanceByInterfaceName(
			IAgentVectorStore::class,
			self::RESOURCE_NAME
		);

		if (!$store) {
			return null;
		}

		// Only connection config here. Collection routing is driven by COLLECTION_KEY.
		if (method_exists($store, 'setConfig')) {
			$store->setConfig([
				'endpoint' => [
					'mode' => 'config',
					'section' => self::CONNECTION_CONFIG_SECTION,
					'key' => 'endpoint'
				],
				'apikey' => [
					'mode' => 'config',
					'section' => self::CONNECTION_CONFIG_SECTION,
					'key' => 'apikey'
				]
			]);
		}

		return $store;
	}

	private function buildEndpointBase(string $baseEndpoint): string {
		$baseEndpoint = trim($baseEndpoint);

		// fallback: typical BASE3 routing if config missing
		if ($baseEndpoint === '') {
			$baseEndpoint = 'base3.php';
		}

		$sep = str_contains($baseEndpoint, '?') ? '&' : '?';

		return $baseEndpoint . $sep . 'name=' . rawurlencode(self::getName()) . '&out=json&action=';
	}

	private function jsonSuccess(array $data, ?callable $sideEffect = null): string {
		if ($sideEffect) {
			$sideEffect();
		}

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
