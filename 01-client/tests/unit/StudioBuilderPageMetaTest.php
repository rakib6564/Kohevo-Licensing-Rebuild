<?php
/**
 * Kohevo Studio — a page's custom field values: each is cleaned to what its field type and options allow.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Meta\PageMeta;

$field = static fn(string $type, array $extra = []): array => array_merge(['key' => 'f', 'label' => 'F', 'type' => $type, 'options' => []], $extra);

unit('page meta: text is trimmed and cut to max_length, and a required empty one is refused', function () use ($field): void {
    assert_eq('abc', PageMeta::clean($field('text', ['options' => ['max_length' => 3]]), '  abcdef ')['value']);
    assert_true(PageMeta::clean($field('text', ['required' => true]), '   ')['error'] !== null, 'required and empty');
    assert_eq('', PageMeta::clean($field('text'), '')['value']);
    assert_eq('xhi', PageMeta::clean($field('richtext'), '<b onclick=1>x</b>hi')['value'], 'tags stripped');
});

unit('page meta: email, url, colour and date are validated', function () use ($field): void {
    assert_true(PageMeta::clean($field('email'), 'nope')['error'] !== null);
    assert_eq('a@b.co', PageMeta::clean($field('email'), 'a@b.co')['value']);
    assert_true(PageMeta::clean($field('url'), 'javascript:alert(1)')['error'] !== null, 'no javascript: urls');
    assert_true(PageMeta::clean($field('url'), '//evil.example')['error'] !== null, 'no protocol-relative urls');
    assert_eq('/media/a.png', PageMeta::clean($field('image'), '/media/a.png')['value']);
    assert_eq('#aabbcc', PageMeta::clean($field('color'), '#AABBCC')['value']);
    assert_true(PageMeta::clean($field('color'), 'red')['error'] !== null);
    assert_true(PageMeta::clean($field('date'), '2026-02-30')['error'] !== null, 'impossible date');
    assert_eq('2026-02-28', PageMeta::clean($field('date'), '2026-02-28')['value']);
});

unit('page meta: numbers keep their range, choices keep their options', function () use ($field): void {
    $n = $field('number', ['options' => ['min' => 1, 'max' => 10]]);
    assert_eq(5, PageMeta::clean($n, '5')['value']);
    assert_true(PageMeta::clean($n, '11')['error'] !== null);
    assert_true(PageMeta::clean($n, 'abc')['error'] !== null);
    assert_true(PageMeta::clean($field('rating'), '6')['error'] !== null, 'rating tops out at 5');
    $c = ['choices' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]];
    assert_eq('a', PageMeta::clean($field('select', ['options' => $c]), 'a')['value']);
    assert_true(PageMeta::clean($field('select', ['options' => $c]), 'z')['error'] !== null);
    assert_eq(['a', 'b'], PageMeta::clean($field('checkboxes', ['options' => $c]), ['a', 'b'])['value']);
    assert_true(PageMeta::clean($field('multiselect', ['options' => $c]), ['a', 'z'])['error'] !== null);
});

unit('page meta: toggles, ranges, lists and pairs take their own shape', function () use ($field): void {
    assert_eq(true, PageMeta::clean($field('boolean'), '1')['value']);
    assert_eq(false, PageMeta::clean($field('boolean'), null)['value']);
    assert_eq(['start' => '2026-01-01', 'end' => '2026-01-05'], PageMeta::clean($field('daterange'), ['start' => '2026-01-01', 'end' => '2026-01-05'])['value']);
    assert_true(PageMeta::clean($field('daterange'), ['start' => '2026-02-01', 'end' => '2026-01-05'])['error'] !== null, 'end before start');
    assert_eq(['one', 'two'], PageMeta::clean($field('list'), "one\n\n two \n")['value']);
    assert_eq(['k' => 'v : w'], (array) PageMeta::clean($field('keyvalue'), "k : v : w\n:bad")['value']);
    assert_eq([3, 4], PageMeta::clean($field('relations'), ['3', 'x', '4', '3'])['value']);
});
