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
 *                 media resolver (missing/foreign media -> no og:image).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Seo;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
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
    ) {}

    /**
     * @param array<string, mixed> $seo normalized document seo
     */
    public static function build(array $seo, PageAddress $page, SiteContext $site, RenderMode $mode, MediaResolverInterface $media): self
    {
        $title = trim(is_string($seo['title'] ?? null) ? $seo['title'] : '');
        if ($title === '') {
            $title = $page->title;
        }
        $description = is_string($seo['description'] ?? null) ? trim($seo['description']) : '';

        $robots = is_string($seo['robots'] ?? null) && in_array($seo['robots'], CanonicalDocumentSchema::ALLOWED_ROBOTS_DIRECTIVES, true)
            ? $seo['robots']
            : 'index,follow';

        $ogImage = null;
        $ogId = $seo['og_image_media_id'] ?? null;
        if (is_int($ogId) && $ogId > 0) {
            $resolved = $media->resolveImage($ogId);
            if ($resolved !== null) {
                $ogImage = str_starts_with($resolved->url, '/') && $site->baseUrl !== ''
                    ? $site->absoluteUrl($resolved->url)
                    : $resolved->url;
            }
        }

        $head = new self(
            $title,
            $description,
            $robots,
            self::canonical($seo['canonical_url'] ?? null, $site, self::publicPath($page)),
            $ogImage,
            $site->siteName,
        );

        return $head->forMode($mode);
    }

    /** The page's own public path: `/` for the homepage route, `/{slug}` otherwise. */
    public static function publicPath(PageAddress $page): string
    {
        return $page->routeMode === 'homepage' ? '/' : '/' . $page->slug;
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
        );
    }
}
