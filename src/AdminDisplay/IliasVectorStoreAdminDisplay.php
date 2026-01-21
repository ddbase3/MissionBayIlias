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
				default => $this->jsonError("Unknown action '$action'. Use: create|delete|info"),
			};
		} catch (\Throwable $e) {
			return $this->jsonError('Exception: ' . $e->getMessage());
		}
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
