<?php
// The inner pages, from data/pages.json (build/export.py writes it from the original's copy with the original's parser).
// Each section kind of the original generator has a builder here; each copy block (paragraph, tiles, steps, prices,
// FAQ...) becomes native blocks in the section's theme colours.
declare(strict_types=1);

use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PageRoutes;

if (!function_exists('say')) { require __DIR__ . '/lib.php'; }

$DATA = json_decode((string) file_get_contents($BUNDLE . '/data/pages.json'), true, 512, JSON_THROW_ON_ERROR);
const PROJECT_ROUTE = '/case-study';

/** A theme's colours: text, muted text, lines, accent. */
function TC(string $theme): array
{
    return match ($theme) {
        'bone' => ['fg' => INK, 'muted' => MUTED_BONE, 'line' => LINE_BONE, 'accent' => RUST, 'card' => 'rgba(11,11,12,0.04)'],
        'navy' => ['fg' => '#E9ECF5', 'muted' => '#9AA6C4', 'line' => '#24335A', 'accent' => PERI, 'card' => 'rgba(233,236,245,0.05)'],
        'orange' => ['fg' => INK, 'muted' => '#4A1806', 'line' => '#E04A12', 'accent' => INK, 'card' => 'rgba(11,11,12,0.06)'],
        default => ['fg' => BONE, 'muted' => MUTED, 'line' => LINE, 'accent' => ORANGE, 'card' => 'rgba(237,232,223,0.04)'],
    };
}
/** A media file by its name without the extension (the original's blob ids). */
function MFILE(string $hash): string
{
    global $BUNDLE;
    $hit = glob($BUNDLE . '/media/' . $hash . '.*') ?: [];
    if ($hit === []) { throw new RuntimeException("No media {$hash}"); }
    return basename($hit[0]);
}
function NUM(int $n): string { return str_pad((string) $n, 2, '0', STR_PAD_LEFT); }
function RICH(string $html, string $color, string $size = '18px', array $opt = [], string $weight = '400'): array
{
    $px = (float) $size;
    if (str_ends_with($size, 'px') && $px >= 20 && !isset($opt['responsive']['mobile']['style']['typography']['size'])) {
        $opt['responsive']['mobile']['style']['typography']['size'] = max(16, (int) round($px * 0.78)) . 'px';
    }
    $opt['classNames'] = array_values(array_unique(array_merge(['rk-rich'], $opt['classNames'] ?? [])));
    return B('core.rich_text', ['content' => $html], [], ['color' => $color, 'typography' => ['size' => $size, 'font_family' => SANS, 'weight' => $weight, 'line_height' => '1.6']], $opt);
}
function CARD(array $kids, array $c, string $gap = '14px', array $more = []): array
{
    return VBOX($kids, $gap, array_replace_recursive(['background' => ['color' => $c['card']], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => $c['line'], 'radius' => '20px'], 'padding' => ['top' => '26px', 'right' => '26px', 'bottom' => '26px', 'left' => '26px']], $more));
}
/**
 * Cards in a grid of up to `$per` columns that wraps to fewer as the space narrows: each column is at least a card's
 * readable width, so a narrow column (a two-column section on a laptop) never squeezes a title out of its card.
 */
function GRID(array $cards, int $per, string $gap = '16px'): array
{
    $min = [1 => 320, 2 => 280, 3 => 220, 4 => 200][min(4, max(1, $per))] ?? 220;
    return B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'wrap', 'align' => 'stretch'], $cards,
        ['layout' => ['display' => 'grid', 'tracks' => 'repeat(auto-fit, minmax(' . $min . 'px, 1fr))', 'gap' => $gap]], ['classNames' => ['rk-grid', 'rk-grid-' . $per]]);
}
function BTNS(array $items, array $c): array
{
    $kids = [];
    foreach ($items as $b) {
        $kids[] = $b['primary'] ? PILL($b['label'] . '  →', $b['href'], ORANGE === $c['accent'] || RUST === $c['accent'] ? ORANGE : INK, ORANGE === $c['accent'] || RUST === $c['accent'] ? INK : BONE)
            : B('core.button', ['link' => L($b['label'] . '  →', $b['href']), 'variant' => 'outline'], [], [], ['style_parts' => ['button' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'color' => $c['fg'], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => $c['line'], 'radius' => '999px'], 'padding' => ['top' => '13px', 'right' => '22px', 'bottom' => '13px', 'left' => '22px'], 'typography' => ['size' => '15px', 'weight' => 'medium', 'family' => 'body']]]]);
    }
    return ROW($kids, '10px', ['margin' => ['top' => '8px']], ['wrap' => 'wrap'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']]);
}

/** One copy block in a theme. */
function BLK(array $b, array $c, bool $lead = false): array
{
    switch ($b['k']) {
        case 'p':
            return RICH('<p>' . $b['html'] . '</p>', $lead ? $c['fg'] : $c['muted'], $lead ? '24px' : '17px', [], $lead ? '300' : '400');
        case 'note':
            return RICH('<p>' . $b['html'] . '</p>', $c['muted'], '13px');
        case 'link':
            return B('core.link', ['link' => L($b['label'], $b['href']), 'style' => 'arrow'], [], ['color' => $c['fg'], 'typography' => ['size' => '15px', 'weight' => '500']]);
        case 'btns':
            return BTNS($b['items'], $c);
        case 'badge':
            return ROW(array_map(static fn(string $t): array => P($t, $c['fg'], '13px', [], ['classNames' => ['rk-chip']]), $b['items']), '8px', [], ['wrap' => 'wrap'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']]);
        case 'faq':
            return B('layout.accordion', ['allow_multiple' => false, 'look' => 'lines', 'icon' => 'plus'], array_map(static fn(array $f): array => B('layout.accordion_item', ['title' => $f['q'], 'open' => false], [RICH('<p>' . $f['a'] . '</p>', $c['muted'], '16px')]), $b['items']),
                ['color' => $c['fg'], 'typography' => ['size' => '19px', 'font_family' => SANS, 'weight' => '400']], ['classNames' => ['rk-faq']]);
        case 'tiles':
            return GRID(array_map(static fn(array $t, int $i): array => CARD([EYE(NUM($i + 1), $c['accent']), P($t['h'], $c['fg'], '21px', ['weight' => '500', 'line_height' => '1.25']), RICH('<p>' . $t['html'] . '</p>', $c['muted'], '15px')], $c, '10px'), $b['items'], array_keys($b['items'])), count($b['items']) % 3 === 0 ? 3 : 2);
        case 'checks':
            return VBOX(array_map(static fn(string $h, int $i): array => ROW([
                P('✓', $c['accent'], '15px', ['weight' => '600'], ['classNames' => ['rk-tick']]),
                W(RICH('<p>' . $h . '</p>', $c['fg'], '16px'), '100%'),
                EYE(NUM($i + 1), $c['muted'], 'right'),
            ], '14px', ['padding' => ['top' => '14px', 'bottom' => '14px'], 'border' => ['width' => '0 0 1px 0', 'style' => 'solid', 'color' => $c['line']]], ['align' => 'center'], []), $b['items'], array_keys($b['items'])), '0px');
        case 'steps':
            return B('layout.timeline', ['line_color' => $c['line'], 'fill_color' => $c['accent'], 'numbered' => true], array_map(static fn(array $s): array => VBOX(array_values(array_filter([
                $s['h'] !== '' ? P($s['h'], $c['fg'], '20px', ['weight' => '500']) : null,
                RICH('<p>' . $s['html'] . '</p>', $c['muted'], '16px'),
            ])), '6px'), $b['items']), ['color' => $c['fg']]);
        case 'prices':
            return GRID(array_map(static fn(array $p, int $i): array => CARD(array_values(array_filter([
                $p['feat'] ? P('Most chosen', INK, '11px', ['font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1.2px'], ['classNames' => ['rk-chip-on']]) : null,
                EYE('Package ' . NUM($i + 1), $p['feat'] ? MUTED : $c['muted']),
                P($p['name'], $p['feat'] ? BONE : $c['fg'], '30px', ['weight' => '300', 'letter_spacing' => '-1px', 'line_height' => '1.1']),
                RICH('<p>' . $p['html'] . '</p>', $p['feat'] ? 'rgba(237,232,223,.75)' : $c['muted'], '15px'),
                P($p['price'], $p['feat'] ? BONE : $c['fg'], '34px', ['weight' => '200', 'letter_spacing' => '-1.4px']),
                $p['feat'] ? PILL($p['cta'] . '  →', '/free-audit/') : B('core.link', ['link' => L($p['cta'], '/free-audit/'), 'style' => 'arrow'], [], ['color' => $c['fg'], 'typography' => ['size' => '15px', 'weight' => '500']]),
            ])), $c, '14px', $p['feat'] ? ['background' => ['color' => INK], 'border' => ['color' => INK]] : []), $b['items'], array_keys($b['items'])), min(3, count($b['items'])));
        case 'pricelist':
            return VBOX([
                ROW([EYE($b['head'][0], $c['muted']), EYE($b['head'][1], $c['muted'], 'right')], '12px', ['padding' => ['bottom' => '12px'], 'border' => ['width' => '0 0 1px 0', 'style' => 'solid', 'color' => $c['line']]], ['justify' => 'between'], []),
                ...array_map(static fn(array $r): array => ROW([P($r[0], $c['fg'], '17px'), P($r[1], $c['fg'], '17px', ['weight' => '500'])], '12px', ['padding' => ['top' => '14px', 'bottom' => '14px'], 'border' => ['width' => '0 0 1px 0', 'style' => 'solid', 'color' => $c['line']]], ['justify' => 'between'], []), $b['rows']),
                B('core.link', ['link' => L('Get an exact quote after a free audit', '/free-audit/'), 'style' => 'arrow'], [], ['color' => $c['fg'], 'margin' => ['top' => '12px'], 'typography' => ['size' => '15px', 'weight' => '500']]),
            ], '0px');
        case 'picker':
            return B('layout.tabs', ['position' => 'left', 'look' => 'list', 'autoplay_ms' => 0, 'aria_label' => $b['head'][0]], array_map(static fn(array $r): array => B('layout.tab', ['title' => $r['q'], 'subtitle' => ''], [
                CARD([EYE($b['head'][1], MUTED), P($r['a'], BONE, '40px', ['weight' => '200', 'letter_spacing' => '-1.6px', 'line_height' => '1.05']), P($r['q'], 'rgba(237,232,223,.7)', '16px'), PILL('See ' . explode(' or ', $r['a'])[0] . ' work  →', $r['href'])], TC('ink'), '16px', ['background' => ['color' => INK]]),
            ]), $b['rows']), ['color' => $c['fg']], ['classNames' => ['rk-picker']]);
        case 'specs':
            return GRID(array_map(static fn(array $r): array => CARD([P($r[0], $c['fg'], '20px', ['weight' => '500']), ...array_map(static fn(string $h, string $v): array => VBOX([EYE($h, $c['muted']), P($v, $c['fg'], '15px')], '4px'), array_slice($b['head'], 1), array_slice($r, 1))], $c, '12px'), $b['rows']), min(3, count($b['rows'])));
        case 'quote':
            return CARD([P('“', $c['accent'], '56px', ['line_height' => '0.6', 'weight' => '300']), P($b['text'], $c['fg'], '19px', ['line_height' => '1.45']), EYE($b['who'], $c['muted'])], $c, '14px');
    }
    throw new RuntimeException('Unknown block ' . $b['k']);
}
function BLKS(array $blocks, array $c, bool $lead = false): array
{
    $out = [];
    foreach ($blocks as $i => $b) { $out[] = BLK($b, $c, $lead && $i === 0); }
    return $out;
}
function LABEL(int $n, array $c): array { return EYE(NUM($n), $c['muted']); }
function H2(string $t, array $c, string $size = '56px', string $msize = '36px'): array
{
    return B('core.heading', ['text' => $t, 'level' => 'h2'], [], ['color' => $c['fg'], 'typography' => ['size' => $size, 'font_family' => SANS, 'weight' => '200', 'line_height' => '1.02', 'letter_spacing' => round(-0.045 * (float) $size, 1) . 'px']], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => $msize]]]], 'interactions' => ['text' => ['effect' => 'lines', 'step_ms' => 80]]]);
}

// ── Section kinds ───────────────────────────────────────────────────────────────
function S_SECTION(array $s): array
{
    $c = TC($s['theme']);
    if ($s['subs']) {
        $cards = array_map(static fn(array $sub, int $k): array => CARD(array_values(array_filter([
            EYE(NUM($k + 1), $c['muted']), P($sub['title'], $c['fg'], '24px', ['weight' => '400', 'line_height' => '1.2', 'letter_spacing' => '-0.6px']),
            ...BLKS($sub['blocks'], $c),
            $sub['link'] ? B('core.link', ['link' => L($sub['link']['label'], $sub['link']['href']), 'style' => 'arrow'], [], ['color' => $c['accent'], 'typography' => ['size' => '15px', 'weight' => '500']]) : null,
        ])), $c), $s['subs'], array_keys($s['subs']));
        return SEC($s['title'], [
            ROW([W(VBOX([LABEL($s['n'], $c), H2($s['title'], $c)], '18px'), '55%'), $s['blocks'] ? W(VBOX(BLKS($s['blocks'], $c), '14px'), '40%') : null], '40px', ['margin' => ['bottom' => '48px']], ['justify' => 'between', 'align' => 'end']),
            GRID($cards, count($cards) % 3 === 0 || count($cards) > 4 ? 3 : 2),
        ], '120px', '120px', [], ['interactions' => THEME($s['theme'])]);
    }
    if ($s['quotes']) {
        $quotes = array_values(array_filter($s['blocks'], static fn(array $b): bool => $b['k'] === 'quote'));
        $rest = array_values(array_filter($s['blocks'], static fn(array $b): bool => $b['k'] !== 'quote'));
        return SEC($s['title'], [
            VBOX([LABEL($s['n'], $c), H2($s['title'], $c)], '18px', ['margin' => ['bottom' => '48px']]),
            GRID(BLKS($quotes, $c), min(3, count($quotes))),
            $rest ? VBOX(BLKS($rest, $c), '14px', ['margin' => ['top' => '32px']]) : null,
        ], '120px', '120px', [], ['interactions' => THEME($s['theme'])]);
    }
    return SEC($s['title'], [
        ROW([
            W(VBOX([LABEL($s['n'], $c), H2($s['title'], $c, '52px')], '18px'), '38%'),
            W(VBOX(BLKS($s['blocks'], $c, $s['lead']), '18px'), '56%'),
        ], '48px', [], ['justify' => 'between']),
    ], '120px', '120px', [], ['interactions' => THEME($s['theme'])]);
}
function S_STATEMENT(array $s): array
{
    $c = TC($s['theme']);
    return SEC($s['title'], [
        ROW([LABEL($s['n'], $c), P($s['title'], $c['fg'], '15px', ['weight' => '500']), B('core.divider', ['weight' => 'thin'], [], ['color' => $c['line'], 'dimensions' => ['width' => '100%']])], '18px', ['margin' => ['bottom' => '40px']], ['align' => 'center'], [], ['classNames' => ['rk-label-row']]),
        ROW([
            W(B('core.text', ['content' => $s['lead'], 'size' => 'inherit'], [], ['color' => $c['fg'], 'typography' => ['size' => '46px', 'font_family' => SANS, 'weight' => '300', 'line_height' => '1.15', 'letter_spacing' => '-1.4px']], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => '28px']]]], 'interactions' => ['text' => ['effect' => 'light_words', 'dim' => 18]]]), '62%'),
            W(VBOX(BLKS($s['rest'], $c), '14px', ['padding' => ['top' => '10px']]), '32%'),
        ], '48px', [], ['justify' => 'between']),
    ], '120px', '120px', [], ['interactions' => THEME($s['theme'])]);
}
function S_BENTO(array $s): array
{
    $c = TC($s['theme']);
    $cards = array_map(static fn(array $card, int $i): array => CARD([
        ROW([EYE(NUM($s['n'] + $i), $c['muted']), P('✦', $c['accent'], '16px', [], [])], '8px', [], ['justify' => 'between'], []),
        P($card['title'], $c['fg'], '30px', ['weight' => '300', 'letter_spacing' => '-1px', 'line_height' => '1.1']),
        ...BLKS($card['blocks'], $c),
    ], $c, '14px', ['padding' => ['top' => '30px', 'right' => '30px', 'bottom' => '34px', 'left' => '30px'], 'border' => ['radius' => '24px']]), $s['cards'], array_keys($s['cards']));
    return SEC('Highlights', [GRID($cards, min(count($cards), 2) === 2 && count($cards) !== 3 ? 2 : 3, '18px')], '110px', '110px', [], ['interactions' => THEME($s['theme']) + ['stagger' => ['step_ms' => 90]]]);
}
function S_CTA(array $s, bool $form = false): array
{
    $c = TC('orange');
    return SEC($s['title'], [
        B('core.heading', ['text' => $s['title'], 'level' => 'h2'], [], ['color' => INK, 'typography' => ['size' => '110px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '0.95', 'letter_spacing' => '-6px'], 'dimensions' => ['max_width' => '1100px']], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => '52px']]]], 'interactions' => ['text' => ['effect' => 'lines', 'step_ms' => 110]]]),
        ROW([
            W(VBOX($form ? [...BLKS($s['blocks'], $c), AUDITFORM('Your trade, your area, your top five fixes.', true)] : BLKS($s['blocks'], $c), '16px'), '60%'),
            $form ? null : PILL('Start here', '/free-audit/', INK, BONE, ['classNames' => ['rk-magnet'], 'interactions' => ['mouse' => ['magnet' => 6]], 'devices' => ['md', 'lg']]),
        ], '40px', ['margin' => ['top' => '48px']], ['justify' => 'between', 'align' => 'center']),
    ], '150px', '130px', [], ['interactions' => THEME('orange')]);
}
function S_BAND(array $s): array
{
    return CTOP('Photo', [
        B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [
            PIC(MFILE($s['img']), $s['alt'], '620px', '360px', '24px'),
            // The caption: a small frosted card on the photo's lower-left corner — the face, the name, the line under it.
            ROW([
                B('core.image', ['media' => IMGREF(MFILE('dc5af924d95ea0c65e0bccbd61ccba59'), 'Rakib Hasan'), 'aspect_ratio' => '1:1', 'rounded' => false], [], ['dimensions' => ['width' => '44px'], 'border' => ['radius' => '12px']], ['classNames' => ['rk-band-face']]),
                VBOX([P('Rakib Hasan', BONE, '15px', ['weight' => '500', 'line_height' => '1.2']), EYE($s['cap'], '#BDB7AD')], '4px', ['dimensions' => ['width' => 'auto']]),
            ], '12px', ['background' => ['color' => 'rgba(11,11,12,0.62)'], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => 'rgba(237,232,223,0.14)', 'radius' => '16px'], 'padding' => ['top' => '10px', 'right' => '18px', 'bottom' => '10px', 'left' => '10px'], 'dimensions' => ['width' => 'auto']], ['align' => 'center'], ['layout' => ['direction' => 'row', 'align' => 'center']], ['classNames' => ['rk-band-cap']]),
        ], ['position' => ['mode' => 'relative']], ['classNames' => ['rk-band'], 'interactions' => ['scroll' => ['scale' => 2, 'devices' => ['desktop']]]]),
    ], ['padding' => ['top' => '20px', 'right' => '28px', 'bottom' => '40px', 'left' => '28px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'content_width' => 'boxed', 'content_max' => '1440px']],
        ['interactions' => THEME('ink'), 'responsive' => ['mobile' => ['style' => ['padding' => ['top' => '10px', 'right' => '16px', 'bottom' => '24px', 'left' => '16px']]]]]);
}
function S_WORLD(string $label): array
{
    $c = TC('navy');
    $countries = [['United States', 72], ['Belgium', 10], ['United Kingdom', 4], ['Azerbaijan', 3], ['Germany', 2], ['Nigeria', 2], ['New Zealand', 2], ['Canada', 1], ['Hungary', 1], ['Slovakia', 1], ['Denmark', 1], ['Latvia', 1], ['Vietnam', 1], ['Uganda', 'Direct']];
    $stat = static fn(string $n, string $l): array => VBOX([H($n, 'div', '48px', '36px', $c['fg']), EYE($l, $c['muted'])], '6px', ['dimensions' => ['width' => 'auto']]);
    return SEC($label, [
        EYE($label, $c['muted']),
        B('core.dual_heading', ['before' => '250+ websites, shipped from', 'colored' => 'one desk in Dhaka.', 'after' => '', 'level' => 'h2'], [], ['typography' => ['size' => '64px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '1', 'letter_spacing' => '-3px'], 'dimensions' => ['max_width' => '900px'], 'margin' => ['top' => '20px']],
            ['style_parts' => ['highlight' => ['color' => PERI]], 'responsive' => ['mobile' => ['style' => ['typography' => ['size' => '40px']]]]]),
        ROW([$stat('250+', 'Websites shipped'), $stat('14', 'Countries'), $stat('5', 'Continents'), $stat('101', 'Fiverr reviews')], '44px', ['margin' => ['top' => '32px', 'bottom' => '36px']], ['wrap' => 'wrap'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap', 'gap' => '28px']]),
        B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'wrap'], array_map(static fn(array $x): array => P($x[0] . '  ' . $x[1], $c['fg'], '13px', ['font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1px'], ['classNames' => ['rk-country']]), $countries), ['layout' => ['display' => 'flex', 'direction' => 'row', 'wrap' => 'wrap', 'gap' => '10px']], ['interactions' => ['stagger' => ['step_ms' => 50]]]),
    ], '110px', '110px', [], ['interactions' => THEME('navy')]);
}
/** The project marquee: two rows of case study cards drifting in opposite directions. */
function S_RECENT(array $cases): array
{
    $card = static fn(array $p, string $img): array => B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [
        B('core.image', ['media' => IMGREF(MFILE($img), $p['name'] . ' website'), 'aspect_ratio' => '4:3', 'rounded' => false, 'link_to' => 'custom', 'url' => PROJECT_ROUTE . '/' . $p['slug'] . '/'], [], ['dimensions' => ['width' => '100%'], 'border' => ['radius' => '18px']]),
        P($p['name'] . ' · ' . explode(' &', $p['industry'])[0], INK, '13px', ['font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1px'], ['style' => ['margin' => ['top' => '10px']]]),
    ], [], ['classNames' => ['rk-iq-card']]);
    $a = [];
    $b = [];
    foreach (array_values($cases) as $i => $p) {
        if ($i % 2 === 0) { $a[] = $card($p, $p['img']); } else { $b[] = $card($p, $p['img']); }
    }
    $row = static fn(array $cards, string $dir): array => B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'nowrap'], array_slice($cards, 0, 10), ['layout' => ['display' => 'flex', 'direction' => 'row', 'gap' => '18px']], ['interactions' => ['marquee' => ['speed' => 2, 'direction' => $dir, 'scroll' => true, 'pause' => true, 'width' => 320]]]);
    return CTOP('Recent work', [
        ROW([VBOX([EYE('Recent work', MUTED_BONE), H2('250+ sites, a few of them here', TC('bone'), '56px')], '14px'), B('core.link', ['link' => L('See all work →', '/work/'), 'style' => 'plain'], [], ['color' => MUTED_BONE, 'typography' => ['size' => '12px', 'font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1.2px']])],
            '20px', ['padding' => ['right' => '28px', 'left' => '28px'], 'margin' => ['bottom' => '40px']], ['justify' => 'between', 'align' => 'end']),
        $row($a, 'left'), $row($b, 'right'),
    ], ['padding' => ['top' => '110px', 'right' => '0px', 'bottom' => '130px', 'left' => '0px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '18px', 'content_width' => 'full']], ['interactions' => THEME('bone')]);
}
function AUDITFORM(string $note, bool $onOrange = false): array
{
    $fg = $onOrange ? INK : BONE;
    $line = $onOrange ? 'rgba(11,11,12,0.35)' : LINE;
    $f = static fn(string $name, string $label, string $type, bool $req = true, array $more = []): array => B('core.form_field', ['name' => $name, 'label' => $label, 'field_type' => $type, 'required' => $req] + $more);
    return VBOX([
        B('core.form', ['form_name' => 'free_audit', 'submit_text' => 'Send me my audit →', 'submit_variant' => 'primary', 'success_message' => 'Got it. I\'ll review your site by hand and email your top five fixes within 48 hours.', 'labels' => 'visible', 'layout' => 'stacked'], [
            $f('name', 'Your name', 'text', false), $f('email', 'Email', 'email'), $f('website', 'Website address', 'url', true, ['placeholder' => 'https://']),
            $f('goal', 'What would you most like your website to do better? (optional)', 'textarea', false),
        ], [], ['style_parts' => [
            'label' => ['color' => $fg, 'typography' => ['size' => '12px', 'transform' => 'uppercase', 'letter_spacing' => '1.2px']],
            'input' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'color' => $fg, 'border' => ['sides' => 'bottom', 'width' => '1px', 'style' => 'solid', 'color' => $line, 'radius' => '0px'], 'padding' => ['top' => '12px', 'right' => '2px', 'bottom' => '12px', 'left' => '2px'], 'typography' => ['size' => '18px']],
            'textarea' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'color' => $fg, 'border' => ['sides' => 'bottom', 'width' => '1px', 'style' => 'solid', 'color' => $line, 'radius' => '0px'], 'typography' => ['size' => '18px']],
            'button' => ['background' => ['color' => $onOrange ? INK : ORANGE], 'color' => $onOrange ? BONE : INK, 'border' => ['radius' => '999px', 'width' => '0px'], 'padding' => ['top' => '16px', 'right' => '28px', 'bottom' => '16px', 'left' => '28px'], 'typography' => ['size' => '16px', 'weight' => 'medium', 'family' => 'body']],
        ]]),
        P($note, $onOrange ? '#4A1806' : MUTED, '13px'),
    ], '10px');
}
function S_CONTACT(int $n): array
{
    $c = TC('bone');
    $f = static fn(string $name, string $label, string $type, bool $req, array $more = []): array => B('core.form_field', ['name' => $name, 'label' => $label, 'field_type' => $type, 'required' => $req] + $more);
    $form = B('core.form', ['form_name' => 'contact', 'submit_text' => 'Send message →', 'submit_variant' => 'primary', 'success_message' => 'Thanks, your message is on its way. I\'ll reply within 12 hours.', 'labels' => 'visible', 'layout' => 'stacked'], [
        $f('name', 'Your name', 'text', true, ['width' => '50']), $f('email', 'Email', 'email', true, ['width' => '50']),
        $f('website', 'Website address, if you have one', 'url', false, ['placeholder' => 'https://']),
        $f('need', 'What do you need?', 'select', false, ['width' => '50', 'options' => 'New website, Redesign, Custom plugin or app, SEO audit or fixes, Shopify, Wix or Squarespace, Branding or content, Something else']),
        $f('budget', 'Budget', 'select', false, ['width' => '50', 'options' => 'Under $1,000, $1,000–$3,000, $3,000–$7,500, $7,500+, Not sure yet']),
        $f('message', 'Tell me about the project', 'textarea', true),
    ], [], ['style_parts' => [
        'label' => ['color' => MUTED_BONE, 'typography' => ['size' => '12px', 'transform' => 'uppercase', 'letter_spacing' => '1.2px']],
        'input' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'color' => INK, 'border' => ['sides' => 'bottom', 'width' => '1px', 'style' => 'solid', 'color' => LINE_BONE, 'radius' => '0px'], 'typography' => ['size' => '18px']],
        'textarea' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'color' => INK, 'border' => ['sides' => 'bottom', 'width' => '1px', 'style' => 'solid', 'color' => LINE_BONE, 'radius' => '0px'], 'typography' => ['size' => '18px']],
        'button' => ['background' => ['color' => ORANGE], 'color' => INK, 'border' => ['radius' => '999px', 'width' => '0px'], 'padding' => ['top' => '16px', 'right' => '28px', 'bottom' => '16px', 'left' => '28px'], 'typography' => ['size' => '16px', 'weight' => 'medium', 'family' => 'body']],
    ]]);
    return SEC('Send a message', [ROW([
        W(VBOX([LABEL($n, $c), H2('Send a message', $c), ROW([B('core.image', ['media' => IMGREF(MFILE('dc5af924d95ea0c65e0bccbd61ccba59'), ''), 'aspect_ratio' => '1:1', 'rounded' => true], [], ['dimensions' => ['width' => '48px']]), VBOX([P('Rakib Hasan', INK, '16px'), EYE('Replies within about an hour', MUTED_BONE)], '2px')], '12px', ['margin' => ['top' => '12px']], ['align' => 'center'], [])], '18px'), '36%'),
        W(VBOX([$form, P('I\'ll only use your details to reply to you.', MUTED_BONE, '13px')], '10px'), '58%'),
    ], '48px', [], ['justify' => 'between'])], '120px', '120px', [], ['interactions' => THEME('bone')]);
}
function S_PRODUCTS(int $n): array
{
    $c = TC('ink');
    $card = static fn(string $tag, string $title, string $text, string $gh, array $chips): array => CARD(array_values(array_filter([
        ROW([P('● ' . $tag, MUTED, '11px', ['font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1px']), $gh !== '' ? B('core.link', ['link' => L('GitHub ↗', 'https://github.com/rakib6564/' . $gh), 'style' => 'plain'], [], ['color' => MUTED, 'typography' => ['size' => '11px', 'font_family' => MONO, 'transform' => 'uppercase']]) : null], '8px', [], ['justify' => 'between'], []),
        P($title, BONE, '34px', ['weight' => '200', 'letter_spacing' => '-1.4px']),
        P($text, 'rgba(237,232,223,.7)', '16px'),
        $chips ? ROW(array_map(static fn(string $t): array => P($t, BONE, '11px', ['font_family' => MONO, 'transform' => 'uppercase'], ['classNames' => ['rk-chip']]), $chips), '6px', [], ['wrap' => 'wrap'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']]) : null,
    ])), $c, '14px', ['border' => ['radius' => '22px']]);
    $stat = static fn(string $v, string $l): array => VBOX([H($v, 'div', '36px', '28px', BONE), EYE($l, MUTED)], '4px', ['dimensions' => ['width' => 'auto']]);
    return SEC('Products I build', [
        ROW([W(VBOX([EYE(NUM($n) . ' — Products I build', MUTED), B('core.dual_heading', ['before' => 'I don\'t only build for clients.', 'colored' => 'I ship my own software.', 'after' => '', 'level' => 'h2'], [], ['typography' => ['size' => '56px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '1.02', 'letter_spacing' => '-2.4px']], ['style_parts' => ['highlight' => ['color' => ORANGE]], 'responsive' => ['mobile' => ['style' => ['typography' => ['size' => '36px']]]]])], '16px'), '58%'),
            W(P('The same standards I bring to client plugins, proven on products I design, release and maintain myself: versioned releases, automated checks, documentation and real customers.', MUTED, '17px'), '36%')], '40px', ['margin' => ['bottom' => '28px']], ['justify' => 'between', 'align' => 'end']),
        ROW([$stat('641', 'Commits on Kohevo'), $stat('18', 'Active branches'), $stat('v1.6.2', 'Current release'), $stat('EN · FR', '3,358 translated strings')], '48px', ['padding' => ['top' => '18px', 'bottom' => '18px'], 'margin' => ['bottom' => '22px'], 'border' => ['width' => '1px 0 1px 0', 'style' => 'solid', 'color' => LINE]], ['wrap' => 'wrap'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap', 'gap' => '24px']]),
        GRID([
            $card('Platform · PHP 8.2', 'Kohevo', 'A white-label business platform. Website, customer portal, bookings, memberships, forms, coaching programmes and Stripe payments run in one branded system instead of five disconnected tools.', 'kohevo', ['PHP 8.2', 'MySQL', 'No build step', 'Multi-tenant', 'EN · FR']),
            $card('Licensing server · v1.6.2', 'Kohevo Licensing', 'The central authority behind every Kohevo install: products, plans, licences and module entitlements, with Ed25519-signed check-ins, a 7-day expiry warning and a 7-day grace period.', 'Kohevo-Licensing-Rebuild', []),
            $card('WordPress plugin · React', 'RK React Builder', 'A focused visual page builder for WordPress, shipped as one installable plugin. A React and TypeScript editor with reusable blocks, drafts, revisions and undo, rendered by PHP on the live site, with an optional headless mode.', 'RK-React-Builder', ['React', 'TypeScript', 'Vite', 'REST API', 'Headless mode']),
            $card('Private · in development', 'Elementor Forge', 'Tooling for faster, cleaner Elementor builds, in active private development.', '', []),
        ], 2, '14px'),
        ROW([P('Need a plugin, a licensing system or a custom builder of your own?', BONE, '17px'), PILL('Talk about your product  →', '/contact/')], '20px', ['margin' => ['top' => '28px']], ['justify' => 'between', 'align' => 'center']),
    ], '120px', '120px', [], ['interactions' => THEME('ink')]);
}
function S_REVIEWS(array $reviews, array $featured): array
{
    // Label, bar and count on one line, as the original's charts.
    $bars = static fn(array $rows): array => B('core.progress', ['items' => array_map(static fn(array $x): array => ['label' => preg_replace('/ · \d+$/', '', $x[0]), 'value' => (int) round($x[1] * 100 / 101), 'note' => (string) $x[1]], $rows),
        'show_value' => true, 'layout' => 'inline', 'bar_color' => ORANGE, 'track_color' => '#232120', 'height' => 4], [], ['color' => BONE, 'typography' => ['size' => '14px']], ['classNames' => ['rk-bars']]);
    $box = static fn(string $h, array $kids, array $item = []): array => CARD([EYE($h, MUTED), ...$kids], TC('ink'), '14px', ['layout' => $item]);
    $num = static fn(string $n, string $l): array => VBOX([P($n, BONE, '26px', ['weight' => '300']), EYE($l, MUTED)], '4px', ['dimensions' => ['width' => 'auto']]);
    $chip = static fn(string $t): array => P($t, BONE, '14px', [], ['classNames' => ['rk-chip']]);
    // The original's bento: the score card down the left, two charts, and the clients who came back across the bottom.
    $score = CARD([
        EYE('Fiverr · rakib_64 · Level 2', MUTED), H('4.99', 'div', '150px', '96px', BONE, ['responsive' => ['tablet' => ['style' => ['typography' => ['size' => '120px']]], 'mobile' => ['style' => ['typography' => ['size' => '96px']]]]]), P('★★★★★', ORANGE, '22px'),
        RICH('<p>Average of all <strong>101 reviews</strong>. 98 are five stars. Every category on the profile is rated 5: communication, quality and value.</p>', 'rgba(237,232,223,.75)', '16px'),
        ROW([$num('101', 'Reviews'), $num('13', 'Countries'), $num('1 hr', 'Avg. response'), $num('89', 'Replied to')], '28px',
            ['margin' => ['top' => 'auto'], 'padding' => ['top' => '22px'], 'border' => ['width' => '1px 0 0 0', 'style' => 'solid', 'color' => LINE]], ['wrap' => 'wrap', 'justify' => 'between'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']]),
    ], TC('ink'), '14px', ['layout' => ['row_span' => 2, 'justify' => 'between'], 'background' => ['gradient' => 'radial-gradient(120% 90% at 100% 0%, rgba(255,90,31,0.28) 0%, rgba(255,90,31,0.06) 45%, rgba(11,11,12,0) 70%)']]);
    $stats = SEC('Review stats', [B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'wrap', 'align' => 'stretch'], [
        $score,
        $box('Where clients are', [$bars([['United States · 72', 72], ['Belgium · 10', 10], ['United Kingdom · 4', 4], ['Azerbaijan · 3', 3], ['Germany · 2', 2], ['Nigeria · 2', 2], ['New Zealand · 2', 2], ['8 more countries · 6', 6]])]),
        $box('What they hired me for', [$bars([['Construction websites · 39', 39], ['Ecommerce websites · 33', 33], ['Portfolio & resume · 20', 20], ['Consulting & coaching · 4', 4], ['Transport & logistics · 3', 3], ['Restaurants · 2', 2]])]),
        $box('Clients who came back', [
            ROW(array_map($chip, ['evburgessjr_01 ×20', 'stelaire ×10', 'chefgregorys ×7', 'muzteng309 ×4', 'creditdude ×4', 'rtjrentals ×4']), '8px', [], ['wrap' => 'wrap'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']]),
            P('evburgessjr_01 has hired me 20 times.', MUTED, '14px'),
        ], ['column_span' => 2]),
    ], ['layout' => ['display' => 'grid', 'columns' => 3, 'gap' => '16px']], ['responsive' => [
        'tablet' => ['style' => ['layout' => ['columns' => 1]]],
        'mobile' => ['style' => ['layout' => ['columns' => 1]]],
    ], 'classNames' => ['rk-bento']])], '120px', '100px', [], ['interactions' => THEME('ink')]);

    // The short reviews scroll past in two rows: the same Review items, the ones under 48 characters.
    $marquee = CTOP('Short reviews', [B('core.testimonial_grid', [
        'source' => 'content_type', 'content_type' => 'review', 'text_field' => 'text', 'rating_field' => 'rating', 'meta_fields' => '', 'date_field' => '', 'photo_field' => '',
        'order' => 'newest', 'min_length' => 0, 'max_length' => 47, 'limit' => 60, 'items' => [], 'layout' => 'ticker', 'ticker_rows' => 2, 'ticker_speed' => 3,
        'show_stars' => true, 'show_name' => false,
    ], [], ['typography' => ['size' => '16px', 'font_family' => SANS]], ['classNames' => ['rk-tgrid', 'rk-ticker']])],
        ['padding' => ['top' => '70px', 'right' => '0px', 'bottom' => '40px', 'left' => '0px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '14px', 'content_width' => 'full']], ['interactions' => THEME('bone')]);

    $grid = SEC('In their own words', [
        ROW([W(VBOX([EYE('Client reviews', MUTED_BONE), H2('In their own words', TC('bone'))], '14px'), '50%'), W(RICH('<p>Copied exactly as they appear on Fiverr, typos and all. <a href="https://fiverr.com/rakib_64/">Read all 101 on Fiverr</a>.</p>', MUTED_BONE, '17px'), '40%')], '40px', ['margin' => ['bottom' => '40px']], ['justify' => 'between', 'align' => 'end']),
        B('core.testimonial_grid', [
            'source' => 'content_type', 'content_type' => 'review', 'text_field' => 'text', 'rating_field' => 'rating', 'meta_fields' => 'country, price', 'date_field' => 'when', 'photo_field' => '',
            'order' => 'newest', 'min_length' => 48, 'limit' => 120, 'items' => [],
            'per_page' => 6, 'per_page_tablet' => 4, 'per_page_mobile' => 3, 'columns' => 3, 'columns_tablet' => 2, 'columns_mobile' => 1, 'clamp_lines' => 6,
            'count_label' => '{from}–{to} of {total} reviews', 'read_more_label' => 'Read full review', 'source_note' => 'on Fiverr',
            'show_quote' => true, 'show_stars' => true, 'show_progress' => true, 'highlight_first' => true,
        ], [], ['typography' => ['font_family' => SANS]], ['classNames' => ['rk-tgrid'], 'style_parts' => [
            'card' => ['background' => ['color' => 'rgba(255,255,255,0.45)']],
            'featured' => ['background' => ['color' => INK], 'color' => BONE],
            'avatar' => ['background' => ['color' => INK], 'color' => ORANGE],
        ]]),
    ], '90px', '120px', [], ['interactions' => THEME('bone')]);
    return [$stats, $marquee, $grid];
}

/** The inner hero: breadcrumb, H1 rising line by line, intro, the proof row, and the page's own side piece. */
function HERO(array $p, array $crumbs, ?array $side = null, bool $sideBelow = false, ?string $photo = null): array
{
    $c = TC('ink');
    $crumb = [];
    foreach ($crumbs as [$label, $href]) {
        $crumb[] = $href !== '' ? B('core.link', ['link' => L($label, $href), 'style' => 'plain'], [], ['color' => MUTED, 'typography' => ['size' => '12px', 'font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1.2px']]) : EYE($label, BONE);
        $crumb[] = EYE('/', LINE);
    }
    array_pop($crumb);
    $proof = ROW(array_map(static fn(array $x): array => RICH('<p><strong>' . $x[0] . '</strong> ' . $x[1] . '</p>', MUTED, '12px', ['classNames' => ['rk-rich', 'rk-proof']]), [['5.0', 'from 101 reviews'], ['250+', 'sites shipped'], ['~1 hr', 'average reply'], ['14', 'countries']]), '0px',
        ['padding' => ['top' => '20px'], 'margin' => ['top' => '48px'], 'border' => ['width' => '1px 0 0 0', 'style' => 'solid', 'color' => LINE]], ['wrap' => 'wrap', 'align' => 'center'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap', 'gap' => '10px']], ['classNames' => ['rk-proofs']]);
    // Over a photo the heading keeps to the left, clear of the face.
    $title = B('core.heading', ['text' => $p['h1'], 'level' => 'h1'], [], ['color' => BONE, 'typography' => ['size' => '104px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '0.96', 'letter_spacing' => '-5.6px'], 'dimensions' => ['max_width' => $photo !== null ? '820px' : '1180px']],
        ['responsive' => ['tablet' => ['style' => ['typography' => ['size' => '72px']]], 'mobile' => ['style' => ['typography' => ['size' => '46px']]]], 'interactions' => ['text' => ['effect' => 'lines', 'step_ms' => 90]]]);
    // The intro reads at body size, as the original's (16-17px, not a lead).
    $intro = VBOX(BLKS($p['intro'], ['muted' => 'rgba(237,232,223,.82)'] + $c, false), '14px');
    $body = $side !== null && !$sideBelow
        ? ROW([W(VBOX([$title, $intro], '32px'), '58%'), W($side, '36%')], '48px', ['margin' => ['top' => '40px']], ['justify' => 'between', 'align' => 'end'])
        : VBOX(array_values(array_filter([$title, $side !== null ? ROW([W($intro, '56%'), W($side, '38%')], '40px', [], ['justify' => 'between', 'align' => 'end']) : W($intro, '62%')])), '32px', ['margin' => ['top' => '40px']]);
    return CTOP('Hero', [
        // The wide photo on desktop and tablet; on a phone, a portrait crop of it (the face above the words).
        ...($photo !== null ? [BGPIC($photo, 'right', true, ['md', 'lg']), BGPIC(str_replace('.jpg', '-m.jpg', $photo), 'top', false, ['base', 'sm'])] : []),
        ROW([ROW($crumb, '10px', ['dimensions' => ['width' => 'auto']], ['align' => 'center'], []), EYE('Available from December 2026', BONE, 'right')], '16px', [], ['justify' => 'between'], ['layout' => ['direction' => 'column', 'gap' => '8px']]),
        $body, $proof,
    ], ['background' => ['gradient' => 'radial-gradient(circle at 75% 20%, #2a140a, #0B0B0C 60%)'], 'padding' => ['top' => '150px', 'right' => '28px', 'bottom' => '56px', 'left' => '28px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '0px', 'content_width' => 'boxed', 'content_max' => '1440px']],
        ['interactions' => THEME('ink') + ['glow' => ['color' => 'rgba(255,90,31,0.16)', 'size' => 560]], 'classNames' => $photo !== null ? ['rk-photo-hero'] : [],
         'responsive' => ['mobile' => ['style' => ['padding' => ['top' => $photo !== null ? '300px' : '110px', 'right' => '20px', 'bottom' => '40px', 'left' => '20px']]]]]);
}

function ITEMS(array $items, array $cases, array $data): array
{
    $out = [];
    foreach ($items as $it) {
        switch ($it['kind']) {
            case 'section': $out[] = S_SECTION($it); break;
            case 'statement': $out[] = S_STATEMENT($it); break;
            case 'bento': $out[] = S_BENTO($it); break;
            case 'cta': $out[] = S_CTA($it); break;
            case 'cta_form': $out[] = S_CTA($it, true); break;
            case 'band': $out[] = S_BAND($it); break;
            case 'world': $out[] = S_WORLD($it['label']); break;
            case 'recent': $out[] = S_RECENT($cases); break;
            case 'contact': $out[] = S_CONTACT($it['n']); break;
            case 'products': $out[] = S_PRODUCTS($it['n']); break;
            case 'reviews': array_push($out, ...S_REVIEWS($data['reviews'], $data['featured'])); break;
            case 'projects': $out[] = S_PROJECTS($it); break;
            case 'grid': $out[] = S_WORKGRID(); break;
            case 'servicelist': $out[] = S_SERVICES($it); break;
            case 'faqlist': $out[] = S_FAQS($it); break;
            default: throw new RuntimeException('Unknown section kind ' . $it['kind']);
        }
    }
    return $out;
}

// ── Projects: a content type, its single template, card and items (project.php) ──
require __DIR__ . '/project.php';

// ── Reviews: a content type with no pages of its own; the Testimonial grid reads its items ──
PageMeta::savePostTypes($tid, array_replace(PageMeta::postTypes($tid), ['review' => [
    'key' => 'review', 'singular' => 'Review', 'plural' => 'Reviews', 'route' => '', 'is_system' => false, 'description' => 'Client reviews, copied from Fiverr', 'show_in_menu' => true,
    // Listed (so a Query Loop can show them, as the menu's About panel does) but with no pages of their own, as the FAQs.
    'public' => true, 'publicly_queryable' => false, 'rewrite' => false, 'has_archive' => false, 'exclude_from_search' => true, 'show_in_nav_menus' => false,
    'hide_field_names' => false, 'edit_link' => true, 'menu_icon' => 'star', 'menu_position' => 2,
    'columns' => [['label' => 'Country', 'source' => 'country', 'prefix' => '', 'suffix' => '', 'sortable' => true], ['label' => 'Stars', 'source' => 'rating', 'prefix' => '', 'suffix' => '', 'sortable' => true]],
    'filters' => [],
]]));
$rfield = static fn(string $key, string $label, string $kind = 'text'): array => ['id' => 'f_' . substr(md5('review' . $key), 0, 8), 'post_type' => 'review', 'label' => $label, 'key' => $key, 'type' => $kind, 'required' => $key === 'text', 'placeholder' => '', 'help' => '', 'options' => []];
$others = array_values(array_filter(PageMeta::allFields($tid), static fn(array $f): bool => ($f['post_type'] ?? '') !== 'review'));
PageMeta::saveFields($tid, array_merge($others, [
    $rfield('text', 'Review', 'textarea'), $rfield('rating', 'Stars', 'number'), $rfield('country', 'Country'), $rfield('price', 'Price'), $rfield('when', 'When'),
]));
PageRoutes::forget($tid);
// The list order is the original's: its picked reviews first, then the rest longest first. Newest first, so the
// first is published last; a review added later shows first.
$byN = [];
foreach ($DATA['reviews'] as $r) { $byN[(string) $r['n']] = $r; }
$picked = array_values(array_filter(array_map(static fn($n) => $byN[(string) $n] ?? null, $DATA['featured'])));
$pickedN = array_map(static fn(array $r): string => (string) $r['n'], $picked);
$rest = array_values(array_filter($DATA['reviews'], static fn(array $r): bool => !in_array((string) $r['n'], $pickedN, true)));
usort($rest, static fn(array $a, array $b): int => mb_strlen(trim((string) $b['text'])) <=> mb_strlen(trim((string) $a['text'])));
$ordered = array_merge($picked, $rest);
foreach (array_reverse($ordered) as $i => $r) {
    build_page('review-' . $r['n'], (string) $r['who'], 'page', 'standalone', [CTOP('Body', [P(trim((string) $r['text']), BONE, '18px')], ['layout' => ['display' => 'flex', 'direction' => 'column']])], [
        'date' => date('Y-m-d H:i:s', strtotime('2025-01-01') + $i * 3600),
        'type' => 'review', 'fields' => ['text' => trim((string) $r['text']), 'rating' => (string) $r['rating'], 'country' => (string) $r['country'], 'price' => (string) $r['price'], 'when' => (string) $r['when']],
    ]);
}
say('  + ' . count($ordered) . ' reviews');

function LOOP(array $props): array
{
    return B('core.query_loop', array_replace(['source' => 'posts', 'content_type' => 'project', 'per_page' => 24, 'columns' => 3, 'gap' => 'lg', 'order_by' => 'published_at', 'order' => 'desc',
        'show_featured_image' => true, 'show_date' => false, 'show_author' => false, 'show_category' => true, 'show_excerpt' => true, 'enable_pagination' => true, 'pagination_style' => 'numbers', 'empty_message' => 'No projects yet.'], $props), [], [], ['classNames' => ['rk-loop']]);
}
/** Service items of one group, as cards. */
function S_SERVICES(array $s): array
{
    $c = TC($s['theme']);
    return SEC($s['title'], [VBOX([LABEL($s['n'], $c), H2($s['title'], $c)], '18px', ['margin' => ['bottom' => '40px']]),
        LOOP(['content_type' => 'service', 'category' => $s['group'], 'per_page' => 12, 'columns' => 3, 'enable_pagination' => false, 'order_by' => 'published_at', 'order' => 'desc'])],
        '120px', '120px', [], ['interactions' => THEME($s['theme'])]);
}
function S_WORKGRID(): array
{
    // A heading for the list (read by screen readers, not shown), so the cards' h3 titles follow an h2.
    return SEC('Projects', [B('core.heading', ['text' => 'All projects', 'level' => 'h2'], [], [], ['classNames' => ['rk-sr']]), LOOP(['filter_field' => 'industry', 'filter_all_label' => 'All', 'filter_counts' => true, 'open_in' => 'page', 'per_page' => 48])], '90px', '120px', [], ['interactions' => THEME('bone')]);
}
function S_PROJECTS(array $s): array
{
    $c = TC('navy');
    return SEC($s['title'], [VBOX([LABEL($s['n'], $c), H2($s['title'], $c)], '18px', ['margin' => ['bottom' => '40px']]), LOOP(['category' => $s['industry'], 'open_in' => 'page', 'enable_pagination' => false])], '120px', '120px', [], ['interactions' => THEME('navy'), 'classNames' => ['rk-on-navy']]);
}

// ── Services: a content type, its templates and items (services.php) ──
require __DIR__ . '/services.php';
// ── FAQs: a content type the FAQ page lists by topic (faq.php) ──
require __DIR__ . '/faq.php';

// ── Pages ──────────────────────────────────────────────────────────────────────
$names = ['services' => 'Services', 'wordpress-websites' => 'WordPress websites', 'custom-development' => 'Custom development', 'seo' => 'SEO audits', 'apps' => 'Web & mobile apps', 'platforms' => 'Shopify · Wix · Squarespace', 'branding' => 'Branding & video'];
$onlyPages = array_filter(explode(',', (string) getenv('RK_PAGES')));
foreach ($DATA['pages'] as $slug => $p) {
    if ($onlyPages !== [] && !in_array($slug, $onlyPages, true)) { continue; }
    if (in_array($slug, SERVICE_SLUGS, true)) { continue; } // Service items (services.php)
    if ($slug === 'faq') {
        foreach ($p['items'] as $k => $it) {
            if (($it['kind'] ?? '') === 'section') { $p['items'][$k] = ['kind' => 'faqlist'] + $it; }
        }
    }
    if ($slug === 'services') {
        // The hub lists the Service items, core and supporting, with the Loop Item: Service card.
        foreach ($p['items'] as $k => $it) {
            if (in_array($it['title'] ?? '', ['Core services', 'Supporting services'], true)) {
                $p['items'][$k] = ['kind' => 'servicelist', 'n' => $it['n'], 'theme' => $it['theme'], 'title' => $it['title'], 'group' => $it['title'] === 'Core services' ? 'Core' : 'Supporting'];
            }
        }
    }
    $crumbs = [['Home', '/']];
    if ($p['parent'] === 'services') { $crumbs[] = ['Services', '/services/']; }
    if ($p['parent'] === 'work') { $crumbs[] = ['Industries', '/work/']; }
    $crumbs[] = [$names[$slug] ?? $p['name'], ''];
    $side = null;
    $below = false;
    if ($slug === 'free-audit') { $side = AUDITFORM('I reply within 12 hours. Your details are only used to send your audit.'); }
    // About and Contact open on a photo of Rakib behind the words.
    $photo = ['about' => 'rakib-portrait-studio.jpg', 'contact' => 'rakib-portrait-blue.jpg'][$slug] ?? null;
    if ($slug === 'construction') { $side = VBOX([EYE('Built for', MUTED), ROW(array_map(static fn(string $t): array => P($t, BONE, '13px', [], ['classNames' => ['rk-chip']]), $p['chips']), '8px', [], ['wrap' => 'wrap'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']])], '12px'); $below = true; }
    if ($slug === 'work') { $side = ROW(array_map(static fn(array $x): array => VBOX([H($x[0], 'div', '48px', '36px', BONE), EYE($x[1], MUTED)], '4px', ['dimensions' => ['width' => 'auto']]), [['250+', 'Websites shipped'], ['25', 'Case studies'], ['14', 'Countries']]), '36px', [], ['justify' => 'end'], []); $below = true; }
    $intro = $p;
    if ($slug === 'free-audit') { $intro['intro'] = array_values(array_filter($p['intro'], static fn(array $b): bool => $b['k'] !== 'note')); }
    build_page($slug, $p['name'], 'page', 'standalone', [HERO($intro, $crumbs, $side, $below, $photo), ...ITEMS($p['items'], $DATA['cases'], $DATA)], ['seo_title' => $p['seo_title'], 'seo_desc' => $p['meta']]);
}
