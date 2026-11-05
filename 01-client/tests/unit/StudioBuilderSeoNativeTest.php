<?php
/**
 * Site & SEO, written by Studio instead of pasted code: web font lines become one Google Fonts address (and nothing
 * else gets through), the business becomes Organization/WebSite structured data with an item's own nodes added, and
 * page descriptions are cut at a whole word.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Render\Seo\SeoHead;
use Slate\Module\StudioBuilder\Render\Seo\StructuredData;
use Slate\Module\StudioBuilder\Render\Seo\WebFonts;

unit('seo native: font lines become Google Fonts families, friendly or in Google\'s notation', function (): void {
    assert_eq('Karla:wght@400;700;800', WebFonts::spec('Karla: 800, 400, 700'));
    assert_eq('Open+Sans', WebFonts::spec('Open Sans'));
    assert_eq('Lora:ital,wght@0,400;0,700;1,400', WebFonts::spec('Lora: 400, 400 italic, 700'));
    assert_eq('Fraunces:opsz,wght@9..144,500;9..144,700', WebFonts::spec('Fraunces:opsz,wght@9..144,500;9..144,700'));
    foreach (['Karla: bold', 'Evil"><script>', 'Karla: 450', 'x:wght@400&family=y', 'Karla: 400; 700'] as $bad) {
        assert_eq(null, WebFonts::spec($bad), 'refused: ' . $bad);
    }
    $p = WebFonts::parse("Karla: 400, 700\n\nCaveat: 600\nnot a font!\nKarla: 400, 700");
    assert_eq(['Karla:wght@400;700', 'Caveat:wght@600'], $p['families'], 'one of each; blank lines skipped');
    assert_eq(['not a font!'], $p['bad']);
    assert_eq('https://fonts.googleapis.com/css2?family=Karla:wght@400;700&family=Caveat:wght@600&display=swap', WebFonts::url($p['families']));
    assert_eq('', WebFonts::url([]));
    assert_eq('', WebFonts::url(['x"onload=1']), 'only parse()\'s shapes make an address');
});

unit('seo native: the business is cleaned to known kinds, bounded text and https profiles', function (): void {
    $b = StructuredData::clean(['type' => 'OnlineStore', 'name' => '  Gourmet Test  ', 'same_as' => "https://example.test/a\njavascript:alert(1)\nhttp://plain.test/\nhttps://example.test/a", 'logo_media_id' => '7', 'extra' => 'x']);
    assert_eq(['OnlineStore', 'Gourmet Test', ['https://example.test/a'], 7], [$b['type'], $b['name'], $b['same_as'], $b['logo_media_id']]);
    assert_eq('', StructuredData::clean(['type' => 'Thing'])['type'], 'an unknown kind is none');
    assert_true(!array_key_exists('extra', $b));
});

unit('seo native: the graph names the business and the site, and adds an item\'s product with the brand', function (): void {
    $b = StructuredData::clean(['type' => 'OnlineStore', 'name' => 'Gourmet Test', 'alternate_name' => 'GT', 'founder' => 'A. Chef', 'same_as' => ['https://other.test/']]);
    $g = StructuredData::graph($b, 'https://shop.test', 'Site Name', 'https://shop.test/logo.png', [['@type' => 'Product', 'name' => 'Brittle'], ['@type' => 'BreadcrumbList'], ['no type']]);
    assert_eq(['OnlineStore', 'WebSite', 'Product', 'BreadcrumbList'], array_column($g, '@type'));
    assert_eq(['https://shop.test/#organization', 'https://shop.test/', 'GT', 'https://shop.test/logo.png', 'A. Chef', ['https://other.test/']],
        [$g[0]['@id'], $g[0]['url'], $g[0]['alternateName'], $g[0]['logo'], $g[0]['founder']['name'], $g[0]['sameAs']]);
    assert_eq(['@id' => 'https://shop.test/#organization'], $g[1]['publisher']);
    assert_eq(['@type' => 'Brand', 'name' => 'Gourmet Test'], $g[2]['brand'], 'a product carries the business as its brand');
    $none = StructuredData::graph(StructuredData::clean([]), 'https://shop.test', 'Site Name', null);
    assert_eq(['WebSite'], array_column($none, '@type'), 'no business kind: only the site');
    assert_eq([], StructuredData::graph($b, '', 'Site Name', null), 'no site address: nothing');
});

unit('seo native: the business carries its address, telephone, email and VAT number into the graph, cleaned', function (): void {
    $b = StructuredData::clean(['type' => 'NGO', 'name' => 'FightxLife', 'telephone' => '+32 483 12 72 98<script>', 'email' => 'contact@example.test', 'vat_id' => 'BE0720965564"x',
        'street' => 'Rue Gabrielle Petit 32', 'postal_code' => '1080', 'locality' => 'Molenbeek', 'country' => 'BE']);
    assert_eq('NGO', $b['type']);
    assert_eq(['+32 483 12 72 98', 'BE0720965564x'], [$b['telephone'], $b['vat_id']], 'only phone and VAT characters stay');
    assert_eq('', StructuredData::clean(['email' => 'not an email'])['email'], 'a bad email is dropped');
    $g = StructuredData::graph($b, 'https://fxl.test', 'Site', null);
    assert_eq(['NGO', 'WebSite'], array_column($g, '@type'));
    assert_eq(['+32 483 12 72 98', 'contact@example.test', 'BE0720965564x'], [$g[0]['telephone'], $g[0]['email'], $g[0]['vatID']]);
    assert_eq(['@type' => 'PostalAddress', 'streetAddress' => 'Rue Gabrielle Petit 32', 'postalCode' => '1080', 'addressLocality' => 'Molenbeek', 'addressCountry' => 'BE'], $g[0]['address']);
    $bare = StructuredData::graph(StructuredData::clean(['type' => 'Organization', 'name' => 'X']), 'https://fxl.test', 'Site', null);
    assert_true(!isset($bare[0]['address']) && !isset($bare[0]['telephone']), 'nothing entered, nothing written');
});

unit('site context: the page language is a valid BCP 47 tag', function (): void {
    $tag = static fn(string $l): string => (new \Slate\Module\StudioBuilder\Render\SiteContext('https://x.test', 'X', '', '', $l))->locale;
    assert_eq(['fr', 'fr-BE', 'fr-BE', 'en'], [$tag('fr'), $tag('fr_be'), $tag('fr-BE'), $tag('nonsense')]);
});

unit('site context: a region only applies on top of the site\'s own language', function (): void {
    $w = static fn(string $l, string $t): string => \Slate\Module\StudioBuilder\Render\SiteContext::withRegion($l, $t);
    assert_eq(['fr-BE', 'fr-BE', 'fr', 'fr', 'en'], [$w('fr', 'fr-BE'), $w('fr', 'fr_be'), $w('fr', 'en-GB'), $w('fr', ''), $w('en', 'fr-BE')]);
});

unit('image block: a priority picture loads first, a normal one lazily', function (): void {
    $lazy = \Slate\Module\StudioBuilder\Render\Block\CoreRenderers\ImageRenderer::img('/a.jpg', '', 10, 10, null, true, [], false);
    $first = \Slate\Module\StudioBuilder\Render\Block\CoreRenderers\ImageRenderer::img('/a.jpg', '', 10, 10, null, false, [], true);
    assert_true(str_contains($lazy, 'loading="lazy"') && !str_contains($lazy, 'fetchpriority'));
    assert_true(str_contains($first, 'fetchpriority="high"') && !str_contains($first, 'loading="lazy"'));
});

unit('seo native: descriptions are cut at a whole word or sentence, with no markup', function (): void {
    assert_eq('Short & sweet.', SeoHead::excerpt('<p>Short &amp; sweet.</p>', 300));
    $long = 'Our Old Fashioned Peanut Brittle is a special treat. Each batch is hand-made with care and attention to detail, ensuring that every piece is perfectly crunchy.';
    assert_eq('Our Old Fashioned Peanut Brittle is a special treat. Each batch is hand-made with care and attention to…', SeoHead::excerpt($long, 110));
    assert_eq('Our Old Fashioned Peanut Brittle is a special treat.', SeoHead::excerpt($long, 70), 'a sentence that ends late enough is kept whole');
});
