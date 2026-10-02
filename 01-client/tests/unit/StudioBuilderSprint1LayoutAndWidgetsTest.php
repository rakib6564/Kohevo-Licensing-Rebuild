<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Sprint 1 Layout Engine & Widget Foundation.
 *
 * Exercises:
 *  1. WidgetRegistry registration, manifest filtering, and categories.
 *  2. BlockRegistry::registerLayoutBlocks and withAllCoreBlocks.
 *  3. Individual renderers: layout.section, layout.container, layout.flex, layout.grid, core.text.
 *  4. HeadingRenderer and ButtonRenderer enhancements.
 *  5. DocumentRenderer custom attributes, classNames, and responsive device classes.
 *  6. StudioStylesheet CSS utilities for layout and responsive rules.
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
    $studioS1UnitStandalone = true;
}

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Registry\WidgetRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\ButtonRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\FlexRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\GridRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\HeadingRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\LayoutContainerRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\SectionRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\TextRenderer;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\RenderMode;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Tenancy\TenantContext;

function s1_scope(array $block, string $children = ''): BlockRenderScope
{
    $tenants = new TenantContext();
    $context = RenderContext::forPublic(101, new SiteContext('https://example.test', 'Test Site'));
    $theme = new ResolvedTheme('default', []);
    $collector = new RenderCollector();
    $media = new CoreMediaResolver($tenants);
    return new BlockRenderScope($block, $context, $media, $theme, $collector, $children, []);
}

// ── 1. WidgetRegistry ─────────────────────────────────────────────────────────

unit('sprint1: WidgetRegistry withCoreWidgets has layout and content primitives with manifests', function (): void {
    $wr = WidgetRegistry::withCoreWidgets();
    assert_true($wr->has('layout.section'));
    assert_true($wr->has('layout.container'));
    assert_true($wr->has('layout.flex'));
    assert_true($wr->has('layout.grid'));
    assert_true($wr->has('core.heading'));
    assert_true($wr->has('core.text'));
    assert_true($wr->has('core.button'));

    $cats = $wr->categories();
    assert_true(isset($cats['layout']));
    assert_true(isset($cats['content']));

    $section = $wr->resolve('layout.section');
    assert_eq('layout.section', $section->type());
    assert_true($section->allowsChildren());
    assert_true(!empty($section->description()));
    assert_true(is_array($section->controls()));

    $manifests = $wr->editorManifests();
    $types = array_column($manifests, 'type');
    assert_true(in_array('layout.section', $types, true));
    assert_true(in_array('layout.container', $types, true));
    assert_true(in_array('layout.flex', $types, true));
    assert_true(in_array('layout.grid', $types, true));
    assert_true(in_array('core.text', $types, true));
});

// ── 2. BlockRegistry ──────────────────────────────────────────────────────────

unit('sprint1: BlockRegistry registerLayoutBlocks registers primitives onto BlockRegistry', function (): void {
    $br = BlockRegistry::withAllCoreBlocks();
    assert_true($br->has('layout.section'));
    assert_true($br->has('layout.container'));
    assert_true($br->has('layout.flex'));
    assert_true($br->has('layout.grid'));
    assert_true($br->has('core.text'));
    assert_true($br->has('core.heading'));
    assert_true($br->has('core.button'));
});

// ── 3. Renderers ──────────────────────────────────────────────────────────────

unit('sprint1: SectionRenderer compiles to semantic HTML tag with width and padding classes', function (): void {
    $renderer = new SectionRenderer();
    assert_eq('layout.section', $renderer->type());
    assert_false($renderer->isDynamic());

    $html = $renderer->render(s1_scope([
        'type' => 'layout.section',
        'props' => ['tag' => 'section', 'content_width' => 'boxed', 'padding_y' => 'lg', 'min_height' => 'screen'],
    ], '<h2>Inner</h2>'));

    assert_true(str_starts_with($html, '<section class="sb-layout-section sb-layout-section--boxed sb-py-lg sb-layout-section--min-screen">'));
    assert_true(str_ends_with($html, '<h2>Inner</h2></section>'));
});

unit('sprint1: LayoutContainerRenderer compiles with constraint classes and alignment', function (): void {
    $renderer = new LayoutContainerRenderer();
    assert_eq('layout.container', $renderer->type());

    $html = $renderer->render(s1_scope([
        'type' => 'layout.container',
        'props' => ['width' => 'constrained', 'alignment' => 'center', 'padding' => 'md'],
    ], '<p>Contained</p>'));

    assert_eq('<div class="sb-container sb-container--constrained sb-container--align-center sb-pad-md"><p>Contained</p></div>', $html);
});

unit('sprint1: FlexRenderer compiles with flex direction, wrap, and justify classes', function (): void {
    $renderer = new FlexRenderer();
    assert_eq('layout.flex', $renderer->type());

    $html = $renderer->render(s1_scope([
        'type' => 'layout.flex',
        'props' => ['direction' => 'row', 'wrap' => 'wrap', 'justify' => 'between', 'align' => 'center', 'gap' => 'lg'],
    ], '<span>Item 1</span><span>Item 2</span>'));

    assert_eq('<div class="sb-flex sb-flex--row sb-flex--wrap sb-flex--justify-between sb-flex--align-center sb-gap-lg"><span>Item 1</span><span>Item 2</span></div>', $html);
});

unit('sprint1: GridRenderer compiles with columns and alignment classes', function (): void {
    $renderer = new GridRenderer();
    assert_eq('layout.grid', $renderer->type());

    $html = $renderer->render(s1_scope([
        'type' => 'layout.grid',
        'props' => ['columns' => 3, 'gap' => 'md', 'align' => 'stretch'],
    ], '<div>A</div><div>B</div><div>C</div>'));

    assert_eq('<div class="sb-grid sb-cols-3 sb-grid--align-stretch sb-gap-md"><div>A</div><div>B</div><div>C</div></div>', $html);
});

unit('sprint1: TextRenderer compiles paragraph text with size and alignment', function (): void {
    $renderer = new TextRenderer();
    assert_eq('core.text', $renderer->type());

    $html = $renderer->render(s1_scope([
        'type' => 'core.text',
        'props' => ['content' => "Line 1\nLine 2", 'size' => 'lg', 'align' => 'center'],
    ]));

    assert_eq("<p class=\"sb-text sb-text--lg sb-text--align-center\">Line 1<br>\nLine 2</p>", $html);
});

unit('sprint1: HeadingRenderer and ButtonRenderer enhanced props compile correctly', function (): void {
    $hRenderer = new HeadingRenderer();
    $hHtml = $hRenderer->render(s1_scope([
        'type' => 'core.heading',
        'props' => ['text' => 'Hello World', 'level' => 'h1', 'size' => '4xl', 'align' => 'center'],
    ]));
    assert_eq('<h1 class="sb-heading sb-heading--4xl sb-heading--align-center">Hello World</h1>', $hHtml);

    $bRenderer = new ButtonRenderer();
    $bHtml = $bRenderer->render(s1_scope([
        'type' => 'core.button',
        'props' => [
            'link' => ['href' => '/pricing', 'label' => 'Get Started', 'target' => '_blank'],
            'variant' => 'primary',
            'size' => 'lg',
        ],
    ]));
    assert_true(str_contains($bHtml, 'sb-button--primary sb-button--lg'));
    assert_true(str_contains($bHtml, 'href="/pricing"'));
    assert_true(str_contains($bHtml, 'target="_blank"'));
});

// ── 4. DocumentRenderer responsive & custom attributes ────────────────────────

unit('sprint1: DocumentRenderer emits responsive device classes and custom attributes', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $renderers = BlockRendererRegistry::withCoreRenderers();
    $tenants = new TenantContext();
    $docRenderer = new DocumentRenderer($registry, $renderers, new CoreMediaResolver($tenants), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));

    $context = RenderContext::forPublic(101, new SiteContext('https://example.test', 'Test'));
    $theme = new ResolvedTheme('default', []);
    $collector = new RenderCollector();

    $block = [
        'id' => CanonicalDocumentSchema::newBlockId(),
        'type' => 'core.heading',
        'version' => 1,
        'props' => ['text' => 'Mobile Hidden Heading', 'level' => 'h2'],
        'style' => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [],
        'children' => [],
        'responsive' => [
            'mobile' => ['hide' => true],
            'desktop' => ['align' => 'center'],
        ],
        'classNames' => ['custom-hero-title', 'highlight-text'],
        'attributes' => [
            'data-tracking' => 'hero-header',
            'aria-label' => 'Main title',
        ],
    ];

    $html = $docRenderer->renderBlock($block, $context, $theme, $collector, false);

    assert_true(str_contains($html, 'sb-hide-mobile'), 'emits sb-hide-mobile class');
    assert_true(str_contains($html, 'sb-align-desktop-center'), 'emits responsive align class');
    assert_true(str_contains($html, 'custom-hero-title'), 'emits custom class names');
    assert_true(str_contains($html, 'highlight-text'), 'emits custom class names');
    assert_true(str_contains($html, 'data-tracking="hero-header"'), 'emits custom data attributes');
    assert_true(str_contains($html, 'aria-label="Main title"'), 'emits aria attributes');
});

// ── 5. StudioStylesheet ───────────────────────────────────────────────────────

unit('sprint1: StudioStylesheet contains layout and responsive device classes', function (): void {
    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();

    assert_true(str_contains($css, '.sb-layout-section{'));
    assert_true(str_contains($css, '.sb-container{'));
    assert_true(str_contains($css, '.sb-flex{'));
    assert_true(str_contains($css, '.sb-grid{'));
    assert_true(str_contains($css, '.sb-text{'));
    assert_true(str_contains($css, '.sb-hide-desktop{display:none!important}'));
    assert_true(str_contains($css, '.sb-hide-tablet{display:none!important}'));
    assert_true(str_contains($css, '.sb-hide-mobile{display:none!important}'));
});
