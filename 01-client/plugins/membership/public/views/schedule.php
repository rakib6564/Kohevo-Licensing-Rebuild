<?php
/** Member app — Schedule tab (premium). Scope: router.php ($cid, $tid). */
if (!defined('SLATE_ROOT')) exit;

$appts = [];
$hasBooking = false;
try {
    $hasBooking = (int) Database::value(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_appointments'") > 0;
    if ($hasBooking) {
        $appts = Database::rows(
            "SELECT a.ref, a.starts_at, a.status, s.name AS service_name
               FROM booking_appointments a
               LEFT JOIN booking_services s ON s.id = a.service_id
              WHERE a.customer_id = ? AND a.tenant_id = ?
                AND a.starts_at >= NOW() - INTERVAL 1 DAY AND a.status IN ('confirmed','pending')
           ORDER BY a.starts_at LIMIT 30",
            [$cid, $tid]
        );
    }
} catch (\Throwable $e) { /* booking not installed */ }
?>
<div class="mview-head">
    <div>
        <h1><?= __('membership_schedule', 'Schedule') ?></h1>
        <p><?= __('membership_sched_sub', 'Your upcoming sessions.') ?></p>
    </div>
    <?php if ($hasBooking): ?>
        <a href="<?= e(SLATE_URL) ?>/book" class="mbtn mbtn-primary"><?= __('membership_book_now', 'Book a session') ?></a>
    <?php endif; ?>
</div>

<div class="pcard">
    <?php if (!$hasBooking): ?>
        <p class="qr-meta" style="text-align:center;padding:20px 0;"><?= __('membership_no_booking', 'Booking is not available') ?></p>
    <?php elseif (!$appts): ?>
        <div style="text-align:center;padding:28px 10px;">
            <div style="font-size:34px;margin-bottom:8px;"><?= slate_icon('calendar') ?></div>
            <div class="pcard-title"><?= __('membership_no_upcoming', 'No upcoming sessions') ?></div>
            <p class="qr-meta" style="margin:6px 0 16px;"><?= __('membership_book_prompt', 'Book your next session to see it here.') ?></p>
            <a href="<?= e(SLATE_URL) ?>/book" class="mbtn mbtn-primary"><?= __('membership_book_now', 'Book a session') ?></a>
        </div>
    <?php else: foreach ($appts as $a):
        $confirmed = $a['status'] === 'confirmed';
    ?>
        <div class="sched-row">
            <div class="sched-main">
                <b><?= e((string)($a['service_name'] ?? __('membership_session','Session'))) ?></b>
                <span><?= __('membership_ref', 'Ref') ?> <code><?= e((string)$a['ref']) ?></code></span>
            </div>
            <div class="sched-when">
                <b><?= e(I18n::localDate('j M Y', strtotime($a['starts_at']))) ?></b>
                <span class="qr-meta"><?= e(date('H:i', strtotime($a['starts_at']))) ?></span>
            </div>
            <span class="pill <?= $confirmed ? 'pill-green' : 'pill-amber' ?>"><?= $confirmed ? '✓ '.__('membership_confirmed','Confirmed') : __('membership_pending','Pending') ?></span>
        </div>
    <?php endforeach; endif; ?>
</div>
