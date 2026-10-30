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
}
$pageTitle   = $type !== null ? (string) ($type['plural'] ?? $type['singular'] ?? $typeKey) : __('studio_pages', 'Studio Pages');
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

$search = trim((string) ($_GET['q'] ?? ''));
if ($search !== '') {
    $needle = mb_strtolower($search);
    $filteredPages = array_values(array_filter($filteredPages, static fn(array $p): bool => str_contains(mb_strtolower((string) ($p['title'] ?? '')), $needle) || str_contains(mb_strtolower((string) ($p['slug'] ?? '')), $needle)));
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
            <p><?= e($type['description'] ?? '' ?: sprintf(__('studio_type_list_sub', 'Every %s on the site. Its fields are filled in under Fields.'), mb_strtolower($singular))) ?> <a href="types-fields.php?type=<?= e(rawurlencode($typeKey)) ?>"><?= e(__('studio_type_list_fields_link', 'Edit the fields')) ?></a></p>
            <?php else: ?>
            <h2><?= e(__('studio_pages', 'Studio Pages')) ?></h2>
            <p><?= e(__('studio_pages_sub_plain', 'Your pages and landing pages. Content types have their own lists, and headers, footers and theme templates are under Templates.')) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($canEdit): ?>
        <div class="sb-screen-actions">
            <a href="<?= e($newHref) ?>" class="btn btn-primary" data-testid="new-page">
                <?= sb_svg('plus', 14) ?>
                <span><?= e($type !== null ? sprintf(__('studio_new_item_button', 'New %s'), $singular) : __('studio_new_page_button', 'New page')) ?></span>
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
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="<?= e(__('studio_search_pages', 'Search title or address')) ?>" aria-label="<?= e(__('studio_search_pages', 'Search title or address')) ?>">
                <button type="submit" class="btn btn-outline"><?= e(__('search', 'Search')) ?></button>
                <?php if ($search !== ''): ?><a href="<?= e('pages.php' . (($q = http_build_query(array_filter(['type' => $typeKey !== '' ? $typeKey : null, 'filter' => $filter !== 'all' ? $filter : null]))) !== '' ? '?' . $q : '')) ?>" class="btn btn-ghost"><?= e(__('studio_clear_search', 'Clear')) ?></a><?php endif; ?>
            </form>
        </div>

        <?php if ($filteredPages === []): ?>
        <div class="empty">
            <div class="empty-title"><?= e($pages === [] ? ($type !== null ? sprintf(__('studio_no_items_yet', 'No %s yet'), mb_strtolower($pageTitle)) : __('studio_no_pages_yet', 'No pages yet')) : __('studio_no_pages_match', 'No Studio pages match this filter')) ?></div>
            <p class="text-sm"><?= e($pages === [] ? __('studio_no_pages_first', 'Create your first page to open it in the builder.') : __('studio_no_pages_match_hint', 'Try another filter or clear the search.')) ?></p>
            <?php if ($canEdit && $pages === []): ?>
                <a href="<?= e($newHref) ?>" class="btn btn-primary"><?= sb_svg('plus', 14) ?> <span><?= e($type !== null ? sprintf(__('studio_new_item_button', 'New %s'), $singular) : __('studio_new_page_button', 'New page')) ?></span></a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <form method="post" id="studio-bulk-form">
            <?= csrf_field() ?>
            <input type="hidden" name="filter" value="<?= e($filter) ?>">
            <input type="hidden" name="type" value="<?= e($typeKey) ?>">
            <input type="hidden" name="q" value="<?= e($search) ?>">
            <input type="hidden" name="page" value="<?= $slice['page'] ?>">
            <?= PageListView::render($slice['rows'], $canEdit, plugin_url('studio-builder', 'admin/builder.php'), plugin_url('studio-builder', 'admin/preview.php'), $canEdit, 'page-edit.php', $typeLabels) ?>
        </form>
        <?php slate_pagination($slice['page'], $slice['pages'], array_diff_key($_GET, ['deleted' => 1, 'failed' => 1]), ['total' => $slice['total'], 'per_page' => $slice['per_page'], 'label' => $type !== null ? mb_strtolower($pageTitle) : __('studio_pager_label', 'pages')]); ?>
        <?php slate_data_list_script(); ?>
        <?= PageListView::selectionScript() ?>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
