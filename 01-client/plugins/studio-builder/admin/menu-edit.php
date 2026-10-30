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
use Slate\Module\StudioBuilder\Menu\MenuSources;
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
        $menuForm = ['key' => $newKey, 'name' => $name, 'items' => array_map(static fn($r): array => is_array($r) ? ['label' => (string) ($r['label'] ?? ''), 'url' => (string) ($r['url'] ?? ''), 'new_tab' => !empty($r['new_tab']), 'class' => (string) ($r['class'] ?? ''), 'depth' => (int) ($r['depth'] ?? 0)] : [], $items)];
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
$menuSources = [];
try {
    $menuSources = MenuSources::forTenant($tenantId, StudioRuntimeFactory::build()->app->listPages(StudioActor::fromCurrentSession()));
} catch (\Throwable) {
    // the Add menu items column is a convenience: custom links still work without it
}

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">
    <?php sb_render_crumbs([[__('studio_menus', 'Menus'), 'menus.php'], [$menu !== null ? $menu['name'] : __('studio_menu_edit', 'Edit'), null]]); ?>

    <?php sb_render_flash($flash); ?>

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
    <form method="post" class="sb-menu-form">
        <?= csrf_field() ?>
        <input type="hidden" name="original_key" value="<?= e($key) ?>">
        <input type="hidden" name="version" value="<?= e(StudioMenus::version($menu)) ?>">
        <input type="hidden" name="action" value="save">
        <?php $menuSubmitLabel = __('studio_menu_save', 'Save menu'); $menuDeleteForm = $canEdit ? 'sb-menu-delete' : ''; require __DIR__ . '/_menu_form.php'; ?>
    </form>
    <?php if ($canEdit): ?>
    <form method="post" id="sb-menu-delete" hidden onsubmit="return window.confirm(this.getAttribute('data-confirm'));" data-confirm="<?= e(__('studio_menu_delete_confirm', 'Delete this menu? Nav menu blocks that use it fall back to their own links.')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="original_key" value="<?= e($key) ?>">
        <input type="hidden" name="version" value="<?= e(StudioMenus::version($menu)) ?>">
        <input type="hidden" name="action" value="delete">
    </form>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
