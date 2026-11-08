<?php
/**
 * rakibhasaan.com — FAQs as a content type.
 *
 * One FAQ item per question: its title is the question, its Answer field the answer, its category the topic (Cost
 * and payment, Timelines…). The type is listed but has no pages of its own (not publicly queryable): the FAQ page
 * shows each topic with a Query Loop of that category, drawn by the Loop Item: FAQ card, an accordion row.
 *
 * Required by inner.php ($DATA, $tid, helpers).
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PageRoutes;

PageMeta::savePostTypes($tid, array_replace(PageMeta::postTypes($tid), ['faq' => [
    'key' => 'faq', 'singular' => 'Question', 'plural' => 'FAQs', 'route' => '', 'is_system' => false, 'description' => 'Questions and answers, listed on the FAQ page by topic', 'show_in_menu' => true,
    'public' => true, 'publicly_queryable' => false, 'rewrite' => false, 'has_archive' => false, 'exclude_from_search' => true, 'show_in_nav_menus' => false,
    'hide_field_names' => false, 'edit_link' => true, 'menu_icon' => 'message-square', 'menu_position' => 3,
    'columns' => [], 'filters' => [],
]]));
$others = array_values(array_filter(PageMeta::allFields($tid), static fn(array $x): bool => ($x['post_type'] ?? '') !== 'faq'));
PageMeta::saveFields($tid, array_merge($others, [
    ['id' => 'f_' . substr(md5('faqanswer'), 0, 8), 'post_type' => 'faq', 'label' => 'Answer', 'key' => 'answer', 'type' => 'richtext', 'required' => true, 'placeholder' => '', 'help' => 'The topic is the item\'s category.', 'options' => []],
]));
PageRoutes::forget($tid);

// The row: the question opens its answer.
$faqRow = CTOP('Question', [
    B('layout.accordion', ['allow_multiple' => false, 'look' => 'lines', 'icon' => 'plus'], [
        B('layout.accordion_item', ['title' => 'Question', 'open' => false], [FV('answer', 'inherit', '16px', 'div', ['typography' => ['line_height' => '1.6']], [], ['classNames' => ['rk-faq-answer']])], [], ['dynamic' => ['title' => ['tag' => 'post_title']]]),
    ], ['typography' => ['size' => '19px', 'font_family' => SANS, 'weight' => '400']], ['classNames' => ['rk-faq']]),
], ['layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '0px', 'content_width' => 'full', 'padding_y' => 'none']]);
build_page('template-loop-faq', 'Loop Item: FAQ', 'system', 'standalone', [$faqRow], ['settings' => ['template_type' => 'loop', 'conditions' => ['rules' => [['action' => 'include', 'condition' => 'singular', 'value' => 'faq']]]]]);

// The questions, topic by topic, in the page's order (published oldest first; the loops list oldest first).
$n = 0;
foreach ($DATA['pages']['faq']['items'] as $it) {
    foreach ($it['blocks'] ?? [] as $b) {
        if ($b['k'] !== 'faq') { continue; }
        foreach ($b['items'] as $q) {
            $n++;
            build_page('faq-' . $n, html_entity_decode(strip_tags($q['q']), ENT_QUOTES), 'page', 'standalone', [CTOP('Body', [P(strip_tags($q['a']), BONE, '16px')], ['layout' => ['display' => 'flex', 'direction' => 'column']])], [
                'settings' => ['category' => $it['title']], 'date' => date('Y-m-d H:i:s', strtotime('2025-03-01') + $n * 3600),
                'type' => 'faq', 'fields' => ['answer' => '<p>' . $q['a'] . '</p>'],
            ]);
        }
    }
}
say('  + ' . $n . ' questions');

/** One topic of the FAQ page: its heading on the left, its questions from the FAQ items on the right. */
function S_FAQS(array $s): array
{
    $c = TC($s['theme']);
    return SEC($s['title'], [ROW([
        W(VBOX([LABEL($s['n'], $c), H2($s['title'], $c, '48px', '32px')], '16px'), '34%'),
        W(LOOP(['content_type' => 'faq', 'category' => $s['title'], 'per_page' => 24, 'columns' => 1, 'gap' => 'none', 'enable_pagination' => false, 'show_featured_image' => false, 'show_excerpt' => false, 'show_category' => false,
            'order_by' => 'published_at', 'order' => 'asc', 'empty_message' => '']), '60%'),
    ], '48px', [], ['justify' => 'between', 'align' => 'start'])], '110px', '110px', [], ['interactions' => THEME($s['theme'])]);
}
