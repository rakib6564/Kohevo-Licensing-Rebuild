<?php
// The Thanksgiving Menu: on the live site one custom HTML widget with its own look, header and footer. Here, native blocks.
declare(strict_types=1);

const TG_CREAM = '#f7f1e6'; const TG_RUST = '#b4471d'; const TG_DARK = '#1d1815'; const TG_TEXT = '#5c5148';
const TG_SERIF = 'Libre Caslon Display, serif'; const TG_SANS = 'Hanken Grotesk, sans-serif';
const TG_ORDER = 'https://order.spoton.com/so-chef-gregorys-barbeque-19548/atascaderoca/6658c438f62ff1003c24f5d3';
const TG_MENU = 'https://gregory-thanksgiving-feast.base44.app/menu/';

function tgH(string $t, string $lv, int $size, string $color = TG_DARK, string $align = 'left'): array { return B('core.heading', ['text' => $t, 'level' => $lv], [], ['color' => $color, 'typography' => ['size' => "{$size}px", 'font_family' => TG_SERIF, 'weight' => '600', 'line_height' => '1.25'], 'align' => ['base' => $align]]); }
function tgT(string $t, int $size = 15, string $color = TG_TEXT, string $align = 'left', string $weight = '400'): array { return B('core.text', ['content' => $t, 'size' => 'inherit'], [], ['color' => $color, 'typography' => ['size' => "{$size}px", 'font_family' => TG_SANS, 'weight' => $weight, 'line_height' => '1.55'], 'align' => ['base' => $align]]); }
function tgBtn(string $t, string $href, string $bg = TG_RUST, string $fg = '#ffffff'): array { return B('core.button', ['link' => ['label' => $t, 'href' => $href, 'target' => $href[0] === '#' ? '_self' : '_blank'], 'variant' => 'primary'], [], [], ['style_parts' => ['button' => ['background' => ['color' => $bg], 'color' => $fg, 'border' => ['radius' => '0px', 'width' => '1px', 'style' => 'solid', 'color' => $bg === 'rgba(0,0,0,0)' ? '#d8cbb8' : $bg], 'padding' => ['top' => '12px', 'right' => '26px', 'bottom' => '12px', 'left' => '26px']]]]); }
function tgCard(array $kids, string $bg = '#ffffff', array $more = []): array { return B('layout.flex', ['direction' => 'column', 'gap' => 'none', 'align' => 'stretch'], $kids, array_replace_recursive(['background' => ['color' => $bg], 'padding' => ['top' => '24px', 'right' => '26px', 'bottom' => '24px', 'left' => '26px'], 'border' => ['radius' => '0px'], 'layout' => ['gap' => '10px']], $more)); }
function tgGrid(int $cols, array $kids): array { return B('layout.grid', ['columns' => $cols, 'gap' => 'none'], $kids, ['layout' => ['display' => 'grid', 'columns' => $cols, 'gap' => '20px']], ['responsive' => ['mobile' => ['style' => ['layout' => ['columns' => 1]]]]]); }
function tgSec(string $label, array $kids, string $bg = TG_CREAM, string $max = '1100px'): array { return CTOP($label, $kids, ['background' => ['color' => $bg], 'padding' => ['top' => '72px', 'right' => '24px', 'bottom' => '72px', 'left' => '24px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '26px', 'content_width' => 'boxed', 'content_max' => $max]]); }

$dishes = [
    ['Slow Roasted & Smoked Tri-tip', 'Cooked to perfection, medium rare.', 'Sold by the pound. 1 1/2 lb minimum order.', 'tri-tip', ''],
    ['Whole Roasted Turkey', 'Our classic whole turkey, roasted to golden perfection.', '8 to 16 lbs (sizes vary with available birds).', 'whole-roasted-turkey', ''],
    ['Mashed Potatoes', 'Creamy mashed potatoes with roasted garlic and a hint of fresh rosemary.', '¼ pan or ½ pan.', 'mashed-potatoes', ''],
    ['Classical Potato Gratin', 'Yukon Gold potatoes smothered in cream with Gruyère cheese, garlic, and butter.', '¼ pan or ½ pan.', 'potato-gratin', ''],
    ['Traditional Green Bean Casserole', 'Del Monte’s best, topped with crispy fried onions.', '¼ pan or ½ pan.', 'green-bean-casserole', ''],
    ['Roasted Brussels Sprouts', 'Caramelized onion, bacon bits, and a balsamic reduction.', '¼ pan or ½ pan.', 'brussels-sprouts', ''],
    ['Roasted Carrots', 'Glazed with garlic and thyme.', '¼ pan or ½ pan.', 'roasted-carrots', ''],
    ['Fresh Cranberry Sauce', 'Freshly made for the holiday.', 'By the pint.', 'cranberry-sauce', ''],
    ['Turkey Gravy', 'Rich, house-made turkey gravy.', 'By the pint.', 'turkey-gravy', ''],
    ['Brioche Rolls', 'Soft, buttery brioche rolls, baked fresh for your table.', '[PRICE per dozen or per roll].', 'brioche-rolls', 'Customers may provide their own butter.'],
    ['Dinner Rolls', 'Warm, classic dinner rolls for the holiday table.', '[PRICE per dozen or per roll].', 'dinner-rolls', 'Customers may provide their own butter.'],
];
$cards = [];
foreach ($dishes as [$name, $text, $size, $slug, $note]) {
    $cards[] = tgCard(array_filter([
        tgH($name, 'h3', 22), tgT($text), tgT($size, 13, TG_RUST, 'left', '500'), $note !== '' ? tgT($note, 12, '#8a7f73') : null,
        B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center'], [
            B('core.link', ['link' => ['label' => 'View Details', 'href' => TG_MENU . $slug, 'target' => '_blank'], 'style' => 'underline'], [], ['color' => TG_DARK, 'typography' => ['size' => '13px', 'font_family' => TG_SANS, 'weight' => '600']]),
            tgBtn('Order This', TG_ORDER),
        ], ['layout' => ['justify' => 'between', 'align' => 'center', 'gap' => '12px'], 'margin' => ['top' => 'auto']]),
    ]));
}
$date = static fn(string $k, string $v): array => tgCard([tgT($k, 12, '#b9a48e', 'center', '500'), tgH($v, 'h3', 20, '#ffffff', 'center')], 'rgba(255,255,255,0.04)', ['border' => ['width' => '1px', 'style' => 'solid', 'color' => 'rgba(255,255,255,0.14)']]);
$pickup = static fn(string $icon, string $h, string $t): array => tgCard([B('core.icon', ['name' => $icon, 'size_px' => 24, 'align' => 'center'], [], ['color' => TG_RUST]), tgH($h, 'h3', 20, TG_DARK, 'center'), tgT($t, 14, TG_TEXT, 'center')]);
$step = static fn(string $n, string $h, string $t): array => tgCard([B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'start'], [tgH($n, 'h3', 44, TG_RUST), B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [tgH($h, 'h3', 22), tgT($t)], ['layout' => ['gap' => '6px']])], ['layout' => ['gap' => '20px']])]);
$field = static fn(string $type, string $name, string $label, bool $req): array => B('core.form_field', ['field_type' => $type, 'name' => $name, 'label' => $label, 'placeholder' => $label, 'required' => $req]);

$hero = tgSec('Thanksgiving intro', [
    tgT('ATASCADERO, CALIFORNIA', 13, TG_RUST, 'center', '500'), tgH('Thanksgiving Menu', 'h1', 64, TG_DARK, 'center'),
    tgT('Award-winning Thanksgiving dinner. Create your ultimate Thanksgiving dinner by choosing from the list below, and order online by Tuesday, November 17 at 6:00 PM.', 18, TG_TEXT, 'center'),
    tgT('Once your order is placed, we give you a call back to confirm your time and pickup on Thursday, November 26 between 12:00 PM and 3:00 PM.', 16, TG_TEXT, 'center'),
    B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'center', 'wrap' => 'wrap'], [tgBtn('View the Menu', '#cg-menu'), tgBtn('Order Now', TG_ORDER, 'rgba(0,0,0,0)', TG_DARK)], ['layout' => ['justify' => 'center', 'gap' => '14px']]),
    B('core.image', ['media' => IMGREF('thanksgiving-feast.jpg', 'A Thanksgiving feast'), 'aspect_ratio' => 'auto', 'rounded' => false], [], ['border' => ['radius' => '0px']]),
], TG_CREAM, '860px');
$menu = tgSec('Menu', [tgH('Choose From the List Below', 'h2', 40, TG_DARK, 'center'), tgT('Pan sizes: ¼ pan = 2 quarts, serves 8 (8 oz per serving).', 14, TG_TEXT, 'center'), tgGrid(3, $cards), tgT('Pickup Only: all orders are for pickup at our Atascadero location.', 14, TG_TEXT, 'center')]);
$dates = tgSec('Key dates', [tgH('Key Dates', 'h2', 34, '#ffffff', 'center'), tgGrid(3, [$date('ORDER DEADLINE', 'Tuesday, November 17 at 6:00 PM'), $date('PICKUP DAY', 'Thursday, November 26'), $date('PICKUP WINDOW', '12:00 PM to 3:00 PM')])], TG_DARK);
$how = tgSec('How pickup works', [
    tgH('How Pickup Works', 'h2', 40, TG_DARK, 'center'),
    tgGrid(3, [$pickup('check', 'Order by Nov 17, 6:00 PM', 'Order online by Tuesday, November 17 at 6:00 PM to reserve your dinner.'), $pickup('phone', 'We call you back', 'Once your order is placed, we call you to confirm your pickup time.'), $pickup('map-pin', 'Pickup Nov 26, 12–3 PM', 'Pickup is Thursday, November 26 between 12:00 PM and 3:00 PM.')]),
    tgCard([tgH('Please be punctual for your window', 'h3', 24), tgT('Your pickup time is reserved specifically for you, and we have a lot of people arriving that day. It works like a doctor’s appointment: if your time is 2:00, please arrive five minutes early or exactly on time, not 2:10.'), tgT('When you arrive in your window, your meal will be packed and ready to go. Arriving on time keeps every pickup moving and your food at its best.')], '#ffffff', ['border' => ['left' => ['width' => '3px', 'style' => 'solid', 'color' => TG_RUST]]]),
], TG_CREAM, '960px');
$order = tgSec('How to order', [
    tgH('How to Order', 'h2', 40, TG_DARK, 'center'),
    $step('1', 'Choose your menu.', 'Browse the Thanksgiving Menu and pick the items you want. Mix and match, and choose ¼ or ½ pans for the sides.'),
    $step('2', 'Place your order online.', 'Click any “Order” button on the page. You will be taken to our online ordering page to complete your order.'),
    $step('3', 'Confirm your pickup time.', 'Once your order is placed, we will call you to confirm a pickup time on Thursday, November 26, between 12:00 PM and 3:00 PM. That time is reserved specifically for you.'),
    $step('4', 'Pick up and enjoy.', 'Pick up your order at our Atascadero location and enjoy your holiday meal.'),
    tgCard([tgH('Order deadline: Tuesday, November 17 at 6:00 PM', 'h3', 20, '#ffffff', 'center'), tgH('Pickup: Thursday, November 26, 12:00 PM to 3:00 PM', 'h3', 20, '#ffffff', 'center')], TG_DARK),
], TG_CREAM, '860px');
$contact = tgSec('Contact', [
    tgH('Contact Us', 'h2', 40, TG_DARK, 'center'),
    tgGrid(2, [
        B('layout.flex', ['direction' => 'column', 'gap' => 'none', 'align' => 'start'], [
            B('core.rich_text', ['content' => '<p>Phone: <a href="tel:949-547-8117">949-547-8117</a></p><p>Email: <a href="mailto:chef@chefgregory.com">chef@chefgregory.com</a></p><p>Location: Atascadero, CA</p>'], [], ['color' => TG_TEXT, 'typography' => ['size' => '16px', 'font_family' => TG_SANS]]),
            tgT('For Thanksgiving orders, please place your order online so we can confirm your pickup time.'), tgBtn('Order Now', TG_ORDER),
        ], ['layout' => ['gap' => '16px']]),
        B('core.form', ['form_name' => 'Thanksgiving contact', 'notify_email' => 'chef@chefgregory.com', 'submit_text' => 'Send Message', 'submit_variant' => 'primary', 'success_message' => 'Thank you. We will be in touch.'], [$field('text', 'name', 'Name', true), $field('email', 'email', 'Email', true), $field('tel', 'phone', 'Phone', false), $field('textarea', 'message', 'Message', true)]),
    ]),
], '#ffffff', '960px');
$foot = tgSec('Thanksgiving footer', [tgGrid(3, [
    B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [tgH('Chef Gregory’s Gourmet Catering', 'h3', 20, '#ffffff'), tgT('ChefGregory.com', 13, '#b9a48e')]),
    B('core.rich_text', ['content' => '<p>Phone: <a href="tel:949-547-8117">949-547-8117</a></p><p>Email: <a href="mailto:chef@chefgregory.com">chef@chefgregory.com</a></p><p>Location: Atascadero, CA</p>'], [], ['color' => '#d9cfc3', 'typography' => ['size' => '14px', 'font_family' => TG_SANS]]),
    B('layout.flex', ['direction' => 'column', 'gap' => 'none'], array_map(static fn(array $l): array => B('core.link', ['link' => ['label' => $l[0], 'href' => $l[1], 'target' => '_self'], 'style' => 'plain'], [], ['color' => '#d9cfc3', 'typography' => ['size' => '14px', 'font_family' => TG_SANS]]), [['Thanksgiving Menu', '#cg-menu'], ['Pickup', '#cg-pickup'], ['How to Order', '#cg-how'], ['Contact', '#cg-contact']]), ['layout' => ['gap' => '6px']]),
]), tgT('© 2026 Chef Gregory’s Gourmet Catering. All rights reserved.', 12, '#8a7f73', 'center')], TG_DARK);
foreach ([[$menu, 'cg-menu'], [$how, 'cg-pickup'], [$order, 'cg-how'], [$contact, 'cg-contact']] as $i => [$c, $anchor]) { $c['attributes'] = ['id' => $anchor]; ${['menu', 'how', 'order', 'contact'][$i]} = $c; }
build_page('thanksgiving-menu', 'Thanksgiving Menu', 'page', 'standalone', [$hero, $menu, $dates, $how, $order, $contact], ['seo_title' => (string) $CAP['/thanksgiving-menu/']['d']['title'], 'seo_desc' => (string) $CAP['/thanksgiving-menu/']['d']['meta'], 'image' => 'thanksgiving-feast.jpg']);
