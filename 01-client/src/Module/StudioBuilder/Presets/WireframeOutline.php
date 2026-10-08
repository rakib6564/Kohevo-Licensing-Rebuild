<?php
/**
 * Kohevo Studio (studio-builder) — structural outline of a document for wireframe thumbnails.
 *
 * Pure. The Add panel draws a small SVG wireframe of each preset from this outline, so a
 * preset needs no image asset. It carries structure only: block types, how many repeater
 * items or columns a block shows, alignment, whether it is drawn as a card, and children.
 * Never any copy, URL or media reference.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Presets;

final class WireframeOutline
{
    /** Cap on outline nodes per document (the library view stays small). */
    public const MAX_NODES = 60;

    /**
     * @param array<string, mixed> $document A canonical document.
     * @return list<array{bg: string, nodes: list<array<string, mixed>>}>
     */
    public static function fromDocument(array $document): array
    {
        $budget = self::MAX_NODES;
        $outline = [];
        foreach ((array) ($document['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $outline[] = [
                'bg'    => (string) (($section['layout']['background_token'] ?? '') ?: ''),
                'nodes' => self::nodes(is_array($section['blocks'] ?? null) ? $section['blocks'] : [], $budget),
            ];
        }
        return $outline;
    }

    /**
     * @param list<mixed> $blocks
     * @return list<array<string, mixed>>
     */
    private static function nodes(array $blocks, int &$budget): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block) || $budget <= 0) {
                continue;
            }
            $budget--;
            $children = self::nodes(is_array($block['children'] ?? null) ? $block['children'] : [], $budget);
            $out[] = self::node($block, $children);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $block
     * @param list<array<string, mixed>> $children
     * @return array<string, mixed>
     */
    private static function node(array $block, array $children): array
    {
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];
        $style = is_array($block['style'] ?? null) ? $block['style'] : [];
        $node  = ['t' => (string) ($block['type'] ?? '')];
        foreach (['items', 'slides', 'images', 'tabs', 'stats'] as $list) {
            if (is_array($props[$list] ?? null)) {
                $node['n'] = count($props[$list]);
                break;
            }
        }
        if (is_array($props['items'] ?? null)) {
            foreach ($props['items'] as $item) {
                if (is_array($item) && !empty($item['url'])) {
                    $node['u'] = 1; // some items link somewhere (drawn as a small arrow)
                    break;
                }
            }
        }
        if (isset($props['columns']) && is_numeric($props['columns'])) {
            $node['c'] = max(1, min(6, (int) $props['columns']));
        }
        if (is_string($props['level'] ?? null)) {
            $node['l'] = $props['level'];
        }
        if (is_string($props['field_type'] ?? null)) {
            $node['f'] = $props['field_type'];
        }
        $align = $style['align']['base'] ?? null;
        if (is_string($align) && $align !== 'left') {
            $node['a'] = $align;
        }
        if (!empty($style['surface_token'])) {
            $node['s'] = 1;
        }
        if ($children !== []) {
            $node['k'] = $children;
        }
        return $node;
    }
}
