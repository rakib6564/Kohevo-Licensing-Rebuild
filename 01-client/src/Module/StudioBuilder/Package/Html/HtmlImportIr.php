<?php
/**
 * Kohevo Studio (studio-builder) — Phase 8B: the in-memory import IR.
 *
 * Between the HTML/CSS evidence and the canonical document sits a small,
 * DATA-ONLY intermediate representation (plain arrays of scalars — no
 * objects, closures, source HTML, raw CSS, paths other than the site-relative
 * `/uploads/…` media hint, or environment data):
 *
 *   section  { loc, label, layout: {padding_y?, gap?, width?, background_token?},
 *              visibility: ?list<breakpoint>, nodes: list<node> }
 *   node     { type: heading|rich|image|button|container|hero|features, loc,
 *              text:   {…}   semantic text (plain strings; `rich.html` is
 *                             converter-built allowlisted markup)
 *              link:   ?{label, href, target}   (isSafeUrl-checked)
 *              media:  ?{path: '/uploads/…', alt}
 *              layout: {direction?, gap?, columns?}
 *              style:  {align?, surface_token?, …}   registry token refs only
 *              visibility: ?list<breakpoint>
 *              children: list<node> }
 *
 * `check()` enforces the bounds before anything is mapped: ≤ 2,000 nodes,
 * depth ≤ 8, strings ≤ 50,000 characters, ≤ 250 children per node, and that
 * the whole structure is plain data.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Package\Html;

final class HtmlImportIr
{
    public const MAX_NODES    = 2000;
    public const MAX_DEPTH    = 8;
    public const MAX_STRING   = 50000;
    public const MAX_CHILDREN = 250;

    public const NODE_TYPES = ['heading', 'rich', 'image', 'button', 'container', 'hero', 'features'];

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function node(string $type, string $loc, array $fields = []): array
    {
        return [
            'type'       => $type,
            'loc'        => $loc,
            'text'       => $fields['text'] ?? [],
            'link'       => $fields['link'] ?? null,
            'media'      => $fields['media'] ?? null,
            'layout'     => $fields['layout'] ?? [],
            'style'      => $fields['style'] ?? [],
            'visibility' => $fields['visibility'] ?? null,
            'children'   => $fields['children'] ?? [],
        ];
    }

    /**
     * The first bound the IR violates, or null.
     *
     * @param list<array<string, mixed>> $sections
     */
    public static function check(array $sections): ?string
    {
        $count = 0;
        $stack = [];
        foreach ($sections as $section) {
            if (count($section['nodes'] ?? []) > self::MAX_CHILDREN) {
                return 'A section holds more than ' . self::MAX_CHILDREN . ' imported nodes.';
            }
            foreach ($section['nodes'] ?? [] as $node) {
                $stack[] = [$node, 1];
            }
            if (($problem = self::plainData($section, 0)) !== null) {
                return $problem;
            }
        }
        while ($stack !== []) {
            [$node, $depth] = array_pop($stack);
            if (++$count > self::MAX_NODES) {
                return 'The page produced more than ' . self::MAX_NODES . ' import nodes.';
            }
            if ($depth > self::MAX_DEPTH) {
                return 'The page structure is nested more than ' . self::MAX_DEPTH . ' levels deep.';
            }
            if (!in_array($node['type'] ?? null, self::NODE_TYPES, true)) {
                return 'Unknown import node type.';
            }
            $children = $node['children'] ?? [];
            if (count($children) > self::MAX_CHILDREN) {
                return 'A structure holds more than ' . self::MAX_CHILDREN . ' children.';
            }
            foreach ($children as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }
        return null;
    }

    /** Scalars and arrays only; every string within the size bound. */
    private static function plainData(mixed $value, int $depth): ?string
    {
        $stack = [[$value, $depth]];
        while ($stack !== []) {
            [$v, $d] = array_pop($stack);
            if (is_string($v)) {
                if (mb_strlen($v, 'UTF-8') > self::MAX_STRING) {
                    return 'An imported text is longer than ' . self::MAX_STRING . ' characters.';
                }
            } elseif (is_array($v)) {
                if ($d > 64) {
                    return 'The page structure is too deep.';
                }
                foreach ($v as $item) {
                    $stack[] = [$item, $d + 1];
                }
            } elseif ($v !== null && !is_int($v) && !is_float($v) && !is_bool($v)) {
                return 'The import structure must be plain data.';
            }
        }
        return null;
    }
}
