<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — a section's width.
 *
 * Autoloader only, no database. Default follows the site's content width (Site settings › Layout › `layout.content`, like
 * Elementor's site-wide Content width), Narrow, Normal and Wide follow their own Layout tokens, and Custom takes a size per
 * device (`layout.custom_width`), checked as the other size fields are.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Document\StyleSurface;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Tenancy\TenantContext;

/** A page with one empty top-level Container, boxed, with `$layout` over its layout (a null value removes a key). */
function sbsw_doc(array $layout, array $responsive = []): array
{
    $merged = array_filter(array_replace(['content_width' => 'boxed', 'gutter' => 'site'], $layout), static fn($v): bool => $v !== null);
    $container = CanonicalDocumentSchema::newContainer(['style' => ['layout' => $merged]]);
    if ($responsive !== []) {
        $container['responsive'] = $responsive;
    }
    $doc = CanonicalDocumentSchema::emptyDocument();
    $doc['containers'] = [$container];
    return $doc;
}

function sbsw_valid(array $layout, array $responsive = []): bool
{
    return DocumentValidator::validate(sbsw_doc($layout, $responsive), BlockRegistry::withAllCoreBlocks())->isValid();
}

/** The Container's element and CSS as the public page renders them. */
function sbsw_render(array $layout, array $responsive = []): array
{
    $tenants  = new TenantContext();
    $renderer = new DocumentRenderer(BlockRegistry::withAllCoreBlocks(), BlockRendererRegistry::withCoreRenderers(), new CoreMediaResolver($tenants), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    $collector = new RenderCollector();
    $html = $renderer->renderContainers(sbsw_doc($layout, $responsive)['containers'], RenderContext::forPublic(101, new SiteContext('https://example.test', 'T')), (new ThemeResolver())->resolve('default'), $collector, false);
    return ['html' => $html, 'css' => $collector->css()];
}

unit('Container width: a new Container, and one with no size, follows the site content width', function (): void {
    $new = CanonicalDocumentSchema::newContainer()['style']['layout'];
    assert_eq(['content_width' => 'boxed', 'gutter' => 'site'], $new, 'a new Container takes Default, with the site gutter');
    assert_true(str_contains(sbsw_render([])['css'], '--sb-box:var(--sb-layout-content,80rem)'), 'no size is Default');
    assert_true(str_contains(sbsw_render(['content_size' => 'narrow'])['css'], '--sb-box:var(--sb-layout-narrow,'), 'a preset keeps its width');
    assert_true(str_contains(sbsw_render(['content_size' => 'bogus'])['css'], '--sb-box:var(--sb-layout-content,80rem)'), 'an unknown stored size renders as Default');
});

unit('Container width: every width has a size, and Default and the presets read the site Layout tokens', function (): void {
    $box = static fn(array $layout): string => StyleSurface::declarations(['layout' => ['content_width' => 'boxed'] + $layout]);
    assert_true(str_contains($box([]), '--sb-box:var(--sb-layout-content,80rem)'), 'Default is the content width');
    assert_true(str_contains($box(['content_size' => 'narrow']), '--sb-box:var(--sb-layout-narrow,42rem)'), 'Narrow');
    assert_true(str_contains($box(['content_size' => 'wide']), '--sb-box:var(--sb-layout-wide,80rem)'), 'Wide');
    assert_true(str_contains($box(['content_size' => 'full']), '--sb-box:100%'), 'Full has no limit');
    foreach (array_diff(CanonicalDocumentSchema::ALLOWED_CONTAINER_WIDTHS, ['default']) as $w) {
        assert_true(sbsw_valid(['content_size' => $w]), "a size for {$w}");
    }
    assert_eq('80rem', ThemeResolver::DEFAULT_TOKENS['layout.content'], 'the content width defaults to what Wide was');
    assert_true(str_contains((new ThemeResolver())->resolve('default')->rootCss(), '--sb-layout-content:80rem'), 'the page defines it');
    assert_eq('1170px', ThemeResolver::sanitizeValue('layout.content', '1170px'), 'a site can set it');
    assert_eq(null, ThemeResolver::sanitizeValue('layout.content', '1170px;color:red'), 'and nothing else');
});

unit('Container width: an author\'s width is a size per device, written as the property the padding reads', function (): void {
    $r = sbsw_render(['content_max' => '60rem'], ['tablet' => ['style' => ['layout' => ['content_max' => '90vw']]], 'mobile' => ['style' => ['layout' => ['content_max' => '100%']]]]);
    assert_true(str_contains($r['css'], '--sb-box:60rem'), 'desktop: ' . $r['css']);
    assert_true(str_contains($r['css'], '--sb-box:90vw !important'), 'tablet');
    assert_true(str_contains($r['css'], '--sb-box:100% !important'), 'phone');
    assert_true(str_contains(sbsw_render(['content_size' => 'wide', 'content_max' => '900px'])['css'], '--sb-box:900px'), 'the author\'s own width wins over a preset');
});

unit('Container width: a size is checked as the other size fields are, so nothing else reaches the style', function (): void {
    assert_true(sbsw_valid(['content_max' => '960px'], ['tablet' => ['style' => ['layout' => ['content_max' => '90%']]], 'mobile' => ['style' => ['layout' => ['content_max' => '80vw']]]]), 'px, % and vw');
    assert_true(sbsw_valid(['content_max' => '60rem']), 'rem');
    assert_true(sbsw_valid([]), 'Default');
    foreach (['960', 'auto', 'none', '960px;color:red', 'url(x)'] as $bad) {
        assert_false(sbsw_valid(['content_max' => $bad]), "refused: {$bad}");
        assert_false(StyleSurface::isSize($bad), "not a size: {$bad}");
    }
    assert_false(StyleSurface::isSize('-10px'), 'not a size: -10px');
    assert_false(sbsw_valid([], ['desktop' => ['style' => ['layout' => ['content_size' => 'wide']]]]), 'a preset is one value, not per device');
    assert_false(sbsw_valid(['content_size' => 'huge']), 'an unknown width');

    // A stored document the validator never saw still cannot put CSS in the page.
    $css = sbsw_render(['content_max' => '1px;background:red'], ['mobile' => ['style' => ['layout' => ['content_max' => '50%']]]])['css'];
    assert_false(str_contains($css, 'background:red'), $css);
    assert_true(str_contains($css, '--sb-box:50% !important'), 'the good size stays');
});

unit('Container width: a boxed container with no width of its own keeps to the site content width', function (): void {
    $css = StyleSurface::declarations(['layout' => ['content_width' => 'boxed'], 'padding' => ['left' => '32px']]);
    assert_true(str_contains($css, '--sb-box:var(--sb-layout-content,80rem)') && str_contains($css, 'padding-left:max(32px,calc((100% - var(--sb-box))/2))'), $css);
    assert_true(str_contains(StyleSurface::declarations(['layout' => ['content_width' => 'boxed', 'content_max' => '900px']]), '--sb-box:900px'), 'its own width wins');
});
