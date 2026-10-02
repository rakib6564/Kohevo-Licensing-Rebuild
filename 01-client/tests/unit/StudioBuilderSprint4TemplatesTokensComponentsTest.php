<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Sprint 4
 * Reusable Sections, Global Components, Templates, Global Tokens & Global Styles.
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
    $studioS4UnitStandalone = true;
}

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentCopier;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Service\StudioGlobalComponentService;
use Slate\Module\StudioBuilder\Service\StudioTemplateService;
use Slate\Module\StudioBuilder\Service\StudioThemeService;

unit('sprint4 unit: StudioTemplateService extracts section preset with preserved subtree', function (): void {
    $secId = CanonicalDocumentSchema::newSectionId();
    $btnId = CanonicalDocumentSchema::newBlockId();
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Page');
    $doc['sections'] = [
        [
            'id' => $secId,
            'label' => 'Reusable Call To Action',
            'layout' => CanonicalDocumentSchema::defaultSectionLayout(),
            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            'blocks' => [
                [
                    'id' => $btnId,
                    'type' => 'core.button',
                    'version' => 1,
                    'props' => ['variant' => 'primary', 'link' => ['href' => '/contact', 'label' => 'Contact Us', 'target' => '_self']],
                    'style' => CanonicalDocumentSchema::defaultBlockStyle(),
                    'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                    'bindings' => [],
                    'children' => [],
                ],
            ],
        ],
    ];

    $extracted = StudioTemplateService::extractTemplateDocument($doc, $secId, 'section_preset');
    assert_eq(CanonicalDocumentSchema::SCHEMA_VERSION, $extracted['schema_version']);
    assert_eq('section_preset', $extracted['document_type']);
    assert_eq(1, count($extracted['sections']), 'extracted 1 section');
    assert_eq('Reusable Call To Action', $extracted['sections'][0]['label']);
    assert_eq('core.button', $extracted['sections'][0]['blocks'][0]['type']);
    assert_eq('Contact Us', $extracted['sections'][0]['blocks'][0]['props']['link']['label']);
});

unit('sprint4 unit: StudioGlobalComponentService reference and detach operations mint fresh IDs', function (): void {
    $sec = [
        'id' => 'sec_source_1',
        'label' => 'Global Header Section',
        'layout' => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks' => [],
    ];
    $componentUuid = '01923456-789a-7bcd-ef01-23456789abcd';

    // 1. Reference section creation
    $refSec = StudioGlobalComponentService::referenceSectionFor($sec, $componentUuid);
    assert_eq($componentUuid, $refSec['global_ref'], 'holds global_ref uuid');
    assert_eq([], $refSec['blocks'], 'global reference owns no local blocks');
    assert_eq('Global Header Section', $refSec['label']);

    // 2. DocumentCopier deep subtree copy mints fresh block IDs
    $originalBlocks = [
        [
            'id' => 'blk_orig_container',
            'type' => 'layout.container',
            'version' => 1,
            'props' => ['width' => 'constrained'],
            'style' => CanonicalDocumentSchema::defaultBlockStyle(),
            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            'bindings' => [],
            'children' => [
                [
                    'id' => 'blk_orig_heading',
                    'type' => 'core.heading',
                    'version' => 1,
                    'props' => ['text' => 'Brand Header'],
                    'style' => CanonicalDocumentSchema::defaultBlockStyle(),
                    'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                    'bindings' => [],
                    'children' => [],
                ],
            ],
        ],
    ];

    $copiedBlocks = DocumentCopier::copyBlocks($originalBlocks);
    assert_eq(1, count($copiedBlocks));
    assert_true($copiedBlocks[0]['id'] !== 'blk_orig_container', 'container ID re-minted');
    assert_true(str_starts_with($copiedBlocks[0]['id'], 'blk_'));
    assert_true($copiedBlocks[0]['children'][0]['id'] !== 'blk_orig_heading', 'child heading ID re-minted');
    assert_true(str_starts_with($copiedBlocks[0]['children'][0]['id'], 'blk_'));
    assert_eq('Brand Header', $copiedBlocks[0]['children'][0]['props']['text'], 'properties preserved');
});

unit('sprint4 unit: ThemeResolver layers and sanitizes values; rejects injection attempts', function (): void {
    // 1. Sanitization of valid tokens
    assert_eq('#4f46e5', ThemeResolver::sanitizeValue('color.accent', '#4f46e5'));
    assert_eq('rgb(79, 70, 229)', ThemeResolver::sanitizeValue('surface.primary', 'rgb(79, 70, 229)'));
    assert_eq('12px', ThemeResolver::sanitizeValue('radius.md', '12px'));
    assert_eq('0 4px 12px rgba(0,0,0,0.08)', ThemeResolver::sanitizeValue('shadow.md', '0 4px 12px rgba(0,0,0,0.08)'));
    assert_eq('Inter, sans-serif', ThemeResolver::sanitizeValue('font.body', 'Inter, sans-serif'));

    // 2. Rejection of hostile / injection patterns
    assert_null(ThemeResolver::sanitizeValue('color.accent', 'red; } </style><script>alert(1)</script>'));
    assert_null(ThemeResolver::sanitizeValue('color.accent', 'url(https://malicious.test/track)'));
    assert_null(ThemeResolver::sanitizeValue('font.body', 'expression(alert(1))'));
    assert_null(ThemeResolver::sanitizeValue('radius.md', '12px; display:none'));

    // 3. Layer resolution
    $theme = new ResolvedTheme('default', [
        'color.accent' => '#6366f1',
        'radius.md' => '10px',
        'surface.page' => '#f8fafc',
    ]);
    assert_eq('#6366f1', $theme->value('color.accent'));
    assert_eq('10px', $theme->value('radius.md'));

    // 4. Root CSS generation
    $css = $theme->rootCss();
    assert_true(str_starts_with($css, ':root{'));
    assert_true(str_contains($css, '--sb-color-accent:#6366f1'));
    assert_true(str_contains($css, '--sb-radius-md:10px'));
    assert_true(str_contains($css, '--sb-surface-page:#f8fafc'));
});

unit('sprint4 unit: StudioStylesheet contains global body, typography, button, and responsive rules', function (): void {
    $css = StudioStylesheet::css();
    assert_true(str_contains($css, 'body.sb-body'));
    assert_true(str_contains($css, 'var(--sb-surface-page)'));
    assert_true(str_contains($css, 'var(--sb-font-body)'));
    assert_true(str_contains($css, 'var(--sb-font-heading)'));
    assert_true(str_contains($css, '.sb-button--primary'));
    assert_true(str_contains($css, '.sb-button--secondary'));
    assert_true(str_contains($css, '.sb-button--outline'));
    assert_true(str_contains($css, '.sb-button--ghost'));
    assert_true(str_contains($css, '.sb-layout-section'));
    assert_true(str_contains($css, '.sb-container'));
    assert_true(str_contains($css, '.sb-flex'));
    assert_true(str_contains($css, '.sb-grid'));
});
