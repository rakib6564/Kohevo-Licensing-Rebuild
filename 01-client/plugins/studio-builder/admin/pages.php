<?php
/**
 * Kohevo Studio — Pages Management Console.
 *
 * The list of Studio pages: search, filter by type, open in the builder, preview, bulk delete.
 * Creating a page is page-new.php; one page's settings, duplicate and delete are page-edit.php.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Admin\PageListView;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Exception\StudioException;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

Auth::require();

$studioApp   = StudioRuntimeFactory::build()->app;
$studioActor = StudioActor::fromCurrentSession();
$pageTitle   = __('studio_pages', 'Studio Pages');
$currentNav  = 'studio-pages';
$flash       = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        if (($_POST['bulk_action'] ?? '') === 'delete') {
            // Deleting a page is archiving it: it leaves the site and the list, and its history stays. A page that cannot go
            // (a Global Component other pages still use) is skipped and counted, not allowed to stop the rest.
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn(int $i): bool => $i > 0)));
            $deleted = 0;
            $failed = 0;
            foreach (array_slice($ids, 0, 200) as $pageId) {
                try {
                    $studioApp->archivePage($studioActor, $pageId);
                    $deleted++;
                } catch (StudioException $e) {
                    $failed++;
                }
            }
            $keep = (string) ($_POST['filter'] ?? 'all');
            header('Location: pages.php?' . http_build_query(['filter' => $keep, 'deleted' => $deleted, 'failed' => $failed]), true, 303);
            exit;
        }
    }
}

if (isset($_GET['deleted']) || isset($_GET['failed'])) {
    $nDeleted = (int) ($_GET['deleted'] ?? 0);
    $nFailed  = (int) ($_GET['failed'] ?? 0);
    $flash = $nFailed > 0
        ? ['type' => 'error', 'msg' => sprintf(__('studio_pages_deleted_some', '%1$d page(s) deleted, %2$d could not be (a shared component that other pages still use).'), $nDeleted, $nFailed)]
        : ['type' => 'success', 'msg' => sprintf(__('studio_pages_deleted', '%d page(s) deleted.'), $nDeleted)];
}

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

$filter = (string) ($_GET['filter'] ?? 'all');
$filteredPages = $pages;
if ($filter === 'page' || $filter === 'landing' || $filter === 'header_partial' || $filter === 'footer_partial') {
    $filteredPages = array_values(array_filter($pages, static fn(array $p) => ($p['page_type'] ?? '') === $filter));
} elseif ($filter === 'homepage') {
    $filteredPages = array_values(array_filter($pages, static fn(array $p) => ($p['route_mode'] ?? '') === 'homepage'));
}

$search = trim((string) ($_GET['q'] ?? ''));
if ($search !== '') {
    $needle = mb_strtolower($search);
    $filteredPages = array_values(array_filter($filteredPages, static fn(array $p): bool => str_contains(mb_strtolower((string) ($p['title'] ?? '')), $needle) || str_contains(mb_strtolower((string) ($p['slug'] ?? '')), $needle)));
}
$filterUrl = static fn(string $f): string => 'pages.php' . ($f === 'all' && $search === '' ? '' : '?' . http_build_query(array_filter(['filter' => $f === 'all' ? null : $f, 'q' => $search !== '' ? $search : null])));
$countOf = static function (string $f) use ($pages): int {
    return match ($f) {
        'homepage' => count(array_filter($pages, static fn(array $p): bool => ($p['route_mode'] ?? '') === 'homepage')),
        'all'      => count($pages),
        default    => count(array_filter($pages, static fn(array $p): bool => ($p['page_type'] ?? '') === $f)),
    };
};
$tabs = ['all' => __('studio_filter_all', 'All'), 'page' => __('studio_filter_pages', 'Pages'), 'landing' => __('studio_filter_landing', 'Landing'), 'homepage' => __('studio_filter_homepage', 'Homepage'), 'header_partial' => __('studio_filter_headers', 'Headers'), 'footer_partial' => __('studio_filter_footers', 'Footers')];

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin-wrapper">
    <?php sb_render_admin_nav('pages'); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($listError !== null): ?>
        <div class="alert alert-error" role="status"><?= e($listError) ?></div>
    <?php else: ?>

    <div class="sb-screen-head">
        <div>
            <h2><?= e(__('studio_pages', 'Studio Pages')) ?></h2>
            <p><?= e(__('studio_pages_sub', 'Your published pages, drafts, headers and footers.')) ?></p>
        </div>
        <?php if ($canEdit): ?>
        <div class="sb-screen-actions">
            <a href="page-new.php" class="btn btn-primary" data-testid="new-page">
                <?= sb_svg('plus', 14) ?>
                <span><?= e(__('studio_new_page_button', 'New page')) ?></span>
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
                <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="<?= e(__('studio_search_pages', 'Search title or address')) ?>" aria-label="<?= e(__('studio_search_pages', 'Search title or address')) ?>">
                <button type="submit" class="btn btn-outline"><?= e(__('search', 'Search')) ?></button>
                <?php if ($search !== ''): ?><a href="<?= e('pages.php' . ($filter !== 'all' ? '?filter=' . urlencode($filter) : '')) ?>" class="btn btn-ghost"><?= e(__('studio_clear_search', 'Clear')) ?></a><?php endif; ?>
            </form>
        </div>

        <?php if ($filteredPages === []): ?>
        <div class="empty">
            <div class="empty-title"><?= e($pages === [] ? __('studio_no_pages_yet', 'No pages yet') : __('studio_no_pages', 'No Studio pages match this filter')) ?></div>
            <p class="text-sm"><?= e($pages === [] ? __('studio_no_pages_first', 'Create your first page to open it in the builder.') : __('studio_no_pages_hint', 'Try another filter or clear the search.')) ?></p>
            <?php if ($canEdit && $pages === []): ?>
                <a href="page-new.php" class="btn btn-primary"><?= sb_svg('plus', 14) ?> <span><?= e(__('studio_new_page_button', 'New page')) ?></span></a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <form method="post" id="studio-bulk-form">
            <?= csrf_field() ?>
            <input type="hidden" name="filter" value="<?= e($filter) ?>">
            <?= PageListView::render($filteredPages, $canEdit, plugin_url('studio-builder', 'admin/builder.php'), plugin_url('studio-builder', 'admin/preview.php'), $canEdit, 'page-edit.php') ?>
        </form>
        <?php slate_data_list_script(); ?>
        <?= PageListView::selectionScript() ?>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
