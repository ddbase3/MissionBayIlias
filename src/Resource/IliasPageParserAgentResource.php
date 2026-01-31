<?php declare(strict_types=1);

namespace MissionBayIlias\Resource;

use MissionBay\Api\IAgentContentParser;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;
use MissionBay\Resource\AbstractAgentResource;

/**
 * IliasPageParserAgentResource
 *
 * Parses ILIAS PageObject-based payloads (wiki pages, blog postings, glossary terms)
 * and converts rendered HTML/XML into plain text while preserving paragraphs.
 *
 * Supported payload types:
 * - wiki_page
 * - blog_posting
 * - glo_term
 * - lm_page
 * - cat
 *
 * Critical contract:
 * - Keep AgentParsedContent->structured in the extractor "root shape"
 *   so IliasChunkerAgentResource::supports() stays true.
 */
final class IliasPageParserAgentResource extends AbstractAgentResource implements IAgentContentParser {

        private const CONTENT_TYPE = 'application/x-ilias-content-json';

        /** PageObject-based payload types */
        private const SUPPORTED_TYPES = [
                'wiki_page',
                'blog_posting',
		'glo_term',
		'lm_page',
                'cat'
        ];

        public static function getName(): string {
                return 'iliaspageparseragentresource';
        }

        public function getDescription(): string {
		return 'Parser for ILIAS PageObject content (wiki pages, blog postings, glossary terms, learning module pages, categories): '
			. 'converts rendered HTML/XML to text and keeps extractor root shape for chunking.';
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

                $payload = $root['content'] ?? null;
                if (!is_array($payload) && !is_object($payload)) {
                        return false;
                }

                $payloadArr = (array)$payload;
                $type = strtolower(trim((string)($payloadArr['type'] ?? '')));

                return in_array($type, self::SUPPORTED_TYPES, true);
        }

        public function parse(mixed $item): AgentParsedContent {
                if (!$item instanceof AgentContentItem) {
                        throw new \InvalidArgumentException('IliasPageParser expects AgentContentItem.');
                }

                $root = $this->requireRoot($item);
                $payload = (array)$root['content'];

                $type = strtolower(trim((string)($payload['type'] ?? '')));

                $title = trim((string)($payload['title'] ?? ''));
                $raw = (string)($payload['content'] ?? '');

                $textBody = $this->convertMarkupToText($raw);
                $textBody = $this->normalizeText($textBody);

                // Put cleaned text back into payload so the chunker sees clean paragraphs
                $payload['content'] = $textBody;

                // Ensure title is present consistently
                if ($title === '' && isset($root['title']) && is_string($root['title'])) {
                        $title = trim((string)$root['title']);
                }
                if ($title !== '') {
                        $payload['title'] = $title;
                }

                // Keep extractor root shape for chunker supports()
                $root['content'] = $payload;

                // Optional combined text (debug / fallback chunkers)
                $text = $title !== '' ? ($title . "\n\n" . $textBody) : $textBody;

                $meta = is_array($item->metadata) ? $item->metadata : [];
                $meta['type'] = $type;

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
                        throw new \RuntimeException('ILIAS page parser: item.content is not an array.');
                }

                $root = $item->content;

                $payload = $root['content'] ?? null;
                if (!is_array($payload) && !is_object($payload)) {
                        throw new \RuntimeException('ILIAS page parser: missing root.content payload.');
                }

                $p = (array)$payload;
                $type = strtolower(trim((string)($p['type'] ?? '')));

                if (!in_array($type, self::SUPPORTED_TYPES, true)) {
                        throw new \RuntimeException("ILIAS page parser: unsupported payload type '{$type}'.");
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
