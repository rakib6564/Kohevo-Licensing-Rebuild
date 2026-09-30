<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Phase 9C SEO settings,
 * sitemap.xml and robots.txt.
 *
 * Autoloader only (no database): the canonical document's SEO validation
 * matrix (title/description limits, robots enum, canonical URL safety, og
 * media id), the published canonical policy for every hostile shape, the
 * sitemap XML generator and robots.txt text, the reserved-route list, and
 * static guarantees that the sitemap code never reads `seo_json` or the Host
 * header.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Seo\SeoHead;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Runtime\StudioReservedRoutes;
use Slate\Module\StudioBuilder\Runtime\StudioSitemapService;

/** @return list<string> the error codes the validator reports for a document with this `seo` patch */
function sbs9u_seo_errors(array $seo, array $options = []): array
{
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'T');
    $doc['seo'] = array_merge($doc['seo'], $seo);
    $result = DocumentValidator::validate($doc, ModuleBlockDefinitions::studioRegistry(), $options);
    return array_map(static fn(array $e): string => $e['path'] . ':' . $e['code'], $result->errors());
}

unit('phase9c seo validation: title and description — normal text, the exact schema limits, one over the limit, wrong types', function (): void {
    assert_eq(255, CanonicalDocumentSchema::SEO_TITLE_MAX_LENGTH);
    assert_eq(1000, CanonicalDocumentSchema::SEO_DESCRIPTION_MAX_LENGTH);
    assert_eq([], sbs9u_seo_errors(['title' => 'About Alpha Dance Studio — classes for every level', 'description' => 'We teach ballet, jazz and hip-hop to all ages in Brussels.']));
    assert_eq([], sbs9u_seo_errors(['title' => str_repeat('t', 255), 'description' => str_repeat('d', 1000)]), 'exact maximum is valid');
    assert_eq([], sbs9u_seo_errors(['title' => str_repeat('é', 255), 'description' => str_repeat('日', 1000)]), 'limits count characters, not bytes');
    assert_eq(['$.seo.title:invalid_seo_title'], sbs9u_seo_errors(['title' => str_repeat('t', 256)]));
    assert_eq(['$.seo.description:invalid_seo_description'], sbs9u_seo_errors(['description' => str_repeat('d', 1001)]));
    assert_eq(['$.seo.title:invalid_seo_title'], sbs9u_seo_errors(['title' => 123]));
    assert_eq(['$.seo.description:invalid_seo_description'], sbs9u_seo_errors(['description' => ['x']]));
    assert_eq([], sbs9u_seo_errors(['title' => null, 'description' => null]), 'null clears');
    // The existing executable / SQL-fragment policy is unchanged in this phase.
    assert_eq(['$.seo.title:invalid_seo_title'], sbs9u_seo_errors(['title' => '<script>alert(1)</script>']));
});

unit('phase9c seo validation: robots accepts exactly the four canonical directives', function (): void {
    foreach (CanonicalDocumentSchema::ALLOWED_ROBOTS_DIRECTIVES as $robots) {
        assert_eq([], sbs9u_seo_errors(['robots' => $robots]), $robots);
    }
    assert_eq(['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'], CanonicalDocumentSchema::ALLOWED_ROBOTS_DIRECTIVES);
    foreach (['noindex', 'index', 'INDEX,FOLLOW', 'index, follow', 'none', 'noarchive', '', 7, ['index,follow']] as $bad) {
        assert_eq(['$.seo.robots:invalid_robots'], sbs9u_seo_errors(['robots' => $bad]), 'rejects ' . json_encode($bad));
    }
});

unit('phase9c seo validation: canonical_url — validator result and the URL a visitor would actually get for each hostile shape', function (): void {
    $site = new SiteContext('https://site.example', 'Site');
    $own = 'https://site.example/about';
    // [stored by the validator?, effective canonical on the public page]
    $matrix = [
        'empty (null)'       => [null, true, $own],
        'site-relative'      => ['/team', true, 'https://site.example/team'],
        'same-site absolute' => ['https://site.example/team', true, 'https://site.example/team'],
        'foreign host'       => ['https://evil.example/team', true, $own],
        'protocol-relative'  => ['//evil.example/team', false, $own],
        'malformed'          => ['https://', false, $own],
        'no scheme'          => ['site.example/team', false, $own],
        'javascript:'        => ['javascript:alert(1)', false, $own],
        'JaVaScRiPt:'        => ['JaVaScRiPt:alert(1)', false, $own],
        'data:'              => ['data:text/html,<b>x</b>', false, $own],
        'mailto:'            => ['mailto:a@site.example', true, $own],
        'look-alike suffix'  => ['https://site.example.evil.example/team', true, $own],
        'look-alike userinfo' => ['https://site.example@evil.example/team', true, $own],
        'look-alike query'   => ['https://evil.example/?u=https://site.example', true, $own],
        'control character'  => ["/team\r\nLocation: https://evil.example", false, $own],
    ];
    foreach ($matrix as $label => [$authored, $stored, $effective]) {
        $errors = sbs9u_seo_errors(['canonical_url' => $authored]);
        assert_eq($stored, $errors === [], "{$label}: validator " . json_encode($errors));
        if (!$stored) {
            assert_eq(['$.seo.canonical_url:invalid_canonical_url'], $errors, "{$label}: code");
        }
        assert_eq($effective, SeoHead::canonical($authored, $site, '/about'), "{$label}: public canonical");
    }
});

unit('phase9c seo validation: og_image_media_id — a positive integer, tenant media only when the existence check is bound', function (): void {
    assert_eq([], sbs9u_seo_errors(['og_image_media_id' => null]));
    assert_eq([], sbs9u_seo_errors(['og_image_media_id' => 12]));
    foreach ([0, -1, '12', 1.5, true, [12]] as $bad) {
        assert_eq(['$.seo.og_image_media_id:invalid_media_id'], sbs9u_seo_errors(['og_image_media_id' => $bad]), 'rejects ' . json_encode($bad));
    }
    $owned = static fn(int $id): bool => $id === 12;
    assert_eq([], sbs9u_seo_errors(['og_image_media_id' => 12], ['media_exists' => $owned]), 'own media accepted');
    assert_eq(['$.seo.og_image_media_id:cross_tenant_or_missing_media'], sbs9u_seo_errors(['og_image_media_id' => 13], ['media_exists' => $owned]), 'foreign or missing media refused');
});

unit('phase9c seo: unknown seo keys stay refused and the document schema is unchanged', function (): void {
    assert_eq(['$.seo.json_ld:unknown_property'], sbs9u_seo_errors(['json_ld' => '{}']));
    assert_eq(['$.seo.hreflang:unknown_property'], sbs9u_seo_errors(['hreflang' => []]));
    assert_eq('1.0', CanonicalDocumentSchema::SCHEMA_VERSION);
    assert_eq(['title', 'description', 'canonical_url', 'robots', 'og_image_media_id'], CanonicalDocumentSchema::ALLOWED_SEO_KEYS);
});

unit('phase9c seo: effectiveRobots is the published directive, defaulting safely', function (): void {
    assert_eq('noindex,follow', SeoHead::effectiveRobots(['robots' => 'noindex,follow']));
    assert_eq('index,follow', SeoHead::effectiveRobots([]));
    assert_eq('index,follow', SeoHead::effectiveRobots(['robots' => 'bogus']));
    assert_eq('index,follow', SeoHead::effectiveRobots(['robots' => null]));
});

unit('phase9c sitemap xml: a well-formed urlset, every value XML-escaped, control characters dropped, deterministic', function (): void {
    $entries = [
        ['loc' => 'https://site.example/', 'lastmod' => '2026-10-01'],
        ['loc' => 'https://site.example/a?x=1&y="2"&z=<3>', 'lastmod' => null],
        ['loc' => "https://site.example/ctrl\x01\x0Bchars'", 'lastmod' => '2026-09-30'],
    ];
    $xml = StudioSitemapService::xml($entries);
    assert_eq($xml, StudioSitemapService::xml($entries), 'deterministic');
    assert_true(str_starts_with($xml, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'));
    assert_true(str_contains($xml, '<loc>https://site.example/a?x=1&amp;y=&quot;2&quot;&amp;z=&lt;3&gt;</loc>'), 'escaped: ' . $xml);
    assert_true(str_contains($xml, '<loc>https://site.example/ctrlchars&apos;</loc>'), 'control characters removed, quote escaped');
    assert_eq(1, substr_count($xml, '<lastmod>2026-10-01</lastmod>'));
    assert_eq(2, substr_count($xml, '<lastmod>'), 'lastmod only where known');
    $doc = new DOMDocument();
    assert_true($doc->loadXML($xml, LIBXML_NONET), 'parses as XML');
    assert_eq(3, $doc->getElementsByTagName('url')->length);
    assert_eq('https://site.example/a?x=1&y="2"&z=<3>', $doc->getElementsByTagName('loc')->item(1)->textContent, 'round-trips exactly');

    $empty = new DOMDocument();
    assert_true($empty->loadXML(StudioSitemapService::xml([])), 'an empty sitemap is still valid XML');
    assert_eq(0, $empty->getElementsByTagName('url')->length);
});

unit('phase9c robots.txt: allow-all plus a Sitemap line only when the configured base URL is valid — never an invented host', function (): void {
    $with = StudioSitemapService::robotsTxt(new SiteContext('https://site.example/app', 'Site'));
    assert_eq("User-agent: *\nAllow: /\n\nSitemap: https://site.example/app/sitemap.xml\n", $with);
    $without = StudioSitemapService::robotsTxt(new SiteContext(SiteContext::configuredBaseUrl(''), 'Site'));
    assert_eq("User-agent: *\nAllow: /\n", $without);
    $bad = StudioSitemapService::robotsTxt(new SiteContext(SiteContext::configuredBaseUrl('not a url'), 'Site'));
    assert_eq($without, $bad, 'a malformed base URL behaves like none');
    foreach ([$with, $without] as $txt) {
        assert_true(!preg_match('/disallow:\s*\S/i', $txt), 'no paths are listed (nothing about admin or draft routes)');
        assert_eq($txt, StudioSitemapService::robotsTxt(new SiteContext(str_contains($txt, 'Sitemap') ? 'https://site.example/app' : '', 'Site')), 'deterministic');
    }
});

unit('phase9c reserved routes: system paths, module prefixes and the SEO files can never be a page slug', function (): void {
    $r = new StudioReservedRoutes(static fn(): array => ['forms', 'book/services', 'custom-module']);
    foreach (['admin', 'api', 'robots', 'sitemap', 'login', 'media', 'forms', 'book', 'custom-module', 'uploads', 'member'] as $slug) {
        assert_true($r->isReserved($slug), "{$slug} is reserved");
    }
    foreach (['about', 'contact', 'home', 'pricing', 'classes', 'landing-page', 'sitemap-page', 'robots-info'] as $slug) {
        assert_true(!$r->isReserved($slug), "{$slug} is free");
    }
});

unit('phase9c sitemap: the generator reads only published revisions — no seo_json, no Host header, no request input, no rendering', function (): void {
    foreach (['Runtime/StudioSitemapService.php', 'Runtime/StudioPublicRuntime.php'] as $file) {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Module/StudioBuilder/' . $file);
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src);
        foreach (['seo_json', 'HTTP_HOST', 'SERVER_NAME', 'HTTP_X_FORWARDED', '$_GET', '$_POST', '$_REQUEST', '$_SERVER'] as $needle) {
            assert_true(!str_contains((string) $code, $needle), "{$file} must not reference {$needle}");
        }
    }
    $sitemap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Module/StudioBuilder/Runtime/StudioSitemapService.php');
    foreach (['renderPublished', 'renderRevision', 'compilePublished', 'publishedArtifact', 'active_draft_revision_id'] as $needle) {
        assert_true(!str_contains($sitemap, $needle), "the sitemap must not use {$needle}");
    }
});
