<?php
/**
 * Shop — orders (list by status, one order's detail, and its actions).
 *
 * Paid → Mark fulfilled (shipped or collected). Awaiting payment → Cancel. Paid or fulfilled → Refund (the whole
 * Stripe charge, through stripe-payment; stock is put back). Checkouts left unpaid for a day are cancelled when this
 * screen opens: their stock was never taken.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/ShopStoreAPI.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

Auth::require();
Auth::requirePerm('shop.view');
ModuleGuard::require('shop');
ShopStoreAPI::ensureSchema();

$pageTitle  = __('shop_orders', 'Orders');
$currentNav = 'shop-orders';
$self = plugin_url('shop', 'admin/orders.php');
$canManage = Auth::can('shop.manage_orders') || Auth::isSuperAdmin();
$labels = ShopStoreAPI::statusLabels();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if (!$canManage) {
        http_response_code(403);
        $flash = ['type' => 'error', 'msg' => __('shop_err_no_permission_orders', 'You do not have permission to change orders.')];
    } elseif (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('shop_err_security', 'Security check failed. Please reload the page.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        $msg = null;
        if ($action === 'fulfil') {
            $msg = ShopStoreAPI::fulfil($id) ? ['type' => 'success', 'msg' => __('shop_order_fulfilled_msg', 'Order marked as fulfilled.')] : null;
        } elseif ($action === 'cancel') {
            $msg = ShopStoreAPI::cancel($id) ? ['type' => 'success', 'msg' => __('shop_order_cancelled_msg', 'Order cancelled.')] : null;
        } elseif ($action === 'refund') {
            $res = ShopStoreAPI::refund($id);
            $msg = $res['ok'] ? ['type' => 'success', 'msg' => __('shop_order_refunded_msg', 'Order refunded; the stock was put back.')] : ['type' => 'error', 'msg' => (string) $res['error']];
        }
        $_SESSION['shop_flash'] = $msg ?? ['type' => 'warning', 'msg' => __('shop_order_unchanged', 'The order could not be changed from its current status.')];
        header('Location: ' . $self . '?id=' . $id);
        exit;
    }
}
if (!$flash && !empty($_SESSION['shop_flash'])) { $flash = $_SESSION['shop_flash']; unset($_SESSION['shop_flash']); }

$viewId = (int) ($_GET['id'] ?? 0);
$order = $viewId > 0 ? ShopStoreAPI::order($viewId) : null;
if ($viewId > 0 && !$order) http_response_code(404);
if (!$order && $canManage) ShopStoreAPI::cancelStale();

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs(array_values(array_filter([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('shop_orders', 'Orders'), 'href' => $order ? $self : null],
    $order ? ['label' => (string) $order['ref']] : null,
]))); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($order):
    $items = ShopStoreAPI::orderItems((int) $order['id']);
    $cur = (string) $order['currency'];
    $button = static function (string $action, string $label, string $class, string $confirm = '') use ($order): string {
        return '<form method="post" style="display:inline;margin:0;"' . ($confirm !== '' ? ' onsubmit="return confirm(\'' . e($confirm) . '\')"' : '') . '>' . csrf_field()
            . '<input type="hidden" name="_action" value="' . e($action) . '"><input type="hidden" name="id" value="' . (int) $order['id'] . '">'
            . '<button class="btn ' . e($class) . '">' . e($label) . '</button></form>';
    };
    $actions = '';
    if ($canManage) {
        if ($order['status'] === 'paid') $actions .= $button('fulfil', __('shop_mark_fulfilled', 'Mark fulfilled'), 'btn-primary') . ' ';
        if ($order['status'] === 'pending_payment') $actions .= $button('cancel', __('shop_cancel_order', 'Cancel order'), 'btn-ghost', __('shop_cancel_confirm', 'Cancel this unpaid order?')) . ' ';
        if (in_array($order['status'], ['paid', 'fulfilled'], true)) $actions .= $button('refund', __('shop_refund', 'Refund'), 'btn-danger', __('shop_refund_confirm', 'Refund the full amount to the customer?'));
    }
?>
<?php slate_editor_css(); ?>
<?php slate_edit_open(['title_fallback' => (string) $order['ref']]); ?>
    <?php slate_edit_backlink(['back_href' => $self, 'back_label' => __('shop_all_orders', 'All orders')]); ?>
    <?php slate_edit_hero([
        'icon'     => 'shopping-bag',
        'initials' => mb_strtoupper(mb_substr((string) $order['name'], 0, 2)),
        'title'    => (string) $order['ref'],
        'ref'      => (int) $order['id'],
        'stats'    => [
            [__('shop_status', 'Status'), e($labels[$order['status']] ?? $order['status'])],
            [__('shop_total', 'Total'), e(ShopStoreAPI::money((int) $order['total_cents'], $cur))],
            [__('shop_placed', 'Placed'), e(slate_format_date((string) $order['created_at']))],
        ],
    ]); ?>

    <?php if (!empty($order['stock_note'])): ?><div class="alert alert-warning" role="status"><?= e((string) $order['stock_note']) ?></div><?php endif; ?>

    <?php slate_edit_card_open(['icon' => 'user', 'eyebrow' => __('shop_customer', 'Customer'), 'title' => (string) $order['name']]); ?>
        <p><a href="mailto:<?= e((string) $order['email']) ?>"><?= e((string) $order['email']) ?></a><?= $order['phone'] ? ' · ' . e((string) $order['phone']) : '' ?></p>
        <p><strong><?= __('shop_delivery', 'Delivery') ?>:</strong>
            <?= $order['delivery'] === 'pickup'
                ? e(__('shop_pickup', 'Pick up (free)'))
                : e(implode(', ', array_filter([$order['address_line1'], $order['address_line2'], trim($order['postal_code'] . ' ' . $order['city']), $order['country']]))) ?></p>
        <?php if (!empty($order['notes'])): ?><p><strong><?= __('shop_notes', 'Order notes (optional)') ?>:</strong> <?= nl2br(e((string) $order['notes'])) ?></p><?php endif; ?>
    <?php slate_edit_card_close(); ?>

    <?php slate_edit_card_open(['icon' => 'list', 'eyebrow' => __('shop_items', 'Items'), 'title' => __('shop_order_summary', 'Order summary')]); ?>
        <table class="table">
            <thead><tr><th><?= __('shop_product', 'Product') ?></th><th><?= __('shop_sku', 'SKU') ?></th><th><?= __('shop_price', 'Price') ?></th><th><?= __('shop_quantity', 'Quantity') ?></th><th><?= __('shop_total', 'Total') ?></th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr><td><?= e((string) $it['title']) ?></td><td><?= e((string) ($it['sku'] ?? '')) ?></td><td><?= e(ShopStoreAPI::money((int) $it['unit_price_cents'], $cur)) ?></td><td><?= (int) $it['qty'] ?></td><td><?= e(ShopStoreAPI::money((int) $it['line_total_cents'], $cur)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="4"><?= __('shop_subtotal', 'Subtotal') ?></td><td><?= e(ShopStoreAPI::money((int) $order['subtotal_cents'], $cur)) ?></td></tr>
                <tr><td colspan="4"><?= __('shop_shipping', 'Shipping') ?></td><td><?= e(ShopStoreAPI::money((int) $order['shipping_cents'], $cur)) ?></td></tr>
                <tr><td colspan="4"><strong><?= __('shop_total_incl_vat', 'Total (incl. VAT)') ?></strong></td><td><strong><?= e(ShopStoreAPI::money((int) $order['total_cents'], $cur)) ?></strong></td></tr>
            </tfoot>
        </table>
    <?php slate_edit_card_close(); ?>

    <?php if ($actions !== ''): slate_edit_actionbar(['buttons_html' => $actions]); endif; ?>
<?php slate_edit_close(); ?>

<?php else:
    $status = (string) ($_GET['status'] ?? '');
    $orders = ShopStoreAPI::listOrders($status);
?>
    <div class="page-header"><div><h1><?= __('shop_orders', 'Orders') ?></h1></div></div>
    <p class="text-sm" style="display:flex;gap:.5rem;flex-wrap:wrap;margin:0 0 1rem;">
        <a class="btn btn-sm <?= $status === '' ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e($self) ?>"><?= __('shop_all', 'All') ?></a>
        <?php foreach ($labels as $key => $label): ?>
            <a class="btn btn-sm <?= $status === $key ? 'btn-primary' : 'btn-ghost' ?>" href="?status=<?= e($key) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </p>
    <?php if (!$orders): ?>
        <div class="card"><div class="empty"><div class="empty-title"><?= __('shop_no_orders', 'No orders yet') ?></div>
            <p class="text-sm"><?= __('shop_no_orders_sub', 'Orders appear here as soon as a customer checks out.') ?></p></div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($orders as $o):
            $colors = ['pending_payment' => 'muted', 'paid' => 'warning', 'fulfilled' => 'success', 'cancelled' => 'muted', 'refunded' => 'danger'];
            slate_data_row([
                'avatar'       => mb_substr((string) $o['name'], 0, 1),
                'avatar_color' => $colors[$o['status']] ?? 'muted',
                'title'        => $o['ref'] . ' · ' . $o['name'],
                'meta'         => ShopStoreAPI::money((int) $o['total_cents'], (string) $o['currency']) . ' · ' . slate_format_date((string) $o['created_at']),
                'badge'        => [$labels[$o['status']] ?? $o['status'], in_array($o['status'], ['paid', 'fulfilled'], true) ? 'active' : 'inactive'],
                'detail'       => [
                    __('shop_email', 'Email')       => (string) $o['email'],
                    __('shop_delivery', 'Delivery') => $o['delivery'] === 'pickup' ? __('shop_pickup', 'Pick up (free)') : (string) ($o['city'] ?? ''),
                    __('shop_total', 'Total')       => ShopStoreAPI::money((int) $o['total_cents'], (string) $o['currency']),
                ],
                'actions'      => '<a href="?id=' . (int) $o['id'] . '" class="btn btn-sm">' . __('shop_open', 'Open') . '</a>',
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
