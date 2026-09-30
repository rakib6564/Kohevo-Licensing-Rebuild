<?php
/**
 * Kohevo Studio (studio-builder) — Public runtime (anonymous visitors).
 *
 *   incoming path (after every plugin prefix failed to match)
 *     -> single-segment slug, not reserved          else: not ours (null)
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
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\StudioRenderService;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Services\Licensing\EntitlementService;
use Slate\Tenancy\TenantContext;

final class StudioPublicRuntime
{
    public const ENTITLEMENT_KEY = 'studio-builder';

    private readonly \Closure $entitlementCheck;

    /**
     * @param ?\Closure $entitlementCheck fn(int $tenantId, string $moduleKey): bool — defaults to EntitlementService::canAccess()
     */
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PageRepository $pages,
        private readonly StudioRenderService $renderer,
        private readonly StudioReservedRoutes $reserved,
        ?\Closure $entitlementCheck = null,
        private readonly ?StudioSitemapService $sitemap = null,
    ) {
        $this->entitlementCheck = $entitlementCheck
            ?? static fn(int $tenantId, string $moduleKey): bool => EntitlementService::canAccess($tenantId, $moduleKey);
    }

    public function handlePath(string $path, ?string $ifNoneMatch = null): ?PublicResponse
    {
        $file = $this->seoFileFromPath($path);
        if ($file !== null) {
            return $this->serveSeoFile($file, $ifNoneMatch);
        }
        $slug = $this->slugFromPath($path);
        if ($slug === null) {
            return null;
        }
        return $this->serve(fn(): ?array => $this->findPublishedBySlug($slug), $ifNoneMatch);
    }

    public function handleHomepage(?string $ifNoneMatch = null): ?PublicResponse
    {
        return $this->serve(fn(): ?array => $this->pages->findPublishedHomepage(StudioRenderService::PUBLIC_PAGE_TYPES), $ifNoneMatch);
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

    private function serveSeoFile(string $file, ?string $ifNoneMatch): ?PublicResponse
    {
        if ($this->sitemap === null || !$this->tenants->isScoped()) {
            return null;
        }
        $tenantId = $this->tenants->id();
        if ($tenantId <= 0 || !$this->isEntitled($tenantId, self::ENTITLEMENT_KEY)) {
            return null;
        }
        try {
            $site = $this->sitemap->site();
            if ($file === 'robots.txt') {
                $type = 'text/plain; charset=utf-8';
                $body = StudioSitemapService::robotsTxt($site);
            } else {
                if ($site->baseUrl === '') {
                    return null; // no configured base URL: no sitemap rather than one on an invented host
                }
                $type = 'application/xml; charset=utf-8';
                $body = StudioSitemapService::xml($this->sitemap->entries($site));
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

        if ($result->etag !== null && $ifNoneMatch !== null && self::etagMatches($ifNoneMatch, $result->etag)) {
            return PublicResponse::notModified($result->headers);
        }
        return PublicResponse::ok($result->headers, $result->html);
    }

    /** @return ?array<string, mixed> */
    private function findPublishedBySlug(string $slug): ?array
    {
        foreach (StudioRenderService::PUBLIC_PAGE_TYPES as $type) {
            $row = $this->pages->findBySlug($slug, $type);
            if ($row !== null && ($row['status'] ?? null) === 'published' && !empty($row['published_revision_id'])) {
                return $row;
            }
        }
        return null;
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
        if (\function_exists('slate_log')) {
            \slate_log('Studio public ' . $stage . ' failed: ' . get_class($e), 'error');
        }
    }
}
