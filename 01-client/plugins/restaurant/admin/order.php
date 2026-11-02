<?php
/**
 * Restaurant — single order detail (receipt view).
 *
 * History view for one check: line items + modifiers, the money breakdown,
 * recorded payments and refunds. Linked from the "View" action on the Recent
 * orders list (index.php). Editing happens in the POS; here staff with
 * restaurant.manage_orders can refund a settled payment (in full or in part),
 * and anyone who can view the order can print its receipt. Open checks get an
 * "Open in POS" link.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';

Auth::require();
Auth::requirePerm('restaurant.view');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$id        = (int)($_GET['id'] ?? 0);
$canRefund = Auth::can('restaurant.manage_orders') || Auth::isSuperAdmin();
$flash     = null;

// Refund one payment of this order (POST → redirect, so a reload never repeats it).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refund') {
    $pid = (int)($_POST['payment_id'] ?? 0);
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('restaurant_err_csrf', 'Security check failed. Reload the page and try again.')];
    } elseif (!$canRefund) {
        $flash = ['type' => 'error', 'msg' => __('restaurant_refund_err_perm', 'You are not allowed to issue refunds.')];
    } elseif (!Database::value("SELECT 1 FROM restaurant_payments WHERE id = ? AND order_id = ? AND tenant_id = ?",
                               [$pid, $id, current_tenant_id()])) {
        $flash = ['type' => 'error', 'msg' => __('restaurant_refund_err_not_found', 'Payment not found.')];
    } else {
        $raw   = trim(str_replace(',', '.', (string)($_POST['amount'] ?? '')));
        $cents = $raw === '' ? null : (int) round((float) $raw * 100);
        $res   = RestaurantAPI::refundPayment($pid, $cents, trim((string)($_POST['reason'] ?? '')));
        if (!empty($res['ok'])) {
            header('Location: ' . plugin_url('restaurant', 'admin/order.php') . '?id=' . $id . '&refunded=1');
            exit;
        }
        $flash = ['type' => 'error', 'msg' => (string)$res['error']];
    }
}
if (!$flash && !empty($_GET['refunded'])) {
    $flash = ['type' => 'success', 'msg' => __('restaurant_refund_done', 'Refund issued.')];
}

$order = $id > 0 ? RestaurantAPI::getOrder($id) : null;

if (!$order) {
    http_response_code(404);
    $pageTitle  = 'Order not found';
    $currentNav = 'restaurant';
    require SLATE_ROOT . '/admin/partials/header.php';
    slate_breadcrumbs([
        ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
        ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
        ['label' => 'Order'],
    ]);
    echo '<div class="card"><div class="empty"><div class="empty-title">Order not found</div>'
       . '<p class="text-sm">It may have been deleted, or belongs to another account.</p>'
       . '<a class="btn" href="' . e(plugin_url('restaurant', 'admin/index.php')) . '">← Back to orders</a></div></div>';
    require SLATE_ROOT . '/admin/partials/footer.php';
    return;
}

$where = trim((string)($order['table_label'] ?? '')) !== ''
    ? 'Table ' . $order['table_label']
    : ucfirst(str_replace('_', ' ', (string)$order['type']));

$statusColors = ['open' => 'info', 'sent' => 'info', 'paid' => 'success', 'closed' => 'muted', 'voided' => 'danger'];
$statusColor  = $statusColors[$order['status']] ?? 'muted';

$total    = (int)$order['total_cents'];
$paid     = (int)$order['paid_cents'];
$balance  = $total - $paid;
$isOnline = ($order['source'] ?? 'pos') === 'online';
$isOpen   = in_array($order['status'], ['open', 'sent'], true);

$pageTitle  = 'Order #' . $order['order_no'];
$currentNav = $isOnline ? 'restaurant-online' : 'restaurant';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => '#' . $order['order_no']],
]); ?>

<div class="page-header">
    <div>
        <h1>Order #<?= e($order['order_no']) ?></h1>
        <p class="page-header-sub">
            <?= e($where) ?> · <?= e(ucfirst(str_replace('_', ' ', (string)$order['type']))) ?>
            <?= $order['opened_at'] ? ' · ' . e(date('M j, Y g:ia', strtotime((string)$order['opened_at']))) : '' ?>
        </p>
    </div>
    <div class="toolbar">
        <a href="<?= e(plugin_url('restaurant', 'admin/index.php')) ?>" class="btn">← Orders</a>
        <?php if ($isOnline): ?>
            <a href="<?= e(plugin_url('restaurant', 'admin/online.php')) ?>" class="btn">Online queue</a>
        <?php endif; ?>
        <a href="<?= e(plugin_url('restaurant', 'admin/receipt.php')) ?>?id=<?= (int)$order['id'] ?>" class="btn" target="_blank" rel="noopener"><?= e(__('restaurant_rcpt_print', 'Print receipt')) ?></a>
        <?php if ($isOpen): ?>
            <a href="<?= e(plugin_url('restaurant', 'admin/pos.php')) ?>?order=<?= (int)$order['id'] ?>" class="btn btn-primary">Open in POS →</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php slate_page_layout('with-aside'); ?>
    <div class="page-main">
        <div class="card">
            <div class="card-header"><h2>Items</h2>
                <span class="badge badge-<?= e($statusColor) ?>"><?= e(ucfirst((string)$order['status'])) ?></span>
            </div>
            <?php $visible = array_filter($order['items'], fn($it) => $it['kitchen_status'] !== 'void');
            if (!$visible): ?>
                <div class="empty"><div class="empty-title">No items on this order</div></div>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>Item</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($order['items'] as $it):
                        $void = $it['kitchen_status'] === 'void';
                        $mods = array_map(fn($m) => $m['name_snapshot']
                            . ((int)$m['price_delta_cents'] !== 0 ? ' (' . RestaurantAPI::money((int)$m['price_delta_cents']) . ')' : ''),
                            $it['modifiers'] ?? []); ?>
                        <tr<?= $void ? ' style="opacity:.5;text-decoration:line-through;"' : '' ?>>
                            <td>
                                <strong><?= e($it['name_snapshot']) ?></strong>
                                <?= $void ? ' <span class="text-xs text-danger">VOID</span>' : '' ?>
                                <?php if ($mods): ?><br><span class="text-sm text-muted"><?= e(implode(', ', $mods)) ?></span><?php endif; ?>
                                <?php if (trim((string)($it['notes'] ?? '')) !== ''): ?><br><span class="text-xs text-muted"><?= slate_admin_nav_icon('edit-3') ?> <?= e($it['notes']) ?></span><?php endif; ?>
                            </td>
                            <td style="text-align:center;"><?= (int)$it['qty'] ?></td>
                            <td style="text-align:right;white-space:nowrap;"><?= e(RestaurantAPI::money((int)$it['line_total_cents'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (trim((string)($order['notes'] ?? '')) !== ''): ?>
                <div style="padding:10px 14px;border-top:1px solid var(--border);" class="text-sm"><?= slate_admin_nav_icon('edit-3') ?> <?= e($order['notes']) ?></div>
            <?php endif; ?>

            <ul class="kv-list" style="border-top:1px solid var(--border);">
                <li class="kv-row"><span class="kv-label">Subtotal</span><span class="kv-value"><?= e(RestaurantAPI::money((int)$order['subtotal_cents'])) ?></span></li>
                <?php if ((int)$order['discount_cents'] > 0): ?>
                    <li class="kv-row"><span class="kv-label">Discount</span><span class="kv-value">−<?= e(RestaurantAPI::money((int)$order['discount_cents'])) ?></span></li>
                <?php endif; ?>
                <?php if ((int)$order['service_charge_cents'] > 0): ?>
                    <li class="kv-row"><span class="kv-label">Service charge<?= (float)$order['service_charge_pct'] > 0 ? ' (' . rtrim(rtrim(number_format((float)$order['service_charge_pct'], 2), '0'), '.') . '%)' : '' ?></span><span class="kv-value"><?= e(RestaurantAPI::money((int)$order['service_charge_cents'])) ?></span></li>
                <?php endif; ?>
                <li class="kv-row"><span class="kv-label">Tax</span><span class="kv-value"><?= e(RestaurantAPI::money((int)$order['tax_cents'])) ?></span></li>
                <?php if ((int)$order['tip_cents'] > 0): ?>
                    <li class="kv-row"><span class="kv-label">Tip</span><span class="kv-value"><?= e(RestaurantAPI::money((int)$order['tip_cents'])) ?></span></li>
                <?php endif; ?>
                <li class="kv-row" style="font-weight:700;border-top:1px solid var(--border);"><span class="kv-label" style="color:var(--text);">Total</span><span class="kv-value"><?= e(RestaurantAPI::money($total)) ?></span></li>
                <?php if ((int)($order['refunded_cents'] ?? 0) > 0): ?>
                    <li class="kv-row"><span class="kv-label"><?= e(__('restaurant_refunded', 'Refunded')) ?></span><span class="kv-value">−<?= e(RestaurantAPI::money((int)$order['refunded_cents'])) ?></span></li>
                <?php endif; ?>
                <li class="kv-row"><span class="kv-label">Paid</span><span class="kv-value"><?= e(RestaurantAPI::money($paid)) ?></span></li>
                <?php if ($balance > 0 && (int)($order['refunded_cents'] ?? 0) === 0): ?>
                    <li class="kv-row" style="font-weight:700;"><span class="kv-label" style="color:var(--danger);">Balance due</span><span class="kv-value" style="color:var(--danger);"><?= e(RestaurantAPI::money($balance)) ?></span></li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="card">
            <div class="card-header"><h2>Payments</h2></div>
            <?php if (!$order['payments']): ?>
                <div class="empty"><div class="empty-title">No payments recorded</div></div>
            <?php else: ?>
                <div class="table-wrap">
                <table class="table" style="min-width:520px;">
                    <thead><tr><th>Method</th><th>Status</th><th style="text-align:right;">Tip</th><th style="text-align:right;">Amount</th><th style="text-align:right;">When</th><?= $canRefund ? '<th></th>' : '' ?></tr></thead>
                    <tbody>
                    <?php foreach ($order['payments'] as $p):
                        $pColors = ['paid' => 'success', 'pending' => 'info', 'failed' => 'danger', 'refunded' => 'muted'];
                        $method  = ['cash' => 'Cash', 'terminal' => 'Card (terminal)', 'other' => 'Other'][$p['method']] ?? ucfirst((string)$p['method']);
                        $partial = $p['status'] === 'paid' && (int)$p['refunded_cents'] > 0;
                        $label   = $partial ? __('restaurant_pay_partially_refunded', 'Partially refunded')
                                 : ($p['status'] === 'refunded' ? __('restaurant_refunded', 'Refunded') : ucfirst((string)$p['status']));
                        $left    = RestaurantAPI::refundableCents($p); ?>
                        <tr>
                            <td><?= e($method) ?></td>
                            <td><span class="badge badge-<?= e($partial ? 'warning' : ($pColors[$p['status']] ?? 'muted')) ?>"><?= e($label) ?></span>
                                <?php if ((int)$p['refunded_cents'] > 0): ?><br><span class="text-xs text-muted"><?= e(sprintf(__('restaurant_pay_refunded_of', '%s refunded'), RestaurantAPI::money((int)$p['refunded_cents']))) ?></span><?php endif; ?></td>
                            <td style="text-align:right;"><?= (int)$p['tip_cents'] > 0 ? e(RestaurantAPI::money((int)$p['tip_cents'])) : '—' ?></td>
                            <td style="text-align:right;white-space:nowrap;"><?= e(RestaurantAPI::money((int)$p['amount_cents'] + (int)$p['tip_cents'])) ?></td>
                            <td style="text-align:right;white-space:nowrap;" class="text-sm text-muted"><?= $p['created_at'] ? e(date('M j, g:ia', strtotime((string)$p['created_at']))) : '—' ?></td>
                            <?php if ($canRefund): ?>
                                <td style="text-align:right;">
                                    <?php if ($left > 0): ?>
                                        <button type="button" class="btn btn-sm" data-refund-toggle="<?= (int)$p['id'] ?>"><?= e(__('restaurant_refund', 'Refund')) ?></button>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php if ($canRefund && $left > 0): ?>
                            <tr id="refund-<?= (int)$p['id'] ?>" hidden>
                                <td colspan="6">
                                    <form method="post" class="rrefund" data-max="<?= $left ?>"
                                          data-confirm="<?= e(__('restaurant_refund_confirm', 'Refund this amount? This cannot be undone.')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="refund">
                                        <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                                        <div class="field">
                                            <label class="field-label" for="ramt-<?= (int)$p['id'] ?>"><?= e(__('restaurant_refund_amount', 'Amount')) ?></label>
                                            <input type="number" id="ramt-<?= (int)$p['id'] ?>" name="amount" step="0.01" min="0.01"
                                                   max="<?= e(number_format($left / 100, 2, '.', '')) ?>" value="<?= e(number_format($left / 100, 2, '.', '')) ?>" required>
                                            <div class="field-hint"><?= e(sprintf(__('restaurant_refund_hint',
                                                'Up to %s. %s'), RestaurantAPI::money($left),
                                                !empty($p['stripe_pi_id'])
                                                    ? __('restaurant_refund_hint_card', 'Goes back to the card through Stripe.')
                                                    : __('restaurant_refund_hint_cash', 'Recorded only: hand the cash back from the drawer.'))) ?></div>
                                        </div>
                                        <div class="field">
                                            <label class="field-label" for="rrsn-<?= (int)$p['id'] ?>"><?= e(__('restaurant_refund_reason', 'Reason')) ?></label>
                                            <input type="text" id="rrsn-<?= (int)$p['id'] ?>" name="reason" maxlength="255">
                                        </div>
                                        <button type="submit" class="btn btn-danger btn-sm"><?= e(__('restaurant_refund_submit', 'Issue refund')) ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($order['refunds'])): ?>
        <div class="card">
            <div class="card-header"><h2><?= e(__('restaurant_refunds', 'Refunds')) ?></h2></div>
            <div class="table-wrap">
            <table class="table" style="min-width:520px;">
                <thead><tr><th><?= e(__('restaurant_refund_when', 'When')) ?></th><th><?= e(__('restaurant_refund_status', 'Status')) ?></th><th><?= e(__('restaurant_refund_reason', 'Reason')) ?></th><th style="text-align:right;"><?= e(__('restaurant_refund_amount', 'Amount')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($order['refunds'] as $r):
                    $rLabels = ['succeeded' => [__('restaurant_refund_succeeded', 'Refunded'), 'success'],
                                'failed'    => [__('restaurant_refund_failed', 'Refused'), 'danger'],
                                'pending'   => [__('restaurant_refund_unknown', 'No answer from Stripe'), 'warning']];
                    [$rl, $rc] = $rLabels[$r['status']] ?? [(string)$r['status'], 'muted']; ?>
                    <tr>
                        <td class="text-sm"><?= e(date('M j, g:ia', strtotime((string)$r['created_at']))) ?></td>
                        <td><span class="badge badge-<?= e($rc) ?>"><?= e($rl) ?></span>
                            <?php if (trim((string)($r['error'] ?? '')) !== ''): ?><br><span class="text-xs text-muted"><?= e($r['error']) ?></span><?php endif; ?></td>
                        <td class="text-sm"><?= e((string)($r['reason'] ?? '')) ?></td>
                        <td style="text-align:right;white-space:nowrap;"><?= e(RestaurantAPI::money((int)$r['amount_cents'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <aside class="page-aside">
        <div class="aside-card">
            <div class="aside-card-title">Details</div>
            <ul class="kv-list">
                <li class="kv-row"><span class="kv-label">Status</span><span class="kv-value"><span class="badge badge-<?= e($statusColor) ?>"><?= e(ucfirst((string)$order['status'])) ?></span></span></li>
                <li class="kv-row"><span class="kv-label">Type</span><span class="kv-value"><?= e(ucfirst(str_replace('_', ' ', (string)$order['type']))) ?></span></li>
                <li class="kv-row"><span class="kv-label">Source</span><span class="kv-value"><?= $isOnline ? 'Online' : 'POS' ?></span></li>
                <?php if (trim((string)($order['table_label'] ?? '')) !== ''): ?>
                    <li class="kv-row"><span class="kv-label">Table</span><span class="kv-value"><?= e($order['table_label']) ?></span></li>
                <?php endif; ?>
                <li class="kv-row"><span class="kv-label">Guests</span><span class="kv-value"><?= (int)$order['guest_count'] ?></span></li>
                <?php if (trim((string)($order['customer_name'] ?? '')) !== ''): ?>
                    <li class="kv-row"><span class="kv-label">Customer</span><span class="kv-value"><?= e($order['customer_name']) ?></span></li>
                <?php endif; ?>
                <?php if (trim((string)($order['customer_phone'] ?? '')) !== ''): ?>
                    <li class="kv-row"><span class="kv-label">Phone</span><span class="kv-value"><?= e($order['customer_phone']) ?></span></li>
                <?php endif; ?>
                <?php if ($isOnline && trim((string)($order['fulfillment_status'] ?? '')) !== ''): ?>
                    <li class="kv-row"><span class="kv-label">Fulfillment</span><span class="kv-value"><?= e(ucfirst((string)$order['fulfillment_status'])) ?></span></li>
                <?php endif; ?>
                <?php if ($isOnline && trim((string)($order['requested_time'] ?? '')) !== ''): ?>
                    <li class="kv-row"><span class="kv-label">Requested</span><span class="kv-value"><?= e($order['requested_time']) ?></span></li>
                <?php endif; ?>
                <li class="kv-row"><span class="kv-label">Opened</span><span class="kv-value"><?= $order['opened_at'] ? e(date('M j, g:ia', strtotime((string)$order['opened_at']))) : '—' ?></span></li>
                <?php if ($order['closed_at']): ?>
                    <li class="kv-row"><span class="kv-label">Closed</span><span class="kv-value"><?= e(date('M j, g:ia', strtotime((string)$order['closed_at']))) ?></span></li>
                <?php endif; ?>
            </ul>
        </div>
    </aside>
<?php slate_page_layout_end(); ?>

<?php if ($canRefund): ?>
<script>
document.querySelectorAll('[data-refund-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
        var row = document.getElementById('refund-' + b.getAttribute('data-refund-toggle'));
        if (row) row.hidden = !row.hidden;
    });
});
document.querySelectorAll('form.rrefund').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
        if (!window.confirm(f.getAttribute('data-confirm'))) { ev.preventDefault(); return; }
        f.querySelector('button[type=submit]').disabled = true;
    });
});
</script>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
