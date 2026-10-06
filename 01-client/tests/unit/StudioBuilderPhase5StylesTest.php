<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Phase 5 style ceiling.
 *
 * Autoloader only, no database.
 *
 * The Phase 5 premise, verified here: `ALLOWED_STYLE_KEYS` already listed
 * `typography`, `color`, `background`, `border`, `shadow`, `dimensions`,
 * `opacity` and `z_index`, and `DocumentValidator` already validated all of
 * them — but none of it was reachable from the inspector, so authors were
 * confined to token references and presets. `StyleControls.jsx` is the new
 * authoring surface.
 *
 * These tests pin the contract that surface depends on. If the validator
 * starts rejecting something a control can produce, or the renderer stops
 * emitting a key the control writes, the builder would fail at SAVE time with
 * no visible cause — so each key is asserted in both directions.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
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

const SBP5S_TENANT = 101;

/**
 * Render one heading block with the given style, through the real renderer.
 *
 * `buildInlineStyles` is private, so the only honest way to assert on emitted
 * CSS is to render a block and read the `style=` attribute back out.
 */
function sbp5s_render(array $style): string
{
    $registry  = BlockRegistry::withAllCoreBlocks();
    $renderers = BlockRendererRegistry::withCoreRenderers();
    $themes    = new ThemeResolver();
    $tenants   = new TenantContext();
    $renderer  = new DocumentRenderer(
        $registry,
        $renderers,
        new CoreMediaResolver($tenants),
        new ProviderBindingResolver(new DataProviderRegistry(), $tenants)
    );

    $block = [
        'id'           => CanonicalDocumentSchema::newBlockId(),
        'type'         => 'core.heading',
        'version'      => 1,
        'props'        => ['text' => 'Styled', 'level' => 'h2'],
        'style'        => $style + CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility'   => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'     => [],
        'children'     => [],
        'animation'    => [],
        'interactions' => [],
    ];

    return $renderer->renderBlock(
        $block,
        RenderContext::forPublic(SBP5S_TENANT, new SiteContext('https://example.test', 'Test Site')),
        $themes->resolve('default'),
        new RenderCollector(),
        false
    );
}

/** Validate a document carrying one block with the given style. */
function sbp5s_validate(array $style): \Slate\Module\StudioBuilder\Schema\ValidationResult
{
    $doc = [
        'schema_version' => '1.0',
        'document_type'  => 'page',
        'template_key'   => 'default',
        'settings'       => [],
        'seo'            => [],
        'sections'       => [[
            'id'         => CanonicalDocumentSchema::newSectionId(),
            'label'      => 'Styled',
            'global_ref' => null,
            'layout'     => [],
            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            'blocks'     => [[
                'id'         => CanonicalDocumentSchema::newBlockId(),
                'type'       => 'core.heading',
                'version'    => 1,
                'props'      => ['text' => 'x', 'level' => 'h2'],
                'style'      => $style + CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings'   => [],
                'children'   => [],
            ]],
        ]],
    ];
    return DocumentValidator::validate($doc, BlockRegistry::withAllCoreBlocks());
}

// ── Every key the new controls write must survive validation ───────────────

unit('phase5: a full inspector-authored style validates', function (): void {
    // This is literally what StyleControls produces when an author touches
    // every control: typography literals, a two-colour gradient, a full
    // border, a custom shadow, dimensions, opacity and z-index.
    $style = [
        'typography' => [
            'size'           => '1.5rem',
            'line_height'    => '1.5',
            'letter_spacing' => '-0.01em',
            'font_family'    => 'Inter, sans-serif',
            'color'          => '#e8734a',
        ],
        'color'       => '#f5f3f0',
        'background'  => ['color' => '#0b0c0f', 'gradient' => 'linear-gradient(135deg, #e8734a, #8a3d24)'],
        'border'      => ['style' => 'solid', 'radius' => 'lg', 'width' => '1px', 'color' => '#2b3358'],
        'shadow'      => '0 10px 25px rgba(0,0,0,.15)',
        'dimensions'  => ['width' => '100%', 'min_height' => '20rem', 'max_width' => '60rem'],
        'opacity'     => 0.85,
        'z_index'     => 5,
    ];

    $result = sbp5s_validate($style);
    $detail = array_map(
        static fn (array $i): string => ($i['path'] ?? '?') . ': ' . ($i['message'] ?? ''),
        $result->errors()
    );
    assert_true($result->isValid(), 'a full authored style must validate: ' . implode(' | ', $detail));
});

unit('phase5: each Phase 5 key is a declared style capability', function (): void {
    // The inspector hides a control unless the key is in ALLOWED_STYLE_KEYS, so
    // a key missing here is a control that silently never renders.
    foreach ([
        'typography', 'color', 'background', 'border',
        'shadow', 'dimensions', 'opacity', 'z_index',
    ] as $key) {
        assert_true(
            in_array($key, CanonicalDocumentSchema::ALLOWED_STYLE_KEYS, true),
            "ALLOWED_STYLE_KEYS must contain '{$key}'"
        );
    }
});

// ── …and must actually reach the page ──────────────────────────────────────

unit('phase5: typography literals reach the rendered style attribute', function (): void {
    $html = sbp5s_render([
        'typography' => [
            'size'           => '1.5rem',
            'line_height'    => '1.5',
            'letter_spacing' => '-0.01em',
            'font_family'    => 'Inter, sans-serif',
            'color'          => '#e8734a',
        ],
    ]);
    foreach ([
        'font-size:1.5rem',
        'line-height:1.5',
        'letter-spacing:-0.01em',
        'font-family:Inter, sans-serif',
        'color:#e8734a',
    ] as $decl) {
        assert_true(str_contains($html, $decl), "expected '{$decl}' in: " . $html);
    }
});

unit('phase5: background colour and gradient both reach the page', function (): void {
    $html = sbp5s_render([
        'background' => ['color' => '#0b0c0f', 'gradient' => 'linear-gradient(135deg, #e8734a, #8a3d24)'],
    ]);
    assert_true(str_contains($html, 'background-color:#0b0c0f'), 'background colour: ' . $html);
    assert_true(
        str_contains($html, 'background-image:linear-gradient(135deg, #e8734a, #8a3d24)'),
        'gradient: ' . $html
    );
});

unit('phase5: border width, colour and custom radius reach the page', function (): void {
    $html = sbp5s_render(['border' => ['style' => 'solid', 'radius' => '1.25rem', 'width' => '2px', 'color' => '#2b3358']]);
    assert_true(str_contains($html, 'border-width:2px'), 'border width: ' . $html);
    assert_true(str_contains($html, 'border-color:#2b3358'), 'border colour: ' . $html);
    // A non-preset radius is an inline value; a preset one becomes a utility
    // class instead. Only the former belongs in the style attribute.
    assert_true(str_contains($html, 'border-radius:1.25rem'), 'custom radius: ' . $html);
});

unit('phase5: a preset radius renders as a class, not an inline value', function (): void {
    $html = sbp5s_render(['border' => ['style' => 'solid', 'radius' => 'lg']]);
    assert_true(str_contains($html, 'sb-radius-lg'), 'preset radius class: ' . $html);
    assert_false(str_contains($html, 'border-radius:'), 'a preset must not also be inlined: ' . $html);
});

unit('phase5: a preset shadow renders as a class, a custom one as a value', function (): void {
    $preset = sbp5s_render(['shadow' => 'lg']);
    assert_true(str_contains($preset, 'sb-shadow-lg'), 'preset shadow class: ' . $preset);
    assert_false(str_contains($preset, 'box-shadow:'), 'a preset must not also be inlined: ' . $preset);

    $custom = sbp5s_render(['shadow' => '0 10px 25px rgba(0,0,0,.15)']);
    assert_true(str_contains($custom, 'box-shadow:0 10px 25px rgba(0,0,0,.15)'), 'custom shadow: ' . $custom);
});

unit('phase5: dimensions, opacity and z-index reach the page', function (): void {
    $html = sbp5s_render([
        'dimensions' => ['width' => '100%', 'min_height' => '20rem', 'max_width' => '60rem'],
        'opacity'    => 0.85,
        'z_index'    => 7,
    ]);
    foreach ([
        'width:100%',
        'min-height:20rem',
        'max-width:60rem',
        'opacity:0.85',
        'z-index:7',
    ] as $decl) {
        assert_true(str_contains($html, $decl), "expected '{$decl}' in: " . $html);
    }
});

// ── Safety: the ceiling is still a ceiling ─────────────────────────────────

unit('phase5: the style ceiling still rejects an unknown key', function (): void {
    $result = sbp5s_validate(['transform' => 'rotate(3deg)']);
    assert_false($result->isValid(), 'an unknown style key must fail validation');
});

unit('phase5: a script-bearing custom value cannot be injected', function (): void {
    // `isSafeCssValue` is the only thing between an author and a CSS escape
    // here, so it is asserted against the shapes that matter.
    foreach ([
        '0 10px 25px red;}</style><script>alert(1)</script>',
        'url(javascript:alert(1))',
        'expression(alert(1))',
    ] as $bad) {
        assert_false(sbp5s_validate(['shadow' => $bad])->isValid(), "must reject unsafe value: {$bad}");
    }
});

unit('phase5: opacity and z-index stay bounded', function (): void {
    assert_false(sbp5s_validate(['opacity' => 1.5])->isValid(), 'opacity above 1 must be rejected');
    assert_false(sbp5s_validate(['opacity' => -0.1])->isValid(), 'negative opacity must be rejected');
    assert_false(sbp5s_validate(['z_index' => 99999])->isValid(), 'an out-of-range z-index must be rejected');
});

unit('phase5: the block still emits exactly one style attribute', function (): void {
    // A second `style=` in the browser silently discards the first, which is
    // how a fully-authored block could lose every value it just set.
    $html = sbp5s_render([
        'typography' => ['size' => '2rem'],
        'background' => ['color' => '#000000'],
        'border'     => ['width' => '1px'],
        'shadow'     => '0 1px 2px rgba(0,0,0,.2)',
        'opacity'    => 0.9,
        'z_index'    => 2,
    ]);
    assert_eq(1, preg_match_all('/\sstyle="/', $html), 'exactly one style attribute: ' . $html);
});