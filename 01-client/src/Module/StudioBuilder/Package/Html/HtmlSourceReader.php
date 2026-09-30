<?php
/**
 * Kohevo Studio (studio-builder) — Phase 8B: raw source guards, parser and
 * the bounded, security-filtering traversal.
 *
 * Order (each stage runs only if the previous one passed):
 *
 *   1. raw guards (before any parser work — the first DoS boundary; libxml
 *      allocates outside PHP's memory_limit): HTML ≤ 512 KiB, CSS ≤ 128 KiB,
 *      ≤ 12,000 `<`, valid UTF-8, no NUL / C0 controls except \t \n \r, no
 *      DOCTYPE internal subset.
 *   2. charset-safe normalization: every non-ASCII character becomes a
 *      numeric entity, so libxml's HTML4 parser (which assumes Latin-1 when
 *      no charset is declared) preserves Unicode exactly. No XML encoding PI.
 *   3. `DOMDocument::loadHTML($html, LIBXML_NONET)` with internal errors —
 *      never LIBXML_NOENT, LIBXML_PARSEHUGE or LIBXML_HTML_NOIMPLIED. libxml's
 *      "Excessive depth" (it silently truncates past 256 levels) is fatal.
 *   4. one explicit-stack walk (no recursion): ≤ 5,000 elements, ≤ 10,000
 *      nodes, depth ≤ 64. Security-sensitive elements are dropped WITH their
 *      whole subtree (never descended into) and reported; `<style>` text is
 *      handed to the CSS budget first. Unsupported elements (nav, tables, hr,
 *      details, dialog, widgets) are dropped with a warning. Only the
 *      allowlisted attributes are read, as evidence; nothing is persisted.
 *
 * Two libxml HTML4-parser differentials are corrected (probed on libxml 2.9):
 *   - HTML5 void elements it does not know (`source`, `track`, `embed`,
 *     `param`, `keygen`, `wbr`) are parsed as CONTAINERS that swallow their
 *     following siblings. The element itself is dropped/reported, and those
 *     swallowed siblings are walked as siblings — through the same filters.
 *   - unknown (HTML5) elements after `<title>` stay inside `<head>`; any
 *     non-head element or text found there is treated as body content.
 *
 * The result is a flat, data-only tree: `['tag' => …, 'children' => [ids]]`
 * elements and `['tag' => '#text', 'text' => …]` text nodes.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Package\Html;

final class HtmlSourceReader
{
    public const MAX_HTML_BYTES   = 524288;
    public const MAX_CSS_BYTES    = 131072;
    public const MAX_LT           = 12000;
    public const MAX_ELEMENTS     = 5000;
    public const MAX_NODES        = 10000;
    public const MAX_DEPTH        = 64;
    public const MAX_ATTRIBUTES   = 32;
    public const MAX_ATTR_BYTES   = 2048;
    public const MAX_CLASSES      = 32;
    public const MAX_DISTINCT_CLASSES = 1000;
    public const MAX_TEXT_CHARS   = 400000;

    /** Dropped with their whole subtree and reported as `security_stripped`. */
    public const STRIP = [
        'script', 'noscript', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'form', 'input', 'textarea', 'select', 'option', 'optgroup', 'button', 'label', 'fieldset', 'legend', 'output', 'datalist', 'keygen',
        'canvas', 'video', 'audio', 'source', 'track', 'svg', 'math', 'template', 'portal', 'param',
    ];

    /** Dropped with their subtree and reported as `unsupported_element` (no canonical structure). */
    public const UNSUPPORTED = [
        'nav', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
        'hr', 'details', 'summary', 'dialog', 'map', 'area', 'meter', 'progress', 'marquee', 'menu', 'slot',
    ];

    /** HTML5 void elements libxml's HTML4 parser nests following siblings into. */
    public const MISPARSED_VOID = ['source', 'track', 'embed', 'param', 'keygen', 'wbr'];

    /** Elements that legitimately live in <head>; anything else there is body content. */
    public const HEAD_ELEMENTS = ['title', 'meta', 'link', 'base', 'style', 'script', 'noscript', 'template'];

    /** Never content; ignored silently wherever they appear. */
    public const IGNORED = ['head', 'meta', 'link', 'base', 'title'];

    /** The only attributes read — as mapping evidence, never persisted. */
    public const READ_ATTRIBUTES = ['href', 'target', 'rel', 'src', 'alt', 'class', 'id', 'role', 'style'];

    /** @var list<array<string, mixed>> */
    private array $nodes = [];

    /** @var list<string> */
    private array $styles = [];

    /** @var array<string, true> */
    private array $classSet = [];

    private ?string $title = null;
    private int $elements = 0;
    private int $nodeCount = 0;
    private int $textChars = 0;
    private int $stripped = 0;
    private int $unsupported = 0;
    private bool $classCapReported = false;

    public function __construct(private readonly HtmlImportIssues $issues) {}

    /**
     * Stage 1 — raw guards over the untouched source strings.
     */
    public static function guard(string $html, string $css, HtmlImportIssues $issues): bool
    {
        if (strlen($html) > self::MAX_HTML_BYTES) {
            $issues->error('source_too_large', 'html:', 'The HTML is larger than 512 KiB.');
        }
        if (strlen($css) > self::MAX_CSS_BYTES) {
            $issues->error('source_too_large', 'css:', 'The CSS is larger than 128 KiB.');
        }
        if ($issues->hasErrors()) {
            return false;
        }
        foreach (['html' => $html, 'css' => $css] as $what => $text) {
            if (!mb_check_encoding($text, 'UTF-8')) {
                $issues->error('invalid_source', $what . ':', "The {$what} is not valid UTF-8.");
            } elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text) === 1) {
                $issues->error('invalid_source', $what . ':', "The {$what} contains NUL or control characters.");
            }
        }
        if ($issues->hasErrors()) {
            return false;
        }
        if (trim($html) === '') {
            $issues->error('invalid_source', 'html:', 'The HTML is empty.');
            return false;
        }
        if (substr_count($html, '<') > self::MAX_LT) {
            $issues->error('source_limit_exceeded', 'html:', 'The HTML has more than ' . self::MAX_LT . ' tags.');
            return false;
        }
        if (preg_match('/<!DOCTYPE[^>\[]*\[/i', $html) === 1) {
            $issues->error('invalid_source', 'html:', 'A DOCTYPE with an internal subset (entity declarations) is not accepted.');
            return false;
        }
        return true;
    }

    /**
     * Stages 2–4. Null on a fatal error (already recorded).
     *
     * @return ?array{nodes: list<array<string, mixed>>, body: int, title: ?string, styles: list<string>, stats: array<string, int>}
     */
    public function read(string $html): ?array
    {
        $prepared = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $doc = new \DOMDocument();
            $ok = $doc->loadHTML($prepared, LIBXML_NONET);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        foreach ($errors as $e) {
            if (stripos((string) $e->message, 'Excessive depth') !== false) {
                $this->issues->error('source_limit_exceeded', 'html:', 'The HTML is nested more deeply than the parser allows.', 'depth');
                return null;
            }
        }
        if (!$ok || $doc->documentElement === null) {
            $this->issues->error('invalid_source', 'html:', 'The HTML could not be parsed.');
            return null;
        }

        $body = $this->walk($doc->documentElement);
        if ($body === null) {
            return null;
        }
        return [
            'nodes'  => $this->nodes,
            'body'   => $body,
            'title'  => $this->title,
            'styles' => $this->styles,
            'stats'  => [
                'elements_seen'         => $this->elements,
                'nodes_seen'            => $this->nodeCount,
                'stripped_security'     => $this->stripped,
                'unsupported_elements'  => $this->unsupported,
            ],
        ];
    }

    private function walk(\DOMNode $root): ?int
    {
        $body = null;
        // [DOM node, parent id (-1 = none), depth, location segments, inside <head>]
        $stack = [[$root, -1, 1, ['html'], false]];
        while ($stack !== []) {
            [$node, $parent, $depth, $segments, $inHead] = array_pop($stack);

            if (++$this->nodeCount > self::MAX_NODES) {
                $this->issues->error('source_limit_exceeded', 'html:', 'The HTML has more than ' . self::MAX_NODES . ' nodes.', 'nodes');
                return null;
            }
            if ($node instanceof \DOMText && !($node instanceof \DOMCdataSection)) {
                $text = (string) $node->nodeValue;
                if ($parent < 0 || $inHead) {
                    if (trim($text) === '') {
                        continue;
                    }
                    $parent = $body = $this->ensureBody($body);
                }
                $this->textChars += mb_strlen($text, 'UTF-8');
                if ($this->textChars > self::MAX_TEXT_CHARS) {
                    $this->issues->error('source_limit_exceeded', 'html:', 'The HTML has more than ' . self::MAX_TEXT_CHARS . ' characters of text.', 'text');
                    return null;
                }
                $this->nodes[] = ['tag' => '#text', 'text' => $text, 'parent' => $parent];
                $this->nodes[$parent]['children'][] = count($this->nodes) - 1;
                continue;
            }
            if (!$node instanceof \DOMElement) {
                continue; // comments, processing instructions, CDATA, doctype: never content
            }

            if (++$this->elements > self::MAX_ELEMENTS) {
                $this->issues->error('source_limit_exceeded', 'html:', 'The HTML has more than ' . self::MAX_ELEMENTS . ' elements.', 'elements');
                return null;
            }
            if ($depth > self::MAX_DEPTH) {
                $this->issues->error('source_limit_exceeded', 'html:', 'The HTML is nested more than ' . self::MAX_DEPTH . ' levels deep.', 'depth');
                return null;
            }

            $tag = strtolower($node->nodeName);
            $loc = self::location($node, $segments);

            if (in_array($tag, self::STRIP, true)) {
                if ($tag === 'style') {
                    // CSS evidence first (inside the CSS budget), then the element is discarded.
                    $this->styles[] = mb_decode_numericentity((string) $node->textContent, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
                }
                $this->stripped++;
                $this->issues->warning('security_stripped', $loc, "A <{$tag}> element was removed with everything inside it.", $tag);
                if (in_array($tag, self::MISPARSED_VOID, true)) {
                    // A void element has no content: what libxml nested into it are its following siblings.
                    $this->pushChildren($stack, $node, $parent, $depth - 1, array_slice($segments, 0, -1), $inHead);
                }
                continue;
            }
            if (in_array($tag, self::UNSUPPORTED, true)) {
                $this->unsupported++;
                $message = $tag === 'nav'
                    ? 'Navigation is site chrome, not page content; the <nav> element was not imported.'
                    : "Studio has no structure for <{$tag}>; it was not imported.";
                $this->issues->warning('unsupported_element', $loc, $message, $tag);
                continue;
            }
            if ($tag === 'head') {
                $this->pushChildren($stack, $node, -1, $depth, $segments, true);
                continue;
            }
            if (in_array($tag, self::IGNORED, true)) {
                if ($tag === 'title' && $inHead && $this->title === null) {
                    $this->title = trim((string) preg_replace('/\s+/', ' ', (string) $node->textContent));
                }
                continue;
            }
            if ($inHead || $parent < 0) {
                if ($tag !== 'html' && $tag !== 'body') {
                    // libxml left an HTML5 element in <head> (or at the root): it is body content.
                    $parent = $body = $this->ensureBody($body);
                    $inHead = false;
                } elseif ($inHead) {
                    continue;
                }
            }
            if ($tag === 'html') {
                $this->pushChildren($stack, $node, -1, $depth, $segments, false);
                continue;
            }
            if ($tag === 'body' && $body !== null) {
                // Content already started (see above): the <body> element's children join it.
                $this->pushChildren($stack, $node, $body, $depth, $segments, false);
                continue;
            }

            $el = ['tag' => $tag, 'loc' => $loc, 'line' => $node->getLineNo(), 'parent' => $parent, 'children' => []] + $this->attributes($node, $loc);
            $this->nodes[] = $el;
            $id = count($this->nodes) - 1;
            if ($parent >= 0) {
                $this->nodes[$parent]['children'][] = $id;
            }
            if ($tag === 'body' && $body === null) {
                $body = $id;
            }
            $this->pushChildren($stack, $node, $id, $depth, $segments, false);
        }
        if ($body === null) {
            $this->issues->error('invalid_source', 'html:', 'The HTML has no content.');
        }
        return $body;
    }

    private function ensureBody(?int $body): int
    {
        if ($body !== null) {
            return $body;
        }
        $this->nodes[] = ['tag' => 'body', 'loc' => 'html:L1 html>body', 'line' => 1, 'parent' => -1, 'children' => [], 'attrs' => [], 'classes' => [], 'id' => null];
        return count($this->nodes) - 1;
    }

    /**
     * @param list<array<int, mixed>> $stack
     * @param list<string> $segments
     */
    private function pushChildren(array &$stack, \DOMNode $node, int $parent, int $depth, array $segments, bool $inHead): void
    {
        $counts = [];
        $items = [];
        foreach ($node->childNodes as $child) {
            $seg = null;
            if ($child instanceof \DOMElement) {
                $name = strtolower($child->nodeName);
                $counts[$name] = ($counts[$name] ?? 0) + 1;
                $seg = $name . '[' . $counts[$name] . ']';
            }
            $items[] = [$child, $parent, $depth + 1, $seg === null ? $segments : [...array_slice($segments, -4), $seg], $inHead];
        }
        for ($i = count($items) - 1; $i >= 0; $i--) {
            $stack[] = $items[$i];
        }
    }

    /** @param list<string> $segments */
    private static function location(\DOMElement $node, array $segments): string
    {
        return 'html:L' . $node->getLineNo() . ' ' . implode('>', $segments);
    }

    /** @return array{attrs: array<string, string>, classes: list<string>, id: ?string} */
    private function attributes(\DOMElement $node, string $loc): array
    {
        $out = ['attrs' => [], 'classes' => [], 'id' => null];
        if (!$node->hasAttributes()) {
            return $out;
        }
        if ($node->attributes->length > self::MAX_ATTRIBUTES) {
            $this->issues->warning('source_limit_exceeded', $loc, 'An element has more than ' . self::MAX_ATTRIBUTES . ' attributes; its attributes were ignored.', 'attributes');
            return $out;
        }
        foreach ($node->attributes as $attr) {
            $name = strtolower($attr->nodeName);
            $value = (string) $attr->nodeValue;
            if (str_starts_with($name, 'on') || in_array($name, ['srcdoc', 'formaction', 'action'], true) || str_starts_with($name, 'xlink')) {
                $this->issues->warning('security_stripped', $loc, "The '{$name}' attribute was removed.", 'attribute:' . substr($name, 0, 40));
                continue;
            }
            if (!in_array($name, self::READ_ATTRIBUTES, true)) {
                continue; // data-*, srcset, aria-*, …: never read, never persisted
            }
            if (strlen($value) > self::MAX_ATTR_BYTES) {
                if ($name === 'href' || $name === 'src') {
                    $this->issues->warning('unsafe_url', $loc, 'A link or image address is too long; it was ignored.', $name);
                }
                continue;
            }
            if ($name === 'class') {
                $out['classes'] = $this->classes($value, $loc);
            } elseif ($name === 'id') {
                $out['id'] = preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $value) === 1 ? $value : null;
            } else {
                $out['attrs'][$name] = $value;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function classes(string $value, string $loc): array
    {
        $tokens = array_values(array_unique(preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        if (count($tokens) > self::MAX_CLASSES) {
            $this->issues->warning('source_limit_exceeded', $loc, 'An element has more than ' . self::MAX_CLASSES . ' classes; the rest were ignored.', 'classes');
            $tokens = array_slice($tokens, 0, self::MAX_CLASSES);
        }
        $out = [];
        foreach ($tokens as $t) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $t) !== 1) {
                continue;
            }
            if (!isset($this->classSet[$t]) && count($this->classSet) >= self::MAX_DISTINCT_CLASSES) {
                if (!$this->classCapReported) {
                    $this->classCapReported = true;
                    $this->issues->warning('source_limit_exceeded', $loc, 'The HTML uses more than ' . self::MAX_DISTINCT_CLASSES . ' distinct classes; further classes were ignored.', 'classes');
                }
                continue;
            }
            $this->classSet[$t] = true;
            $out[] = $t;
        }
        return $out;
    }
}
