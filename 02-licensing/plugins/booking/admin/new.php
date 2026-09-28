<?php
/**
 * Booking — manual / walk-in appointment creation (admin-side).
 * Walk-in mode books "now" and bypasses hour/advance validation.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';

Auth::require();
Auth::requirePerm('booking.manage_appointments');
ModuleGuard::require('booking');
BookingAPI::ensureSchema();

$pageTitle  = __('booking_new_appointment', 'New appointment');
$currentNav = 'booking-appointments';
$tid = current_tenant_id();

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('booking_security_check_failed', 'Security check failed.')];
    } else {
        $walkin = !empty($_POST['walkin']);
        $starts = $walkin ? date('Y-m-d H:i') : (string)($_POST['starts_at'] ?? '');
        $res = BookingAPI::createAppointment([
            'service_id'     => (int)($_POST['service_id'] ?? 0),
            'provider_id'    => (int)($_POST['provider_id'] ?? 0),
            'starts_at'      => $starts,
            'customer_name'  => trim((string)($_POST['customer_name'] ?? '')),
            'customer_email' => trim((string)($_POST['customer_email'] ?? '')),
            'customer_phone' => trim((string)($_POST['customer_phone'] ?? '')),
            'notes'          => trim((string)($_POST['notes'] ?? '')),
            'party_size'     => max(1, (int)($_POST['party_size'] ?? 1)),
            'source'         => $walkin ? 'walkin' : 'admin',
            'ignore_hours'   => !empty($_POST['ignore_hours']) || $walkin,
        ]);
        if (!empty($res['ok'])) {
            header('Location: ' . plugin_url('booking', 'admin/appointment.php') . '?id=' . (int)$res['id']);
            exit;
        }
        $flash = ['type' => 'error', 'msg' => $res['error'] ?? __('booking_new_could_not_create', 'Could not create appointment.')];
    }
}

$services  = Database::rows("SELECT id, name FROM booking_services WHERE tenant_id = ? AND is_active = 1 ORDER BY name", [$tid]);
$providers = BookingAPI::getAllProviders();

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'), 'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => __('booking_new_appointments_crumb', 'Appointments'), 'href' => plugin_url('booking', 'admin/appointments.php')],
    ['label' => __('booking_new_crumb', 'New')],
]); ?>

<div class="page-header"><div><h1><?= e(__('booking_new_appointment', 'New appointment')) ?></h1><p class="page-header-sub"><?= e(__('booking_new_appointment_sub', 'Book on behalf of a customer, or log a walk-in.')) ?></p></div></div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$services || !$providers): ?>
    <div class="card"><div class="empty"><div class="empty-title"><?= e(__('booking_new_setup_first', 'Set up first')) ?></div><p class="text-sm"><?= e(__('booking_new_setup_first_sub', 'You need at least one active service and provider.')) ?></p></div></div>
<?php else: ?>
<div class="card">
    <form method="post">
        <?= csrf_field() ?>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="service_id"><?= e(__('service', 'Service')) ?> <span class="field-required">*</span></label>
                <select id="service_id" name="service_id" required>
                    <?php foreach ($services as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="provider_id"><?= e(__('provider', 'Provider')) ?> <span class="field-required">*</span></label>
                <select id="provider_id" name="provider_id" required>
                    <?php foreach ($providers as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="walkin" name="walkin" value="1" onchange="document.getElementById('whenRow').style.display=this.checked?'none':'';">
                <span><?= e(__('booking_new_walkin_label', 'Walk-in (start now, ignore working hours)')) ?></span>
            </label>
        </div>

        <div class="field-row field-row-3" id="whenRow">
            <div class="field">
                <label class="field-label" for="starts_at"><?= e(__('booking_new_date_time', 'Date & time')) ?></label>
                <input type="datetime-local" id="starts_at" name="starts_at" value="<?= e(date('Y-m-d\TH:i')) ?>">
            </div>
            <div class="field">
                <label class="field-label" for="party_size"><?= e(__('booking_people', 'People')) ?></label>
                <input type="number" id="party_size" name="party_size" min="1" step="1" value="1">
            </div>
            <div class="field" style="display:flex;align-items:flex-end;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="ignore_hours" value="1"><span><?= e(__('booking_new_ignore_hours', 'Ignore working hours')) ?></span>
                </label>
            </div>
        </div>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="customer_name"><?= e(__('booking_new_customer_name', 'Customer name')) ?> <span class="field-required">*</span></label>
                <input type="text" id="customer_name" name="customer_name" required maxlength="160">
            </div>
            <div class="field">
                <label class="field-label" for="customer_email"><?= e(__('email', 'Email')) ?> <span class="field-required">*</span></label>
                <input type="email" id="customer_email" name="customer_email" required maxlength="200">
            </div>
        </div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="customer_phone"><?= e(__('booking_phone', 'Phone')) ?></label>
                <input type="tel" id="customer_phone" name="customer_phone" maxlength="40">
            </div>
        </div>
        <div class="field">
            <label class="field-label" for="notes"><?= e(__('booking_notes', 'Notes')) ?></label>
            <textarea id="notes" name="notes" maxlength="2000"></textarea>
        </div>

        <div class="flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary"><?= e(__('booking_new_create_appointment_btn', 'Create appointment')) ?></button>
            <a href="<?= e(plugin_url('booking', 'admin/appointments.php')) ?>" class="btn btn-ghost"><?= e(__('cancel', 'Cancel')) ?></a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
