<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — layer lock and block rename.
 *
 * Autoloader only, no database. Pins: the canonical shape (section `locked`,
 * block `metadata.{label,locked}`) validates and normalizes to a minimal form;
 * the two new operations; and the lock rules the operation applier enforces for
 * every caller (builder UI, AI/MCP): locked content cannot be edited, moved,
 * removed or inserted into, a parent holding a locked child cannot be removed,
 * unlocking works only from the outermost lock, and duplicates keep their flags.
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
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Document\LayerLock;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Mcp\StudioMcpAdapter;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Operation\DocumentOperationApplier;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;

/** A page with one section holding a container that holds a heading, plus a sibling heading. */
function sbll_doc(): array
{
    $reg = BlockRegistry::withAllCoreBlocks();
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default');
    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation('insert_section', ['index' => 0, 'section' => ['label' => 'Main']]), $reg);
    $sec = $doc['sections'][0]['id'];

    $heading = static fn (string $text): array => [
        'id' => CanonicalDocumentSchema::newBlockId(), 'type' => 'core.heading', 'version' => 1,
        'props' => ['text' => $text, 'level' => 'h2'], 'style' => [],
        'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
    ];
    $container = [
        'id' => CanonicalDocumentSchema::newBlockId(), 'type' => 'layout.container', 'version' => 1,
        'props' => ['width' => 'constrained', 'alignment' => 'center', 'padding' => 'md'], 'style' => [],
        'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [],
        'children' => [$heading('Inner')],
    ];
    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation('insert_block', ['parent_id' => $sec, 'index' => 0, 'block' => $container]), $reg);
    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation('insert_block', ['parent_id' => $sec, 'index' => 1, 'block' => $heading('Sibling')]), $reg);
    return $doc;
}

function sbll_apply(array $doc, DocumentOperation $op): array
{
    return DocumentOperationApplier::applyOne($doc, $op, BlockRegistry::withAllCoreBlocks());
}

function sbll_refused(array $doc, DocumentOperation $op, string $why): void
{
    try {
        sbll_apply($doc, $op);
    } catch (StudioValidationException $e) {
        $codes = array_column($e->errors(), 'code');
        assert_true(in_array('node_locked', $codes, true), "$why: expected node_locked, got " . implode(',', $codes));
        return;
    }
    throw new \Exception("$why: the lock should have refused this operation");
}

unit('layer lock: new operations are part of the vocabulary and validate their payload', function (): void {
    assert_true(in_array(DocumentOperation::OP_UPDATE_BLOCK_META, DocumentOperation::ALLOWED_OPS, true));
    assert_true(in_array(DocumentOperation::OP_UPDATE_SECTION_LOCKED, DocumentOperation::ALLOWED_OPS, true));
    assert_eq(['section_id' => 'sec_a', 'locked' => true], DocumentOperation::updateSectionLocked('sec_a', true)->payload);
    assert_eq(['block_id' => 'blk_a', 'label' => 'Hero'], DocumentOperation::updateBlockMeta('blk_a', ['label' => 'Hero'])->payload);
    assert_throws(StudioValidationException::class, static function (): void {
        new DocumentOperation(DocumentOperation::OP_UPDATE_SECTION_LOCKED, ['section_id' => 'sec_a']);
    }, 'update_section_locked requires locked');
});

unit('layer lock: rename and lock round-trip through validate + normalize in a minimal canonical form', function (): void {
    $reg = BlockRegistry::withAllCoreBlocks();
    $doc = sbll_doc();
    $container = $doc['sections'][0]['blocks'][0]['id'];

    $doc = sbll_apply($doc, DocumentOperation::updateBlockMeta($container, ['label' => '  Hero wrapper  ', 'locked' => true]));
    $doc = sbll_apply($doc, DocumentOperation::updateSectionLocked($doc['sections'][0]['id'], true));
    $norm = DocumentNormalizer::validateAndNormalize($doc, $reg);

    assert_eq(['label' => 'Hero wrapper', 'locked' => true], $norm['sections'][0]['blocks'][0]['metadata']);
    assert_true($norm['sections'][0]['locked'] === true, 'locked section survives normalization');
    assert_true(!array_key_exists('metadata', $norm['sections'][0]['blocks'][1]), 'untouched blocks stay metadata-free');

    // Unlocking and clearing the label returns to the plain canonical shape.
    $doc = sbll_apply($doc, DocumentOperation::updateSectionLocked($doc['sections'][0]['id'], false));
    $doc = sbll_apply($doc, DocumentOperation::updateBlockMeta($container, ['locked' => false]));
    $doc = sbll_apply($doc, DocumentOperation::updateBlockMeta($container, ['label' => null]));
    $norm = DocumentNormalizer::validateAndNormalize($doc, $reg);
    assert_true(!array_key_exists('metadata', $norm['sections'][0]['blocks'][0]), 'empty metadata is removed');
    assert_true(!array_key_exists('locked', $norm['sections'][0]), 'unlocked section has no lock key');
});

unit('layer lock: bad labels, unknown metadata keys and non-boolean flags are rejected', function (): void {
    $reg = BlockRegistry::withAllCoreBlocks();
    $doc = sbll_doc();
    $id  = $doc['sections'][0]['blocks'][1]['id'];

    foreach ([
        ['label' => str_repeat('x', 81)],
        ['label' => "bad\x07label"],
        ['label' => 123],
        ['locked' => 'yes'],
        ['colour' => 'red'],
    ] as $bad) {
        assert_throws(StudioValidationException::class, static function () use ($doc, $id, $bad): void {
            sbll_apply($doc, DocumentOperation::updateBlockMeta($id, $bad));
        }, 'applier must refuse ' . json_encode($bad));
    }

    // The validator guards documents that did not come through the applier (imports, API).
    $tampered = $doc;
    $tampered['sections'][0]['blocks'][1]['metadata'] = ['label' => 'ok', 'extra' => true];
    assert_throws(StudioValidationException::class, static function () use ($tampered, $reg): void {
        DocumentNormalizer::validateAndNormalize($tampered, $reg);
    }, 'unknown metadata key');
    $tampered = $doc;
    $tampered['sections'][0]['locked'] = 'true';
    assert_throws(StudioValidationException::class, static function () use ($tampered, $reg): void {
        DocumentNormalizer::validateAndNormalize($tampered, $reg);
    }, 'section.locked must be boolean');
});

unit('layer lock: a locked block cannot be edited, restyled, renamed, moved or removed', function (): void {
    $doc = sbll_doc();
    $sec = $doc['sections'][0]['id'];
    $sibling = $doc['sections'][0]['blocks'][1]['id'];
    $doc = sbll_apply($doc, DocumentOperation::updateBlockMeta($sibling, ['locked' => true]));

    sbll_refused($doc, new DocumentOperation('update_block_props', ['block_id' => $sibling, 'props' => ['text' => 'x', 'level' => 'h2']]), 'props');
    sbll_refused($doc, new DocumentOperation('update_block_style', ['block_id' => $sibling, 'style' => []]), 'style');
    sbll_refused($doc, DocumentOperation::updateBlockMeta($sibling, ['label' => 'New name']), 'rename');
    sbll_refused($doc, new DocumentOperation('move_block', ['block_id' => $sibling, 'parent_id' => $sec, 'index' => 0]), 'move');
    sbll_refused($doc, new DocumentOperation('remove_block', ['block_id' => $sibling]), 'remove');

    // Other blocks in the same section stay editable.
    $other = $doc['sections'][0]['blocks'][0]['id'];
    $doc2 = sbll_apply($doc, DocumentOperation::updateBlockMeta($other, ['label' => 'Still editable']));
    assert_eq('Still editable', $doc2['sections'][0]['blocks'][0]['metadata']['label']);
});

unit('layer lock: a locked container locks its descendants and refuses inserts and moves into it', function (): void {
    $doc = sbll_doc();
    $container = $doc['sections'][0]['blocks'][0]['id'];
    $inner = $doc['sections'][0]['blocks'][0]['children'][0]['id'];
    $sibling = $doc['sections'][0]['blocks'][1]['id'];
    $doc = sbll_apply($doc, DocumentOperation::updateBlockMeta($container, ['locked' => true]));

    sbll_refused($doc, new DocumentOperation('update_block_props', ['block_id' => $inner, 'props' => ['text' => 'x', 'level' => 'h2']]), 'descendant edit');
    sbll_refused($doc, new DocumentOperation('insert_block', ['parent_id' => $container, 'index' => 0, 'block' => ['type' => 'core.heading']]), 'insert into locked container');
    sbll_refused($doc, new DocumentOperation('move_block', ['block_id' => $sibling, 'parent_id' => $container, 'index' => 0]), 'move into locked container');
    sbll_refused($doc, new DocumentOperation('move_block', ['block_id' => $inner, 'parent_id' => $doc['sections'][0]['id'], 'index' => 0]), 'move out of locked container');
});

unit('layer lock: removing a parent that holds a locked child is refused, so a lock cannot be deleted away', function (): void {
    $doc = sbll_doc();
    $sec = $doc['sections'][0]['id'];
    $container = $doc['sections'][0]['blocks'][0]['id'];
    $inner = $doc['sections'][0]['blocks'][0]['children'][0]['id'];
    $doc = sbll_apply($doc, DocumentOperation::updateBlockMeta($inner, ['locked' => true]));

    sbll_refused($doc, new DocumentOperation('remove_block', ['block_id' => $container]), 'remove container holding a locked child');
    sbll_refused($doc, new DocumentOperation('remove_section', ['section_id' => $sec]), 'remove section holding a locked child');
    assert_true(LayerLock::hasLockedDescendant(LayerLock::index($doc), $sec));
});

unit('layer lock: a locked section refuses edits, moves, removal and inserts; its blocks are effectively locked', function (): void {
    $doc = sbll_doc();
    $sec = $doc['sections'][0]['id'];
    $sibling = $doc['sections'][0]['blocks'][1]['id'];
    $doc = sbll_apply($doc, DocumentOperation::updateSectionLocked($sec, true));

    sbll_refused($doc, DocumentOperation::updateSectionLabel($sec, 'Renamed'), 'section rename');
    sbll_refused($doc, new DocumentOperation('update_section_layout', ['section_id' => $sec, 'layout' => CanonicalDocumentSchema::defaultSectionLayout()]), 'section layout');
    sbll_refused($doc, new DocumentOperation('move_section', ['section_id' => $sec, 'to_index' => 0]), 'section move');
    sbll_refused($doc, new DocumentOperation('remove_section', ['section_id' => $sec]), 'section remove');
    sbll_refused($doc, new DocumentOperation('insert_block', ['parent_id' => $sec, 'index' => 0, 'block' => ['type' => 'core.heading']]), 'insert into locked section');
    sbll_refused($doc, new DocumentOperation('update_block_props', ['block_id' => $sibling, 'props' => ['text' => 'x', 'level' => 'h2']]), 'block in locked section');
});

unit('layer lock: unlocking works from the outermost lock only', function (): void {
    $doc = sbll_doc();
    $sec = $doc['sections'][0]['id'];
    $inner = $doc['sections'][0]['blocks'][0]['children'][0]['id'];
    $doc = sbll_apply($doc, DocumentOperation::updateBlockMeta($inner, ['locked' => true]));
    $doc = sbll_apply($doc, DocumentOperation::updateSectionLocked($sec, true));

    // The block's own lock cannot be lifted while its section is locked.
    sbll_refused($doc, DocumentOperation::updateBlockMeta($inner, ['locked' => false]), 'unlock under a locked ancestor');

    $doc = sbll_apply($doc, DocumentOperation::updateSectionLocked($sec, false));
    $doc = sbll_apply($doc, DocumentOperation::updateBlockMeta($inner, ['locked' => false]));
    assert_true(!array_key_exists('metadata', $doc['sections'][0]['blocks'][0]['children'][0]));
});

unit('layer lock: duplicating is allowed and the copy keeps its lock and label', function (): void {
    $doc = sbll_doc();
    $sibling = $doc['sections'][0]['blocks'][1]['id'];
    $doc = sbll_apply($doc, DocumentOperation::updateBlockMeta($sibling, ['label' => 'Promo', 'locked' => true]));
    $doc = sbll_apply($doc, DocumentOperation::duplicateBlock($sibling));

    $copy = $doc['sections'][0]['blocks'][2];
    assert_true($copy['id'] !== $sibling, 'the copy has a fresh id');
    assert_eq(['label' => 'Promo', 'locked' => true], $copy['metadata']);
});

unit('layer lock: an AI assistant can rename layers but can never lock or unlock them', function (): void {
    $call = static function (array $operations): array {
        $m = new \ReflectionMethod(StudioMcpAdapter::class, 'operations');
        $m->setAccessible(true);
        return $m->invoke(null, ['operations' => $operations]);
    };
    $blk = 'blk_' . str_repeat('a', 16);
    $sec = 'sec_' . str_repeat('b', 16);

    $ok = $call([['op' => 'update_block_meta', 'payload' => ['block_id' => $blk, 'label' => 'Hero']]]);
    assert_eq('update_block_meta', $ok[0]->op, 'renaming is allowed');

    foreach ([
        ['op' => 'update_block_meta', 'payload' => ['block_id' => $blk, 'locked' => false]],
        ['op' => 'update_block_meta', 'payload' => ['block_id' => $blk, 'label' => 'x', 'locked' => true]],
        ['op' => 'update_section_locked', 'payload' => ['section_id' => $sec, 'locked' => false]],
    ] as $forbidden) {
        assert_throws(\Throwable::class, static function () use ($call, $forbidden): void {
            $call([$forbidden]);
        }, 'must refuse ' . json_encode($forbidden));
    }
});
