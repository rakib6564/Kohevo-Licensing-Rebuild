<?php
/**
 * on-accent must actually contrast with the accent.
 *
 * It used to be chosen by a luminance cutoff — `> 0.55 ? dark : white` — which
 * is a proxy for the question, not the question. A mid-luminance accent falls
 * below the cutoff and takes white: #00c6ff measures 0.476, rendered white text
 * at 2.00:1 against a 4.5 requirement, when dark on the same accent is 8.90.
 *
 * That was live. It is the reason every button on a cyan-accented tenant had
 * sub-AA label text, independent of anything Phase E does.
 */

declare(strict_types=1);

use Slate\Presentation\Theme\TenantThemeResolver;

/** WCAG relative luminance. */
function _oa_lum(string $hex): float
{
    $h = ltrim($hex, '#');
    $f = static fn (float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    return 0.2126 * $f(hexdec(substr($h, 0, 2)) / 255)
         + 0.7152 * $f(hexdec(substr($h, 2, 2)) / 255)
         + 0.0722 * $f(hexdec(substr($h, 4, 2)) / 255);
}

function _oa_ratio(string $a, string $b): float
{
    $la = _oa_lum($a); $lb = _oa_lum($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/**
 * The on-accent that ends up in effect for a given accent.
 *
 * The resolver deliberately emits NO override for the default accent — a default
 * install should keep pointing at the primitive — so an absent token is not a
 * failure, it means the semantic default applies. That default is
 * slate-color-on-accent => var(--slate-color-neutral-0), i.e. white.
 */
function _oa_for(string $accent): string
{
    $tokens = TenantThemeResolver::fromBrandAccent($accent)->tokens();
    return (string) ($tokens['slate-color-on-accent'] ?? '#FFFFFF');
}

unit('on-accent clears AA against every accent, including mid-luminance ones', function (): void {
    $accents = [
        '#FFCB70' => 'gold (this install\'s brand accent)',
        '#00c6ff' => 'cyan (the 0.55-cutoff failure)',
        '#2563eb' => 'blue-600 (the default)',
        '#111827' => 'near-black',
        '#cdfa45' => 'lime',
    ];

    // #808080 is deliberately NOT in that list. A mid grey cannot clear 4.5:1
    // against black OR white — the best achievable is ~4.5 and it lands just
    // under. That is a property of the colour, not of the picker, and asserting
    // AA for it would demand something no on-accent value can deliver. The
    // property that DOES hold for it — that the better candidate is chosen — is
    // asserted in the next test, where #808080 is included.

    foreach ($accents as $hex => $label) {
        $on = _oa_for($hex);
        $r  = _oa_ratio($on, $hex);
        assert_true(
            $r >= 4.5,
            sprintf('%s: on-accent %s gives %.2f:1 — below 4.5 means every button '
                . 'label on that accent is sub-AA', $label, $on, $r)
        );
    }
});

unit('on-accent picks the better of the two candidates, not a cutoff', function (): void {
    // The property, stated directly: whichever of dark/white contrasts more must
    // win. A cutoff can disagree with this, and did.
    foreach (['#FFCB70', '#00c6ff', '#2563eb', '#808080', '#7f7f7f', '#cdfa45'] as $hex) {
        $chosen = _oa_for($hex);
        $other  = $chosen === '#FFFFFF' ? '#16181D' : '#FFFFFF';
        assert_true(
            _oa_ratio($chosen, $hex) >= _oa_ratio($other, $hex),
            "{$hex}: chose {$chosen} but {$other} contrasts better"
        );
    }
});
