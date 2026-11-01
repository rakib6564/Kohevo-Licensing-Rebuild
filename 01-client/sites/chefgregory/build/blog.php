<?php
// Chef Gregory's redesign: blog posts and recipes, their Single templates and lists.
declare(strict_types=1);

/** ALL-CAPS titles as written words; a short word stays lower, BBQ stays BBQ. */
function TT(string $t): string
{
    if ($t !== strtoupper($t)) { return $t; }
    $t = ucwords(strtolower($t), " -");
    return str_replace('Bbq', 'BBQ', $t);
}
/** A captured article as clean blocks in the site's own type. */
function ARTICLE(array $leaves): array
{
    $out = [];
    foreach ($leaves as $i => $n) {
        $text = plain((string) ($n['text'] ?? $n['html'] ?? ''));
        switch ($n['t']) {
            case 'heading':
                // A heading with nothing under it, as the live recipe's instructions are, says nothing.
                if ($text !== '' && ($leaves[$i + 1]['t'] ?? '') !== 'heading') { $out[] = VBOX([H($text, 'h2', '34px', '28px')], '0px', ['margin' => ['top' => '20px']]); }
                break;
            case 'text-editor':
                if ($text === '' || str_starts_with($text, '— ')) { break; }
                if (str_starts_with($text, '“') && mb_strlen($text) < 320) {
                    $who = plain((string) ($leaves[$i + 1]['html'] ?? ''));
                    $out[] = VBOX([P($text, INK, '26px', 'left', DISPLAY), str_starts_with($who, '— ') ? P($who, COCOA, '15px') : null], '6px', ['border' => ['left' => ['width' => '3px', 'style' => 'solid', 'color' => EMBER]], 'padding' => ['left' => '22px'], 'margin' => ['top' => '10px', 'bottom' => '10px']]);
                    break;
                }
                if (($leaves[$i - 1]['t'] ?? '') === 'image') { $out[] = P($text, COCOA, '15px', 'left', ROMAN, 'italic'); break; }
                $out[] = PROSE(clean_html((string) $n['html']));
                break;
            case 'image':
                if (MID((string) ($n['file'] ?? '')) > 0 && !str_contains((string) $n['file'], 'logo')) { $out[] = PIC((string) $n['file'], 'A plated dinner', '380px', '220px'); }
                break;
            case 'icon-list':
                $items = array_values(array_filter(array_map(static fn(array $x): string => trim($x['text']), is_array($n['items'] ?? null) ? $n['items'] : [])));
                if ($items) { $out[] = B('core.list', ['style' => 'check', 'items' => array_map(static fn(string $x): array => ['text' => mb_substr($x, 0, 300)], $items)], [], ['color' => COCOA, 'typography' => ['size' => '17px', 'font_family' => BODY, 'weight' => '400', 'line_height' => '1.55']], ['style_parts' => ['marker' => ['color' => EMBER]]]); }
                break;
        }
    }
    return [CTOP('Article', $out, ['layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '16px']])];
}
function ITEMHERO(string $backLabel, string $back, array $texts): array
{
    return SEC('Hero', [ROW([
        W(VBOX([B('core.link', ['link' => L($backLabel, $back), 'style' => 'plain'], [], ['color' => '#d9cdbd', 'typography' => ['size' => '14px', 'font_family' => BODY, 'weight' => '500']], ['classNames' => ['cg-link']]), ...$texts], '16px'), '50%'),
        W(FVB('hero_image', ['dimensions' => ['height' => '420px', 'width' => '100%', 'overflow' => 'hidden']], ['classNames' => ['cg-hero-img'], 'responsive' => ['mobile' => ['style' => ['dimensions' => ['height' => '240px']]]]]), '44%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '28px']], ['justify' => 'between', 'align' => 'center'])], INK, ['padding' => ['top' => '52px', 'right' => '24px', 'bottom' => '60px', 'left' => '24px']], WRAP(), '40px');
}
function ITEMTITLE(): array { return B('theme.post_title', ['level' => 'h1'], [], ['color' => CREAM, 'typography' => ['size' => '56px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.12']], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => '38px']]]]]); }
function CARDS(string $type, int $cols, int $per, string $more, array $class = []): array
{
    return B('core.query_loop', ['content_type' => $type, 'columns' => $cols, 'per_page' => $per, 'gap' => 'lg', 'order_by' => 'published_at', 'order' => 'desc', 'show_author' => false, 'show_category' => false, 'show_date' => false, 'read_more_text' => $more, 'enable_pagination' => false], [], [], ['classNames' => $class, 'style_parts' => [
        'card' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'border' => ['width' => '0px', 'radius' => '0px'], 'shadow' => ['x' => '0px', 'y' => '0px', 'blur' => '0px', 'spread' => '0px', 'color' => 'rgba(0,0,0,0)']],
        'title' => ['color' => INK, 'typography' => ['size' => '30px', 'line_height' => '36px', 'family' => 'heading', 'weight' => 'normal', 'transform' => 'none']],
        'excerpt' => ['color' => COCOA, 'typography' => ['size' => '16px', 'line_height' => '25px']],
        'body' => ['padding' => ['top' => '18px', 'right' => '0px', 'bottom' => '0px', 'left' => '0px']],
        'button' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'color' => EMBER, 'border' => ['width' => '0px'], 'padding' => ['left' => '0px', 'right' => '0px'], 'typography' => ['weight' => 'semibold']],
    ], 'responsive' => ['mobile' => ['props' => ['columns' => 1]]]]);
}

// ── Blog posts ─────────────────────────────────────────────────────────────────
// The live posts share one placeholder hero, so each gets a photo that fits it. The lists show this image too.
$BLOGIMG = ['corporate-event-catering-guide' => '2026-03-feedwell-lunch-catering-600x600-1.webp', 'seasonal-central-coast-menu-guide' => '2026-03-pan-seared-halibut-steaks-with-c.webp', 'private-chef-dinner-party' => '2023-08-20230810-195536-scaled.jpg', 'wine-country-dining' => '2026-03-hills.webp', 'wine-country-wedding-catering' => '2022-04-food-catering-cuisine-culinary-gourmet-party-cheers-concept.jpg'];
foreach (array_values(array_filter(array_keys($CAP), static fn($u) => preg_match('~^/blog-post/.+~', $u))) as $u) {
    $x = $MAIN($u);
    $heads = []; collect($x['d'][0], 'heading', $heads);
    $eds = []; collect($x['d'][0], 'text-editor', $eds);
    $lists = []; collect($x['d'][1]['kids'][1] ?? ['k' => 'c', 'kids' => []], 'icon-list', $lists);
    $toc = array_map(static fn($i) => trim($i['text']), $lists[0]['items'] ?? []);
    $lede = plain((string) ($eds[0]['html'] ?? ''));
    $image = $BLOGIMG[basename($u)];
    $leaves = []; LEAVES($x['d'][1]['kids'][0], $leaves);
    build_page(basename($u), TT(trim(html_entity_decode((string) ($heads[1]['text'] ?? $WP[$u]['title']['rendered'])))), 'page', 'standalone', ARTICLE($leaves), [
        'type' => 'blog_post', 'fields' => ['hero_image' => MEDIA_DIR . $image, 'date_label' => trim((string) ($heads[0]['text'] ?? '')), 'lede' => $lede, 'toc' => implode("\n", $toc)],
        'seo_title' => $CAP[$u]['d']['title'], 'seo_desc' => $meta($u), 'image' => $image, 'date' => $WP[$u]['date'], 'settings' => ['excerpt' => mb_substr($lede, 0, 200)],
    ]);
}
SINGLE('template-blog-post', 'Single: Blog Post', 'blog_post', [
    ITEMHERO('← All posts', '/blog-post/', [FVB('date_label', EYESTYLE(EMBERLT)), ITEMTITLE(), FVB('lede', ['color' => '#d9cdbd', 'typography' => ['size' => '18px', 'font_family' => BODY, 'line_height' => '1.6']])]),
    CTOP('Check band', [CHECK()], []),
    SEC('Article', [ROW([
        W(VBOX([B('theme.post_content', [])]), '62%'),
        W(VBOX([
            VBOX([EYE('In This Article'), FVB('toc', ['color' => INK, 'typography' => ['size' => '16px', 'font_family' => BODY, 'line_height' => '1.45']], ['classNames' => ['cg-toc']])], '8px', ['margin' => ['bottom' => '24px']]),
            VBOX([CHECK(), VBOX([H('Planning an Event?', 'h2', '30px', '28px', CREAM), P('Every event begins with a complimentary consultation and tasting.', '#d9cdbd', '16px'), ROW([BTN('Book Your Tasting', '/contact/')], '0px', ['margin' => ['top' => '8px']], [])], '10px', ['padding' => ['top' => '24px', 'right' => '24px', 'bottom' => '26px', 'left' => '24px']])], '0px', ['background' => ['color' => INK]]),
        ], '0px', [], ['classNames' => ['cg-sticky']]), '32%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '40px']], ['justify' => 'between', 'align' => 'start'])], CREAM, GUT('88px', '96px'), WRAP(), '48px'),
    SEC('More posts', [VBOX([EYE('From the Kitchen', EMBER, 'center'), H('More from Chef Gregory', 'h2', '46px', '34px', INK, 'center')], '12px', ['margin' => ['bottom' => '40px']]), CARDS('blog_post', 3, 3, 'Read more →')], SAND, GUT('88px', '88px'), WRAP(), '56px'),
    BOOK(),
]);

$list = static fn(string $slug, string $title, string $eye, string $h1, string $text, string $u, string $eye2, string $h2): int => build_page($slug, $title, 'page', 'standalone', [
    INHERO($eye, $h1, $text),
    CTOP('Check band', [CHECK()], []),
    SEC('Posts', [VBOX([EYE($eye2, EMBER, 'center'), H($h2, 'h2', '50px', '36px', INK, 'center')], '12px', ['margin' => ['bottom' => '44px']]), CARDS('blog_post', 2, 12, 'Read more →', ['cg-svc-grid'])], CREAM, GUT('88px', '96px'), WRAP(), '48px'),
    BOOK(),
], ['seo_title' => $CAP[$u]['d']['title'], 'seo_desc' => $meta($u) ?: $text, 'image' => '2026-03-hills.webp']);
$list('blog-post', 'Blog', 'From the Kitchen', 'Chef Gregory’s Blog', 'A professional chef’s tips and guidelines for planning, menus, and memorable events.', '/blog-post/', 'Latest Posts', 'Notes from the Kitchen');
$list('media-press', 'Media & Press', 'In the Spotlight', 'Media & Press', 'As seen in regional and national food media — Chef Gregory’s story and cuisine continue to make headlines.', '/media-press/', 'Latest', 'Chef Gregory in the News');

// ── Recipes ────────────────────────────────────────────────────────────────────
$rec = '/recipe/award-winning-bbq/';
$x = $MAIN($rec);
$bar = array_map(static fn($k) => plain((string) (array_values(array_filter($k['kids'], static fn($c) => $c['t'] === 'text-editor'))[0]['html'] ?? '')), $x['d'][1]['kids']);
$heads = []; collect($x['d'][0], 'heading', $heads);
$leaves = []; LEAVES($x['d'][2]['kids'][0], $leaves);
$recImg = '2026-04-brisket.webp';
build_page(basename($rec), TT(trim(html_entity_decode((string) $heads[1]['text']))), 'page', 'standalone', ARTICLE($leaves), [
    'type' => 'recipe', 'fields' => ['hero_image' => MEDIA_DIR . $recImg, 'eyebrow' => (string) $heads[0]['text'], 'prep_time' => $bar[0] ?? '', 'cook_time' => $bar[1] ?? '', 'serves' => $bar[2] ?? '', 'temperature' => $bar[3] ?? '', 'difficulty' => $bar[4] ?? 'Advanced', 'ingredients' => implode("\n", $itemMeta($rec))],
    'seo_title' => $CAP[$rec]['d']['title'], 'seo_desc' => $meta($rec), 'image' => $recImg, 'date' => $WP[$rec]['date'], 'settings' => ['excerpt' => mb_substr(plain($WP[$rec]['content']['rendered']), 0, 200)],
]);
$stat = static fn(string $label, string $key): array => VBOX([EYE($label, EMBER, 'center'), FVB($key, ['color' => INK, 'align' => ['base' => 'center'], 'typography' => ['size' => '24px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.25']])], '4px');
SINGLE('template-recipe', 'Single: Recipe', 'recipe', [
    ITEMHERO('← All recipes', '/recipe/', [FVB('eyebrow', EYESTYLE(EMBERLT)), ITEMTITLE()]),
    CTOP('Check band', [CHECK()], []),
    SEC('Recipe facts', [B('layout.flex', ['direction' => 'row', 'gap' => 'none'], [$stat('Prep', 'prep_time'), $stat('Cook', 'cook_time'), $stat('Serves', 'serves'), $stat('Temp', 'temperature'), $stat('Level', 'difficulty')], ['layout' => ['display' => 'grid', 'columns' => 5, 'gap' => '20px']], ['responsive' => ['mobile' => ['style' => ['layout' => ['columns' => 2]]]]])], SAND, ['padding' => ['top' => '32px', 'right' => '24px', 'bottom' => '32px', 'left' => '24px']], WRAP(), '28px'),
    SEC('Recipe', [ROW([
        W(VBOX([B('theme.post_content', [])]), '60%'),
        W(VBOX([CHECK(), VBOX([H('Ingredients', 'h2', '32px', '28px', CREAM), FVB('ingredients', ['color' => CREAM, 'typography' => ['size' => '16px', 'font_family' => BODY, 'line_height' => '1.5']], ['classNames' => ['cg-included']]), ROW([BTN('Book Chef Gregory', '/contact/')], '0px', ['margin' => ['top' => '12px']], [])], '10px', ['padding' => ['top' => '26px', 'right' => '26px', 'bottom' => '28px', 'left' => '26px']])], '0px', ['background' => ['color' => INK]], ['classNames' => ['cg-sticky']]), '35%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '40px']], ['justify' => 'between', 'align' => 'start'])], CREAM, GUT('88px', '96px'), WRAP(), '48px'),
    BOOK(),
]);
build_page('recipe', 'Recipes', 'page', 'standalone', [
    INHERO('From the Kitchen', 'Chef Gregory’s Recipes', 'Gourmet techniques made accessible — bringing CIA-trained excellence to your home kitchen.'),
    CTOP('Check band', [CHECK()], []),
    SEC('Recipes', [VBOX([EYE('Signature Collection', EMBER, 'center'), H('Featured Recipes', 'h2', '50px', '36px', INK, 'center'), P('Each recipe reflects Chef Gregory’s commitment to fresh, seasonal ingredients and classical technique. Difficulty levels guide home cooks from approachable weeknight dinners to impressive entertaining showstoppers.', COCOA, '18px', 'center')], '12px', ['margin' => ['bottom' => '44px'], 'dimensions' => ['max_width' => '760px']]), CARDS('recipe', 3, 12, 'View recipe →', ['cg-svc-grid'])], CREAM, GUT('88px', '96px'), WRAP('column', '0px', ['align' => 'center']), '48px'),
    BOOK(),
], ['seo_title' => $CAP['/recipe/']['d']['title'], 'seo_desc' => 'Gourmet techniques made accessible — bringing CIA-trained excellence to your home kitchen.', 'image' => $recImg]);
