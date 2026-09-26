<?php
/**
 * Booking — admin overview.
 * Today + this-week appointments at a glance, plus headline KPIs.
 *
 * The KPI row uses the shared slate_stat_card() component (see
 * includes/ui_components.php) — the same glass stat-card look the main
 * admin dashboard uses — each with a small trend sparkline built from this
 * plugin's own recent data. The appointment lists use slate_dlist_row(),
 * the same "recent list" row Booking's own admin_dashboard_widgets
 * contribution already uses on the main dashboard, so the visual language
 * is consistent across both.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';

Auth::require();
Auth::requirePerm('booking.view');
ModuleGuard::require('booking');
BookingAPI::ensureSchema();

$pageTitle  = __('booking', 'Booking');
$currentNav = 'booking';

$tid = current_tenant_id();

$todayList = Database::rows(
    "SELECT a.*, s.name AS service_name, p.name AS provider_name
       FROM booking_appointments a
       JOIN booking_services  s ON s.id = a.service_id
       JOIN booking_providers p ON p.id = a.provider_id
      WHERE a.tenant_id = ? AND a.status = 'confirmed'
        AND DATE(a.starts_at) = CURDATE()
   ORDER BY a.starts_at",
    [$tid]
);

$weekList = Database::rows(
    "SELECT a.*, s.name AS service_name, p.name AS provider_name
       FROM booking_appointments a
       JOIN booking_services  s ON s.id = a.service_id
       JOIN booking_providers p ON p.id = a.provider_id
      WHERE a.tenant_id = ? AND a.status = 'confirmed'
        AND a.starts_at >  NOW()
        AND a.starts_at <= NOW() + INTERVAL 7 DAY
   ORDER BY a.starts_at LIMIT 50",
    [$tid]
);

// ── Headline KPIs ────────────────────────────────────────────────────
// Every query here is wrapped defensively — a dashboard must never fatal
// because of a reporting query, and these are all read-only aggregates
// on top of the same table the lists above already use successfully.
try {
    $totalCustomers = (int)(Database::value(
        "SELECT COUNT(DISTINCT customer_email) FROM booking_appointments WHERE tenant_id = ?",
        [$tid]
    ) ?: 0);

    $revenueThisMonth = (int)(Database::value(
        "SELECT COALESCE(SUM(paid_cents), 0) FROM booking_appointments
          WHERE tenant_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') AND status != 'cancelled'",
        [$tid]
    ) ?: 0);

    $revenueCurrency = (string)(Database::value(
        "SELECT s.currency FROM booking_appointments a JOIN booking_services s ON s.id = a.service_id
          WHERE a.tenant_id = ? AND a.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') AND a.status != 'cancelled'
       GROUP BY s.currency ORDER BY COUNT(*) DESC LIMIT 1",
        [$tid]
    ) ?: 'USD');

    // Past-7-days daily appointment counts, zero-filled so the sparkline's
    // shape is real (a day with zero bookings is a real dip, not a gap).
    $pastRows = Database::rows(
        "SELECT DATE(starts_at) d, COUNT(*) c FROM booking_appointments
          WHERE tenant_id = ? AND status = 'confirmed'
            AND starts_at >= CURDATE() - INTERVAL 6 DAY AND starts_at < CURDATE() + INTERVAL 1 DAY
       GROUP BY DATE(starts_at)",
        [$tid]
    );
    $pastByDay = [];
    foreach ($pastRows as $r) $pastByDay[(string)$r['d']] = (int)$r['c'];
    $todaySpark = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $todaySpark[] = $pastByDay[$d] ?? 0;
    }

    // Next-7-days daily counts, forward-looking shape for the week card.
    $nextRows = Database::rows(
        "SELECT DATE(starts_at) d, COUNT(*) c FROM booking_appointments
          WHERE tenant_id = ? AND status = 'confirmed'
            AND starts_at >= NOW() AND starts_at < CURDATE() + INTERVAL 8 DAY
       GROUP BY DATE(starts_at)",
        [$tid]
    );
    $nextByDay = [];
    foreach ($nextRows as $r) $nextByDay[(string)$r['d']] = (int)$r['c'];
    $weekSpark = [];
    for ($i = 0; $i <= 6; $i++) {
        $d = date('Y-m-d', strtotime("+$i day"));
        $weekSpark[] = $nextByDay[$d] ?? 0;
    }

    // Past-7-days daily revenue (paid_cents), zero-filled.
    $revRows = Database::rows(
        "SELECT DATE(created_at) d, COALESCE(SUM(paid_cents),0) c FROM booking_appointments
          WHERE tenant_id = ? AND status != 'cancelled'
            AND created_at >= CURDATE() - INTERVAL 6 DAY AND created_at < CURDATE() + INTERVAL 1 DAY
       GROUP BY DATE(created_at)",
        [$tid]
    );
    $revByDay = [];
    foreach ($revRows as $r) $revByDay[(string)$r['d']] = (int)$r['c'];
    $revenueSpark = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $revenueSpark[] = $revByDay[$d] ?? 0;
    }

    // Weekly distinct-active-customers over the last 6 weeks — an
    // approximate activity trend (bookings made per week), not a precise
    // new-signups cohort. One small query per week (6 total) rather than a
    // single GROUP BY, so the bucket boundaries are plain PHP date math
    // instead of relying on a specific YEARWEEK() mode matching exactly.
    $customersSpark = [];
    for ($i = 5; $i >= 0; $i--) {
        $weekStart = date('Y-m-d', strtotime(($i + 1) . " week ago"));
        $weekEnd   = date('Y-m-d', strtotime($i . " week ago"));
        $customersSpark[] = (int)(Database::value(
            "SELECT COUNT(DISTINCT customer_email) FROM booking_appointments
              WHERE tenant_id = ? AND created_at >= ? AND created_at < ?",
            [$tid, $weekStart, $weekEnd]
        ) ?: 0);
    }
} catch (\Throwable $e) {
    $totalCustomers = $totalCustomers ?? 0;
    $revenueThisMonth = $revenueThisMonth ?? 0;
    $revenueCurrency = $revenueCurrency ?? 'USD';
    $todaySpark = $todaySpark ?? [];
    $weekSpark = $weekSpark ?? [];
    $revenueSpark = $revenueSpark ?? [];
    $customersSpark = $customersSpark ?? [];
}

// Status → avatar tint, shared with Booking's own main-dashboard widget
// (Booking.php::addAdminDashboardWidget()) for a consistent colour language.
$statusColors = ['confirmed' => 'success', 'pending' => 'warning', 'completed' => 'info',
                 'cancelled' => 'danger', 'no_show' => 'muted'];

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('booking', 'Booking') ?></h1>
        <p class="page-header-sub"><?= __('booking_today_week_ahead', 'Today and the week ahead.') ?></p>
    </div>
    <div class="toolbar">
        <a href="<?= e(plugin_url('booking', 'admin/services.php'))  ?>" class="btn"><?= __('booking_services', 'Services') ?></a>
        <a href="<?= e(plugin_url('booking', 'admin/providers.php')) ?>" class="btn"><?= __('booking_providers', 'Providers') ?></a>
        <a href="<?= e(SLATE_URL) ?>/book" target="_blank" class="btn btn-primary"><?= __('booking_open_public_widget', 'Open public widget ↗') ?></a>
    </div>
</div>

<div class="dash-stats">
    <?php slate_stat_card([
        'icon'    => 'calendar',
        'number'  => count($todayList),
        'label'   => __('booking_today', 'Today'),
        'spark'   => $todaySpark,
    ]); ?>
    <?php slate_stat_card([
        'icon'    => 'clock',
        'number'  => count($weekList),
        'label'   => __('booking_next_7', 'Next 7 days'),
        'spark'   => $weekSpark,
    ]); ?>
    <?php slate_stat_card([
        'icon'    => 'credit-card',
        'number'  => slate_format_price_plain($revenueThisMonth, $revenueCurrency),
        'label'   => __('booking_revenue_month', 'Revenue this month'),
        'spark'   => $revenueSpark,
        'tone'    => 'success',
    ]); ?>
    <?php slate_stat_card([
        'icon'    => 'users',
        'number'  => $totalCustomers,
        'label'   => __('customers', 'Customers'),
        'spark'   => $customersSpark,
    ]); ?>
</div>

<?php slate_page_layout('with-aside'); ?>
    <div class="page-main">
        <div class="card">
            <div class="card-header">
                <h2><?= __('booking_today', 'Today') ?> (<?= count($todayList) ?>)</h2>
                <a href="<?= e(plugin_url('booking', 'admin/appointments.php')) ?>" class="btn btn-sm"><?= __('all', 'All') ?></a>
            </div>
            <?php if (!$todayList): ?>
                <div class="empty"><div class="empty-title"><?= __('booking_nothing_today', 'Nothing today') ?></div></div>
            <?php else: ?>
                <div class="dlist">
                    <?php foreach ($todayList as $a):
                        $name = trim((string)($a['customer_name'] ?? '')) ?: __('booking_guest', 'Guest');
                        slate_dlist_row([
                            'avatar_html'  => slate_avatar_overlay_html(mb_substr($name, 0, 2), (string)($a['customer_email'] ?? '')),
                            'avatar_color' => $statusColors[$a['status']] ?? 'muted',
                            'title'        => $name,
                            'sub'          => sprintf(__('booking_service_with_provider', '%s · with %s'), $a['service_name'], $a['provider_name']),
                            'amount'       => $a['starts_at'] ? slate_format_time($a['starts_at']) : '',
                            'time'         => $a['starts_at'] ? I18n::localDate('M j', strtotime($a['starts_at'])) : '',
                            'href'         => plugin_url('booking', 'admin/appointment.php') . '?id=' . (int)$a['id'],
                        ]);
                    endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header"><h2><?= __('booking_next_7', 'Next 7 days') ?> (<?= count($weekList) ?>)</h2></div>
            <?php if (!$weekList): ?>
                <div class="empty"><div class="empty-title"><?= __('booking_nothing_booked_yet', 'Nothing booked yet') ?></div></div>
            <?php else: ?>
                <div class="dlist">
                    <?php foreach ($weekList as $a):
                        $name = trim((string)($a['customer_name'] ?? '')) ?: __('booking_guest', 'Guest');
                        slate_dlist_row([
                            'avatar_html'  => slate_avatar_overlay_html(mb_substr($name, 0, 2), (string)($a['customer_email'] ?? '')),
                            'avatar_color' => $statusColors[$a['status']] ?? 'muted',
                            'title'        => $name,
                            'sub'          => $a['service_name'],
                            'amount'       => $a['starts_at'] ? slate_format_time($a['starts_at']) : '',
                            'time'         => $a['starts_at'] ? I18n::localDate('D j M', strtotime($a['starts_at'])) : '',
                            'href'         => plugin_url('booking', 'admin/appointment.php') . '?id=' . (int)$a['id'],
                        ]);
                    endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <aside class="page-aside">
        <div class="aside-card">
            <div class="aside-card-title"><?= __('booking_public_booking', 'Public booking') ?></div>
            <p class="text-sm text-muted" style="margin:0 0 var(--space-3);"><?= sprintf(__('booking_public_booking_desc', 'Customers book at %s. Embed it on any site with an iframe.'), '<code>/book</code>') ?></p>
            <pre class="snippet">&lt;iframe src="<?= e(SLATE_URL) ?>/book?embed=1"
  style="width:100%;border:0;min-height:640px"&gt;
&lt;/iframe&gt;</pre>
        </div>
        <div class="aside-card">
            <div class="aside-card-title"><?= __('booking_setup_checklist', 'Setup checklist') ?></div>
            <ul class="kv-list">
                <li class="kv-row"><span class="kv-label"><?= __('booking_checklist_add_services', '1. Add services') ?></span><span class="kv-value"><a href="<?= e(plugin_url('booking', 'admin/services.php')) ?>">→</a></span></li>
                <li class="kv-row"><span class="kv-label"><?= __('booking_checklist_add_providers', '2. Add providers') ?></span><span class="kv-value"><a href="<?= e(plugin_url('booking', 'admin/providers.php')) ?>">→</a></span></li>
                <li class="kv-row kv-row-stack"><span class="kv-label"><?= __('booking_checklist_set_hours', '3. Set weekly hours') ?></span><span class="kv-value"><?= __('booking_on_provider_edit', 'on provider edit') ?></span></li>
                <li class="kv-row kv-row-stack"><span class="kv-label"><?= __('booking_checklist_link_services', '4. Link services to providers') ?></span><span class="kv-value"><?= __('booking_on_provider_edit', 'on provider edit') ?></span></li>
            </ul>
        </div>
    </aside>
<?php slate_page_layout_end(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
