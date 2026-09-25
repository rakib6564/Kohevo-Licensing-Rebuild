<?php
/**
 * Slate — PlatformIdentity: the single, deterministic source of the Kohevo
 * platform identity (name, mark/wordmark asset URLs, favicon, signature).
 *
 * Every value here is a fixed constant or derived from the SLATE_URL
 * constant only — ORDINARY tenant branding settings (see TenantBranding)
 * can never edit, unset, or override any value this class returns; there is
 * no code path from `settings.setting_value` into this class. The one
 * exception, added in Phase 6, is signature(): it consults
 * PlatformIdentityPolicy — never settings/TenantBranding directly — to
 * decide whether the CURRENT TENANT holds the licensed white_label
 * capability, and returns '' rather than the attribution line if so. Every
 * other method (name/markUrl/wordmarkUrl/faviconUrl) stays unconditional:
 * the underlying platform identity remains internally canonical regardless
 * of white-label presentation state; only the one attribution-text output
 * is gated. See PlatformIdentityPolicy's docblock for the licensing
 * fail-safe contract.
 *
 * Deliberately NOT under Slate\Services\Identity — that namespace already
 * means the customer/contact authentication "identity spine"
 * (ContactRepository/IdentityStore/IdentityRepository/IdentityTokenRepository,
 * docs/09-Roadmap/phase2a-identity-design.md), an unrelated concept. Co-located
 * with TenantBranding instead, as its platform-owned counterpart.
 *
 * Asset paths point at the official Kohevo monogram package (Phase 1A —
 * "Monogramme K pour Kohevo"), copied unmodified into
 * assets/platform/brand/; see that directory's LISEZMOI.txt (the authoritative
 * usage guidance from the brand package itself) and README.md for the full
 * asset set and the mapping rationale below. Two asset variants remain
 * available on disk but intentionally unexposed by this class for now
 * (kohevo-signe-3-bandes-*.svg, the larger from-32px full sign; and
 * kohevo-lockup-vertical-*.svg, the stacked lockup) — nothing in the current
 * architecture needs them, per the Phase 1A instruction to keep this API
 * minimal and intentional rather than exposing every supplied asset.
 *
 * Kohevo Brand Persistence System — Phase 1/1A (Foundation). Nothing renders
 * this yet; see PlatformSignature and docs/09-Roadmap/phase-kohevo-identity-p1.md.
 */

declare(strict_types=1);

namespace Slate\Services\Content;

final class PlatformIdentity
{
    private const NAME = 'Kohevo';

    /**
     * "Variante une bande" (LISEZMOI) — the compact, single-band mark. The
     * official package's own guidance names this exact file for favicon/icon
     * use as well (see FAVICON_PATH below), so mark and favicon intentionally
     * resolve to the identical asset.
     */
    private const MARK_PATH = '/assets/platform/brand/kohevo-compact-noir.svg';

    /**
     * "Signe + 'ohevo' a droite, alignes sur la ligne de base" (LISEZMOI) —
     * the horizontal lockup: the one-line, inline mark+wordmark composition,
     * matching how PlatformSignature actually renders it (mark image
     * followed by name/text on one line).
     */
    private const WORDMARK_PATH      = '/assets/platform/brand/kohevo-lockup-horizontal-noir.svg';
    private const WORDMARK_DARK_PATH = '/assets/platform/brand/kohevo-lockup-horizontal-blanc.svg';

    /**
     * LISEZMOI is explicit that the compact variant is the intended favicon
     * source ("Favicon, icone d'application, gravure. Des 16 px"), so this
     * now supersedes the pre-existing assets/img/kohevo-favicon.ico for
     * PlatformIdentity's own purposes. That .ico file is untouched on disk —
     * nothing currently live reads favicons through PlatformIdentity (the
     * production favicon path is still the separate, pre-existing
     * slate_default_favicon_url() helper), so this is a Phase-1-safe change,
     * not a production behavior change.
     */
    private const FAVICON_PATH = self::MARK_PATH;

    public static function name(): string
    {
        return self::NAME;
    }

    public static function markUrl(): string
    {
        return self::assetUrl(self::MARK_PATH);
    }

    public static function wordmarkUrl(): string
    {
        return self::assetUrl(self::WORDMARK_PATH);
    }

    public static function wordmarkDarkUrl(): string
    {
        return self::assetUrl(self::WORDMARK_DARK_PATH);
    }

    public static function faviconUrl(): string
    {
        return self::assetUrl(self::FAVICON_PATH);
    }

    /**
     * The platform's own attribution line — plain text, callers own their
     * own markup. Empty when the current tenant is licensed to white-label
     * (Phase 6 — see PlatformIdentityPolicy, the only caller this class
     * makes into licensing; every other method here remains unconditional,
     * since asset/name identity stays internally canonical regardless of
     * white-label presentation state — see PlatformIdentityPolicy's own
     * docblock and docs/09-Roadmap/phase-kohevo-identity-p6.md §16).
     */
    public static function signature(): string
    {
        if (PlatformIdentityPolicy::whiteLabelActive()) {
            return '';
        }
        return 'Powered by ' . self::NAME;
    }

    /**
     * Absolute URL for a platform-owned asset path. Guards on defined()
     * because unit tests (tests/unit/run.php) boot the autoloader only, with
     * no config.php and so no SLATE_URL — matching the same guard already
     * used by tests/unit/AuthSecurityBoundaryTest.php.
     */
    private static function assetUrl(string $path): string
    {
        $base = defined('SLATE_URL') ? rtrim((string) \SLATE_URL, '/') : '';
        return $base . $path;
    }
}
