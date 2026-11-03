<?php
/**
 * Restaurant online orders on the shared payment layer, paid with PayPal (tests/fixtures/fake-paypal.php): the
 * order starts a PayPal checkout, a second press reuses it, the return settles it only for the order's own checkout
 * and token, the order is paid exactly once with the ledger row linked, a partial refund goes back through PayPal,
 * a webhook alone settles a customer who never returns, and a failure marks the payment failed. Also the receipt
 * label, the reports' online class and the MCP business report's per-gateway breakdown.
 *
 * Every restaurant row this file writes is removed; fake_paypal_stop() restores the PayPal settings.
 */

declare(strict_types=1);

use Slate\Services\Payments\PaymentEvent;

require_once SLATE_ROOT . '/plugins/restaurant/Restaurant.php';
require_once SLATE_ROOT . '/plugins/restaurant/includes/receipt.php';
require_once SLATE_ROOT . '/plugins/paypal-payment/PayPalPaymentAPI.php';
require_once SLATE_ROOT . '/plugins/mcp-gateway/ReportingMcpTools.php';
require_once SLATE_ROOT . '/tests/support/fake_paypal.php';

$RPP = fake_paypal_start();
$rppRows = ['orders' => [], 'items' => []];
$rppListener = [RestaurantAPI::class, 'handlePaymentEvent'];
// The active plugin listens itself; only an inactive one needs the test to stand in.
$rppOwnListener = !PluginLoader::isActive('restaurant');
if ($rppOwnListener) Hook::addAction('payment_event', $rppListener);
$rppCurrency = Database::setting('restaurant.currency');

/** A takeout order of two 10.00 items at 10% tax: total 22.00, with a public token like a storefront order. */
$rppOrder = function () use (&$rppRows): array {
    $id = RestaurantAPI::openOrder(['type' => 'takeout', 'source' => 'online']);
    $rppRows['orders'][] = $id;
    RestaurantAPI::addLine($id, ['item_id' => $rppRows['items'][0], 'qty' => 2]);
    $token = bin2hex(random_bytes(16));
    Database::update('restaurant_orders', ['public_token' => $token], 'id = ?', [$id]);
    return ['id' => $id, 'token' => $token];
};
$rppPay = fn (int $id): array => Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$id]);
$rppOrd = fn (int $id): array => Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$id]);

try {
    RestaurantAPI::ensureSchema();
    Database::setSetting('restaurant.currency', 'USD');
    $rppRows['items'][] = Database::insert('restaurant_items', [
        'tenant_id' => current_tenant_id(), 'name' => 'RPP Pulled pork', 'slug' => 'rpp-pork-' . bin2hex(random_bytes(3)),
        'price_cents' => 1000, 'tax_rate' => 10, 'is_active' => 1,
    ]);

    unit('restaurant + paypal: a second press reuses the checkout; the return settles only the order\'s own checkout, once', function () use ($RPP, $rppOrder, $rppPay, $rppOrd): void {
        assert_true(isset(RestaurantAPI::onlineGateways()['paypal']), 'PayPal is offered for online orders');
        $o = $rppOrder();
        $ok = 'https://example.test/order/confirm?token=' . $o['token'] . '&gw=paypal&ref={PAYMENT_REF}';
        $a = RestaurantAPI::beginOnlinePayment($o['id'], $ok, 'https://example.test/cancel', 'paypal');
        assert_true($a['ok'], 'started: ' . ($a['error'] ?? ''));
        $b = RestaurantAPI::beginOnlinePayment($o['id'], $ok, 'https://example.test/cancel', 'paypal');
        assert_true($b['ok'], 'second press: ' . ($b['error'] ?? ''));
        assert_eq([$a['payment_id'], $a['url']], [$b['payment_id'], $b['url']], 'same payment, same PayPal page');
        assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM restaurant_payments WHERE order_id = ?", [$o['id']]));
        $creates = array_filter(fake_paypal_requests($RPP), fn ($q) => $q['method'] === 'POST' && $q['path'] === '/v2/checkout/orders' && empty($q['replay']));
        assert_eq(1, count($creates), 'one PayPal order');

        $p = $rppPay($a['payment_id']);
        $ref = (string) $p['payment_ref'];
        assert_eq(['paypal', 'pending'], [$p['payment_gateway'], $p['status']]);
        assert_null($p['stripe_session_id'], 'no Stripe session for a PayPal payment');
        $s = Payments::session('paypal', $ref);
        assert_eq(['restaurant', (string) $o['id'], 2200], [$s['source_plugin'], $s['source_id'], (int) $s['amount_cents']]);

        RestaurantAPI::settleOnlineReturn('paypal', $ref, $o['token']);
        assert_eq('pending', $rppPay($a['payment_id'])['status'], 'not approved yet: still pending');

        fake_paypal_hook($RPP, '/__test/approve/' . $ref);
        assert_null(RestaurantAPI::settleOnlineReturn('paypal', $ref, str_repeat('0', 32)), 'another order\'s token does not settle it');
        assert_null(RestaurantAPI::settleOnlineReturn('paypal', 'ORDSOMEONEELSE', $o['token']), 'an unknown ref does nothing');
        assert_null(RestaurantAPI::settleOnlineReturn('stripe', $ref, $o['token']), 'nor the right ref under another gateway');
        assert_eq('pending', $rppPay($a['payment_id'])['status']);

        $order = RestaurantAPI::settleOnlineReturn('paypal', $ref, $o['token']);
        assert_eq('paid', $rppPay($a['payment_id'])['status'], 'the return captures and settles it');
        assert_eq(2200, (int) $order['paid_cents']);
        assert_eq('paid', $rppOrd($o['id'])['status']);
        $p = $rppPay($a['payment_id']);
        $charge = Payments::getCharge((int) $p['central_charge_id']);
        assert_eq(['paypal', 'restaurant', (string) $o['id'], 2200], [$charge['gateway'], $charge['source_plugin'], $charge['source_id'], (int) $charge['amount_cents']]);

        // The capture webhook arriving afterwards changes nothing.
        $e = Payments::emit(Payments::gateway('paypal')->fromWebhook(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
            'id' => (string) $charge['gateway_txn_id'], 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '22.00'],
            'supplementary_data' => ['related_ids' => ['order_id' => $ref]]]]));
        assert_eq((int) $p['central_charge_id'], $e->chargeId, 'same ledger row');
        assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'restaurant.payment_paid' AND target = ? AND tenant_id = ?",
            [(string) $a['payment_id'], current_tenant_id()]), 'settled exactly once');

        // Receipt and reports see an online payment through PayPal.
        $html = restaurant_receipt_html(RestaurantAPI::getOrder($o['id']));
        assert_true(str_contains($html, 'Online (PayPal)'), 'receipt names the gateway');
    });

    unit('restaurant + paypal: a partial refund goes back through PayPal; the rest refunds it fully', function () use ($RPP, $rppOrder, $rppPay, $rppOrd): void {
        $o = $rppOrder();
        $a = RestaurantAPI::beginOnlinePayment($o['id'], 'https://example.test/ok?gw=paypal&ref={PAYMENT_REF}', 'https://example.test/cancel', 'paypal');
        $ref = (string) $rppPay($a['payment_id'])['payment_ref'];
        fake_paypal_hook($RPP, '/__test/approve/' . $ref);
        RestaurantAPI::settleOnlineReturn('paypal', $ref, $o['token']);
        $p = $rppPay($a['payment_id']);
        assert_eq('paid', $p['status']);
        $capture = (string) Payments::getCharge((int) $p['central_charge_id'])['gateway_txn_id'];

        $r = RestaurantAPI::refundPayment($a['payment_id'], 700, 'cold fries');
        assert_true($r['ok'], 'refunded: ' . ($r['error'] ?? ''));
        assert_eq(['paid', 700], [$rppPay($a['payment_id'])['status'], (int) $rppPay($a['payment_id'])['refunded_cents']]);
        assert_eq(1500, (int) $rppOrd($o['id'])['paid_cents']);
        assert_eq(700, (int) Payments::getCharge((int) $p['central_charge_id'])['refunded_cents'], 'ledger follows');
        $refundRow = Database::row("SELECT * FROM restaurant_refunds WHERE id = ?", [$r['refund_id']]);
        assert_eq('succeeded', $refundRow['status']);
        assert_true((string) $refundRow['stripe_refund_id'] !== '', 'the PayPal refund id is kept');

        assert_true(RestaurantAPI::refundPayment($a['payment_id'], null)['ok'], 'the rest');
        assert_eq('refunded', $rppPay($a['payment_id'])['status']);
        $refunds = array_filter(fake_paypal_requests($RPP), fn ($q) => $q['path'] === '/v2/payments/captures/' . $capture . '/refund' && empty($q['replay']));
        assert_eq(2, count($refunds), 'two refunds at PayPal');
        Database::query("DELETE FROM restaurant_refunds WHERE payment_id = ?", [$a['payment_id']]);
    });

    unit('restaurant + paypal: a customer who never returns is settled by the webhook; a failure marks a pending payment failed', function () use ($RPP, $rppOrder, $rppPay): void {
        $o = $rppOrder();
        $a = RestaurantAPI::beginOnlinePayment($o['id'], 'https://example.test/ok?ref={PAYMENT_REF}', 'https://example.test/cancel', 'paypal');
        $ref = (string) $rppPay($a['payment_id'])['payment_ref'];
        fake_paypal_hook($RPP, '/__test/approve/' . $ref);
        $e = Payments::emit(Payments::gateway('paypal')->fromWebhook(['event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => ['id' => $ref, 'status' => 'APPROVED']]));
        assert_eq(PaymentEvent::SUCCEEDED, $e->type);
        assert_eq('paid', $rppPay($a['payment_id'])['status'], 'the payment_event listener settled it');

        $o2 = $rppOrder();
        $b = RestaurantAPI::beginOnlinePayment($o2['id'], 'https://example.test/ok?ref={PAYMENT_REF}', 'https://example.test/cancel', 'paypal');
        $ref2 = (string) $rppPay($b['payment_id'])['payment_ref'];
        // An event naming another order is not this payment's (the layer routes by its session; the listener checks too).
        RestaurantAPI::handlePaymentEvent(new PaymentEvent(type: PaymentEvent::FAILED, gateway: 'paypal', ref: $ref2, txnId: '',
            sourcePlugin: 'restaurant', sourceId: (string) $o['id'], amountCents: 2200, currency: 'USD', failureMessage: 'declined'));
        assert_eq('pending', $rppPay($b['payment_id'])['status'], 'an event for another order is ignored');
        Payments::emit(new PaymentEvent(type: PaymentEvent::FAILED, gateway: 'paypal', ref: $ref2, txnId: '',
            amountCents: 2200, currency: 'USD', failureMessage: 'declined'));
        assert_eq('failed', $rppPay($b['payment_id'])['status'], 'a declined capture fails the pending payment');
        assert_eq('paid', $rppPay($a['payment_id'])['status'], 'and never touches a settled one');
    });

    unit('MCP business report: payments come from the shared ledger, broken down by gateway', function (): void {
        $report = ReportingMcpTools::callTool(null, 'slate_business_report', ['period_days' => 1], ['scopes' => ['mcp-gateway.reports.read']]);
        $pay = $report['payments'];
        assert_true(is_array($pay), 'reported while a gateway is installed');
        assert_true(isset($pay['by_gateway']['paypal']), 'PayPal charges are counted');
        assert_true($pay['by_gateway']['paypal']['succeeded_count'] >= 2, 'the settled PayPal payments');
        assert_eq($pay['charge_count'], array_sum(array_column($pay['by_gateway'], 'charge_count')), 'the breakdown adds up');
    });

} finally {
    if ($rppOwnListener) Hook::removeAction('payment_event', $rppListener);
    $tid = current_tenant_id();
    foreach ($rppRows['orders'] as $id) {
        Database::query("DELETE FROM restaurant_refunds WHERE order_id = ? AND tenant_id = ?", [$id, $tid]);
        Database::query("DELETE FROM restaurant_payments WHERE order_id = ? AND tenant_id = ?", [$id, $tid]);
        Database::query("DELETE FROM restaurant_order_item_modifiers WHERE order_item_id IN (SELECT id FROM restaurant_order_items WHERE order_id = ?)", [$id]);
        Database::query("DELETE FROM restaurant_order_items WHERE order_id = ? AND tenant_id = ?", [$id, $tid]);
        Database::query("DELETE FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$id, $tid]);
    }
    foreach ($rppRows['items'] as $id) Database::query("DELETE FROM restaurant_items WHERE id = ? AND tenant_id = ?", [$id, $tid]);
    if ($rppCurrency === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = 'restaurant.currency'", [$tid]);
    else Database::setSetting('restaurant.currency', $rppCurrency);
    fake_paypal_stop($RPP);
}
