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

// Inline edits from the menu board (JSON): price, 86 / visible switches, drag order, bulk actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $out = function (array $r) { echo json_encode($r); exit; };
    if (!csrf_verify()) $out(['ok' => false, 'error' => __('restaurant_err_csrf', 'Security check failed. Reload the page and try again.')]);
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($i) => $i > 0));
    $own = $ids ? array_map('intval', array_column(Database::rows(
        "SELECT id FROM restaurant_items WHERE tenant_id = ? AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")",
        array_merge([$tid], $ids)), 'id')) : [];
    $ids = array_values(array_intersect($ids, $own));
    switch ((string)($_POST['op'] ?? '')) {
        case 'price':
            if (count($ids) !== 1) $out(['ok' => false, 'error' => __('restaurant_err_item', 'Item not found.')]);
            $cents = max(0, (int) round((float) str_replace(',', '.', (string)($_POST['value'] ?? '0')) * 100));
            Database::update('restaurant_items', ['price_cents' => $cents], 'id = ? AND tenant_id = ?', [$ids[0], $tid]);
            AuditLog::record('restaurant.item_price', (string)$ids[0], ['cents' => $cents]);
            $out(['ok' => true, 'price' => RestaurantAPI::money($cents), 'value' => number_format($cents / 100, 2, '.', '')]);
        case 'flag':
            $field = ($_POST['field'] ?? '') === 'is_active' ? 'is_active' : 'is_86';
            foreach ($ids as $id) {
                Database::update('restaurant_items', [$field => !empty($_POST['on']) ? 1 : 0], 'id = ? AND tenant_id = ?', [$id, $tid]);
            }
            AuditLog::record('restaurant.item_' . ($field === 'is_86' ? '86' : 'visible'), implode(',', $ids), ['on' => !empty($_POST['on'])]);
            $out(['ok' => true]);
        case 'reorder':
            foreach ($ids as $i => $id) {
                Database::update('restaurant_items', ['sort_order' => ($i + 1) * 10], 'id = ? AND tenant_id = ?', [$id, $tid]);
            }
            $out(['ok' => true]);
        case 'move':
            $cat = (int)($_POST['category_id'] ?? 0);
            if ($cat > 0 && !Database::value("SELECT 1 FROM restaurant_menu_categories WHERE id = ? AND tenant_id = ?", [$cat, $tid])) {
                $out(['ok' => false, 'error' => __('restaurant_err_category', 'Category not found.')]);
            }
            foreach ($ids as $id) {
                Database::update('restaurant_items', ['category_id' => $cat ?: null], 'id = ? AND tenant_id = ?', [$id, $tid]);
            }
            $out(['ok' => true]);
        case 'delete':
            foreach ($ids as $id) RestaurantAPI::deleteItem($id);
            $out(['ok' => true]);
    }
    $out(['ok' => false, 'error' => __('restaurant_err_action', 'Unknown action.')]);
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
                RestaurantAPI::deleteItem($id);
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
        "SELECT i.*, (SELECT COUNT(*) FROM restaurant_item_modifier_groups l WHERE l.item_id = i.id) AS group_count
           FROM restaurant_items i WHERE i.tenant_id = ? ORDER BY i.sort_order, i.name", [$tid]);
    $byCat = [];
    foreach ($items as $it) $byCat[(int)($it['category_id'] ?? 0)][] = $it;
    $sections = array_merge(array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], $cats),
                            isset($byCat[0]) ? [['id' => 0, 'name' => __('restaurant_items_uncategorised', 'Uncategorised')]] : []);
    $n86 = count(array_filter($items, fn($i) => !empty($i['is_86'])));
    $photo = function (?string $p): string {
        $p = trim((string)$p);
        if ($p === '') return '';
        return preg_match('#^https?://#', $p) ? $p : rtrim(SLATE_URL, '/') . '/' . ltrim($p, '/');
    };
?>
    <div class="page-header">
        <div><h1><?= e(__('restaurant_items', 'Menu items')) ?></h1>
            <p class="page-header-sub"><?= e(sprintf(__('restaurant_items_sub', '%1$s items in %2$s categories · %3$s sold out'), count($items), count($cats), $n86)) ?></p></div>
        <div class="toolbar">
            <a href="<?= e(plugin_url('restaurant', 'admin/categories.php')) ?>" class="btn"><?= e(__('restaurant_categories', 'Categories')) ?></a>
            <a href="?new=1" class="btn btn-primary"><?= e(__('restaurant_items_new', 'New item')) ?></a>
        </div>
    </div>

    <?php if (!$items): ?>
    <div class="card"><div class="empty"><div class="empty-title"><?= e(__('restaurant_items_empty', 'No menu items yet')) ?></div><p class="text-sm"><?= e(__('restaurant_items_empty_hint', 'Add your first item to start selling.')) ?></p></div></div>
    <?php else: ?>
    <div class="rm-tools card">
        <label class="rm-search"><input type="search" id="rmSearch" placeholder="<?= e(__('restaurant_items_search', 'Find an item')) ?>"></label>
        <div class="rm-filter" role="group">
            <button type="button" class="btn btn-sm is-active" data-show="all"><?= e(__('restaurant_pos_all', 'All')) ?></button>
            <button type="button" class="btn btn-sm" data-show="86"><?= e(__('restaurant_items_sold_out', 'Sold out')) ?></button>
            <button type="button" class="btn btn-sm" data-show="hidden"><?= e(__('restaurant_items_hidden', 'Hidden')) ?></button>
        </div>
        <span class="rm-hint text-sm text-muted"><?= e(__('restaurant_items_drag_hint', 'Drag ⋮⋮ to reorder. Prices save as you leave the box.')) ?></span>
    </div>

    <div class="rm-bulk card" id="rmBulk" hidden>
        <strong id="rmCount"></strong>
        <button type="button" class="btn btn-sm" data-bulk="86on"><?= e(__('restaurant_items_bulk_86', 'Mark sold out')) ?></button>
        <button type="button" class="btn btn-sm" data-bulk="86off"><?= e(__('restaurant_items_bulk_un86', 'Back on')) ?></button>
        <button type="button" class="btn btn-sm" data-bulk="show"><?= e(__('restaurant_items_bulk_show', 'Show')) ?></button>
        <button type="button" class="btn btn-sm" data-bulk="hide"><?= e(__('restaurant_items_bulk_hide', 'Hide')) ?></button>
        <select id="rmMoveTo" aria-label="<?= e(__('restaurant_items_bulk_move', 'Move to category')) ?>">
            <option value=""><?= e(__('restaurant_items_bulk_move', 'Move to category')) ?>…</option>
            <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
            <option value="0"><?= e(__('restaurant_items_uncategorised', 'Uncategorised')) ?></option>
        </select>
        <button type="button" class="btn btn-sm btn-danger" data-bulk="delete"><?= e(__('restaurant_items_bulk_delete', 'Delete')) ?></button>
        <button type="button" class="btn btn-sm btn-ghost" data-bulk="clear"><?= e(__('restaurant_items_bulk_clear', 'Clear selection')) ?></button>
    </div>

    <?php foreach ($sections as $sec): $list = $byCat[$sec['id']] ?? []; ?>
    <section class="card rm-cat" data-cat="<?= (int)$sec['id'] ?>">
        <div class="card-header">
            <label class="rm-check"><input type="checkbox" class="rm-all" aria-label="<?= e(sprintf(__('restaurant_items_select_cat', 'Select all in %s'), $sec['name'])) ?>"></label>
            <h2><?= e($sec['name']) ?> <span class="text-muted text-sm"><?= count($list) ?></span></h2>
            <a href="?new=1" class="btn btn-sm btn-ghost"><?= e(__('restaurant_items_add_here', '+ Item')) ?></a>
        </div>
        <ol class="rm-list">
            <?php if (!$list): ?><li class="rm-empty text-sm text-muted"><?= e(__('restaurant_items_cat_empty', 'No items in this category.')) ?></li><?php endif; ?>
            <?php foreach ($list as $it): $img = $photo($it['photo_path'] ?? ''); ?>
            <li class="rm-item<?= !empty($it['is_86']) ? ' is-86' : '' ?><?= empty($it['is_active']) ? ' is-hidden' : '' ?>" data-id="<?= (int)$it['id'] ?>"
                data-name="<?= e(mb_strtolower($it['name'] . ' ' . ($it['description'] ?? ''))) ?>">
                <span class="rm-drag" draggable="true" title="<?= e(__('restaurant_items_drag', 'Drag to reorder')) ?>" aria-hidden="true">⋮⋮</span>
                <label class="rm-check"><input type="checkbox" class="rm-sel" value="<?= (int)$it['id'] ?>" aria-label="<?= e($it['name']) ?>"></label>
                <span class="rm-thumb"<?= $img ? ' style="background-image:url(\'' . e(str_replace("'", '%27', $img)) . '\')"' : '' ?>><?= $img ? '' : e(mb_strtoupper(mb_substr($it['name'], 0, 1))) ?></span>
                <a class="rm-name" href="?edit=<?= (int)$it['id'] ?>">
                    <strong><?= e($it['name']) ?></strong>
                    <span class="text-sm text-muted"><?= e(trim(mb_substr((string)($it['description'] ?? ''), 0, 80))) ?><?= (int)$it['group_count'] ? ' · ' . e(sprintf(__('restaurant_items_groups_n', '%s option groups'), (int)$it['group_count'])) : '' ?></span>
                </a>
                <label class="rm-price"><span><?= e(trim(preg_replace('/[0-9.,\s-]/u', '', RestaurantAPI::money(0)))) ?></span>
                    <input type="number" step="0.01" min="0" inputmode="decimal" value="<?= e(number_format((int)$it['price_cents'] / 100, 2, '.', '')) ?>" aria-label="<?= e(sprintf(__('restaurant_items_price_of', 'Price of %s'), $it['name'])) ?>"></label>
                <label class="rm-switch" title="<?= e(__('restaurant_items_sold_out', 'Sold out')) ?>"><input type="checkbox" data-flag="is_86" <?= !empty($it['is_86']) ? 'checked' : '' ?>><span>86</span></label>
                <label class="rm-switch" title="<?= e(__('restaurant_items_visible', 'Visible')) ?>"><input type="checkbox" data-flag="is_active" <?= !empty($it['is_active']) ? 'checked' : '' ?>><span><?= e(__('restaurant_items_visible', 'Visible')) ?></span></label>
                <a class="btn btn-sm btn-ghost" href="?edit=<?= (int)$it['id'] ?>"><?= e(__('restaurant_items_edit', 'Edit')) ?></a>
            </li>
            <?php endforeach; ?>
        </ol>
    </section>
    <?php endforeach; ?>

    <div class="rm-toast" id="rmToast" role="status" hidden></div>
    <style>
    .rm-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 12px 16px; }
    .rm-search input { min-width: 240px; }
    .rm-filter { display: flex; gap: 6px; }
    .rm-filter .is-active { background: var(--accent); color: var(--on-accent); border-color: var(--accent); }
    .rm-hint { margin-left: auto; }
    .rm-bulk { position: sticky; top: 8px; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 10px 16px; border-color: var(--accent); box-shadow: var(--shadow-sm); }
    .rm-cat .card-header { gap: 10px; }
    .rm-cat .card-header h2 { flex: 1; }
    .rm-list { list-style: none; margin: 0; padding: 0; }
    .rm-item { display: flex; align-items: center; gap: 12px; padding: 10px 16px; border-top: 1px solid var(--border); background: var(--surface); }
    .rm-item.is-dragging { opacity: .4; }
    .rm-item.is-over { box-shadow: inset 0 2px 0 var(--accent); }
    .rm-item.is-86 .rm-name strong { text-decoration: line-through; color: var(--muted); }
    .rm-item.is-hidden { background: var(--surface-2); }
    .rm-drag { cursor: grab; color: var(--subtle); letter-spacing: -2px; user-select: none; padding: 6px 2px; }
    .rm-check input { width: 18px; height: 18px; accent-color: var(--accent); }
    .rm-thumb { flex: none; width: 48px; height: 48px; border-radius: var(--radius); background: var(--accent-soft) center / cover no-repeat; display: grid; place-items: center; font-weight: 700; color: var(--text-2); }
    .rm-name { flex: 1; min-width: 0; display: flex; flex-direction: column; color: inherit; text-decoration: none; }
    .rm-name span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rm-price { display: inline-flex; align-items: center; gap: 4px; border: 1px solid var(--border-strong); border-radius: var(--radius-sm); padding: 0 8px; background: var(--surface); }
    .rm-price input { width: 80px; border: 0; padding: 8px 0; background: transparent; text-align: right; font-variant-numeric: tabular-nums; }
    .rm-price.is-saved { border-color: var(--success); }
    .rm-switch { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-2); cursor: pointer; }
    .rm-switch input { width: 18px; height: 18px; accent-color: var(--accent); }
    .rm-empty { padding: 14px 16px; border-top: 1px solid var(--border); }
    .rm-toast { position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); padding: 10px 16px; border-radius: var(--radius); background: #111; color: #fff; font-weight: 600; z-index: 60; }
    .rm-toast.is-error { background: var(--danger); }
    @media (max-width: 760px) {
        .rm-item { flex-wrap: wrap; }
        .rm-name { flex-basis: calc(100% - 140px); }
        .rm-hint { display: none; }
        .rm-search input { min-width: 0; width: 100%; }
    }
    </style>
    <script>
    (function () {
        const csrf = <?= json_encode(csrf_token()) ?>;
        const T = <?= json_encode(__js([
            'restaurant_items_saved'         => 'Saved',
            'restaurant_items_selected'      => '%s selected',
            'restaurant_items_delete_confirm'=> 'Delete %s items? This cannot be undone.',
            'restaurant_pos_failed'          => 'That did not work. Try again.',
        ]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const post = async (data) => {
            const b = new URLSearchParams({ _ajax: '1', _csrf: csrf });
            Object.keys(data).forEach((k) => Array.isArray(data[k]) ? data[k].forEach((v) => b.append(k + '[]', v)) : b.set(k, data[k]));
            try { const r = await fetch(location.pathname, { method: 'POST', body: b, credentials: 'same-origin' }); return await r.json(); }
            catch (e) { return { ok: false }; }
        };
        const toast = (msg, bad) => { const t = document.getElementById('rmToast'); t.textContent = msg; t.className = 'rm-toast' + (bad ? ' is-error' : ''); t.hidden = false; clearTimeout(t._t); t._t = setTimeout(() => t.hidden = true, 2000); };
        const fail = (j) => toast((j && j.error) || T.restaurant_pos_failed, true);

        // Inline price
        document.querySelectorAll('.rm-price input').forEach((inp) => {
            let was = inp.value;
            inp.addEventListener('change', async () => {
                const id = inp.closest('.rm-item').dataset.id;
                const j = await post({ op: 'price', 'ids': [id], value: inp.value });
                if (!j.ok) { inp.value = was; return fail(j); }
                inp.value = was = j.value;
                const box = inp.closest('.rm-price'); box.classList.add('is-saved'); setTimeout(() => box.classList.remove('is-saved'), 1200);
                toast(T.restaurant_items_saved + ' · ' + j.price);
            });
            inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') inp.blur(); });
        });
        // 86 / visible switches
        document.querySelectorAll('[data-flag]').forEach((cb) => cb.addEventListener('change', async () => {
            const li = cb.closest('.rm-item');
            const j = await post({ op: 'flag', ids: [li.dataset.id], field: cb.dataset.flag, on: cb.checked ? '1' : '' });
            if (!j.ok) { cb.checked = !cb.checked; return fail(j); }
            li.classList.toggle(cb.dataset.flag === 'is_86' ? 'is-86' : 'is-hidden', cb.dataset.flag === 'is_86' ? cb.checked : !cb.checked);
        }));
        // Search + filter
        let show = 'all';
        const filter = () => {
            const q = document.getElementById('rmSearch').value.trim().toLowerCase();
            document.querySelectorAll('.rm-item').forEach((li) => {
                const ok = (!q || li.dataset.name.includes(q)) && (show === 'all' || (show === '86' ? li.classList.contains('is-86') : li.classList.contains('is-hidden')));
                li.hidden = !ok;
            });
        };
        document.getElementById('rmSearch').addEventListener('input', filter);
        document.querySelectorAll('[data-show]').forEach((b) => b.onclick = () => { show = b.dataset.show; document.querySelectorAll('[data-show]').forEach((x) => x.classList.toggle('is-active', x === b)); filter(); });
        // Selection + bulk
        const selected = () => Array.from(document.querySelectorAll('.rm-sel:checked')).map((c) => c.value);
        const syncBulk = () => { const n = selected().length; document.getElementById('rmBulk').hidden = !n; document.getElementById('rmCount').textContent = T.restaurant_items_selected.replace('%s', n); };
        document.querySelectorAll('.rm-sel').forEach((c) => c.addEventListener('change', syncBulk));
        document.querySelectorAll('.rm-all').forEach((all) => all.addEventListener('change', () => {
            all.closest('.rm-cat').querySelectorAll('.rm-item:not([hidden]) .rm-sel').forEach((c) => c.checked = all.checked); syncBulk();
        }));
        const bulk = async (data) => { const j = await post(Object.assign({ ids: selected() }, data)); if (!j.ok) return fail(j); location.reload(); };
        document.querySelectorAll('[data-bulk]').forEach((b) => b.onclick = () => {
            const k = b.dataset.bulk;
            if (k === 'clear') { document.querySelectorAll('.rm-sel, .rm-all').forEach((c) => c.checked = false); return syncBulk(); }
            if (k === 'delete') { if (!confirm(T.restaurant_items_delete_confirm.replace('%s', selected().length))) return; return bulk({ op: 'delete' }); }
            if (k === '86on' || k === '86off') return bulk({ op: 'flag', field: 'is_86', on: k === '86on' ? '1' : '' });
            return bulk({ op: 'flag', field: 'is_active', on: k === 'show' ? '1' : '' });
        });
        document.getElementById('rmMoveTo').addEventListener('change', (e) => { if (e.target.value !== '') bulk({ op: 'move', category_id: e.target.value }); });
        // Drag to reorder within a category (HTML5 drag on the handle)
        let drag = null;
        document.querySelectorAll('.rm-drag').forEach((h) => {
            h.addEventListener('dragstart', (e) => { drag = h.closest('.rm-item'); drag.classList.add('is-dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', drag.dataset.id); });
            h.addEventListener('dragend', () => { if (drag) drag.classList.remove('is-dragging'); document.querySelectorAll('.is-over').forEach((x) => x.classList.remove('is-over')); drag = null; });
        });
        document.querySelectorAll('.rm-item').forEach((li) => {
            li.addEventListener('dragover', (e) => { if (!drag || drag === li || drag.parentNode !== li.parentNode) return; e.preventDefault(); li.classList.add('is-over'); });
            li.addEventListener('dragleave', () => li.classList.remove('is-over'));
            li.addEventListener('drop', async (e) => {
                e.preventDefault(); li.classList.remove('is-over');
                if (!drag || drag === li || drag.parentNode !== li.parentNode) return;
                const all = Array.from(li.parentNode.children);
                li.parentNode.insertBefore(drag, all.indexOf(drag) < all.indexOf(li) ? li.nextSibling : li);
                const ids = Array.from(li.parentNode.querySelectorAll('.rm-item')).map((x) => x.dataset.id);
                const j = await post({ op: 'reorder', ids });
                j.ok ? toast(T.restaurant_items_saved) : fail(j);
            });
        });
    })();
    </script>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
