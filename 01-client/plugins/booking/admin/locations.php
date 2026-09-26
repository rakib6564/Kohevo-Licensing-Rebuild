<?php
/**
 * Booking — locations CRUD (physical sites + virtual/online).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';
require_once __DIR__ . '/_editor_ui.php';   // reusable record-editor UI kit

Auth::require();
Auth::requirePerm('booking.manage_resources');
ModuleGuard::require('booking');
BookingAPI::ensureSchema();

$pageTitle  = __('booking_locations', 'Locations');
$currentNav = 'booking-locations';
$tid = current_tenant_id();

$flash  = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$isNew  = !empty($_GET['new']);

$editing = null;
if ($editId > 0) {
    $editing = Database::row("SELECT * FROM booking_locations WHERE id = ? AND tenant_id = ?", [$editId, $tid]);
    if (!$editing) { http_response_code(404); $editId = 0; $editing = null; }
}

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
                $type = in_array(($_POST['type'] ?? 'in_person'), ['in_person','online'], true) ? (string)$_POST['type'] : 'in_person';
                $murl = trim((string)($_POST['meeting_url'] ?? ''));
                $row = [
                    'tenant_id'   => $tid,
                    'name'        => mb_substr($name, 0, 160),
                    'type'        => $type,
                    'address'     => trim((string)($_POST['address'] ?? '')) ?: null,
                    'meeting_url' => ($murl !== '' && filter_var($murl, FILTER_VALIDATE_URL)) ? mb_substr($murl, 0, 500) : null,
                    'sort_order'  => (int)($_POST['sort_order'] ?? 0),
                    'is_active'   => !empty($_POST['is_active']) ? 1 : 0,
                ];
                if ($id > 0) {
                    Database::update('booking_locations', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
                    AuditLog::record('booking.location_updated', (string)$id);
                } else {
                    $id = Database::insert('booking_locations', $row);
                    AuditLog::record('booking.location_created', (string)$id);
                }
                header('Location: ' . plugin_url('booking', 'admin/locations.php'));
                exit;
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                Database::query("UPDATE booking_services SET location_id = NULL WHERE location_id = ? AND tenant_id = ?", [$id, $tid]);
                Database::delete('booking_locations', 'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('booking.location_deleted', (string)$id);
                $flash = ['type' => 'success', 'msg' => __('booking_location_deleted', 'Location deleted.')];
                header('Location: ' . plugin_url('booking', 'admin/locations.php'));
                exit;
            }
        }
    }
}

$locations = Database::rows(
    "SELECT l.*, (SELECT COUNT(*) FROM booking_services s WHERE s.location_id = l.id) AS service_count
       FROM booking_locations l WHERE l.tenant_id = ? ORDER BY l.sort_order, l.name",
    [$tid]
);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'), 'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => __('booking_locations', 'Locations')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($isNew || $editing):
    $l = $editing ?: ['id'=>0,'name'=>'','type'=>'in_person','address'=>'','meeting_url'=>'','sort_order'=>0,'is_active'=>1];
    $lInitials = ($editing && trim((string)$editing['name']) !== '') ? mb_strtoupper(mb_substr($editing['name'], 0, 2)) : '–';
    $lSvcCount = $editing ? (int) Database::value(
        "SELECT COUNT(*) FROM booking_services WHERE location_id = ? AND tenant_id = ?", [(int)$editing['id'], $tid]) : 0;
?>
<?php booking_editor_css(); ?>

<?php booking_edit_open([
    'title_fallback' => __('booking_new_location', 'New location'),
]); ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">

        <?php booking_edit_backlink([
            'back_href'  => plugin_url('booking', 'admin/locations.php'),
            'back_label' => __('booking_all_locations', 'All locations'),
        ]); ?>

        <?php booking_edit_hero([
            'icon'         => 'map-pin',
            'initials'     => $lInitials,
            'title'        => $editing ? $editing['name'] : __('booking_new_location', 'New location'),
            'ref'          => $editing ? (int)$editing['id'] : null,
            'active'       => !empty($l['is_active']),
            'toggle_name'  => 'is_active',
            'toggle_id'    => 'is_active',
            'toggle_title' => __('booking_location_toggle_active', 'Active (bookable)'),
            'status_on'    => __('booking_location_status_active', 'Active · bookable'),
            'status_off'   => __('inactive', 'Inactive'),
            'stats'        => $editing ? [
                [__('booking_type', 'Type'),     ($l['type'] === 'online' ? __('booking_type_online', 'Online') : __('booking_in_person_nbsp', 'In&nbsp;person'))],
                [__('booking_services', 'Services'), (string)$lSvcCount],
                [__('booking_order', 'Order'),    (string)(int)$l['sort_order']],
            ] : [],
        ]); ?>

        <?php booking_edit_card_open(['icon' => 'map-pin', 'eyebrow' => __('booking_general', 'General'), 'title' => __('booking_location_details', 'Location details')]); ?>
            <div class="field-row field-row-3">
                <div class="field">
                    <label class="field-label" for="name"><?= e(__('name', 'Name')) ?> <span class="field-required">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="160" value="<?= e($l['name']) ?>" placeholder="<?= e(__('booking_location_name_ph', 'Main studio')) ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="type"><?= e(__('booking_type', 'Type')) ?></label>
                    <select id="type" name="type">
                        <option value="in_person" <?= ($l['type'] ?? 'in_person') === 'in_person' ? 'selected' : '' ?>><?= e(__('booking_type_in_person', 'In person')) ?></option>
                        <option value="online" <?= ($l['type'] ?? '') === 'online' ? 'selected' : '' ?>><?= e(__('booking_type_online', 'Online')) ?></option>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label" for="sort_order"><?= e(__('booking_sort_order', 'Sort order')) ?></label>
                    <input type="number" id="sort_order" name="sort_order" step="1" value="<?= (int)$l['sort_order'] ?>">
                </div>
            </div>
            <div class="field">
                <label class="field-label" for="address"><?= e(__('booking_address', 'Address')) ?></label>
                <textarea id="address" name="address" maxlength="1000" placeholder="<?= e(__('booking_address_ph', 'Street, city, postcode')) ?>"><?= e($l['address'] ?? '') ?></textarea>
            </div>
            <div class="field" style="margin-bottom:0;">
                <label class="field-label" for="meeting_url"><?= e(__('booking_meeting_url_label', 'Meeting URL (online)')) ?></label>
                <input type="url" id="meeting_url" name="meeting_url" maxlength="500" value="<?= e($l['meeting_url'] ?? '') ?>" placeholder="<?= e(__('booking_url_ph', 'https://…')) ?>">
                <div class="field-hint"><?= e(__('booking_meeting_url_hint', 'Used for online locations — the link customers receive after booking.')) ?></div>
            </div>
        <?php booking_edit_card_close(); ?>

        <?php booking_edit_actionbar([
            'buttons_html' =>
                '<button type="submit" class="btn btn-primary">' . ($editing ? e(__('save_changes', 'Save changes')) : e(__('booking_create_location', 'Create location'))) . '</button>'
              . '<a href="' . e(plugin_url('booking', 'admin/locations.php')) . '" class="btn btn-ghost">' . e(__('cancel', 'Cancel')) . '</a>',
        ]); ?>

    </form>
<?php booking_edit_close(); ?>

<?php booking_editor_js(); ?>

<?php else: ?>
    <div class="page-header">
        <div><h1><?= e(__('booking_locations', 'Locations')) ?></h1></div>
        <a href="?new=1" class="btn btn-primary"><?= e(__('booking_new_location', 'New location')) ?></a>
    </div>

    <?php if (!$locations): ?>
    <div class="card"><div class="empty"><div class="empty-title"><?= e(__('booking_no_locations', 'No locations yet')) ?></div><p class="text-sm"><?= e(__('booking_no_locations_sub', 'Add a site or an online meeting point.')) ?></p></div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($locations as $l):
            $typeLabel = $l['type'] === 'online' ? __('booking_type_online', 'Online') : __('booking_type_in_person', 'In person');
            $detail = [
                __('booking_type', 'Type')     => $typeLabel,
                __('booking_services', 'Services') => $l['service_count'],
            ];
            if (!empty($l['address']))     $detail['Address'] = ['label'=>__('booking_address', 'Address'),'value'=>$l['address'],'muted'=>true];
            if (!empty($l['meeting_url']))  $detail['Meeting URL'] = ['label'=>__('booking_meeting_url', 'Meeting URL'),'html'=>'<code>' . e($l['meeting_url']) . '</code>'];
            $actions = '<a href="?edit=' . (int)$l['id'] . '" class="btn btn-sm">' . e(__('edit', 'Edit')) . '</a> '
                     . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'' . e(__('booking_confirm_delete_location', 'Delete this location?')) . '\')">'
                     . csrf_field()
                     . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int)$l['id'] . '">'
                     . '<button class="btn btn-sm btn-danger">' . e(__('delete', 'Delete')) . '</button></form>';
            slate_data_row([
                'avatar'       => mb_substr($l['name'], 0, 1),
                'avatar_color' => $l['is_active'] ? 'info' : 'muted',
                'title'        => $l['name'],
                'meta'         => sprintf(__('booking_location_meta', '%s · %d service(s)'), $typeLabel, $l['service_count']),
                'badge'        => $l['is_active'] ? [__('active', 'Active'), 'active'] : [__('inactive', 'Inactive'), 'inactive'],
                'detail'       => $detail,
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
