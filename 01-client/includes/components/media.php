<?php
/**
 * Slate Component — Media (slate_c_media).
 *
 * A responsive image styled by .slate-media (components.css). Lazy-loaded, always
 * carries an alt attribute (empty allowed for decorative images), optional fixed
 * aspect ratio. Content-blind and escaped.
 *
 * Props:
 *   src    string  image URL (escaped)
 *   alt    string  alt text (escaped; '' = decorative)
 *   ratio  string  '' | 1-1 | 4-3 | 16-9   (default '' = intrinsic)
 *   attrs  array   extra passthrough attributes (escaped)
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';

if (!function_exists('slate_c_media')) {
    function slate_c_media(array $props = []): string
    {
        $src = (string) ($props['src'] ?? '');
        if ($src === '') {
            return '';
        }
        $alt   = (string) ($props['alt'] ?? '');
        $ratio = in_array($props['ratio'] ?? '', ['1-1', '4-3', '16-9'], true) ? $props['ratio'] : '';
        $attrs = is_array($props['attrs'] ?? null) ? $props['attrs'] : [];

        $class = slate_c_classes(['slate-media', $ratio !== '' ? "slate-media--{$ratio}" : '']);

        return '<img class="' . $class . '" src="' . slate_c_e($src) . '" alt="' . slate_c_e($alt) . '"'
            . ' loading="lazy" decoding="async"' . slate_c_attrs($attrs) . '>';
    }
}
