<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — B2-P3b style surface.
 *
 * Autoloader only, no database. Reuses `sbp5s_validate()` (StudioBuilderPhase5StylesTest).
 *
 * `layout`, `position`, `effects` and the new `dimensions` fields are defined by
 * one closed table (`StyleSurface`): every accepted value maps to a known CSS
 * declaration that the SERVER formats, and the rule is scoped to the block's own
 * class in the page stylesheet. Nothing authored is ever concatenated into CSS.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
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

/** @return array{html: string, css: string} one styled heading through the real renderer */
function sbss_render(array $style, ?string $id = null): array
{
    $tenants   = new TenantContext();
    $renderer  = new DocumentRenderer(BlockRegistry::withAllCoreBlocks(), BlockRendererRegistry::withCoreRenderers(), new CoreMediaResolver($tenants), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    $collector = new RenderCollector();
    $html = $renderer->renderBlock([
        'id' => $id ?? 'blk_aaaaaaaaaaaaaaaaaaaaaaaa', 'type' => 'core.heading', 'version' => 1,
        'props' => ['text' => 'Styled', 'level' => 'h2'],
        'style' => $style + CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [], 'children' => [], 'animation' => [], 'interactions' => [],
    ], RenderContext::forPublic(101, new SiteContext('https://example.test', 'T')), (new ThemeResolver())->resolve('default'), $collector, false);
    return ['html' => $html, 'css' => $collector->css()];
}

// ── The surface is wired into the schema ──────────────────────────────────

unit('surface: the new keys are style keys, and the unknown ones still are not', function (): void {
    foreach (StyleSurface::KEYS as $key) {
        assert_true(in_array($key, CanonicalDocumentSchema::ALLOWED_STYLE_KEYS, true), "{$key} is a style key");
    }
    assert_false(sbp5s_validate(['transform' => 'rotate(3deg)'])->isValid(), 'a top-level transform is still not a key');
    assert_false(sbp5s_validate(['custom_css' => 'x'])->isValid(), 'no free CSS key exists');
});

// ── Validation tables ────────────────────────────────────────────────────

unit('surface: valid layout, position, size and effects values validate', function (): void {
    $valid = [
        ['layout' => ['display' => 'flex', 'direction' => 'column', 'wrap' => 'wrap', 'justify' => 'between', 'align' => 'center', 'gap' => '1.5rem', 'row_gap' => '8px', 'column_gap' => '2rem', 'order' => -1, 'grow' => 1, 'shrink' => 0, 'basis' => 'auto']],
        ['layout' => ['display' => 'grid', 'columns' => 3, 'rows' => 2, 'gap' => 'clamp(1rem, 2vw, 2rem)']],
        ['position' => ['mode' => 'sticky', 'top' => '0', 'left' => '-4px']],
        ['position' => ['mode' => 'absolute', 'top' => '10%', 'right' => 'auto']],
        ['dimensions' => ['width' => '100%', 'min_width' => '10rem', 'max_height' => '40rem', 'aspect_ratio' => '16/9', 'overflow' => 'hidden', 'object_fit' => 'cover', 'object_position' => 'top-left']],
        ['effects' => ['transform' => ['translate_x' => '10px', 'translate_y' => '-1rem', 'rotate' => -3.5, 'scale' => 1.05, 'skew_x' => 2, 'skew_y' => 0], 'filter' => ['blur' => 4, 'brightness' => 110, 'contrast' => 100, 'saturate' => 120, 'grayscale' => 0], 'backdrop_blur' => 8, 'blend' => 'multiply', 'transition' => ['duration_ms' => 300, 'easing' => 'ease-out'], 'cursor' => 'pointer']],
    ];
    foreach ($valid as $style) {
        $r = sbp5s_validate($style);
        assert_true($r->isValid(), 'must validate ' . json_encode($style) . ' → ' . json_encode($r->errors ?? []));
    }
});

unit('surface: out-of-range, unknown and hostile values are refused', function (): void {
    $bad = [
        'position fixed'            => ['position' => ['mode' => 'fixed']],
        'position unknown mode'     => ['position' => ['mode' => 'fixed; top:0']],
        'position offset url'       => ['position' => ['top' => 'url(x)']],
        'position offset keyword'   => ['position' => ['top' => 'inherit']],
        'position unknown field'    => ['position' => ['z' => 1]],
        'layout display none'       => ['layout' => ['display' => 'none']],
        'layout display free'       => ['layout' => ['display' => 'flex;position:fixed']],
        'layout columns zero'       => ['layout' => ['columns' => 0]],
        'layout columns 13'         => ['layout' => ['columns' => 13]],
        'layout columns string'     => ['layout' => ['columns' => '3']],
        'layout columns float'      => ['layout' => ['columns' => 2.5]],
        'layout order huge'         => ['layout' => ['order' => 1000]],
        'layout gap var'            => ['layout' => ['gap' => 'var(--x)']],
        'layout gap keyword'        => ['layout' => ['gap' => 'none']],
        'layout unknown field'      => ['layout' => ['float' => 'left']],
        'layout list'               => ['layout' => ['flex']],
        'size ratio free'           => ['dimensions' => ['aspect_ratio' => '16/9;x:y']],
        'size ratio zero'           => ['dimensions' => ['aspect_ratio' => '0/9']],
        'size overflow'             => ['dimensions' => ['overflow' => 'clip; x']],
        'size object_position url'  => ['dimensions' => ['object_position' => 'url(x)']],
        'effects unknown'           => ['effects' => ['animation' => 'x']],
        'effects rotate huge'       => ['effects' => ['transform' => ['rotate' => 361]]],
        'effects rotate nan'        => ['effects' => ['transform' => ['rotate' => NAN]]],
        'effects rotate string'     => ['effects' => ['transform' => ['rotate' => '3deg']]],
        'effects scale negative'    => ['effects' => ['transform' => ['scale' => -1]]],
        'effects translate url'     => ['effects' => ['transform' => ['translate_x' => 'url(x)']]],
        'effects filter unknown'    => ['effects' => ['filter' => ['drop-shadow' => 5]]],
        'effects filter huge'       => ['effects' => ['filter' => ['blur' => 51]]],
        'effects backdrop huge'     => ['effects' => ['backdrop_blur' => 1e9]],
        'effects blend free'        => ['effects' => ['blend' => 'normal;x:y']],
        'effects cursor url'        => ['effects' => ['cursor' => 'url(x), auto']],
        'effects transition long'   => ['effects' => ['transition' => ['duration_ms' => 4001]]],
        'effects transition easing' => ['effects' => ['transition' => ['easing' => 'cubic-bezier(0,0,0,0)']]],
        'effects transition prop'   => ['effects' => ['transition' => ['property' => 'all']]],
    ];
    foreach ($bad as $label => $style) {
        assert_false(sbp5s_validate($style)->isValid(), "must refuse: {$label}");
    }
});

// ── Emission ──────────────────────────────────────────────────────────────

unit('surface: declarations are server-formatted, in table order', function (): void {
    assert_eq('display:flex;flex-direction:column;justify-content:space-between;align-items:center;gap:1.5rem;order:-1', StyleSurface::declarations(['layout' => ['order' => -1, 'gap' => '1.5rem', 'align' => 'center', 'justify' => 'between', 'direction' => 'column', 'display' => 'flex']]));
    assert_eq('display:grid;grid-template-columns:repeat(3,minmax(0,1fr))', StyleSurface::declarations(['layout' => ['display' => 'grid', 'columns' => 3]]));
    assert_eq('position:sticky;top:0', StyleSurface::declarations(['position' => ['mode' => 'sticky', 'top' => '0']]));
    assert_eq('aspect-ratio:16/9;object-fit:cover;object-position:top left', StyleSurface::declarations(['dimensions' => ['aspect_ratio' => '16/9', 'object_fit' => 'cover', 'object_position' => 'top-left']]));
    assert_eq('transform:translate(10px,0) rotate(-3.5deg) scale(1.05) skew(2deg,0deg);filter:blur(4px) brightness(110%);-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);mix-blend-mode:multiply;transition:all 300ms ease-out;cursor:pointer', StyleSurface::declarations(['effects' => [
        'cursor' => 'pointer', 'transition' => ['duration_ms' => 300, 'easing' => 'ease-out'], 'blend' => 'multiply', 'backdrop_blur' => 8,
        'filter' => ['brightness' => 110, 'blur' => 4], 'transform' => ['skew_x' => 2, 'scale' => 1.05, 'rotate' => -3.5, 'translate_x' => '10px'],
    ]]));
    assert_eq('', StyleSurface::declarations([]), 'no surface keys, no declarations');
    assert_eq('', StyleSurface::declarations(['typography' => ['size' => '2rem']]), 'legacy keys are not this class\'s business');
});

unit('surface: a stored hostile value is not emitted, and its good neighbours are', function (): void {
    $css = StyleSurface::declarations(['layout' => ['display' => 'flex', 'gap' => 'url(x)', 'columns' => 99, 'order' => 2], 'position' => ['mode' => 'fixed', 'top' => '0'], 'effects' => ['cursor' => 'pointer', 'transform' => ['rotate' => 9999]]]);
    assert_eq('display:flex;order:2;top:0;cursor:pointer', $css);
    foreach (['url(', 'fixed', '99', '9999'] as $needle) {
        assert_false(str_contains($css, $needle), "must not emit {$needle}");
    }
});

unit('surface: numbers are formatted by the server, never echoed', function (): void {
    $css = StyleSurface::declarations(['effects' => ['transform' => ['rotate' => 1.23456789, 'scale' => 1e-9], 'filter' => ['blur' => 0.5]]]);
    assert_true(str_contains($css, 'rotate(1.235deg)'), $css);
    assert_true(str_contains($css, 'scale(0)'), $css);
    assert_true(str_contains($css, 'blur(0.5px)'), $css);
    assert_false(str_contains($css, 'e-'), 'no exponent notation');
});

// ── Scoped rule in the page stylesheet ────────────────────────────────────

unit('surface: the rule is scoped to the block\'s own class and lives in the stylesheet, not the markup', function (): void {
    $out = sbss_render(['layout' => ['display' => 'flex', 'gap' => '1rem'], 'position' => ['mode' => 'relative']]);
    assert_true(str_contains($out['html'], 'sb-b-aaaaaaaaaaaaaaaaaaaaaaaa'), 'the block carries its scoped class: ' . $out['html']);
    assert_true(str_contains($out['css'], '.sb-b-aaaaaaaaaaaaaaaaaaaaaaaa{display:flex;gap:1rem;position:relative}'), $out['css']);
    assert_false(str_contains($out['html'], 'display:flex'), 'nothing generated is inlined into the markup');
    assert_false(str_contains($out['html'], 'style='), 'no style attribute is added for the surface');
});

unit('surface: reduced motion switches transitions off', function (): void {
    $out = sbss_render(['effects' => ['transition' => ['duration_ms' => 300]]]);
    assert_true(str_contains($out['css'], 'transition:all 300ms ease'), $out['css']);
    assert_true(str_contains($out['css'], '@media (prefers-reduced-motion:reduce){.sb-b-aaaaaaaaaaaaaaaaaaaaaaaa{transition:none}}'), $out['css']);
    $none = sbss_render(['effects' => ['cursor' => 'pointer']]);
    assert_false(str_contains($none['css'], 'prefers-reduced-motion'), 'no transition, no media rule');
});

unit('surface: a block that does not use the surface renders exactly as before', function (): void {
    $plain = sbss_render(['typography' => ['size' => '2rem'], 'opacity' => 0.9]);
    assert_false(str_contains($plain['html'], 'sb-b-'), 'no scoped class: ' . $plain['html']);
    assert_eq('', $plain['css'], 'and no rule');
});

unit('surface: two blocks get two independent rules, sorted deterministically', function (): void {
    $tenants   = new TenantContext();
    $renderer  = new DocumentRenderer(BlockRegistry::withAllCoreBlocks(), BlockRendererRegistry::withCoreRenderers(), new CoreMediaResolver($tenants), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    $collector = new RenderCollector();
    foreach (['blk_bbbbbbbbbbbbbbbbbbbbbbbb' => 'pointer', 'blk_aaaaaaaaaaaaaaaaaaaaaaaa' => 'grab'] as $id => $cursor) {
        $renderer->renderBlock([
            'id' => $id, 'type' => 'core.heading', 'version' => 1, 'props' => ['text' => 'x', 'level' => 'h2'],
            'style' => ['effects' => ['cursor' => $cursor]] + CanonicalDocumentSchema::defaultBlockStyle(),
            'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [], 'animation' => [], 'interactions' => [],
        ], RenderContext::forPublic(101, new SiteContext('https://example.test', 'T')), (new ThemeResolver())->resolve('default'), $collector, false);
    }
    assert_eq('.sb-b-aaaaaaaaaaaaaaaaaaaaaaaa{cursor:grab}.sb-b-bbbbbbbbbbbbbbbbbbbbbbbb{cursor:pointer}', $collector->css());
});

unit('surface: a malformed block id never produces a rule', function (): void {
    foreach (['', 'x', 'blk_', 'blk_ABC', 'blk_aaaa"}body{x:y'] as $id) {
        $out = sbss_render(['effects' => ['cursor' => 'pointer']], $id);
        assert_eq('', $out['css'], "no rule for id '{$id}'");
    }
});

// ── The signature cannot be covered (extends the P3a test) ────────────────

unit('surface: with position now authorable, nothing can stack above the signature', function (): void {
    $worst = sbss_render([
        'position' => ['mode' => 'absolute', 'top' => '0', 'left' => '0'],
        'dimensions' => ['width' => '100vw', 'height' => '100vh'],
        'effects' => ['blend' => 'multiply', 'transform' => ['scale' => 5]],
        'z_index' => 99999,
    ]);
    assert_false(str_contains($worst['css'] . $worst['html'], 'fixed'), 'fixed positioning is not reachable');
    assert_false(preg_match('/z-index:(\d{4,})/', $worst['css'] . $worst['html']) === 1, 'no four-digit z-index');
    assert_true(\Slate\Module\StudioBuilder\Http\StudioCodePolicy::SIGNATURE_Z_INDEX > CanonicalDocumentSchema::Z_INDEX_MAX);
    $guard = \Slate\Module\StudioBuilder\Http\StudioCodePolicy::signatureProtectionCss();
    foreach (['position:relative', 'mix-blend-mode:normal', 'transform:none', 'inset:auto'] as $decl) {
        assert_true(str_contains($guard, $decl . '!important'), "the guard resets {$decl}");
    }
});
