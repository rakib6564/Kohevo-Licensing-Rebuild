<?php
/**
 * Slate Component — Button (slate_c_button).
 *
 * A link or button styled by the .slate-btn classes (components.css reads only
 * --slate-* semantic tokens). Renders an <a> when `href` is given, else a
 * <button>. Content-blind, escaped, accessible by construction.
 *
 * Props:
 *   label     string  visible text (escaped)
 *   href      string  when set → <a href>; else <button type>
 *   tone      string  accent | neutral   (default accent)
 *   size      string  sm | md | lg        (default md)
 *   type      string  button type when not a link (default 'button')
 *   disabled  bool    renders aria-disabled + disabled/inert
 *   attrs     array   extra passthrough attributes (escaped)
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

if (!function_exists('slate_c_button')) {
    function slate_c_button(array $props = []): string
    {
        $label = slate_c_e((string) ($props['label'] ?? ''));
        $tone  = (string) ($props['tone'] ?? 'accent');
        $tone  = in_array($tone, ['accent', 'neutral'], true) ? $tone : 'accent';
        $size  = (string) ($props['size'] ?? 'md');
        $size  = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';
        $href  = (string) ($props['href'] ?? '');
        $disabled = !empty($props['disabled']);
        $attrs = is_array($props['attrs'] ?? null) ? $props['attrs'] : [];

        $class = slate_c_classes(['slate-btn', "slate-btn--{$tone}", "slate-btn--{$size}"]);

        if ($href !== '') {
            $dis = $disabled ? ' aria-disabled="true" tabindex="-1"' : '';
            return '<a class="' . $class . '" href="' . slate_c_e($href) . '"' . $dis . slate_c_attrs($attrs) . '>' . $label . '</a>';
        }

        $type = slate_c_e((string) ($props['type'] ?? 'button'));
        $dis  = $disabled ? ' disabled aria-disabled="true"' : '';
        return '<button class="' . $class . '" type="' . $type . '"' . $dis . slate_c_attrs($attrs) . '>' . $label . '</button>';
    }
}
