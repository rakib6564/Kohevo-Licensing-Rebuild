<?php
/**
 * The frozen content-document contract — ADR-0013.
 *
 * DocumentSchemaTest covers how the normalizer behaves. This covers what the
 * envelope *is*, and exists to fail when its shape changes.
 *
 * Renderers, the content API, the builder and the revision store are all being
 * written against this shape in parallel. A field quietly added or renamed here
 * would not break any single one of them at the point of change — it would
 * surface later as a renderer disagreeing with a fixture, or a revision diff
 * that never converges. So the shape is asserted literally, key by key.
 *
 * If a test here fails, that is the freeze working. Either the change was
 * unintended, or ADR-0013 needs amending first and the version bumping with it.
 *
 * Pure: no database, no rendering.
 */

declare(strict_types=1);

use Slate\Presentation\DocumentSchema;

/** Load a fixture document as its raw JSON string. */
function _doc_fixture(string $name): string
{
    $path = dirname(__DIR__) . '/fixtures/documents/' . $name . '.json';
    $raw  = is_file($path) ? (string) file_get_contents($path) : '';
    return $raw;
}

unit('the document schema version is pinned at 1', function (): void {
    // A bump is a deliberate act that invalidates stored envelopes and every
    // fixture built against them. It should never happen as a side effect.
    assert_eq(1, DocumentSchema::VERSION, 'ADR-0013 freezes the envelope at version 1');
});

unit('the envelope has exactly the five contracted top-level keys', function (): void {
    $env = DocumentSchema::normalize(_doc_fixture('envelope-basic'), 'page');

    $keys = array_keys($env);
    sort($keys);
    assert_eq(
        ['schema', 'sections', 'seo', 'template', 'type'],
        $keys,
        'top-level envelope keys are frozen — see ADR-0013'
    );

    assert_eq(1, $env['schema'], 'the schema version is stamped on output');
    assert_true(is_array($env['sections']), 'sections is a list');
    assert_true(is_array($env['seo']), 'seo is a map');
});

unit('a section has exactly the three contracted keys', function (): void {
    $env = DocumentSchema::normalize(_doc_fixture('envelope-basic'), 'page');
    $section = $env['sections'][0] ?? [];

    $keys = array_keys($section);
    sort($keys);
    assert_eq(['blocks', 'id', 'layout'], $keys, 'section keys are frozen');
    assert_eq('s1', $section['id'], 'section ids are positional, not random');
});

unit('a block carries type and props, and props stay data', function (): void {
    $env   = DocumentSchema::normalize(_doc_fixture('envelope-basic'), 'page');
    $block = $env['sections'][0]['blocks'][0] ?? [];

    assert_true(isset($block['type']), 'every block declares a type');
    assert_true(is_array($block['props'] ?? null), 'props is a map, never a markup string');

    // The property the second renderer depends on: props are structured data, so
    // a React implementation can consume them without parsing HTML.
    assert_eq('hero', $block['type'], 'the fixture hero survives normalization');
    assert_eq('One document', $block['props']['heading'] ?? '', 'props pass through verbatim');
});

unit('addressing never leaks into the document', function (): void {
    // ADR-0013 point 2: site/route/locale/slug live on the row. If they ever
    // appear inside the envelope there are two sources of truth for a page's
    // identity, and normalization stops being a pure function of content.
    $env  = DocumentSchema::normalize(_doc_fixture('envelope-basic'), 'page');
    $flat = json_encode($env);

    foreach (['route', 'locale', 'site_id', 'slug', 'public_id'] as $addressing) {
        assert_false(
            str_contains((string) $flat, '"' . $addressing . '"'),
            "'{$addressing}' is addressing and belongs on the row, not in the document"
        );
    }
});

unit('legacy flat layouts upconvert without being rewritten', function (): void {
    // Every stored row today is the flat shape. Reading must be a pure
    // transform: no migration, no row touched.
    $env = DocumentSchema::normalize(_doc_fixture('legacy-flat'), 'page');

    assert_eq(1, count($env['sections']), 'a flat array becomes one implicit section');
    assert_eq(3, count($env['sections'][0]['blocks']), 'all three blocks survive');
    assert_eq('heading', $env['sections'][0]['blocks'][0]['type'] ?? '', 'order is preserved');
});

unit('normalization is byte-stable across repeats', function (): void {
    // Revision diffing and render caching both assume re-normalizing an already
    // normalized document yields identical bytes. Asserted on every fixture,
    // including the escape hatches, because those are the shapes most likely to
    // acquire non-deterministic handling later.
    foreach (['legacy-flat', 'envelope-basic', 'escape-hatches', 'media-keys'] as $name) {
        $once  = DocumentSchema::normalize(_doc_fixture($name), 'page');
        $twice = DocumentSchema::normalize(json_encode($once), 'page');
        assert_eq(
            json_encode($once),
            json_encode($twice),
            "re-normalizing {$name} must produce identical bytes"
        );
    }
});

unit('image blocks reference media by logical key, with raw URLs deprecated', function (): void {
    // ADR-0013 point 4 — the one real shape commitment in the contract. An image
    // block stores a stable key that the renderer resolves, because URLs move
    // (a CDN changes, the install moves under a sub-path, a React host serves
    // assets the PHP renderer never sees) and a key does not. That is what lets
    // one document render correctly under two renderers on two hosts.
    $env    = DocumentSchema::normalize(_doc_fixture('media-keys'), 'page');
    $blocks = $env['sections'][0]['blocks'] ?? [];

    assert_eq(2, count($blocks), 'both the keyed and the legacy form survive normalization');

    $media = $blocks[0]['props']['media'] ?? null;
    assert_true(is_array($media), 'the keyed form carries a media map, not a string');
    assert_eq('hero.primary', $media['key'] ?? '', 'the logical key is preserved verbatim');
    assert_true(($media['alt'] ?? '') !== '', 'alt text travels with the reference');
    assert_true(is_array($media['focal'] ?? null), 'the focal point folds in from react-site-bridge');

    // Deprecated, not removed: existing documents still read, and a document
    // mixing both forms must normalize without either being dropped.
    assert_eq(
        '/uploads/legacy/old-hero.jpg',
        $blocks[1]['props']['src'] ?? '',
        'a raw-URL image block is still read for backward compatibility'
    );
});

unit('both escape hatches survive normalization intact', function (): void {
    $env    = DocumentSchema::normalize(_doc_fixture('escape-hatches'), 'page');
    $blocks = $env['sections'][0]['blocks'] ?? [];
    $types  = array_column($blocks, 'type');

    assert_true(in_array('html', $types, true), 'the raw-HTML block is preserved');
    assert_true(in_array('react', $types, true), 'the React-component block is preserved');

    // The React block names a component and carries a server-side fallback, so
    // the PHP renderer has something to emit rather than a hole in the page.
    $react = $blocks[array_search('react', $types, true)] ?? [];
    assert_eq('PricingTable', $react['props']['component'] ?? '', 'the component is named');
    assert_true(($react['props']['fallback'] ?? '') !== '', 'a server-side fallback is declared');
});
