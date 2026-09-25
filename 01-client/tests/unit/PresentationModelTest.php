<?php
/**
 * Unit tests for the Phase 3A B1 Presentation model (no DB).
 *
 * Covers the content-spine value objects (FieldSchema, LayoutSpec, Section,
 * Page, RenderContext) and confirms the Block/BlockRegistry/Renderer contracts
 * are satisfiable — ADR-0007, docs/05-Rendering/blocks-and-sections.md.
 */

declare(strict_types=1);

use Slate\Presentation\FieldSchema;
use Slate\Presentation\LayoutSpec;
use Slate\Presentation\Section;
use Slate\Presentation\Page;
use Slate\Presentation\RenderContext;
use Slate\Presentation\Block;

// ── FieldSchema ───────────────────────────────────────────
unit('FieldSchema::normalize fills defaults, provided value wins', function () {
    $s = FieldSchema::of(
        [['key' => 'heading', 'type' => 'text'], ['key' => 'sub', 'type' => 'text']],
        ['heading' => 'Default', 'sub' => 'Sub']
    );
    $out = $s->normalize(['heading' => 'Custom']);
    assert_eq('Custom', $out['heading']);
    assert_eq('Sub', $out['sub']);            // default filled in
});

unit('FieldSchema::normalize keeps falsy provided values (does not treat as absent)', function () {
    $s = FieldSchema::of([], ['show' => true, 'count' => 5]);
    $out = $s->normalize(['show' => false, 'count' => 0]);
    assert_false($out['show']);
    assert_eq(0, $out['count']);
});

unit('FieldSchema::normalize preserves unknown keys (forward-compatible)', function () {
    $s = FieldSchema::of([], ['a' => 1]);
    $out = $s->normalize(['a' => 2, 'b' => 9]);
    assert_eq(2, $out['a']);
    assert_eq(9, $out['b']);
});

unit('FieldSchema::keys unions default + field keys', function () {
    $s = FieldSchema::of([['key' => 'x'], ['key' => 'y']], ['x' => '', 'z' => '']);
    $keys = $s->keys();
    foreach (['x', 'z', 'y'] as $k) assert_true(in_array($k, $keys, true), "missing key $k");
});

unit('FieldSchema round-trips through toArray/fromArray', function () {
    $s = FieldSchema::of([['key' => 'q', 'type' => 'text']], ['q' => 'hi']);
    $r = FieldSchema::fromArray($s->toArray());
    assert_eq($s->defaults(), $r->defaults());
    assert_eq($s->fields(), $r->fields());
});

// ── LayoutSpec ────────────────────────────────────────────
unit('LayoutSpec::default is single-column, unskinned', function () {
    $l = LayoutSpec::default();
    assert_eq(1, $l->cols);
    assert_eq('', $l->bg);
    assert_eq('normal', $l->pad);
    assert_eq('normal', $l->width);
});

unit('LayoutSpec::fromArray coerces cols to >= 1 and ignores unknown keys', function () {
    $l = LayoutSpec::fromArray(['cols' => '3', 'bg' => 'surface', 'nope' => 'x']);
    assert_eq(3, $l->cols);
    assert_eq('surface', $l->bg);
    $l0 = LayoutSpec::fromArray(['cols' => 0]);
    assert_eq(1, $l0->cols);
});

// ── Section ───────────────────────────────────────────────
unit('Section::fromArray normalizes blocks and drops malformed entries', function () {
    $s = Section::fromArray([
        'id' => 's1',
        'layout' => ['cols' => 2, 'bg' => 'tint'],
        'blocks' => [
            ['type' => 'hero', 'props' => ['heading' => 'Hi']],
            ['noType' => 'x'],                 // dropped — no 'type'
            'garbage',                         // dropped — not an array
            ['type' => 'cta'],                 // props defaults to []
        ],
    ]);
    assert_eq('s1', $s->id);
    assert_eq(2, $s->layout->cols);
    assert_eq(2, count($s->blocks));
    assert_eq('hero', $s->blocks[0]['type']);
    assert_eq([], $s->blocks[1]['props']);
});

unit('Section::withId returns a new section with the id replaced', function () {
    $s = Section::fromArray(['id' => '', 'blocks' => []]);
    $s2 = $s->withId('s_new');
    assert_eq('', $s->id);                     // original unchanged (immutable)
    assert_eq('s_new', $s2->id);
    assert_true($s2->isEmpty());
});

unit('Section::toArray omits savedAs when null, includes it when set', function () {
    $plain = Section::fromArray(['id' => 'a', 'blocks' => []])->toArray();
    assert_false(array_key_exists('savedAs', $plain));
    $saved = Section::fromArray(['id' => 'a', 'savedAs' => 'testimonials', 'blocks' => []])->toArray();
    assert_eq('testimonials', $saved['savedAs']);
});

// ── Page ──────────────────────────────────────────────────
unit('Page::of holds ordered sections and round-trips through toArray', function () {
    $s1 = Section::fromArray(['id' => 's1', 'blocks' => [['type' => 'hero', 'props' => []]]]);
    $s2 = Section::fromArray(['id' => 's2', 'blocks' => []]);
    $p = Page::of('page', [$s1, $s2], 'landing', ['title' => 'T']);
    assert_eq('page', $p->type);
    assert_eq('landing', $p->template);
    assert_eq(2, count($p->sections));
    $arr = $p->toArray();
    assert_eq('s1', $arr['sections'][0]['id']);
    assert_eq('T', $arr['seo']['title']);
    assert_false($p->isEmpty());
});

// ── RenderContext ─────────────────────────────────────────
unit('RenderContext defaults + immutable withers', function () {
    $ctx = RenderContext::for(7);
    assert_eq(7, $ctx->tenantId);
    assert_eq(RenderContext::SURFACE_PAGE, $ctx->surface);
    assert_false($ctx->isPreview());

    $pv = $ctx->withPreview();
    assert_false($ctx->isPreview());           // original untouched
    assert_true($pv->isPreview());

    $fr = $ctx->withSurface(RenderContext::SURFACE_FRAGMENT);
    assert_true($fr->isFragment());
    assert_eq(7, $fr->tenantId);
});

// ── Contract conformance ──────────────────────────────────
unit('a minimal Block implementation satisfies the contract', function () {
    $block = new class implements Block {
        public function type(): string { return 'demo'; }
        public function schema(): FieldSchema { return FieldSchema::of([], ['text' => 'hi']); }
        public function render(array $props, RenderContext $ctx): string {
            return '<p>' . htmlspecialchars($props['text'] ?? '') . '</p>';
        }
    };
    assert_true($block instanceof Block);
    assert_eq('demo', $block->type());
    $props = $block->schema()->normalize([]);
    assert_eq('<p>hi</p>', $block->render($props, RenderContext::for(1)));
});
