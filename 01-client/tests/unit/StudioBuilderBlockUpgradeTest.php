<?php
/**
 * Block version upgrades (gap report TI-07, option B).
 *
 * A block definition declares one upgrade step per older version, and
 * DocumentUpgrader steps every older block of a document up to the
 * registered version before validation. A block without a full upgrade path
 * is left untouched, and the validator refuses it with a report that names
 * the block, its version and the supported one.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentUpgrader;
use Slate\Module\StudioBuilder\Document\ValidatedDocument;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Registry\DeclarativeBlockDefinition;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\Sdk\CustomWidgetDefinition;

/** A probe block at `$version`, whose current prop is `text`, with the given upgrade steps. */
function sbup_probe(string $type, int $version, array $upgrades): DeclarativeBlockDefinition
{
    return new DeclarativeBlockDefinition(
        type: $type,
        version: $version,
        label: 'Probe',
        category: 'test',
        icon: 'box',
        schema: FieldSchema::define([['key' => 'text', 'type' => 'string', 'label' => 'Text', 'required' => false, 'default' => '', 'max_length' => 200]]),
        upgrades: $upgrades,
    );
}

function sbup_registry(): BlockRegistry
{
    $registry = BlockRegistry::withCoreFoundationBlocks();
    // v1 said `caption`, v2 said `label`, v3 says `text`.
    $registry->register(sbup_probe('probe.note', 3, [
        1 => static fn(array $p): array => ['label' => (string) ($p['caption'] ?? '')],
        2 => static fn(array $p): array => ['text' => (string) ($p['label'] ?? '')],
    ]));
    // Only 2 -> 3 is declared: a version-1 block has no path.
    $registry->register(sbup_probe('probe.gap', 3, [2 => static fn(array $p): array => ['text' => (string) ($p['label'] ?? '')]]));
    $registry->register(sbup_probe('probe.boom', 2, [1 => static function (array $p): array { throw new \RuntimeException('broken step'); }]));
    return $registry;
}

function sbup_block(string $type, int $version, array $props, array $children = []): array
{
    return [
        'id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => $version, 'props' => $props,
        'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [], 'children' => $children,
    ];
}

function sbup_doc(array $blocks): array
{
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Upgrade');
    $doc['sections'][] = [
        'id' => CanonicalDocumentSchema::newSectionId(), 'label' => 'Main', 'global_ref' => null,
        'layout' => CanonicalDocumentSchema::defaultSectionLayout(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks' => $blocks,
    ];
    return $doc;
}

unit('block upgrade: older blocks are stepped up one version at a time, nested children too, and then validate', function (): void {
    $registry = sbup_registry();
    $nested = sbup_block('probe.note', 1, ['caption' => 'Inside']);
    $doc = sbup_doc([
        sbup_block('probe.note', 1, ['caption' => 'From v1']),
        sbup_block('probe.note', 2, ['label' => 'From v2']),
        sbup_block('core.container', 1, ['direction' => 'vertical', 'gap' => 'md'], [$nested]),
        sbup_block('core.heading', 1, ['text' => 'Current', 'level' => 'h2']),
    ]);
    $out = DocumentUpgrader::upgrade($doc, $registry);
    $blocks = $out['document']['sections'][0]['blocks'];
    assert_eq([3, ['text' => 'From v1']], [$blocks[0]['version'], $blocks[0]['props']], 'v1 -> v3 through both steps');
    assert_eq([3, ['text' => 'From v2']], [$blocks[1]['version'], $blocks[1]['props']], 'v2 -> v3 through the last step');
    assert_eq([3, ['text' => 'Inside']], [$blocks[2]['children'][0]['version'], $blocks[2]['children'][0]['props']], 'a nested child is upgraded');
    assert_eq($doc['sections'][0]['blocks'][3], $blocks[3], 'a current block is untouched');
    assert_eq($nested['id'], $blocks[2]['children'][0]['id'], 'ids and everything but props/version stay');
    assert_eq([
        ['path' => '$.sections[0].blocks[0]', 'type' => 'probe.note', 'from' => 1, 'to' => 3],
        ['path' => '$.sections[0].blocks[1]', 'type' => 'probe.note', 'from' => 2, 'to' => 3],
        ['path' => '$.sections[0].blocks[2].children[0]', 'type' => 'probe.note', 'from' => 1, 'to' => 3],
    ], $out['upgraded']);

    $flat = sbup_doc([sbup_block('probe.note', 1, ['caption' => 'Saved long ago'])]);
    $valid = ValidatedDocument::from(DocumentUpgrader::upgrade($flat, $registry)['document'], $registry)->toArray();
    assert_eq('Saved long ago', $valid['sections'][0]['blocks'][0]['props']['text'], 'the upgraded document validates');
    assert_eq(DocumentUpgrader::upgrade($flat, $registry)['document'], DocumentUpgrader::upgrade(DocumentUpgrader::upgrade($flat, $registry)['document'], $registry)['document'], 'upgrading twice changes nothing more');
});

unit('block upgrade: no full path, a failing step, a newer or unknown block are left alone, and the validator names the block', function (): void {
    $registry = sbup_registry();
    $doc = sbup_doc([
        sbup_block('probe.gap', 1, ['caption' => 'No path']),
        sbup_block('probe.boom', 1, ['caption' => 'Bad step']),
        sbup_block('probe.note', 7, ['text' => 'From the future']),
        sbup_block('evil.unknown', 1, []),
    ]);
    $out = DocumentUpgrader::upgrade($doc, $registry);
    assert_eq($doc, $out['document'], 'nothing changed');
    assert_eq([], $out['upgraded']);

    $errors = [];
    try {
        ValidatedDocument::from(sbup_doc([sbup_block('probe.gap', 1, ['caption' => 'No path'])]), $registry);
    } catch (StudioValidationException $e) {
        $errors = $e->errors();
    }
    assert_eq('invalid_block_version', $errors[0]['code'] ?? null);
    assert_eq('$.sections[0].blocks[0].version', $errors[0]['path'] ?? null);
    assert_eq("Block 'probe.gap' is version 1; this site supports version 3 and has no upgrade from version 1.", $errors[0]['message'] ?? null, 'the refusal names the block and both versions');
});

unit('block upgrade: steps are declared for older versions only, and SDK widget migrations become upgrade steps', function (): void {
    foreach ([[0 => 'x'], [3 => 'x'], ['one' => 'x'], [1 => 'not callable']] as $bad) {
        $steps = array_map(static fn($v) => $v === 'x' ? static fn(array $p): array => $p : $v, $bad);
        assert_throws(\InvalidArgumentException::class, static fn() => sbup_probe('probe.bad', 3, $steps));
    }
    assert_null(sbup_probe('probe.none', 2, [])->upgrade(['text' => 'a'], 1), 'no step: no upgrade');

    $widget = new CustomWidgetDefinition(
        type: 'vendor.upgrade_probe',
        version: 3,
        label: 'Upgrade probe',
        schema: [['key' => 'text', 'type' => 'string', 'label' => 'Text', 'required' => false]],
        renderer: static fn(): string => '<div></div>',
        migration: static fn(int $from, int $to, array $props): array => ['steps' => ($props['steps'] ?? '') . "{$from}>{$to};"],
    );
    $block = $widget->toBlockDefinition();
    assert_eq(['steps' => '1>2;'], $block->upgrade([], 1));
    assert_eq(['steps' => '2>3;'], $block->upgrade([], 2));
    assert_null($block->upgrade([], 3), 'nothing above the current version');
});

unit('block upgrade: rendering upgrades older blocks in memory; only a block without a path falls back to unavailable', function (): void {
    $registry = sbup_registry();
    $doc = sbup_doc([sbup_block('probe.note', 1, ['caption' => 'Published at v1']), sbup_block('core.heading', 1, ['text' => 'Current', 'level' => 'h2'])]);
    $prepared = \Slate\Module\StudioBuilder\Render\RenderDocumentPreparer::prepare(\Slate\Module\StudioBuilder\Document\CanonicalJson::encode($doc), $registry);
    assert_false($prepared['degraded'], 'the whole document validates after the upgrade');
    $block = $prepared['document']['sections'][0]['blocks'][0];
    assert_eq([3, ['text' => 'Published at v1']], [$block['version'], $block['props']]);

    $gap = \Slate\Module\StudioBuilder\Render\RenderDocumentPreparer::prepare(sbup_doc([sbup_block('probe.gap', 1, ['text' => 'x']), sbup_block('core.heading', 1, ['text' => 'Kept', 'level' => 'h2'])]), $registry);
    assert_true($gap['degraded'], 'no path: degraded');
    assert_true(isset($gap['document']['sections'][0]['blocks'][0][\Slate\Module\StudioBuilder\Render\RenderDocumentPreparer::UNAVAILABLE_KEY]), 'that block alone is unavailable');
    assert_eq('Kept', $gap['document']['sections'][0]['blocks'][1]['props']['text'], 'the rest of the page still renders');
});
