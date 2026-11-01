<?php
// Chef Gregory's redesign: the galleries and the food pages.
declare(strict_types=1);

function LEAVES(array $n, array &$out): void { if ($n['k'] === 'w') { $out[] = $n; return; } foreach ($n['kids'] ?? [] as $k) { LEAVES($k, $out); } }
function LEAFLIST(array $tops): array { $out = []; foreach ($tops as $t) { LEAVES($t, $out); } return $out; }
function ALT(string $file): string
{
    $name = trim((string) preg_replace('/\s+/', ' ', str_replace('-', ' ', (string) preg_replace(['/\.\w+$/', '/^\d{4}-\d{2}-/', '/-\d+x\d+(-\d)?$/'], '', $file))));
    return preg_match('/\d{5,}|^img|^gg|^gm|^cam|^picture|^i phone/i', $name) || $name === '' ? 'Catering by Chef Gregory’s' : ucfirst($name);
}
function INHERO(string $eye, string $title, string $text, array $extra = []): array
{
    return SEC('Hero', [VBOX([EYE($eye, EMBERLT), H($title, 'h1', '64px', '42px', CREAM), P($text, '#d9cdbd', '19px'), ...$extra], '16px', ['dimensions' => ['max_width' => '820px']])], INK, ['padding' => ['top' => '60px', 'right' => '24px', 'bottom' => '60px', 'left' => '24px']], WRAP(), '40px');
}

// ── Galleries ──────────────────────────────────────────────────────────────────
$tabs = [['Gallery', '/gallery/'], ['Plated and Styled', '/plated-and-styled/'], ['Items for Catered Events', '/items-for-catered-events/'], ['Canapés', '/canapes/']];
// Rough groups from the file names, for the filters on the longest gallery.
function CATEGORY(string $f): string
{
    return match (true) {
        (bool) preg_match('/ice|luge|olymp/', $f) && !preg_match('/slice/', $f) => 'Ice Displays',
        (bool) preg_match('/chocol|dessert|torte|bavarin|mouse|parffiet|candie|berry|saffronpear|fruittort/', $f) => 'Desserts',
        (bool) preg_match('/shrimp|lobster|ahi|poke|halibut|coctail/', $f) => 'Seafood',
        (bool) preg_match('/canape|springroll|quesadilla|kabob|bites|escar|dumpling|cashew|coconut/', $f) => 'Appetizers',
        (bool) preg_match('/buffet|display|platter|tray|holiday|bar-|table/', $f) => 'Buffets & Displays',
        default => 'Plated & More',
    };
}
$gallery = static function (string $u, string $slug, string $title, string $eyebrow, array $props) use ($MAIN, $meta, $CAP, $tabs): void {
    $files = [];
    foreach (LEAFLIST($MAIN($u)['d']) as $n) { foreach ($n['files'] ?? [] as $f) { $files[] = $f; } }
    // Under 150px a photo cannot fill a column without falling apart.
    $sizes = [];
    foreach (array_unique($files) as $f) { $s = @getimagesize(getcwd() . MEDIA_DIR . $f); if (MID($f) > 0 && $s && $s[0] >= 150) { $sizes[$f] = $s; } }
    $images = [];
    foreach ($sizes as $f => $s) { $images[] = ['url' => MEDIA_DIR . $f, 'alt' => ALT($f), 'category' => CATEGORY($f), 'width' => $s[0], 'height' => $s[1]]; }
    $nav = array_map(static fn(array $t): array => B('core.link', ['link' => L($t[0], $t[1]), 'style' => 'plain'], [], ['color' => $t[1] === $u ? EMBERLT : CREAM, 'typography' => ['size' => '15px', 'font_family' => BODY, 'weight' => '600']], ['classNames' => ['cg-link']]), $tabs);
    build_page($slug, $title, 'page', 'standalone', [
        INHERO($eyebrow, $title, 'Every event tells a story — through the food, the presentation, and the moments shared around the table.', [ROW($nav, '28px', ['margin' => ['top' => '8px']], ['layout' => ['direction' => 'row', 'wrap' => 'wrap', 'gap' => '20px']], ['wrap' => 'wrap'])]),
        CTOP('Check band', [CHECK()], []),
        SEC('Photos', [B('core.gallery', ['images' => $images, 'columns' => '4', 'gap' => 'sm', 'rounded' => false, 'hover' => 'zoom', 'load_more_text' => 'Load more photos'] + $props, [], [], ['style_parts' => [
            'more' => ['background' => ['color' => EMBER], 'color' => '#ffffff', 'border' => ['width' => '1px', 'style' => 'solid', 'color' => EMBER, 'radius' => '0px'], 'padding' => ['top' => '16px', 'right' => '30px', 'bottom' => '16px', 'left' => '30px'], 'typography' => ['size' => '14px', 'weight' => 'semibold', 'letter_spacing' => '1.4px', 'transform' => 'uppercase']],
            'filter' => ['border' => ['width' => '1px', 'style' => 'solid', 'color' => '#c9b99d', 'radius' => '0px'], 'typography' => ['size' => '14px', 'weight' => 'medium']],
            'filter_active' => ['background' => ['color' => INK], 'color' => '#ffffff', 'border' => ['width' => '1px', 'style' => 'solid', 'color' => INK, 'radius' => '0px']],
        ]])], CREAM, ['padding' => ['top' => '56px', 'right' => '24px', 'bottom' => '88px', 'left' => '24px']], WRAP(), '28px'),
        BOOK(),
    ], ['seo_title' => $CAP[$u]['d']['title'], 'seo_desc' => $meta($u), 'image' => array_key_first($sizes) ?? '']);
    say('    ' . count($images) . ' photos');
};
$gallery('/gallery/', 'gallery', 'Gallery', 'Visual Stories', ['layout' => 'justified', 'row_height' => 260, 'pagination' => 'load_more', 'per_page' => 18]);
$gallery('/plated-and-styled/', 'plated-and-styled', 'Plated and Styled', 'Fine Dining', ['layout' => 'masonry', 'pagination' => 'load_more', 'per_page' => 12]);
$gallery('/items-for-catered-events/', 'items-for-catered-events', 'Items for Catered Events', 'Events & Cuisine', ['layout' => 'grid', 'aspect_ratio' => '1:1', 'pagination' => 'infinite', 'per_page' => 16, 'show_filters' => true, 'all_label' => 'All Items']);

// ── Canapés ────────────────────────────────────────────────────────────────────
$lis = [];
foreach (LEAFLIST($MAIN('/canapes/')['d']) as $n) { if ($n['t'] === 'text-editor' && preg_match_all('~<li[^>]*>(.*?)</li>~si', (string) $n['html'], $m)) { $lis = array_map('plain', $m[1]); } }
$names = ['Goose Liver Foie Gras', 'Smoked Salmon on Toast Rounds', 'Maui Onion Tartlets', 'Melon Balls with Parma Prosciutto', 'Medallions of Shrimp', 'Smoked Mozzarella with Marinated Tomatoes', 'Norwegian Smoked Salmon', 'New Baby Red Potatoes', 'King Crab Stuffed Mushrooms'];
if (count($lis) !== count($names)) { throw new RuntimeException('Canapé list changed: ' . count($lis)); }
$rows = [];
foreach ($lis as $i => $li) {
    $price = '';
    if (preg_match('/[\s\x{00a0}]*\$([\d.]+)[\s\x{00a0}]*(ea|qty\.[\s\x{00a0}]*four dozen)\.?\s*$/u', $li, $m)) { $price = '$' . $m[1] . ($m[2] === 'ea' ? ' each' : ' for four dozen'); $li = trim(substr($li, 0, -strlen($m[0]))); }
    $rows[] = VBOX([
        ROW([B('core.text', ['content' => $names[$i], 'size' => 'inherit'], [], ['color' => INK, 'typography' => ['size' => '25px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.25']]), $price !== '' ? B('core.text', ['content' => $price, 'size' => 'inherit'], [], ['color' => EMBER, 'typography' => ['size' => '15px', 'font_family' => BODY, 'weight' => '600', 'line_height' => '1.8']], ['classNames' => ['cg-price']]) : null], '16px', [], ['layout' => ['direction' => 'column', 'gap' => '2px']], ['justify' => 'between']),
        P($li, COCOA, '16px', 'left', ROMAN, 'italic'),
    ], '4px', ['border' => ['bottom' => ['width' => '1px', 'style' => 'solid', 'color' => '#d9ccb5']], 'padding' => ['bottom' => '16px']]);
}
build_page('canapes', 'Canapés & Hors d’oeuvres', 'page', 'standalone', [
    INHERO('Passed Bites', 'Canapés & Hors d’oeuvres', 'Chef-crafted passed bites for weddings, cocktail parties, and corporate events.'),
    CTOP('Check band', [CHECK()], []),
    SEC('Menu', [ROW([
        W(VBOX([VBOX([EYE('The Menu', EMBER, 'center'), H('Canapés', 'h2', '50px', '38px', INK, 'center'), ...$rows], '18px', ['border' => ['width' => '1px', 'style' => 'solid', 'color' => '#c9b99d'], 'padding' => ['top' => '40px', 'right' => '40px', 'bottom' => '36px', 'left' => '40px']], ['responsive' => ['mobile' => ['style' => ['padding' => ['top' => '28px', 'right' => '18px', 'bottom' => '24px', 'left' => '18px']]]]])], '0px', ['background' => ['color' => CREAM], 'padding' => ['top' => '14px', 'right' => '14px', 'bottom' => '14px', 'left' => '14px']]), '64%'),
        W(VBOX([PIC('2022-04-canapes-821x1024-1.jpg', 'A tray of canapés', '100%', '280px')], '0px', ['dimensions' => ['min_height' => '100%']], ['classNames' => ['cg-fill']]), '36%'),
    ], '0px', [], ['layout' => ['direction' => 'column-reverse']])], INK, GUT('72px', '96px'), WRAP(), '48px'),
    BOOK(),
], ['seo_title' => $CAP['/canapes/']['d']['title'], 'seo_desc' => $meta('/canapes/'), 'image' => '2022-04-canapes-821x1024-1.jpg']);

// ── Corporate breakfast ────────────────────────────────────────────────────────
$offer = static fn(string $t, string $text, string $note = ''): array => VBOX([H($t, 'h2', '36px', '30px'), P($text), $note !== '' ? B('core.text', ['content' => $note, 'size' => 'inherit'], [], ['color' => EMBER, 'typography' => ['size' => '16px', 'font_family' => BODY, 'weight' => '600']]) : null], '10px', ['border' => ['bottom' => ['width' => '1px', 'style' => 'solid', 'color' => '#d9ccb5']], 'padding' => ['top' => '22px', 'bottom' => '24px']]);
build_page('corporate-breakfast-continental', 'Corporate Breakfast Continental', 'page', 'standalone', [
    INHERO('Corporate Catering', 'Corporate Breakfast Continental', 'Fresh pastries, fruit, and great coffee for your office or meeting.'),
    CTOP('Check band', [CHECK()], []),
    SEC('Breakfast', [ROW([
        W(PIC('2022-04-gg2.jpg', 'A catered breakfast spread', '520px', '260px'), '46%'),
        W(VBOX([
            $offer('Continental Breakfast', 'Fresh bagels, muffins, fruit salad, Philadelphia cream cheese, a selection of juices (apple, orange, V-8), bottled water, pastries, and coffee.', 'Starting at $7.95 per person plus rentals and service'),
            $offer('Coffee Station', 'Offering the finest of roasted coffees, Peet’s Coffee. All setups include half & half, non-fat milk, hot water for tea, sugar, Equal, and the rest of the necessities to have a great morning coffee.'),
            ROW([BTN('Book Now', '/contact/'), BTN('Corporate Catering', '/service/corporate-catering/', 'dark')], '12px', ['margin' => ['top' => '24px']], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']], ['wrap' => 'wrap']),
        ]), '48%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '28px']], ['justify' => 'between', 'align' => 'center'])], CREAM, GUT('88px', '96px'), WRAP(), '48px'),
    BOOK(),
], ['seo_title' => $CAP['/corporate-breakfast-continental/']['d']['title'], 'seo_desc' => $meta('/corporate-breakfast-continental/'), 'image' => '2022-04-gg2.jpg']);

// ── Products ───────────────────────────────────────────────────────────────────
$shop = 'https://gourmetmenow.com/products/';
build_page('ginger-soy-dressing', 'Ginger Soy Dressing', 'page', 'standalone', [
    SEC('Hero', [ROW([
        W(VBOX([EYE('Gourmet Me Now Products', EMBERLT), H('Ginger Soy Dressing', 'h1', '64px', '42px', CREAM), P('Use Chef Gregory’s Ginger Soy Dressing on your favorite salad mix and be amazed at how great the flavor of the Pacific comes to life.', '#d9cdbd'), ROW([BTN('Shop Products', $shop), BTN('Gourmet Me Now', '/gourmet-me-now/', 'light')], '12px', ['margin' => ['top' => '10px']], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']], ['wrap' => 'wrap'])], '18px'), '50%'),
        W(PIC('2022-04-gm10-scaled-1.jpg', 'A bottle of Chef Gregory’s Ginger Soy Dressing beside a salad', '460px', '260px'), '44%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '32px']], ['justify' => 'between', 'align' => 'center'])], INK, ['padding' => ['top' => '56px', 'right' => '24px', 'bottom' => '64px', 'left' => '24px']], WRAP(), '40px'),
    CTOP('Check band', [CHECK()], []),
    SEC('Details', [ROW([
        W(VBOX([EYE('How to use it'), H('Salad, Marinade, or Dip', 'h2', '46px', '34px'), P('Best on Spring Mix lettuce with toasted pine nuts, dried cranberries, and a sprinkle of goat cheese, if you like.'), P('Fantastic as a marinade for fish, poultry, tofu, beef, and pork, or as a dipping sauce from spring rolls to vegetables and much more. Marinate your favorite fish in a bag for two hours, or meat up to seven hours. Rinse before cooking if desired.'), P('Here at Chef Gregory’s® we take the time at the market place to select the finest and freshest quality ingredients to produce our product line. We care about what we do and you can taste it!')], '14px'), '56%'),
        W(VBOX([CHECK(), VBOX([H('Ingredients', 'h2', '32px', '28px', CREAM), P('Low sodium soy sauce, ginger, rice vinegar, herbs, onions & sesame oil.', '#d9cdbd', '17px'), P('“Guaranteed to taste great, or your money back”', CREAM, '19px', 'left', ROMAN, 'italic')], '12px', ['padding' => ['top' => '26px', 'right' => '26px', 'bottom' => '28px', 'left' => '26px']])], '0px', ['background' => ['color' => INK]]), '36%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '36px']], ['justify' => 'between', 'align' => 'center'])], CREAM, GUT('88px', '96px'), WRAP(), '48px'),
    BOOK(),
], ['seo_title' => $CAP['/ginger-soy-dressing/']['d']['title'], 'seo_desc' => $meta('/ginger-soy-dressing/'), 'image' => '2022-04-gm10-scaled-1.jpg']);

// Recipes: each heading with the text after it. The live page repeats the burger heading over the guacamole.
$gm = LEAFLIST($MAIN('/gourmet-me-now/')['d']);
$recipes = [];
for ($i = 6, $n = count($gm); $i < $n; $i++) {
    if ($gm[$i]['t'] !== 'heading') { continue; }
    $title = plain((string) $gm[$i]["text"]);
    $lead = '';
    if (($gm[$i + 1]['t'] ?? '') === 'heading') { $lead = plain((string) $gm[$i + 1]["text"]); $i++; }
    if (($gm[$i + 1]['t'] ?? '') !== 'text-editor') { continue; }
    $html = (string) $gm[++$i]['html'];
    if (str_contains($html, '<strong>Guacamole</strong>')) { $title = 'Guacamole'; $html = (string) preg_replace('~<p><strong>Guacamole</strong>.*?</p>~si', '', $html, 1); }
    $html = (string) preg_replace('~<strong>Chef Gregory’s(?:&nbsp;|\s)*</strong>.*?</b>~si', '', $html);
    $html = str_replace(['&nbsp;', ' '], ' ', clean_html($html));
    // The source nests whole recipes inside one bullet: ingredients become lines, steps stay a list.
    $html = (string) preg_replace(['~<ul>\s*<li>\s*<p>~', '~</p>\s*<ul>~', '~</ul>\s*</li>\s*</ul>~', '~</p>\s*</li>\s*</ul>~', '~</ul>\s*<ul>~', '~<li>\s*</li>~', '~<p>\s*</p>~'], ['<p>', '</p><ul>', '</ul>', '</p>', '', '', ''], $html);
    $recipes[] = VBOX([H($title, 'h3', '28px', '26px'), $lead !== '' ? P($lead, COCOA, '16px', 'left', ROMAN, 'italic') : null, B('core.rich_text', ['content' => $html], [], ['color' => COCOA, 'typography' => ['size' => '16px', 'font_family' => BODY, 'weight' => '400', 'line_height' => '1.55']], ['classNames' => ['cg-recipe']])], '8px', ['border' => ['top' => ['width' => '1px', 'style' => 'solid', 'color' => INK]], 'padding' => ['top' => '18px']]);
}
say('    ' . count($recipes) . ' recipes');
build_page('gourmet-me-now', 'Gourmet Me Now', 'page', 'standalone', [
    SEC('Hero', [ROW([
        W(VBOX([EYE('Southwestern Flair Seasoning', EMBERLT), H('Gourmet Me Now', 'h1', '64px', '42px', CREAM), P('A seasoning with LOTS of flavor and very LITTLE heat, pending on the version. Southwestern Flair comes in many varieties from “Mild with a little Kick” to “Oh my God Someone Dial 911!!”', '#d9cdbd'), ROW([BTN('Shop Products', $shop), BTN('Ginger Soy Dressing', '/ginger-soy-dressing/', 'light')], '12px', ['margin' => ['top' => '10px']], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']], ['wrap' => 'wrap'])], '18px'), '50%'),
        W(PIC('2022-04-gm-5.jpg', 'Jars of Chef Gregory’s Southwestern Flair seasoning', '460px', '260px'), '44%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '32px']], ['justify' => 'between', 'align' => 'center'])], INK, ['padding' => ['top' => '56px', 'right' => '24px', 'bottom' => '64px', 'left' => '24px']], WRAP(), '40px'),
    CTOP('Check band', [CHECK()], []),
    SEC('Seasoning', [ROW([
        W(VBOX([EYE('Great on'), H('Try It on Most Anything', 'h2', '46px', '34px'), P('Use this spice product to mix up your grilled items on the BBQ, jazz up your favorite chili recipes, and enhance most of your Mexican food from guacamole to enchiladas. Try this spice on most anything and it might become a tradition in your house.')], '14px'), '50%'),
        W(VBOX([P('Steaks, Chicken, Fish, Shrimp, Ribs, Roasts, Tacos, Avocados, Cheeses, Sandwiches, Meatloaf, All Southwest Cuisine, Corn on the cob, Most all Vegetables on the grill, Eggs, Salads, Baked Potatoes… & the list goes on.', INK, '20px', 'left', ROMAN, 'italic'), P('Ingredients: Chili powder (NOT SPICY), coriander, garlic salt, black pepper, sugar and many other spices.', COCOA, '16px')], '14px'), '42%'),
    ], '48px', [], ['layout' => ['direction' => 'column', 'gap' => '24px']], ['justify' => 'between', 'align' => 'center'])], SAND, GUT('88px', '88px'), WRAP(), '48px'),
    SEC('Recipes', [
        VBOX([EYE('From the Kitchen', EMBER, 'center'), H('Southwestern Flair Recipes', 'h2', '52px', '38px', INK, 'center')], '14px', ['margin' => ['bottom' => '44px']]),
        B('layout.flex', ['direction' => 'row', 'gap' => 'none'], $recipes, ['layout' => ['display' => 'grid', 'columns' => 3, 'gap' => '36px']], ['responsive' => ['tablet' => ['style' => ['layout' => ['columns' => 2]]], 'mobile' => ['style' => ['layout' => ['columns' => 1, 'gap' => '28px']]]]]),
    ], CREAM, GUT(), WRAP(), '56px'),
    BOOK(),
], ['seo_title' => $CAP['/gourmet-me-now/']['d']['title'], 'seo_desc' => $meta('/gourmet-me-now/'), 'image' => '2022-04-gm-5.jpg']);
