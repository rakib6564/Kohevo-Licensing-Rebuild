<?php
/**
 * Phase 2 editor migration — the 5 ported blocks (LegacyBlockBridge) and the
 * ContentServiceFactory registry adapter. Pure rendering logic with no DB
 * reads/writes of its own, but placed in tests/integration (not tests/unit)
 * because the render closures use the global e()/slate_safe_url() helpers,
 * which only exist after config.php's full boot — tests/unit/run.php loads
 * only the PSR-4 autoloader (see tests/unit/run.php's own docblock), so a
 * genuinely no-DB test still needs the integration harness here.
 */

declare(strict_types=1);

use Slate\Presentation\RenderContext;
use Slate\Services\Content\ContentServiceFactory;

unit('paletteProjection exposes all supported block types, transport-safe', function () {
    $types = array_map(fn ($b) => $b['type'], ContentServiceFactory::paletteProjection());
    sort($types);
    assert_eq([
        'button', 'columns', 'container', 'cta', 'divider', 'heading', 'hero', 'html',
        'icon-grid', 'image', 'image-grid', 'paragraph', 'post-list', 'react', 'rx-gallery',
        'rx-hero', 'rx-marquee', 'rx-menu', 'rx-reviews', 'rx-story', 'rx-visit', 'spacer',
        'testimonial',
    ], $types);

    foreach (ContentServiceFactory::paletteProjection() as $def) {
        assert_true(!isset($def['render']), 'the palette must never leak a render closure');
        assert_true(isset($def['fields']) && is_array($def['fields']));
    }
});

unit('validatorRegistry produces the type-keyed shape DocumentValidator expects', function () {
    $registry = ContentServiceFactory::validatorRegistry();
    foreach (['heading', 'paragraph', 'image', 'button', 'hero'] as $type) {
        assert_true(isset($registry[$type]), "$type must be present");
        assert_true(is_array($registry[$type]['fields']));
        assert_true(is_array($registry[$type]['defaults']));
        assert_eq(false, $registry[$type]['capabilities']['nested'], "$type declares no nesting");
    }
});

unit('heading block renders the expected markup and clamps an out-of-range level', function () {
    $registry = ContentServiceFactory::blockRegistry();
    $block = $registry->get('heading');
    $ctx = RenderContext::for(1, RenderContext::SURFACE_FRAGMENT);
    assert_eq('<h2 class="cb-heading">Hi</h2>', $block->render(['text' => 'Hi', 'level' => '2'], $ctx));
    assert_eq('<h6 class="cb-heading">Hi</h6>', $block->render(['text' => 'Hi', 'level' => '99'], $ctx), 'level must clamp to 1-6');
});

unit('paragraph block escapes text and preserves line breaks', function () {
    $registry = ContentServiceFactory::blockRegistry();
    $block = $registry->get('paragraph');
    $ctx = RenderContext::for(1, RenderContext::SURFACE_FRAGMENT);
    $html = $block->render(['text' => "<script>x</script>\nline2"], $ctx);
    assert_false(str_contains($html, '<script>'), 'text must be HTML-escaped');
    assert_true(str_contains($html, '<br'), 'newlines become <br>');
});

unit('image block renders nothing for an unresolved/absent media key (honest empty state)', function () {
    $registry = ContentServiceFactory::blockRegistry();
    $block = $registry->get('image');
    $ctx = RenderContext::for(1, RenderContext::SURFACE_FRAGMENT);
    assert_eq('', $block->render(['width' => 'full'], $ctx), 'no media prop at all');
    assert_eq('', $block->render(['media' => ['key' => '../../etc/passwd']], $ctx), 'a path-traversal key must not resolve to a URL');
});

unit('image block renders a figure for a valid relative media key', function () {
    $registry = ContentServiceFactory::blockRegistry();
    $block = $registry->get('image');
    $ctx = RenderContext::for(1, RenderContext::SURFACE_FRAGMENT);
    $html = $block->render(['media' => ['key' => 'pages/example.jpg', 'alt' => 'An example']], $ctx);
    assert_true(str_contains($html, '<figure'));
    assert_true(str_contains($html, 'alt="An example"'));
    assert_true(str_contains($html, '/uploads/pages/example.jpg'));
});

unit('button block rejects a javascript: href via slate_safe_url', function () {
    $registry = ContentServiceFactory::blockRegistry();
    $block = $registry->get('button');
    $ctx = RenderContext::for(1, RenderContext::SURFACE_FRAGMENT);
    $html = $block->render(['text' => 'Go', 'href' => 'javascript:alert(1)', 'style' => 'primary'], $ctx);
    assert_false(str_contains($html, 'javascript:'), 'a dangerous URL scheme must never reach the output');
});

unit('hero block renders the banner branch with no image and the split branch with one', function () {
    $registry = ContentServiceFactory::blockRegistry();
    $block = $registry->get('hero');
    $ctx = RenderContext::for(1, RenderContext::SURFACE_FRAGMENT);

    $banner = $block->render(['heading' => 'Banner Hero', 'layout' => 'banner'], $ctx);
    assert_true(str_contains($banner, 'cb-hero-banner'));
    assert_true(str_contains($banner, 'cb-hero-plain'), 'no image => plain banner, not white-on-white overlay');

    $split = $block->render(['heading' => 'Split Hero', 'layout' => 'split', 'media' => ['key' => 'hero.jpg']], $ctx);
    assert_true(str_contains($split, 'cb-hero-split'));
    assert_true(str_contains($split, '/uploads/hero.jpg'));
});

unit('a columns block with a cols prop (the editor structure chooser\'s shape) passes validation', function () {
    // Regression: the editor's "choose your structure" modal saves a columns
    // block as {columns, gap, cols: [{blocks: []}, ...]} — the same nested
    // path DocumentOperations::insertBlockAtPath() addresses via
    // ['sectionId', idx, 'props', 'cols', N, 'blocks']. The columns block's
    // FieldSchema originally declared only 'columns' and 'gap', so
    // DocumentValidator rejected any document containing 'cols' with
    // unknown_property, and the structure chooser could never actually save.
    $document = [
        'schema' => 1, 'type' => 'page', 'template' => '',
        'sections' => [[
            'id' => 's1', 'layout' => ['cols' => 1],
            'blocks' => [[
                'type' => 'columns',
                'props' => ['columns' => 3, 'gap' => 24, 'cols' => [['blocks' => []], ['blocks' => []], ['blocks' => []]]],
            ]],
        ]],
        'seo' => [],
    ];
    $result = \Slate\Presentation\DocumentValidator::validate($document, ContentServiceFactory::validatorRegistry());
    assert_true($result['valid'], 'a columns block with a cols prop must validate: ' . json_encode($result['errors']));
});
