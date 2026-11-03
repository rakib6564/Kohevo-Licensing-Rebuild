<?php
/**
 * Restaurant — reports.
 *
 * Sales for a period (today, yesterday, this or last week, this month, or a
 * custom range), each figure beside the same figure for the period before
 * with the change. Every table has its own CSV download (one file per
 * table, ?export=<table>), built from the very cells shown on screen. All
 * figures come from RestaurantReports, which documents the rules. Channel
 * orders are split by channel, and courier fees (DoorDash Drive, Uber
 * Direct) get their own card and table, so owners can judge both.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';
require_once dirname(__DIR__) . '/includes/reports.php';
require_once dirname(__DIR__) . '/includes/delivery.php';   // courier service names

Auth::require();
Auth::requirePerm('restaurant.reports');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$PERIODS = ['today' => __('restaurant_rep_p_today', 'Today'), 'yesterday' => __('restaurant_rep_p_yesterday', 'Yesterday'),
            'this_week' => __('restaurant_rep_p_this_week', 'This week'), 'last_week' => __('restaurant_rep_p_last_week', 'Last week'),
            'this_month' => __('restaurant_rep_p_this_month', 'This month'), 'custom' => __('restaurant_rep_p_custom', 'Custom range')];
$key = array_key_exists($_GET['period'] ?? '', $PERIODS) ? (string)$_GET['period'] : 'today';
$p   = RestaurantReports::period($key, (string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? ''));
$cur  = RestaurantReports::summary($p['from'], $p['to']);
$prev = RestaurantReports::summary($p['prev_from'], $p['prev_to']);
$tables = RestaurantReports::tables($cur, $prev);

$export = (string)($_GET['export'] ?? '');
if ($export !== '' && isset($tables[$export])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="restaurant-' . $export . '-' . $p['from'] . ($p['to'] !== $p['from'] ? '_' . $p['to'] : '') . '.csv"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF" . RestaurantReports::tableCsv($tables[$export]);
    exit;
}

$money = fn($c) => RestaurantAPI::money((int)$c);
$span  = fn(string $a, string $b) => $a === $b ? date('D j M Y', strtotime($a)) : date('j M', strtotime($a)) . ' – ' . date('j M Y', strtotime($b));
$query = array_filter(['period' => $key, 'from' => $key === 'custom' ? $p['from'] : '', 'to' => $key === 'custom' ? $p['to'] : ''], fn($v) => $v !== '');
$csvUrl = fn(string $t) => '?' . http_build_query($query + ['export' => $t]);
/** Change badge; $lowerIsBetter flips the colour for refunds, comps and discounts. */
$delta = function (?float $pct, bool $lowerIsBetter = false): string {
    if ($pct === null) return '<span class="rr-chg is-flat">' . e(__('restaurant_rep_new', 'new')) . '</span>';
    if ($pct == 0.0) return '<span class="rr-chg is-flat">0%</span>';
    $good = ($pct > 0) !== $lowerIsBetter;
    return '<span class="rr-chg ' . ($good ? 'is-up' : 'is-down') . '">' . ($pct > 0 ? '▲ ' : '▼ ') . e(number_format(abs($pct), 1)) . '%</span>';
};
$cell = function ($v, string $type) use ($money): string {
    if ($v === null) return '—';
    return match ($type) { 'money' => e($money($v)), 'pct' => e(number_format((float)$v, 1)) . '%', 'int' => e(number_format((int)$v)), default => e((string)$v) };
};

$pageTitle  = __('restaurant_reports', 'Reports');
$currentNav = 'restaurant-reports';
require SLATE_ROOT . '/admin/partials/header.php';

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => __('restaurant_reports', 'Reports')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= e(__('restaurant_reports', 'Reports')) ?></h1>
        <p class="page-header-sub"><?= e(sprintf(__('restaurant_rep_sub', '%1$s, compared with %2$s'), $span($p['from'], $p['to']), $span($p['prev_from'], $p['prev_to']))) ?></p>
    </div>
    <div class="toolbar"><a class="btn" href="<?= e(plugin_url('restaurant', 'admin/payments.php') . '?' . http_build_query(['from' => $p['from'], 'to' => $p['to']])) ?>"><?= e(__('restaurant_rep_to_payments', 'Payments for this period')) ?> →</a></div>
</div>

<div class="card">
    <form method="get" class="filter-row">
        <div class="field">
            <label class="field-label" for="period"><?= e(__('restaurant_rep_period', 'Period')) ?></label>
            <select id="period" name="period" onchange="document.getElementById('rrCustom').hidden = this.value !== 'custom'; if (this.value !== 'custom') this.form.submit();">
                <?php foreach ($PERIODS as $k => $label): ?><option value="<?= e($k) ?>" <?= $key === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field" id="rrCustom" <?= $key === 'custom' ? '' : 'hidden' ?>>
            <label class="field-label" for="from"><?= e(__('restaurant_orders_from_to', 'From – to')) ?></label>
            <div style="display:flex;gap:6px;"><input type="date" id="from" name="from" value="<?= e($p['from']) ?>"><input type="date" name="to" value="<?= e($p['to']) ?>" aria-label="<?= e(__('restaurant_orders_to', 'To')) ?>"></div>
        </div>
        <div class="filter-actions"><button class="btn"><?= e(__('restaurant_rep_show', 'Show')) ?></button></div>
    </form>
</div>

<div class="rr-cards">
    <?php foreach (array_merge([
        ['net_sales', __('restaurant_rep_net', 'Net sales'), 'money', false, __('restaurant_rep_net_hint', 'Order totals less comps and refunds')],
        ['orders', __('restaurant_rep_orders', 'Orders'), 'int', false, ''],
        ['avg_order', __('restaurant_rep_aov', 'Average order'), 'money', false, ''],
        ['covers', __('restaurant_rep_covers', 'Covers'), 'int', false, __('restaurant_rep_covers_hint', 'Dine-in guests')],
        ['gross_sales', __('restaurant_rep_gross', 'Gross sales'), 'money', false, __('restaurant_rep_gross_hint', 'Items before discounts')],
        ['discounts', __('restaurant_rep_discounts', 'Discounts'), 'money', true, ''],
        ['refunds', __('restaurant_rep_refunds', 'Refunds'), 'money', true, sprintf(__('restaurant_rep_refunds_n', '%d refund(s)'), $cur['refund_count'])],
        ['tips', __('restaurant_rep_tips', 'Tips'), 'money', false, sprintf(__('restaurant_rep_tips_split', 'Cash %1$s · card %2$s'), $money($cur['tips_cash']), $money($cur['tips_card']))],
        ['tax', __('restaurant_rep_tax', 'Tax collected'), 'money', false, ''],
        ['service', __('restaurant_rep_service', 'Service charges'), 'money', false, ''],
    ], $cur['courier_jobs'] + $prev['courier_jobs'] > 0
        ? [['courier_fees', __('restaurant_rep_courier_fees', 'Courier fees'), 'money', true, sprintf(__('restaurant_rep_courier_n', '%d courier deliveries'), $cur['courier_jobs'])]]
        : []) as [$k, $label, $type, $inv, $hint]): ?>
        <div class="rr-card">
            <div class="rr-card-k"><?= e($label) ?></div>
            <div class="rr-card-v"><?= $cell($cur[$k], $type) ?></div>
            <div class="rr-card-c"><?= $delta(RestaurantReports::change((int)$cur[$k], (int)$prev[$k]), $inv) ?> <span><?= e(sprintf(__('restaurant_rep_was', 'was %s'), strip_tags($cell($prev[$k], $type)))) ?></span></div>
            <?php if ($hint !== ''): ?><div class="rr-card-h"><?= e($hint) ?></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($cur['orders'] === 0 && $cur['refund_count'] === 0 && $prev['orders'] === 0): ?>
    <div class="card"><div class="empty"><div class="empty-title"><?= e(__('restaurant_rep_empty', 'No sales in this period')) ?></div>
        <p class="text-sm"><?= e(__('restaurant_rep_empty_hint', 'Paid and closed orders show here by the day they were opened. Try a wider period.')) ?></p></div></div>
<?php endif; ?>

<div class="rr-grid">
<?php
// Each table is a list of the shared collapsible rows: the headline cell on
// the right, the other columns in the line under the title, every column in
// the expanded detail. The CSV keeps the plain table shape.
$invert   = ['discounts' => true, 'comps' => true, 'refunds' => true, 'refund_count' => true, 'voided' => true, 'courier_fees' => true];
$valueCol = ['summary' => 1, 'payments' => 4, 'items_qty' => 1, 'items_revenue' => 2, 'categories' => 2, 'sources' => 2, 'channels' => 2, 'couriers' => 3, 'fulfilment' => 1];
$ranked   = ['items_qty' => true, 'items_revenue' => true, 'categories' => true];
foreach ($tables as $tkey => $t):
    $wide = in_array($tkey, ['summary', 'payments'], true);
    $vc   = $valueCol[$tkey] ?? 1; ?>
    <section class="card rr-table<?= $wide ? ' is-wide' : '' ?>">
        <header class="rr-table-h"><h2><?= e($t['title']) ?></h2>
            <a class="btn btn-sm" href="<?= e($csvUrl($tkey)) ?>" download><?= e(__('restaurant_rep_csv', 'CSV')) ?></a></header>
        <?php if (!$t['rows']): ?>
            <p class="text-sm" style="color:var(--muted);margin:0;"><?= e(__('restaurant_rep_table_empty', 'Nothing in this period.')) ?></p>
        <?php else: ?>
        <div class="data-list" data-single-open>
        <?php foreach ($t['rows'] as $i => $row):
            $inv  = ($tkey === 'summary' && isset($invert[$t['keys'][$i]])) || $tkey === 'couriers';
            $type = fn(int $j) => $t['columns'][$j][1] === 'mixed' ? $t['types'][$i] : $t['columns'][$j][1];
            $text = fn(int $j) => strip_tags($cell($row[$j], $type($j)));
            $meta = []; $badge = ''; $detail = [];
            foreach ($row as $j => $v) {
                if ($j === 0) continue;
                $isChange = $type($j) === 'pct';
                $detail[] = $isChange ? ['label' => $t['columns'][$j][0], 'html' => $delta($v, $inv)]
                                      : ['label' => $t['columns'][$j][0], 'value' => $text($j)];
                if ($isChange) { $badge = $delta($v, $inv); continue; }
                if ($j !== $vc) $meta[] = e($t['columns'][$j][0]) . ' ' . e($text($j));
            }
            slate_data_row([
                'avatar'    => isset($ranked[$tkey]) ? (string)($i + 1) : '',
                'avatar_color' => 'muted',
                'title'     => (string)$row[0],
                'meta_html' => implode(' · ', $meta) . ($badge !== '' ? ' ' . $badge : ''),
                'value'     => $text($vc),
                'detail'    => $detail,
            ]);
        endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
</div>
<?php slate_data_list_script(); ?>

<p class="rr-note"><?= e(__('restaurant_rep_rules', 'How these are counted: an order is a sale once it is paid or closed, on the day it was opened; voided orders never count. Gross sales are items before discounts. Order totals add service charges, tax and tips. Net sales are order totals less comps and refunds; refunds count on the day they were made. Payment methods go by the day each payment was taken.')) ?>
    <?= e(__('restaurant_rep_rules_channels', 'Sales by channel split channel orders by the channel they came through; cancelled counts the channel orders voided in the period. Courier fees are what DoorDash or Uber charge for couriers booked in the period that delivered or are still under way; they are a cost and are not taken out of net sales.')) ?></p>

<style>
.rr-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: var(--space-4, 16px); }
.rr-card { padding: 14px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); min-width: 0; }
.rr-card-k { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
.rr-card-v { margin-top: 4px; font-size: 22px; font-weight: 700; font-variant-numeric: tabular-nums; }
.rr-card-c { margin-top: 4px; display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 12px; color: var(--muted); }
.rr-card-h { margin-top: 2px; font-size: 12px; color: var(--muted); }
.rr-chg { display: inline-block; padding: 1px 7px; border-radius: 999px; font-weight: 700; font-size: 11.5px; font-variant-numeric: tabular-nums; }
.rr-chg.is-up { background: var(--success-soft); color: var(--success); }
.rr-chg.is-down { background: var(--danger-soft); color: var(--danger); }
.rr-chg.is-flat { background: var(--surface-2); color: var(--muted); }
.rr-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4, 16px); }
.rr-table { margin: 0; min-width: 0; }
.rr-table.is-wide { grid-column: 1 / -1; }
.rr-table-h { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.rr-table-h h2 { margin: 0; font-size: 15px; }
.rr-table .data-row-meta .rr-chg { margin-left: 2px; }
@media (max-width: 640px) { .rr-table .data-row-value { display: inline-flex; } }  /* the figure is the point of a report row; the shared list hides it on phones */
.rr-note { margin: var(--space-4, 16px) 0 0; font-size: 12.5px; color: var(--muted); max-width: 900px; }
@media (max-width: 900px) { .rr-grid { grid-template-columns: minmax(0, 1fr); } }
@media (max-width: 560px) {
    .rr-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
    .rr-card { padding: 10px 12px; }
    .rr-card-v { font-size: 18px; }
    .rr-card-h { display: none; }
}
</style>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
