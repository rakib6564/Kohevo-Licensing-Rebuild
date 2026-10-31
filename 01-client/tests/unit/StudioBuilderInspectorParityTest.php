<?php

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\ScrollEffects;
use Slate\Module\StudioBuilder\Document\StyleSurface;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\ContainerRenderer;

unit('inspector parity: flip is checked, and joins the transform', function (): void {
    $css = StyleSurface::declarations(['effects' => ['transform' => ['rotate' => 10, 'flip_x' => true, 'flip_y' => false]]]);
    assert_true(str_contains($css, 'transform:rotate(10deg) scaleX(-1)'), $css);
});

unit('inspector parity: scrolling and mouse effects are levels, checked, and render as data attributes', function (): void {
    assert_eq([], ScrollEffects::issues(['scroll' => ['vertical' => -3, 'blur' => 4, 'devices' => ['desktop']], 'mouse' => ['track' => 2, 'tilt' => 5]], '$'));
    assert_eq(1, count(ScrollEffects::issues(['scroll' => ['vertical' => 11]], '$')));
    assert_eq(1, count(ScrollEffects::issues(['scroll' => ['blur' => -1]], '$')), 'blur has no negative');
    assert_eq(1, count(ScrollEffects::issues(['mouse' => ['spin' => 1]], '$')), 'unknown settings are refused');
    $attrs = ScrollEffects::render(['interactions' => ['scroll' => ['vertical' => -3, 'blur' => 4, 'devices' => ['desktop']], 'mouse' => ['tilt' => 5]]])['attrs'];
    assert_true(str_contains($attrs, 'data-sb-scroll="y:-3 b:4"'), $attrs);
    assert_true(str_contains($attrs, 'data-sb-scroll-off="tablet mobile"'), $attrs);
    assert_true(str_contains($attrs, 'data-sb-mouse="i:5"'), $attrs);
    assert_eq('', ScrollEffects::render(['interactions' => ['scroll' => ['vertical' => 99, 'x' => '"><script>']]])['attrs'], 'junk renders nothing');
});

unit('inspector parity: the builder\'s shape picker draws the renderer\'s shapes', function (): void {
    preg_match_all("/^  ([a-z_]+): '([^']+)',$/m", (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/ui/src/core/shapes.mjs'), $m);
    assert_eq(ContainerRenderer::SHAPES, array_combine($m[1], $m[2]));
    assert_eq(array_keys(ContainerRenderer::SHAPES), array_slice(ContainerRenderer::SHAPE_NAMES, 1));
});

unit('inspector parity: an element\'s CSS is scoped to it, and is sanitized when it renders', function (): void {
    $css = \Slate\Module\StudioBuilder\Document\ElementCss::compile('selector{color:red} selector a{color:blue}</style><script>x</script>', 'sb-el-1');
    assert_true(str_contains($css, '.sb-el-1{color:red}') && str_contains($css, '.sb-el-1 a{color:blue}'), $css);
    assert_false(str_contains(strtolower($css), '</style') || str_contains(strtolower($css), '<script'), $css);
    $doc = ['sections' => [['id' => 'sec_1', 'blocks' => [['id' => 'blk_a', 'children' => [['id' => 'blk_b', 'custom_css' => 'selector{top:0}']]]]]]];
    assert_eq(['blk_b' => 'selector{top:0}'], \Slate\Module\StudioBuilder\Document\ElementCss::collect($doc), 'found however deep it sits');
});
