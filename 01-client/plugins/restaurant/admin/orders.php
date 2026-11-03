<?php
/**
 * Restaurant — orders list.
 *
 * Every check, newest first, with search (order number, guest name or
 * phone), filters (date range, status, type, source), totals for whatever is
 * filtered, paging, and quick actions per order (details, receipt, open in
 * the POS). Rows use the shared collapsible data list.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';

Auth::require();
Auth::requirePerm('restaurant.view');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$tid = current_tenant_id();

$RANGES   = ['today' => __('restaurant_orders_today', 'Today'), 'yesterday' => __('restaurant_orders_yesterday', 'Yesterday'),
             '7d' => __('restaurant_orders_7d', 'Last 7 days'), '30d' => __('restaurant_orders_30d', 'Last 30 days'),
             'all' => __('restaurant_orders_all_time', 'All time'), 'custom' => __('restaurant_orders_custom', 'Custom')];
$STATUSES = ['open' => __('restaurant_pos_st_open', 'Open'), 'sent' => __('restaurant_pos_st_sent', 'Sent'),
             'paid' => __('restaurant_pos_st_paid', 'Paid'), 'closed' => __('restaurant_pos_st_closed', 'Closed'),
             'voided' => __('restaurant_pos_st_voided', 'Voided')];
$TYPES    = ['dine_in' => __('restaurant_rcpt_dine_in', 'Dine in'), 'takeout' => __('restaurant_rcpt_takeout', 'Takeout'),
             'delivery' => __('restaurant_rcpt_delivery', 'Delivery')];
$SOURCES  = ['pos' => __('restaurant_orders_src_pos', 'POS'), 'online' => __('restaurant_pos_online', 'Online')];

$q      = trim((string)($_GET['q'] ?? ''));
$range  = array_key_exists($_GET['range'] ?? '', $RANGES) ? (string)$_GET['range'] : '7d';
$status = array_key_exists($_GET['status'] ?? '', $STATUSES) ? (string)$_GET['status'] : '';
$type   = array_key_exists($_GET['type'] ?? '', $TYPES) ? (string)$_GET['type'] : '';
$source = array_key_exists($_GET['source'] ?? '', $SOURCES) ? (string)$_GET['source'] : '';
$isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
$from   = $isDate($_GET['from'] ?? null) ? (string)$_GET['from'] : '';
$to     = $isDate($_GET['to'] ?? null) ? (string)$_GET['to'] : '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 25;

// Day boundaries come from the database clock, the same clock opened_at is stamped with.
$today = substr(slate_db_now(), 0, 10);
$day   = fn(int $offset) => date('Y-m-d', strtotime($today . ' ' . ($offset >= 0 ? '+' : '') . $offset . ' days'));
[$dFrom, $dTo] = match ($range) {
    'today'     => [$today, $today],
    'yesterday' => [$day(-1), $day(-1)],
    '7d'        => [$day(-6), $today],
    '30d'       => [$day(-29), $today],
    'custom'    => [$from ?: $today, $to ?: $today],
    default     => ['', ''],
};

$where  = ['o.tenant_id = ?'];
$params = [$tid];
if ($dFrom !== '') { $where[] = 'o.opened_at >= ?'; $params[] = $dFrom . ' 00:00:00'; }
if ($dTo !== '')   { $where[] = 'o.opened_at <= ?'; $params[] = $dTo . ' 23:59:59'; }
if ($status !== '') { $where[] = 'o.status = ?'; $params[] = $status; }
if ($type !== '')   { $where[] = 'o.type = ?'; $params[] = $type; }
if ($source !== '') { $where[] = 'o.source = ?'; $params[] = $source; }
if ($q !== '') {
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $where[] = '(o.order_no LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR t.label = ?)';
    array_push($params, $like, $like, $like, $q);
}
$from_sql = "FROM restaurant_orders o
             LEFT JOIN restaurant_tables t ON t.id = o.table_id
             LEFT JOIN restaurant_customers c ON c.id = o.customer_id
            WHERE " . implode(' AND ', $where);

$sum = Database::row(
    "SELECT COUNT(*) AS n,
            COALESCE(SUM(CASE WHEN o.status <> 'voided' THEN o.total_cents ELSE 0 END),0)    AS sales,
            COALESCE(SUM(CASE WHEN o.status <> 'voided' THEN o.discount_cents ELSE 0 END),0) AS discounts,
            COALESCE(SUM(CASE WHEN o.status <> 'voided' THEN o.comp_cents ELSE 0 END),0)     AS comps,
            COALESCE(SUM(o.refunded_cents),0)                                                 AS refunds,
            COALESCE(SUM(CASE WHEN o.status <> 'voided' THEN o.tip_cents ELSE 0 END),0)      AS tips,
            COALESCE(SUM(o.paid_cents),0)                                                     AS paid,
            COALESCE(SUM(CASE WHEN o.status <> 'voided' THEN 1 ELSE 0 END),0)                AS counted
       {$from_sql}", $params);
$total = (int)$sum['n'];
$pages = max(1, (int) ceil($total / $per));
$page  = min($page, $pages);
$rows  = Database::rows(
    "SELECT o.*, t.label AS table_label, c.name AS customer_name, c.phone AS customer_phone
       {$from_sql}
   ORDER BY o.opened_at DESC, o.id DESC
      LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);

$filtered  = $q !== '' || $status !== '' || $type !== '' || $source !== '' || $range !== '7d';
$avg       = (int)$sum['counted'] > 0 ? intdiv((int)$sum['sales'], (int)$sum['counted']) : 0;
$money     = fn(int $c) => RestaurantAPI::money($c);
$canPos    = Auth::can('restaurant.pos') || Auth::isSuperAdmin();
$colors    = ['open' => 'info', 'sent' => 'warning', 'paid' => 'success', 'closed' => 'muted', 'voided' => 'danger'];
$url       = fn(string $page, int $id) => plugin_url('restaurant', 'admin/' . $page) . '?id=' . $id;

$pageTitle  = __('restaurant_orders', 'Orders');
$currentNav = 'restaurant-orders';
require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => __('restaurant_orders', 'Orders')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= e(__('restaurant_orders', 'Orders')) ?></h1>
        <p class="page-header-sub"><?= e(sprintf(__('restaurant_orders_sub', '%1$s orders · %2$s'), number_format($total),
            $range === 'all' ? $RANGES['all'] : ($dFrom === $dTo ? $dFrom : $dFrom . ' → ' . $dTo))) ?></p>
    </div>
    <?php if ($canPos): ?>
        <div class="toolbar"><a href="<?= e(plugin_url('restaurant', 'admin/pos.php')) ?>" class="btn btn-primary"><?= e(__('restaurant_pos', 'POS register')) ?> →</a></div>
    <?php endif; ?>
</div>

<div class="card">
    <form method="get" class="filter-row">
        <div class="field filter-search">
            <label class="field-label" for="q"><?= e(__('restaurant_orders_search', 'Search')) ?></label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('restaurant_orders_search_ph', 'Order no., guest, phone or table')) ?>">
        </div>
        <div class="field">
            <label class="field-label" for="range"><?= e(__('restaurant_orders_when', 'When')) ?></label>
            <select id="range" name="range" onchange="document.getElementById('rangeCustom').hidden = this.value !== 'custom'">
                <?php foreach ($RANGES as $k => $label): ?><option value="<?= e($k) ?>" <?= $range === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field" id="rangeCustom" <?= $range === 'custom' ? '' : 'hidden' ?>>
            <label class="field-label" for="from"><?= e(__('restaurant_orders_from_to', 'From – to')) ?></label>
            <div style="display:flex;gap:6px;"><input type="date" id="from" name="from" value="<?= e($from) ?>"><input type="date" name="to" value="<?= e($to) ?>" aria-label="<?= e(__('restaurant_orders_to', 'To')) ?>"></div>
        </div>
        <div class="field">
            <label class="field-label" for="status"><?= e(__('restaurant_refund_status_label', 'Status')) ?></label>
            <select id="status" name="status"><option value=""><?= e(__('restaurant_pos_all', 'All')) ?></option>
                <?php foreach ($STATUSES as $k => $label): ?><option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="field-label" for="type"><?= e(__('restaurant_rcpt_type', 'Type')) ?></label>
            <select id="type" name="type"><option value=""><?= e(__('restaurant_pos_all', 'All')) ?></option>
                <?php foreach ($TYPES as $k => $label): ?><option value="<?= e($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="field-label" for="source"><?= e(__('restaurant_orders_source', 'Source')) ?></label>
            <select id="source" name="source"><option value=""><?= e(__('restaurant_pos_all', 'All')) ?></option>
                <?php foreach ($SOURCES as $k => $label): ?><option value="<?= e($k) ?>" <?= $source === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions"><button class="btn"><?= e(__('restaurant_orders_filter', 'Filter')) ?></button>
            <?php if ($filtered): ?><a href="?" class="btn btn-ghost"><?= e(__('restaurant_orders_reset', 'Reset')) ?></a><?php endif; ?></div>
    </form>
</div>

<div class="ro-stats">
    <?php foreach ([
        [__('restaurant_orders_sales', 'Sales'), $money((int)$sum['sales']), __('restaurant_orders_sales_hint', 'Totals of non-void orders, tips included')],
        [__('restaurant_orders_count', 'Orders'), number_format((int)$sum['counted']), sprintf(__('restaurant_orders_avg', 'Average %s'), $money($avg))],
        [__('restaurant_orders_collected', 'Collected'), $money((int)$sum['paid']), __('restaurant_orders_collected_hint', 'Paid, net of refunds')],
        [__('restaurant_orders_tips', 'Tips'), $money((int)$sum['tips']), ''],
        [__('restaurant_orders_discounts', 'Discounts and comps'), $money((int)$sum['discounts'] + (int)$sum['comps']),
            (int)$sum['comps'] > 0 ? sprintf(__('restaurant_orders_comps_incl', 'incl. %s comped'), $money((int)$sum['comps'])) : ''],
        [__('restaurant_refunds', 'Refunds'), $money((int)$sum['refunds']), ''],
    ] as [$k, $v, $hint]): ?>
        <div class="ro-stat"><div class="ro-stat-k"><?= e($k) ?></div><div class="ro-stat-v"><?= e($v) ?></div><?php if ($hint !== ''): ?><div class="ro-stat-h"><?= e($hint) ?></div><?php endif; ?></div>
    <?php endforeach; ?>
</div>

<div class="card">
    <?php if (!$rows): ?>
        <div class="empty"><div class="empty-title"><?= e(__('restaurant_orders_none', 'No orders match')) ?></div>
            <p class="text-sm"><?= e(__('restaurant_orders_none_hint', 'Try a wider date range or clear the filters.')) ?></p></div>
    <?php else: ?>
        <div class="data-list" data-single-open>
        <?php foreach ($rows as $o):
            $where2 = trim((string)($o['table_label'] ?? '')) !== ''
                ? __('restaurant_rcpt_table', 'Table') . ' ' . $o['table_label'] : ($TYPES[$o['type']] ?? $o['type']);
            $meta = [date('M j, g:ia', strtotime((string)$o['opened_at']))];
            if (trim((string)($o['customer_name'] ?? '')) !== '') $meta[] = $o['customer_name'];
            if ($o['source'] === 'online') $meta[] = $SOURCES['online'];
            $detail = [
                ['label' => __('restaurant_rcpt_subtotal', 'Subtotal'), 'value' => $money((int)$o['subtotal_cents'])],
                ['label' => __('restaurant_rcpt_discount', 'Discount'), 'value' => (int)$o['discount_cents'] ? '-' . $money((int)$o['discount_cents']) . ($o['discount_reason'] ? ' · ' . $o['discount_reason'] : '') : '—', 'muted' => !(int)$o['discount_cents']],
                ['label' => __('restaurant_rcpt_tax', 'Tax'), 'value' => $money((int)$o['tax_cents'])],
                ['label' => __('restaurant_rcpt_tip', 'Tip'), 'value' => (int)$o['tip_cents'] ? $money((int)$o['tip_cents']) : '—', 'muted' => !(int)$o['tip_cents']],
                ['label' => __('restaurant_rcpt_paid', 'Paid'), 'value' => $money((int)$o['paid_cents'])],
                ['label' => __('restaurant_refunded', 'Refunded'), 'value' => (int)$o['refunded_cents'] ? $money((int)$o['refunded_cents']) : '—', 'muted' => !(int)$o['refunded_cents']],
            ];
            if ((int)$o['comp_cents']) $detail[] = ['label' => __('restaurant_pos_comped', 'Comped'), 'value' => $money((int)$o['comp_cents']) . ($o['comp_reason'] ? ' · ' . $o['comp_reason'] : '')];
            $detail[] = ['label' => __('restaurant_pos_guest_count', 'Guests'), 'value' => (string)(int)$o['guest_count']];
            $isOpenO  = in_array($o['status'], ['open', 'sent'], true);
            $actions  = '<a class="btn btn-sm" href="' . e($url('order.php', (int)$o['id'])) . '">' . e(__('restaurant_pos_details', 'Order details')) . '</a>'
                      . '<a class="btn btn-sm" href="' . e($url('receipt.php', (int)$o['id'])) . '" target="_blank" rel="noopener">' . e(__('restaurant_rcpt_print', 'Print receipt')) . '</a>'
                      . ($isOpenO && $canPos ? '<a class="btn btn-sm btn-primary" href="' . e(plugin_url('restaurant', 'admin/pos.php') . '?order=' . (int)$o['id']) . '">' . e(__('restaurant_orders_open_pos', 'Open in POS')) . '</a>' : '')
                      . ((int)$o['paid_cents'] > 0 ? '<a class="btn btn-sm" href="' . e($url('order.php', (int)$o['id'])) . '#payments">' . e(__('restaurant_refund', 'Refund')) . '</a>' : '');
            slate_data_row([
                'avatar'       => mb_strtoupper(mb_substr($TYPES[$o['type']] ?? 'O', 0, 1)),
                'avatar_color' => $colors[$o['status']] ?? 'muted',
                'title'        => '#' . $o['order_no'] . ' · ' . $where2,
                'meta'         => implode(' · ', $meta),
                'value'        => $money((int)$o['total_cents']),
                'badge'        => [$STATUSES[$o['status']] ?? $o['status'], $colors[$o['status']] ?? 'muted'],
                'detail'       => $detail,
                'actions'      => $actions,
            ]);
        endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
        <?php if ($pages > 1) slate_pagination($page, $pages, array_filter(['q' => $q, 'range' => $range, 'status' => $status, 'type' => $type,
            'source' => $source, 'from' => $from, 'to' => $to], fn($v) => $v !== '')); ?>
    <?php endif; ?>
</div>

<style>
.ro-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: var(--space-4, 16px); }
.ro-stat { padding: 14px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); }
.ro-stat-k { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
.ro-stat-v { margin-top: 4px; font-size: 22px; font-weight: 700; font-variant-numeric: tabular-nums; }
.ro-stat-h { margin-top: 2px; font-size: 12px; color: var(--muted); }
</style>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
