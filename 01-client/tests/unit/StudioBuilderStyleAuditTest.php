<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the stored-document style audit.
 *
 * The audit is the validator itself filtered to styling errors, so it names exactly the blocks
 * that would be left out of a public page.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\StyleAudit;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;

function sbau_doc(array $style, array $blockExtra = []): array
{
    return ['schema_version' => '1.0', 'document_type' => 'page', 'template_key' => 'default', 'settings' => [], 'seo' => [], 'sections' => [[
        'id' => CanonicalDocumentSchema::newSectionId(), 'label' => 'S', 'global_ref' => null, 'layout' => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks' => [[
            'id' => CanonicalDocumentSchema::newBlockId(), 'type' => 'core.heading', 'version' => 1, 'props' => ['text' => 'x', 'level' => 'h2'],
            'style' => $style + CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
        ] + $blockExtra],
    ]]];
}

unit('style audit: a clean document has no findings', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    assert_eq([], StyleAudit::issues(sbau_doc([]), $registry));
    assert_eq([], StyleAudit::issues(sbau_doc(['typography' => ['size' => '2rem'], 'background' => ['image' => ['media_id' => 7, 'alt' => ''], 'fit' => 'cover', 'repeat' => 'no-repeat', 'overlay' => ['color' => '#00000066']], 'z_index' => 999]), $registry));
});

unit('style audit: it names every kind of styling the current rules refuse', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $cases = [
        'url() in a value'          => [sbau_doc(['typography' => ['size' => 'url(https://evil.test/x)']]), '.style.typography.size'],
        'z-index over the ceiling'  => [sbau_doc(['z_index' => 9999]), '.style.z_index'],
        'background fit "fill"'     => [sbau_doc(['background' => ['fit' => 'fill']]), '.style.background.fit'],
        'background two-word position' => [sbau_doc(['background' => ['position' => 'center center']]), '.style.background.position'],
        'unknown style key'         => [sbau_doc(['transform' => 'rotate(3deg)']), '.style.transform'],
        'bad state'                 => [sbau_doc([], ['style_states' => ['visited' => ['color' => '#fff']]]), '.style_states.visited'],
        'bad tag'                   => [sbau_doc([], ['tag' => 'script']), '.tag'],
    ];
    foreach ($cases as $label => [$doc, $needle]) {
        $found = StyleAudit::issues($doc, $registry);
        assert_true($found !== [], "{$label} must be reported");
        assert_true(str_contains(json_encode(array_column($found, 'path')), str_replace('.', '.', $needle)), "{$label} must name {$needle}: " . json_encode($found));
    }
});

unit('style audit: errors that are not about styling are not reported', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $doc = sbau_doc([]);
    $doc['sections'][0]['blocks'][0]['props'] = ['text' => 'x', 'level' => 'h9'];
    assert_eq([], StyleAudit::issues($doc, $registry), 'an invalid prop is somebody else\'s finding');
});
