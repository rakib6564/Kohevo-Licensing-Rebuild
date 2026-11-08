<?php
// The home page: hero, manifesto, project marquee, industries, selected work, three rules, about, reviews,
// worldwide, free audit. Each section sets the page's colours while it is on screen, as the original's themes did.
declare(strict_types=1);

if (!function_exists('say')) { require __DIR__ . '/lib.php'; }

$IMG = [
    'cdg' => 'e37702657907eb0bc2e36a82f2212ce8.jpg', 'redkey' => 'b131e2de14c018247fe2c08587aa79d2.webp', 'villa' => '3e90a1e3057c56feda9aa00c4dbcdd5f.webp',
    'b5ab' => 'b5ab7d08d5870755f32a3d0a43e7f82a.jpg', 'law' => '611fb132b1dbd5cec1b65c55e0a4ff7d.jpg', 'png' => '286bece3cd0f08b4ad4ec3f69420675b.png',
    'safi' => '8bad9500d3d698ebbeff8b1348d8df76.webp', '3694' => '3694c109ea579750e33aef9a769d2f94.webp', 'tatiana' => '3d7dd89fe2d5f85018e84b655abb7040.jpg',
    '2535' => '25352af8e18f0bea31d93fe93145daa7.jpg', 'd3f8' => 'd3f8c1d570d1e654e963ed3ab3bad18f.webp', 'portrait' => '6fbbcd188e6864012d87c634f944c25d.jpg',
];

// ── 1. Hero ────────────────────────────────────────────────────────────────────
$stat = static fn(string $n, string $label): array => VBOX([
    H($n, 'div', '52px', '40px', BONE),
    EYE($label, MUTED, 'right'),
], '6px', ['dimensions' => ['width' => 'auto']]);
$hero = CTOP('Hero', [
    // The studio portrait: its wide framing on desktop and tablet, the tall one on a phone (the face above the words).
    BGPIC('rakib-portrait-studio.jpg', 'right', true, ['md', 'lg']),
    BGPIC('rakib-portrait-tall.jpg', 'top', false, ['base', 'sm']),
    ROW([EYE('Freelance designer + developer', BONE), EYE('Available from December 2026', BONE, 'right')], '16px', [], ['justify' => 'between'], ['layout' => ['direction' => 'column', 'gap' => '6px']]),
    B('core.dual_heading', ['before' => 'Websites built for', 'colored' => 'contractors', 'rotate_words' => 'truckers, cleaners, villas, lawyers, artists', 'rotate_ms' => 2200, 'after' => '', 'level' => 'h1'], [],
        ['typography' => ['size' => '138px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '0.95', 'letter_spacing' => '-7.6px'], 'dimensions' => ['max_width' => '900px'], 'margin' => ['top' => '24px']],
        ['style_parts' => ['highlight' => ['color' => PERI]], 'classNames' => ['rk-hero-title'], 'responsive' => ['tablet' => ['style' => ['typography' => ['size' => '96px']]], 'mobile' => ['style' => ['typography' => ['size' => '56px']]]],
         'interactions' => ['text' => ['effect' => 'lines', 'step_ms' => 90]]]),
    ROW([
        P('250+ websites for real businesses in 14 countries, each designed the way its industry actually wins work.', BONE, '18px', [], ['style' => ['dimensions' => ['max_width' => '470px']]]),
        ROW([$stat('5.0', '101 reviews'), $stat('250+', 'Sites shipped')], '44px', ['dimensions' => ['width' => 'auto']], ['align' => 'end'], []),
    ], '32px', ['margin' => ['top' => '56px']], ['justify' => 'between', 'align' => 'end'], ['layout' => ['direction' => 'column', 'gap' => '28px']]),
], ['background' => ['color' => INK], 'dimensions' => ['min_height' => '780px'],
    'padding' => ['top' => '200px', 'right' => '28px', 'bottom' => '64px', 'left' => '28px'],
    'layout' => ['display' => 'flex', 'direction' => 'column', 'justify' => 'end', 'gap' => '0px', 'content_width' => 'boxed', 'content_max' => '1440px']],
    // The hero paints its own dark background and pins its own light colours (rk-own-dark): it is still a page-colour
    // section, so the header over it knows it is dark, but the page's colours never reach its words.
    ['interactions' => THEME('ink') + ['glow' => ['color' => 'rgba(255,90,31,0.18)', 'size' => 620]], 'classNames' => ['rk-photo-hero', 'rk-own-dark'],
     'responsive' => ['mobile' => ['style' => ['dimensions' => ['min_height' => '760px'], 'padding' => ['top' => '320px', 'right' => '20px', 'bottom' => '40px', 'left' => '20px']]]]]);

// ── 2. Manifesto ───────────────────────────────────────────────────────────────
$manifesto = SEC('The idea', [
    EYE('01 — The idea', MUTED_BONE),
    B('core.rich_text', ['content' => '<p>Most web designers build one website and sell it to everyone. I build the website your industry actually needs: the contractor\'s <em>quote form,</em> the trucker\'s <em>instant price,</em> the cleaner\'s <em>service map,</em> the villa\'s <em>view.</em> More than 250 times so far.</p>'], [],
        ['color' => INK, 'typography' => ['size' => '48px', 'font_family' => SANS, 'weight' => '300', 'line_height' => '1.2', 'letter_spacing' => '-1.4px'], 'margin' => ['top' => '40px']],
        ['classNames' => ['rk-manifesto'], 'responsive' => ['mobile' => ['style' => ['typography' => ['size' => '28px']]]], 'interactions' => ['text' => ['effect' => 'light_words', 'dim' => 15]]]),
], '160px', '80px', [], ['interactions' => THEME('bone')]);

// ── 3. Project marquee ─────────────────────────────────────────────────────────
$tile = static fn(string $file): array => PIC($file, '', '250px', '180px', '14px');
$mq = static fn(array $files, string $dir): array => B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'nowrap', 'align' => 'stretch'], array_map($tile, $files),
    ['layout' => ['display' => 'flex', 'direction' => 'row', 'gap' => '20px']],
    ['interactions' => ['marquee' => ['speed' => 3, 'direction' => $dir, 'scroll' => true, 'pause' => true, 'width' => 375]]]);
$gallery = CTOP('Project gallery', [
    $mq([$IMG['cdg'], $IMG['redkey'], $IMG['villa'], $IMG['b5ab'], $IMG['law'], $IMG['png']], 'left'),
    $mq([$IMG['safi'], $IMG['3694'], $IMG['tatiana'], $IMG['2535'], $IMG['d3f8'], $IMG['cdg']], 'right'),
], ['padding' => ['top' => '20px', 'right' => '0px', 'bottom' => '140px', 'left' => '0px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '20px', 'content_width' => 'full']],
    ['interactions' => THEME('bone'), 'responsive' => ['mobile' => ['style' => ['padding' => ['top' => '0px', 'right' => '0px', 'bottom' => '80px', 'left' => '0px']]]]]);

// ── 4. Industries ──────────────────────────────────────────────────────────────
$rows = [
    ['01', 'Construction', 8, $IMG['cdg'], '/construction/'], ['02', 'Logistics', 4, $IMG['redkey'], '/free-audit/'], ['03', 'Cleaning', 3, $IMG['safi'], '/free-audit/'],
    ['04', 'Hospitality', 4, $IMG['villa'], '/free-audit/'], ['05', 'Law & coaching', 3, $IMG['law'], '/free-audit/'], ['06', 'Creative', 3, $IMG['tatiana'], '/free-audit/'],
];
$industryRows = array_map(static fn(array $r): array => ROW([
    EYE($r[0]),
    B('core.heading', ['text' => $r[1], 'level' => 'h3', 'url' => $r[4]], [], ['color' => BONE, 'typography' => ['size' => '84px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '1', 'letter_spacing' => '-5px'], 'dimensions' => ['width' => '100%']],
        ['classNames' => ['rk-ind-name'], 'responsive' => ['tablet' => ['style' => ['typography' => ['size' => '56px']]], 'mobile' => ['style' => ['typography' => ['size' => '30px']]]]]),
    PIC($r[3], $r[1] . ' website', '240px', '', '12px'),
    EYE($r[2] . ' sites', MUTED, 'right'),
    B('core.link', ['link' => L('↗', $r[4]), 'style' => 'plain'], [], ['color' => BONE, 'typography' => ['size' => '18px']], ['classNames' => ['rk-arrow']]),
], '28px', ['padding' => ['top' => '18px', 'bottom' => '18px'], 'border' => ['width' => '0 0 1px 0', 'style' => 'solid', 'color' => LINE]], ['align' => 'center'], ['layout' => ['direction' => 'row', 'gap' => '14px']], ['classNames' => ['rk-ind-row']]), $rows);
$industries = SEC('Industries', [
    ROW([EYE('02 — Pick your world'), P('Six industries · 250+ sites', MUTED, '18px', [], [])], '16px', ['padding' => ['bottom' => '32px'], 'border' => ['width' => '0 0 1px 0', 'style' => 'solid', 'color' => LINE]], ['justify' => 'between', 'align' => 'center'], []),
    // A heading for the list (read by screen readers, not shown), so the industries' h3 names follow an h2.
    B('core.heading', ['text' => 'Industries', 'level' => 'h2'], [], [], ['classNames' => ['rk-sr']]),
    VBOX($industryRows, '0px', [], ['interactions' => ['hover_image' => ['size' => 320]]]),
], '140px', '140px', [], ['interactions' => THEME('ink'), 'classNames' => ['rk-anchor-industries']]);

// ── 5. Selected work ───────────────────────────────────────────────────────────
$work = [
    ['01', 'CDG Maui', 'Construction', $IMG['cdg'], 'Design-build firm on Maui · USA · 2024', ORANGE],
    ['02', 'Redkey Logistics', 'Logistics', $IMG['redkey'], 'Freight with 50,000 vetted carriers · USA · 2023', PERI],
    ['03', 'Safi Cleaners', 'Cleaning', $IMG['safi'], 'House cleaning across Hampden County · USA · 2023', '#19C7B0'],
    ['04', 'Villa Lapierrot', 'Hospitality', $IMG['villa'], 'Five-bedroom villa in Provence · 2023', '#FF6FA5'],
    ['05', 'Sergio Ardila Law', 'Law', $IMG['law'], 'Bilingual injury law firm in Atlanta · 2023', '#E8B931'],
    ['06', 'Tatiana Megard', 'Creative', $IMG['tatiana'], 'Avant-garde fashion portfolio · Belgium · 2023', '#B49BFF'],
];
// The six featured projects, from the Project items: a card of their own (navy, the industry tag in its colour, the
// short line), chosen by the Query Loop by name, so the Work page keeps its own card.
$homeCard = CTOP('Card', [
    B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [
        B('theme.post_image', [], [], ['dimensions' => ['height' => '300px', 'width' => '100%'], 'border' => ['radius' => '14px']], ['classNames' => ['rk-pcard-img'], 'responsive' => ['mobile' => ['style' => ['dimensions' => ['height' => '220px']]]]]),
        FV('industry', INK, '11px', 'span', ['typography' => ['size' => '11px', 'font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1.1px', 'line_height' => '1.3'], 'position' => ['mode' => 'absolute', 'top' => '14px', 'left' => '14px']],
            ['bg_field' => 'color'], ['classNames' => ['rk-tag']]),
    ], ['position' => ['mode' => 'relative']]),
    B('theme.post_title', ['level' => 'h3', 'link_to_post' => true], [], ['color' => BONE, 'margin' => ['top' => '18px'], 'typography' => ['size' => '36px', 'font_family' => SANS, 'weight' => '300', 'line_height' => '1.05', 'letter_spacing' => '-1.6px']], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => '28px']]]]]),
    FV('tagline', MUTED, '15px', 'p', [], [], ['when_field' => 'tagline']),
], ['layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '6px', 'content_width' => 'full', 'padding_y' => 'none']], ['classNames' => ['rk-card']]);
build_page('template-loop-project-home', 'Loop Item: Project (home)', 'system', 'standalone', [$homeCard], ['settings' => ['template_type' => 'loop']]);
$homeCardUuid = (string) \Slate\Data\Database::value("SELECT uuid FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'template-loop-project-home' AND status <> 'archived'", [$tid]);
$featuredLoop = B('core.query_loop', ['source' => 'posts', 'content_type' => 'project', 'featured_only' => true, 'loop_template' => $homeCardUuid, 'per_page' => 6, 'columns' => 3, 'gap' => 'lg',
    'order_by' => 'published_at', 'order' => 'desc', 'show_featured_image' => true, 'show_date' => false, 'show_author' => false, 'show_category' => false, 'show_excerpt' => false, 'enable_pagination' => false, 'empty_message' => ''], [], [], ['classNames' => ['rk-loop', 'rk-home-loop']]);
$workSec = SEC('Selected work', [
    ROW([EYE('03 — Selected work', MUTED), B('core.dual_heading', ['before' => 'One from every', 'colored' => 'world', 'after' => '', 'level' => 'h2'], [], ['typography' => ['size' => '72px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '0.95', 'letter_spacing' => '-3.6px']], ['style_parts' => ['highlight' => ['color' => PERI]], 'responsive' => ['mobile' => ['style' => ['typography' => ['size' => '44px']]]]])],
        '16px', ['margin' => ['bottom' => '40px']], ['justify' => 'between', 'align' => 'end'], ['layout' => ['direction' => 'column', 'gap' => '10px']]),
    B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'wrap', 'align' => 'stretch'], [$featuredLoop], ['layout' => ['display' => 'flex', 'direction' => 'row', 'wrap' => 'wrap', 'gap' => '28px']],
        ['interactions' => ['horizontal' => ['offset' => 90, 'width' => 520]], 'classNames' => ['rk-work'], 'responsive' => ['mobile' => ['style' => ['layout' => ['direction' => 'column']]]]]),
], '130px', '130px', [], ['interactions' => THEME('navy')]);

// ── 6. Three rules ─────────────────────────────────────────────────────────────
$check = static fn(string $label, string $state, bool $ok): array => ROW([P($label, BONE, '15px'), P($state, $ok ? '#22C55E' : ORANGE, '12px', ['font_family' => MONO, 'weight' => '500', 'transform' => 'uppercase'], ['classNames' => [$ok ? 'rk-ok' : 'rk-fix']])],
    '12px', ['padding' => ['top' => '11px', 'right' => '14px', 'bottom' => '11px', 'left' => '14px'], 'border' => ['width' => '0 0 1px 0', 'style' => 'solid', 'color' => '#232120']], ['justify' => 'between', 'align' => 'center'], []);
$panel = static fn(string $rule, string $when, string $bar, array $lines, string $foot, string $title, string $body, string $color): array => VBOX([
    ROW([EYE($rule, $color), EYE($when, MUTED, 'right')], '12px', [], ['justify' => 'between'], []),
    VBOX([
        P('●●●  ' . $bar, MUTED, '11px', ['font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1px'], ['style' => ['padding' => ['top' => '12px', 'right' => '14px', 'bottom' => '12px', 'left' => '14px'], 'border' => ['width' => '0 0 1px 0', 'style' => 'solid', 'color' => '#232120']]]),
        ...$lines,
        P($foot, $color, '11px', ['font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1px'], ['style' => ['margin' => ['top' => '10px', 'right' => '14px', 'bottom' => '14px', 'left' => '14px'], 'padding' => ['top' => '10px', 'right' => '12px', 'bottom' => '10px', 'left' => '12px'], 'background' => ['color' => 'rgba(255,90,31,0.12)'], 'border' => ['radius' => '8px']]]),
    ], '0px', ['background' => ['color' => '#141312'], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => '#232120', 'radius' => '12px'], 'margin' => ['top' => '18px']]),
    H($title, 'h3', '44px', '34px', BONE, [], '200'),
    P($body, 'rgba(237,232,223,.8)', '16px'),
], '14px', ['background' => ['gradient' => 'linear-gradient(135deg, #0B0B0C 45%, #3a1a0c)'], 'border' => ['radius' => '24px'], 'padding' => ['top' => '28px', 'right' => '28px', 'bottom' => '32px', 'left' => '28px']]);
$tab = static fn(string $title, string $when, array $panelBlock): array => B('layout.tab', ['title' => $title, 'subtitle' => $when], [$panelBlock]);
$rules = SEC('Three rules', [
    ROW([EYE('04 — Three rules, every project', MUTED_BONE), P('Three habits that protect your business while your website changes.', MUTED_BONE, '17px', [], ['style' => ['dimensions' => ['max_width' => '420px']]])], '16px', ['margin' => ['bottom' => '48px']], ['justify' => 'between'], ['layout' => ['direction' => 'column', 'gap' => '12px']]),
    B('layout.tabs', ['position' => 'left', 'look' => 'list', 'autoplay_ms' => 6000, 'pause_on_hover' => true, 'aria_label' => 'Three rules'], [
        $tab('Audit first', 'Before any quote', $panel('Rule 01', 'Before any quote', 'yoursite.com · audit', [$check('Google indexing', 'OK', true), $check('Page titles', 'Fix', false), $check('Mobile speed', 'Fix', false), $check('Broken links', 'Fix', false), $check('Business details', 'OK', true)], '3 fixes found · Quote based on facts →', 'Audit first', 'I look at what\'s really on your site before I quote. No guesses, and no "it was more complicated than expected".', ORANGE)),
        $tab('Staging, always', 'During the build', $panel('Rule 02', 'During the build', 'staging.yoursite.com', [$check('staging.yoursite.com', 'Building', false), $check('yoursite.com', 'Live', true), $check('Enquiries today', '+3', true), $check('Zero downtime', 'Backup taken ✓', true)], 'Your live site keeps taking enquiries', 'Staging, always', 'Every big change is built on a backed-up copy. Your live site keeps taking enquiries the whole time.', '#19C7B0')),
        $tab('Verified live', 'At launch', $panel('Rule 03', 'At launch', 'yoursite.com · live checks', [$check('Pages', '42 / 42 ✓', true), $check('Forms', '3 / 3 ✓', true), $check('Redirects', '118 / 118 ✓', true), $check('Search Console', 'Submitted ✓', true), $check('SSL', 'Valid ✓', true)], 'Verified on the real domain', 'Verified live', 'I check every page, form and redirect on your real domain. Not a preview. The real thing.', PERI)),
    ], ['color' => INK], ['classNames' => ['rk-rules']]),
], '130px', '110px', [], ['interactions' => THEME('bone')]);

// ── 7. About ───────────────────────────────────────────────────────────────────
$fact = static fn(string $k, string $v): array => VBOX([EYE($k), P($v, BONE, '17px')], '8px', ['padding' => ['top' => '18px', 'bottom' => '18px'], 'border' => ['width' => '1px 0 0 0', 'style' => 'solid', 'color' => LINE], 'dimensions' => ['width' => '50%']], ['responsive' => ['mobile' => ['style' => ['dimensions' => ['width' => '100%']]]]]);
$about = SEC('About', [
    EYE('05 — Who you\'ll work with'),
    ROW([
        W(B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [
            PIC($IMG['portrait'], 'Rakib Hasan, freelance WordPress designer and developer', '720px', '440px', '16px'),
            B('core.circle_text', ['text' => 'Available December 2026 · Taking ', 'icon' => 'arrow-up-right', 'size' => 128, 'font_size' => 11, 'spin_seconds' => 16, 'direction' => 'clockwise'], [],
                ['color' => INK, 'background' => ['color' => ORANGE], 'border' => ['radius' => '999px'], 'position' => ['mode' => 'absolute', 'right' => '-34px', 'bottom' => '-34px']], ['classNames' => ['rk-badge']]),
        ], ['position' => ['mode' => 'relative']]), '42%'),
        W(VBOX([
            B('core.heading', ['text' => 'One person, start to finish.', 'level' => 'h2'], [], ['color' => BONE, 'typography' => ['size' => '75px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '0.98', 'letter_spacing' => '-3.7px'], 'dimensions' => ['max_width' => '440px']], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => '46px']]]]]),
            P('I\'m Rakib Hasan, a WordPress designer and developer. You won\'t be handed to an account manager or a junior. I plan your pages, design them, build them, and I\'m still here after launch.', 'rgba(237,232,223,.7)', '18px', [], ['style' => ['dimensions' => ['max_width' => '540px']]]),
            B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'wrap'], [$fact('Based in', 'Bangladesh, working worldwide'), $fact('Clients in', '14 countries, 5 continents'), $fact('Built on', 'WordPress + Elementor'), $fact('Rated', '5.0 from 101 reviews')], ['layout' => ['display' => 'flex', 'direction' => 'row', 'wrap' => 'wrap', 'gap' => '0px'], 'border' => ['width' => '0 0 1px 0', 'style' => 'solid', 'color' => LINE]]),
            B('core.link', ['link' => L('Talk to me about your site', '/free-audit/'), 'style' => 'arrow'], [], ['color' => BONE, 'typography' => ['size' => '16px', 'weight' => '500']], ['interactions' => ['mouse' => ['magnet' => 4]]]),
        ], '28px', ['padding' => ['top' => '100px']], ['responsive' => ['mobile' => ['style' => ['padding' => ['top' => '40px']]]]]), '50%'),
    ], '64px', ['margin' => ['top' => '64px']], ['justify' => 'between'], ['layout' => ['direction' => 'column', 'gap' => '32px']]),
], '160px', '170px', [], ['interactions' => THEME('ink')]);

// ── 8. Reviews ─────────────────────────────────────────────────────────────────
$small = static fn(string $q, string $who): array => VBOX([P($q, INK, '18px'), EYE($who, '#4A1806')], '8px', ['dimensions' => ['width' => '25%']], ['responsive' => ['mobile' => ['style' => ['dimensions' => ['width' => '100%']]]]]);
$reviews = SEC('Reviews', [
    H('“Always great to work with. He has done several sites for me, and all have been fantastic!”', 'h2', '58px', '32px', INK, ['interactions' => ['text' => ['effect' => 'lines', 'step_ms' => 80]]], '400'),
    EYE('Thiago Fernan — Palm Flooring, New England', '#4A1806'),
    ROW([$small('“Outstanding job”', 'Jonathan Cooper'), $small('“A pleasure to work with”', 'Stéphanie Escouflaire'), $small('“This guy is really good”', 'Rafael'), $small('“I love my website”', 'Abonyi Tünde Anná')], '28px',
        ['margin' => ['top' => '56px'], 'padding' => ['top' => '28px'], 'border' => ['width' => '1px 0 0 0', 'style' => 'solid', 'color' => '#E04A12']], [], ['layout' => ['direction' => 'column', 'gap' => '18px']]),
], '160px', '140px', ['layout' => ['gap' => '28px', 'content_max' => '1180px']], ['interactions' => THEME('orange')]);

// ── 9. Worldwide (the client map is not rebuilt: the countries are listed instead) ──
$wstat = static fn(string $n, string $l): array => VBOX([H($n, 'div', '48px', '36px', BONE), EYE($l, MUTED)], '6px', ['dimensions' => ['width' => 'auto']]);
$countries = [['United States', 72], ['Belgium', 10], ['United Kingdom', 4], ['Azerbaijan', 3], ['Germany', 2], ['Nigeria', 2], ['New Zealand', 2], ['Canada', 1], ['Hungary', 1], ['Slovakia', 1], ['Denmark', 1], ['Latvia', 1], ['Vietnam', 1], ['Uganda', 'Direct']];
$world = SEC('Worldwide', [
    EYE('06 — Worldwide', MUTED),
    B('core.dual_heading', ['before' => '250+ websites, shipped from', 'colored' => 'one desk in Dhaka.', 'after' => '', 'level' => 'h2'], [], ['typography' => ['size' => '72px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '1', 'letter_spacing' => '-3.6px'], 'dimensions' => ['max_width' => '980px'], 'margin' => ['top' => '24px']],
        ['style_parts' => ['highlight' => ['color' => PERI]], 'responsive' => ['mobile' => ['style' => ['typography' => ['size' => '42px']]]]]),
    ROW([$wstat('250+', 'Websites shipped'), $wstat('14', 'Countries'), $wstat('5', 'Continents'), $wstat('101', 'Fiverr reviews')], '44px', ['margin' => ['top' => '36px', 'bottom' => '48px']], ['wrap' => 'wrap'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap', 'gap' => '28px']]),
    VBOX([
        ROW([EYE('● Client map', '#22C55E'), B('core.local_time', ['label' => 'Dhaka', 'city' => '', 'timezone' => 'Asia/Dhaka', 'format' => '24h', 'seconds' => false, 'show_offset' => false], [], ['color' => MUTED, 'typography' => ['size' => '12px', 'font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1.4px']])], '12px', [], ['justify' => 'between'], []),
        B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'wrap'], array_map(static fn(array $c): array => P($c[0] . '  ' . $c[1], BONE, '13px', ['font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1px'], ['classNames' => ['rk-country']]), $countries), ['layout' => ['display' => 'flex', 'direction' => 'row', 'wrap' => 'wrap', 'gap' => '10px']], ['interactions' => ['stagger' => ['step_ms' => 60]]]),
        ROW([EYE('14 countries · 5 continents', MUTED), B('core.link', ['link' => L('Your country next? →', '/free-audit/'), 'style' => 'plain'], [], ['color' => ORANGE, 'typography' => ['size' => '12px', 'font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1.4px']])], '12px', ['padding' => ['top' => '18px'], 'border' => ['width' => '1px 0 0 0', 'style' => 'solid', 'color' => LINE]], ['justify' => 'between'], []),
    ], '28px', ['background' => ['color' => '#08080A'], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => LINE, 'radius' => '20px'], 'padding' => ['top' => '24px', 'right' => '22px', 'bottom' => '22px', 'left' => '22px']]),
], '120px', '120px', [], ['interactions' => THEME('navy')]);

// ── 10. Free audit ─────────────────────────────────────────────────────────────
$industries6 = 'Construction & trades, Logistics & transport, Cleaning & sterilisation, Hospitality & rentals, Professional services, Creative & wellness';
$field = static fn(string $name, string $label, string $type, array $more = []): array => B('core.form_field', ['name' => $name, 'label' => $label, 'field_type' => $type, 'required' => true] + $more);
$form = B('core.form', ['form_name' => 'free_audit', 'submit_text' => 'Send me my audit →', 'submit_variant' => 'secondary', 'success_message' => 'Got it. Your top five fixes arrive within 48 hours.', 'labels' => 'visible', 'layout' => 'stacked'], [
    $field('industry', 'Your industry', 'select', ['options' => $industries6, 'default_value' => 'Construction & trades']),
    $field('website', 'Website', 'url', ['placeholder' => 'https://']),
    $field('email', 'Email', 'email'),
], [], ['classNames' => ['rk-form'], 'style_parts' => [
    'label' => ['color' => MUTED, 'typography' => ['size' => '12px', 'transform' => 'uppercase', 'letter_spacing' => '1.4px']],
    'input' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'color' => BONE, 'border' => ['sides' => 'bottom', 'width' => '1px', 'style' => 'solid', 'color' => LINE, 'radius' => '0px'], 'padding' => ['top' => '14px', 'right' => '2px', 'bottom' => '14px', 'left' => '2px'], 'typography' => ['size' => '20px']],
    'button' => ['background' => ['color' => BONE], 'color' => INK, 'border' => ['radius' => '999px', 'width' => '0px'], 'padding' => ['top' => '17px', 'right' => '30px', 'bottom' => '17px', 'left' => '30px'], 'typography' => ['size' => '16px', 'weight' => 'medium', 'family' => 'body']],
]]);
$audit = SEC('Free audit', [
    B('core.dual_heading', ['before' => 'Let\'s build', 'colored' => 'yours.', 'after' => '', 'level' => 'h2'], [], ['typography' => ['size' => '130px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '0.92', 'letter_spacing' => '-7.8px'], 'dimensions' => ['max_width' => '560px']],
        ['style_parts' => ['highlight' => ['color' => ORANGE]], 'responsive' => ['mobile' => ['style' => ['typography' => ['size' => '68px']]]], 'interactions' => ['text' => ['effect' => 'lines', 'step_ms' => 120]]]),
    ROW([
        W(VBOX([B('core.circle_text', ['text' => 'Free audit · Top 5 fixes · No obligation · ', 'icon' => 'none', 'size' => 190, 'font_size' => 12, 'spin_seconds' => 22, 'direction' => 'clockwise'], [], ['color' => BONE]),
            PILL('Start here', '/free-audit/', ORANGE, INK, ['classNames' => ['rk-start'], 'interactions' => ['mouse' => ['magnet' => 6]]])], '0px', [], ['classNames' => ['rk-start-wrap']]), '22%'),
        W(VBOX([P('Send your website and industry. You\'ll get the top five things holding it back, free.', BONE, '22px', ['line_height' => '1.45'], ['style' => ['dimensions' => ['max_width' => '520px']]]), $form], '28px', [], ['classNames' => ['rk-anchor-form']]), '76%'),
    ], '40px', ['margin' => ['top' => '64px']], ['align' => 'center'], ['layout' => ['direction' => 'column', 'gap' => '32px']]),
], '160px', '110px', [], ['interactions' => THEME('ink'), 'classNames' => ['rk-anchor-audit']]);

build_page('home', 'Home', 'page', 'homepage', [$hero, $manifesto, $gallery, $industries, $workSec, $rules, $about, $reviews, $world, $audit], [
    'seo_title' => 'Rakib Hasan: Built Different',
    'seo_desc' => 'Freelance WordPress designer and developer. 250+ websites for real businesses in 14 countries, each designed the way its industry actually wins work.',
    'image' => 'og-home.png',
]);
