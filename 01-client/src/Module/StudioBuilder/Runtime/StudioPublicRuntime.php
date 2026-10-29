<?php
/**
 * Kohevo Studio (studio-builder) — Public runtime (anonymous visitors).
 *
 *   incoming path (after every plugin prefix failed to match)
 *     -> single-segment slug, not reserved          else: not ours (null)
 *        (or `{prefix}/{slug}` for a page of a content type with a URL route prefix — PageRoutes —
 *         and a 301 from that page's `/{slug}` to it)
 *     -> tenant from the server-side TenantContext   (never the request)
 *     -> studio-builder entitlement (no bypass)      else: not ours (null)
 *     -> tenant-scoped page lookup: `page`, then `landing`, status=published
 *     -> PUBLISHED revision only                     else: not ours (null)
 *     -> tenant site locale pinned (StudioPublicLocale — never the visitor's)
 *     -> compile (cached artifact) -> fill -> assemble
 *     -> 200 / 304 with public cache headers
 *
 * Phase 9C: `robots.txt` and `sitemap.xml` are answered here too, for the
 * same tenant/entitlement gate (StudioSitemapService; base URL from
 * SLATE_URL only).
 *
 * Anti-enumeration: every "no" before a published page is confirmed —
 * unknown slug, draft-only page, archived page, unlicensed Studio, missing
 * tenant, lookup error — returns null, and the caller renders the platform's
 * ordinary 404. No Studio-specific status, header, id, or message is ever
 * emitted for a page that is not public. After a published page is confirmed
 * (its existence is public knowledge), a render failure takes the generic
 * error path with no internal detail.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Meta\PageRoutes;
use Slate\Module\StudioBuilder\Registry\PluginSingleInterface;
use Slate\Module\StudioBuilder\Registry\PluginWidgetCatalog;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\StudioRenderService;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Theme\TemplatePreviewContext;
use Slate\Module\StudioBuilder\Theme\ThemeTemplateResolver;
use Slate\Services\Licensing\EntitlementService;
use Slate\Tenancy\TenantContext;

final class StudioPublicRuntime
{
    public const ENTITLEMENT_KEY = 'studio-builder';

    private readonly \Closure $entitlementCheck;
    private readonly \Closure $pluginSingles;

    /**
     * @param ?\Closure $entitlementCheck fn(int $tenantId, string $moduleKey): bool — defaults to EntitlementService::canAccess()
     * @param ?\Closure $pluginSingles    fn(): list<PluginSingleInterface> — defaults to PluginWidgetCatalog::singles()
     */
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PageRepository $pages,
        private readonly StudioRenderService $renderer,
        private readonly StudioReservedRoutes $reserved,
        ?\Closure $entitlementCheck = null,
        private readonly ?StudioSitemapService $sitemap = null,
        private readonly ?ThemeTemplateResolver $themeTemplates = null,
        private readonly ?StudioRedirects $redirects = null,
        ?\Closure $pluginSingles = null,
    ) {
        $this->entitlementCheck = $entitlementCheck
            ?? static fn(int $tenantId, string $moduleKey): bool => EntitlementService::canAccess($tenantId, $moduleKey);
        $this->pluginSingles = $pluginSingles ?? static fn(): array => PluginWidgetCatalog::singles();
    }

    /** @param ?string $accept the request's `Accept` header: a browser gets the sitemap as a readable page, a crawler as XML */
    public function handlePath(string $path, ?string $ifNoneMatch = null, ?string $accept = null): ?PublicResponse
    {
        $file = $this->seoFileFromPath($path);
        if ($file !== null) {
            // `?format=xml` is the raw XML for anyone, whatever their browser asks for.
            $rawXml = preg_match('/[?&]format=xml(?:&|$)/', $path) === 1;
            return $this->serveSeoFile($file, $ifNoneMatch, $rawXml ? null : $accept);
        }

        $redirect = $this->redirectFor($path);
        if ($redirect !== null) {
            return $redirect;
        }

        // Theme Builder: Taxonomy archive routing (e.g. category/Engineering, tag/dev, author/10)
        $trimmedPath = trim($path, '/');
        $queryString = '';
        if (($q = strpos($trimmedPath, '?')) !== false) {
            $queryString = substr($trimmedPath, $q + 1);
            $trimmedPath = substr($trimmedPath, 0, $q);
        }
        if ($this->themeTemplates !== null && preg_match('#^(category|tag|author)/([^/]+)$#i', $trimmedPath, $m)) {
            return $this->handleArchive($m[1], urldecode($m[2]), $ifNoneMatch);
        }

        // Theme Builder: Search routing
        if ($this->themeTemplates !== null && ($trimmedPath === 'search')) {
            $query = '';
            if ($queryString !== '') {
                parse_str($queryString, $qp);
                $query = isset($qp['q']) ? (string) $qp['q'] : (isset($qp['s']) ? (string) $qp['s'] : '');
            }
            return $this->handleSearch($query, $ifNoneMatch);
        }

        // An item a plugin keeps itself (a Shop product) lives at `/{its prefix}/{slug}`, shown by its Single template.
        $item = $this->handlePluginSingle($trimmedPath, $ifNoneMatch);
        if ($item !== false) {
            return $item;
        }

        // A page of a content type with a URL route prefix lives at `/{prefix}/{slug}`.
        $typed = PageRoutes::split($trimmedPath);
        if ($typed !== null) {
            if ($this->reserved->isReserved(explode('/', $typed['prefix'])[0])) {
                return null;
            }
            return $this->serve(fn(): ?array => $this->findPublishedTyped($typed['prefix'], $typed['slug']), $ifNoneMatch);
        }

        $slug = $this->slugFromPath($path);
        if ($slug === null) {
            return null;
        }
        return $this->movedUnderPrefix($slug)
            ?? $this->serve(fn(): ?array => $this->findPublishedBySlug($slug), $ifNoneMatch);
    }

    /** A 301 from `/{slug}` to `/{prefix}/{slug}` when the published page there lives under its content type's prefix. */
    private function movedUnderPrefix(string $slug): ?PublicResponse
    {
        if (!$this->tenants->isScoped() || $this->tenants->id() <= 0 || !$this->isEntitled($this->tenants->id(), self::ENTITLEMENT_KEY)) {
            return null;
        }
        try {
            $tenantId = $this->tenants->id();
            $row = $this->findPublishedBySlug($slug);
            if ($row === null || ($row['route_mode'] ?? '') === 'homepage' || PageRoutes::prefixForPage($tenantId, (int) $row['id']) === '') {
                return null;
            }
            $path = PageRoutes::path($tenantId, (int) $row['id'], $slug, (string) ($row['route_mode'] ?? ''));
            return StudioPublicLocale::run(fn(): PublicResponse => PublicResponse::redirect(301, $this->renderer->siteContext()->absoluteUrl($path)));
        } catch (\Throwable $e) {
            self::log('prefix-redirect', $e);
            return null;
        }
    }

    /** A Redirects-screen rule for this address, for an entitled tenant (a test may inject the rules; otherwise the tenant's setting). */
    private function redirectFor(string $path): ?PublicResponse
    {
        if (!$this->tenants->isScoped() || $this->tenants->id() <= 0 || !$this->isEntitled($this->tenants->id(), self::ENTITLEMENT_KEY)) {
            return null;
        }
        try {
            $redirects = $this->redirects ?? StudioRedirects::fromSettings($this->tenants->id());
            if (!$redirects->matches($path)) {
                return null;
            }
            // The site context reads the locale, so it is built (only for a matching rule) the way a page render builds it:
            // pinned to the site's locale, so a visitor's ?lang= is never written to their session.
            return StudioPublicLocale::run(fn(): ?PublicResponse => $redirects->responseFor($path, $this->renderer->siteContext()));
        } catch (\Throwable $e) {
            self::log('redirect', $e);
            return null;
        }
    }

    public function handleHomepage(?string $ifNoneMatch = null): ?PublicResponse
    {
        return $this->serve(fn(): ?array => $this->pages->findPublishedHomepage(StudioRenderService::PUBLIC_PAGE_TYPES), $ifNoneMatch);
    }

    public function handleNotFound(?string $ifNoneMatch = null): ?PublicResponse
    {
        $tpl = $this->resolveThemeTemplate(fn(ThemeTemplateResolver $t): ?array => $t->resolveNotFound());
        if ($tpl === null) {
            return null;
        }
        $page = PageAddress::fromRow($tpl['page']);
        return $this->serveTemplate($page, $ifNoneMatch, 404, ['is_404' => true]);
    }

    /**
     * A category, tag or author archive. The term comes from the address: control characters are dropped and a
     * term that is empty, not UTF-8 or longer than a stored term can be is the ordinary 404.
     */
    public function handleArchive(string $type, string $term, ?string $ifNoneMatch = null): ?PublicResponse
    {
        $term = self::archiveTerm($term);
        if ($term === null) {
            return null;
        }
        $tpl = $this->resolveThemeTemplate(fn(ThemeTemplateResolver $t): ?array => $t->resolveArchive($type, $term));
        if ($tpl === null) {
            return null;
        }
        $page = PageAddress::fromRow($tpl['page']);
        return $this->serveTemplate($page, $ifNoneMatch, 200, [
            'archive_type' => $type,
            'term'         => $term,
            'category'     => $type === 'category' ? $term : '',
            'tag'          => $type === 'tag' ? $term : '',
        ]);
    }

    /**
     * `/{prefix}/{slug}` of a plugin single (PluginSingleInterface): false when no plugin claims the prefix (the
     * address is someone else's), else the response, or null (the ordinary 404) when the plugin is not active and
     * licensed, the item is not published, or no published Single template names its content type.
     */
    public function handlePluginSingle(string $path, ?string $ifNoneMatch = null): PublicResponse|null|false
    {
        $path = trim($path, '/');
        $cut = strrpos($path, '/');
        if ($cut === false || $this->themeTemplates === null || !$this->tenants->isScoped()) {
            return false;
        }
        $prefix = strtolower(substr($path, 0, $cut));
        $slug = substr($path, $cut + 1);
        $tenantId = $this->tenants->id();
        try {
            $singles = ($this->pluginSingles)();
        } catch (\Throwable $e) {
            self::log('plugin singles', $e);
            return false;
        }
        foreach ($singles as $single) {
            if (!$single instanceof PluginSingleInterface || PageRoutes::normalizePrefix($single->prefix($tenantId)) !== $prefix || $prefix === '') {
                continue;
            }
            if ($this->reserved->isReserved(explode('/', $prefix)[0]) || preg_match(PageAddress::SLUG_PATTERN, $slug) !== 1) {
                return null;
            }
            if ($tenantId <= 0 || !$this->isEntitled($tenantId, $single->requiredEntitlement())) {
                return null;
            }
            try {
                $found = $single->find($tenantId, $slug);
            } catch (\Throwable $e) {
                self::log('plugin single lookup', $e);
                return null;
            }
            if (!is_array($found) || !is_int($found['id'] ?? null)) {
                return null;
            }
            $type = $single->contentType();
            $tpl = $this->resolveThemeTemplate(fn(ThemeTemplateResolver $t): ?array => $t->resolvePluginSingle($type, $slug));
            if ($tpl === null) {
                return null;
            }
            $item = ['type' => $type, 'url' => PageRoutes::itemPath($tenantId, $prefix, $slug)] + $found;
            return $this->serveTemplate(PageAddress::fromRow($tpl['page']), $ifNoneMatch, 200, [
                'content_type' => $type,
                'single_item'  => $item,
                'post_title'   => (string) ($found['title'] ?? ''),
                'post_url'     => $this->renderer->siteContext()->absoluteUrl($item['url']),
            ]);
        }
        return false;
    }

    public function handleSearch(string $query, ?string $ifNoneMatch = null): ?PublicResponse
    {
        $tpl = $this->resolveThemeTemplate(fn(ThemeTemplateResolver $t): ?array => $t->resolveSearch($query));
        if ($tpl === null) {
            return null;
        }
        $page = PageAddress::fromRow($tpl['page']);
        return $this->serveTemplate($page, $ifNoneMatch, 200, [
            'search_query' => $query,
            'is_search'    => true,
        ]);
    }

    /**
     * A theme template lookup behind the same gate as a page: a scoped tenant licensed for Studio, checked BEFORE
     * any template query runs. A lookup error is "not ours" (the ordinary 404), never an exception.
     *
     * @param \Closure(ThemeTemplateResolver): ?array<string, mixed> $resolve
     * @return ?array{page: array<string, mixed>, revision: array<string, mixed>}
     */
    private function resolveThemeTemplate(\Closure $resolve): ?array
    {
        if ($this->themeTemplates === null || !$this->tenants->isScoped()) {
            return null;
        }
        $tenantId = $this->tenants->id();
        if ($tenantId <= 0 || !$this->isEntitled($tenantId, self::ENTITLEMENT_KEY)) {
            return null;
        }
        try {
            $tpl = $resolve($this->themeTemplates);
        } catch (\Throwable $e) {
            self::log('template lookup', $e);
            return null;
        }
        return is_array($tpl) && is_array($tpl['page'] ?? null) ? $tpl : null;
    }

    /** An archive term from the address, cleaned like a preview sample; null when it cannot be a real term. */
    private static function archiveTerm(string $term): ?string
    {
        if (!mb_check_encoding($term, 'UTF-8')) {
            return null;
        }
        $clean = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $term) ?? '');
        if ($clean === '' || mb_strlen($clean, 'UTF-8') > TemplatePreviewContext::MAX_TEXT) {
            return null;
        }
        return $clean;
    }

    /** A routable slug, or null for anything Studio must not claim. */
    public function slugFromPath(string $path): ?string
    {
        $path = trim($path);
        if (($q = strpos($path, '?')) !== false) {
            $path = substr($path, 0, $q);
        }
        $path = trim($path, '/');
        if ($path === '' || str_contains($path, '/') || strlen($path) > 191) {
            return null;
        }
        if (preg_match(PageAddress::SLUG_PATTERN, $path) !== 1 || $this->reserved->isReserved($path)) {
            return null;
        }
        return $path;
    }

    /** `robots.txt` / `sitemap.xml` (the whole path, nothing else), else null. */
    private function seoFileFromPath(string $path): ?string
    {
        $path = trim($path);
        if (($q = strpos($path, '?')) !== false) {
            $path = substr($path, 0, $q);
        }
        $path = trim($path, '/');
        return ($path === 'robots.txt' || $path === 'sitemap.xml') ? $path : null;
    }

    private function serveSeoFile(string $file, ?string $ifNoneMatch, ?string $accept = null): ?PublicResponse
    {
        if ($this->sitemap === null || !$this->tenants->isScoped()) {
            return null;
        }
        $tenantId = $this->tenants->id();
        if ($tenantId <= 0 || !$this->isEntitled($tenantId, self::ENTITLEMENT_KEY)) {
            return null;
        }
        $readable = false;
        try {
            $site = $this->sitemap->site();
            if ($file === 'robots.txt') {
                $type = 'text/plain; charset=utf-8';
                $body = StudioSitemapService::robotsTxt($site);
            } else {
                if ($site->baseUrl === '') {
                    return null; // no configured base URL: no sitemap rather than one on an invented host
                }
                $entries = $this->sitemap->entries($site);
                if (SitemapPage::prefersHtml($accept)) {
                    $readable = true;
                    $type = 'text/html; charset=utf-8';
                    $body = SitemapPage::html($entries, $site->siteName, $site->absoluteUrl('/'), str_starts_with($site->seo->robotsFor('index,follow'), 'noindex'));
                } else {
                    $type = 'application/xml; charset=utf-8';
                    $body = StudioSitemapService::xml($entries);
                }
            }
        } catch (\Throwable $e) {
            self::log('seo file', $e);
            return null;
        }

        $etag = hash('sha256', $body);
        $headers = [
            'Content-Type'           => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'public, no-cache',
            'ETag'                   => '"' . $etag . '"',
        ];
        if ($file === 'sitemap.xml') {
            // The same address has two representations; a cache must not hand a crawler the page, or a browser the XML.
            $headers['Vary'] = 'Accept';
            if ($readable) {
                $headers['X-Robots-Tag'] = 'noindex';
            }
        }
        if ($ifNoneMatch !== null && self::etagMatches($ifNoneMatch, $etag)) {
            return PublicResponse::notModified($headers);
        }
        return PublicResponse::ok($headers, $body);
    }

    private function serve(callable $lookup, ?string $ifNoneMatch): ?PublicResponse
    {
        if (!$this->tenants->isScoped()) {
            return null;
        }
        $tenantId = $this->tenants->id();
        if ($tenantId <= 0 || !$this->isEntitled($tenantId, self::ENTITLEMENT_KEY)) {
            return null;
        }

        try {
            $row = $lookup();
            $page = is_array($row) ? PageAddress::fromRow($row) : null;
        } catch (\Throwable $e) {
            self::log('lookup', $e);
            return null;
        }
        if ($page === null || !$page->isPublished() || !in_array($page->pageType, StudioRenderService::PUBLIC_PAGE_TYPES, true)) {
            return null;
        }

        try {
            $result = StudioPublicLocale::run(function () use ($tenantId, $page) {
                $context = RenderContext::forPublic(
                    $tenantId,
                    $this->renderer->siteContext(),
                    fn(string $moduleKey): bool => $this->isEntitled($tenantId, $moduleKey),
                );
                return $this->renderer->renderPublished($page, $context);
            });
        } catch (\Throwable $e) {
            self::log('render', $e);
            return PublicResponse::error();
        }
        if ($result === null) {
            return null;
        }

        $headers = $result->headers + self::languageHeader();
        if ($result->etag !== null && $ifNoneMatch !== null && self::etagMatches($ifNoneMatch, $result->etag)) {
            return PublicResponse::notModified($headers);
        }
        return PublicResponse::ok($headers, $result->html);
    }

    /**
     * Serve a specific resolved template page address under an optional status code and dynamic context.
     *
     * @param PageAddress          $page
     * @param ?string              $ifNoneMatch
     * @param int                  $statusCode
     * @param array<string, mixed> $dynamicContext
     */
    private function serveTemplate(PageAddress $page, ?string $ifNoneMatch, int $statusCode = 200, array $dynamicContext = []): ?PublicResponse
    {
        if (!$this->tenants->isScoped()) {
            return null;
        }
        $tenantId = $this->tenants->id();
        if ($tenantId <= 0 || !$this->isEntitled($tenantId, self::ENTITLEMENT_KEY)) {
            return null;
        }

        if (!$page->isPublished()) {
            return null;
        }

        try {
            $result = StudioPublicLocale::run(function () use ($tenantId, $page, $dynamicContext) {
                $context = RenderContext::forPublic(
                    $tenantId,
                    $this->renderer->siteContext(),
                    fn(string $moduleKey): bool => $this->isEntitled($tenantId, $moduleKey),
                    $dynamicContext,
                );
                return $this->renderer->renderPublished($page, $context);
            });
        } catch (\Throwable $e) {
            self::log('render', $e);
            return PublicResponse::error();
        }
        if ($result === null) {
            return null;
        }

        $headers = $result->headers + self::languageHeader();
        if ($result->etag !== null && $ifNoneMatch !== null && self::etagMatches($ifNoneMatch, $result->etag)) {
            return PublicResponse::notModified($headers);
        }
        return $statusCode === 404
            ? PublicResponse::notFound($headers, $result->html)
            : PublicResponse::ok($headers, $result->html);
    }

    /**
     * `Content-Language` = the tenant's public site locale (Phase 9A), i.e.
     * exactly what the page was rendered in and what `<html lang>` says —
     * never the visitor's ?lang= / session / force-locale header. Omitted if
     * the value is not a plain language tag rather than emitted malformed.
     *
     * @return array<string, string>
     */
    private static function languageHeader(): array
    {
        $locale = str_replace('_', '-', StudioPublicLocale::resolve());
        return preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $locale) === 1 ? ['Content-Language' => $locale] : [];
    }

    /**
     * The published page `$slug` when its content type's route prefix is `$prefix`.
     *
     * @return ?array<string, mixed>
     */
    private function findPublishedTyped(string $prefix, string $slug): ?array
    {
        $row = $this->findPublishedBySlug($slug);
        if ($row === null || ($row['route_mode'] ?? '') === 'homepage') {
            return null;
        }
        return PageRoutes::prefixForPage($this->tenants->id(), (int) $row['id']) === $prefix ? $row : null;
    }

    /** @return ?array<string, mixed> */
    private function findPublishedBySlug(string $slug): ?array
    {
        foreach (StudioRenderService::PUBLIC_PAGE_TYPES as $type) {
            $row = $this->pages->findBySlug($slug, $type);
            // Template-ness is read from what was PUBLISHED: a draft that names a template_type must not take the
            // live page offline before anyone publishes it.
            if ($row !== null && ($row['status'] ?? null) === 'published' && !empty($row['published_revision_id'])
                && !self::isThemeTemplate($row, PublishedPageData::settingsOf($this->tenants->id(), $row))) {
                return $row;
            }
        }
        return null;
    }

    /**
     * A theme template (a Single template stored as a page, older rows) lays out other pages; it is never a page
     * of its own at its `template-…` address.
     *
     * Pass `$settings` as the page was PUBLISHED with (`PublishedPageData`); without it the row's settings column is
     * read, which mirrors the current draft.
     *
     * @param array<string, mixed> $row
     * @param ?array<string, mixed> $settings
     */
    public static function isThemeTemplate(array $row, ?array $settings = null): bool
    {
        $settings ??= json_decode((string) ($row['settings_json'] ?? ''), true);
        return is_array($settings) && (!empty($settings['is_theme_template']) || (is_string($settings['template_type'] ?? null) && $settings['template_type'] !== ''));
    }

    private function isEntitled(int $tenantId, string $moduleKey): bool
    {
        try {
            return (bool) ($this->entitlementCheck)($tenantId, $moduleKey);
        } catch (\Throwable $ignored) {
            return false;
        }
    }

    private static function etagMatches(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate === '*' || trim($candidate, '"') === $etag) {
                return true;
            }
        }
        return false;
    }

    private static function log(string $stage, \Throwable $e): void
    {
        StudioLog::failure('public', $stage, $e);
    }
}
