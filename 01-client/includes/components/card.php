<?php
/**
 * Slate Component — Card (slate_c_card).
 *
 * A surface panel styled by .slate-card (components.css, --slate-* semantics).
 * The `body` is a SLOT: already-rendered child HTML, passed through verbatim
 * (a Component composes children by concatenation; it never re-escapes a child).
 * The `title` is plain text and IS escaped here.
 *
 * Props:
 *   title  string  optional heading text (escaped)
 *   body   string  child HTML (slot — passed through)
 *   tone   string  surface | sunken  (default surface)
 *   attrs  array   extra passthrough attributes (escaped)
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

if (!function_exists('slate_c_card')) {
    function slate_c_card(array $props = []): string
    {
        $title = (string) ($props['title'] ?? '');
        $body  = (string) ($props['body'] ?? '');
        $tone  = ($props['tone'] ?? 'surface') === 'sunken' ? 'sunken' : 'surface';
        $attrs = is_array($props['attrs'] ?? null) ? $props['attrs'] : [];

        $class = slate_c_classes(['slate-card', "slate-card--{$tone}"]);
        $head  = $title !== '' ? '<h3 class="slate-card__title">' . slate_c_e($title) . '</h3>' : '';

        return '<article class="' . $class . '"' . slate_c_attrs($attrs) . '>'
            . $head
            . '<div class="slate-card__body">' . $body . '</div>'
            . '</article>';
    }
}
