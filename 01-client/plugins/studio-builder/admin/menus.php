<?php
/**
 * Kohevo Studio — Menus: the list of named menus (Header menu, Footer menu, Services menu, ...).
 * A Nav menu block in the builder picks one of these; creating a menu is menu-new.php and editing one is menu-edit.php.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Menu\StudioMenus;

if (!function_exists('slate_data_row')) {
    require_once SLATE_ROOT . '/includes/ui_components.php';
}

Auth::require();
Auth::requirePerm('studio-builder.view');

// Menus are read by the live site on every request: changing one is publishing, so it needs the publish permission.
$canEdit    = Auth::can('studio-builder.publish') || Auth::isSuperAdmin();
$tenantId   = current_tenant_id();
$pageTitle  = __('studio_menus', 'Menus');
$currentNav = 'studio-menus';
$flash      = null;

if (isset($_GET['saved'])) {
    $flash = ['type' => 'success', 'msg' => __('studio_menu_saved', 'Menu saved.')];
} elseif (isset($_GET['deleted'])) {
    $flash = ['type' => 'success', 'msg' => __('studio_menu_deleted', 'Menu deleted.')];
}

$menus = StudioMenus::all($tenantId);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">

    <?php sb_render_flash($flash); ?>

    <div class="sb-screen-head">
        <div>
            <h2><?= e(__('studio_menus', 'Menus')) ?></h2>
            <p><?= e(__('studio_menus_sub', 'Named lists of links for your header, footer or a services section. Pick one in a Nav menu block and every page that uses it stays in step.')) ?></p>
        </div>
        <?php if ($canEdit): ?>
        <div class="sb-screen-actions">
            <a href="menu-new.php" class="btn btn-primary" data-testid="new-menu"><?= sb_svg('plus', 14) ?> <span><?= e(__('studio_menu_new_button', 'New menu')) ?></span></a>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <?php if ($menus === []): ?>
        <div class="empty">
            <div class="empty-title"><?= e(__('studio_menus_none', 'No menus yet')) ?></div>
            <p class="text-sm"><?= e(__('studio_menus_none_hint', 'Create a Header menu, a Footer menu or a Services menu, then choose it in a Nav menu block.')) ?></p>
            <?php if ($canEdit): ?><a href="menu-new.php" class="btn btn-primary"><?= sb_svg('plus', 14) ?> <span><?= e(__('studio_menu_new_button', 'New menu')) ?></span></a><?php endif; ?>
        </div>
        <?php else: ?>
        <div class="data-list is-columnar" data-single-open>
            <?php
            slate_data_list_head(['main' => __('studio_menu_name', 'Menu name'), 'value' => __('studio_menu_links', 'Links'), 'badge' => __('studio_menu_key', 'Key')]);
            foreach ($menus as $m) {
                slate_data_row([
                    'avatar'       => $m['name'],
                    'avatar_color' => 'muted',
                    'title'        => $m['name'],
                    'meta'         => implode(' · ', array_slice(array_column($m['items'], 'label'), 0, 4)),
                    'value'        => (string) count($m['items']),
                    'badge'        => [$m['key'], 'muted'],
                    'detail'       => [
                        'links' => ['label' => __('studio_menu_links', 'Links'), 'html' => $m['items'] === [] ? e(__('studio_menu_empty', 'No links yet')) : '<ul style="margin:0;padding-left:18px">' . implode('', array_map(static fn(array $i): string => '<li>' . e($i['label']) . ' <code>' . e($i['url']) . '</code></li>', $m['items'])) . '</ul>'],
                    ],
                    'actions'      => ($canEdit ? '<a class="btn btn-sm btn-primary" href="menu-edit.php?key=' . e(rawurlencode($m['key'])) . '">' . e(__('studio_menu_edit', 'Edit')) . '</a>' : ''),
                ]);
            }
            ?>
        </div>
        <?php slate_data_list_script(); ?>
        <?php endif; ?>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
