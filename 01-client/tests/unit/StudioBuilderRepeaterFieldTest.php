<?php
/**
 * Kohevo Studio: rich text and repeater fields, and blocks that depend on a field having a value.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Application\SiteModeTemplates;
use Slate\Module\StudioBuilder\Meta\LoopTemplates;
use Slate\Module\StudioBuilder\Meta\SiteMode;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Operation\DocumentOperationApplier;
use Slate\Module\StudioBuilder\Meta\MetaFieldTypes;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Dynamic\DynamicTags;
use Slate\Module\StudioBuilder\Render\Icon\IconLibrary;
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

unit('loop item: a card blocks read the item in the render context, and go quietly without one', function (): void {
    $attrs = LoopTemplates::attributes(['title' => 'Ribs', 'url' => '/recipe/ribs/', 'excerpt' => 'Slow <b>', 'featured_image' => '/uploads/r.jpg']);
    $context = RenderContext::forPublic(101, new SiteContext('https://example.test', 'Test Site'))->withAttributes($attrs);
    $image = sbrf_render(sbrf_block('theme.post_image', []), [], [], $context);
    assert_true(str_contains($image, 'src="/uploads/r.jpg"') && str_contains($image, 'href="/recipe/ribs/"'));
    assert_true(str_contains(sbrf_render(sbrf_block('theme.post_excerpt', []), [], [], $context), 'Slow &lt;b&gt;'));
    assert_true(str_contains(sbrf_render(sbrf_block('theme.post_link', ['text' => 'Go']), [], [], $context), '>Go</a>'));
    assert_eq('', sbrf_render(sbrf_block('theme.post_excerpt', []), [], []), 'no item, no box');
});

unit('loop item: a loop takes the chosen template, else the one for its content type, and none means built-in cards', function (): void {
    $a = ['uuid' => 'a', 'id' => 1, 'title' => 'A', 'content_type' => 'service', 'document_json' => ''];
    $b = ['uuid' => 'b', 'id' => 2, 'title' => 'B', 'content_type' => 'recipe', 'document_json' => ''];
    assert_eq('b', LoopTemplates::pick([$a, $b], '', 'recipe')['uuid']);
    assert_eq('a', LoopTemplates::pick([$a, $b], 'a', 'recipe')['uuid'], 'the chosen one wins');
    assert_eq(null, LoopTemplates::pick([$a, $b], LoopTemplates::NONE, 'recipe'));
    assert_eq(null, LoopTemplates::pick([$a, $b], '', 'area'));
    assert_eq(null, LoopTemplates::pick([$a, $b], '', ''), 'a loop of everything has no type to go by');
});

unit('icon list: an icon comes only from the library, and a link only from a safe address', function (): void {
    $html = sbrf_render(sbrf_block('core.icon_list', ['items' => [
        ['text' => 'Call <us>', 'icon' => 'phone', 'url' => 'tel:8773572433'],
        ['text' => 'Bad', 'icon' => '"><script>', 'url' => 'javascript:bad()'],
    ], 'layout' => 'inline', 'divider' => true, 'icon_size' => 20]), [], []);
    assert_true(str_contains($html, 'Call &lt;us&gt;') && str_contains($html, 'href="tel:8773572433"'));
    assert_true(str_contains($html, 'sb-icon-list--inline') && str_contains($html, 'sb-icon-list--divided') && str_contains($html, '--sb-il-is-d:20px'));
    assert_true(!str_contains($html, '<script') && !str_contains($html, 'javascript:'), 'a bad icon or link goes');
    assert_eq(1, substr_count($html, '<svg'), 'only the known icon');
});

unit('icon library: every icon is plain shapes, and a stored name can only be a library name', function (): void {
    assert_true(count(IconLibrary::names()) > 1500);
    foreach (['check', 'x-social', 'leaf', 'chef-hat', 'utensils'] as $name) {
        assert_true(IconLibrary::has($name), $name);
    }
    foreach (IconLibrary::names() as $name) {
        assert_true(preg_match('/<(script|style|a|image|foreignObject)\b|\son[a-z]+=/i', IconLibrary::markup($name)) !== 1, $name);
    }
    assert_eq('', IconLibrary::svg('nope'));
});

unit('dynamic tags: a Heading or Text takes the title, a field or a link, with before, after and fallback', function (): void {
    $context = RenderContext::forPublic(101, new SiteContext('https://example.test', 'Chef'))->withAttributes(LoopTemplates::attributes(['title' => 'Ribs', 'url' => '/recipe/ribs/', 'excerpt' => 'Slow']));
    $field = ['key' => 'note', 'label' => 'Note', 'type' => 'text'];
    $heading = sbrf_block('core.heading', ['text' => 'Mine', 'level' => 'h2']) + ['dynamic' => ['text' => ['tag' => 'post_title', 'before' => 'Try ', 'after' => '!'], 'url' => ['tag' => 'post_url']]];
    $html = sbrf_render($heading, [], [], $context);
    assert_true(str_contains($html, '>Try Ribs!<') && str_contains($html, 'href="/recipe/ribs/"') && !str_contains($html, 'Mine'));
    assert_true(str_contains(sbrf_render(sbrf_block('core.text', ['content' => 'Mine']) + ['dynamic' => ['content' => ['tag' => 'field', 'key' => 'note']]], ['note' => 'From a field'], [$field], $context), 'From a field'));
    $empty = RenderContext::forPublic(101, new SiteContext('https://example.test', 'Chef'));
    assert_eq('', sbrf_render(sbrf_block('core.text', ['content' => 'Mine']) + ['dynamic' => ['content' => ['tag' => 'post_excerpt']]], [], [], $empty), 'nothing to show leaves no box');
    assert_true(str_contains(sbrf_render(sbrf_block('core.text', ['content' => 'Mine']) + ['dynamic' => ['content' => ['tag' => 'post_excerpt', 'fallback' => 'Soon']]], [], [], $empty), 'Soon'));
    assert_true(str_contains(sbrf_render(sbrf_block('core.text', ['content' => 'Go', 'url' => '/contact/', 'new_tab' => true]), [], []), 'target="_blank"'));
    assert_true(DocumentRenderer::isDynamicNode($heading, BlockRegistry::withAllCoreBlocks()->get('core.heading'), BlockRendererRegistry::withCoreRenderers()->get('core.heading')), 'never baked into a stored page');
});

unit('dynamic tags: values stay safe, and images, buttons and repeater rows take them too', function (): void {
    $field = ['key' => 'pic', 'label' => 'Pic', 'type' => 'image'];
    $evil = ['key' => 'evil', 'label' => 'Evil', 'type' => 'text'];
    $link = ['key' => 'bad', 'label' => 'Bad', 'type' => 'url'];
    $image = sbrf_render(sbrf_block('core.image', []) + ['dynamic' => ['media' => ['tag' => 'field', 'key' => 'pic']]], ['pic' => '/uploads/media/a.jpg'], [$field]);
    assert_true(str_contains($image, 'src="/uploads/media/a.jpg"'));
    assert_true(str_contains(sbrf_render(sbrf_block('core.image', []) + ['dynamic' => ['media' => ['tag' => 'field', 'key' => 'pic']]], ['pic' => 'javascript:alert(1)'], [$field]), 'javascript') === false);
    $text = sbrf_render(sbrf_block('core.heading', ['text' => 'x', 'level' => 'h2']) + ['dynamic' => ['text' => ['tag' => 'field', 'key' => 'evil']]], ['evil' => '<script>alert(1)</script>'], [$evil]);
    assert_true(!str_contains($text, '<script>'));
    $rich = sbrf_render(sbrf_block('core.rich_text', ['content' => '<p>x</p>']) + ['dynamic' => ['content' => ['tag' => 'field', 'key' => 'evil']]], ['evil' => '<img src=x onerror=alert(1)>'], [$evil]);
    assert_true(!str_contains($rich, '<img'));
    $list = sbrf_render(sbrf_block('core.heading', ['text' => 'x', 'level' => 'h2', 'url' => '/own/']) + ['dynamic' => ['url' => ['tag' => 'field', 'key' => 'bad']]], ['bad' => 'javascript:alert(1)'], [$link]);
    assert_true(str_contains($list, 'href="/own/"') && !str_contains($list, 'javascript'), 'a bad address keeps the own link');

    $container = array_replace(sbrf_block('core.container', []), ['version' => BlockRegistry::withAllCoreBlocks()->get('core.container')->version(), 'dynamic' => ['@background' => ['tag' => 'field', 'key' => 'pic']]]);
    $band = sbrf_render($container, ['pic' => '/uploads/media/a.jpg'], [$field]);
    assert_true(str_contains($band, 'background-image:url('), 'a Container background sits on the element, so each Loop Item has its own');
    $bad = sbrf_render($container, ['pic' => 'javascript:alert(1)'], [$field]);
    assert_true(!str_contains($bad, 'background-image'));
    $row = sbrf_render(sbrf_block('core.icon_list', ['items' => [['text' => 'Own', 'icon' => 'check']]]) + ['dynamic' => ['items.0.text' => ['tag' => 'field', 'key' => 'evil']]], ['evil' => 'Tagged row'], [$evil]);
    assert_true(str_contains($row, 'Tagged row') && !str_contains($row, 'Own'));
    $applied = DocumentOperationApplier::apply(['containers' => [$container]], [DocumentOperation::fromArray(['op' => 'update_block_dynamic', 'payload' => ['block_id' => $container['id'], 'dynamic' => ['@link' => ['tag' => 'post_url']]]])], BlockRegistry::withAllCoreBlocks());
    assert_eq(['@link' => ['tag' => 'post_url']], $applied['containers'][0]['dynamic']);
    $definition = BlockRegistry::withAllCoreBlocks()->get('core.icon_list');
    assert_true(DynamicTags::fieldAt($definition, 'items.0.text') !== null && DynamicTags::fieldAt($definition, 'items.0.nope') === null);
    assert_true(DynamicTags::fieldAt(BlockRegistry::withAllCoreBlocks()->get('core.heading'), 'highlight') === null, 'a word to match is no place for a tag');
});

unit('reading: feed items are escaped, and the settings stay inside their limits', function (): void {
    $source = (string) file_get_contents(SLATE_ROOT . '/src/Module/StudioBuilder/Runtime/StudioFeed.php');
    assert_true(str_contains($source, 'ENT_XML1') && str_contains($source, 'RichTextSanitizer::sanitize'));
    assert_true(in_array('feed', \Slate\Module\StudioBuilder\Runtime\StudioReservedRoutes::RESERVED, true), 'no page can sit on the feed address');
    $screen = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/reading.php');
    assert_true(str_contains($screen, 'csrf_verify()') && str_contains($screen, 'studio-builder.publish') && str_contains($screen, 'updatePageAddress'));
});

unit('site mode: the prebuilt templates are valid documents, branded by tags and without site chrome', function (): void {
    foreach ([SiteMode::COMING_SOON, SiteMode::MAINTENANCE] as $mode) {
        $document = DocumentNormalizer::validateAndNormalize(SiteModeTemplates::document($mode), ModuleBlockDefinitions::studioRegistry());
        assert_eq('maintenance', $document['settings']['template_type']);
        assert_eq('hidden', $document['settings']['header_mode']);
        assert_eq('site_logo', $document['containers'][0]['children'][0]['dynamic']['media']['tag']);
    }
});

unit('heading: a line break in the title, escaped otherwise; the dual color heading colours its middle part', function (): void {
    $html = sbrf_render(sbrf_block('core.heading', ['text' => "Where Fine Cuisine<br>Meets\nEvery <b>", 'level' => 'h2']), [], []);
    assert_true(str_contains($html, 'Cuisine<br>Meets<br>') && str_contains($html, '&lt;b&gt;') && !str_contains($html, '<b>'));
    $dual = sbrf_render(sbrf_block('core.dual_heading', ['before' => 'Fine', 'colored' => 'Cuisine <i>', 'after' => '', 'level' => 'h1', 'url' => '/x/']), [], []);
    assert_true(str_contains($dual, '<h1 class="sb-heading sb-dual-heading">') && str_contains($dual, 'sb-dual-heading__highlight">Cuisine &lt;i&gt;') && str_contains($dual, 'href="/x/"'));
    assert_eq('', sbrf_render(sbrf_block('core.dual_heading', ['before' => '', 'colored' => '', 'after' => '']), [], []));
});

unit('dynamic tags: the validator refuses a bad map, a required setting can be left to its tag, and older props become tags', function (): void {
    assert_eq([], DynamicTags::issues(['text' => ['tag' => 'post_title', 'before' => 'A'], '@background' => ['tag' => 'field', 'key' => 'hero']]));
    assert_true(DynamicTags::issues(['text' => ['tag' => 'nope']]) !== []);
    assert_true(DynamicTags::issues(['text' => ['tag' => 'field']]) !== [], 'a field tag needs its field');
    assert_true(DynamicTags::issues(['__proto' => ['tag' => 'post_title']]) !== []);
    assert_true(DynamicTags::issues(['text' => ['tag' => 'post_title', 'onclick' => 'x']]) !== []);
    assert_true(DynamicTags::issues(['text' => ['tag' => 'post_date', 'format' => '<b>']]) !== []);

    $document = CanonicalDocumentSchema::emptyDocument();
    $image = sbrf_block('core.image', []) + ['dynamic' => ['media' => ['tag' => 'featured_image']]];
    $heading = sbrf_block('core.heading', ['text' => 'x', 'level' => 'h2', 'source' => 'post_title', 'link_source' => 'field', 'link_field' => 'menu']);
    $document['containers'] = [array_replace(CanonicalDocumentSchema::newContainer(), ['children' => [$image, $heading]])];
    $normal = DocumentNormalizer::validateAndNormalize($document, BlockRegistry::withAllCoreBlocks());
    $kept = $normal['containers'][0]['children'][1];
    assert_eq(['text' => ['tag' => 'post_title'], 'url' => ['key' => 'menu', 'tag' => 'field']], $kept['dynamic']);
    assert_true(!array_key_exists('source', $kept['props']));
});

