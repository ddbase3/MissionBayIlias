<?php declare(strict_types=1);

namespace MissionBayIlias\Resource;

use MissionBay\Api\IAgentContentParser;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;
use MissionBay\Resource\AbstractAgentResource;

/**
 * IliasFileQualitusParserAgentResource
 *
 * Parses ILIAS file payloads (type=file) by sending the file to the Qualitus parser proxy
 * (Docling behind the proxy) and returning extracted text.
 *
 * Debug (CLI):
 * - prints the file path + file name being processed
 * - prints extracted text length (chars)
 */
final class IliasFileQualitusParserAgentResource extends AbstractAgentResource implements IAgentContentParser {

	private const CONTENT_TYPE = 'application/x-ilias-content-json';

	private const SUPPORTED_TYPES = [
		'file',
	];

	protected IAgentConfigValueResolver $resolver;

	protected array|string|null $endpointConfig = null;
	protected array|string|null $apikeyConfig = null;

	protected int $timeoutSeconds = 90;
	protected int $maxBytes = 0;

	/**
	 * @var array<string,mixed>
	 */
	protected array $resolvedOptions = [];

	public function __construct(IAgentConfigValueResolver $resolver, ?string $id = null) {
		parent::__construct($id);
		$this->resolver = $resolver;
	}

	public static function getName(): string {
		return 'iliasfilequalitusparseragentresource';
	}

	public function getDescription(): string {
		return 'Parser for ILIAS file payloads (file): sends file to Qualitus parser proxy (Docling) and returns extracted text.';
	}

	public function getPriority(): int {
		// Adjust in flow by ordering/priority. Keep lower than unstructured if you want unstructured to win.
		return 45;
	}

	public function setConfig(array $config): void {
		parent::setConfig($config);

		$this->endpointConfig = $config['endpoint'] ?? null;
		$this->apikeyConfig = $config['apikey'] ?? null;

		$this->timeoutSeconds = (int)($this->resolver->resolveValue($config['timeout_seconds'] ?? 90) ?? 90);
		$this->maxBytes = (int)($this->resolver->resolveValue($config['max_bytes'] ?? 0) ?? 0);

		$this->resolvedOptions = [
			'endpoint' => (string)($this->resolver->resolveValue($this->endpointConfig) ?? ''),
			'apikey' => (string)($this->resolver->resolveValue($this->apikeyConfig) ?? ''),
			'timeout_seconds' => max(1, $this->timeoutSeconds),
			'max_bytes' => max(0, $this->maxBytes),
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
			throw new \RuntimeException("ILIAS file(qualitus/docling) parser: unsupported payload type '{$type}'.");
		}

		$title = trim((string)($payload['title'] ?? ''));

		$meta = $payload['meta'] ?? null;
		$metaArr = (is_array($meta) || is_object($meta)) ? (array)$meta : [];

		$fileName = trim((string)($metaArr['file_name'] ?? ''));
		$path = trim((string)($metaArr['location'] ?? $metaArr['file_path'] ?? ''));

		if ($path === '') {
			throw new \RuntimeException('ILIAS file(qualitus/docling) parser: missing meta.location (file path).');
		}
		if (!file_exists($path)) {
			throw new \RuntimeException("ILIAS file(qualitus/docling) parser: file not found at path '{$path}'.");
		}
		if (!is_readable($path)) {
			throw new \RuntimeException("ILIAS file(qualitus/docling) parser: file not readable at path '{$path}'.");
		}

		if ($this->resolvedOptions['max_bytes'] > 0) {
			$size = @filesize($path);
			if (is_int($size) && $size > 0 && $size > (int)$this->resolvedOptions['max_bytes']) {
				throw new \RuntimeException('ILIAS file(qualitus/docling) parser: file exceeds max_bytes (' . $size . ' > ' . (int)$this->resolvedOptions['max_bytes'] . ').');
			}
		}

		$effectiveName = $fileName !== '' ? $fileName : basename($path);

		$this->cliDebug('Qualitus/Docling parse file=' . $path . ' name=' . $effectiveName);

		$response = $this->callQualitusParser($path, $effectiveName);

		$text = $this->doclingResponseToText($response);
		$text = $this->normalizeText($text);

		$this->cliDebug('Qualitus/Docling extracted chars=' . (string)mb_strlen($text));

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
		$metaOut['parser'] = 'qualitus_docling';
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
			throw new \RuntimeException('ILIAS file(qualitus/docling) parser: item.content is not an array.');
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
			throw new \RuntimeException('ILIAS file(qualitus/docling) parser: missing root.content payload.');
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
	 * Calls Qualitus parser proxy (Docling behind).
	 *
	 * Expected response shape:
	 * { "metadata": { ... }, "elements": [ { "type": "...", "text": "..." }, ... ] }
	 *
	 * @return array<string,mixed>
	 */
	private function callQualitusParser(string $filePath, string $filename): array {
		$endpoint = (string)($this->resolvedOptions['endpoint'] ?? '');
		$token = (string)($this->resolvedOptions['apikey'] ?? '');
		$timeout = (int)($this->resolvedOptions['timeout_seconds'] ?? 90);

		if ($endpoint === '') {
			throw new \RuntimeException('ILIAS file(qualitus/docling) parser: missing endpoint config.');
		}
		if ($token === '') {
			throw new \RuntimeException('ILIAS file(qualitus/docling) parser: missing apikey config.');
		}

		$cfile = new \CURLFile($filePath, 'application/octet-stream', $filename);

		$ch = curl_init($endpoint);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(20, $timeout));
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'X-Proxy-Token: ' . $token,
		]);
		curl_setopt($ch, CURLOPT_POSTFIELDS, [
			'file' => $cfile,
		]);

		$result = curl_exec($ch);

		if (curl_errno($ch)) {
			$err = curl_error($ch);
			curl_close($ch);
			throw new \RuntimeException('Qualitus parser proxy request failed: ' . $err);
		}

		$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($httpCode < 200 || $httpCode >= 300) {
			$body = substr((string)$result, 0, 600);
			$detail = $this->tryExtractErrorDetail($body);
			$msg = "Qualitus parser proxy failed with HTTP {$httpCode}";
			if ($detail !== '') {
				$msg .= ': ' . $detail;
			} elseif ($body !== '') {
				$msg .= ': ' . $body;
			}
			throw new \RuntimeException($msg);
		}

		$data = json_decode((string)$result, true);
		if (!is_array($data)) {
			throw new \RuntimeException('Qualitus parser proxy response is not valid JSON object.');
		}

		return $data;
	}

	private function tryExtractErrorDetail(string $body): string {
		$decoded = json_decode($body, true);
		if (!is_array($decoded)) {
			return '';
		}
		$detail = $decoded['detail'] ?? null;
		return is_string($detail) ? trim($detail) : '';
	}

	/**
	 * Convert Docling-like response to plain text.
	 *
	 * Notes:
	 * - In your curl test, "elements[].text" is a string representation of a Python object,
	 *   containing patterns like "text='Hello Docling'" and "orig='Hello Docling'".
	 */
	private function doclingResponseToText(array $response): string {
		$elements = $response['elements'] ?? null;
		if (!is_array($elements)) {
			return '';
		}

		$out = [];

		foreach ($elements as $el) {
			if (!is_array($el) && !is_object($el)) {
				continue;
			}
			$a = (array)$el;

			$raw = $a['text'] ?? null;
			if (!is_string($raw)) {
				continue;
			}

			$raw = trim($raw);
			if ($raw === '') {
				continue;
			}

			$text = $this->extractDoclingTextField($raw);
			$text = trim($text);

			if ($text === '') {
				continue;
			}

			$out[] = $text;
		}

		return implode("\n\n", $out);
	}

	/**
	 * Try to extract the useful text portion from Docling's stringified item format.
	 */
	private function extractDoclingTextField(string $raw): string {
		// Most common: "... text='Hello Docling' ..."
		if (preg_match("/\btext='([^']*)'/u", $raw, $m) === 1) {
			return (string)$m[1];
		}

		// Fallback: sometimes "orig='...'"
		if (preg_match("/\borig='([^']*)'/u", $raw, $m) === 1) {
			return (string)$m[1];
		}

		// Last resort: use full string
		return $raw;
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

	private function cliDebug(string $msg): void {
		if (PHP_SAPI !== 'cli') {
			return;
		}
		echo '- ' . $msg . "\n";
	}
}
