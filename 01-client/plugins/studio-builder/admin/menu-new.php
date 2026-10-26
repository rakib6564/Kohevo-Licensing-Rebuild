<?php
/**
 * Kohevo Studio — New menu. One screen, one job: name the menu, list its links, save.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Menu\StudioMenus;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;

Auth::require();
Auth::requirePerm('studio-builder.view');

// Menus are read by the live site on every request: changing one is publishing, so it needs the publish permission.
$canEdit    = Auth::can('studio-builder.publish') || Auth::isSuperAdmin();
$tenantId   = current_tenant_id();
$pageTitle  = __('studio_menu_new', 'New menu');
$currentNav = 'studio-menus';
$flash      = null;
$isNew      = true;
$menuForm   = ['key' => '', 'name' => '', 'items' => []];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to edit menus.')];
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $key  = trim((string) ($_POST['key'] ?? ''));
        if ($key === '') {
            $key = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        }
        $items = is_array($_POST['items'] ?? null) ? array_values($_POST['items']) : [];
        $menuForm = ['key' => $key, 'name' => $name, 'items' => array_map(static fn($r): array => is_array($r) ? ['label' => (string) ($r['label'] ?? ''), 'url' => (string) ($r['url'] ?? ''), 'new_tab' => !empty($r['new_tab'])] : [], $items)];
        try {
            StudioMenus::save($tenantId, $key, $name, $items);
            header('Location: menus.php?saved=1', true, 303);
            exit;
        } catch (\InvalidArgumentException $e) {
            $flash = ['type' => 'error', 'msg' => $e->getMessage()];
        }
    }
}

$menuPaths = [];
try {
    $app = StudioRuntimeFactory::build()->app;
    foreach ($app->listPages(StudioActor::fromCurrentSession()) as $p) {
        if (in_array($p['page_type'] ?? '', ['page', 'landing'], true) && !empty($p['is_published'])) {
            $menuPaths[] = (string) $p['public_path'];
        }
    }
} catch (\Throwable) {
    // the address suggestions are a convenience, not required
}

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin-wrapper">
    <?php sb_render_admin_nav('menus'); ?>
    <?php sb_render_crumbs([[__('studio_menus', 'Menus'), 'menus.php'], [__('studio_menu_new', 'New menu'), null]]); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="alert"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if (!$canEdit): ?>
        <div class="alert alert-error" role="status"><?= e(__('forbidden', 'You do not have permission to edit menus.')) ?></div>
    <?php else: ?>
    <div class="sb-screen-head">
        <div>
            <h2><?= e(__('studio_menu_new', 'New menu')) ?></h2>
            <p><?= e(__('studio_menu_new_sub', 'Name it, list its links, then choose it in a Nav menu block.')) ?></p>
        </div>
    </div>
    <form method="post" class="card sb-form-card">
        <?= csrf_field() ?>
        <?php require __DIR__ . '/_menu_form.php'; ?>
        <div class="sb-form-actions">
            <button type="submit" class="btn btn-primary"><?= sb_svg('save', 14) ?> <span><?= e(__('studio_menu_create', 'Create menu')) ?></span></button>
            <a href="menus.php" class="btn btn-ghost"><?= e(__('cancel', 'Cancel')) ?></a>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
