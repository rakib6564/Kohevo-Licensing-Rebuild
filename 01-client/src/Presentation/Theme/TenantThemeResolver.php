<?php
/**
 * Slate — TenantThemeResolver: build a Theme from a tenant's brand inputs
 * (Presentation, Phase 3B B2).
 *
 * Maps a tenant's stored brand color to --slate-* token overrides — the new-
 * vocabulary equivalent of the legacy slate_brand_accent_emit(). Overriding the
 * one accent role re-brands every surface that reads it (design-tokens.md §5).
 *
 * PURE by design: Presentation must not read the database (it sits above the
 * Services/Data layers). The caller reads `brand_accent_color` and passes the hex
 * in; this class only transforms values. A missing/default/invalid color yields
 * the DefaultTheme (no overrides), so default installs stay on the token defaults.
 */

declare(strict_types=1);

namespace Slate\Presentation\Theme;

final class TenantThemeResolver
{
    /** The platform default accent — a brand color equal to this adds no override. */
    public const DEFAULT_ACCENT = '#2563EB';

    /**
     * @param string|null $brandAccent  a #RRGGBB hex, or null/empty for the default
     */
    public static function fromBrandAccent(?string $brandAccent, ?string $onAccentOverride = null): Theme
    {
        $hex = self::normalizeHex($brandAccent);
        if ($hex === null || $hex === self::DEFAULT_ACCENT) {
            return new DefaultTheme();
        }

        return new ArrayTheme(
            tokens: [
                'slate-color-accent'        => $hex,
                'slate-color-on-accent'     => self::readableOn($hex, $onAccentOverride),
                'slate-color-accent-strong' => self::strongOn($hex),
                'slate-color-focus-ring'    => $hex,
            ],
        );
    }

    /**
     * A Theme carrying a tenant's WHOLE brand, not just its accent.
     *
     * fromBrandAccent() above maps one value. That was enough while --slate-*
     * had no tenant-facing consumers, and it is not enough for Phase E: the
     * content engine's --cb-* vocabulary is tenant-fed for twelve values, so
     * --slate-color-text is a fixed neutral-900 while --cb-ink is whatever the
     * site chose. Renaming a consumer from one to the other would change the
     * colour on every branded site. This closes that gap, doing for the rest
     * what the resolver already did for the accent.
     *
     * Still PURE: the caller reads the settings and passes the resolved values
     * in, exactly as before.
     *
     * @param array $brand Branding::resolve()'s shape — accent, ink, muted,
     *                     surface, surface2, pageBg, heading, body, radius,
     *                     btnRadius. Unknown or empty values are skipped, so a
     *                     partially configured site keeps the defaults.
     */
    public static function fromBrand(array $brand): Theme
    {
        $tokens = [];

        // Same rule fromBrandAccent() applies: the default accent is not an
        // override, so a default install keeps pointing at the primitive.
        $hex = self::normalizeHex($brand['accent'] ?? null);
        if ($hex === self::DEFAULT_ACCENT) { $hex = null; }
        if ($hex !== null) {
            // 'onAccentOverride' ('light'/'dark'/null) is threaded in by the
            // caller (CoreBridge::siteTheme()) from Settings -> Branding ->
            // Button text color — this class stays PURE, it never reads it
            // itself.
            $onAccentOverride = $brand['onAccentOverride'] ?? null;
            $tokens['slate-color-accent']        = $hex;
            $tokens['slate-color-on-accent']     = self::readableOn($hex, $onAccentOverride);
            // Against the darkest light surface this tenant actually uses, so
            // the result is AA on their canvas and cards, not only on white.
            $tokens['slate-color-accent-strong'] = self::strongOn($hex, self::darkestSurface($brand));
            $tokens['slate-color-focus-ring']    = $hex;
        }

        foreach ([
            'ink'      => 'slate-color-text',
            'muted'    => 'slate-color-text-muted',
            'surface'  => 'slate-color-surface',
            'surface2' => 'slate-color-surface-sunken',
            'pageBg'   => 'slate-color-canvas',
            'heading'  => 'slate-font-heading',
            'body'     => 'slate-font-sans',
        ] as $key => $token) {
            $v = trim((string) ($brand[$key] ?? ''));
            if ($v !== '') {
                $tokens[$token] = $v;
            }
        }

        // radius arrives as a bare number of pixels.
        $radius = trim((string) ($brand['radius'] ?? ''));
        if ($radius !== '') {
            $tokens['slate-radius-md'] = is_numeric($radius) ? $radius . 'px' : $radius;
        }

        // btnRadius may be the literal string 'var(--cb-radius)' — the button
        // shape "rounded" defers to the global radius. Copying that through
        // would put the OLD vocabulary inside a --slate-* token and quietly
        // keep the coupling Phase E exists to remove, so the reference is
        // translated rather than carried.
        $btn = trim((string) ($brand['btnRadius'] ?? ''));
        if ($btn !== '') {
            $tokens['slate-radius-control'] = $btn === 'var(--cb-radius)'
                ? 'var(--slate-radius-md)'
                : $btn;
        }

        return $tokens === [] ? new DefaultTheme() : new ArrayTheme(tokens: $tokens);
    }

    // ── internals ─────────────────────────────────────────────

    /** Validate + upper-case a #RRGGBB hex; null if it isn't one. */
    private static function normalizeHex(?string $v): ?string
    {
        $v = trim((string) $v);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : null;
    }

    /**
     * A readable foreground (#fff or near-black) for text/icons on an accent fill,
     * chosen by relative luminance (WCAG) — the same rule as the legacy emitter.
     */
    /**
     * A darkened form of the accent that is legible as TEXT on a light page.
     *
     * The accent is chosen as a brand colour, which means it is chosen to look
     * good as a fill. Used as type on white it frequently fails badly — this
     * install's #FFCB70 measures 1.50:1, and the previous #00c6ff measured 2.00.
     * Both are links, prices and card titles on a live site.
     *
     * DERIVED rather than stored, so it tracks the accent instead of drifting
     * from it. Branding's accentDark was a stored second value and on this
     * install it had silently become equal to the accent (Branding.php sets
     * accentDark = accent whenever a custom hex is used), so "dark" was a lie
     * and hovers did not darken at all.
     *
     * Darkened in steps until it clears 4.5:1 against the DARKEST light surface
     * it lands on, not against white.
     *
     * Targeting white is the intuitive choice and it is wrong: white is the
     * lightest background, so it yields the highest ratio for dark text. A value
     * tuned to just clear 4.5 on white lands under it on any off-white — the
     * derived #8F723F measures 4.52 on #FFFFFF and 4.14 on this install's canvas
     * #faf4ee. The binding constraint is the darkest surface, so that is what it
     * is measured against.
     */
    private static function strongOn(string $hex, string $against = '#FFFFFF'): string
    {
        $h = ltrim($hex, '#');
        if (strlen($h) !== 6) return $hex;

        $r = hexdec(substr($h, 0, 2));
        $g = hexdec(substr($h, 2, 2));
        $b = hexdec(substr($h, 4, 2));

        $lin = static fn (float $c): float =>
            $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $lumOf = static fn (int $r, int $g, int $b): float =>
            0.2126 * $lin($r / 255) + 0.7152 * $lin($g / 255) + 0.0722 * $lin($b / 255);

        $a = ltrim($against, '#');
        $bgLum = strlen($a) === 6
            ? $lumOf(hexdec(substr($a, 0, 2)), hexdec(substr($a, 2, 2)), hexdec(substr($a, 4, 2)))
            : 1.0;

        // Scale toward black in 2% steps. 50 iterations reaches black, so this
        // always terminates; black clears 4.5:1 on any light surface.
        for ($i = 0; $i <= 50; $i++) {
            $k  = 1.0 - ($i * 0.02);
            $rr = (int) round($r * $k);
            $gg = (int) round($g * $k);
            $bb = (int) round($b * $k);
            $ratio = ($bgLum + 0.05) / ($lumOf($rr, $gg, $bb) + 0.05);
            if ($ratio >= 4.5) {
                return sprintf('#%02X%02X%02X', $rr, $gg, $bb);
            }
        }
        return '#000000';
    }

    /** The darkest of a brand's light surfaces — the binding case for text on it. */
    private static function darkestSurface(array $brand): string
    {
        $lin = static fn (float $c): float =>
            $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $lum = static function (string $hex) use ($lin): float {
            $h = ltrim($hex, '#');
            if (strlen($h) !== 6) return 1.0;
            return 0.2126 * $lin(hexdec(substr($h, 0, 2)) / 255)
                 + 0.7152 * $lin(hexdec(substr($h, 2, 2)) / 255)
                 + 0.0722 * $lin(hexdec(substr($h, 4, 2)) / 255);
        };

        $darkest = '#FFFFFF';
        foreach (['pageBg', 'surface', 'surface2'] as $k) {
            $v = trim((string) ($brand[$k] ?? ''));
            if ($v !== '' && $v[0] === '#' && $lum($v) < $lum($darkest)) {
                $darkest = $v;
            }
        }
        return $darkest;
    }

    private static function readableOn(string $hex, ?string $override = null): string
    {
        // Settings -> Branding -> Button text color: manual escape hatch for
        // the rare accent where the measured pick isn't the one an admin
        // wants. 'auto' (anything other than these two) keeps the measured
        // pick computed below.
        if ($override === 'light') return '#FFFFFF';
        if ($override === 'dark')  return '#16181D';

        $h = ltrim($hex, '#');
        $lin = static fn (float $c): float =>
            $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $lum = 0.2126 * $lin(hexdec(substr($h, 0, 2)) / 255)
             + 0.7152 * $lin(hexdec(substr($h, 2, 2)) / 255)
             + 0.0722 * $lin(hexdec(substr($h, 4, 2)) / 255);
        // Pick whichever candidate actually contrasts better, rather than
        // guessing from a luminance cutoff.
        //
        // The old rule was `luminance > 0.55 ? dark : white`, which is not the
        // same question. A mid-luminance accent sits below the cutoff and got
        // white text that fails badly: #00c6ff measures 0.476, took white, and
        // renders at 2.00:1 against a 4.5 requirement — while dark on the same
        // accent would have been 8.94. The cutoff was a proxy for contrast; this
        // computes it.
        $contrast = static function (float $a, float $b): float {
            [$hi, $lo] = $a > $b ? [$a, $b] : [$b, $a];
            return ($hi + 0.05) / ($lo + 0.05);
        };

        $dark      = 0.00929;   // #16181D, precomputed
        $white     = 1.0;       // #FFFFFF

        return $contrast($lum, $dark) >= $contrast($lum, $white) ? '#16181D' : '#FFFFFF';
    }
}
