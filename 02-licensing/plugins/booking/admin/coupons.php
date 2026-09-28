<?php
/**
 * Booking — coupons CRUD (percent or fixed discount, min order, usage caps).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';
require_once __DIR__ . '/_editor_ui.php';   // reusable record-editor UI kit

Auth::require();
Auth::requirePerm('booking.manage_payments');
ModuleGuard::require('booking');
BookingAPI::ensureSchema();

$pageTitle  = __('booking_coupons', 'Coupons');
$currentNav = 'booking-coupons';
$tid = current_tenant_id();

$flash  = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$isNew  = !empty($_GET['new']);

$editing = null;
if ($editId > 0) {
    $editing = Database::row("SELECT * FROM booking_coupons WHERE id = ? AND tenant_id = ?", [$editId, $tid]);
    if (!$editing) { http_response_code(404); $editId = 0; $editing = null; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'save') {
            $id   = (int)($_POST['id'] ?? 0);
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $type = in_array(($_POST['type'] ?? 'percent'), ['percent','fixed'], true) ? (string)$_POST['type'] : 'percent';
            if ($code === '') {
                $flash = ['type' => 'error', 'msg' => __('booking_code_required', 'Code is required.')];
            } else {
                // Percent value = whole %, fixed value = cents.
                $value = $type === 'fixed'
                    ? max(0, (int)round(((float)($_POST['value'] ?? 0)) * 100))
                    : max(0, min(100, (int)($_POST['value'] ?? 0)));
                $exp = trim((string)($_POST['expires_at'] ?? ''));
                $row = [
                    'tenant_id'       => $tid,
                    'code'            => mb_substr($code, 0, 60),
                    'type'            => $type,
                    'value'           => $value,
                    'min_total_cents' => max(0, (int)round(((float)($_POST['min_total'] ?? 0)) * 100)),
                    'max_uses'        => trim((string)($_POST['max_uses'] ?? '')) !== '' ? max(1, (int)$_POST['max_uses']) : null,
                    'expires_at'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp . ' 23:59:59' : null,
                    'is_active'       => !empty($_POST['is_active']) ? 1 : 0,
                ];
                try {
                    if ($id > 0) {
                        Database::update('booking_coupons', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
                        AuditLog::record('booking.coupon_updated', (string)$id);
                    } else {
                        $id = Database::insert('booking_coupons', $row);
                        AuditLog::record('booking.coupon_created', (string)$id);
                    }
                    header('Location: ' . plugin_url('booking', 'admin/coupons.php'));
                    exit;
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => __('booking_code_already_exists', 'That code already exists.')];
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                Database::delete('booking_coupons', 'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('booking.coupon_deleted', (string)$id);
                $flash = ['type' => 'success', 'msg' => __('booking_coupon_deleted', 'Coupon deleted.')];
                header('Location: ' . plugin_url('booking', 'admin/coupons.php'));
                exit;
            }
        }
    }
}

$coupons = Database::rows("SELECT * FROM booking_coupons WHERE tenant_id = ? ORDER BY code", [$tid]);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'), 'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => __('booking_coupons', 'Coupons')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($isNew || $editing):
    $c = $editing ?: ['id'=>0,'code'=>'','type'=>'percent','value'=>0,'min_total_cents'=>0,'max_uses'=>null,'expires_at'=>null,'is_active'=>1];
    $valDisplay = $c['type'] === 'fixed' ? number_format(((int)$c['value'])/100, 2, '.', '') : (string)(int)$c['value'];
    $cInitials  = ($editing && trim((string)$editing['code']) !== '') ? mb_strtoupper(mb_substr($editing['code'], 0, 2)) : '%';
    $cDiscount  = $c['type'] === 'fixed' ? number_format(((int)$c['value'])/100, 2) : (int)$c['value'] . '<small>%</small>';
    $cExpired   = !empty($c['expires_at']) && strtotime($c['expires_at']) < time();
?>
<?php booking_editor_css(); ?>

<?php booking_edit_open([
    'title_fallback' => __('booking_new_coupon', 'New coupon'),
]); ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">

        <?php booking_edit_backlink([
            'back_href'  => plugin_url('booking', 'admin/coupons.php'),
            'back_label' => __('booking_all_coupons', 'All coupons'),
        ]); ?>

        <?php booking_edit_hero([
            'icon'         => 'percent',
            'initials'     => $cInitials,
            'title'        => $editing ? $editing['code'] : __('booking_new_coupon', 'New coupon'),
            'ref'          => $editing ? (int)$editing['id'] : null,
            'active'       => !empty($c['is_active']) && !$cExpired,
            'toggle_name'  => 'is_active',
            'toggle_id'    => 'is_active',
            'toggle_title' => __('booking_coupon_active_toggle', 'Active (redeemable)'),
            'status_on'    => __('booking_active_redeemable', 'Active · redeemable'),
            'status_off'   => $cExpired ? __('booking_expired', 'Expired') : __('inactive', 'Inactive'),
            'stats'        => $editing ? [
                [__('booking_discount', 'Discount'), $cDiscount],
                [__('booking_used', 'Used'),     (int)($c['used_count'] ?? 0) . ($c['max_uses'] !== null ? '<small>/' . (int)$c['max_uses'] . '</small>' : '')],
                [__('booking_expires', 'Expires'),  !empty($c['expires_at']) ? '<small>' . e(I18n::localDate('j M Y', strtotime($c['expires_at']))) . '</small>' : '—'],
            ] : [],
        ]); ?>

        <?php booking_edit_card_open(['icon' => 'tag', 'eyebrow' => __('booking_discount', 'Discount'), 'title' => __('booking_code_and_value', 'Code & value')]); ?>
            <div class="field-row field-row-3">
                <div class="field">
                    <label class="field-label" for="name"><?= e(__('booking_code', 'Code')) ?> <span class="field-required">*</span></label>
                    <input type="text" id="name" name="code" required maxlength="60" value="<?= e($c['code']) ?>" placeholder="SUMMER20" style="text-transform:uppercase;">
                </div>
                <div class="field">
                    <label class="field-label" for="type"><?= e(__('booking_type', 'Type')) ?></label>
                    <select id="type" name="type">
                        <option value="percent" <?= $c['type'] === 'percent' ? 'selected' : '' ?>><?= e(__('booking_percent_type', 'Percent (%)')) ?></option>
                        <option value="fixed" <?= $c['type'] === 'fixed' ? 'selected' : '' ?>><?= e(__('booking_fixed_amount_type', 'Fixed amount')) ?></option>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label" for="value"><?= e(__('booking_value', 'Value')) ?></label>
                    <input type="number" id="value" name="value" min="0" step="0.01" value="<?= e($valDisplay) ?>">
                </div>
            </div>
            <?php booking_edit_card_note(__('booking_coupon_value_note', 'Percent is a whole number (20 = 20% off). Fixed is an amount in the store currency.')); ?>
        <?php booking_edit_card_close(); ?>

        <?php booking_edit_card_open(['icon' => 'clock', 'eyebrow' => __('booking_limits', 'Limits'), 'title' => __('booking_conditions', 'Conditions')]); ?>
            <div class="field-row field-row-3" style="margin-bottom:0;">
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="min_total"><?= e(__('booking_minimum_order', 'Minimum order')) ?></label>
                    <input type="number" id="min_total" name="min_total" min="0" step="0.01" value="<?= e(number_format(((int)$c['min_total_cents'])/100, 2, '.', '')) ?>">
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="max_uses"><?= e(__('booking_max_uses', 'Max uses')) ?></label>
                    <input type="number" id="max_uses" name="max_uses" min="1" step="1" value="<?= e($c['max_uses'] !== null ? (string)(int)$c['max_uses'] : '') ?>" placeholder="<?= e(__('booking_unlimited', 'Unlimited')) ?>">
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="expires_at"><?= e(__('booking_expires', 'Expires')) ?></label>
                    <input type="date" id="expires_at" name="expires_at" value="<?= e(!empty($c['expires_at']) ? substr($c['expires_at'], 0, 10) : '') ?>">
                </div>
            </div>
        <?php booking_edit_card_close(); ?>

        <?php booking_edit_actionbar([
            'buttons_html' =>
                '<button type="submit" class="btn btn-primary">' . ($editing ? e(__('booking_save_changes', 'Save changes')) : e(__('booking_create_coupon', 'Create coupon'))) . '</button>'
              . '<a href="' . e(plugin_url('booking', 'admin/coupons.php')) . '" class="btn btn-ghost">' . e(__('cancel', 'Cancel')) . '</a>',
        ]); ?>

    </form>
<?php booking_edit_close(); ?>

<?php booking_editor_js(); ?>

<?php else: ?>
    <div class="page-header">
        <div><h1><?= e(__('booking_coupons', 'Coupons')) ?></h1></div>
        <a href="?new=1" class="btn btn-primary"><?= e(__('booking_new_coupon', 'New coupon')) ?></a>
    </div>

    <?php if (!$coupons): ?>
    <div class="card"><div class="empty"><div class="empty-title"><?= e(__('booking_no_coupons', 'No coupons yet')) ?></div></div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($coupons as $c):
            $val = $c['type'] === 'fixed' ? number_format(((int)$c['value'])/100, 2) : (int)$c['value'] . '%';
            $expired = !empty($c['expires_at']) && strtotime($c['expires_at']) < time();
            $actions = '<a href="?edit=' . (int)$c['id'] . '" class="btn btn-sm">' . e(__('edit', 'Edit')) . '</a> '
                     . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'' . e(__('booking_confirm_delete_coupon', 'Delete this coupon?')) . '\')">'
                     . csrf_field()
                     . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int)$c['id'] . '">'
                     . '<button class="btn btn-sm btn-danger">' . e(__('delete', 'Delete')) . '</button></form>';
            slate_data_row([
                'avatar'       => '%',
                'avatar_color' => ($c['is_active'] && !$expired) ? 'accent' : 'muted',
                'title'        => $c['code'],
                'meta'         => sprintf(__('booking_coupon_off_used', '%1$s off · used %2$d'), $val, (int)$c['used_count']) . (($c['max_uses'] !== null) ? '/' . (int)$c['max_uses'] : ''),
                'badge'        => $expired ? [__('booking_expired', 'Expired'), 'inactive'] : ($c['is_active'] ? [__('active', 'Active'), 'active'] : [__('inactive', 'Inactive'), 'inactive']),
                'detail'       => [
                    __('booking_discount', 'Discount')   => $c['type'] === 'fixed' ? __('booking_fixed_amount_type', 'Fixed amount') . ' ' . $val : $val,
                    __('booking_min_order', 'Min order')  => (int)$c['min_total_cents'] > 0 ? number_format($c['min_total_cents']/100, 2) : '—',
                    __('booking_uses', 'Uses')       => (int)$c['used_count'] . (($c['max_uses'] !== null) ? ' / ' . (int)$c['max_uses'] : ' (' . __('booking_unlimited', 'unlimited') . ')'),
                    __('booking_expires', 'Expires')    => !empty($c['expires_at']) ? I18n::localDate('j M Y', strtotime($c['expires_at'])) : '—',
                ],
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
