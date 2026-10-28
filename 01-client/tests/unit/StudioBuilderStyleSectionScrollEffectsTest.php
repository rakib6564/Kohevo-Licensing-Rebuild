<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — sticky and parallax (ScrollEffects).
 *
 * Autoloader only, no database. Reuses sbsm_section / sbsm_doc / sbsm_render from StudioBuilderStyleSectionMotionTest
 * (loaded first, alphabetically).
 *
 * `interactions.sticky` and `interactions.parallax` validate with fixed shapes, render as classes, data-sb-* attributes and
 * custom properties only, and a parallax background moves the image from the node to its ::after layer.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Document\ScrollEffects;
use Slate\Module\StudioBuilder\Document\StyleSurface;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;

unit('scroll effects: sticky and parallax validate with fixed shapes and refuse anything else', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $ok = DocumentValidator::validate(sbsm_doc(sbsm_section(['interactions' => [
        'sticky'   => ['edge' => 'top', 'offset' => 12, 'devices' => ['desktop', 'tablet'], 'shadow' => true, 'background' => 'surface.page', 'hide_on_scroll' => true],
        'parallax' => ['layer' => 'background', 'speed' => 40, 'direction' => 'down', 'devices' => ['desktop']],
    ]])), $registry);
    assert_true($ok->isValid(), 'a full sticky and parallax setting is valid: ' . json_encode($ok->errors()));
    foreach ([
        'no edge'          => ['sticky' => ['offset' => 10]],
        'unknown edge'     => ['sticky' => ['edge' => 'left']],
        'offset too large' => ['sticky' => ['edge' => 'top', 'offset' => 900]],
        'offset a string'  => ['sticky' => ['edge' => 'top', 'offset' => '10px']],
        'unknown device'   => ['sticky' => ['edge' => 'top', 'devices' => ['watch']]],
        'no devices'       => ['sticky' => ['edge' => 'top', 'devices' => []]],
        'device twice'     => ['sticky' => ['edge' => 'top', 'devices' => ['mobile', 'mobile']]],
        'a CSS injection'  => ['sticky' => ['edge' => 'top', 'background' => 'red;position:fixed']],
        'unknown key'      => ['sticky' => ['edge' => 'top', 'z' => 9]],
        'sticky a list'    => ['sticky' => ['top']],
        'no speed'         => ['parallax' => ['layer' => 'element']],
        'speed too fast'   => ['parallax' => ['speed' => 200]],
        'unknown layer'    => ['parallax' => ['speed' => 20, 'layer' => 'foreground']],
    ] as $why => $interactions) {
        assert_true(!DocumentValidator::validate(sbsm_doc(sbsm_section(['interactions' => $interactions])), $registry)->isValid(), "refused: {$why}");
    }
    $kept = DocumentNormalizer::normalize(sbsm_doc(sbsm_section(['interactions' => ['sticky' => ['edge' => 'bottom', 'devices' => ['mobile']]]])), $registry);
    assert_eq(['sticky' => ['devices' => ['mobile'], 'edge' => 'bottom']], $kept['sections'][0]['interactions'], 'the canonical form keeps the settings');
});

unit('scroll effects: a sticky section is sticky in the stylesheet, with its edge, offset, devices and stuck effects on the tag', function (): void {
    $html = sbsm_render(sbsm_section(['interactions' => ['sticky' => ['edge' => 'top', 'offset' => 24, 'devices' => ['desktop'], 'shadow' => true, 'background' => '#ffffff', 'hide_on_scroll' => true]]]));
    preg_match('/<section[^>]*>/', $html, $m);
    $tag = $m[0] ?? '';
    foreach (['sb-sticky', 'sb-sticky--top', 'sb-sticky-off-tablet', 'sb-sticky-off-mobile'] as $class) {
        assert_true((bool) preg_match('/class="[^"]*\b' . preg_quote($class, '/') . '\b/', $tag), "{$class}: {$tag}");
    }
    assert_true(str_contains($tag, 'data-sb-sticky="top"'), 'edge');
    assert_true(str_contains($tag, 'data-sb-sticky-effect="shadow background hide"'), 'effects');
    assert_true(str_contains($tag, '--sb-stuck-bg:#ffffff') && str_contains($tag, '--sb-sticky-offset:24px'), 'stuck colour and offset');

    $bottom = sbsm_render(sbsm_section(['interactions' => ['sticky' => ['edge' => 'bottom', 'hide_on_scroll' => true, 'background' => 'surface.page']]]));
    assert_true(str_contains($bottom, 'data-sb-sticky="bottom"') && !str_contains($bottom, 'hide'), 'hiding on scroll is for a top edge only');
    assert_true(str_contains($bottom, '--sb-stuck-bg:var(--sb-surface-page)'), 'a theme colour becomes its custom property');

    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();
    assert_true(str_contains($css, '.sb-sticky{position:sticky;'), 'sticky without JavaScript');
    assert_true(str_contains($css, '.sb-site-header--partial:has(>.sb-sticky)'), 'a sticky header section sticks to the page, not inside its wrapper');
    assert_true(str_contains($css, '.sb-sticky.sb-sticky-off-mobile{position:relative'), 'a device left out does not stick');
});

unit('scroll effects: a parallax background is painted by the layer the runtime moves; an element parallax moves the node', function (): void {
    $style = ['background' => ['image' => ['media_id' => 5, 'alt' => ''], 'fit' => 'cover']];
    $normal = StyleSurface::sectionDeclarations($style, 'https://example.test/a.jpg');
    $moving = StyleSurface::sectionDeclarations($style, 'https://example.test/a.jpg', true);
    assert_true(str_contains($normal, 'background-image:url("https://example.test/a.jpg")'), 'without parallax the node paints the image');
    assert_true(!str_contains($moving, 'background-image:') && str_contains($moving, '--sb-pbg-image:url("https://example.test/a.jpg")') && str_contains($moving, '--sb-pbg-size:cover'), 'with it the layer does');

    $bg = ScrollEffects::render(['interactions' => ['parallax' => ['speed' => 40, 'direction' => 'down', 'devices' => ['desktop', 'tablet']]]]);
    assert_eq(['sb-parallax-bg'], $bg['classes']);
    assert_eq(' data-sb-parallax-bg="-0.4" data-sb-parallax-off="mobile"', $bg['attrs']);
    assert_eq(['--sb-pbg-room:20%'], $bg['vars'], 'the layer has half the speed of room on each side');
    $el = ScrollEffects::render(['interactions' => ['parallax' => ['layer' => 'element', 'speed' => 15]]]);
    assert_eq([[], ' data-sb-parallax="0.15"', []], [$el['classes'], $el['attrs'], $el['vars']]);
    assert_true(ScrollEffects::hasParallaxBackground(['interactions' => ['parallax' => ['speed' => 40]]]) && !ScrollEffects::hasParallaxBackground(['interactions' => ['parallax' => ['speed' => 40, 'layer' => 'element']]]));
    assert_eq(['classes' => [], 'attrs' => '', 'vars' => []], ScrollEffects::render(['interactions' => ['parallax' => ['speed' => 999], 'sticky' => ['edge' => 'side']]]), 'junk renders nothing');

    $html = sbsm_render(sbsm_section(['interactions' => ['parallax' => ['speed' => 30]]]));
    assert_true((bool) preg_match('/<section class="[^"]*\bsb-parallax-bg\b[^"]*"[^>]* data-sb-parallax-bg="0.3"[^>]* style="--sb-pbg-room:15%"/', $html), 'section tag: ' . substr($html, 0, 300));
});

unit('scroll effects: the runtime marks every sticky node, not only the first, and moves parallax layers', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true(str_contains($js, "qsa(root, '[data-sb-sticky]')"), 'every sticky node');
    assert_true(str_contains($js, "classList.toggle('is-stuck'") && str_contains($js, "classList.toggle('is-hidden'"), 'stuck and hidden states');
    assert_true(str_contains($js, "qsa(root, '[data-sb-parallax-bg]')") && str_contains($js, "'--sb-parallax-y'"), 'parallax layers');
    assert_true(str_contains($js, "'focusin'"), 'focus brings a hidden header back');
});

unit('scroll effects: the markup and CSS match the browser fixture the runtime spec scrolls through', function (): void {
    $header = sbsm_section(['id' => 'sec_sticky', 'interactions' => ['sticky' => ['edge' => 'top', 'shadow' => true, 'hide_on_scroll' => true]]]);
    $tall = sbsm_section(['id' => 'sec_tall', 'interactions' => ['parallax' => ['speed' => 40]]]);
    $html = '<header class="sb-site-header sb-site-header--partial">' . sbsm_render($header) . '</header>'
        . '<main>' . sbsm_render($tall) . '</main>';
    $html = (string) preg_replace('/ data-sb-node="[^"]*" data-sb-type="[^"]*"/', '', $html); // block ids are random
    $html = (string) preg_replace('/\bblk_[a-z0-9]+/', 'blk', $html);
    $fixture = __DIR__ . '/../../plugins/studio-builder/ui/tests/fixtures/scroll-effects.html';
    $css = '<style>' . StudioStylesheet::scrollEffectsCss() . '</style>';
    if (getenv('SBSE_WRITE_FIXTURE') === '1') {
        file_put_contents($fixture, $css . $html . "\n");
    }
    assert_eq(trim((string) file_get_contents($fixture)), $css . $html, 'ui/tests/fixtures/scroll-effects.html is stale — regenerate with SBSE_WRITE_FIXTURE=1');
});
