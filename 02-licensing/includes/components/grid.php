<?php
/**
 * Slate Component — Grid (slate_c_grid).
 *
 * A responsive column grid styled by .slate-grid (components.css, --slate-*
 * tokens; collapses to one column on narrow screens). `items` is a list of SLOTS
 * (already-rendered child HTML), passed through and concatenated.
 *
 * Props:
 *   items  string[]  child HTML fragments (slots — passed through)
 *   cols   int       1..4 columns (default 3)
 *   attrs  array     extra passthrough attributes (escaped)
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

if (!function_exists('slate_c_grid')) {
    function slate_c_grid(array $props = []): string
    {
        $items = is_array($props['items'] ?? null) ? $props['items'] : [];
        $cols  = (int) ($props['cols'] ?? 3);
        $cols  = $cols < 1 ? 1 : ($cols > 4 ? 4 : $cols);
        $attrs = is_array($props['attrs'] ?? null) ? $props['attrs'] : [];

        $class = slate_c_classes(['slate-grid', "slate-grid--cols-{$cols}"]);
        $inner = '';
        foreach ($items as $item) {
            $inner .= '<div class="slate-grid__cell">' . (string) $item . '</div>';
        }

        return '<div class="' . $class . '"' . slate_c_attrs($attrs) . '>' . $inner . '</div>';
    }
}
