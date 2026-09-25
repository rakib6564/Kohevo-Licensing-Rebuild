<?php
/**
 * Booking — appointments list (filter by provider / status / date range).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';

Auth::require();
Auth::requirePerm('booking.view');
BookingAPI::ensureSchema();

$pageTitle  = __('booking_appointments', 'Appointments');
$currentNav = 'booking-appointments';

$tid = current_tenant_id();

$providerId = (int)($_GET['provider_id'] ?? 0);
$status     = (string)($_GET['status'] ?? '');
$from       = (string)($_GET['from'] ?? '');
$to         = (string)($_GET['to']   ?? '');
$groupOnly  = !empty($_GET['group_only']);

$where  = ['a.tenant_id = ?'];
$params = [$tid];
if ($providerId > 0)             { $where[] = 'a.provider_id = ?'; $params[] = $providerId; }
if (in_array($status, ['pending','awaiting_approval','confirmed','cancelled','no_show','completed'], true)) {
    $where[] = 'a.status = ?'; $params[] = $status;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'a.starts_at >= ?'; $params[] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'a.starts_at <= ?'; $params[] = $to   . ' 23:59:59'; }
// "Group bookings only" — a customer's weekly-repeat or multi-select batch
// (any appointment sharing a recurrence_group with at least one other row).
if ($groupOnly) {
    $where[] = 'a.recurrence_group IS NOT NULL AND a.recurrence_group IN ('
             . 'SELECT recurrence_group FROM booking_appointments '
             . 'WHERE tenant_id = ? AND recurrence_group IS NOT NULL '
             . 'GROUP BY recurrence_group HAVING COUNT(*) >= 2)';
    $params[] = $tid;
}

// Pagination
$perPage    = 30;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalRows  = (int) Database::value(
    "SELECT COUNT(*) FROM booking_appointments a WHERE " . implode(' AND ', $where), $params);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = Database::rows(
    "SELECT a.*, s.name AS service_name, p.name AS provider_name
       FROM booking_appointments a
       JOIN booking_services  s ON s.id = a.service_id
       JOIN booking_providers p ON p.id = a.provider_id
      WHERE " . implode(' AND ', $where) . "
   ORDER BY a.starts_at DESC LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$providers = BookingAPI::getAllProviders();

// Group-booking badge support: for every recurrence_group on this page of
// results, look up how many appointments in that group actually exist
// (across the whole tenant, not just this filtered/paginated page), so a
// row can show "Group of 4" even if only 1 of the 4 falls on this page.
$groupCounts = [];
$groupIds    = array_values(array_unique(array_filter(array_map(
    static fn(array $a) => $a['recurrence_group'] ?? null, $rows
))));
if ($groupIds) {
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $gRows = Database::rows(
        "SELECT recurrence_group, COUNT(*) AS cnt FROM booking_appointments
          WHERE tenant_id = ? AND recurrence_group IN ($placeholders)
          GROUP BY recurrence_group",
        array_merge([$tid], $groupIds)
    );
    foreach ($gRows as $g) { $groupCounts[$g['recurrence_group']] = (int)$g['cnt']; }
}

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'), 'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => __('booking_appointments', 'Appointments')],
]); ?>

<div class="page-header">
    <div><h1><?= e(__('booking_appointments', 'Appointments')) ?></h1><p class="page-header-sub"><?= e(sprintf(__('booking_n_total', '%s total.'), number_format($totalRows))) ?></p></div>
    <?php if (Auth::can('booking.manage_appointments')): ?>
        <a href="<?= e(plugin_url('booking', 'admin/new.php')) ?>" class="btn btn-primary"><?= e(__('booking_new_walkin', 'New / walk-in')) ?></a>
    <?php endif; ?>
</div>

<div class="card tight">
    <form method="get" class="filter-row">
        <div class="field filter-search">
            <label class="field-label" for="provider_id"><?= e(__('provider', 'Provider')) ?></label>
            <select id="provider_id" name="provider_id">
                <option value="0"><?= e(__('booking_all_providers', 'All providers')) ?></option>
                <?php foreach ($providers as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $providerId === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="field-label" for="status"><?= e(__('status', 'Status')) ?></label>
            <select id="status" name="status">
                <option value=""><?= e(__('booking_all', 'All')) ?></option>
                <option value="pending"    <?= $status==='pending'    ? 'selected':'' ?>><?= e(__('booking_status_pending', 'Pending')) ?></option>
                <option value="awaiting_approval" <?= $status==='awaiting_approval' ? 'selected':'' ?>><?= e(__('booking_status_awaiting_approval', 'Awaiting approval')) ?></option>
                <option value="confirmed"  <?= $status==='confirmed'  ? 'selected':'' ?>><?= e(__('booking_status_confirmed', 'Confirmed')) ?></option>
                <option value="cancelled"  <?= $status==='cancelled'  ? 'selected':'' ?>><?= e(__('booking_status_cancelled', 'Cancelled')) ?></option>
                <option value="no_show"    <?= $status==='no_show'    ? 'selected':'' ?>><?= e(__('booking_status_no_show', 'No show')) ?></option>
                <option value="completed"  <?= $status==='completed'  ? 'selected':'' ?>><?= e(__('booking_status_completed', 'Completed')) ?></option>
            </select>
        </div>
        <div class="field">
            <label class="field-label" for="from"><?= e(__('booking_from', 'From')) ?></label>
            <input type="date" id="from" name="from" value="<?= e($from) ?>">
        </div>
        <div class="field">
            <label class="field-label" for="to"><?= e(__('booking_to', 'To')) ?></label>
            <input type="date" id="to" name="to" value="<?= e($to) ?>">
        </div>
        <div class="field" style="justify-content:flex-end;">
            <label class="field-label" for="group_only" style="visibility:hidden;"><?= e(__('booking_group', 'Group')) ?></label>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;white-space:nowrap;">
                <input type="checkbox" id="group_only" name="group_only" value="1" <?= $groupOnly ? 'checked' : '' ?>>
                <?= e(__('booking_group_bookings_only', 'Group bookings only')) ?>
            </label>
        </div>
        <div class="filter-actions">
            <button class="btn"><?= e(__('filter', 'Filter')) ?></button>
            <a href="?" class="btn btn-ghost"><?= e(__('reset', 'Reset')) ?></a>
        </div>
    </form>
</div>

<?php if (!$rows): ?>
    <div class="card"><div class="empty"><div class="empty-title"><?= e(__('booking_no_appts_match', 'No appointments match')) ?></div></div></div>
<?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($rows as $a):
            $statusBadge = [
                'pending'           => [__('booking_status_pending', 'Pending'), 'warning'],
                'awaiting_approval' => [__('booking_status_awaiting_approval', 'Awaiting approval'), 'warning'],
                'confirmed'         => [__('booking_status_confirmed', 'Confirmed'), 'active'],
                'cancelled'         => [__('booking_status_cancelled', 'Cancelled'), 'inactive'],
                'no_show'           => [__('booking_status_no_show', 'No show'), 'warning'],
                'completed'         => [__('booking_status_completed', 'Completed'), 'accent'],
            ][$a['status']] ?? ['?', ''];

            $detail = [
                __('booking_ref', 'Ref')           => ['label' => __('booking_ref', 'Ref'), 'html' => '<code>' . e($a['ref']) . '</code>'],
                __('booking_when', 'When')          => slate_format_datetime($a['starts_at'], 'l, j F Y') . ' – ' . slate_format_time($a['ends_at']),
                __('service', 'Service')       => $a['service_name'],
                __('provider', 'Provider')      => $a['provider_name'],
                __('booking_customer_singular', 'Customer')      => $a['customer_name'] . ' · ' . $a['customer_email']
                                   . ($a['customer_phone'] ? ' · ' . $a['customer_phone'] : ''),
            ];
            if (!empty($a['notes'])) $detail[__('booking_notes', 'Notes')] = ['label'=>__('booking_notes', 'Notes'),'value'=>$a['notes'],'muted'=>true];

            $actions = '<a href="' . e(plugin_url('booking', 'admin/appointment.php')) . '?id=' . (int)$a['id'] . '" class="btn btn-sm btn-primary">' . e(__('open', 'Open')) . '</a>';

            // "Group of N" — this row shares a recurrence_group with at
            // least one other appointment (a weekly-repeat series or a
            // multi-select batch booking). Shown next to the date/provider
            // meta line rather than in the main status-badge slot, since
            // that slot is already used for Pending/Confirmed/etc.
            $groupKey  = $a['recurrence_group'] ?? null;
            $groupCnt  = $groupKey !== null ? ($groupCounts[$groupKey] ?? 0) : 0;
            $metaHtml  = e(slate_format_datetime($a['starts_at'], 'D j M') . ' · ' . $a['provider_name']);
            if ($groupCnt >= 2) {
                $metaHtml .= ' <span class="badge badge-accent" title="' . e(sprintf(__('booking_booked_together_n', 'Booked together with %d other time(s)'), $groupCnt - 1))
                           . '">' . e(sprintf(__('booking_group_of_n', 'Group of %d'), $groupCnt)) . '</span>';
                $detail[__('booking_group', 'Group')] = ['label' => __('booking_group', 'Group'), 'html' =>
                    e($groupCnt) . ' ' . e(__('booking_group_appts_note', 'appointments booked together — open this one to see the full list.'))];
            }

            slate_data_row([
                'avatar_html'  => slate_avatar_overlay_html(mb_substr($a['customer_name'], 0, 1), (string)($a['customer_email'] ?? '')),
                'avatar_color' => $a['status'] === 'confirmed' ? 'info' : 'muted',
                'title'        => $a['customer_name'] . ' · ' . $a['service_name'],
                'meta_html'    => $metaHtml,
                'badge'        => $statusBadge,
                'detail'       => $detail,
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php slate_pagination($page, $totalPages, $_GET, [
        'total' => $totalRows, 'per_page' => $perPage, 'label' => __('booking_appointments', 'appointments'),
    ]); ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
