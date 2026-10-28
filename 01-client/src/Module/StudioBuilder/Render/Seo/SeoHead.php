<?php
/**
 * Kohevo Studio (studio-builder) — Server-side SEO head assembly.
 *
 * Built from the canonical document's `seo` object and the page address:
 *
 *  - title        seo.title, falling back to the page title
 *  - description  seo.description
 *  - robots       seo.robots (allowlisted) in Public; ALWAYS `noindex,nofollow`
 *                 in Preview/Editor, whatever the document says
 *  - canonical    Public only. An authored canonical_url is honoured only when
 *                 it stays inside this site (a site-relative path, or an
 *                 absolute URL on the site's own host); anything else — a
 *                 foreign domain, mailto:, a fragment — is replaced by the
 *                 page's own public URL, so an author cannot point the page's
 *                 canonical at another site/tenant.
 *  - og:image     seo.og_image_media_id resolved through the tenant-scoped
 *                 media resolver (missing/foreign media -> no og:image; a
 *                 site-relative URL with no configured base URL -> no og:image).
 *  - no base URL  (APP_URL unset/invalid) -> no canonical and no og:url: never
 *                 another host (Phase 9B).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Seo;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Meta\PageRoutes;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\RenderMode;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class SeoHead
{
    public const PRIVATE_ROBOTS = 'noindex,nofollow';

    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $robots,
        public readonly ?string $canonicalUrl,
        public readonly ?string $ogImageUrl,
        public readonly string $siteName,
        /** @var array<string, string> site-verification meta name => code (public output only) */
        public readonly array $verification = [],
    ) {}

    /**
     * @param array<string, mixed> $seo normalized document seo
     */
    public static function build(array $seo, PageAddress $page, SiteContext $site, RenderMode $mode, MediaResolverInterface $media, int $tenantId = 0): self
    {
        // The Site & SEO screen's settings fill in what the page leaves empty, and its robots can only make a page stricter.
        $defaults = $site->seo;
        $title = trim(is_string($seo['title'] ?? null) ? $seo['title'] : '');
        if ($title === '') {
            $title = $defaults->title($page->title, $site->siteName);
        }
        $description = is_string($seo['description'] ?? null) ? trim($seo['description']) : '';
        if ($description === '') {
            $description = $defaults->description;
        }

        $robots = $defaults->robotsFor(self::effectiveRobots($seo));

        $ogImage = null;
        $ogId = $seo['og_image_media_id'] ?? null;
        if (!is_int($ogId) || $ogId <= 0) {
            $ogId = $defaults->ogImageMediaId;
            $ogImage = $ogId === null && $defaults->ogImageUrl !== '' ? $defaults->ogImageUrl : null;
        }
        if (is_int($ogId) && $ogId > 0) {
            $resolved = $media->resolveImage($ogId);
            if ($resolved !== null) {
                // og:image must be absolute: a site-relative media URL needs the
                // configured base URL, and without one there is no og:image (fail closed).
                $ogImage = str_starts_with($resolved->url, '/')
                    ? ($site->baseUrl !== '' ? $site->absoluteUrl($resolved->url) : null)
                    : $resolved->url;
            }
        }

        $head = new self(
            $title,
            $description,
            $robots,
            self::canonical($seo['canonical_url'] ?? null, $site, self::publicPath($page, $tenantId)),
            $ogImage,
            $site->siteName,
            $defaults->verification,
        );

        return $head->forMode($mode);
    }

    /** The robots directive a document publishes: its allowlisted `seo.robots`, else `index,follow`. Shared with the sitemap. */
    public static function effectiveRobots(array $seo): string
    {
        return is_string($seo['robots'] ?? null) && in_array($seo['robots'], CanonicalDocumentSchema::ALLOWED_ROBOTS_DIRECTIVES, true)
            ? $seo['robots']
            : 'index,follow';
    }

    /** The page's own public path (PageRoutes): `/` for the homepage, `/{slug}` or `/{prefix}/{slug}` otherwise. */
    public static function publicPath(PageAddress $page, int $tenantId = 0): string
    {
        return PageRoutes::path($tenantId, (int) $page->id, $page->slug, $page->routeMode);
    }

    public static function canonical(mixed $authored, SiteContext $site, string $publicPath): ?string
    {
        if ($site->baseUrl === '') {
            return null;
        }
        $default = $site->absoluteUrl($publicPath);
        if (!is_string($authored) || trim($authored) === '' || !FieldSchema::isSafeUrl($authored)) {
            return $default;
        }
        $authored = trim($authored);
        if (str_starts_with($authored, '/') && !str_starts_with($authored, '//')) {
            return $site->absoluteUrl($authored);
        }
        if (preg_match('~^https?://~i', $authored) === 1) {
            $host = parse_url($authored, PHP_URL_HOST);
            if (is_string($host) && $site->host() !== null && strcasecmp($host, $site->host()) === 0) {
                return $authored;
            }
        }
        return $default;
    }

    /**
     * One compiled template serves every archive term and every search, so the head it was compiled with names the
     * template page. For an archive the title is the term and the canonical is that archive's own address (a
     * `/category/*` page is no longer a duplicate of one canonical); a search or 404 result is never indexed and
     * declares no canonical. Any other page is returned unchanged.
     */
    public function forListing(RenderContext $context): self
    {
        $type = $context->attribute('archive_type');
        $term = $context->attribute('term');
        if (is_string($type) && in_array($type, ['category', 'tag', 'author'], true) && is_string($term) && trim($term) !== '') {
            $term = trim($term);
            return new self(
                $term . ($this->siteName !== '' ? ' | ' . $this->siteName : ''),
                $this->description,
                $this->robots,
                $context->site->baseUrl !== '' ? $context->site->absoluteUrl('/' . $type . '/' . rawurlencode($term)) : null,
                $this->ogImageUrl,
                $this->siteName,
                $this->verification,
            );
        }
        if ($context->attribute('is_search') === true || $context->attribute('is_404') === true) {
            $query = $context->attribute('search_query');
            $title = is_string($query) && trim($query) !== '' ? trim($query) . ($this->siteName !== '' ? ' | ' . $this->siteName : '') : $this->title;
            return new self($title, $this->description, 'noindex,follow', null, $this->ogImageUrl, $this->siteName, $this->verification);
        }
        return $this;
    }

    /** Preview/Editor output is never indexable and never declares a canonical. */
    public function forMode(RenderMode $mode): self
    {
        if (!$mode->isPrivate()) {
            return $this;
        }
        return new self($this->title, $this->description, self::PRIVATE_ROBOTS, null, $this->ogImageUrl, $this->siteName);
    }

    public function tags(): string
    {
        $tags = '<title>' . Html::e($this->title) . '</title>'
            . ($this->description !== '' ? '<meta name="description" content="' . Html::e($this->description) . '">' : '')
            . '<meta name="robots" content="' . Html::e($this->robots) . '">'
            . ($this->canonicalUrl !== null ? '<link rel="canonical" href="' . Html::e($this->canonicalUrl) . '">' : '')
            . '<meta property="og:type" content="website">'
            . '<meta property="og:title" content="' . Html::e($this->title) . '">'
            . ($this->siteName !== '' ? '<meta property="og:site_name" content="' . Html::e($this->siteName) . '">' : '')
            . ($this->description !== '' ? '<meta property="og:description" content="' . Html::e($this->description) . '">' : '')
            . ($this->canonicalUrl !== null ? '<meta property="og:url" content="' . Html::e($this->canonicalUrl) . '">' : '')
            . ($this->ogImageUrl !== null ? '<meta property="og:image" content="' . Html::e($this->ogImageUrl) . '">' : '')
            . '<meta name="twitter:card" content="' . ($this->ogImageUrl !== null ? 'summary_large_image' : 'summary') . '">';
        foreach ($this->verification as $name => $code) {
            $tags .= '<meta name="' . Html::e($name) . '" content="' . Html::e($code) . '">';
        }
        return $tags;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'canonical_url' => $this->canonicalUrl,
            'description'   => $this->description,
            'og_image_url'  => $this->ogImageUrl,
            'robots'        => $this->robots,
            'site_name'     => $this->siteName,
            'title'         => $this->title,
            'verification'  => $this->verification,
        ];
    }

    /**
     * Rehydrate from a stored compilation. Values are re-checked, not trusted:
     * robots must be allowlisted and URLs must still pass the URL allowlist.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $robots = is_string($data['robots'] ?? null) && in_array($data['robots'], CanonicalDocumentSchema::ALLOWED_ROBOTS_DIRECTIVES, true)
            ? $data['robots']
            : self::PRIVATE_ROBOTS;
        return new self(
            is_string($data['title'] ?? null) ? $data['title'] : '',
            is_string($data['description'] ?? null) ? $data['description'] : '',
            $robots,
            Html::safeUrl($data['canonical_url'] ?? null),
            Html::safeUrl($data['og_image_url'] ?? null),
            is_string($data['site_name'] ?? null) ? $data['site_name'] : '',
            self::verificationFrom($data['verification'] ?? null),
        );
    }

    /** @return array<string, string> only the two known verification tags, with codes of the allowed shape */
    private static function verificationFrom(mixed $stored): array
    {
        $out = [];
        foreach (['google-site-verification', 'msvalidate.01'] as $name) {
            $code = is_array($stored) ? ($stored[$name] ?? null) : null;
            if (is_string($code) && preg_match(SiteSeoDefaults::VERIFICATION_PATTERN, $code) === 1) {
                $out[$name] = $code;
            }
        }
        return $out;
    }
}
