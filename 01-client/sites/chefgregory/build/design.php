<?php
// Chef Gregory's redesign: theme, header, footer and the home page. Runs after site.php, or alone to redo just these.
//
//   cd <site> && php <bundle>/build/design.php
declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;

use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Menu\StudioMenus;

if (!function_exists('say')) { require __DIR__ . '/lib.php'; }
require_once __DIR__ . '/capture.php';

const INK = '#1d1815';
const CREAM = '#f7f1e6';
const SAND = '#ebe1cf';
const EMBER = '#b4471d';
const EMBERLT = '#e8a077';
const COCOA = '#5c5148';
const DISPLAY = 'Libre Caslon Display, serif';
const ROMAN = 'Libre Caslon Text, serif';
const BODY = 'Hanken Grotesk, sans-serif';
const PHONE = '877-357-2433';

// ── Theme ──────────────────────────────────────────────────────────────────────
$runtime->app->saveDesignTokens($actor, 'default', [
    'surface.page' => CREAM, 'surface.primary' => CREAM, 'surface.secondary' => SAND, 'surface.muted' => SAND, 'surface.accent' => EMBER, 'surface.inverse' => INK,
    'text.primary' => INK, 'text.muted' => COCOA, 'text.accent' => EMBER, 'text.inverse' => CREAM, 'color.accent' => EMBER, 'border.default' => '#d9ccb5',
    'font.heading' => DISPLAY, 'font.body' => BODY, 'text.body' => COCOA, 'text.heading' => INK,
    'type.body_size' => '17px', 'type.flow' => '0', 'type.heading_gap' => '0', 'type.section_gutter' => '0', 'layout.content' => '1240px',
]);
// The pages not redesigned yet still name the old families.
$fonts = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Libre+Caslon+Display&family=Libre+Caslon+Text:ital@0;1&family=Hanken+Grotesk:wght@400;500;600;700&family=Cormorant:wght@400;500;600;700&family=Cormorant+Garamond:ital,wght@0,400;0,500;1,400;1,500&family=Italianno&family=Rajdhani:wght@400;500;600;700&family=Heebo:wght@300;400;500;700&family=Lora:wght@400;500;600&family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@400;500;700&family=Open+Sans:wght@400;600&family=Jost:wght@400;500;600&display=swap">';
Database::setSetting(StudioCodePolicy::SETTING_HEAD_SNIPPET, StudioCodePolicy::prepareSnippet($fonts, 'head'), $tid);
$css = StudioCodePolicy::prepareCustomCss((string) file_get_contents(__DIR__ . '/gap.css') . "\n" . (string) file_get_contents(__DIR__ . '/design.css'));
Database::setSetting(StudioCodePolicy::SETTING_CUSTOM_CSS, $css['css'], $tid);

// ── Pieces ─────────────────────────────────────────────────────────────────────
function GUT(string $top = '110px', string $bottom = '110px', string $mob = '72px'): array { return ['padding' => ['top' => $top, 'right' => '24px', 'bottom' => $bottom, 'left' => '24px']]; }
function WRAP(string $dir = 'column', string $gap = '0px', array $more = []): array { return ['display' => 'flex', 'direction' => $dir, 'gap' => $gap, 'content_width' => 'boxed', 'content_max' => '1240px'] + $more; }
function SEC(string $label, array $kids, string $bg, array $pad, array $layout, string $mob = '72px'): array
{
    return CTOP($label, $kids, ['background' => ['color' => $bg]] + $pad + ['layout' => $layout], ['mobile' => ['style' => ['padding' => ['top' => $mob, 'right' => '20px', 'bottom' => $mob, 'left' => '20px']]]]);
}
function VBOX(array $kids, string $gap = '0px', array $style = [], array $opt = []): array { return B('layout.flex', ['direction' => 'column', 'gap' => 'none', 'wrap' => 'nowrap', 'align' => 'stretch'], array_values(array_filter($kids)), ['layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => $gap]] + $style, $opt); }
function ROW(array $kids, string $gap = '0px', array $style = [], array $mobile = ['layout' => ['direction' => 'column']], array $layout = []): array
{
    return B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'nowrap', 'align' => 'stretch'], array_values(array_filter($kids)), ['layout' => ['display' => 'flex', 'direction' => 'row', 'gap' => $gap] + $layout] + $style, $mobile ? ['responsive' => ['mobile' => ['style' => $mobile]]] : []);
}
/** Column width inside a row; a phone stacks it at full width. */
function W(array $b, string $w): array { $b['style']['dimensions']['width'] = $w; $b['responsive']['mobile']['style']['dimensions']['width'] = '100%'; return $b; }
function EYE(string $text, string $color = EMBER, string $align = 'left'): array { return B('core.text', ['content' => $text, 'size' => 'inherit'], [], ['color' => $color, 'typography' => ['size' => '13px', 'font_family' => BODY, 'weight' => '600', 'transform' => 'uppercase', 'letter_spacing' => '2.4px', 'line_height' => '1.4'], 'align' => ['base' => $align]]); }
function H(string $text, string $level, string $size, string $msize, string $color = INK, string $align = 'left'): array
{
    return B('core.heading', ['text' => $text, 'level' => $level], [], ['color' => $color, 'typography' => ['size' => $size, 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.1'], 'align' => ['base' => $align]], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => $msize]]]]]);
}
function P(string $text, string $color = COCOA, string $size = '18px', string $align = 'left', string $font = BODY, string $fst = ''): array { return B('core.text', ['content' => $text, 'size' => 'inherit'], [], ['color' => $color, 'typography' => ['size' => $size, 'font_family' => $font, 'weight' => '400', 'line_height' => '1.6'] + ($fst !== '' ? ['style' => $fst] : []), 'align' => ['base' => $align]]); }
function BTN(string $label, string $href, string $kind = 'solid'): array
{
    [$bg, $fg, $line, $cls] = match ($kind) {
        'solid' => [EMBER, '#ffffff', EMBER, 'cg-btn'],
        'light' => ['rgba(0,0,0,0)', CREAM, 'rgba(247,241,230,.55)', 'cg-btn cg-btn--light'],
        default => ['rgba(0,0,0,0)', INK, INK, 'cg-btn cg-btn--dark'],
    };
    return B('core.button', ['link' => L($label, $href), 'variant' => 'primary'], [], [], ['classNames' => explode(' ', $cls), 'style_parts' => ['button' => ['background' => ['color' => $bg], 'color' => $fg, 'border' => ['width' => '1px', 'style' => 'solid', 'color' => $line, 'radius' => '0px'], 'padding' => ['top' => '16px', 'right' => '30px', 'bottom' => '16px', 'left' => '30px'], 'typography' => ['size' => '14px', 'weight' => 'semibold', 'letter_spacing' => '1.4px', 'transform' => 'uppercase']]]]);
}
function PIC(string $file, string $alt, string $h, string $mh = '', string $href = ''): array
{
    $ref = IMGREF($file, $alt);
    if ($ref === null) { throw new RuntimeException("No image {$file}"); }
    $props = ['media' => $ref, 'aspect_ratio' => 'auto', 'rounded' => false] + ($href !== '' ? ['link_to' => 'custom', 'url' => $href] : []);
    return B('core.image', $props, [], ['dimensions' => ['width' => '100%', 'height' => $h, 'object_fit' => 'cover']], ['classNames' => ['cg-pic']] + ($mh !== '' ? ['responsive' => ['mobile' => ['style' => ['dimensions' => ['height' => $mh]]]]] : []));
}
function CHECK(): array { return B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [], ['dimensions' => ['height' => '16px', 'width' => '100%']], ['classNames' => ['cg-check']]); }
function LINKS(array $items, string $color, string $size = '16px', string $gap = '10px'): array
{
    $out = [];
    foreach ($items as [$label, $href]) { $out[] = B('core.link', ['link' => L($label, $href), 'style' => 'plain'], [], ['color' => $color, 'typography' => ['size' => $size, 'font_family' => BODY, 'weight' => '400', 'line_height' => '1.4']], ['classNames' => ['cg-link']]); }
    return VBOX($out, $gap);
}

function BOOK(): array
{
    return CTOP('Book a tasting', [
    H('Ready to Create Something Unforgettable?', 'h2', '60px', '40px', CREAM, 'center'),
    P('Every great event starts with a conversation. Contact Chef Gregory today to discuss your vision and receive a personalized proposal.', '#e6dccd', '19px', 'center'),
    ROW([BTN('Book Your Tasting', '/contact/'), BTN('Call ' . PHONE, 'tel:' . PHONE, 'light')], '12px', ['margin' => ['top' => '14px']], ['layout' => ['direction' => 'row', 'wrap' => 'wrap', 'justify' => 'center']], ['justify' => 'center', 'wrap' => 'wrap']),
], ['background' => ['color' => INK, 'image' => IMGREF('2026-06-brisket.webp'), 'fit' => 'cover', 'repeat' => 'no-repeat', 'position' => 'center', 'overlay' => ['color' => 'rgba(29,24,21,.74)']], 'padding' => ['top' => '130px', 'right' => '24px', 'bottom' => '130px', 'left' => '24px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '16px', 'align' => 'center', 'content_width' => 'boxed', 'content_max' => '760px']], ['mobile' => ['style' => ['padding' => ['top' => '84px', 'right' => '20px', 'bottom' => '84px', 'left' => '20px']]]]);
}

$AREAS = [['Atascadero', '/catering-in-atascadero/'], ['Paso Robles', '/catering/paso-robles/'], ['Templeton', '/catering/templeton/'], ['San Luis Obispo', '/catering/san-luis-obispo/'], ['Morro Bay', '/catering-in-morro-bay/'], ['Los Osos', '/catering-in-los-osos/'], ['Cambria', '/catering-in-cambria/'], ['Pismo Beach', '/catering-in-pismo-beach/'], ['Arroyo Grande', '/catering-in-arroyo-grande/'], ['Nipomo', '/catering-in-nipomo/'], ['San Miguel', '/catering/san-miguel/']];
$SERVICES = [['Wedding Catering', '/service/wedding-catering/'], ['Corporate Catering', '/service/corporate-catering/'], ['Winery Dinners', '/service/winery-dinner-catering/'], ['Private Chef Services', '/service/private-chef-catering/'], ['BBQ & Casual Events', '/service/bbq-catering/'], ['Cocktail & Bar Service', '/service/bar-service-catering/'], ['Special Celebrations', '/service/special-celebrations-catering/']];

// ── Header ─────────────────────────────────────────────────────────────────────
$sub = static fn(array $list): array => array_map(static fn(array $x): array => ['label' => $x[0], 'url' => $x[1]], $list);
StudioMenus::save($tid, 'header', 'Header', [
    ['label' => 'About', 'url' => '/about-us/'], ['label' => 'Services', 'url' => '/services/', 'children' => $sub($SERVICES)],
    ['label' => 'Service Areas', 'url' => '/catering/', 'children' => $sub($AREAS)], ['label' => 'Gallery', 'url' => '/gallery/'],
    ['label' => 'Recipes', 'url' => '/recipe/'], ['label' => 'Blog', 'url' => '/blog-post/'], ['label' => 'Help Us Help You', 'url' => '/contact/help-us-help-you/'], ['label' => 'Contact', 'url' => '/contact/'],
], 'header');
$strip = CTOP('Top strip', [
    B('core.link', ['link' => L('Thanksgiving Menu →', '/thanksgiving-menu/'), 'style' => 'plain'], [], ['color' => EMBERLT, 'typography' => ['size' => '13px', 'font_family' => BODY, 'weight' => '600', 'letter_spacing' => '.6px']]),
    B('core.link', ['link' => L('Call ' . PHONE, 'tel:' . PHONE), 'style' => 'plain'], [], ['color' => CREAM, 'typography' => ['size' => '13px', 'font_family' => BODY, 'weight' => '600', 'letter_spacing' => '.6px']]),
], ['background' => ['color' => '#120e0c'], 'padding' => ['top' => '7px', 'right' => '24px', 'bottom' => '7px', 'left' => '24px'], 'layout' => WRAP('row', '16px', ['justify' => 'between', 'align' => 'center'])]);
$logoRef = IMGREF('2026-01-logo-white.png', "Chef Gregory's");
$nav = B('core.nav_menu', ['menu' => 'header', 'items' => [], 'layout' => 'horizontal', 'align' => 'right', 'aria_label' => 'Main menu', 'toggle_breakpoint' => 'tablet', 'toggle_cta_label' => 'Book a Tasting', 'toggle_cta_url' => '/contact/'], [], ['color' => CREAM, 'typography' => ['size' => '15px', 'font_family' => BODY, 'weight' => '500']], ['classNames' => ['cg-nav'], 'style_parts' => [
    'link' => ['color' => CREAM], 'current' => ['color' => EMBERLT],
    'submenu' => ['background' => ['color' => INK]], 'sublink' => ['color' => CREAM],
    'burger' => ['color' => CREAM], 'panel' => ['background' => ['color' => INK]],
    'cta' => ['background' => ['color' => EMBER], 'color' => '#ffffff', 'border' => ['radius' => '0px']],
]]);
$hdr = CTOP('Header', [
    B('core.image', ['media' => $logoRef, 'aspect_ratio' => 'auto', 'rounded' => false, 'link_to' => 'custom', 'url' => '/'], [], ['dimensions' => ['width' => '132px']], ['responsive' => ['mobile' => ['style' => ['dimensions' => ['width' => '104px']]]]]),
    ROW([$nav, B('core.button', ['link' => L('Book a Tasting', '/contact/'), 'variant' => 'primary'], [], [], ['classNames' => ['cg-btn', 'cg-head-cta'], 'style_parts' => ['button' => ['background' => ['color' => EMBER], 'color' => '#ffffff', 'border' => ['radius' => '0px'], 'padding' => ['top' => '12px', 'right' => '20px', 'bottom' => '12px', 'left' => '20px'], 'typography' => ['size' => '13px', 'weight' => 'semibold', 'letter_spacing' => '1.2px', 'transform' => 'uppercase']]]])], '24px', ['dimensions' => ['width' => 'auto']], [], ['align' => 'center', 'justify' => 'end']),
], ['background' => ['color' => INK], 'padding' => ['top' => '10px', 'right' => '24px', 'bottom' => '10px', 'left' => '24px'], 'position' => ['mode' => 'sticky', 'top' => '0px'], 'z_index' => 50, 'layout' => WRAP('row', '24px', ['justify' => 'between', 'align' => 'center'])]);
build_page('default', 'Header', 'header_partial', 'standalone', [$strip, $hdr]);

// ── Footer ─────────────────────────────────────────────────────────────────────
$fh = static fn(string $t): array => EYE($t, EMBERLT);
$fcol = static fn(string $t, array $links): array => VBOX([$fh($t), LINKS($links, CREAM, '15px', '9px')], '18px');
$ft = CTOP('Footer', [
    ROW([
        W(VBOX([
            B('core.image', ['media' => $logoRef, 'aspect_ratio' => 'auto', 'rounded' => false, 'link_to' => 'custom', 'url' => '/'], [], ['dimensions' => ['width' => '170px']]),
            P('When Great Service Really Matters', CREAM, '20px', 'left', ROMAN, 'italic'),
            LINKS([['6550 El Camino Real, Atascadero, CA 93422', 'https://maps.google.com/?q=6550+El+Camino+Real,+Atascadero,+CA+93422'], [PHONE, 'tel:' . PHONE], ['sales@gourmetmenow.com', 'mailto:sales@gourmetmenow.com']], CREAM, '15px', '8px'),
            P('By appointment. We respond within 24 hours.', '#b9ab9b', '14px'),
            ROW(array_map(static fn(array $s): array => B('core.link', ['link' => L($s[0], $s[1]), 'style' => 'plain'], [], ['color' => EMBERLT, 'typography' => ['size' => '14px', 'font_family' => BODY, 'weight' => '600']], ['classNames' => ['cg-link']]), [['Facebook', 'https://www.facebook.com/ChefgregoryOU812/'], ['Instagram', 'https://www.instagram.com/chefgregoryou812/'], ['Yelp', 'https://www.yelp.com/biz/chef-gregorys-gourmet-catering-atascadero']]), '20px', [], []),
        ], '18px'), '34%'),
        W($fcol('Plan', [['About Us', '/about-us/'], ['Services', '/services/'], ['Pricing', '/holiday-services/'], ['Plan Your Event', '/contact/help-us-help-you/'], ['Contact', '/contact/'], ['Blog', '/blog-post/'], ['Recipes', '/recipe/'], ['All Services', '/service/'], ['Thanksgiving Menu', '/thanksgiving-menu/']]), '18%'),
        W($fcol('Food', [['Food Items', '/items-for-catered-events/'], ['Plated And Styled', '/plated-and-styled/'], ['Canapés & Hors d’oeuvres', '/canapes/'], ['Corporate Breakfast', '/corporate-breakfast-continental/'], ['Cooking Classes', '/cooking-classes/'], ['Gourmet Me Now', '/gourmet-me-now/'], ['Products', 'https://gourmetmenow.com/products/'], ['Ginger Soy Dressing', '/ginger-soy-dressing/']]), '22%'),
        W($fcol('Catering Areas', $AREAS), '18%'),
    ], '32px', [], ['layout' => ['direction' => 'column', 'gap' => '40px']], ['justify' => 'between']),
    B('core.divider', ['weight' => 'thin'], [], ['margin' => ['top' => '48px', 'bottom' => '20px']], ['classNames' => ['cg-rule']]),
    ROW([P('© 2026 Chef Gregory’s. All Rights Reserved.', '#b9ab9b', '13px'), P('Designed by Rakib Hasan', '#b9ab9b', '13px')], '16px', [], ['layout' => ['direction' => 'column', 'gap' => '4px']], ['justify' => 'between']),
], ['background' => ['color' => INK], 'padding' => ['top' => '72px', 'right' => '24px', 'bottom' => '28px', 'left' => '24px'], 'layout' => WRAP()], ['mobile' => ['style' => ['padding' => ['top' => '56px', 'right' => '20px', 'bottom' => '28px', 'left' => '20px']]]]);
build_page('default', 'Footer', 'footer_partial', 'standalone', [CTOP('Check band', [CHECK()], []), $ft]);

// ── Home ───────────────────────────────────────────────────────────────────────
$hero = SEC('Hero', [
    ROW([
        W(VBOX([
            EYE('Central Coast Wedding & Event Catering', EMBERLT),
            H('Where Fine Cuisine Meets Every Occasion', 'h1', '70px', '44px', CREAM),
            P('Award-winning Chef Gregory Kalatsky brings 25+ years of culinary mastery to your most memorable events — wedding catering, corporate events, and private parties across Atascadero, Paso Robles, San Luis Obispo, and California’s Central Coast.', '#d9cdbd'),
            ROW([BTN('Book Your Tasting', '/contact/'), BTN('Explore Services', '/services/', 'light')], '12px', ['margin' => ['top' => '10px']], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']], ['wrap' => 'wrap']),
        ], '22px', [], []), '47%'),
        W(ROW([
            W(PIC('2026-03-roasted-rack-of-lamb-7-scaled-1.png', 'Herb-roasted rack of lamb', '560px', '300px'), '56%'),
            W(VBOX([PIC('2026-03-pan-seared-halibut-steaks-with-c.webp', 'Pan-seared Pacific halibut', '440px', '300px')], '0px', ['padding' => ['top' => '72px']], ['responsive' => ['mobile' => ['style' => ['padding' => ['top' => '28px']]]]]), '44%'),
        ], '14px', [], ['layout' => ['direction' => 'row']]), '53%'),
    ], '56px', [], ['layout' => ['direction' => 'column', 'gap' => '40px']], ['align' => 'center']),
], INK, ['padding' => ['top' => '64px', 'right' => '24px', 'bottom' => '72px', 'left' => '24px']], WRAP(), '44px');

$fact = static fn(string $big, string $small): array => VBOX([B('core.text', ['content' => $big, 'size' => 'inherit'], [], ['color' => EMBER, 'typography' => ['size' => '40px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.1'], 'align' => ['base' => 'center']]), B('core.text', ['content' => $small, 'size' => 'inherit'], [], ['color' => COCOA, 'typography' => ['size' => '14px', 'font_family' => BODY, 'weight' => '500', 'line_height' => '1.4'], 'align' => ['base' => 'center']])], '6px', ['padding' => ['top' => '4px', 'right' => '8px', 'bottom' => '4px', 'left' => '8px']]);
$facts = SEC('Why book', [B('layout.flex', ['direction' => 'row', 'gap' => 'none'], [$fact('25+', 'Years of experience'), $fact('5 Star', 'Rating on Yelp'), $fact('CIA', 'Culinary Institute of America trained'), $fact('1997', 'Established on the Central Coast')], ['layout' => ['display' => 'grid', 'columns' => 4, 'gap' => '16px']], ['responsive' => ['mobile' => ['style' => ['layout' => ['columns' => 2, 'gap' => '28px']]]]])], SAND, ['padding' => ['top' => '40px', 'right' => '24px', 'bottom' => '40px', 'left' => '24px']], WRAP(), '36px');

$tile = static function (string $n, string $title, string $text, string $href, string $file, string $h, string $mh = '230px'): array {
    return VBOX([PIC($file, $title, $h, $mh, $href), VBOX([EYE($n), H($title, 'h3', '30px', '28px'), P($text, COCOA, '16px'), B('core.link', ['link' => L('Learn more →', $href), 'style' => 'plain'], [], ['color' => EMBER, 'typography' => ['size' => '15px', 'font_family' => BODY, 'weight' => '600']], ['classNames' => ['cg-link']])], '8px', ['padding' => ['top' => '18px']])], '0px', [], ['classNames' => ['cg-tile']]);
};
$services = SEC('Services', [
    ROW([
        W(VBOX([EYE('What we offer'), H('Exceptional Catering for Every Occasion', 'h2', '52px', '38px')], '14px'), '50%'),
        W(VBOX([P('From intimate winery dinners for 20 to grand wedding receptions for 300+, Chef Gregory’s team delivers uncompromising quality with meticulous attention to detail. Every event begins with a complimentary pre-event tasting and custom menu consultation.'), B('core.link', ['link' => L('Request a quote →', '/contact/'), 'style' => 'plain'], [], ['color' => EMBER, 'typography' => ['size' => '16px', 'font_family' => BODY, 'weight' => '600']], ['classNames' => ['cg-link']])], '14px'), '42%'),
    ], '40px', [], ['layout' => ['direction' => 'column', 'gap' => '20px']], ['justify' => 'between', 'align' => 'end']),
    ROW([
        W($tile('01', 'Wedding Catering', 'Custom menus crafted around your vision, dietary needs, and venue, from rehearsal dinners to grand receptions.', '/service/wedding-catering/', '2022-04-food-catering-cuisine-culinary-gourmet-party-cheers-concept.jpg', '420px'), '60%'),
        W($tile('02', 'Corporate Events', 'Boardroom lunches, company galas, product launches, and multi-day conference catering.', '/service/corporate-catering/', '2022-04-food-catering-cuisine-culinary-gourmet-buffet-part-pp6g36v.jpg', '420px'), '40%'),
    ], '28px', ['margin' => ['top' => '52px']], ['layout' => ['direction' => 'column', 'gap' => '36px']]),
    B('layout.flex', ['direction' => 'row', 'gap' => 'none'], [
        $tile('03', 'Winery Dinners', 'Cuisine designed to complement the finest Paso Robles and Central Coast wines.', '/service/winery-dinner-catering/', '2026-03-hills.webp', '230px'),
        $tile('04', 'Social Celebrations', 'Bar and bat mitzvahs, holidays, anniversaries, and milestone birthdays.', '/service/special-celebrations-catering/', '2022-04-food-buffet-catering-dining-eating-party-sharing-concept-1.jpg', '230px'),
        $tile('05', 'Bar Service', 'Professional mixologists, curated wine lists, and custom cocktail programs.', '/service/bar-service-catering/', '2026-08-cocktail-bar-service.jpeg', '230px'),
        $tile('06', 'Culinary Instruction', 'Private cooking classes, team workshops, and intimate chef’s table experiences.', '/cooking-classes/', '2022-04-cooking-class-salad-bowl-finished.jpg', '230px'),
    ], ['layout' => ['display' => 'grid', 'columns' => 4, 'gap' => '28px'], 'margin' => ['top' => '44px']], ['responsive' => ['tablet' => ['style' => ['layout' => ['columns' => 2]]], 'mobile' => ['style' => ['layout' => ['columns' => 1, 'gap' => '36px'], 'margin' => ['top' => '36px']]]]]),
], CREAM, GUT(), WRAP());

$chef = SEC('Meet the Chef', [
    ROW([
        W(VBOX([
            PIC('2023-08-20230812-181431-scaled.jpg', 'Filo purses plated by Chef Gregory’s team', '520px', '280px'),
            ROW([
                B('core.image', ['media' => IMGREF('design-chef-portrait.jpg', 'Chef Gregory Kalatsky'), 'aspect_ratio' => 'auto', 'rounded' => false], [], ['dimensions' => ['width' => '96px', 'height' => '96px', 'object_fit' => 'cover']], ['classNames' => ['cg-round']]),
                VBOX([B('core.text', ['content' => 'Chef Gregory S. Kalatsky', 'size' => 'inherit'], [], ['color' => INK, 'typography' => ['size' => '22px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.2']]), P('Cooking on the Central Coast since 1997', COCOA, '15px')], '2px'),
            ], '18px', ['background' => ['color' => CREAM], 'padding' => ['top' => '14px', 'right' => '26px', 'bottom' => '14px', 'left' => '14px'], 'margin' => ['top' => '-64px', 'left' => '24px'], 'dimensions' => ['width' => 'fit-content', 'max_width' => 'calc(100% - 48px)']], ['layout' => ['direction' => 'row'], 'margin' => ['top' => '-40px', 'left' => '12px'], 'dimensions' => ['max_width' => 'calc(100% - 24px)']], ['align' => 'center']),
        ]), '50%'),
        W(VBOX([
            EYE('Meet the Chef'),
            H('Culinary Artistry Rooted in Classical Training', 'h2', '50px', '36px'),
            P('Chef Gregory S. Kalatsky is one of California’s Central Coast premier culinary professionals — a graduate of the prestigious Culinary Institute of America in Hyde Park, New York, with a career spanning more than a quarter century of gastronomic excellence.'),
            P('His philosophy is simple yet uncompromising: fresh ingredients, cooked to perfection, with 30–50 hours of pre-event preparation for every single occasion.'),
            B('core.list', ['style' => 'check', 'items' => array_map(static fn(string $t): array => ['text' => $t], ['Graduate of the Culinary Institute of America', 'Award-winning BBQ competitor and restaurateur', 'Expert in event catering, menu development & culinary instruction', 'Perfect 5-star rating on Yelp'])], [], ['color' => INK, 'typography' => ['size' => '16px', 'font_family' => BODY, 'weight' => '500']], ['style_parts' => ['marker' => ['color' => EMBER]]]),
            ROW([BTN('Full Biography', '/about-us/', 'dark')], '0px', ['margin' => ['top' => '8px']], []),
        ], '18px'), '44%'),
    ], '64px', [], ['layout' => ['direction' => 'column', 'gap' => '48px']], ['justify' => 'between', 'align' => 'center']),
], SAND, GUT(), WRAP());

$dish = static function (string $course, string $name, string $text): array {
    return VBOX([EYE($course, EMBER, 'center'), B('core.text', ['content' => $name, 'size' => 'inherit'], [], ['color' => INK, 'typography' => ['size' => '30px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.2'], 'align' => ['base' => 'center']], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => '26px']]]]]), P($text, COCOA, '16px', 'center', ROMAN, 'italic')], '6px');
};
$menu = SEC('Signature Dishes', [
    ROW([
        W(VBOX([
            VBOX([
                EYE('Culinary Masterpieces', EMBER, 'center'),
                H('Signature Dishes', 'h2', '50px', '38px', INK, 'center'),
                $dish('Award-Winning BBQ', 'Slow-Smoked Prime Brisket', 'Oak-smoked for 14 hours over live coals, with a hand-rubbed bark of Central Coast spices, house-made pickled jalapeños and brioche.'),
                $dish('Wedding Signature', 'Herb-Roasted Rack of Lamb', 'French-trimmed California lamb crusted in Dijon and herbes de Provence, with truffle-whipped parsnip and a rosemary reduction.'),
                $dish('Coastal Seafood', 'Pan-Seared Pacific Halibut', 'Wild-caught halibut in brown butter with caperberries, lemon confit, and a golden saffron-potato purée.'),
                $dish('Vegetarian Feature', 'Black Truffle Mushroom Risotto', 'Arborio rice with house-made porcini stock, shaved Périgord truffle, and aged Parmigiano-Reggiano.'),
                $dish('Winery Dinner Dessert', 'Valrhona Chocolate Fondant', 'Warm 70% dark chocolate with a molten center, Paso Robles late-harvest Zinfandel caramel, and vanilla bean crème fraîche.'),
            ], '30px', ['border' => ['width' => '1px', 'style' => 'solid', 'color' => '#c9b99d'], 'padding' => ['top' => '48px', 'right' => '48px', 'bottom' => '48px', 'left' => '48px']], ['responsive' => ['mobile' => ['style' => ['padding' => ['top' => '32px', 'right' => '20px', 'bottom' => '32px', 'left' => '20px']]]]]),
        ], '0px', ['background' => ['color' => CREAM], 'padding' => ['top' => '14px', 'right' => '14px', 'bottom' => '14px', 'left' => '14px']]), '60%'),
        W(VBOX([
            PIC('2026-04-brisket.webp', 'Sliced oak-smoked brisket', '100%', '280px'),
        ], '0px', ['dimensions' => ['min_height' => '100%']], ['classNames' => ['cg-fill']]), '40%'),
    ], '0px', [], ['layout' => ['direction' => 'column-reverse']]),
    ROW([BTN('View Full Menu Gallery', '/gallery/', 'light')], '0px', ['margin' => ['top' => '40px']], ['layout' => ['direction' => 'row', 'justify' => 'center']], ['justify' => 'center']),
], INK, GUT(), WRAP());

$step = static fn(string $n, string $title, string $text): array => VBOX([B('core.text', ['content' => $n, 'size' => 'inherit'], [], ['color' => EMBER, 'typography' => ['size' => '44px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1']]), H($title, 'h3', '26px', '26px'), P($text, COCOA, '16px')], '10px', ['border' => ['top' => ['width' => '1px', 'style' => 'solid', 'color' => INK]], 'padding' => ['top' => '20px']]);
$process = SEC('How We Work', [
    VBOX([EYE('How We Work', EMBER, 'center'), H('The Chef Gregory Experience', 'h2', '52px', '38px', INK, 'center')], '14px'),
    B('layout.flex', ['direction' => 'row', 'gap' => 'none'], [
        $step('1', 'Initial Consultation', 'We meet to understand your vision, guest count, venue, dietary requirements, and event style.'),
        $step('2', 'Custom Menu Design', 'Chef Gregory personally crafts a bespoke menu reflecting the season, your taste, and the character of your event.'),
        $step('3', 'Pre-Event Tasting', 'You taste and refine the menu before the event. You should never serve guests a dish you haven’t personally approved.'),
        $step('4', 'Event Day', 'With 30–50 hours of pre-event preparation, our team arrives fully equipped to deliver from first course to last.'),
    ], ['layout' => ['display' => 'grid', 'columns' => 4, 'gap' => '32px'], 'margin' => ['top' => '52px']], ['responsive' => ['tablet' => ['style' => ['layout' => ['columns' => 2]]], 'mobile' => ['style' => ['layout' => ['columns' => 1, 'gap' => '28px'], 'margin' => ['top' => '36px']]]]]),
], CREAM, GUT(), WRAP());

$slide = static fn(string $q, string $who): array => ['quote' => $q, 'author' => $who, 'caption' => '', 'image' => null];
$reviews = SEC('Client Reviews', [
    VBOX([EYE('★★★★★  Client Reviews', '#f6d9c6', 'center'), H('What Our Guests Say', 'h2', '52px', '38px', '#ffffff', 'center')], '14px'),
    B('core.carousel', ['options' => ['autoplay_ms' => 0, 'loop' => true, 'per_view' => 1], 'slides' => [
        $slide('The food was absolutely delicious and the service was even better. Chef Gregory went above and beyond to make our wedding reception extraordinary — every single guest came up to us raving about the meal.', 'Sarah & Michael T. · Wedding Reception, Paso Robles Winery'),
        $slide('Our winery harvest dinner was an absolute highlight of the season. Chef Gregory’s menu paired so beautifully with our Zinfandel — guests are still talking about it months later.', 'Amanda V. · Harvest Dinner, Paso Robles Winery'),
        $slide('We’ve hired Chef Gregory for our last three company holiday parties, and every year the food outdoes the last. His team is professional, punctual, and genuinely passionate about what they do.', 'Thomas B. · Holiday Party, Central Coast Tech Co.'),
        $slide('Friendly, helpful, and delicious — three words that perfectly describe our experience. The corporate luncheon was flawlessly executed and left a lasting impression on our clients.', 'Robert J. · Corporate Event, San Luis Obispo'),
        $slide('From the initial consultation to the last bite of dessert, working with Chef Gregory was a joy. His attention to detail and genuine care for our guests made our bat mitzvah truly unforgettable.', 'Deborah M. · Bat Mitzvah, San Luis Obispo'),
        $slide('Prices are reasonable for the quality you receive. Chef Gregory provided a pre-tasting experience that was itself worth every penny. Our anniversary dinner was perfect.', 'Linda & James K. · Anniversary Dinner, Santa Barbara'),
    ]], [], ['margin' => ['top' => '36px'], 'dimensions' => ['max_width' => '900px', 'width' => '100%']], ['classNames' => ['cg-reviews'], 'style_parts' => [
        'quote' => ['color' => '#ffffff', 'typography' => ['size' => '28px', 'line_height' => '42px', 'family' => 'heading']],
        'author' => ['color' => '#f6d9c6', 'typography' => ['size' => '15px', 'weight' => 'semibold', 'family' => 'body', 'style' => 'normal']],
    ]]),
], EMBER, GUT('96px', '96px'), WRAP('column', '0px', ['align' => 'center']));

$half = (int) ceil(count($AREAS) / 2);
$areas = SEC('Service Areas', [
    ROW([
        W(VBOX([
            EYE('Where we cook'),
            H('Catering Across the Central Coast', 'h2', '50px', '36px'),
            P('Based in Atascadero, we bring the full kitchen and team to your venue, vineyard, or home, from Cambria to Nipomo.'),
            ROW([W(LINKS(array_slice($AREAS, 0, $half), INK, '18px'), '50%'), W(LINKS(array_slice($AREAS, $half), INK, '18px'), '50%')], '16px', ['margin' => ['top' => '8px']], ['layout' => ['direction' => 'row']]),
            B('core.link', ['link' => L('See all service areas →', '/catering/'), 'style' => 'plain'], [], ['color' => EMBER, 'typography' => ['size' => '16px', 'font_family' => BODY, 'weight' => '600'], 'margin' => ['top' => '8px']], ['classNames' => ['cg-link']]),
        ], '16px'), '44%'),
        W(PIC('2026-03-hills.webp', 'Vineyard hills of the Central Coast', '500px', '260px'), '50%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '36px']], ['justify' => 'between', 'align' => 'center']),
], SAND, GUT(), WRAP());

$blog = SEC('Blog', [
    ROW([VBOX([EYE('From the Kitchen'), H('Chef Gregory’s Blog', 'h2', '52px', '38px')], '14px'), B('core.link', ['link' => L('All posts →', '/blog-post/'), 'style' => 'plain'], [], ['color' => EMBER, 'typography' => ['size' => '16px', 'font_family' => BODY, 'weight' => '600']], ['classNames' => ['cg-link']])], '20px', ['margin' => ['bottom' => '44px']], ['layout' => ['direction' => 'column', 'gap' => '12px'], 'margin' => ['bottom' => '28px']], ['justify' => 'between', 'align' => 'end']),
    B('core.query_loop', ['content_type' => 'blog_post', 'columns' => 3, 'per_page' => 3, 'gap' => 'lg', 'order_by' => 'published_at', 'order' => 'desc', 'show_author' => false, 'show_category' => false, 'show_date' => false, 'read_more_text' => 'Read more', 'enable_pagination' => false], [], [], ['style_parts' => [
        'card' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'border' => ['width' => '0px', 'radius' => '0px'], 'shadow' => ['x' => '0px', 'y' => '0px', 'blur' => '0px', 'spread' => '0px', 'color' => 'rgba(0,0,0,0)']],
        'title' => ['color' => INK, 'typography' => ['size' => '26px', 'line_height' => '32px', 'family' => 'heading', 'weight' => 'normal', 'transform' => 'none']],
        'excerpt' => ['color' => COCOA],
        'body' => ['padding' => ['top' => '18px', 'right' => '0px', 'bottom' => '0px', 'left' => '0px']],
        'button' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'color' => EMBER, 'border' => ['width' => '0px'], 'padding' => ['left' => '0px', 'right' => '0px'], 'typography' => ['weight' => 'semibold']],
    ], 'responsive' => ['mobile' => ['props' => ['columns' => 1]]]]),
], CREAM, GUT(), WRAP());

build_page('home', 'Home', 'page', 'homepage', [$hero, CTOP('Check band', [CHECK()], []), $facts, $services, $chef, $menu, $process, $reviews, $areas, $blog, BOOK()], ['seo_title' => 'Catering in Atascadero, Paso Robles & SLO | Chef Gregory\'s', 'seo_desc' => "CIA-trained wedding, event & party catering across Atascadero, Paso Robles, Templeton & SLO. Fresh local menus, full service. Call 877-357-2433 for a quote.", 'image' => '2026-04-brisket.webp']);
require __DIR__ . '/services.php';
require __DIR__ . '/about.php';
require __DIR__ . '/areas.php';
require __DIR__ . '/food.php';
require __DIR__ . '/blog.php';
require __DIR__ . '/more.php';
LOOPITEM('template-loop-service', 'Loop Item: Service', 'service', 'View service →');
LOOPITEM('template-loop-blog-post', 'Loop Item: Blog Post', 'blog_post', 'Read more →');
LOOPITEM('template-loop-recipe', 'Loop Item: Recipe', 'recipe', 'View recipe →');
LOOPITEM('template-loop-area', 'Loop Item: Catering Area', 'area', 'View area →');
// The Templates screen lists what is in its own table, so the site's templates show up there.
foreach ([['loop-service', 'Loop Item: Service', 'loop'], ['loop-blog-post', 'Loop Item: Blog Post', 'loop'], ['loop-recipe', 'Loop Item: Recipe', 'loop'], ['loop-area', 'Loop Item: Catering Area', 'loop'], ['service', 'Single: Service', 'single'], ['blog-post', 'Single: Blog Post', 'single'], ['recipe', 'Single: Recipe', 'single'], ['area', 'Single: Catering Area', 'single']] as [$key, $name, $category]) {
    $runtime->app->saveTemplate($actor, $key, 'page_template', $category, $name, null, CanonicalDocumentSchema::emptyDocument('system', 'default', $name), null, true);
}
say('design done');
