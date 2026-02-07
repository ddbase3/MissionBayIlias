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
	 * Only kind is kept as inline marker for debugging / later traceability.
	 * Everything else is moved to payload metadata (filterable fields), not to text.
	 */
	protected bool $inlineKindMarker = true;

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
		return 'RAG chunker for ILIAS content units (wiki/wiki_page): clean chunks, title header in every chunk, payload-only metadata.';
	}

	public function getPriority(): int {
		return 40;
	}

	public function setConfig(array $config): void {
		parent::setConfig($config);

		$this->maxLength = $this->resolveInt($config, 'max_length', $this->maxLength);
		$this->minLength = $this->resolveInt($config, 'min_length', $this->minLength);
		$this->overlap = $this->resolveInt($config, 'overlap', $this->overlap);

		$value = $this->resolver->resolveValue($config['inline_kind_marker'] ?? $this->inlineKindMarker);
		$this->inlineKindMarker = (bool)$value;

		$this->resolvedOptions = [
			'max_length' => $this->maxLength,
			'min_length' => $this->minLength,
			'overlap' => $this->overlap,
			'inline_kind_marker' => $this->inlineKindMarker
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
		$meta = $this->mergeFilterMetaFromPayload($data, $meta);

		$title = $this->pickTitle($root, $data, $meta);
		$kind = $this->pickKind($root, $data, $meta);

		$header = $this->buildHeader($title, $kind);
		$body = $this->buildBodyText($data);

		// Edge: if there is no body at all, still emit a single chunk with title.
		if (trim($body) === '') {
			return [$this->makeChunk(trim($header), $meta)];
		}

		// Split body only, but reserve space for header in every chunk.
		$bodyChunks = $this->chunkTextMaxFit($body, $this->calcBodyMaxLength($header));

		if ($this->overlap > 0 && count($bodyChunks) > 1) {
			$bodyChunks = $this->applyOverlapRaw($bodyChunks);
		}

		$out = [];
		foreach ($bodyChunks as $rawBody) {
			$text = $this->composeChunkText($header, $rawBody);
			$out[] = $this->makeChunk($text, $meta);
		}

		return $out;
	}

	/**
	 * Prefer content.title, fallback to extractor metadata.title, then kind+locator.
	 *
	 * @param array<string,mixed> $root
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $meta
	 */
	protected function pickTitle(array $root, array $data, array $meta): string {
		$title = $data['title'] ?? $meta['title'] ?? $root['title'] ?? null;
		if (is_string($title) && trim($title) !== '') {
			return trim($title);
		}

		$kind = $root['kind'] ?? $data['kind'] ?? $meta['source_kind'] ?? null;
		$locator = $root['locator'] ?? $data['locator'] ?? $meta['source_locator'] ?? null;

		if (is_string($kind) && $kind !== '' && is_string($locator) && $locator !== '') {
			return strtoupper($kind) . ' ' . $locator;
		}

		if (is_string($kind) && $kind !== '') {
			return strtoupper($kind);
		}

		return 'ILIAS Content';
	}

	/**
	 * @param array<string,mixed> $root
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $meta
	 */
	protected function pickKind(array $root, array $data, array $meta): string {
		$kind = $root['kind'] ?? $data['kind'] ?? $meta['source_kind'] ?? null;
		if (is_string($kind) && trim($kind) !== '') {
			return trim($kind);
		}
		return '';
	}

	protected function buildHeader(string $title, string $kind): string {
		$parts = [];

		if ($this->inlineKindMarker && $kind !== '') {
			// Minimal inline marker; IDs/locator/title are intentionally excluded.
			$k = str_replace('"', "'", $kind);
			$parts[] = "<!-- meta: kind=\"{$k}\" -->";
		}

		$parts[] = '# ' . $title;

		// Keep one blank line after header.
		return trim(implode("\n", $parts)) . "\n\n";
	}

	/**
	 * Build only semantically relevant body text for embeddings.
	 * Everything "metadata-like" stays in payload metadata, not in text.
	 *
	 * @param array<string,mixed> $data
	 */
	protected function buildBodyText(array $data): string {
		$parts = [];

		// Primary: most ILIAS providers use "content" for the main text.
		$main = $data['content'] ?? null;
		if (is_string($main)) {
			$main = trim($this->normalizeNewlines($main));
			if ($main !== '') {
				$parts[] = $main;
			}
		}

		// Optional: if provider uses other long text fields, include them if they are "big enough".
		// This avoids accidental inclusion of short metadata-like strings.
		$skipKeys = [
			'title', 'type', 'kind', 'locator', 'source_locator',
			'page_id', 'wiki_obj_id', 'container_obj_id', 'source_int_id',
			'meta', 'render_md5', 'created', 'last_change', 'lang', 'page_lang'
		];

		$minTextLen = max(120, (int)floor($this->minLength / 2));

		foreach ($data as $key => $value) {
			$k = (string)$key;

			if (in_array($k, $skipKeys, true)) {
				continue;
			}

			if (!is_string($value)) {
				continue;
			}

			$val = trim($this->normalizeNewlines($value));
			if ($val === '') {
				continue;
			}

			if (mb_strlen($val) < $minTextLen) {
				continue;
			}

			// Keep section marker (helps retrieval) but do not add noisy metadata blocks.
			$parts[] = "## " . ucfirst($k) . "\n\n" . $val;
		}

		return trim(implode("\n\n", $parts));
	}

	/**
	 * Merge filter/invalidator metadata from content payload into chunk meta.
	 *
	 * We support either:
	 * - top-level keys on $data (created/last_change/lang/page_lang/render_md5), or
	 * - nested array/object at $data['meta'] with those keys
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>
	 */
	protected function mergeFilterMetaFromPayload(array $data, array $meta): array {
		$fields = ['created', 'last_change', 'lang', 'page_lang', 'render_md5'];

		$nested = $data['meta'] ?? null;
		$nestedArr = (is_array($nested) || is_object($nested)) ? (array)$nested : [];

		foreach ($fields as $f) {
			$v = $data[$f] ?? $nestedArr[$f] ?? null;

			if (!is_string($v)) {
				continue;
			}

			$v = trim($v);
			if ($v === '') {
				continue;
			}

			$meta[$f] = $v;
		}

		return $meta;
	}

	protected function composeChunkText(string $header, string $body): string {
		$body = trim($body);
		if ($body === '') {
			return trim($header);
		}
		return trim($header . $body);
	}

	protected function calcBodyMaxLength(string $header): int {
		$headerLen = mb_strlen($header);

		// Ensure we always have reasonable space for body.
		$bodyMax = $this->maxLength - $headerLen;
		if ($bodyMax < 200) {
			$bodyMax = 200;
		}

		return $bodyMax;
	}

	private function resolveInt(array $config, string $key, int $default): int {
		$value = $this->resolver->resolveValue($config[$key] ?? $default);
		return (int)$value;
	}

	private function normalizeNewlines(string $text): string {
		return preg_replace('/\R/u', "\n", $text);
	}

	/**
	 * Max-fit chunking for BODY ONLY:
	 * - split by paragraphs
	 * - keep headings sticky with following paragraph
	 * - fallback: split by lines, then hard split
	 *
	 * @return array<int,string>
	 */
	private function chunkTextMaxFit(string $text, int $maxLen): array {
		$text = $this->normalizeNewlines($text);

		if (mb_strlen($text) <= $maxLen) {
			return [trim($text)];
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

			if (mb_strlen($candidate) <= $maxLen) {
				$current = $candidate;
				continue;
			}

			if ($current !== '') {
				$out[] = trim($current);
				$current = '';
			}

			if (mb_strlen($p) <= $maxLen) {
				$current = $p;
				continue;
			}

			foreach ($this->splitByLinesMaxFit($p, $maxLen) as $part) {
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
	private function splitByLinesMaxFit(string $text, int $maxLen): array {
		$text = $this->normalizeNewlines($text);
		$lines = explode("\n", $text);

		$out = [];
		$current = '';

		foreach ($lines as $line) {
			$line = rtrim($line);

			$candidate = ($current === '' ? $line : $current . "\n" . $line);

			if (mb_strlen($candidate) <= $maxLen) {
				$current = $candidate;
				continue;
			}

			if ($current !== '') {
				$out[] = trim($current);
				$current = '';
			}

			if (mb_strlen($line) <= $maxLen) {
				$current = $line;
				continue;
			}

			foreach ($this->hardSplit($line, $maxLen) as $part) {
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
