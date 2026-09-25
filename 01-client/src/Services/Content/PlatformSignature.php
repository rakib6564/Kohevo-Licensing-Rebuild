<?php
/**
 * Slate — PlatformSignature: renders the platform's own "Powered by Kohevo"
 * attribution, in the handful of shapes the shell/login/error/email surfaces
 * will eventually need (spec: KOHEVO-BRAND-PERSISTENCE-SYSTEM.md §6).
 *
 * Foundation only (Kohevo Brand Persistence System — Phase 1). Nothing calls
 * this yet — no shell, login, error, or email markup was touched to insert
 * it; that is later-phase work. Every value comes from PlatformIdentity,
 * never a hardcoded asset path or literal here, so a future change to where
 * platform assets live never requires touching a consumer of this class.
 *
 * Self-contained (no global e()/helpers.php dependency, no i18n __()) so it
 * is exercisable by the autoloader-only unit-test harness (tests/unit/run.php),
 * which boots no config.php. The "Powered by" copy is a plain literal for
 * now; routing it through __() is future-phase UI-integration work.
 *
 * Phase 1A: the mark image carries no fixed pixel width/height. The official
 * compact mark's own viewBox (198.64 x 300, non-square) makes a hardcoded
 * square size distort the artwork; sizing is left to the consuming CSS
 * (a later, UI-integration-phase concern) rather than guessed here.
 */

declare(strict_types=1);

namespace Slate\Services\Content;

final class PlatformSignature
{
    /** Mark only — e.g. a compact sidebar-bottom icon. */
    public const MODE_COMPACT = 'compact';
    /** Mark + platform name, no "Powered by" framing. */
    public const MODE_STANDARD = 'standard';
    /** Mark + full "Powered by Kohevo" attribution — the login/email default. */
    public const MODE_SIGNATURE = 'signature';

    private const MODES = [self::MODE_COMPACT, self::MODE_STANDARD, self::MODE_SIGNATURE];

    /**
     * A small, self-contained HTML snippet for the given mode. Callers own
     * their own wrapping element/CSS class; this returns only the mark
     * image plus whatever text the mode calls for. An unrecognized mode
     * falls back to MODE_SIGNATURE rather than throwing.
     *
     * Returns '' for EVERY mode (including MODE_COMPACT's mark-only image)
     * when the current tenant holds the licensed white_label capability
     * (Phase 6 — see PlatformIdentityPolicy): white-labeling suppresses the
     * platform identity's presentation entirely, not just its "Powered by"
     * text, so this checks up front rather than only gating the SIGNATURE
     * mode's text portion (which would otherwise still show the mark image
     * even when white-labeled).
     */
    public static function render(string $mode = self::MODE_SIGNATURE): string
    {
        if (PlatformIdentityPolicy::whiteLabelActive()) {
            return '';
        }

        $mode = in_array($mode, self::MODES, true) ? $mode : self::MODE_SIGNATURE;

        $markImg = '<img src="' . self::escape(PlatformIdentity::markUrl()) . '"'
            . ' alt="' . self::escape(PlatformIdentity::name()) . '">';
        $name = self::escape(PlatformIdentity::name());

        return match ($mode) {
            self::MODE_COMPACT   => $markImg,
            self::MODE_STANDARD  => $markImg . ' ' . $name,
            self::MODE_SIGNATURE => $markImg . ' ' . self::escape(PlatformIdentity::signature()),
        };
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
