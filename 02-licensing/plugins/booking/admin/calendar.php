<?php
/**
 * Booking — calendar.
 *
 * Two views sharing one filtered dataset:
 *   - Month: a 7-column grid of day cells with "chip" appointments.
 *   - Week:  an hour-by-hour time grid (Mon–Sun) with positioned blocks.
 *
 * Both views support drag-and-drop rescheduling (month: drag a chip onto a
 * different day, keeping its time; week: drag a block to a new day/time,
 * snapped to 15 minutes) for anyone with booking.manage_appointments, and
 * share an advanced filter bar (provider, service, status, payment status,
 * free-text search) that narrows the same query feeding both grids.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';

Auth::require();
Auth::requirePerm('booking.view');
ModuleGuard::require('booking');
BookingAPI::ensureSchema();

$pageTitle  = __('booking_calendar', 'Calendar');
$currentNav = 'booking-calendar';

$tid     = current_tenant_id();
$canDrag = Auth::can('booking.manage_appointments');

// ── AJAX: drag-and-drop reschedule ───────────────────────────────
// Posted by the calendar's own JS after a drop. Kept in this file (rather
// than a separate endpoint) so it shares the exact same permission/schema
// bootstrap above it.
if (($_GET['ajax'] ?? '') === 'reschedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canDrag) {
        echo json_encode(['ok' => false, 'error' => __('booking_no_perm_reschedule', 'You do not have permission to reschedule appointments.')]);
        exit;
    }
    if (!csrf_verify()) {
        echo json_encode(['ok' => false, 'error' => __('booking_csrf_failed_reload', 'Security check failed — please reload the page and try again.')]);
        exit;
    }
    $apptId   = (int)($_POST['id'] ?? 0);
    $newStart = (string)($_POST['starts_at'] ?? '');
    if ($apptId <= 0 || $newStart === '') {
        echo json_encode(['ok' => false, 'error' => __('booking_missing_appt_or_time', 'Missing appointment or time.')]);
        exit;
    }
    echo json_encode(BookingAPI::rescheduleAppointment($apptId, $newStart, true));
    exit;
}

// ── View + advanced filters ──────────────────────────────────────
$view = ($_GET['view'] ?? 'month') === 'week' ? 'week' : 'month';

$providerId    = (int)($_GET['provider_id'] ?? 0);
$serviceId     = (int)($_GET['service_id'] ?? 0);
$status        = (string)($_GET['status'] ?? '');
$paymentStatus = (string)($_GET['payment_status'] ?? '');
$q             = trim((string)($_GET['q'] ?? ''));

$validStatuses  = ['pending', 'awaiting_approval', 'confirmed', 'cancelled', 'no_show', 'completed'];
$validPayStatus = ['none', 'pending', 'deposit_paid', 'paid', 'refunded', 'partially_refunded', 'failed'];
if (!in_array($status, $validStatuses, true))        { $status = ''; }
if (!in_array($paymentStatus, $validPayStatus, true)) { $paymentStatus = ''; }

// Resolve the month being viewed (YYYY-MM), defaulting to the current one.
$monthParam = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m');
}
$first      = DateTime::createFromFormat('Y-m-d', $monthParam . '-01') ?: new DateTime('first day of this month');
$first->setTime(0, 0, 0);
$monthLabel = I18n::localDate('F Y', $first->getTimestamp());
$prevMonth  = (clone $first)->modify('-1 month')->format('Y-m');
$nextMonth  = (clone $first)->modify('+1 month')->format('Y-m');

// Resolve the week being viewed (any date within it; Monday-start).
$weekParam = (string)($_GET['week'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekParam)) {
    $weekParam = date('Y-m-d');
}
$weekAnchor = DateTime::createFromFormat('Y-m-d', $weekParam) ?: new DateTime();
$weekAnchor->setTime(0, 0, 0);
$weekStart  = (clone $weekAnchor)->modify('-' . ((int)$weekAnchor->format('N') - 1) . ' days');
$weekEnd    = (clone $weekStart)->modify('+6 days');
$prevWeek   = (clone $weekStart)->modify('-7 days')->format('Y-m-d');
$nextWeek   = (clone $weekStart)->modify('+7 days')->format('Y-m-d');
$sameYear   = $weekStart->format('Y') === $weekEnd->format('Y');
$weekLabel  = I18n::localDate($sameYear ? 'j M' : 'j M Y', $weekStart->getTimestamp()) . ' – ' . I18n::localDate('j M Y', $weekEnd->getTimestamp());

if ($view === 'week') {
    $rangeStart = clone $weekStart;
    $rangeEnd   = clone $weekEnd;
} else {
    // Grid spans whole weeks (Monday-start): back up to the Monday on/before
    // the 1st, forward to the Sunday on/after the last day of the month.
    $last       = (clone $first)->modify('last day of this month');
    $rangeStart = (clone $first)->modify('-' . ((int)$first->format('N') - 1) . ' days');
    $rangeEnd   = (clone $last)->modify('+' . (7 - (int)$last->format('N')) . ' days');
}

// ── Build the filtered query (shared by both views) ──────────────
$where  = ['a.tenant_id = ?', 'a.starts_at >= ?', 'a.starts_at <= ?'];
$params = [$tid, $rangeStart->format('Y-m-d') . ' 00:00:00', $rangeEnd->format('Y-m-d') . ' 23:59:59'];
if ($providerId > 0)    { $where[] = 'a.provider_id = ?';    $params[] = $providerId; }
if ($serviceId > 0)     { $where[] = 'a.service_id = ?';     $params[] = $serviceId; }
if ($status !== '')     { $where[] = 'a.status = ?';         $params[] = $status; }
if ($paymentStatus !== '') { $where[] = 'a.payment_status = ?'; $params[] = $paymentStatus; }
if ($q !== '') {
    $where[] = '(a.customer_name LIKE ? OR a.customer_email LIKE ? OR a.ref LIKE ?)';
    $like    = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$rows = Database::rows(
    "SELECT a.id, a.ref, a.starts_at, a.ends_at, a.status, a.payment_status, a.customer_name, a.customer_email,
            s.name AS service_name, p.id AS provider_id, p.name AS provider_name, p.color AS provider_color
       FROM booking_appointments a
       JOIN booking_services  s ON s.id = a.service_id
       JOIN booking_providers p ON p.id = a.provider_id
      WHERE " . implode(' AND ', $where) . "
   ORDER BY a.starts_at",
    $params
);

// Bucket appointments by their start date (Y-m-d) — used by both views.
$byDay = [];
foreach ($rows as $r) {
    $byDay[date('Y-m-d', strtotime($r['starts_at']))][] = $r;
}

$providers = BookingAPI::getAllProviders();
$services  = BookingAPI::getActiveServices();
$todayKey  = date('Y-m-d');
$thisMonth = $first->format('Y-m');
$maxChips  = 4;

$activeFilterCount = ($providerId > 0 ? 1 : 0) + ($serviceId > 0 ? 1 : 0)
    + ($status !== '' ? 1 : 0) + ($paymentStatus !== '' ? 1 : 0) + ($q !== '' ? 1 : 0);

// Week time-grid bounds: default to a sensible business window, but widen
// automatically so no visible appointment ever gets clipped off-screen.
$gridStartHour = 7;
$gridEndHour   = 20;
if ($view === 'week') {
    foreach ($rows as $r) {
        $sTs = strtotime($r['starts_at']);
        $eTs = strtotime($r['ends_at']);
        $sH  = (int)date('G', $sTs);
        $eH  = (int)date('G', $eTs) + (date('i', $eTs) > '00' ? 1 : 0);
        if ($sH < $gridStartHour) $gridStartHour = $sH;
        if ($eH > $gridEndHour)   $gridEndHour   = $eH;
    }
}
$gridStartHour = max(0, min(23, $gridStartHour));
$gridEndHour   = max($gridStartHour + 1, min(24, $gridEndHour));
$pxPerHour     = 56;
$gridHeight    = ($gridEndHour - $gridStartHour) * $pxPerHour;

$statusLabels = [
    'pending' => __('booking_status_pending', 'Pending'),
    'awaiting_approval' => __('booking_status_awaiting_approval', 'Awaiting approval'),
    'confirmed' => __('booking_status_confirmed', 'Confirmed'),
    'cancelled' => __('booking_status_cancelled', 'Cancelled'),
    'no_show' => __('booking_status_no_show', 'No show'),
    'completed' => __('booking_status_completed', 'Completed'),
];
$payLabels = [
    'none' => __('booking_unpaid', 'Unpaid'),
    'pending' => __('booking_payment_pending', 'Payment pending'),
    'deposit_paid' => __('booking_deposit_paid', 'Deposit paid'),
    'paid' => __('booking_paid', 'Paid'),
    'refunded' => __('booking_refunded', 'Refunded'),
    'partially_refunded' => __('booking_partially_refunded', 'Partially refunded'),
    'failed' => __('booking_payment_failed_label', 'Payment failed'),
];

// Build a query string preserving the current view + filters, with
// per-call overrides (e.g. a different month, or switching view).
$baseQs = function (array $overrides = []) use ($view, $monthParam, $weekParam, $providerId, $serviceId, $status, $paymentStatus, $q) {
    $qs = array_merge([
        'view'           => $view,
        'month'          => $monthParam,
        'week'           => $weekParam,
        'provider_id'    => $providerId > 0 ? $providerId : null,
        'service_id'     => $serviceId > 0 ? $serviceId : null,
        'status'         => $status !== '' ? $status : null,
        'payment_status' => $paymentStatus !== '' ? $paymentStatus : null,
        'q'              => $q !== '' ? $q : null,
    ], $overrides);
    $qs = array_filter($qs, fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($qs);
};
$clearFiltersQs = $baseQs(['provider_id' => null, 'service_id' => null, 'status' => null, 'payment_status' => null, 'q' => null]);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'), 'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => __('booking_calendar_page_title', 'Calendar')],
]); ?>

<style>
.bk-cal-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-bottom:12px;position:relative;}
.bk-cal-nav{display:flex;align-items:center;gap:8px;}
.bk-cal-month{font-family:var(--font-display,inherit);font-size:1.25rem;font-weight:600;min-width:180px;}
.bk-cal-views{display:inline-flex;background:var(--surface-sunken,var(--card));border:1px solid var(--border);
    border-radius:var(--radius-sm,8px);padding:2px;gap:2px;}
.bk-cal-view-tab{border:0;background:transparent;padding:6px 14px;font-size:.82rem;font-weight:600;color:var(--muted);
    border-radius:6px;cursor:pointer;text-decoration:none;line-height:1.3;}
.bk-cal-view-tab.is-active{background:var(--card);color:var(--text);box-shadow:0 1px 2px rgba(0,0,0,.06);}
.bk-cal-toolbar-actions{margin-left:auto;display:flex;align-items:center;gap:8px;position:relative;}
.bk-cal-filter-btn{position:relative;}
.bk-cal-filter-badge{position:absolute;top:-6px;right:-6px;background:var(--accent);color:var(--on-accent,#fff);
    font-size:.65rem;font-weight:700;min-width:16px;height:16px;border-radius:8px;display:flex;align-items:center;
    justify-content:center;padding:0 4px;}
.bk-cal-filterpanel{position:absolute;top:calc(100% + 8px);right:0;z-index:30;background:var(--card);
    border:1px solid var(--border);border-radius:var(--radius,12px);box-shadow:var(--shadow-lg,0 12px 32px rgba(0,0,0,.14));
    padding:16px;width:320px;max-width:calc(100vw - 32px);display:none;}
.bk-cal-filterpanel.is-open{display:block;}
.bk-cal-filterpanel h3{margin:0 0 12px;font-size:.85rem;font-weight:700;}
.bk-cal-filterpanel .field{margin-bottom:10px;}
.bk-cal-filterpanel .field:last-of-type{margin-bottom:0;}
.bk-cal-filterpanel .field-label{display:block;font-size:.72rem;font-weight:600;color:var(--muted);margin-bottom:4px;}
.bk-cal-filterpanel select,.bk-cal-filterpanel input[type=text]{width:100%;}
.bk-cal-filter-footer{display:flex;justify-content:space-between;align-items:center;margin-top:14px;padding-top:12px;
    border-top:1px solid var(--border);}
.bk-cal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:1px;background:var(--border);
    border:1px solid var(--border);border-radius:var(--radius,12px);overflow:hidden;}
.bk-cal-dow{background:var(--surface-sunken,var(--card));padding:8px 10px;font-size:.72rem;font-weight:600;
    letter-spacing:.04em;text-transform:uppercase;color:var(--muted);text-align:left;}
.bk-cal-cell{background:var(--card);min-height:118px;padding:6px 6px 8px;display:flex;flex-direction:column;gap:4px;
    transition:background-color .12s ease;}
.bk-cal-cell.is-out{background:var(--bg);}
.bk-cal-cell.is-today{box-shadow:inset 0 0 0 2px var(--accent);}
.bk-cal-cell.is-dropok{background:var(--accent-soft,var(--surface-sunken));}
.bk-cal-daynum{font-size:.8rem;color:var(--muted);font-weight:600;text-decoration:none;display:inline-block;}
.bk-cal-cell.is-today .bk-cal-daynum{color:var(--accent);}
.bk-cal-daynum:hover{color:var(--text);}
.bk-cal-chip{display:block;font-size:.72rem;line-height:1.3;padding:2px 6px;border-radius:var(--radius-sm,6px);
    border-left:3px solid var(--accent);background:var(--accent-soft,var(--surface-sunken));color:var(--text);
    text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.bk-cal-chip:hover{filter:brightness(.97);}
.bk-cal-chip[draggable="true"]{cursor:grab;}
.bk-cal-chip.is-dragging{opacity:.4;}
.bk-cal-chip.is-cancelled{opacity:.55;text-decoration:line-through;}
.bk-cal-chip-time{font-variant-numeric:tabular-nums;font-weight:600;}
.bk-cal-more{font-size:.7rem;color:var(--muted);text-decoration:none;padding:1px 6px;}
.bk-cal-more:hover{color:var(--accent);}
.bk-cal-legend{display:flex;flex-wrap:wrap;gap:14px;margin-top:12px;font-size:.74rem;color:var(--muted);}
.bk-cal-legend span{display:inline-flex;align-items:center;gap:5px;}
.bk-cal-dot{width:10px;height:10px;border-radius:3px;display:inline-block;}

/* ── Week time-grid ── */
.bk-week{display:grid;grid-template-columns:56px repeat(7,minmax(0,1fr));border:1px solid var(--border);
    border-radius:var(--radius,12px);overflow:hidden;background:var(--card);}
.bk-week-head{display:contents;}
.bk-week-head-cell{background:var(--surface-sunken,var(--card));padding:8px 6px;text-align:center;
    border-bottom:1px solid var(--border);border-left:1px solid var(--border);}
.bk-week-head-cell:first-child{border-left:0;}
.bk-week-head-gutter{background:var(--surface-sunken,var(--card));border-bottom:1px solid var(--border);}
.bk-week-head-dow{font-size:.68rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);}
.bk-week-head-num{font-size:1rem;font-weight:700;margin-top:2px;}
.bk-week-head-cell.is-today .bk-week-head-num{color:var(--accent);}
.bk-week-body{grid-column:1 / -1;display:grid;grid-template-columns:56px repeat(7,minmax(0,1fr));position:relative;}
.bk-week-gutter{position:relative;border-left:0;}
.bk-week-hour-label{position:absolute;right:6px;transform:translateY(-50%);font-size:.68rem;color:var(--muted);
    white-space:nowrap;}
.bk-week-col{position:relative;border-left:1px solid var(--border);background-image:repeating-linear-gradient(
    to bottom, var(--border) 0, var(--border) 1px, transparent 1px, transparent <?= (int)$pxPerHour ?>px);
    background-position:0 -1px;}
.bk-week-col.is-today{background-color:var(--accent-soft,rgba(124,58,237,.04));}
.bk-week-col.is-dropok{outline:2px dashed var(--accent);outline-offset:-2px;}
.bk-week-now{position:absolute;left:0;right:0;height:2px;background:#ef4444;z-index:5;pointer-events:none;}
.bk-week-now::before{content:'';position:absolute;left:-4px;top:-3px;width:8px;height:8px;border-radius:50%;background:#ef4444;}
.bk-week-indicator{position:absolute;left:2px;right:2px;height:2px;background:var(--accent);border-radius:1px;
    display:none;z-index:6;pointer-events:none;}
.bk-week-event{position:absolute;left:3px;right:3px;border-radius:6px;border-left:3px solid var(--accent);
    background:var(--accent-soft,var(--surface-sunken));padding:3px 6px;overflow:hidden;font-size:.7rem;
    line-height:1.25;box-shadow:0 1px 2px rgba(0,0,0,.06);text-decoration:none;color:var(--text);z-index:2;}
.bk-week-event:hover{filter:brightness(.97);z-index:3;}
.bk-week-event[draggable="true"]{cursor:grab;}
.bk-week-event.is-dragging{opacity:.4;}
.bk-week-event.is-cancelled{opacity:.5;text-decoration:line-through;}
.bk-week-event-time{font-weight:700;font-variant-numeric:tabular-nums;display:block;}
.bk-week-event-title{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

.bk-cal-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(12px);background:#0f172a;
    color:#fff;padding:10px 18px;border-radius:8px;font-size:.85rem;box-shadow:0 8px 24px rgba(0,0,0,.25);
    opacity:0;transition:opacity .2s ease, transform .2s ease;z-index:100;pointer-events:none;}
.bk-cal-toast.is-error{background:#991b1b;}
.bk-cal-toast.is-visible{opacity:1;transform:translateX(-50%) translateY(0);}

@media (max-width:768px){
    .bk-cal-cell{min-height:84px;}
    .bk-cal-chip-cust{display:none;}
    .bk-cal-month{min-width:0;}
    .bk-week{overflow-x:auto;}
}
</style>

<div class="page-header">
    <div><h1><?= e(__('booking_calendar_page_title', 'Calendar')) ?></h1><p class="page-header-sub"><?= e($view === 'week' ? $weekLabel : $monthLabel) ?></p></div>
    <?php if (Auth::can('booking.manage_appointments')): ?>
        <a href="<?= e(plugin_url('booking', 'admin/new.php')) ?>" class="btn btn-primary"><?= e(__('booking_new_walkin', 'New / walk-in')) ?></a>
    <?php endif; ?>
</div>

<div class="bk-cal-toolbar">
    <div class="bk-cal-views">
        <a class="bk-cal-view-tab<?= $view === 'month' ? ' is-active' : '' ?>" href="<?= e($baseQs(['view' => 'month'])) ?>"><?= e(__('booking_month', 'Month')) ?></a>
        <a class="bk-cal-view-tab<?= $view === 'week' ? ' is-active' : '' ?>" href="<?= e($baseQs(['view' => 'week'])) ?>"><?= e(__('booking_week', 'Week')) ?></a>
    </div>

    <?php if ($view === 'month'): ?>
        <div class="bk-cal-nav">
            <a class="btn btn-sm" href="<?= e($baseQs(['month' => $prevMonth])) ?>" aria-label="<?= e(__('booking_prev_month', 'Previous month')) ?>">&larr;</a>
            <span class="bk-cal-month"><?= e($monthLabel) ?></span>
            <a class="btn btn-sm" href="<?= e($baseQs(['month' => $nextMonth])) ?>" aria-label="<?= e(__('booking_next_month', 'Next month')) ?>">&rarr;</a>
            <a class="btn btn-sm btn-ghost" href="<?= e($baseQs(['month' => date('Y-m')])) ?>"><?= e(__('booking_today', 'Today')) ?></a>
        </div>
    <?php else: ?>
        <div class="bk-cal-nav">
            <a class="btn btn-sm" href="<?= e($baseQs(['week' => $prevWeek])) ?>" aria-label="<?= e(__('booking_prev_week', 'Previous week')) ?>">&larr;</a>
            <span class="bk-cal-month"><?= e($weekLabel) ?></span>
            <a class="btn btn-sm" href="<?= e($baseQs(['week' => $nextWeek])) ?>" aria-label="<?= e(__('booking_next_week', 'Next week')) ?>">&rarr;</a>
            <a class="btn btn-sm btn-ghost" href="<?= e($baseQs(['week' => date('Y-m-d')])) ?>"><?= e(__('booking_today', 'Today')) ?></a>
        </div>
    <?php endif; ?>

    <div class="bk-cal-toolbar-actions">
        <button type="button" id="bkCalFilterToggle" class="btn btn-sm bk-cal-filter-btn">
            <?= e(__('booking_filters', 'Filters')) ?>
            <?php if ($activeFilterCount > 0): ?><span class="bk-cal-filter-badge"><?= (int)$activeFilterCount ?></span><?php endif; ?>
        </button>

        <div id="bkCalFilterPanel" class="bk-cal-filterpanel<?= $activeFilterCount > 0 ? '' : '' ?>">
            <h3><?= e(__('booking_advanced_filters', 'Advanced filters')) ?></h3>
            <form method="get">
                <input type="hidden" name="view" value="<?= e($view) ?>">
                <input type="hidden" name="month" value="<?= e($monthParam) ?>">
                <input type="hidden" name="week" value="<?= e($weekParam) ?>">

                <div class="field">
                    <label class="field-label" for="f_provider_id"><?= e(__('provider', 'Provider')) ?></label>
                    <select id="f_provider_id" name="provider_id">
                        <option value="0"><?= e(__('booking_all_providers', 'All providers')) ?></option>
                        <?php foreach ($providers as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $providerId === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="field-label" for="f_service_id"><?= e(__('service', 'Service')) ?></label>
                    <select id="f_service_id" name="service_id">
                        <option value="0"><?= e(__('booking_all_services', 'All services')) ?></option>
                        <?php foreach ($services as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= $serviceId === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="field-label" for="f_status"><?= e(__('status', 'Status')) ?></label>
                    <select id="f_status" name="status">
                        <option value=""><?= e(__('booking_any_status', 'Any status')) ?></option>
                        <?php foreach ($statusLabels as $val => $lbl): ?>
                            <option value="<?= e($val) ?>" <?= $status === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="field-label" for="f_payment_status"><?= e(__('booking_payment_label', 'Payment')) ?></label>
                    <select id="f_payment_status" name="payment_status">
                        <option value=""><?= e(__('booking_any_payment_status', 'Any payment status')) ?></option>
                        <?php foreach ($payLabels as $val => $lbl): ?>
                            <option value="<?= e($val) ?>" <?= $paymentStatus === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="field-label" for="f_q"><?= e(__('search', 'Search')) ?></label>
                    <input type="text" id="f_q" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('booking_search_customer_ph', 'Customer name, email or ref')) ?>">
                </div>

                <div class="bk-cal-filter-footer">
                    <a href="<?= e($clearFiltersQs) ?>" class="btn btn-sm btn-ghost"><?= e(__('booking_clear_all', 'Clear all')) ?></a>
                    <button type="submit" class="btn btn-sm btn-primary"><?= e(__('booking_apply_filters', 'Apply filters')) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($view === 'month'): ?>

<div class="bk-cal-grid">
    <?php
    $dowCursor = clone $weekStart;
    for ($i = 0; $i < 7; $i++):
    ?>
        <div class="bk-cal-dow"><?= e(I18n::localDate('D', $dowCursor->getTimestamp())) ?></div>
    <?php
        $dowCursor->modify('+1 day');
    endfor;
    ?>

    <?php
    $cursor = clone $rangeStart;
    while ($cursor <= $rangeEnd):
        $key      = $cursor->format('Y-m-d');
        $inMonth  = $cursor->format('Y-m') === $thisMonth;
        $isToday  = $key === $todayKey;
        $dayAppts = $byDay[$key] ?? [];
        $cls      = 'bk-cal-cell' . ($inMonth ? '' : ' is-out') . ($isToday ? ' is-today' : '');
        $dayList  = plugin_url('booking', 'admin/appointments.php') . '?from=' . $key . '&to=' . $key
                  . ($providerId > 0 ? '&provider_id=' . $providerId : '');
    ?>
        <div class="<?= $cls ?>" data-date="<?= e($key) ?>">
            <a class="bk-cal-daynum" href="<?= e($dayList) ?>" title="<?= e(__('booking_view_this_day', 'View this day')) ?>"><?= (int)$cursor->format('j') ?></a>
            <?php foreach (array_slice($dayAppts, 0, $maxChips) as $a):
                $color    = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$a['provider_color']) ? $a['provider_color'] : 'var(--accent)';
                $draggable = $canDrag && $a['status'] !== 'cancelled';
                $chipCls  = 'bk-cal-chip' . ($a['status'] === 'cancelled' ? ' is-cancelled' : '');
                $first1   = trim((string)strtok((string)$a['customer_name'], ' '));
                // $timeHm24 is a machine value: the drag/reschedule JS below
                // concatenates it straight into "YYYY-MM-DD HH:MM:00" for the
                // reschedule API call, so it must stay 24-hour regardless of
                // the site's display setting. $timeDisplay is what a person
                // actually reads (chip label + tooltip).
                $timeHm24    = date('H:i', strtotime($a['starts_at']));
                $timeDisplay = slate_format_time($a['starts_at']);
            ?>
                <a class="<?= $chipCls ?>" style="border-left-color:<?= e($color) ?>;"
                   href="<?= e(plugin_url('booking', 'admin/appointment.php')) ?>?id=<?= (int)$a['id'] ?>"
                   <?php if ($draggable): ?>draggable="true" data-appt-id="<?= (int)$a['id'] ?>" data-time="<?= e($timeHm24) ?>"<?php endif; ?>
                   title="<?= e($timeDisplay . ' · ' . $a['customer_name'] . ' · ' . $a['service_name'] . ' · ' . $a['provider_name']) ?>">
                    <span class="bk-cal-chip-time"><?= e($timeDisplay) ?></span>
                    <span class="bk-cal-chip-cust"> <?= e($first1) ?></span>
                </a>
            <?php endforeach; ?>
            <?php if (count($dayAppts) > $maxChips): ?>
                <a class="bk-cal-more" href="<?= e($dayList) ?>">+<?= e(sprintf(__('booking_n_more', '%d more'), count($dayAppts) - $maxChips)) ?></a>
            <?php endif; ?>
        </div>
    <?php
        $cursor->modify('+1 day');
    endwhile;
    ?>
</div>

<div class="bk-cal-legend">
    <span><span class="bk-cal-dot" style="background:var(--accent);"></span> <?= e(__('booking_legend_chip_colour', 'Chip colour = provider')) ?></span>
    <span><span class="bk-cal-dot" style="background:var(--border);"></span> <?= e(__('booking_legend_faded_cancelled', 'Faded / struck = cancelled')) ?></span>
    <?php if ($canDrag): ?><span><?= e(__('booking_legend_drag_chip', 'Drag a chip onto another day to reschedule it')) ?></span><?php endif; ?>
</div>

<?php else: /* ── Week time-grid ── */ ?>

<div class="bk-week">
    <div class="bk-week-head">
        <div class="bk-week-head-gutter"></div>
        <?php
        $wCursor = clone $weekStart;
        while ($wCursor <= $weekEnd):
            $isToday = $wCursor->format('Y-m-d') === $todayKey;
        ?>
            <div class="bk-week-head-cell<?= $isToday ? ' is-today' : '' ?>">
                <div class="bk-week-head-dow"><?= e(I18n::localDate('D', $wCursor->getTimestamp())) ?></div>
                <div class="bk-week-head-num"><?= (int)$wCursor->format('j') ?></div>
            </div>
        <?php
            $wCursor->modify('+1 day');
        endwhile;
        ?>
    </div>

    <div class="bk-week-body" style="height:<?= (int)$gridHeight ?>px;">
        <div class="bk-week-gutter" style="height:<?= (int)$gridHeight ?>px;">
            <?php for ($h = $gridStartHour; $h <= $gridEndHour; $h++): ?>
                <span class="bk-week-hour-label" style="top:<?= (int)(($h - $gridStartHour) * $pxPerHour) ?>px;">
                    <?= e(slate_format_time(mktime($h, 0))) ?>
                </span>
            <?php endfor; ?>
        </div>

        <?php
        $now = new DateTime();
        $wCursor = clone $weekStart;
        while ($wCursor <= $weekEnd):
            $key      = $wCursor->format('Y-m-d');
            $isToday  = $key === $todayKey;
            $dayAppts = $byDay[$key] ?? [];
        ?>
            <div class="bk-week-col<?= $isToday ? ' is-today' : '' ?>" data-date="<?= e($key) ?>" style="height:<?= (int)$gridHeight ?>px;">
                <div class="bk-week-indicator"></div>
                <?php if ($isToday):
                    $nowMin = ((int)$now->format('G')) * 60 + (int)$now->format('i');
                    $gridMin = $gridStartHour * 60;
                    $gridMax = $gridEndHour * 60;
                    if ($nowMin >= $gridMin && $nowMin <= $gridMax):
                        $nowTop = ($nowMin - $gridMin) / 60 * $pxPerHour;
                ?>
                    <div class="bk-week-now" style="top:<?= (int)$nowTop ?>px;"></div>
                <?php endif; endif; ?>

                <?php foreach ($dayAppts as $a):
                    $sTs = strtotime($a['starts_at']);
                    $eTs = strtotime($a['ends_at']);
                    $startMin = ((int)date('G', $sTs)) * 60 + (int)date('i', $sTs);
                    $durMin   = max(15, (int)round(($eTs - $sTs) / 60));
                    $top      = max(0, ($startMin - $gridStartHour * 60) / 60 * $pxPerHour);
                    $height   = max(20, $durMin / 60 * $pxPerHour - 2);
                    $color    = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$a['provider_color']) ? $a['provider_color'] : 'var(--accent)';
                    $draggable = $canDrag && $a['status'] !== 'cancelled';
                    $evCls    = 'bk-week-event' . ($a['status'] === 'cancelled' ? ' is-cancelled' : '');
                    $timeHm   = slate_format_time($sTs);
                ?>
                    <a class="<?= $evCls ?>" style="top:<?= (int)$top ?>px;height:<?= (int)$height ?>px;border-left-color:<?= e($color) ?>;"
                       href="<?= e(plugin_url('booking', 'admin/appointment.php')) ?>?id=<?= (int)$a['id'] ?>"
                       <?php if ($draggable): ?>draggable="true" data-appt-id="<?= (int)$a['id'] ?>" data-duration-min="<?= (int)$durMin ?>"<?php endif; ?>
                       title="<?= e($timeHm . ' · ' . $a['customer_name'] . ' · ' . $a['service_name'] . ' · ' . $a['provider_name']) ?>">
                        <span class="bk-week-event-time"><?= e($timeHm) ?></span>
                        <span class="bk-week-event-title"><?= e($a['customer_name']) ?> · <?= e($a['service_name']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php
            $wCursor->modify('+1 day');
        endwhile;
        ?>
    </div>
</div>

<div class="bk-cal-legend">
    <span><span class="bk-cal-dot" style="background:var(--accent);"></span> <?= e(__('booking_legend_block_colour', 'Block colour = provider')) ?></span>
    <span><span class="bk-cal-dot" style="background:#ef4444;"></span> <?= e(__('booking_legend_red_line_now', 'Red line = now')) ?></span>
    <?php if ($canDrag): ?><span><?= e(__('booking_legend_drag_block', 'Drag a block to a new time or day to reschedule it (snaps to 15 min)')) ?></span><?php endif; ?>
</div>

<?php endif; ?>

<script>
(function () {
    var CSRF = (document.querySelector('meta[name="csrf"]') || {}).content || '';
    var i18n = <?= json_encode([
        'rescheduled'   => __('booking_toast_rescheduled', 'Appointment rescheduled.'),
        'couldNotResch' => __('booking_toast_could_not_reschedule', 'Could not reschedule that appointment.'),
        'networkError'  => __('booking_toast_network_error', 'Network error — please try again.'),
    ], JSON_UNESCAPED_UNICODE) ?>;

    function toast(msg, kind) {
        var t = document.createElement('div');
        t.className = 'bk-cal-toast' + (kind === 'error' ? ' is-error' : '');
        t.textContent = msg;
        document.body.appendChild(t);
        requestAnimationFrame(function () { t.classList.add('is-visible'); });
        setTimeout(function () {
            t.classList.remove('is-visible');
            setTimeout(function () { t.remove(); }, 250);
        }, 3200);
    }

    function reschedule(id, startsAt) {
        var body = new URLSearchParams({ id: String(id), starts_at: startsAt, _csrf: CSRF });
        fetch('?ajax=reschedule', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.ok) {
                toast(i18n.rescheduled);
                setTimeout(function () { window.location.reload(); }, 350);
            } else {
                toast((data && data.error) || i18n.couldNotResch, 'error');
            }
        }).catch(function () {
            toast(i18n.networkError, 'error');
        });
    }

    // ── Filter panel toggle ──────────────────────────────────────
    var filterBtn   = document.getElementById('bkCalFilterToggle');
    var filterPanel = document.getElementById('bkCalFilterPanel');
    if (filterBtn && filterPanel) {
        filterBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            filterPanel.classList.toggle('is-open');
        });
        filterPanel.addEventListener('click', function (e) { e.stopPropagation(); });
        document.addEventListener('click', function () { filterPanel.classList.remove('is-open'); });
        <?php if ($activeFilterCount > 0): ?>
        // Nothing extra needed — the badge already reflects active filters.
        <?php endif; ?>
    }

    // ── Month view: drag a chip onto another day ─────────────────
    document.querySelectorAll('.bk-cal-chip[draggable="true"]').forEach(function (chip) {
        chip.addEventListener('dragstart', function (e) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', JSON.stringify({
                id: chip.getAttribute('data-appt-id'),
                time: chip.getAttribute('data-time')
            }));
            chip.classList.add('is-dragging');
        });
        chip.addEventListener('dragend', function () { chip.classList.remove('is-dragging'); });
    });
    document.querySelectorAll('.bk-cal-cell[data-date]').forEach(function (cell) {
        cell.addEventListener('dragover', function (e) {
            e.preventDefault();
            cell.classList.add('is-dropok');
        });
        cell.addEventListener('dragleave', function () { cell.classList.remove('is-dropok'); });
        cell.addEventListener('drop', function (e) {
            e.preventDefault();
            cell.classList.remove('is-dropok');
            var data;
            try { data = JSON.parse(e.dataTransfer.getData('text/plain')); } catch (err) { return; }
            if (!data || !data.id) return;
            reschedule(data.id, cell.getAttribute('data-date') + ' ' + data.time + ':00');
        });
    });

    // ── Week view: drag a block to a new day/time ────────────────
    var PX_PER_HOUR = <?= (int)$pxPerHour ?>;
    var GRID_START_HOUR = <?= (int)$gridStartHour ?>;
    document.querySelectorAll('.bk-week-event[draggable="true"]').forEach(function (ev) {
        ev.addEventListener('dragstart', function (e) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', JSON.stringify({ id: ev.getAttribute('data-appt-id') }));
            ev.classList.add('is-dragging');
        });
        ev.addEventListener('dragend', function () { ev.classList.remove('is-dragging'); });
    });
    document.querySelectorAll('.bk-week-col[data-date]').forEach(function (col) {
        var indicator = col.querySelector('.bk-week-indicator');
        col.addEventListener('dragover', function (e) {
            e.preventDefault();
            var rect = col.getBoundingClientRect();
            var mins = (e.clientY - rect.top) / PX_PER_HOUR * 60;
            mins = Math.max(0, Math.round(mins / 15) * 15);
            col.__dropMinutes = mins;
            col.classList.add('is-dropok');
            if (indicator) {
                indicator.style.display = 'block';
                indicator.style.top = (mins / 60 * PX_PER_HOUR) + 'px';
            }
        });
        col.addEventListener('dragleave', function () {
            col.classList.remove('is-dropok');
            if (indicator) indicator.style.display = 'none';
        });
        col.addEventListener('drop', function (e) {
            e.preventDefault();
            col.classList.remove('is-dropok');
            if (indicator) indicator.style.display = 'none';
            var data;
            try { data = JSON.parse(e.dataTransfer.getData('text/plain')); } catch (err) { return; }
            if (!data || !data.id) return;
            var mins = col.__dropMinutes || 0;
            var totalMin = GRID_START_HOUR * 60 + mins;
            var hh = String(Math.floor(totalMin / 60)).padStart(2, '0');
            var mm = String(totalMin % 60).padStart(2, '0');
            reschedule(data.id, col.getAttribute('data-date') + ' ' + hh + ':' + mm + ':00');
        });
    });
})();
</script>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
