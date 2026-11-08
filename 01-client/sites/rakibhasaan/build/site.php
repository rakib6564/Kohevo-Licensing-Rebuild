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
$brand = $brandRow();
$nav = B('core.nav_menu', ['menu' => 'header', 'items' => [], 'layout' => 'horizontal', 'align' => 'center', 'aria_label' => 'Main', 'toggle_breakpoint' => 'none'], [], ['typography' => ['size' => '14px', 'font_family' => SANS, 'weight' => '400']], ['classNames' => ['rk-nav'], 'style_parts' => [
    'link' => ['color' => 'rgba(237,232,223,.72)'], 'current' => ['color' => BONE],
    'submenu' => ['background' => ['color' => '#141416'], 'border' => ['radius' => '14px']], 'sublink' => ['color' => BONE],
], 'devices' => ['lg']]);
// The full-screen menu: the brand where the header's is, two columns of numbered links with a line under each,
// the hovered link's picture on the right, and a row of contact links along the bottom.
$fsLinks = static fn(array $links): array => array_map(static fn(array $l): array => B('core.link', ['link' => L($l[0], $l[1]), 'style' => 'plain'], [], ['color' => BONE, 'typography' => ['size' => '14px']]), $links);
$fullMenu = B('layout.offcanvas', ['drawer_id' => 'rk-menu', 'title' => '', 'position' => 'fullscreen', 'close_text' => 'Close', 'trigger_text' => 'Menu', 'trigger_variant' => 'plain', 'trigger_icon' => 'menu', 'trigger_icon_size' => 18, 'trigger_show_text' => true], [
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
    'trigger' => ['background' => ['color' => 'rgba(237,232,223,.08)'], 'color' => BONE, 'border' => ['radius' => '999px'], 'padding' => ['top' => '11px', 'right' => '18px', 'bottom' => '11px', 'left' => '18px'], 'typography' => ['size' => '14px', 'weight' => 'medium', 'family' => 'body']],
    'panel' => ['background' => ['color' => INK], 'color' => BONE],
    'header' => ['padding' => ['top' => '16px', 'right' => '28px', 'bottom' => '16px', 'left' => '28px']],
    'close' => ['color' => BONE, 'background' => ['color' => '#1C1B19']],
    'body' => ['padding' => ['top' => '0px', 'right' => '0px', 'bottom' => '0px', 'left' => '0px']],
]]);
$hdr = CTOP('Header', [
    $brand,
    $nav,
    ROW([PILL('Free audit', '/free-audit/'), $fullMenu], '8px', ['dimensions' => ['width' => 'auto']], ['align' => 'center'], []),
], ['padding' => ['top' => '16px', 'right' => '28px', 'bottom' => '16px', 'left' => '28px'], 'layout' => ['display' => 'flex', 'direction' => 'row', 'gap' => '24px', 'justify' => 'between', 'align' => 'center', 'content_width' => 'boxed', 'content_max' => '1440px']],
    ['interactions' => ['adapt' => true, 'progress' => ['edge' => 'bottom', 'color' => ORANGE, 'height' => 2]], 'responsive' => ['mobile' => ['style' => ['padding' => ['top' => '12px', 'right' => '16px', 'bottom' => '12px', 'left' => '16px']]]]]);
build_page('default', 'Header', 'header_partial', 'standalone', [$hdr], ['settings' => ['sticky_header' => ['edge' => 'top', 'hide_on_scroll' => true, 'background' => 'rgba(11,11,12,0.86)']]]);

// ── Footer ─────────────────────────────────────────────────────────────────────
$col = static fn(string $title, array $links): array => VBOX([EYE($title), VBOX(array_map(static fn(array $l): array => B('core.link', ['link' => L($l[0], $l[1]), 'style' => 'plain'], [], ['color' => BONE, 'typography' => ['size' => '15px', 'weight' => '400', 'line_height' => '1.5']]), $links), '10px')], '20px');
$chip = static fn(string $t): array => P($t, BONE, '13px', [], ['classNames' => ['rk-chip']]);
$ft = CTOP('Footer', [
    ROW([
        B('core.local_time', ['label' => 'Online ·', 'city' => '', 'timezone' => 'Asia/Dhaka', 'format' => '12h', 'seconds' => false, 'show_offset' => false], [], ['color' => MUTED, 'typography' => ['size' => '12px', 'font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1.4px']], ['classNames' => ['rk-online']]),
        EYE('in Dhaka'),
    ], '6px', [], ['align' => 'center'], []),
    ROW([
        B('core.heading', ['text' => 'Have a project in mind?', 'level' => 'h2'], [], ['color' => BONE, 'typography' => ['size' => '96px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '0.95', 'letter_spacing' => '-5.2px'], 'dimensions' => ['max_width' => '620px']], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => '52px']]]]]),
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
        W($col('Services', [['WordPress websites', '/wordpress-websites/'], ['Custom development', '/custom-development/'], ['SEO audits', '/seo/'], ['Web & mobile apps', '/apps/'], ['Shopify, Wix & Squarespace', '/platforms/'], ['Branding & video', '/branding/']]), '22%'),
        W($col('Site', [['Work', '/work/'], ['Construction', '/construction/'], ['Process', '/process/'], ['About', '/about/'], ['Reviews', '/reviews/'], ['FAQ', '/faq/']]), '22%'),
        W($col('Contact', [['Free audit', '/free-audit/'], ['Contact', '/contact/'], ['Fiverr', 'https://fiverr.com/rakib_64/'], ['GitHub', 'https://github.com/rakib6564/']]), '18%'),
    ], '32px', ['margin' => ['top' => '56px', 'bottom' => '40px']], ['justify' => 'between'], ['layout' => ['direction' => 'column', 'gap' => '40px']]),
    B('core.divider', ['weight' => 'thin'], [], ['color' => LINE]),
    ROW([EYE('Designer + developer'), EYE('Est. 2018 · Dhaka, Bangladesh', MUTED, 'center'), EYE('250+ sites · 14 countries', MUTED, 'right')], '16px', ['padding' => ['top' => '18px', 'bottom' => '18px']], ['justify' => 'between'], ['layout' => ['direction' => 'column', 'gap' => '6px']]),
    B('core.divider', ['weight' => 'thin'], [], ['color' => LINE]),
    ROW([P('© 2026 Rakib Hasan', MUTED, '13px'), ROW([
        B('core.link', ['link' => L('Privacy policy', '/privacy/'), 'style' => 'plain'], [], ['color' => MUTED, 'typography' => ['size' => '13px']]),
        B('core.link', ['link' => L('Terms', '/terms/'), 'style' => 'plain'], [], ['color' => MUTED, 'typography' => ['size' => '13px']]),
        B('core.link', ['link' => L('↑', '#top'), 'style' => 'plain'], [], ['color' => BONE], ['classNames' => ['rk-top']]),
    ], '22px', ['dimensions' => ['width' => 'auto']], ['align' => 'center'], [])], '16px', ['padding' => ['top' => '22px']], ['justify' => 'between', 'align' => 'center'], []),
    B('core.message_widget', [
        'launcher_label' => 'Message Rakib H.', 'launcher_icon' => 'message-circle', 'position' => 'left',
        'launcher_photo' => true, 'launcher_lines' => "{status}\nIt's {time} in Dhaka\nAsk me about your website", 'compact_on_scroll' => true,
        'title' => 'Message Rakib H.', 'name' => 'Rakib', 'avatar' => IMGREF('dc5af924d95ea0c65e0bccbd61ccba59.jpg', 'Rakib Hasan'),
        'intro' => 'Tell me about your site. I usually reply within the hour.',
        'timezone' => 'Asia/Dhaka', 'hours_start' => '10:00', 'hours_end' => '23:00', 'days' => 'everyday',
        'online_text' => 'Online · avg. reply 1 hour', 'away_text' => 'Away · I reply within a few hours of starting work',
        'topics' => "New website\nRedesign\nSEO audit\nCustom plugin", 'topics_label' => 'I need help with', 'ask_name' => false,
        'message_placeholder' => 'Hi Rakib, I would like to talk about my website.', 'submit_text' => 'Send',
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
