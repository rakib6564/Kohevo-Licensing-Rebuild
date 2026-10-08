<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — B2-P3d declared inline text.
 *
 * Autoloader only, no database.
 *
 * The canvas edits text in place only where a block DECLARES which prop an element renders. The declaration is
 * checked when the block is registered, so an edit can never write a prop the schema does not have.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Registry\DeclarativeBlockDefinition;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

function sbit_def(array $inline, array $fields = []): DeclarativeBlockDefinition
{
    return new DeclarativeBlockDefinition(
        type: 'test.inline', version: 1, label: 'Inline', category: 'content', icon: 'x',
        schema: FieldSchema::define($fields ?: [
            ['key' => 'text', 'type' => 'string', 'label' => 'Text', 'required' => false, 'default' => '', 'max_length' => 100],
            ['key' => 'count', 'type' => 'number', 'label' => 'Count', 'required' => false, 'default' => 1],
            ['key' => 'link', 'type' => 'link', 'label' => 'Link', 'required' => false],
        ]),
        inlineText: $inline,
    );
}

unit('inline text: a declaration must name a string/text prop, or a link label, with a plain class selector', function (): void {
    $ok = sbit_def([['prop' => 'text', 'selector' => '.sb-x'], ['prop' => 'link.label', 'selector' => '.sb-x a']]);
    assert_eq(
        [['prop' => 'text', 'selector' => '.sb-x', 'multiline' => false], ['prop' => 'link.label', 'selector' => '.sb-x a', 'multiline' => false]],
        $ok->toEditorManifest()['inline_text']
    );
    foreach ([
        'a prop the schema does not have' => ['prop' => 'nope', 'selector' => '.sb-x'],
        'a number prop' => ['prop' => 'count', 'selector' => '.sb-x'],
        'a link href' => ['prop' => 'link.href', 'selector' => '.sb-x'],
        'a sub-path of a string' => ['prop' => 'text.label', 'selector' => '.sb-x'],
        'an id selector' => ['prop' => 'text', 'selector' => '#x'],
        'an attribute selector' => ['prop' => 'text', 'selector' => '.x[onclick]'],
        'a selector with a quote' => ['prop' => 'text', 'selector' => '.x"'],
        'an empty selector' => ['prop' => 'text', 'selector' => ''],
    ] as $why => $entry) {
        assert_throws(\InvalidArgumentException::class, static fn() => sbit_def([$entry]), $why);
    }
    assert_eq([], sbit_def([])->toEditorManifest()['inline_text'], 'declaring nothing is allowed');
});

unit('inline text: a text field is multiline, a string is not', function (): void {
    $def = sbit_def(
        [['prop' => 'body', 'selector' => '.sb-b'], ['prop' => 'title', 'selector' => '.sb-t']],
        [
            ['key' => 'body', 'type' => 'text', 'label' => 'Body', 'required' => false, 'default' => '', 'max_length' => 500],
            ['key' => 'title', 'type' => 'string', 'label' => 'Title', 'required' => false, 'default' => '', 'max_length' => 100],
        ]
    );
    assert_eq([true, false], array_column($def->toEditorManifest()['inline_text'], 'multiline'));
});

unit('inline text: the core blocks declare the elements their renderers write', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $declared = [];
    foreach (['core.hero', 'core.heading', 'core.button', 'core.text', 'core.quote', 'core.feature_list'] as $type) {
        $declared[$type] = array_column($registry->get($type)->toEditorManifest()['inline_text'], 'prop');
    }
    assert_eq(['heading', 'eyebrow', 'subheading'], $declared['core.hero']);
    assert_eq(['link.label'], $declared['core.button']);
    assert_eq([], $registry->get('core.image')->toEditorManifest()['inline_text'], 'an image has no text to edit in place');
    // Each declared selector's class is one the block's renderer actually writes.
    $renderers = dirname(__DIR__, 2) . '/src/Module/StudioBuilder/Render/Block/CoreRenderers/';
    $files = ['core.hero' => 'HeroRenderer', 'core.heading' => 'HeadingRenderer', 'core.button' => 'ButtonRenderer', 'core.text' => 'TextRenderer', 'core.quote' => 'QuoteRenderer', 'core.feature_list' => 'FeatureListRenderer'];
    foreach ($files as $type => $file) {
        $source = (string) file_get_contents($renderers . $file . '.php');
        foreach ($registry->get($type)->toEditorManifest()['inline_text'] as $entry) {
            preg_match('/^\.([a-z0-9_-]+)/i', $entry['selector'], $m);
            assert_true(str_contains($source, $m[1]), "{$type} renders the class {$m[1]}");
        }
    }
});
