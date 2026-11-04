<?php
/**
 * Shop — orders: the list (by status, searchable, exportable as CSV), one order's detail, and its actions.
 *
 * Paid → Mark fulfilled (shipped, with its tracking, or collected; the customer can be emailed). A shipped order can
 * get a carrier label through EasyPost (Shop › Shipping). Awaiting payment → Cancel. Paid or fulfilled → Refund (the
 * whole charge, through the gateway that took it; stock is put back). Any order: a printable packing slip, the shop's
 * own notes, and (paid) the receipt sent again. Checkouts left unpaid for a day are cancelled when this screen opens:
 * their stock was never taken.
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
        $tracking = ['carrier' => (string) ($_POST['tracking_carrier'] ?? ''), 'number' => (string) ($_POST['tracking_number'] ?? ''), 'url' => (string) ($_POST['tracking_url'] ?? '')];
        if ($action === 'fulfil') {
            if (trim($tracking['url']) !== '' && !preg_match('#^https://#i', trim($tracking['url']))) {
                $msg = ['type' => 'error', 'msg' => __('shop_err_tracking_url', 'A tracking link starts with https://.')];
            } else {
                $msg = ShopStoreAPI::fulfil($id, $tracking, !empty($_POST['notify'])) ? ['type' => 'success', 'msg' => !empty($_POST['notify'])
                    ? __('shop_order_fulfilled_notified', 'Order marked as fulfilled; the customer was emailed.') : __('shop_order_fulfilled_msg', 'Order marked as fulfilled.')] : null;
            }
        } elseif ($action === 'tracking') {
            $res = ShopStoreAPI::saveTracking($id, $tracking);
            $msg = $res['ok'] ? ['type' => 'success', 'msg' => __('shop_saved', 'Saved.')] : ['type' => 'error', 'msg' => (string) $res['error']];
            if ($res['ok'] && !empty($_POST['notify'])) {
                ShopStoreAPI::sendFulfilledEmail($id);
                $msg['msg'] = __('shop_tracking_sent', 'Saved, and the customer was emailed.');
            }
        } elseif ($action === 'label') {
            $res = ShopShipping::buyLabel($id);
            $msg = $res['ok'] ? ['type' => 'success', 'msg' => sprintf(__('shop_label_bought', 'Label bought. Tracking: %s'), (string) $res['tracking'])] : ['type' => 'error', 'msg' => (string) $res['error']];
        } elseif ($action === 'notes') {
            ShopStoreAPI::saveAdminNotes($id, (string) ($_POST['admin_notes'] ?? ''));
            $msg = ['type' => 'success', 'msg' => __('shop_saved', 'Saved.')];
        } elseif ($action === 'resend') {
            $msg = ShopStoreAPI::resendReceipt($id) ? ['type' => 'success', 'msg' => __('shop_receipt_resent', 'The receipt was sent again.')] : null;
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

$status = (string) ($_GET['status'] ?? '');
$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';

// CSV export of the list as filtered (spreadsheet-safe: a cell starting with = + - @ is quoted as text).
if (($_GET['export'] ?? '') === 'csv') {
    $rows = ShopStoreAPI::listOrders($status, 5000, $q);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    $cell = static fn($v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    fputcsv($out, ['ref', 'date', 'status', 'name', 'email', 'phone', 'delivery', 'address', 'shipping_method', 'currency', 'subtotal', 'discount', 'coupon', 'shipping', 'tax', 'tax_included', 'total', 'tracking', 'paid_at'], ',', '"', '');
    foreach ($rows as $o) {
        fputcsv($out, array_map($cell, [
            $o['ref'], $o['created_at'], $o['status'], $o['name'], $o['email'], (string) $o['phone'], $o['delivery'],
            $o['delivery'] === 'shipping' ? ShopStoreAPI::addressText($o) : '', (string) $o['shipping_method'], $o['currency'],
            number_format((int) $o['subtotal_cents'] / 100, 2, '.', ''), number_format((int) $o['discount_cents'] / 100, 2, '.', ''), (string) $o['coupon_code'],
            number_format((int) $o['shipping_cents'] / 100, 2, '.', ''), number_format((int) $o['tax_cents'] / 100, 2, '.', ''), (int) $o['tax_inclusive'] === 1 ? 'yes' : 'no',
            number_format((int) $o['total_cents'] / 100, 2, '.', ''), trim((string) $o['tracking_carrier'] . ' ' . $o['tracking_number']), (string) $o['paid_at'],
        ]), ',', '"', '');
    }
    fclose($out);
    exit;
}

$viewId = (int) ($_GET['id'] ?? 0);
$order = $viewId > 0 ? ShopStoreAPI::order($viewId) : null;
if ($viewId > 0 && !$order) http_response_code(404);

// A packing slip: the order's lines and address on a plain page to print, without prices.
if ($order && ($_GET['slip'] ?? '') === '1') {
    $site = (string) (Database::setting('site_name') ?: 'Kohevo');
    $items = ShopStoreAPI::orderItems((int) $order['id']);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>' . e(__('shop_packing_slip', 'Packing slip') . ' ' . $order['ref']) . '</title>'
        . '<style>body{font:14px/1.45 system-ui,sans-serif;color:#111;max-width:720px;margin:24px auto;padding:0 16px}h1{font-size:20px;margin:0}table{width:100%;border-collapse:collapse;margin:18px 0}'
        . 'th,td{text-align:left;padding:8px;border-bottom:1px solid #ccc}th{font-size:12px;text-transform:uppercase;letter-spacing:.04em}.cols{display:flex;gap:32px;margin:18px 0}.cols>div{flex:1}.muted{color:#666}'
        . '@media print{button{display:none}}</style></head><body>'
        . '<p><button onclick="window.print()">' . e(__('shop_print', 'Print')) . '</button></p>'
        . '<h1>' . e($site) . '</h1><p class="muted">' . e(__('shop_packing_slip', 'Packing slip')) . ' · ' . e((string) $order['ref']) . ' · ' . e(slate_format_date((string) $order['created_at'])) . '</p>'
        . '<div class="cols"><div><strong>' . e(__('shop_ship_to', 'Ship to')) . '</strong><br>' . e((string) $order['name']) . '<br>'
        . ($order['delivery'] === 'shipping' ? nl2br(e(str_replace(', ', "\n", ShopStoreAPI::addressText($order)))) : e($order['delivery'] === 'pickup' ? __('shop_pickup', 'Pick up (free)') : __('shop_nothing_to_ship', 'Nothing to ship')))
        . ($order['phone'] ? '<br>' . e((string) $order['phone']) : '') . '</div>'
        . '<div><strong>' . e(__('shop_shipping_method', 'Shipping method')) . '</strong><br>' . e((string) ($order['shipping_method'] ?: '—'))
        . (!empty($order['tracking_number']) ? '<br>' . e(trim((string) $order['tracking_carrier'] . ' ' . $order['tracking_number'])) : '') . '</div></div>'
        . '<table><thead><tr><th>' . e(__('shop_product', 'Product')) . '</th><th>' . e(__('shop_sku', 'SKU')) . '</th><th>' . e(__('shop_quantity', 'Quantity')) . '</th><th>✓</th></tr></thead><tbody>';
    foreach ($items as $it) {
        echo '<tr><td>' . e((string) $it['title']) . (!empty($it['options_label']) ? '<br><span class="muted">' . e((string) $it['options_label']) . '</span>' : '') . '</td><td>' . e((string) ($it['sku'] ?? '')) . '</td><td>' . (int) $it['qty'] . '</td><td>☐</td></tr>';
    }
    echo '</tbody></table>' . (!empty($order['notes']) ? '<p><strong>' . e(__('shop_customer_note', 'Customer\'s note')) . ':</strong> ' . nl2br(e((string) $order['notes'])) . '</p>' : '') . '</body></html>';
    exit;
}

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
    $oid = (int) $order['id'];
    $button = static function (string $action, string $label, string $class, string $confirm = '') use ($oid): string {
        return '<form method="post" style="display:inline;margin:0;"' . ($confirm !== '' ? ' onsubmit="return confirm(\'' . e($confirm) . '\')"' : '') . '>' . csrf_field()
            . '<input type="hidden" name="_action" value="' . e($action) . '"><input type="hidden" name="id" value="' . $oid . '">'
            . '<button class="btn ' . e($class) . '">' . e($label) . '</button></form>';
    };
    $actions = '<a class="btn btn-ghost" href="?id=' . $oid . '&amp;slip=1" target="_blank" rel="noopener">' . e(__('shop_packing_slip', 'Packing slip')) . '</a> ';
    if ($canManage) {
        if (in_array($order['status'], ['paid', 'fulfilled'], true)) $actions .= $button('resend', __('shop_resend_receipt', 'Send the receipt again'), 'btn-ghost') . ' ';
        if ($order['status'] === 'pending_payment') $actions .= $button('cancel', __('shop_cancel_order', 'Cancel order'), 'btn-ghost', __('shop_cancel_confirm', 'Cancel this unpaid order?')) . ' ';
        if (in_array($order['status'], ['paid', 'fulfilled'], true)) $actions .= $button('refund', __('shop_refund', 'Refund'), 'btn-danger', __('shop_refund_confirm', 'Refund the full amount to the customer?'));
    }
    $ships = $order['delivery'] === 'shipping';
?>
<?php slate_editor_css(); ?>
<?php slate_edit_open(['title_fallback' => (string) $order['ref']]); ?>
    <?php slate_edit_backlink(['back_href' => $self, 'back_label' => __('shop_all_orders', 'All orders')]); ?>
    <?php slate_edit_hero([
        'icon'     => 'shopping-bag',
        'initials' => mb_strtoupper(mb_substr((string) $order['name'], 0, 2)),
        'title'    => (string) $order['ref'],
        'ref'      => $oid,
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
            <?= $order['delivery'] === 'pickup' ? e(ShopStoreAPI::pickupText($order)) : ($order['delivery'] === 'none' ? e(__('shop_nothing_to_ship', 'Nothing to ship')) : e(ShopStoreAPI::addressText($order))) ?></p>
        <?php if (!empty($order['shipping_method'])): ?><p><strong><?= __('shop_shipping_method', 'Shipping method') ?>:</strong> <?= e((string) $order['shipping_method']) ?></p><?php endif; ?>
        <?php if (!empty($order['notes'])): ?><p><strong><?= __('shop_customer_note', 'Customer\'s note') ?>:</strong> <?= nl2br(e((string) $order['notes'])) ?></p><?php endif; ?>
    <?php slate_edit_card_close(); ?>

    <?php slate_edit_card_open(['icon' => 'list', 'eyebrow' => __('shop_items', 'Items'), 'title' => __('shop_order_summary', 'Order summary')]); ?>
        <table class="table">
            <thead><tr><th><?= __('shop_product', 'Product') ?></th><th><?= __('shop_sku', 'SKU') ?></th><th><?= __('shop_price', 'Price') ?></th><th><?= __('shop_quantity', 'Quantity') ?></th><th><?= __('shop_total', 'Total') ?></th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr><td><?= e((string) $it['title']) ?><?php if (!empty($it['options_label'])): ?><br><span class="text-muted text-sm"><?= e((string) $it['options_label']) ?></span><?php endif; ?></td><td><?= e((string) ($it['sku'] ?? '')) ?></td><td><?= e(ShopStoreAPI::money((int) $it['unit_price_cents'], $cur)) ?></td><td><?= (int) $it['qty'] ?></td><td><?= e(ShopStoreAPI::money((int) $it['line_total_cents'], $cur)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <?php foreach (ShopStoreAPI::totalRows($order, $cur) as $r): ?>
                <tr><td colspan="4"><?= $r['key'] === 'total' ? '<strong>' . e($r['label']) . '</strong>' : e($r['label']) ?></td><td><?= $r['key'] === 'total' ? '<strong>' . e($r['text']) . '</strong>' : e($r['key'] === 'tax_included' ? $r['text'] : $r['text']) ?></td></tr>
            <?php endforeach; ?>
            </tfoot>
        </table>
    <?php slate_edit_card_close(); ?>

    <?php if ($order['delivery'] !== 'none' && in_array($order['status'], ['paid', 'fulfilled'], true)): ?>
    <?php slate_edit_card_open(['icon' => 'truck', 'eyebrow' => __('shop_fulfilment', 'Fulfilment'), 'title' => $order['status'] === 'paid' ? ($ships ? __('shop_ship_it', 'Ship it') : __('shop_hand_it_over', 'Hand it over')) : __('shop_fulfilled', 'Fulfilled')]); ?>
        <?php if (!empty($order['label_url'])): ?>
            <p><a class="btn" href="<?= e((string) $order['label_url']) ?>" target="_blank" rel="noopener"><?= __('shop_open_label', 'Open the shipping label') ?></a></p>
        <?php elseif ($ships && $canManage && ShopShipping::carrierReady()): ?>
            <p class="text-sm"><?= sprintf(__('shop_label_hint', 'Buy a label through EasyPost (%s): the carrier and service the customer chose, else the cheapest.'), ShopShipping::isTestKey() ? __('shop_easypost_test', 'test') : __('shop_easypost_live', 'live')) ?></p>
            <p><?= $button('label', __('shop_buy_label', 'Buy a shipping label'), 'btn', ShopShipping::isTestKey() ? '' : __('shop_buy_label_confirm', 'Buy this label? EasyPost charges your account.')) ?></p>
        <?php endif; ?>
        <?php if ($canManage): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="<?= $order['status'] === 'paid' ? 'fulfil' : 'tracking' ?>"><input type="hidden" name="id" value="<?= $oid ?>">
            <?php if ($ships): ?>
            <div class="field-row field-row-2">
                <div class="field"><label class="field-label" for="tracking_carrier"><?= __('shop_carrier', 'Carrier') ?></label>
                    <input type="text" id="tracking_carrier" name="tracking_carrier" maxlength="40" value="<?= e((string) ($order['tracking_carrier'] ?? $order['shipping_carrier'] ?? '')) ?>" placeholder="USPS"></div>
                <div class="field"><label class="field-label" for="tracking_number"><?= __('shop_tracking_number', 'Tracking number') ?></label>
                    <input type="text" id="tracking_number" name="tracking_number" maxlength="80" value="<?= e((string) ($order['tracking_number'] ?? '')) ?>"></div>
            </div>
            <div class="field"><label class="field-label" for="tracking_url"><?= __('shop_tracking_url', 'Tracking link (optional)') ?></label>
                <input type="url" id="tracking_url" name="tracking_url" maxlength="500" value="<?= e((string) ($order['tracking_url'] ?? '')) ?>" placeholder="https://">
                <div class="field-hint"><?= __('shop_tracking_url_hint', 'Empty: the carrier\'s own tracking page, for USPS, UPS, FedEx, DHL, Canada Post and bpost.') ?></div></div>
            <?php endif; ?>
            <div class="field"><label class="field-check"><input type="checkbox" name="notify" value="1" <?= $order['status'] === 'paid' ? 'checked' : '' ?>> <?= $ships ? __('shop_notify_shipped', 'Email the customer that it is on its way') : __('shop_notify_ready', 'Email the customer that it is ready to collect') ?></label></div>
            <button type="submit" class="btn btn-primary"><?= $order['status'] === 'paid' ? e(__('shop_mark_fulfilled', 'Mark fulfilled')) : e(__('shop_save_tracking', 'Save tracking')) ?></button>
        </form>
        <?php elseif (!empty($order['tracking_number'])): ?>
            <p><?= e(trim((string) $order['tracking_carrier'] . ' ' . $order['tracking_number'])) ?></p>
        <?php endif; ?>
    <?php slate_edit_card_close(); ?>
    <?php elseif ($order['status'] === 'paid' && $canManage): ?>
        <?php $actions = $button('fulfil', __('shop_mark_fulfilled', 'Mark fulfilled'), 'btn-primary') . ' ' . $actions; ?>
    <?php endif; ?>

    <?php slate_edit_card_open(['icon' => 'file-text', 'eyebrow' => __('shop_internal', 'Only you see this'), 'title' => __('shop_admin_notes', 'Notes')]); ?>
        <?php if ($canManage): ?>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="_action" value="notes"><input type="hidden" name="id" value="<?= $oid ?>">
            <div class="field"><textarea name="admin_notes" rows="3" maxlength="5000" aria-label="<?= e(__('shop_admin_notes', 'Notes')) ?>"><?= e((string) ($order['admin_notes'] ?? '')) ?></textarea></div>
            <button type="submit" class="btn btn-sm"><?= __('shop_save', 'Save changes') ?></button>
        </form>
        <?php else: ?>
            <p><?= nl2br(e((string) ($order['admin_notes'] ?? ''))) ?></p>
        <?php endif; ?>
    <?php slate_edit_card_close(); ?>

    <?php slate_edit_actionbar(['buttons_html' => $actions]); ?>
<?php slate_edit_close(); ?>

<?php else:
    $orders = ShopStoreAPI::listOrders($status, 200, $q);
    $qs = static fn(array $p): string => '?' . http_build_query(array_filter($p + ['status' => $status, 'q' => $q], static fn($v): bool => $v !== ''));
?>
    <div class="page-header"><div><h1><?= __('shop_orders', 'Orders') ?></h1></div>
        <div><a class="btn" href="<?= e($qs(['export' => 'csv'])) ?>"><?= __('shop_export_csv', 'Export CSV') ?></a> <?php if ($canManage): ?><a class="btn" href="<?= e(plugin_url('shop', 'admin/import-orders.php')) ?>"><?= __('shop_orders_import_title', 'Import orders') ?></a><?php endif; ?></div></div>
    <form method="get" style="display:flex;gap:.5rem;flex-wrap:wrap;margin:0 0 .75rem;max-width:32rem;">
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('shop_order_search', 'Reference, email, name or tracking')) ?>" aria-label="<?= e(__('shop_search', 'Search')) ?>" style="flex:1;min-width:12rem;">
        <button class="btn"><?= __('shop_search', 'Search') ?></button>
    </form>
    <p class="text-sm" style="display:flex;gap:.5rem;flex-wrap:wrap;margin:0 0 1rem;">
        <a class="btn btn-sm <?= $status === '' ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e($qs(['status' => ''])) ?>"><?= __('shop_all', 'All') ?></a>
        <?php foreach ($labels as $key => $label): ?>
            <a class="btn btn-sm <?= $status === $key ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e($qs(['status' => $key])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </p>
    <?php if (!$orders): ?>
        <div class="card"><div class="empty"><div class="empty-title"><?= $q !== '' ? __('shop_no_orders_match', 'No order matches') : __('shop_no_orders', 'No orders yet') ?></div>
            <p class="text-sm"><?= $q !== '' ? '' : __('shop_no_orders_sub', 'Orders appear here as soon as a customer checks out.') ?></p></div></div>
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
                'detail'       => array_filter([
                    __('shop_email', 'Email')       => (string) $o['email'],
                    __('shop_delivery', 'Delivery') => $o['delivery'] === 'pickup' ? __('shop_pickup', 'Pick up (free)') : ($o['delivery'] === 'none' ? __('shop_nothing_to_ship', 'Nothing to ship') : trim((string) ($o['city'] ?? '') . ' ' . ($o['region'] ?? ''))),
                    __('shop_shipping_method', 'Shipping method') => (string) ($o['shipping_method'] ?? ''),
                    __('shop_tracking', 'Tracking')  => trim((string) ($o['tracking_carrier'] ?? '') . ' ' . ($o['tracking_number'] ?? '')),
                    __('shop_total', 'Total')       => ShopStoreAPI::money((int) $o['total_cents'], (string) $o['currency']),
                ], static fn(string $v): bool => $v !== ''),
                'actions'      => '<a href="?id=' . (int) $o['id'] . '" class="btn btn-sm">' . __('shop_open', 'Open') . '</a>',
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
