<?php
/**
 * Kohevo Studio — the meta field type catalogue: a stored field never carries a type or an option it does not know.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Meta\MetaFieldTypes;

unit('meta field types: a rich catalogue, grouped, and every type names a known group and only known options', function (): void {
    $all = MetaFieldTypes::all();
    assert_true(count($all) >= 25, 'many more kinds than the original ten');
    foreach (['text', 'textarea', 'number', 'image', 'gallery', 'select', 'relation', 'date', 'boolean', 'url'] as $original) {
        assert_true(isset($all[$original]), "the original type stays: {$original}");
    }
    foreach (['richtext', 'email', 'phone', 'price', 'rating', 'multiselect', 'file', 'video', 'time', 'datetime', 'daterange', 'relations', 'color', 'location', 'list', 'keyvalue'] as $new) {
        assert_true(isset($all[$new]), "a new type: {$new}");
    }
    foreach ($all as $key => $def) {
        assert_true(isset(MetaFieldTypes::GROUPS[$def['group']]), "{$key} has a known group");
        assert_true($def['label'] !== '' && $def['hint'] !== '', "{$key} has a label and a hint");
        foreach ($def['options'] as $opt) {
            assert_true(in_array($opt, ['choices', 'min', 'max', 'step', 'max_length', 'target', 'extensions'], true), "{$key}: known option {$opt}");
        }
    }
});

unit('meta field types: an unknown type becomes text, a known one is kept, case is ignored', function (): void {
    assert_eq('text', MetaFieldTypes::normalize('<script>'));
    assert_eq('text', MetaFieldTypes::normalize(''));
    assert_eq('rating', MetaFieldTypes::normalize(' Rating '));
    assert_eq('Rating', MetaFieldTypes::label('rating'));
    assert_eq('gone', MetaFieldTypes::label('gone'), 'a type the catalogue dropped shows its key');
});

unit('meta field types: options are cleaned to what the type takes', function (): void {
    $pt = ['portfolio', 'services'];
    // choices: value or "value : Label", blanks and repeats dropped
    $o = MetaFieldTypes::options('select', ['choices' => "remote : Remote\n\nonsite\nremote : Again\nhybrid: Hybrid mode", 'min' => '5'], $pt);
    assert_eq([['value' => 'remote', 'label' => 'Remote'], ['value' => 'onsite', 'label' => 'onsite'], ['value' => 'hybrid', 'label' => 'Hybrid mode']], $o['choices']);
    assert_true(!isset($o['min']), 'a select takes no minimum');
    assert_eq(50, count(MetaFieldTypes::options('radio', ['choices' => implode("\n", range(1, 80))], $pt)['choices']), 'at most 50 choices');

    // numbers: numeric only, min/max swapped when reversed, a non-positive step dropped
    $n = MetaFieldTypes::options('number', ['min' => '10', 'max' => '2', 'step' => '0.5'], $pt);
    assert_eq(2, $n['min']); assert_eq(10, $n['max']); assert_eq(0.5, $n['step']);
    assert_true(!isset(MetaFieldTypes::options('number', ['step' => '-1'], $pt)['step']), 'a step must be positive');
    assert_eq([], MetaFieldTypes::options('number', ['min' => 'abc', 'max' => ''], $pt));

    // length, target, extensions
    assert_eq(['max_length' => 160], MetaFieldTypes::options('text', ['max_length' => '160'], $pt));
    assert_eq([], MetaFieldTypes::options('text', ['max_length' => '0'], $pt));
    assert_eq(['target' => 'services'], MetaFieldTypes::options('relation', ['target' => 'Services'], $pt));
    assert_eq([], MetaFieldTypes::options('relation', ['target' => 'nope'], $pt), 'a relation can only link to a post type that exists');
    assert_eq(['extensions' => ['pdf', 'docx']], MetaFieldTypes::options('file', ['extensions' => '.PDF, docx; <b>, pdf'], $pt));

    // a type with no options takes none
    assert_eq([], MetaFieldTypes::options('boolean', ['choices' => 'a', 'min' => '1', 'target' => 'portfolio'], $pt));
    assert_eq([], MetaFieldTypes::options('not-a-type', ['min' => '1'], $pt));
});

unit('meta field types: the list shows a one-line summary of the options', function (): void {
    assert_eq('3 choices', MetaFieldTypes::summary(['options' => ['choices' => [1, 2, 3]]]));
    assert_eq('0–100 · step 5', MetaFieldTypes::summary(['options' => ['min' => 0, 'max' => 100, 'step' => 5]]));
    assert_eq('→ portfolio', MetaFieldTypes::summary(['options' => ['target' => 'portfolio']]));
    assert_eq('', MetaFieldTypes::summary(['type' => 'text']));
});
