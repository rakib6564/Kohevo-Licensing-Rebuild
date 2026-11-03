<?php
// Chef Gregory's Barbecue Smokehouse Emporium (chefgquesbbq.com) in Kohevo Studio: menu and shop details live in
// content types with meta fields; templates hold the layout.
//
//   cd <site> && php <bundle>/build/site.php      (media/ comes from build/capture.sh; see README)
//
// It replaces every page it builds, so never rerun it on a site someone has edited. GQ_LIVE=1 lifts the noindex.
declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Menu\StudioMenus;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PageRoutes;

require __DIR__ . '/lib.php';
require __DIR__ . '/content.php';
$BUNDLE = dirname(__DIR__);

// ── Media ──────────────────────────────────────────────────────────────────────
$dst = getcwd() . '/uploads/media/gq';
if (!is_dir($dst)) { mkdir($dst, 0755, true); }
foreach (glob("{$BUNDLE}/media/*.*") ?: [] as $file) {
    $name = basename($file);
    if (!is_file("{$dst}/{$name}")) { copy($file, "{$dst}/{$name}"); }
    if (MID($name) <= 0) {
        $size = @getimagesize("{$dst}/{$name}") ?: [null, null, 'mime' => ''];
        Media::register(MEDIA_DIR . $name, ['mime' => (string) ($size['mime'] ?? ''), 'size_bytes' => filesize("{$dst}/{$name}"), 'width' => $size[0] ?? null, 'height' => $size[1] ?? null, 'original_name' => $name]);
    }
}
// Old upload addresses stay; the live switch brings the whole folder.
$legacy = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$BUNDLE}/media/legacy", FilesystemIterator::SKIP_DOTS));
foreach ($legacy as $f) {
    $to = getcwd() . '/wp-content/uploads/' . substr((string) $f, strlen("{$BUNDLE}/media/legacy/"));
    if (!is_dir(dirname($to))) { mkdir(dirname($to), 0755, true); }
    copy((string) $f, $to);
}
say('  + media');

// ── Theme ──────────────────────────────────────────────────────────────────────
$runtime->app->saveDesignTokens($actor, 'default', [
    'surface.page' => PAPER, 'surface.primary' => PAPER, 'surface.secondary' => LIGHT, 'surface.muted' => LIGHT, 'surface.accent' => SAUCE, 'surface.inverse' => SOOT,
    'text.primary' => SOOT, 'text.muted' => SMOKE, 'text.accent' => SAUCE, 'text.inverse' => '#ffffff', 'color.accent' => SAUCE, 'border.default' => SOOT,
    'font.heading' => SLAB, 'font.body' => BODY, 'text.body' => SMOKE, 'text.heading' => SOOT,
    'type.body_size' => '18px', 'type.flow' => '0', 'type.heading_gap' => '0', 'type.section_gutter' => '0', 'layout.content' => '1240px',
]);
Database::setSetting('site_name', NAME, $tid);
Database::setSetting('brand_favicon_path', MEDIA_DIR . 'gq-logo.png', $tid);
Database::setSetting(PageRoutes::SETTING_TRAILING_SLASH, '1', $tid);
Database::setSetting('studio_seo_title_format', '%title%', $tid);
// Hidden from search until the real switch; the live site's noindex is the first thing that switch removes.
Database::setSetting('studio_seo_robots', getenv('GQ_LIVE') === '1' ? 'index,follow' : 'noindex,nofollow', $tid);
$fonts = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Alfa+Slab+One&family=Barlow+Condensed:wght@500;600;700&family=Barlow:wght@400;500;600;700&family=Permanent+Marker&display=swap">';
$spec = [];
foreach ([['Thursday', '12:00', '14:30'], ['Thursday', '17:00', '20:00'], ['Friday', '12:00', '14:30'], ['Friday', '17:00', '20:00'], ['Saturday', '12:00', '14:00'], ['Saturday', '17:00', '20:00']] as [$d, $o, $c]) {
    $spec[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $d, 'opens' => $o, 'closes' => $c];
}
$ld = ['@context' => 'https://schema.org', '@type' => 'BarbecueRestaurant', '@id' => 'https://chefgquesbbq.com/#restaurant', 'name' => NAME, 'alternateName' => ["Chef Gregory's BBQ Emporium", "Chef G Que's"], 'slogan' => TAGLINE, 'url' => 'https://chefgquesbbq.com/', 'telephone' => '+1-' . PHONE,
    'image' => 'https://chefgquesbbq.com' . MEDIA_DIR . 'gq-hero-bark.jpg', 'logo' => 'https://chefgquesbbq.com' . MEDIA_DIR . 'gq-logo.png', 'address' => ['@type' => 'PostalAddress', 'streetAddress' => STREET, 'addressLocality' => 'Atascadero', 'addressRegion' => 'CA', 'postalCode' => '93422', 'addressCountry' => 'US'],
    'servesCuisine' => ['Barbecue', 'Memphis-style barbecue'], 'hasMenu' => 'https://chefgquesbbq.com/menu/', 'acceptsReservations' => false, 'paymentAccepted' => 'Cash, Venmo, Visa, Mastercard, American Express, Google Pay', 'openingHoursSpecification' => $spec,
    'potentialAction' => ['@type' => 'OrderAction', 'target' => ORDER], 'founder' => ['@type' => 'Person', 'name' => 'Gregory S. Kalatsky', 'alumniOf' => 'Culinary Institute of America'], 'sameAs' => ['https://chefgregory.com/', 'https://gourmetmenow.com/']];
$jsonld = '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
Database::setSetting(StudioCodePolicy::SETTING_HEAD_SNIPPET, StudioCodePolicy::prepareSnippet($fonts . $jsonld, 'head'), $tid);
Database::setSetting(StudioCodePolicy::SETTING_CUSTOM_CSS, StudioCodePolicy::prepareCustomCss((string) file_get_contents(__DIR__ . '/design.css'))['css'], $tid);

// ── Content types ──────────────────────────────────────────────────────────────
$type = static fn(string $key, string $plural, string $singular, string $route, string $icon, int $pos, array $columns): array => [
    'key' => $key, 'singular' => $singular, 'plural' => $plural, 'route' => $route, 'is_system' => false, 'description' => '', 'show_in_menu' => true,
    'rewrite' => true, 'rewrite_slug' => trim($route, '/'), 'hide_field_names' => false, 'edit_link' => true, 'menu_icon' => $icon, 'menu_position' => $pos, 'columns' => $columns, 'filters' => [],
];
PageMeta::savePostTypes($tid, [
    'menu_item' => $type('menu_item', 'Menu Items', 'Menu Item', '/menu', 'utensils', 1, [['label' => 'Section', 'source' => 'section', 'prefix' => '', 'suffix' => '', 'sortable' => true], ['label' => 'Availability', 'source' => 'availability', 'prefix' => '', 'suffix' => '', 'sortable' => false]]),
    'location' => $type('location', 'Locations', 'Location', '/locations', 'map-pin', 2, [['label' => 'Phone', 'source' => 'phone', 'prefix' => '', 'suffix' => '', 'sortable' => false]]),
]);
$field = static fn(string $type, string $key, string $label, string $kind = 'text', array $options = []): array => ['id' => 'f_' . substr(md5($type . $key), 0, 8), 'post_type' => $type, 'label' => $label, 'key' => $key, 'type' => $kind, 'required' => false, 'placeholder' => '', 'help' => '', 'options' => $options];
$rep = static fn(string ...$subs): array => ['subfields' => array_map(static function (string $s): array { [$key, $label, $type] = explode(':', $s); return ['key' => $key, 'label' => $label, 'type' => $type]; }, $subs)];
$choices = array_map(static fn(string $s): array => ['value' => $s, 'label' => $s], [...array_column($SECTIONS, 0), 'Specials']);
PageMeta::saveFields($tid, [
    $field('menu_item', 'section', 'Section', 'select', ['choices' => $choices]), $field('menu_item', 'tagline', 'Menu line', 'textarea'), $field('menu_item', 'sizes', 'Sizes and prices', 'repeater', $rep('size:Size:text', 'portion:Portion:text', 'price:Price:text')),
    $field('menu_item', 'availability', 'Availability'), $field('menu_item', 'plate_note', 'Plate note'), $field('menu_item', 'story', 'Story', 'richtext'), $field('menu_item', 'hero_image', 'Photo', 'image'),
    $field('location', 'street', 'Street'), $field('location', 'city_line', 'City'), $field('location', 'phone', 'Phone'), $field('location', 'hours', 'Hours', 'repeater', $rep('day:Day:text', 'lunch:Lunch:text', 'dinner:Dinner:text')),
    $field('location', 'hours_note', 'Hours note'), $field('location', 'preorder_note', 'Preorder note'), $field('location', 'payment', 'Payment', 'textarea'), $field('location', 'faq', 'Questions', 'repeater', $rep('question:Question:text', 'answer:Answer:richtext')),
]);
PageRoutes::forget($tid);
say('  + content types');

// ── Items ──────────────────────────────────────────────────────────────────────
foreach ($MENU as $i => [$slug, $title, $section, $line, $story, $sizes, $img, $when, $note]) {
    $from = array_values(array_filter(array_map(static fn(array $s): string => trim($s[0] . ' ' . $s[1] . ' ' . $s[2]), $sizes)));
    $desc = trim("{$title} at Chef Gregory's BBQ in Atascadero. {$line} " . ($from ? implode(', ', array_slice($from, 0, 3)) . '.' : ''));
    build_page($slug, $title, 'page', 'standalone', [], ['type' => 'menu_item', 'date' => date('Y-m-d H:i:s', strtotime('2024-06-01') + $i * 86400), 'image' => $img ?: 'gq-logo.png', 'seo_title' => "{$title} | Memphis-Style BBQ in Atascadero", 'seo_desc' => $desc,
        'settings' => ['category' => $section, 'excerpt' => $line],
        'fields' => ['section' => $section, 'tagline' => $line, 'sizes' => array_map(static fn(array $s): array => ['size' => $s[0], 'portion' => $s[1], 'price' => $s[2]], $sizes), 'availability' => $when, 'plate_note' => $note, 'story' => $story, 'hero_image' => $img !== '' ? MEDIA_DIR . $img : ''],
    ]);
}
$visitId = build_page('visit', 'Visit the Smokehouse', 'page', 'standalone', [], ['type' => 'location', 'image' => 'gq-shop-wall.jpg', 'seo_title' => 'Hours & Location | BBQ on El Camino Real, Atascadero', 'seo_desc' => "Find Chef Gregory's BBQ at 6550 El Camino Real, Atascadero. Open Thursday to Saturday for lunch and dinner, until we run out of food. Preorder to get some.",
    'settings' => ['category' => 'Location', 'excerpt' => 'Thursday to Saturday, lunch and dinner.'],
    'fields' => ['street' => STREET, 'city_line' => CITY, 'phone' => PHONE, 'hours' => $HOURS, 'hours_note' => 'Or until we run out of food, and we do!', 'preorder_note' => 'Preorder from Sunday to Tuesday to ensure that you “get some”.', 'payment' => 'Cash, Venmo, Visa, Mastercard, American Express and Google Pay.', 'faq' => $FAQ],
]);
Database::setSetting(PageRoutes::SETTING_PAGE_PREFIXES, json_encode([(string) $visitId => '']), $tid);
PageRoutes::forget($tid);

// ── Loop Items and Single templates ────────────────────────────────────────────
$chip = static fn(string $size = '19px', string $price = '20px'): array => B('layout.flex', ['direction' => 'row', 'gap' => 'none'], [
    FV('size', ['color' => SOOT] + TYPE(COND, $size, '700', ['transform' => 'uppercase', 'letter_spacing' => '.5px'])),
    FV('portion', ['color' => SMOKE] + TYPE(COND, $size, '600')),
    FV('price', ['color' => SAUCE] + TYPE(COND, $price, '700'), ['classNames' => ['gq-price']]),
], [], ['classNames' => ['gq-chip']]);
$chips = static fn(bool $big = false): array => B('theme.repeater', ['field' => 'sizes'], [$big ? $chip('23px', '26px') : $chip()], [], ['classNames' => $big ? ['gq-chips', 'gq-chips--big'] : ['gq-chips']]);
$when = static fn(string $color): array => FV('availability', ['color' => $color] + TYPE(COND, '18px', '700', ['transform' => 'uppercase', 'letter_spacing' => '1px']));
$bare = ['layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '8px', 'content_width' => 'full', 'padding_y' => 'none']];

$cls = static function (array $c, string $class): array { $c['classNames'] = [$class]; return $c; };
$rowId = TEMPLATE('template-loop-menu-row', 'Loop Item: Menu Line', 'loop', 'menu_item', [$cls(CTOP('Menu line', [
    ROW([
        VBOX([
            B('theme.post_title', ['level' => 'h3', 'link_to_post' => true], [], ['color' => SOOT, 'typography' => ['size' => '28px', 'font_family' => COND, 'weight' => '700', 'line_height' => '1.12', 'transform' => 'uppercase']]),
            $when(SAUCE), FV('tagline', ['color' => SMOKE] + TYPE(BODY, '17px')),
        ], '4px', [], ['classNames' => ['gq-row-main']]),
        $chips(),
    ], '24px', ['justify' => 'between', 'align' => 'start'], ['layout' => ['direction' => 'column', 'gap' => '10px', 'align' => 'stretch']]),
], $bare + ['border' => ['bottom' => ['width' => '2px', 'style' => 'solid', 'color' => SOOT]], 'padding' => ['top' => '16px', 'bottom' => '16px']]), 'gq-row')]);
$cardId = TEMPLATE('template-loop-menu-card', 'Loop Item: Menu Card', 'loop', 'menu_item', [$cls(CTOP('Menu card', [
    VBOX([B('theme.post_image', [], [], ['dimensions' => ['width' => '100%']])], '0px', [], ['classNames' => ['gq-card-img']]),
    VBOX([
        B('theme.post_title', ['level' => 'h3', 'link_to_post' => true], [], ['color' => SOOT, 'typography' => ['size' => '24px', 'font_family' => COND, 'weight' => '700', 'line_height' => '1.15', 'transform' => 'uppercase']]),
        FV('tagline', ['color' => SMOKE] + TYPE(BODY, '16px')), $chips(),
    ], '10px', ['padding' => ['top' => '16px', 'right' => '16px', 'bottom' => '18px', 'left' => '16px']]),
], ['layout' => ['display' => 'flex', 'direction' => 'column', 'content_width' => 'full', 'padding_y' => 'none']]), 'gq-card')]);
$boardId = TEMPLATE('template-loop-menu-board', 'Loop Item: Specials Board', 'loop', 'menu_item', [$cls(CTOP('Specials line', [
    B('theme.post_title', ['level' => 'h3', 'link_to_post' => true], [], ['color' => MUSTARD, 'typography' => ['size' => '32px', 'font_family' => MARKER, 'weight' => '400', 'line_height' => '1.2']]),
    FV('tagline', ['color' => '#ffffff'] + TYPE(BODY, '18px')),
], $bare), 'gq-boardline')]);
$hoursBlock = static function (string $color, string $size = '18px') use ($bare): array {
    return B('theme.repeater', ['field' => 'hours'], [B('layout.flex', ['direction' => 'row', 'gap' => 'none'], [
        FV('day', ['color' => $color] + TYPE(COND, '21px', '700', ['transform' => 'uppercase', 'letter_spacing' => '.5px'])),
        VBOX([FV('lunch', ['color' => $color] + TYPE(BODY, $size, '500')), FV('dinner', ['color' => $color] + TYPE(BODY, $size, '500'))], '0px', [], ['classNames' => ['gq-hour-times']]),
    ], [], ['classNames' => ['gq-hour']])], [], ['classNames' => ['gq-hours']]);
};
$hoursId = TEMPLATE('template-loop-hours', 'Loop Item: Hours (dark)', 'loop', 'location', [CTOP('Hours', [$hoursBlock('#ffffff', '17px'), FV('hours_note', ['color' => MUSTARD] + TYPE(COND, '20px', '700', ['transform' => 'uppercase'])), FV('preorder_note', ['color' => '#ffffff'] + TYPE(BODY, '16px'))], $bare)]);
$hoursLight = TEMPLATE('template-loop-hours-light', 'Loop Item: Hours (light)', 'loop', 'location', [CTOP('Hours', [$hoursBlock(SOOT), FV('hours_note', ['color' => SAUCE] + TYPE(COND, '22px', '700', ['transform' => 'uppercase'])), FV('preorder_note', ['color' => SMOKE] + TYPE(BODY, '17px'))], $bare)]);
$ROW = UUID($rowId); $CARD = UUID($cardId); $BOARD = UUID($boardId); $HOURS_DARK = UUID($hoursId); $HOURS_LIGHT = UUID($hoursLight);
$hoursLoop = static fn(string $tpl): array => LOOP('location', $tpl, 1, 1, 'Location', 0, 'sm');
$specials = static fn(string $eyebrow): array => SEC('Specials board', [VBOX([
    MARK($eyebrow, '#ffffff', '24px', 'center'), B('core.heading', ['text' => 'Be sure to check the specials board', 'level' => 'h2'], [], ['color' => '#ffffff', 'typography' => ['size' => '46px', 'font_family' => MARKER, 'weight' => '400', 'line_height' => '1.15'], 'align' => ['base' => 'center']], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => '32px']]]]]),
    B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [LOOP('menu_item', $BOARD, 2, 4, 'Specials')], ['margin' => ['top' => '14px']]),
    P('Not always available. When they are, you’ll find them in our online ordering portal with the other specials. Daily specials: we recommend preordering.', '#ffffff', '17px', 'center'),
    BTNS([BTN('Order now', ORDER, 'mustard')], '6px', true),
], '20px', ['padding' => ['top' => '44px', 'right' => '40px', 'bottom' => '44px', 'left' => '40px']], ['classNames' => ['gq-board'], 'responsive' => ['mobile' => ['style' => ['padding' => ['top' => '30px', 'right' => '18px', 'bottom' => '30px', 'left' => '18px']]]]])], PAPER, '72px', '48px', '', '0px', '980px');

TEMPLATE('template-menu-item', 'Single: Menu Item', 'single', 'menu_item', [
    SEC('Menu item', [
        ROW([
            W(VBOX([
                LNK('← Back to the menu', '/menu/', SOOT, '18px'),
                FV('section', ['color' => SAUCE] + TYPE(COND, '20px', '700', ['transform' => 'uppercase', 'letter_spacing' => '2.5px'])),
                B('theme.post_title', ['level' => 'h1'], [], ['color' => SOOT, 'typography' => ['size' => '56px', 'font_family' => SLAB, 'weight' => '400', 'line_height' => '1.06', 'transform' => 'uppercase']], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => '36px']]]]]),
                $when(SAUCE), FV('tagline', ['color' => SOOT] + TYPE(BODY, '21px', '500')),
                FV('story', ['color' => SMOKE] + TYPE(BODY, '18px'), ['classNames' => ['gq-prose']]),
                VBOX([$chips(true)], '0px', ['background' => ['color' => LIGHT], 'border' => ['width' => '4px', 'style' => 'solid', 'color' => SOOT]], ['when_field' => 'sizes']),
                FV('plate_note', ['color' => SOOT] + TYPE(COND, '21px', '700', ['transform' => 'uppercase'])),
                BTNS([BTN('Order now', ORDER), BTN('See the full menu', '/menu/', 'mustard')]),
            ], '14px'), '54%'),
            W(VBOX([FV('hero_image', [], ['when_field' => 'hero_image'])], '0px', [], ['classNames' => ['gq-item-img']]), '42%'),
        ], '40px', ['justify' => 'between', 'align' => 'start'], ['layout' => ['direction' => 'column', 'gap' => '28px']]),
    ], PAPER, '64px', '36px'),
    SEC('More pork', [EYE('While you’re here'), H('More from the pit', 'h2', '40px', '30px'), B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [LOOP('menu_item', $CARD, 4, 4, 'Pork', 2)], ['margin' => ['top' => '24px']])], LIGHT, '64px', '48px'),
]);
TEMPLATE('template-location', 'Single: Location', 'single', 'location', [
    SEC('Visit', [
        ROW([
            W(VBOX([
                EYE('Find us on El Camino Real', MUSTARD),
                B('theme.post_title', ['level' => 'h1'], [], ['color' => '#ffffff', 'typography' => ['size' => '64px', 'font_family' => SLAB, 'weight' => '400', 'line_height' => '1.04', 'transform' => 'uppercase']], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => '40px']]]]]),
                FV('street', ['color' => '#ffffff'] + TYPE(COND, '32px', '700', ['transform' => 'uppercase'])), FV('city_line', ['color' => '#ffffff'] + TYPE(COND, '28px', '600')),
                B('core.link', ['link' => L('Call ' . PHONE, 'tel:' . PHONE), 'style' => 'plain'], [], ['color' => MUSTARD] + TYPE(COND, '28px', '700'), ['classNames' => ['gq-link'], 'dynamic' => []]),
                BTNS([BTN('Order now', ORDER, 'mustard'), BTN('Get directions', MAPS, 'black')]),
            ], '10px'), '50%'),
            W(VBOX([EYE('Hours', SOOT), $hoursBlock(SOOT), FV('hours_note', ['color' => SAUCE] + TYPE(COND, '24px', '700', ['transform' => 'uppercase'])), FV('preorder_note', ['color' => SMOKE] + TYPE(BODY, '18px', '500'))], '8px', ['background' => ['color' => LIGHT], 'border' => ['width' => '4px', 'style' => 'solid', 'color' => SOOT], 'padding' => ['top' => '26px', 'right' => '26px', 'bottom' => '26px', 'left' => '26px']]), '44%'),
        ], '40px', ['justify' => 'between', 'align' => 'center'], ['layout' => ['direction' => 'column', 'gap' => '28px']]),
    ], SAUCE, '72px', '44px'),
    SEC('Map', [ROW([
        W(VBOX([B('core.map', ['latitude' => 35.48793, 'longitude' => -120.66847, 'zoom' => 16, 'height' => 'lg', 'title' => 'Map of 6550 El Camino Real, Atascadero'], [], ['border' => ['width' => '4px', 'style' => 'solid', 'color' => SOOT]], ['classNames' => ['gq-map']])]), '58%'),
        W(PIC('gq-shop-wall.jpg', 'Inside the Smokehouse: red and orange walls, checkered trim and a shelf of Chef Gregory’s products', '544px', '260px'), '38%'),
    ], '32px', ['justify' => 'between'], ['layout' => ['direction' => 'column', 'gap' => '20px']])], PAPER, '64px', '40px'),
    SEC('Good to know', [ROW([
        W(VBOX([EYE('Good to know'), H('Questions we get at the counter', 'h2', '44px', '32px'), EYE('We accept', SOOT), FV('payment', ['color' => SMOKE] + TYPE(BODY, '18px')), MARK('In God we trust, all others pay cash.', SAUCE, '24px')], '14px'), '40%'),
        W(B('theme.repeater', ['field' => 'faq', 'display' => 'accordion', 'title_field' => 'question', 'body_field' => 'answer'], [], [], ['classNames' => ['gq-acc'], 'style_parts' => ['title' => ['color' => SOOT, 'typography' => ['size' => '22px', 'family' => 'body', 'weight' => 'semibold']]]]), '54%'),
    ], '40px', ['justify' => 'between'], ['layout' => ['direction' => 'column', 'gap' => '24px']])], LIGHT, '72px', '48px'),
]);
foreach ([['loop-menu-row', 'Loop Item: Menu Line', 'loop'], ['loop-menu-card', 'Loop Item: Menu Card', 'loop'], ['loop-menu-board', 'Loop Item: Specials Board', 'loop'], ['loop-hours', 'Loop Item: Hours (dark)', 'loop'], ['loop-hours-light', 'Loop Item: Hours (light)', 'loop'], ['menu-item', 'Single: Menu Item', 'single'], ['location', 'Single: Location', 'single']] as [$key, $name, $category]) {
    $runtime->app->saveTemplate($actor, $key, 'page_template', $category, $name, null, CanonicalDocumentSchema::emptyDocument('system', 'default', $name), null, true);
}

// ── Header and footer ──────────────────────────────────────────────────────────
StudioMenus::save($tid, 'header', 'Header', [
    ['label' => 'Menu', 'url' => '/menu/'], ['label' => 'Order', 'url' => '/order/'], ['label' => 'Our Story', 'url' => '/about/'], ['label' => 'Catering', 'url' => '/catering/'], ['label' => 'Gallery', 'url' => '/gallery/'], ['label' => 'Hours & Location', 'url' => '/visit/'],
], 'header');
$strip = CTOP('Top strip', [
    LNK(STREET . ', Atascadero', '/visit/', '#ffffff', '15px'),
    B('core.text', ['content' => 'If online ordering is open, so are we!', 'size' => 'inherit'], [], ['color' => MUSTARD] + TYPE(COND, '17px', '700', ['transform' => 'uppercase', 'letter_spacing' => '1px']), ['classNames' => ['gq-strip-mid']]),
    LNK(PHONE, 'tel:' . PHONE, '#ffffff', '15px'),
], ['background' => ['color' => SOOT], 'padding' => ['top' => '8px', 'right' => '24px', 'bottom' => '8px', 'left' => '24px'], 'layout' => ['display' => 'flex', 'direction' => 'row', 'gap' => '16px', 'justify' => 'between', 'align' => 'center', 'content_width' => 'boxed', 'content_max' => '1240px']], ['mobile' => ['style' => ['padding' => ['top' => '7px', 'right' => '16px', 'bottom' => '7px', 'left' => '16px']]]]);
$nav = B('core.nav_menu', ['menu' => 'header', 'items' => [], 'layout' => 'horizontal', 'align' => 'right', 'aria_label' => 'Main menu', 'toggle_breakpoint' => 'tablet', 'toggle_cta_label' => 'Order now', 'toggle_cta_url' => ORDER], [], ['color' => SOOT] + TYPE(COND, '21px', '700', ['transform' => 'uppercase', 'letter_spacing' => '.8px']), ['style_parts' => [
    'link' => ['color' => SOOT], 'current' => ['color' => SAUCE], 'burger' => ['color' => SOOT], 'panel' => ['background' => ['color' => PAPER]],
    'cta' => ['background' => ['color' => SAUCE], 'color' => '#ffffff', 'border' => ['radius' => '0px']],
]]);
$logo = static fn(string $w, string $mw, string $class): array => B('core.image', ['media' => IMGREF('gq-logo.png', NAME), 'aspect_ratio' => 'auto', 'rounded' => false, 'link_to' => 'custom', 'url' => '/'], [], ['dimensions' => ['width' => $w]], ['classNames' => [$class], 'responsive' => ['mobile' => ['style' => ['dimensions' => ['width' => $mw]]]]]);
$bar = CTOP('Header', [
    $logo('68px', '54px', 'gq-logo-paper'),
    ROW([$nav, B('core.button', ['link' => L('Order now', ORDER), 'variant' => 'primary'], [], [], ['classNames' => ['gq-btn', 'gq-head-cta'], 'style_parts' => ['button' => ['background' => ['color' => SAUCE], 'color' => '#ffffff', 'border' => ['width' => '3px', 'style' => 'solid', 'color' => SOOT, 'radius' => '0px'], 'padding' => ['top' => '8px', 'right' => '18px', 'bottom' => '8px', 'left' => '18px'], 'typography' => ['size' => '20px', 'weight' => 'bold', 'letter_spacing' => '1px', 'transform' => 'uppercase']]]])], '20px', ['align' => 'center', 'justify' => 'end'], [], ['dimensions' => ['width' => 'auto']]),
], ['background' => ['color' => PAPER], 'border' => ['bottom' => ['width' => '4px', 'style' => 'solid', 'color' => SOOT]], 'padding' => ['top' => '8px', 'right' => '24px', 'bottom' => '8px', 'left' => '24px'], 'layout' => ['display' => 'flex', 'direction' => 'row', 'gap' => '20px', 'justify' => 'between', 'align' => 'center', 'content_width' => 'boxed', 'content_max' => '1240px']], ['mobile' => ['style' => ['padding' => ['top' => '6px', 'right' => '16px', 'bottom' => '6px', 'left' => '16px']]]]);
build_page('default', 'Header', 'header_partial', 'standalone', [$strip, $bar]);

$fh = static fn(string $t): array => EYE($t, MUSTARD);
$links = static fn(array $list): array => VBOX(array_map(static fn(array $x): array => LNK($x[0], $x[1], '#ffffff', '17px'), $list), '8px');
build_page('default', 'Footer', 'footer_partial', 'standalone', [CTOP('Footer', [
    ROW([
        W(VBOX([$logo('150px', '130px', 'gq-logo-white'), MARK(TAGLINE . '!', MUSTARD, '24px'), P(STREET . ', ' . CITY, '#ffffff', '17px'), LNK(PHONE, 'tel:' . PHONE, '#ffffff', '17px')], '12px'), '30%'),
        W(VBOX([$fh('Hours'), $hoursLoop($HOURS_DARK)], '12px'), '32%'),
        W(VBOX([$fh('Eat'), $links([['Full menu', '/menu/'], ['Order online', ORDER], ['Catering', '/catering/'], ['Our story', '/about/'], ['Gallery', '/gallery/'], ['Hours & location', '/visit/']])], '12px'), '16%'),
        W(VBOX([$fh('The family'), $links([['Chef Gregory’s Catering', 'https://chefgregory.com/'], ['Chef Gregory’s Gourmet Products', 'https://gourmetmenow.com/']])], '12px'), '16%'),
    ], '28px', ['justify' => 'between'], ['layout' => ['direction' => 'column', 'gap' => '32px']]),
    B('core.divider', ['weight' => 'thin'], [], ['margin' => ['top' => '40px', 'bottom' => '16px']]),
    P('© ' . date('Y') . ' ' . NAME . '. All rights reserved. Prices subject to change.', '#bdb2a1', '15px'),
], ['background' => ['color' => SOOT], 'padding' => ['top' => '64px', 'right' => '24px', 'bottom' => '26px', 'left' => '24px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'content_width' => 'boxed', 'content_max' => '1240px']], ['mobile' => ['style' => ['padding' => ['top' => '48px', 'right' => '16px', 'bottom' => '24px', 'left' => '16px']]]])]);

// ── Home ───────────────────────────────────────────────────────────────────────
$run = static fn(string $text): array => CTOP('Running strip', [B('core.marquee', ['text' => $text, 'duration' => 180, 'pause_on_hover' => true], [], ['color' => SOOT] + TYPE(COND, '24px', '700', ['transform' => 'uppercase', 'letter_spacing' => '1.5px']))], ['background' => ['color' => MUSTARD], 'padding' => ['top' => '8px', 'right' => '0px', 'bottom' => '8px', 'left' => '0px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'content_width' => 'full']]);
$hero = SEC('Hero', [ROW([
    W(VBOX([
        MARK(TAGLINE . '!', MUSTARD, '30px'),
        H('Memphis-style BBQ in Atascadero', 'h1', '78px', '44px', '#ffffff'),
        P('Slow-roasted and smoked pork, ribs and chicken from Chef Gregory Kalatsky, with a little Pacific Rim flair. Thursday to Saturday, until we run out.', '#ffffff', '21px', 'left', '500'),
        BTNS([BTN('Order now', ORDER, 'mustard'), BTN('See the menu', '/menu/', 'line')], '12px'),
    ], '16px'), '56%'),
    W(PIC('gq-hero-bark.jpg', 'Scored, smoked pork with a dark caramelized bark', '430px', '240px', 'gq-pic gq-framed gq-tilt'), '40%'),
], '40px', ['justify' => 'between', 'align' => 'center'], ['layout' => ['direction' => 'column', 'gap' => '30px']])], SAUCE, '64px', '40px');
$size = static fn(string $name, string $a, string $b, string $c): array => VBOX([
    B('core.text', ['content' => $name, 'size' => 'inherit'], [], ['color' => SAUCE, 'typography' => ['size' => '40px', 'font_family' => SLAB, 'weight' => '400', 'line_height' => '1.1', 'transform' => 'uppercase']]),
    P($a, SOOT, '20px', 'left', '600'), P($b, SOOT, '20px', 'left', '600'), P($c, SOOT, '20px', 'left', '600'),
], '4px', ['padding' => ['top' => '24px', 'right' => '24px', 'bottom' => '24px', 'left' => '24px']], ['classNames' => ['gq-ticket']]);
$sizes = SEC('Pick your size', [
    ROW([W(VBOX([EYE('How we size it', MUSTARD), H('Pick your hog', 'h2', '54px', '36px', '#ffffff')], '8px'), '46%'), W(P('Three sizes run through the whole menu. Plates come with your choice of side, baked beans or coleslaw. Add a second side for $2.00.', '#ffffff', '20px'), '46%')], '32px', ['justify' => 'between', 'align' => 'end'], ['layout' => ['direction' => 'column', 'gap' => '12px', 'align' => 'start']]),
    GRID([$size('Piglet', '4 oz pork', '3 ribs', '8 oz side'), $size('Porker', '8 oz pork', '6 ribs', '16 oz side'), $size('Whole Hog', '1 lb pork', '12 ribs', '32 oz side')], 3, '20px', 3, 1, '14px', ['margin' => ['top' => '32px']]),
], SOOT, '80px', '52px');
$pit = SEC('From the pit', [
    ROW([VBOX([EYE('Off the smoker'), H('The pork that built the place', 'h2', '46px', '36px')], '8px'), BTNS([BTN('Full menu', '/menu/', 'black')], '0px')], '20px', ['justify' => 'between', 'align' => 'end'], ['layout' => ['direction' => 'column', 'gap' => '14px', 'align' => 'start']]),
    B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [LOOP('menu_item', $CARD, 4, 4, 'Pork', 2)], ['margin' => ['top' => '32px']]),
], PAPER, '88px', '56px', 'menu');
$year = static fn(string $y, string $t): array => VBOX([B('core.text', ['content' => $y, 'size' => 'inherit'], [], ['color' => SAUCE, 'typography' => ['size' => '40px', 'font_family' => SLAB, 'weight' => '400', 'line_height' => '1.1']]), P($t, SOOT, '17px', 'left', '600')], '2px');
$story = SEC('Our story', [ROW([
    W(PIC('gq-chef.jpg', 'Chef Gregory Kalatsky in his checkered bandana', '560px', '340px', 'gq-pic gq-framed'), '40%'),
    W(VBOX([
        EYE('Our story'), H('A lifetime in kitchens. Decades at the pit.', 'h2', '50px', '34px'),
        P('Chef Gregory S. Kalatsky is a graduate of the Culinary Institute of America, Hyde Park, New York. He has cooked at the Memphis in May World Championship Barbecue Cooking Contest since 1998, and it shows in every rack. Memphis tradition, Pacific Rim flair, and a simple rule: integrity, consistency and quality in every dish.'),
        GRID([$year('1997', 'Catering begins'), $year('1998', 'First Memphis in May'), $year('2024', 'The Smokehouse opens')], 3, '16px', 3, 3, '10px', ['margin' => ['top' => '6px']]),
        BTNS([BTN('Read our story', '/about/', 'black')]),
    ], '14px'), '54%'),
], '40px', ['justify' => 'between', 'align' => 'center'], ['layout' => ['direction' => 'column', 'gap' => '28px']])], LIGHT, '88px', '56px', 'about');
$find = SEC('Hours and location', [ROW([
    W(PIC('gq-shop-counter.jpg', 'The counter and open kitchen at the Smokehouse in Atascadero', '520px', '260px', 'gq-pic gq-framed'), '52%'),
    W(VBOX([EYE('Come get some', MUSTARD), H('6550 El Camino Real', 'h2', '44px', '32px', '#ffffff'), $hoursLoop($HOURS_DARK), BTNS([BTN('Directions', '/visit/', 'mustard')])], '12px'), '42%'),
], '40px', ['justify' => 'between', 'align' => 'stretch'], ['layout' => ['direction' => 'column', 'gap' => '26px']])], SOOT, '80px', '52px');
$fam = static fn(string $eye, string $title, string $text, string $label, string $href, string $img, string $alt): array => VBOX([
    PIC($img, $alt, '240px', '200px'), VBOX([EYE($eye), H($title, 'h3', '30px', '26px'), P($text, SMOKE, '18px'), BTNS([BTN($label, $href, 'black')], '4px')], '10px', ['padding' => ['top' => '20px', 'right' => '20px', 'bottom' => '22px', 'left' => '20px']]),
], '0px', [], ['classNames' => ['gq-ticket']]);
$family = SEC('The family', [GRID([
    $fam('We do catering too', 'And not just BBQ', 'From an intimate two-person sit-down dinner to a 400-person pickup party.', 'Plan your event', '/catering/', 'gq-belly-sliced.jpg', 'Sliced smoked pork belly on a tray'),
    $fam('Take it home', 'Southwestern Flair', 'The seasoning on our ribs and chicken, developed by Chef Gregory in 2001, plus sauces from Gourmet Me Now.', 'Shop products', 'https://gourmetmenow.com/', 'gq-flair-red.jpg', 'Bottles of Chef Gregory’s Southwestern Flair seasoning'),
], 2, '24px', 2, 1, '20px')], PAPER, '80px', '52px');
build_page('home', 'Home', 'page', 'homepage', [$hero, $run('Slow-roasted & smoked  ★  Never frozen  ★  Memphis in May since 1998  ★  Open until we run out  ★  Preorder to get some  ★  Smoke them if you got ’em  ★'), $sizes, $pit, $story, $specials('Today’s board'), $find, $family], ['seo_title' => "Memphis-Style BBQ in Atascadero | Chef Gregory's Smokehouse", 'seo_desc' => "Slow-smoked ribs, pulled pork and chicken at 6550 El Camino Real, Atascadero. Award-winning Memphis-style BBQ by Chef Gregory. Order online or preorder today.", 'image' => 'gq-hero-bark.jpg']);

// ── Inner pages ────────────────────────────────────────────────────────────────
$head = static function (string $eye, string $title, string $text, string $photo): array {
    $c = SEC($title, [VBOX([EYE($eye, MUSTARD), H($title, 'h1', '78px', '42px', '#ffffff'), P($text, '#ffffff', '21px', 'left', '500')], '10px', ['dimensions' => ['max_width' => '860px']])], SAUCE, '88px', '52px');
    $c['style']['background'] = ['color' => DARKRED, 'image' => IMGREF($photo), 'fit' => 'cover', 'repeat' => 'no-repeat', 'position' => 'center', 'overlay' => ['color' => 'rgba(142,20,18,.8)']];
    return $c;
};

$jump = [];
foreach ($SECTIONS as [$name, $id]) { $jump[] = LNK($name, '#' . $id, SOOT, '22px', ['typography' => ['font_family' => COND, 'size' => '22px', 'weight' => '700', 'transform' => 'uppercase', 'letter_spacing' => '.8px']]); }
$photos = ['Pork' => ['gq-ribs-plate.jpg', 'Sliced smoked ribs'], 'Chicken' => ['gq-chicken-flair.jpg', 'Smoked chicken with Southwestern Flair seasoning'], 'Beef' => ['gq-sliced-beef.jpg', 'Sliced smoked beef'], 'Salads' => ['gq-signature-salad.jpg', 'A signature salad'], 'Sides' => ['gq-beans.jpg', 'BBQ baked beans'], 'Sweets' => ['gq-cake.jpg', 'Brown butter cake'], 'Drinks' => ['gq-shop-kitchen.jpg', 'The Smokehouse dining room'], 'Soups' => ['gq-soup.jpg', 'A bowl of soup']];
$menuSecs = [];
foreach ($SECTIONS as $i => [$name, $id, $line]) {
    $menuSecs[] = SEC($name, [ROW([
        W(VBOX([H($name, 'h2', '54px', '38px', SOOT), $line !== '' ? P($line, SMOKE, '18px') : null, PIC($photos[$name][0], $photos[$name][1], '300px', '190px', 'gq-pic gq-framed')], '12px', [], ['classNames' => ['gq-sticky']]), '32%'),
        W(VBOX([LOOP('menu_item', $ROW, 1, 12, $name, 1, 'sm')], '0px', [], ['classNames' => $name === 'Drinks' ? ['gq-list', 'gq-list--rows'] : ['gq-list']]), '63%'),
    ], '40px', ['justify' => 'between', 'align' => 'start'], ['layout' => ['direction' => 'column', 'gap' => '12px']])], $i % 2 ? LIGHT : PAPER, '64px', '40px', $id);
}
build_page('menu', 'Menu', 'page', 'standalone', [
    $head('Award-winning Memphis-style slow-roasted and smoked BBQ', 'Our menu', PLATE . ' Prices subject to change.', 'gq-hero-bark.jpg'),
    $cls(CTOP('Sections', [ROW($jump, '26px', ['wrap' => 'wrap', 'justify' => 'center'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap', 'gap' => '14px']])], ['background' => ['color' => MUSTARD], 'border' => ['bottom' => ['width' => '4px', 'style' => 'solid', 'color' => SOOT]], 'padding' => ['top' => '10px', 'right' => '16px', 'bottom' => '10px', 'left' => '16px'], 'layout' => ['display' => 'flex', 'direction' => 'column']]), 'gq-jump'),
    ...$menuSecs, $specials('Also watch for'),
    SEC('Print the menu', [EYE('Want it on paper?'), H('The counter menu', 'h2', '40px', '30px', SOOT), BTNS([BTN('Page one', COUNTER1, 'black'), BTN('Page two', COUNTER2, 'black'), BTN('Order now', ORDER)])], LIGHT, '56px', '40px', '', '6px'),
], ['seo_title' => "BBQ Menu & Prices | Chef Gregory's Smokehouse, Atascadero", 'seo_desc' => 'Pulled pork, baby back ribs, smoked chicken, tri-tip and sides, in Piglet, Porker and Whole Hog sizes. See every price, then order online.', 'image' => 'gq-ribs-plate.jpg']);

build_page('order', 'Order Online', 'page', 'standalone', [
    $head('Easy and convenient', 'Order your barbecue online', 'If online ordering is open, so are we. You can look through the menu any time, but orders only go through during our hours.', 'gq-sandwich-plate.jpg'),
    SEC('How to order', [ROW([
        W(VBOX([EYE('Three ways to get some'), H('Order now and satisfy your barbecue cravings', 'h2', '44px', '32px'),
            P('Whether you’re craving tender smoked ribs, savory pulled pork or any of our other favorites, you’re a few clicks from dinner.'),
            B('core.list', ['style' => 'check', 'items' => [['text' => 'Click Order Now and pick your food.'], ['text' => 'Or scan the QR code with your phone.'], ['text' => 'Preorder Sunday to Tuesday so you don’t miss out.'], ['text' => 'Pick up at 6550 El Camino Real, Atascadero.']]], [], ['color' => SOOT] + TYPE(BODY, '19px', '500'), ['style_parts' => ['marker' => ['color' => SAUCE]]]),
            BTNS([BTN('Order now', ORDER), BTN('See the menu', '/menu/', 'mustard')]),
        ], '16px'), '56%'),
        W(VBOX([EYE('Scan to order', SOOT, 'center'), B('core.image', ['media' => IMGREF('gq-qr.png', 'QR code for online ordering'), 'aspect_ratio' => 'auto', 'rounded' => false], [], ['dimensions' => ['width' => '230px']], ['classNames' => ['gq-qr']]), P('Orders can’t be placed while we’re closed.', SMOKE, '16px', 'center')], '12px', ['padding' => ['top' => '26px', 'right' => '24px', 'bottom' => '24px', 'left' => '24px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '12px', 'align' => 'center']], ['classNames' => ['gq-ticket']]), '34%'),
    ], '40px', ['justify' => 'between', 'align' => 'center'], ['layout' => ['direction' => 'column', 'gap' => '30px']])], PAPER, '72px', '48px'),
    SEC('When', [EYE('When you can order'), H('Hours', 'h2', '44px', '32px'), B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [$hoursLoop($HOURS_LIGHT)], ['margin' => ['top' => '14px']])], LIGHT, '64px', '44px', '', '4px', '640px'),
], ['seo_title' => "Order BBQ Online in Atascadero | Chef Gregory's Smokehouse", 'seo_desc' => "Order Chef Gregory's slow-smoked ribs, pulled pork and chicken online for pickup in Atascadero. Preorder from Sunday to Tuesday so you get some.", 'image' => 'gq-sandwich-plate.jpg']);

$tl = static fn(string $y, string $t): array => ROW([B('core.text', ['content' => $y, 'size' => 'inherit'], [], ['color' => SAUCE, 'dimensions' => ['width' => '110px'], 'typography' => ['size' => '32px', 'font_family' => SLAB, 'weight' => '400', 'line_height' => '1.15']]), P($t, SOOT, '20px', 'left', '500')], '16px', ['align' => 'center'], ['layout' => ['direction' => 'row']], ['border' => ['bottom' => ['width' => '3px', 'style' => 'solid', 'color' => SOOT]], 'padding' => ['top' => '12px', 'bottom' => '12px']]);
build_page('about', 'Our Story', 'page', 'standalone', [
    $head('Where Memphis traditions meet culinary excellence', 'Our story', 'From a childhood passion to a smokehouse on El Camino Real.', 'gq-ribs-close.jpg'),
    SEC('The story', [ROW([
        W(VBOX([EYE('Smoke and spice'), H('Pacific Rim flair, Memphis tradition', 'h2', '44px', '32px'),
            P('At Chef Gregory’s, we craft food that honors the rich tradition of Southern cooking and the many influences gathered in a lifetime dedicated to cuisine. Our journey began with a childhood passion ignited by the flames of the kitchen, and grew through rigorous training and hands-on experience.'),
            P('With a focus on barbecue pork and chicken, we blend Pacific Rim influences with the time-honored traditions of Memphis-style barbecue. Our work has been tested on the grand stage of the Memphis in May World Championship Barbecue Cooking Contest, where our team has garnered numerous accolades, including grand champion titles.'),
            P('Our mission is simple yet profound: integrity, consistency and quality in every dish we create.'),
        ], '14px'), '54%'),
        W(PIC('gq-ribs-plate.jpg', 'Sliced smoked ribs on a plate', '520px', '280px', 'gq-pic gq-framed'), '40%'),
    ], '40px', ['justify' => 'between', 'align' => 'center'], ['layout' => ['direction' => 'column', 'gap' => '28px']])], PAPER, '72px', '48px'),
    SEC('Master chef', [ROW([
        W(PIC('gq-chef.jpg', 'Chef Gregory Kalatsky', '500px', '320px', 'gq-pic gq-framed'), '36%'),
        W(VBOX([EYE('Meet our master chef', MUSTARD), H('Gregory S. Kalatsky', 'h2', '50px', '34px', '#ffffff'),
            P('Gregory S. Kalatsky is a graduate of the Culinary Institute of America, Hyde Park, New York. His studies and training have taken him around the world, into kitchens with some of the world’s best chefs, trained by Certified Master Chefs, Certified Master Pastry Chefs and Culinary Olympic team members.', '#ffffff'),
            P('His long experience as a consultant has developed techniques to oversee restaurants with a successful approach. He continually strives to achieve, making a dream a reality.', '#ffffff'),
        ], '14px'), '58%'),
    ], '40px', ['justify' => 'between', 'align' => 'center'], ['layout' => ['direction' => 'column', 'gap' => '28px']])], SOOT, '72px', '48px'),
    SEC('Timeline', [EYE('The road to Atascadero'), H('How we got here', 'h2', '46px', '32px'), VBOX([
        $tl('1995', 'Graduates from the Culinary Institute of America'), $tl('1997', 'Catering begins'), $tl('1998', 'First Memphis in May World Championship Barbecue competition, and on through 2025'),
        $tl('2001', 'Southwestern Flair seasoning developed'), $tl('2020', 'Moved to the Central Coast of California'), $tl('2024', 'Chef Gregory’s Barbecue Smokehouse Emporium starts'),
    ], '0px', ['margin' => ['top' => '16px']])], LIGHT, '72px', '48px', '', '6px', '900px'),
    SEC('A Chef’s Journey', [EYE('Watch', MUSTARD, 'center'), H('A Chef’s Journey', 'h2', '46px', '32px', '#ffffff', 'center'), B('core.video', ['source' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=msUZd1mzV74', 'privacy' => true], [], ['margin' => ['top' => '18px']])], SAUCE, '72px', '48px', '', '6px', '900px'),
], ['seo_title' => "About Chef Gregory Kalatsky | Atascadero Smokehouse", 'seo_desc' => 'CIA-trained Chef Gregory Kalatsky has competed at Memphis in May since 1998. He moved to the Central Coast in 2020 and opened his Atascadero smokehouse in 2024.', 'image' => 'gq-chef.jpg']);

$ff = static fn(string $type, string $name, string $label, bool $req = false, array $more = []): array => B('core.form_field', ['field_type' => $type, 'name' => $name, 'label' => $label, 'placeholder' => '', 'required' => $req] + $more);
build_page('catering', 'Catering', 'page', 'standalone', [
    $head('We do catering too', 'And not just BBQ', 'From an intimate two-person sit-down dinner to a 400-person pickup party.', 'gq-belly-sliced.jpg'),
    SEC('Catering', [ROW([
        W(VBOX([EYE('Tell us about it'), H('Feed the whole crew', 'h2', '44px', '32px'),
            P('Whether you’re looking for high-end catering or a mid-range option, Chef Gregory is your chef. We source only the finest ingredients, and your satisfaction is our priority.'),
            P('For weddings, winery dinners and corporate events, see the full catering site.'),
            BTNS([BTN('Chef Gregory’s Catering', 'https://chefgregory.com/', 'black')]),
            PIC('gq-sandwich-studio.jpg', 'A pulled pork sandwich with coleslaw', '280px', '220px', 'gq-pic gq-framed'),
        ], '14px'), '46%'),
        W(VBOX([H('Ask about your event', 'h2', '30px', '26px'), B('core.form', ['form_name' => 'Catering enquiry', 'notify_email' => '', 'submit_text' => 'Send it', 'submit_variant' => 'primary', 'success_message' => 'Thanks! We’ll get back to you. We’re probably at the smoker.'], [
            $ff('text', 'name', 'Your name', true), $ff('tel', 'phone', 'Phone', true), $ff('email', 'email', 'Email'), $ff('date', 'event_date', 'Event date'), $ff('number', 'guests', 'How many people?'), $ff('textarea', 'message', 'What are you planning?', true),
        ], [], ['classNames' => ['gq-form']])], '14px', ['padding' => ['top' => '26px', 'right' => '26px', 'bottom' => '26px', 'left' => '26px']], ['classNames' => ['gq-ticket']]), '48%'),
    ], '40px', ['justify' => 'between'], ['layout' => ['direction' => 'column', 'gap' => '28px']])], PAPER, '72px', '48px'),
], ['seo_title' => "BBQ Catering in Atascadero | Chef Gregory's Smokehouse", 'seo_desc' => 'Barbecue catering from Atascadero, from a two-person sit-down dinner to a 400-person pickup party. Ask Chef Gregory about your event.', 'image' => 'gq-belly-sliced.jpg']);

$shots = [['gq-ribs-rack.jpg', 'A rack of smoked baby back ribs'], ['gq-sandwich-tall.jpg', 'A pulled pork sandwich piled high'], ['gq-belly-sliced.jpg', 'Sliced smoked pork belly'], ['gq-chicken-flair.jpg', 'Smoked chicken with Southwestern Flair seasoning'], ['gq-shop-wall.jpg', 'The Smokehouse shelves and checkered trim'], ['gq-ribs-sliced.jpg', 'Sliced ribs on a plate'], ['gq-sandwich-tray.jpg', 'A pork sandwich in a checkered paper tray'], ['gq-beans.jpg', 'BBQ baked beans with pork'], ['gq-signature-salad.jpg', 'A seared salad'], ['gq-cake.jpg', 'Brown butter cake'], ['gq-brownie.jpg', 'Chocolate puddle brownie cake'], ['gq-sauce.jpg', 'Bottles of Chef Gregory’s sauce'], ['gq-shop-counter.jpg', 'The counter and kitchen'], ['gq-ribs-close.jpg', 'Glazed ribs up close'], ['gq-chicken-plate.jpg', 'Smoked chicken quarters'], ['gq-shop-kitchen.jpg', 'The Smokehouse dining room'], ['gq-flair-green.jpg', 'Chef Gregory’s seasoning'], ['gq-sandwich-slaw.jpg', 'A sandwich topped with coleslaw']];
build_page('gallery', 'Gallery', 'page', 'standalone', [
    $head('Off the smoker', 'Gallery', 'Real food, real smoke, real checkered trim at 6550 El Camino Real.', 'gq-shop-wall.jpg'),
    SEC('Photos', [B('core.gallery', ['images' => array_map(static fn(array $s): array => ['url' => MEDIA_DIR . $s[0], 'alt' => $s[1]], $shots), 'columns' => '3', 'layout' => 'masonry', 'aspect_ratio' => '4:3'])], PAPER, '56px', '36px'),
], ['seo_title' => "BBQ Photos | Chef Gregory's Smokehouse, Atascadero", 'seo_desc' => 'Ribs, pulled pork, smoked chicken and the Smokehouse itself: photos from Chef Gregory’s Barbecue Smokehouse Emporium in Atascadero.', 'image' => 'gq-ribs-close.jpg']);

// The one old address with nothing to keep goes to the menu; the rest answer as they did.
Database::setSetting('studio_redirects', json_encode([['from' => '/category/uncategorized/', 'to' => '/menu/', 'code' => 301]]), $tid);
PageRoutes::forget($tid);
say('site done');
