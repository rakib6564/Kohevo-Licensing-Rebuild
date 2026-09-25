<?php
/**
 * Unit tests for Phase 3A B3 — PageRenderer + InMemoryBlockRegistry + CallbackBlock.
 *
 * The one core traversal Page → Section → Block: default-layout sections render
 * transparently (legacy byte-parity), specified-layout sections get a wrapper,
 * unknown/throwing blocks fail soft. docs/05-Rendering/rendering-pipeline.md.
 */

declare(strict_types=1);

use Slate\Presentation\DocumentSchema;
use Slate\Presentation\FieldSchema;
use Slate\Presentation\Page;
use Slate\Presentation\RenderContext;
use Slate\Presentation\Section;
use Slate\Presentation\LayoutSpec;
use Slate\Presentation\Rendering\PageRenderer;
use Slate\Presentation\Rendering\InMemoryBlockRegistry;
use Slate\Presentation\Rendering\CallbackBlock;

/** A registry with a couple of simple blocks for the tests. */
function _rendererFixture(): PageRenderer {
    $reg = new InMemoryBlockRegistry();
    $reg->register(new CallbackBlock(
        'heading',
        FieldSchema::of([['key' => 'text'], ['key' => 'level']], ['text' => 'Untitled', 'level' => '2']),
        fn (array $p, RenderContext $c) => "<h{$p['level']}>" . htmlspecialchars($p['text']) . "</h{$p['level']}>"
    ));
    $reg->register(new CallbackBlock(
        'boom',
        FieldSchema::of([], []),
        function (array $p, RenderContext $c): string { throw new \RuntimeException('kaboom'); }
    ));
    return new PageRenderer($reg);
}

$CTX = RenderContext::for(1);

// ── renderBlock ───────────────────────────────────────────
unit('renderBlock renders a known block with normalized props', function () use ($CTX) {
    $r = _rendererFixture();
    assert_eq('<h1>Hi</h1>', $r->renderBlock(['type' => 'heading', 'props' => ['text' => 'Hi', 'level' => '1']], $CTX));
});

unit('renderBlock applies schema defaults for missing props', function () use ($CTX) {
    $r = _rendererFixture();
    // level defaults to '2', text defaults to 'Untitled'
    assert_eq('<h2>Untitled</h2>', $r->renderBlock(['type' => 'heading'], $CTX));
});

unit('renderBlock on an unknown type fails soft to a comment', function () use ($CTX) {
    $r = _rendererFixture();
    assert_eq('<!-- unknown block "nope" -->', $r->renderBlock(['type' => 'nope'], $CTX));
});

unit('renderBlock neutralizes a malicious type in the fail-soft comment', function () use ($CTX) {
    $r = _rendererFixture();
    $out = $r->renderBlock(['type' => 'a-->x'], $CTX);
    assert_true(strpos($out, '-->x') === false, 'comment must not be breakable');
    assert_true(strpos($out, 'unknown block') !== false);
});

unit('renderBlock catches a throwing block and fails soft', function () use ($CTX) {
    $r = _rendererFixture();
    assert_eq('<!-- block "boom" failed to render -->', $r->renderBlock(['type' => 'boom'], $CTX));
});

unit('renderBlock returns empty string for a typeless block', function () use ($CTX) {
    $r = _rendererFixture();
    assert_eq('', $r->renderBlock(['props' => []], $CTX));
});

// Regression: renderBlock used to defer to \Renderer::applyStyle(), a class
// that only ever existed in the archived content-builder plugin — so a
// block's `style` was validated and saved but never actually rendered
// anywhere outside the editor's own client-side canvas. See
// BlockStyleRenderer's own docblock and its dedicated test file for full
// per-property coverage; this just proves PageRenderer actually calls it.
unit('renderBlock with no style is unaffected (no wrapper)', function () use ($CTX) {
    $r = _rendererFixture();
    assert_eq('<h1>Hi</h1>', $r->renderBlock(['type' => 'heading', 'props' => ['text' => 'Hi', 'level' => '1'], 'style' => []], $CTX));
});

unit('renderBlock with a style wraps the block and applies real CSS', function () use ($CTX) {
    $r = _rendererFixture();
    $out = $r->renderBlock(['type' => 'heading', 'props' => ['text' => 'Hi', 'level' => '1'], 'style' => ['bgColor' => '#ff0000', 'hideMobile' => true]], $CTX);
    assert_true(str_contains($out, '<h1>Hi</h1>'), 'the block\'s own HTML must still be present');
    assert_true(str_contains($out, 'background-color:rgba(255,0,0,1)'), 'the style must actually be rendered as real CSS: ' . $out);
    assert_true(str_contains($out, 've-hide-mobile'), 'a visibility toggle must become a real class: ' . $out);
});

// ── renderSection ─────────────────────────────────────────
unit('a default-layout section renders TRANSPARENTLY (no wrapper)', function () use ($CTX) {
    $r = _rendererFixture();
    $sec = new Section('s1', LayoutSpec::default(), [
        ['type' => 'heading', 'props' => ['text' => 'A', 'level' => '2']],
        ['type' => 'heading', 'props' => ['text' => 'B', 'level' => '3']],
    ]);
    assert_eq('<h2>A</h2><h3>B</h3>', $r->renderSection($sec, $CTX));
});

unit('a specified-layout section is wrapped with escaped data attrs', function () use ($CTX) {
    $r = _rendererFixture();
    $sec = new Section('s1', new LayoutSpec(2, 'tint', 'spacious', 'wide'), [
        ['type' => 'heading', 'props' => ['text' => 'X', 'level' => '2']],
    ]);
    $out = $r->renderSection($sec, $CTX);
    assert_true(strpos($out, '<section class="slate-section" data-cols="2" data-pad="spacious" data-width="wide" data-bg="tint">') === 0, $out);
    assert_true(strpos($out, '<div class="slate-section__inner"><h2>X</h2></div></section>') !== false, $out);
});

// ── renderPage + legacy parity shape ──────────────────────
unit('renderPage concatenates its sections in order', function () use ($CTX) {
    $r = _rendererFixture();
    $page = new Page('page', '', [
        new Section('s1', LayoutSpec::default(), [['type' => 'heading', 'props' => ['text' => '1', 'level' => '2']]]),
        new Section('s2', LayoutSpec::default(), [['type' => 'heading', 'props' => ['text' => '2', 'level' => '2']]]),
    ]);
    assert_eq('<h2>1</h2><h2>2</h2>', $r->renderPage($page, $CTX));
});

unit('a legacy layout renders as a bare block concatenation (parity shape)', function () use ($CTX) {
    $r = _rendererFixture();
    // The exact shape an existing contentbuilder_posts.layout holds.
    $legacy = [
        ['type' => 'heading', 'props' => ['text' => 'Welcome', 'level' => '1']],
        ['type' => 'heading', 'props' => ['text' => 'Sub', 'level' => '2']],
    ];
    $page = DocumentSchema::toPage($legacy, 'page');
    // No <section> wrapper anywhere — byte-identical to concatenating the blocks.
    $out = $r->renderPage($page, $CTX);
    assert_eq('<h1>Welcome</h1><h2>Sub</h2>', $out);
    assert_true(strpos($out, '<section') === false, 'legacy content must not gain a section wrapper');
});
