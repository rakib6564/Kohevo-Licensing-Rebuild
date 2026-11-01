<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — section-based documents become Container-only ones.
 *
 * Autoloader only, no database. Every field of a section lands on the Container that replaces it, the content box and the
 * CSS match what the section drew, and the upgrade is stable: same ids each read, and nothing to do the second time.
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
}

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Document\DocumentUpgrader;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Document\StyleSurface;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Operation\DocumentOperationApplier;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;

function sbcu_block(string $suffix): array
{
    return [
        'id' => 'blk_' . str_pad($suffix, 16, '0'), 'type' => 'core.heading', 'version' => 1, 'props' => ['text' => 'Hi'],
        'style' => [], 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
    ];
}

function sbcu_section(array $over = []): array
{
    return $over + [
        'id' => 'sec_aaaaaaaaaaaaaaaa', 'label' => 'Hero', 'global_ref' => null, 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'layout' => ['background_token' => 'surface.primary', 'columns' => ['base' => 1, 'md' => 12], 'gap' => 'lg', 'padding_y' => ['base' => 'md', 'md' => 'lg'], 'width' => 'wide'],
        'blocks' => [sbcu_block('a'), sbcu_block('b'), sbcu_block('c')],
    ];
}

function sbcu_doc(array $sections): array
{
    return ['schema_version' => '1.0', 'document_type' => 'page', 'template_key' => 'default', 'settings' => [], 'seo' => [], 'sections' => $sections];
}

function sbcu_one(array $over = []): array
{
    return DocumentUpgrader::structure(sbcu_doc([sbcu_section($over)]))['containers'][0];
}

unit('a section-based document becomes the current format', function (): void {
    $doc = DocumentUpgrader::structure(sbcu_doc([sbcu_section()]));
    assert_eq('2.0', $doc['schema_version']);
    assert_true(!array_key_exists('sections', $doc), 'no sections key is left');
    $c = $doc['containers'][0];
    assert_eq('core.container', $c['type']);
    assert_eq('section', $c['tag'], 'the semantic element stays');
    assert_eq('blk_aaaaaaaaaaaaaaaa', $c['id'], 'the id is derived, so it holds across reads');
    assert_eq('Hero', $c['metadata']['label']);
    assert_eq(3, count($c['children']));
    assert_eq('lg', $c['props']['gap']);
    assert_eq('surface.primary', $c['style']['surface_token']);
});

unit('layout carries over: columns capped at the block count, per device', function (): void {
    $c = sbcu_one();
    assert_eq('grid', $c['style']['layout']['display']);
    assert_eq(3, $c['style']['layout']['columns'], 'twelve tracks for three blocks drew three');
    assert_eq(1, $c['responsive']['mobile']['style']['layout']['columns']);
    assert_true(!isset($c['responsive']['tablet']['style']['layout']['columns']), 'tablet matches desktop');
    assert_eq('lg', $c['style']['layout']['padding_y']);
    assert_eq('md', $c['responsive']['mobile']['style']['layout']['padding_y']);
    assert_eq('wide', $c['style']['layout']['content_size']);
    assert_eq('site', $c['style']['layout']['gutter']);
    assert_eq('boxed', $c['style']['layout']['content_width']);
});

unit('a custom width carries over per device', function (): void {
    $layout = sbcu_section()['layout'];
    $layout['width'] = 'custom';
    $layout['custom_width'] = ['base' => '90%', 'lg' => '960px'];
    $c = sbcu_one(['layout' => $layout]);
    assert_eq('960px', $c['style']['layout']['content_max']);
    assert_eq('90%', $c['responsive']['tablet']['style']['layout']['content_max']);
});

unit('style, motion, visibility and the lock carry over', function (): void {
    $c = sbcu_one([
        'locked' => true,
        'style' => ['background' => ['color' => '#112233'], 'padding' => ['left' => '40px', 'top' => '10px', 'bottom' => '20px'], 'border' => ['width' => '2px', 'color' => 'border.default']],
        'animation' => ['type' => 'fade_up'],
        'interactions' => ['trigger' => 'load'],
        'visibility' => ['auth_state' => 'guest', 'devices' => ['md', 'lg']],
    ]);
    assert_eq(true, $c['metadata']['locked']);
    assert_eq('#112233', $c['style']['background']['color']);
    assert_eq('40px', $c['style']['padding']['left']);
    assert_eq('solid', $c['style']['border']['style'], 'a width alone drew a solid line');
    assert_eq('border.default', $c['style']['border_token']);
    assert_eq('fade_up', $c['animation']['type']);
    assert_eq('load', $c['interactions']['trigger']);
    assert_eq('guest', $c['visibility']['auth_state']);
    assert_true(!isset($c['style']['layout']['padding_y']), 'a section\'s own padding beat its spacing');
});

unit('a global reference stays a reference and owns nothing', function (): void {
    $c = sbcu_one(['global_ref' => '11111111-1111-4111-8111-111111111111', 'blocks' => []]);
    assert_eq('11111111-1111-4111-8111-111111111111', $c['global_ref']);
    assert_eq([], $c['children']);
});

unit('the upgrade is stable and leaves a current document alone', function (): void {
    $once = DocumentUpgrader::structure(sbcu_doc([sbcu_section()]));
    assert_eq($once, DocumentUpgrader::structure($once));
    assert_eq($once, DocumentUpgrader::structure(sbcu_doc([sbcu_section()])), 'two reads give one document');
});

unit('an upgraded document validates, and normalizing takes either format', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $doc = DocumentNormalizer::validateAndNormalize(sbcu_doc([sbcu_section()]), $registry);
    assert_eq('2.0', $doc['schema_version']);
    assert_true(DocumentValidator::validate($doc, $registry)->isValid());
});

unit('component documents take the new type', function (): void {
    $doc = sbcu_doc([]);
    $doc['document_type'] = 'section_preset';
    assert_eq('container_preset', DocumentUpgrader::structure($doc)['document_type']);
});

unit('the content box is the section\'s: middle of the padded area, gutter inside', function (): void {
    $css = StyleSurface::declarations(['layout' => ['content_width' => 'boxed', 'gutter' => 'site', 'content_size' => 'wide'], 'padding' => ['left' => '40px']]);
    assert_true(str_contains($css, '--sb-box:var(--sb-layout-wide,80rem)'));
    assert_true(str_contains($css, 'padding-left:calc(40px + var(--sb-type-section_gutter,1rem) + max(0px,(100% - 40px - 0px - var(--sb-box))/2))'));
    $full = StyleSurface::declarations(['layout' => ['content_width' => 'boxed', 'gutter' => 'site', 'content_size' => 'full']]);
    assert_true(str_contains($full, '--sb-box:100%'));
});

unit('spacing follows the site\'s scale, per device, and padding wins', function (): void {
    $css = StyleSurface::declarations(['layout' => ['padding_y' => 'lg'], 'padding' => ['top' => '5px']]);
    assert_true(strpos($css, 'padding-top:calc(var(--sb-space-lg,3rem)*4/3)') < strpos($css, 'padding-top:5px'), 'padding comes later, so it wins');
    $rules = StyleSurface::responsiveRules(['mobile' => ['style' => ['layout' => ['padding_y' => 'md', 'content_max' => '90%']]]]);
    assert_true(str_contains(implode('', $rules), 'padding-top:calc(var(--sb-space-md,1.5rem)*4/3) !important'));
    assert_true(str_contains(implode('', $rules), '--sb-box:90% !important'));
});

unit('only a Container sits at the top level', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $doc = CanonicalDocumentSchema::emptyDocument();
    $doc['containers'] = [sbcu_block('x')];
    assert_true(in_array('top_level_not_container', array_column(DocumentValidator::validate($doc, $registry)->errors(), 'code'), true));

    $empty = CanonicalDocumentSchema::emptyDocument();
    assert_throws(StudioValidationException::class, static fn () => DocumentOperationApplier::apply($empty, [new DocumentOperation('insert_block', ['parent_id' => null, 'index' => 0, 'block' => ['type' => 'core.heading']])], $registry));
    $done = DocumentOperationApplier::apply($empty, [new DocumentOperation('insert_block', ['parent_id' => null, 'index' => 0, 'block' => ['type' => 'core.container']])], $registry);
    assert_eq('core.container', $done['containers'][0]['type']);
});

unit('section operations are gone and say what replaced them', function (): void {
    try {
        new DocumentOperation('insert_section', ['index' => 0]);
        assert_true(false, 'refused');
    } catch (StudioValidationException $e) {
        assert_true(str_contains((string) json_encode($e->errors()), 'insert_block'));
    }
});

unit('a Container moves to the top level, and a nested block cannot hold a reference', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $inner = CanonicalDocumentSchema::newContainer(['id' => 'blk_bbbbbbbbbbbbbbbb']);
    $doc = CanonicalDocumentSchema::emptyDocument();
    $doc['containers'] = [CanonicalDocumentSchema::newContainer(['id' => 'blk_aaaaaaaaaaaaaaaa', 'children' => [$inner]])];
    $moved = DocumentOperationApplier::apply($doc, [new DocumentOperation('move_block', ['block_id' => 'blk_bbbbbbbbbbbbbbbb', 'parent_id' => null, 'index' => 0])], $registry);
    assert_eq('blk_bbbbbbbbbbbbbbbb', $moved['containers'][0]['id']);

    $inner['global_ref'] = '11111111-1111-4111-8111-111111111111';
    $doc['containers'][0]['children'] = [$inner];
    assert_true(in_array('global_ref_not_allowed', array_column(DocumentValidator::validate($doc, $registry)->errors(), 'code'), true));
});
