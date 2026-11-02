<?php
/**
 * Restaurant — menu items CRUD (list + editor with modifier-group assignment).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

Auth::require();
Auth::requirePerm('restaurant.manage_menu');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$pageTitle  = 'Menu items';
$currentNav = 'restaurant-items';
$tid = current_tenant_id();

$flash  = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$isNew  = !empty($_GET['new']);
$DAYPARTS = ['all' => 'All day', 'breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner', 'late' => 'Late night'];

$editing = null;
if ($editId > 0) {
    $editing = Database::row("SELECT * FROM restaurant_items WHERE id = ? AND tenant_id = ?", [$editId, $tid]);
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
                $flash = ['type' => 'error', 'msg' => 'Item name is required.'];
            } else {
                $daypart = array_key_exists(($_POST['daypart'] ?? 'all'), $DAYPARTS) ? $_POST['daypart'] : 'all';
                $row = [
                    'tenant_id'   => $tid,
                    'category_id' => !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null,
                    'name'        => mb_substr($name, 0, 160),
                    'slug'        => RestaurantAPI::slugify($name, 'restaurant_items', $id > 0 ? $id : null),
                    'description' => trim((string)($_POST['description'] ?? '')) ?: null,
                    'price_cents' => max(0, (int) round(((float)($_POST['price'] ?? 0)) * 100)),
                    'tax_rate'    => max(0, (float)($_POST['tax_rate'] ?? 0)),
                    'photo_path'  => mb_substr(trim((string)($_POST['photo_path'] ?? '')), 0, 255) ?: null,
                    'daypart'     => $daypart,
                    'is_86'       => !empty($_POST['is_86']) ? 1 : 0,
                    'is_active'   => !empty($_POST['is_active']) ? 1 : 0,
                    'sort_order'  => (int)($_POST['sort_order'] ?? 0),
                ];
                if ($id > 0) {
                    Database::update('restaurant_items', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
                } else {
                    $id = Database::insert('restaurant_items', $row);
                }

                // Sync modifier-group links.
                $wantGroups = array_map('intval', (array)($_POST['groups'] ?? []));
                Database::delete('restaurant_item_modifier_groups', 'item_id = ? AND tenant_id = ?', [$id, $tid]);
                $sort = 0;
                foreach ($wantGroups as $gid) {
                    if ($gid <= 0) continue;
                    Database::insert('restaurant_item_modifier_groups', [
                        'tenant_id'  => $tid,
                        'item_id'    => $id,
                        'group_id'   => $gid,
                        'sort_order' => $sort++,
                    ]);
                }
                AuditLog::record('restaurant.item_saved', (string)$id);
                header('Location: ' . plugin_url('restaurant', 'admin/items.php'));
                exit;
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                Database::delete('restaurant_item_modifier_groups', 'item_id = ? AND tenant_id = ?', [$id, $tid]);
                Database::delete('restaurant_items', 'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('restaurant.item_deleted', (string)$id);
                header('Location: ' . plugin_url('restaurant', 'admin/items.php'));
                exit;
            }
        } elseif ($action === 'toggle_86') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $cur = (int) Database::value("SELECT is_86 FROM restaurant_items WHERE id = ? AND tenant_id = ?", [$id, $tid]);
                Database::update('restaurant_items', ['is_86' => $cur ? 0 : 1], 'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('restaurant.item_86', (string)$id, ['is_86' => $cur ? 0 : 1]);
                header('Location: ' . plugin_url('restaurant', 'admin/items.php'));
                exit;
            }
        }
    }
}

$cats     = Database::rows("SELECT id, name FROM restaurant_menu_categories WHERE tenant_id = ? ORDER BY sort_order, name", [$tid]);
$allGroups = Database::rows("SELECT id, name FROM restaurant_modifier_groups WHERE tenant_id = ? ORDER BY sort_order, name", [$tid]);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => 'Menu items'],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($isNew || $editing):
    $it = $editing ?: ['id'=>0,'category_id'=>null,'name'=>'','description'=>'','price_cents'=>0,'tax_rate'=>RestaurantAPI::defaultTaxRate(),
                       'photo_path'=>'','daypart'=>'all','is_86'=>0,'is_active'=>1,'sort_order'=>0];
    $linkedGroups = $editing
        ? array_map('intval', array_column(Database::rows("SELECT group_id FROM restaurant_item_modifier_groups WHERE item_id = ? AND tenant_id = ?", [(int)$editing['id'], $tid]), 'group_id'))
        : [];
?>
<?php slate_editor_css(); ?>
<?php slate_edit_open(['title_fallback' => 'New item']); ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">

        <?php slate_edit_backlink(['back_href' => plugin_url('restaurant', 'admin/items.php'), 'back_label' => 'All menu items']); ?>

        <?php slate_edit_hero([
            'icon'         => 'coffee',
            'title'        => $editing ? $editing['name'] : 'New item',
            'ref'          => $editing ? (int)$editing['id'] : null,
            'active'       => !empty($it['is_active']),
            'toggle_name'  => 'is_active',
            'toggle_id'    => 'is_active',
            'toggle_title' => 'Active (orderable)',
            'status_on'    => 'Active',
            'status_off'   => 'Hidden',
            'stats'        => $editing ? [
                ['Price', RestaurantAPI::money((int)$it['price_cents'])],
                ['Tax',   rtrim(rtrim(number_format((float)$it['tax_rate'], 3), '0'), '.') . '%'],
                ['Status', !empty($it['is_86']) ? '<span style="color:var(--danger);">86\'d</span>' : 'Available'],
            ] : [],
        ]); ?>

        <?php slate_edit_card_open(['icon' => 'tag', 'eyebrow' => 'General', 'title' => 'Item details']); ?>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="name">Name <span class="field-required">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="160" value="<?= e($it['name']) ?>" placeholder="Cheeseburger">
                </div>
                <div class="field">
                    <label class="field-label" for="category_id">Category</label>
                    <select id="category_id" name="category_id">
                        <option value="">— Uncategorised —</option>
                        <?php foreach ($cats as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (int)($it['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="field-label" for="description">Description</label>
                <textarea id="description" name="description" maxlength="2000" placeholder="What's in it?"><?= e((string)($it['description'] ?? '')) ?></textarea>
            </div>
            <div class="field-row field-row-3">
                <div class="field">
                    <label class="field-label" for="price">Price <span class="field-required">*</span></label>
                    <input type="number" id="price" name="price" step="0.01" min="0" required value="<?= number_format((int)$it['price_cents'] / 100, 2, '.', '') ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="tax_rate">Tax rate (%)</label>
                    <input type="number" id="tax_rate" name="tax_rate" step="0.001" min="0" value="<?= e(rtrim(rtrim(number_format((float)$it['tax_rate'], 3), '0'), '.')) ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="daypart">Daypart</label>
                    <select id="daypart" name="daypart">
                        <?php foreach ($DAYPARTS as $k => $label): ?>
                            <option value="<?= e($k) ?>" <?= ($it['daypart'] ?? 'all') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="photo_path">Photo URL / path</label>
                    <input type="text" id="photo_path" name="photo_path" maxlength="255" value="<?= e((string)($it['photo_path'] ?? '')) ?>" placeholder="/uploads/…">
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="sort_order">Sort order</label>
                    <input type="number" id="sort_order" name="sort_order" step="1" value="<?= (int)$it['sort_order'] ?>">
                </div>
            </div>
            <label class="field-check" style="display:flex;align-items:center;gap:8px;margin-top:6px;">
                <input type="checkbox" name="is_86" value="1" <?= !empty($it['is_86']) ? 'checked' : '' ?>>
                <span>86 this item — temporarily unavailable (hidden from the POS)</span>
            </label>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_card_open(['icon' => 'list', 'eyebrow' => 'Modifiers', 'title' => 'Attached modifier groups']); ?>
            <?php if (!$allGroups): ?>
                <?php slate_edit_card_note('No modifier groups yet. <a href="' . e(plugin_url('restaurant', 'admin/modifiers.php?new=1')) . '">Create one</a> to offer options like sides or temperatures.'); ?>
            <?php else: ?>
                <?php slate_edit_card_note('Toggle the groups that apply to this item. They appear when the item is added in the POS.'); ?>
                <?php foreach ($allGroups as $g): ?>
                    <?php slate_edit_toggle_row([
                        'name'    => 'groups[]',
                        'value'   => (int)$g['id'],
                        'checked' => in_array((int)$g['id'], $linkedGroups, true),
                        'label'   => $g['name'],
                    ]); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_actionbar([
            'buttons_html' =>
                '<button type="submit" class="btn btn-primary">' . ($editing ? 'Save item' : 'Create item') . '</button>'
              . '<a href="' . e(plugin_url('restaurant', 'admin/items.php')) . '" class="btn btn-ghost">Cancel</a>',
        ]); ?>
    </form>
<?php slate_edit_close(); ?>
<?php slate_editor_js(); ?>

<?php else:
    $items = Database::rows(
        "SELECT i.*, c.name AS cat_name FROM restaurant_items i
           LEFT JOIN restaurant_menu_categories c ON c.id = i.category_id
          WHERE i.tenant_id = ? ORDER BY c.sort_order, i.sort_order, i.name", [$tid]);
?>
    <div class="page-header">
        <div><h1>Menu items</h1><p class="page-header-sub"><?= count($items) ?> item(s).</p></div>
        <a href="?new=1" class="btn btn-primary">New item</a>
    </div>

    <?php if (!$items): ?>
    <div class="card"><div class="empty"><div class="empty-title">No menu items yet</div><p class="text-sm">Add your first item to start selling.</p></div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($items as $it):
            $tags = [];
            if ($it['daypart'] !== 'all') $tags[] = ucfirst($it['daypart']);
            $meta = ($it['cat_name'] ?: 'Uncategorised') . ' · ' . RestaurantAPI::money((int)$it['price_cents']) . ($tags ? ' · ' . implode(' ', $tags) : '');
            $badge = !empty($it['is_86']) ? ["86'd", 'danger'] : ($it['is_active'] ? ['Active', 'active'] : ['Hidden', 'inactive']);
            $eightySix = '<form method="post" style="display:inline;margin:0;">' . csrf_field()
                       . '<input type="hidden" name="_action" value="toggle_86"><input type="hidden" name="id" value="' . (int)$it['id'] . '">'
                       . '<button class="btn btn-sm">' . (!empty($it['is_86']) ? 'Un-86' : "86") . '</button></form>';
            $actions = '<a href="?edit=' . (int)$it['id'] . '" class="btn btn-sm">Edit</a> ' . $eightySix . ' '
                     . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'Delete this item?\')">'
                     . csrf_field()
                     . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int)$it['id'] . '">'
                     . '<button class="btn btn-sm btn-danger">Delete</button></form>';
            slate_data_row([
                'avatar'       => mb_substr($it['name'], 0, 1),
                'avatar_color' => !empty($it['is_86']) ? 'danger' : ($it['is_active'] ? 'info' : 'muted'),
                'title'        => $it['name'],
                'meta'         => $meta,
                'value'        => RestaurantAPI::money((int)$it['price_cents']),
                'badge'        => $badge,
                'detail'       => ['Category' => $it['cat_name'] ?: 'Uncategorised',
                                   'Price' => RestaurantAPI::money((int)$it['price_cents']),
                                   'Tax' => rtrim(rtrim(number_format((float)$it['tax_rate'], 3), '0'), '.') . '%',
                                   'Daypart' => ucfirst($it['daypart'])],
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
