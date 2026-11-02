<?php
/**
 * Restaurant — menu categories CRUD (list + inline create/edit).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

Auth::require();
Auth::requirePerm('restaurant.manage_menu');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$pageTitle  = 'Menu categories';
$currentNav = 'restaurant-categories';
$tid = current_tenant_id();

$flash  = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$isNew  = !empty($_GET['new']);

$editing = null;
if ($editId > 0) {
    $editing = Database::row("SELECT * FROM restaurant_menu_categories WHERE id = ? AND tenant_id = ?", [$editId, $tid]);
    if (!$editing) { http_response_code(404); $editId = 0; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'save') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                $flash = ['type' => 'error', 'msg' => 'Name is required.'];
            } else {
                $row = [
                    'tenant_id'   => $tid,
                    'name'        => mb_substr($name, 0, 160),
                    'slug'        => RestaurantAPI::slugify($name, 'restaurant_menu_categories', $id > 0 ? $id : null),
                    'description' => mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255) ?: null,
                    'sort_order'  => (int)($_POST['sort_order'] ?? 0),
                    'is_active'   => !empty($_POST['is_active']) ? 1 : 0,
                ];
                if ($id > 0) {
                    Database::update('restaurant_menu_categories', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
                    AuditLog::record('restaurant.category_updated', (string)$id);
                } else {
                    $id = Database::insert('restaurant_menu_categories', $row);
                    AuditLog::record('restaurant.category_created', (string)$id);
                }
                header('Location: ' . plugin_url('restaurant', 'admin/categories.php'));
                exit;
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                Database::query("UPDATE restaurant_items SET category_id = NULL WHERE category_id = ? AND tenant_id = ?", [$id, $tid]);
                Database::delete('restaurant_menu_categories', 'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('restaurant.category_deleted', (string)$id);
                header('Location: ' . plugin_url('restaurant', 'admin/categories.php'));
                exit;
            }
        }
    }
}

$categories = Database::rows(
    "SELECT c.*, (SELECT COUNT(*) FROM restaurant_items i WHERE i.category_id = c.id) AS item_count
       FROM restaurant_menu_categories c WHERE c.tenant_id = ? ORDER BY c.sort_order, c.name",
    [$tid]
);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => 'Categories'],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($isNew || $editing):
    $c = $editing ?: ['id'=>0,'name'=>'','slug'=>'','description'=>'','sort_order'=>0,'is_active'=>1];
?>
<?php slate_editor_css(); ?>
<?php slate_edit_open(['title_fallback' => 'New category']); ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">

        <?php slate_edit_backlink(['back_href' => plugin_url('restaurant', 'admin/categories.php'), 'back_label' => 'All categories']); ?>

        <?php slate_edit_hero([
            'icon'         => 'folder',
            'title'        => $editing ? $editing['name'] : 'New category',
            'ref'          => $editing ? (int)$editing['id'] : null,
            'active'       => !empty($c['is_active']),
            'toggle_name'  => 'is_active',
            'toggle_id'    => 'is_active',
            'toggle_title' => 'Active',
            'status_on'    => 'Active',
            'status_off'   => 'Hidden',
            'stats'        => $editing ? [
                ['Items', (string)(int) Database::value("SELECT COUNT(*) FROM restaurant_items WHERE category_id = ? AND tenant_id = ?", [(int)$editing['id'], $tid])],
                ['Order', (string)(int)$c['sort_order']],
                ['Slug',  '<small style="font-family:var(--font-mono);">' . e((string)($c['slug'] ?? '')) . '</small>'],
            ] : [],
        ]); ?>

        <?php slate_edit_card_open(['icon' => 'tag', 'eyebrow' => 'General', 'title' => 'Category details']); ?>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="name">Name <span class="field-required">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="160" value="<?= e($c['name']) ?>" placeholder="Appetizers">
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="sort_order">Sort order</label>
                    <input type="number" id="sort_order" name="sort_order" step="1" value="<?= (int)$c['sort_order'] ?>">
                </div>
            </div>
            <div class="field">
                <label class="field-label" for="description">Description</label>
                <input type="text" id="description" name="description" maxlength="255" value="<?= e((string)($c['description'] ?? '')) ?>" placeholder="Optional — shown on the menu">
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_actionbar([
            'buttons_html' =>
                '<button type="submit" class="btn btn-primary">' . ($editing ? 'Save changes' : 'Create category') . '</button>'
              . '<a href="' . e(plugin_url('restaurant', 'admin/categories.php')) . '" class="btn btn-ghost">Cancel</a>',
        ]); ?>
    </form>
<?php slate_edit_close(); ?>
<?php slate_editor_js(); ?>

<?php else: ?>
    <div class="page-header">
        <div><h1>Menu categories</h1><p class="page-header-sub">Sections that group your menu items.</p></div>
        <a href="?new=1" class="btn btn-primary">New category</a>
    </div>

    <?php if (!$categories): ?>
    <div class="card"><div class="empty"><div class="empty-title">No categories yet</div><p class="text-sm">Add a category, then assign items to it.</p></div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($categories as $c):
            $actions = '<a href="?edit=' . (int)$c['id'] . '" class="btn btn-sm">Edit</a> '
                     . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'Delete this category? Items keep existing but become uncategorised.\')">'
                     . csrf_field()
                     . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int)$c['id'] . '">'
                     . '<button class="btn btn-sm btn-danger">Delete</button></form>';
            slate_data_row([
                'avatar'       => mb_substr($c['name'], 0, 1),
                'avatar_color' => $c['is_active'] ? 'info' : 'muted',
                'title'        => $c['name'],
                'meta'         => $c['item_count'] . ' item(s) · order ' . (int)$c['sort_order'],
                'badge'        => $c['is_active'] ? ['Active', 'active'] : ['Hidden', 'inactive'],
                'detail'       => ['Items' => $c['item_count'], 'Sort order' => (int)$c['sort_order'],
                                   'Slug' => ['label'=>'Slug','html'=>'<code>' . e($c['slug']) . '</code>']],
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
