<?php
/**
 * Kohevo Studio (studio-builder) — the site-wide SEO settings (Site & SEO screen).
 *
 * What a page falls back to when its own SEO fields are empty, plus what applies to every page:
 *
 *  - title format   `%title%` and `%site_name%`, used only when the page has no SEO title of its own
 *  - description    used only when the page has no SEO description
 *  - share image    a media id or an absolute https URL, used only when the page has no share image
 *  - robots         can only make a page stricter: a site set to `noindex` keeps every page out of search
 *  - verification   Google and Bing site-verification codes, as meta tags on public pages
 *
 * Every value is re-checked here, so a bad setting gives no default rather than broken markup.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Seo;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class SiteSeoDefaults
{
    public const VERIFICATION_PATTERN = '/^[A-Za-z0-9_\-]{1,100}$/';

    private function __construct(
        public readonly string $titleFormat,
        public readonly string $description,
        public readonly ?int $ogImageMediaId,
        public readonly string $ogImageUrl,
        public readonly string $robots,
        /** @var array<string, string> meta name => content */
        public readonly array $verification,
    ) {}

    public static function none(): self
    {
        return new self('', '', null, '', 'index,follow', []);
    }

    /** @param array<string, mixed> $values the `studio_seo_*` settings, keyed without the prefix */
    public static function fromValues(array $values): self
    {
        $str = static fn(string $k, int $max): string => mb_substr(trim(is_scalar($values[$k] ?? null) ? (string) $values[$k] : ''), 0, $max, 'UTF-8');

        $format = $str('title_format', 200);
        if ($format !== '' && !str_contains($format, '%title%')) {
            $format = ''; // a format without the page title would give every page the same title
        }
        $mediaId = $str('og_media_id', 20);
        $url = $str('og_image_url', 2000);
        $robots = $str('robots', 40);
        $verification = [];
        foreach (['google_verify' => 'google-site-verification', 'bing_verify' => 'msvalidate.01'] as $key => $meta) {
            $code = $str($key, 100);
            if ($code !== '' && preg_match(self::VERIFICATION_PATTERN, $code) === 1) {
                $verification[$meta] = $code;
            }
        }

        return new self(
            $format,
            $str('description', 300),
            ctype_digit($mediaId) && (int) $mediaId > 0 ? (int) $mediaId : null,
            preg_match('~^https://~i', $url) === 1 && FieldSchema::isSafeUrl($url) ? $url : '',
            in_array($robots, CanonicalDocumentSchema::ALLOWED_ROBOTS_DIRECTIVES, true) ? $robots : 'index,follow',
            $verification,
        );
    }

    /** The current tenant's settings. Best-effort: SEO settings can never break a render. */
    public static function fromSettings(?int $tenantId = null): self
    {
        $values = [];
        try {
            foreach (['title_format', 'description', 'og_media_id', 'og_image_url', 'robots', 'google_verify', 'bing_verify'] as $key) {
                $values[$key] = Database::setting('studio_seo_' . $key, $tenantId);
            }
        } catch (\Throwable) {
            return self::none();
        }
        return self::fromValues($values);
    }

    /** The page title through the site's format, or the page title itself when there is no format. */
    public function title(string $pageTitle, string $siteName): string
    {
        if ($this->titleFormat === '') {
            return $pageTitle;
        }
        return trim(strtr($this->titleFormat, ['%title%' => $pageTitle, '%site_name%' => $siteName]));
    }

    /** The stricter of a page's robots directive and the site's: either side's noindex or nofollow wins. */
    public function robotsFor(string $pageRobots): string
    {
        $noindex  = str_contains($pageRobots, 'noindex') || str_contains($this->robots, 'noindex');
        $nofollow = str_contains($pageRobots, 'nofollow') || str_contains($this->robots, 'nofollow');
        return ($noindex ? 'noindex' : 'index') . ',' . ($nofollow ? 'nofollow' : 'follow');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'description'       => $this->description,
            'og_image_media_id' => $this->ogImageMediaId,
            'og_image_url'      => $this->ogImageUrl,
            'robots'            => $this->robots,
            'title_format'      => $this->titleFormat,
            'verification'      => $this->verification,
        ];
    }
}
