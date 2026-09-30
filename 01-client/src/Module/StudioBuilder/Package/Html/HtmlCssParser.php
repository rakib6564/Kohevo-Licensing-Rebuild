<?php
/**
 * Kohevo Studio (studio-builder) — Phase 8B: bounded CSS subset parser.
 *
 * NOT a CSS engine. A small, linear, budgeted tokenizer that extracts only
 * the evidence the importer maps onto canonical enums and registry tokens.
 * Nothing it reads is ever persisted.
 *
 *   selectors  type, .class, #id, and compounds of those (`div.card.x`), plus
 *              `:root` for custom properties. Combinators, attribute
 *              selectors, pseudo-classes/elements and `*` drop the selector
 *              (`unsupported_css`).
 *   cascade    specificity (id > class > type), then source order; inline
 *              `style=""` evidence wins. `!important` is ignored (no second
 *              priority model). text-align / color / font-family inherit.
 *   at-rules   only `@media` with exact Studio-breakpoint width conditions,
 *              and inside it only `display`. Every other at-rule (@import,
 *              @font-face, @keyframes, @supports, @layer, @container, @page,
 *              @namespace, @charset, …) is dropped and reported.
 *   values     `url(`, `expression(`, `javascript:`… and the forbidden
 *              properties (position except static, z-index, transform,
 *              animation*, transition*, filter, backdrop-filter,
 *              -moz-binding, behavior) are dropped and reported. Any
 *              backslash (escape smuggling) fails closed: the rule or
 *              declaration is dropped. `var(--x)` resolves ONE level from
 *              literal `:root` custom properties; anything else is unmapped.
 *   budgets    ≤ 2,000 rules, ≤ 5,000 declarations, ≤ 64 per rule,
 *              selectors ≤ 256 chars, values ≤ 256 chars, inline ≤ 32 —
 *              exceeding one is a fatal `source_limit_exceeded`.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Package\Html;

final class HtmlCssParser
{
    public const MAX_RULES         = 2000;
    public const MAX_DECLARATIONS  = 5000;
    public const MAX_PER_RULE      = 64;
    public const MAX_SELECTOR      = 256;
    public const MAX_VALUE         = 256;
    public const MAX_INLINE        = 32;
    public const MAX_VARS          = 200;

    /** Properties the importer can map (anything else is ignored, never stored). */
    public const MAPPED = [
        'text-align', 'padding', 'padding-top', 'padding-bottom', 'gap', 'row-gap', 'column-gap', 'grid-gap',
        'max-width', 'display', 'grid-template-columns', 'flex-direction', 'background-color', 'background',
        'color', 'border-radius', 'box-shadow', 'font-family',
    ];

    public const INHERITED = ['text-align', 'color', 'font-family'];

    /** Forbidden properties (vendor prefix removed; prefixes match *-anything). */
    private const FORBIDDEN = ['binding', 'behavior', 'z-index', 'transform', 'filter', 'backdrop-filter', 'animation', 'transition', 'will-change'];
    private const FORBIDDEN_PREFIXES = ['animation-', 'transition-', 'transform-'];

    /** Studio breakpoint buckets: [start px, end px) — StudioStylesheet: base < 640 <= sm < 768 <= md < 1024 <= lg. */
    public const BUCKETS = ['base' => [0, 640], 'sm' => [640, 768], 'md' => [768, 1024], 'lg' => [1024, PHP_INT_MAX]];

    /** @var list<array{sel: array{tag: ?string, classes: list<string>, ids: list<string>}, spec: int, order: int, decls: array<string, string>}> */
    private array $rules = [];

    /** @var list<array{sel: array{tag: ?string, classes: list<string>, ids: list<string>}, spec: int, order: int, buckets: list<string>, display: string}> */
    private array $media = [];

    /** @var array<string, list<int>> */
    private array $index = [];

    /** @var array<string, list<int>> */
    private array $mediaIndex = [];

    /** @var array<string, string> */
    private array $vars = [];

    private int $order = 0;
    private int $rulesSeen = 0;
    private int $declarations = 0;
    private int $applied = 0;
    private int $dropped = 0;
    private int $ignored = 0;
    private int $important = 0;
    private bool $fatal = false;

    public function __construct(private readonly HtmlImportIssues $issues) {}

    public function failed(): bool
    {
        return $this->fatal;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return [
            'css_rules_seen'           => $this->rulesSeen,
            'css_declarations_seen'    => $this->declarations,
            'css_declarations_applied' => $this->applied,
            'css_dropped'              => $this->dropped,
            'css_ignored'              => $this->ignored,
            'css_important_ignored'    => $this->important,
        ];
    }

    // ── Stylesheets ──────────────────────────────────────────────────────────

    public function parseSheet(string $css): bool
    {
        $css = self::stripComments($css);
        $this->parseRules($css, null, 0);
        return !$this->fatal;
    }

    /** @param ?list<string> $media the buckets of an enclosing @media, or null at top level */
    private function parseRules(string $css, ?array $media, int $nesting): void
    {
        $i = 0;
        $n = strlen($css);
        while ($i < $n && !$this->fatal) {
            while ($i < $n && ctype_space($css[$i])) {
                $i++;
            }
            if ($i >= $n) {
                break;
            }
            if ($css[$i] === '}' || $css[$i] === ';') {
                $i++;
                continue;
            }
            [$prelude, $term, $i] = self::scanPrelude($css, $i);
            $prelude = trim($prelude);
            $body = '';
            $nested = false;
            if ($term === '{') {
                [$body, $nested, $i] = self::scanBlock($css, $i);
            }
            if ($prelude === '' && $term !== '{') {
                continue;
            }
            if (++$this->rulesSeen > self::MAX_RULES) {
                $this->fail('css:', 'The stylesheet has more than ' . self::MAX_RULES . ' rules.');
                return;
            }
            $loc = 'css:rule[' . $this->rulesSeen . ']';
            if (str_starts_with($prelude, '@')) {
                $name = strtolower((string) preg_replace('/^@([a-zA-Z-]*).*$/s', '$1', $prelude));
                if ($name === 'media' && $term === '{' && $media === null && $nesting === 0) {
                    $this->parseMedia(substr($prelude, 6), $body, $loc);
                } else {
                    $this->drop($loc, 'The @' . $name . ' rule is not supported; it was ignored.', '@' . $name);
                }
                continue;
            }
            if ($term !== '{') {
                $this->dropped++;
                continue;
            }
            $this->parseRule($prelude, $body, $nested, $media, $loc);
        }
    }

    private function parseMedia(string $query, string $body, string $loc): void
    {
        $buckets = self::mediaBuckets($query);
        if ($buckets === null) {
            $this->drop($loc, 'Only @media rules on exact Studio breakpoints (640px / 768px / 1024px) are supported; this one was ignored.', 'media_query');
            return;
        }
        $this->parseRules($body, $buckets, 1);
    }

    /** @param ?list<string> $media */
    private function parseRule(string $prelude, string $body, bool $nested, ?array $media, string $loc): void
    {
        if (strlen($prelude) > self::MAX_SELECTOR) {
            $this->fail($loc, 'A selector is longer than ' . self::MAX_SELECTOR . ' characters.');
            return;
        }
        if (str_contains($prelude, '\\')) {
            $this->drop($loc, 'Escaped selectors are not supported; the rule was ignored.', 'escape');
            return;
        }
        if ($nested) {
            $this->drop($loc, 'Nested rules are not supported; the rule was ignored.', 'nesting');
            return;
        }

        $selectors = [];
        $root = false;
        foreach (explode(',', $prelude) as $raw) {
            $raw = trim($raw);
            if ($raw === ':root') {
                $root = true;
                continue;
            }
            $sel = self::selector($raw);
            if ($sel === null) {
                $this->drop($loc, 'Only simple type, class and id selectors are supported; a selector was ignored.', self::selectorProblem($raw));
                continue;
            }
            $selectors[] = $sel;
        }

        $decls = self::splitDeclarations($body);
        if (count($decls) > self::MAX_PER_RULE) {
            $this->fail($loc, 'A rule has more than ' . self::MAX_PER_RULE . ' declarations.');
            return;
        }
        $mapped = [];
        foreach ($decls as $raw) {
            $d = $this->declaration($raw, $loc, $root && $media === null);
            if ($this->fatal) {
                return;
            }
            if ($d !== null) {
                $mapped[$d[0]] = $d[1];
            }
        }
        if ($selectors === [] || $mapped === []) {
            return;
        }

        if ($media !== null) {
            $display = $mapped['display'] ?? null;
            if (count($mapped) > ($display === null ? 0 : 1)) {
                $this->drop($loc, 'Inside @media only display is supported; other declarations were ignored.', 'media_declaration');
            }
            if ($display === null) {
                return;
            }
            foreach ($selectors as $sel) {
                $this->media[] = ['sel' => $sel, 'spec' => self::specificity($sel), 'order' => $this->order++, 'buckets' => $media, 'display' => strtolower($display)];
                $this->mediaIndex[self::indexKey($sel)][] = count($this->media) - 1;
            }
            return;
        }
        foreach ($selectors as $sel) {
            $this->rules[] = ['sel' => $sel, 'spec' => self::specificity($sel), 'order' => $this->order++, 'decls' => $mapped];
            $this->index[self::indexKey($sel)][] = count($this->rules) - 1;
        }
    }

    /**
     * Inline `style=""` evidence. Null only on a fatal budget error.
     *
     * @return ?array<string, string>
     */
    public function parseInline(string $style, string $loc): ?array
    {
        $decls = self::splitDeclarations(self::stripComments($style));
        if (count($decls) > self::MAX_INLINE) {
            $this->fail($loc, 'An inline style has more than ' . self::MAX_INLINE . ' declarations.');
            return null;
        }
        $out = [];
        foreach ($decls as $raw) {
            $d = $this->declaration($raw, $loc, false);
            if ($this->fatal) {
                return null;
            }
            if ($d !== null) {
                $out[$d[0]] = $d[1];
            }
        }
        return $out;
    }

    /** @return ?array{0: string, 1: string} */
    private function declaration(string $raw, string $loc, bool $root): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (++$this->declarations > self::MAX_DECLARATIONS) {
            $this->fail($loc, 'The stylesheet has more than ' . self::MAX_DECLARATIONS . ' declarations.');
            return null;
        }
        $colon = strpos($raw, ':');
        if ($colon === false) {
            $this->dropped++;
            return null;
        }
        $prop  = strtolower(trim(substr($raw, 0, $colon)));
        $value = trim(substr($raw, $colon + 1));
        if (strlen($value) > self::MAX_VALUE) {
            $this->fail($loc, 'A CSS value is longer than ' . self::MAX_VALUE . ' characters.');
            return null;
        }
        if (str_contains($raw, '\\')) {
            $this->drop($loc, 'Escaped CSS is not supported; a declaration was ignored.', 'escape');
            return null;
        }
        if (preg_match('/!\s*important\s*$/i', $value) === 1) {
            $this->important++;
            $value = trim((string) preg_replace('/!\s*important\s*$/i', '', $value));
        }
        $lower = strtolower($value);
        foreach (['url(', 'expression(', 'javascript:', 'vbscript:', '@import', 'image-set(', 'element('] as $needle) {
            if (str_contains(str_replace(' ', '', $lower), $needle)) {
                $this->drop($loc, 'A CSS value referencing external or executable content was ignored.', 'value');
                return null;
            }
        }

        if (str_starts_with($prop, '--')) {
            if ($root && preg_match('/^--[A-Za-z0-9_-]{1,64}$/', $prop) === 1 && !str_contains($lower, 'var(') && count($this->vars) < self::MAX_VARS) {
                $this->vars[$prop] = $value;
            } else {
                $this->ignored++;
            }
            return null;
        }
        if (preg_match('/^-?[a-z][a-z0-9-]{0,63}$/', $prop) !== 1) {
            $this->dropped++;
            return null;
        }
        $base = (string) preg_replace('/^-(webkit|moz|ms|o)-/', '', $prop);
        $forbidden = in_array($base, self::FORBIDDEN, true) || ($base === 'position' && $lower !== 'static');
        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            $forbidden = $forbidden || str_starts_with($base, $prefix);
        }
        if ($forbidden) {
            $this->drop($loc, "The CSS property '{$base}' is not supported; it was ignored.", 'property:' . $base);
            return null;
        }
        if (!in_array($prop, self::MAPPED, true)) {
            $this->ignored++;
            return null;
        }
        $this->applied++;
        return [$prop, $value];
    }

    // ── Cascade ──────────────────────────────────────────────────────────────

    /**
     * The winning mapped declarations for one element (inline not included)
     * and the @media display rules that match it, in cascade order.
     *
     * @param list<string> $classes
     * @return array{decls: array<string, string>, media: list<array{buckets: list<string>, display: string}>}
     */
    public function match(string $tag, array $classes, ?string $id): array
    {
        $keys = [$tag];
        foreach ($classes as $c) {
            $keys[] = '.' . $c;
        }
        if ($id !== null) {
            $keys[] = '#' . $id;
        }
        $hits = [];
        $mediaHits = [];
        foreach ($keys as $key) {
            foreach ($this->index[$key] ?? [] as $r) {
                $hits[$r] = true;
            }
            foreach ($this->mediaIndex[$key] ?? [] as $r) {
                $mediaHits[$r] = true;
            }
        }
        $matched = [];
        foreach (array_keys($hits) as $r) {
            if (self::matches($this->rules[$r]['sel'], $tag, $classes, $id)) {
                $matched[] = $this->rules[$r];
            }
        }
        usort($matched, static fn(array $a, array $b): int => [$a['spec'], $a['order']] <=> [$b['spec'], $b['order']]);
        $decls = [];
        foreach ($matched as $rule) {
            foreach ($rule['decls'] as $p => $v) {
                $decls[$p] = $v;
            }
        }
        $media = [];
        foreach (array_keys($mediaHits) as $r) {
            if (self::matches($this->media[$r]['sel'], $tag, $classes, $id)) {
                $media[] = $this->media[$r];
            }
        }
        usort($media, static fn(array $a, array $b): int => [$a['spec'], $a['order']] <=> [$b['spec'], $b['order']]);
        return ['decls' => $decls, 'media' => array_map(static fn(array $m): array => ['buckets' => $m['buckets'], 'display' => $m['display']], $media)];
    }

    /** A value with a whole-value `var(--x)` resolved ONE level; null when it cannot be resolved. */
    public function resolveVar(string $value): ?string
    {
        if (!str_contains(strtolower($value), 'var(')) {
            return $value;
        }
        if (preg_match('/^var\(\s*(--[A-Za-z0-9_-]{1,64})\s*\)$/', trim($value), $m) === 1 && isset($this->vars[$m[1]])) {
            return $this->vars[$m[1]];
        }
        return null;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function drop(string $loc, string $message, string $detail): void
    {
        $this->dropped++;
        $this->issues->warning('unsupported_css', $loc, $message, $detail);
    }

    private function fail(string $loc, string $message): void
    {
        $this->fatal = true;
        $this->issues->error('source_limit_exceeded', $loc, $message, 'css');
    }

    public static function stripComments(string $css): string
    {
        return (string) preg_replace('~/\*.*?(?:\*/|\z)~s', ' ', $css);
    }

    /** @return array{0: string, 1: string, 2: int} [prelude, terminator ('{', ';' or ''), next index] */
    private static function scanPrelude(string $css, int $i): array
    {
        $n = strlen($css);
        $start = $i;
        $quote = null;
        $depth = 0;
        for (; $i < $n; $i++) {
            $c = $css[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && ($c === '{' || $c === ';')) {
                return [substr($css, $start, $i - $start), $c, $i + 1];
            } elseif ($depth === 0 && $c === '}') {
                return [substr($css, $start, $i - $start), '', $i + 1];
            }
        }
        return [substr($css, $start), '', $n];
    }

    /** @return array{0: string, 1: bool, 2: int} [block body, contains a nested block, next index] */
    private static function scanBlock(string $css, int $i): array
    {
        $n = strlen($css);
        $start = $i;
        $depth = 1;
        $quote = null;
        $nested = false;
        for (; $i < $n; $i++) {
            $c = $css[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '{') {
                $depth++;
                $nested = true;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return [substr($css, $start, $i - $start), $nested, $i + 1];
                }
            }
        }
        return [substr($css, $start), $nested, $n];
    }

    /** @return list<string> */
    private static function splitDeclarations(string $body): array
    {
        $out = [];
        $quote = null;
        $depth = 0;
        $start = 0;
        $n = strlen($body);
        for ($i = 0; $i < $n; $i++) {
            $c = $body[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($c === ';' && $depth === 0) {
                $out[] = substr($body, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $out[] = substr($body, $start);
        return array_values(array_filter($out, static fn(string $d): bool => trim($d) !== ''));
    }

    /** @return ?array{tag: ?string, classes: list<string>, ids: list<string>} */
    public static function selector(string $raw): ?array
    {
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9-]*)?((?:[.#][A-Za-z_][A-Za-z0-9_-]{0,63})*)$/', $raw, $m) !== 1 || $raw === '') {
            return null;
        }
        $classes = [];
        $ids = [];
        if (($m[2] ?? '') !== '' && preg_match_all('/([.#])([A-Za-z_][A-Za-z0-9_-]*)/', $m[2], $parts, PREG_SET_ORDER)) {
            foreach ($parts as $p) {
                if ($p[1] === '.') {
                    $classes[] = $p[2];
                } else {
                    $ids[] = $p[2];
                }
            }
        }
        return ['tag' => ($m[1] ?? '') !== '' ? strtolower($m[1]) : null, 'classes' => $classes, 'ids' => $ids];
    }

    private static function selectorProblem(string $raw): string
    {
        return match (true) {
            str_contains($raw, '::')                    => 'pseudo_element',
            str_contains($raw, ':')                     => 'pseudo_class',
            str_contains($raw, '[')                     => 'attribute_selector',
            str_contains($raw, '*')                     => 'universal_selector',
            preg_match('/[\s>+~]/', $raw) === 1         => 'combinator',
            default                                     => 'selector',
        };
    }

    /** @param array{tag: ?string, classes: list<string>, ids: list<string>} $sel */
    private static function specificity(array $sel): int
    {
        return count($sel['ids']) * 10000 + count($sel['classes']) * 100 + ($sel['tag'] !== null ? 1 : 0);
    }

    /** @param array{tag: ?string, classes: list<string>, ids: list<string>} $sel */
    private static function indexKey(array $sel): string
    {
        if ($sel['ids'] !== []) {
            return '#' . $sel['ids'][0];
        }
        if ($sel['classes'] !== []) {
            return '.' . $sel['classes'][0];
        }
        return (string) $sel['tag'];
    }

    /**
     * @param array{tag: ?string, classes: list<string>, ids: list<string>} $sel
     * @param list<string> $classes
     */
    private static function matches(array $sel, string $tag, array $classes, ?string $id): bool
    {
        if ($sel['tag'] !== null && $sel['tag'] !== $tag) {
            return false;
        }
        foreach ($sel['ids'] as $want) {
            if ($want !== $id) {
                return false;
            }
        }
        foreach ($sel['classes'] as $want) {
            if (!in_array($want, $classes, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The Studio breakpoint buckets an @media query covers EXACTLY, or null.
     * Accepted: optional `screen|all` media type, then `(min-width: Npx)`
     * and/or `(max-width: Npx)`, where min is a bucket start (640/768/1024)
     * and max lies in [boundary-1, boundary) of a bucket end.
     *
     * @return ?list<string>
     */
    public static function mediaBuckets(string $query): ?array
    {
        $q = strtolower(trim((string) preg_replace('/\s+/', ' ', $query)));
        $q = (string) preg_replace('/^(only )?(screen|all) and /', '', $q);
        if (preg_match('/^\(\s*(min|max)-width\s*:\s*([0-9]+(?:\.[0-9]+)?)px\s*\)(?: and \(\s*(min|max)-width\s*:\s*([0-9]+(?:\.[0-9]+)?)px\s*\))?$/', $q, $m) !== 1) {
            return null;
        }
        $min = 0.0;
        $max = null;
        foreach ([[$m[1], $m[2]], [$m[3] ?? '', $m[4] ?? '']] as [$kind, $value]) {
            if ($kind === 'min') {
                $min = (float) $value;
            } elseif ($kind === 'max') {
                $max = (float) $value;
            }
        }
        if (!in_array($min, [0.0, 640.0, 768.0, 1024.0], true)) {
            return null;
        }
        $end = PHP_INT_MAX;
        if ($max !== null) {
            $end = null;
            foreach ([640, 768, 1024] as $boundary) {
                if ($max >= $boundary - 1 && $max < $boundary) {
                    $end = $boundary;
                }
            }
            if ($end === null) {
                return null;
            }
        }
        $out = [];
        foreach (self::BUCKETS as $bucket => [$start, $stop]) {
            if ($start >= $min && $stop <= $end) {
                $out[] = $bucket;
            }
        }
        return $out === [] ? null : $out;
    }
}
