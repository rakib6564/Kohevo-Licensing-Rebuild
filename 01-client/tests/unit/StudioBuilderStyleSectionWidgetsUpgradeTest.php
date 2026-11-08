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
use Slate\Module\StudioBuilder\Document\StyleSurface;
use Slate\Module\StudioBuilder\Provider\TestimonialItems;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\TestimonialGridRenderer;
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

unit('message widget: Theme light or dark fixes its colours (custom properties a site can tune); Auto follows the site', function (): void {
    $base = ['timezone' => 'UTC', 'hours_start' => '00:00', 'hours_end' => '00:00', 'days' => 'everyday'];
    foreach (['light' => true, 'dark' => true, 'auto' => false] as $theme => $classed) {
        $block = sbpm_block('core.message_widget', $base + ['theme' => $theme]);
        assert_true(DocumentValidator::validate(sbsm_doc(sbpm_box([$block])), BlockRegistry::withAllCoreBlocks())->isValid(), $theme . ' valid');
        $html = sbsm_render(sbpm_box([$block]));
        assert_eq($classed, str_contains($html, 'sb-msg--' . $theme), $theme);
    }
    assert_true(!DocumentValidator::validate(sbsm_doc(sbpm_box([sbpm_block('core.message_widget', $base + ['theme' => 'neon'])])), BlockRegistry::withAllCoreBlocks())->isValid(), 'an unknown theme is refused');
    $css = StudioStylesheet::widgetsCss();
    assert_true(str_contains($css, '.sb-msg--dark{--sb-msg-bg:') && str_contains($css, '.sb-msg--light{--sb-msg-bg:#fff;'), 'both palettes');
    assert_true(str_contains($css, '.sb-msg__panel .sb-form-group textarea') && str_contains($css, 'font:inherit'), 'fields take the panel colours and the site font');
});

unit('site scroll bar: default emits nothing; thin, branded (accent or own colours) and hidden style only the page bar', function (): void {
    assert_eq('', \Slate\Module\StudioBuilder\Render\SiteScrollbar::cssFor('default'));
    assert_eq('', \Slate\Module\StudioBuilder\Render\SiteScrollbar::cssFor('rainbow'), 'unknown style is the browser bar');
    assert_eq('html{scrollbar-width:thin}', \Slate\Module\StudioBuilder\Render\SiteScrollbar::cssFor('thin'));
    $hidden = \Slate\Module\StudioBuilder\Render\SiteScrollbar::cssFor('hidden');
    assert_true(str_contains($hidden, 'html{scrollbar-width:none}') && str_contains($hidden, 'html::-webkit-scrollbar{display:none'), $hidden);
    $accent = \Slate\Module\StudioBuilder\Render\SiteScrollbar::cssFor('branded');
    assert_true(str_contains($accent, 'scrollbar-color:var(--sb-color-accent,#888) transparent'), $accent);
    $own = \Slate\Module\StudioBuilder\Render\SiteScrollbar::cssFor('branded', '#FF5A1F', '#0B0B0C');
    assert_true(str_contains($own, 'scrollbar-color:#ff5a1f #0b0b0c') && str_contains($own, 'html::-webkit-scrollbar-thumb{background:#ff5a1f;'), $own);
    $bad = \Slate\Module\StudioBuilder\Render\SiteScrollbar::cssFor('branded', 'red;}body{x:y', 'url(x)');
    assert_true(!str_contains($bad, 'red') && !str_contains($bad, 'url('), 'only #rrggbb colours reach the CSS');
    assert_true(!str_contains(\Slate\Module\StudioBuilder\Render\SiteScrollbar::cssFor('branded'), 'body'), 'the page bar only');
});

unit('testimonial grid: typed testimonials render every card, a pager the runtime fills, and a dialog for the full text', function (): void {
    $items = [
        ['name' => 'Jonathan Cooper', 'text' => "Great <work>\nThanks", 'rating' => 4.5, 'meta' => 'United States', 'date' => '3 years ago'],
        ['name' => 'doomreboot', 'text' => 'Short', 'rating' => 5, 'meta' => '', 'date' => ''],
        ['name' => 'Sam', 'text' => 'A much longer review than the others here.', 'rating' => 5, 'meta' => '', 'date' => ''],
    ];
    $block = sbpm_block('core.testimonial_grid', ['items' => $items, 'per_page' => 2, 'per_page_tablet' => 2, 'per_page_mobile' => 1, 'columns' => 2, 'clamp_lines' => 4, 'source_note' => 'on Fiverr', 'order' => 'longest']);
    assert_true(DocumentValidator::validate(sbsm_doc(sbpm_box([$block])), BlockRegistry::withAllCoreBlocks())->isValid(), 'valid');
    $html = sbsm_render(sbpm_box([$block]));
    assert_true((bool) preg_match('/<div class="sb-tgrid sb-tgrid--clamp sb-tgrid--first" id="[^"]+" data-sb-tgrid data-sb-tg-per="2,2,1" style="--sb-tg-cols:2;--sb-tg-cols-md:2;--sb-tg-cols-sm:1;--sb-tg-clamp:4">/', $html), $html);
    assert_eq(3, substr_count($html, 'class="sb-tgrid__card'), 'every card is in the page');
    assert_true(str_contains($html, '>01–03 of 3 reviews</p>'), 'the count for the whole list');
    assert_true(str_contains($html, '<nav class="sb-tgrid__nav" aria-label="Review pages" hidden>'), 'the pager waits for the runtime');
    assert_true(strpos($html, 'A much longer review') < strpos($html, 'Great &lt;work&gt;') && strpos($html, 'Great &lt;work&gt;') < strpos($html, '>Short<'), 'longest first');
    assert_true(str_contains($html, 'class="sb-tgrid__card is-first" data-sb-tg-i="0"'), 'the first stands out');
    assert_true(str_contains($html, 'aria-label="4.5 out of 5">★★★★★</span>') || str_contains($html, 'aria-label="4.5 out of 5">'), 'rating read out');
    assert_true(str_contains($html, '<span class="sb-tgrid__avatar" aria-hidden="true">JC</span>') && str_contains($html, 'aria-hidden="true">DO</span>'), 'initials');
    assert_true(str_contains($html, '<span class="sb-tgrid__meta">United States</span>') && str_contains($html, '<span class="sb-tgrid__date">3 years ago</span>'), 'details and date');
    assert_true(str_contains($html, 'data-sb-tg-note="on Fiverr"') && str_contains($html, '<dialog class="sb-tgrid__dialog"'), 'the dialog');
    assert_true(str_contains($html, '<button type="button" class="sb-tgrid__more" data-sb-tg-more hidden>Read full review</button>'), 'read more waits for a measure');

    $min = sbsm_render(sbpm_box([sbpm_block('core.testimonial_grid', ['items' => $items, 'min_length' => 10])]));
    assert_eq(2, substr_count($min, 'class="sb-tgrid__card'), 'shorter reviews left out');
    assert_eq('', sbsm_render(sbpm_box([sbpm_block('core.testimonial_grid', ['items' => [], 'source' => 'items'])])) === '' ? '' : 'x', 'nothing to show, nothing on the page');
    assert_true(!DocumentValidator::validate(sbsm_doc(sbpm_box([sbpm_block('core.testimonial_grid', ['text_field' => 'Text; drop'])])), BlockRegistry::withAllCoreBlocks())->isValid(), 'a field key is checked');
});

unit('testimonial grid: a content type item reads its title and mapped fields, rich text as plain', function (): void {
    $map = ['text' => 'text', 'rating' => 'rating', 'meta' => ['country', 'price'], 'date' => 'when', 'photo' => 'photo'];
    $t = TestimonialItems::item(' cj6105 ', ['text' => '<p>Truly <b>one</b> of the best &amp; kind</p><p>Again</p>', 'rating' => '7', 'country' => 'United States', 'price' => '', 'when' => '4 years ago', 'photo' => '/uploads/cj.jpg'], $map);
    assert_eq(['name' => 'cj6105', 'text' => "Truly one of the best & kind\nAgain", 'rating' => 5.0, 'meta' => 'United States', 'date' => '4 years ago', 'photo' => '/uploads/cj.jpg'], $t, 'mapped and cleaned, rating capped');
    assert_null(TestimonialItems::item('x', ['text' => '  '], $map), 'no text, no testimonial');
    assert_eq(null, TestimonialItems::item('x', ['text' => 'ok', 'rating' => 'n/a'], $map)['rating'], 'no rating');
    assert_eq('JC', TestimonialGridRenderer::initials('Jonathan Cooper'));
    assert_eq('EV', TestimonialGridRenderer::initials('evburgessjr_01'));
    assert_eq('ÉS', TestimonialGridRenderer::initials('élise sand'));
    assert_true(str_contains(TestimonialGridRenderer::stars(3.0), '★★★<span class="sb-tgrid__star-off">★★</span>'), 'unlit stars');
});

unit('device styles: a phone or tablet may set its own line height and letter spacing', function (): void {
    assert_eq([], StyleSurface::responsiveIssues(['typography' => ['size' => '48px', 'line_height' => '1.05', 'letter_spacing' => '-1.5px']], 'x'), 'accepted');
    assert_true(StyleSurface::responsiveIssues(['typography' => ['line_height' => '1;x:y']], 'x') !== [], 'checked');
    assert_eq(['(max-width:767.98px)' => 'font-size:48px !important;line-height:1.05 !important;letter-spacing:-1.5px !important'],
        StyleSurface::responsiveRules(['mobile' => ['style' => ['typography' => ['size' => '48px', 'line_height' => '1.05', 'letter_spacing' => '-1.5px']]]]));
});

unit('offcanvas: the trigger text can give way to its icon on a phone, still read out', function (): void {
    $html = sbsm_render(sbpm_box([sbpm_block('layout.offcanvas', ['drawer_id' => 'm', 'trigger_text' => 'Menu', 'trigger_icon' => 'menu', 'trigger_text_mobile' => false])]));
    assert_true(str_contains($html, 'sb-offcanvas-trigger--icon sb-offcanvas-trigger--icon-sm') && str_contains($html, '<span class="sb-offcanvas-trigger__label">Menu</span>'), $html);
    assert_true(str_contains(StudioStylesheet::css(), '.sb-offcanvas-trigger--icon-sm .sb-offcanvas-trigger__label{position:absolute;width:1px'), 'hidden visually only');
});

unit('testimonial grid: each part can be hidden, a maximum length, and a ticker of chips in rows', function (): void {
    $items = [['name' => 'Ann Lee', 'text' => 'Great work, thanks!', 'rating' => 5, 'meta' => 'USA', 'date' => '1 year ago'], ['name' => 'Bob', 'text' => str_repeat('Long review. ', 10), 'rating' => 4, 'meta' => 'UK', 'date' => '2 years ago'], ['name' => 'Cy', 'text' => 'Fast!', 'rating' => 5, 'meta' => '', 'date' => '']];
    $bare = sbsm_render(sbpm_box([sbpm_block('core.testimonial_grid', ['items' => $items, 'show_avatar' => false, 'show_meta' => false, 'show_date' => false, 'show_name' => false, 'show_quote' => false, 'show_stars' => false])]));
    assert_true(!str_contains($bare, 'sb-tgrid__avatar') && !str_contains($bare, 'sb-tgrid__meta') && !str_contains($bare, 'sb-tgrid__date') && !str_contains($bare, 'sb-tgrid__name') && !str_contains($bare, 'sb-tgrid__top') && !str_contains($bare, 'sb-tgrid__by'), $bare);
    assert_true(str_contains($bare, 'data-sb-tg-name="Ann Lee"'), 'the dialog still names the reviewer');
    $noAvatar = sbsm_render(sbpm_box([sbpm_block('core.testimonial_grid', ['items' => $items, 'show_avatar' => false])]));
    assert_true(str_contains($noAvatar, 'class="sb-tgrid__by sb-tgrid__by--plain"'), 'the row closes up without the avatar');
    $max = sbsm_render(sbpm_box([sbpm_block('core.testimonial_grid', ['items' => $items, 'max_length' => 30])]));
    assert_eq(2, substr_count($max, 'class="sb-tgrid__card'), 'the long one left out');
    $ticker = sbsm_render(sbpm_box([sbpm_block('core.testimonial_grid', ['items' => $items, 'layout' => 'ticker', 'ticker_rows' => 2, 'ticker_speed' => 4])]));
    assert_true(str_contains($ticker, '<div class="sb-tgrid sb-tgrid--ticker"><ul class="sb-tgrid__row" data-sb-ticker="4 left scroll pause">') && str_contains($ticker, '<ul class="sb-tgrid__row" data-sb-ticker="4 right scroll pause">'), $ticker);
    assert_eq(3, substr_count($ticker, 'class="sb-tgrid__chip"'), 'a chip each');
    assert_true(!str_contains($ticker, 'sb-tgrid__nav'), 'no pager');
});

unit('accordions: rows can read as lines and open with a plus, in every accordion widget', function (): void {
    $set = sbsm_render(sbpm_box([sbpm_block('layout.accordion', ['look' => 'lines', 'icon' => 'plus'], ['children' => [sbpm_block('layout.accordion_item', ['title' => 'Q'])]])]));
    assert_true(str_contains($set, 'class="sb-accordion-block sb-accordion-block--blocks sb-accordion-block--lines sb-accordion-block--plus"'), 'set: ' . $set);
    $core = sbsm_render(sbpm_box([sbpm_block('core.accordion', ['items' => [['heading' => 'Q', 'body' => 'A']], 'look' => 'lines'])]));
    assert_true(str_contains($core, 'class="sb-accordion-block sb-accordion-block--lines"'), 'core: ' . $core);
    $plain = sbsm_render(sbpm_box([sbpm_block('core.accordion', ['items' => [['heading' => 'Q', 'body' => 'A']]])]));
    assert_true(str_contains($plain, 'class="sb-accordion-block"'), 'boxed by default');
    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();
    assert_true(str_contains($css, '.sb-accordion-block--plus .sb-accordion__button[aria-expanded="true"] .sb-accordion__icon{transform:rotate(45deg)}'), 'plus turns');
});

unit('style parts: a line height may be a plain number, as a block\'s own', function (): void {
    assert_eq([], StyleSurface::partIssues(['paragraph' => ['typography' => ['line_height' => '1.45']]], 'theme.field_value', 'p'), 'accepted');
    assert_true(StyleSurface::partIssues(['paragraph' => ['typography' => ['line_height' => '1;x:y']]], 'theme.field_value', 'p') !== [], 'checked');
});

unit('query loop: on a Single template the item\'s own category does not filter the loop; on an archive it does', function (): void {
    $listing = new ReflectionMethod(\Slate\Module\StudioBuilder\Render\Block\CoreRenderers\QueryLoopRenderer::class, 'listing');
    $scopeWith = static function (array $attrs): \Slate\Module\StudioBuilder\Render\Block\BlockRenderScope {
        $block = ['id' => 'loop_l', 'type' => 'core.query_loop', 'props' => []];
        return new \Slate\Module\StudioBuilder\Render\Block\BlockRenderScope($block,
            \Slate\Module\StudioBuilder\Render\RenderContext::forPublic(1, new \Slate\Module\StudioBuilder\Render\SiteContext('https://example.test', 'Test', 'en'), null, $attrs),
            new \Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver(new \Slate\Tenancy\TenantContext()), new \Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme('default', []),
            new \Slate\Module\StudioBuilder\Render\RenderCollector('nonce'), '', []);
    };
    assert_eq('', $listing->invoke(null, $scopeWith(['is_single' => true, 'category' => 'Core']))['category'], 'a single item');
    assert_eq('Web', $listing->invoke(null, $scopeWith(['archive_type' => 'category', 'category' => 'Web']))['category'], 'a category archive');
    $def = BlockRegistry::withAllCoreBlocks()->get('core.query_loop');
    $schema = json_encode($def->toEditorManifest());
    assert_true(str_contains($schema, '"featured_only"') && str_contains($schema, '"max":48'), 'featured only, and up to 48 a page');
});

unit('post titles: a linked item title keeps its colour over the site link colour', function (): void {
    StudioStylesheet::resetCache();
    assert_true(str_contains(StudioStylesheet::css(), '.sb-body .sb-post-title a:not(.sb-button):not(.sb-heading__link),.sb-body a.sb-post-link:not(.sb-button):not(.sb-heading__link){color:inherit}'));
});

unit('progress: a row can show its own value, and the label, bar and value can sit on one line', function (): void {
    $html = sbsm_render(sbpm_box([sbpm_block('core.progress', ['items' => [['label' => 'United States', 'value' => 71, 'note' => '72 <b>'], ['label' => 'Belgium', 'value' => 10]], 'layout' => 'inline'])]));
    assert_true(str_contains($html, '<div class="sb-progress sb-progress--inline" data-sb-progress-bars'), $html);
    assert_true(str_contains($html, '<span class="sb-progress__value">72 &lt;b&gt;</span>') && str_contains($html, '<span class="sb-progress__value">10%</span>'), 'own value, escaped; else the share');
    assert_true(str_contains(sbsm_render(sbpm_box([sbpm_block('core.progress', ['items' => [['label' => 'A', 'value' => 5]]])])), '<div class="sb-progress" data-sb-progress-bars'), 'stacked by default');
});

unit('grid tracks: as many columns as fit, each at least a size, and nothing looser', function (): void {
    assert_true(StyleSurface::isTracks('repeat(auto-fit, minmax(220px, 1fr))') && StyleSurface::isTracks('repeat(auto-fill,minmax(14rem,1fr))'), 'auto-fit and auto-fill');
    foreach (['repeat(3, 1fr)', 'repeat(auto-fit, minmax(220px, 1fr)) 1fr', 'repeat(auto-fit, minmax(calc(1px), 1fr))', 'repeat(auto-fit, minmax(220px, 1fr));x:y'] as $bad) {
        assert_true(!StyleSurface::isTracks($bad), $bad);
    }
});
