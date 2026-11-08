<?php
/**
 * rakibhasaan.com — Services as a content type.
 *
 * Each service page (WordPress websites, Custom development, SEO audits, Web & mobile apps, Platforms, Branding) is a
 * Service item at its own address (/wordpress-websites/ …). Its copy lives in fields grouped by the kind of section
 * the original uses: highlights (cards), features (rows), a statement, a checklist, steps, pricing, questions and a
 * closing call to action, each with its own title and lead. The Single: Service template draws them in that order
 * with Repeater widgets, and leaves out a section whose field is empty. The Services hub lists the items with Query
 * Loops (core and supporting, by category) through the Loop Item: Service card.
 *
 * Required by inner.php (TC, CARD, RICH, EYE, H2, HERO helpers, $DATA, $tid).
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PageRoutes;

const SERVICE_SLUGS = ['wordpress-websites', 'custom-development', 'seo', 'apps', 'platforms', 'branding'];

PageMeta::savePostTypes($tid, array_replace(PageMeta::postTypes($tid), ['service' => [
    'key' => 'service', 'singular' => 'Service', 'plural' => 'Services', 'route' => '', 'is_system' => false, 'description' => 'Service pages, drawn by the Single: Service template', 'show_in_menu' => true,
    'rewrite' => false, 'hide_field_names' => false, 'edit_link' => true, 'menu_icon' => 'layout', 'menu_position' => 2,
    'columns' => [['label' => 'Group', 'source' => 'group', 'prefix' => '', 'suffix' => '', 'sortable' => true]],
    'filters' => [],
]]));
$sf = static fn(string $key, string $label, string $kind = 'text', array $options = [], string $help = ''): array => ['id' => 'f_' . substr(md5('service' . $key), 0, 8), 'post_type' => 'service', 'label' => $label, 'key' => $key, 'type' => $kind, 'required' => in_array($key, ['h1', 'summary', 'intro'], true), 'placeholder' => '', 'help' => $help, 'options' => $options];
$sub = static fn(array $rows): array => ['subfields' => array_map(static fn(array $r): array => ['key' => $r[0], 'label' => $r[1], 'type' => $r[2] ?? 'text'], $rows)];
$section = static fn(string $key, string $label): array => [$sf($key . '_title', $label . ': title'), $sf($key . '_lead', $label . ': lead', 'textarea')];
$others = array_values(array_filter(PageMeta::allFields($tid), static fn(array $x): bool => ($x['post_type'] ?? '') !== 'service'));
PageMeta::saveFields($tid, array_merge($others, [
    $sf('group', 'Group', 'select', ['choices' => [['value' => 'Core', 'label' => 'Core service'], ['value' => 'Supporting', 'label' => 'Supporting service']]]),
    $sf('h1', 'Page heading'), $sf('intro', 'Intro', 'richtext'),
    $sf('summary', 'Card text', 'textarea', [], 'What the Services page says about it.'), $sf('best_for', 'Best for', 'textarea'), $sf('card_label', 'Card link text'),
    $sf('primary_label', 'Main button text'), $sf('primary_url', 'Main button address', 'url'), $sf('secondary_label', 'Second button text'), $sf('secondary_url', 'Second button address', 'url'),
    ...$section('highlights', 'Highlights'), $sf('highlights', 'Highlights', 'repeater', $sub([['title', 'Title'], ['text', 'Text', 'textarea']])),
    ...$section('features', 'Features'), $sf('features', 'Features', 'repeater', $sub([['title', 'Title'], ['text', 'Text', 'richtext']])),
    $sf('statement_title', 'Statement: title'), $sf('statement_lead', 'Statement: lead', 'textarea'), $sf('statement_text', 'Statement: text', 'richtext'),
    ...$section('checklist', 'Checklist'), $sf('checklist', 'Checklist', 'list'), $sf('checklist_note', 'Checklist: note', 'richtext'),
    ...$section('steps', 'Steps'), $sf('steps', 'Steps', 'repeater', $sub([['title', 'Title'], ['text', 'Text', 'textarea']])),
    ...$section('pricing', 'Pricing'), $sf('pricing', 'Pricing', 'repeater', $sub([['name', 'Name'], ['text', 'What it includes', 'textarea'], ['price', 'Price'], ['cta', 'Button text']])),
    $sf('faq', 'Questions', 'repeater', $sub([['question', 'Question'], ['answer', 'Answer', 'richtext']])),
    $sf('cta_title', 'Closing: title'), $sf('cta_text', 'Closing: text', 'textarea'),
]));
PageRoutes::forget($tid);
say('  + service type');

// ── The Single template ───────────────────────────────────────────────────────────
/** A section head: number and a heading from a field, and its lead from a field. */
$sHead = static function (string $n, string $key, array $c, string $fallback): array {
    return VBOX([
        EYE($n, $c['muted']),
        B('core.heading', ['text' => $fallback, 'level' => 'h2'], [], ['color' => $c['fg'], 'typography' => ['size' => '56px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '1.02', 'letter_spacing' => '-2.5px']],
            ['dynamic' => ['text' => ['tag' => 'field', 'key' => $key . '_title']], 'responsive' => ['mobile' => ['style' => ['typography' => ['size' => '36px']]]], 'interactions' => ['text' => ['effect' => 'lines', 'step_ms' => 80]]]),
        FV($key . '_lead', $c['muted'], '19px', 'p', ['dimensions' => ['max_width' => '760px'], 'typography' => ['line_height' => '1.55']], [], ['when_field' => $key . '_lead']),
    ], '16px', ['margin' => ['bottom' => '44px']]);
};
$cardStyle = static fn(array $c): array => ['background' => ['color' => $c['card']], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => $c['line'], 'radius' => '20px'], 'padding' => ['top' => '26px', 'right' => '26px', 'bottom' => '26px', 'left' => '26px']];
/** A Repeater widget laid out as a grid; its child is drawn once per row. */
$rep = static fn(string $field, array $row, int $cols, string $gap = '16px', array $more = []): array => B('theme.repeater', ['field' => $field, 'display' => 'list'], [$row],
    array_replace_recursive(['layout' => ['display' => 'grid', 'columns' => $cols, 'gap' => $gap]], $more),
    ['responsive' => ['tablet' => ['style' => ['layout' => ['columns' => min(2, $cols)]]], 'mobile' => ['style' => ['layout' => ['columns' => 1]]]], 'classNames' => ['rk-rep', 'rk-rep-' . $field]]);

$cB = TC('bone'); $cI = TC('ink'); $cN = TC('navy'); $cO = TC('orange');
/** The original's section layout: the head on the left, the content on the right; stacked on a phone. */
$twoCol = static fn(array $head, array $content): array => ROW([W($head, '34%'), W($content, '60%')], '48px', [], ['justify' => 'between', 'align' => 'start']);

$hero = CTOP('Hero', [
    ROW([
        ROW([
            B('core.link', ['link' => L('Home', '/'), 'style' => 'plain'], [], ['color' => MUTED, 'typography' => ['size' => '12px', 'font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1.2px']]), EYE('/', LINE),
            B('core.link', ['link' => L('Services', '/services/'), 'style' => 'plain'], [], ['color' => MUTED, 'typography' => ['size' => '12px', 'font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1.2px']]), EYE('/', LINE),
            B('theme.post_title', ['level' => 'h6'], [], ['color' => BONE, 'typography' => ['size' => '12px', 'font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1.2px']]),
        ], '10px', ['dimensions' => ['width' => 'auto']], ['align' => 'center', 'wrap' => 'wrap'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']]),
        EYE('Available from December 2026', BONE, 'right'),
    ], '16px', [], ['justify' => 'between'], ['layout' => ['direction' => 'column', 'gap' => '8px']]),
    B('core.heading', ['text' => 'Service', 'level' => 'h1'], [], ['color' => BONE, 'margin' => ['top' => '40px'], 'typography' => ['size' => '96px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '0.96', 'letter_spacing' => '-5.2px'], 'dimensions' => ['max_width' => '1180px']],
        ['dynamic' => ['text' => ['tag' => 'field', 'key' => 'h1']], 'responsive' => ['tablet' => ['style' => ['typography' => ['size' => '68px']]], 'mobile' => ['style' => ['typography' => ['size' => '44px']]]], 'interactions' => ['text' => ['effect' => 'lines', 'step_ms' => 90]]]),
    FV('intro', 'rgba(237,232,223,0.78)', '17px', 'div', ['margin' => ['top' => '32px'], 'dimensions' => ['max_width' => '640px'], 'typography' => ['line_height' => '1.6']], [],
        ['style_parts' => ['paragraph' => ['typography' => ['size' => '17px', 'line_height' => '1.6'], 'margin' => ['bottom' => '14px']]]]),
    ROW([
        PILL('Get a free audit', '/free-audit/', ORANGE, INK, ['dynamic' => ['link.label' => ['tag' => 'field', 'key' => 'primary_label'], 'link.href' => ['tag' => 'field', 'key' => 'primary_url']], 'when_field' => 'primary_label']),
        PILL('See the work', '/work/', 'rgba(0,0,0,0)', BONE, ['dynamic' => ['link.label' => ['tag' => 'field', 'key' => 'secondary_label'], 'link.href' => ['tag' => 'field', 'key' => 'secondary_url']], 'when_field' => 'secondary_label',
            'style_parts' => ['button' => ['background' => ['color' => 'rgba(0,0,0,0)'], 'color' => BONE, 'border' => ['width' => '1px', 'style' => 'solid', 'color' => LINE, 'radius' => '999px']]]]),
    ], '10px', ['margin' => ['top' => '24px']], ['wrap' => 'wrap'], ['layout' => ['direction' => 'row', 'wrap' => 'wrap']]),
], ['background' => ['gradient' => 'radial-gradient(circle at 75% 20%, #2a140a, #0B0B0C 60%)'], 'padding' => ['top' => '150px', 'right' => '28px', 'bottom' => '80px', 'left' => '28px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '0px', 'content_width' => 'boxed', 'content_max' => '1440px']],
    ['interactions' => THEME('ink') + ['glow' => ['color' => 'rgba(255,90,31,0.16)', 'size' => 560]], 'responsive' => ['mobile' => ['style' => ['padding' => ['top' => '110px', 'right' => '20px', 'bottom' => '48px', 'left' => '20px']]]]]);

$highlights = SEC('Highlights', [
    $twoCol($sHead('01', 'highlights', $cB, 'Who this is for'),
        $rep('highlights', VBOX([FV('title', INK, '19px', 'h3', ['typography' => ['weight' => '500', 'line_height' => '1.25']]), FV('text', MUTED_BONE, '14px', 'p', ['typography' => ['line_height' => '1.55']])], '8px', $cardStyle($cB), ['classNames' => ['rk-step']]), 2, '10px')),
], '120px', '120px', [], ['interactions' => THEME('bone'), 'when_field' => 'highlights']);

$features = SEC('Features', [
    $sHead('02', 'features', $cI, 'What you get'),
    $rep('features', VBOX([
        FV('title', BONE, '24px', 'h3', ['typography' => ['weight' => '300', 'line_height' => '1.15', 'letter_spacing' => '-0.6px']]),
        FV('text', MUTED, '15px', 'div', ['typography' => ['line_height' => '1.6']], [], ['style_parts' => ['paragraph' => ['margin' => ['bottom' => '10px']]]]),
    ], '14px', ['padding' => ['top' => '26px', 'right' => '26px', 'bottom' => '30px', 'left' => '26px'], 'border' => ['width' => '0 1px 0 0', 'style' => 'solid', 'color' => LINE]], ['classNames' => ['rk-step']]), 4, '0px',
        ['border' => ['width' => '1px', 'style' => 'solid', 'color' => LINE, 'radius' => '20px']]),
], '120px', '120px', [], ['interactions' => THEME('ink'), 'when_field' => 'features']);

$statement = SEC('Statement', [
    EYE('03', MUTED_BONE),
    B('core.heading', ['text' => 'Statement', 'level' => 'h2'], [], ['color' => INK, 'margin' => ['top' => '16px'], 'typography' => ['size' => '20px', 'font_family' => SANS, 'weight' => '500']], ['dynamic' => ['text' => ['tag' => 'field', 'key' => 'statement_title']]]),
    FV('statement_lead', INK, '44px', 'p', ['margin' => ['top' => '24px'], 'dimensions' => ['max_width' => '1100px'], 'typography' => ['weight' => '300', 'line_height' => '1.15', 'letter_spacing' => '-1.6px']], [],
        ['interactions' => ['text' => ['effect' => 'light_words', 'dim' => 22]], 'responsive' => ['mobile' => ['style' => ['typography' => ['size' => '28px']]]]]),
    FV('statement_text', MUTED_BONE, '18px', 'div', ['margin' => ['top' => '24px'], 'dimensions' => ['max_width' => '760px']]),
], '130px', '130px', [], ['interactions' => THEME('bone'), 'when_field' => 'statement_lead']);

$checklist = SEC('Checklist', [
    $twoCol($sHead('04', 'checklist', $cN, 'What you can expect'), VBOX([
        FV('checklist', '#E9ECF5', '17px', 'div', [], [], ['classNames' => ['rk-checks']]),
        FV('checklist_note', '#9AA6C4', '16px', 'div', ['margin' => ['top' => '20px']]),
    ], '0px')),
], '120px', '120px', [], ['interactions' => THEME('navy'), 'when_field' => 'checklist']);

$steps = SEC('Steps', [
    $twoCol($sHead('05', 'steps', $cB, 'How the project runs'),
        $rep('steps', ROW([VBOX([FV('title', INK, '19px', 'h3', ['typography' => ['weight' => '500', 'line_height' => '1.25']]), FV('text', MUTED_BONE, '15px', 'p', ['typography' => ['line_height' => '1.55']])], '6px')], '0px',
            ['padding' => ['top' => '22px', 'bottom' => '22px'], 'border' => ['width' => '0 0 1px 0', 'style' => 'solid', 'color' => LINE_BONE]], [], [], ['classNames' => ['rk-step', 'rk-step-row']]), 1, '0px')),
], '120px', '120px', [], ['interactions' => THEME('bone'), 'when_field' => 'steps']);

$pricing = SEC('Pricing', [
    $twoCol($sHead('06', 'pricing', $cI, 'Pricing'), $rep('pricing', VBOX([
        FV('name', BONE, '30px', 'h3', ['typography' => ['weight' => '300', 'letter_spacing' => '-1px', 'line_height' => '1.1']]),
        FV('text', 'rgba(237,232,223,0.72)', '15px', 'p', ['typography' => ['line_height' => '1.55']]),
        FV('price', BONE, '34px', 'div', ['margin' => ['top' => 'auto'], 'typography' => ['weight' => '200', 'letter_spacing' => '-1.4px', 'line_height' => '1.1']]),
        B('core.link', ['link' => L('Get a quote', '/free-audit/'), 'style' => 'arrow'], [], ['color' => ORANGE, 'typography' => ['size' => '15px', 'weight' => '500']], ['dynamic' => ['link.label' => ['tag' => 'field', 'key' => 'cta']], 'when_field' => 'cta']),
    ], '14px', $cardStyle($cI) + ['dimensions' => ['min_height' => '300px']], ['classNames' => ['rk-price']]), 2, '10px')),
], '120px', '120px', [], ['interactions' => THEME('ink'), 'when_field' => 'pricing']);

$faq = SEC('Questions', [
    $twoCol(VBOX([EYE('07', MUTED_BONE), H2('Frequently asked questions', $cB)], '16px'),
        B('theme.repeater', ['field' => 'faq', 'display' => 'accordion', 'title_field' => 'question', 'body_field' => 'answer', 'allow_multiple' => false, 'look' => 'lines', 'icon' => 'plus'], [],
            ['color' => INK, 'typography' => ['size' => '17px', 'font_family' => SANS, 'weight' => '400']], ['classNames' => ['rk-faq']])),
], '120px', '120px', [], ['interactions' => THEME('bone'), 'when_field' => 'faq']);

// Projects in a ticker: a Query Loop alone in a marquee Container lends it its items.
$recent = CTOP('Recent work', [
    ROW([VBOX([EYE('Recent work', MUTED_BONE), H2('250+ sites, a few of them here', $cB)], '14px'), B('core.link', ['link' => L('See all work →', '/work/'), 'style' => 'plain'], [], ['color' => MUTED_BONE, 'typography' => ['size' => '12px', 'font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1.2px']])],
        '16px', ['padding' => ['right' => '28px', 'left' => '28px'], 'dimensions' => ['max_width' => '1440px'], 'margin' => ['bottom' => '36px']], ['justify' => 'between', 'align' => 'end']),
    B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'nowrap'], [
        LOOP(['per_page' => 12, 'columns' => 4, 'enable_pagination' => false, 'show_excerpt' => false, 'order_by' => 'published_at', 'order' => 'desc']),
    ], ['layout' => ['display' => 'flex', 'direction' => 'row', 'gap' => '16px']], ['interactions' => ['marquee' => ['speed' => 2, 'direction' => 'left', 'scroll' => true, 'pause' => true, 'width' => 360]], 'classNames' => ['rk-recent']]),
], ['padding' => ['top' => '110px', 'right' => '0px', 'bottom' => '120px', 'left' => '0px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '0px', 'content_width' => 'full']], ['interactions' => THEME('bone')]);

$cta = SEC('Closing', [
    B('core.heading', ['text' => 'Ready to see what your site needs?', 'level' => 'h2'], [], ['color' => INK, 'typography' => ['size' => '88px', 'font_family' => SANS, 'weight' => '200', 'line_height' => '0.98', 'letter_spacing' => '-4.4px'], 'dimensions' => ['max_width' => '1000px']],
        ['dynamic' => ['text' => ['tag' => 'field', 'key' => 'cta_title']], 'responsive' => ['mobile' => ['style' => ['typography' => ['size' => '46px']]]]]),
    ROW([FV('cta_text', INK, '18px', 'p', ['dimensions' => ['max_width' => '620px']]), PILL('Get my free audit  →', '/free-audit/', INK, BONE)], '40px', ['margin' => ['top' => '32px']], ['justify' => 'between', 'align' => 'end']),
], '150px', '130px', [], ['interactions' => THEME('orange'), 'when_field' => 'cta_title']);

build_page('template-single-service', 'Single: Service', 'system', 'standalone', [$hero, $highlights, $features, $statement, $checklist, $steps, $pricing, $faq, $recent, $cta],
    ['settings' => ['template_type' => 'single', 'conditions' => ['rules' => [['action' => 'include', 'condition' => 'singular', 'value' => 'service']]]]]);

// ── The card the Services hub lists ─────────────────────────────────────────────────
$sCard = CTOP('Card', [
    B('theme.post_title', ['level' => 'h3', 'link_to_post' => true], [], ['color' => INK, 'typography' => ['size' => '26px', 'font_family' => SANS, 'weight' => '400', 'line_height' => '1.2', 'letter_spacing' => '-0.6px']]),
    FV('summary', MUTED_BONE, '15px', 'p', ['typography' => ['line_height' => '1.6']]),
    FV('best_for', MUTED_BONE, '14px', 'p', ['margin' => ['top' => 'auto'], 'typography' => ['line_height' => '1.55']], ['before' => 'Best for: ']),
    B('core.link', ['link' => L('Read more', '/services/'), 'style' => 'arrow'], [], ['color' => RUST, 'typography' => ['size' => '14px', 'weight' => '500']],
        ['dynamic' => ['link.label' => ['tag' => 'field', 'key' => 'card_label'], 'link.href' => ['tag' => 'post_url']]]),
], ['layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '14px', 'content_width' => 'full', 'padding_y' => 'none'], 'padding' => ['top' => '28px', 'right' => '26px', 'bottom' => '26px', 'left' => '26px'],
    'border' => ['width' => '1px', 'style' => 'solid', 'color' => LINE_BONE, 'radius' => '20px'], 'background' => ['color' => 'rgba(255,255,255,0.4)'], 'dimensions' => ['min_height' => '340px']], ['classNames' => ['rk-scard']]);
build_page('template-loop-service', 'Loop Item: Service', 'system', 'standalone', [$sCard], ['settings' => ['template_type' => 'loop', 'conditions' => ['rules' => [['action' => 'include', 'condition' => 'singular', 'value' => 'service']]]]]);

// ── The items, from the original's pages ────────────────────────────────────────────
/** The original's copy blocks as rich text (paragraphs and lists). */
function sv_html(array $blocks): string
{
    $out = '';
    foreach ($blocks as $b) {
        $out .= match ($b['k']) {
            'p', 'note' => '<p>' . $b['html'] . '</p>',
            'checks' => '<ul>' . implode('', array_map(static fn(string $h): string => '<li>' . $h . '</li>', $b['items'])) . '</ul>',
            'tiles', 'steps' => '<ul>' . implode('', array_map(static fn(array $t): string => '<li><strong>' . $t['h'] . '</strong> ' . $t['html'] . '</li>', $b['items'])) . '</ul>',
            default => '',
        };
    }
    return $out;
}
$plain = static fn(string $h): string => trim(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

/** One service page's sections dealt into the Service fields. */
function sv_fields(array $p, callable $plain): array
{
    $v = ['highlights' => [], 'features' => [], 'steps' => [], 'pricing' => [], 'faq' => [], 'checklist' => ''];
    $leadOf = static fn(array $blocks): string => implode("\n\n", array_map(static fn(array $b): string => $plain($b['html']), array_filter($blocks, static fn(array $b): bool => $b['k'] === 'p')));
    foreach ($p['items'] as $it) {
        $kinds = array_map(static fn(array $b): string => $b['k'], $it['blocks'] ?? []);
        $title = (string) ($it['title'] ?? '');
        if ($it['kind'] === 'cta') {
            $v['cta_title'] = $title;
            $v['cta_text'] = $leadOf($it['blocks']);
            continue;
        }
        if (!in_array($it['kind'], ['section', 'statement'], true) || str_starts_with($title, 'Recent')) {
            continue; // the template lists recent projects itself; the Kohevo products stay on the page's own copy
        }
        if ($it['kind'] === 'statement') {
            if (empty($v['statement_lead'])) {
                $v['statement_title'] = $title;
                $v['statement_lead'] = $plain((string) $it['lead']);
                $v['statement_text'] = sv_html($it['rest'] ?? []);
            } elseif ($title === 'Pricing' && empty($v['pricing_title'])) {
                $v['pricing_title'] = $title;
                $v['pricing_lead'] = $plain((string) $it['lead']) . "\n\n" . $plain(sv_html($it['rest'] ?? []));
                $v['pricing'][] = ['name' => 'Quoted per project', 'text' => '', 'price' => 'After a free audit', 'cta' => 'Start with an audit'];
            } else {
                $v['features'][] = ['title' => $title, 'text' => '<p>' . htmlspecialchars($plain((string) $it['lead'])) . '</p>' . sv_html($it['rest'] ?? [])];
            }
            continue;
        }
        if (in_array('faq', $kinds, true)) {
            foreach ($it['blocks'] as $b) {
                if ($b['k'] === 'faq') {
                    foreach ($b['items'] as $q) { $v['faq'][] = ['question' => $q['q'], 'answer' => '<p>' . $q['a'] . '</p>']; }
                }
            }
            continue;
        }
        if (in_array('prices', $kinds, true) || in_array('pricelist', $kinds, true)) {
            $v['pricing_title'] = $title;
            $v['pricing_lead'] = $leadOf($it['blocks']);
            foreach ($it['blocks'] as $b) {
                if ($b['k'] === 'prices') {
                    foreach ($b['items'] as $x) { $v['pricing'][] = ['name' => $x['name'], 'text' => $plain($x['html']), 'price' => $x['price'], 'cta' => $x['cta']]; }
                }
                if ($b['k'] === 'pricelist') {
                    foreach ($b['rows'] as $r) { $v['pricing'][] = ['name' => $r[0], 'text' => '', 'price' => $r[1], 'cta' => 'Get a quote']; }
                }
            }
            continue;
        }
        if (in_array('steps', $kinds, true) && $v['steps'] === []) {
            $v['steps_title'] = $title;
            $v['steps_lead'] = $leadOf($it['blocks']);
            foreach ($it['blocks'] as $b) {
                if ($b['k'] === 'steps') {
                    foreach ($b['items'] as $s) { $v['steps'][] = ['title' => $plain($s['h']), 'text' => $plain($s['html'])]; }
                }
            }
            continue;
        }
        if (!empty($it['subs'])) {
            $v['features_title'] ??= $title;
            foreach ($it['subs'] as $s) { $v['features'][] = ['title' => $s['title'], 'text' => sv_html($s['blocks'])]; }
            continue;
        }
        if (in_array('tiles', $kinds, true) && $v['highlights'] === []) {
            $v['highlights_title'] = $title;
            $v['highlights_lead'] = $leadOf($it['blocks']);
            foreach ($it['blocks'] as $b) {
                if ($b['k'] === 'tiles') {
                    foreach ($b['items'] as $t) { $v['highlights'][] = ['title' => $plain($t['h']), 'text' => $plain($t['html'])]; }
                }
            }
            continue;
        }
        if (in_array('checks', $kinds, true) && $v['checklist'] === '') {
            $v['checklist_title'] = $title;
            $first = array_search('checks', $kinds, true);
            $v['checklist_lead'] = $leadOf(array_slice($it['blocks'], 0, (int) $first));
            foreach ($it['blocks'] as $b) {
                if ($b['k'] === 'checks') { $v['checklist'] = implode("\n", array_map($plain, $b['items'])); }
            }
            $v['checklist_note'] = sv_html(array_slice($it['blocks'], (int) $first + 1));
            continue;
        }
        if (in_array('picker', $kinds, true)) {
            foreach ($it['blocks'] as $b) {
                if ($b['k'] === 'picker') {
                    foreach ($b['rows'] as $r) { $v['features'][] = ['title' => $r['q'], 'text' => '<p>Best fit: <strong>' . $r['a'] . '</strong></p>']; }
                }
            }
            $v['features_title'] ??= $title;
            continue;
        }
        // Anything else reads as a feature row: its title and its copy.
        $v['features'][] = ['title' => $title, 'text' => sv_html($it['blocks'] ?? [])];
        $v['features_title'] ??= $title;
    }
    return array_filter($v, static fn($x): bool => $x !== '' && $x !== []);
}

// The hub's cards give each service its card text, best-for line and link text, and its group.
$cards = [];
foreach ($DATA['pages']['services']['items'] as $it) {
    foreach ($it['subs'] ?? [] as $s) {
        $slug = trim((string) ($s['link']['href'] ?? ''), '/');
        $paras = array_values(array_filter($s['blocks'], static fn(array $b): bool => $b['k'] === 'p'));
        $cards[$slug] = ['group' => $it['title'] === 'Core services' ? 'Core' : 'Supporting', 'summary' => $plain($paras[0]['html'] ?? ''),
            'best_for' => (string) preg_replace('/^Best for:\s*/', '', $plain($paras[1]['html'] ?? '')), 'card_label' => (string) ($s['link']['label'] ?? $s['title']), 'title' => $s['title']];
    }
}
$onlyPages = array_filter(explode(',', (string) getenv('RK_PAGES')));
foreach (array_reverse(SERVICE_SLUGS) as $i => $slug) {
    if ($onlyPages !== [] && !in_array($slug, $onlyPages, true) && !in_array('services', $onlyPages, true)) { continue; }
    $p = $DATA['pages'][$slug];
    $card = $cards[$slug] ?? ['group' => 'Supporting', 'summary' => '', 'best_for' => '', 'card_label' => $p['name'], 'title' => $p['name']];
    $btns = [];
    foreach ($p['intro'] as $b) { if ($b['k'] === 'btns') { $btns = $b['items']; } }
    $fields = ['group' => $card['group'], 'h1' => $p['h1'], 'intro' => sv_html(array_filter($p['intro'], static fn(array $b): bool => $b['k'] === 'p')),
        'summary' => $card['summary'], 'best_for' => $card['best_for'], 'card_label' => $card['card_label'],
        'primary_label' => $btns[0]['label'] ?? '', 'primary_url' => $btns[0]['href'] ?? '', 'secondary_label' => $btns[1]['label'] ?? '', 'secondary_url' => $btns[1]['href'] ?? ''] + sv_fields($p, $plain);
    build_page($slug, $card['title'], 'page', 'standalone', [CTOP('Body', [P($card['summary'], BONE, '18px')], ['layout' => ['display' => 'flex', 'direction' => 'column']])], [
        'seo_title' => $p['seo_title'], 'seo_desc' => $p['meta'], 'settings' => ['category' => $card['group'], 'excerpt' => $card['summary']],
        'date' => date('Y-m-d H:i:s', strtotime('2025-06-01') + $i * 3600), 'type' => 'service', 'fields' => $fields,
    ]);
}
say('  + ' . count(SERVICE_SLUGS) . ' services');
