<?php declare(strict_types=1);

namespace MissionBayIlias\Resource;

use MissionBay\Api\IAgentChunker;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBay\Dto\AgentParsedContent;
use MissionBay\Resource\AbstractAgentResource;

final class IliasChunkerAgentResource extends AbstractAgentResource implements IAgentChunker {

	protected IAgentConfigValueResolver $resolver;

	protected int $maxLength = 2000;
	protected int $minLength = 500;
	protected int $overlap = 50;

	/**
	 * Inline metadata that is prefixed to every chunk as a HTML comment marker.
	 * Keep it small, stable, and filter-friendly.
	 *
	 * @var string[]
	 */
	protected array $inlineMetaFields = [
		'kind',
		'locator',
		'container_obj_id',
		'source_int_id',
		'title'
	];

	/**
	 * @var array<string,mixed>
	 */
	protected array $resolvedOptions = [];

	public static function getName(): string {
		return 'iliaschunkeragentresource';
	}

	public function __construct(IAgentConfigValueResolver $resolver, ?string $id = null) {
		parent::__construct($id);
		$this->resolver = $resolver;
	}

	public function getDescription(): string {
		return 'RAG chunker for ILIAS content units (wiki/wiki_page): merge content fields, sticky headings, inline meta in every chunk.';
	}

	public function getPriority(): int {
		return 40;
	}

	public function setConfig(array $config): void {
		parent::setConfig($config);

		$this->maxLength = $this->resolveInt($config, 'max_length', $this->maxLength);
		$this->minLength = $this->resolveInt($config, 'min_length', $this->minLength);
		$this->overlap = $this->resolveInt($config, 'overlap', $this->overlap);

		$this->inlineMetaFields = $this->resolveInlineMetaFields($config);

		$this->resolvedOptions = [
			'max_length' => $this->maxLength,
			'min_length' => $this->minLength,
			'overlap' => $this->overlap,
			'inline_meta_fields' => $this->inlineMetaFields
		];
	}

	public function getOptions(): array {
		return $this->resolvedOptions;
	}

	public function supports(AgentParsedContent $parsed): bool {
		if (!is_array($parsed->structured) && !is_object($parsed->structured)) {
			return false;
		}

		$root = (array)$parsed->structured;

		// Primary: extractor shape
		if (($root['system'] ?? null) === 'ilias' && isset($root['kind'])) {
			return true;
		}

		// Fallback: any structured object with a content payload
		if (isset($root['kind']) && isset($root['content']) && (is_array($root['content']) || is_object($root['content']))) {
			return true;
		}

		return false;
	}

	/**
	 * @return array<int,array<string,mixed>> raw chunk arrays: {id,text,meta}
	 */
	public function chunk(AgentParsedContent $parsed): array {
		$root = (array)$parsed->structured;
		$meta = is_array($parsed->metadata ?? null) ? $parsed->metadata : [];

		$content = $root['content'] ?? null;
		$data = (is_array($content) || is_object($content)) ? (array)$content : [];

		return $this->chunkStructured($root, $data, $meta);
	}

	/**
	 * @param array<string,mixed> $root
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $meta
	 * @return array<int,array<string,mixed>>
	 */
	protected function chunkStructured(array $root, array $data, array $meta): array {
		$inlineMeta = $this->buildInlineMetadata($root, $data);

		$metaLines = [];
		$textSections = [];

		/*
		 * Key fix:
		 * Treat short strings as metadata lines (not as their own "## <Key>" text sections).
		 * This prevents tiny "## Title" chunks and keeps the first chunk useful.
		 */
		$shortTextThreshold = max(100, (int)floor($this->minLength / 2)); // e.g. minLength=500 => 250

		foreach ($data as $key => $value) {
			if ($value === null || $value === '' || $value === '0' || $value === 0) {
				continue;
			}

			if (is_numeric($value) || is_bool($value)) {
				$metaLines[] = ucfirst((string)$key) . ": " . json_encode($value);
				continue;
			}

			if (is_string($value)) {
				$val = trim($value);

				if ($val === '') {
					continue;
				}

				$len = mb_strlen($val);

				if ($len <= $shortTextThreshold) {
					$metaLines[] = ucfirst((string)$key) . ": " . $val;
					continue;
				}

				$textSections[(string)$key] = $this->normalizeNewlines($val);
				continue;
			}

			if (is_array($value) || is_object($value)) {
				$json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
				$json = $this->normalizeNewlines((string)$json);

				if (mb_strlen($json) <= 100) {
					$metaLines[] = ucfirst((string)$key) . ": " . trim($json);
				} else {
					$textSections[(string)$key] = $json;
				}
				continue;
			}
		}

		$name = $this->pickName($root, $data);
		$fullText = $this->buildFullText($name, $metaLines, $textSections);

		$rawChunks = $this->chunkTextMaxFit($fullText);

		if ($this->overlap > 0 && count($rawChunks) > 1) {
			$rawChunks = $this->applyOverlapRaw($rawChunks);
		}

		$out = [];
		foreach ($rawChunks as $raw) {
			$out[] = $this->makeChunk($this->prefixMeta($inlineMeta, $raw), $meta);
		}

		return $out;
	}

	/**
	 * @param array<int,string> $metaLines
	 * @param array<string,string> $textSections
	 */
	protected function buildFullText(string $name, array $metaLines, array $textSections): string {
		$parts = [];

		$parts[] = "# " . $name;
		$parts[] = "";
		$parts[] = "## Metadata";
		if ($metaLines) {
			$parts[] = implode("\n", $metaLines);
		}

		foreach ($textSections as $key => $content) {
			$parts[] = "";
			$parts[] = "## " . ucfirst((string)$key);
			$parts[] = "";
			$parts[] = trim((string)$content);
		}

		return trim(implode("\n", $parts));
	}

	/**
	 * ILIAS naming:
	 * - prefer content.title
	 * - fallback: kind + locator
	 */
	protected function pickName(array $root, array $data): string {
		$title = $data['title'] ?? null;
		if (is_string($title) && trim($title) !== '') {
			return trim($title);
		}

		$kind = $root['kind'] ?? null;
		$locator = $root['locator'] ?? null;

		if (is_string($kind) && $kind !== '' && is_string($locator) && $locator !== '') {
			return strtoupper($kind) . ' ' . $locator;
		}

		if (is_string($kind) && $kind !== '') {
			return strtoupper($kind);
		}

		return 'ILIAS Content';
	}

	/**
	 * Inline marker helps later retrieval / debugging.
	 */
	protected function buildInlineMetadata(array $root, array $data): string {
		$pairs = [];

		foreach ($this->inlineMetaFields as $field) {
			$val = null;

			// special virtual field
			if ($field === 'title') {
				$val = $data['title'] ?? $root['title'] ?? null;
			} else {
				$val = $root[$field] ?? $data[$field] ?? null;
			}

			if ($val === null) {
				continue;
			}

			if (is_array($val)) {
				$val = implode(',', array_map('trim', array_map('strval', $val)));
			}

			if (is_object($val)) {
				$val = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			}

			$v = str_replace('"', "'", (string)$val);
			$pairs[] = "{$field}=\"{$v}\"";
		}

		if (!$pairs) {
			return "<!-- meta: -->";
		}

		return "<!-- meta: " . implode("; ", $pairs) . " -->";
	}

	private function resolveInt(array $config, string $key, int $default): int {
		$value = $this->resolver->resolveValue($config[$key] ?? $default);
		return (int)$value;
	}

	/**
	 * @return string[]
	 */
	private function resolveInlineMetaFields(array $config): array {
		$value = $this->resolver->resolveValue($config['inline_meta_fields'] ?? $this->inlineMetaFields);

		if (is_string($value)) {
			$items = array_map('trim', explode(',', $value));
			$items = array_values(array_filter($items, fn($v) => $v !== ''));
			return $items ?: $this->inlineMetaFields;
		}

		if (is_array($value)) {
			$out = [];
			foreach ($value as $v) {
				$s = trim((string)$v);
				if ($s !== '') {
					$out[] = $s;
				}
			}
			return $out ?: $this->inlineMetaFields;
		}

		return $this->inlineMetaFields;
	}

	private function normalizeNewlines(string $text): string {
		return preg_replace('/\R/u', "\n", $text);
	}

	private function prefixMeta(string $inlineMeta, string $text): string {
		return trim($inlineMeta . "\n" . trim($text));
	}

	/**
	 * Max-fit chunking:
	 * - split by paragraphs
	 * - keep headings sticky with following paragraph
	 * - fallback: split by lines, then hard split
	 *
	 * @return array<int,string>
	 */
	private function chunkTextMaxFit(string $text): array {
		$text = $this->normalizeNewlines($text);

		if (mb_strlen($text) <= $this->maxLength) {
			return [$text];
		}

		$paras = $this->splitParagraphs($text);
		$paras = $this->mergeStickyHeadings($paras);

		$out = [];
		$current = '';

		foreach ($paras as $p) {
			$p = trim($p);
			if ($p === '') {
				continue;
			}

			$candidate = ($current === '' ? $p : $current . "\n\n" . $p);

			if (mb_strlen($candidate) <= $this->maxLength) {
				$current = $candidate;
				continue;
			}

			if ($current !== '') {
				$out[] = trim($current);
				$current = '';
			}

			if (mb_strlen($p) <= $this->maxLength) {
				$current = $p;
				continue;
			}

			foreach ($this->splitByLinesMaxFit($p) as $part) {
				$out[] = $part;
			}
		}

		if ($current !== '') {
			$out[] = trim($current);
		}

		return $this->enforceMinSizeRaw($out);
	}

	/**
	 * @return array<int,string>
	 */
	private function splitParagraphs(string $text): array {
		$parts = preg_split("/\n{2,}/u", trim($text));
		if (!$parts || count($parts) === 0) {
			return [trim($text)];
		}
		return array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
	}

	/**
	 * @param array<int,string> $paras
	 * @return array<int,string>
	 */
	private function mergeStickyHeadings(array $paras): array {
		$out = [];
		$count = count($paras);

		for ($i = 0; $i < $count; $i++) {
			$p = trim((string)$paras[$i]);
			if ($p === '') {
				continue;
			}

			if ($this->isHeadingOnly($p) && $i + 1 < $count) {
				$next = trim((string)$paras[$i + 1]);
				if ($next !== '') {
					$out[] = $p . "\n\n" . $next;
					$i++;
					continue;
				}
			}

			$out[] = $p;
		}

		return $out;
	}

	private function isHeadingOnly(string $para): bool {
		$para = trim($para);
		if ($para === '') {
			return false;
		}
		if (!preg_match('/^#{1,6}\s+/u', $para)) {
			return false;
		}
		return (substr_count($para, "\n") === 0);
	}

	/**
	 * @return array<int,string>
	 */
	private function splitByLinesMaxFit(string $text): array {
		$text = $this->normalizeNewlines($text);
		$lines = explode("\n", $text);

		$out = [];
		$current = '';

		foreach ($lines as $line) {
			$line = rtrim($line);

			$candidate = ($current === '' ? $line : $current . "\n" . $line);

			if (mb_strlen($candidate) <= $this->maxLength) {
				$current = $candidate;
				continue;
			}

			if ($current !== '') {
				$out[] = trim($current);
				$current = '';
			}

			if (mb_strlen($line) <= $this->maxLength) {
				$current = $line;
				continue;
			}

			foreach ($this->hardSplit($line, $this->maxLength) as $part) {
				$out[] = $part;
			}
		}

		if ($current !== '') {
			$out[] = trim($current);
		}

		return $out;
	}

	/**
	 * @return array<int,string>
	 */
	private function hardSplit(string $text, int $max): array {
		$text = trim($text);
		if ($max < 50) {
			$max = 50;
		}

		$out = [];
		$len = mb_strlen($text);
		$offset = 0;

		while ($offset < $len) {
			$out[] = trim(mb_substr($text, $offset, $max));
			$offset += $max;
		}

		return $out;
	}

	/**
	 * If we ended up with trailing small chunks, merge them into a buffer.
	 *
	 * @param array<int,string> $chunks
	 * @return array<int,string>
	 */
	private function enforceMinSizeRaw(array $chunks): array {
		if (count($chunks) < 2) {
			return $chunks;
		}

		$out = [];
		$buffer = '';

		foreach ($chunks as $c) {
			if (mb_strlen($c) < $this->minLength) {
				$buffer .= ($buffer === '' ? '' : "\n\n") . $c;
				continue;
			}

			if ($buffer !== '') {
				$out[] = trim($buffer);
				$buffer = '';
			}

			$out[] = trim($c);
		}

		if ($buffer !== '') {
			$out[] = trim($buffer);
		}

		return $out;
	}

	/**
	 * @param array<int,string> $chunks
	 * @return array<int,string>
	 */
	private function applyOverlapRaw(array $chunks): array {
		$out = [];
		$count = count($chunks);

		for ($i = 0; $i < $count; $i++) {
			$current = $chunks[$i];

			if ($i > 0) {
				$prev = $chunks[$i - 1];
				$tail = mb_substr($prev, -$this->overlap);
				$current = trim($tail . "\n" . $current);
			}

			$out[] = $current;
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>
	 */
	private function makeChunk(string $text, array $meta): array {
		return [
			'id' => uniqid('chunk_', true),
			'text' => trim($text),
			'meta' => $meta
		];
	}
}
