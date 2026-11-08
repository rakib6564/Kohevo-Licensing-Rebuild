<?php
// rakibhasaan.com in Kohevo Studio: media, theme, menus, header, footer, then each page.
//
//   cd <site> && php <bundle>/build/site.php            everything
//   RK_ONLY=home php <bundle>/build/site.php            the theme, header, footer and just those pages
declare(strict_types=1);

use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Menu\StudioMenus;

require __DIR__ . '/lib.php';

import_media();
// A one-language site: no language switcher.
Database::query("UPDATE plugins SET status = 'inactive' WHERE slug = 'multilang-translate'");

// ── Theme ──────────────────────────────────────────────────────────────────────
$runtime->app->saveDesignTokens($actor, 'default', [
    'surface.page' => INK, 'surface.primary' => INK, 'surface.secondary' => '#141416', 'surface.muted' => '#141416', 'surface.accent' => ORANGE, 'surface.inverse' => BONE,
    'text.primary' => BONE, 'text.muted' => MUTED, 'text.accent' => ORANGE, 'text.inverse' => INK, 'color.accent' => ORANGE, 'border.default' => LINE,
    'font.heading' => SANS, 'font.body' => SANS, 'text.body' => BONE, 'text.heading' => BONE,
    'type.body_size' => '17px', 'type.flow' => '0', 'type.heading_gap' => '0', 'type.section_gutter' => '0', 'layout.content' => '1440px',
]);
$fonts = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@100..900&family=Geist+Mono:wght@400;500&display=swap">';
Database::setSetting(StudioCodePolicy::SETTING_HEAD_SNIPPET, StudioCodePolicy::prepareSnippet($fonts, 'head'), $tid);
$css = StudioCodePolicy::prepareCustomCss((string) file_get_contents(__DIR__ . '/site.css'));
Database::setSetting(StudioCodePolicy::SETTING_CUSTOM_CSS, $css['css'], $tid);
Database::setSetting('studio_smooth_scroll', '1', $tid);
// No scroll bar on the page or in the menus (Site & SEO › Scrolling › Scroll bar: Hidden); the page still scrolls.
Database::setSetting('studio_scrollbar', 'hidden', $tid);
foreach (['site_name' => 'Rakib Hasan', 'brand_accent_color' => ORANGE] as $key => $value) { Database::setSetting($key, $value, $tid); }
say('  + theme');

// ── Menus ──────────────────────────────────────────────────────────────────────
$INDUSTRIES = [['Construction & trades', '/construction/'], ['Logistics & transport', '/work/'], ['Cleaning & sterilisation', '/work/'], ['Hospitality & rentals', '/work/'], ['Professional services', '/work/'], ['Creative & wellness', '/work/']];
$SERVICES = [['WordPress & Elementor websites', '/wordpress-websites/'], ['Custom WordPress development', '/custom-development/'], ['SEO audits & fixes', '/seo/'], ['Web & mobile apps', '/apps/'], ['Shopify · Wix · Squarespace', '/platforms/'], ['Branding & video', '/branding/']];
$menu = static function (string $key, string $name, array $items) use ($tid): void {
    try { StudioMenus::save($tid, $key, $name, $items, $key); } catch (\InvalidArgumentException) { StudioMenus::save($tid, $key, $name, $items); }
};
$sub = static fn(array $list): array => array_map(static fn(array $x): array => ['label' => $x[0], 'url' => $x[1]], $list);
$menu('header', 'Header', [
    ['label' => 'Industries', 'url' => '/#industries', 'children' => $sub($INDUSTRIES)],
    ['label' => 'Work', 'url' => '/work/'], ['label' => 'Services', 'url' => '/services/', 'children' => $sub($SERVICES)],
    ['label' => 'Process', 'url' => '/process/'], ['label' => 'About', 'url' => '/about/'], ['label' => 'Reviews', 'url' => '/reviews/'],
]);
$menu('full', 'Full menu', array_map(static fn(array $r): array => ['label' => $r[0], 'url' => $r[1], 'description' => $r[2], 'image' => MEDIA_DIR . $r[3]], [
    ['Home', '/', 'Websites built for your industry', 'e37702657907eb0bc2e36a82f2212ce8.jpg'],
    ['Work', '/work/', '250+ sites · 14 countries', '3e90a1e3057c56feda9aa00c4dbcdd5f.webp'],
    ['Services', '/services/', 'WordPress, custom code, SEO', 'b131e2de14c018247fe2c08587aa79d2.webp'],
    ['Process', '/process/', 'Audit, staging, verified live', '3694c109ea579750e33aef9a769d2f94.webp'],
    ['About', '/about/', 'Rakib Hasan · designer + developer', 'fbba8323ce2532684276bba580d15600.jpg'],
    ['Reviews', '/reviews/', '5.0 from 101 Fiverr reviews', 'b5ab7d08d5870755f32a3d0a43e7f82a.jpg'],
    ['FAQ', '/faq/', 'Cost, timelines, ownership', '286bece3cd0f08b4ad4ec3f69420675b.png'],
    ['Contact', '/contact/', 'Replies within about an hour', '611fb132b1dbd5cec1b65c55e0a4ff7d.jpg'],
    ['Free audit', '/free-audit/', 'Top five fixes, free', '8bad9500d3d698ebbeff8b1348d8df76.webp'],
]));
say('  + menus');

// ── Header ─────────────────────────────────────────────────────────────────────
$brandRow = static fn(): array => ROW([
    B('core.icon', ['name' => 'circle', 'size' => 'sm'], [], ['color' => ORANGE, 'dimensions' => ['width' => '12px']], ['classNames' => ['rk-dot']]),
    B('core.link', ['link' => L('Rakib Hasan', '/'), 'style' => 'plain'], [], ['color' => BONE, 'typography' => ['size' => '16px', 'font_family' => SANS, 'weight' => '500', 'letter_spacing' => '-0.3px']]),
], '8px', ['dimensions' => ['width' => 'auto']], ['align' => 'center'], []);
// In the header the name follows the colours of what it passes over (the header adapts); in the full menu it stays light.
$brand = follow_page($brandRow(), [strtolower(BONE) => 'text.body', strtolower(ORANGE) => 'text.accent']);
// The mega menu: Work, Services and About open wide frosted-glass panels drawn from the content types, so a new
// project, service or review shows in the menu by itself. Each panel's cards are Loop Items of their own (compact).
$tplUuid = static fn(string $slug): string => (string) Database::value("SELECT uuid FROM studiobuilder_pages WHERE tenant_id = ? AND slug = ? AND status <> 'archived'", [$tid, $slug]);
$megaEye = static fn(string $t): array => EYE($t, '#8F897F');
$megaMore = static fn(string $label, string $href): array => B('core.link', ['link' => L($label, $href), 'style' => 'arrow'], [], ['color' => BONE, 'typography' => ['size' => '13px', 'weight' => '500']], ['classNames' => ['rk-mega-more']]);
$megaLoop = static fn(string $type, string $tpl, int $n, int $cols, array $more = []): array => B('core.query_loop', array_replace(['source' => 'posts', 'content_type' => $type, 'loop_template' => $tplUuid($tpl), 'per_page' => $n, 'columns' => $cols, 'gap' => 'md',
    'order_by' => 'published_at', 'order' => 'desc', 'show_featured_image' => false, 'show_date' => false, 'show_author' => false, 'show_category' => false, 'show_excerpt' => false, 'enable_pagination' => false, 'empty_message' => ''], $more), [], [], ['classNames' => ['rk-loop', 'rk-mega-loop']]);

// Loop Items for the panels.
build_page('template-loop-project-mega', 'Loop Item: Project (menu)', 'system', 'standalone', [CTOP('Card', [
    B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [
        B('theme.post_image', [], [], ['dimensions' => ['height' => '150px', 'width' => '100%', 'overflow' => 'hidden'], 'border' => ['radius' => '12px']], ['classNames' => ['rk-pcard-img']]),
        FV('industry', INK, '10px', 'span', ['typography' => ['size' => '10px', 'font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1px', 'line_height' => '1.3'], 'position' => ['mode' => 'absolute', 'top' => '10px', 'left' => '10px']], ['bg_field' => 'color'], ['classNames' => ['rk-tag']]),
    ], ['position' => ['mode' => 'relative']]),
    B('theme.post_title', ['level' => 'h3', 'link_to_post' => true], [], ['color' => BONE, 'margin' => ['top' => '12px'], 'typography' => ['size' => '17px', 'font_family' => SANS, 'weight' => '500', 'line_height' => '1.25', 'letter_spacing' => '-0.2px']], ['classNames' => ['rk-stretch']]),
    FV('tagline', '#A39D93', '13px', 'p', ['typography' => ['line_height' => '1.45']], [], ['when_field' => 'tagline', 'classNames' => ['rk-clamp2']]),
], ['layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '4px', 'content_width' => 'full', 'padding_y' => 'none'], 'position' => ['mode' => 'relative']], ['classNames' => ['rk-mcard']])], ['settings' => ['template_type' => 'loop']]);
build_page('template-loop-service-mega', 'Loop Item: Service (menu)', 'system', 'standalone', [CTOP('Row', [
    B('theme.post_title', ['level' => 'h3', 'link_to_post' => true], [], ['color' => BONE, 'typography' => ['size' => '16px', 'font_family' => SANS, 'weight' => '500', 'line_height' => '1.3']], ['classNames' => ['rk-stretch']]),
    FV('summary', '#A39D93', '13px', 'p', ['typography' => ['line_height' => '1.45']], [], ['classNames' => ['rk-clamp2']]),
], ['layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '4px', 'content_width' => 'full', 'padding_y' => 'none'], 'padding' => ['top' => '14px', 'right' => '16px', 'bottom' => '14px', 'left' => '16px'], 'border' => ['radius' => '14px'], 'position' => ['mode' => 'relative']], ['classNames' => ['rk-mrow']])], ['settings' => ['template_type' => 'loop']]);
build_page('template-loop-review-mega', 'Loop Item: Review (menu)', 'system', 'standalone', [CTOP('Review', [
    P('★★★★★', ORANGE, '12px', [], ['classNames' => ['rk-stars']]),
    FV('text', BONE, '14px', 'p', ['typography' => ['line_height' => '1.5']], [], ['classNames' => ['rk-clamp3']]),
    ROW([B('theme.post_title', ['level' => 'h4'], [], ['color' => BONE, 'typography' => ['size' => '12px', 'font_family' => SANS, 'weight' => '500']]), FVM('country', '#8F897F', ['before' => '· '])], '6px', [], ['align' => 'baseline'], []),
], ['layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '8px', 'content_width' => 'full', 'padding_y' => 'none'], 'padding' => ['top' => '16px', 'right' => '16px', 'bottom' => '16px', 'left' => '16px'], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => 'rgba(237,232,223,0.10)', 'radius' => '14px']], ['classNames' => ['rk-mreview']])], ['settings' => ['template_type' => 'loop']]);

$INDUSTRY_TERMS = ['Construction & trades' => '/construction/', 'Logistics & transport' => '', 'Cleaning & sterilisation' => '', 'Hospitality & rentals' => '', 'Professional services' => '', 'Creative & wellness' => ''];
$megaLink = static fn(string $label, string $href, string $desc = ''): array => VBOX(array_values(array_filter([
    B('core.link', ['link' => L($label, $href), 'style' => 'plain'], [], ['color' => BONE, 'typography' => ['size' => '16px', 'weight' => '400', 'line_height' => '1.3']], ['classNames' => ['rk-stretch']]),
    $desc !== '' ? P($desc, '#8F897F', '13px') : null,
])), '2px', ['padding' => ['top' => '10px', 'right' => '12px', 'bottom' => '10px', 'left' => '12px'], 'border' => ['radius' => '12px'], 'position' => ['mode' => 'relative']], ['classNames' => ['rk-mlink']]);
$col = static fn(array $kids, string $gap = '14px', array $classes = []): array => VBOX($kids, $gap, [], $classes !== [] ? ['classNames' => $classes] : []);
$megaItem = static fn(string $label, string $url, int $cols, array $panel): array => B('layout.mega_item', ['label' => $label, 'url' => $url, 'columns' => $cols], $panel);

$mWork = $megaItem('Work', '/work/', 4, [
    $col([$megaEye('By industry'), VBOX(array_map(static fn(string $t, string $u): array => $megaLink($t, $u !== '' ? $u : '/work/?filter=' . rawurlencode($t)), array_keys($INDUSTRY_TERMS), $INDUSTRY_TERMS), '0px', ['margin' => ['left' => '-12px']]), $megaMore('All case studies', '/work/')], '12px'),
    $col([ROW([$megaEye('Latest case studies'), $megaMore('See all work', '/work/')], '12px', [], ['justify' => 'between', 'align' => 'center'], []), $megaLoop('project', 'template-loop-project-mega', 3, 3)], '14px', ['rk-mega-span3']),
]);
$mServices = $megaItem('Services', '/services/', 3, [
    $col([ROW([$megaEye('Services'), $megaMore('All services', '/services/')], '12px', [], ['justify' => 'between', 'align' => 'center'], []), $megaLoop('service', 'template-loop-service-mega', 6, 2)], '10px', ['rk-mega-span2']),
    $col([
        B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [
            PIC('b131e2de14c018247fe2c08587aa79d2.webp', 'Redkey Logistics website on a tablet', '100%', '', '16px'),
        ], ['dimensions' => ['height' => '150px', 'overflow' => 'hidden'], 'border' => ['radius' => '16px']], ['classNames' => ['rk-mpromo-pic']]),
        P('Free website audit', BONE, '18px', ['weight' => '500']),
        P('Send your site. I review it by hand and reply within 12 hours with the top five fixes.', '#A39D93', '13px'),
        PILL('Get my free audit  →', '/free-audit/', ORANGE, INK, ['style_parts' => ['button' => ['padding' => ['top' => '10px', 'right' => '18px', 'bottom' => '10px', 'left' => '18px'], 'typography' => ['size' => '13px']]]]),
    ], '10px', ['rk-mpromo']),
]);
$mAbout = $megaItem('About', '/about/', 3, [
    $col([
        B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [
            PIC('rakib-portrait-studio.jpg', 'Rakib Hasan, freelance web designer and developer', '100%', '', '16px'),
            VBOX([P('Rakib Hasan', BONE, '15px', ['weight' => '500']), EYE('Designer + developer · Dhaka', '#BDB7AD')], '2px', ['position' => ['mode' => 'absolute', 'left' => '16px', 'bottom' => '14px']]),
        ], ['dimensions' => ['height' => '250px', 'overflow' => 'hidden'], 'position' => ['mode' => 'relative'], 'border' => ['radius' => '16px']], ['classNames' => ['rk-mabout-pic']]),
    ]),
    $col([$megaEye('Get to know me'), VBOX([
        $megaLink('About', '/about/', 'One person, start to finish'),
        $megaLink('Process', '/process/', 'Audit, staging, verified live'),
        $megaLink('FAQ', '/faq/', 'Cost, timelines, ownership'),
        $megaLink('Contact', '/contact/', 'Replies within about an hour'),
    ], '0px', ['margin' => ['left' => '-12px']])], '12px'),
    $col([ROW([$megaEye('★ 5.0 · 101 reviews'), $megaMore('All reviews', '/reviews/')], '12px', [], ['justify' => 'between', 'align' => 'center'], []), $megaLoop('review', 'template-loop-review-mega', 2, 1)], '14px'),
]);
$nav = B('layout.mega_menu', ['aria_label' => 'Main', 'align' => 'center', 'panel_width' => 'content', 'panel_look' => 'glass', 'toggle_breakpoint' => 'none'], [
    $mWork, $mServices, $mAbout,
    B('layout.mega_item', ['label' => 'Process', 'url' => '/process/']),
    B('layout.mega_item', ['label' => 'Reviews', 'url' => '/reviews/']),
], ['typography' => ['size' => '14px', 'font_family' => SANS, 'weight' => '400']], ['classNames' => ['rk-nav', 'rk-mega'], 'style_parts' => [
    'link' => ['color' => 'text.body'], 'current' => ['color' => 'text.body'],
    'submenu' => ['color' => BONE],
], 'devices' => ['lg']]);
// The full-screen menu: the brand where the header's is, two columns of numbered links with a line under each,
// the hovered link's picture on the right, and a row of contact links along the bottom.
$fsLinks = static fn(array $links): array => array_map(static fn(array $l): array => B('core.link', ['link' => L($l[0], $l[1]), 'style' => 'plain'], [], ['color' => BONE, 'typography' => ['size' => '14px']]), $links);
$fullMenu = B('layout.offcanvas', ['drawer_id' => 'rk-menu', 'title' => '', 'position' => 'fullscreen', 'close_text' => 'Close', 'trigger_text' => 'Menu', 'trigger_variant' => 'plain', 'trigger_icon' => 'menu', 'trigger_icon_size' => 18, 'trigger_show_text' => true, 'trigger_text_mobile' => false], [
    ROW([$brandRow()], '0px', ['padding' => ['top' => '20px', 'right' => '28px', 'bottom' => '20px', 'left' => '28px']], ['align' => 'center'], []),
    B('core.nav_menu', ['menu' => 'full', 'items' => [], 'layout' => 'vertical', 'align' => 'left', 'aria_label' => 'Full menu', 'toggle_breakpoint' => 'none',
        'numbered' => true, 'descriptions' => true, 'arrow' => true, 'columns' => 2, 'preview' => true, 'reveal' => true], [],
        ['typography' => ['size' => '40px', 'font_family' => SANS, 'weight' => '300', 'letter_spacing' => '-1.6px', 'line_height' => '1.05'],
         'padding' => ['top' => '40px', 'right' => '28px', 'bottom' => '40px', 'left' => '28px'], 'dimensions' => ['max_width' => '1440px'], 'margin' => ['top' => 'auto', 'bottom' => '0px']],
        ['classNames' => ['rk-fs'], 'style_parts' => [
            'item' => ['border' => ['width' => '1px', 'style' => 'solid', 'color' => '#22211F', 'sides' => 'bottom']],
            'link' => ['color' => BONE, 'padding' => ['top' => '14px', 'right' => '0px', 'bottom' => '14px', 'left' => '0px']],
            'current' => ['color' => ORANGE],
            'number' => ['color' => MUTED],
            'description' => ['color' => MUTED],
            'arrow' => ['color' => ORANGE],
            'preview' => ['border' => ['radius' => '20px']],
            'caption' => ['color' => MUTED],
        ], 'responsive' => ['mobile' => ['style' => ['typography' => ['size' => '30px'], 'padding' => ['top' => '24px', 'right' => '16px', 'bottom' => '24px', 'left' => '16px']]]]]),
    ROW([
        P('Available from December 2026 · Based in Bangladesh, working worldwide', '#BDB7AD', '14px'),
        ROW($fsLinks([['Support@rakibhasaan.com', 'mailto:Support@rakibhasaan.com'], ['Fiverr', 'https://fiverr.com/rakib_64/'], ['GitHub', 'https://github.com/rakib6564/']]), '22px', ['dimensions' => ['width' => 'auto']], ['wrap' => 'wrap'], []),
    ], '20px', ['padding' => ['top' => '22px', 'right' => '28px', 'bottom' => '22px', 'left' => '28px'], 'border' => ['width' => '1px 0 0 0', 'style' => 'solid', 'color' => '#22211F'], 'margin' => ['top' => 'auto']], ['justify' => 'between', 'align' => 'center', 'wrap' => 'wrap'], ['layout' => ['direction' => 'column', 'align' => 'start']]),
], [], ['style_parts' => [
    'trigger' => ['color' => 'text.body', 'border' => ['radius' => '999px'], 'padding' => ['top' => '11px', 'right' => '18px', 'bottom' => '11px', 'left' => '18px'], 'typography' => ['size' => '14px', 'weight' => 'medium', 'family' => 'body']],
    'panel' => ['background' => ['color' => INK], 'color' => BONE],
    'header' => ['padding' => ['top' => '16px', 'right' => '28px', 'bottom' => '16px', 'left' => '28px']],
    'close' => ['color' => BONE, 'background' => ['color' => '#1C1B19']],
    'body' => ['padding' => ['top' => '0px', 'right' => '0px', 'bottom' => '0px', 'left' => '0px']],
]]);
$hdr = CTOP('Header', [
    $brand,
    $nav,
    ROW([PILL('Free audit', '/free-audit/', ORANGE, INK, ['responsive' => ['mobile' => ['hide' => true]]]), $fullMenu], '8px', ['dimensions' => ['width' => 'auto']], ['align' => 'center'], []),
], ['padding' => ['top' => '16px', 'right' => '28px', 'bottom' => '16px', 'left' => '28px'], 'layout' => ['display' => 'flex', 'direction' => 'row', 'gap' => '24px', 'justify' => 'between', 'align' => 'center', 'content_width' => 'boxed', 'content_max' => '1440px']],
    ['interactions' => ['adapt' => 'text', 'progress' => ['edge' => 'bottom', 'color' => ORANGE, 'height' => 2]], 'responsive' => ['mobile' => ['style' => ['padding' => ['top' => '12px', 'right' => '16px', 'bottom' => '12px', 'left' => '16px']]]]]);
// Transparent over the top of every page (each page's first section starts under it); frosted glass in the colour of
// the section beneath once the page scrolls, and its words follow that section's colours throughout.
build_page('default', 'Header', 'header_partial', 'standalone', [$hdr], ['settings' => ['sticky_header' => ['edge' => 'top', 'hide_on_scroll' => true, 'overlay' => true]]]);

// ── Footer ─────────────────────────────────────────────────────────────────────
$col = static fn(string $title, array $links): array => VBOX([EYE($title), VBOX(array_map(static fn(array $l): array => B('core.link', ['link' => L($l[0], $l[1]), 'style' => 'plain'], [], ['color' => BONE, 'typography' => ['size' => '15px', 'weight' => '400', 'line_height' => '1.5']]), $links), '10px')], '20px');
$chip = static fn(string $t): array => P($t, BONE, '13px', [], ['classNames' => ['rk-chip']]);
$ft = CTOP('Footer', [
    ROW([
        B('core.local_time', ['label' => 'Online ·', 'city' => '', 'timezone' => 'Asia/Dhaka', 'format' => '12h', 'seconds' => false, 'show_offset' => false], [], ['color' => MUTED, 'typography' => ['size' => '12px', 'font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1.4px']], ['classNames' => ['rk-online']]),
        EYE('in Dhaka'),
    ], '6px', [], ['align' => 'center'], []),
    ROW([
        B('core.heading', ['text' => 'Have a project in mind?', 'level' => 'h2'], [], ['color' => BONE, 'typography' => ['size' => '96px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '0.95', 'letter_spacing' => '-5.2px'], 'dimensions' => ['max_width' => '620px']], ['responsive' => ['tablet' => ['style' => ['typography' => ['size' => '72px']]], 'mobile' => ['style' => ['typography' => ['size' => '52px']]]]]),
        VBOX([
            PILL('Start with a free audit  ↗', '/free-audit/', ORANGE, INK),
            EYE('Copy email'),
            B('core.button', ['link' => L('Support@rakibhasaan.com', 'mailto:Support@rakibhasaan.com'), 'variant' => 'ghost', 'copy_text' => 'Support@rakibhasaan.com', 'copied_label' => 'Copied'], [], [], ['style_parts' => ['button' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'color' => BONE, 'padding' => ['top' => '0px', 'right' => '0px', 'bottom' => '0px', 'left' => '0px'], 'border' => ['width' => '0px'], 'typography' => ['size' => '18px', 'weight' => 'normal', 'family' => 'body']]]]),
        ], '12px', ['dimensions' => ['width' => 'auto']]),
    ], '40px', ['margin' => ['top' => '24px', 'bottom' => '64px']], ['justify' => 'between', 'align' => 'end']),
    B('core.divider', ['weight' => 'thin'], [], ['color' => LINE]),
    ROW([
        W(VBOX([
            ROW([B('core.icon', ['name' => 'circle', 'size' => 'sm'], [], ['color' => ORANGE, 'dimensions' => ['width' => '12px']], ['classNames' => ['rk-dot']]), P('Rakib Hasan', BONE, '16px', ['weight' => '500'])], '8px', [], ['align' => 'center'], []),
            P('WordPress websites, custom plugins and SEO fixes for small businesses in the US and Europe. Based in Bangladesh, working worldwide.', MUTED, '15px'),
            ROW([$chip('★ 5.0 · 101 reviews'), $chip('Fiverr Level 2'), $chip('250+ sites · 14 countries')], '8px', [], ['wrap' => 'wrap'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']]),
        ], '18px'), '30%'),
        W($col('Services', [['WordPress websites', '/wordpress-websites/'], ['Custom development', '/custom-development/'], ['SEO audits', '/seo/'], ['Web & mobile apps', '/apps/'], ['Shopify, Wix & Squarespace', '/platforms/'], ['Branding & video', '/branding/']]), '22%', true),
        W($col('Site', [['Work', '/work/'], ['Construction', '/construction/'], ['Process', '/process/'], ['About', '/about/'], ['Reviews', '/reviews/'], ['FAQ', '/faq/']]), '22%', true),
        W($col('Contact', [['Free audit', '/free-audit/'], ['Contact', '/contact/'], ['Fiverr', 'https://fiverr.com/rakib_64/'], ['GitHub', 'https://github.com/rakib6564/']]), '18%', true),
    ], '32px', ['margin' => ['top' => '56px', 'bottom' => '40px']], ['justify' => 'between'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap', 'gap' => '40px']]),
    B('core.divider', ['weight' => 'thin'], [], ['color' => LINE]),
    ROW([EYE('Designer + developer'), EYE('Est. 2018 · Dhaka, Bangladesh', MUTED, 'center'), EYE('250+ sites · 14 countries', MUTED, 'right')], '16px', ['padding' => ['top' => '18px', 'bottom' => '18px']], ['justify' => 'between'], ['layout' => ['direction' => 'column', 'gap' => '6px']]),
    B('core.divider', ['weight' => 'thin'], [], ['color' => LINE]),
    ROW([P('© 2026 Rakib Hasan', MUTED, '13px'), ROW([
        B('core.link', ['link' => L('Privacy policy', '/privacy/'), 'style' => 'plain'], [], ['color' => MUTED, 'typography' => ['size' => '13px']]),
        B('core.link', ['link' => L('Terms', '/terms/'), 'style' => 'plain'], [], ['color' => MUTED, 'typography' => ['size' => '13px']]),
        B('core.link', ['link' => L('↑', '#top'), 'style' => 'plain'], [], ['color' => BONE], ['classNames' => ['rk-top']]),
    ], '22px', ['dimensions' => ['width' => 'auto']], ['align' => 'center'], [])], '16px', ['padding' => ['top' => '22px']], ['justify' => 'between', 'align' => 'center'], []),
    B('core.message_widget', [
        'launcher_label' => 'Message Rakib', 'launcher_icon' => 'message-circle', 'position' => 'left', 'theme' => 'light',
        'launcher_photo' => true, 'launcher_lines' => "{status}\nIt's {time} in Dhaka", 'compact_on_scroll' => true,
        'title' => 'Rakib Hasan', 'name' => '', 'avatar' => IMGREF('dc5af924d95ea0c65e0bccbd61ccba59.jpg', 'Rakib Hasan'),
        'intro' => '',
        'timezone' => 'Asia/Dhaka', 'hours_start' => '10:00', 'hours_end' => '23:00', 'days' => 'everyday',
        'online_text' => 'Online · replies in ~1 hour', 'away_text' => 'Away · replies in a few hours',
        'topics' => "New website\nRedesign\nSEO audit\nCustom plugin", 'topics_label' => 'Topic', 'email_label' => 'Email', 'ask_name' => false,
        'message_placeholder' => 'A line or two about your site', 'submit_text' => 'Send',
        'success_message' => 'Thanks! I’ll reply by email soon.', 'open_after_seconds' => 0, 'open_after_scroll' => 0, 'remember_closed' => true,
    ], [], [], ['classNames' => ['rk-chat']]),
], ['padding' => ['top' => '96px', 'right' => '28px', 'bottom' => '28px', 'left' => '28px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '0px', 'content_width' => 'boxed', 'content_max' => '1440px']],
    ['interactions' => THEME('ink'), 'responsive' => ['mobile' => ['style' => ['padding' => ['top' => '72px', 'right' => '20px', 'bottom' => '24px', 'left' => '20px']]]]]);
build_page('default', 'Footer', 'footer_partial', 'standalone', [$ft]);

// ── Pages ──────────────────────────────────────────────────────────────────────
$only = array_filter(explode(',', (string) getenv('RK_ONLY')));
foreach (['home', 'inner'] as $page) {
    if ($only === [] || in_array($page, $only, true)) { require __DIR__ . "/{$page}.php"; }
}
say('done');
