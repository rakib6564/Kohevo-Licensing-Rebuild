<?php
/**
 * Booking — Messages Inbox.
 *
 * Displays pending human-message threads from clients that need practitioner reply,
 * with KPI statistics and quick mark-replied actions.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';
require_once dirname(__DIR__) . '/BookingPlusAPI.php';

Auth::require();
if (!Auth::can('booking.view') && !Auth::isSuperAdmin()) {
    Auth::requirePerm('booking.view');
}
ModuleGuard::require('booking');

// The capability toggle in Booking → Settings must actually turn this inbox
// off, not just hide its nav link. Placed before the mark_replied POST
// handling below, so a disabled capability also blocks that mutation, not
// just the read view — mirrors the client-facing guard already in
// booking/public/message.php for the same capability.
if (!PluginLoader::isCapabilityEnabled('booking', 'client_messaging')) {
    http_response_code(503);
    require SLATE_ROOT . '/admin/partials/header.php';
    echo '<div class="card"><div class="empty"><div class="empty-title">Not available</div>'
       . '<p class="text-sm">Client messaging is currently disabled. Enable it in Booking &rarr; Settings.</p></div></div>';
    require SLATE_ROOT . '/admin/partials/footer.php';
    exit;
}

BookingPlusAPI::ensureSchema();

$pageTitle  = __('booking_messages', 'Booking Messages');
$currentNav = 'booking-messages';
$tid        = current_tenant_id();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['mark_replied'])) {
    if (csrf_verify()) {
        $mid = (int)$_POST['mark_replied'];
        if ($mid > 0) {
            Database::update('bookingplus_appointment_meta',
                ['therapist_replied_at' => slate_db_now()],
                'id = ? AND tenant_id = ?', [$mid, $tid]);
        }
    }
    header('Location: ' . plugin_url('booking', 'admin/messages.php'));
    exit;
}

$pending = Database::rows(
    "SELECT m.*, a.customer_name, a.customer_email, a.customer_phone, a.starts_at, a.ref,
            s.name AS service_name
       FROM bookingplus_appointment_meta m
       JOIN booking_appointments a ON a.id = m.appointment_id
       JOIN booking_services    s ON s.id = a.service_id
      WHERE m.tenant_id = ?
        AND m.client_message IS NOT NULL
        AND m.therapist_replied_at IS NULL
      ORDER BY m.client_message_at DESC
      LIMIT 100",
    [$tid]
);

$totalMsgs    = (int) Database::value("SELECT COUNT(*) FROM bookingplus_appointment_meta WHERE tenant_id = ? AND client_message IS NOT NULL", [$tid]);
$totalReplied = (int) Database::value("SELECT COUNT(*) FROM bookingplus_appointment_meta WHERE tenant_id = ? AND therapist_replied_at IS NOT NULL", [$tid]);

require SLATE_ROOT . '/admin/partials/header.php';

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'),     'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => __('messages', 'Messages')],
]);
?>

<div class="page-header">
    <div>
        <h1><?= __('booking_messages', 'Booking Messages') ?></h1>
        <p class="text-muted"><?= __('booking_messages_sub', 'Human-message threads routed from client bookings. Reply directly to client, then click "Mark replied" here.') ?></p>
    </div>
    <div class="toolbar">
        <a href="<?= e(plugin_url('booking', 'admin/services.php')) ?>" class="btn btn-ghost"><?= __('services', 'Services') ?></a>
        <a href="<?= e(plugin_url('booking', 'admin/settings.php')) ?>" class="btn btn-ghost"><?= __('settings', 'Settings') ?></a>
    </div>
</div>

<div class="kpi-strip" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:var(--space-3);margin-bottom:var(--space-4);">
    <div class="card kpi-card"><div class="kpi-label"><?= __('waiting_reply', 'Waiting for reply') ?></div><div class="kpi-value"><?= count($pending) ?></div></div>
    <div class="card kpi-card"><div class="kpi-label"><?= __('messages_received', 'Messages received') ?></div><div class="kpi-value"><?= $totalMsgs ?></div></div>
    <div class="card kpi-card"><div class="kpi-label"><?= __('replied', 'Replied') ?></div><div class="kpi-value"><?= $totalReplied ?></div></div>
</div>

<?php if (!$pending): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title"><?= __('all_caught_up', "You're caught up") ?></div>
            <p class="text-sm"><?= __('no_pending_messages', 'No client messages are waiting for a reply.') ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="data-list" data-single-open>
    <?php foreach ($pending as $m):
        $waitedH  = $m['client_message_at']
            ? (int) floor((slate_db_time() - strtotime($m['client_message_at'])) / 3600)
            : null;
        $overdue  = $waitedH !== null && $waitedH >= BookingPlusAPI::globalNudgeHours();
        $badge    = $overdue ? ['Overdue · ' . $waitedH . 'h', 'inactive'] : ['New', 'active'];

        $detail = [
            'Service'   => (string)$m['service_name'],
            'Session'   => slate_format_datetime($m['starts_at'], 'l, j F Y', ' · '),
            'Reference' => ['label' => 'Reference', 'html' => '<code>' . e($m['ref']) . '</code>'],
            'Email'     => ['label' => 'Email',     'html' => '<a href="mailto:' . e($m['customer_email']) . '">' . e($m['customer_email']) . '</a>'],
            'Phone'     => $m['customer_phone'] ?: '—',
            'Received'  => $m['client_message_at'] ? slate_format_datetime($m['client_message_at'], 'j M Y') : '—',
        ];

        ob_start(); ?>
        <div style="padding:var(--space-3);background:var(--surface-2);border-radius:var(--radius-md);margin-bottom:var(--space-3);">
            <div style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:var(--muted);margin-bottom:6px;">Client message</div>
            <div style="white-space:pre-wrap;font-size:14px;line-height:1.5;"><?= e((string)$m['client_message']) ?></div>
        </div>
        <div style="display:flex;gap:var(--space-2);justify-content:flex-end;">
            <a href="mailto:<?= e($m['customer_email']) ?>?subject=<?= rawurlencode('Re: ' . $m['service_name'] . ' (' . $m['ref'] . ')') ?>" class="btn btn-secondary">Reply by email</a>
            <form method="post" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="mark_replied" value="<?= (int)$m['id'] ?>">
                <button type="submit" class="btn btn-primary">Mark replied</button>
            </form>
        </div>
        <?php
        $body = ob_get_clean();

        echo slate_data_row([
            'title'    => (string)$m['customer_name'],
            'sub'      => (string)$m['service_name'] . ' · ' . slate_format_datetime($m['starts_at'], 'j M Y'),
            'meta'     => mb_substr((string)$m['client_message'], 0, 90) . (mb_strlen((string)$m['client_message']) > 90 ? '…' : ''),
            'badge'    => $badge,
            'details'  => $detail,
            'expanded' => $body,
        ]);
    endforeach; ?>
    </div>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
