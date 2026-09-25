<?php
declare(strict_types=1);

use Slate\Presentation\BlockRegistryProjection;
use Slate\Presentation\CompilationMetadata;
use Slate\Presentation\DocumentOperations;
use Slate\Presentation\EditorStateSerializer;
use Slate\Presentation\GlobalReferenceResolver;
use Slate\Presentation\Theme\ThemeSlotBinding;
use Slate\Presentation\FieldSchema;
use Slate\Services\Content\PreviewService;
use Slate\Presentation\Rendering\CallbackBlock;
use Slate\Presentation\Rendering\InMemoryBlockRegistry;

$registry = new InMemoryBlockRegistry();
$registry->register(new CallbackBlock(
    'hero',
    FieldSchema::of([
        ['key' => 'heading', 'type' => 'text'],
        ['key' => 'align', 'type' => 'select', 'responsive' => true],
    ], ['heading' => '', 'align' => 'start']),
    static fn (array $props, \Slate\Presentation\RenderContext $context): string => '<h1>' . htmlspecialchars((string)$props['heading'], ENT_QUOTES, 'UTF-8') . '</h1>'
));

$projection = BlockRegistryProjection::project($registry);
assert_eq(1, count($projection), 'registry projection includes registered blocks');
assert_eq('hero', $projection[0]['type'], 'projection exposes stable type key');
assert_eq('Hero', $projection[0]['label'], 'projection derives a safe display label');
assert_eq(true, $projection[0]['capabilities']['responsive'], 'projection exposes responsive capability');
assert_false(isset($projection[0]['render']), 'projection does not leak server renderer');
assert_false(isset($projection[0]['class']), 'projection does not leak PHP class metadata');

$document = [
    'schema' => 1,
    'type' => 'page',
    'template' => '',
    'sections' => [
        ['id' => 's1', 'layout' => ['cols' => 1, 'bg' => '', 'pad' => 'normal', 'width' => 'normal'], 'blocks' => [
            ['type' => 'hero', 'props' => ['heading' => 'One']],
        ]],
        ['id' => 's2', 'layout' => ['cols' => 1, 'bg' => '', 'pad' => 'normal', 'width' => 'normal'], 'blocks' => []],
    ],
    'seo' => [],
];

$inserted = DocumentOperations::insertBlock($document, 's1', 1, ['type' => 'hero', 'props' => ['heading' => 'Two']]);
assert_eq(1, count($document['sections'][0]['blocks']), 'insert does not mutate original document');
assert_eq(2, count($inserted['sections'][0]['blocks']), 'insert adds block immutably');

$moved = DocumentOperations::moveBlock($inserted, 's1', 0, 's2', 0);
assert_eq(2, count($inserted['sections'][0]['blocks']), 'move does not mutate source document');
assert_eq(1, count($moved['sections'][1]['blocks']), 'move transfers block to destination section');

$updated = DocumentOperations::updateBlockProps($moved, 's1', 0, ['heading' => 'Updated']);
assert_eq('Two', $moved['sections'][0]['blocks'][0]['props']['heading'], 'update does not mutate original document');
assert_eq('Updated', $updated['sections'][0]['blocks'][0]['props']['heading'], 'update patches exact block props');
$nestedDocument = $document;
$nestedDocument['sections'][0]['blocks'] = [[
    'type' => 'columns',
    'props' => ['cols' => [['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Nested']]]]]],
]];
$nestedInserted = DocumentOperations::insertBlockAtPath($nestedDocument, ['s1', 0, 'props', 'cols', 0, 'blocks'], 1, ['type' => 'hero', 'props' => ['heading' => 'Second']]);
assert_eq(2, count($nestedInserted['sections'][0]['blocks'][0]['props']['cols'][0]['blocks']), 'nested insert targets the requested child collection');
$nestedUpdated = DocumentOperations::updateBlockPropsAtPath($nestedInserted, ['s1', 0, 'props', 'cols', 0, 'blocks'], 0, ['heading' => 'Changed']);
assert_eq('Nested', $nestedInserted['sections'][0]['blocks'][0]['props']['cols'][0]['blocks'][0]['props']['heading'], 'nested update does not mutate source');
assert_eq('Changed', $nestedUpdated['sections'][0]['blocks'][0]['props']['cols'][0]['blocks'][0]['props']['heading'], 'nested update patches child props');

$metadataA = CompilationMetadata::for($updated, 'renderer-1', 'theme-1', ['content:1', 'global:2']);
$metadataB = CompilationMetadata::for($updated, 'renderer-1', 'theme-1', ['global:2', 'content:1']);
assert_eq($metadataA->fingerprint, $metadataB->fingerprint, 'dependency order does not change compilation fingerprint');
assert_eq(['content:1', 'global:2'], $metadataA->dependencies, 'dependencies are unique and sorted');
assert_true(isset($metadataA->jsonSerialize()['content_fingerprint']), 'metadata serializes for persistence');

$editorDocument = $document;
$editorDocument['sections'][0]['blocks'][0]['nodeId'] = 'craft-node-1';
$editorDocument['sections'][0]['blocks'][0]['selected'] = true;
$editorDocument['sections'][0]['blocks'][0]['dragRect'] = ['x' => 1, 'y' => 2];
$serialized = EditorStateSerializer::serialize(['document' => $editorDocument, 'selection' => 'craft-node-1', 'history' => []]);
assert_false(isset($serialized['sections'][0]['blocks'][0]['nodeId']), 'serializer strips Craft node IDs');
assert_false(isset($serialized['sections'][0]['blocks'][0]['selected']), 'serializer strips selection state');
assert_false(isset($serialized['sections'][0]['blocks'][0]['dragRect']), 'serializer strips drag measurements');

$previewCompiler = new class implements \Slate\Presentation\ContentCompiler {
    public function compile(array $document, \Slate\Presentation\RenderContext $context): string
    {
        return '<main data-preview="1"></main>';
    }
};
$previewDocument = ['schema' => 1, 'type' => 'page', 'template' => '', 'sections' => [], 'seo' => []];
$preview = (new PreviewService($previewCompiler, []))->render($previewDocument, \Slate\Presentation\RenderContext::for(1));
assert_eq('<main data-preview="1"></main>', $preview['html'], 'preview uses the shared compiler capability');
assert_eq('noindex, nofollow', $preview['headers']['X-Robots-Tag'], 'preview is non-indexable');
assert_eq('no-store', $preview['headers']['Cache-Control'], 'preview bypasses cache');

$slots = ThemeSlotBinding::fromArray(['header_block_id' => '12', 'footer_block_id' => 13]);
assert_eq(12, $slots->headerBlockId, 'theme slot IDs normalize numeric strings');
assert_eq(['header_block_id' => 12, 'footer_block_id' => 13], $slots->jsonSerialize(), 'theme slots serialize canonically');

$global = GlobalReferenceResolver::resolve(['$ref' => 'global-header'], [
    'global-header' => ['type' => 'hero', 'props' => ['heading' => 'Header']],
]);
assert_eq('hero', $global['type'], 'global references resolve to stored block definitions');
assert_throws(\InvalidArgumentException::class, fn () => GlobalReferenceResolver::resolve(['$ref' => 'missing'], []));
assert_throws(\InvalidArgumentException::class, fn () => GlobalReferenceResolver::resolve(['$ref' => 'a'], ['a' => ['$ref' => 'a']]));
