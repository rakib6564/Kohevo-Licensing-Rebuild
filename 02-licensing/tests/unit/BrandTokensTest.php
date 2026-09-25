<?php
/**
 * Unit tests for slate_brand_accent_tokens() — the per-tenant derivation of
 * --on-accent and --accent-ink from a brand colour.
 *
 * These assert the PROPERTY (the resulting pair clears WCAG AA) rather than
 * the hex values, because the values are an implementation detail and the
 * contrast is the actual requirement. A test pinned to "#F89DC1 gives
 * #15181E" would have to be rewritten every time the darkening step changed,
 * and would still not notice if the result stopped being readable.
 *
 * The bug these exist to prevent: the admin chrome used to pick its
 * foreground with `luminance > 0.55 ? ink : white`. Company B's #F89DC1 sits
 * at 0.47, so it chose white — about 2:1 — and every primary button in the
 * admin failed AA while the customer portal, which measured contrast
 * properly, rendered the same brand readably.
 *
 * No DB, no config: brand_tokens.php is pure arithmetic.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/brand_tokens.php';

/** WCAG relative luminance of "#RRGGBB". */
function _bt_lum(string $hex): float
{
    $h = ltrim($hex, '#');
    $f = static fn (float $v): float => $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
    return 0.2126 * $f(hexdec(substr($h, 0, 2)) / 255)
         + 0.7152 * $f(hexdec(substr($h, 2, 2)) / 255)
         + 0.0722 * $f(hexdec(substr($h, 4, 2)) / 255);
}

/** WCAG contrast ratio between two "#RRGGBB" colours. */
function _bt_ratio(string $a, string $b): float
{
    $x = _bt_lum($a); $y = _bt_lum($b);
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

/** The 9% accent-on-white tint that --accent-ink has to sit on. */
function _bt_soft(string $hex): string
{
    $h = ltrim($hex, '#');
    $mix = static fn (int $c): int => (int) round($c * 0.09 + 255 * 0.91);
    return sprintf('#%02X%02X%02X',
        $mix((int) hexdec(substr($h, 0, 2))),
        $mix((int) hexdec(substr($h, 2, 2))),
        $mix((int) hexdec(substr($h, 4, 2))));
}

/**
 * Brands spanning the range that matters: a pale pink and a pale yellow that
 * a lightness threshold gets wrong, the shipped blue, and the extremes.
 */
const _BT_BRANDS = [
    '#F89DC1',   // Company B — luminance 0.47, the one that broke
    '#FFF9E6',   // near-white
    '#2563EB',   // Slate default blue
    '#0EA5E9',   // sky — light enough to be a trap
    '#0E1117',   // near-black
    '#00FF00',   // maximum-luminance green
    '#7C3AED',   // violet
];

unit('brand tokens: --on-accent always clears AA on the accent it sits on', function () {
    foreach (_BT_BRANDS as $brand) {
        $t = slate_brand_accent_tokens($brand);
        $r = _bt_ratio($t['on_accent'], $brand);
        assert_true(
            $r >= 4.5,
            "on_accent {$t['on_accent']} on {$brand} is only " . round($r, 2) . ':1'
        );
    }
});

unit('brand tokens: --accent-ink clears AA as text on the soft tint', function () {
    foreach (_BT_BRANDS as $brand) {
        $t    = slate_brand_accent_tokens($brand);
        $soft = _bt_soft($brand);
        $r    = _bt_ratio($t['accent_ink'], $soft);
        assert_true(
            $r >= 4.5,
            "accent_ink {$t['accent_ink']} on soft {$soft} is only " . round($r, 2) . ':1'
        );
    }
});

unit('brand tokens: a mid-tone brand takes ink, not white', function () {
    // The regression guard, and it has to name the colour rather than compare
    // ratios: "on_accent beats white" is trivially true when on_accent IS
    // white, so that phrasing would have passed against the very threshold
    // bug this test exists to catch. Asserting it is not white does not.
    foreach (['#F89DC1', '#0EA5E9', '#00FF00', '#FFF9E6'] as $brand) {
        $t = slate_brand_accent_tokens($brand);
        assert_true(
            $t['on_accent'] !== '#FFFFFF',
            "{$brand} was given white text; white on it is only "
                . round(_bt_ratio('#FFFFFF', $brand), 2) . ':1'
        );
    }
});

unit('brand tokens: a dark brand still takes white', function () {
    foreach (['#0E1117', '#2563EB', '#7C3AED'] as $brand) {
        $t = slate_brand_accent_tokens($brand);
        assert_eq('#FFFFFF', $t['on_accent'], "dark brand {$brand} should keep white text");
    }
});

unit('brand tokens: shorthand hex and a missing hash are accepted', function () {
    $long  = slate_brand_accent_tokens('#FF00AA');
    $short = slate_brand_accent_tokens('#F0A');
    assert_eq($long['on_accent'], $short['on_accent'], '#F0A expands to #FF00AA');

    $bare = slate_brand_accent_tokens('FF00AA');
    assert_eq($long['accent_ink'], $bare['accent_ink'], 'a missing # is tolerated');
});

unit('brand tokens: output is always a full uppercase hex triplet', function () {
    foreach (_BT_BRANDS as $brand) {
        $t = slate_brand_accent_tokens($brand);
        foreach ([$t['on_accent'], $t['accent_ink']] as $v) {
            assert_true(
                (bool) preg_match('/^#[0-9A-F]{6}$/', $v),
                "{$v} is not a #RRGGBB value (from {$brand})"
            );
        }
    }
});
