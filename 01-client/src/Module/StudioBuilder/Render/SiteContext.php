<?php
/**
 * Kohevo Studio (studio-builder) — Site Context.
 *
 * The server-side site/URL context a render runs in: the install's configured
 * base URL (`SLATE_URL`), and the tenant's own branding (site name, logo,
 * favicon) read through `TenantBranding` — never from the request, the
 * document, or the query string.
 *
 * One site per tenant (open question Q2, option A — the MVP recommendation):
 * `siteKey` is always `default` today. It is carried explicitly anyway so the
 * cache key and compilation fingerprint already name the site dimension.
 *
 * Platform identity is deliberately absent here: `PlatformIdentity` values are
 * read only by the page assembler's protected signature slot, never mixed into
 * the tenant-controlled context.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Render\Seo\SiteSeoDefaults;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Services\Content\TenantBranding;

final class SiteContext
{
    public const DEFAULT_SITE_KEY = 'default';
    private const BASE_URL_PATTERN = '~^https?://[^\s/?#<>"\']+(/[^\s?#<>"\']*)?$~iD';

    public readonly string $baseUrl;
    public readonly string $logoUrl;
    public readonly string $faviconUrl;
    public readonly string $locale;
    /** The site-wide SEO settings (title format, defaults, robots, verification). */
    public readonly SiteSeoDefaults $seo;

    public function __construct(
        string $baseUrl,
        public readonly string $siteName,
        string $logoUrl = '',
        string $faviconUrl = '',
        string $locale = 'en',
        public readonly string $siteKey = self::DEFAULT_SITE_KEY,
        ?SiteSeoDefaults $seo = null,
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl !== '' && preg_match(self::BASE_URL_PATTERN, $baseUrl) !== 1) {
            throw new \InvalidArgumentException('SiteContext baseUrl must be an absolute http(s) URL or empty.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $siteKey) !== 1) {
            throw new \InvalidArgumentException('SiteContext siteKey is invalid.');
        }
        $this->baseUrl    = $baseUrl;
        $this->logoUrl    = self::safeAssetUrl($logoUrl);
        $this->faviconUrl = self::safeAssetUrl($faviconUrl);
        // A BCP 47 tag for <html lang>: language lower case, region upper case, a hyphen between (fr_be -> fr-BE).
        $this->locale     = preg_match('/^[a-z]{2}([_-][A-Za-z]{2})?$/', $locale) === 1
            ? preg_replace_callback('/^([A-Za-z]{2})(?:[_-]([A-Za-z]{2}))?$/', static fn(array $m): string => strtolower($m[1]) . (isset($m[2]) ? '-' . strtoupper($m[2]) : ''), $locale)
            : 'en';
        $this->seo        = $seo ?? SiteSeoDefaults::none();
    }

    /**
     * Build from the running install: `SLATE_URL` + the CURRENT tenant's branding.
     * Every read is best-effort — branding can never break a render.
     */
    /** The language with a region (fr + fr-BE = fr-BE), when the region belongs to that same language; else the language. */
    public static function withRegion(string $locale, string $tag): string
    {
        $tag = trim($tag);
        if (preg_match('/^([A-Za-z]{2})[_-]([A-Za-z]{2})$/', $tag, $m) === 1
            && strtolower($m[1]) === strtolower(substr($locale, 0, 2))) {
            return strtolower($m[1]) . '-' . strtoupper($m[2]);
        }
        return $locale;
    }

    public static function fromEnvironment(): self
    {
        $base = self::configuredBaseUrl(defined('SLATE_URL') ? (string) \SLATE_URL : '');
        $name = 'Kohevo';
        $logo = '';
        $favicon = '';
        $locale = 'en';
        try {
            $name    = TenantBranding::siteName();
            $logo    = TenantBranding::logoUrls()['light'] ?? '';
            $favicon = TenantBranding::faviconUrl();
        } catch (\Throwable $ignored) {
            // Branding is presentation only; fall back to neutral values.
        }
        try {
            if (class_exists('I18n')) {
                $locale = (string) \I18n::currentLocale();
            }
        } catch (\Throwable $ignored) {
        }
        // Optional region for <html lang> (setting `site_html_lang`, e.g. fr-BE): only on top of the site's own language.
        try {
            $locale = self::withRegion($locale, (string) (\Database::setting('site_html_lang') ?? ''));
        } catch (\Throwable $ignored) {
        }
        return new self($base, $name, $logo, $favicon, $locale, self::DEFAULT_SITE_KEY, SiteSeoDefaults::fromSettings());
    }

    /**
     * The install's configured base URL when it is a valid absolute http(s)
     * URL, else '' — Studio then fails closed (no canonical, no og:url, no
     * relative og:image) instead of rendering against a malformed or missing
     * base (Phase 9B). Never derived from the request (Host header).
     */
    public static function configuredBaseUrl(string $configured): string
    {
        $base = rtrim(trim($configured), '/');
        return ($base !== '' && preg_match(self::BASE_URL_PATTERN, $base) === 1) ? $base : '';
    }

    /** Absolute URL for a site-relative path (`/about`). Relative when the base URL is unknown. */
    public function absoluteUrl(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    /** The install's folder (`/site` for `https://example.com/site`), or '' when it sits at the domain root. */
    public function basePath(): string
    {
        $path = $this->baseUrl === '' ? null : parse_url($this->baseUrl, PHP_URL_PATH);
        return is_string($path) ? rtrim($path, '/') : '';
    }

    public function host(): ?string
    {
        if ($this->baseUrl === '') {
            return null;
        }
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : null;
    }

    /** Deterministic fingerprint of everything in this context that can change rendered output. */
    public function fingerprint(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'base_url'    => $this->baseUrl,
            'favicon_url' => $this->faviconUrl,
            'locale'      => $this->locale,
            'logo_url'    => $this->logoUrl,
            'seo'         => $this->seo->toArray(),
            'site_key'    => $this->siteKey,
            'site_name'   => $this->siteName,
        ]));
    }

    private static function safeAssetUrl(string $url): string
    {
        $url = trim($url);
        return ($url !== '' && FieldSchema::isSafeUrl($url)) ? $url : '';
    }
}
