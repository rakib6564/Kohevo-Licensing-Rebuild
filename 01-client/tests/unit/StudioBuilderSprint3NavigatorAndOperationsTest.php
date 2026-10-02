<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Sprint 3 Operations & Navigator.
 *
 * Verifies Sprint 3 Specifications (Section 59 & Section 37):
 *   - Duplicate block operation creates deep clone with fresh IDs for all nodes in subtree.
 *   - Duplicate section operation creates deep clone with fresh section ID and cloned blocks.
 *   - Section rename operation updates section label with safety checks.
 *   - Payload validation enforces required keys and safe strings.
 *   - Document remains valid canonical schema after duplicate mutations.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!function_exists('unit')) {
    require_once dirname(__DIR__, 2) . '/config.php';
    require_once dirname(__DIR__) . '/guard.php';
    require_once dirname(__DIR__) . '/unit/harness.php';
}

require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Operation\DocumentOperationApplier;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;

unit('sprint3 unit: DocumentOperation supports duplicate_block, duplicate_section, and update_section_label', function (): void {
    $opDupB = DocumentOperation::duplicateBlock('blk_1111111111111111');
    assert_eq(DocumentOperation::OP_DUPLICATE_BLOCK, $opDupB->op);
    assert_eq(['block_id' => 'blk_1111111111111111'], $opDupB->payload);

    $opDupS = DocumentOperation::duplicateSection('sec_2222222222222222');
    assert_eq(DocumentOperation::OP_DUPLICATE_SECTION, $opDupS->op);
    assert_eq(['section_id' => 'sec_2222222222222222'], $opDupS->payload);

    $opLabel = DocumentOperation::updateSectionLabel('sec_2222222222222222', 'New Hero Label');
    assert_eq(DocumentOperation::OP_UPDATE_SECTION_LABEL, $opLabel->op);
    assert_eq(['section_id' => 'sec_2222222222222222', 'label' => 'New Hero Label'], $opLabel->payload);

    // Payload validation: missing keys fail closed
    assert_throws(StudioValidationException::class, static function (): void {
        new DocumentOperation(DocumentOperation::OP_DUPLICATE_BLOCK, []);
    }, 'duplicate_block requires block_id');

    assert_throws(StudioValidationException::class, static function (): void {
        new DocumentOperation(DocumentOperation::OP_DUPLICATE_SECTION, []);
    }, 'duplicate_section requires section_id');

    assert_throws(StudioValidationException::class, static function (): void {
        new DocumentOperation(DocumentOperation::OP_UPDATE_SECTION_LABEL, ['section_id' => 'sec_123']);
    }, 'update_section_label requires label');
});

unit('sprint3 unit: DocumentOperationApplier duplicateBlock duplicates deep subtree with fresh IDs', function (): void {
    $reg = BlockRegistry::withAllCoreBlocks();
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default');

    $opSec = new DocumentOperation('insert_section', ['index' => 0, 'section' => ['label' => 'Main']]);
    $doc = DocumentOperationApplier::applyOne($doc, $opSec, $reg);
    $secId = $doc['sections'][0]['id'];

    $innerButton = [
        'id' => CanonicalDocumentSchema::newBlockId(),
        'type' => 'core.button',
        'version' => 1,
        'props' => ['variant' => 'primary', 'size' => 'md', 'link' => ['href' => '/learn', 'label' => 'Learn More', 'target' => '_self'], 'full_width' => false],
        'style' => [],
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [],
        'children' => [],
    ];

    $heading = [
        'id' => CanonicalDocumentSchema::newBlockId(),
        'type' => 'core.heading',
        'version' => 1,
        'props' => ['text' => 'Nested Hero', 'level' => 'h2'],
        'style' => [],
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [],
        'children' => [],
    ];

    $container = [
        'id' => CanonicalDocumentSchema::newBlockId(),
        'type' => 'layout.container',
        'version' => 1,
        'props' => ['width' => 'constrained', 'alignment' => 'center', 'padding' => 'md'],
        'style' => [],
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [],
        'children' => [$heading, $innerButton],
    ];

    $opInsert = new DocumentOperation('insert_block', ['parent_id' => $secId, 'index' => 0, 'block' => $container]);
    $doc = DocumentOperationApplier::applyOne($doc, $opInsert, $reg);

    $origContainerId = $doc['sections'][0]['blocks'][0]['id'];
    $origHeadingId = $doc['sections'][0]['blocks'][0]['children'][0]['id'];
    $origButtonId = $doc['sections'][0]['blocks'][0]['children'][1]['id'];

    // Duplicate the entire container with its children
    $opDup = DocumentOperation::duplicateBlock($origContainerId);
    $docAfterDup = DocumentOperationApplier::applyOne($doc, $opDup, $reg);

    $blocks = $docAfterDup['sections'][0]['blocks'];
    assert_eq(2, count($blocks), 'container duplicated in section');

    $firstContainer = $blocks[0];
    $clonedContainer = $blocks[1];

    assert_eq($origContainerId, $firstContainer['id']);
    assert_true($clonedContainer['id'] !== $origContainerId, 'cloned container receives fresh ID');
    assert_true(str_starts_with($clonedContainer['id'], 'blk_'), 'cloned container ID has canonical prefix');

    // Verify children have fresh IDs and retained props
    assert_eq(2, count($clonedContainer['children']));
    $clonedHeading = $clonedContainer['children'][0];
    $clonedButton = $clonedContainer['children'][1];

    assert_true($clonedHeading['id'] !== $origHeadingId, 'cloned heading receives fresh ID');
    assert_eq('Nested Hero', $clonedHeading['props']['text']);
    assert_true($clonedButton['id'] !== $origButtonId, 'cloned button receives fresh ID');
    assert_eq('Learn More', $clonedButton['props']['link']['label']);

    // Document remains 100% schema valid
    $res = DocumentValidator::validate($docAfterDup, $reg);
    assert_true($res->isValid(), 'document is schema valid after duplicate_block');
});

unit('sprint3 unit: DocumentOperationApplier duplicateSection duplicates section and contents', function (): void {
    $reg = BlockRegistry::withAllCoreBlocks();
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default');

    $opSec = new DocumentOperation('insert_section', ['index' => 0, 'section' => ['label' => 'Features']]);
    $doc = DocumentOperationApplier::applyOne($doc, $opSec, $reg);
    $secId = $doc['sections'][0]['id'];

    $btn = [
        'id' => CanonicalDocumentSchema::newBlockId(),
        'type' => 'core.button',
        'version' => 1,
        'props' => ['variant' => 'secondary', 'size' => 'lg', 'link' => ['href' => '/features', 'label' => 'Features', 'target' => '_self'], 'full_width' => false],
        'style' => [],
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [],
        'children' => [],
    ];
    $opInsert = new DocumentOperation('insert_block', ['parent_id' => $secId, 'index' => 0, 'block' => $btn]);
    $doc = DocumentOperationApplier::applyOne($doc, $opInsert, $reg);

    $opDupSec = DocumentOperation::duplicateSection($secId);
    $docAfterDup = DocumentOperationApplier::applyOne($doc, $opDupSec, $reg);

    assert_eq(2, count($docAfterDup['sections']), 'section duplicated');
    $sec1 = $docAfterDup['sections'][0];
    $sec2 = $docAfterDup['sections'][1];

    assert_eq($secId, $sec1['id']);
    assert_true($sec2['id'] !== $secId, 'cloned section receives fresh ID');
    assert_true(str_starts_with($sec2['id'], 'sec_'), 'cloned section ID has canonical prefix');
    assert_eq('Features (Copy)', $sec2['label']);
    assert_eq(1, count($sec2['blocks']));
    assert_true($sec2['blocks'][0]['id'] !== $sec1['blocks'][0]['id'], 'cloned section block receives fresh ID');
    assert_eq('Features', $sec2['blocks'][0]['props']['link']['label']);

    // Document remains 100% schema valid
    $res = DocumentValidator::validate($docAfterDup, $reg);
    assert_true($res->isValid(), 'document is schema valid after duplicate_section');
});

unit('sprint3 unit: DocumentOperationApplier updateSectionLabel renames section safely and rejects injection', function (): void {
    $reg = BlockRegistry::withAllCoreBlocks();
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default');

    $opSec = new DocumentOperation('insert_section', ['index' => 0, 'section' => ['label' => 'Original Label']]);
    $doc = DocumentOperationApplier::applyOne($doc, $opSec, $reg);
    $secId = $doc['sections'][0]['id'];

    $opRename = DocumentOperation::updateSectionLabel($secId, 'Our Client Testimonials');
    $doc = DocumentOperationApplier::applyOne($doc, $opRename, $reg);
    assert_eq('Our Client Testimonials', $doc['sections'][0]['label']);

    // Hostile / unsafe input rejected
    assert_throws(StudioValidationException::class, static function () use ($doc, $secId, $reg): void {
        $hostile = DocumentOperation::updateSectionLabel($secId, '<script>alert(1)</script>');
        DocumentOperationApplier::applyOne($doc, $hostile, $reg);
    }, 'rejects script in label');

    assert_throws(StudioValidationException::class, static function () use ($doc, $secId, $reg): void {
        $hostile = DocumentOperation::updateSectionLabel($secId, 'Title UNION SELECT 1,2,3');
        DocumentOperationApplier::applyOne($doc, $hostile, $reg);
    }, 'rejects sql in label');
});
