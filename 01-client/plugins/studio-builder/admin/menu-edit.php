<?php
/**
 * Kohevo Studio — Edit one menu: rename, change its links, or delete it.
 *
 *   menu-edit.php?key=<menu key>
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
$currentNav = 'studio-menus';
$flash      = null;
$isNew      = false;
$key        = (string) ($_GET['key'] ?? $_POST['original_key'] ?? '');
$menu       = StudioMenus::find($tenantId, $key);
$menuForm   = $menu ?? ['key' => $key, 'name' => '', 'items' => []];

// Both forms carry the version of the menu they were loaded with, so a write over someone else's change is refused.
$menuError = static fn(\InvalidArgumentException $e): string => $e->getCode() === StudioMenus::CONFLICT
    ? __('studio_menu_conflict', 'Someone else changed this menu while you were editing it. Reload it and make your change again.')
    : $e->getMessage();

if ($menu !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to edit menus.')];
    } elseif (!sb_studio_entitled()) {
        $flash = ['type' => 'error', 'msg' => __('studio_pages_forbidden', 'Kohevo Studio is not available for your account on this site.')];
    } elseif ($action === 'delete') {
        try {
            StudioMenus::delete($tenantId, $key, (string) ($_POST['version'] ?? ''));
            header('Location: menus.php?deleted=1', true, 303);
            exit;
        } catch (\InvalidArgumentException $e) {
            $flash = ['type' => 'error', 'msg' => $menuError($e)];
        }
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $newKey = trim((string) ($_POST['key'] ?? ''));
        if ($newKey === '') {
            $newKey = $key;
        }
        $items = is_array($_POST['items'] ?? null) ? array_values($_POST['items']) : [];
        $menuForm = ['key' => $newKey, 'name' => $name, 'items' => array_map(static fn($r): array => is_array($r) ? ['label' => (string) ($r['label'] ?? ''), 'url' => (string) ($r['url'] ?? ''), 'new_tab' => !empty($r['new_tab'])] : [], $items)];
        try {
            StudioMenus::save($tenantId, $newKey, $name, $items, $key, (string) ($_POST['version'] ?? ''));
            header('Location: menu-edit.php?key=' . rawurlencode($newKey) . '&saved=1', true, 303);
            exit;
        } catch (\InvalidArgumentException $e) {
            $flash = ['type' => 'error', 'msg' => $menuError($e)];
        }
    }
}
if ($flash === null && isset($_GET['saved'])) {
    $flash = ['type' => 'success', 'msg' => __('studio_menu_saved', 'Menu saved.')];
}

$pageTitle = $menu !== null ? $menu['name'] : __('studio_menus', 'Menus');
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
    <?php sb_render_crumbs([[__('studio_menus', 'Menus'), 'menus.php'], [$menu !== null ? $menu['name'] : __('studio_menu_edit', 'Edit'), null]]); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($menu === null): ?>
        <div class="alert alert-error" role="status"><?= e(__('studio_menu_not_found', 'This menu does not exist, or has been deleted.')) ?></div>
        <p><a class="btn btn-outline" href="menus.php"><?= e(__('studio_menu_back', 'Back to menus')) ?></a></p>
    <?php else: ?>
    <div class="sb-screen-head">
        <div>
            <h2><?= e($menu['name']) ?></h2>
            <p><?= e(__('studio_menu_edit_sub', 'Choose this menu in a Nav menu block. Changing it here updates every page that shows it.')) ?></p>
        </div>
    </div>
    <form method="post" class="card sb-form-card">
        <?= csrf_field() ?>
        <input type="hidden" name="original_key" value="<?= e($key) ?>">
        <input type="hidden" name="version" value="<?= e($menu !== null ? StudioMenus::version($menu) : '') ?>">
        <input type="hidden" name="action" value="save">
        <?php require __DIR__ . '/_menu_form.php'; ?>
        <?php if ($canEdit): ?>
        <div class="sb-form-actions">
            <button type="submit" class="btn btn-primary"><?= sb_svg('save', 14) ?> <span><?= e(__('save', 'Save changes')) ?></span></button>
            <a href="menus.php" class="btn btn-ghost"><?= e(__('studio_menu_back', 'Back to menus')) ?></a>
        </div>
        <?php endif; ?>
    </form>
    <?php if ($canEdit): ?>
    <form method="post" class="card sb-form-card sb-danger-card" onsubmit="return window.confirm(this.getAttribute('data-confirm'));" data-confirm="<?= e(__('studio_menu_delete_confirm', 'Delete this menu? Nav menu blocks that use it fall back to their own links.')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="original_key" value="<?= e($key) ?>">
        <input type="hidden" name="version" value="<?= e($menu !== null ? StudioMenus::version($menu) : '') ?>">
        <input type="hidden" name="action" value="delete">
        <div class="card-header"><div><h3><?= e(__('studio_menu_delete', 'Delete menu')) ?></h3><div class="card-sub"><?= e(__('studio_menu_delete_sub', 'Nav menu blocks that use it fall back to their own links.')) ?></div></div></div>
        <div class="sb-form-actions" style="border-top:0; margin-top:0; padding-top:0;">
            <button type="submit" class="btn btn-danger"><?= sb_svg('trash', 14) ?> <span><?= e(__('studio_menu_delete', 'Delete menu')) ?></span></button>
        </div>
    </form>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
