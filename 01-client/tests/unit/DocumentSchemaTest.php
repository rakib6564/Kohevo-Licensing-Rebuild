<?php
/**
 * Unit tests for Phase 3A B2 — DocumentSchema (the BC bridge / normalizer).
 *
 * The legacy flat block array (every existing contentbuilder_posts.layout) must
 * upconvert to one implicit Section without rewriting stored data, the new
 * envelope shape must parse faithfully, and normalization must be idempotent +
 * deterministic. docs/05-Rendering/blocks-and-sections.md §4.
 */

declare(strict_types=1);

use Slate\Presentation\DocumentSchema;
use Slate\Presentation\Page;
use Slate\Presentation\Section;
use Slate\Presentation\LayoutSpec;

// A realistic legacy layout (the shape the seeded Home page + demo pages store).
$LEGACY_JSON = json_encode([
    ['type' => 'heading',   'props' => ['text' => 'Welcome', 'level' => '1']],
    ['type' => 'paragraph', 'props' => ['text' => 'This is your new home page.']],
]);

// ── Legacy flat array → single implicit Section ───────────
unit('legacy flat array upconverts to ONE implicit section (default layout)', function () use ($LEGACY_JSON) {
    $page = DocumentSchema::toPage($LEGACY_JSON, 'page');
    assert_true($page instanceof Page);
    assert_eq(1, count($page->sections));
    $sec = $page->sections[0];
    assert_eq('s1', $sec->id);
    assert_eq(LayoutSpec::default()->toArray(), $sec->layout->toArray());
    assert_eq(2, count($sec->blocks));
    assert_eq('heading', $sec->blocks[0]['type']);
    assert_eq('Welcome', $sec->blocks[0]['props']['text']);
});

unit('DocumentSchema::isLegacyFlat detects list vs envelope', function () use ($LEGACY_JSON) {
    assert_true(DocumentSchema::isLegacyFlat($LEGACY_JSON));
    assert_false(DocumentSchema::isLegacyFlat(['schema' => 1, 'sections' => []]));
});

unit('empty / null / malformed layout yields a page with zero sections', function () {
    assert_eq(0, count(DocumentSchema::toPage(null)->sections));
    assert_eq(0, count(DocumentSchema::toPage('')->sections));
    assert_eq(0, count(DocumentSchema::toPage('not json')->sections));
    assert_eq(0, count(DocumentSchema::toPage([])->sections));
});

unit('legacy normalization drops malformed block entries', function () {
    $env = DocumentSchema::normalize([
        ['type' => 'hero', 'props' => ['h' => 1]],
        ['noType' => true],
        'garbage',
        ['type' => ''],            // empty type dropped
    ]);
    assert_eq(1, count($env['sections']));
    assert_eq(1, count($env['sections'][0]['blocks']));
    assert_eq('hero', $env['sections'][0]['blocks'][0]['type']);
});

// ── Document envelope shape ───────────────────────────────
unit('envelope shape parses sections, layout, type, template, seo', function () {
    $env = [
        'schema'   => 1,
        'type'     => 'landing',
        'template' => 'wide',
        'sections' => [
            ['id' => 'a', 'layout' => ['cols' => 2, 'bg' => 'tint'],
             'blocks' => [['type' => 'hero', 'props' => []]]],
            ['layout' => [], 'blocks' => [['type' => 'cta', 'props' => []]]],  // no id → positional
        ],
        'seo' => ['title' => 'Hello'],
    ];
    $page = DocumentSchema::toPage($env, 'page');
    assert_eq('landing', $page->type);          // envelope type wins over the row type
    assert_eq('wide', $page->template);
    assert_eq('Hello', $page->seo['title']);
    assert_eq(2, count($page->sections));
    assert_eq('a', $page->sections[0]->id);
    assert_eq(2, $page->sections[0]->layout->cols);
    assert_eq('s2', $page->sections[1]->id);    // filled positionally
});

unit('envelope with sections key but empty list → zero sections, schema stamped', function () {
    $env = DocumentSchema::normalize(['schema' => 1, 'sections' => []], 'page');
    assert_eq(DocumentSchema::VERSION, $env['schema']);
    assert_eq(0, count($env['sections']));
});

// ── Idempotency + round-trip ──────────────────────────────
unit('normalize is idempotent (re-normalizing yields identical output)', function () use ($LEGACY_JSON) {
    $once  = DocumentSchema::normalize($LEGACY_JSON, 'page');
    $twice = DocumentSchema::normalize($once, 'page');
    assert_eq($once, $twice);
    // And stable when fed back as JSON.
    $thrice = DocumentSchema::normalize(json_encode($once), 'page');
    assert_eq($once, $thrice);
});

unit('fromPage(toPage(x)) round-trips a legacy layout to a stable envelope', function () use ($LEGACY_JSON) {
    $page = DocumentSchema::toPage($LEGACY_JSON, 'page');
    $env  = DocumentSchema::fromPage($page);
    assert_eq(DocumentSchema::VERSION, $env['schema']);
    assert_eq('page', $env['type']);
    assert_eq(1, count($env['sections']));
    assert_eq('s1', $env['sections'][0]['id']);
    // Re-parsing the envelope gives an equivalent page.
    $page2 = DocumentSchema::toPage($env, 'page');
    assert_eq($page->toArray(), $page2->toArray());
});

unit('savedAs on a section survives normalization', function () {
    $env = DocumentSchema::normalize([
        'sections' => [['id' => 'x', 'savedAs' => 'testimonials', 'blocks' => []]],
    ], 'page');
    assert_eq('testimonials', $env['sections'][0]['savedAs']);
});
