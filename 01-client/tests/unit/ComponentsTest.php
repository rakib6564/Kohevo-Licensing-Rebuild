<?php
/**
 * Unit tests for Phase 3B B4 — the slate_c_* Component starter set.
 *
 * Components are pure render functions: escaped text, slots passed through,
 * variant classes, and CSS that reads only --slate-* semantics
 * (docs/04-Design-System/component-library.md §3).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/components/load.php';

// ── Button ────────────────────────────────────────────────
unit('slate_c_button renders a link when href is set, else a button', function () {
    $link = slate_c_button(['label' => 'Go', 'href' => '/p/x']);
    assert_true(str_starts_with($link, '<a class="slate-btn slate-btn--accent slate-btn--md" href="/p/x"'));
    assert_true(str_contains($link, '>Go</a>'));

    $btn = slate_c_button(['label' => 'Save']);
    assert_true(str_starts_with($btn, '<button class="slate-btn slate-btn--accent slate-btn--md" type="button"'));
});

unit('slate_c_button applies tone/size and falls back on invalid tone', function () {
    $b = slate_c_button(['label' => 'x', 'tone' => 'neutral', 'size' => 'lg']);
    assert_true(str_contains($b, 'slate-btn--neutral slate-btn--lg'));
    $bad = slate_c_button(['label' => 'x', 'tone' => 'danger']);   // not allowed → accent
    assert_true(str_contains($bad, 'slate-btn--accent'));
});

unit('slate_c_button escapes its label and marks disabled', function () {
    $b = slate_c_button(['label' => '<script>', 'disabled' => true]);
    assert_true(str_contains($b, '&lt;script&gt;'), 'label escaped');
    assert_false(str_contains($b, '<script>'), 'no raw script');
    assert_true(str_contains($b, 'disabled aria-disabled="true"'));
});

// ── Card ──────────────────────────────────────────────────
unit('slate_c_card escapes the title but passes the body slot through', function () {
    $c = slate_c_card(['title' => 'A & B', 'body' => '<p>raw <b>child</b></p>', 'tone' => 'sunken']);
    assert_true(str_contains($c, 'slate-card--sunken'));
    assert_true(str_contains($c, '<h3 class="slate-card__title">A &amp; B</h3>'), 'title escaped');
    assert_true(str_contains($c, '<div class="slate-card__body"><p>raw <b>child</b></p></div>'), 'body passed through');
});

unit('slate_c_card omits the heading when no title', function () {
    $c = slate_c_card(['body' => 'x']);
    assert_false(str_contains($c, 'slate-card__title'));
});

// ── Grid ──────────────────────────────────────────────────
unit('slate_c_grid wraps items in cells and clamps cols to 1..4', function () {
    $g = slate_c_grid(['items' => ['<span>a</span>', '<span>b</span>'], 'cols' => 9]);
    assert_true(str_contains($g, 'slate-grid slate-grid--cols-4'), 'cols clamped to 4');
    assert_true(str_contains($g, '<div class="slate-grid__cell"><span>a</span></div>'));
    assert_true(str_contains($g, '<div class="slate-grid__cell"><span>b</span></div>'));

    $g1 = slate_c_grid(['items' => [], 'cols' => 0]);
    assert_true(str_contains($g1, 'slate-grid--cols-1'), 'cols clamped to 1');
});

// ── Media ─────────────────────────────────────────────────
unit('slate_c_media renders a lazy, escaped, alt-bearing image; empty src → empty', function () {
    $m = slate_c_media(['src' => '/u/a.jpg?"x', 'alt' => 'A "cat"', 'ratio' => '16-9']);
    assert_true(str_contains($m, 'class="slate-media slate-media--16-9"'));
    assert_true(str_contains($m, 'src="/u/a.jpg?&quot;x"'), 'src escaped');
    assert_true(str_contains($m, 'alt="A &quot;cat&quot;"'), 'alt escaped');
    assert_true(str_contains($m, 'loading="lazy"'));
    assert_eq('', slate_c_media(['src' => '']));
});

// ── Attribute passthrough ────────────────────────────────
unit('slate_c_attrs escapes values and drops bad names', function () {
    $b = slate_c_button(['label' => 'x', 'attrs' => ['data-id' => '7"', 'onclick' => 'x', 'bad name' => 'y']]);
    assert_true(str_contains($b, 'data-id="7&quot;"'));
    assert_false(str_contains($b, 'bad name'), 'invalid attribute name dropped');
});

// ── CSS token purity ─────────────────────────────────────
unit('components.css reads --slate- tokens and hardcodes no hex palette', function () {
    $css = file_get_contents(__DIR__ . '/../../includes/components/components.css');
    assert_true(str_contains($css, 'var(--slate-color-accent)'), 'uses semantic accent token');
    assert_false((bool) preg_match('/#[0-9a-fA-F]{3,6}\b/', $css), 'no hardcoded hex colors');
});
