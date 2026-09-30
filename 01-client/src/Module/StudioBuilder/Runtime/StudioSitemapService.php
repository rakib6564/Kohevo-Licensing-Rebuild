<?php
/**
 * Kohevo Studio (studio-builder) — sitemap.xml and robots.txt for the current tenant.
 *
 * Both are derived ONLY from server-side state: the tenant in `TenantContext`,
 * the configured `SLATE_URL` (SiteContext::baseUrl — never the Host header),
 * and each page's PUBLISHED revision. Neither reads `studiobuilder_pages.seo_json`
 * (the draft copy), a draft revision, or another tenant's rows, and neither
 * compiles or renders a page.
 *
 * A page is listed only when a visitor can actually reach it and it asks to be
 * indexed:
 *   - published (status + published revision), type `page` / `landing`;
 *   - reachable: the homepage is listed once, as `/`; any other page needs a
 *     routable, non-reserved slug, and a `landing` is not listed when a
 *     published `page` with the same slug shadows it (the runtime serves the
 *     `page`);
 *   - published `seo.robots` is not `noindex,*`;
 *   - its effective canonical (SeoHead::canonical — the same policy the page
 *     head uses) is the page's own URL. A page whose authored canonical names
 *     another URL of this site is a duplicate of that URL and is not listed; a
 *     foreign-host canonical is already replaced by the page's own URL by that
 *     policy, so it can never put a foreign URL in the sitemap.
 *
 * Cost: one page query plus one revision query per 500 pages.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Render\Seo\SeoHead;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\StudioRenderService;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Tenancy\TenantContext;

final class StudioSitemapService
{
    /** The sitemaps.org limit for one file. */
    public const MAX_URLS = 50000;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PageRepository $pages,
        private readonly RevisionRepository $revisions,
        private readonly StudioReservedRoutes $reserved,
        private readonly StudioRenderService $renderer,
    ) {}

    /** The current tenant's site context (server-side base URL and branding). */
    public function site(): SiteContext
    {
        return $this->renderer->siteContext();
    }

    /**
     * @return list<array{loc: string, lastmod: ?string}> in a deterministic order: homepage first, then by slug
     */
    public function entries(SiteContext $site): array
    {
        if ($site->baseUrl === '' || !$this->tenants->isScoped() || $this->tenants->id() <= 0) {
            return [];
        }

        $rows = $this->pages->publishedForSitemap(StudioRenderService::PUBLIC_PAGE_TYPES, self::MAX_URLS + 1);

        // The homepage the runtime serves at `/`: the most recently published homepage-mode page.
        $homeId = null;
        $homeKey = null;
        $pageSlugs = [];
        foreach ($rows as $row) {
            if (($row['page_type'] ?? '') === 'page') {
                $pageSlugs[(string) $row['slug']] = true;
            }
            if (($row['route_mode'] ?? '') === 'homepage') {
                $key = [(string) ($row['published_at'] ?? ''), (int) $row['id']];
                if ($homeKey === null || $key > $homeKey) {
                    $homeKey = $key;
                    $homeId = (int) $row['id'];
                }
            }
        }

        $candidates = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $slug = (string) $row['slug'];
            if (($row['route_mode'] ?? '') === 'homepage') {
                if ($id !== $homeId) {
                    continue; // a losing homepage-mode page is only a duplicate of `/`
                }
                $path = '/';
            } else {
                if (preg_match(PageAddress::SLUG_PATTERN, $slug) !== 1 || $this->reserved->isReserved($slug)) {
                    continue;
                }
                if (($row['page_type'] ?? '') === 'landing' && isset($pageSlugs[$slug])) {
                    continue; // shadowed by the published `page` of the same slug
                }
                $path = '/' . $slug;
            }
            $candidates[$id] = ['row' => $row, 'path' => $path];
        }

        $documents = $this->revisions->documentsByIds(array_map(
            static fn(array $c): int => (int) $c['row']['published_revision_id'],
            array_values($candidates),
        ));

        $entries = [];
        foreach ($candidates as $candidate) {
            $row = $candidate['row'];
            $json = $documents[(int) $row['published_revision_id']] ?? null;
            $document = is_string($json) ? json_decode($json, true) : null;
            $seo = is_array($document) && is_array($document['seo'] ?? null) ? $document['seo'] : null;
            if ($seo === null || str_starts_with(SeoHead::effectiveRobots($seo), 'noindex')) {
                continue;
            }
            $own = $site->absoluteUrl($candidate['path']);
            $canonical = SeoHead::canonical($seo['canonical_url'] ?? null, $site, $candidate['path']);
            if ($canonical === null || !self::sameUrl($canonical, $own)) {
                continue;
            }
            $entries[$own] ??= [
                'home'    => $candidate['path'] === '/',
                'loc'     => $own,
                'lastmod' => self::lastmod($row['published_at'] ?? null),
            ];
        }

        usort($entries, static fn(array $a, array $b): int => [$b['home'], $a['loc']] <=> [$a['home'], $b['loc']]);
        $entries = array_slice($entries, 0, self::MAX_URLS);
        return array_map(static fn(array $e): array => ['loc' => $e['loc'], 'lastmod' => $e['lastmod']], $entries);
    }

    /** The sitemap document for the given entries (pure, deterministic, XML-escaped). */
    public static function xml(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as $entry) {
            $xml .= "  <url>\n    <loc>" . self::escape((string) $entry['loc']) . "</loc>\n";
            if (!empty($entry['lastmod'])) {
                $xml .= '    <lastmod>' . self::escape((string) $entry['lastmod']) . "</lastmod>\n";
            }
            $xml .= "  </url>\n";
        }
        return $xml . "</urlset>\n";
    }

    /**
     * robots.txt: everything crawlable (per-page indexing is each page's own
     * `robots` SEO setting), plus the sitemap location ONLY when the
     * configured base URL is valid — never a guessed or request-derived host.
     */
    public static function robotsTxt(SiteContext $site): string
    {
        $txt = "User-agent: *\nAllow: /\n";
        if ($site->baseUrl !== '') {
            $txt .= "\nSitemap: " . $site->absoluteUrl('/sitemap.xml') . "\n";
        }
        return $txt;
    }

    private static function escape(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** `Y-m-d` of a DATETIME (date precision only: the stored value has no time zone), or null. */
    private static function lastmod(mixed $publishedAt): ?string
    {
        return is_string($publishedAt) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $publishedAt, $m) === 1 ? $m[1] : null;
    }

    /** Whether two absolute URLs are the same address (scheme/host case, a trailing slash and the fragment do not matter). */
    private static function sameUrl(string $a, string $b): bool
    {
        $norm = static function (string $url): ?string {
            $p = parse_url($url);
            if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
                return null;
            }
            return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '')
                . rtrim($p['path'] ?? '', '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        };
        $na = $norm($a);
        return $na !== null && $na === $norm($b);
    }
}
