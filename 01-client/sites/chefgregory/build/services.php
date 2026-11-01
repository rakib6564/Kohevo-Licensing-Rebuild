<?php
// Chef Gregory's redesign: the Services pages, the seven service items and their Single template.
declare(strict_types=1);

function FVB(string $key, array $style = [], array $opt = []): array { return B('theme.field_value', ['field' => $key, 'tag' => 'div'], [], $style, $opt); }
function EYESTYLE(string $color): array { return ['color' => $color, 'typography' => ['size' => '13px', 'font_family' => BODY, 'weight' => '600', 'transform' => 'uppercase', 'letter_spacing' => '2.4px', 'line_height' => '1.4']]; }
function PROSE(string $html): array { return B('core.rich_text', ['content' => $html], [], ['color' => COCOA, 'typography' => ['size' => '18px', 'font_family' => BODY, 'weight' => '400', 'line_height' => '1.65']], ['classNames' => ['cg-prose']]); }
function SVCGRID(): array
{
    return B('core.query_loop', ['content_type' => 'service', 'columns' => 3, 'per_page' => 7, 'gap' => 'lg', 'order_by' => 'published_at', 'order' => 'asc', 'show_author' => false, 'show_category' => false, 'show_date' => false, 'read_more_text' => 'View service →', 'enable_pagination' => false], [], [], ['classNames' => ['cg-svc-grid'], 'style_parts' => [
            'card' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'border' => ['width' => '0px', 'radius' => '0px'], 'shadow' => ['x' => '0px', 'y' => '0px', 'blur' => '0px', 'spread' => '0px', 'color' => 'rgba(0,0,0,0)']],
            'title' => ['color' => INK, 'typography' => ['size' => '30px', 'line_height' => '36px', 'family' => 'heading', 'weight' => 'normal', 'transform' => 'none']],
            'excerpt' => ['color' => COCOA, 'typography' => ['size' => '16px', 'line_height' => '25px']],
            'body' => ['padding' => ['top' => '18px', 'right' => '0px', 'bottom' => '0px', 'left' => '0px']],
            'button' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'color' => EMBER, 'border' => ['width' => '0px'], 'padding' => ['left' => '0px', 'right' => '0px'], 'typography' => ['weight' => 'semibold']],
        ], 'responsive' => ['mobile' => ['props' => ['columns' => 1]]]]);
}
function ACC(array $rows): array
{
    return B('core.accordion', ['items' => $rows], [], [], ['classNames' => ['cg-acc'], 'style_parts' => [
            'item' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'border' => ['width' => '0px', 'radius' => '0px']],
            'title' => ['color' => INK, 'typography' => ['size' => '24px', 'family' => 'heading', 'weight' => 'normal']],
            'content' => ['color' => COCOA, 'typography' => ['size' => '17px', 'line_height' => '27px']],
        ]]);
}
/** Whole sentences up to the limit, so a card never ends mid-thought. */
function SENTENCES(string $text, int $max = 190): string
{
    $out = '';
    foreach (preg_split('/(?<=[.!?])\s+/', $text) ?: [] as $s) {
        if ($out !== '' && mb_strlen($out . ' ' . $s) > $max) { break; }
        $out = trim($out . ' ' . $s);
    }
    return $out;
}

// The live site's item heroes are small or repeat other pages; these are the sharper fits.
$SVCIMG = ['wedding-catering' => '2022-04-food-catering-cuisine-culinary-gourmet-party-cheers-concept.jpg', 'private-chef-catering' => '2023-08-20230810-195536-scaled.jpg', 'winery-dinner-catering' => '2026-03-hills.webp', 'special-celebrations-catering' => '2022-04-food-buffet-catering-dining-eating-party-sharing-concept-1.jpg'];

// ── Items: only what differs from service to service ───────────────────────────
foreach (array_values(array_filter(array_keys($CAP), static fn($u) => preg_match('~^/service/.+~', $u))) as $u) {
    $x = $MAIN($u);
    $slug = basename($u);
    $h = []; collect($x['d'][1], 'heading', $h);
    $t = []; collect($x['d'][1], 'text-editor', $t);
    $included = array_map(static fn(string $l): string => str_replace(': YES', ': Yes', (string) preg_replace('/^(.+?)\s*[-–]\s+/u', '$1: ', $l)), $itemMeta($u));
    $hero = $SVCIMG[$slug] ?? $heroFile($x['d'][0]);
    $text = plain($WP[$u]['content']['rendered']);
    build_page($slug, trim(html_entity_decode($WP[$u]['title']['rendered'])), 'page', 'standalone', [
        CTOP('Service details', [H(trim((string) $h[0]['text']), 'h2', '44px', '34px'), PROSE(clean_html((string) $t[0]['html']))], ['layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '20px']]),
    ], [
        'type' => 'service', 'fields' => ['hero_image' => MEDIA_DIR . $hero, 'eyebrow' => 'Service · Central Coast California', 'included' => implode("\n", $included)],
        'seo_title' => $CAP[$u]['d']['title'], 'seo_desc' => $meta($u), 'image' => $hero, 'date' => $WP[$u]['date'], 'settings' => ['excerpt' => SENTENCES($text)],
    ]);
}

// ── Single: Service ────────────────────────────────────────────────────────────
$charge = static fn(string $name, string $text): array => ROW([
    W(B('core.text', ['content' => $name, 'size' => 'inherit'], [], ['color' => INK, 'typography' => ['size' => '22px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.3']]), '30%'),
    W(P($text, COCOA, '17px'), '70%'),
], '24px', ['border' => ['bottom' => ['width' => '1px', 'style' => 'solid', 'color' => '#d9ccb5']], 'padding' => ['top' => '16px', 'bottom' => '16px']], ['layout' => ['direction' => 'column', 'gap' => '2px']]);
$pkg = static fn(string $name): array => B('core.text', ['content' => $name, 'size' => 'inherit'], [], ['color' => INK, 'border' => ['bottom' => ['width' => '1px', 'style' => 'solid', 'color' => '#d9ccb5']], 'padding' => ['top' => '13px', 'bottom' => '13px'], 'typography' => ['size' => '22px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.3']]);
$chips = [];
foreach ($SERVICES as [$label, $href]) { $chips[] = B('core.link', ['link' => L($label, $href), 'style' => 'plain'], [], ['color' => CREAM, 'typography' => ['size' => '15px', 'font_family' => BODY, 'weight' => '500']], ['classNames' => ['cg-link']]); }

SINGLE('template-service', 'Single: Service', 'service', [
    SEC('Hero', [ROW([
        W(VBOX([
            B('core.link', ['link' => L('← All services', '/services/'), 'style' => 'plain'], [], ['color' => '#d9cdbd', 'typography' => ['size' => '14px', 'font_family' => BODY, 'weight' => '500']], ['classNames' => ['cg-link']]),
            FVB('eyebrow', EYESTYLE(EMBERLT)),
            B('theme.post_title', ['level' => 'h1'], [], ['color' => CREAM, 'typography' => ['size' => '64px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.1']], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => '42px']]]]]),
            ROW([BTN('Book Your Tasting', '/contact/'), BTN('Call ' . PHONE, 'tel:' . PHONE, 'light')], '12px', ['margin' => ['top' => '10px']], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']], ['wrap' => 'wrap']),
        ], '18px'), '46%'),
        W(FVB('hero_image', ['dimensions' => ['height' => '440px', 'width' => '100%', 'overflow' => 'hidden']], ['classNames' => ['cg-hero-img'], 'responsive' => ['mobile' => ['style' => ['dimensions' => ['height' => '250px']]]]]), '50%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '32px']], ['justify' => 'between', 'align' => 'center'])], INK, ['padding' => ['top' => '52px', 'right' => '24px', 'bottom' => '60px', 'left' => '24px']], WRAP(), '40px'),
    CTOP('Check band', [CHECK()], []),
    SEC('Details', [ROW([
        W(VBOX([B('theme.post_content', []), ROW([BTN('Request a Consultation', '/contact/', 'dark')], '0px', ['margin' => ['top' => '24px']], [])]), '56%'),
        W(VBOX([
            CHECK(),
            VBOX([H('What’s Included', 'h2', '34px', '30px', CREAM), FVB('included', ['color' => CREAM, 'typography' => ['size' => '16px', 'font_family' => BODY, 'line_height' => '1.5']], ['classNames' => ['cg-included']])], '10px', ['padding' => ['top' => '28px', 'right' => '28px', 'bottom' => '20px', 'left' => '28px']]),
        ], '0px', ['background' => ['color' => INK]], ['classNames' => ['cg-sticky']]), '38%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '40px']], ['justify' => 'between', 'align' => 'start'])], CREAM, GUT('96px', '96px'), WRAP(), '60px'),
    SEC('Packages', [ROW([
        W(VBOX([EYE('Service Options'), H('Choose Your Package', 'h2', '50px', '36px'), P('Every package is fully customizable. These structures provide a starting framework — the final scope is always tailored to your specific vision.'), P('All of our packages have similar charges involved with them. All bar packages come with many options, please communicate with us to discuss them.')], '16px'), '50%'),
        W(VBOX([EYE('Our Packages'), VBOX(array_map($pkg, ['Limited Bar Pkg', 'Classical service', 'Rock’n’roll Bar Pkg', 'Opera Kettle One Bar Pkg', 'Opera Grey Goose Bar Pkg 2022'])), ROW([BTN('Book Now', '/contact/')], '0px', ['margin' => ['top' => '24px']], [])], '8px', ['background' => ['color' => CREAM], 'padding' => ['top' => '32px', 'right' => '32px', 'bottom' => '32px', 'left' => '32px']]), '42%'),
    ], '40px', [], ['layout' => ['direction' => 'column', 'gap' => '32px']], ['justify' => 'between', 'align' => 'center'])], SAND, GUT('96px', '96px'), WRAP(), '60px'),
    SEC('Charges', [
        VBOX([EYE('No surprises'), H('Charges Are as Follows', 'h2', '50px', '36px'), P('People always want to know how much things cost ahead of time, so here is the bottom part of our catering agreement, showing labor, rentals, service charge, and taxes. The only things that change are the rentals, the time we spend on site, and the food you choose. Tri-tip sandwiches and smoked prime are priced differently per person.')], '14px', ['dimensions' => ['max_width' => '800px'], 'margin' => ['bottom' => '32px']]),
        $charge('Subtotal', 'All of the food that is ordered: bars, appetizers, salads, main courses, and desserts.'),
        $charge('Tax', 'For food and drinks only, at the sales tax of the area you live in.'),
        $charge('1 Chef On Site', '$185 an hour for time on site.'),
        $charge('Servers On Site', '$25 an hour.'),
        $charge('Gratuity', 'Up to you for the most part. For large numbers we add gratuity at 20% of the food and beverage only.'),
        $charge('Service Fee', '$295 for the time, effort, and energy used to purchase products, make phone calls, and arrange rentals.'),
        $charge('Rentals', 'Can be expensive depending on your event, or there may be hardly any charge at all if you already have much of what a successful event needs.'),
        VBOX([P('Time on site is an approximation and may vary by 1–3 hours at most.', COCOA, '15px', 'left', ROMAN, 'italic')], '0px', ['margin' => ['top' => '18px']]),
    ], CREAM, GUT('96px', '96px'), WRAP('column', '0px'), '60px'),
    SEC('More services', [EYE('More services', EMBERLT), ROW($chips, '28px', ['margin' => ['top' => '14px']], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']], ['wrap' => 'wrap'])], INK, ['padding' => ['top' => '44px', 'right' => '24px', 'bottom' => '44px', 'left' => '24px']], WRAP(), '40px'),
    BOOK(),
]);

// ── Services and the /service/ list ────────────────────────────────────────────
$acc = [];
$s0 = $MAIN('/services/')['d'];
foreach ($s0 as $top) { collect($top, 'accordion', $acc); }
$rows = array_map(static fn(array $i): array => ['heading' => $i['title'], 'body' => plain($i['html'])], $acc[0]['items']);
$servicesPage = static fn(): array => [
    SEC('Hero', [ROW([
        W(VBOX([
            EYE('Our Services', EMBERLT),
            H('We Provide Premium Catering Services', 'h1', '64px', '42px', CREAM),
            P('Whether it’s a wedding, a memorial, or an intimate gathering with friends, we’re here to elevate your event. Our team expertly handles every detail related to food and catering, so you can focus on what truly matters: enjoying time with your guests.', '#d9cdbd'),
            ROW([BTN('Book Your Tasting', '/contact/'), BTN('Plan Your Event', '/help-us-help-you/', 'light')], '12px', ['margin' => ['top' => '10px']], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']], ['wrap' => 'wrap']),
        ], '20px'), '50%'),
        W(PIC('2022-04-food-catering-cuisine-culinary-gourmet-party-conce-pa7gygx.jpg', 'Guests sharing a catered meal', '480px', '250px'), '44%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '32px']], ['justify' => 'between', 'align' => 'center'])], INK, ['padding' => ['top' => '56px', 'right' => '24px', 'bottom' => '64px', 'left' => '24px']], WRAP(), '40px'),
    CTOP('Check band', [CHECK()], []),
    SEC('All services', [
        VBOX([EYE('What we cater', EMBER, 'center'), H('Catering for Every Occasion', 'h2', '52px', '38px', INK, 'center')], '14px', ['margin' => ['bottom' => '48px']]),
        SVCGRID(),
    ], CREAM, GUT(), WRAP()),
    SEC('Planning', [ROW([
        W(VBOX([EYE('Good to know'), H('Planning Your Catering', 'h2', '50px', '36px'), P('How we approach each kind of event, from weddings and platters to dine-in service and cooking classes.'), ROW([BTN('Plan Your Event', '/help-us-help-you/', 'dark')], '0px', ['margin' => ['top' => '8px']], [])], '14px', [], ['classNames' => ['cg-sticky']]), '36%'),
        W(ACC($GLOBALS['rows']), '58%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '32px']], ['justify' => 'between', 'align' => 'start'])], SAND, GUT('96px', '96px'), WRAP(), '60px'),
    BOOK(),
];
foreach (['/services/' => 'services', '/service/' => 'service'] as $u => $slug) {
    build_page($slug, 'Services', 'page', 'standalone', $servicesPage(), ['seo_title' => $CAP['/services/']['d']['title'], 'seo_desc' => $meta('/services/') ?: $meta($u), 'image' => '2022-04-food-catering-cuisine-culinary-gourmet-party-conce-pa7gygx.jpg']);
}
