<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the Button and Heading options of their Elementor-style panels.
 *
 * Autoloader only, no database. A heading's link and its div / span / p element, a button's icon (before or after, with
 * its spacing), and the text shadow and text stroke every text block can now take.
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
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Tenancy\TenantContext;

function sbbh_block(string $type, array $props, array $style = []): array
{
    return [
        'id' => 'blk_bbbbbbbbbbbbbbbb', 'type' => $type, 'version' => ($type === 'core.container' ? 2 : 1), 'props' => $props,
        'style' => $style + CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [], 'children' => [],
    ];
}

function sbbh_valid(array $block): bool
{
    $doc = ['schema_version' => '2.0', 'document_type' => 'page', 'template_key' => 'default', 'settings' => [], 'seo' => [], 'containers' => [CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'S'], 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'children' => [$block]])]];
    return DocumentValidator::validate($doc, BlockRegistry::withAllCoreBlocks())->isValid();
}

function sbbh_render(array $block): string
{
    $tenants  = new TenantContext();
    $renderer = new DocumentRenderer(BlockRegistry::withAllCoreBlocks(), BlockRendererRegistry::withCoreRenderers(), new CoreMediaResolver($tenants), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    return $renderer->renderBlock($block, RenderContext::forPublic(101, new SiteContext('https://example.test', 'T')), (new ThemeResolver())->resolve('default'), new RenderCollector(), false);
}

// ── Heading ───────────────────────────────────────────────────────────────

unit('heading: a link wraps the text inside the heading, after the URL allowlist; a new tab adds noopener', function (): void {
    $html = sbbh_render(sbbh_block('core.heading', ['text' => 'Our menu', 'level' => 'h2', 'url' => '/menu', 'new_tab' => true]));
    assert_true(str_contains($html, '<h2 class="sb-heading"><a class="sb-heading__link" href="/menu" target="_blank" rel="noopener noreferrer">Our menu</a></h2>'), $html);
    $plain = sbbh_render(sbbh_block('core.heading', ['text' => 'Our menu', 'level' => 'h2']));
    assert_false(str_contains($plain, '<a'), 'no link without a URL');
    assert_false(sbbh_valid(sbbh_block('core.heading', ['text' => 'x', 'url' => 'javascript:alert(1)'])), 'an unsafe link is refused');
    assert_false(str_contains(sbbh_render(sbbh_block('core.heading', ['text' => 'x', 'url' => 'javascript:alert(1)'])), 'href'), 'and never rendered, even if stored');
});

unit('heading: the HTML tag may be a level, or div, span or p', function (): void {
    foreach (['div', 'span', 'p'] as $tag) {
        assert_true(sbbh_valid(sbbh_block('core.heading', ['text' => 'x', 'level' => $tag])), "{$tag} is accepted");
        assert_true(str_contains(sbbh_render(sbbh_block('core.heading', ['text' => 'x', 'level' => $tag])), '<' . $tag . ' class="sb-heading">'), "{$tag} is rendered");
    }
    assert_false(sbbh_valid(sbbh_block('core.heading', ['text' => 'x', 'level' => 'section'])), 'not any element');
});

// ── Button ────────────────────────────────────────────────────────────────

unit('button: an icon sits before or after the label, spaced as set, and the label keeps its own span', function (): void {
    $link = ['label' => 'Book', 'href' => '/book'];
    $before = sbbh_render(sbbh_block('core.button', ['link' => $link, 'icon' => 'star', 'icon_spacing' => 12]));
    assert_true(preg_match('~<a class="sb-button sb-button--primary" href="/book"><span class="sb-button__icon" aria-hidden="true" style="margin-right:12px"><svg[^>]*>.*</svg></span><span class="sb-button__label">Book</span></a>~s', $before) === 1, $before);
    $after = sbbh_render(sbbh_block('core.button', ['link' => $link, 'icon' => 'star', 'icon_position' => 'after']));
    assert_true(preg_match('~<span class="sb-button__label">Book</span><span class="sb-button__icon" aria-hidden="true" style="margin-left:8px">~', $after) === 1, 'after, default 8px: ' . $after);
    $none = sbbh_render(sbbh_block('core.button', ['link' => $link, 'icon' => 'none']));
    assert_true(str_contains($none, '<a class="sb-button sb-button--primary" href="/book">Book</a>'), 'no icon: the button is as it was');
    assert_false(sbbh_valid(sbbh_block('core.button', ['link' => $link, 'icon' => 'not-an-icon'])), 'only icons of the library');
    assert_false(sbbh_valid(sbbh_block('core.button', ['link' => $link, 'icon' => 'star', 'icon_spacing' => 500])), 'spacing within 0-50');
});

unit('button: a label is escaped inside its span', function (): void {
    $html = sbbh_render(sbbh_block('core.button', ['link' => ['label' => '<b>Go</b>', 'href' => '/go'], 'icon' => 'star']));
    assert_true(str_contains($html, '<span class="sb-button__label">&lt;b&gt;Go&lt;/b&gt;</span>'), $html);
});

// ── Text shadow and stroke ────────────────────────────────────────────────

unit('text shadow: x, y and a colour are required, blur is optional, and a theme colour is its custom property', function (): void {
    assert_eq('text-shadow:1px 2px 4px rgba(0,0,0,0.4)', StyleSurface::declarations(['text_shadow' => ['x' => '1px', 'y' => '2px', 'blur' => '4px', 'color' => 'rgba(0,0,0,0.4)']]));
    assert_eq('text-shadow:0 1px var(--sb-color-accent)', StyleSurface::declarations(['text_shadow' => ['x' => '0', 'y' => '1px', 'color' => 'color.accent']]));
    $valid = static fn (array $ts): bool => sbbh_valid(sbbh_block('core.heading', ['text' => 'x'], ['text_shadow' => $ts]));
    assert_true($valid(['x' => '1px', 'y' => '1px', 'color' => '#000']), 'a plain shadow');
    assert_false($valid(['x' => '1px', 'y' => '1px']), 'no colour');
    assert_false($valid(['x' => '1px', 'y' => '1px', 'color' => '#000', 'spread' => '2px']), 'a text shadow has no spread');
    assert_false($valid(['x' => '1px;color:red', 'y' => '1px', 'color' => '#000']), 'no CSS smuggled in');
});

unit('text stroke: a width and a colour around the letters', function (): void {
    $css = StyleSurface::declarations(['typography' => ['stroke_width' => '1px', 'stroke_color' => 'text.primary']]);
    assert_true(str_contains($css, '-webkit-text-stroke-width:1px'), $css);
    assert_true(str_contains($css, '-webkit-text-stroke-color:var(--sb-text-primary)'), $css);
    assert_false(sbbh_valid(sbbh_block('core.heading', ['text' => 'x'], ['typography' => ['stroke_width' => 'thick']])), 'a length only');
});
