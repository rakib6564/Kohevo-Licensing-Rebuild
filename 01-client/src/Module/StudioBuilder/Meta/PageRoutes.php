<?php
/**
 * Kohevo Studio — the public address of a page.
 *
 * A page of a content type whose URL route prefix is set lives under that prefix (`/events/{slug}`); every other page
 * lives at `/{slug}`, and the homepage at `/`. With the site's trailing-slash setting on, page addresses end in `/`
 * (`/contact/`), which is how a site moved from WordPress keeps the URLs search engines already know.
 *
 * Canonical tags, the sitemap, Query Loop links and the editor's link to the live page all ask this class, and the
 * public runtime serves what it answers: `/events/{slug}` for a page of that type, and a 301 from `/{slug}` to it.
 *
 * A prefix is one to three slug-shaped segments (`events`, `fr/evenements`). A type whose route is empty or `/` has no
 * prefix, and so does one whose route is not a valid prefix or starts with an address Theme Builder answers itself.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Meta;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Domain\PageAddress;

final class PageRoutes
{
    public const SETTING_TRAILING_SLASH = 'studio_url_trailing_slash';
    public const MAX_PREFIX_SEGMENTS = 3;

    /** First segments Theme Builder routes itself (archives, search); a prefix may not start with one. */
    private const ARCHIVE_SEGMENTS = ['category', 'tag', 'author', 'search'];

    /** @var array<int, array{prefixes?: array<string, string>, types?: array<int, string>, slash?: bool}> per-request cache by tenant */
    private static array $cache = [];

    /** A route as a prefix: '' for none, null when it cannot be one. */
    public static function normalizePrefix(mixed $route): ?string
    {
        if (!is_string($route)) {
            return null;
        }
        $route = strtolower(trim(trim($route), '/'));
        if ($route === '') {
            return '';
        }
        $segments = explode('/', $route);
        if (count($segments) > self::MAX_PREFIX_SEGMENTS || in_array($segments[0], self::ARCHIVE_SEGMENTS, true)) {
            return null;
        }
        foreach ($segments as $segment) {
            if (preg_match(PageAddress::SLUG_PATTERN, $segment) !== 1) {
                return null;
            }
        }
        return implode('/', $segments);
    }

    /** @return array<string, string> content type => its prefix, for the types that have one */
    public static function prefixes(int $tenantId): array
    {
        if (!isset(self::$cache[$tenantId]['prefixes'])) {
            $out = [];
            try {
                foreach (PageMeta::postTypes($tenantId) as $key => $type) {
                    $prefix = self::normalizePrefix($type['route'] ?? '');
                    if (is_string($prefix) && $prefix !== '') {
                        $out[(string) $key] = $prefix;
                    }
                }
            } catch (\Throwable) {
                // No settings to read (no database): every page keeps its plain `/{slug}`.
            }
            self::$cache[$tenantId]['prefixes'] = $out;
        }
        return self::$cache[$tenantId]['prefixes'];
    }

    /** The content type of a page, '' for none. Every page's type is read in one query, once per request. */
    public static function typeOfPage(int $tenantId, int $pageId): string
    {
        if (!isset(self::$cache[$tenantId]['types'])) {
            $types = [];
            try {
                $rows = Database::all('SELECT setting_key, setting_value FROM settings WHERE tenant_id = ? AND setting_key LIKE ?', [$tenantId, PageMeta::SETTING_PREFIX . '%']);
            } catch (\Throwable) {
                $rows = [];
            }
            foreach ($rows as $row) {
                $data = json_decode((string) $row['setting_value'], true);
                $type = is_array($data) && is_string($data['type'] ?? null) ? $data['type'] : '';
                if ($type !== '') {
                    $types[(int) substr((string) $row['setting_key'], strlen(PageMeta::SETTING_PREFIX))] = $type;
                }
            }
            self::$cache[$tenantId]['types'] = $types;
        }
        return self::$cache[$tenantId]['types'][$pageId] ?? '';
    }

    /** The prefix a page lives under, '' for none. */
    public static function prefixForPage(int $tenantId, int $pageId): string
    {
        if ($pageId <= 0) {
            return '';
        }
        $type = self::typeOfPage($tenantId, $pageId);
        return $type !== '' ? (self::prefixes($tenantId)[$type] ?? '') : '';
    }

    public static function trailingSlash(int $tenantId): bool
    {
        if (!isset(self::$cache[$tenantId]['slash'])) {
            try {
                self::$cache[$tenantId]['slash'] = (string) Database::setting(self::SETTING_TRAILING_SLASH, $tenantId) === '1';
            } catch (\Throwable) {
                self::$cache[$tenantId]['slash'] = false;
            }
        }
        return self::$cache[$tenantId]['slash'];
    }

    /** The public path of an item a plugin keeps itself (a Shop product): `/{prefix}/{slug}`, with the site's trailing slash. */
    public static function itemPath(int $tenantId, string $prefix, string $slug): string
    {
        $path = '/' . trim($prefix, '/') . '/' . ltrim($slug, '/');
        return $tenantId > 0 && self::trailingSlash($tenantId) ? $path . '/' : $path;
    }

    /** A page's public path: `/` for the homepage, else `/{prefix}/{slug}` or `/{slug}`, with the site's trailing slash. */
    public static function path(int $tenantId, int $pageId, string $slug, string $routeMode): string
    {
        if ($routeMode === 'homepage') {
            return '/';
        }
        $prefix = $tenantId > 0 ? self::prefixForPage($tenantId, $pageId) : '';
        $path = '/' . ($prefix !== '' ? $prefix . '/' : '') . ltrim($slug, '/');
        return $tenantId > 0 && self::trailingSlash($tenantId) ? $path . '/' : $path;
    }

    /**
     * A request path of two or more segments as a prefix and a page slug, else null.
     *
     * @return ?array{prefix: string, slug: string}
     */
    public static function split(string $path): ?array
    {
        if (($q = strpos($path, '?')) !== false) {
            $path = substr($path, 0, $q);
        }
        $segments = explode('/', trim(trim($path), '/'));
        if (count($segments) < 2 || count($segments) > self::MAX_PREFIX_SEGMENTS + 1 || in_array('', $segments, true)) {
            return null;
        }
        $slug = (string) array_pop($segments);
        $prefix = self::normalizePrefix(implode('/', $segments));
        if ($prefix === null || $prefix === '' || preg_match(PageAddress::SLUG_PATTERN, $slug) !== 1) {
            return null;
        }
        return ['prefix' => $prefix, 'slug' => $slug];
    }

    /** Drop what this request has read, after a type, a page's type or the setting changes. */
    public static function forget(?int $tenantId = null): void
    {
        if ($tenantId === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$tenantId]);
        }
    }
}
