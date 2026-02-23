<?php declare(strict_types=1);

namespace MissionBayIlias\Resource;

use MissionBay\Api\IAgentContentParser;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;
use MissionBay\Resource\AbstractAgentResource;

/**
 * IliasSahsAssetMarkupParserAgentResource
 *
 * Converts inline SAHS asset markup (HTML/HTM/XHTML/XML) to plain text.
 * Handles XML files that contain embedded HTML as text (e.g. escaped or CDATA fragments).
 *
 * Contract:
 * - Keep extractor root shape intact:
 *   root['content'] is the provider payload array (type=sahs_asset)
 * - Update payload['content'] to cleaned plaintext so IliasChunkerAgentResource chunks clean text.
 */
final class IliasSahsAssetMarkupParserAgentResource extends AbstractAgentResource implements IAgentContentParser {

	private const CONTENT_TYPE = 'application/x-ilias-content-json';

	public static function getName(): string {
		return 'iliassahsassetmarkupparseragentresource';
	}

	public function getDescription(): string {
		return 'Parser for SAHS assets (sahs_asset): converts inline HTML/XML to plain text and updates payload.content for chunking.';
	}

	public function getPriority(): int {
		// Must run before StructuredObjectParser (100)
		return 45;
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
		if ($type !== 'sahs_asset') {
			return false;
		}

		$raw = $payload['content'] ?? null;
		return is_string($raw) && trim($raw) !== '';
	}

	public function parse(AgentContentItem $item): AgentParsedContent {
		$root = $this->requireRoot($item);
		$payload = (array)$root['content'];

		$title = trim((string)($payload['title'] ?? ''));
		$raw = (string)($payload['content'] ?? '');

		$meta = $payload['meta'] ?? null;
		$metaArr = (is_array($meta) || is_object($meta)) ? (array)$meta : [];

		$ext = strtolower(trim((string)($metaArr['ext'] ?? '')));

		$textBody = $this->convertToTextByHint($raw, $ext);
		$textBody = $this->cleanupResidualMarkup($textBody);
		$textBody = $this->normalizeText($textBody);

		$payload['content'] = $textBody;
		$payload['meta'] = $metaArr;

		$root['content'] = $payload;

		$text = $title !== '' ? ($title . "\n\n" . $textBody) : $textBody;

		$metaOut = is_array($item->metadata) ? $item->metadata : [];
		$metaOut['type'] = 'sahs_asset';
		$metaOut['parser'] = 'sahs_asset_markup';
		if ($ext !== '') {
			$metaOut['ext'] = $ext;
		}

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
			throw new \RuntimeException('SAHS asset parser: item.content is not an array.');
		}

		$root = $item->content;

		$payload = $root['content'] ?? null;
		if (!is_array($payload) && !is_object($payload)) {
			throw new \RuntimeException('SAHS asset parser: missing root.content payload.');
		}

		$p = (array)$payload;
		$type = strtolower(trim((string)($p['type'] ?? '')));
		if ($type !== 'sahs_asset') {
			throw new \RuntimeException("SAHS asset parser: unsupported payload type '{$type}'.");
		}

		return $root;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function extractPayload(AgentContentItem $item): ?array {
		if (!is_array($item->content)) {
			return null;
		}

		$payload = $item->content['content'] ?? null;

		if (is_array($payload)) {
			return $payload;
		}
		if (is_object($payload)) {
			return (array)$payload;
		}

		return null;
	}

	private function convertToTextByHint(string $raw, string $ext): string {
		$raw = trim($raw);
		if ($raw === '') {
			return '';
		}

		if ($ext === 'xml') {
			return $this->xmlToText($raw);
		}
		if ($ext === 'html' || $ext === 'htm' || $ext === 'xhtml') {
			return $this->htmlToText($raw);
		}

		if ($this->looksLikeXml($raw)) {
			return $this->xmlToText($raw);
		}
		if ($this->looksLikeHtml($raw)) {
			return $this->htmlToText($raw);
		}

		return html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	private function cleanupResidualMarkup(string $text): string {
		$text = trim($text);
		if ($text === '') {
			return '';
		}

		// Decode entities so "&lt;p&gt;" becomes "<p>"
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		// If we still see tags, run through HTML-to-text once more.
		if ($this->looksLikeHtml($text)) {
			$text = $this->htmlToText($text);
		}

		// Hard fallback: if anything still looks like tags, strip them.
		if (str_contains($text, '<') && str_contains($text, '>')) {
			$text = strip_tags($text);
			$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}

		return $text;
	}

	private function looksLikeXml(string $raw): bool {
		$head = ltrim(substr($raw, 0, 120));
		if (str_starts_with($head, '<?xml')) {
			return true;
		}
		return (bool)preg_match('/^\s*<([A-Za-z_][A-Za-z0-9_\-:.]*)\b[^>]*>/', $head);
	}

	private function looksLikeHtml(string $raw): bool {
		$head = strtolower(ltrim(substr($raw, 0, 400)));
		if (str_contains($head, '<html') || str_contains($head, '<body') || str_contains($head, '<head')) {
			return true;
		}
		return (bool)preg_match('/<\s*(p|div|br|h1|h2|h3|li|ul|ol|table|tr|td|script|style|font)\b/i', $raw);
	}

	private function xmlToText(string $xml): string {
		$xml = trim($xml);
		if ($xml === '') {
			return '';
		}

		$doc = new \DOMDocument('1.0', 'UTF-8');
		$prev = libxml_use_internal_errors(true);

		$loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		if (!$loaded) {
			$text = strip_tags($xml);
			return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}

		$text = $doc->textContent ?? '';
		return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	private function htmlToText(string $html): string {
		$html = trim($html);
		if ($html === '') {
			return '';
		}

		$doc = new \DOMDocument('1.0', 'UTF-8');
		$prev = libxml_use_internal_errors(true);

		$loaded = $doc->loadHTML(
			'<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);

		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		if (!$loaded) {
			$text = strip_tags($html);
			return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}

		$this->removeByTagName($doc, 'script');
		$this->removeByTagName($doc, 'style');
		$this->removeByTagName($doc, 'noscript');

		$body = $doc->getElementsByTagName('body')->item(0);
		if (!$body) {
			$text = strip_tags($html);
			return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}

		$this->injectNewlinesForBlocks($doc, $body);

		$text = $body->textContent ?? '';
		return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	private function removeByTagName(\DOMDocument $doc, string $tag): void {
		$list = $doc->getElementsByTagName($tag);
		if ($list->length <= 0) {
			return;
		}

		$nodes = [];
		foreach ($list as $n) {
			$nodes[] = $n;
		}
		foreach ($nodes as $n) {
			$n->parentNode?->removeChild($n);
		}
	}

	private function injectNewlinesForBlocks(\DOMDocument $doc, \DOMNode $node): void {
		$block = ['p','div','h1','h2','h3','h4','h5','h6','br','li','ul','ol','table','tr','blockquote','hr'];

		$this->walk($node, function(\DOMNode $n) use ($doc, $block) {
			if ($n->nodeType !== XML_ELEMENT_NODE) {
				return;
			}

			$name = strtolower((string)$n->nodeName);
			if (!in_array($name, $block, true)) {
				return;
			}

			$n->parentNode?->insertBefore($doc->createTextNode("\n"), $n);
			$n->parentNode?->insertBefore($doc->createTextNode("\n"), $n->nextSibling);
		});

		$this->walk($node, function(\DOMNode $n) use ($doc) {
			if ($n->nodeType !== XML_ELEMENT_NODE) {
				return;
			}

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
		if (!$root->hasChildNodes()) {
			return;
		}

		$children = [];
		foreach ($root->childNodes as $c) {
			$children[] = $c;
		}

		foreach ($children as $c) {
			$this->walk($c, $fn);
		}
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
