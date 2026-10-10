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

/** The content-addressed class a styled element carries (identical looks share it). */
function sbss_class(string $html): string
{
    assert_true(preg_match('/\bsb-x-[0-9a-f]{16}\b/', $html, $m) === 1, 'the element carries a scoped class: ' . $html);
    return $m[0];
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
        assert_true($r->isValid(), 'must validate ' . json_encode($style) . ' → ' . json_encode($r->errors()));
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
    $cls = sbss_class($out['html']);
    assert_true(str_contains($out['css'], '.' . $cls . '{display:flex;gap:1rem;position:relative}'), $out['css']);
    assert_false(str_contains($out['html'], 'display:flex'), 'nothing generated is inlined into the markup');
    assert_false(str_contains($out['html'], 'style='), 'no style attribute is added for the surface');
});

unit('surface: reduced motion switches transitions off', function (): void {
    $out = sbss_render(['effects' => ['transition' => ['duration_ms' => 300]]]);
    assert_true(str_contains($out['css'], 'transition:all 300ms ease'), $out['css']);
    assert_true(str_contains($out['css'], '@media (prefers-reduced-motion:reduce){.' . sbss_class($out['html']) . '{transition:none}}'), $out['css']);
    $none = sbss_render(['effects' => ['cursor' => 'pointer']]);
    assert_false(str_contains($none['css'], 'prefers-reduced-motion'), 'no transition, no media rule');
});

unit('surface: a block that does not use the surface renders exactly as before', function (): void {
    $plain = sbss_render(['typography' => ['size' => '2rem'], 'opacity' => 0.9]);
    assert_false(str_contains($plain['html'], 'sb-x-'), 'no scoped class: ' . $plain['html']);
    assert_eq('', $plain['css'], 'and no rule');
});

unit('surface: different looks get different rules; identical looks share one class and one rule', function (): void {
    $tenants   = new TenantContext();
    $renderer  = new DocumentRenderer(BlockRegistry::withAllCoreBlocks(), BlockRendererRegistry::withCoreRenderers(), new CoreMediaResolver($tenants), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    $collector = new RenderCollector();
    $classes = [];
    foreach (['blk_bbbbbbbbbbbbbbbbbbbbbbbb' => 'pointer', 'blk_aaaaaaaaaaaaaaaaaaaaaaaa' => 'grab', 'blk_cccccccccccccccccccccccc' => 'pointer'] as $id => $cursor) {
        $html = $renderer->renderBlock([
            'id' => $id, 'type' => 'core.heading', 'version' => 1, 'props' => ['text' => 'x', 'level' => 'h2'],
            'style' => ['effects' => ['cursor' => $cursor]] + CanonicalDocumentSchema::defaultBlockStyle(),
            'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [], 'animation' => [], 'interactions' => [],
        ], RenderContext::forPublic(101, new SiteContext('https://example.test', 'T')), (new ThemeResolver())->resolve('default'), $collector, false);
        $classes[$id] = sbss_class($html);
    }
    assert_eq($classes['blk_bbbbbbbbbbbbbbbbbbbbbbbb'], $classes['blk_cccccccccccccccccccccccc'], 'same look, same class');
    assert_true($classes['blk_aaaaaaaaaaaaaaaaaaaaaaaa'] !== $classes['blk_bbbbbbbbbbbbbbbbbbbbbbbb'], 'different look, different class');
    $css = $collector->css();
    assert_eq(2, substr_count($css, '{cursor:'), 'two rules, not three: ' . $css);
    // Deterministic order: sorted by class, whatever order the blocks were rendered in.
    $expected = [];
    foreach (['grab' => $classes['blk_aaaaaaaaaaaaaaaaaaaaaaaa'], 'pointer' => $classes['blk_bbbbbbbbbbbbbbbbbbbbbbbb']] as $cursor => $class) {
        $expected[$class] = '.' . $class . '{cursor:' . $cursor . '}';
    }
    ksort($expected, SORT_STRING);
    assert_eq(implode('', $expected), $css);
});

unit('surface: the class depends only on the rules, so the same look is the same class on every page', function (): void {
    $a = sbss_render(['effects' => ['cursor' => 'pointer']], 'blk_aaaaaaaaaaaaaaaaaaaaaaaa');
    $b = sbss_render(['effects' => ['cursor' => 'pointer']], 'blk_zzzzzzzzzzzzzzzzzzzzzzzz');
    assert_eq(sbss_class($a['html']), sbss_class($b['html']));
    assert_eq($a['css'], $b['css']);
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

// ═══ Slice 2: spacing, border sides/corners, typography extras, custom shadow, interaction states ═══

use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Document\DocumentValidator;

/** Validate a document whose one block carries `style` plus any extra block keys (e.g. style_states). */
function sbss_validate_block(array $style, array $extra = []): \Slate\Module\StudioBuilder\Schema\ValidationResult
{
    $doc = [
        'schema_version' => '1.0', 'document_type' => 'page', 'template_key' => 'default', 'settings' => [], 'seo' => [],
        'sections' => [[
            'id' => CanonicalDocumentSchema::newSectionId(), 'label' => 'S', 'global_ref' => null, 'layout' => [],
            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            'blocks' => [[
                'id' => CanonicalDocumentSchema::newBlockId(), 'type' => 'core.heading', 'version' => 1,
                'props' => ['text' => 'x', 'level' => 'h2'], 'style' => $style + CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
            ] + $extra],
        ]],
    ];
    return DocumentValidator::validate($doc, BlockRegistry::withAllCoreBlocks());
}

unit('surface 2: valid margin, padding, border sides and corners, typography extras and a custom shadow validate', function (): void {
    foreach ([
        ['margin' => ['top' => '-1rem', 'bottom' => 'auto', 'left' => '0']],
        ['padding' => ['top' => '1rem', 'right' => '2rem', 'bottom' => '1rem', 'left' => '2rem']],
        ['border' => ['top' => ['width' => '2px', 'style' => 'solid', 'color' => '#e8734a'], 'left' => ['width' => '1px'], 'radius_corners' => ['tl' => '8px', 'br' => '50%']]],
        ['typography' => ['style' => 'italic', 'decoration' => 'underline', 'decoration_style' => 'wavy', 'decoration_color' => 'rgba(0,0,0,.5)', 'decoration_thickness' => '2px', 'decoration_offset' => '4px']],
        ['shadow' => ['x' => '0', 'y' => '10px', 'blur' => '25px', 'spread' => '-5px', 'color' => 'rgba(0,0,0,.15)', 'inset' => true]],
        ['shadow' => ['x' => '1px', 'y' => '1px', 'color' => '#000']],
        ['border' => ['top' => ['color' => 'color.accent'], 'left' => ['color' => 'border.default']]],
        ['typography' => ['decoration' => 'underline', 'decoration_color' => 'text.muted']],
        ['shadow' => ['x' => '0', 'y' => '4px', 'color' => 'color.accent']],
    ] as $style) {
        $r = sbss_validate_block($style);
        assert_true($r->isValid(), 'must validate ' . json_encode($style) . ' → ' . json_encode($r->errors()));
    }
});

unit('surface 2: invalid spacing, border, typography and shadow values are refused', function (): void {
    $bad = [
        'padding negative'        => ['padding' => ['top' => '-1px']],
        'padding unitless'        => ['padding' => ['top' => '12']],
        'padding number'          => ['padding' => ['top' => 12]],
        'margin unknown side'     => ['margin' => ['middle' => '1px']],
        'margin url'              => ['margin' => ['top' => 'url(x)']],
        'margin keyword'          => ['margin' => ['top' => 'inherit']],
        'border side unknown'     => ['border' => ['top' => ['weight' => '1px']]],
        'border side style'       => ['border' => ['top' => ['style' => 'groove; x:y']]],
        'border side non-colour token' => ['border' => ['top' => ['color' => 'space.md']]],
        'shadow non-colour token' => ['shadow' => ['x' => '1px', 'y' => '1px', 'color' => 'radius.md']],
        'deco non-colour token' => ['typography' => ['decoration_color' => 'shadow.lg']],
        'border side url colour'  => ['border' => ['top' => ['color' => 'url(x)']]],
        'border corner unknown'   => ['border' => ['radius_corners' => ['xx' => '1px']]],
        'border corner url'       => ['border' => ['radius_corners' => ['tl' => 'url(x)']]],
        'typography decoration'   => ['typography' => ['decoration' => 'blink']],
        'typography deco colour'  => ['typography' => ['decoration_color' => 'var(--x)']],
        'typography deco thick'   => ['typography' => ['decoration_thickness' => 'url(x)']],
        'shadow object no colour' => ['shadow' => ['x' => '1px', 'y' => '1px']],
        'shadow object url'       => ['shadow' => ['x' => '1px', 'y' => '1px', 'color' => 'url(x)']],
        'shadow object unknown'   => ['shadow' => ['x' => '1px', 'y' => '1px', 'color' => '#000', 'z' => '1px']],
        'shadow inset string'     => ['shadow' => ['x' => '1px', 'y' => '1px', 'color' => '#000', 'inset' => 'yes']],
    ];
    foreach ($bad as $label => $style) {
        assert_false(sbss_validate_block($style)->isValid(), "must refuse: {$label}");
    }
});

unit('surface tokens: a theme colour is written out as the theme\'s own custom property, and the name is the renderer\'s', function (): void {
    assert_eq('border-top-color:var(--sb-color-accent)', StyleSurface::declarations(['border' => ['top' => ['color' => 'color.accent']]]));
    assert_eq('text-decoration-line:underline;text-decoration-color:var(--sb-text-muted)', StyleSurface::declarations(['typography' => ['decoration' => 'underline', 'decoration_color' => 'text.muted']]));
    assert_eq('box-shadow:0 4px var(--sb-color-accent)', StyleSurface::declarations(['shadow' => ['x' => '0', 'y' => '4px', 'color' => 'color.accent']]));
    assert_eq('box-shadow:inset 0 4px 12px 2px #000', StyleSurface::declarations(['shadow' => ['inset' => true, 'x' => '0', 'y' => '4px', 'blur' => '12px', 'spread' => '2px', 'color' => '#000']]), 'a literal colour is unchanged');
    assert_eq('', StyleSurface::declarations(['border' => ['top' => ['color' => 'space.md']]]), 'a token that is not a colour is never written');
    foreach (['color.accent', 'text.muted', 'surface.alt', 'border.default', 'color.brand.dark'] as $ref) {
        assert_eq(\Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme::cssVarName($ref), '--sb-' . str_replace('.', '-', $ref), "the custom property name for {$ref} is the renderer's");
        assert_true(StyleSurface::isColourToken($ref), "{$ref} is a colour token");
    }
    foreach (['space.md', 'radius.sm', 'shadow.lg', 'font.body', '#fff', 'color', 'color.', 'Color.accent'] as $notColour) {
        assert_true(!StyleSurface::isColourToken($notColour), "{$notColour} is not a colour token");
    }
});

unit('surface 2: declarations for the new fields', function (): void {
    assert_eq('margin-top:-1rem;margin-bottom:auto;padding-left:2rem', StyleSurface::declarations(['padding' => ['left' => '2rem'], 'margin' => ['bottom' => 'auto', 'top' => '-1rem']]));
    assert_eq('border-top-width:2px;border-top-style:solid;border-top-color:#e8734a;border-top-left-radius:8px;border-bottom-right-radius:50%', StyleSurface::declarations(['border' => ['radius_corners' => ['br' => '50%', 'tl' => '8px'], 'top' => ['color' => '#e8734a', 'style' => 'solid', 'width' => '2px']]]));
    assert_eq('font-style:italic;text-decoration-line:underline;text-decoration-style:wavy;text-decoration-thickness:2px;text-underline-offset:4px', StyleSurface::declarations(['typography' => ['decoration_offset' => '4px', 'decoration_thickness' => '2px', 'decoration_style' => 'wavy', 'decoration' => 'underline', 'style' => 'italic', 'size' => '9px']]));
    assert_eq('box-shadow:inset 0 10px 25px -5px rgba(0,0,0,.15)', StyleSurface::declarations(['shadow' => ['inset' => true, 'x' => '0', 'y' => '10px', 'blur' => '25px', 'spread' => '-5px', 'color' => 'rgba(0,0,0,.15)']]));
    assert_eq('box-shadow:1px 1px #000', StyleSurface::declarations(['shadow' => ['x' => '1px', 'y' => '1px', 'color' => '#000']]));
    assert_eq('', StyleSurface::declarations(['shadow' => 'lg']), 'a preset shadow is a class, not a declaration');
    assert_eq('', StyleSurface::declarations(['shadow' => ['x' => '1px', 'y' => '1px', 'color' => 'url(x)']]), 'a bad stored shadow object emits nothing');
});

// ── Interaction states ────────────────────────────────────────────────────

unit('states: valid hover, focus, active and disabled overlays validate', function (): void {
    $states = [
        'hover'    => ['color' => '#fff', 'background' => ['color' => '#e8734a', 'gradient' => 'linear-gradient(135deg, #e8734a, #8a3d24)'], 'border' => ['color' => '#fff'], 'shadow' => '0 10px 25px rgba(0,0,0,.2)', 'opacity' => 0.9, 'effects' => ['transform' => ['scale' => 1.05, 'translate_y' => '-2px'], 'filter' => ['brightness' => 110]], 'typography' => ['color' => '#000', 'decoration' => 'underline']],
        'focus'    => ['shadow' => ['x' => '0', 'y' => '0', 'spread' => '3px', 'color' => 'rgba(232,115,74,.5)']],
        'active'   => ['effects' => ['transform' => ['scale' => 0.98]]],
        'disabled' => ['opacity' => 0.5, 'effects' => ['blend' => 'normal']],
    ];
    $r = sbss_validate_block([], ['style_states' => $states]);
    assert_true($r->isValid(), json_encode($r->errors()));
    assert_true(sbss_validate_block([], ['style_states' => []])->isValid(), 'an empty map is fine');
});

unit('states: unknown states, unknown keys, base-only fields and hostile values are refused', function (): void {
    $bad = [
        'unknown state'         => ['visited' => ['color' => '#fff']],
        'before pseudo'         => ['before' => ['color' => '#fff']],
        'list'                  => [['color' => '#fff']],
        'state not object'      => ['hover' => '#fff'],
        'key not allowed'       => ['hover' => ['position' => ['mode' => 'fixed']]],
        'layout not allowed'    => ['hover' => ['layout' => ['display' => 'none']]],
        'z_index not allowed'   => ['hover' => ['z_index' => 5]],
        'colour url'            => ['hover' => ['color' => 'url(x)']],
        'colour token'          => ['hover' => ['color' => 'text.primary']],
        'background url'        => ['hover' => ['background' => ['color' => 'url(x)']]],
        'background image key'  => ['hover' => ['background' => ['image' => 'x.png']]],
        'gradient url'          => ['hover' => ['background' => ['gradient' => 'linear-gradient(url(x), red)']]],
        'opacity high'          => ['hover' => ['opacity' => 2]],
        'shadow string url'     => ['hover' => ['shadow' => '0 0 0 url(x)']],
        'effects transition'    => ['hover' => ['effects' => ['transition' => ['duration_ms' => 100]]]],
        'effects cursor'        => ['hover' => ['effects' => ['cursor' => 'pointer']]],
        'effects rotate huge'   => ['hover' => ['effects' => ['transform' => ['rotate' => 9999]]]],
    ];
    foreach ($bad as $label => $states) {
        assert_false(sbss_validate_block([], ['style_states' => $states])->isValid(), "must refuse: {$label}");
    }
    assert_false(sbss_validate_block([], ['style_states' => 'hover'])->isValid(), 'a string is not a states map');
});

unit('states: they render as pseudo-class rules scoped to the block, with the base rule', function (): void {
    $tenants   = new TenantContext();
    $renderer  = new DocumentRenderer(BlockRegistry::withAllCoreBlocks(), BlockRendererRegistry::withCoreRenderers(), new CoreMediaResolver($tenants), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    $collector = new RenderCollector();
    $html = $renderer->renderBlock([
        'id' => 'blk_aaaaaaaaaaaaaaaaaaaaaaaa', 'type' => 'core.heading', 'version' => 1, 'props' => ['text' => 'x', 'level' => 'h2'],
        'style' => ['effects' => ['cursor' => 'pointer', 'transition' => ['duration_ms' => 200]]] + CanonicalDocumentSchema::defaultBlockStyle(),
        'style_states' => [
            'hover'    => ['background' => ['color' => '#e8734a'], 'effects' => ['transform' => ['scale' => 1.05]]],
            'focus'    => ['shadow' => ['x' => '0', 'y' => '0', 'spread' => '3px', 'color' => '#e8734a']],
            'disabled' => ['opacity' => 0.5, 'color' => 'url(x)'],
        ],
        'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [], 'animation' => [], 'interactions' => [],
    ], RenderContext::forPublic(101, new SiteContext('https://example.test', 'T')), (new ThemeResolver())->resolve('default'), $collector, false);
    $css = $collector->css();
    $c = '.' . sbss_class($html);
    assert_true(str_contains($css, $c . '{transition:all 200ms ease;cursor:pointer}'), $css);
    assert_true(str_contains($css, $c . ':hover{background-color:#e8734a;transform:scale(1.05)}'), $css);
    assert_true(str_contains($css, $c . ':focus{box-shadow:0 0 0 3px #e8734a}'), 'a spread without a blur keeps the blur slot: ' . $css);
    assert_true(str_contains($css, $c . ':disabled{opacity:0.5}'), 'the bad colour is dropped, the opacity stays: ' . $css);
    assert_false(str_contains($css, 'url('), 'no url() anywhere: ' . $css);
    assert_true(str_contains($css, '@media (prefers-reduced-motion:reduce)'), 'transitions respect reduced motion');
    assert_false(str_contains($html, 'hover'), 'states never touch the markup');
});

unit('states: a state with only invalid values produces no rule at all', function (): void {
    assert_eq([], StyleSurface::stateRules(['hover' => ['color' => 'url(x)'], 'focus' => []]));
    assert_eq(['hover' => 'opacity:0.5'], StyleSurface::stateRules(['hover' => ['opacity' => 0.5]]));
});

unit('states: the normalizer drops empty states and keeps keys sorted', function (): void {
    $block = fn (array $states) => [
        'id' => CanonicalDocumentSchema::newBlockId(), 'type' => 'core.heading', 'version' => 1, 'props' => ['text' => 'x', 'level' => 'h2'],
        'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [], 'children' => [], 'style_states' => $states,
    ];
    $doc = fn (array $b) => ['schema_version' => '1.0', 'document_type' => 'page', 'template_key' => 'default', 'settings' => [], 'seo' => [], 'sections' => [[
        'id' => CanonicalDocumentSchema::newSectionId(), 'label' => 'S', 'global_ref' => null, 'layout' => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'blocks' => [$b],
    ]]];
    $registry = BlockRegistry::withAllCoreBlocks();
    $out = DocumentNormalizer::normalize($doc($block(['hover' => [], 'focus' => ['opacity' => 0.5, 'color' => '#fff']])), $registry);
    $states = $out['sections'][0]['blocks'][0]['style_states'] ?? null;
    assert_eq(['focus' => ['color' => '#fff', 'opacity' => 0.5]], $states, 'the empty hover state is gone and keys are sorted');
    $none = DocumentNormalizer::normalize($doc($block(['hover' => []])), $registry);
    assert_false(array_key_exists('style_states', $none['sections'][0]['blocks'][0]), 'an all-empty map is not stored');
});

// ═══ Slice 3: background image (media_ref), fit, repeat, focal point, overlay ═══

use Slate\Module\StudioBuilder\Dependency\DependencyExtractor;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;

final class SbssMedia implements MediaResolverInterface
{
    /** @param array<int, string> $urls media id => url */
    public function __construct(private array $urls) {}

    public function resolveImage(int $id): ?ResolvedMedia
    {
        return isset($this->urls[$id]) ? new ResolvedMedia($id, $this->urls[$id], 800, 600) : null;
    }
}

/** @return array{html: string, css: string} */
function sbss_render_with_media(array $style, array $urls): array
{
    $tenants   = new TenantContext();
    $renderer  = new DocumentRenderer(BlockRegistry::withAllCoreBlocks(), BlockRendererRegistry::withCoreRenderers(), new SbssMedia($urls), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    $collector = new RenderCollector();
    $html = $renderer->renderBlock([
        'id' => 'blk_aaaaaaaaaaaaaaaaaaaaaaaa', 'type' => 'core.heading', 'version' => 1, 'props' => ['text' => 'x', 'level' => 'h2'],
        'style' => $style + CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [], 'animation' => [], 'interactions' => [],
    ], RenderContext::forPublic(101, new SiteContext('https://example.test', 'T')), (new ThemeResolver())->resolve('default'), $collector, false);
    return ['html' => $html, 'css' => $collector->css()];
}

unit('background: a media_ref image with fit, repeat, position and overlay validates', function (): void {
    foreach ([
        ['background' => ['image' => ['media_id' => 7, 'alt' => '']]],
        ['background' => ['image' => ['media_id' => 7, 'alt' => 'Team', 'focal_point' => [0.25, 0.75]], 'fit' => 'contain', 'repeat' => 'repeat-x', 'overlay' => ['color' => 'rgba(0,0,0,.4)']]],
        ['background' => ['image' => ['media_id' => 7, 'alt' => ''], 'position' => 'top-right']],
        ['background' => ['image' => 'https://typed.example/legacy.png']],   // pre-P3b typed URL: ignored, as before
    ] as $style) {
        $r = sbss_validate_block($style);
        assert_true($r->isValid(), 'must validate ' . json_encode($style) . ' → ' . json_encode($r->errors()));
    }
});

unit('background: bad images, fits, repeats, positions and overlays are refused', function (): void {
    $bad = [
        'media id zero'         => ['background' => ['image' => ['media_id' => 0, 'alt' => '']]],
        'media id string'       => ['background' => ['image' => ['media_id' => '7', 'alt' => '']]],
        'media url key'         => ['background' => ['image' => ['media_id' => 7, 'alt' => '', 'url' => 'https://evil.test/x.png']]],
        'media tenant id'       => ['background' => ['image' => ['media_id' => 7, 'alt' => '', 'tenant_id' => 2]]],
        'media no alt'          => ['background' => ['image' => ['media_id' => 7]]],
        'focal point range'     => ['background' => ['image' => ['media_id' => 7, 'alt' => '', 'focal_point' => [2, 0]]]],
        'image and gradient'    => ['background' => ['image' => ['media_id' => 7, 'alt' => ''], 'gradient' => 'linear-gradient(red, blue)']],
        'fit free'              => ['background' => ['fit' => 'cover; x:y']],
        'repeat free'           => ['background' => ['repeat' => 'space']],
        'position free'         => ['background' => ['position' => '10px 20px']],
        'overlay url'           => ['background' => ['overlay' => ['color' => 'url(x)']]],
        'overlay token'         => ['background' => ['overlay' => ['color' => 'surface.dark']]],
        'overlay empty'         => ['background' => ['overlay' => []]],
        'overlay extra'         => ['background' => ['overlay' => ['color' => '#000', 'image' => 'x']]],
    ];
    foreach ($bad as $label => $style) {
        assert_false(sbss_validate_block($style)->isValid(), "must refuse: {$label}");
    }
});

unit('background: the image comes from the tenant media resolver and renders as one scoped rule', function (): void {
    $out = sbss_render_with_media(['background' => ['image' => ['media_id' => 7, 'alt' => '', 'focal_point' => [0.25, 0.75]], 'overlay' => ['color' => 'rgba(0,0,0,.4)'], 'fit' => 'cover', 'repeat' => 'no-repeat']], [7 => '/uploads/t101/hero.jpg']);
    assert_true(str_contains($out['css'], 'background-image:linear-gradient(rgba(0,0,0,.4),rgba(0,0,0,.4)),url("/uploads/t101/hero.jpg")'), $out['css']);
    assert_true(str_contains($out['css'], 'background-size:cover;background-repeat:no-repeat;background-position:25% 75%'), $out['css']);
    assert_false(str_contains($out['html'], 'hero.jpg'), 'the URL lives in the stylesheet only');
    assert_false(str_contains($out['html'], 'style='), 'nothing inline');
});

unit('background: defaults, and position keyword when there is no focal point', function (): void {
    $plain = sbss_render_with_media(['background' => ['image' => ['media_id' => 7, 'alt' => '']]], [7 => '/u/a.jpg']);
    assert_true(str_contains($plain['css'], 'background-image:url("/u/a.jpg");background-size:cover;background-repeat:no-repeat;background-position:center'), $plain['css']);
    $kw = sbss_render_with_media(['background' => ['image' => ['media_id' => 7, 'alt' => ''], 'position' => 'bottom-left']], [7 => '/u/a.jpg']);
    assert_true(str_contains($kw['css'], 'background-position:bottom left'), $kw['css']);
});

unit('background: a media id that does not resolve for this tenant produces nothing', function (): void {
    $out = sbss_render_with_media(['background' => ['image' => ['media_id' => 8, 'alt' => '']]], [7 => '/u/a.jpg']);
    assert_eq('', $out['css'], 'another tenant\'s or deleted media renders nothing');
});

unit('background: a resolver URL that could break out of url("") is never emitted', function (): void {
    foreach (['/u/a.jpg") ;}body{background:red', "/u/a.jpg\"", '/u/a b.jpg', "/u/a'.jpg", '/u/a\\.jpg', '/u/a.jpg)', 'javascript:alert(1)', 'data:image/svg+xml;base64,AAAA', '/u/<x>.jpg', '', 'u/a.jpg'] as $url) {
        $out = sbss_render_with_media(['background' => ['image' => ['media_id' => 7, 'alt' => '']]], [7 => $url]);
        assert_eq('', $out['css'], 'must refuse URL ' . json_encode($url));
    }
    foreach (['/uploads/a.jpg', 'https://cdn.example.test/a/b.jpg?v=3&w=800', '//cdn.example.test/a.png'] as $url) {
        $out = sbss_render_with_media(['background' => ['image' => ['media_id' => 7, 'alt' => '']]], [7 => $url]);
        assert_true(str_contains($out['css'], 'url("' . $url . '")'), 'must accept URL ' . $url . ': ' . $out['css']);
    }
});

unit('background: a typed legacy URL is still ignored, never rendered', function (): void {
    $out = sbss_render_with_media(['background' => ['image' => 'https://evil.test/x.png']], []);
    assert_eq('', $out['css']);
    assert_false(str_contains($out['html'], 'evil.test'));
});

unit('background: the image is a tracked media dependency', function (): void {
    $doc = ['schema_version' => '1.0', 'document_type' => 'page', 'template_key' => 'default', 'settings' => [], 'seo' => [], 'sections' => [[
        'id' => CanonicalDocumentSchema::newSectionId(), 'label' => 'S', 'global_ref' => null, 'layout' => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'blocks' => [[
            'id' => 'blk_aaaaaaaaaaaaaaaaaaaaaaaa', 'type' => 'core.heading', 'version' => 1, 'props' => ['text' => 'x', 'level' => 'h2'],
            'style' => ['background' => ['image' => ['media_id' => 7, 'alt' => '']]] + CanonicalDocumentSchema::defaultBlockStyle(),
            'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
        ]],
    ]]];
    $records = DependencyExtractor::extract($doc, BlockRegistry::withAllCoreBlocks());
    $media = array_values(array_filter($records, static fn ($r) => $r->dependencyType === 'media'));
    assert_eq(1, count($media), 'one media dependency');
    assert_eq('7', $media[0]->dependencyKey);
    assert_eq('blk_aaaaaaaaaaaaaaaaaaaaaaaa', $media[0]->nodeId);
});

unit('background: the editor\'s tenant check refuses a media id the tenant does not own', function (): void {
    $doc = ['schema_version' => '1.0', 'document_type' => 'page', 'template_key' => 'default', 'settings' => [], 'seo' => [], 'sections' => [[
        'id' => CanonicalDocumentSchema::newSectionId(), 'label' => 'S', 'global_ref' => null, 'layout' => [],
        'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'blocks' => [[
            'id' => CanonicalDocumentSchema::newBlockId(), 'type' => 'core.heading', 'version' => 1, 'props' => ['text' => 'x', 'level' => 'h2'],
            'style' => ['background' => ['image' => ['media_id' => 99, 'alt' => '']]] + CanonicalDocumentSchema::defaultBlockStyle(),
            'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
        ]],
    ]]];
    $own = static fn (int $id): bool => $id === 7;
    $r = DocumentValidator::validate($doc, BlockRegistry::withAllCoreBlocks(), ['media_exists' => $own]);
    assert_false($r->isValid(), 'media 99 is not this tenant\'s');
    assert_true(str_contains(json_encode($r->errors()), 'cross_tenant_or_missing_media'));
    $doc['sections'][0]['blocks'][0]['style']['background']['image']['media_id'] = 7;
    assert_true(DocumentValidator::validate($doc, BlockRegistry::withAllCoreBlocks(), ['media_exists' => $own])->isValid(), 'media 7 is');
});

// ── Per-device style overrides (block.responsive.<tablet|mobile>.style) ───

/** Render one heading carrying `responsive`, through the real renderer. */
function sbss_render_responsive(array $style, array $responsive): array
{
    $tenants   = new TenantContext();
    $renderer  = new DocumentRenderer(BlockRegistry::withAllCoreBlocks(), BlockRendererRegistry::withCoreRenderers(), new CoreMediaResolver($tenants), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    $collector = new RenderCollector();
    $html = $renderer->renderBlock([
        'id' => 'blk_aaaaaaaaaaaaaaaaaaaaaaaa', 'type' => 'core.heading', 'version' => 1,
        'props' => ['text' => 'Styled', 'level' => 'h2'],
        'style' => $style + CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [], 'children' => [], 'animation' => [], 'interactions' => [], 'responsive' => $responsive,
    ], RenderContext::forPublic(101, new SiteContext('https://example.test', 'T')), (new ThemeResolver())->resolve('default'), $collector, false);
    return ['html' => $html, 'css' => $collector->css()];
}

unit('responsive style: tablet and mobile overrides of size, padding, margin and gap validate', function (): void {
    $ok = [
        ['tablet' => ['style' => ['typography' => ['size' => '1.25rem']]]],
        ['mobile' => ['style' => ['padding' => ['top' => '1rem', 'bottom' => '1rem'], 'margin' => ['top' => '-1rem', 'left' => 'auto'], 'layout' => ['gap' => '8px', 'row_gap' => '4px', 'column_gap' => '2rem']]]],
        ['tablet' => ['hide' => true, 'style' => []], 'mobile' => ['align' => 'center']],
    ];
    foreach ($ok as $responsive) {
        $r = sbss_validate_block([], ['responsive' => $responsive]);
        assert_true($r->isValid(), 'must validate ' . json_encode($responsive) . ' → ' . json_encode($r->errors()));
    }
});

unit('responsive style: values outside the closed table are refused', function (): void {
    $bad = [
        'a colour' => ['tablet' => ['style' => ['color' => '#fff']]],
        'a free group' => ['mobile' => ['style' => ['custom_css' => 'x']]],
        'a url in a size' => ['tablet' => ['style' => ['typography' => ['size' => 'url(x)']]]],
        'a hostile padding' => ['mobile' => ['style' => ['padding' => ['top' => '1rem;color:red']]]],
        'a negative padding' => ['mobile' => ['style' => ['padding' => ['top' => '-1rem']]]],
        'an unknown typography field' => ['tablet' => ['style' => ['typography' => ['weight' => 'bold']]]],
        'a unitless gap' => ['tablet' => ['style' => ['layout' => ['gap' => '12']]]],
        'a layout field outside the gaps' => ['tablet' => ['style' => ['layout' => ['display' => 'flex']]]],
        'a list' => ['tablet' => ['style' => ['padding']]],
    ];
    foreach ($bad as $label => $responsive) {
        assert_false(sbss_validate_block([], ['responsive' => $responsive])->isValid(), "must refuse: {$label}");
    }
});

unit('responsive style: overrides render as media rules after the base rule, tablet before mobile', function (): void {
    $out = sbss_render_responsive(
        ['padding' => ['top' => '4rem'], 'typography' => ['size' => '3rem']],
        ['mobile' => ['style' => ['padding' => ['top' => '1rem'], 'typography' => ['size' => '1.25rem']]], 'tablet' => ['style' => ['padding' => ['top' => '2rem'], 'layout' => ['gap' => '12px']]]],
    );
    $class = sbss_class($out['html']);
    $rule  = ".{$class}{padding-top:4rem}"
        . "@media (max-width:1023.98px){.{$class}{padding-top:2rem !important;gap:12px !important}}"
        . "@media (max-width:767.98px){.{$class}{font-size:1.25rem !important;padding-top:1rem !important}}";
    assert_true(str_contains($out['css'], $rule), 'expected the rule in: ' . $out['css']);
    assert_true(str_contains($out['html'], 'font-size:3rem'), 'the desktop size stays inline');
});

unit('responsive style: an override alone still renders, and a hostile stored value is dropped', function (): void {
    $only = sbss_render_responsive([], ['tablet' => ['style' => ['padding' => ['left' => '2rem']]]]);
    $class = sbss_class($only['html']);
    assert_true(str_contains($only['css'], "@media (max-width:1023.98px){.{$class}{padding-left:2rem !important}}"), $only['css']);
    $evil = sbss_render_responsive([], ['tablet' => ['style' => ['padding' => ['left' => '2rem;background:url(x)'], 'color' => 'red']]]);
    assert_false(str_contains($evil['html'], 'sb-x-'), 'nothing valid, so no scoped class');
    assert_eq('', $evil['css'], 'and no rule');
});

unit('responsive style: documents without overrides render exactly as before', function (): void {
    $before = sbss_render(['padding' => ['top' => '4rem']]);
    $after  = sbss_render_responsive(['padding' => ['top' => '4rem']], ['tablet' => ['hide' => true], 'mobile' => ['align' => 'center'], 'md' => ['style' => ['padding' => ['top' => '9rem']]]]);
    assert_eq($before['css'], $after['css'], 'same stylesheet');
    assert_true(str_contains($after['html'], 'sb-hide-tablet') && str_contains($after['html'], 'sb-align-mobile-center'), 'the existing hide/align classes still apply');
});

// ── Typography reaches the block's own text ───────────────────────────────

unit('typography marks: only the properties the author set get a class', function (): void {
    $r = sbss_render(['typography' => ['size' => '40px', 'weight' => 'bold']]);
    assert_true(str_contains($r['html'], 'sb-ty-size') && str_contains($r['html'], 'sb-ty-fw'), 'size and weight are marked');
    foreach (['sb-ty-color', 'sb-ty-lh', 'sb-ty-ls', 'sb-ty-ff', 'sb-ty-tt'] as $mark) {
        assert_true(!str_contains($r['html'], $mark), "{$mark} is not set, so it is absent");
    }
    $r = sbss_render(['typography' => ['color' => '#ff0000', 'line_height' => 1.2, 'letter_spacing' => '0.02em', 'transform' => 'uppercase']]);
    foreach (['sb-ty-color', 'sb-ty-lh', 'sb-ty-ls', 'sb-ty-tt'] as $mark) {
        assert_true(str_contains($r['html'], $mark), "{$mark} is present");
    }
});

unit('typography marks: a block with no typography renders exactly as before', function (): void {
    $r = sbss_render([]);
    assert_true(!str_contains($r['html'], 'sb-ty-'), 'no marker class: ' . $r['html']);
});

unit('typography marks: a size set only for tablet or mobile still marks the block, an invalid one does not', function (): void {
    $r = sbss_render_responsive([], ['tablet' => ['style' => ['typography' => ['size' => '22px']]]]);
    assert_true(str_contains($r['html'], 'sb-ty-size'), 'a tablet-only size marks the block');
    $r = sbss_render(['typography' => ['size' => 'url(x)']]);
    assert_true(!str_contains($r['html'], 'sb-ty-size'), 'a refused size leaves the theme size alone');
});

unit('typography marks: the stylesheet makes the block text inherit, reaches three levels and never through a nested block', function (): void {
    \Slate\Module\StudioBuilder\Render\StudioStylesheet::resetCache();
    $css = \Slate\Module\StudioBuilder\Render\StudioStylesheet::css();
    assert_true(str_contains($css, '.sb-ty-size>:is(h1,h2,h3,h4,h5,h6,p,blockquote,figcaption,a,span,li),.sb-ty-size>:not(.sb-block)>'), 'the size rule is scoped to the block text');
    assert_true(str_contains($css, ':not(.sb-block)>:not(.sb-block)>:is('), 'it stops at nested blocks');
    foreach (['font-size', 'color', 'line-height', 'letter-spacing', 'font-family', 'font-weight', 'text-transform'] as $prop) {
        assert_true(str_contains($css, '{' . $prop . ':inherit !important}'), "{$prop} is inherited when marked");
    }
    assert_true(str_contains($css, '.sb-ty-color>:is(h1,h2,h3,h4,h5,h6,p,blockquote,figcaption,span,li,a.sb-button)'), 'a text colour does not repaint links, except a button label');
});
