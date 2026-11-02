<?php
/**
 * Restaurant — single order detail (receipt view).
 *
 * Read-only history view for one check: line items + modifiers, the money
 * breakdown, and recorded payments. Linked from the "View" action on the
 * Recent orders list (index.php). Editing happens in the POS; this page just
 * shows what the order ended up being. Open checks get an "Open in POS" link.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';

Auth::require();
Auth::requirePerm('restaurant.view');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$id    = (int)($_GET['id'] ?? 0);
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
        <?php if ($isOpen): ?>
            <a href="<?= e(plugin_url('restaurant', 'admin/pos.php')) ?>?order=<?= (int)$order['id'] ?>" class="btn btn-primary">Open in POS →</a>
        <?php endif; ?>
    </div>
</div>

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
                <li class="kv-row"><span class="kv-label">Paid</span><span class="kv-value"><?= e(RestaurantAPI::money($paid)) ?></span></li>
                <?php if ($balance > 0): ?>
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
                    <thead><tr><th>Method</th><th>Status</th><th style="text-align:right;">Tip</th><th style="text-align:right;">Amount</th><th style="text-align:right;">When</th></tr></thead>
                    <tbody>
                    <?php foreach ($order['payments'] as $p):
                        $pColors = ['paid' => 'success', 'pending' => 'info', 'failed' => 'danger', 'refunded' => 'muted'];
                        $method  = ['cash' => 'Cash', 'terminal' => 'Card (terminal)', 'other' => 'Other'][$p['method']] ?? ucfirst((string)$p['method']); ?>
                        <tr>
                            <td><?= e($method) ?></td>
                            <td><span class="badge badge-<?= e($pColors[$p['status']] ?? 'muted') ?>"><?= e(ucfirst((string)$p['status'])) ?></span></td>
                            <td style="text-align:right;"><?= (int)$p['tip_cents'] > 0 ? e(RestaurantAPI::money((int)$p['tip_cents'])) : '—' ?></td>
                            <td style="text-align:right;white-space:nowrap;"><?= e(RestaurantAPI::money((int)$p['amount_cents'] + (int)$p['tip_cents'])) ?></td>
                            <td style="text-align:right;white-space:nowrap;" class="text-sm text-muted"><?= $p['created_at'] ? e(date('M j, g:ia', strtotime((string)$p['created_at']))) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
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

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
