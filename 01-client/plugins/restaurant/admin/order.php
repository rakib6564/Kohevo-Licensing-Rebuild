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
require_once dirname(__DIR__) . '/includes/delivery.php';
require_once dirname(__DIR__) . '/includes/receipt.php';

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
$isChannel = ($order['source'] ?? 'pos') === 'channel';
$isOnline = in_array($order['source'] ?? 'pos', ['online', 'channel'], true);   // both run through the online-orders board
$isOpen   = in_array($order['status'], ['open', 'sent'], true);
// A channel order the channel already collected payment for: nothing is due here.
$chanName = $isChannel ? (string)(Database::value("SELECT name FROM restaurant_channels WHERE tenant_id = ? AND slug = ?", [current_tenant_id(), (string)$order['channel']]) ?: $order['channel']) : '';
$chanPaid = $isChannel && (int) Database::value("SELECT COALESCE(MAX(channel_paid), 0) FROM restaurant_channel_orders WHERE tenant_id = ? AND order_id = ?", [current_tenant_id(), (int)$order['id']]) === 1;

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
<?php if ($chanPaid): ?><div class="alert alert-info" role="status"><?= e(sprintf(__('restaurant_order_paid_via', 'Paid via %s: the marketplace collected payment, so there is nothing to take here.'), $chanName)) ?></div><?php endif; ?>
<?php if ($isChannel && trim((string)($order['flag_reason'] ?? '')) !== ''): ?><div class="alert alert-warning" role="status"><?= e((string)$order['flag_reason']) ?></div><?php endif; ?>

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
                <?php if ($balance > 0 && (int)($order['refunded_cents'] ?? 0) === 0 && !$chanPaid): ?>
                    <li class="kv-row" style="font-weight:700;"><span class="kv-label" style="color:var(--danger);">Balance due</span><span class="kv-value" style="color:var(--danger);"><?= e(RestaurantAPI::money($balance)) ?></span></li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="card" id="payments">
            <div class="card-header"><h2><?= e(__('restaurant_payments', 'Payments')) ?></h2></div>
            <?php if (!$order['payments']): ?>
                <div class="empty"><div class="empty-title"><?= e(__('restaurant_pay_none', 'No payments recorded')) ?></div></div>
            <?php else: ?>
                <div class="data-list" data-single-open>
                <?php foreach ($order['payments'] as $p):
                    $pColors = ['paid' => 'success', 'pending' => 'info', 'failed' => 'danger', 'refunded' => 'muted'];
                    $pLabels = ['paid'     => __('restaurant_pay_paid', 'Paid'),
                                'pending'  => __('restaurant_pay_pending', 'Pending'),
                                'failed'   => __('restaurant_pay_failed', 'Failed'),
                                'refunded' => __('restaurant_refunded', 'Refunded')];
                    $partial  = $p['status'] === 'paid' && (int)$p['refunded_cents'] > 0;
                    $charged  = (int)$p['amount_cents'] + (int)$p['tip_cents'];
                    $left     = RestaurantAPI::refundableCents($p);
                    $payGw    = RestaurantAPI::paymentGatewayOf($p);
                    $isCard   = $payGw !== null || $p['method'] === 'terminal';
                    $payGwObj = $payGw !== null && $payGw !== 'stripe' && class_exists('Payments') ? Payments::gateway($payGw) : null;

                    $detail = [
                        ['label' => __('restaurant_refund_amount', 'Amount'), 'value' => RestaurantAPI::money((int)$p['amount_cents'])],
                        ['label' => __('restaurant_rcpt_tip', 'Tip'), 'value' => (int)$p['tip_cents'] > 0 ? RestaurantAPI::money((int)$p['tip_cents']) : '—',
                         'muted' => (int)$p['tip_cents'] === 0],
                        ['label' => __('restaurant_pay_charged', 'Charged'), 'value' => RestaurantAPI::money($charged)],
                        ['label' => __('restaurant_refunded', 'Refunded'), 'value' => (int)$p['refunded_cents'] > 0 ? RestaurantAPI::money((int)$p['refunded_cents']) : '—',
                         'muted' => (int)$p['refunded_cents'] === 0],
                    ];
                    if (in_array($p['status'], ['paid', 'refunded'], true) && $p['method'] !== 'channel') {
                        $detail[] = ['label' => __('restaurant_pay_refundable', 'Left to refund'), 'value' => RestaurantAPI::money($left)];
                    }
                    if ($payGw !== null && $payGw !== 'stripe' && !empty($p['payment_ref'])) {
                        $detail[] = ['label' => sprintf(__('restaurant_pay_gateway_ref', '%s payment'), $payGwObj ? $payGwObj->label() : ucfirst($payGw)),
                                     'html'  => '<code style="font-size:11.5px;">' . e((string)$p['payment_ref']) . '</code>'];
                    }
                    if (!empty($p['stripe_pi_id'])) {
                        $detail[] = ['label' => __('restaurant_pay_stripe', 'Stripe payment'),
                                     'html'  => '<code style="font-size:11.5px;">' . e((string)$p['stripe_pi_id']) . '</code>'];
                    }

                    $actions = '';
                    if ($canRefund && $left > 0) {
                        $max = number_format($left / 100, 2, '.', '');
                        $actions = '<form method="post" class="rrefund" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0;width:100%;"'
                                 . ' data-confirm="' . e(__('restaurant_refund_confirm', 'Refund this amount? This cannot be undone.')) . '">'
                                 . csrf_field()
                                 . '<input type="hidden" name="action" value="refund">'
                                 . '<input type="hidden" name="payment_id" value="' . (int)$p['id'] . '">'
                                 . '<input type="number" class="input" name="amount" step="0.01" min="0.01" max="' . e($max) . '" value="' . e($max) . '"'
                                 . ' style="width:110px;" aria-label="' . e(__('restaurant_refund_amount', 'Amount')) . '" required>'
                                 . '<input type="text" class="input" name="reason" maxlength="255" style="flex:1;min-width:140px;"'
                                 . ' placeholder="' . e(__('restaurant_refund_reason', 'Reason')) . '" aria-label="' . e(__('restaurant_refund_reason', 'Reason')) . '">'
                                 . '<button type="submit" class="btn btn-sm btn-danger">' . e(__('restaurant_refund_submit', 'Issue refund')) . '</button>'
                                 . '<span class="text-xs text-muted" style="flex-basis:100%;">' . e(sprintf(__('restaurant_refund_hint', 'Up to %s. %s'),
                                       RestaurantAPI::money($left), $isCard
                                           ? ($payGw !== null && $payGw !== 'stripe'
                                               ? sprintf(__('restaurant_refund_hint_gateway', 'Goes back to the customer through %s.'), $payGwObj ? $payGwObj->label() : ucfirst($payGw))
                                               : __('restaurant_refund_hint_card', 'Goes back to the card through Stripe.'))
                                           : __('restaurant_refund_hint_cash', 'Recorded only: hand the cash back from the drawer.'))) . '</span>'
                                 . '</form>';
                    } elseif ($p['method'] === 'channel') {
                        $actions = '<span class="text-sm text-muted">' . e(__('restaurant_pay_channel_refund', 'Refunds for this payment go through the ordering channel.')) . '</span>';
                    } elseif ($canRefund && in_array($p['status'], ['paid', 'refunded'], true)) {
                        $actions = '<span class="text-sm text-muted">' . e(__('restaurant_pay_no_refund', 'Nothing left to refund')) . '</span>';
                    }

                    slate_data_row([
                        'avatar_html'  => $isCard ? slate_admin_nav_icon('credit-card') : null,
                        'avatar'       => $isCard ? '' : mb_substr(restaurant_receipt_method($p), 0, 1),
                        'avatar_color' => $partial ? 'warning' : ($pColors[$p['status']] ?? 'muted'),
                        'title'        => restaurant_receipt_method($p) . ' · ' . RestaurantAPI::money($charged),
                        'meta'         => ($p['created_at'] ? date('M j, g:ia', strtotime((string)$p['created_at'])) : '')
                                          . ((int)$p['refunded_cents'] > 0
                                              ? ' · ' . sprintf(__('restaurant_pay_refunded_of', '%s refunded'), RestaurantAPI::money((int)$p['refunded_cents']))
                                              : ''),
                        'badge'        => [$partial ? __('restaurant_pay_partially_refunded', 'Partially refunded')
                                                    : ($p['method'] === 'channel' && $p['status'] === 'failed' ? __('restaurant_pay_channel_cancelled', 'Cancelled by channel')
                                                    : ($pLabels[$p['status']] ?? ucfirst((string)$p['status']))),
                                           $partial ? 'warning' : ($pColors[$p['status']] ?? 'muted')],
                        'detail'       => $detail,
                        'actions'      => $actions !== '' ? $actions : null,
                    ]);
                endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($order['refunds'])): ?>
        <div class="card">
            <div class="card-header"><h2><?= e(__('restaurant_refunds', 'Refunds')) ?></h2></div>
            <div class="data-list" data-single-open>
            <?php foreach ($order['refunds'] as $r):
                $rLabels = ['succeeded' => [__('restaurant_refund_succeeded', 'Refunded'), 'success'],
                            'failed'    => [__('restaurant_refund_failed', 'Refused'), 'danger'],
                            'pending'   => [__('restaurant_refund_unknown', 'No answer from Stripe'), 'warning']];
                $rb = $rLabels[$r['status']] ?? [(string)$r['status'], 'muted'];
                $by = !empty($r['user_id'])
                    ? (string) Database::value("SELECT name FROM users WHERE id = ? AND tenant_id = ?", [(int)$r['user_id'], current_tenant_id()])
                    : '';
                $detail = [
                    ['label' => __('restaurant_refund_amount', 'Amount'), 'value' => RestaurantAPI::money((int)$r['amount_cents'])],
                    ['label' => __('restaurant_refund_reason', 'Reason'), 'value' => trim((string)($r['reason'] ?? '')) !== '' ? (string)$r['reason'] : '—',
                     'muted' => trim((string)($r['reason'] ?? '')) === ''],
                    ['label' => __('restaurant_refund_by', 'Issued by'), 'value' => $by !== '' ? $by : '—', 'muted' => $by === ''],
                ];
                if (!empty($r['stripe_refund_id'])) {
                    $detail[] = ['label' => __('restaurant_refund_stripe_id', 'Stripe refund'),
                                 'html'  => '<code style="font-size:11.5px;">' . e((string)$r['stripe_refund_id']) . '</code>'];
                }
                if (trim((string)($r['error'] ?? '')) !== '') {
                    $detail[] = ['label' => __('restaurant_refund_error', 'Stripe said'), 'value' => (string)$r['error']];
                }
                slate_data_row([
                    'avatar'       => mb_substr(__('restaurant_rcpt_refund', 'Refund'), 0, 1),
                    'avatar_color' => $rb[1],
                    'title'        => RestaurantAPI::money((int)$r['amount_cents']),
                    'meta'         => date('M j, g:ia', strtotime((string)$r['created_at']))
                                      . (trim((string)($r['reason'] ?? '')) !== '' ? ' · ' . $r['reason'] : ''),
                    'badge'        => $rb,
                    'detail'       => $detail,
                ]);
            endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php slate_data_list_script(); ?>
    </div>

    <aside class="page-aside">
        <div class="aside-card">
            <div class="aside-card-title">Details</div>
            <ul class="kv-list">
                <li class="kv-row"><span class="kv-label">Status</span><span class="kv-value"><span class="badge badge-<?= e($statusColor) ?>"><?= e(ucfirst((string)$order['status'])) ?></span></span></li>
                <li class="kv-row"><span class="kv-label">Type</span><span class="kv-value"><?= e(ucfirst(str_replace('_', ' ', (string)$order['type']))) ?></span></li>
                <li class="kv-row"><span class="kv-label">Source</span><span class="kv-value"><?= $isChannel ? e($chanName) : ($isOnline ? 'Online' : 'POS') ?></span></li>
                <?php if ($isChannel && trim((string)($order['external_ref'] ?? '')) !== ''): ?>
                    <li class="kv-row"><span class="kv-label"><?= e(__('restaurant_ch_external', 'Channel order ID')) ?></span><span class="kv-value"><?= e((string)$order['external_ref']) ?></span></li>
                <?php endif; ?>
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
                <?php $cj = $order['type'] === 'delivery' ? RestaurantDelivery::view(RestaurantDelivery::latestJob((int)$order['id'])) : null;
                if ($cj && $cj['status'] !== 'quoted'): ?>
                    <li class="kv-row"><span class="kv-label"><?= e(__('restaurant_cr_courier', 'Courier')) ?></span><span class="kv-value"><?= e($cj['provider'] . ' · ' . $cj['label']) ?>
                        <?php if ($cj['tracking_url'] !== ''): ?> · <a href="<?= e($cj['tracking_url']) ?>" target="_blank" rel="noopener"><?= e(__('restaurant_cr_track', 'Track')) ?> ↗</a><?php endif; ?>
                        <?php if ($cj['error'] !== ''): ?><br><small class="text-muted"><?= e($cj['error']) ?></small><?php endif; ?></span></li>
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
document.querySelectorAll('form.rrefund').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
        if (!window.confirm(f.getAttribute('data-confirm'))) { ev.preventDefault(); return; }
        f.querySelector('button[type=submit]').disabled = true;
    });
});
</script>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
