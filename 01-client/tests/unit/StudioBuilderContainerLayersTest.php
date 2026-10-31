<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — a Container's layers (phase 2 of its Elementor-style options).
 *
 * Autoloader only, no database. The background overlay (a style key: a colour or a gradient, opacity, blend, and a hover
 * state), the shape dividers at the top and bottom edge, and a background video, each behind the children.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Document\StyleSurface;
use Slate\Module\StudioBuilder\Http\StudioCanvasPolicy;
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

function sbcl_box(array $props = [], array $style = [], array $states = []): array
{
    $block = [
        'id' => 'blk_dddddddddddddddd', 'type' => 'core.container', 'version' => 1, 'props' => $props,
        'style' => $style + CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [], 'children' => [],
    ];
    if ($states !== []) {
        $block['style_states'] = $states;
    }
    return $block;
}

function sbcl_valid(array $block): bool
{
    $doc = ['schema_version' => '1.0', 'document_type' => 'page', 'template_key' => 'default', 'settings' => [], 'seo' => [], 'sections' => [[
        'id' => 'sec_dddddddddddddddd', 'label' => 'S', 'global_ref' => null, 'layout' => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'blocks' => [$block],
    ]]];
    return DocumentValidator::validate($doc, BlockRegistry::withAllCoreBlocks())->isValid();
}

/** @return array{html: string, css: string} */
function sbcl_render(array $block): array
{
    $tenants   = new TenantContext();
    $renderer  = new DocumentRenderer(BlockRegistry::withAllCoreBlocks(), BlockRendererRegistry::withCoreRenderers(), new CoreMediaResolver($tenants), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    $collector = new RenderCollector();
    $html = $renderer->renderBlock($block, RenderContext::forPublic(101, new SiteContext('https://example.test', 'T')), (new ThemeResolver())->resolve('default'), $collector, false);
    return ['html' => $html, 'css' => $collector->css()];
}

// ── Background overlay ────────────────────────────────────────────────────

unit('overlay: a colour, its opacity and blend become custom properties the Container layer reads, in its own stacking context', function (): void {
    $css = StyleSurface::declarations(['overlay' => ['color' => 'rgba(0,0,0,0.5)', 'opacity' => 0.8, 'blend' => 'multiply']]);
    assert_eq('--sb-ov-display:block;--sb-ov-bg:rgba(0,0,0,0.5);--sb-ov-op:0.8;--sb-ov-blend:multiply;isolation:isolate;position:relative', $css);
    assert_eq('--sb-ov-display:block;--sb-ov-bg:var(--sb-color-accent);isolation:isolate;position:relative', StyleSurface::declarations(['overlay' => ['color' => 'color.accent']]), 'a theme colour is its custom property');
    $sticky = StyleSurface::declarations(['overlay' => ['gradient' => 'linear-gradient(135deg, #000000, #ffffff)'], 'position' => ['mode' => 'sticky', 'top' => '0']]);
    assert_false(str_contains($sticky, 'position:relative'), 'an author\'s position mode is kept: ' . $sticky);
    $sheet = StudioStylesheet::css();
    assert_true(str_contains($sheet, '.sb-block--core-container::before{content:"";display:var(--sb-ov-display,none);position:absolute;inset:0;z-index:-1;'), 'the layer sits under the children');
});

unit('overlay: a colour or a gradient, not both and not neither; only known blends; no CSS smuggled in', function (): void {
    $ok = static fn (array $ov): bool => sbcl_valid(sbcl_box([], ['overlay' => $ov]));
    assert_true($ok(['color' => '#000000', 'opacity' => 0.4]));
    assert_true($ok(['gradient' => 'linear-gradient(90deg, #000000, #ffffff)', 'blend' => 'screen']));
    assert_false($ok(['opacity' => 0.4]), 'needs a paint');
    assert_false($ok(['color' => '#000', 'gradient' => 'linear-gradient(90deg, #000000, #ffffff)']), 'one paint at a time');
    assert_false($ok(['color' => '#000', 'blend' => 'difference']), 'a closed list of blends');
    assert_false($ok(['color' => '#000', 'opacity' => 2]), 'opacity within 0-1');
    assert_false($ok(['color' => 'red;}body{display:none']), 'no CSS smuggled in');
    assert_false($ok(['color' => '#000', 'image' => 'x']), 'nothing else');
});

unit('overlay: a hover state changes its colour and opacity, a literal colour only, and wins over the base', function (): void {
    $box = sbcl_box([], ['overlay' => ['color' => '#000000', 'opacity' => 0.3]], ['hover' => ['overlay' => ['opacity' => 0.7]]]);
    assert_true(sbcl_valid($box));
    $out = sbcl_render($box);
    assert_true((bool) preg_match('/:hover\{--sb-ov-display:block !important;--sb-ov-op:0\.7 !important\}/', $out['css']), $out['css']);
    assert_false(sbcl_valid(sbcl_box([], ['overlay' => ['color' => '#000000']], ['hover' => ['overlay' => ['color' => 'color.accent']]])), 'a state colour is literal, as every state colour');
});

// ── Shape dividers and the background video ───────────────────────────────

unit('shape dividers: a known shape at the top or the bottom, flipped or in front, sized by clamped integers, hidden from assistive tech', function (): void {
    $out = sbcl_render(sbcl_box(['shape_top' => 'wave', 'shape_top_height' => 80, 'shape_top_width' => 150, 'shape_top_flip' => true, 'shape_bottom' => 'tilt', 'shape_bottom_front' => true, 'shape_bottom_color' => 'surface.secondary']));
    $html = $out['html'];
    assert_true(str_contains($html, '<div class="sb-shape sb-shape--top sb-shape--flip" aria-hidden="true" style="--sb-shape-w:150%;--sb-shape-h:80px"><svg viewBox="0 0 1000 100" preserveAspectRatio="none" focusable="false"><path d="M0,0 L0,60'), $html);
    assert_true((bool) preg_match('/<div class="sb-shape sb-shape--bottom sb-fg-[a-z0-9-]+ sb-shape--front" aria-hidden="true">/', $html), $html);
    assert_true(str_contains($html, 'sb-layered'), 'the wrapper is the layers\' stacking context');
    assert_false(str_contains(sbcl_render(sbcl_box())['html'], 'sb-shape'), 'none by default');
    assert_false(str_contains(sbcl_render(sbcl_box())['html'], 'sb-layered'), 'and a plain Container renders as it did');
    assert_false(sbcl_valid(sbcl_box(['shape_top' => 'star'])), 'only the library\'s shapes');
    assert_false(sbcl_valid(sbcl_box(['shape_top' => 'wave', 'shape_top_height' => 900])), 'height within 0-500');
});

unit('background video: a muted, looping, inline video from a safe address, behind the children; the canvas may play it', function (): void {
    $html = sbcl_render(sbcl_box(['bg_video' => 'https://cdn.example.test/loop.mp4']))['html'];
    assert_true(str_contains($html, '<video class="sb-bg-video" src="https://cdn.example.test/loop.mp4" autoplay muted loop playsinline preload="metadata" aria-hidden="true" tabindex="-1"></video>'), $html);
    assert_false(sbcl_valid(sbcl_box(['bg_video' => 'javascript:alert(1)'])), 'an unsafe address is refused');
    assert_false(str_contains(sbcl_render(sbcl_box(['bg_video' => 'javascript:alert(1)']))['html'], '<video'), 'and never rendered, even if stored');
    assert_true(str_contains(StudioCanvasPolicy::CONTENT_SECURITY_POLICY, "media-src 'self' https:"), 'the editor canvas can load it');
    assert_true(str_contains(StudioStylesheet::css(), '@media (prefers-reduced-motion:reduce){.sb-bg-video{display:none}}'), 'hidden for visitors who ask for less motion');
});
