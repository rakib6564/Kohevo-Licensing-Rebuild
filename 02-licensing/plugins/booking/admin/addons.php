<?php
/**
 * Booking — service add-ons / extras CRUD, scoped to one service.
 * Pick a service, then manage its add-ons (extra price + extra minutes).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';
require_once __DIR__ . '/_editor_ui.php';   // reusable record-editor UI kit

Auth::require();
Auth::requirePerm('booking.manage_services');
ModuleGuard::require('booking');
BookingAPI::ensureSchema();

$pageTitle  = __('booking_addons_page_title', 'Service add-ons');
$currentNav = 'booking-addons';
$tid = current_tenant_id();

$serviceId = (int)($_GET['service_id'] ?? ($_POST['service_id'] ?? 0));
$service   = $serviceId > 0
    ? Database::row("SELECT * FROM booking_services WHERE id = ? AND tenant_id = ?", [$serviceId, $tid])
    : null;
if (!$service) { $serviceId = 0; }

$flash  = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

$editing = null;
if ($service && $editId > 0) {
    $editing = Database::row("SELECT * FROM booking_service_addons WHERE id = ? AND service_id = ? AND tenant_id = ?", [$editId, $serviceId, $tid]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $service) {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        $base = plugin_url('booking', 'admin/addons.php') . '?service_id=' . $serviceId;
        if ($action === 'save') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                $flash = ['type' => 'error', 'msg' => __('booking_name_required', 'Name is required.')];
            } else {
                $row = [
                    'tenant_id'    => $tid,
                    'service_id'   => $serviceId,
                    'name'         => mb_substr($name, 0, 160),
                    'price_cents'  => max(0, (int)round(((float)($_POST['price'] ?? 0)) * 100)),
                    'duration_min' => max(0, (int)($_POST['duration_min'] ?? 0)),
                    'is_active'    => !empty($_POST['is_active']) ? 1 : 0,
                    'sort_order'   => (int)($_POST['sort_order'] ?? 0),
                ];
                if ($id > 0) {
                    Database::update('booking_service_addons', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
                    AuditLog::record('booking.addon_updated', (string)$id);
                } else {
                    $id = Database::insert('booking_service_addons', $row);
                    AuditLog::record('booking.addon_created', (string)$id);
                }
                header('Location: ' . $base);
                exit;
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                Database::delete('booking_service_addons', 'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('booking.addon_deleted', (string)$id);
                $flash = ['type' => 'success', 'msg' => __('booking_addon_deleted', 'Add-on deleted.')];
                header('Location: ' . $base);
                exit;
            }
        }
    }
}

$allServices = Database::rows("SELECT id, name FROM booking_services WHERE tenant_id = ? ORDER BY name", [$tid]);
$addons = $service ? BookingAPI::getServiceAddons($serviceId, false) : [];
$currency = $service['currency'] ?? 'USD';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'), 'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => __('booking_addons', 'Add-ons')],
]); ?>

<div class="page-header"><div><h1><?= e(__('booking_addons_page_title', 'Service add-ons')) ?></h1></div></div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php booking_editor_css(); ?>

<div class="card">
    <form method="get" style="margin:0;">
        <div class="field">
            <label class="field-label" for="service_id"><?= e(__('service', 'Service')) ?></label>
            <select id="service_id" name="service_id" onchange="this.form.submit()">
                <option value="0"><?= e(__('booking_choose_service_option', '— choose a service —')) ?></option>
                <?php foreach ($allServices as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $serviceId === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if (!$service): ?>
    <div class="card"><div class="empty"><div class="empty-title"><?= e(__('booking_pick_service_title', 'Pick a service')) ?></div><p class="text-sm"><?= e(__('booking_addons_pick_service_sub', 'Choose a service above to manage its add-ons.')) ?></p></div></div>
<?php else:
    $a = $editing ?: ['id'=>0,'name'=>'','price_cents'=>0,'duration_min'=>0,'is_active'=>1,'sort_order'=>0];
?>
    <div class="pv-edit">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="service_id" value="<?= $serviceId ?>">
        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">

        <?php booking_edit_card_open([
            'icon'    => 'tag',
            'eyebrow' => __('booking_addon_eyebrow', 'Add-on'),
            'title'   => $editing ? $a['name'] : sprintf(__('booking_new_addon_for', 'New add-on for %s'), $service['name']),
        ]); ?>
            <div class="field-row field-row-3">
                <div class="field">
                    <label class="field-label" for="name"><?= e(__('name', 'Name')) ?> <span class="field-required">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="160" value="<?= e($a['name']) ?>" placeholder="<?= e(__('booking_addon_name_ph', 'Hot towel')) ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="price"><?= e(sprintf(__('booking_extra_price_currency', 'Extra price (%s)'), $currency)) ?></label>
                    <input type="number" id="price" name="price" min="0" step="0.01" value="<?= e(number_format(((int)$a['price_cents'])/100, 2, '.', '')) ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="duration_min"><?= e(__('booking_extra_time_min', 'Extra time (min)')) ?></label>
                    <input type="number" id="duration_min" name="duration_min" min="0" step="5" value="<?= (int)$a['duration_min'] ?>">
                </div>
            </div>
            <div class="field-row field-row-2" style="margin-bottom:0;align-items:end;">
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="sort_order"><?= e(__('booking_sort_order', 'Sort order')) ?></label>
                    <input type="number" id="sort_order" name="sort_order" step="1" value="<?= (int)$a['sort_order'] ?>">
                </div>
                <div class="field" style="margin-bottom:0;display:flex;align-items:flex-end;">
                    <label class="switch-label">
                        <span class="switch"><input type="checkbox" name="is_active" value="1" <?= !empty($a['is_active']) ? 'checked' : '' ?>><span class="switch-track"></span></span>
                        <span><?= e(__('active', 'Active')) ?></span>
                    </label>
                </div>
            </div>
        <?php booking_edit_card_close(); ?>

        <div class="flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary"><?= $editing ? e(__('booking_save_changes', 'Save changes')) : e(__('booking_add_btn', 'Add')) ?></button>
            <?php if ($editing): ?><a href="?service_id=<?= $serviceId ?>" class="btn btn-ghost"><?= e(__('cancel', 'Cancel')) ?></a><?php endif; ?>
        </div>
    </form>
    </div>

    <?php if (!$addons): ?>
        <div class="card"><div class="empty"><div class="empty-title"><?= e(__('booking_no_addons', 'No add-ons yet')) ?></div></div></div>
    <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($addons as $a):
                $actions = '<a href="?service_id=' . $serviceId . '&edit=' . (int)$a['id'] . '" class="btn btn-sm">' . e(__('edit', 'Edit')) . '</a> '
                         . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'' . e(__('booking_confirm_delete_addon', 'Delete this add-on?')) . '\')">'
                         . csrf_field()
                         . '<input type="hidden" name="service_id" value="' . $serviceId . '">'
                         . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int)$a['id'] . '">'
                         . '<button class="btn btn-sm btn-danger">' . e(__('delete', 'Delete')) . '</button></form>';
                slate_data_row([
                    'avatar'       => '+',
                    'avatar_color' => $a['is_active'] ? 'accent' : 'muted',
                    'title'        => $a['name'],
                    'meta'         => ($a['price_cents'] > 0 ? slate_format_price_plain((int)$a['price_cents'], $currency) : __('booking_free', 'Free'))
                                      . ($a['duration_min'] > 0 ? ' · +' . (int)$a['duration_min'] . ' ' . __('booking_min_abbrev', 'min') : ''),
                    'badge'        => $a['is_active'] ? [__('active', 'Active'), 'active'] : [__('inactive', 'Inactive'), 'inactive'],
                    'detail'       => [
                        __('booking_extra_price', 'Extra price') => $a['price_cents'] > 0 ? slate_format_price_plain((int)$a['price_cents'], $currency) : __('booking_free', 'Free'),
                        __('booking_extra_time', 'Extra time')  => (int)$a['duration_min'] . ' ' . __('booking_min_abbrev', 'min'),
                    ],
                    'actions'      => $actions,
                ]);
            endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php booking_editor_js(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
