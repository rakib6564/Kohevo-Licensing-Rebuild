<?php
/**
 * Kohevo Studio: rich text and repeater fields, and blocks that depend on a field having a value.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Meta\MetaFieldTypes;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Tenancy\TenantContext;

$sbrField = ['key' => 'faq', 'label' => 'FAQ', 'type' => 'repeater', 'options' => ['subfields' => [['key' => 'question', 'label' => 'Question', 'type' => 'text'], ['key' => 'answer', 'label' => 'Answer', 'type' => 'richtext']]]];

unit('repeater field: sub-fields parse from lines, and a bad key or type cannot get in', function (): void {
    $subs = MetaFieldTypes::parseSubfields("question : Question : text\nanswer : Answer : richtext\nBad Key : x\nplain\nquestion : Again\nodd : Odd : script");
    assert_eq(['question', 'answer', 'plain', 'odd'], array_column($subs, 'key'));
    assert_eq('richtext', $subs[1]['type']);
    assert_eq('plain', $subs[2]['label'], 'the label defaults to the key');
    assert_eq('text', $subs[3]['type'], 'an unknown type falls back to text');
});

unit('repeater field: rows are cleaned by their sub-field, empty rows go, and unknown keys are dropped', function () use ($sbrField): void {
    $r = PageMeta::clean($sbrField, [
        ['question' => ' Q1 ', 'answer' => '<p onclick="x">A1</p><script>bad()</script>', 'extra' => 'no'],
        ['question' => '', 'answer' => '<p></p>'],
        'not a row',
        ['question' => 'Q2', 'answer' => ''],
    ]);
    assert_eq(null, $r['error']);
    assert_eq('Q1', $r['value'][0]['question']);
    assert_true(!str_contains($r['value'][0]['answer'], 'script') && !str_contains($r['value'][0]['answer'], 'onclick'), 'answer sanitized');
    assert_true(!isset($r['value'][0]['extra']), 'unknown sub-field dropped');
    assert_eq(['Q1', 'Q2'], array_column($r['value'], 'question'));
    assert_true(PageMeta::clean($sbrField + ['required' => true], [])['error'] !== null, 'a required repeater needs a row');
});

unit('single template: its content type comes from its conditions; a normal page has none', function (): void {
    $doc = ['settings' => ['template_type' => 'single', 'conditions' => ['rules' => [['action' => 'exclude', 'condition' => 'singular', 'value' => 'x'], ['action' => 'include', 'condition' => 'singular', 'value' => 'service']]]]];
    assert_eq('service', PageMeta::singleTemplateType($doc));
    assert_eq('', PageMeta::singleTemplateType(['settings' => []]));
});

function sbrf_render(array $block, array $values, array $fields, ?RenderContext $context = null): string
{
    $renderer = new DocumentRenderer(BlockRegistry::withAllCoreBlocks(), BlockRendererRegistry::withCoreRenderers(), new CoreMediaResolver(new TenantContext()), new ProviderBindingResolver(new DataProviderRegistry(), new TenantContext()));
    $context ??= RenderContext::forPublic(101, new SiteContext('https://example.test', 'Test Site'));
    return $renderer->renderBlock($block, $context->withAttributes(['page_meta' => ['fields' => $fields, 'values' => $values]]), (new ThemeResolver())->resolve('default'), new RenderCollector(), false);
}

function sbrf_block(string $type, array $props, array $children = [], array $visibility = []): array
{
    return ['id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => 1, 'props' => $props, 'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => $visibility + CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => $children];
}

unit('repeater block: children repeat once per row and read that row', function () use ($sbrField): void {
    $block = sbrf_block('theme.repeater', ['field' => 'faq'], [sbrf_block('theme.field_value', ['field' => 'question', 'tag' => 'div'])]);
    $html = sbrf_render($block, ['faq' => [['question' => 'One', 'answer' => ''], ['question' => 'Two <b>', 'answer' => '']]], [$sbrField]);
    assert_true(str_contains($html, '>One<') && str_contains($html, 'Two &lt;b&gt;'), 'each row, escaped');
    assert_eq('', sbrf_render($block, ['faq' => []], [$sbrField]), 'no rows, no box');
});

unit('repeater block: an accordion shows rich answers, and a script in one never reaches the page', function () use ($sbrField): void {
    $block = sbrf_block('theme.repeater', ['field' => 'faq', 'display' => 'accordion']);
    $html = sbrf_render($block, ['faq' => [['question' => 'Q', 'answer' => '<p><strong>A</strong></p><script>x()</script>']]], [$sbrField]);
    assert_true(str_contains($html, 'data-sb-accordion') && str_contains($html, '<strong>A</strong>'));
    assert_true(!str_contains($html, '<script'), 'a stored script is cleaned again on output');
});

unit('field value: rich text keeps formatting and images, and older plain text keeps its line breaks', function (): void {
    $field = ['key' => 'body', 'label' => 'Body', 'type' => 'richtext'];
    $block = sbrf_block('theme.field_value', ['field' => 'body', 'tag' => 'div']);
    $html = sbrf_render($block, ['body' => '<h2>T</h2><img src="/uploads/a.jpg" alt="x" onerror="y()"><img src="javascript:bad()">'], [$field]);
    assert_true(str_contains($html, '<h2>T</h2>') && str_contains($html, 'src="/uploads/a.jpg"'));
    assert_true(!str_contains($html, 'onerror') && !str_contains($html, 'javascript:'));
    assert_true(str_contains(sbrf_render($block, ['body' => "one\ntwo"], [$field]), "<br>"), 'plain text');
});

unit('visibility: a block tied to a field goes when the field is empty, is deferred, and stays on the canvas', function (): void {
    $field = ['key' => 'note', 'label' => 'Note', 'type' => 'text'];
    $block = sbrf_block('core.text', ['content' => 'Hello', 'size' => 'inherit'], [], ['when_field' => 'note']);
    assert_true(str_contains(sbrf_render($block, ['note' => 'x'], [$field]), 'Hello'));
    assert_eq('', sbrf_render($block, ['note' => ''], [$field]));
    assert_eq('', sbrf_render($block, ['note' => []], [$field]), 'an empty repeater counts as empty');
    assert_true(DocumentRenderer::isDynamicNode($block, BlockRegistry::withAllCoreBlocks()->get('core.text'), BlockRendererRegistry::withCoreRenderers()->get('core.text')), 'never baked into a stored page');
});
