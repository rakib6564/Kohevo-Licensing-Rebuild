<?php
// Chef Gregory's Gourmet Me Now (gourmetmenow.com) in Kohevo Studio: a candy-shop redesign on the native Shop.
//
//   cd <site> && php <bundle>/build/site.php      (the site needs the Shop, Studio, Stripe and Media plugins active)
//
// Products come from the WooCommerce export through Shop's importer (catalog.php). Every page keeps the old address
// (/products/, /brittles/, /about-us/, /recipes/, /gallery/, /contact-us/, /product/<slug>/, /cart/, /checkout/).
// It replaces every page it builds, so never rerun it on a site someone has edited. GMN_LIVE=1 lifts the noindex.
declare(strict_types=1);

use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Menu\StudioMenus;
use Slate\Module\StudioBuilder\Meta\PageRoutes;

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/content.php';
require __DIR__ . '/catalog.php';
require __DIR__ . '/orders.php';
$BUNDLE = dirname(__DIR__);

// The best sellers (from the old shop's sales) are the featured products.
Database::query("UPDATE shop_products SET featured = 0 WHERE tenant_id = ?", [$tid]);
Database::query("UPDATE shop_products SET featured = 1 WHERE tenant_id = ? AND external_ref IN ('woo:601', 'woo:603', 'woo:599', 'woo:615')", [$tid]);
foreach (['cart_page' => 'cart', 'checkout_page' => 'checkout', 'thanks_page' => 'order-received', 'shop_page' => 'products'] as $k => $v) ShopStoreAPI::setSetting($k, $v);
$CAT = [];
foreach (ShopStoreAPI::categories() as $c) $CAT[$c['name']] = $c['slug'];
$brittle = ShopStoreAPI::productBySlug(BRITTLE_SLUG);
$BRITTLE_URL = $brittle ? '/product/' . BRITTLE_SLUG . '/' : '/brittles/';
$FLAVORS = $brittle ? array_values(array_map(static fn(array $v): string => $v['label'], array_filter($brittle['variants'], static fn(array $v): bool => $v['in_stock']))) : [];

// ── Theme ──────────────────────────────────────────────────────────────────────
$runtime->app->saveDesignTokens($actor, 'default', [
    'surface.page' => CREAM, 'surface.primary' => CREAM, 'surface.secondary' => KRAFT, 'surface.muted' => '#f3e6cc', 'surface.accent' => CHERRY, 'surface.inverse' => CHOC,
    'text.primary' => CHOC, 'text.muted' => COCOA, 'text.accent' => CHERRY, 'text.inverse' => CREAM, 'color.accent' => CHERRY, 'border.default' => '#e2cfae',
    'font.heading' => SERIF, 'font.body' => BODY, 'text.body' => COCOA, 'text.heading' => CHOC,
    'type.body_size' => '18px', 'type.flow' => '0', 'type.heading_gap' => '0', 'type.section_gutter' => '0', 'layout.content' => '1200px',
]);
Database::setSetting('site_name', NAME, $tid);
Database::setSetting('brand_favicon_path', MEDIA_DIR . '202307-chef-gregorys-logo.jpg', $tid);
// The sign-in page: his logo, the brittle photo, a line from his own product title, the label's cherry red.
foreach (['brand_logo_path' => ltrim(MEDIA_DIR, '/') . '202408-logo-transperant.jpg', 'brand_login_image_path' => ltrim(MEDIA_DIR, '/') . '202303-regular-three.webp',
          'brand_login_tagline' => 'World-famous peanut brittle!! Over 20 flavors to choose from', 'brand_accent_color' => CHERRY] as $k => $v) {
    Database::setSetting($k, $v, $tid);
}
Database::setSetting(PageRoutes::SETTING_TRAILING_SLASH, '1', $tid);
Database::setSetting('studio_seo_title_format', '%title%', $tid);
Database::setSetting('studio_seo_robots', getenv('GMN_LIVE') === '1' ? 'index,follow' : 'noindex,nofollow', $tid);
// Fonts and the business for search engines: Studio's own Site & SEO settings, so the custom head code stays empty.
Database::setSetting(\Slate\Module\StudioBuilder\Render\Seo\WebFonts::SETTING, "Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700\nKarla: 400, 500, 700, 800\nCaveat: 600", $tid);
\Slate\Module\StudioBuilder\Render\Seo\StructuredData::save($tid, [
    'type' => 'OnlineStore', 'name' => NAME, 'alternate_name' => SHORT, 'logo_media_id' => MID('202307-chef-gregorys-logo.jpg'),
    'founder' => 'Gregory S. Kalatsky', 'same_as' => ['https://chefgregory.com/', 'https://chefgquesbbq.com/'],
]);
Database::setSetting(StudioCodePolicy::SETTING_HEAD_SNIPPET, '', $tid);
// Everything is styled with Studio's own settings: no custom CSS.
Database::setSetting(StudioCodePolicy::SETTING_CUSTOM_CSS, '', $tid);
say('  + theme');

// ── Shared pieces ──────────────────────────────────────────────────────────────
// The checkerboard from the labels and the chef's bandana: a 14px tile of four 7px squares in the Media library, which
// the strips repeat as their background (Studio's own background image setting).
foreach (['cream' => [0xfb, 0xf4, 0xe6], 'butter' => [0xe3, 0xa3, 0x3b]] as $name => [$r, $g, $b]) {
    $file = "checker-{$name}.png";
    $path = getcwd() . '/uploads/media/gmn/' . $file;
    if (!is_file($path)) {
        $im = imagecreatetruecolor(14, 14);
        $dark = imagecolorallocate($im, 0x3a, 0x23, 0x17);
        $light = imagecolorallocate($im, $r, $g, $b);
        imagefilledrectangle($im, 0, 0, 13, 13, $light);
        imagefilledrectangle($im, 0, 0, 6, 6, $dark);
        imagefilledrectangle($im, 7, 7, 13, 13, $dark);
        imagepng($im, $path);
    }
    if (MID($file) <= 0) {
        Media::register(MEDIA_DIR . $file, ['mime' => 'image/png', 'size_bytes' => filesize($path), 'width' => 14, 'height' => 14, 'original_name' => $file]);
    }
}
$checker = static fn(string $kind = ''): array => CTOP('Checkerboard', [],
    ['background' => ['color' => CHOC, 'image' => ['media_id' => MID($kind === 'butter' ? 'checker-butter.png' : 'checker-cream.png'), 'alt' => ''], 'fit' => 'auto', 'repeat' => 'repeat', 'position' => 'top-left'],
        'dimensions' => ['height' => '14px'], 'padding' => ['top' => '0px', 'right' => '0px', 'bottom' => '0px', 'left' => '0px'], 'layout' => ['display' => 'block', 'content_width' => 'full']]);
// The house look of the other Shop widgets, as their style parts.
$pillButton = ['background' => ['color' => CHERRY], 'color' => '#ffffff', 'border' => ['width' => '0px', 'radius' => '999px'], 'typography' => ['weight' => 'bold']];
$field = ['background' => ['color' => '#fffaf0'], 'color' => CHOC, 'border' => ['width' => '1.5px', 'style' => 'solid', 'color' => '#c9ad80', 'radius' => '14px']];
$shopParts = [
    'shop.product_image' => ['main' => ['border' => ['radius' => '22px']], 'thumbs' => ['border' => ['radius' => '10px']]],
    'shop.product_add_to_cart' => ['button' => $pillButton, 'button:hover' => ['background' => ['color' => CHOC]],
        'options' => ['background' => ['color' => '#fffaf0'], 'color' => CHOC, 'border' => ['width' => '1.5px', 'style' => 'solid', 'color' => CHOC, 'radius' => '12px'], 'typography' => ['weight' => 'semibold']],
        'option_labels' => ['color' => CHERRY, 'typography' => ['weight' => 'extrabold', 'transform' => 'uppercase', 'letter_spacing' => '1.6px', 'size' => '12px']],
        'variant_price' => ['color' => CARAMEL], 'quantity' => $field],
    'shop.product_meta' => ['label' => ['color' => CHERRY, 'typography' => ['weight' => 'extrabold', 'transform' => 'uppercase', 'letter_spacing' => '1.8px', 'size' => '12px']]],
    'shop.mini_cart' => ['link' => ['color' => CHOC, 'typography' => ['weight' => 'extrabold']], 'count' => ['background' => ['color' => CHERRY], 'color' => '#ffffff']],
    'shop.cart' => ['button' => $pillButton, 'continue' => ['border' => ['radius' => '999px']], 'title' => ['color' => CHOC],
        'image' => ['border' => ['radius' => '12px']], 'line_total' => ['color' => CHOC],
        'stepper' => ['background' => ['color' => '#fffaf0'], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => '#d7bd90', 'radius' => '999px'], 'color' => CHOC],
        'remove' => ['color' => COCOA, 'border' => ['radius' => '999px']]],
    'shop.checkout' => ['fields' => $field, 'button' => $pillButton, 'summary' => ['background' => ['color' => '#fffaf0'], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => '#e2cfae', 'radius' => '22px']]],
    'shop.order_confirmation' => ['total' => ['color' => CHOC]],
];
$shopBlock = static fn(string $type, array $props = [], array $style = []): array => B($type, $props, [], $style, ['style_parts' => $shopParts[$type] ?? []]);

$grid = static function (array $props, string $category = '') : array {
    // Everything the grid looks like is the widget's own: columns, gaps and alignment per device, the card, the badges,
    // the filter bar, and its parts' colours and borders (Style › Parts). No CSS.
    $pill = ['radius' => '999px'];
    $g = B('shop.products', $props + [
        'columns' => 4, 'per_page' => 4, 'show_pager' => false, 'button_label' => 'Add to cart', 'options_label' => 'Choose a flavor',
        'gap' => 24, 'row_gap' => 32, 'density' => 'compact', 'card_style' => 'outlined', 'card_radius' => 22, 'card_padding' => 18, 'image_ratio' => 'square',
        'image_hover' => 'second_image', 'title_lines' => '2', 'show_category' => true, 'sale_badge' => 'percent', 'button_width' => 'full',
        'filter_style' => 'chips', 'all_label' => 'Everything', 'pagination' => 'load_more', 'load_more_label' => 'Show more goodies',
    ], [], [], [
        'responsive' => [
            'tablet' => ['props' => ['columns' => 2, 'gap' => 18, 'row_gap' => 24]],
            'mobile' => ['props' => ['columns' => 2, 'gap' => 10, 'row_gap' => 14, 'card_padding' => 10]],
        ],
        'style_parts' => [
            'card'          => ['background' => ['color' => '#fffaf0'], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => '#e2cfae']],
            'image'         => ['background' => ['color' => '#f3e6cc']],
            'category'      => ['color' => CHERRY, 'typography' => ['weight' => 'bold', 'letter_spacing' => '1.6px', 'size' => '11px']],
            'title'         => ['color' => CHOC, 'typography' => ['family' => 'heading', 'weight' => 'semibold', 'size' => '19px', 'line_height' => '24px']],
            'price'         => ['color' => CARAMEL, 'typography' => ['weight' => 'extrabold']],
            'button'        => ['background' => ['color' => CHERRY], 'color' => '#ffffff', 'border' => ['width' => '0px', 'radius' => '999px'], 'typography' => ['weight' => 'bold']],
            'button:hover'  => ['background' => ['color' => CHOC]],
            'badge'         => ['background' => ['color' => CHOC], 'color' => CREAM, 'border' => $pill],
            'sale_badge'    => ['background' => ['color' => CHERRY], 'color' => '#ffffff'],
            'filter'        => ['color' => CHOC, 'background' => ['color' => '#fffaf0'], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => CHOC] + $pill, 'typography' => ['weight' => 'bold']],
            'filter:hover'  => ['background' => ['color' => KRAFT]],
            'filter_active' => ['background' => ['color' => CHOC], 'color' => CREAM],
            'fields'        => ['color' => CHOC, 'background' => ['color' => '#fffaf0'], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => CHOC] + $pill, 'typography' => ['weight' => 'semibold']],
            'count'         => ['color' => COCOA, 'typography' => ['weight' => 'semibold']],
            'more'          => ['background' => ['color' => CHOC], 'color' => CREAM, 'border' => ['width' => '0px', 'radius' => '999px'], 'typography' => ['weight' => 'bold']],
            'more:hover'    => ['background' => ['color' => CHERRY]],
        ],
    ]);
    $g['bindings'] = ['items' => ['provider' => 'shop.products'] + ($category !== '' ? ['params' => ['category' => $category]] : [])];
    return $g;
};
$pageHead = static fn(string $eye, string $title, string $text, string $mark = ''): array => SEC('Page heading', [VBOX(array_filter([
    $mark !== '' ? MARK($mark, CARAMEL, '30px', 'center') : null, EYE($eye, CHERRY, 'center'), H($title, 'h1', '60px', '40px', CHOC, 'center'),
    $text !== '' ? B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [P($text, COCOA, '19px', 'center')], ['dimensions' => ['max_width' => '720px'], 'margin' => ['left' => 'auto', 'right' => 'auto']]) : null,
]), '12px')], CREAM, '72px', '44px');

// ── Header and footer ──────────────────────────────────────────────────────────
StudioMenus::save($tid, 'header', 'Header', [
    ['label' => 'Shop', 'url' => '/products/'], ['label' => 'Peanut Brittle', 'url' => '/brittles/'], ['label' => 'Recipes', 'url' => '/recipes/'],
    ['label' => 'Our Story', 'url' => '/about-us/'], ['label' => 'Gallery', 'url' => '/gallery/'], ['label' => 'Contact', 'url' => '/contact-us/'],
], 'header');
$logo = static fn(string $w, string $mw): array => B('core.image', ['media' => IMGREF('202408-logo-transperant.jpg', NAME), 'aspect_ratio' => 'auto', 'rounded' => false, 'link_to' => 'custom', 'url' => '/'], [],
    ['dimensions' => ['width' => $w], 'effects' => ['blend' => 'multiply']], ['responsive' => ['mobile' => ['style' => ['dimensions' => ['width' => $mw]]]]]);
$nav = B('core.nav_menu', ['menu' => 'header', 'items' => [], 'layout' => 'horizontal', 'align' => 'right', 'aria_label' => 'Main menu', 'toggle_breakpoint' => 'tablet', 'toggle_cta_label' => 'Shop now', 'toggle_cta_url' => '/products/'], [],
    ['color' => CHOC] + TYPE(BODY, '16px', '800'), ['style_parts' => [
        'link' => ['color' => CHOC], 'current' => ['color' => CHERRY], 'burger' => ['color' => CHOC], 'panel' => ['background' => ['color' => CREAM]],
        'cta' => ['background' => ['color' => CHERRY], 'color' => '#ffffff', 'border' => ['radius' => '999px']],
    ]]);
$bar = CTOP('Header', [
    $logo('150px', '118px'),
    ROW([$nav, $shopBlock('shop.mini_cart', ['label' => 'Cart', 'show_total' => false])], '18px', ['align' => 'center', 'justify' => 'end'], ['layout' => ['direction' => 'row', 'gap' => '10px']]),
], ['background' => ['color' => CREAM], 'padding' => ['top' => '10px', 'right' => '24px', 'bottom' => '10px', 'left' => '24px'],
    'layout' => ['display' => 'flex', 'direction' => 'row', 'gap' => '20px', 'justify' => 'between', 'align' => 'center', 'content_width' => 'boxed', 'content_max' => '1200px']],
    ['mobile' => ['style' => ['padding' => ['top' => '8px', 'right' => '16px', 'bottom' => '8px', 'left' => '16px']]]]);
// The bar stays at the top while the page scrolls (Studio's own sticky setting), with a shadow once it is stuck.
$bar['interactions']['sticky'] = ['edge' => 'top', 'shadow' => true, 'background' => CREAM, 'shrink' => true];
build_page('default', 'Header', 'header_partial', 'standalone', [$checker(), $bar]);

$fh = static fn(string $t): array => EYE($t, BUTTER);
$links = static fn(array $list): array => VBOX(array_map(static fn(array $x): array => LNK($x[0], $x[1], CREAM, '16px'), $list), '8px');
build_page('default', 'Footer', 'footer_partial', 'standalone', [$checker('butter'), CTOP('Footer', [
    ROW([
        W(VBOX([MARK('Gourmet Me Now!!', BUTTER, '40px'), P('Chef Gregory’s whimsical treats: peanut brittle in over 20 flavors, toffee, Southwestern Flair seasonings and Ginger Soy dressing.', '#e9dcc6', '16px'), P('EST. 1997', BUTTER, '14px', 'left', '800')], '10px'), '34%'),
        W(VBOX([$fh('Shop'), $links([['All products', '/products/'], ['Peanut brittle', '/brittles/'], ['Toffee & treats', '/products/?category=' . ($CAT['Toffee & Treats'] ?? '')], ['Seasonings & dressing', '/products/?category=' . ($CAT['Seasonings & Dressing'] ?? '')], ['Your cart', '/cart/']])], '12px'), '20%'),
        W(VBOX([$fh('Explore'), $links([['Recipes', '/recipes/'], ['Our story', '/about-us/'], ['Gallery', '/gallery/'], ['Contact', '/contact-us/']])], '12px'), '18%'),
        W(VBOX([$fh('The family'), $links([['Chef Gregory’s Catering', 'https://chefgregory.com/'], ['Chef Gregory’s BBQ Smokehouse', 'https://chefgquesbbq.com/']])], '12px'), '20%'),
    ], '28px', ['justify' => 'between'], ['layout' => ['direction' => 'column', 'gap' => '30px']]),
    B('core.divider', ['weight' => 'thin'], [], ['margin' => ['top' => '40px', 'bottom' => '16px']]),
    P('© ' . date('Y') . ' ' . NAME . '. All rights reserved.', '#bba98e', '14px'),
], ['background' => ['color' => CHOC], 'padding' => ['top' => '60px', 'right' => '24px', 'bottom' => '26px', 'left' => '24px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'content_width' => 'boxed', 'content_max' => '1200px']],
    ['mobile' => ['style' => ['padding' => ['top' => '44px', 'right' => '16px', 'bottom' => '24px', 'left' => '16px']]]])]);
say('  + header and footer');

// ── Home ───────────────────────────────────────────────────────────────────────
$sticker = B('core.text', ['content' => 'Est. 1997', 'size' => 'inherit'], [], [
    'position' => ['mode' => 'absolute', 'top' => '-14px', 'right' => '-10px'], 'dimensions' => ['width' => '104px', 'height' => '104px'],
    'layout' => ['display' => 'flex', 'justify' => 'center', 'align' => 'center'], 'align' => ['base' => 'center'],
    'padding' => ['top' => '10px', 'right' => '10px', 'bottom' => '10px', 'left' => '10px'], 'background' => ['color' => CHERRY], 'color' => CREAM,
    'border' => ['radius' => '999px'], 'shadow' => ['x' => '0px', 'y' => '8px', 'blur' => '18px', 'spread' => '-8px', 'color' => 'rgba(0,0,0,0.45)'],
    'effects' => ['transform' => ['rotate' => 10]], 'typography' => ['font_family' => HAND, 'size' => '22px', 'weight' => '600', 'line_height' => '1'],
], ['responsive' => ['mobile' => ['style' => ['dimensions' => ['width' => '78px', 'height' => '78px'], 'typography' => ['size' => '18px']]]]]);
$hero = SEC('Hero', [ROW([
    W(VBOX([
        MARK('Gourmet Me Now!!', CARAMEL, '36px'),
        H('Over 20 flavors of peanut brittle', 'h1', '72px', '44px'),
        P(BRITTLE_LINE, COCOA, '20px'),
        BTNS([BTN('Pick your flavor', $BRITTLE_URL), BTN('Shop everything', '/products/', 'outline')], '14px'),
    ], '16px'), '52%'),
    W(VBOX([PIC('202303-regular-three.webp', 'Chef Gregory’s peanut brittle on marble, with the Gourmet Me Now boxes behind it', '460px', '260px', true), $sticker], '0px', ['position' => ['mode' => 'relative']]), '44%'),
], '44px', ['justify' => 'between', 'align' => 'center'], ['layout' => ['direction' => 'column', 'gap' => '30px']])], CREAM, '72px', '40px');
// The marquee takes 200 characters: as many flavors as fit.
$runText = '';
foreach ($FLAVORS as $f) { if (mb_strlen($runText . $f . '  ✦  ') > 200) break; $runText .= $f . '  ✦  '; }
$run = CTOP('Flavors', [B('core.marquee', ['text' => rtrim($runText), 'duration' => 90, 'pause_on_hover' => true], [], ['color' => CHOC] + TYPE(SERIF, '24px', '600'))],
    ['background' => ['color' => BUTTER], 'padding' => ['top' => '12px', 'right' => '0px', 'bottom' => '12px', 'left' => '0px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'content_width' => 'full']]);
$tile = static fn(string $eye, string $title, string $img, string $alt, string $href): array => VBOX([
    PIC($img, $alt, '240px', '220px'),
    VBOX([EYE($eye), H($title, 'h3', '28px', '24px'), LNK('Shop now →', $href, CHERRY, '16px', ['typography' => ['weight' => '800']])], '6px', ['padding' => ['top' => '16px', 'right' => '18px', 'bottom' => '20px', 'left' => '18px']]),
], '0px', CARD, ['style_states' => ['hover' => ['effects' => ['transform' => ['translate_y' => '-4px']]]]]);
$cats = SEC('Shop by treat', [
    VBOX([EYE('Shop by treat'), H('Whimsical treats, made by hand', 'h2', '48px', '34px')], '8px'),
    GRID([
        $tile('Over 20 flavors', 'Peanut Brittle', '202303-maple-bacon-scaled.webp', 'Maple bacon peanut brittle', '/brittles/'),
        $tile('Sweet things', 'Toffee & Treats', '202112-picture-096-scaled-1.jpg', 'Pieces of toffee on a glass plate', '/products/?category=' . ($CAT['Toffee & Treats'] ?? '')),
        $tile('From the kitchen', 'Seasonings & Dressing', '202112-20210225_163429-scaled-1.jpg', 'Bottles of Chef Gregory’s Ginger Soy Dressing', '/products/?category=' . ($CAT['Seasonings & Dressing'] ?? '')),
    ], 3, '24px', 3, 1, '18px', ['margin' => ['top' => '30px']]),
], CREAM, '40px', '20px');
$best = SEC('Best sellers', [
    ROW([VBOX([EYE('Customer favorites'), H('Best sellers', 'h2', '48px', '34px')], '8px'), BTNS([BTN('See all products', '/products/', 'choc')], '0px')], '20px', ['justify' => 'between', 'align' => 'end'], ['layout' => ['direction' => 'column', 'gap' => '14px', 'align' => 'start']]),
    B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [$grid(['source' => 'featured'])], ['margin' => ['top' => '30px']]),
], CREAM, '64px', '40px');
$flair = SEC('Southwestern Flair', [ROW([
    W(PIC('202112-20210225_120023-scaled-1.jpg', 'Bottles of Chef Gregory’s Southwestern Flair low sodium seasoning', '440px', '240px'), '44%'),
    W(VBOX([
        MARK('Born in a cooking class', BUTTER, '32px'),
        H('Southwestern Flair Seasonings', 'h2', '48px', '34px', CREAM),
        P(STORY[0], '#e9dcc6', '18px'), P(STORY[2], '#e9dcc6', '18px'),
        BTNS([BTN('Shop the seasonings', '/products/?category=' . ($CAT['Seasonings & Dressing'] ?? ''), 'butter'), BTN('Get the recipes', '/recipes/', 'cream')], '12px'),
    ], '14px'), '50%'),
], '44px', ['justify' => 'between', 'align' => 'center'], ['layout' => ['direction' => 'column', 'gap' => '28px']])], CHOC, '84px', '52px');
$brittleStory = SEC('The brittle', [ROW([
    W(VBOX([
        EYE('Since 1899'), H('An old Southern family recipe', 'h2', '48px', '34px'),
        P(BRITTLE_LINE), P(BRITTLE_INGREDIENTS),
        BTNS([BTN('Pick your flavor', $BRITTLE_URL)], '8px'),
    ], '14px'), '50%'),
    W(PIC('202303-bourbon-whiskey-three-scaled.webp', 'Bourbon whiskey peanut brittle beside the bottles it is made with', '420px', '240px'), '44%'),
], '44px', ['justify' => 'between', 'align' => 'center'], ['layout' => ['direction' => 'column-reverse', 'gap' => '28px']])], KRAFT, '84px', '52px');
$chef = SEC('Meet the chef', [ROW([
    W(PIC('202303-chef-gregory-in-front-of-gourmet-product-line1.webp', 'Chef Gregory Kalatsky in his chef’s whites behind a table of his seasonings, dressing and peanut brittle', '380px', '280px'), '42%'),
    W(VBOX([EYE('Meet the taste expert'), H('Chef Gregory Kalatsky', 'h2', '44px', '32px'), P(CHEF_TRAINING), BTNS([BTN('Read our story', '/about-us/', 'choc')], '6px')], '12px'), '52%'),
], '40px', ['justify' => 'between', 'align' => 'center'], ['layout' => ['direction' => 'column', 'gap' => '24px']])], CREAM, '84px', '52px');
$family = SEC('The family', [GRID([
    VBOX([EYE('Chef Gregory’s Catering'), H('Planning an event?', 'h3', '30px', '26px'), GROW(P(CATERING_LINE)), BTNS([BTN('Visit chefgregory.com', 'https://chefgregory.com/', 'outline')], '6px')], '10px', CARD + ['padding' => ['top' => '26px', 'right' => '26px', 'bottom' => '26px', 'left' => '26px']]),
    VBOX([EYE('Chef Gregory’s BBQ'), H('Barbecue Smokehouse Emporium', 'h3', '30px', '26px'), GROW(P('Memphis-style BBQ in Atascadero.')), BTNS([BTN('Visit chefgquesbbq.com', 'https://chefgquesbbq.com/', 'outline')], '6px')], '10px', CARD + ['padding' => ['top' => '26px', 'right' => '26px', 'bottom' => '26px', 'left' => '26px']]),
], 2, '24px', 2, 1, '18px')], KRAFT, '64px', '40px');
build_page('home', 'Home', 'page', 'homepage', [$hero, $run, $cats, $best, $flair, $brittleStory, $chef, $family], [
    'seo_title' => "Peanut Brittle in Over 20 Flavors | Chef Gregory's Gourmet Me Now",
    'seo_desc' => 'Hand-made peanut brittle in over 20 flavors from an old Southern family recipe from 1899, plus toffee, Southwestern Flair low sodium seasonings and Ginger Soy dressing by Chef Gregory Kalatsky.',
    'image' => '202303-regular-three.webp',
]);
say('  + home');

// ── Shop ───────────────────────────────────────────────────────────────────────
build_page('products', 'Shop', 'page', 'standalone', [
    $pageHead('Gourmet Me Now', 'Shop', PRODUCTS_LINE),
    SEC('Products', [$grid(['source' => 'all', 'columns' => 3, 'per_page' => 6, 'show_filter' => true, 'show_search' => true, 'show_sort' => true, 'show_count' => true, 'show_pager' => true, 'empty_message' => 'Nothing here yet.'])], CREAM, '24px', '16px'),
], ['seo_title' => "Shop Peanut Brittle, Toffee & Seasonings | Gourmet Me Now", 'seo_desc' => 'Peanut brittle in over 20 flavors, English and vegan toffee, a chocolate puddle brownie cake, Southwestern Flair low sodium seasonings and Ginger Soy dressing.', 'image' => '202303-regular-three.webp']);
build_page('brittles', 'Peanut Brittle', 'page', 'standalone', [
    $pageHead('Over 20 flavors', 'Peanut Brittle', BRITTLE_LINE, 'Since 1899'),
    SEC('Flavors', [VBOX([EYE('Today’s flavors', CHERRY, 'center'), B('shop.product_options', ['product' => BRITTLE_SLUG, 'display' => 'chips', 'density' => 'compact', 'align' => 'center', 'show_photo' => true,
        'show_price' => false, 'sold_out' => 'muted', 'visible' => 12, 'phone_row' => true, 'link' => true], [], [], ['style_parts' => [
            'option' => ['background' => ['color' => '#fffaf0'], 'color' => CHOC, 'border' => ['width' => '1px', 'style' => 'solid', 'color' => CHOC, 'radius' => '999px'], 'typography' => ['weight' => 'bold']],
            'option:hover' => ['border' => ['color' => CHERRY], 'color' => CHERRY],
            'more' => ['background' => ['color' => CHOC], 'color' => CREAM, 'border' => ['width' => '0px', 'radius' => '999px'], 'typography' => ['weight' => 'bold']],
        ], 'responsive' => ['mobile' => ['props' => ['align' => 'left']]]]), BTNS([BTN('Pick your flavor', $BRITTLE_URL)], '6px', true)], '14px')], KRAFT, '36px', '24px'),
    SEC('Brittles', [$grid(['source' => 'all', 'columns' => 3, 'per_page' => 24, 'show_pager' => false], $CAT['Peanut Brittle'] ?? '')], CREAM, '56px', '36px'),
], ['seo_title' => "Peanut Brittle in Over 20 Flavors | Gourmet Me Now", 'seo_desc' => BRITTLE_LINE, 'image' => '202303-maple-bacon-scaled.webp']);

// The product page: Shop's Single template for Products, in the house style.
TEMPLATE('template-product', 'Single: Product', 'single', 'product', [
    SEC('Product', [ROW([
        W(B('shop.product_image', ['ratio' => 'square', 'show_thumbnails' => true, 'thumb_position' => 'left', 'thumb_size' => 68], [], [],
            ['style_parts' => $shopParts['shop.product_image'], 'responsive' => ['tablet' => ['props' => ['thumb_size' => 60]], 'mobile' => ['props' => ['thumb_size' => 52]]]]), '50%'),
        W(VBOX([
            B('shop.product_title', ['tag' => 'h1'], [], ['color' => CHOC] + TYPE(SERIF, '46px', '600', ['line_height' => '1.08']), ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => '34px']]]]]),
            B('shop.product_price', ['show_vat_note' => false], [], TYPE(BODY, '26px', '800')),
            B('shop.product_stock', ['low_stock' => 3, 'show_in_stock' => true]),
            B('shop.product_description', ['source' => 'short'], [], ['color' => COCOA] + TYPE(BODY, '18px'), ['when_field' => 'short_description']),
            $shopBlock('shop.product_add_to_cart', ['button_label' => 'Add to cart', 'show_quantity' => true]),
            $shopBlock('shop.product_meta', ['show_sku' => false, 'show_categories' => true, 'show_tags' => false, 'show_weight' => true, 'show_dimensions' => false]),
        ], '16px'), '44%'),
    ], '44px', ['justify' => 'between', 'align' => 'start'], ['layout' => ['direction' => 'column', 'gap' => '26px']])], CREAM, '56px', '28px'),
    // Shown only when the product has a long description (most of the old shop's products have only a short one).
    gm_when(SEC('About this treat', [VBOX([EYE('About this treat'), B('shop.product_description', ['source' => 'long'], [], ['color' => COCOA] + TYPE(BODY, '18px', '400', ['line_height' => '1.7']))], '12px',
        CARD + ['padding' => ['top' => '30px', 'right' => '32px', 'bottom' => '30px', 'left' => '32px']])], KRAFT, '56px', '32px'), 'description'),
    SEC('More to love', [VBOX([MARK('You might also like', CARAMEL, '30px'), H('More to love', 'h2', '40px', '30px')], '6px'), B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [$grid(['source' => 'related'])], ['margin' => ['top' => '26px']])], CREAM, '64px', '40px'),
]);

// Cart, checkout and the confirmation, at the old addresses.
foreach ([['cart', 'Your cart', 'shop.cart', 'Almost yours'], ['checkout', 'Checkout', 'shop.checkout', 'Nearly there'], ['order-received', 'Order received', 'shop.order_confirmation', 'Thank you!']] as [$slug, $title, $type, $mark]) {
    build_page($slug, $title, 'page', 'standalone', [SEC($title, [VBOX([MARK($mark, CARAMEL, '30px'), H($title, 'h1', '52px', '36px'), $shopBlock($type)], '16px')], CREAM, '56px', '32px')],
        ['seo_title' => $title . ' | Gourmet Me Now']);
}
say('  + shop pages');

// ── About ──────────────────────────────────────────────────────────────────────
build_page('about-us', 'Our Story', 'page', 'standalone', [
    $pageHead('About us', 'And how we got here', '', 'Our delicious story'),
    SEC('Story', [ROW([
        W(PIC('202112-20210225_132050-scaled-1.jpg', 'Chef Gregory’s Southwestern Flair seasonings', '460px', '260px'), '42%'),
        W(VBOX(array_merge([EYE('Southwestern Flair'), H('From a class called Chill’n and Grill’n', 'h2', '40px', '30px')], array_map(static fn(string $t): array => P($t), STORY)), '14px'), '52%'),
    ], '44px', ['justify' => 'between', 'align' => 'center'], ['layout' => ['direction' => 'column', 'gap' => '26px']])], CREAM, '40px', '20px'),
    SEC('The chef', [ROW([
        W(VBOX([EYE('Meet the taste expert', BUTTER), H('Chef Gregory Kalatsky', 'h2', '44px', '32px', CREAM), P(CHEF_BIO, '#e9dcc6'), P(CHEF_TRAINING, '#e9dcc6'), MARK('Gregory S. Kalatsky — Founder / Exe. Chef / CCE / CEC', BUTTER, '26px')], '14px'), '58%'),
        W(PIC('202303-chef-gregory-in-front-of-gourmet-product-line1.webp', 'Chef Gregory Kalatsky in his chef’s whites behind a table of his seasonings, dressing and peanut brittle', '440px', '280px'), '34%'),
    ], '44px', ['justify' => 'between', 'align' => 'center'], ['layout' => ['direction' => 'column', 'gap' => '26px']])], CHOC, '80px', '48px'),
    SEC('Catering', [VBOX([EYE('Chef Gregory’s EST 1997', CHERRY, 'center'), H('We cater, too', 'h2', '40px', '30px', CHOC, 'center'), P(CATERING_LINE, COCOA, '19px', 'center'), BTNS([BTN('Visit chefgregory.com', 'https://chefgregory.com/', 'choc')], '8px', true)], '12px')], KRAFT, '64px', '40px'),
], ['seo_title' => "Our Story | Chef Gregory's Gourmet Me Now", 'seo_desc' => 'How Chef Gregory Kalatsky created Southwestern Flair Seasonings in his Chill’n and Grill’n class in 1999, and the chef behind Gourmet Me Now.', 'image' => '202112-20210225_132050-scaled-1.jpg']);
say('  + about');

// ── Recipes ────────────────────────────────────────────────────────────────────
$R = gmn_recipes($BUNDLE);
$anchor = static fn(string $title): string => 'r-' . ShopStoreAPI::slugify($title, 40);
$cards = [];
foreach ($R['recipes'] as $r) {
    $left = array_filter([
        $r['yield'] !== '' ? P($r['yield'], CHERRY, '16px', 'left', '800') : null,
        $r['ingredients'] !== [] ? EYE('You need', CHOC) : null,
        $r['ingredients'] !== [] ? B('core.list', ['style' => 'dash', 'items' => array_map(static fn(string $t): array => ['text' => $t], $r['ingredients'])], [], ['color' => CHOC] + TYPE(BODY, '17px', '500')) : null,
    ]);
    $right = array_filter([
        $r['steps'] !== [] ? EYE('How to make it', CHOC) : null,
        $r['steps'] !== [] ? B('core.list', ['style' => 'number', 'items' => array_map(static fn(string $t): array => ['text' => $t], $r['steps'])], [], ['color' => COCOA] + TYPE(BODY, '17px'), ['style_parts' => ['marker' => ['color' => CHERRY, 'typography' => ['family' => 'heading', 'weight' => 'bold']]]]) : null,
    ]);
    $cards[] = CTOP($r['title'], array_values(array_filter([
        H($r['title'], 'h2', '36px', '28px'),
        ...array_map(static fn(string $t): array => P($t), $r['intro']),
        ROW(array_values(array_filter([$left !== [] ? W(VBOX(array_values($left), '10px'), $right !== [] ? '38%' : '100%') : null, $right !== [] ? W(VBOX(array_values($right), '10px'), $left !== [] ? '56%' : '100%') : null])), '36px', ['justify' => 'between', 'align' => 'start'], ['layout' => ['direction' => 'column', 'gap' => '20px']]),
        ...array_map(static fn(string $t): array => MARK($t, CARAMEL, '24px'), $r['notes']),
        ...array_map(static fn(string $u): array => B('core.video', ['source' => 'youtube', 'url' => $u, 'privacy' => true], [], ['dimensions' => ['max_width' => '640px']]), $r['videos']),
    ])), ['background' => ['color' => '#fffaf0'], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => '#e2cfae', 'radius' => '22px'], 'padding' => ['top' => '34px', 'right' => '34px', 'bottom' => '34px', 'left' => '34px'],
        'margin' => ['bottom' => '26px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '14px', 'content_width' => 'boxed', 'content_max' => '1000px']],
        ['mobile' => ['style' => ['padding' => ['top' => '22px', 'right' => '18px', 'bottom' => '22px', 'left' => '18px']]]], ['id' => $anchor($r['title'])]);
}
// Jump links to each recipe: small pill buttons that fill chocolate on hover (native button hover, no CSS).
$jump = ROW(array_map(static fn(array $r): array => B('core.button', ['link' => L($r['title'], '#' . $anchor($r['title'])), 'variant' => 'secondary'], [], [], ['style_parts' => BTN_PARTS(
    ['background' => ['color' => '#fffaf0'], 'color' => CHOC, 'border' => ['width' => '1px', 'style' => 'solid', 'color' => CHOC, 'radius' => '999px'],
     'padding' => ['top' => '7px', 'right' => '14px', 'bottom' => '7px', 'left' => '14px'], 'typography' => ['size' => '14px', 'weight' => 'semibold']], CHOC, CREAM)]), $R['recipes']),
    '8px', ['wrap' => 'wrap', 'justify' => 'center'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']]);
build_page('recipes', 'Recipes', 'page', 'standalone', [
    $pageHead('Cook with Southwestern Flair', 'Recipes', $R['intro']['lines'][0] ?? '', 'From Chef Gregory’s kitchen'),
    SEC('Seasoning', [ROW([
        W(VBOX(array_merge([H($R['intro']['title'], 'h2', '36px', '28px')], array_map(static fn(string $t): array => P($t), array_slice($R['intro']['lines'], 1, 4)), [BTNS([BTN('Shop the seasonings', '/products/?category=' . ($CAT['Seasonings & Dressing'] ?? ''))], '6px')]), '12px'), '46%'),
        W($R['intro']['video'] !== '' ? B('core.video', ['source' => 'youtube', 'url' => $R['intro']['video'], 'privacy' => true]) : PIC('202112-20210225_152543-scaled-1.jpg', 'Southwestern Flair seasoning', '320px'), '48%'),
    ], '40px', ['justify' => 'between', 'align' => 'center'], ['layout' => ['direction' => 'column', 'gap' => '24px']])], KRAFT, '64px', '40px'),
    SEC('Jump to a recipe', [$jump], CREAM, '40px', '28px'),
    SEC('All recipes', $cards, CREAM, '8px', '8px', '', '0px', '1000px'),
    SEC('Tips', [VBOX([EYE('Tips for Gourmet Me Now!!'), H('More ways to use Southwestern Flair', 'h2', '40px', '30px'),
        B('core.accordion', ['allow_multiple' => true, 'items' => array_map(static fn(array $t): array => ['heading' => rtrim($t[0], ':'), 'body' => $t[1]], $R['tips'])])], '14px')], KRAFT, '72px', '44px', '', '0px', '1000px'),
], ['seo_title' => "Southwestern Flair Recipes | Chef Gregory's Gourmet Me Now", 'seo_desc' => 'Chef Gregory’s recipes for Southwestern Flair low sodium seasoning: burgers, guacamole, pico de gallo, roast chicken, garlic bread, meatloaf and more.', 'image' => '202112-20210225_152543-scaled-1.jpg']);
say('  + recipes');

// ── Gallery ────────────────────────────────────────────────────────────────────
$shots = [];
foreach (Database::rows("SELECT title, images_json FROM shop_products WHERE tenant_id = ? AND status = 'published' ORDER BY featured DESC, id", [$tid]) as $p) {
    foreach (json_decode((string) $p['images_json'], true) ?: [] as $u) {
        if (!isset($shots[$u])) $shots[$u] = ['url' => $u, 'alt' => ShopImport::plainTitle((string) $p['title'])];
    }
}
build_page('gallery', 'Gallery', 'page', 'standalone', [
    $pageHead('Our delicious products', 'Gallery', ''),
    SEC('Photos', [B('core.gallery', ['images' => array_values(array_slice($shots, 0, 40)), 'columns' => '3', 'layout' => 'masonry', 'aspect_ratio' => '4:3', 'rounded' => true, 'captions' => 'none'])], CREAM, '24px', '16px'),
], ['seo_title' => "Gallery | Chef Gregory's Gourmet Me Now", 'seo_desc' => 'Photos of Chef Gregory’s peanut brittle, toffee, seasonings and dressing.', 'image' => '202303-regular-three.webp']);

// ── Contact ────────────────────────────────────────────────────────────────────
$ff = static fn(string $type, string $name, string $label, bool $req = false, string $width = '100'): array => B('core.form_field', ['field_type' => $type, 'name' => $name, 'label' => $label, 'placeholder' => '', 'required' => $req, 'width' => $width]);
build_page('contact-us', 'Contact Us', 'page', 'standalone', [
    $pageHead('Get in touch', 'Contact us', 'Please feel free to reach out to us.'),
    SEC('Contact', [ROW([
        W(VBOX([EYE('Open hours'), H('By appointment', 'h2', '36px', '28px'), P('Open for business by appointment only. ' . OFFICE_HOURS),
            EYE('Catering'), P('If you are looking for appetizers or catering services please visit Chef Gregory’s Catering.'), BTNS([BTN('chefgregory.com', 'https://chefgregory.com/', 'outline')], '4px')], '12px'), '40%'),
        W(VBOX([H('Send us a message', 'h2', '30px', '26px'), B('core.form', ['form_name' => 'Gourmet Me Now contact', 'notify_email' => EMAIL, 'submit_text' => 'Send message', 'submit_variant' => 'primary', 'success_message' => 'Thank you! We will get back to you soon.'], [
            $ff('text', 'name', 'Your name', true, '50'), $ff('email', 'email', 'Email', true, '50'), $ff('tel', 'phone', 'Phone', false), $ff('textarea', 'message', 'Message', true),
        ], [], ['style_parts' => ['button' => $pillButton + ['padding' => ['top' => '14px', 'right' => '26px', 'bottom' => '14px', 'left' => '26px']], 'input' => ['background' => ['color' => '#fffaf0'], 'border' => ['width' => '1.5px', 'style' => 'solid', 'color' => '#c9ad80', 'radius' => '14px']]]])], '14px', CARD + ['padding' => ['top' => '28px', 'right' => '28px', 'bottom' => '28px', 'left' => '28px']]), '54%'),
    ], '40px', ['justify' => 'between', 'align' => 'start'], ['layout' => ['direction' => 'column', 'gap' => '26px']])], CREAM, '32px', '20px'),
], ['seo_title' => "Contact | Chef Gregory's Gourmet Me Now", 'seo_desc' => 'Contact Chef Gregory’s Gourmet Me Now about peanut brittle, toffee, seasonings and orders.']);
say('  + about, recipes, gallery, contact');

// Old addresses with nothing to keep: the shop page, empty categories, and the old one-flavour brittle pages, which
// go to the Peanut Brittle product with that flavour already picked (?option=), so their Google links keep working.
$redirects = [
    ['from' => '/evercompare/', 'to' => '/products/', 'code' => 301], ['from' => '/my-account/', 'to' => '/products/', 'code' => 302],
    ['from' => '/shop/', 'to' => '/products/', 'code' => 301],
    ['from' => '/product-category/brittles/', 'to' => '/brittles/', 'code' => 301], ['from' => '/product-category/gourmet/', 'to' => '/products/', 'code' => 301],
    ['from' => '/product-category/uncategorized/', 'to' => '/products/', 'code' => 301],
];
$brittleRow = Database::row("SELECT * FROM shop_products WHERE tenant_id = ? AND slug = 'peanut-brittles'", [$tid]);
$flavours = [];
foreach ($brittleRow ? ShopStoreAPI::attributes($brittleRow) : [] as $attr) foreach ((array) ($attr['values'] ?? []) as $o) $flavours[] = (string) $o;
usort($flavours, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
$norm = static fn(string $t): string => trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower(html_entity_decode($t, ENT_QUOTES))));
foreach (Database::rows("SELECT slug, title FROM shop_products WHERE tenant_id = ? AND status = 'draft'", [$tid]) as $d) {
    $pick = '';
    $t = ' ' . $norm((string) $d['title']) . ' ';
    foreach ($flavours as $o) {
        $on = ' ' . $norm($o) . ' ';
        if (trim($on) !== '' && (str_contains($t, $on) || str_contains($on, ' ' . trim(str_replace(' peanut brittle ', ' ', $t)) . ' '))) { $pick = $o; break; }
    }
    $isBrittle = $pick !== '' || str_contains($t, ' brittle ');
    $redirects[] = ['from' => '/product/' . $d['slug'] . '/', 'to' => $isBrittle ? '/product/peanut-brittles/' . ($pick !== '' ? '?option=' . rawurlencode($pick) : '') : '/products/', 'code' => 301];
}
Database::setSetting('studio_redirects', json_encode($redirects, JSON_UNESCAPED_SLASHES), $tid);
say('  + redirects: ' . count($redirects));
PageRoutes::forget($tid);
Database::query('DELETE FROM studiobuilder_compilations WHERE tenant_id = ?', [$tid]);
say('site done');
