<?php
/**
 * What a StudioStylesheet value comes to with the default theme tokens.
 *
 * Presets that follow a site token are written as `var(--sb-token, default)` or, for padding and gap,
 * as a multiple of a Spacing token: `calc(var(--sb-space-md,1.5rem)*4/3)`. Tests that compare the
 * stylesheet with a fixed table (the Inspector's labels, the HTML importer's scales) compare the size
 * a page gets when no token was edited.
 */

declare(strict_types=1);

function sb_css_default(string $value): string
{
    $value = trim($value);
    if (preg_match('~^calc\(var\(--sb-[a-z0-9-]+,\s*([\d.]+)rem\)(?:\*(\d+))?(?:/(\d+))?\)$~', $value, $m) === 1) {
        $rem = (float) $m[1] * (int) (($m[2] ?? '') !== '' ? $m[2] : 1) / (int) (($m[3] ?? '') !== '' ? $m[3] : 1);
        return rtrim(rtrim(number_format($rem, 4, '.', ''), '0'), '.') . 'rem';
    }
    if (preg_match('~^var\(--sb-[a-z0-9_-]+,\s*(.+)\)$~', $value, $m) === 1) {
        return trim($m[1]);
    }
    return $value;
}
