<?php
/** Storefront — order confirmation / status. Settles a returning Stripe session. */
if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }

$token = trim((string)($_GET['token'] ?? ''));
$cs    = trim((string)($_GET['cs'] ?? ''));
$piId  = trim((string)($_GET['payment_intent'] ?? ''));

// A returning paid payment settles here (idempotent). The webhook is the
// backstop; this synchronous path is what the customer sees immediately.
if ($cs !== '' && strpos($cs, '{') === false) {
    try { RestaurantAPI::settleOnlineFromSession($cs); } catch (\Throwable $e) { /* shown as unpaid below */ }
}
if ($piId !== '') {  // embedded Payment Element return
    try { RestaurantAPI::settleOnlineFromPaymentIntent($piId); } catch (\Throwable $e) { /* shown as unpaid below */ }
}

$order = $token !== '' ? RestaurantAPI::getOrderByToken($token) : null;

sf_header('Your order');
if (!$order) {
    echo '<h1 class="sf-h1">Order not found</h1><p class="sf-sub">We couldn’t find that order. <a href="' . e(sf_url()) . '">Start a new order →</a></p>';
    sf_footer(); return;
}

$paid      = (int)$order['paid_cents'] >= (int)$order['total_cents'] && (int)$order['total_cents'] > 0;
$cancelled = !empty($_GET['cancelled']);
$payErr    = !empty($_GET['payerr']);

if ($cancelled) {
    echo '<div class="sf-flash sf-flash-info">Payment was cancelled. Your order is saved as unpaid — you can pay at pickup, or contact us.</div>';
} elseif ($payErr) {
    echo '<div class="sf-flash sf-flash-info">We couldn’t start the online payment. Your order is saved — please pay at pickup or contact us.</div>';
} elseif ($paid) {
    echo '<div class="sf-flash sf-flash-ok">' . sf_icon('check', 15) . ' Payment received — your order is confirmed!</div>';
} else {
    echo '<div class="sf-flash sf-flash-info">Order received. Payment due at pickup.</div>';
}

$typeLabel = $order['type'] === 'delivery' ? 'Delivery' : 'Pickup';
echo '<h1 class="sf-h1">Order #' . e($order['order_no']) . '</h1>';
echo '<p class="sf-sub">' . $typeLabel . ($order['requested_time'] ? ' · ' . e($order['requested_time']) : '')
   . ' · ' . e(ucfirst((string)($order['fulfillment_status'] ?? 'new'))) . '</p>';

echo '<div class="sf-card">';
foreach ($order['items'] as $it) {
    if ($it['kitchen_status'] === 'void') continue;
    $mods = array_map(fn($m) => $m['name_snapshot'], $it['modifiers'] ?? []);
    echo '<div class="sf-item"><div><div class="nm">' . (int)$it['qty'] . '× ' . e($it['name_snapshot']) . '</div>';
    if ($mods) echo '<div class="ds">' . e(implode(', ', $mods)) . '</div>';
    if (trim((string)($it['notes'] ?? '')) !== '') echo '<div class="ds">' . sf_icon('note', 14) . ' ' . e($it['notes']) . '</div>';
    echo '</div><div class="pr">' . sf_money((int)$it['line_total_cents']) . '</div></div>';
}
echo '</div>';

echo '<div class="sf-card">';
echo '<div class="sf-totrow"><span>Subtotal</span><span>' . sf_money((int)$order['subtotal_cents']) . '</span></div>';
if ((int)$order['service_charge_cents'] > 0) echo '<div class="sf-totrow"><span>Service</span><span>' . sf_money((int)$order['service_charge_cents']) . '</span></div>';
echo '<div class="sf-totrow"><span>Tax</span><span>' . sf_money((int)$order['tax_cents']) . '</span></div>';
echo '<div class="sf-totrow grand"><span>Total</span><span>' . sf_money((int)$order['total_cents']) . '</span></div>';
echo '<div class="sf-totrow sf-muted"><span>' . ($paid ? 'Paid' : 'Amount due') . '</span><span>' . sf_money($paid ? (int)$order['paid_cents'] : (int)$order['total_cents']) . '</span></div>';
echo '</div>';

$s = RestaurantAPI::settings();
if (trim((string)$order['customer_phone']) !== '' || $s['addr_line1'] !== '') {
    echo '<p class="sf-muted" style="text-align:center">Questions? ' . e(sf_biz_name())
       . ($s['addr_line1'] ? ' · ' . e($s['addr_line1'] . ', ' . $s['addr_city'] . ', ' . $s['addr_state']) : '') . '</p>';
}
echo '<p style="text-align:center;margin-top:16px"><a class="sf-btn sf-btn-ghost" href="' . e(sf_url()) . '">Order again</a></p>';
sf_footer();
