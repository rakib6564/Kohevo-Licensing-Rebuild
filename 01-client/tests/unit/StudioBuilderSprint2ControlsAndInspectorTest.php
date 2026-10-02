<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Sprint 2 Control System & Visual Styling.
 *
 * Exercises:
 *  1. ControlSchema tabs, sections, and standard presets.
 *  2. DocumentValidator visual styles validation (typography, border, shadow, dimensions, CSS injection prevention).
 *  3. DocumentOperation & Applier for responsive overrides, classNames, and attributes.
 *  4. DocumentRenderer compilation of utility classes, inline styles, and responsive classes.
 *  5. StudioStylesheet typography, radius, and shadow utility rules.
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
    $studioS2UnitStandalone = true;
}

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Operation\DocumentOperationApplier;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Registry\WidgetRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\RenderMode;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Module\StudioBuilder\Schema\ControlSchema;
use Slate\Tenancy\TenantContext;

// ── 1. ControlSchema ──────────────────────────────────────────────────────────

unit('sprint2 unit: ControlSchema tabs, sections, and standard presets', function (): void {
    $cs = ControlSchema::create();
    $cs->addTab(ControlSchema::TAB_CONTENT, 'Content', [
        ['id' => 'sec_1', 'label' => 'Main', 'controls' => [['key' => 'title', 'type' => 'text']]],
    ]);
    $arr = $cs->toArray();
    assert_true(isset($arr['content']));
    assert_eq('Content', $arr['content']['label']);
    assert_eq(1, count($arr['content']['sections']));

    $standard = ControlSchema::standard(
        contentControls: [['key' => 'label', 'type' => 'text']],
        styleControls: ControlSchema::typographyControls(),
        advancedControls: ControlSchema::advancedStandardControls(),
        responsiveControls: ControlSchema::responsiveOverridesControls(),
    );
    $stdArr = $standard->toArray();
    assert_true(isset($stdArr[ControlSchema::TAB_CONTENT]));
    assert_true(isset($stdArr[ControlSchema::TAB_STYLE]));
    assert_true(isset($stdArr[ControlSchema::TAB_ADVANCED]));
    assert_true(isset($stdArr[ControlSchema::TAB_RESPONSIVE]));

    // Check helper arrays
    $typo = ControlSchema::typographyControls();
    assert_true(count($typo) >= 5);
    $border = ControlSchema::borderControls();
    assert_true(count($border) >= 4);
    $shadow = ControlSchema::shadowControls();
    assert_true(count($shadow) >= 1);
    $adv = ControlSchema::advancedStandardControls();
    assert_true(count($adv) >= 3);
    $resp = ControlSchema::responsiveOverridesControls();
    assert_true(count($resp) >= 6);
});

// ── 2. DocumentValidator Visual Styles ────────────────────────────────────────

unit('sprint2 unit: DocumentValidator accepts valid visual styles and rejects malicious/invalid styles', function (): void {
    $blocks = BlockRegistry::withAllCoreBlocks();

    $baseDoc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Test Page');
    $baseDoc['sections'] = [
        [
            'id' => CanonicalDocumentSchema::newSectionId(),
            'label' => 'Hero',
            'global_ref' => null,
            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            'layout' => ['width' => 'wide', 'padding_y' => ['base' => 'md']],
            'blocks' => [
                [
                    'id' => CanonicalDocumentSchema::newBlockId(),
                    'type' => 'core.heading',
                    'version' => 1,
                    'props' => ['text' => 'Hello World', 'level' => 'h1'],
                    'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                    'bindings' => [],
                    'children' => [],
                    'style' => [
                        'typography' => [
                            'size' => ['desktop' => '64px', 'tablet' => '48px', 'mobile' => '36px'],
                            'weight' => 'bold',
                            'transform' => 'capitalize',
                            'line_height' => '1.2',
                            'color' => '#1e293b',
                        ],
                        'border' => [
                            'radius' => 'lg',
                            'style' => 'solid',
                            'width' => '2px',
                            'color' => '#e2e8f0',
                        ],
                        'shadow' => 'xl',
                        'z_index' => 10,
                        'opacity' => 0.95,
                    ],
                    'classNames' => ['hero-title', 'font-feature-settings'],
                    'attributes' => ['data-analytics' => 'hero-h1', 'aria-level' => 1],
                    'responsive' => [
                        'mobile' => ['align' => 'center'],
                        'desktop' => ['align' => 'left'],
                    ],
                ],
            ],
        ],
    ];

    $res = DocumentValidator::validate($baseDoc, $blocks);
    assert_true($res->isValid(), 'Valid rich visual styles should pass validation');

    // Test CSS Injection Prevention: semicolon / curly brace / javascript
    $maliciousStyles = [
        ['typography' => ['color' => 'red; background: url(evil.com)']],
        ['typography' => ['line_height' => '1.5; color: red']],
        ['border' => ['color' => '#fff} body{display:none}']],
        ['background' => ['color' => 'javascript:alert(1)']],
        ['background' => ['gradient' => 'expression(alert(1))']],
        ['typography' => ['weight' => 'ultra-bold-invalid']],
        ['border' => ['style' => 'glowing-rainbow']],
        ['shadow' => 'gigantic-shadow-invalid'],
        ['opacity' => 1.5], // max is 1.0
        ['opacity' => -0.5], // min is 0.0
        ['z_index' => 'not-a-number'],
    ];

    foreach ($maliciousStyles as $idx => $badStyle) {
        $badDoc = $baseDoc;
        $badDoc['sections'][0]['blocks'][0]['style'] = $badStyle;
        $badRes = DocumentValidator::validate($badDoc, $blocks);
        assert_false($badRes->isValid(), "Malicious/invalid style #{$idx} should be rejected");
    }
});

// ── 3. DocumentOperation & Applier ────────────────────────────────────────────

unit('sprint2 unit: DocumentOperation & Applier handle responsive overrides, classNames, and attributes', function (): void {
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Page');
    $doc['sections'] = [
        [
            'id' => CanonicalDocumentSchema::newSectionId(),
            'label' => 'Section',
            'global_ref' => null,
            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            'layout' => CanonicalDocumentSchema::defaultSectionLayout(),
            'blocks' => [
                [
                    'id' => 'blk_1',
                    'type' => 'core.heading',
                    'version' => 1,
                    'props' => ['text' => 'Title'],
                    'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                    'bindings' => [],
                    'children' => [],
                    'style' => [],
                ],
            ],
        ],
    ];

    $applier = new DocumentOperationApplier();

    $blocks = BlockRegistry::withAllCoreBlocks();
    // 1. update_block_responsive
    $opResp = DocumentOperation::updateBlockResponsive('blk_1', [
        'desktop' => ['align' => 'left'],
        'mobile' => ['align' => 'center', 'hide' => true],
    ]);
    $doc1 = $applier->apply($doc, [$opResp], $blocks);
    $b1 = $doc1['sections'][0]['blocks'][0];
    assert_true(isset($b1['responsive']['mobile']['hide']));
    assert_eq('center', $b1['responsive']['mobile']['align']);

    // 2. update_block_class_names
    $opClass = DocumentOperation::updateBlockClassNames('blk_1', ['custom-cta', 'glow-effect']);
    $doc2 = $applier->apply($doc1, [$opClass], $blocks);
    $b2 = $doc2['sections'][0]['blocks'][0];
    assert_eq(['custom-cta', 'glow-effect'], $b2['classNames']);

    // 3. update_block_attributes
    $opAttr = DocumentOperation::updateBlockAttributes('blk_1', ['data-id' => 'hero-123', 'role' => 'banner']);
    $doc3 = $applier->apply($doc2, [$opAttr], $blocks);
    $b3 = $doc3['sections'][0]['blocks'][0];
    assert_eq('hero-123', $b3['attributes']['data-id']);
    assert_eq('banner', $b3['attributes']['role']);
});

// ── 4. DocumentRenderer Visual Styles & Utilities ─────────────────────────────

unit('sprint2 unit: DocumentRenderer compiles utility classes, inline styles, and responsive classes', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $renderers = BlockRendererRegistry::withCoreRenderers();
    $tenants = new TenantContext();
    $bindings = new ProviderBindingResolver(new DataProviderRegistry(), $tenants);
    $docRenderer = new DocumentRenderer($registry, $renderers, new CoreMediaResolver($tenants), $bindings);

    $context = RenderContext::forPublic(101, new SiteContext('https://example.test', 'Test Site'));
    $theme = new ResolvedTheme('default', []);
    $collector = new RenderCollector();

    $block = [
        'id' => CanonicalDocumentSchema::newBlockId(),
        'type' => 'core.heading',
        'version' => 1,
        'props' => ['text' => 'Styled Heading', 'level' => 'h2'],
        'style' => [
            'typography' => [
                'weight' => 'bold',
                'transform' => 'uppercase',
                'line_height' => '1.4',
                'color' => '#0284c7',
            ],
            'border' => [
                'radius' => 'full',
            ],
            'shadow' => '2xl',
            'z_index' => 5,
        ],
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [],
        'children' => [],
        'classNames' => ['extra-class-1'],
        'attributes' => ['data-track' => 'styled-head'],
        'responsive' => [
            'mobile' => ['hide' => true],
            'tablet' => ['align' => 'center'],
        ],
    ];

    $html = $docRenderer->renderBlock($block, $context, $theme, $collector, false);

    // Verify preset utility classes compiled on the block wrapper
    assert_true(str_contains($html, 'sb-font-bold'), 'Heading should have .sb-font-bold');
    assert_true(str_contains($html, 'sb-uppercase'), 'Heading should have .sb-uppercase');
    assert_true(str_contains($html, 'sb-radius-full'), 'Heading should have .sb-radius-full');
    assert_true(str_contains($html, 'sb-shadow-2xl'), 'Heading should have .sb-shadow-2xl');

    // Verify sanitized inline styles
    assert_true(str_contains($html, 'color:#0284c7'), 'Heading should have inline color');
    assert_true(str_contains($html, 'line-height:1.4'), 'Heading should have inline line-height');
    assert_true(str_contains($html, 'z-index:5'), 'Heading should have inline z-index');

    // Verify custom class and custom attribute
    assert_true(str_contains($html, 'extra-class-1'), 'Heading should include custom class');
    assert_true(str_contains($html, 'data-track="styled-head"'), 'Heading should include custom attribute');

    // Verify responsive device classes
    assert_true(str_contains($html, 'sb-hide-mobile'), 'Heading should have .sb-hide-mobile');
    assert_true(str_contains($html, 'sb-align-tablet-center'), 'Heading should have .sb-align-tablet-center');
});

// ── 5. StudioStylesheet Utility Rules ─────────────────────────────────────────

unit('sprint2 unit: StudioStylesheet contains typography, radius, and shadow utility rules', function (): void {
    $css = StudioStylesheet::css();

    // Typography
    assert_true(str_contains($css, '.sb-font-normal{font-weight:400}'));
    assert_true(str_contains($css, '.sb-font-bold{font-weight:700}'));
    assert_true(str_contains($css, '.sb-uppercase{text-transform:uppercase}'));

    // Radius
    assert_true(str_contains($css, '.sb-radius-full{border-radius:9999px}'));
    assert_true(str_contains($css, '.sb-radius-lg{border-radius:1rem}'));

    // Shadows
    assert_true(str_contains($css, '.sb-shadow-none{box-shadow:none}'));
    assert_true(str_contains($css, '.sb-shadow-lg{'));
    assert_true(str_contains($css, '.sb-shadow-2xl{'));

    // Responsive device alignment & hidden classes
    assert_true(str_contains($css, '.sb-hide-mobile'));
    assert_true(str_contains($css, '.sb-align-desktop-center'));
});
