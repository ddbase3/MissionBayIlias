<?php declare(strict_types=1);

namespace MissionBayIlias\Resource;

use MissionBay\Api\IAgentContentParser;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;
use MissionBay\Resource\AbstractAgentResource;

/**
 * IliasWikiPageParserAgentResource
 *
 * Parses ILIAS wiki page payloads and converts rendered HTML/XML into plain text
 * while preserving paragraphs.
 *
 * Critical contract:
 * - Keep AgentParsedContent->structured in the extractor "root shape"
 *   so IliasChunkerAgentResource::supports() stays true.
 */
final class IliasWikiPageParserAgentResource extends AbstractAgentResource implements IAgentContentParser {

	private const CONTENT_TYPE = 'application/x-ilias-content-json';

	public static function getName(): string {
		return 'iliaswikipageparseragentresource';
	}

	public function getDescription(): string {
		return 'Parser for ILIAS wiki pages: converts rendered HTML/XML to text and keeps the extractor root shape for chunking.';
	}

	public function getPriority(): int {
		// Must win against StructuredObjectParserAgentResource (priority 100)
		return 50;
	}

	public function supports(mixed $item): bool {
		if (!$item instanceof AgentContentItem) {
			return false;
		}

		if ((string)($item->contentType ?? '') !== self::CONTENT_TYPE) {
			return false;
		}

		if (!is_array($item->content)) {
			return false;
		}

		$root = $item->content;

		$kind = strtolower(trim((string)($root['kind'] ?? '')));
		if ($kind !== 'wiki_page') {
			return false;
		}

		$payload = $root['content'] ?? null;
		if (!is_array($payload) && !is_object($payload)) {
			return false;
		}

		$payloadArr = (array)$payload;
		return strtolower(trim((string)($payloadArr['type'] ?? ''))) === 'wiki_page';
	}

	public function parse(mixed $item): AgentParsedContent {
		if (!$item instanceof AgentContentItem) {
			throw new \InvalidArgumentException('IliasWikiPageParser expects AgentContentItem.');
		}

		$root = $this->requireRoot($item);
		$payload = (array)$root['content'];

		$title = trim((string)($payload['title'] ?? ''));
		$raw = (string)($payload['content'] ?? '');

		$textBody = $this->convertMarkupToText($raw);
		$textBody = $this->normalizeText($textBody);

		// Put cleaned text back into the payload so the chunker sees clean paragraphs.
		$payload['content'] = $textBody;

		// Ensure title is present consistently.
		if ($title === '' && isset($root['title']) && is_string($root['title'])) {
			$title = trim((string)$root['title']);
		}
		if ($title !== '') {
			$payload['title'] = $title;
		}

		// Keep extractor root shape for chunker supports():
		// root.system/kind/locator/... + root.content(payload array)
		$root['content'] = $payload;

		// ParsedContent text is optional for your chunker, but nice for debugging and fallback chunkers.
		$text = $title !== '' ? ($title . "\n\n" . $textBody) : $textBody;

		$meta = is_array($item->metadata) ? $item->metadata : [];
		$meta['type'] = 'wiki_page';

		return new AgentParsedContent(
			text: trim($text),
			metadata: $meta,
			structured: $root,
			attachments: []
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function requireRoot(AgentContentItem $item): array {
		if (!is_array($item->content)) {
			throw new \RuntimeException('ILIAS wiki parser: item.content is not an array.');
		}

		$root = $item->content;

		$payload = $root['content'] ?? null;
		if (!is_array($payload) && !is_object($payload)) {
			throw new \RuntimeException('ILIAS wiki parser: missing root.content payload.');
		}

		$kind = strtolower(trim((string)($root['kind'] ?? '')));
		if ($kind !== 'wiki_page') {
			throw new \RuntimeException("ILIAS wiki parser: unsupported root.kind '{$kind}'.");
		}

		$p = (array)$payload;
		$type = strtolower(trim((string)($p['type'] ?? '')));
		if ($type !== 'wiki_page') {
			throw new \RuntimeException("ILIAS wiki parser: unsupported payload type '{$type}'.");
		}

		return $root;
	}

	private function convertMarkupToText(string $raw): string {
		$raw = trim($raw);
		if ($raw === '') {
			return '';
		}

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

		// Remove ILIAS break markers and noisy templating tokens.
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
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return $text;
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

		// Table cell separation
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
		$raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return $raw;
	}

	private function normalizeText(string $text): string {
		$text = str_replace("\r\n", "\n", $text);
		$text = str_replace("\r", "\n", $text);

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
