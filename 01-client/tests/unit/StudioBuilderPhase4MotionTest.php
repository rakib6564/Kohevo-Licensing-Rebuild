<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Phase 4 motion exposure.
 *
 * Autoloader only, no database. Phase 4's premise is that the backend has
 * supported motion since Sprint 7 (`update_block_animation`,
 * `update_block_interactions`, the `animation` / `interactions` schema keys)
 * while the builder had no UI for it — and that the renderer silently DROPPED
 * the per-block timing the inspector wants to write. These tests pin the part
 * that was actually missing: that duration / delay / easing reach the stylesheet.
 *
 * They also pin the safety contract around those values, because they land in a
 * custom property that the stylesheet feeds straight into `animation:`.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Operation\DocumentOperationApplier;
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

const SBP4M_TENANT = 101;

/** Render one heading block carrying the given animation / interactions. */
function sbp4m_render(array $animation, array $interactions = []): string
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
        'props'        => ['text' => 'Motion', 'level' => 'h2'],
        'style'        => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility'   => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'     => [],
        'children'     => [],
        'animation'    => $animation,
        'interactions' => $interactions,
    ];

    return $renderer->renderBlock(
        $block,
        RenderContext::forPublic(SBP4M_TENANT, new SiteContext('https://example.test', 'Test Site')),
        $themes->resolve('default'),
        new RenderCollector(),
        false
    );
}

// ── The gap Phase 4 closed: timing must actually reach the stylesheet ──────

unit('phase4: duration, delay and easing are emitted as custom properties', function (): void {
    $html = sbp4m_render(['type' => 'fade_up', 'duration_ms' => 900, 'delay_ms' => 150, 'easing' => 'ease-out']);

    assert_true(str_contains($html, 'sb-animate-fade-up'), 'preset class: ' . $html);
    assert_true(str_contains($html, '--sb-anim-duration:900ms'), 'duration must reach the stylesheet: ' . $html);
    assert_true(str_contains($html, '--sb-anim-delay:150ms'), 'delay must reach the stylesheet: ' . $html);
    assert_true(str_contains($html, '--sb-anim-easing:ease-out'), 'easing must reach the stylesheet: ' . $html);
});

unit('phase4: the block emits exactly one style attribute', function (): void {
    // Motion variables ride along with the block's own inline styles. A second
    // `style=` would silently discard one of them in the browser.
    $html = sbp4m_render(['type' => 'fade_in', 'duration_ms' => 600], ['trigger' => 'hover']);
    assert_eq(1, preg_match_all('/\sstyle="/', $html), 'exactly one style attribute: ' . $html);
});

unit('phase4: a block with motion timing but no type emits no variables', function (): void {
    // `none` means no animation; emitting timing for it would be dead CSS.
    $html = sbp4m_render(['type' => 'none', 'duration_ms' => 900]);
    assert_false(str_contains($html, '--sb-anim-duration'), 'no variables for type none: ' . $html);
    assert_false(str_contains($html, 'sb-animate-'), 'no animate class for type none: ' . $html);
});

unit('phase4: a plain block emits no style attribute at all', function (): void {
    $html = sbp4m_render([]);
    assert_false(str_contains($html, '--sb-anim-'), 'no motion variables: ' . $html);
});
// ── Safety: these values reach `animation:` via a custom property ──────────

unit('phase4: timings are clamped to the range the inspector allows', function (): void {
    $html = sbp4m_render(['type' => 'fade_in', 'duration_ms' => 999999, 'delay_ms' => -500]);
    assert_true(str_contains($html, '--sb-anim-duration:4000ms'), 'duration clamped to 4000ms: ' . $html);
    assert_true(str_contains($html, '--sb-anim-delay:0ms'), 'negative delay clamped to 0: ' . $html);
});

unit('phase4: an unknown easing is dropped, never escaped into the attribute', function (): void {
    // Escaping would still be the wrong answer here: a custom property is fed
    // straight into `animation:`, so the value is allowlisted instead.
    $html = sbp4m_render(['type' => 'fade_in', 'easing' => 'cubic-bezier(1);background:url(//evil.test/x)']);
    assert_false(str_contains($html, '--sb-anim-easing'), 'unknown easing must be dropped: ' . $html);
    assert_false(str_contains($html, 'evil.test'), 'payload must not appear: ' . $html);
});

unit('phase4: a non-numeric duration is ignored rather than stringified', function (): void {
    $html = sbp4m_render(['type' => 'fade_in', 'duration_ms' => '600;background:url(//evil.test/x)']);
    assert_false(str_contains($html, '--sb-anim-duration'), 'non-numeric duration ignored: ' . $html);
    assert_false(str_contains($html, 'evil.test'), 'payload must not appear: ' . $html);
});

unit('phase4: an allowlisted easing survives', function (): void {
    $html = sbp4m_render(['type' => 'fade_in', 'easing' => 'cubic-bezier(.22,1,.36,1)']);
    assert_true(str_contains($html, '--sb-anim-easing:cubic-bezier(.22,1,.36,1)'), 'allowlisted easing kept: ' . $html);
});

// ── Interaction triggers ─────────────────────────────────────────────────

unit('phase4: an interaction trigger emits its class and data attribute', function (): void {
    $html = sbp4m_render(['type' => 'none'], ['trigger' => 'viewport-enter']);
    assert_true(str_contains($html, 'sb-interaction-viewport-enter'), 'trigger class: ' . $html);
    assert_true(str_contains($html, 'data-sb-interaction-trigger="viewport-enter"'), 'trigger attribute: ' . $html);
});

unit('phase4: a hover animation composes with the entrance animation', function (): void {
    $html = sbp4m_render(['type' => 'fade_in'], ['trigger' => 'hover', 'animation' => ['type' => 'scale_up']]);
    assert_true(str_contains($html, 'sb-animate-fade-in'), 'entrance class: ' . $html);
    assert_true(str_contains($html, 'sb-animate-scale-up'), 'hover class: ' . $html);
});

// ── The stylesheet must have rules for the classes the renderer emits ─────

unit('phase4: the stylesheet reads the custom properties, not a hardcoded duration', function (): void {
    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();
    foreach (['fade-in', 'fade-up', 'fade-down', 'scale-up', 'slide-in'] as $preset) {
        assert_true(
            str_contains($css, '.sb-animate-' . $preset . '{animation:sb-'),
            "preset {$preset} must animate"
        );
    }
    assert_true(substr_count($css, '--sb-anim-easing') >= 5, 'every preset must honour --sb-anim-easing');
    assert_true(substr_count($css, '--sb-anim-delay') >= 5, 'every preset must honour --sb-anim-delay');
});

unit('phase4: scroll-reveal CSS is @supports-guarded so content is never invisible', function (): void {
    // Without the guard, a browser that lacks scroll-driven animations would
    // leave the block at opacity:0 forever — content invisible beats no motion.
    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();
    assert_true(str_contains($css, '@supports (animation-timeline:view())'), 'must be @supports-guarded');
    assert_true(str_contains($css, '.sb-interaction-viewport-enter'), 'must define the viewport-enter trigger');
});

unit('phase4: focus trigger has keyboard parity with hover', function (): void {
    // A hover-only lift leaves keyboard users with no affordance at all.
    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();
    assert_true(str_contains($css, '.sb-interaction-focus:focus-visible'), 'focus-visible rule must exist');
    assert_true(substr_count($css, 'prefers-reduced-motion:reduce') >= 1, 'reduced motion must still be honoured');
});

// ── The operations the inspector sends ───────────────────────────────────

unit('phase4: both motion operations are in the server allowlist', function (): void {
    assert_true(in_array(DocumentOperation::OP_UPDATE_BLOCK_ANIMATION, DocumentOperation::ALLOWED_OPS, true));
    assert_true(in_array(DocumentOperation::OP_UPDATE_BLOCK_INTERACTIONS, DocumentOperation::ALLOWED_OPS, true));
});

unit('phase4: applying both motion operations writes the document fields', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $document = [
        'settings' => [],
        'seo'      => [],
        'sections' => [[
            'blocks' => [['id' => 'blk_motion_1', 'type' => 'core.heading']],
        ]],
    ];

    $afterAnimation = DocumentOperationApplier::applyOne(
        $document,
        new DocumentOperation(DocumentOperation::OP_UPDATE_BLOCK_ANIMATION, [
            'block_id'  => 'blk_motion_1',
            'animation' => ['type' => 'fade_up', 'duration_ms' => 700],
        ]),
        $registry
    );
    assert_eq(['type' => 'fade_up', 'duration_ms' => 700], $afterAnimation['sections'][0]['blocks'][0]['animation']);

    $afterInteractions = DocumentOperationApplier::applyOne(
        $afterAnimation,
        new DocumentOperation(DocumentOperation::OP_UPDATE_BLOCK_INTERACTIONS, [
            'block_id'     => 'blk_motion_1',
            'interactions' => ['trigger' => 'hover'],
        ]),
        $registry
    );
    assert_eq(['trigger' => 'hover'], $afterInteractions['sections'][0]['blocks'][0]['interactions']);
});