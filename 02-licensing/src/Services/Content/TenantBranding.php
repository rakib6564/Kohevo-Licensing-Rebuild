<?php
/**
 * Slate — TenantBranding: the canonical read path for a tenant's own
 * identity — colors/fonts (the original scope), plus name, logo, favicon,
 * login image, tagline, and sublabel (added in the Kohevo Brand Persistence
 * System, Phase 1). Its platform-owned counterpart is PlatformIdentity —
 * tenant settings can change what is returned here; they can never reach
 * PlatformIdentity's values (see that class's own docblock).
 *
 * KEYS/resolve() read the brand color/font settings (the same keys as
 * admin/settings.php's Branding section). Their original callers — the
 * visual page editor and its theme resolver — were removed with the
 * editor; they are kept as the canonical read path for those settings.
 *
 * The methods below this point are net-new additions (Phase 1). They read
 * the exact same `settings` keys already read independently today by
 * includes/helpers.php's slate_logo_urls()/slate_favicon_url(),
 * admin/partials/header.php, customer/partials/header.php, admin/login.php,
 * includes/error_page.php, and BrandedEmail::brand() — none of those call
 * sites were changed or migrated onto this class in Phase 1 (see
 * docs/09-Roadmap/phase-kohevo-identity-p1.md for why: several of those
 * files are shell/login/error/email surfaces this phase is scoped to leave
 * untouched). These methods exist so a *later* phase has one canonical place
 * to migrate those call sites onto, without inventing new setting keys or
 * changing the stored data format — every value is read the same way, with
 * the same fallback semantics, as its existing counterpart.
 */

declare(strict_types=1);

namespace Slate\Services\Content;

final class TenantBranding
{
    /** Brand color/font setting keys. */
    public const KEYS = [
        'accent' => 'brand_accent_color',
        'ink' => 'brand_text_color',
        'pageBg' => 'brand_canvas_color',
        'heading' => 'brand_heading_font',
        'body' => 'brand_body_font',
    ];

    /** @return array<string,string> brand key => stored setting value */
    public static function resolve(): array
    {
        $out = [];
        foreach (self::KEYS as $brandKey => $settingKey) {
            $out[$brandKey] = (string) (\Database::setting($settingKey) ?? '');
        }
        return $out;
    }

    /**
     * The tenant's product/site name, falling back to the platform name —
     * exact-behavior parity with the `Database::setting('site_name') ?: 'Kohevo'`
     * pattern repeated at 20+ existing call sites, except the fallback is
     * sourced from PlatformIdentity::name() instead of a repeated literal.
     */
    public static function siteName(): string
    {
        return (string) (\Database::setting('site_name') ?: PlatformIdentity::name());
    }

    /** The tenant's business/company name. Empty string when unset — no fallback (matches existing raw-setting reads). */
    public static function businessName(): string
    {
        return trim((string) (\Database::setting('business_name') ?? ''));
    }

    /**
     * @return array{light:string,dark:string} Resolved absolute URLs, or ''
     * when unset — identical shape and fallback behavior to the existing
     * includes/helpers.php slate_logo_urls().
     */
    public static function logoUrls(): array
    {
        $light = trim((string) \Database::setting('brand_logo_path'));
        $dark  = trim((string) \Database::setting('brand_logo_dark_path'));
        return [
            'light' => $light !== '' ? self::assetUrl($light) : '',
            'dark'  => $dark  !== '' ? self::assetUrl($dark)  : '',
        ];
    }

    /**
     * The tenant's favicon, falling back to the platform favicon — identical
     * resolved URL to the existing includes/helpers.php slate_favicon_url(),
     * except the fallback is sourced from PlatformIdentity::faviconUrl()
     * instead of the duplicate slate_default_favicon_url() implementation.
     */
    public static function faviconUrl(): string
    {
        $path = trim((string) \Database::setting('brand_favicon_path'));
        return $path !== '' ? self::assetUrl($path) : PlatformIdentity::faviconUrl();
    }

    /** The tenant's login/auth hero image. Empty string when unset — no fallback. */
    public static function loginImageUrl(): string
    {
        $path = trim((string) \Database::setting('brand_login_image_path'));
        return $path !== '' ? self::assetUrl($path) : '';
    }

    /** The tenant's login-screen tagline. Empty string when unset. */
    public static function loginTagline(): string
    {
        return trim((string) (\Database::setting('brand_login_tagline') ?? ''));
    }

    /** The tenant's shell/login sublabel (e.g. "Pro Admin", "Customer Portal"). Empty string when unset — callers supply their own page-specific default, as today. */
    public static function sublabel(): string
    {
        return trim((string) (\Database::setting('brand_sublabel') ?? ''));
    }

    /** Absolute URL for a tenant-uploaded asset path, matching the existing SLATE_URL . '/' . ltrim($path, '/') convention used throughout the app. */
    private static function assetUrl(string $path): string
    {
        $base = defined('SLATE_URL') ? rtrim((string) \SLATE_URL, '/') : '';
        return $base . '/' . ltrim($path, '/');
    }
}
