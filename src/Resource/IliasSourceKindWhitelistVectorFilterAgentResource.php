<?php declare(strict_types=1);

namespace MissionBayIlias\Resource;

use MissionBay\Api\IAgentConfigValueResolver;
use MissionBay\Api\IAgentVectorFilter;
use MissionBay\Resource\AbstractAgentResource;

/**
 * IliasSourceKindWhitelistVectorFilterAgentResource
 *
 * Restricts vector retrieval to a configured whitelist of ILIAS source kinds.
 *
 * Config:
 * - sourcekinds: array|string  (resolved via IAgentConfigValueResolver)
 *
 * Examples:
 * - ["wiki_page","glo_term"]
 * - "wiki_page,glo_term"
 *
 * FilterSpec result:
 * - ['must' => ['source_kind' => ['wiki_page','glo_term']]]
 */
final class IliasSourceKindWhitelistVectorFilterAgentResource extends AbstractAgentResource implements IAgentVectorFilter {

	private array|string|null $sourceKindsConfig = null;

	public function __construct(
		private readonly IAgentConfigValueResolver $resolver,
		?string $id = null
	) {
		parent::__construct($id);
	}

	public static function getName(): string {
		return 'iliassourcekindwhitelistvectorfilteragentresource';
	}

	public function getDescription(): string {
		return 'Restricts vector retrieval to configured ILIAS source_kind whitelist (e.g. wiki_page, glo_term).';
	}

	public function setConfig(array $config): void {
		parent::setConfig($config);
		$this->sourceKindsConfig = $config['sourcekinds'] ?? $config['source_kinds'] ?? $config['whitelist'] ?? null;
	}

	public function getFilterSpec(): ?array {
		$resolved = $this->resolver->resolveValue($this->sourceKindsConfig);

		$kinds = $this->normalizeKinds($resolved);
		if (!$kinds) {
			return null;
		}

		return [
			'must' => [
				// array => OR on same key (per your FilterSpec v1 contract)
				'source_kind' => $kinds
			]
		];
	}

	/**
	 * @return string[]
	 */
	private function normalizeKinds(mixed $value): array {
		if ($value === null) {
			return [];
		}

		$out = [];
		$seen = [];

		$push = function(string $s) use (&$out, &$seen): void {
			$s = strtolower(trim($s));
			if ($s === '') {
				return;
			}
			if (isset($seen[$s])) {
				return;
			}
			$seen[$s] = true;
			$out[] = $s;
		};

		if (is_string($value)) {
			// allow "a,b,c" or "a b c"
			$parts = preg_split('/[,\s]+/', trim($value));
			if (is_array($parts)) {
				foreach ($parts as $p) {
					$push((string)$p);
				}
			}
			return $out;
		}

		if (is_array($value)) {
			foreach ($value as $v) {
				if (is_string($v) || is_numeric($v) || is_bool($v)) {
					$push((string)$v);
				}
			}
			return $out;
		}

		// anything else => ignore
		return [];
	}
}
