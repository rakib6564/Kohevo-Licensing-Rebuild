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

/** A page with one empty section whose layout is the default one with `$layout` over it (a null value removes a key). */
function sbsw_doc(array $layout): array
{
    $merged = array_filter(array_replace(CanonicalDocumentSchema::defaultSectionLayout(), $layout), static fn($v): bool => $v !== null);
    return ['schema_version' => '2.0', 'document_type' => 'page', 'template_key' => 'default', 'settings' => [], 'seo' => [], 'containers' => [CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'S'], 'layout' => $merged /*TODO*/, 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'children' => []])]];
}

function sbsw_valid(array $layout): bool
{
    return DocumentValidator::validate(sbsw_doc($layout), BlockRegistry::withAllCoreBlocks())->isValid();
}

/** The section's inner `<div>` as the public page renders it. */
function sbsw_inner(array $layout): string
{
    $tenants  = new TenantContext();
    $renderer = new DocumentRenderer(BlockRegistry::withAllCoreBlocks(), BlockRendererRegistry::withCoreRenderers(), new CoreMediaResolver($tenants), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    $html = $renderer->renderContainers(sbsw_doc($layout)['containers'], RenderContext::forPublic(101, new SiteContext('https://example.test', 'T')), (new ThemeResolver())->resolve('default'), new RenderCollector(), false);
    return preg_match('/<div class="sb-section__inner[^>]*>/', $html, $m) === 1 ? $m[0] : '';
}

unit('section width: a new section, and one with no width, follows the site content width', function (): void {
    assert_eq('default', CanonicalDocumentSchema::defaultSectionLayout()['width'], 'new sections take Default');
    assert_true(str_contains(sbsw_inner(['width' => null]), 'sb-w-default'), 'no width is Default');
    assert_true(str_contains(sbsw_inner(['width' => 'narrow']), 'sb-w-narrow'), 'a preset keeps its class');
    assert_true(str_contains(sbsw_inner(['width' => 'bogus']), 'sb-w-default'), 'an unknown width renders as Default');
});

unit('section width: every width has a rule, and Default and the presets read the site Layout tokens', function (): void {
    $css = StudioStylesheet::css();
    assert_true(str_contains($css, '.sb-w-default{max-width:var(--sb-layout-content,80rem);'), 'Default is the content width');
    assert_true(str_contains($css, '.sb-w-narrow{max-width:var(--sb-layout-narrow,42rem);'), 'Narrow');
    assert_true(str_contains($css, '.sb-w-wide{max-width:var(--sb-layout-wide,80rem);'), 'Wide');
    assert_true(str_contains($css, '.sb-w-full{max-width:none;'), 'Full has no limit');
    foreach (CanonicalDocumentSchema::ALLOWED_SECTION_WIDTHS as $w) {
        assert_true(str_contains($css, '.sb-w-' . $w . '{'), "a rule for {$w}");
    }
    assert_eq('80rem', ThemeResolver::DEFAULT_TOKENS['layout.content'], 'the content width defaults to what Wide was');
    assert_true(str_contains((new ThemeResolver())->resolve('default')->rootCss(), '--sb-layout-content:80rem'), 'the page defines it');
    assert_eq('1170px', ThemeResolver::sanitizeValue('layout.content', '1170px'), 'a site can set it');
    assert_eq(null, ThemeResolver::sanitizeValue('layout.content', '1170px;color:red'), 'and nothing else');
});

unit('section width: a custom width is a size per device, written as properties the stylesheet reads mobile first', function (): void {
    $inner = sbsw_inner(['width' => 'custom', 'custom_width' => ['base' => '100%', 'md' => '90vw', 'lg' => '60rem']]);
    assert_true(str_contains($inner, 'sb-w-custom'), $inner);
    assert_true(str_contains($inner, 'sb-md-cw') && str_contains($inner, 'sb-lg-cw'), 'each wider device reads its own size');
    assert_false(str_contains($inner, 'sb-sm-cw'), 'a device with no size keeps the one below it');
    assert_true(str_contains($inner, 'style="--sb-cw:100%;--sb-cw-md:90vw;--sb-cw-lg:60rem"'), $inner);

    $css = StudioStylesheet::css();
    assert_true(str_contains($css, '.sb-w-custom{max-width:var(--sb-cw,none);'), 'the phone size, or no limit');
    assert_true(str_contains($css, '@media (min-width:768px){') && str_contains($css, '.sb-w-custom.sb-md-cw{max-width:var(--sb-cw-md)}'), 'tablet');
    assert_true(str_contains($css, '.sb-w-custom.sb-lg-cw{max-width:var(--sb-cw-lg)}'), 'desktop');

    assert_false(str_contains(sbsw_inner(['width' => 'wide', 'custom_width' => ['base' => '900px']]), 'cw'), 'a size is used only while Custom is picked');
});

unit('section width: a custom size is checked as the other size fields are, so nothing else reaches the style attribute', function (): void {
    assert_true(sbsw_valid(['width' => 'custom', 'custom_width' => ['base' => '960px', 'md' => '90%', 'lg' => '60rem', 'sm' => '80vw']]), 'px, %, rem and vw');
    assert_true(sbsw_valid(['width' => 'custom']), 'Custom with no size yet is no limit');
    assert_true(sbsw_valid(['width' => 'default']), 'Default');
    foreach (['960', '-10px', 'auto', 'none', '960px;color:red', 'url(x)'] as $bad) {
        assert_false(sbsw_valid(['width' => 'custom', 'custom_width' => ['base' => $bad]]), "refused: {$bad}");
        assert_false(StyleSurface::isSize($bad), "not a size: {$bad}");
    }
    assert_false(sbsw_valid(['custom_width' => ['xl' => '900px']]), 'an unknown device');
    assert_false(sbsw_valid(['custom_width' => ['900px']]), 'a list is not a device map');
    assert_false(sbsw_valid(['custom_width' => []]), 'an empty map');
    assert_false(sbsw_valid(['width' => 'huge']), 'an unknown width');

    // A stored document the validator never saw still cannot put CSS in the page.
    $inner = sbsw_inner(['width' => 'custom', 'custom_width' => ['base' => '1px;background:red', 'md' => '50%']]);
    assert_false(str_contains($inner, 'background'), $inner);
    assert_true(str_contains($inner, '--sb-cw-md:50%'), 'the good size stays');
});

unit('section width: a boxed container with no width of its own keeps to the site content width', function (): void {
    $css = StyleSurface::declarations(['layout' => ['content_width' => 'boxed'], 'padding' => ['left' => '32px']]);
    assert_true(str_contains($css, 'padding-left:max(32px,calc((100% - var(--sb-layout-content,80rem))/2))'), $css);
    assert_true(str_contains(StyleSurface::declarations(['layout' => ['content_width' => 'boxed', 'content_max' => '900px']]), '(100% - 900px)'), 'its own width wins');
});
