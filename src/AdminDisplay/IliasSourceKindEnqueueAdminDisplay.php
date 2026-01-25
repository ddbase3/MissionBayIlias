<?php declare(strict_types=1);

namespace MissionBayIlias\AdminDisplay;

use Base3\Api\IClassMap;
use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\Configuration\Api\IConfiguration;
use Base3\State\Api\IStateStore;
use MissionBayIlias\Api\IContentProvider;
use UiFoundation\Api\IAdminDisplay;

final class IliasSourceKindEnqueueAdminDisplay implements IAdminDisplay {

	private const CONFIG_GROUP = 'enqueuesourcekind';
	private const STATE_LAST_RUN_KEY = 'missionbay.embedding.enqueue.last_run_at';

	public function __construct(
		private readonly IClassMap $classmap,
		private readonly IMvcView $view,
		private readonly IRequest $request,
		private readonly IConfiguration $config,
		private readonly IStateStore $state
	) {}

	public static function getName(): string {
		return 'iliassourcekindenqueueadmindisplay';
	}

	public function getHelp() {
		return 'Toggle enqueue per ILIAS source_kind (IContentProvider::getSourceKind). Default is OFF. After changes, the enqueue job is unblocked by clearing last_run_at in state store.';
	}

	public function setData($data) {
		// no-op
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
		$this->view->setTemplate('AdminDisplay/IliasSourceKindEnqueueAdminDisplay.php');

		$baseEndpoint = $this->buildEndpointBase();

		$this->view->assign('endpoint', $baseEndpoint);
		$this->view->assign('configGroup', self::CONFIG_GROUP);

		return $this->view->loadTemplate();
	}

	private function handleJson(): string {
		$action = (string)($this->request->get('action') ?? '');

		try {
			return match ($action) {
				'list' => $this->jsonSuccess([
					'group' => self::CONFIG_GROUP,
					'kinds' => $this->listKinds(),
				]),
				'set_active' => $this->jsonSuccess([
					'group' => self::CONFIG_GROUP,
					'kind' => $this->setActive(
						(string)($this->request->get('kind') ?? ''),
						$this->normalizeBool($this->request->get('value'))
					),
				]),
				default => $this->jsonError("Unknown action '$action'. Use: list|set_active"),
			};
		} catch (\Throwable $e) {
			return $this->jsonError('Exception: ' . $e->getMessage());
		}
	}

	// ---------------------------------------------------------------------
	// Core logic
	// ---------------------------------------------------------------------

	private function listKinds(): array {
		$map = [];

		$instances = $this->classmap->getInstances(['interface' => IContentProvider::class]);
		foreach ($instances as $provider) {
			try {
				if (!method_exists($provider, 'getSourceKind')) continue;
				$kind = (string)$provider->getSourceKind();
				$kind = $this->normalizeKey($kind);
				if ($kind === '') continue;

				$map[$kind] = true;
			} catch (\Throwable $e) {
				// ignore faulty provider
			}
		}

		$kinds = array_keys($map);
		sort($kinds, SORT_STRING);

		$rows = [];
		foreach ($kinds as $kind) {
			$key = $kind . '.active';

			$hasActive = $this->config->hasValue(self::CONFIG_GROUP, $key);

			// Default: OFF (0) if not present
			$active = $hasActive
				? $this->config->getBool(self::CONFIG_GROUP, $key, false)
				: false;

			$rows[] = [
				'kind' => $kind,
				'active' => (bool)$active,
				'hasActiveConfig' => $hasActive,
			];
		}

		return $rows;
	}

	private function setActive(string $kind, bool $value): array {
		$kind = $this->normalizeKey($kind);
		if ($kind === '') {
			throw new \RuntimeException('Missing kind.');
		}

		$key = $kind . '.active';
		$this->persist(self::CONFIG_GROUP, $key, $value ? '1' : '0');

		// Unblock enqueue job immediately
		$this->state->delete(self::STATE_LAST_RUN_KEY);

		return [
			'kind' => $kind,
			'active' => $value,
			'clearedStateKey' => self::STATE_LAST_RUN_KEY,
		];
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	private function persist(string $group, string $key, string $value): void {
		$ok = false;

		try {
			$ok = $this->config->persistValue($group, $key, $value);
		} catch (\Throwable $e) {
			$ok = false;
		}

		if ($ok) {
			return;
		}

		$this->config->setValue($group, $key, $value);
		$this->config->saveIfDirty();
	}

	private function normalizeKey(string $s): string {
		$s = trim($s);
		$s = strtolower($s);
		// allow only: a-z 0-9 . _ -
		$s = preg_replace('/[^a-z0-9._-]+/', '', $s) ?? '';
		return $s;
	}

	private function normalizeBool(mixed $v): bool {
		if (is_bool($v)) return $v;
		$s = strtolower(trim((string)$v));
		return in_array($s, ['1', 'true', 'yes', 'on'], true);
	}

	private function buildEndpointBase(): string {
		$baseEndpoint = '';
		try {
			$baseEndpoint = (string)($this->config->get('base')['endpoint'] ?? '');
		} catch (\Throwable $e) {
			$baseEndpoint = '';
		}

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
