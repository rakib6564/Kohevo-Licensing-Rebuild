<?php
/**
 * Booking — custom booking-form fields CRUD.
 * Fields with service_id NULL apply to every service; otherwise scoped to one.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';
require_once __DIR__ . '/_editor_ui.php';   // reusable record-editor UI kit

Auth::require();
Auth::requirePerm('booking.manage_services');
ModuleGuard::require('booking');
BookingAPI::ensureSchema();

$pageTitle  = __('booking_fields', 'Custom fields');
$currentNav = 'booking-fields';
$tid = current_tenant_id();

$TYPES = [
    'text'     => __('booking_field_type_text', 'Text'),
    'textarea' => __('booking_field_type_paragraph', 'Paragraph'),
    'select'   => __('booking_field_type_dropdown', 'Dropdown'),
    'checkbox' => __('booking_field_type_checkbox', 'Checkbox'),
    'file'     => __('booking_field_type_file', 'File upload'),
];

$flash  = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$isNew  = !empty($_GET['new']);

$editing = null;
if ($editId > 0) {
    $editing = Database::row("SELECT * FROM booking_custom_fields WHERE id = ? AND tenant_id = ?", [$editId, $tid]);
    if (!$editing) { http_response_code(404); $editId = 0; $editing = null; }
}

/** Machine name: lowercase alnum + underscores, unique-ish per tenant. */
$machineName = function (string $label, ?int $excludeId) use ($tid): string {
    $base = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($label)));
    $base = trim($base, '_') ?: 'field';
    $base = substr($base, 0, 60);
    $name = $base; $i = 2;
    while (true) {
        $clash = Database::row(
            "SELECT id FROM booking_custom_fields WHERE tenant_id = ? AND name = ?" . ($excludeId ? " AND id <> ?" : ""),
            $excludeId ? [$tid, $name, $excludeId] : [$tid, $name]
        );
        if (!$clash) return $name;
        $name = $base . '_' . $i++;
        if ($i > 999) return $base . '_' . bin2hex(random_bytes(2));
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'save') {
            $id    = (int)($_POST['id'] ?? 0);
            $label = trim((string)($_POST['label'] ?? ''));
            $type  = isset($TYPES[$_POST['type'] ?? '']) ? (string)$_POST['type'] : 'text';
            if ($label === '') {
                $flash = ['type' => 'error', 'msg' => __('booking_label_required', 'Label is required.')];
            } else {
                // Options: one per line, only meaningful for select.
                $options = null;
                if ($type === 'select') {
                    $lines = preg_split('/\r\n|\r|\n/', (string)($_POST['options'] ?? ''));
                    $opts = array_values(array_filter(array_map('trim', $lines), fn($v) => $v !== ''));
                    $options = $opts ? json_encode($opts, JSON_UNESCAPED_UNICODE) : null;
                }
                $row = [
                    'tenant_id'    => $tid,
                    'service_id'   => ((int)($_POST['service_id'] ?? 0)) ?: null,
                    'label'        => mb_substr($label, 0, 200),
                    'name'         => $machineName($label, $id > 0 ? $id : null),
                    'type'         => $type,
                    'options_json' => $options,
                    'is_required'  => !empty($_POST['is_required']) ? 1 : 0,
                    'is_active'    => !empty($_POST['is_active']) ? 1 : 0,
                    'sort_order'   => (int)($_POST['sort_order'] ?? 0),
                ];
                if ($id > 0) {
                    // Keep the existing machine name stable on edit.
                    unset($row['name']);
                    Database::update('booking_custom_fields', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
                    AuditLog::record('booking.field_updated', (string)$id);
                } else {
                    $id = Database::insert('booking_custom_fields', $row);
                    AuditLog::record('booking.field_created', (string)$id);
                }
                header('Location: ' . plugin_url('booking', 'admin/fields.php'));
                exit;
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                Database::delete('booking_custom_fields', 'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('booking.field_deleted', (string)$id);
                $flash = ['type' => 'success', 'msg' => __('booking_field_deleted', 'Field deleted.')];
                header('Location: ' . plugin_url('booking', 'admin/fields.php'));
                exit;
            }
        }
    }
}

$fields = Database::rows(
    "SELECT f.*, s.name AS service_name
       FROM booking_custom_fields f
       LEFT JOIN booking_services s ON s.id = f.service_id
      WHERE f.tenant_id = ? ORDER BY f.sort_order, f.id",
    [$tid]
);
$allServices = Database::rows("SELECT id, name FROM booking_services WHERE tenant_id = ? ORDER BY name", [$tid]);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'), 'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => __('booking_fields', 'Custom fields')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($isNew || $editing):
    $f = $editing ?: ['id'=>0,'service_id'=>null,'label'=>'','type'=>'text','options_json'=>null,'is_required'=>0,'is_active'=>1,'sort_order'=>0];
    $optsText = '';
    if (!empty($f['options_json'])) { $arr = json_decode($f['options_json'], true); if (is_array($arr)) $optsText = implode("\n", $arr); }
    $fInitials = ($editing && trim((string)$editing['label']) !== '') ? mb_strtoupper(mb_substr($editing['label'], 0, 2)) : '–';
    $fScope = ($editing && !empty($editing['service_id']))
        ? (string) Database::value("SELECT name FROM booking_services WHERE id = ? AND tenant_id = ?", [(int)$editing['service_id'], $tid])
        : __('booking_all_services', 'All services');
?>
<?php booking_editor_css(); ?>

<?php booking_edit_open([
    'title_fallback' => __('booking_new_field', 'New field'),
]); ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">

        <?php booking_edit_backlink([
            'back_href'  => plugin_url('booking', 'admin/fields.php'),
            'back_label' => __('booking_all_fields', 'All fields'),
        ]); ?>

        <?php booking_edit_hero([
            'icon'         => 'list',
            'initials'     => $fInitials,
            'title'        => $editing ? $editing['label'] : __('booking_new_field', 'New field'),
            'ref'          => $editing ? (int)$editing['id'] : null,
            'active'       => !empty($f['is_active']),
            'toggle_name'  => 'is_active',
            'toggle_id'    => 'is_active',
            'toggle_title' => __('booking_field_active_toggle', 'Active (shown on the form)'),
            'status_on'    => __('active', 'Active'),
            'status_off'   => __('inactive', 'Inactive'),
            'stats'        => $editing ? [
                [__('booking_type', 'Type'),     e($TYPES[$f['type']] ?? $f['type'])],
                [__('booking_scope', 'Scope'),    e($fScope ?: __('booking_all_services', 'All services'))],
                [__('booking_required', 'Required'), !empty($f['is_required']) ? __('yes', 'Yes') : __('no', 'No')],
            ] : [],
        ]); ?>

        <?php booking_edit_card_open(['icon' => 'box', 'eyebrow' => __('booking_general', 'General'), 'title' => __('booking_field_details', 'Field details')]); ?>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="name"><?= e(__('label', 'Label')) ?> <span class="field-required">*</span></label>
                    <input type="text" id="name" name="label" required maxlength="200" value="<?= e($f['label']) ?>" placeholder="<?= e(__('booking_field_label_ph', 'Any allergies?')) ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="type"><?= e(__('booking_type', 'Type')) ?></label>
                    <select id="type" name="type">
                        <?php foreach ($TYPES as $k => $lbl): ?>
                            <option value="<?= e($k) ?>" <?= ($f['type'] ?? 'text') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field-row field-row-2" style="margin-bottom:0;">
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="service_id"><?= e(__('booking_applies_to', 'Applies to')) ?></label>
                    <select id="service_id" name="service_id">
                        <option value="0"><?= e(__('booking_all_services', 'All services')) ?></option>
                        <?php foreach ($allServices as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= (int)($f['service_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="sort_order"><?= e(__('booking_sort_order', 'Sort order')) ?></label>
                    <input type="number" id="sort_order" name="sort_order" step="1" value="<?= (int)$f['sort_order'] ?>">
                </div>
            </div>
        <?php booking_edit_card_close(); ?>

        <?php booking_edit_card_open(['icon' => 'tag', 'eyebrow' => __('booking_settings', 'Settings'), 'title' => __('booking_options_validation', 'Options & validation')]); ?>
            <div class="field">
                <label class="field-label" for="options"><?= e(__('booking_dropdown_options', 'Dropdown options (one per line)')) ?></label>
                <textarea id="options" name="options" rows="4" placeholder="<?= e(__('booking_dropdown_options_ph', 'Only used for the Dropdown type')) ?>"><?= e($optsText) ?></textarea>
            </div>
            <div class="field" style="margin-bottom:0;">
                <label class="switch-label">
                    <span class="switch"><input type="checkbox" name="is_required" value="1" <?= !empty($f['is_required']) ? 'checked' : '' ?>><span class="switch-track"></span></span>
                    <span><?= e(__('booking_required_field', 'Required field')) ?></span>
                </label>
            </div>
        <?php booking_edit_card_close(); ?>

        <?php booking_edit_actionbar([
            'buttons_html' =>
                '<button type="submit" class="btn btn-primary">' . ($editing ? e(__('booking_save_changes', 'Save changes')) : e(__('booking_create_field', 'Create field'))) . '</button>'
              . '<a href="' . e(plugin_url('booking', 'admin/fields.php')) . '" class="btn btn-ghost">' . e(__('cancel', 'Cancel')) . '</a>',
        ]); ?>

    </form>
<?php booking_edit_close(); ?>

<?php booking_editor_js(); ?>

<?php else: ?>
    <div class="page-header">
        <div><h1><?= e(__('booking_custom_booking_fields', 'Custom booking fields')) ?></h1></div>
        <a href="?new=1" class="btn btn-primary"><?= e(__('booking_new_field', 'New field')) ?></a>
    </div>

    <?php if (!$fields): ?>
    <div class="card"><div class="empty"><div class="empty-title"><?= e(__('booking_no_custom_fields', 'No custom fields')) ?></div><p class="text-sm"><?= e(__('booking_no_custom_fields_sub', 'Collect extra info at booking time — notes, preferences, file uploads.')) ?></p></div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($fields as $f):
            $actions = '<a href="?edit=' . (int)$f['id'] . '" class="btn btn-sm">' . e(__('edit', 'Edit')) . '</a> '
                     . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'' . e(__('booking_confirm_delete_field', 'Delete this field?')) . '\')">'
                     . csrf_field()
                     . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int)$f['id'] . '">'
                     . '<button class="btn btn-sm btn-danger">' . e(__('delete', 'Delete')) . '</button></form>';
            slate_data_row([
                'avatar'       => mb_substr($f['label'], 0, 1),
                'avatar_color' => $f['is_active'] ? 'info' : 'muted',
                'title'        => $f['label'],
                'meta'         => ($TYPES[$f['type']] ?? $f['type']) . ' · ' . ($f['service_name'] ?: __('booking_all_services', 'All services')),
                'badge'        => $f['is_required'] ? [__('booking_required', 'Required'), 'warning'] : [__('booking_optional', 'Optional'), 'inactive'],
                'detail'       => [
                    __('booking_type', 'Type')      => $TYPES[$f['type']] ?? $f['type'],
                    __('booking_scope', 'Scope')     => $f['service_name'] ?: __('booking_all_services', 'All services'),
                    __('name', 'Name')      => ['label'=>__('name', 'Name'),'html'=>'<code>' . e($f['name']) . '</code>'],
                    __('booking_required', 'Required')  => $f['is_required'] ? __('yes', 'Yes') : __('no', 'No'),
                ],
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
