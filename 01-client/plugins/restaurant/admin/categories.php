<?php
/**
 * Restaurant — menu categories CRUD (list + inline create/edit).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';
require_once SLATE_ROOT . '/includes/record_editor.php';
require_once dirname(__DIR__) . '/includes/preorder.php';

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
            // Pre-order menu: its ordering window and pickup dates must read cleanly, or nothing is saved.
            $isPre   = !empty($_POST['preorder']);
            $poFrom  = trim((string)($_POST['preorder_from'] ?? ''));
            $poUntil = trim((string)($_POST['preorder_until'] ?? ''));
            $poDates = RestaurantPreorder::parseDates(mb_substr((string)($_POST['preorder_dates'] ?? ''), 0, 4000));
            $tz      = RestaurantHours::tz();
            $fromAt  = $poFrom !== '' ? RestaurantPreorder::at($poFrom, $tz) : null;
            $untilAt = $poUntil !== '' ? RestaurantPreorder::at($poUntil, $tz) : null;
            $poError = null;
            if ($poDates['bad']) $poError = sprintf(__('restaurant_po_dates_bad', 'Pickup dates could not be read: %s. Write one per line, like 2026-11-25 or 2026-11-25 10:00-14:00.'), implode(' | ', $poDates['bad']));
            elseif (($poFrom !== '' && $fromAt === null) || ($poUntil !== '' && $untilAt === null)) $poError = __('restaurant_po_window_bad', 'The ordering window dates could not be read.');
            elseif ($fromAt && $untilAt && $untilAt <= $fromAt) $poError = __('restaurant_po_window_order', 'Ordering must close after it opens.');
            elseif ($isPre && $poDates['dates'] === []) $poError = __('restaurant_po_dates_needed', 'A pre-order menu needs at least one pickup date.');
            if ($name === '') {
                $flash = ['type' => 'error', 'msg' => 'Name is required.'];
            } elseif ($poError !== null) {
                $flash = ['type' => 'error', 'msg' => $poError . ' ' . __('restaurant_po_not_saved', 'Nothing was saved.')];
                $isNew = $id <= 0;
                $c = ['id' => $id] + $_POST;   // the form shows what was posted
            } else {
                $row = [
                    'tenant_id'   => $tid,
                    'name'        => mb_substr($name, 0, 160),
                    'slug'        => RestaurantAPI::slugify($name, 'restaurant_menu_categories', $id > 0 ? $id : null),
                    'description' => mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255) ?: null,
                    'sort_order'  => (int)($_POST['sort_order'] ?? 0),
                    'is_active'   => !empty($_POST['is_active']) ? 1 : 0,
                    'preorder'       => $isPre ? 1 : 0,
                    'preorder_from'  => $fromAt ? $fromAt->format('Y-m-d H:i:s') : null,
                    'preorder_until' => $untilAt ? $untilAt->format('Y-m-d H:i:s') : null,
                    'preorder_dates' => RestaurantPreorder::datesText($poDates['dates']) ?: null,
                    'preorder_note'  => mb_substr(trim((string)($_POST['preorder_note'] ?? '')), 0, 255) ?: null,
                ];
                if ($id > 0) {
                    Database::update('restaurant_menu_categories', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
                    AuditLog::record('restaurant.category_updated', (string)$id);
                    RestaurantAPI::menuChanged('category', $id);
                } else {
                    $id = Database::insert('restaurant_menu_categories', $row);
                    AuditLog::record('restaurant.category_created', (string)$id);
                    RestaurantAPI::menuChanged('category', $id);
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
                RestaurantAPI::menuChanged('category', $id);
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

<?php if ($isNew || $editing || isset($c)):
    $c = ($c ?? null) ?: ($editing ?: ['id'=>0,'name'=>'','slug'=>'','description'=>'','sort_order'=>0,'is_active'=>1]);
    $c += ['preorder' => 0, 'preorder_from' => '', 'preorder_until' => '', 'preorder_dates' => '', 'preorder_note' => ''];
    $dtLocal = fn($v): string => ($t = RestaurantPreorder::at((string)$v, RestaurantHours::tz())) ? $t->format('Y-m-d\TH:i') : '';
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

        <?php slate_edit_card_open(['icon' => 'calendar', 'eyebrow' => __('restaurant_po_eyebrow', 'Online ordering'), 'title' => __('restaurant_po_title', 'Pre-order menu')]); ?>
            <label class="field" style="display:flex;gap:10px;align-items:center;">
                <input type="checkbox" name="preorder" value="1" <?= !empty($c['preorder']) ? 'checked' : '' ?>>
                <span><?= __('restaurant_po_on', '<strong>This is a pre-order menu</strong> (a holiday menu, a weekend special). Its items show first on the order page while ordering is open, and are hidden otherwise.') ?></span>
            </label>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="preorder_from"><?= e(__('restaurant_po_from', 'Ordering opens')) ?> <span class="field-hint"><?= e(__('restaurant_po_from_hint', 'empty = now')) ?></span></label>
                    <input type="datetime-local" id="preorder_from" name="preorder_from" value="<?= e(str_contains((string)$c['preorder_from'], 'T') ? (string)$c['preorder_from'] : $dtLocal($c['preorder_from'])) ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="preorder_until"><?= e(__('restaurant_po_until', 'Ordering closes')) ?> <span class="field-hint"><?= e(__('restaurant_po_until_hint', 'empty = after the last pickup date')) ?></span></label>
                    <input type="datetime-local" id="preorder_until" name="preorder_until" value="<?= e(str_contains((string)$c['preorder_until'], 'T') ? (string)$c['preorder_until'] : $dtLocal($c['preorder_until'])) ?>">
                </div>
            </div>
            <div class="field">
                <label class="field-label" for="preorder_dates"><?= e(__('restaurant_po_dates', 'Pickup dates')) ?> <span class="field-hint"><?= e(__('restaurant_po_dates_hint', 'one per line, with times if you like')) ?></span></label>
                <textarea id="preorder_dates" name="preorder_dates" rows="4" placeholder="2026-11-25 10:00-14:00&#10;2026-11-26 9:00-12:00"><?= e((string)$c['preorder_dates']) ?></textarea>
                <p class="text-sm text-muted" style="margin:6px 0 0;"><?= e(sprintf(__('restaurant_po_dates_help', 'Customers pick a time on these dates, every few minutes as set under Settings > Online ordering. A date without times uses that day\'s opening hours (or is one "any time" choice when there are none). Pickup dates apply even on closed dates. Pre-order items cannot be mixed with other items in one order. Times are in the site time zone (%s).'), RestaurantHours::tz()->getName())) ?></p>
            </div>
            <div class="field" style="margin-bottom:0;">
                <label class="field-label" for="preorder_note"><?= e(__('restaurant_po_note_label', 'Note on the order page')) ?> <span class="field-hint"><?= e(__('restaurant_po_note_hint', 'empty = built from the dates')) ?></span></label>
                <input type="text" id="preorder_note" name="preorder_note" maxlength="255" value="<?= e((string)$c['preorder_note']) ?>" placeholder="<?= e(__('restaurant_po_note_ph', 'Thanksgiving pre-order · order by Sun Nov 22 · pickup Wed Nov 25')) ?>">
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
                'meta'         => $c['item_count'] . ' item(s) · order ' . (int)$c['sort_order'] . (!empty($c['preorder']) ? ' · ' . __('restaurant_po_tag', 'Pre-order') : ''),
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
