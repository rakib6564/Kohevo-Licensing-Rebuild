<?php
/**
 * rakibhasaan.com — Projects (the Work case studies) as a content type.
 *
 * Every case study is one Project item: its own page at /case-study/<slug>/, its text, pictures, facts and numbers in
 * fields, edited on the item's screen. The Single template draws every project the original's way, from those fields
 * only: hero with the browser-framed screenshot, the stack as a ticker, the facts, the story with a chapter list that
 * follows the reader, the screens, the result and its numbers, the client's words, a call to action and the next
 * project. A section whose field is empty is left out. The card template is what Query Loops show.
 *
 * Required by inner.php (it uses TC, CARD, RICH, LOOP's helpers and $DATA, $tid).
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PageRoutes;

$INDUSTRY = ['Construction & trades' => '#FF5A1F', 'Logistics & transport' => '#9BCB3C', 'Cleaning & sterilisation' => '#19C7B0', 'Hospitality & rentals' => '#FF6FA5', 'Professional services' => '#E8B931', 'Creative & wellness' => '#E07A5F'];
PageMeta::savePostTypes($tid, array_replace(PageMeta::postTypes($tid), ['project' => [
    'key' => 'project', 'singular' => 'Project', 'plural' => 'Projects', 'route' => PROJECT_ROUTE, 'is_system' => false, 'description' => 'Case studies: one page each, drawn by the Single: Project template', 'show_in_menu' => true,
    'rewrite' => true, 'rewrite_slug' => trim(PROJECT_ROUTE, '/'), 'hide_field_names' => false, 'edit_link' => true, 'menu_icon' => 'briefcase', 'menu_position' => 1,
    'columns' => [['label' => 'Industry', 'source' => 'industry', 'prefix' => '', 'suffix' => '', 'sortable' => true], ['label' => 'Country', 'source' => 'country', 'prefix' => '', 'suffix' => '', 'sortable' => true], ['label' => 'Year', 'source' => 'year', 'prefix' => '', 'suffix' => '', 'sortable' => true]],
    'filters' => [],
]]));
$f = static fn(string $key, string $label, string $kind = 'text', array $options = [], string $help = ''): array => ['id' => 'f_' . substr(md5('project' . $key), 0, 8), 'post_type' => 'project', 'label' => $label, 'key' => $key, 'type' => $kind, 'required' => in_array($key, ['industry', 'h1', 'summary', 'screen'], true), 'placeholder' => '', 'help' => $help, 'options' => $options];
$others = array_values(array_filter(PageMeta::allFields($tid), static fn(array $x): bool => ($x['post_type'] ?? '') !== 'project'));
PageMeta::saveFields($tid, array_merge($others, [
    $f('industry', 'Industry', 'terms', ['choices' => array_map(static fn(string $k): array => ['value' => $k, 'label' => explode(' &', $k)[0]], array_keys($INDUSTRY))]),
    $f('h1', 'Page heading', 'text', [], 'The H1 on the case study, e.g. "A booking site for a Provençal villa".'),
    $f('summary', 'Summary', 'textarea', [], 'One or two sentences: on the card, in the hero and in search results.'),
    $f('client', 'Client'), $f('country', 'Country'), $f('country_short', 'Country (short)', 'text', [], 'Shown on the pills, e.g. USA.'),
    $f('year', 'Year launched'), $f('service', 'Service'),
    $f('stack', 'Stack', 'list', [], 'One tool per row: WordPress, Elementor Pro…'),
    $f('live', 'Live site', 'url'),
    $f('screen', 'Desktop screenshot', 'image'), $f('phone', 'Phone screenshot', 'image'), $f('screen_alt', 'Screenshot description'),
    $f('the_client', 'The client', 'richtext'), $f('challenge', 'The challenge', 'richtext'), $f('built', 'What I built', 'richtext'), $f('result', 'The result', 'richtext'),
    $f('weeks', 'Weeks to launch', 'number'), $f('pagespeed', 'Mobile PageSpeed at launch', 'number'), $f('pages', 'Pages built', 'number'),
    $f('quote', 'Client quote', 'textarea'), $f('quote_by', 'Quote by'),
    $f('tagline', 'Short line', 'text', [], 'Under the name on the home page, e.g. "Freight with 50,000 vetted carriers · USA · 2023".'),
    $f('color', 'Industry colour', 'color'),
]));
PageRoutes::forget($tid);
say('  + project type');

$pill = static fn(array $b): array => $b + ['style_parts' => ['value' => ['border' => ['width' => '1px', 'style' => 'solid', 'color' => LINE, 'radius' => '999px'], 'padding' => ['top' => '8px', 'right' => '13px', 'bottom' => '8px', 'left' => '13px']]]];
$headRow = static fn(string $n, string $title, string $theme): array => ROW([
    EYE($n, TC($theme)['muted']), P($title, TC($theme)['fg'], '15px', ['weight' => '500']), B('core.divider', ['weight' => 'thin'], [], ['color' => TC($theme)['line'], 'dimensions' => ['width' => '100%']]),
], '18px', ['margin' => ['bottom' => '40px']], ['align' => 'center'], [], ['classNames' => ['rk-label-row']]);

// 1. Hero: crumbs, the industry, the heading, the summary and pills, and the screenshot in a browser frame.
$crumbs = ROW([
    B('core.link', ['link' => L('Home', '/'), 'style' => 'plain'], [], ['color' => MUTED, 'typography' => ['size' => '12px', 'font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1.2px']]), EYE('/', LINE),
    B('core.link', ['link' => L('Work', '/work/'), 'style' => 'plain'], [], ['color' => MUTED, 'typography' => ['size' => '12px', 'font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1.2px']]), EYE('/', LINE),
    B('theme.post_title', ['level' => 'h6'], [], ['color' => BONE, 'typography' => ['size' => '12px', 'font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1.2px']]),
], '10px', [], ['align' => 'center', 'wrap' => 'wrap'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']]);
$frame = B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [
    ROW([
        ROW(array_map(static fn(string $c): array => B('core.icon', ['name' => 'circle', 'size' => 'sm'], [], ['color' => $c, 'dimensions' => ['width' => '10px']], ['classNames' => ['rk-dot']]), ['#FF5F57', '#FEBC2E', '#28C840']), '6px', ['dimensions' => ['width' => 'auto']], ['align' => 'center'], []),
        FVM('live', MUTED, ['url_label' => 'domain', 'fallback' => 'private build'], ['dimensions' => ['max_width' => '420px']]),
    ], '14px', ['padding' => ['top' => '12px', 'right' => '16px', 'bottom' => '12px', 'left' => '16px'], 'background' => ['color' => '#18181B'], 'border' => ['width' => '0 0 1px 0', 'style' => 'solid', 'color' => '#26262A']], ['align' => 'center'], [], ['classNames' => ['rk-bar']]),
    FV('screen', BONE, '16px', 'div', [], ['fit' => 'cover', 'alt_field' => 'screen_alt', 'priority' => true], ['classNames' => ['rk-shot']]),
], ['background' => ['color' => '#141416'], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => '#26262A', 'radius' => '18px'], 'shadow' => '0 60px 120px -40px rgba(0,0,0,0.9)'],
    ['classNames' => ['rk-frame'], 'interactions' => ['scroll' => ['scale' => 2]]]);
$badge = static fn(string $label, string $key, string $after, string $cls): array => VBOX([EYE($label, MUTED), FV($key, BONE, '30px', 'div', ['typography' => ['weight' => '300', 'line_height' => '1', 'letter_spacing' => '-1px']], ['after' => $after])], '6px',
    ['background' => ['color' => 'rgba(20,20,22,0.92)'], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => '#2A2926', 'radius' => '16px'], 'padding' => ['top' => '14px', 'right' => '18px', 'bottom' => '14px', 'left' => '18px'], 'dimensions' => ['width' => 'auto']],
    ['classNames' => ['rk-badge-' . $cls], 'when_field' => $key, 'animation' => ['type' => 'fade_up', 'duration_ms' => 700, 'delay_ms' => 300]]);
$hero = CTOP('Project hero', [
    $crumbs,
    ROW([
        W(VBOX([
            ROW([B('core.icon', ['name' => 'circle', 'size' => 'sm'], [], ['color' => ORANGE, 'dimensions' => ['width' => '10px']], ['classNames' => ['rk-dot']]), EYE('Case study ·', '#BDB7AD'), FVM('industry', '#BDB7AD')], '10px', [], ['align' => 'center'], [], ['classNames' => ['rk-case-ind']]),
            B('core.heading', ['text' => 'Project heading', 'level' => 'h1'], [], ['color' => BONE, 'typography' => ['size' => '84px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '0.98', 'letter_spacing' => '-4.6px']],
                ['dynamic' => ['text' => ['tag' => 'field', 'key' => 'h1']], 'responsive' => ['mobile' => ['style' => ['typography' => ['size' => '42px']]]], 'interactions' => ['text' => ['effect' => 'lines', 'step_ms' => 80]]]),
        ], '18px'), '60%'),
        W(VBOX([
            FV('summary', '#CFC9BF', '19px', 'p', ['typography' => ['weight' => '300', 'line_height' => '1.55']]),
            ROW([$pill(FVM('year', '#BDB7AD', ['before' => 'Year '])), $pill(FVM('country_short', '#BDB7AD')), $pill(FVM('service', '#BDB7AD'))], '8px', [], ['wrap' => 'wrap'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']]),
        ], '18px'), '36%'),
    ], '40px', ['margin' => ['top' => '30px', 'bottom' => '44px']], ['justify' => 'between', 'align' => 'end']),
    B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [$frame, $badge('Launched', 'year', '', 'l'), $badge('PageSpeed', 'pagespeed', '', 'r')], ['position' => ['mode' => 'relative'], 'padding' => ['bottom' => '70px']], ['classNames' => ['rk-stage']]),
], ['padding' => ['top' => '130px', 'right' => '28px', 'bottom' => '0px', 'left' => '28px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '0px', 'content_width' => 'boxed', 'content_max' => '1440px'],
    'background' => ['gradient' => 'radial-gradient(60% 50% at 90% 0%, rgba(255,90,31,0.16) 0%, rgba(255,90,31,0) 62%)']],
    ['interactions' => THEME('ink'), 'classNames' => ['rk-case-hero'], 'responsive' => ['mobile' => ['style' => ['padding' => ['top' => '100px', 'right' => '20px', 'bottom' => '0px', 'left' => '20px']]]]]);

// 2. The stack, scrolling across an orange band.
$stack = CTOP('Stack', [FV('stack', INK, '28px', 'div', ['typography' => ['weight' => '300', 'letter_spacing' => '-0.6px', 'line_height' => '1.2']], ['list_style' => 'ticker', 'ticker_speed' => 3], ['classNames' => ['rk-stack']])],
    ['background' => ['color' => ORANGE], 'padding' => ['top' => '18px', 'right' => '0px', 'bottom' => '18px', 'left' => '0px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'content_width' => 'full']], ['when_field' => 'stack']);

// 3. The facts, as cards; the live site's is dark.
$factCard = static fn(string $label, array $value, bool $dark = false, string $when = ''): array => VBOX([EYE($label, $dark ? MUTED : MUTED_BONE), $value], '10px',
    ['background' => ['color' => $dark ? INK : 'rgba(255,255,255,0.45)'], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => $dark ? INK : LINE_BONE, 'radius' => '16px'], 'padding' => ['top' => '16px', 'right' => '18px', 'bottom' => '18px', 'left' => '18px'], 'layout' => ['justify' => 'between'], 'dimensions' => ['min_height' => '150px']],
    ['when_field' => $when, 'classNames' => $dark ? ['rk-fact', 'rk-fact-live'] : ['rk-fact']]);
$facts = SEC('Facts', [B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'wrap', 'align' => 'stretch'], [
    $factCard('Client', FV('client', INK, '17px'), false, 'client'),
    $factCard('Country', FV('country', INK, '17px'), false, 'country'),
    $factCard('Stack', FV('stack', INK, '16px', 'div', ['typography' => ['line_height' => '1.45']], [], ['classNames' => ['rk-inline-list']]), false, 'stack'),
    $factCard('Live site', FV('live', BONE, '17px', 'div', [], ['url_label' => 'domain', 'link_arrow' => true], ['style_parts' => ['link' => ['color' => BONE]]]), true, 'live'),
], ['layout' => ['display' => 'grid', 'columns' => 4, 'gap' => '12px']], ['responsive' => ['tablet' => ['style' => ['layout' => ['columns' => 2]]], 'mobile' => ['style' => ['layout' => ['columns' => 1]]]]])],
    '40px', '20px', [], ['interactions' => THEME('bone')], '32px', '12px');

// 4. The story: a chapter list that follows the reader and the phone screenshot beside three chapters.
$chapter = static fn(int $n, string $title, string $key): array => VBOX([
    EYE(NUM($n) . ' — ' . $title, RUST),
    FV($key, INK, '24px', 'div', ['typography' => ['weight' => '300', 'line_height' => '1.45', 'letter_spacing' => '-0.3px']], [], ['style_parts' => ['paragraph' => ['typography' => ['size' => '24px', 'line_height' => '1.45']]],
        'responsive' => ['mobile' => ['style_parts' => ['paragraph' => ['typography' => ['size' => '19px']]]]]]),
], '14px', ['padding' => ['bottom' => '56px']], ['attributes' => ['id' => 'chapter-' . $n], 'when_field' => $key]);
$chapterNav = B('core.nav_menu', ['menu' => '', 'items' => [['label' => 'The client', 'url' => '#chapter-1'], ['label' => 'The challenge', 'url' => '#chapter-2'], ['label' => 'What I built', 'url' => '#chapter-3']],
    'layout' => 'vertical', 'align' => 'left', 'aria_label' => 'Chapters', 'toggle_breakpoint' => 'none', 'numbered' => true, 'divider' => true], [],
    ['typography' => ['size' => '15px', 'font_family' => SANS, 'weight' => '400']],
    ['interactions' => ['spy' => true], 'classNames' => ['rk-chapters'], 'style_parts' => [
        'link' => ['color' => MUTED_BONE, 'padding' => ['top' => '12px', 'right' => '0px', 'bottom' => '12px', 'left' => '0px']],
        'current' => ['color' => INK], 'number' => ['color' => RUST],
        'item' => ['border' => ['width' => '1px', 'style' => 'solid', 'color' => LINE_BONE, 'sides' => 'bottom']],
    ]]);
$story = SEC('Story', [ROW([
    W(VBOX([$chapterNav, FV('phone', INK, '16px', 'div', ['margin' => ['top' => '24px']], ['fit' => 'cover'], ['classNames' => ['rk-thumb']])], '0px', [], ['interactions' => ['sticky' => ['edge' => 'top', 'offset' => 110]], 'devices' => ['md', 'lg']]), '24%'),
    W(VBOX([$chapter(1, 'The client', 'the_client'), $chapter(2, 'The challenge', 'challenge'), $chapter(3, 'What I built', 'built')], '0px'), '68%'),
], '48px', [], ['justify' => 'between', 'align' => 'start'])], '30px', '60px', [], ['interactions' => THEME('bone')], '24px', '40px');

// 5. On every screen: the desktop and phone screenshots, with the turning badge.
$screens = SEC('On every screen', [
    $headRow('04', 'On every screen', 'navy'),
    ROW([
        W(FV('screen', BONE, '16px', 'div', [], ['fit' => 'cover', 'alt_field' => 'screen_alt'], ['classNames' => ['rk-show-a']]), '66%'),
        W(VBOX([
            FV('phone', BONE, '16px', 'div', [], ['fit' => 'cover'], ['classNames' => ['rk-show-b'], 'when_field' => 'phone']),
            B('core.circle_text', ['text' => 'Designed · Built · Launched · By Rakib · ', 'icon' => 'none', 'size' => 130, 'font_size' => 10, 'spin_seconds' => 18, 'direction' => 'clockwise'], [], ['color' => PERI], ['classNames' => ['rk-spin']]),
        ], '24px'), '30%'),
    ], '24px', [], ['justify' => 'between', 'align' => 'start']),
], '110px', '110px', [], ['interactions' => THEME('navy'), 'when_field' => 'screen']);

// 6. The result, and the numbers it came with.
// A number in a small card: the figure with its unit, and what it counts.
$metric = static fn(string $key, string $unit, string $label): array => ROW([
    FV($key, BONE, '40px', 'div', ['typography' => ['weight' => '200', 'line_height' => '1', 'letter_spacing' => '-1.6px']], ['after' => $unit],
        ['style_parts' => ['affix' => ['typography' => ['size' => '15px', 'letter_spacing' => '0px'], 'color' => MUTED]], 'classNames' => ['rk-metric-v']]),
    P($label, MUTED, '12px', ['line_height' => '1.35']),
], '14px', ['padding' => ['top' => '16px', 'right' => '18px', 'bottom' => '16px', 'left' => '18px'], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => LINE, 'radius' => '14px'], 'margin' => ['bottom' => '10px']],
    ['align' => 'end'], ['layout' => ['direction' => 'row', 'align' => 'end']], ['when_field' => $key]);
$result = SEC('The result', [
    $headRow('05', 'The result', 'ink'),
    ROW([
        W(FV('result', BONE, '26px', 'div', ['typography' => ['weight' => '300', 'line_height' => '1.45', 'letter_spacing' => '-0.4px']], [], ['style_parts' => ['paragraph' => ['typography' => ['size' => '26px', 'line_height' => '1.45']]],
            'responsive' => ['mobile' => ['style_parts' => ['paragraph' => ['typography' => ['size' => '20px']]]]]]), '58%'),
        W(VBOX([$metric('weeks', ' weeks', 'From first call to launch'), $metric('pagespeed', '/100', 'Mobile PageSpeed at launch'), $metric('pages', ' pages', 'Designed and built')], '0px'), '34%'),
    ], '48px', [], ['justify' => 'between', 'align' => 'start']),
], '110px', '110px', [], ['interactions' => THEME('ink'), 'when_field' => 'result']);

// 7. In the client's words.
$quote = SEC('In the client\'s words', [
    EYE('06 — In the client\'s words', '#4A1806'),
    P('“', INK, '120px', ['weight' => '200', 'line_height' => '0.8'], ['classNames' => ['rk-qmark']]),
    FV('quote', INK, '44px', 'div', ['typography' => ['weight' => '300', 'line_height' => '1.18', 'letter_spacing' => '-1.4px'], 'dimensions' => ['max_width' => '1100px']], [], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => '28px']]]]]),
    ROW([FV('quote_by', INK, '16px', 'div', ['typography' => ['weight' => '500']]), P('★★★★★', INK, '14px')], '14px', ['margin' => ['top' => '26px']], ['align' => 'center'], []),
], '110px', '110px', [], ['interactions' => THEME('orange'), 'when_field' => 'quote']);

// 8. Your turn.
$turn = SEC('Your turn', [ROW([
    W(VBOX([PIC('7e977488fb738bdc0dafb257c02f8d16.webp', 'Rakib Hasan having coffee', '420px', '300px', '20px'), EYE('Rakib Hasan · Dhaka', MUTED_BONE)], '12px'), '38%'),
    W(VBOX([
        EYE('07 — Your turn', MUTED_BONE),
        B('core.heading', ['text' => 'Want a site like this?', 'level' => 'h2'], [], ['color' => INK, 'typography' => ['size' => '64px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '1.02', 'letter_spacing' => '-3px']],
            ['dynamic' => ['text' => ['tag' => 'post_title', 'before' => 'Want a site like ', 'after' => '?']], 'responsive' => ['mobile' => ['style' => ['typography' => ['size' => '38px']]]]]),
        P('Send me your current site or your idea. I\'ll review it by hand and reply within 12 hours with what I\'d change first.', MUTED_BONE, '18px'),
        ROW([PILL('Get a free audit  →', '/free-audit/'), PILL('See all work', '/work/', 'rgba(0,0,0,0)', INK)], '10px', ['margin' => ['top' => '10px']], ['wrap' => 'wrap'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']]),
    ], '18px'), '56%'),
], '48px', [], ['justify' => 'between', 'align' => 'center'])], '110px', '110px', [], ['interactions' => THEME('bone')]);

// 9. The next project.
$next = SEC('Next project', [
    EYE('Next project', MUTED),
    B('core.heading', ['text' => 'Next project', 'level' => 'h2', 'url' => '/work/'], [], ['color' => BONE, 'typography' => ['size' => '96px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '1', 'letter_spacing' => '-4.8px'], 'margin' => ['top' => '14px']],
        ['dynamic' => ['text' => ['tag' => 'next_post_title', 'after' => ' →'], 'url' => ['tag' => 'next_post_url']], 'responsive' => ['mobile' => ['style' => ['typography' => ['size' => '48px']]]], 'classNames' => ['rk-next']]),
], '110px', '120px', [], ['interactions' => THEME('ink')]);

build_page('template-single-project', 'Single: Project', 'system', 'standalone', [$hero, $stack, $facts, $story, $screens, $result, $quote, $turn, $next],
    ['settings' => ['template_type' => 'single', 'conditions' => ['rules' => [['action' => 'include', 'condition' => 'singular', 'value' => 'project']]]]]);

// The card the Query Loops draw: the screenshot with its industry, the name and where and when, the summary.
$cardTpl = CTOP('Card', [
    B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [
        B('theme.post_image', [], [], ['dimensions' => ['height' => '300px', 'width' => '100%', 'overflow' => 'hidden'], 'border' => ['radius' => '16px']], ['classNames' => ['rk-pcard-img']]),
        FVM('industry', INK, [], ['background' => ['color' => BONE], 'padding' => ['top' => '6px', 'right' => '11px', 'bottom' => '6px', 'left' => '11px'], 'border' => ['radius' => '999px'], 'position' => ['mode' => 'absolute', 'top' => '14px', 'left' => '14px']]),
    ], ['position' => ['mode' => 'relative']]),
    ROW([B('theme.post_title', ['level' => 'h3', 'link_to_post' => true], [], ['color' => INK, 'typography' => ['size' => '28px', 'font_family' => SANS, 'weight' => '300', 'line_height' => '1.15', 'letter_spacing' => '-0.8px']]),
        ROW([FVM('country_short', MUTED_BONE), FVM('year', MUTED_BONE, ['before' => '· '])], '6px', ['dimensions' => ['width' => 'auto']], [], [])], '12px', ['margin' => ['top' => '16px']], ['justify' => 'between', 'align' => 'baseline'], []),
    FV('summary', MUTED_BONE, '15px', 'p', ['typography' => ['line_height' => '1.5']]),
], ['layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '6px', 'content_width' => 'full', 'padding_y' => 'none']], ['classNames' => ['rk-pcard']]);
build_page('template-loop-project', 'Loop Item: Project', 'system', 'standalone', [$cardTpl], ['settings' => ['template_type' => 'loop', 'conditions' => ['rules' => [['action' => 'include', 'condition' => 'singular', 'value' => 'project']]]]]);

// The items, newest first in the list (published in reverse so the list order matches the original's). The six on
// the home page are featured; the numbers are the original's per-project figures.
$rich = static fn(array $paras): string => implode('', array_map(static fn(string $h): string => '<p>' . $h . '</p>', $paras));
// The six on the home page, with the short line it shows under each.
$HOME = ['cdg-maui' => 'Design-build firm on Maui · USA · 2024', 'redkey-logistics' => 'Freight with 50,000 vetted carriers · USA · 2023', 'safi-cleaners' => 'House cleaning across Hampden County · USA · 2023',
    'villa-lapierrot' => 'Five-bedroom villa in Provence · 2023', 'sergio-ardila-law' => 'Bilingual injury law firm in Atlanta · 2023', 'tatiana-megard' => 'Avant-garde fashion portfolio · Belgium · 2023'];
foreach (array_reverse($DATA['cases']) as $i => $p) {
    $testi = trim(strip_tags(implode(' ', $p['p_testi'])));
    $testi = trim((string) preg_replace('/^&gt;\s*|^>\s*/', '', html_entity_decode($testi, ENT_QUOTES)));
    [$q, $by] = str_contains($testi, ' — ') ? array_map('trim', explode(' — ', $testi, 2)) : [$testi, ''];
    $h = array_sum(array_map('ord', str_split($p['slug'])));
    build_page($p['slug'], $p['name'], 'page', 'standalone', [CTOP('Body', [P($p['summary'], BONE, '18px')], ['layout' => ['display' => 'flex', 'direction' => 'column']])], [
        'seo_title' => $p['title'] ?: $p['name'] . ' | Rakib Hasan', 'seo_desc' => $p['summary'], 'image' => MFILE($p['img']),
        'settings' => ['category' => $p['industry'], 'excerpt' => $p['summary'], 'featured' => isset($HOME[$p['slug']])], 'date' => date('Y-m-d H:i:s', strtotime('2026-01-01') + $i * 3600),
        'type' => 'project', 'fields' => [
            'industry' => [$p['industry']], 'h1' => $p['h1'] ?: $p['name'], 'summary' => $p['summary'],
            'client' => $p['client'], 'country' => $p['country'], 'country_short' => $p['cshort'], 'year' => $p['year'], 'service' => $p['service'],
            'stack' => implode("\n", array_filter(array_map('trim', explode(',', (string) $p['stack'])))), 'live' => $p['live'],
            'screen' => MEDIA_DIR . MFILE($p['img']), 'phone' => MEDIA_DIR . MFILE($p['img2']), 'screen_alt' => $p['alt'] ?: $p['name'] . ' website',
            'the_client' => $rich($p['p_client']), 'challenge' => $rich($p['p_challenge']), 'built' => $rich($p['p_built']), 'result' => $rich($p['p_result']),
            'weeks' => (string) (3 + $h % 4), 'pagespeed' => (string) (89 + $h % 9), 'pages' => (string) (6 + $h % 13),
            'quote' => trim($q, " \"“”"), 'quote_by' => $by ?: $p['client'],
            'tagline' => $HOME[$p['slug']] ?? '', 'color' => $INDUSTRY[$p['industry']] ?? '',
        ],
    ]);
}
say('  + ' . count($DATA['cases']) . ' projects');
