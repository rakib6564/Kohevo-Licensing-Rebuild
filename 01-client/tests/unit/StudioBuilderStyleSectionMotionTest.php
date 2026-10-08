<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — B2-P3d section motion and the move/reveal presets.
 *
 * Autoloader only, no database.
 *
 * Reuses `SBP5S_TENANT` from StudioBuilderPhase5StylesTest (loaded first, alphabetically).
 *
 * A section may carry the same `animation` and `interactions` a block can; the renderer writes the same classes
 * and trigger attribute on the section element, and the stylesheet owns every keyframe.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
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
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Tenancy\TenantContext;

function sbsm_section(array $extra = []): array
{
    return $extra + [
        'id' => CanonicalDocumentSchema::newSectionId(), 'label' => 'S', 'global_ref' => null, 'layout' => [],
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks' => [[
            'id' => CanonicalDocumentSchema::newBlockId(), 'type' => 'core.heading', 'version' => 1,
            'props' => ['text' => 'x', 'level' => 'h2'], 'style' => CanonicalDocumentSchema::defaultBlockStyle(),
            'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
        ]],
    ];
}

function sbsm_doc(array $section): array
{
    return ['schema_version' => '1.0', 'document_type' => 'page', 'template_key' => 'default', 'settings' => [], 'seo' => [], 'sections' => [$section]];
}

function sbsm_render(array $section): string
{
    $renderer = new DocumentRenderer(
        BlockRegistry::withAllCoreBlocks(),
        BlockRendererRegistry::withCoreRenderers(),
        new CoreMediaResolver(new TenantContext()),
        new ProviderBindingResolver(new DataProviderRegistry(), new TenantContext())
    );
    return $renderer->renderSections(
        [$section],
        RenderContext::forPublic(SBP5S_TENANT, new SiteContext('https://example.test', 'Test Site')),
        (new ThemeResolver())->resolve('default'),
        new RenderCollector(),
        false
    );
}

unit('section motion: animation and interactions validate with the block rules, and refuse what blocks refuse', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $ok = DocumentValidator::validate(sbsm_doc(sbsm_section(['animation' => ['type' => 'reveal_up', 'duration_ms' => 600], 'interactions' => ['trigger' => 'viewport-enter']])), $registry);
    assert_true($ok->isValid(), 'a section with a reveal preset and a scroll trigger is valid');
    foreach ([
        'unknown preset' => ['animation' => ['type' => 'spin']],
        'animation not an object' => ['animation' => 'fade_up'],
        'unknown trigger' => ['interactions' => ['trigger' => 'dance']],
        'interactions a list' => ['interactions' => ['hover']],
    ] as $why => $extra) {
        assert_true(!DocumentValidator::validate(sbsm_doc(sbsm_section($extra)), $registry)->isValid(), "refused: {$why}");
    }
});

unit('section motion: the canonical form keeps motion only while it holds something', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $with = DocumentNormalizer::normalize(sbsm_doc(sbsm_section(['animation' => ['type' => 'fade_in'], 'interactions' => ['trigger' => 'hover']])), $registry);
    assert_eq(['type' => 'fade_in'], $with['sections'][0]['animation']);
    assert_eq(['trigger' => 'hover'], $with['sections'][0]['interactions']);
    $empty = DocumentNormalizer::normalize(sbsm_doc(sbsm_section(['animation' => [], 'interactions' => []])), $registry);
    assert_true(!array_key_exists('animation', $empty['sections'][0]) && !array_key_exists('interactions', $empty['sections'][0]), 'empty objects are dropped');
});

unit('section motion: the section element carries the classes, trigger attribute and timing; one without motion is unchanged', function (): void {
    $plain = sbsm_render($s = sbsm_section());
    $same = sbsm_render(['animation' => [], 'interactions' => []] + $s);
    assert_eq($plain, $same, 'empty motion renders exactly as no motion');

    $html = sbsm_render(['animation' => ['type' => 'move_left', 'duration_ms' => 600], 'interactions' => ['trigger' => 'viewport-enter']] + $s);
    assert_eq(1, preg_match('/<section class="[^"]*\bsb-animate-move-left\b[^"]*\bsb-interaction-viewport-enter\b[^"]*"[^>]* data-sb-interaction-trigger="viewport-enter"[^>]* style="--sb-anim-duration:600ms;;?"/', $html), 'section tag: ' . substr($html, 0, 400));
});

unit('move and reveal presets: the stylesheet has a rule, keyframes and a scroll variant for each, and reduced motion removes clipping', function (): void {
    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();
    foreach (['move-left', 'move-right', 'reveal-left', 'reveal-up'] as $preset) {
        assert_true(str_contains($css, ".sb-animate-{$preset}{animation:sb-{$preset} "), "{$preset} animates");
        assert_true(str_contains($css, "@keyframes sb-{$preset}{"), "{$preset} has keyframes");
        assert_true(str_contains($css, ".sb-interaction-viewport-enter.sb-animate-{$preset}{animation-name:sb-{$preset}}"), "{$preset} has a scroll variant");
    }
    assert_true(str_contains($css, 'clip-path:none!important'), 'reduced motion shows the whole element');
    foreach (['move_left', 'move_right', 'reveal_left', 'reveal_up'] as $type) {
        assert_true(in_array($type, CanonicalDocumentSchema::ALLOWED_ANIMATION_TYPES, true), "{$type} is a valid animation type");
    }
});
