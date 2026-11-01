<?php
// Chef Gregory's redesign: the catering areas, their Single template and the overview at /catering/.
declare(strict_types=1);

// The live items all share one hero. These photos are for variety only and do not claim to be of the town.
$AREAIMG = ['paso-robles' => '2026-03-hills.webp', 'paso-robles-wedding-catering' => '2022-04-food-catering-cuisine-culinary-gourmet-party-cheers-concept.jpg', 'atascadero' => '2026-08-img-7106-scaled.webp', 'templeton' => '2026-03-roasted-rack-of-lamb-7-scaled-1.png', 'san-miguel' => '2022-04-food-catering-cuisine-culinary-gourmet-buffet-part-pp6g36v.jpg', 'san-luis-obispo' => '2026-03-pan-seared-halibut-steaks-with-c.webp', 'morro-bay' => '2023-07-20210622-125632-scaled.jpg', 'los-osos' => '2022-04-food-catering-cuisine-culinary-gourmet-buffet-part-paruax2.jpg', 'cambria' => '2022-04-food-catering-cuisine-culinary-gourmet-buffet-part-p9rbzq8.jpg', 'pismo-beach' => '2023-08-20230812-181431-scaled.jpg', 'arroyo-grande' => '2026-04-brisket.webp', 'nipomo' => '2022-04-food-buffet-catering-dining-eating-party-sharing-concept-2.jpg'];

foreach (array_values(array_filter(array_keys($CAP), static fn($u) => preg_match('~^/(catering/.+|catering-in-.+|paso-robles-wedding-catering/)$~', $u))) as $u) {
    $x = $MAIN($u);
    $slug = basename($u);
    $key = (string) preg_replace('~^catering-in-~', '', $slug);
    $heads = []; $texts = []; $accs = [];
    foreach ($x['d'] as $top) { collect($top, 'heading', $heads); collect($top, 'text-editor', $texts); collect($top, 'accordion', $accs); }
    $title = trim(html_entity_decode($WP[$u]['title']['rendered'] ?? ''));
    $title = $title ?: trim((string) ($heads[0]['text'] ?? ucfirst($slug)));
    $town = trim((string) preg_replace('/^Catering in /', '', $title));
    $eyebrow = count($heads) >= 4 ? trim((string) $heads[0]['text']) : "{$town} · Central Coast";
    $h2 = trim((string) $heads[count($heads) - 1]['text']);
    $body = clean_html((string) $texts[array_key_last($texts)]['html']);
    $hero = $AREAIMG[$key] ?? '2026-03-hills.webp';
    $rows = array_map(static fn(array $i): array => ['question' => $i['title'], 'answer' => clean_html((string) $i['html'])], $accs[0]['items'] ?? []);
    if ($slug !== $key) { Database::query("UPDATE studiobuilder_pages SET status = 'archived' WHERE tenant_id = ? AND slug = ? AND page_type = 'page'", [$tid, $key]); } // an earlier build's shorter address
    $AREAPAGES[$slug] = !str_starts_with($u, '/catering/');
    $AREAIDS[$slug] = build_page($slug, $title, 'page', 'standalone', [], ['type' => 'area', 'fields' => ['hero_image' => MEDIA_DIR . $hero, 'eyebrow' => $eyebrow, 'area_heading' => $h2, 'area_description' => $body, 'faq' => $rows], 'seo_title' => $CAP[$u]['d']['title'], 'seo_desc' => $meta($u), 'image' => $hero, 'settings' => ['excerpt' => SENTENCES(plain($body), 170)]]);
}

$chips = [];
foreach ([...$AREAS, ['Paso Robles Weddings', '/paso-robles-wedding-catering/']] as [$label, $href]) { $chips[] = B('core.link', ['link' => L($label, $href), 'style' => 'plain'], [], ['color' => CREAM, 'typography' => ['size' => '15px', 'font_family' => BODY, 'weight' => '500']], ['classNames' => ['cg-link']]); }

SINGLE('template-area', 'Single: Catering Area', 'area', [
    SEC('Hero', [ROW([
        W(VBOX([
            B('core.link', ['link' => L('← All service areas', '/catering/'), 'style' => 'plain'], [], ['color' => '#d9cdbd', 'typography' => ['size' => '14px', 'font_family' => BODY, 'weight' => '500']], ['classNames' => ['cg-link']]),
            FVB('eyebrow', EYESTYLE(EMBERLT)),
            B('theme.post_title', ['level' => 'h1'], [], ['color' => CREAM, 'typography' => ['size' => '64px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.1']], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => '42px']]]]]),
            ROW([BTN('Book Your Tasting', '/contact/'), BTN('Call ' . PHONE, 'tel:' . PHONE, 'light')], '12px', ['margin' => ['top' => '10px']], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']], ['wrap' => 'wrap']),
        ], '18px'), '46%'),
        W(FVB('hero_image', ['dimensions' => ['height' => '440px', 'width' => '100%', 'overflow' => 'hidden']], ['responsive' => ['mobile' => ['style' => ['dimensions' => ['height' => '250px']]]]]), '50%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '32px']], ['justify' => 'between', 'align' => 'center'])], INK, ['padding' => ['top' => '52px', 'right' => '24px', 'bottom' => '60px', 'left' => '24px']], WRAP(), '40px'),
    CTOP('Check band', [CHECK()], []),
    SEC('Details', [ROW([
        W(VBOX([HEADF('area_heading'), BODYF('area_description'), FAQF()], '20px'), '60%'),
        W(VBOX([
            CHECK(),
            VBOX([
                H('Every Event Includes', 'h2', '32px', '28px', CREAM),
                B('core.list', ['style' => 'check', 'items' => array_map(static fn(string $t): array => ['text' => $t], ['A complimentary pre-event tasting', 'A custom menu by Chef Gregory', 'Fresh, local ingredients', 'Setup and service, start to finish'])], [], ['color' => CREAM, 'typography' => ['size' => '16px', 'font_family' => BODY, 'weight' => '500']], ['style_parts' => ['marker' => ['color' => EMBERLT]]]),
                ROW([BTN('Plan Your Event', '/contact/help-us-help-you/')], '0px', ['margin' => ['top' => '10px']], []),
            ], '12px', ['padding' => ['top' => '28px', 'right' => '28px', 'bottom' => '28px', 'left' => '28px']]),
        ], '0px', ['background' => ['color' => INK]], ['classNames' => ['cg-sticky']]), '34%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '40px']], ['justify' => 'between', 'align' => 'start'])], CREAM, GUT('96px', '96px'), WRAP(), '60px'),
    SEC('Services', [VBOX([EYE('Our Services', EMBER, 'center'), H('Catering for Every Occasion', 'h2', '52px', '38px', INK, 'center')], '14px', ['margin' => ['bottom' => '48px']]), SVCGRID()], SAND, GUT('96px', '96px'), WRAP(), '60px'),
    SEC('More areas', [EYE('We also cater in', EMBERLT), ROW($chips, '28px', ['margin' => ['top' => '14px']], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']], ['wrap' => 'wrap'])], INK, ['padding' => ['top' => '44px', 'right' => '24px', 'bottom' => '44px', 'left' => '24px']], WRAP(), '40px'),
    BOOK(),
]);

build_page('catering', 'Catering Service Areas', 'page', 'standalone', [
    SEC('Hero', [ROW([
        W(VBOX([EYE('Serving the Central Coast', EMBERLT), H('Central Coast Catering Service Areas', 'h1', '64px', '42px', CREAM), P('From Paso Robles to Santa Barbara, Chef Gregory brings weddings, winery dinners, and corporate events to life across California’s Central Coast.', '#d9cdbd'), ROW([BTN('Book Your Tasting', '/contact/'), BTN('Call ' . PHONE, 'tel:' . PHONE, 'light')], '12px', ['margin' => ['top' => '10px']], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']], ['wrap' => 'wrap'])], '20px'), '50%'),
        W(PIC('2026-03-hills.webp', 'Vineyard hills of the Central Coast', '460px', '240px'), '44%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '32px']], ['justify' => 'between', 'align' => 'center'])], INK, ['padding' => ['top' => '56px', 'right' => '24px', 'bottom' => '64px', 'left' => '24px']], WRAP(), '40px'),
    CTOP('Check band', [CHECK()], []),
    SEC('Areas', [
        VBOX([EYE('Choose your area', EMBER, 'center'), H('Explore Our Service Areas', 'h2', '52px', '38px', INK, 'center'), P('Chef Gregory’s Gourmet Catering serves weddings, winery dinners, and corporate events across the Central Coast. Choose your area to start planning.', COCOA, '18px', 'center')], '14px', ['margin' => ['bottom' => '48px'], 'dimensions' => ['max_width' => '760px']]),
        B('core.query_loop', ['content_type' => 'area', 'columns' => 3, 'per_page' => 12, 'gap' => 'lg', 'order_by' => 'title', 'order' => 'asc', 'show_author' => false, 'show_category' => false, 'show_date' => false, 'read_more_text' => 'View area →', 'enable_pagination' => false], [], [], ['style_parts' => [
            'card' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'border' => ['width' => '0px', 'radius' => '0px'], 'shadow' => ['x' => '0px', 'y' => '0px', 'blur' => '0px', 'spread' => '0px', 'color' => 'rgba(0,0,0,0)']],
            'title' => ['color' => INK, 'typography' => ['size' => '28px', 'line_height' => '34px', 'family' => 'heading', 'weight' => 'normal', 'transform' => 'none']],
            'excerpt' => ['color' => COCOA, 'typography' => ['size' => '16px', 'line_height' => '25px']],
            'body' => ['padding' => ['top' => '16px', 'right' => '0px', 'bottom' => '0px', 'left' => '0px']],
            'button' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'color' => EMBER, 'border' => ['width' => '0px'], 'padding' => ['left' => '0px', 'right' => '0px'], 'typography' => ['weight' => 'semibold']],
        ], 'responsive' => ['mobile' => ['props' => ['columns' => 1]]]]),
    ], CREAM, GUT(), WRAP('column', '0px', ['align' => 'center'])),
    BOOK(),
], ['seo_title' => $CAP['/catering/']['d']['title'], 'seo_desc' => $meta('/catering/'), 'image' => '2026-03-hills.webp']);
