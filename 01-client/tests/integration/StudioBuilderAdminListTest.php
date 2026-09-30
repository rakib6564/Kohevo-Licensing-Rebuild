<?php
/**
 * Kohevo Studio — the admin "Studio pages" list uses the shared Kohevo
 * expandable data-row component (markup contract only; the expand/collapse
 * script is the platform's own and is exercised in a browser).
 *
 * No database: the view is presentation over already-resolved page views.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!function_exists('unit')) {
    require_once dirname(__DIR__, 2) . '/config.php';
    require_once dirname(__DIR__) . '/guard.php';
    slate_require_test_database();
    require_once dirname(__DIR__) . '/unit/harness.php';
    $studioAdminListStandalone = true;
}

use Slate\Module\StudioBuilder\Admin\PageListView;

function sbal_page(int $id, string $title, bool $published, bool $changes, string $type = 'page', string $path = ''): array
{
    return [
        'id' => $id, 'title' => $title, 'page_type' => $type, 'public_path' => $path !== '' ? $path : '/p' . $id,
        'is_published' => $published, 'has_unpublished_changes' => $changes, 'updated_at' => '2026-10-01 09:30:00',
    ];
}

function sbal_xpath(string $html): DOMXPath
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
    libxml_clear_errors();
    return new DOMXPath($dom);
}

unit('studio admin list: rows use the shared data-row contract (collapsed, accessible, one chevron each)', function (): void {
    $pages = [sbal_page(1, 'Home', true, true, 'page', '/'), sbal_page(2, 'Promo', false, false, 'landing')];
    $x = sbal_xpath(PageListView::render($pages, true, '/b.php', '/p.php'));

    assert_eq(1, $x->query('//div[contains(@class,"data-list")][@data-single-open]')->length, 'one single-open .data-list');
    assert_eq(2, $x->query('//article[contains(@class,"data-row")]')->length, 'one row per page');
    assert_eq(0, $x->query('//table | //tr')->length, 'no table markup');
    $btns = $x->query('//button[contains(@class,"data-row-summary")]');
    assert_eq(2, $btns->length, 'a real <button> toggle per row');
    foreach ($btns as $b) {
        assert_eq('false', $b->getAttribute('aria-expanded'), 'rows start collapsed');
        assert_false($b->hasAttribute('disabled'), 'every Studio row has detail, so every row expands');
    }
    assert_eq(2, $x->query('//button//svg[contains(@class,"data-row-chevron")]')->length, 'chevron inside each toggle');
    assert_eq(2, $x->query('//div[contains(@class,"data-row-detail")][@hidden]')->length, 'detail is hidden until expanded');
    assert_eq(2, $x->query('//div[contains(@class,"data-row-detail")]/dl[contains(@class,"data-row-grid")]')->length, 'labeled fields');
});

unit('studio admin list: status pill, metadata fields and actions', function (): void {
    $pages = [sbal_page(1, 'Home', true, true, 'page', '/'), sbal_page(2, 'Promo', false, false, 'landing', '/promo')];
    $x = sbal_xpath(PageListView::render($pages, true, '/b.php', '/p.php'));

    $badges = $x->query('//button/span[contains(@class,"badge")]');
    assert_eq('Published', trim($badges->item(0)->textContent));
    assert_true(str_contains($badges->item(0)->getAttribute('class'), 'badge-active'));
    assert_eq('Draft', trim($badges->item(1)->textContent));
    assert_true(str_contains($badges->item(1)->getAttribute('class'), 'badge-warning'));

    $details = $x->query('//div[contains(@class,"data-row-detail")]');
    $first = $details->item(0)->textContent;
    assert_true(str_contains($first, 'unpublished changes'), 'published page with changes says so in the Status field');
    assert_false(str_contains($details->item(1)->textContent, 'unpublished changes'), 'draft page does not');
    assert_true(str_contains($first, '/') && str_contains($first, 'Address') && str_contains($first, 'Type') && str_contains($first, 'Updated'));

    $acts = $x->query('//div[contains(@class,"data-row-actions")]')->item(0);
    $links = $x->query('.//a', $acts);
    assert_eq(2, $links->length);
    assert_eq('/b.php?page=1', $links->item(0)->getAttribute('href'));
    assert_true(str_contains($links->item(0)->getAttribute('class'), 'btn btn-sm btn-primary'), 'existing Kohevo button classes');
    assert_eq('/p.php?page=1', $links->item(1)->getAttribute('href'));
    assert_eq('noopener', $links->item(1)->getAttribute('rel'));
});

unit('studio admin list: without edit permission only Preview is offered; values are escaped', function (): void {
    $x = sbal_xpath(PageListView::render([sbal_page(3, '<script>alert(1)</script>', true, false, 'page', '/x"y')], false, '/b.php', '/p.php'));
    assert_eq(1, $x->query('//div[contains(@class,"data-row-actions")]//a')->length, 'Preview only');
    assert_eq(0, $x->query('//a[contains(@href,"/b.php")]')->length, 'no builder link without edit');
    assert_eq(0, $x->query('//script')->length, 'title is escaped');
});

unit('studio admin list: empty input renders an empty list; index.php keeps its empty state and loads the shared script', function (): void {
    assert_eq('<div class="data-list" data-single-open></div>', PageListView::render([], true, '/b', '/p'));
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/studio-builder/admin/index.php');
    assert_true(str_contains($src, 'studio_no_pages'), 'empty state retained');
    assert_true(str_contains($src, 'slate_data_list_script()'), 'shared toggle script emitted');
    assert_false(str_contains($src, '<table'), 'no bespoke table left');
    assert_true(str_contains($src, 'PageListView::render'));
});

unit('studio admin list: every translation key the list uses has a French entry', function (): void {
    $fr = require dirname(__DIR__, 2) . '/plugins/studio-builder/lang/fr.php';
    $core = require dirname(__DIR__, 2) . '/lang/fr.php';
    foreach (['studio_status_published', 'studio_status_draft', 'studio_address', 'studio_page_type', 'studio_unpublished_changes', 'studio_open_builder', 'studio_preview', 'studio_no_pages'] as $k) {
        assert_true(isset($fr[$k]), "studio-builder fr: {$k}");
    }
    foreach (['status', 'updated'] as $k) {
        assert_true(isset($core[$k]), "core fr: {$k}");
    }
});

if (!empty($studioAdminListStandalone)) {
    exit(unit_summary());
}
