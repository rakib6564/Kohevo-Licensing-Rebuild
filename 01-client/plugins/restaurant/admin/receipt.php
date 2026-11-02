<?php
/**
 * Restaurant — printable receipt for one order.
 *
 * A bare page (no admin chrome) sized for an 80 mm receipt printer that also
 * prints cleanly on A4/Letter. Opened from the POS and the order view; the
 * browser's print dialog does the rest. The markup comes from
 * includes/receipt.php so tests render exactly what staff print.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';
require_once dirname(__DIR__) . '/includes/receipt.php';

Auth::require();
Auth::requirePerm('restaurant.view');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$id    = (int)($_GET['id'] ?? 0);
$order = $id > 0 ? RestaurantAPI::getOrder($id) : null;
if (!$order) {
    http_response_code(404);
    echo e(__('restaurant_rcpt_not_found', 'Order not found.'));
    return;
}
$back = plugin_url('restaurant', 'admin/order.php') . '?id=' . (int)$order['id'];
?><!doctype html>
<html lang="<?= e(class_exists('I18n') ? I18n::currentLocale() : 'en') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(__('restaurant_rcpt_title', 'Receipt')) ?> #<?= e((string)$order['order_no']) ?></title>
<style>
    :root { --ink:#111; --muted:#555; --rule:#999; --bg:#f3f4f6; --paper:#fff; }
    * { box-sizing:border-box; }
    body { margin:0; background:var(--bg); color:var(--ink); font:13px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .rcpt-bar { display:flex; gap:8px; justify-content:center; padding:16px; }
    .rcpt-bar a, .rcpt-bar button { font:600 14px/1 system-ui, sans-serif; padding:10px 16px; border-radius:6px;
        border:1px solid #d1d5db; background:#fff; color:#111; text-decoration:none; cursor:pointer; }
    .rcpt-bar button { background:#111; color:#fff; border-color:#111; }
    .rcpt { width:80mm; max-width:calc(100% - 32px); margin:0 auto 32px; padding:16px 14px; background:var(--paper);
        box-shadow:0 1px 3px rgba(0,0,0,.12); }
    .rcpt-head, .rcpt-foot { text-align:center; }
    .rcpt-biz { font-size:16px; font-weight:700; }
    .rcpt-text { margin-top:6px; white-space:normal; }
    .rcpt-meta, .rcpt-sums, .rcpt-pays { border-top:1px dashed var(--rule); margin-top:10px; padding-top:8px; }
    .rcpt-meta div, .rcpt-sums div, .rcpt-pays div { display:flex; justify-content:space-between; gap:8px; }
    .rcpt-items { width:100%; border-collapse:collapse; border-top:1px dashed var(--rule); margin-top:10px; }
    .rcpt-items td { vertical-align:top; padding:4px 0; }
    .rcpt-items td.q { width:2.5em; }
    .rcpt-items td.a { text-align:right; white-space:nowrap; padding-left:8px; }
    .rcpt-mod { color:var(--muted); font-size:12px; }
    .rcpt-total { font-weight:700; font-size:14px; }
    .rcpt-void { text-align:center; font-size:20px; font-weight:700; letter-spacing:.2em; margin-top:10px; }
    .rcpt-foot { border-top:1px dashed var(--rule); margin-top:10px; padding-top:8px; }
    .rcpt-printed { color:var(--muted); font-size:11px; margin-top:6px; }
    @media print {
        @page { margin:4mm; }
        body { background:#fff; }
        .rcpt-bar, .mlt-switcher { display:none !important; }
        .rcpt { width:auto; max-width:80mm; margin:0; padding:0; box-shadow:none; }
    }
</style>
</head>
<body>
<div class="rcpt-bar">
    <a href="<?= e($back) ?>">← <?= e(__('restaurant_rcpt_back', 'Back to order')) ?></a>
    <button type="button" onclick="window.print()"><?= e(__('restaurant_rcpt_print', 'Print receipt')) ?></button>
</div>
<?= restaurant_receipt_html($order) ?>
<?php if (!empty($_GET['print'])): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body>
</html>
