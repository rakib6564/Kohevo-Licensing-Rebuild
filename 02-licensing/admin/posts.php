<?php
/**
 * Slate — Pages list (Phase 2 migration).
 *
 * Route: /admin/posts.php?type=page
 *
 * Minimal list + create view against content_pages (migration 0019), the
 * owning row for admin/editor.php's Phase 2 persistence. Replaces the previous
 * placeholder that bridged to the now-archived content-builder plugin, which
 * no longer exists in plugins/ — there is no working legacy list to preserve.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

use Slate\Services\Content\ContentServiceFactory;

Auth::require();
Auth::requirePerm('content.view');

// Same Phase 2 kill switch as admin/editor.php — hiding the nav link alone
// leaves this page (both Pages and Posts, via ?type=) fully reachable by
// direct URL for any admin who knows it.
if (Database::setting('editor_phase2_enabled') === '0') {
    $pageTitle  = __('content', 'Content');
    $currentNav = (($_GET['type'] ?? 'page') === 'post') ? 'content-post' : 'content-page';
    require __DIR__ . '/partials/header.php';
    echo '<div class="card"><div class="card-body"><p>'
        . e(__('editor_unavailable', 'The visual editor is temporarily unavailable.'))
        . '</p></div></div>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

$pages  = ContentServiceFactory::pageRepository();
$type   = preg_match('/^[a-z][a-z0-9_-]{0,31}$/', (string) ($_GET['type'] ?? 'page')) ? $_GET['type'] : 'page';
$flash  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requirePerm('content.edit');
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (($_POST['_action'] ?? '') === 'create') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug  = trim((string) ($_POST['slug'] ?? ''));
        if ($title === '') {
            $flash = ['type' => 'error', 'msg' => __('title_required', 'Title is required.')];
        } else {
            if ($slug === '') {
                $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-') ?: 'untitled';
            }
            if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,188}[a-z0-9])?$/', $slug)) {
                $flash = ['type' => 'error', 'msg' => __('invalid_slug', 'Slug must be lowercase letters, numbers, and hyphens only.')];
            } else {
                try {
                    $newId = $pages->create($type, mb_substr($title, 0, 190), mb_substr($slug, 0, 190));
                    header('Location: ' . SLATE_URL . '/admin/editor.php?id=' . $newId);
                    exit;
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => __('slug_in_use', 'That slug is already in use for this content type.')];
                }
            }
        }
    }
}

$rows = $pages->search(['type' => $type, 'search' => (string) ($_GET['q'] ?? '')]);

$pageTitle  = __('content', 'Content');
$currentNav = 'content-page';
require __DIR__ . '/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('content', 'Content')],
]); ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1><?= __('content', 'Content') ?></h1>
        <p class="page-header-sub"><?= __('content_intro', 'Pages built with the visual editor.') ?></p>
    </div>
</div>

<?php if (Auth::can('content.edit')): ?>
<div class="card" style="margin-bottom:16px;">
    <div class="card-body">
        <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="create">
            <div class="field" style="flex:1; min-width:200px; margin:0;">
                <label class="field-label" for="p_title"><?= __('title', 'Title') ?></label>
                <input type="text" id="p_title" name="title" required maxlength="190" placeholder="<?= e(__('untitled_page', 'Untitled page')) ?>">
            </div>
            <div class="field" style="flex:1; min-width:200px; margin:0;">
                <label class="field-label" for="p_slug"><?= __('slug', 'Slug (optional)') ?></label>
                <input type="text" id="p_slug" name="slug" maxlength="190" pattern="[a-z0-9]([a-z0-9-]{0,188}[a-z0-9])?" placeholder="auto-generated">
            </div>
            <button type="submit" class="btn btn-primary"><?= __('add_new', 'Add New') ?></button>
        </form>
    </div>
</div>
<?php endif; ?>

<form method="get" style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap;">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <input type="search" name="q" placeholder="<?= e(__('search_pages', 'Search by title…')) ?>"
           value="<?= e((string)($_GET['q'] ?? '')) ?>" style="flex:1; min-width:220px;">
    <button type="submit" class="btn"><?= __('filter', 'Filter') ?></button>
</form>

<?php if (empty($rows)): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title"><?= __('no_pages', 'No pages found') ?></div>
            <p><?= __('no_pages_intro', 'Create your first page to get started.') ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($rows as $r):
            ob_start(); ?>
            <a href="<?= e(SLATE_URL) ?>/admin/editor.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary"><?= __('edit', 'Edit') ?></a>
            <?php $actions = ob_get_clean();

            slate_data_row([
                'title'   => $r['title'],
                'meta'    => $r['slug'],
                'badge'   => [ucfirst($r['status']), $r['status'] === 'published' ? 'success' : 'accent'],
                'detail'  => [
                    'Type'    => $r['type'],
                    'Created' => $r['created_at'],
                    'Updated' => $r['updated_at'],
                ],
                'actions' => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
