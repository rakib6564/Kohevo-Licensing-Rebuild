<?php
/**
 * Restaurant — payments, for matching against Stripe payouts.
 *
 * Every payment with its order and check, method, amount, tip, refunds to
 * date, net, status and Stripe references, filtered by date, method,
 * status, source or a search for the order number or PaymentIntent id. The
 * totals strip covers the whole filter (settled payments only), so a day's
 * card net can be set beside the payout line. CSV export takes the same
 * filter and ends with the same totals.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';
require_once dirname(__DIR__) . '/includes/reports.php';

Auth::require();
Auth::requirePerm('restaurant.reports');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$METHODS  = ['cash' => __('restaurant_rep_m_cash', 'Cash'), 'terminal' => __('restaurant_rep_m_terminal', 'Card reader'),
             'online' => __('restaurant_rep_m_online', 'Online card'), 'other' => __('restaurant_rep_m_other', 'Other'),
             'channel' => __('restaurant_rep_m_channel', 'Paid via channel')];
$STATUSES = ['paid' => __('restaurant_pay_st_paid', 'Paid'), 'partial' => __('restaurant_pay_st_partial', 'Partly refunded'),
             'refunded' => __('restaurant_pay_st_refunded', 'Refunded'), 'pending' => __('restaurant_pay_st_pending', 'Pending'),
             'failed' => __('restaurant_pay_st_failed', 'Failed')];
$SOURCES  = ['pos' => __('restaurant_orders_src_pos', 'POS'), 'online' => __('restaurant_pos_online', 'Online'),
             'channel' => __('restaurant_rep_src_channel', 'Channels')];
$TONES    = ['paid' => 'active', 'partial' => 'warning', 'refunded' => 'inactive', 'pending' => 'installed', 'failed' => 'danger'];

$today = substr(slate_db_now(), 0, 10);
$isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
$f = [
    'from'   => $isDate($_GET['from'] ?? null) ? (string)$_GET['from'] : $today,
    'to'     => $isDate($_GET['to'] ?? null) ? (string)$_GET['to'] : ($isDate($_GET['from'] ?? null) ? (string)$_GET['from'] : $today),
    'method' => array_key_exists($_GET['method'] ?? '', $METHODS) ? (string)$_GET['method'] : '',
    'status' => array_key_exists($_GET['status'] ?? '', $STATUSES) ? (string)$_GET['status'] : '',
    'source' => array_key_exists($_GET['source'] ?? '', $SOURCES) ? (string)$_GET['source'] : '',
    'q'      => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120),
];
if ($f['to'] < $f['from']) [$f['from'], $f['to']] = [$f['to'], $f['from']];

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="restaurant-payments-' . $f['from'] . ($f['to'] !== $f['from'] ? '_' . $f['to'] : '') . '.csv"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF" . RestaurantReports::paymentsCsv($f);
    exit;
}

$per   = 50;
$page  = max(1, (int)($_GET['page'] ?? 1));
$res   = RestaurantReports::payments($f, $per, ($page - 1) * $per);
$pages = max(1, (int) ceil($res['count'] / $per));
if ($page > $pages) { $page = $pages; $res = RestaurantReports::payments($f, $per, ($page - 1) * $per); }
$money = fn($c) => RestaurantAPI::money((int)$c);
$query = array_filter($f, fn($v) => $v !== '');

$pageTitle  = __('restaurant_payments', 'Payments');
$currentNav = 'restaurant-payments';
require SLATE_ROOT . '/admin/partials/header.php';

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => __('restaurant_payments', 'Payments')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= e(__('restaurant_payments', 'Payments')) ?></h1>
        <p class="page-header-sub"><?= e(__('restaurant_pay_sub', 'Every payment taken, to check against your Stripe payouts and the cash drawer.')) ?></p>
    </div>
    <div class="toolbar"><a class="btn" href="?<?= e(http_build_query($query + ['export' => 'csv'])) ?>" download><?= e(__('restaurant_pay_csv', 'Export CSV')) ?></a></div>
</div>

<div class="card">
    <form method="get" class="filter-row">
        <div class="field filter-search">
            <label class="field-label" for="q"><?= e(__('restaurant_orders_search', 'Search')) ?></label>
            <input type="search" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="<?= e(__('restaurant_pay_search_ph', 'Order no. or Stripe pi_… / ch_… id')) ?>">
        </div>
        <div class="field">
            <label class="field-label" for="from"><?= e(__('restaurant_orders_from_to', 'From – to')) ?></label>
            <div style="display:flex;gap:6px;"><input type="date" id="from" name="from" value="<?= e($f['from']) ?>"><input type="date" name="to" value="<?= e($f['to']) ?>" aria-label="<?= e(__('restaurant_orders_to', 'To')) ?>"></div>
        </div>
        <?php foreach ([['method', __('restaurant_rep_method', 'Method'), $METHODS], ['status', __('restaurant_refund_status_label', 'Status'), $STATUSES],
                        ['source', __('restaurant_orders_source', 'Source'), $SOURCES]] as [$name, $label, $opts]): ?>
        <div class="field">
            <label class="field-label" for="<?= $name ?>"><?= e($label) ?></label>
            <select id="<?= $name ?>" name="<?= $name ?>"><option value=""><?= e(__('restaurant_pos_all', 'All')) ?></option>
                <?php foreach ($opts as $k => $l): ?><option value="<?= e($k) ?>" <?= $f[$name] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php endforeach; ?>
        <div class="filter-actions"><button class="btn"><?= e(__('restaurant_orders_filter', 'Filter')) ?></button>
            <a href="?" class="btn btn-ghost"><?= e(__('restaurant_pay_today', 'Today')) ?></a></div>
    </form>
</div>

<div class="rp-totals">
    <div><span><?= e(__('restaurant_pay_gross', 'Gross (amount + tip)')) ?></span><b><?= e($money($res['totals']['gross'])) ?></b></div>
    <div><span><?= e(__('restaurant_pay_refunded', 'Refunded')) ?></span><b><?= e($res['totals']['refunded'] ? '-' . $money($res['totals']['refunded']) : $money(0)) ?></b></div>
    <div class="is-net"><span><?= e(__('restaurant_pay_net', 'Net')) ?></span><b><?= e($money($res['totals']['net'])) ?></b></div>
    <div><span><?= e(__('restaurant_pay_count', 'Payments')) ?></span><b><?= e(sprintf(__('restaurant_pay_count_v', '%1$d settled of %2$d'), $res['totals']['settled'], $res['count'])) ?></b></div>
</div>

<div class="card">
<?php if (!$res['rows']): ?>
    <div class="empty"><div class="empty-title"><?= e(__('restaurant_pay_none', 'No payments match')) ?></div>
        <p class="text-sm"><?= e(__('restaurant_orders_none_hint', 'Try a wider date range or clear the filters.')) ?></p></div>
<?php else: ?>
    <div class="data-list rp-list" data-single-open>
    <?php foreach ($res['rows'] as $r):
        $settled = in_array($r['status'], ['paid', 'refunded'], true);
        $meta = [$METHODS[$r['method']] ?? $r['method'], date('M j, g:ia', strtotime((string)$r['created_at'])), sprintf(__('restaurant_pay_check', 'check %d'), (int)$r['check_no'])];
        if ($r['reader']) $meta[] = $r['reader'];
        if ($r['source'] === 'online' || $r['source'] === 'channel') $meta[] = $SOURCES[$r['source']];
        if ((int)$r['refunded_cents']) $meta[] = __('restaurant_pay_refunded', 'Refunded') . ' -' . $money($r['refunded_cents']);
        slate_data_row([
            'avatar'       => mb_strtoupper(mb_substr($METHODS[$r['method']] ?? $r['method'], 0, 2)),
            'avatar_color' => ['cash' => 'success', 'terminal' => 'info', 'online' => 'accent'][$r['method']] ?? 'muted',
            'title'        => '#' . $r['order_no'],
            'meta'         => implode(' · ', $meta),
            'value'        => $settled ? $money($r['net_cents']) : $money((int)$r['amount_cents'] + (int)$r['tip_cents']),
            'badge'        => $r['method'] === 'channel' && $r['state'] === 'failed'
                                ? [__('restaurant_pay_channel_cancelled', 'Cancelled by channel'), 'inactive']
                                : [$STATUSES[$r['state']] ?? $r['state'], $TONES[$r['state']] ?? 'inactive'],
            'detail'       => [
                ['label' => __('restaurant_pay_amount', 'Amount'), 'value' => $money($r['amount_cents'])],
                ['label' => __('restaurant_rcpt_tip', 'Tip'), 'value' => (int)$r['tip_cents'] ? $money($r['tip_cents']) : '—', 'muted' => !(int)$r['tip_cents']],
                ['label' => __('restaurant_pay_refunded', 'Refunded'), 'value' => (int)$r['refunded_cents'] ? '-' . $money($r['refunded_cents']) : '—', 'muted' => !(int)$r['refunded_cents']],
                ['label' => __('restaurant_pay_net', 'Net'), 'value' => $settled ? $money($r['net_cents']) : '—', 'muted' => !$settled],
                ['label' => __('restaurant_pay_pi', 'PaymentIntent'), 'html' => $r['stripe_pi_id'] ? '<code class="rp-id">' . e($r['stripe_pi_id']) . '</code>' : '—'],
                ['label' => __('restaurant_pay_central', 'Charge record'), 'value' => $r['central_charge_id'] ? sprintf(__('restaurant_pay_charge', 'charge #%d'), (int)$r['central_charge_id']) : '—', 'muted' => !$r['central_charge_id']],
            ],
            'actions'      => '<a class="btn btn-sm" href="' . e(plugin_url('restaurant', 'admin/order.php') . '?id=' . (int)$r['order_id'] . '#payments') . '">'
                            . e(__('restaurant_pos_details', 'Order details')) . '</a>',
        ]);
    endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php if ($pages > 1) slate_pagination($page, $pages, $query); ?>
<?php endif; ?>
</div>
<p class="rr-note"><?= e(__('restaurant_pay_note', 'Totals cover every payment the filter matches (not just this page) that settled: pending and failed attempts are listed but never counted. Refunded shows refunds to date on each payment, which is what Stripe takes out of a later payout.')) ?></p>

<style>
.rp-totals { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: var(--space-4, 16px); }
.rp-totals > div { padding: 12px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); display: flex; flex-direction: column; gap: 2px; }
.rp-totals span { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
.rp-totals b { font-size: 20px; font-variant-numeric: tabular-nums; }
.rp-totals .is-net { border-color: var(--accent, var(--primary)); }
.rp-id { font-size: 11.5px; word-break: break-all; }
@media (max-width: 640px) { .rp-list .data-row-value { display: inline-flex; } }
.rr-note { margin: var(--space-4, 16px) 0 0; font-size: 12.5px; color: var(--muted); max-width: 900px; }
</style>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
