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

    assert_eq(1, $x->query('//div[contains(@class,"data-list")][contains(@class,"is-columnar")][@data-single-open]')->length, 'one single-open columnar .data-list');
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

unit('studio admin list: header row, row checkboxes outside the toggle, selection script', function (): void {
    $pages = [sbal_page(1, 'Home', true, false), sbal_page(2, 'Promo', false, false)];
    $x = sbal_xpath(PageListView::render($pages, true, '/b.php', '/p.php'));

    $head = $x->query('//div[contains(@class,"data-list-head")]');
    assert_eq(1, $head->length, 'one header row');
    $txt = preg_replace('/\s+/', ' ', $head->item(0)->textContent);
    foreach (['Title', 'Type', 'Status'] as $label) {
        assert_true(str_contains($txt, $label), "header label {$label}");
    }
    assert_eq(1, $x->query('//div[contains(@class,"data-list-head")]//input[@type="checkbox"][contains(@class,"data-list-select-all")][@aria-label]')->length, 'labelled select-all');
    $rowChecks = $x->query('//article[contains(@class,"has-select")]/label/input[@type="checkbox"][@name="ids[]"][@aria-label]');
    assert_eq(2, $rowChecks->length, 'a labelled checkbox per row, sibling of the summary');
    assert_eq('1', $rowChecks->item(0)->getAttribute('value'));
    assert_true(str_contains($rowChecks->item(0)->getAttribute('aria-label'), 'Home'));
    assert_eq(0, $x->query('//button//input')->length, 'no checkbox nested inside an expand button');
    assert_eq(1, $x->query('//div[contains(@class,"data-list-selectbar")][@role="status"]')->length, 'selection bar');

    $js = PageListView::selectionScript();
    foreach (['indeterminate', 'is-selected', 'data-list-select-all', 'data-list-clear'] as $needle) {
        assert_true(str_contains($js, $needle), "script handles {$needle}");
    }
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/studio-builder/admin/index.php');
    assert_true(str_contains($src, 'PageListView::selectionScript()'));
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
    assert_eq('<div class="data-list" data-single-open></div>', PageListView::render([], true, '/b', '/p'), 'no header for an empty list');
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/studio-builder/admin/index.php');
    assert_true(str_contains($src, 'studio_no_pages'), 'empty state retained');
    assert_true(str_contains($src, 'slate_data_list_script()'), 'shared toggle script emitted');
    assert_false(str_contains($src, '<table'), 'no bespoke table left');
    assert_true(str_contains($src, 'PageListView::render'));
});

unit('studio admin list: every translation key the list uses has a French entry', function (): void {
    $fr = require dirname(__DIR__, 2) . '/plugins/studio-builder/lang/fr.php';
    $core = require dirname(__DIR__, 2) . '/lang/fr.php';
    foreach (['studio_status_published', 'studio_status_draft', 'studio_address', 'studio_page_type', 'studio_unpublished_changes', 'studio_open_builder', 'studio_preview', 'studio_no_pages', 'studio_selected', 'studio_clear_selection', 'studio_select_all_pages', 'studio_select_page', 'studio_delete_pages', 'studio_delete_pages_confirm', 'studio_pages_deleted', 'studio_pages_deleted_some'] as $k) {
        assert_true(isset($fr[$k]), "studio-builder fr: {$k}");
    }
    foreach (['status', 'updated', 'title'] as $k) {
        assert_true(isset($core[$k]), "core fr: {$k}");
    }
});

unit('studio admin list: the selection bar offers Delete only when allowed, as a submit that carries the ticked ids and asks first', function (): void {
    $pages = [sbal_page(1, 'A', true, false), sbal_page(2, 'B', false, false)];
    $with = sbal_xpath(PageListView::render($pages, true, '/b.php', '/p.php', true));
    $button = $with->query('//div[contains(@class,"data-list-selectbar")]//button[@name="bulk_action"][@value="delete"][@type="submit"]');
    assert_eq(1, $button->length, 'one Delete button in the selection bar');
    assert_true(str_contains($button->item(0)->getAttribute('data-confirm'), '%d'), 'the confirmation names how many pages');
    assert_eq(2, $with->query('//input[@name="ids[]"]')->length, 'each row carries its id for the form');

    $without = sbal_xpath(PageListView::render($pages, true, '/b.php', '/p.php'));
    assert_eq(0, $without->query('//button[@name="bulk_action"]')->length, 'no Delete unless the page asks for it');
    $none = sbal_xpath(PageListView::render($pages, false, '/b.php', '/p.php', false));
    assert_eq(0, $none->query('//button[@name="bulk_action"]')->length, 'a viewer is never offered Delete');

    $js = PageListView::selectionScript();
    assert_true(str_contains($js, 'window.confirm') && str_contains($js, 'preventDefault'), 'the script asks before submitting and cancels on No');
});

foreach (['pages.php' => 'pages.php?', 'index.php' => 'index.php?'] as $sbalScreen => $sbalBack) {
    unit("studio admin {$sbalScreen}: the bulk delete is a CSRF-checked POST through the shared helper, and redirects back", function () use ($sbalScreen, $sbalBack): void {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/studio-builder/admin/' . $sbalScreen);
        assert_true(str_contains($src, "require_once __DIR__ . '/_page_bulk.php';"), 'loads the shared helper');
        assert_true(str_contains($src, "'bulk_action'") && str_contains($src, 'sb_archive_pages($studioApp, $studioActor, $_POST[\'ids\'] ?? [])'), 'archives the ticked pages');
        assert_true((bool) preg_match('/if \(!csrf_verify\(\)\) \{.*?sb_archive_pages/s', $src), 'only after the CSRF check');
        assert_true(str_contains($src, "header('Location: {$sbalBack}") && str_contains($src, '303'), 'redirects back to the same screen so a refresh does not repeat it');
        assert_true(str_contains($src, 'sb_bulk_delete_flash($_GET)'), 'and says how it went');
        assert_true(str_contains($src, '<form method="post" id="studio-bulk-form">') && str_contains($src, 'csrf_field()'), 'the list sits in a form with the CSRF field');
        assert_true(str_contains($src, "plugin_url('studio-builder', 'admin/preview.php'), \$canEdit, 'page-edit.php'"), 'the selection bar offers Delete to an editor');
    });
}

require_once dirname(__DIR__, 2) . '/plugins/studio-builder/admin/_page_bulk.php';

unit('studio admin bulk delete: each distinct positive id is archived once, failures are counted, at most 200 per request', function (): void {
    $app = new class {
        /** @var list<int> */
        public array $calls = [];
        public function archivePage(\Slate\Module\StudioBuilder\Application\StudioActor $actor, int $pageId): array
        {
            $this->calls[] = $pageId;
            if ($pageId === 5) {
                throw new \Slate\Module\StudioBuilder\Exception\StudioException('in use', 'STUDIO_PAGE_IN_USE', 409);
            }
            return [];
        }
    };
    $actor = \Slate\Module\StudioBuilder\Application\StudioActor::authenticated(42, [\Slate\Module\StudioBuilder\StudioPermissions::EDIT]);
    $forgotten = [];
    $forget = static function (int $pageId) use (&$forgotten): void {
        $forgotten[] = $pageId;
    };
    assert_eq(['deleted' => 2, 'failed' => 1], sb_archive_pages($app, $actor, ['3', '3', '-1', 'x', '0', '5', 7], $forget));
    assert_eq([3, 5, 7], $app->calls, 'duplicates, zero, negatives and junk are dropped');
    assert_eq([3, 7], $forgotten, 'the custom field values of each deleted page go with it, not of one that stayed');
    $app->calls = [];
    assert_eq(['deleted' => 0, 'failed' => 0], sb_archive_pages($app, $actor, 'not-a-list', $forget), 'a junk ids value deletes nothing');
    assert_eq([], $app->calls);
    sb_archive_pages($app, $actor, range(1000, 1300), $forget);
    assert_eq(200, count($app->calls), 'one request deletes at most 200 pages');
});

unit('studio admin bulk delete: the message after the redirect says how many went and how many could not', function (): void {
    assert_null(sb_bulk_delete_flash([]), 'no message on a plain visit');
    $ok = sb_bulk_delete_flash(['deleted' => '3', 'failed' => '0']);
    assert_eq('success', $ok['type']);
    assert_true(str_contains($ok['msg'], '3'), $ok['msg']);
    $some = sb_bulk_delete_flash(['deleted' => '2', 'failed' => '1']);
    assert_eq('error', $some['type']);
    assert_true(str_contains($some['msg'], '2') && str_contains($some['msg'], '1'), $some['msg']);
});

if (!empty($studioAdminListStandalone)) {
    exit(unit_summary());
}

unit('studio admin lists: one page at a time; a page past the end shows the last, a junk page the first', function (): void {
    $rows = array_map(static fn(int $i): array => ['id' => $i], range(1, 45));
    $second = sb_page_slice($rows, '2', 20);
    assert_eq([21, 40], [$second['rows'][0]['id'], $second['rows'][19]['id']], 'rows 21-40');
    assert_eq(3, $second['pages'], 'three pages');
    $past = sb_page_slice($rows, 9, 20);
    assert_eq([3, 5], [$past['page'], count($past['rows'])], 'past the end: the last page');
    assert_eq(1, sb_page_slice($rows, 'x', 20)['page'], 'junk: the first page');
    $none = sb_page_slice([], 1, 10);
    assert_eq([1, 1, []], [$none['page'], $none['pages'], $none['rows']], 'an empty list is one empty page');

    foreach (['pages.php' => 20, 'index.php' => 10] as $screen => $per) {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/studio-builder/admin/' . $screen);
        assert_true(str_contains($src, "sb_page_slice(") && str_contains($src, ", {$per});"), "{$screen}: {$per} a screen");
        assert_true(str_contains($src, 'slate_pagination('), "{$screen}: shows the pager");
        assert_true(str_contains($src, 'name="page" value='), "{$screen}: a bulk delete comes back to the same page");
    }
});

unit('studio admin content lists: an item row names its type and offers Fields; a plain page row does not', function (): void {
    $html = PageListView::render([sbal_page(7, 'Boxe Molenbeek', true, false), sbal_page(8, 'Contact', true, false)], true, '/b', '/p', true, 'page-edit.php', [7 => 'Cours']);
    $x = sbal_xpath($html);
    assert_eq(1, $x->query('//a[@href="page-edit.php?id=7#sb-meta-form"]')->length, 'the item links to its fields');
    assert_eq(0, $x->query('//a[@href="page-edit.php?id=8#sb-meta-form"]')->length, 'a plain page has no Fields button');
    assert_true(str_contains($html, '>Cours<'), 'the item shows its type name: ' . $html);
});

unit('studio admin content lists: Pages lists plain pages, ?type= one type; templates and the sidebar find the rest', function (): void {
    $dir = dirname(__DIR__, 2) . '/plugins/studio-builder';
    $pages = (string) file_get_contents($dir . '/admin/pages.php');
    assert_true(str_contains($pages, 'PageMeta::typesByPage($tenantId)') && str_contains($pages, "in_array((string) (\$p['page_type'] ?? ''), PageMeta::PAGE_TYPES, true)")
        && str_contains($pages, "(\$typesByPage[(int) \$p['id']] ?? '') === \$typeKey"), 'pages and landing pages of the chosen type only (none: the plain ones)');
    assert_false(str_contains($pages, "'header_partial' => __('studio_filter_headers'"), 'no Headers or Footers tab on Pages');
    assert_true(str_contains($pages, "'page-new.php' . (\$typeKey !== '' ? '?type='"), 'New <type> starts a new item of that type');
    $new = (string) file_get_contents($dir . '/admin/page-new.php');
    assert_true(str_contains($new, 'PageMeta::assignType($tenantId, $newId, $formValues[\'content_type\'])'), 'the new item gets its type');
    $tpl = (string) file_get_contents($dir . '/admin/templates.php');
    assert_true(str_contains($tpl, "['header_partial', 'footer_partial', 'system']") && str_contains($tpl, 'data-testid="site-parts"'), 'Templates lists the site\'s headers, footers and theme templates');
    $plugin = (string) file_get_contents($dir . '/StudioBuilder.php');
    assert_true(str_contains($plugin, "'admin/pages.php?type=' . rawurlencode((string) \$key)") && str_contains($plugin, 'PageMeta::showsInMenu($type)'), 'each type the site keeps in the sidebar has its own entry');
    $types = (string) file_get_contents($dir . '/admin/types-fields.php');
    assert_true(str_contains($types, "\$action === 'toggle_menu'") && str_contains($types, 'name="show_in_menu" value="1" checked'), 'Types & fields sets it, on by default');
});

unit('studio admin content lists: kinds read as words, type lists have their own tab, and the pager counts their items', function (): void {
    assert_eq(['Page', 'Landing', 'Header', 'Footer', 'Template'], array_map([PageListView::class, 'kindLabel'], ['page', 'landing', 'header_partial', 'footer_partial', 'system']), 'stored kinds as words');
    $html = PageListView::render([sbal_page(3, 'Header', true, false, 'header_partial')], true, '/b', '/p');
    assert_true(str_contains($html, '>Header<') && !str_contains($html, '>header_partial<'), 'a header row says Header: ' . $html);
    $dir = dirname(__DIR__, 2) . '/plugins/studio-builder/admin';
    $nav = (string) file_get_contents($dir . '/_nav.php');
    assert_true(str_contains($nav, "'id' => 'type-' . \$key") && str_contains($nav, 'array_splice($items, 2, 0, $typeTabs)'), 'a tab per type, after Pages');
    foreach (['pages.php' => "\$type !== null ? 'type-' . \$typeKey : 'pages'", 'page-edit.php' => "\$itemType !== null ? 'type-' . \$itemTypeKey : 'pages'", 'page-new.php' => "\$newType !== null ? 'type-' . \$formValues['content_type'] : 'pages'"] as $file => $active) {
        assert_true(str_contains((string) file_get_contents($dir . '/' . $file), "sb_render_admin_nav({$active})"), "{$file} marks its type's tab");
    }
    assert_true(str_contains((string) file_get_contents($dir . '/pages.php'), "'label' => \$type !== null ? mb_strtolower(\$pageTitle)"), 'the pager counts "3 cours", not pages');
});
