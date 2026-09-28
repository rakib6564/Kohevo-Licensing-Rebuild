<?php
/**
 * Booking — service categories CRUD (list + inline create/edit).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';
require_once __DIR__ . '/_editor_ui.php';   // reusable record-editor UI kit

Auth::require();
Auth::requirePerm('booking.manage_services');
ModuleGuard::require('booking');
BookingAPI::ensureSchema();

$pageTitle  = __('booking_categories_page_title', 'Service categories');
$currentNav = 'booking-categories';
$tid = current_tenant_id();

$flash  = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$isNew  = !empty($_GET['new']);

$editing = null;
if ($editId > 0) {
    $editing = Database::row("SELECT * FROM booking_categories WHERE id = ? AND tenant_id = ?", [$editId, $tid]);
    if (!$editing) { http_response_code(404); $editId = 0; $editing = null; }
}

/** Local unique-slug helper for categories. */
$catSlug = function (string $name, ?int $excludeId) use ($tid): string {
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-')) ?: 'category';
    $base = substr($base, 0, 60);
    $slug = $base; $i = 2;
    while (true) {
        $clash = Database::row(
            "SELECT id FROM booking_categories WHERE tenant_id = ? AND slug = ?" . ($excludeId ? " AND id <> ?" : ""),
            $excludeId ? [$tid, $slug, $excludeId] : [$tid, $slug]
        );
        if (!$clash) return $slug;
        $slug = $base . '-' . $i++;
        if ($i > 999) return $base . '-' . bin2hex(random_bytes(3));
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'save') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                $flash = ['type' => 'error', 'msg' => __('booking_name_required', 'Name is required.')];
            } else {
                $row = [
                    'tenant_id'  => $tid,
                    'name'       => mb_substr($name, 0, 160),
                    'slug'       => $catSlug($name, $id > 0 ? $id : null),
                    'sort_order' => (int)($_POST['sort_order'] ?? 0),
                    'is_active'  => !empty($_POST['is_active']) ? 1 : 0,
                ];
                if ($id > 0) {
                    Database::update('booking_categories', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
                    AuditLog::record('booking.category_updated', (string)$id);
                } else {
                    $id = Database::insert('booking_categories', $row);
                    AuditLog::record('booking.category_created', (string)$id);
                }
                header('Location: ' . plugin_url('booking', 'admin/categories.php'));
                exit;
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                // Detach from services, then delete.
                Database::query("UPDATE booking_services SET category_id = NULL WHERE category_id = ? AND tenant_id = ?", [$id, $tid]);
                Database::delete('booking_categories', 'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('booking.category_deleted', (string)$id);
                $flash = ['type' => 'success', 'msg' => __('booking_category_deleted', 'Category deleted.')];
                header('Location: ' . plugin_url('booking', 'admin/categories.php'));
                exit;
            }
        }
    }
}

$categories = Database::rows(
    "SELECT c.*, (SELECT COUNT(*) FROM booking_services s WHERE s.category_id = c.id) AS service_count
       FROM booking_categories c WHERE c.tenant_id = ? ORDER BY c.sort_order, c.name",
    [$tid]
);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'), 'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => __('booking_categories', 'Categories')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($isNew || $editing):
    $c = $editing ?: ['id'=>0,'name'=>'','slug'=>'','sort_order'=>0,'is_active'=>1];
    $cInitials = ($editing && trim((string)$editing['name']) !== '') ? mb_strtoupper(mb_substr($editing['name'], 0, 2)) : '–';
    $cSvcCount = $editing ? (int) Database::value(
        "SELECT COUNT(*) FROM booking_services WHERE category_id = ? AND tenant_id = ?", [(int)$editing['id'], $tid]) : 0;
?>
<?php booking_editor_css(); ?>

<?php booking_edit_open([
    'title_fallback' => __('booking_new_category', 'New category'),
]); ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">

        <?php booking_edit_backlink([
            'back_href'  => plugin_url('booking', 'admin/categories.php'),
            'back_label' => __('booking_all_categories', 'All categories'),
        ]); ?>

        <?php booking_edit_hero([
            'icon'         => 'folder',
            'initials'     => $cInitials,
            'title'        => $editing ? $editing['name'] : __('booking_new_category', 'New category'),
            'ref'          => $editing ? (int)$editing['id'] : null,
            'active'       => !empty($c['is_active']),
            'toggle_name'  => 'is_active',
            'toggle_id'    => 'is_active',
            'toggle_title' => __('booking_category_active_toggle', 'Active (shown on the widget)'),
            'status_on'    => __('active', 'Active'),
            'status_off'   => __('inactive', 'Inactive'),
            'stats'        => $editing ? [
                [__('booking_services', 'Services'), (string)$cSvcCount],
                [__('booking_order', 'Order'),    (string)(int)$c['sort_order']],
                [__('booking_slug', 'Slug'),     '<small style="font-family:var(--font-mono);">' . e((string)($c['slug'] ?? '')) . '</small>'],
            ] : [],
        ]); ?>

        <?php booking_edit_card_open(['icon' => 'tag', 'eyebrow' => __('booking_general', 'General'), 'title' => __('booking_category_details', 'Category details')]); ?>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="name"><?= e(__('name', 'Name')) ?> <span class="field-required">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="160" value="<?= e($c['name']) ?>" placeholder="<?= e(__('booking_category_name_ph', 'Haircuts')) ?>">
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="sort_order"><?= e(__('booking_sort_order', 'Sort order')) ?></label>
                    <input type="number" id="sort_order" name="sort_order" step="1" value="<?= (int)$c['sort_order'] ?>">
                </div>
            </div>
            <?php booking_edit_card_note(__('booking_slug_auto_note', 'The URL slug is generated from the name and kept unique automatically.')); ?>
        <?php booking_edit_card_close(); ?>

        <?php booking_edit_actionbar([
            'buttons_html' =>
                '<button type="submit" class="btn btn-primary">' . ($editing ? e(__('booking_save_changes', 'Save changes')) : e(__('booking_create_category', 'Create category'))) . '</button>'
              . '<a href="' . e(plugin_url('booking', 'admin/categories.php')) . '" class="btn btn-ghost">' . e(__('cancel', 'Cancel')) . '</a>',
        ]); ?>

    </form>
<?php booking_edit_close(); ?>

<?php booking_editor_js(); ?>

<?php else: ?>
    <div class="page-header">
        <div><h1><?= e(__('booking_categories_page_title', 'Service categories')) ?></h1></div>
        <a href="?new=1" class="btn btn-primary"><?= e(__('booking_new_category', 'New category')) ?></a>
    </div>

    <?php if (!$categories): ?>
    <div class="card"><div class="empty"><div class="empty-title"><?= e(__('booking_no_categories', 'No categories yet')) ?></div><p class="text-sm"><?= e(__('booking_no_categories_sub', 'Group your services to organise the booking widget.')) ?></p></div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($categories as $c):
            $actions = '<a href="?edit=' . (int)$c['id'] . '" class="btn btn-sm">' . e(__('edit', 'Edit')) . '</a> '
                     . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'' . e(__('booking_confirm_delete_category', 'Delete this category?')) . '\')">'
                     . csrf_field()
                     . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int)$c['id'] . '">'
                     . '<button class="btn btn-sm btn-danger">' . e(__('delete', 'Delete')) . '</button></form>';
            slate_data_row([
                'avatar'       => mb_substr($c['name'], 0, 1),
                'avatar_color' => $c['is_active'] ? 'info' : 'muted',
                'title'        => $c['name'],
                'meta'         => sprintf(__('booking_n_services_order', '%1$s service(s) · order %2$d'), $c['service_count'], (int)$c['sort_order']),
                'badge'        => $c['is_active'] ? [__('active', 'Active'), 'active'] : [__('inactive', 'Inactive'), 'inactive'],
                'detail'       => [__('booking_services', 'Services') => $c['service_count'], __('booking_sort_order', 'Sort order') => (int)$c['sort_order'],
                                   __('booking_slug', 'Slug') => ['label'=>__('booking_slug', 'Slug'),'html'=>'<code>' . e($c['slug']) . '</code>']],
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
