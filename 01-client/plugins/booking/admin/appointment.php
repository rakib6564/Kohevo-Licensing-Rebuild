<?php
/**
 * Booking — single appointment (right-rail detail + cancel/complete).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';

Auth::require();
Auth::requirePerm('booking.view');
ModuleGuard::require('booking');
BookingAPI::ensureSchema();

$tid = current_tenant_id();
$id  = (int)($_GET['id'] ?? 0);

$row = Database::row(
    "SELECT a.*, s.name AS service_name, s.duration_min AS service_duration_min,
            s.currency AS service_currency,
            p.name AS provider_name, p.email AS provider_email
       FROM booking_appointments a
       JOIN booking_services  s ON s.id = a.service_id
       JOIN booking_providers p ON p.id = a.provider_id
      WHERE a.id = ? AND a.tenant_id = ?",
    [$id, $tid]
);

if (!$row) {
    http_response_code(404);
    $pageTitle = __('booking_appointment_not_found', 'Appointment not found');
    require SLATE_ROOT . '/admin/partials/header.php';
    echo '<div class="card"><h1>' . e(__('booking_appointment_not_found', 'Appointment not found')) . '</h1></div>';
    require SLATE_ROOT . '/admin/partials/footer.php';
    exit;
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::can('booking.manage_appointments')) {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'cancel') {
            BookingAPI::cancelAppointment($id, trim((string)($_POST['reason'] ?? '')));
            $row['status'] = 'cancelled';
            $flash = ['type' => 'success', 'msg' => __('booking_appt_cancelled_notified', 'Appointment cancelled (customer notified).')];
        } elseif ($action === 'reschedule') {
            $res = BookingAPI::rescheduleAppointment($id, (string)($_POST['new_start'] ?? ''));
            if (!empty($res['ok'])) {
                $fresh = Database::row("SELECT starts_at, ends_at FROM booking_appointments WHERE id = ?", [$id]);
                if ($fresh) { $row['starts_at'] = $fresh['starts_at']; $row['ends_at'] = $fresh['ends_at']; }
                $flash = ['type' => 'success', 'msg' => __('booking_appt_rescheduled_notified', 'Appointment rescheduled (customer notified).')];
            } else {
                $flash = ['type' => 'error', 'msg' => $res['error'] ?? __('booking_reschedule_failed', 'Reschedule failed.')];
            }
        } elseif ($action === 'refund') {
            $res = BookingAPI::refundAppointment($id);
            if (!empty($res['ok'])) {
                $row['payment_status'] = 'refunded';
                $flash = ['type' => 'success', 'msg' => __('booking_refund_issued', 'Refund issued.')];
            } else {
                $flash = ['type' => 'error', 'msg' => $res['error'] ?? __('booking_refund_failed', 'Refund failed.')];
            }
        } elseif ($action === 'approve') {
            $res = BookingAPI::approveAppointment($id);
            if (!empty($res['ok'])) {
                $row['status'] = 'confirmed';
                $flash = ['type' => 'success', 'msg' => __('booking_approved_notified', 'Booking approved (customer notified).')];
            } else {
                $flash = ['type' => 'error', 'msg' => $res['error'] ?? __('booking_approve_failed', 'Approve failed.')];
            }
        } elseif ($action === 'decline') {
            $res = BookingAPI::declineAppointment($id, trim((string)($_POST['reason'] ?? '')));
            if (!empty($res['ok'])) {
                $row['status'] = 'cancelled';
                $flash = ['type' => 'success', 'msg' => __('booking_declined_notified', 'Booking declined (customer notified).')];
            } else {
                $flash = ['type' => 'error', 'msg' => $res['error'] ?? __('booking_decline_failed', 'Decline failed.')];
            }
        } else {
            $map = ['complete' => 'completed', 'no_show' => 'no_show', 'reconfirm' => 'confirmed'];
            $newStatus = $map[$action] ?? null;
            if ($newStatus && BookingAPI::changeStatus($id, $newStatus)) {
                $row['status'] = $newStatus;
                $newStatusLabels = [
                    'completed' => __('booking_status_completed', 'Completed'),
                    'no_show'   => __('booking_status_no_show', 'No show'),
                    'confirmed' => __('booking_status_confirmed', 'Confirmed'),
                ];
                $flash = ['type' => 'success', 'msg' => sprintf(__('booking_status_updated_to', 'Status updated to %s.'), $newStatusLabels[$newStatus] ?? $newStatus)];
            }
        }
    }
}

// Linked bookings — other appointments sharing this one's recurrence_group
// (a weekly-repeat series, or a multi-select batch booking — both features
// stamp every appointment they create together with the same group id; see
// BookingAPI::createAppointment()'s $recurGroup param). Ordered by time so
// the list reads as a simple itinerary regardless of which one you opened.
$groupSiblings = [];
if (!empty($row['recurrence_group'])) {
    $groupSiblings = Database::rows(
        "SELECT id, ref, starts_at, status FROM booking_appointments
          WHERE recurrence_group = ? AND tenant_id = ?
       ORDER BY starts_at ASC",
        [$row['recurrence_group'], $tid]
    );
}

$audit = [
    ['action' => __('booking_audit_appointment_created', 'Appointment created'), 'when' => $row['created_at'], 'muted' => false,
     'detail' => sprintf(__('booking_audit_ref', 'ref %s'), $row['ref'])],
];
if ((int)$row['reminder_24h_sent'] === 1) {
    $audit[] = ['action' => __('booking_audit_24h_reminder_sent', '24-hour reminder sent'), 'when' => $row['updated_at'], 'muted' => true];
}
if ((int)$row['reminder_1h_sent'] === 1) {
    $audit[] = ['action' => __('booking_audit_1h_reminder_sent', '1-hour reminder sent'),  'when' => $row['updated_at'], 'muted' => true];
}
if ($row['status'] !== 'confirmed') {
    $auditStatusLabels = [
        'pending'           => __('booking_status_pending', 'pending'),
        'awaiting_approval' => __('booking_status_awaiting_approval', 'awaiting approval'),
        'cancelled'         => __('booking_status_cancelled', 'cancelled'),
        'no_show'           => __('booking_status_no_show', 'no show'),
        'completed'         => __('booking_status_completed', 'completed'),
    ];
    $audit[] = ['action' => sprintf(__('booking_audit_marked_status', 'Marked %s'), $auditStatusLabels[$row['status']] ?? $row['status']), 'when' => $row['updated_at'], 'muted' => false];
}

$pageTitle  = sprintf(__('booking_appointment_ref_title', 'Appointment %s'), $row['ref']);
$currentNav = 'booking-appointments';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'), 'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => __('booking_appointments', 'Appointments'), 'href' => plugin_url('booking', 'admin/appointments.php')],
    ['label' => $row['ref']],
]); ?>

<div class="page-header">
    <div>
        <h1><?= e(__('booking_appointment_singular', 'Appointment')) ?> <code style="font-family:var(--font-mono);font-size:16px;"><?= e($row['ref']) ?></code></h1>
        <p class="page-header-sub">
            <?= e(slate_format_datetime($row['starts_at'], 'l, j F Y', ' · ')) ?>
            – <?= e(slate_format_time($row['ends_at'])) ?>
            <?php if (count($groupSiblings) >= 2): ?>
                <span class="badge badge-accent" style="margin-left:6px;"><?= e(sprintf(__('booking_group_of_n', 'Group of %d'), count($groupSiblings))) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <?php if (Auth::can('booking.manage_appointments')): ?>
        <div class="toolbar" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <form method="post" style="display:inline;margin:0;">
                <?= csrf_field() ?>
                <?php if ($row['status'] === 'confirmed'): ?>
                    <button name="_action" value="complete" class="btn"><?= e(__('booking_mark_completed', 'Mark completed')) ?></button>
                    <button name="_action" value="no_show"  class="btn"><?= e(__('booking_no_show_btn', 'No-show')) ?></button>
                    <button name="_action" value="cancel"   class="btn btn-danger" onclick="return confirm('<?= e(__('booking_confirm_cancel_appt', 'Cancel this appointment? The customer will be emailed.')) ?>')"><?= e(__('cancel', 'Cancel')) ?></button>
                <?php elseif ($row['status'] === 'awaiting_approval'): ?>
                    <button name="_action" value="approve" class="btn btn-primary"><?= e(__('booking_approve', 'Approve')) ?></button>
                <?php else: ?>
                    <button name="_action" value="reconfirm" class="btn btn-primary"><?= e(__('booking_reconfirm', 'Reconfirm')) ?></button>
                <?php endif; ?>
            </form>
            <?php if ($row['status'] === 'awaiting_approval'): ?>
                <form method="post" style="display:flex;gap:6px;align-items:center;margin:0;" onsubmit="return confirm('<?= e(__('booking_confirm_decline_request', 'Decline this booking request? The customer will be emailed.')) ?>')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="decline">
                    <input type="text" name="reason" placeholder="<?= e(__('booking_decline_reason_ph', 'Reason (optional, emailed to customer)')) ?>" style="min-width:220px;">
                    <button class="btn btn-danger"><?= e(__('booking_decline', 'Decline')) ?></button>
                </form>
            <?php endif; ?>
            <?php if ($row['status'] === 'confirmed'): ?>
                <form method="post" style="display:flex;gap:6px;align-items:center;margin:0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="reschedule">
                    <input type="datetime-local" name="new_start" value="<?= e(date('Y-m-d\TH:i', strtotime($row['starts_at']))) ?>" required>
                    <button class="btn"><?= e(__('booking_reschedule', 'Reschedule')) ?></button>
                </form>
            <?php endif; ?>
            <?php if (Auth::can('booking.manage_payments') && in_array(($row['payment_status'] ?? 'none'), ['paid','deposit_paid','partially_refunded'], true)): ?>
                <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('<?= e(__('booking_confirm_refund_stripe', 'Refund this payment via Stripe?')) ?>')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="refund">
                    <button class="btn btn-danger"><?= e(__('booking_refund', 'Refund')) ?></button>
                </form>
            <?php endif; ?>
            <?php if ((int)($row['price_cents'] ?? 0) > 0): ?>
                <a href="<?= e(plugin_url('booking', 'admin/invoice.php')) ?>?id=<?= (int)$row['id'] ?>" class="btn" target="_blank"><?= e(__('booking_invoice', 'Invoice')) ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php slate_page_layout('with-aside'); ?>
    <div class="page-main">
        <div class="card">
            <div class="card-header"><h2><?= e(__('booking_details', 'Details')) ?></h2></div>
            <?php
            $actualMin = (int) round((strtotime($row['ends_at']) - strtotime($row['starts_at'])) / 60);
            $cur       = $row['service_currency'] ?: 'USD';
            $addons    = json_decode((string)($row['addons_json'] ?? '[]'), true) ?: [];
            $customVals= json_decode((string)($row['custom_json'] ?? '[]'), true) ?: [];
            $payLabels = [
                'none'               => '—',
                'pending'            => __('booking_status_awaiting_payment', 'Awaiting payment'),
                'deposit_paid'       => __('booking_deposit_paid', 'Deposit paid'),
                'paid'               => __('booking_paid', 'Paid'),
                'refunded'           => __('booking_refunded', 'Refunded'),
                'partially_refunded' => __('booking_partially_refunded', 'Partially refunded'),
                'failed'             => __('booking_payment_failed_label', 'Payment failed'),
            ];
            $sourceLabels = ['online' => __('booking_online', 'Online')];
            ?>
            <ul class="kv-list">
                <li class="kv-row"><span class="kv-label"><?= e(__('service', 'Service')) ?></span>     <span class="kv-value"><?= e($row['service_name']) ?> (<?= $actualMin ?: (int)$row['service_duration_min'] ?> <?= e(__('booking_min_abbrev', 'min')) ?>)</span></li>
                <li class="kv-row"><span class="kv-label"><?= e(__('provider', 'Provider')) ?></span>    <span class="kv-value"><?= e($row['provider_name']) ?><?php if ($row['provider_email']): ?> · <a href="mailto:<?= e($row['provider_email']) ?>"><?= e($row['provider_email']) ?></a><?php endif; ?></span></li>
                <li class="kv-row"><span class="kv-label"><?= e(__('booking_customer_singular', 'Customer')) ?></span>    <span class="kv-value"><?= e($row['customer_name']) ?> · <a href="mailto:<?= e($row['customer_email']) ?>"><?= e($row['customer_email']) ?></a><?php if ($row['customer_phone']): ?> · <?= e($row['customer_phone']) ?><?php endif; ?></span></li>
                <?php if ((int)($row['party_size'] ?? 1) > 1): ?>
                    <li class="kv-row"><span class="kv-label"><?= e(__('booking_people', 'People')) ?></span>  <span class="kv-value"><?= (int)$row['party_size'] ?></span></li>
                <?php endif; ?>
                <li class="kv-row"><span class="kv-label"><?= e(__('booking_source', 'Source')) ?></span>     <span class="kv-value"><?= e($sourceLabels[$row['source'] ?? 'online'] ?? ucfirst((string)($row['source'] ?? 'online'))) ?></span></li>
                <?php if ($addons): ?>
                    <li class="kv-row"><span class="kv-label"><?= e(__('booking_addons', 'Add-ons')) ?></span> <span class="kv-value"><?= e(implode(', ', array_map(fn($a) => $a['name'] ?? '', $addons))) ?></span></li>
                <?php endif; ?>
                <?php if ((int)($row['price_cents'] ?? 0) > 0): ?>
                    <li class="kv-row"><span class="kv-label"><?= e(__('booking_price', 'Price')) ?></span>   <span class="kv-value kv-mono"><?= e($cur) ?> <?= e(number_format(((int)$row['price_cents'] + (int)($row['tax_cents'] ?? 0))/100, 2)) ?><?php if ((int)($row['tax_cents'] ?? 0) > 0): ?> <span class="text-muted">(<?= e(__('booking_incl_tax', 'incl. tax')) ?>)</span><?php endif; ?></span></li>
                <?php endif; ?>
                <?php if (($row['payment_status'] ?? 'none') !== 'none'): ?>
                    <li class="kv-row"><span class="kv-label"><?= e(__('booking_payment_label', 'Payment')) ?></span> <span class="kv-value"><?= e($payLabels[$row['payment_status']] ?? $row['payment_status']) ?><?php if ((int)($row['deposit_cents'] ?? 0) > 0): ?> · <?= e(__('booking_deposit_lc', 'deposit')) ?> <?= e($cur) ?> <?= e(number_format(((int)$row['deposit_cents'])/100, 2)) ?><?php endif; ?></span></li>
                <?php endif; ?>
                <?php foreach ($customVals as $ck => $cv): if ($cv === '' || $cv === null) continue; ?>
                    <li class="kv-row"><span class="kv-label"><?= e($ck) ?></span> <span class="kv-value"><?= e(is_array($cv) ? implode(', ', $cv) : (string)$cv) ?></span></li>
                <?php endforeach; ?>
                <?php if (!empty($row['notes'])): ?>
                    <li class="kv-row"><span class="kv-label"><?= e(__('booking_notes', 'Notes')) ?></span>   <span class="kv-value"><?= nl2br(e($row['notes'])) ?></span></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <aside class="page-aside">
        <div class="aside-card">
            <div class="aside-card-title"><?= e(__('status', 'Status')) ?></div>
            <ul class="kv-list">
                <li class="kv-row"><span class="kv-label"><?= e(__('booking_current', 'Current')) ?></span><span class="kv-value">
                    <?php
                    $bm = [
                        'pending'           => [__('booking_status_pending', 'Pending'), 'warning'],
                        'awaiting_approval' => [__('booking_status_awaiting_approval', 'Awaiting approval'), 'warning'],
                        'confirmed'         => [__('booking_status_confirmed', 'Confirmed'), 'active'],
                        'cancelled'         => [__('booking_status_cancelled', 'Cancelled'), 'inactive'],
                        'no_show'           => [__('booking_status_no_show', 'No show'), 'warning'],
                        'completed'         => [__('booking_status_completed', 'Completed'), 'accent'],
                    ][$row['status']] ?? ['?', ''];
                    ?>
                    <span class="badge badge-<?= e($bm[1]) ?>"><?= e($bm[0]) ?></span>
                </span></li>
                <li class="kv-row"><span class="kv-label"><?= e(__('booking_reference_label', 'Reference')) ?></span><span class="kv-value kv-mono"><?= e($row['ref']) ?></span></li>
                <li class="kv-row"><span class="kv-label"><?= e(__('booking_24h_reminder', '24h reminder')) ?></span><span class="kv-value"><?= (int)$row['reminder_24h_sent'] === 1 ? e(__('booking_sent', 'Sent')) : '—' ?></span></li>
                <li class="kv-row"><span class="kv-label"><?= e(__('booking_1h_reminder', '1h reminder')) ?></span> <span class="kv-value"><?= (int)$row['reminder_1h_sent']  === 1 ? e(__('booking_sent', 'Sent')) : '—' ?></span></li>
            </ul>
        </div>

        <?php if (count($groupSiblings) >= 2): ?>
        <div class="aside-card">
            <div class="aside-card-title"><?= e(sprintf(__('booking_linked_bookings_n', 'Linked bookings (%d)'), count($groupSiblings))) ?></div>
            <ul class="kv-list">
                <?php foreach ($groupSiblings as $sib):
                    $isThis = (int)$sib['id'] === (int)$row['id'];
                    $sbm = [
                        'pending'           => [__('booking_status_pending', 'Pending'), 'warning'],
                        'awaiting_approval' => [__('booking_status_awaiting_approval', 'Awaiting approval'), 'warning'],
                        'confirmed'         => [__('booking_status_confirmed', 'Confirmed'), 'active'],
                        'cancelled'         => [__('booking_status_cancelled', 'Cancelled'), 'inactive'],
                        'no_show'           => [__('booking_status_no_show', 'No show'), 'warning'],
                        'completed'         => [__('booking_status_completed', 'Completed'), 'accent'],
                    ][$sib['status']] ?? ['?', ''];
                ?>
                    <li class="kv-row">
                        <span class="kv-value" style="display:flex;flex-direction:column;gap:2px;<?= $isThis ? 'font-weight:600;' : '' ?>">
                            <?php if ($isThis): ?>
                                <?= e(slate_format_datetime($sib['starts_at'], 'D j M', ' · ')) ?> — <?= e(__('booking_this_appointment', 'this appointment')) ?>
                            <?php else: ?>
                                <a href="<?= e(plugin_url('booking', 'admin/appointment.php')) ?>?id=<?= (int)$sib['id'] ?>">
                                    <?= e(slate_format_datetime($sib['starts_at'], 'D j M', ' · ')) ?>
                                </a>
                            <?php endif; ?>
                        </span>
                        <span class="kv-label"><span class="badge badge-<?= e($sbm[1]) ?>" style="font-size:10px;"><?= e($sbm[0]) ?></span></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="aside-card">
            <div class="aside-card-title"><?= e(__('booking_audit_trail', 'Audit Trail')) ?></div>
            <ol class="audit-trail">
                <?php foreach ($audit as $a): if (empty($a['when'])) continue; ?>
                    <li class="audit-trail-item<?= !empty($a['muted']) ? ' is-muted' : '' ?>">
                        <div class="audit-trail-action"><?= e($a['action']) ?></div>
                        <div class="audit-trail-meta"><?= e(slate_format_datetime($a['when'], 'j M Y')) ?></div>
                        <?php if (!empty($a['detail'])): ?>
                            <div class="audit-trail-detail"><?= e($a['detail']) ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </aside>
<?php slate_page_layout_end(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
