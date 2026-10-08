<?php
/**
 * Kohevo Studio (studio-builder) — Phase 8B: structure mapping.
 *
 * Source tree + CSS evidence -> import IR -> canonical document, using ONLY
 * the current core blocks and their exact registry prop shapes:
 *
 *   h1–h6                  core.heading   {text, level}
 *   p / lists / quote / pre / dl / loose inline text
 *                          core.rich_text {content} — markup REBUILT here from
 *                          safe nodes (bare allowlisted tags, `<a href target rel>`
 *                          only), each unit pre-checked with the write validator
 *   button-like links      core.button    {link: {label, href, target}, variant}
 *   <img> (tenant /uploads/ only)
 *                          core.image     {media: {media_id, alt}, caption}
 *   hero pattern           core.hero      {eyebrow, heading, subheading, primary_cta, media}
 *   2–12 uniform cards     core.feature_list {columns, items: [{heading, body, url}]}
 *   flex / grid wrappers   core.container {gap, direction} (+ children), depth ≤ 4
 *   section / article / header / footer / main / body-level blocks -> sections
 *
 * Heuristics are deterministic and conservative; anything that does not
 * match falls back to the generic mapping. `div`, `aside` and custom
 * elements are transparent unless flex/grid evidence makes a valid container.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Package\Html;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class HtmlStructureMapper
{
    private const HEADINGS    = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
    private const TEXT_BLOCKS = ['p', 'ul', 'ol', 'dl', 'blockquote', 'pre', 'figcaption', 'li', 'dt', 'dd', 'address'];
    private const FORMAT      = ['strong', 'b', 'em', 'i', 'u', 's', 'code'];
    private const INLINE      = [
        'a', 'span', 'strong', 'b', 'em', 'i', 'u', 's', 'code', 'br', 'small', 'mark', 'sub', 'sup', 'abbr', 'cite', 'q',
        'time', 'kbd', 'samp', 'var', 'del', 'ins', 'dfn', 'bdi', 'bdo', 'wbr', 'data', 'font', 'big', 'tt', 'strike',
    ];
    private const MEDIA       = ['img', 'picture', 'figure'];
    private const SECTIONING  = ['section', 'article', 'header', 'footer', 'main'];
    private const IMAGE_EXTS  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];
    private const ALL_DEVICES = CanonicalDocumentSchema::ALLOWED_BREAKPOINTS;

    public const MAX_CANONICAL_DEPTH = 4;

    /** @var list<array<string, mixed>> */
    private array $nodes;

    /** @var array<int, array{decls: array<string, string>, visible: list<string>}> */
    private array $computed = [];

    /** @var array<string, int> media path => package-local key */
    private array $mediaKeys = [];

    /** @var array<string, true> */
    private array $unmappedSeen = [];

    /** @var array<int, true> */
    private array $hiddenReported = [];

    /** @var array<string, int> */
    private array $stats = [
        'blocks_mapped' => 0, 'transformed' => 0, 'hidden_dropped' => 0, 'unsafe_text_dropped' => 0,
        'style_quantized' => 0, 'css_value_unmapped' => 0, 'media_local' => 0, 'media_dropped' => 0,
    ];

    /** @param array{nodes: list<array<string, mixed>>, body: int} $source */
    public function __construct(
        array $source,
        private readonly HtmlCssParser $css,
        private readonly HtmlCssValues $values,
        private readonly HtmlImportIssues $issues,
        private readonly int $body = 0,
    ) {
        $this->nodes = $source['nodes'];
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->stats;
    }

    // ── 1. Computed style evidence (top-down, parents before children) ───────

    public function computeStyles(): bool
    {
        foreach ($this->nodes as $id => $n) {
            if ($n['tag'] === '#text') {
                continue;
            }
            $match = $this->css->match((string) $n['tag'], (array) $n['classes'], $n['id']);
            $decls = $match['decls'];
            $inline = [];
            if (isset($n['attrs']['style']) && trim((string) $n['attrs']['style']) !== '') {
                $parsed = $this->css->parseInline((string) $n['attrs']['style'], (string) $n['loc']);
                if ($parsed === null) {
                    return false;
                }
                $inline = $parsed;
                $decls = array_merge($decls, $inline);
            }
            foreach ($decls as $prop => $value) {
                $resolved = $this->css->resolveVar($value);
                if ($resolved === null) {
                    $this->unmapped((string) $n['loc'], $prop, $value);
                    unset($decls[$prop]);
                } else {
                    $decls[$prop] = $resolved;
                }
            }
            $parent = (int) ($n['parent'] ?? -1);
            $parentComputed = $parent >= 0 ? ($this->computed[$parent] ?? null) : null;
            foreach (HtmlCssParser::INHERITED as $prop) {
                if (!isset($decls[$prop]) && $parentComputed !== null && isset($parentComputed['decls'][$prop])) {
                    $decls[$prop] = $parentComputed['decls'][$prop];
                }
            }

            $visible = self::ALL_DEVICES;
            if (isset($inline['display'])) {
                $visible = strtolower(trim($inline['display'])) === 'none' ? [] : self::ALL_DEVICES;
            } else {
                if (isset($decls['display']) && strtolower(trim($decls['display'])) === 'none') {
                    $visible = [];
                }
                foreach ($match['media'] as $m) {
                    $visible = $m['display'] === 'none'
                        ? array_values(array_diff($visible, $m['buckets']))
                        : array_values(array_intersect(self::ALL_DEVICES, array_unique([...$visible, ...$m['buckets']])));
                }
            }
            if ($parentComputed !== null) {
                $visible = array_values(array_intersect($visible, $parentComputed['visible']));
            }
            $this->computed[$id] = ['decls' => $decls, 'visible' => $visible];
        }
        return true;
    }

    // ── 2. Sections -> IR ────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function sections(): array
    {
        $out = [];
        foreach ($this->collectSections($this->body, 0) as $entry) {
            $section = $this->mapSection($entry['el'], $entry['items']);
            if ($section !== null) {
                $out[] = $section;
            }
        }
        return $out;
    }

    /** @return list<array{el: ?int, items: list<int>}> */
    private function collectSections(int $container, int $guard): array
    {
        $items = $this->significant($this->children($container));
        // A lone generic wrapper (div / main / custom) around everything is unwrapped.
        if ($guard < 16 && count($items) === 1 && $this->isElement($items[0])) {
            $only = $items[0];
            $tag = $this->tag($only);
            if (!in_array($tag, ['section', 'header', 'footer', 'article'], true) && !$this->isContentLevel($tag) && !$this->isHidden($only)) {
                return $this->collectSections($only, $guard + 1);
            }
        }

        $out = [];
        $loose = [];
        $flush = function () use (&$loose, &$out): void {
            if ($this->significant($loose) !== []) {
                $out[] = ['el' => null, 'items' => $loose];
            }
            $loose = [];
        };
        foreach ($this->children($container) as $child) {
            if (!$this->isElement($child)) {
                $loose[] = $child;
                continue;
            }
            if ($this->skipHidden($child)) {
                continue;
            }
            $tag = $this->tag($child);
            if ($this->isContentLevel($tag)) {
                $loose[] = $child;
                continue;
            }
            $flush();
            if ($guard < 16 && $this->hasSectioningChild($child)) {
                foreach ($this->collectSections($child, $guard + 1) as $entry) {
                    $out[] = $entry;
                }
                continue;
            }
            if ($tag === 'header' || $tag === 'footer') {
                $this->issues->warning('chrome_not_imported', $this->loc($child), "The <{$tag}> was imported as an ordinary page section; site header and footer are managed separately and were not changed.", $tag);
            }
            $out[] = ['el' => $child, 'items' => $this->children($child)];
        }
        $flush();
        return $out;
    }

    /** @param list<int> $items @return ?array<string, mixed> */
    private function mapSection(?int $el, array $items): ?array
    {
        $nodes = null;
        if ($el !== null) {
            $features = $this->tryFeatures($el);
            if ($features !== null) {
                $nodes = [$features];
            } elseif ($this->isFlexOrGrid($el)) {
                $nodes = $this->flexContainer($el, 1);
            }
        }
        $nodes ??= $this->mapFlow($items, 1, $el);

        $hero = $this->tryHero($nodes, $el);
        if ($hero !== null) {
            $nodes = [$hero];
        }
        $nodes = $this->merge($nodes);
        if ($nodes === []) {
            return null;
        }

        $loc = $el !== null ? $this->loc($el) : $this->locOfItems($items);
        return [
            'loc'        => $loc,
            'label'      => $this->sectionLabel($nodes),
            'layout'     => $el !== null ? $this->sectionLayout($el) : [],
            'visibility' => $el !== null ? $this->visibility($el) : null,
            'nodes'      => $nodes,
        ];
    }

    /**
     * Map a sequence of source nodes (siblings) to IR nodes at canonical depth `$depth`.
     *
     * @param list<int> $items
     * @return list<array<string, mixed>>
     */
    private function mapFlow(array $items, int $depth, ?int $parentEl): array
    {
        $out = [];
        $inline = [];
        $soleContext = $parentEl === null || !in_array($this->tag($parentEl), [...self::TEXT_BLOCKS, ...self::INLINE, ...self::HEADINGS], true);
        $soleItem = count($this->significant($items)) === 1;

        $flushInline = function () use (&$inline, &$out, $parentEl): void {
            if ($inline !== []) {
                $unit = $this->richUnit('p', $inline, $parentEl);
                if ($unit !== null) {
                    $out[] = $unit;
                }
                $inline = [];
            }
        };

        foreach ($items as $id) {
            if (!$this->isElement($id)) {
                $inline[] = $id;
                continue;
            }
            if ($this->skipHidden($id)) {
                continue;
            }
            $tag = $this->tag($id);

            if ($tag === 'a' && $this->hasMediaDescendant($id)) {
                $flushInline();
                foreach ($this->mapFlow($this->children($id), $depth, $id) as $node) {
                    $out[] = $node;
                }
                continue;
            }
            if ($tag === 'a' && $this->isButtonLike($id, $soleContext && $soleItem)) {
                $flushInline();
                $node = $this->button($id);
                if ($node !== null) {
                    $out[] = $node;
                }
                continue;
            }
            if (in_array($tag, self::INLINE, true)) {
                $inline[] = $id;
                continue;
            }
            $flushInline();

            if (in_array($tag, self::HEADINGS, true)) {
                $node = $this->heading($id);
                if ($node !== null) {
                    $out[] = $node;
                }
            } elseif ($tag === 'p' && $this->hasMediaDescendant($id)) {
                foreach ($this->mapFlow($this->children($id), $depth, $id) as $node) {
                    $out[] = $node;
                }
            } elseif (in_array($tag, self::TEXT_BLOCKS, true)) {
                $unit = $this->richUnit($tag, [$id], $id);
                if ($unit !== null) {
                    $out[] = $unit;
                }
            } elseif ($tag === 'img') {
                $node = $this->image($id, '', $id);
                if ($node !== null) {
                    $out[] = $node;
                }
            } elseif ($tag === 'picture') {
                $img = $this->firstDescendant($id, 'img');
                $node = $img !== null ? $this->image($img, '', $id) : null;
                if ($node !== null) {
                    $out[] = $node;
                }
            } elseif ($tag === 'figure') {
                foreach ($this->figure($id, $depth) as $node) {
                    $out[] = $node;
                }
            } else {
                foreach ($this->blockContainer($id, $depth) as $node) {
                    $out[] = $node;
                }
            }
        }
        $flushInline();
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function blockContainer(int $id, int $depth): array
    {
        $features = $this->tryFeatures($id);
        if ($features !== null) {
            return [$features];
        }
        if ($this->isFlexOrGrid($id)) {
            return $this->flexContainer($id, $depth);
        }
        return $this->mapFlow($this->children($id), $depth, $id);
    }

    /**
     * Flex/grid evidence -> a horizontal/vertical `core.container`, each
     * multi-block child wrapped in its own vertical container (canonical
     * sections have no per-block column spans). Never deeper than depth 4:
     * without room the wrapper stays transparent.
     *
     * @return list<array<string, mixed>>
     */
    private function flexContainer(int $id, int $depth): array
    {
        if ($depth > self::MAX_CANONICAL_DEPTH - 1) {
            return $this->mapFlow($this->children($id), $depth, $id);
        }
        $children = [];
        foreach ($this->significant($this->children($id)) as $child) {
            if (!$this->isElement($child)) {
                $unit = $this->richUnit('p', [$child], $id);
                if ($unit !== null) {
                    $children[] = $unit;
                }
                continue;
            }
            if ($this->skipHidden($child)) {
                continue;
            }
            $mapped = $this->merge($this->mapFlow([$child], $depth + 2, $id));
            if (count($mapped) > 1 && $depth + 1 <= self::MAX_CANONICAL_DEPTH - 1) {
                $this->stats['transformed']++;
                $children[] = HtmlImportIr::node('container', $this->loc($child), [
                    'layout'     => ['direction' => 'vertical', 'gap' => 'md'],
                    'visibility' => $this->visibility($child),
                    'children'   => $mapped,
                ]);
            } else {
                foreach ($mapped as $node) {
                    $children[] = $node;
                }
            }
        }
        if (count($children) < 2) {
            return $children;
        }
        $decls = $this->decls($id);
        $display = strtolower(trim($decls['display'] ?? ''));
        if (str_contains($display, 'grid')) {
            $cols = HtmlCssValues::columns($decls['grid-template-columns'] ?? '');
            $direction = $cols !== null && $cols > 1 ? 'horizontal' : 'vertical';
        } else {
            $direction = str_starts_with(strtolower(trim($decls['flex-direction'] ?? 'row')), 'column') ? 'vertical' : 'horizontal';
        }
        $this->stats['transformed']++;
        return [HtmlImportIr::node('container', $this->loc($id), [
            'layout'     => ['direction' => $direction, 'gap' => $this->gap($id) ?? 'md'],
            'style'      => $this->style($id, false),
            'visibility' => $this->visibility($id),
            'children'   => $children,
        ])];
    }

    /** @return list<array<string, mixed>> */
    private function figure(int $id, int $depth): array
    {
        $img = null;
        $caption = '';
        $captionEl = null;
        $rest = [];
        foreach ($this->children($id) as $child) {
            $tag = $this->isElement($child) ? $this->tag($child) : '#text';
            if ($img === null && in_array($tag, ['img', 'picture', 'a'], true)) {
                $found = $tag === 'img' ? $child : $this->firstDescendant($child, 'img');
                if ($found !== null) {
                    $img = $found;
                    continue;
                }
            }
            if ($tag === 'figcaption' && $captionEl === null) {
                $caption = $this->plainText($child);
                $captionEl = $child;
                continue;
            }
            $rest[] = $child;
        }
        $out = [];
        if ($img !== null) {
            $node = $this->image($img, $caption, $id);
            if ($node !== null) {
                $this->stats['transformed']++;
                $out[] = $node;
            }
        } elseif ($captionEl !== null) {
            array_unshift($rest, $captionEl);
        }
        foreach ($this->mapFlow($rest, $depth, $id) as $node) {
            $out[] = $node;
        }
        return $out;
    }

    // ── 3. Heuristics ────────────────────────────────────────────────────────

    /**
     * 2–12 element children of one tag, each a card holding exactly one
     * heading, at most one paragraph and at most one link — and nothing else
     * (an image, list, extra text… disqualifies). Otherwise null (fallback).
     *
     * @return ?array<string, mixed>
     */
    private function tryFeatures(int $id): ?array
    {
        $items = $this->significant($this->children($id));
        if (count($items) < 2 || count($items) > 12) {
            return null;
        }
        $tag = null;
        $cards = [];
        foreach ($items as $child) {
            if (!$this->isElement($child) || $this->isHidden($child)) {
                return null;
            }
            $tag ??= $this->tag($child);
            if ($this->tag($child) !== $tag || in_array($tag, [...self::HEADINGS, ...self::TEXT_BLOCKS, ...self::MEDIA], true)) {
                return null;
            }
            $card = $this->card($child);
            if ($card === null) {
                return null;
            }
            $cards[] = $card;
        }
        $decls = $this->decls($id);
        $display = strtolower(trim($decls['display'] ?? ''));
        $count = count($cards);
        if (str_contains($display, 'grid')) {
            $columns = max(1, min(4, HtmlCssValues::columns($decls['grid-template-columns'] ?? '') ?? $count));
        } elseif (str_contains($display, 'flex')) {
            $columns = str_starts_with(strtolower(trim($decls['flex-direction'] ?? 'row')), 'column') ? 1 : min(4, $count);
        } else {
            $columns = 1;
        }
        $this->stats['transformed']++;
        return HtmlImportIr::node('features', $this->loc($id), [
            'text'       => ['items' => $cards],
            'layout'     => ['columns' => $columns],
            'style'      => $this->style($id, true),
            'visibility' => $this->visibility($id),
        ]);
    }

    /** @return ?array{heading: string, body: string, url: ?string} */
    private function card(int $card): ?array
    {
        $headings = [];
        $paras = [];
        $links = [];
        if ($this->tag($card) === 'a') {
            $links[] = trim((string) ($this->nodes[$card]['attrs']['href'] ?? ''));
        }
        $stack = array_reverse($this->children($card));
        while ($stack !== []) {
            $id = array_pop($stack);
            if (!$this->isElement($id)) {
                if (trim((string) $this->nodes[$id]['text']) !== '') {
                    return null;
                }
                continue;
            }
            if ($this->isHidden($id)) {
                return null;
            }
            $tag = $this->tag($id);
            if (in_array($tag, self::HEADINGS, true) || $tag === 'p') {
                if ($this->hasMediaDescendant($id) || $this->hasBlockDescendant($id)) {
                    return null;
                }
                $text = $this->plainText($id);
                if ($text === '') {
                    continue;
                }
                if ($tag === 'p') {
                    $paras[] = $text;
                } else {
                    $headings[] = $text;
                }
                foreach ($this->descendants($id, 'a') as $a) {
                    $links[] = trim((string) ($this->nodes[$a]['attrs']['href'] ?? ''));
                }
                continue;
            }
            if ($tag === 'a') {
                $links[] = trim((string) ($this->nodes[$id]['attrs']['href'] ?? ''));
                if ($this->hasBlockDescendant($id)) {
                    foreach (array_reverse($this->children($id)) as $c) {
                        $stack[] = $c;
                    }
                }
                continue;
            }
            if (in_array($tag, [...self::TEXT_BLOCKS, ...self::MEDIA, ...self::INLINE], true)) {
                return null;
            }
            foreach (array_reverse($this->children($id)) as $c) {
                $stack[] = $c;
            }
        }
        $links = array_values(array_unique(array_filter($links, static fn(string $l): bool => $l !== '')));
        if (count($headings) !== 1 || count($paras) > 1 || count($links) > 1) {
            return null;
        }
        $heading = $headings[0];
        $body = $paras[0] ?? '';
        $url = $links[0] ?? null;
        if (mb_strlen($heading, 'UTF-8') > 150 || mb_strlen($body, 'UTF-8') > 600
            || FieldSchema::containsExecutableOrSqlFragment($heading) || FieldSchema::containsExecutableOrSqlFragment($body)
            || ($url !== null && !FieldSchema::isSafeUrl($url))) {
            return null;
        }
        return ['heading' => $heading, 'body' => $body, 'url' => $url];
    }

    /**
     * Section-level hero: optional eyebrow, exactly one heading (h1), optional
     * paragraph, at most one button, at most one image — nothing else — and at
     * least one of paragraph / button / image. Containers are looked through.
     *
     * @param list<array<string, mixed>> $nodes
     * @return ?array<string, mixed>
     */
    private function tryHero(array $nodes, ?int $el): ?array
    {
        $leaves = [];
        $stack = array_reverse($nodes);
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node['type'] === 'container') {
                foreach (array_reverse($node['children']) as $c) {
                    $stack[] = $c;
                }
                continue;
            }
            $leaves[] = $node;
        }
        $heading = null;
        $eyebrow = null;
        $sub = null;
        $button = null;
        $image = null;
        $visibility = null;
        foreach ($leaves as $i => $leaf) {
            if ($i === 0) {
                $visibility = $leaf['visibility'];
            } elseif ($leaf['visibility'] !== $visibility) {
                return null;
            }
            switch ($leaf['type']) {
                case 'heading':
                    if ($heading !== null || $leaf['text']['level'] !== 'h1' || mb_strlen($leaf['text']['text'], 'UTF-8') > 255) {
                        return null;
                    }
                    $heading = $leaf;
                    break;
                case 'rich':
                    if (empty($leaf['text']['plain_p'])) {
                        return null;
                    }
                    $text = (string) $leaf['text']['plain'];
                    if ($heading === null && $eyebrow === null && mb_strlen($text, 'UTF-8') <= 120) {
                        $eyebrow = $text;
                    } elseif ($heading !== null && $sub === null && mb_strlen($text, 'UTF-8') <= 1000) {
                        $sub = $text;
                    } else {
                        return null;
                    }
                    break;
                case 'button':
                    if ($button !== null) {
                        return null;
                    }
                    $button = $leaf;
                    break;
                case 'image':
                    if ($image !== null) {
                        return null;
                    }
                    $image = $leaf;
                    break;
                default:
                    return null;
            }
        }
        if ($heading === null || ($sub === null && $button === null && $image === null)) {
            return null;
        }
        $this->stats['transformed']++;
        return HtmlImportIr::node('hero', $heading['loc'], [
            'text'       => ['eyebrow' => $eyebrow ?? '', 'heading' => $heading['text']['text'], 'subheading' => $sub ?? ''],
            'link'       => $button['link'] ?? null,
            'media'      => $image['media'] ?? null,
            'style'      => $el !== null ? $this->style($el, false) : $heading['style'],
            'visibility' => $visibility,
        ]);
    }

    /**
     * Adjacent rich-text units with identical style and visibility become one
     * block (≤ 50,000 characters).
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private function merge(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $last = $out === [] ? null : $out[count($out) - 1];
            if ($node['type'] === 'rich' && $last !== null && $last['type'] === 'rich'
                && $last['style'] === $node['style'] && $last['visibility'] === $node['visibility']
                && mb_strlen($last['text']['html'] . $node['text']['html'], 'UTF-8') <= CanonicalDocumentSchema::MAX_RICH_TEXT_LENGTH) {
                $last['text']['html'] .= $node['text']['html'];
                $last['text']['plain'] .= ' ' . $node['text']['plain'];
                $last['text']['plain_p'] = false;
                $out[count($out) - 1] = $last;
                continue;
            }
            $out[] = $node;
        }
        return $out;
    }

    // ── 4. Leaf nodes ────────────────────────────────────────────────────────

    /** @return ?array<string, mixed> */
    private function heading(int $id): ?array
    {
        $text = $this->plainText($id);
        if ($text === '') {
            return null;
        }
        $loc = $this->loc($id);
        $text = $this->fitText($text, 300, $loc);
        if (FieldSchema::containsExecutableOrSqlFragment($text)) {
            $this->unsafeText($loc, 'heading');
            return null;
        }
        $this->stats['blocks_mapped']++;
        return HtmlImportIr::node('heading', $loc, [
            'text'       => ['text' => $text, 'level' => $this->tag($id)],
            'style'      => $this->style($id, true),
            'visibility' => $this->visibility($id),
        ]);
    }

    /** @return ?array<string, mixed> a button, or its label as text when the address is unsafe */
    private function button(int $id): ?array
    {
        $loc = $this->loc($id);
        $label = $this->plainText($id);
        $href = trim((string) ($this->nodes[$id]['attrs']['href'] ?? ''));
        if ($label === '' || !FieldSchema::isSafeUrl($href)) {
            if ($label !== '') {
                $this->issues->warning('unsafe_url', $loc, 'A button link has an unsafe or unsupported address; only its text was kept.', 'button');
            }
            return $label === '' ? null : $this->textUnit($label, $id);
        }
        $label = $this->fitText($label, 255, $loc);
        if (FieldSchema::containsExecutableOrSqlFragment($label)) {
            $this->unsafeText($loc, 'button');
            return null;
        }
        $target = strtolower(trim((string) ($this->nodes[$id]['attrs']['target'] ?? ''))) === '_blank' ? '_blank' : '_self';
        $variant = 'primary';
        foreach ($this->nodes[$id]['classes'] as $class) {
            foreach (preg_split('/[-_]/', strtolower((string) $class)) ?: [] as $part) {
                if (in_array($part, ['secondary', 'outline', 'ghost'], true)) {
                    $variant = $part;
                    break 2;
                }
            }
        }
        $this->stats['blocks_mapped']++;
        return HtmlImportIr::node('button', $loc, [
            'text'       => ['variant' => $variant],
            'link'       => ['label' => $label, 'href' => $href, 'target' => $target],
            'style'      => $this->style($id, true),
            'visibility' => $this->visibility($id),
        ]);
    }

    /** @return ?array<string, mixed> */
    private function image(int $img, string $caption, int $styleEl): ?array
    {
        $loc = $this->loc($img);
        $path = $this->imagePath((string) ($this->nodes[$img]['attrs']['src'] ?? ''), $loc);
        if ($path === null) {
            $this->stats['media_dropped']++;
            return null;
        }
        $alt = (string) preg_replace('/\s+/u', ' ', trim((string) ($this->nodes[$img]['attrs']['alt'] ?? '')));
        $alt = $this->fitText($alt, 500, $loc);
        if (FieldSchema::containsExecutableOrSqlFragment($alt)) {
            $this->unsafeText($loc, 'alt');
            $alt = '';
        }
        $caption = $this->fitText($caption, 300, $loc);
        if (FieldSchema::containsExecutableOrSqlFragment($caption)) {
            $this->unsafeText($loc, 'caption');
            $caption = '';
        }
        $this->mediaKeys[$path] ??= count($this->mediaKeys) + 1;
        $this->stats['media_local']++;
        $this->stats['blocks_mapped']++;
        return HtmlImportIr::node('image', $loc, [
            'text'       => ['caption' => $caption],
            'media'      => ['path' => $path, 'alt' => $alt],
            'style'      => $this->style($styleEl, true),
            'visibility' => $this->visibility($img),
        ]);
    }

    /**
     * Only a tenant-local managed `/uploads/…` path can become an image. Never
     * fetched, never stored as a URL (the canonical media_ref holds a tenant
     * media id, resolved later through the existing tenant-scoped lookup).
     */
    private function imagePath(string $src, string $loc): ?string
    {
        $v = trim($src);
        $compact = strtolower((string) preg_replace('/\s+/', '', html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($v === '') {
            $this->issues->warning('unsupported_media', $loc, 'An image without an address was left out.', 'no_src');
            return null;
        }
        if (str_starts_with($compact, 'data:')) {
            $this->issues->warning('security_stripped', $loc, 'An embedded (data:) image was removed without being decoded.', 'data_uri');
            return null;
        }
        if (!FieldSchema::isSafeUrl($v)) {
            $this->issues->warning('unsafe_url', $loc, 'An image address is unsafe or malformed; the image was left out.', 'image');
            return null;
        }
        if (preg_match('~^https?://~i', $v) === 1) {
            $host = strtolower((string) (parse_url($v, PHP_URL_HOST) ?? ''));
            $host = substr((string) preg_replace('/[^a-z0-9.-]/', '', $host), 0, 100);
            $this->issues->warning('unresolved_media', $loc, "An external image ({$host}) is never downloaded; the image was left out. Upload it to this site's media library and map it to import it.", 'external_url');
            return null;
        }
        $path = (string) preg_replace('/[?#].*$/s', '', $v);
        if (!str_starts_with($path, '/uploads/') || str_contains($path, '..') || str_contains($path, '\\') || strlen($path) > 500) {
            $this->issues->warning('unresolved_media', $loc, "Only images already in this site's media library (/uploads/…) can be imported; the image was left out.", 'not_managed');
            return null;
        }
        if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::IMAGE_EXTS, true)) {
            $this->issues->warning('unsupported_media', $loc, 'This file type is not an importable image; it was left out.', 'extension');
            return null;
        }
        return $path;
    }

    /**
     * One rich-text unit rebuilt from safe nodes, pre-checked with the CURRENT
     * write validator; a unit the validator refuses is dropped on its own
     * (never the whole page).
     *
     * @param list<int> $ids
     * @return ?array<string, mixed>
     */
    private function richUnit(string $kind, array $ids, ?int $styleEl): ?array
    {
        $html = $kind === 'p' && ($ids === [] || !$this->isElement($ids[0]) || $this->tag($ids[0]) !== 'p' || count($ids) > 1)
            ? '<p>' . $this->emitNodes($ids, 'inline', false) . '</p>'
            : $this->emitBlock($ids[0]);
        $html = self::tidy($html);
        $plain = self::plainOf($html);
        if ($plain === '') {
            return null;
        }
        $loc = $ids !== [] ? $this->locOfItems($ids) : 'html:';
        if (mb_strlen($html, 'UTF-8') > CanonicalDocumentSchema::MAX_RICH_TEXT_LENGTH) {
            $this->issues->warning('text_truncated', $loc, 'A very long text was shortened and imported as plain text.', 'rich_text');
            $html = '<p>' . Html::e(mb_substr($plain, 0, 49000, 'UTF-8')) . '</p>';
        }
        if (FieldSchema::validateRichText($html) !== null) {
            $this->unsafeText($loc, 'rich_text');
            return null;
        }
        $this->stats['blocks_mapped']++;
        return HtmlImportIr::node('rich', $loc, [
            'text'       => ['html' => $html, 'plain' => $plain, 'plain_p' => preg_match('~^<p>[^<]*</p>$~', $html) === 1],
            'style'      => $styleEl !== null ? $this->style($styleEl, true) : [],
            'visibility' => $styleEl !== null ? $this->visibility($styleEl) : null,
        ]);
    }

    /** @return ?array<string, mixed> */
    private function textUnit(string $text, int $styleEl): ?array
    {
        $html = '<p>' . Html::e($text) . '</p>';
        if (FieldSchema::validateRichText($html) !== null) {
            $this->unsafeText($this->loc($styleEl), 'rich_text');
            return null;
        }
        return HtmlImportIr::node('rich', $this->loc($styleEl), [
            'text'       => ['html' => $html, 'plain' => $text, 'plain_p' => true],
            'style'      => $this->style($styleEl, true),
            'visibility' => $this->visibility($styleEl),
        ]);
    }

    // ── 5. Allowlisted rich-text emission ────────────────────────────────────

    private function emitBlock(int $id): string
    {
        $tag = $this->tag($id);
        $children = $this->children($id);
        return match (true) {
            in_array($tag, ['p', 'figcaption', 'address', 'dt', 'dd', 'li'], true) => '<p>' . $this->emitNodes($children, 'inline', false) . '</p>',
            in_array($tag, self::HEADINGS, true) => "<{$tag}>" . $this->emitNodes($children, 'inline', false) . "</{$tag}>",
            $tag === 'ul' || $tag === 'ol' => $this->emitList($id),
            $tag === 'blockquote' => '<blockquote>' . $this->emitNodes($children, 'flow', false) . '</blockquote>',
            $tag === 'pre' => '<pre>' . $this->emitPre($children) . '</pre>',
            $tag === 'dl' => $this->emitDefinitionList($id),
            default => $this->emitNodes($children, 'flow', false),
        };
    }

    private function emitList(int $id): string
    {
        $tag = $this->tag($id);
        $out = '';
        foreach ($this->children($id) as $child) {
            if (!$this->isElement($child)) {
                if (trim((string) $this->nodes[$child]['text']) !== '') {
                    $out .= '<li>' . $this->emitNodes([$child], 'inline', false) . '</li>';
                }
                continue;
            }
            if ($this->skipHidden($child)) {
                continue;
            }
            $inner = $this->tag($child) === 'li' ? $this->children($child) : [$child];
            $out .= '<li>' . $this->emitNodes($inner, 'li', false) . '</li>';
        }
        return "<{$tag}>{$out}</{$tag}>";
    }

    private function emitDefinitionList(int $id): string
    {
        $out = '';
        foreach ($this->children($id) as $child) {
            if (!$this->isElement($child) || $this->skipHidden($child)) {
                continue;
            }
            $inline = $this->emitNodes($this->children($child), 'inline', false);
            $out .= match ($this->tag($child)) {
                'dt'    => '<p><strong>' . $inline . '</strong></p>',
                'dd'    => '<p>' . $inline . '</p>',
                default => $this->emitNodes([$child], 'flow', false),
            };
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @param 'inline'|'flow'|'li' $ctx inline: phrasing only; flow: loose inline text is wrapped in <p>; li: kept bare
     */
    private function emitNodes(array $ids, string $ctx, bool $inLink): string
    {
        $out = '';
        $buf = '';
        $flush = function () use (&$out, &$buf, $ctx): void {
            if (trim($buf) !== '') {
                $out .= $ctx === 'flow' ? '<p>' . $buf . '</p>' : $buf;
            }
            $buf = '';
        };
        foreach ($ids as $id) {
            if (!$this->isElement($id)) {
                $buf .= Html::e((string) preg_replace('/\s+/u', ' ', (string) $this->nodes[$id]['text']));
                continue;
            }
            if ($this->skipHidden($id)) {
                continue;
            }
            $tag = $this->tag($id);
            if (in_array($tag, self::FORMAT, true)) {
                $inner = $this->emitNodes($this->children($id), 'inline', $inLink);
                $buf .= trim($inner) === '' ? $inner : "<{$tag}>{$inner}</{$tag}>";
            } elseif ($tag === 'br') {
                $buf .= '<br>';
            } elseif ($tag === 'a') {
                $buf .= $inLink ? $this->emitNodes($this->children($id), 'inline', true) : $this->anchor($id);
            } elseif (in_array($tag, self::MEDIA, true)) {
                $this->issues->warning('unsupported_element', $this->loc($id), 'An image inside text or a list cannot be imported there; it was left out.', 'image_in_text');
            } elseif (in_array($tag, self::INLINE, true)) {
                $buf .= $this->emitNodes($this->children($id), 'inline', $inLink);
            } elseif ($ctx === 'inline') {
                $buf .= ' ' . $this->emitNodes($this->children($id), 'inline', $inLink) . ' ';
            } elseif ($ctx === 'li' && !in_array($tag, ['ul', 'ol', 'p', 'blockquote', 'pre', 'dl'], true)) {
                $buf .= ' ' . $this->emitNodes($this->children($id), 'li', $inLink) . ' ';
            } else {
                $flush();
                $out .= $this->emitBlock($id);
            }
        }
        $flush();
        return $out;
    }

    private function emitPre(array $ids): string
    {
        $out = '';
        foreach ($ids as $id) {
            if (!$this->isElement($id)) {
                $out .= Html::e((string) $this->nodes[$id]['text']);
                continue;
            }
            if ($this->isHidden($id)) {
                continue;
            }
            $tag = $this->tag($id);
            if ($tag === 'br') {
                $out .= "\n";
            } elseif ($tag === 'code') {
                $out .= '<code>' . $this->emitPre($this->children($id)) . '</code>';
            } else {
                $out .= $this->emitPre($this->children($id));
            }
        }
        return $out;
    }

    private function anchor(int $id): string
    {
        $inner = $this->emitNodes($this->children($id), 'inline', true);
        if (trim(strip_tags($inner)) === '') {
            return '';
        }
        $href = $this->nodes[$id]['attrs']['href'] ?? null;
        if ($href === null) {
            return $inner;
        }
        $href = trim((string) $href);
        if (!FieldSchema::isSafeUrl($href)) {
            $this->issues->warning('unsafe_url', $this->loc($id), 'A link has an unsafe or unsupported address; only its text was kept.', 'link');
            return $inner;
        }
        $blank = strtolower(trim((string) ($this->nodes[$id]['attrs']['target'] ?? ''))) === '_blank';
        return '<a href="' . Html::e($href) . '"' . ($blank ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $inner . '</a>';
    }

    /** Whitespace and empty-element cleanup of converter-built markup (never touches <pre>). */
    private static function tidy(string $html): string
    {
        if (str_starts_with($html, '<pre>')) {
            return $html;
        }
        $html = (string) preg_replace('/ {2,}/', ' ', $html);
        $html = (string) preg_replace('~(<(?:p|li|h[1-6]|blockquote)>)\s+~', '$1', $html);
        $html = (string) preg_replace('~\s+(</(?:p|li|h[1-6]|blockquote)>)~', '$1', $html);
        for ($i = 0; $i < 5; $i++) {
            $next = (string) preg_replace('~<(p|li|strong|em|b|i|u|s|code|blockquote|h[1-6]|ul|ol)>\s*</\1>~', '', $html);
            if ($next === $html) {
                break;
            }
            $html = $next;
        }
        return trim($html);
    }

    private static function plainOf(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    // ── 6. Style / layout evidence -> canonical enums and registry tokens ───

    /** @return array<string, mixed> block style hints (registry token refs only) */
    private function style(int $id, bool $withSpacing): array
    {
        $decls = $this->decls($id);
        $loc = $this->loc($id);
        $out = [];
        if (isset($decls['text-align'])) {
            $align = HtmlCssValues::align($decls['text-align']);
            $align !== null ? $out['align'] = $align : $this->unmapped($loc, 'text-align', $decls['text-align']);
        }
        $bg = $decls['background-color'] ?? (isset($decls['background']) ? $decls['background'] : null);
        if ($bg !== null && strtolower(trim($bg)) !== 'transparent' && strtolower(trim($bg)) !== 'none') {
            $this->token($out, 'surface_token', 'surface', 'background-color', $bg, $loc);
        }
        foreach ([['text_token', 'text', 'color'], ['font_token', 'font', 'font-family'], ['radius_token', 'radius', 'border-radius'], ['shadow_token', 'shadow', 'box-shadow']] as [$field, $category, $prop]) {
            if (isset($decls[$prop]) && !in_array(strtolower(trim($decls[$prop])), ['none', 'inherit', 'initial', 'unset', '0'], true)) {
                $this->token($out, $field, $category, $prop, $decls[$prop], $loc);
            }
        }
        if ($withSpacing && isset($decls['padding'])) {
            $sides = HtmlCssValues::sides($decls['padding']);
            if ($sides !== null && count(array_unique($sides)) === 1) {
                $this->token($out, 'spacing_token', 'space', 'padding', $sides[0], $loc);
            } else {
                $this->unmapped($loc, 'padding', $decls['padding']);
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $out */
    private function token(array &$out, string $field, string $category, string $prop, string $value, string $loc): void
    {
        $ref = $this->values->token($category, $value);
        if ($ref !== null) {
            $out[$field] = $ref;
        } else {
            $this->unmapped($loc, $prop, $value);
        }
    }

    /** @return array<string, mixed> */
    private function sectionLayout(int $el): array
    {
        $decls = $this->decls($el);
        $loc = $this->loc($el);
        $layout = [];

        $top = $decls['padding-top'] ?? null;
        $bottom = $decls['padding-bottom'] ?? null;
        if (isset($decls['padding']) && ($sides = HtmlCssValues::sides($decls['padding'])) !== null) {
            $top ??= $sides[0];
            $bottom ??= $sides[2];
        }
        if ($top !== null || $bottom !== null) {
            $rems = array_filter([HtmlCssValues::rem((string) $top), HtmlCssValues::rem((string) $bottom)], static fn($r): bool => $r !== null);
            if ($rems === []) {
                $this->unmapped($loc, 'padding', (string) ($top ?? $bottom));
            } else {
                $layout['padding_y'] = ['base' => $this->quantize(max($rems), HtmlCssValues::PADDING_SCALE, $loc, 'padding')];
            }
        }
        $gap = $this->gap($el);
        if ($gap !== null) {
            $layout['gap'] = $gap;
        }

        $maxWidth = $decls['max-width'] ?? null;
        if ($maxWidth === null) {
            $inner = $this->significant($this->children($el));
            if (count($inner) === 1 && $this->isElement($inner[0])) {
                $maxWidth = $this->decls($inner[0])['max-width'] ?? null;
            }
        }
        if ($maxWidth !== null) {
            $mw = strtolower(trim($maxWidth));
            if ($mw === 'none' || $mw === '100%') {
                $layout['width'] = 'full';
            } elseif (($rem = HtmlCssValues::rem($mw)) !== null && $rem > 0) {
                $layout['width'] = $this->quantize($rem, HtmlCssValues::WIDTH_SCALE, $loc, 'max-width');
            } else {
                $this->unmapped($loc, 'max-width', $maxWidth);
            }
        }

        $bg = $decls['background-color'] ?? ($decls['background'] ?? null);
        if ($bg !== null && !in_array(strtolower(trim($bg)), ['transparent', 'none'], true)) {
            $ref = $this->values->token('surface', $bg);
            $ref !== null ? $layout['background_token'] = $ref : $this->unmapped($loc, 'background-color', $bg);
        }
        return $layout;
    }

    private function gap(int $el): ?string
    {
        $decls = $this->decls($el);
        foreach (['gap', 'grid-gap', 'row-gap', 'column-gap'] as $prop) {
            if (!isset($decls[$prop])) {
                continue;
            }
            $first = (string) (preg_split('/\s+/', trim($decls[$prop]))[0] ?? '');
            $rem = HtmlCssValues::rem($first);
            if ($rem === null || $rem < 0) {
                $this->unmapped($this->loc($el), $prop, $decls[$prop]);
                return null;
            }
            return $this->quantize($rem, HtmlCssValues::GAP_SCALE, $this->loc($el), $prop);
        }
        return null;
    }

    /** @param array<string, float> $scale */
    private function quantize(float $rem, array $scale, string $loc, string $prop): string
    {
        [$bucket, $exact] = HtmlCssValues::quantize($rem, $scale);
        if (!$exact) {
            $this->stats['style_quantized']++;
            $this->issues->warning('style_quantized', $loc, "The {$prop} value was rounded to the nearest Studio size ({$bucket}).", $prop);
        }
        return $bucket;
    }

    private function unmapped(string $loc, string $prop, string $value): void
    {
        $key = $prop . ':' . strtolower(trim($value));
        if (isset($this->unmappedSeen[$key])) {
            return;
        }
        $this->unmappedSeen[$key] = true;
        $this->stats['css_value_unmapped']++;
        $this->issues->warning('css_value_unmapped', $loc, "The {$prop} value has no exact Studio equivalent (design tokens are matched exactly); it was not imported.", $prop);
    }

    // ── 7. IR -> canonical document ──────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $sections
     * @return ?array{document: array<string, mixed>, media: list<array{key: int, path: string, mime: string}>}
     */
    public function document(array $sections, string $pageType, string $title): ?array
    {
        if (count($sections) > CanonicalDocumentSchema::MAX_SECTIONS) {
            $this->issues->error('output_limit_exceeded', 'html:', 'The page would have ' . count($sections) . ' sections; Studio allows ' . CanonicalDocumentSchema::MAX_SECTIONS . '. Nothing was imported.', 'sections');
            return null;
        }
        $counter = ['sec' => 0, 'blk' => 0];
        $doc = CanonicalDocumentSchema::emptyDocument($pageType, 'default', $title);
        foreach ($sections as $section) {
            $layout = array_merge(CanonicalDocumentSchema::defaultSectionLayout(), ['columns' => ['base' => 1]], $section['layout']);
            $doc['sections'][] = [
                'id'         => sprintf('sec_%024d', ++$counter['sec']),
                'label'      => $section['label'],
                'global_ref' => null,
                'layout'     => $layout,
                'visibility' => ['auth_state' => 'any', 'devices' => $section['visibility'] ?? self::ALL_DEVICES],
                'blocks'     => $this->blocks($section['nodes'], $counter),
            ];
        }
        if ($counter['blk'] > CanonicalDocumentSchema::MAX_BLOCKS_PER_DOCUMENT) {
            $this->issues->error('output_limit_exceeded', 'html:', "The page would have {$counter['blk']} blocks; Studio allows " . CanonicalDocumentSchema::MAX_BLOCKS_PER_DOCUMENT . '. Nothing was imported.', 'blocks');
            return null;
        }
        $media = [];
        foreach ($this->mediaKeys as $path => $key) {
            $media[] = ['key' => $key, 'path' => $path, 'mime' => ''];
        }
        return ['document' => $doc, 'media' => $media];
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param array{sec: int, blk: int} $counter
     * @return list<array<string, mixed>>
     */
    private function blocks(array $nodes, array &$counter): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $block = $this->block($node, $counter);
            if ($block !== null) {
                $out[] = $block;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $node
     * @param array{sec: int, blk: int} $counter
     * @return ?array<string, mixed>
     */
    private function block(array $node, array &$counter): ?array
    {
        $children = [];
        [$type, $props] = match ($node['type']) {
            'heading'  => ['core.heading', ['text' => $node['text']['text'], 'level' => $node['text']['level']]],
            'rich'     => ['core.rich_text', ['content' => $node['text']['html']]],
            'image'    => ['core.image', array_filter([
                'media'   => ['media_id' => $this->mediaKeys[$node['media']['path']], 'alt' => $node['media']['alt']],
                'caption' => $node['text']['caption'],
            ], static fn($v): bool => $v !== '')],
            'button'   => ['core.button', ['link' => $node['link'], 'variant' => $node['text']['variant']]],
            'container'=> ['core.container', ['gap' => $node['layout']['gap'], 'direction' => $node['layout']['direction']]],
            'features' => ['core.feature_list', [
                'columns' => $node['layout']['columns'],
                'items'   => array_map(static fn(array $c): array => array_filter(['heading' => $c['heading'], 'body' => $c['body'], 'url' => $c['url']], static fn($v): bool => $v !== null && $v !== ''), $node['text']['items']),
            ]],
            'hero'     => ['core.hero', array_filter([
                'eyebrow'     => $node['text']['eyebrow'],
                'heading'     => $node['text']['heading'],
                'subheading'  => $node['text']['subheading'],
                'primary_cta' => $node['link'],
                'media'       => $node['media'] !== null ? ['media_id' => $this->mediaKeys[$node['media']['path']], 'alt' => $node['media']['alt']] : null,
            ], static fn($v): bool => $v !== null && $v !== '')],
        };
        if ($type === 'core.rich_text' && $props['content'] === '') {
            return null;
        }
        $id = sprintf('blk_%024d', ++$counter['blk']);
        if ($node['type'] === 'container') {
            $children = $this->blocks($node['children'], $counter);
        }
        $style = CanonicalDocumentSchema::defaultBlockStyle();
        foreach ($node['style'] as $k => $v) {
            $style[$k] = $k === 'align' ? ['base' => $v] : $v;
        }
        return [
            'id'         => $id,
            'type'       => $type,
            'version'    => 1,
            'props'      => $props,
            'style'      => $style,
            'visibility' => ['auth_state' => 'any', 'devices' => $node['visibility'] ?? self::ALL_DEVICES],
            'bindings'   => [],
            'children'   => $children,
        ];
    }

    // ── Tree helpers ─────────────────────────────────────────────────────────

    /** @return list<int> */
    private function children(int $id): array
    {
        return $this->nodes[$id]['children'] ?? [];
    }

    private function isElement(int $id): bool
    {
        return $this->nodes[$id]['tag'] !== '#text';
    }

    private function tag(int $id): string
    {
        return (string) $this->nodes[$id]['tag'];
    }

    private function loc(int $id): string
    {
        return (string) ($this->nodes[$id]['loc'] ?? 'html:');
    }

    /** @param list<int> $items */
    private function locOfItems(array $items): string
    {
        foreach ($items as $id) {
            if ($this->isElement($id)) {
                return $this->loc($id);
            }
        }
        $parent = (int) ($this->nodes[$items[0] ?? 0]['parent'] ?? -1);
        return $parent >= 0 ? $this->loc($parent) : 'html:';
    }

    /** @return array<string, string> */
    private function decls(int $id): array
    {
        return $this->computed[$id]['decls'] ?? [];
    }

    /** Partial device visibility (null = everywhere). */
    private function visibility(int $id): ?array
    {
        $visible = $this->computed[$id]['visible'] ?? self::ALL_DEVICES;
        return $visible === self::ALL_DEVICES || count($visible) === count(self::ALL_DEVICES) ? null : $visible;
    }

    private function isHidden(int $id): bool
    {
        return $this->isElement($id) && ($this->computed[$id]['visible'] ?? self::ALL_DEVICES) === [];
    }

    /** True (and reported once) when the element is hidden on every device. */
    private function skipHidden(int $id): bool
    {
        if (!$this->isHidden($id)) {
            return false;
        }
        $parent = (int) ($this->nodes[$id]['parent'] ?? -1);
        if (!isset($this->hiddenReported[$id]) && !($parent >= 0 && $this->isHidden($parent))) {
            $this->hiddenReported[$id] = true;
            $this->stats['hidden_dropped']++;
            $this->issues->warning('content_dropped_hidden', $this->loc($id), 'Content hidden with display:none was not imported.');
        }
        return true;
    }

    /** @param list<int> $items @return list<int> non-whitespace text and visible elements */
    private function significant(array $items): array
    {
        return array_values(array_filter($items, fn(int $id): bool => $this->isElement($id)
            ? !$this->isHidden($id)
            : trim((string) $this->nodes[$id]['text']) !== ''));
    }

    private function isContentLevel(string $tag): bool
    {
        return in_array($tag, self::HEADINGS, true) || in_array($tag, self::TEXT_BLOCKS, true)
            || in_array($tag, self::INLINE, true) || in_array($tag, self::MEDIA, true);
    }

    private function hasSectioningChild(int $id): bool
    {
        foreach ($this->children($id) as $c) {
            if ($this->isElement($c) && in_array($this->tag($c), self::SECTIONING, true) && !$this->isHidden($c)) {
                return true;
            }
        }
        return false;
    }

    private function isFlexOrGrid(int $id): bool
    {
        $display = strtolower(trim($this->decls($id)['display'] ?? ''));
        return in_array($display, ['flex', 'inline-flex', 'grid', 'inline-grid'], true);
    }

    private function isButtonLike(int $id, bool $sole): bool
    {
        $n = $this->nodes[$id];
        if (!isset($n['attrs']['href'])) {
            return false;
        }
        if (strtolower(trim((string) ($n['attrs']['role'] ?? ''))) === 'button') {
            return true;
        }
        foreach ($n['classes'] as $class) {
            if (preg_match('/^(btn|button|cta)([-_].*)?$/i', (string) $class) === 1) {
                return true;
            }
        }
        return $sole;
    }

    private function hasMediaDescendant(int $id): bool
    {
        foreach (['img', 'picture', 'figure'] as $tag) {
            if ($this->descendants($id, $tag) !== []) {
                return true;
            }
        }
        return false;
    }

    private function hasBlockDescendant(int $id): bool
    {
        $stack = $this->children($id);
        while ($stack !== []) {
            $c = array_pop($stack);
            if (!$this->isElement($c)) {
                continue;
            }
            if (in_array($this->tag($c), [...self::HEADINGS, ...self::TEXT_BLOCKS], true)) {
                return true;
            }
            array_push($stack, ...$this->children($c));
        }
        return false;
    }

    /** @return list<int> visible descendants with this tag, in document order */
    private function descendants(int $id, string $tag): array
    {
        $out = [];
        $stack = array_reverse($this->children($id));
        while ($stack !== []) {
            $c = array_pop($stack);
            if (!$this->isElement($c) || $this->isHidden($c)) {
                continue;
            }
            if ($this->tag($c) === $tag) {
                $out[] = $c;
            }
            foreach (array_reverse($this->children($c)) as $cc) {
                $stack[] = $cc;
            }
        }
        return $out;
    }

    private function firstDescendant(int $id, string $tag): ?int
    {
        return $this->descendants($id, $tag)[0] ?? null;
    }

    /** Visible text of an element, whitespace-collapsed. */
    private function plainText(int $id): string
    {
        $parts = [];
        $stack = array_reverse($this->children($id));
        while ($stack !== []) {
            $c = array_pop($stack);
            if (!$this->isElement($c)) {
                $parts[] = (string) $this->nodes[$c]['text'];
                continue;
            }
            if ($this->isHidden($c) || in_array($this->tag($c), self::MEDIA, true)) {
                continue;
            }
            if ($this->tag($c) === 'br') {
                $parts[] = ' ';
                continue;
            }
            foreach (array_reverse($this->children($c)) as $cc) {
                $stack[] = $cc;
            }
        }
        return trim((string) preg_replace('/\s+/u', ' ', implode('', $parts)));
    }

    private function fitText(string $text, int $max, string $loc): string
    {
        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }
        $this->issues->warning('text_truncated', $loc, "A text was longer than {$max} characters and was shortened.");
        return rtrim(mb_substr($text, 0, $max, 'UTF-8'));
    }

    private function unsafeText(string $loc, string $what): void
    {
        $this->stats['unsafe_text_dropped']++;
        $this->issues->warning('unsafe_text_dropped', $loc, "A {$what} text was left out because Studio's content safety check rejected it (it can contain words that look like code or SQL). Re-type it in the builder if needed.", $what);
    }

    /** @param list<array<string, mixed>> $nodes */
    private function sectionLabel(array $nodes): string
    {
        foreach ($nodes as $node) {
            $text = match ($node['type']) {
                'heading' => $node['text']['text'],
                'hero'    => $node['text']['heading'],
                default   => null,
            };
            if (is_string($text) && $text !== '') {
                $label = mb_substr($text, 0, 120, 'UTF-8');
                return FieldSchema::containsExecutableOrSqlFragment($label) ? '' : $label;
            }
        }
        return '';
    }
}
