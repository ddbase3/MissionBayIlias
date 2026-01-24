<?php declare(strict_types=1);

namespace MissionBayIlias\Resource;

use MissionBay\Api\IAgentContentParser;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;
use MissionBay\Resource\AbstractAgentResource;

/**
 * IliasCourseParserAgentResource
 *
 * Dedicated parser for ILIAS course payloads (type=crs).
 *
 * Purpose:
 * - Remove HTML/markup from course text fields that still contain rich text,
 *   especially crs_settings fields like "syllabus" and "important".
 * - Keep the extractor "root shape" intact so downstream chunkers can rely on it.
 *
 * Supported payload types:
 * - crs
 *
 * Critical contract:
 * - Keep AgentParsedContent->structured in the extractor "root shape"
 *   (root['content']['content'] = payload array) so IliasChunkerAgentResource::supports() stays true.
 */
final class IliasCourseParserAgentResource extends AbstractAgentResource implements IAgentContentParser {

	private const CONTENT_TYPE = 'application/x-ilias-content-json';

	private const SUPPORTED_TYPES = [
		'crs',
	];

	public static function getName(): string {
		return 'iliascourseparseragentresource';
	}

	public function getDescription(): string {
		return 'Parser for ILIAS course payloads (crs): strips HTML from syllabus/important (and related text fields) and keeps extractor root shape for chunking.';
	}

	public function getPriority(): int {
		// Must win against generic parsers (e.g. StructuredObjectParserAgentResource priority 100)
		return 40;
	}

	public function supports(AgentContentItem $item): bool {
		if ((string)($item->contentType ?? '') !== self::CONTENT_TYPE) {
			return false;
		}

		if (!is_array($item->content)) {
			return false;
		}

		$root = $item->content;
		$payload = $root['content'] ?? null;

		if (!is_array($payload) && !is_object($payload)) {
			return false;
		}

		$payloadArr = (array)$payload;

		// Extractor builds: root['content']['content'] = provider payload
		$inner = $payloadArr['content'] ?? null;
		if (!is_array($inner) && !is_object($inner)) {
			return false;
		}

		$innerArr = (array)$inner;
		$type = strtolower(trim((string)($innerArr['type'] ?? '')));

		return in_array($type, self::SUPPORTED_TYPES, true);
	}

	public function parse(AgentContentItem $item): AgentParsedContent {
		$root = $this->requireRoot($item);

		$container = (array)$root['content']; // system/kind/locator/... + content(payload)
		$payload = (array)$container['content']; // provider payload array

		$type = strtolower(trim((string)($payload['type'] ?? '')));
		if ($type !== 'crs') {
			throw new \RuntimeException("ILIAS course parser: unsupported payload type '{$type}'.");
		}

		$title = trim((string)($payload['title'] ?? ''));

		// Clean main content (provider sets: content = important|syllabus, may contain HTML)
		$rawContent = (string)($payload['content'] ?? '');
		$cleanContent = $this->convertMarkupToText($rawContent);
		$cleanContent = $this->normalizeText($cleanContent);
		$payload['content'] = $cleanContent;

		// Clean description if it contains HTML (object_data.description can be rich text)
		$rawDesc = (string)($payload['description'] ?? '');
		if ($this->looksLikeMarkup($rawDesc)) {
			$cleanDesc = $this->normalizeText($this->convertMarkupToText($rawDesc));
			$payload['description'] = $cleanDesc;
		}

		// Clean meta fields (syllabus/important are the key offenders)
		$meta = $payload['meta'] ?? null;
		if (is_array($meta) || is_object($meta)) {
			$m = (array)$meta;

			$m = $this->cleanMetaTextField($m, 'syllabus');
			$m = $this->cleanMetaTextField($m, 'important');

			// Optional: contact fields are typically plain, keep as-is.
			$payload['meta'] = $m;
		}

		// Ensure title is present consistently (fallback to root title if present)
		if ($title === '' && isset($root['title']) && is_string($root['title'])) {
			$title = trim((string)$root['title']);
		}
		if ($title !== '') {
			$payload['title'] = $title;
		}

		// Keep extractor root shape for chunker supports()
		$container['content'] = $payload;
		$root['content'] = $container;

		// Provide combined text for debugging/fallback chunkers
		$text = $title !== '' ? ($title . "\n\n" . $cleanContent) : $cleanContent;

		$metaOut = is_array($item->metadata) ? $item->metadata : [];
		$metaOut['type'] = 'crs';

		return new AgentParsedContent(
			text: trim($text),
			metadata: $metaOut,
			structured: $root,
			attachments: []
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function requireRoot(AgentContentItem $item): array {
		if (!is_array($item->content)) {
			throw new \RuntimeException('ILIAS course parser: item.content is not an array.');
		}

		$root = $item->content;

		$container = $root['content'] ?? null;
		if (!is_array($container) && !is_object($container)) {
			throw new \RuntimeException('ILIAS course parser: missing root.content container.');
		}

		$c = (array)$container;
		$payload = $c['content'] ?? null;
		if (!is_array($payload) && !is_object($payload)) {
			throw new \RuntimeException('ILIAS course parser: missing root.content.content payload.');
		}

		$p = (array)$payload;
		$type = strtolower(trim((string)($p['type'] ?? '')));

		if (!in_array($type, self::SUPPORTED_TYPES, true)) {
			throw new \RuntimeException("ILIAS course parser: unsupported payload type '{$type}'.");
		}

		return $root;
	}

	/**
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>
	 */
	private function cleanMetaTextField(array $meta, string $key): array {
		if (!array_key_exists($key, $meta)) {
			return $meta;
		}

		$value = $meta[$key] ?? null;
		if (!is_string($value)) {
			return $meta;
		}

		$raw = trim($value);
		if ($raw === '') {
			$meta[$key] = null;
			return $meta;
		}

		if (!$this->looksLikeMarkup($raw)) {
			$meta[$key] = $this->normalizeText($raw);
			return $meta;
		}

		$clean = $this->normalizeText($this->convertMarkupToText($raw));
		$meta[$key] = $clean !== '' ? $clean : null;

		return $meta;
	}

	private function looksLikeMarkup(string $raw): bool {
		$raw = trim($raw);
		if ($raw === '') {
			return false;
		}

		if (str_contains($raw, '<') && str_contains($raw, '>')) {
			return true;
		}

		// Common entity patterns; treat as markup-ish so we decode
		return (bool)preg_match('/&[a-zA-Z]+;|&#\d+;|&#x[0-9a-fA-F]+;/', $raw);
	}

	private function convertMarkupToText(string $raw): string {
		$raw = trim($raw);
		if ($raw === '') {
			return '';
		}

		// Courses shouldn't be PageObject XML, but keep the same robust behavior.
		if ($this->looksLikeXmlPageObject($raw)) {
			return $this->xmlPageObjectToText($raw);
		}

		return $this->htmlToText($raw);
	}

	private function looksLikeXmlPageObject(string $raw): bool {
		$head = ltrim(substr($raw, 0, 80));
		return str_starts_with($head, '<PageObject') || str_starts_with($head, '<?xml');
	}

	private function xmlPageObjectToText(string $xml): string {
		$xml = trim($xml);
		if ($xml === '') {
			return '';
		}

		$xml = str_replace('\\"', '"', $xml);

		$doc = new \DOMDocument('1.0', 'UTF-8');
		$prev = libxml_use_internal_errors(true);

		$loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		if (!$loaded) {
			return $this->fallbackStripTagsWithParagraphs($xml);
		}

		$paras = $doc->getElementsByTagName('Paragraph');
		$out = [];

		foreach ($paras as $p) {
			$t = trim($p->textContent ?? '');
			$t = $this->normalizeInlineWhitespace($t);
			if ($t !== '') {
				$out[] = $t;
			}
		}

		return implode("\n\n", $out);
	}

	private function htmlToText(string $html): string {
		$html = trim($html);
		if ($html === '') {
			return '';
		}

		$html = str_replace('<!--Break-->', '', $html);
		$html = preg_replace('/\{\{\{\{\{.*?\}\}\}\}\}/s', '', $html) ?? $html;

		$doc = new \DOMDocument('1.0', 'UTF-8');
		$prev = libxml_use_internal_errors(true);

		$loaded = $doc->loadHTML(
			'<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);

		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		if (!$loaded) {
			return $this->fallbackStripTagsWithParagraphs($html);
		}

		$body = $doc->getElementsByTagName('body')->item(0);
		if (!$body) {
			return $this->fallbackStripTagsWithParagraphs($html);
		}

		$this->injectNewlinesForBlocks($doc, $body);

		$text = $body->textContent ?? '';
		return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	private function injectNewlinesForBlocks(\DOMDocument $doc, \DOMNode $node): void {
		$block = ['p','div','h1','h2','h3','h4','h5','h6','br','li','ul','ol','table','tr','blockquote','hr'];

		$this->walk($node, function(\DOMNode $n) use ($doc, $block) {
			if ($n->nodeType !== XML_ELEMENT_NODE) return;

			$name = strtolower((string)$n->nodeName);
			if (!in_array($name, $block, true)) return;

			$n->parentNode?->insertBefore($doc->createTextNode("\n"), $n);
			$n->parentNode?->insertBefore($doc->createTextNode("\n"), $n->nextSibling);
		});

		$this->walk($node, function(\DOMNode $n) use ($doc) {
			if ($n->nodeType !== XML_ELEMENT_NODE) return;

			$name = strtolower((string)$n->nodeName);
			if ($name === 'td' || $name === 'th') {
				$n->parentNode?->insertBefore($doc->createTextNode("\t"), $n->nextSibling);
			}
			if ($name === 'tr') {
				$n->parentNode?->insertBefore($doc->createTextNode("\n"), $n->nextSibling);
			}
		});
	}

	private function walk(\DOMNode $root, \Closure $fn): void {
		$fn($root);
		if (!$root->hasChildNodes()) return;

		$children = [];
		foreach ($root->childNodes as $c) {
			$children[] = $c;
		}

		foreach ($children as $c) {
			$this->walk($c, $fn);
		}
	}

	private function fallbackStripTagsWithParagraphs(string $raw): string {
		$raw = preg_replace('/<\s*br\s*\/?>/i', "\n", $raw) ?? $raw;
		$raw = preg_replace('/<\s*\/p\s*>/i', "\n\n", $raw) ?? $raw;
		$raw = preg_replace('/<\s*\/div\s*>/i', "\n\n", $raw) ?? $raw;
		$raw = strip_tags($raw);
		return html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
