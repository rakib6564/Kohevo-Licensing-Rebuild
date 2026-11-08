<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the message widget, the local time and the wave text effect.
 *
 * Autoloader only, no database. Reuses sbsm_* (StudioBuilderStyleSectionMotionTest) and sbpm_* helpers
 * (StudioBuilderStyleSectionPageMotionTest), both loaded first, alphabetically.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\LocalTimeRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\MessageWidgetRenderer;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Service\StudioFormSync;

unit('message widget: online follows the hours in the owner\'s zone, on the chosen days, past midnight too', function (): void {
    $at = static fn(string $utc): \DateTimeImmutable => new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    $week = MessageWidgetRenderer::weekdays('weekdays');
    // 2026-11-09 is a Monday. Brussels is UTC+1 in November.
    assert_true(MessageWidgetRenderer::isOnline($at('2026-11-09 08:30'), 'Europe/Brussels', '09:00', '18:00', $week), '09:30 in Brussels');
    assert_true(!MessageWidgetRenderer::isOnline($at('2026-11-09 07:30'), 'Europe/Brussels', '09:00', '18:00', $week), '08:30 in Brussels');
    assert_true(!MessageWidgetRenderer::isOnline($at('2026-11-09 17:00'), 'Europe/Brussels', '09:00', '18:00', $week), 'the end is not included');
    assert_true(!MessageWidgetRenderer::isOnline($at('2026-11-08 10:00'), 'Europe/Brussels', '09:00', '18:00', $week), 'a Sunday');
    assert_true(MessageWidgetRenderer::isOnline($at('2026-11-08 10:00'), 'Europe/Brussels', '09:00', '18:00', MessageWidgetRenderer::weekdays('everyday')), 'every day');
    // 22:00–02:00 from Friday: Saturday 01:00 is still Friday's evening; Monday 01:00 belongs to Sunday, which is off.
    assert_true(MessageWidgetRenderer::isOnline($at('2026-11-14 00:00'), 'Europe/Brussels', '22:00', '02:00', $week), 'Friday night past midnight');
    assert_true(!MessageWidgetRenderer::isOnline($at('2026-11-09 00:00'), 'Europe/Brussels', '22:00', '02:00', $week), 'Sunday night is off');
    assert_eq('UTC', MessageWidgetRenderer::zone('UTC'), 'a known zone');
    assert_eq(date_default_timezone_get(), MessageWidgetRenderer::zone('Mars/Olympus'), 'an unknown zone falls back');
    assert_eq(['A', 'B'], MessageWidgetRenderer::topics("A\n, B,\nA"), 'topics are trimmed and unique');
});

unit('message widget: renders a launcher, a hidden panel and a Forms form with topic chips as radios', function (): void {
    $block = sbpm_block('core.message_widget', ['name' => 'Rakib', 'timezone' => 'UTC', 'hours_start' => '00:00', 'hours_end' => '00:00', 'days' => 'everyday', 'topics' => "Web\nSEO", 'open_after_seconds' => 12, 'open_after_scroll' => 40, 'notify_email' => 'me@example.test']);
    assert_true(DocumentValidator::validate(sbsm_doc(sbpm_box([$block])), BlockRegistry::withAllCoreBlocks())->isValid(), 'valid');
    $html = sbsm_render(sbpm_box([$block]));
    assert_true((bool) preg_match('/class="sb-msg sb-msg--right is-online" data-sb-msg data-sb-tz="UTC" data-sb-hours="00:00-00:00" data-sb-days="1,2,3,4,5,6,7" data-sb-who="Rakib" data-sb-open-after="12" data-sb-open-scroll="40" data-sb-msg-remember>/', $html), $html);
    assert_true((bool) preg_match('/<div class="sb-msg__panel" id="([^"]+)" role="dialog" aria-modal="false" aria-labelledby="\1-title" hidden>/', $html, $m), 'panel hidden on the page');
    assert_true(str_contains($html, '<a href="#' . $m[1] . '" class="sb-msg__launcher" data-sb-msg-open aria-controls="' . $m[1] . '" aria-expanded="false">'), 'the launcher links to the panel');
    assert_true(str_contains($html, '<span data-sb-msg-status-text>Rakib · Online now</span>'), 'online now');
    $slug = StudioFormSync::slugFor($block['id']);
    assert_true(str_contains($html, 'action="https://example.test/forms/' . $slug . '" method="post" data-sb-form="message" data-sb-form-slug="' . $slug . '"'), 'posts to Forms');
    assert_eq(2, substr_count($html, 'type="radio" name="topic"'), 'a radio per topic');
    assert_true(str_contains($html, '<textarea name="message"') && str_contains($html, 'type="email" name="email"') && str_contains($html, 'name="hp_field"'), 'fields and honeypot');

    $away = sbsm_render(sbpm_box([sbpm_block('core.message_widget', ['timezone' => 'UTC', 'hours_start' => '00:00', 'hours_end' => '00:01', 'days' => 'weekdays', 'ask_name' => false, 'topics' => ''])]));
    assert_true(str_contains($away, 'is-away') || str_contains($away, 'is-online'), 'a status either way');
    assert_true(!str_contains($away, 'name="topic"') && !str_contains($away, 'name="name"'), 'no topics and no name when not asked');
});

unit('message widget: publishing writes its form into Forms with the fields it shows', function (): void {
    $block = sbpm_block('core.message_widget', ['topics' => "Web\nSEO", 'ask_name' => true, 'submit_text' => 'Go', 'notify_email' => 'bad-address']);
    $doc = sbsm_doc(sbpm_box([sbpm_block('core.heading', ['text' => 'x']), $block]));
    $forms = StudioFormSync::formsIn($doc);
    assert_eq(1, count($forms), 'found inside a section');
    $def = StudioFormSync::definitionFor($forms[0], 'Home');
    assert_eq(['name', 'email', 'topic', 'message'], array_column($def['fields'], 'name'), 'fields in order');
    assert_eq(['Web', 'SEO'], $def['fields'][2]['options'], 'topics are the options');
    assert_eq(true, $def['fields'][3]['required'], 'a message is required');
    assert_eq('Go', $def['submit_label'], 'its button');
    assert_eq(null, $def['notify_email'], 'a bad address is dropped');
    assert_eq('Home · Message widget', $def['title'], 'named after the page');
});

unit('local time: the server writes the time in the zone, and the runtime keeps it ticking', function (): void {
    $now = new \DateTimeImmutable('2026-11-09 13:05:09', new \DateTimeZone('UTC'));
    assert_eq('14:05', LocalTimeRenderer::format($now, 'Europe/Brussels', false, false), '24h');
    assert_eq('2:05:09 PM', LocalTimeRenderer::format($now, 'Europe/Brussels', true, true), '12h with seconds');
    $html = sbsm_render(sbpm_box([sbpm_block('core.local_time', ['label' => 'Local time', 'city' => 'Brussels', 'timezone' => 'Europe/Brussels', 'format' => '12h', 'show_offset' => true])]));
    assert_true((bool) preg_match('/<span class="sb-local-time__city">Brussels<\/span> <time class="sb-local-time__time" datetime="\d\d:\d\d" data-sb-clock data-sb-tz="Europe\/Brussels" data-sb-h12>\d{1,2}:\d\d [AP]M<\/time> <span class="sb-local-time__zone">GMT\+0[12]:00<\/span>/', $html), $html);
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true(str_contains($js, "['clocks', initClocks]") && str_contains($js, "['messageWidgets', initMessageWidgets]") && str_contains($js, "['wave', initWave]"), 'booted');
});

unit('wave text: takes weights and a reach, renders them, and refuses weights out of range', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $ok = sbpm_block('core.heading', ['text' => 'Rakib Hasan', 'level' => 'h2'], ['interactions' => ['text' => ['effect' => 'wave', 'low' => 200, 'high' => 900, 'radius' => 220]]]);
    assert_true(DocumentValidator::validate(sbsm_doc(sbpm_box([$ok])), $registry)->isValid(), 'valid');
    $html = sbsm_render(sbpm_box([$ok]));
    assert_true(str_contains($html, 'data-sb-wave="200,900,220"') && str_contains($html, '--sb-wave-low:200;--sb-wave-high:900'), $html);
    $bad = sbpm_block('core.heading', ['text' => 'x'], ['interactions' => ['text' => ['effect' => 'wave', 'low' => 1000]]]);
    assert_true(!DocumentValidator::validate(sbsm_doc(sbpm_box([$bad])), $registry)->isValid(), 'weight 1000 refused');
    $plain = sbsm_render(sbpm_box([sbpm_block('core.heading', ['text' => 'x'], ['interactions' => ['text' => ['effect' => 'wave']]])]));
    assert_true(str_contains($plain, 'data-sb-wave="300,800,160"'), 'defaults');
    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();
    assert_true(str_contains($css, 'html[data-sb-wave-auto] [data-sb-wave-on] .sb-wave__l{animation:sb-wave'), 'touch screens roll the weight');
    assert_true(str_contains($css, '.sb-msg__panel:target{display:block}'), 'the panel opens by its link without the runtime');
});

/** A Query Loop scope over `$rows` (as a binding gives them), public. */
function sbw_loop_scope(array $props, array $rows): \Slate\Module\StudioBuilder\Render\Block\BlockRenderScope
{
    $block = ['id' => 'loop_w', 'type' => 'core.query_loop', 'props' => $props, 'bindings' => ['items' => ['provider' => 'content.posts']]];
    return new \Slate\Module\StudioBuilder\Render\Block\BlockRenderScope(
        $block,
        \Slate\Module\StudioBuilder\Render\RenderContext::forPublic(1, new \Slate\Module\StudioBuilder\Render\SiteContext('https://example.test', 'Test Site', 'en')),
        new \Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver(new \Slate\Tenancy\TenantContext()),
        new \Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme('default', []),
        new \Slate\Module\StudioBuilder\Render\RenderCollector('nonce'),
        '',
        ['items' => $rows]
    );
}

unit('query loop: a numbered pager links the first, last and nearby pages with gaps, keeping the chosen term', function (): void {
    $numbers = new \ReflectionMethod(\Slate\Module\StudioBuilder\Render\Block\CoreRenderers\QueryLoopRenderer::class, 'pageNumbers');
    $scope = sbw_loop_scope([], []);
    $html = $numbers->invoke(null, $scope, 5, 12, 'web');
    preg_match_all('/>(\d+|…)</', $html, $m);
    assert_eq(['1', '…', '4', '5', '6', '…', '12'], $m[1], $html);
    assert_true(str_contains($html, '<span class="sb-pagination__page sb-pagination__current" aria-current="page">5</span>'), 'current is not a link');
    assert_true(str_contains($html, 'href="?filter=web&amp;page=4"'), 'pages keep the term');
    preg_match_all('/>(\d+|…)</', $numbers->invoke(null, $scope, 1, 3, ''), $few);
    assert_eq(['1', '2', '3'], $few[1], 'no gap when every page fits');
});

unit('query loop: filter chips list "All" and each term with items, in the field\'s order, linking to ?filter=', function (): void {
    $chips = new \ReflectionMethod(\Slate\Module\StudioBuilder\Render\Block\CoreRenderers\QueryLoopRenderer::class, 'chips');
    $html = $chips->invoke(new \Slate\Module\StudioBuilder\Render\Block\CoreRenderers\QueryLoopRenderer(), sbw_loop_scope(['filter_all_label' => 'Everything'], []), 'category', '', ['all' => 7, 'terms' => ['Web' => 4, 'Branding' => 3, 'Empty' => 0]], 'Web');
    assert_true(str_contains($html, '<nav class="sb-filter" aria-label="Filter"><ul class="sb-filter__list">'), $html);
    assert_true(str_contains($html, '<a class="sb-filter__chip" href="?" data-sb-filter-term="">Everything<span class="sb-filter__count">7</span></a>'), 'All has no filter');
    assert_true(str_contains($html, 'href="?filter=Web" data-sb-filter-term="Web" aria-current="true">Web<span class="sb-filter__count">4</span>'), 'chosen term is current');
    assert_true(!str_contains($html, 'Empty'), 'a term without items is left out');
    $plain = $chips->invoke(new \Slate\Module\StudioBuilder\Render\Block\CoreRenderers\QueryLoopRenderer(), sbw_loop_scope(['filter_counts' => false], []), 'category', '', ['all' => 2, 'terms' => ['A' => 2]], '');
    assert_true(!str_contains($plain, 'sb-filter__count') && str_contains($plain, 'data-sb-filter-term="" aria-current="true">All<'), 'no counts; All is current');
});

unit('query loop: each item names its page, and "open in a dialog" marks the loop for the runtime', function (): void {
    $rows = [['id' => 1, 'title' => 'One', 'url' => '/work/one'], ['id' => 2, 'title' => 'Two', 'url' => '/work/two']];
    $html = (new \Slate\Module\StudioBuilder\Render\Block\CoreRenderers\QueryLoopRenderer())->render(sbw_loop_scope(['open_in' => 'dialog', 'per_page' => 6], $rows));
    assert_true(str_contains($html, '<div class="sb-query-loop" data-sb-loop-dialog data-sb-label-close="Close" data-sb-label-prev="Previous" data-sb-label-next="Next" data-sb-label-loading="Loading…">'), $html);
    assert_true(str_contains($html, '<article class="sb-post-card sb-post-card--card" data-sb-item-url="/work/two">'), 'item url');
    $plain = (new \Slate\Module\StudioBuilder\Render\Block\CoreRenderers\QueryLoopRenderer())->render(sbw_loop_scope([], $rows));
    assert_true(str_contains($plain, '<div class="sb-query-loop">') && !str_contains($plain, 'data-sb-filter-local'), 'opens pages by default');
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true(str_contains($js, "['loopFilters', initLoopFilters]") && str_contains($js, "['loopDialogs', initLoopDialogs]"), 'booted');
    assert_true(str_contains($js, "if (pushed && !byHistory) history.back();"), 'closing goes back past the item address it pushed');
});

unit('next / previous item: the neighbours in list order, wrapping round, and the tags offered to the editor', function (): void {
    $rows = [['id' => 30, 'title' => 'C', 'slug' => 'c'], ['id' => 20, 'title' => 'B', 'slug' => 'b'], ['id' => 10, 'title' => 'A', 'slug' => 'a']];
    $mid = \Slate\Module\StudioBuilder\Meta\AdjacentItems::around(0, $rows, 20);
    assert_eq(['C', 'A'], [$mid['prev']['title'], $mid['next']['title']], 'the middle one');
    $last = \Slate\Module\StudioBuilder\Meta\AdjacentItems::around(0, $rows, 10);
    assert_eq(['B', 'C', '/c'], [$last['prev']['title'], $last['next']['title'], $last['next']['url']], 'the last one wraps to the first');
    assert_eq(['prev' => null, 'next' => null], \Slate\Module\StudioBuilder\Meta\AdjacentItems::around(0, $rows, 99), 'not in the list');
    assert_eq(['prev' => null, 'next' => null], \Slate\Module\StudioBuilder\Meta\AdjacentItems::around(0, [$rows[0]], 30), 'alone');
    $tags = array_column(\Slate\Module\StudioBuilder\Render\Dynamic\DynamicTags::forEditor(), 'kinds', 'tag');
    assert_eq(['url'], $tags['next_post_url'], 'a link');
    assert_eq(['text'], $tags['prev_post_title'], 'a title');
});

unit('taxonomy: a Taxonomy field takes several of its terms, and its values read back as terms', function (): void {
    assert_true(\Slate\Module\StudioBuilder\Meta\MetaFieldTypes::exists('terms'), 'offered');
    assert_eq(['choices'], \Slate\Module\StudioBuilder\Meta\MetaFieldTypes::all()['terms']['options'], 'takes choices');
    $field = ['key' => 'service', 'label' => 'Service', 'type' => 'terms', 'options' => ['choices' => [['value' => 'web', 'label' => 'Web'], ['value' => 'brand', 'label' => 'Branding']]]];
    assert_eq(['value' => ['web', 'brand'], 'error' => null], \Slate\Module\StudioBuilder\Meta\PageMeta::clean($field, ['web', 'brand']), 'two terms');
    assert_true(\Slate\Module\StudioBuilder\Meta\PageMeta::clean($field, ['seo'])['error'] !== null, 'an unknown term is refused');
    assert_eq(['value' => [], 'error' => null], \Slate\Module\StudioBuilder\Meta\PageMeta::clean($field, []), 'none');
    assert_eq(['web', 'brand'], \Slate\Module\StudioBuilder\Meta\PageMeta::termsOf(['web', ' brand ', 'web', '']), 'a list');
    assert_eq(['web'], \Slate\Module\StudioBuilder\Meta\PageMeta::termsOf('web'), 'one choice');
    assert_true(in_array('terms', \Slate\Module\StudioBuilder\Meta\PageMeta::TERM_TYPES, true) && in_array('select', \Slate\Module\StudioBuilder\Meta\PageMeta::TERM_TYPES, true), 'choice fields filter too');
});

unit('widgets: the markup and CSS match the browser fixture the runtime spec drives', function (): void {
    $sections = [
        sbpm_box([sbpm_block('core.message_widget', ['name' => 'Rakib', 'timezone' => 'UTC', 'hours_start' => '00:00', 'hours_end' => '00:00', 'days' => 'everyday', 'topics' => "Web\nSEO", 'open_after_scroll' => 50])]),
        sbpm_box([
            sbpm_block('core.local_time', ['label' => 'Local time', 'timezone' => 'UTC', 'seconds' => true]),
            sbpm_block('core.heading', ['text' => 'Rakib Hasan', 'level' => 'h2'], ['interactions' => ['text' => ['effect' => 'wave', 'low' => 200, 'high' => 900, 'radius' => 200]]]),
        ]),
    ];
    $html = implode('', array_map('sbsm_render', $sections));
    $rows = [
        ['id' => 1, 'title' => 'One', 'url' => '/work/one', 'category' => 'Web'],
        ['id' => 2, 'title' => 'Two', 'url' => '/work/two', 'category' => 'Brand'],
        ['id' => 3, 'title' => 'Three', 'url' => '/work/three', 'category' => 'Web'],
    ];
    $html .= '<section>' . (new \Slate\Module\StudioBuilder\Render\Block\CoreRenderers\QueryLoopRenderer())->render(sbw_loop_scope(['filter_field' => 'category', 'open_in' => 'dialog', 'show_featured_image' => false, 'per_page' => 6], $rows)) . '</section>';
    $html = (string) preg_replace('/ data-sb-node="[^"]*" data-sb-type="[^"]*"/', '', $html);
    $html = (string) preg_replace('/\bsb-x-[a-f0-9]+/', 'sb-x', $html);
    // The time and the online state depend on the clock: the runtime redraws both, so the fixture holds fixed ones.
    $html = (string) preg_replace('/(data-sb-clock[^>]*>)[^<]*</', '${1}00:00:00<', $html);
    $html = (string) preg_replace('/datetime="\d\d:\d\d"/', 'datetime="00:00"', $html);
    // The Forms slug is the block id without its underscore.
    $html = (string) preg_replace('/sb-blk[a-z0-9]+/', 'sb-blkform', $html);
    $n = 0;
    $map = [];
    $html = (string) preg_replace_callback('/blk_[a-z0-9]+/', static function (array $m) use (&$n, &$map): string {
        return $map[$m[0]] ??= 'blk_' . str_pad((string) ++$n, 3, '0', STR_PAD_LEFT);
    }, $html);
    $css = '<style>' . StudioStylesheet::widgetsCss() . '</style>';
    $fixture = __DIR__ . '/../../plugins/studio-builder/ui/tests/fixtures/widgets.html';
    if (getenv('SBPM_WRITE_FIXTURE') === '1') {
        file_put_contents($fixture, $css . $html . "\n");
    }
    assert_eq(trim((string) file_get_contents($fixture)), $css . $html, 'ui/tests/fixtures/widgets.html is stale — regenerate with SBPM_WRITE_FIXTURE=1');
});

unit('query loop: a visitor\'s ?filter= marks its chip current and hides the other items before the runtime runs', function (): void {
    $rows = [['id' => 1, 'title' => 'One', 'url' => '/w/1', 'category' => 'Web'], ['id' => 2, 'title' => 'Two', 'url' => '/w/2', 'category' => 'Brand']];
    $_GET['filter'] = 'Brand';
    try {
        $html = (new \Slate\Module\StudioBuilder\Render\Block\CoreRenderers\QueryLoopRenderer())->render(sbw_loop_scope(['filter_field' => 'category'], $rows));
    } finally {
        unset($_GET['filter']);
    }
    assert_true(str_contains($html, 'data-sb-filter-term="Brand" aria-current="true"'), $html);
    assert_true(str_contains($html, 'data-sb-item-url="/w/1" data-sb-terms="[&quot;Web&quot;]" hidden>'), 'the Web item is hidden');
    assert_true(str_contains($html, 'data-sb-item-url="/w/2" data-sb-terms="[&quot;Brand&quot;]">'), 'the Brand item shows');
});

unit('stylesheet: numeric font weights work, links never colour the message launcher or the chips, and themed sections read without the runtime', function (): void {
    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();
    foreach (range(100, 900, 100) as $w) {
        assert_true(str_contains($css, ".sb-font-{$w}{font-weight:{$w}}"), "weight {$w}");
    }
    assert_true(str_contains($css, '.sb-body .sb-msg .sb-msg__launcher[data-sb-msg-open]{color:var(--sb-text-inverse,#fff)}'), 'the launcher keeps its own colour');
    assert_true(str_contains($css, '.sb-body .sb-filter a.sb-filter__chip{color:inherit}'), 'chips are not link-coloured');
    assert_true(str_contains($css, 'html:not([data-sb-ready]) [data-sb-page-colors]{background:var(--sb-page-bg);color:var(--sb-page-fg)}'), 'page colours without the runtime');
});
