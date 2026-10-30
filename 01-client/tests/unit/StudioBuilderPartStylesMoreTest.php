<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the builder gaps a real site rebuild (FightxLife) left in custom CSS:
 * grid column sizes and spans, the larger part table (size, layout, gradient, font, opacity, effects), parts per device,
 * hidden labels and an inline form, and list layouts.
 *
 * Autoloader only, no database. Reuses sbdc_block / sbdc_doc / sbdc_render from StudioBuilderDeviceColumnsTest.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Document\StyleSurface;
use Slate\Module\StudioBuilder\Registry\BlockParts;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\RenderCollector;

function sbpm_valid(array $block): bool
{
    return DocumentValidator::validate(sbdc_doc($block), BlockRegistry::withAllCoreBlocks())->isValid();
}

unit('grid tracks: unequal, fixed and auto columns are a closed grammar, written tidy, and can differ per device', function (): void {
    foreach (['2fr 1fr', '300px auto 1fr', 'minmax(0, 2fr) minmax(0,1fr)', '1.73fr 25% 10vw', 'auto min-content max-content'] as $ok) {
        assert_true(StyleSurface::isTracks($ok), "accepted: {$ok}");
    }
    foreach (['', '2', 'repeat(2,1fr)', '1fr;color:red', 'calc(100% - 2px)', 'minmax(0,1fr', implode(' ', array_fill(0, 13, '1fr')), 'var(--x)', '1fr\n2fr}'] as $bad) {
        assert_false(StyleSurface::isTracks($bad), "refused: {$bad}");
    }
    assert_eq('display:grid;grid-template-columns:repeat(2,minmax(0,1fr));grid-template-columns:minmax(0,2fr) 1fr', StyleSurface::declarations(['layout' => ['display' => 'grid', 'columns' => 2, 'tracks' => ' minmax( 0 , 2fr )   1fr ']]), 'the track list comes after the count, so it wins');
    assert_eq('grid-column:1/-1', StyleSurface::declarations(['layout' => ['column_span' => 'full']]));
    assert_eq('grid-column:span 3', StyleSurface::declarations(['layout' => ['column_span' => 3]]));
    assert_eq(['(max-width:767.98px)' => 'grid-template-columns:52px 1fr !important'], StyleSurface::responsiveRules(['mobile' => ['style' => ['layout' => ['tracks' => '52px 1fr']]]]));

    $grid = sbdc_block('layout.grid', ['columns' => 3]);
    assert_true(sbpm_valid(array_replace($grid, ['style' => ['layout' => ['tracks' => '1fr auto 1fr']], 'responsive' => ['tablet' => ['style' => ['layout' => ['tracks' => '1fr auto']]]]])), 'a grid with its own sizes per device');
    assert_false(sbpm_valid(array_replace($grid, ['style' => ['layout' => ['tracks' => 'url(x)']]])), 'anything else is refused');
    assert_false(sbpm_valid(array_replace($grid, ['style' => ['layout' => ['column_span' => 13]]])), 'a span past twelve is refused');
});

unit('parts: size, layout (hidden included), gradient, font, opacity and effects; a hover move with its transition', function (): void {
    $button = sbdc_block('core.button', ['link' => ['label' => 'Go', 'href' => '/go', 'target' => '_self']]);
    $parts = [
        'button'       => [
            'background' => ['gradient' => 'linear-gradient(140deg, #ff4dae, #e9008a)'],
            'border'     => ['radius' => '999px', 'width' => '0px'],
            'size'       => ['height' => '60px', 'min_width' => '169px'],
            'typography' => ['family' => 'body'],
            'effects'    => ['transition' => ['duration_ms' => 200]],
        ],
        'button:hover' => ['effects' => ['transform' => ['translate_y' => '-2px'], 'filter' => ['saturate' => 107]], 'opacity' => 90],
    ];
    assert_true(sbpm_valid($button + ['style_parts' => $parts]), 'a pill button with a gradient and a hover lift');
    $rules = StyleSurface::partRules($parts, 'core.button');
    assert_eq(['.sb-button'], $rules[0]['selectors']);
    foreach (['font-family:var(--sb-font-body) !important', 'background-image:linear-gradient(140deg, #ff4dae, #e9008a) !important', 'border-radius:999px !important', 'height:60px !important', 'min-width:169px !important', 'transition:all 200ms ease !important'] as $decl) {
        assert_true(str_contains($rules[0]['declarations'], $decl), "{$decl} in {$rules[0]['declarations']}");
    }
    assert_eq(['selectors' => ['.sb-button:hover'], 'declarations' => 'opacity:0.9 !important;filter:saturate(107%) !important'], $rules[1]);
    assert_eq(['selectors' => ['.sb-button:hover'], 'declarations' => 'transform:translate(0,-2px) !important', 'media' => StyleSurface::HOVER_MOTION], $rules[2]);

    $loop = sbdc_block('core.query_loop', []);
    assert_true(sbpm_valid($loop + ['style_parts' => ['button_row' => ['layout' => ['display' => 'none']], 'grid' => ['layout' => ['display' => 'block']]]]), 'a part may be hidden, unlike a block');
    foreach ([
        'a bad gradient'   => ['button' => ['background' => ['gradient' => 'url(x)']]],
        'opacity past 100' => ['button' => ['opacity' => 150]],
        'an unknown font'  => ['button' => ['typography' => ['family' => 'Comic Sans']]],
        'a blend in a part filter' => ['button' => ['effects' => ['filter' => ['sepia' => 10]]]],
        'a fixed position' => ['button' => ['size' => ['position' => 'fixed']]],
    ] as $why => $bad) {
        assert_false(sbpm_valid($button + ['style_parts' => $bad]), "refused: {$why}");
    }
});

unit('parts: a pseudo-element part takes no hover, and a state is written before the pseudo-element', function (): void {
    $list = sbdc_block('core.list', ['style' => 'check']);
    assert_true(sbpm_valid($list + ['style_parts' => ['marker' => ['color' => '#e9008a']]]));
    assert_false(sbpm_valid($list + ['style_parts' => ['marker:hover' => ['color' => '#e9008a']]]), 'a marker has no hover');
    assert_eq([['key' => 'list', 'label' => 'List'], ['key' => 'item', 'label' => 'Items'], ['key' => 'marker', 'label' => 'Markers', 'states' => false]], BlockParts::forEditor('core.list'));
    $rules = StyleSurface::partRules(['marker:hover' => ['color' => '#000000'], 'link:hover' => ['color' => '#111111']], 'core.rich_text');
    assert_eq([['selectors' => ['a:hover'], 'declarations' => 'color:#111111 !important']], $rules, 'the stored marker hover is never written');
});

unit('parts per device: tablet and mobile override a part\'s size, spacing and layout under their media query', function (): void {
    $gallery = sbdc_block('core.gallery', []);
    $responsive = ['mobile' => ['style_parts' => ['item' => ['size' => ['height' => '155px']], 'grid' => ['layout' => ['gap' => '10px']]]]];
    assert_true(sbpm_valid($gallery + ['style_parts' => ['item' => ['size' => ['height' => '245px'], 'border' => ['radius' => '13px']]], 'responsive' => $responsive]));
    foreach ([
        'desktop'          => ['desktop' => ['style_parts' => ['item' => ['size' => ['height' => '1px']]]]],
        'a hover'          => ['mobile' => ['style_parts' => ['item:hover' => ['size' => ['height' => '1px']]]]],
        'a colour'         => ['mobile' => ['style_parts' => ['item' => ['color' => '#000000']]]],
        'an unknown part'  => ['tablet' => ['style_parts' => ['tile' => ['size' => ['height' => '1px']]]]],
    ] as $why => $bad) {
        assert_false(sbpm_valid($gallery + ['responsive' => $bad]), "refused: {$why}");
    }
    $rules = StyleSurface::partRules(['item' => ['size' => ['height' => '245px']]], 'core.gallery', $responsive);
    assert_eq(['selectors' => ['.sb-gallery__item'], 'declarations' => 'height:155px !important', 'media' => '(max-width:767.98px)'], $rules[2]);
    $c = new RenderCollector();
    $class = $c->scopedRule('blk_aaaaaaaaaaaaaaaa', '', false, [], [], $rules);
    assert_true(str_contains($c->css(), '@media (max-width:767.98px){.' . $class . ' .sb-gallery__item{height:155px !important}}'), $c->css());

    $html = sbdc_render($gallery + ['responsive' => $responsive]);
    assert_true((bool) preg_match('/\bsb-x-[0-9a-f]{16}\b/', $html), 'parts set only per device still give the block its class');
});

unit('form: labels can be hidden (still read out) and the fields can sit inline with the button', function (): void {
    $form = sbdc_block('core.form', ['labels' => 'hidden', 'layout' => 'inline'], [], [sbdc_block('core.form_field', ['name' => 'email', 'label' => 'Email', 'field_type' => 'email'])]);
    assert_true(sbpm_valid($form));
    $html = sbdc_render($form);
    assert_true(str_contains($html, '<form class="sb-form sb-form--labels-hidden sb-form--inline"'), $html);
    assert_true(str_contains($html, '>Email'), 'the label is still in the page');
    assert_false(sbpm_valid(sbdc_block('core.form', ['layout' => 'grid'])), 'an unknown layout is refused');
    assert_true(str_contains(sbdc_render(sbdc_block('core.form', [])), '<form class="sb-form"'), 'the default form is unchanged');
});

unit('list: dash markers, columns per device (one on a phone), inline items and lines between them', function (): void {
    $items = ['items' => [['text' => 'One'], ['text' => 'Two'], ['text' => 'Three']]];
    $html = sbdc_render(sbdc_block('core.list', $items + ['style' => 'dash', 'columns' => 2, 'divider' => true]));
    assert_true(str_contains($html, '<ul class="sb-list sb-list--dash sb-list--cols sb-dcols sb-list--divided" style="--sb-dc-d:2;--sb-dc-t:2;--sb-dc-m:1">'), $html);
    $mobile = sbdc_render(sbdc_block('core.list', $items + ['columns' => 3]) + ['responsive' => ['mobile' => ['props' => ['columns' => 2]]]]);
    assert_true(str_contains($mobile, '--sb-dc-d:3;--sb-dc-t:3;--sb-dc-m:2'), $mobile);
    $inline = sbdc_render(sbdc_block('core.list', $items + ['layout' => 'inline', 'columns' => 3]));
    assert_true(str_contains($inline, '<ul class="sb-list sb-list--bullet sb-list--inline">'), 'inline ignores columns: ' . $inline);
    assert_true(str_contains(sbdc_render(sbdc_block('core.list', $items)), '<ul class="sb-list sb-list--bullet">'), 'the default list is unchanged');
});

unit('parts: a border on chosen sides only, and a part placed against its widget', function (): void {
    $nav = sbdc_block('core.nav_menu', ['items' => [['label' => 'Home', 'url' => '/']]]);
    $parts = ['link' => ['border' => ['width' => '1px', 'style' => 'solid', 'color' => '#eeeeee', 'sides' => 'bottom']], 'list' => ['border' => ['width' => '1px', 'sides' => 'top_bottom']]];
    assert_true(sbpm_valid($nav + ['style_parts' => $parts]));
    $rules = StyleSurface::partRules($parts, 'core.nav_menu');
    assert_eq('border-width:1px 0 !important', $rules[0]['declarations'], 'the list: top and bottom');
    assert_true(str_contains($rules[1]['declarations'], 'border-width:0 0 1px 0 !important'), $rules[1]['declarations']);
    assert_false(sbpm_valid($nav + ['style_parts' => ['link' => ['border' => ['sides' => 'diagonal']]]]), 'an unknown side is refused');

    $drawer = sbdc_block('layout.offcanvas', []);
    assert_true(sbpm_valid($drawer + ['style_parts' => ['header' => ['position' => ['mode' => 'absolute', 'top' => '17px', 'right' => '22px']]]]));
    assert_false(sbpm_valid($drawer + ['style_parts' => ['header' => ['position' => ['mode' => 'fixed']]]]), 'never fixed');
    assert_eq('position:absolute !important;top:17px !important;right:22px !important', StyleSurface::partRules(['header' => ['position' => ['mode' => 'absolute', 'top' => '17px', 'right' => '22px']]], 'layout.offcanvas')[0]['declarations']);
});

unit('parts: a drawer\'s parts also match once the runtime has moved the drawer to <body>', function (): void {
    $rules = StyleSurface::partRules(['panel' => ['size' => ['width' => '390px']], 'trigger' => ['size' => ['width' => '34px']]], 'layout.offcanvas', ['mobile' => ['style_parts' => ['body' => ['padding' => ['top' => '20px']]]]]);
    $c = new RenderCollector();
    $class = $c->scopedRule('blk_aaaaaaaaaaaaaaaa', '', false, [], [], $rules);
    $css = $c->css();
    assert_true(str_contains($css, '.' . $class . ' .sb-offcanvas__panel,[data-sb-scope~="' . $class . '"] .sb-offcanvas__panel{width:390px !important}'), $css);
    assert_true(str_contains($css, '.' . $class . ' .sb-offcanvas-trigger{width:34px !important}'), 'the trigger stays in the block: ' . $css);
    assert_true(str_contains($css, '@media (max-width:767.98px){.' . $class . ' .sb-offcanvas__body,[data-sb-scope~="' . $class . '"] .sb-offcanvas__body{padding-top:20px !important}}'), $css);
    assert_true(StyleSurface::isTracks('auto 1fr minmax(320px,.95fr)'), 'a fraction may start with its point');
});
