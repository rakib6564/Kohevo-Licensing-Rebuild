<?php
/**
 * Kohevo Studio — Pages Management Console.
 *
 * The list of Studio pages: search, filter by type, open in the builder, preview, bulk delete.
 * Creating a page is page-new.php; one page's settings, duplicate and delete are page-edit.php.
 *
 * Without `?type=` it lists the site's plain pages: the pages and landing pages that have no content type. Headers,
 * footers and theme templates are on the Templates screen. With `?type=<key>` it lists the items of that content type
 * (Types & fields), each with a Fields button, and "New <type>" creates one with the type already set.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/_page_bulk.php';

use Slate\Module\StudioBuilder\Admin\PageListView;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Exception\StudioException;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PostTypeSettings;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

Auth::require();

$studioApp   = StudioRuntimeFactory::build()->app;
$studioActor = StudioActor::fromCurrentSession();
$tenantId    = current_tenant_id();
$postTypes   = PageMeta::postTypes($tenantId);
$typeKey     = (string) ($_GET['type'] ?? $_POST['type'] ?? '');
$type        = $postTypes[$typeKey] ?? null;
if ($type === null) {
    $typeKey = '';
} elseif (!PostTypeSettings::hasAdmin($type)) {
    // Show in admin is off: its items are not managed here.
    sb_flash_redirect('post-type-edit.php?key=' . rawurlencode($typeKey), ['type' => 'warning', 'msg' => __('studio_type_no_admin', 'Show in admin is off for this post type.')]);
}
$pageTitle   = $type !== null ? PostTypeSettings::label($type, 'all_items') : __('studio_pages', 'Studio Pages');
$currentNav  = $type !== null ? 'studio-type-' . $typeKey : 'studio-pages';
$flash       = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        if (($_POST['bulk_action'] ?? '') === 'delete') {
            $result = sb_archive_pages($studioApp, $studioActor, $_POST['ids'] ?? []);
            $keep = (string) ($_POST['filter'] ?? 'all');
            $back = array_filter(['type' => $typeKey, 'filter' => $keep, 'q' => trim((string) ($_POST['q'] ?? '')), 'page' => (int) ($_POST['page'] ?? 0)]);
            header('Location: pages.php?' . http_build_query($back + $result), true, 303);
            exit;
        }
    }
}

$flash = $flash ?? sb_bulk_delete_flash($_GET);

$pages = [];
$listError = null;
try {
    $pages = $studioApp->listPages($studioActor);
} catch (StudioException $e) {
    $listError = $e->httpStatus() === 403
        ? __('studio_pages_forbidden', 'Kohevo Studio is not available for your account on this site.')
        : __('studio_pages_unavailable', 'Studio pages could not be loaded.');
}
$canEdit = $studioActor->can(StudioPermissions::EDIT) && $listError === null;

// This list: the plain pages, or the items of one content type. Headers, footers and theme templates are on Templates.
$typesByPage = $listError === null ? PageMeta::typesByPage($tenantId) : [];
$pages = array_values(array_filter($pages, static fn(array $p): bool => in_array((string) ($p['page_type'] ?? ''), PageMeta::PAGE_TYPES, true)
    && ($typesByPage[(int) $p['id']] ?? '') === $typeKey));

$filter = $type === null ? (string) ($_GET['filter'] ?? 'all') : 'all';
$filteredPages = $pages;
if ($filter === 'page' || $filter === 'landing') {
    $filteredPages = array_values(array_filter($pages, static fn(array $p) => ($p['page_type'] ?? '') === $filter));
} elseif ($filter === 'homepage') {
    $filteredPages = array_values(array_filter($pages, static fn(array $p) => ($p['route_mode'] ?? '') === 'homepage'));
}

// A content type's own columns and filters, read from its items' saved values.
$typeValues = [];
$filterChoices = [];
$activeFilters = [];
$columns = [];
if ($type !== null && ($type['columns'] !== [] || $type['filters'] !== [])) {
    $typeFields = array_column(PageMeta::fieldsFor($tenantId, $typeKey), null, 'key');
    $typeValues = PageMeta::valuesByPage($tenantId, $typeKey);
    $filterChoices = PageListView::filterChoices($type['filters'], $typeFields, $typeValues);
    foreach ($type['filters'] as $f) {
        $wanted = (string) ($_GET['f'][$f['source']] ?? '');
        if ($wanted !== '' && isset($filterChoices[$f['source']][$wanted])) {
            $activeFilters[$f['source']] = $wanted;
        }
    }
    foreach ($activeFilters as $source => $wanted) {
        $filteredPages = array_values(array_filter($filteredPages, static fn(array $p): bool => in_array($wanted, PageListView::valueWords($typeValues[(int) $p['id']][$source] ?? null), true)));
    }
    $columns = PageListView::columns($type['columns'], $typeFields);
}

$search = trim((string) ($_GET['q'] ?? ''));
if ($search !== '') {
    $needle = mb_strtolower($search);
    $filteredPages = array_values(array_filter($filteredPages, static fn(array $p): bool => str_contains(mb_strtolower((string) ($p['title'] ?? '')), $needle) || str_contains(mb_strtolower((string) ($p['slug'] ?? '')), $needle)));
}
$sortKey = (string) ($_GET['sort'] ?? '');
$sortDir = ($_GET['dir'] ?? '') === 'desc' ? 'desc' : 'asc';
foreach ($columns as $column) {
    if ($column['sortable'] && $column['source'] === $sortKey) {
        $filteredPages = PageListView::sorted($filteredPages, $sortKey, $sortDir, $typeValues);
    }
}
// 20 rows a screen; the pager keeps the filter and the search.
$slice = sb_page_slice($filteredPages, $_GET['page'] ?? 1, 20);
$filterUrl = static fn(string $f): string => 'pages.php' . ($f === 'all' && $search === '' && $typeKey === '' ? '' : '?' . http_build_query(array_filter(['type' => $typeKey !== '' ? $typeKey : null, 'filter' => $f === 'all' ? null : $f, 'q' => $search !== '' ? $search : null])));
$countOf = static function (string $f) use ($pages): int {
    return match ($f) {
        'homepage' => count(array_filter($pages, static fn(array $p): bool => ($p['route_mode'] ?? '') === 'homepage')),
        'all'      => count($pages),
        default    => count(array_filter($pages, static fn(array $p): bool => ($p['page_type'] ?? '') === $f)),
    };
};
$tabs = $type !== null ? [] : ['all' => __('studio_filter_all', 'All'), 'page' => __('studio_filter_pages', 'Pages'), 'landing' => __('studio_filter_landing', 'Landing'), 'homepage' => __('studio_filter_homepage', 'Homepage')];
$singular = $type !== null ? (string) ($type['singular'] ?? $typeKey) : '';
// An item's row says its type and offers its Fields; a plain page's row says page or landing.
$typeLabels = $type !== null ? array_fill_keys(array_map(static fn(array $p): int => (int) $p['id'], $pages), $singular) : [];
$newHref = 'page-new.php' . ($typeKey !== '' ? '?type=' . rawurlencode($typeKey) : '');
$newLabel = $type !== null ? PostTypeSettings::label($type, 'add_new') : __('studio_new_page_button', 'New page');
$searchLabel = $type !== null ? PostTypeSettings::label($type, 'search_items') : __('studio_search_pages', 'Search title or address');

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">

    <?php sb_render_flash($flash); ?>

    <?php if ($listError !== null): ?>
        <div class="alert alert-error" role="status"><?= e($listError) ?></div>
    <?php else: ?>

    <div class="sb-screen-head">
        <div>
            <?php if ($type !== null): ?>
            <h2><?= e($pageTitle) ?></h2>
            <p><?= e($type['description'] ?? '' ?: sprintf(__('studio_type_list_sub', 'Every %s on the site. Its fields are filled in under Fields.'), mb_strtolower($singular))) ?> <a href="post-type-edit.php?key=<?= e(rawurlencode($typeKey)) ?>#sb-sec-fields"><?= e(__('studio_type_list_fields_link', 'Edit the fields')) ?></a></p>
            <?php else: ?>
            <h2><?= e(__('studio_pages', 'Studio Pages')) ?></h2>
            <p><?= e(__('studio_pages_sub_plain', 'Your pages and landing pages. Content types have their own lists, and headers, footers and theme templates are under Templates.')) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($canEdit): ?>
        <div class="sb-screen-actions">
            <a href="<?= e($newHref) ?>" class="btn btn-primary" data-testid="new-page">
                <?= sb_svg('plus', 14) ?>
                <span><?= e($newLabel) ?></span>
            </a>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="sb-toolbar">
            <div class="sb-pill-filter" style="margin-bottom: 0;" role="navigation" aria-label="<?= e(__('studio_filter_label', 'Filter pages')) ?>">
                <?php foreach ($tabs as $key => $label): ?>
                    <a href="<?= e($filterUrl($key)) ?>" class="sb-pill-item <?= $filter === $key ? 'active' : '' ?>"><?= e($label) ?> (<?= $countOf($key) ?>)</a>
                <?php endforeach; ?>
            </div>
            <form method="get" class="sb-search" role="search">
                <?php if ($typeKey !== ''): ?><input type="hidden" name="type" value="<?= e($typeKey) ?>"><?php endif; ?>
                <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="<?= e($searchLabel) ?>" aria-label="<?= e($searchLabel) ?>">
                <button type="submit" class="btn btn-outline"><?= e(__('search', 'Search')) ?></button>
                <?php foreach ($activeFilters as $fKey => $fValue): ?><input type="hidden" name="f[<?= e($fKey) ?>]" value="<?= e($fValue) ?>"><?php endforeach; ?>
                <?php if ($search !== ''): ?><a href="<?= e('pages.php' . (($q = http_build_query(array_filter(['type' => $typeKey !== '' ? $typeKey : null, 'filter' => $filter !== 'all' ? $filter : null]))) !== '' ? '?' . $q : '')) ?>" class="btn btn-ghost"><?= e(__('studio_clear_search', 'Clear')) ?></a><?php endif; ?>
            </form>
        </div>

        <?php if ($filterChoices !== []): ?>
        <form method="get" class="sb-admin-filters" data-testid="admin-filters" aria-label="<?= e(PostTypeSettings::label($type, 'filter_items_list')) ?>">
            <input type="hidden" name="type" value="<?= e($typeKey) ?>">
            <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>
            <?php foreach ($type['filters'] as $f): if (!isset($filterChoices[$f['source']])) { continue; } ?>
                <select name="f[<?= e($f['source']) ?>]" aria-label="<?= e(PageListView::filterTitle($f, $typeFields)) ?>" onchange="this.form.submit()">
                    <option value=""><?= e(PageListView::filterTitle($f, $typeFields)) ?></option>
                    <?php foreach ($filterChoices[$f['source']] as $value => $text): ?>
                        <option value="<?= e((string) $value) ?>"<?= ($activeFilters[$f['source']] ?? null) === (string) $value ? ' selected' : '' ?>><?= e($text) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endforeach; ?>
            <noscript><button type="submit" class="btn btn-outline"><?= e(__('studio_filter_apply', 'Filter')) ?></button></noscript>
        </form>
        <?php endif; ?>

        <?php if ($filteredPages === []): ?>
        <div class="empty">
            <div class="empty-title"><?= e($pages !== [] && $type !== null ? PostTypeSettings::label($type, 'not_found') : ($pages === [] ? ($type !== null ? sprintf(__('studio_no_items_yet', 'No %s yet'), mb_strtolower((string) $type['plural'])) : __('studio_no_pages_yet', 'No pages yet')) : __('studio_no_pages_match', 'No Studio pages match this filter'))) ?></div>
            <p class="text-sm"><?= e($pages === [] ? __('studio_no_pages_first', 'Create your first page to open it in the builder.') : __('studio_no_pages_match_hint', 'Try another filter or clear the search.')) ?></p>
            <?php if ($canEdit && $pages === []): ?>
                <a href="<?= e($newHref) ?>" class="btn btn-primary"><?= sb_svg('plus', 14) ?> <span><?= e($newLabel) ?></span></a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <form method="post" id="studio-bulk-form">
            <?= csrf_field() ?>
            <input type="hidden" name="filter" value="<?= e($filter) ?>">
            <input type="hidden" name="type" value="<?= e($typeKey) ?>">
            <input type="hidden" name="q" value="<?= e($search) ?>">
            <input type="hidden" name="page" value="<?= $slice['page'] ?>">
            <?= PageListView::render($slice['rows'], $canEdit && ($type === null || PostTypeSettings::supports($type, 'editor')), plugin_url('studio-builder', 'admin/builder.php'), plugin_url('studio-builder', 'admin/preview.php'), $canEdit, 'page-edit.php', $typeLabels, $columns, $typeValues, ['base' => array_diff_key($_GET, ['sort' => 1, 'dir' => 1, 'page' => 1, 'deleted' => 1, 'failed' => 1]), 'key' => $sortKey, 'dir' => $sortDir]) ?>
        </form>
        <?php slate_pagination($slice['page'], $slice['pages'], array_diff_key($_GET, ['deleted' => 1, 'failed' => 1]), ['total' => $slice['total'], 'per_page' => $slice['per_page'], 'label' => $type !== null ? mb_strtolower((string) $type['plural']) : __('studio_pager_label', 'pages')]); ?>
        <?php slate_data_list_script(); ?>
        <?= PageListView::selectionScript() ?>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
