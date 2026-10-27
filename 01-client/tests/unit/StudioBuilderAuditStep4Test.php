<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — admin screens that now do what they say (2026-10-26 builder audit, step 4).
 *
 * Autoloader only. The Site & SEO settings reach the page head, and the Redirects rules answer public requests.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
}

use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\RenderMode;
use Slate\Module\StudioBuilder\Render\Seo\SeoHead;
use Slate\Module\StudioBuilder\Render\Seo\SiteSeoDefaults;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Runtime\StudioRedirects;

final class _Sba4Media implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        return $mediaId === 7 ? new ResolvedMedia(7, '/uploads/media/share.jpg') : null;
    }
}

function sba4_site(array $seo = []): SiteContext
{
    return new SiteContext('https://acme.test/shop', 'Acme', '', '', 'en', SiteContext::DEFAULT_SITE_KEY, SiteSeoDefaults::fromValues($seo));
}

function sba4_page(): PageAddress
{
    return new PageAddress(5, '11111111-2222-4333-8444-555555555555', 'About', 'about', 'page', 'published', 'standalone', 9, 9);
}

unit('site SEO: the title format, description and share image fill in only what the page leaves empty', function (): void {
    $site = sba4_site(['title_format' => '%title% | %site_name%', 'description' => 'Site description', 'og_media_id' => '7']);
    $empty = SeoHead::build([], sba4_page(), $site, RenderMode::Public, new _Sba4Media());
    assert_eq('About | Acme', $empty->title, 'the page title through the format');
    assert_eq('Site description', $empty->description);
    assert_eq('https://acme.test/shop/uploads/media/share.jpg', $empty->ogImageUrl, 'the site share image, absolute');

    $own = SeoHead::build(['title' => 'Our story', 'description' => 'Mine', 'og_image_media_id' => 99], sba4_page(), $site, RenderMode::Public, new _Sba4Media());
    assert_eq('Our story', $own->title, 'a page\'s own SEO title is used as written');
    assert_eq('Mine', $own->description);

    $byUrl = SeoHead::build([], sba4_page(), sba4_site(['og_image_url' => 'https://cdn.test/card.png']), RenderMode::Public, new _Sba4Media());
    assert_eq('https://cdn.test/card.png', $byUrl->ogImageUrl, 'or an absolute share-image URL');
    assert_null(SeoHead::build([], sba4_page(), sba4_site(['og_image_url' => 'javascript:alert(1)']), RenderMode::Public, new _Sba4Media())->ogImageUrl, 'never an unsafe one');
    assert_eq('About', SeoHead::build([], sba4_page(), sba4_site(['title_format' => '%site_name% only']), RenderMode::Public, new _Sba4Media())->title, 'a format without %title% is ignored');
});

unit('site SEO: the site robots setting can only make a page stricter, and the sitemap check follows it', function (): void {
    assert_eq('noindex,follow', SeoHead::build([], sba4_page(), sba4_site(['robots' => 'noindex,follow']), RenderMode::Public, new _Sba4Media())->robots, 'a noindex site keeps an indexable page out');
    assert_eq('noindex,nofollow', SeoHead::build(['robots' => 'noindex,follow'], sba4_page(), sba4_site(['robots' => 'index,nofollow']), RenderMode::Public, new _Sba4Media())->robots, 'both sides combine');
    assert_eq('noindex,follow', SeoHead::build(['robots' => 'noindex,follow'], sba4_page(), sba4_site(), RenderMode::Public, new _Sba4Media())->robots, 'a default site never loosens a page');
    assert_eq('index,follow', SiteSeoDefaults::fromValues(['robots' => 'bogus'])->robots, 'an unknown setting is ignored');
});

unit('site SEO: verification codes become meta tags on public pages only, and survive a stored compilation', function (): void {
    $site = sba4_site(['google_verify' => 'abc_123-XYZ', 'bing_verify' => 'bad code"><script>']);
    $head = SeoHead::build([], sba4_page(), $site, RenderMode::Public, new _Sba4Media());
    assert_true(str_contains($head->tags(), '<meta name="google-site-verification" content="abc_123-XYZ">'), $head->tags());
    assert_true(!str_contains($head->tags(), 'msvalidate') && !str_contains($head->tags(), '<script'), 'a malformed code is dropped');
    assert_eq($head->tags(), SeoHead::fromArray($head->toArray())->tags(), 'the stored head renders the same');
    assert_true(!str_contains(SeoHead::build([], sba4_page(), $site, RenderMode::Preview, new _Sba4Media())->tags(), 'verification'), 'not in previews');
});

unit('site SEO: changing a setting changes the site fingerprint, so stored pages recompile', function (): void {
    assert_true(sba4_site()->fingerprint() !== sba4_site(['description' => 'New'])->fingerprint());
    assert_eq(sba4_site(['description' => 'Same'])->fingerprint(), sba4_site(['description' => 'Same'])->fingerprint());
});

unit('redirects: a rule matches its path in any case, with or without a trailing slash or query, and keeps the site folder', function (): void {
    $rules = StudioRedirects::fromRules([
        ['from' => '/Old-Page/', 'to' => '/new-page', 'code' => 301],
        ['from' => '/promo', 'to' => 'https://shop.test/sale', 'code' => 302],
        ['from' => '/', 'to' => '/elsewhere', 'code' => 301],
        ['from' => '/loop', 'to' => '/LOOP/', 'code' => 301],
        ['from' => '/loop2', 'to' => 'https://ACME.test/shop/loop2', 'code' => 301],
        ['from' => '/bad', 'to' => 'javascript:alert(1)', 'code' => 301],
    ]);
    $site = sba4_site();
    foreach (['old-page', '/old-page', 'OLD-PAGE/', 'old-page?utm=1'] as $path) {
        $r = $rules->responseFor($path, $site);
        assert_true($r !== null && $r->status === 301, "{$path} is redirected");
        assert_eq('https://acme.test/shop/new-page', $r->headers['Location'], 'to the page inside the site folder');
    }
    $promo = $rules->responseFor('promo', $site);
    assert_eq([302, 'https://shop.test/sale', 'no-store'], [$promo->status, $promo->headers['Location'], $promo->headers['Cache-Control']], 'a temporary rule to another site');
    assert_null($rules->responseFor('/', $site), 'the home page is never redirected');
    assert_null($rules->responseFor('loop', $site), 'a rule pointing at itself is ignored');
    assert_null($rules->responseFor('loop2', $site), 'also when written as a full address on this site');
    assert_null($rules->responseFor('bad', $site), 'an unsafe target is ignored');
    assert_null($rules->responseFor('other', $site), 'other addresses are untouched');
    assert_true($rules->matches('/old-page/') && !$rules->matches('/other'));
    assert_null(StudioRedirects::fromRules(['not a rule', ['from' => 5]])->responseFor('x', $site), 'broken stored rules are skipped');
});
