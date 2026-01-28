<?php declare(strict_types=1);

namespace MissionBayIlias\Resource;

use MissionBay\Api\IAgentContentParser;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;
use MissionBay\Resource\AbstractAgentResource;

/**
 * IliasFileUnstructuredParserAgentResource
 *
 * Parses ILIAS file payloads (type=file) by sending the binary to a self-hosted
 * Unstructured API and returning plain text.
 *
 * Root shape (as produced by IliasEmbeddingQueueExtractorAgentResource):
 * - item.content is an ARRAY
 * - root['content'] IS the provider payload (no nested root.content.content)
 *
 * Supported payload types:
 * - file
 */
final class IliasFileUnstructuredParserAgentResource extends AbstractAgentResource implements IAgentContentParser {

	private const CONTENT_TYPE = 'application/x-ilias-content-json';

	private const SUPPORTED_TYPES = [
		'file',
	];

	protected IAgentConfigValueResolver $resolver;

	protected array|string|null $endpointConfig = null;
	protected array|string|null $apikeyConfig = null;

	protected array $resolvedOptions = [];

	public function __construct(IAgentConfigValueResolver $resolver, ?string $id = null) {
		parent::__construct($id);
		$this->resolver = $resolver;
	}

	public static function getName(): string {
		return 'iliasfileunstructuredparseragentresource';
	}

	public function getDescription(): string {
		return 'Parser for ILIAS file payloads (file): sends file binary to Unstructured API and returns extracted text.';
	}

	public function getPriority(): int {
		// Must win against generic structured parsers.
		return 35;
	}

	public function setConfig(array $config): void {
		parent::setConfig($config);

		$this->endpointConfig = $config['endpoint'] ?? null;
		$this->apikeyConfig = $config['apikey'] ?? null;

		$this->resolvedOptions = [
			'endpoint' => (string)($this->resolver->resolveValue($this->endpointConfig) ?? 'https://unstructured.base3.de/general/v0/general'),
			'apikey' => (string)($this->resolver->resolveValue($this->apikeyConfig) ?? ''),
		];

		$this->resolvedOptions['endpoint'] = trim($this->resolvedOptions['endpoint']);
		$this->resolvedOptions['apikey'] = trim($this->resolvedOptions['apikey']);
	}

	public function supports(AgentContentItem $item): bool {
		if ((string)($item->contentType ?? '') !== self::CONTENT_TYPE) {
			return false;
		}

		$payload = $this->extractPayload($item);
		if ($payload === null) {
			return false;
		}

		$type = strtolower(trim((string)($payload['type'] ?? '')));
		return in_array($type, self::SUPPORTED_TYPES, true);
	}

	public function parse(AgentContentItem $item): AgentParsedContent {
		$root = $this->requireRootArray($item);
		$payload = $this->requirePayload($item);

		$type = strtolower(trim((string)($payload['type'] ?? '')));
		if ($type !== 'file') {
			throw new \RuntimeException("ILIAS file(unstructured) parser: unsupported payload type '{$type}'.");
		}

		$title = trim((string)($payload['title'] ?? ''));

		$meta = $payload['meta'] ?? null;
		$metaArr = (is_array($meta) || is_object($meta)) ? (array)$meta : [];

		$fileName = trim((string)($metaArr['file_name'] ?? ''));
		$path = trim((string)($metaArr['location'] ?? $metaArr['file_path'] ?? ''));

		if ($path === '') {
			throw new \RuntimeException('ILIAS file(unstructured) parser: missing meta.location (file path).');
		}
		if (!file_exists($path)) {
			throw new \RuntimeException("ILIAS file(unstructured) parser: file not found at path '{$path}'.");
		}
		if (!is_readable($path)) {
			throw new \RuntimeException("ILIAS file(unstructured) parser: file not readable at path '{$path}'.");
		}

		$binary = file_get_contents($path);
		if (!is_string($binary) || $binary === '') {
			throw new \RuntimeException("ILIAS file(unstructured) parser: empty binary from '{$path}'.");
		}

		$elements = $this->callUnstructured($binary, $fileName !== '' ? $fileName : basename($path));
		$text = $this->unstructuredElementsToText($elements);

		$text = $this->normalizeText($text);

		if ($title !== '' && $text !== '') {
			$text = $title . "\n\n" . $text;
		} elseif ($title !== '' && $text === '') {
			$text = $title;
		}

		// Keep root shape intact: root['content'] is the payload.
		// Enrich payload content with extracted text for downstream chunkers/debugging.
		$payload['content'] = $text;
		$payload['meta'] = $metaArr;

		$root['content'] = $payload;

		$metaOut = is_array($item->metadata) ? $item->metadata : [];
		$metaOut['type'] = 'file';
		$metaOut['parser'] = 'unstructured';
		if ($fileName !== '') {
			$metaOut['file_name'] = $fileName;
		}
		$metaOut['location'] = $path;

		return new AgentParsedContent(
			text: trim($text),
			metadata: $metaOut,
			structured: $root,
			attachments: []
		);
	}

	/**
	 * Root must be item.content array (your extractor sets content: $content array).
	 *
	 * @return array<string,mixed>
	 */
	private function requireRootArray(AgentContentItem $item): array {
		if (!is_array($item->content)) {
			throw new \RuntimeException('ILIAS file(unstructured) parser: item.content is not an array.');
		}
		return $item->content;
	}

	/**
	 * Payload is root['content'] (NOT root.content.content).
	 *
	 * @return array<string,mixed>
	 */
	private function requirePayload(AgentContentItem $item): array {
		$payload = $this->extractPayload($item);
		if ($payload === null) {
			throw new \RuntimeException('ILIAS file(unstructured) parser: missing root.content payload.');
		}
		return $payload;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function extractPayload(AgentContentItem $item): ?array {
		if (!is_array($item->content)) {
			return null;
		}

		$root = $item->content;
		$payload = $root['content'] ?? null;

		if (is_array($payload)) {
			return $payload;
		}
		if (is_object($payload)) {
			return (array)$payload;
		}

		return null;
	}

	/**
	 * Calls Unstructured API.
	 *
	 * @return array<int,mixed>
	 */
	private function callUnstructured(string $binary, string $filename): array {
		$endpoint = (string)($this->resolvedOptions['endpoint'] ?? '');
		$apikey = (string)($this->resolvedOptions['apikey'] ?? '');

		if ($endpoint === '') {
			throw new \RuntimeException('ILIAS file(unstructured) parser: missing endpoint config.');
		}
		if ($apikey === '') {
			throw new \RuntimeException('ILIAS file(unstructured) parser: missing API key config.');
		}

		$tmp = tempnam(sys_get_temp_dir(), 'unstructured_');
		if (!is_string($tmp) || $tmp === '') {
			throw new \RuntimeException('ILIAS file(unstructured) parser: failed to create temp file.');
		}

		file_put_contents($tmp, $binary);

		$cfile = new \CURLFile($tmp, 'application/octet-stream', $filename);

		$headers = [
			'X-API-Key: ' . $apikey,
		];

		$post = [
			'files' => $cfile,
		];

		$ch = curl_init($endpoint);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

		$result = curl_exec($ch);

		if (curl_errno($ch)) {
			$err = curl_error($ch);
			curl_close($ch);
			@unlink($tmp);
			throw new \RuntimeException('Unstructured API request failed: ' . $err);
		}

		$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		@unlink($tmp);

		if ($httpCode !== 200) {
			throw new \RuntimeException("Unstructured API request failed with status {$httpCode}: " . substr((string)$result, 0, 500));
		}

		$data = json_decode((string)$result, true);
		if (!is_array($data)) {
			throw new \RuntimeException('Unstructured API response is not valid JSON array.');
		}

		return $data;
	}

	/**
	 * Turns Unstructured elements into readable text.
	 *
	 * Typical element shapes include keys like:
	 * - type / element_id / text / metadata
	 */
	private function unstructuredElementsToText(array $elements): string {
		$out = [];

		foreach ($elements as $el) {
			if (!is_array($el) && !is_object($el)) {
				continue;
			}
			$a = (array)$el;

			$t = $a['text'] ?? null;
			if (!is_string($t)) {
				continue;
			}

			$t = trim($t);
			if ($t === '') {
				continue;
			}

			$out[] = $t;
		}

		return implode("\n\n", $out);
	}

	private function normalizeText(string $text): string {
		$text = str_replace(["\r\n", "\r"], "\n", $text);

		$lines = explode("\n", $text);
		foreach ($lines as &$line) {
			$line = $this->normalizeInlineWhitespace($line);
		}
		unset($line);

		$text = implode("\n", $lines);
		$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

		return trim($text);
	}

	private function normalizeInlineWhitespace(string $s): string {
		$s = preg_replace('/[ \t]+/u', ' ', $s) ?? $s;
		return trim($s);
	}
}
