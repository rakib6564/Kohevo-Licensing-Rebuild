<?php
/**
 * Slate — brand colour token derivation.
 *
 * One brand colour has to produce several tokens with different jobs, and
 * getting the job wrong is how a tenant ends up with unreadable buttons:
 *
 *   --accent      the brand colour itself. A FILL. Never small text.
 *   --on-accent   whichever of white / ink reads on that fill.
 *   --accent-ink  the brand hue darkened until it clears AA as text on the
 *                 soft tint. This is the one to use for coloured TEXT.
 *
 * Both are decided by measuring actual WCAG contrast rather than by a
 * lightness threshold. A threshold has to guess where white stops working,
 * and the guess is wrong across a wide band of mid-tone brands: Company B's
 * #F89DC1 has a relative luminance of 0.47, so a "> 0.55 means dark text"
 * rule picks white, which lands at about 2:1. Ink on the same pink is about
 * 8.9:1. Comparing the two ratios cannot make that mistake.
 *
 * Lives here rather than beside either caller because the admin chrome and
 * the customer portal both need it, and two copies of this would drift —
 * which is exactly what had happened: the portal measured contrast and the
 * admin used a threshold, so the same brand produced readable buttons for
 * parents and unreadable ones for staff.
 */

declare(strict_types=1);

if (!function_exists('slate_brand_accent_tokens')) {
    /**
     * Derive the contrast-safe companions of a brand accent.
     *
     * @param  string $hex Brand colour, "#RRGGBB" or "#RGB".
     * @return array{on_accent: string, accent_ink: string}
     */
    function slate_brand_accent_tokens(string $hex, ?string $onAccentOverride = null): array
    {
        $rgb = static function (string $h): array {
            $h = ltrim($h, '#');
            if (strlen($h) === 3) { $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2]; }
            return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
        };
        $lum = static function (array $c): float {
            $f = static fn (float $v): float => $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
            return 0.2126 * $f($c[0] / 255) + 0.7152 * $f($c[1] / 255) + 0.0722 * $f($c[2] / 255);
        };
        $ratio = static function (array $a, array $b) use ($lum): float {
            $x = $lum($a); $y = $lum($b);
            return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
        };
        $toHex = static fn (array $c): string => sprintf('#%02X%02X%02X', ...$c);
        $mix   = static fn (array $a, array $b, float $p): array => [
            (int) round($a[0] * $p + $b[0] * (1 - $p)),
            (int) round($a[1] * $p + $b[1] * (1 - $p)),
            (int) round($a[2] * $p + $b[2] * (1 - $p)),
        ];

        $accent = $rgb($hex);
        $white  = [255, 255, 255];
        $ink    = [21, 24, 30];            // --m-ink

        // Whichever of white / ink reads better on the accent itself.
        $onAccent = $ratio($white, $accent) >= $ratio($ink, $accent) ? $white : $ink;

        // Darken the accent toward black until it clears AA on the soft tint.
        $soft = $mix($accent, $white, 0.09);
        $accentInk = $accent;
        for ($p = 100; $p >= 0; $p -= 5) {
            $cand = $mix($accent, [0, 0, 0], $p / 100);
            if ($ratio($cand, $soft) >= 4.5) { $accentInk = $cand; break; }
            $accentInk = $cand;             // darkest tried, in case none clears
        }

        // Manual escape hatch — Settings -> Branding -> Button text color.
        // Only on_accent (button/badge TEXT ON the fill) is overridable this
        // way; accent_ink (colored text on a soft tint) stays measured, since
        // an override aimed at button fills would just as easily break AA there.
        if ($onAccentOverride === 'light') { $onAccent = $white; }
        elseif ($onAccentOverride === 'dark') { $onAccent = $ink; }

        return ['on_accent' => $toHex($onAccent), 'accent_ink' => $toHex($accentInk)];
    }
}
