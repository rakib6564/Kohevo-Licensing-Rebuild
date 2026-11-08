<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the big menu (numbers, descriptions, arrow, columns, preview,
 * rise-in), the full-screen drawer with a worded close button, site menu descriptions and images, and the message
 * widget's photo button, turning lines and shrinking.
 *
 * Autoloader only, no database. Reuses sbsm_* and sbpm_* helpers, loaded first, alphabetically.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Menu\StudioMenus;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\MessageWidgetRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\NavMenuRenderer;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;

function sbbm_links(): array
{
    return [
        ['label' => 'Home', 'url' => '/', 'description' => 'Websites built for your industry'],
        ['label' => 'Work', 'url' => '/work/', 'description' => ''],
        ['label' => 'Contact', 'url' => '/contact/'],
    ];
}

unit('big menu: numbers, descriptions and the arrow go inside each top-level link; off, the links are the plain ones', function (): void {
    $plain = sbsm_render(sbpm_box([sbpm_block('core.nav_menu', ['layout' => 'vertical', 'items' => sbbm_links()])]));
    assert_true(str_contains($plain, '<a class="sb-nav__link" href="/">Home</a>'), 'plain link: ' . $plain);
    assert_true(!str_contains($plain, 'sb-nav__num') && !str_contains($plain, 'style="--i:'), 'nothing extra');

    $block = sbpm_block('core.nav_menu', ['layout' => 'vertical', 'items' => sbbm_links(), 'numbered' => true, 'descriptions' => true, 'arrow' => true, 'columns' => 2, 'reveal' => true]);
    assert_true(DocumentValidator::validate(sbsm_doc(sbpm_box([$block])), BlockRegistry::withAllCoreBlocks())->isValid(), 'valid');
    $html = sbsm_render(sbpm_box([$block]));
    assert_true(str_contains($html, '<a class="sb-nav__link" href="/"><span class="sb-nav__num" aria-hidden="true">01</span><span class="sb-nav__text"><span class="sb-nav__label">Home</span><span class="sb-nav__desc">Websites built for your industry</span></span><span class="sb-nav__arrow" aria-hidden="true">↗</span></a>'), $html);
    assert_true(str_contains($html, '<span class="sb-nav__num" aria-hidden="true">02</span><span class="sb-nav__text"><span class="sb-nav__label">Work</span></span>'), 'no empty description');
    assert_true((bool) preg_match('/class="sb-nav sb-nav--vertical[^"]*sb-nav--cols-2[^"]*sb-nav--numbered[^"]*sb-nav--described[^"]*sb-nav--reveal/', $html), 'classes');
    assert_true(str_contains($html, 'style="--i:2"'), 'each item its place, for the rise-in');

    // Columns are for a vertical menu only; out of range they are ignored.
    assert_true(!str_contains(sbsm_render(sbpm_box([sbpm_block('core.nav_menu', ['items' => sbbm_links(), 'columns' => 2])])), 'sb-nav--cols-'), 'not in a row');
    assert_true(!DocumentValidator::validate(sbsm_doc(sbpm_box([sbpm_block('core.nav_menu', ['items' => sbbm_links(), 'columns' => 4])])), BlockRegistry::withAllCoreBlocks())->isValid(), 'four columns refused');
});

unit('big menu: the preview lists one decorative picture per link that has one, the first shown, and safe addresses only', function (): void {
    $r = new NavMenuRenderer();
    $picture = (new ReflectionMethod($r, 'picture'));
    $preview = (new ReflectionMethod($r, 'preview'));
    $scope = sbw_loop_scope([], []);
    assert_eq(['url' => '/uploads/a.jpg', 'alt' => ''], $picture->invoke($r, ['image' => '/uploads/a.jpg'], $scope), 'a site menu address');
    assert_null($picture->invoke($r, ['image' => 'javascript:alert(1)'], $scope), 'unsafe refused');
    assert_null($picture->invoke($r, ['image' => 'mailto:a@b.c'], $scope), 'not a picture');
    assert_null($picture->invoke($r, [], $scope), 'none');
    $html = $preview->invoke($r, [['url' => '/a.jpg', 'alt' => '', 'caption' => 'First <b>'], ['url' => '/b.jpg', 'alt' => '', 'caption' => '']]);
    assert_true(str_starts_with($html, '<div class="sb-nav__preview" aria-hidden="true"><figure class="sb-nav__shot" data-on data-sb-preview="0"><img src="/a.jpg" alt=""'), $html);
    assert_true(str_contains($html, '<figcaption class="sb-nav__preview-caption">First &lt;b&gt;</figcaption>') && str_contains($html, '<figure class="sb-nav__shot" data-sb-preview="1">'), 'caption escaped, second hidden');
    assert_eq('', $preview->invoke($r, []), 'no pictures, no panel');
    $none = sbsm_render(sbpm_box([sbpm_block('core.nav_menu', ['layout' => 'vertical', 'items' => sbbm_links(), 'preview' => true])]));
    assert_true(!str_contains($none, 'sb-nav__preview') && !str_contains($none, 'data-sb-nav-preview'), 'no panel without pictures');
});

unit('site menus: an item may carry a description and a picture, stored only when set and checked', function (): void {
    $menu = StudioMenus::validate('full', 'Full menu', [
        ['label' => 'Home', 'url' => '/', 'description' => "  Websites  built\nfor you ", 'image' => '/uploads/media/home.jpg'],
        ['label' => 'Work', 'url' => '/work/', 'description' => '', 'image' => ''],
    ]);
    assert_eq(['label' => 'Home', 'url' => '/', 'new_tab' => false, 'description' => 'Websites built for you', 'image' => '/uploads/media/home.jpg'], $menu['items'][0], 'kept, spaces folded');
    assert_eq(['label' => 'Work', 'url' => '/work/', 'new_tab' => false], $menu['items'][1], 'nothing stored when empty');
    $flat = StudioMenus::flatten($menu['items']);
    assert_eq('Websites built for you', $flat[0]['description'], 'the form gets it back');
    foreach (['javascript:alert(1)', 'mailto:a@b.c'] as $bad) {
        $threw = false;
        try { StudioMenus::validate('m', 'M', [['label' => 'A', 'url' => '/', 'image' => $bad]]); } catch (InvalidArgumentException) { $threw = true; }
        assert_true($threw, "image {$bad} refused");
    }
    $threw = false;
    try { StudioMenus::validate('m', 'M', [['label' => 'A', 'url' => '/', 'description' => str_repeat('x', StudioMenus::MAX_DESCRIPTION + 1)]]); } catch (InvalidArgumentException) { $threw = true; }
    assert_true($threw, 'a long description refused');
});

unit('offcanvas: full screen covers the window, and a close text replaces the cross', function (): void {
    $block = sbpm_block('layout.offcanvas', ['drawer_id' => 'm', 'title' => '', 'position' => 'fullscreen', 'close_text' => 'Close <x>', 'trigger_text' => 'Menu']);
    assert_true(DocumentValidator::validate(sbsm_doc(sbpm_box([$block])), BlockRegistry::withAllCoreBlocks())->isValid(), 'valid');
    $html = sbsm_render(sbpm_box([$block]));
    assert_true(str_contains($html, 'class="sb-offcanvas sb-offcanvas--fullscreen"'), $html);
    assert_true(str_contains($html, '<a href="#" class="sb-offcanvas__close sb-offcanvas__close--text" data-sb-offcanvas-close>Close &lt;x&gt;<svg'), 'worded close, escaped');
    $cross = sbsm_render(sbpm_box([sbpm_block('layout.offcanvas', ['drawer_id' => 'm', 'trigger_text' => 'Menu'])]));
    assert_true(str_contains($cross, 'aria-label="Close">&times;</a>'), 'the cross by default');
    $css = StudioStylesheet::css();
    assert_true(str_contains($css, '.sb-offcanvas--fullscreen .sb-offcanvas__panel{inset:0;width:auto;max-width:none'), 'full screen panel');
    assert_true(str_contains($css, ':is(.sb-offcanvas:target,.sb-offcanvas[data-sb-open]) .sb-nav--reveal .sb-nav__list>.sb-nav__item{animation:sb-nav-rise'), 'links rise in when it opens');
});

unit('message widget: the button can show the photo, lines that take turns with {status} and {time}, and shrink on scroll', function (): void {
    $lines = MessageWidgetRenderer::lines("{status}\n\n  It's {time} in Dhaka \n" . str_repeat('x', 81) . "\nA\nB\nC", 'Online', 'Asia/Dhaka');
    assert_eq(4, count($lines), 'four at most, blank and long lines dropped');
    assert_eq('Online', $lines[0]['text']);
    assert_true((bool) preg_match('/^It\'s \d{1,2}:\d\d (AM|PM) in Dhaka$/', $lines[1]['text']), $lines[1]['text']);
    assert_eq("It's {time} in Dhaka", $lines[1]['raw'], 'the runtime gets the raw line');

    $block = sbpm_block('core.message_widget', ['timezone' => 'UTC', 'hours_start' => '00:00', 'hours_end' => '00:00', 'days' => 'everyday', 'launcher_label' => 'Message me', 'launcher_lines' => "{status}\nAsk me", 'compact_on_scroll' => true, 'launcher_photo' => true, 'online_text' => 'Online']);
    assert_true(DocumentValidator::validate(sbsm_doc(sbpm_box([$block])), BlockRegistry::withAllCoreBlocks())->isValid(), 'valid');
    $html = sbsm_render(sbpm_box([$block]));
    assert_true(str_contains($html, 'data-sb-msg-compact'), 'shrinks on scroll');
    assert_true(str_contains($html, '<span class="sb-msg__launcher-text"><span class="sb-msg__launcher-label">Message me</span><span class="sb-msg__launcher-sub" data-sb-msg-lines="[&quot;{status}&quot;,&quot;Ask me&quot;]" data-sb-online="Online"'), $html);
    assert_true(str_contains($html, '>Online</span></span>'), 'the first line filled in');
    // No photo in the library: the icon stays, with its dot.
    assert_true(!str_contains($html, 'sb-msg__launcher-photo') && str_contains($html, 'class="sb-msg__launcher sb-msg__launcher--lines"'), 'icon without a photo');
});
