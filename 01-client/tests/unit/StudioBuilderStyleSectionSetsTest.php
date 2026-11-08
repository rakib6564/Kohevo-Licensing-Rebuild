<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Tabs with blocks, Accordion with blocks, Timeline and Progress bars.
 *
 * Autoloader only, no database. Reuses sbsm_* (StudioBuilderStyleSectionMotionTest) and sbpm_* helpers
 * (StudioBuilderStyleSectionPageMotionTest), both loaded first, alphabetically.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;

/** A Tabs with blocks holding tabs titled `$titles`, each with one heading. */
function sbbs_tabs(array $titles, array $props = []): array
{
    return sbpm_block('layout.tabs', $props, ['children' => array_map(
        static fn(string $t): array => sbpm_block('layout.tab', ['title' => $t, 'subtitle' => "About {$t}"], ['children' => [sbpm_block('core.heading', ['text' => "{$t} panel", 'level' => 'h3'])]]),
        $titles
    )]);
}

unit('block sets: tabs take only Tab children and an accordion only Accordion rows; a timeline takes any block', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    assert_true(DocumentValidator::validate(sbsm_doc(sbpm_box([sbbs_tabs(['A', 'B'])])), $registry)->isValid(), 'tabs of tabs');
    $wrong = sbpm_block('layout.tabs', [], ['children' => [sbpm_block('core.heading', ['text' => 'x'])]]);
    assert_true(!DocumentValidator::validate(sbsm_doc(sbpm_box([$wrong])), $registry)->isValid(), 'a heading straight inside tabs is refused');
    $acc = sbpm_block('layout.accordion', [], ['children' => [sbpm_block('layout.tab', ['title' => 'x'])]]);
    assert_true(!DocumentValidator::validate(sbsm_doc(sbpm_box([$acc])), $registry)->isValid(), 'a tab inside an accordion is refused');
    $tl = sbpm_block('layout.timeline', [], ['children' => [sbpm_block('core.heading', ['text' => 'a']), sbpm_block('core.card', [])]]);
    assert_true(DocumentValidator::validate(sbsm_doc(sbpm_box([$tl])), $registry)->isValid(), 'a timeline takes any block');
    foreach (['layout.tabs', 'layout.tab', 'layout.accordion', 'layout.accordion_item', 'layout.timeline'] as $type) {
        assert_true($registry->get($type)->toEditorManifest()['allows_children'], "{$type} holds blocks");
    }
});

unit('block sets: tabs render a strip from their tabs\' titles, keyed to panels holding the blocks, all panels shown', function (): void {
    $html = sbsm_render(sbpm_box([sbbs_tabs(['Audit', 'Staging'], ['position' => 'left', 'autoplay_ms' => 6500, 'pause_on_hover' => true, 'aria_label' => 'Rules'])]));
    assert_true(str_contains($html, 'class="sb-tabset sb-tabset--left" data-sb-tabs data-sb-tabs-autoplay="6500" data-sb-tabs-pause'), $html);
    assert_true(str_contains($html, 'role="tablist" aria-label="Rules" aria-orientation="vertical"'), 'named, vertical');
    preg_match_all('/<button type="button" class="sb-tabset__tab" role="tab" id="([^"]+)-tab" data-sb-tab="\1" aria-controls="\1-panel" aria-selected="(true|false)" tabindex="(0|-1)">/', $html, $m);
    assert_eq(2, count($m[1]), 'a button per tab');
    assert_eq(['true', 'false'], $m[2], 'the first is chosen');
    foreach ($m[1] as $key) {
        assert_true(str_contains($html, 'role="tabpanel" id="' . $key . '-panel" data-sb-panel="' . $key . '" aria-labelledby="' . $key . '-tab"'), "panel for {$key}");
    }
    assert_true(!str_contains($html, ' hidden'), 'every panel ships visible');
    assert_true(str_contains($html, '<span class="sb-tabset__sub">About Audit</span><span class="sb-tabset__bar" aria-hidden="true"><i></i></span>'), 'subtitle and time bar');
    assert_true(str_contains($html, 'Staging panel'), 'the blocks are in the panels');

    $still = sbsm_render(sbpm_box([sbbs_tabs(['A', 'B'], ['autoplay_ms' => 900])]));
    assert_true(!str_contains($still, 'data-sb-tabs-autoplay') && !str_contains($still, 'sb-tabset__bar'), 'too fast to read turns autoplay off');
    assert_true(!str_contains(sbsm_render(sbpm_box([sbpm_block('layout.tabs', [])])), 'sb-tabset'), 'empty tabs render nothing on a page');
});

unit('block sets: accordion rows are the blocks\' wrappers, open or closed as set, with the core accordion\'s hooks', function (): void {
    $acc = sbpm_block('layout.accordion', ['allow_multiple' => true], ['children' => [
        sbpm_block('layout.accordion_item', ['title' => 'How much?', 'open' => true], ['children' => [sbpm_block('core.heading', ['text' => 'From $990', 'level' => 'p'])]]),
        sbpm_block('layout.accordion_item', ['title' => 'How long?'], ['children' => [sbpm_block('core.heading', ['text' => 'Two weeks', 'level' => 'p'])]]),
    ]]);
    $html = sbsm_render(sbpm_box([$acc]));
    assert_true(str_contains($html, 'class="sb-accordion-block sb-accordion-block--blocks" data-sb-accordion data-sb-allow-multiple'), $html);
    assert_eq(2, preg_match_all('/<div class="sb-block sb-block--layout-accordion-item[^"]*\bsb-accordion__item\b/', $html), 'the wrapper is the row');
    assert_true((bool) preg_match('/aria-expanded="true">How much\?<span class="sb-accordion__icon"/', $html), 'open row');
    assert_true((bool) preg_match('/aria-expanded="false">How long\?.*?role="region" aria-labelledby="[^"]+" hidden>/s', $html), 'closed row ships hidden');
});

unit('block sets: a timeline puts its steps beside a line with its colours, and progress bars carry their values', function (): void {
    $tl = sbpm_block('layout.timeline', ['line_color' => '#2a2926', 'fill_color' => 'red;x:y', 'numbered' => true], ['children' => [sbpm_block('core.heading', ['text' => 'Audit']), sbpm_block('core.heading', ['text' => 'Build'])]]);
    $html = sbsm_render(sbpm_box([$tl]));
    assert_true(str_contains($html, 'class="sb-timeline sb-timeline--numbered" data-sb-timeline style="--sb-tl-line:#2a2926"'), 'a bad colour is dropped: ' . $html);
    assert_true(str_contains($html, '<span class="sb-timeline__rail" aria-hidden="true"><span class="sb-timeline__fill"></span></span><div class="sb-timeline__steps">'), 'rail then steps');

    $bars = sbsm_render(sbpm_box([sbpm_block('core.progress', ['items' => [['label' => 'Design', 'value' => 90], ['label' => 'SEO', 'value' => 140], ['label' => '', 'value' => 5]], 'bar_color' => '#ff5a1f', 'height' => 6])]));
    assert_true(str_contains($bars, 'data-sb-progress-bars style="--sb-pg-bar:#ff5a1f;--sb-pg-h:6px"'), $bars);
    assert_true(str_contains($bars, 'role="progressbar" aria-label="Design" aria-valuemin="0" aria-valuemax="100" aria-valuenow="90"><span class="sb-progress__bar" style="--sb-pg-v:0.9">'), 'value and scale');
    assert_true(str_contains($bars, 'aria-valuenow="100"') && str_contains($bars, '>100%<'), 'clamped to 100');
    assert_eq(2, substr_count($bars, 'sb-progress__item'), 'a row without a label is left out');
});

unit('block sets: the runtime keeps each group to its own tabs and rows, and the stylesheet waits for it', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true(str_contains($js, "el.closest('[data-sb-tabs]') === group") && str_contains($js, "el.closest('[data-sb-accordion]') === group"), 'nested groups stay apart');
    assert_true(str_contains($js, "setState(item, button.getAttribute('aria-expanded') === 'true')"), 'rows open as the server set them');
    assert_true(str_contains($js, "['timeline', initTimeline]") && str_contains($js, "['progress-bars', initProgressBars]"), 'booted');
    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();
    assert_true(str_contains($css, 'html[data-sb-ready] [data-sb-timeline] .sb-timeline__fill{transform:scaleY(var(--sb-tl-p,0))}'), 'the line shows full until the runtime');
    assert_true(str_contains($css, 'html[data-sb-ready] [data-sb-progress-bars]:not(.is-in) .sb-progress__bar{transform:scaleX(0);transition:none}'), 'bars wait for the runtime, emptied at once rather than shrinking');
});

unit('block sets: the markup and CSS match the browser fixture the runtime spec drives', function (): void {
    $sections = [
        sbpm_box([sbbs_tabs(['Audit', 'Staging', 'Launch'], ['autoplay_ms' => 2000, 'pause_on_hover' => true])]),
        sbpm_box([sbpm_block('layout.accordion', [], ['children' => [
            sbpm_block('layout.accordion_item', ['title' => 'First'], ['children' => [sbpm_block('core.heading', ['text' => 'One', 'level' => 'p'])]]),
            sbpm_block('layout.accordion_item', ['title' => 'Second', 'open' => true], ['children' => [sbpm_block('core.heading', ['text' => 'Two', 'level' => 'p'])]]),
        ]])]),
        sbpm_box([sbpm_block('layout.timeline', [], ['children' => array_map(static fn(int $i): array => sbpm_block('core.heading', ['text' => "Step {$i}", 'level' => 'h3']), range(1, 4))])]),
        sbpm_box([sbpm_block('core.progress', ['items' => [['label' => 'Design', 'value' => 90], ['label' => 'SEO', 'value' => 60]]])]),
    ];
    $html = implode('', array_map('sbsm_render', $sections));
    $html = (string) preg_replace('/ data-sb-node="[^"]*" data-sb-type="[^"]*"/', '', $html);
    $html = (string) preg_replace('/\bsb-x-[a-f0-9]+/', 'sb-x', $html);
    // Block ids stay (they key tabs to panels) but are numbered in order, so the fixture is stable.
    $n = 0;
    $map = [];
    $html = (string) preg_replace_callback('/blk_[a-z0-9]+/', static function (array $m) use (&$n, &$map): string {
        return $map[$m[0]] ??= 'blk_' . str_pad((string) ++$n, 3, '0', STR_PAD_LEFT);
    }, $html);
    $all = StudioStylesheet::css();
    preg_match('/\.sb-accordion\{[^}]*\}.*?html\[data-sb-ready\] \.sb-accordion__panel\{[^}]*\}/s', $all, $acc);
    $css = '<style>' . ($acc[0] ?? '') . StudioStylesheet::blockSetsCss() . '</style>';
    $fixture = __DIR__ . '/../../plugins/studio-builder/ui/tests/fixtures/block-sets.html';
    if (getenv('SBPM_WRITE_FIXTURE') === '1') {
        file_put_contents($fixture, $css . $html . "\n");
    }
    assert_true(($acc[0] ?? '') !== '', 'the accordion rules were found');
    assert_eq(trim((string) file_get_contents($fixture)), $css . $html, 'ui/tests/fixtures/block-sets.html is stale — regenerate with SBPM_WRITE_FIXTURE=1');
});

unit('block sets: the editor canvas shows every tab titled and every row open; Preview shows them as the page does', function (): void {
    $render = static function (array $section, string $mode): string {
        $site = new \Slate\Module\StudioBuilder\Render\SiteContext('https://example.test', 'Test Site');
        $actor = \Slate\Module\StudioBuilder\Application\StudioActor::authenticated(3, [\Slate\Module\StudioBuilder\StudioPermissions::VIEW, \Slate\Module\StudioBuilder\StudioPermissions::EDIT]);
        $context = $mode === 'editor'
            ? \Slate\Module\StudioBuilder\Render\RenderContext::forEditor(SBP5S_TENANT, $site, $actor, static fn(): bool => true)
            : \Slate\Module\StudioBuilder\Render\RenderContext::forPreview(SBP5S_TENANT, $site, $actor, static fn(): bool => true);
        $renderer = new \Slate\Module\StudioBuilder\Render\DocumentRenderer(
            BlockRegistry::withAllCoreBlocks(),
            \Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry::withCoreRenderers(),
            new \Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver(new \Slate\Tenancy\TenantContext()),
            new \Slate\Module\StudioBuilder\Render\ProviderBindingResolver(new \Slate\Module\StudioBuilder\Provider\DataProviderRegistry(), new \Slate\Tenancy\TenantContext())
        );
        return $renderer->renderContainers([$section], $context, (new \Slate\Module\StudioBuilder\Render\Theme\ThemeResolver())->resolve('default'), new \Slate\Module\StudioBuilder\Render\RenderCollector(), false);
    };
    $section = sbpm_box([
        sbbs_tabs(['A', 'B']),
        sbpm_block('layout.accordion', [], ['children' => [sbpm_block('layout.accordion_item', ['title' => 'Closed row'], ['children' => [sbpm_block('core.heading', ['text' => 'x'])]])]]),
    ]);
    $editor = $render($section, 'editor');
    $preview = $render($section, 'preview');
    assert_eq(2, substr_count($editor, 'class="sb-tabset__label"'), 'each panel titled on the canvas');
    assert_true(str_contains($editor, 'aria-expanded="true">Closed row') && !str_contains($editor, ' hidden>'), 'every row open on the canvas');
    assert_true(!str_contains($preview, 'sb-tabset__label'), 'no titles in Preview');
    assert_true(str_contains($preview, 'aria-expanded="false">Closed row') && str_contains($preview, ' hidden>'), 'Preview keeps the row closed, as the page does');
});
