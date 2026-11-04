<?php
/**
 * Shop on the shared payment layer, paid with PayPal (tests/fixtures/fake-paypal.php): the order starts a PayPal
 * checkout, the return confirms it (and only for the order's own checkout), the order is marked paid exactly once
 * with the ledger row linked, and a refund goes back through PayPal. Also the checkout form's "Pay with" choice.
 *
 * Shop's tables are made in this database by its own ensureSchema(); every row this file writes is removed.
 */

declare(strict_types=1);

use Slate\Services\Payments\PaymentEvent;

require_once dirname(__DIR__, 2) . '/plugins/shop/ShopStoreAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/shop/public/_shop.php';
require_once dirname(__DIR__, 2) . '/plugins/paypal-payment/PayPalPaymentAPI.php';
require_once dirname(__DIR__) . '/support/fake_paypal.php';

/** A pending order of one 19.90 EUR line plus 5.00 shipping, made directly (no cart or session needed). */
function shpp_order(): array
{
    ShopStoreAPI::ensureSchema();
    $tid = current_tenant_id();
    $ref = 'KS-' . strtoupper(bin2hex(random_bytes(4)));
    $id = Database::insert('shop_orders', [
        'tenant_id' => $tid, 'ref' => $ref, 'access_key' => bin2hex(random_bytes(20)), 'email' => 'shpp@example.test',
        'name' => 'Shpp Buyer', 'delivery' => 'shipping', 'status' => 'pending_payment', 'currency' => 'EUR',
        'subtotal_cents' => 1990, 'shipping_cents' => 500, 'total_cents' => 2490,
    ]);
    Database::insert('shop_order_items', ['tenant_id' => $tid, 'order_id' => $id, 'title' => 'Club cap', 'unit_price_cents' => 1990, 'qty' => 1, 'line_total_cents' => 1990]);
    return ShopStoreAPI::order($id);
}

$SHPP = fake_paypal_start();
$shppListener = [ShopStoreAPI::class, 'handlePaymentEvent'];
Hook::addAction('payment_event', $shppListener);

try {

unit('shop + paypal: an order paid with PayPal is marked paid once, with the PayPal charge linked', function () use ($SHPP): void {
    $order = shpp_order();
    $pay = ShopStoreAPI::startPayment($order, 'paypal');
    assert_true($pay['ok'], 'payment started: ' . ($pay['error'] ?? ''));
    $order = ShopStoreAPI::order((int) $order['id']);
    assert_eq('paypal', $order['payment_gateway']);
    assert_true(str_starts_with((string) $order['payment_ref'], 'ORD'), 'the PayPal order is the payment ref');
    assert_null($order['stripe_session_id'], 'no Stripe session for a PayPal order');
    assert_true(str_contains($pay['url'], 'checkoutnow?token=' . $order['payment_ref']), 'the buyer goes to PayPal');
    $s = Payments::session('paypal', (string) $order['payment_ref']);
    assert_eq(['shop', (string) $order['id'], 2490], [$s['source_plugin'], $s['source_id'], (int) $s['amount_cents']]);
    assert_true(str_contains(Payments::returnUrl($s), '&gw=paypal&ref=' . $order['payment_ref']), 'PayPal forwards to the shop\'s return page with the ref');

    // Back before approving: nothing changes.
    ShopStoreAPI::confirmReturn($order['ref'], $order['access_key'], (string) $order['payment_ref'], 'paypal');
    assert_eq('pending_payment', ShopStoreAPI::order((int) $order['id'])['status']);

    fake_paypal_hook($SHPP, '/__test/approve/' . $order['payment_ref']);
    assert_eq('pending_payment', ShopStoreAPI::confirmReturn($order['ref'], $order['access_key'], 'ORDSOMEONEELSE', 'paypal')['status'],
        'another checkout\'s ref does not confirm this order');
    assert_eq('pending_payment', ShopStoreAPI::confirmReturn($order['ref'], $order['access_key'], (string) $order['payment_ref'], 'stripe')['status'],
        'nor the right ref under the wrong gateway');
    $back = ShopStoreAPI::confirmReturn($order['ref'], $order['access_key'], (string) $order['payment_ref'], 'paypal');
    assert_eq('paid', $back['status'], 'the return captures and marks it paid');
    $charge = Payments::getCharge((int) $back['charge_id']);
    assert_eq(['paypal', 'shop', (string) $order['id'], 2490, 'EUR'], [$charge['gateway'], $charge['source_plugin'], $charge['source_id'], (int) $charge['amount_cents'], $charge['currency']]);

    // The capture webhook arriving afterwards changes nothing.
    $capture = (string) $charge['gateway_txn_id'];
    $e = Payments::emit(Payments::gateway('paypal')->fromWebhook(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
        'id' => $capture, 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '24.90'],
        'supplementary_data' => ['related_ids' => ['order_id' => $order['payment_ref']]]]]));
    assert_eq((int) $back['charge_id'], $e->chargeId, 'same ledger row');
    assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'shop.order_paid' AND target = ? AND tenant_id = ?",
        [(string) $order['id'], current_tenant_id()]), 'paid exactly once');

    // Refund from the shop: back through PayPal, stock rules unchanged.
    $r = ShopStoreAPI::refund((int) $order['id']);
    assert_true($r['ok'], 'refunded: ' . ($r['error'] ?? ''));
    assert_eq('refunded', ShopStoreAPI::order((int) $order['id'])['status']);
    assert_eq('refunded', Payments::getCharge((int) $back['charge_id'])['status']);
    $refunds = array_filter(fake_paypal_requests($SHPP), fn ($q) => $q['path'] === '/v2/payments/captures/' . $capture . '/refund');
    assert_eq(1, count($refunds), 'one refund at PayPal');
});

unit('shop + paypal: a buyer who never returns is paid by the webhook', function () use ($SHPP): void {
    $order = shpp_order();
    ShopStoreAPI::startPayment($order, 'paypal');
    $order = ShopStoreAPI::order((int) $order['id']);
    fake_paypal_hook($SHPP, '/__test/approve/' . $order['payment_ref']);
    $e = Payments::emit(Payments::gateway('paypal')->fromWebhook(['event_type' => 'CHECKOUT.ORDER.APPROVED',
        'resource' => ['id' => $order['payment_ref'], 'status' => 'APPROVED']]));
    assert_eq(PaymentEvent::SUCCEEDED, $e->type);
    assert_eq('paid', ShopStoreAPI::order((int) $order['id'])['status'], 'the payment_event listener marked it paid');
});

unit('shop: a payment smaller than the order total does not mark it paid; the full payment does', function () use ($SHPP): void {
    $order = shpp_order();
    assert_true(ShopStoreAPI::startPayment($order, 'paypal')['ok']);
    $order = ShopStoreAPI::order((int) $order['id']);
    $short = new PaymentEvent(type: PaymentEvent::SUCCEEDED, gateway: 'paypal', ref: (string) $order['payment_ref'],
        sourcePlugin: 'shop', sourceId: (string) $order['id'], amountCents: 990, currency: 'EUR');
    ShopStoreAPI::handlePaymentEvent($short);
    ShopStoreAPI::handlePaymentEvent($short);
    $o = ShopStoreAPI::order((int) $order['id']);
    assert_eq('pending_payment', $o['status'], 'not paid');
    assert_true(str_contains((string) $o['stock_note'], 'does not cover'), 'the owner sees why: ' . $o['stock_note']);
    assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'shop.payment_mismatch' AND target = ? AND tenant_id = ?",
        [(string) $order['id'], current_tenant_id()]), 'reported once');
    ShopStoreAPI::handlePaymentEvent(new PaymentEvent(type: PaymentEvent::SUCCEEDED, gateway: 'paypal', ref: (string) $order['payment_ref'],
        sourcePlugin: 'shop', sourceId: (string) $order['id'], amountCents: 2490, currency: 'USD'));
    assert_eq('pending_payment', ShopStoreAPI::order((int) $order['id'])['status'], 'another currency is not paid either');

    ShopStoreAPI::handlePaymentEvent(new PaymentEvent(type: PaymentEvent::SUCCEEDED, gateway: 'paypal', ref: (string) $order['payment_ref'],
        sourcePlugin: 'shop', sourceId: (string) $order['id'], amountCents: 2490, currency: 'EUR'));
    assert_eq('paid', ShopStoreAPI::order((int) $order['id'])['status'], 'the full amount is');
});

unit('shop: an order that is no longer waiting for payment cannot start a new one', function (): void {
    $order = shpp_order();
    assert_true(ShopStoreAPI::markPaid((int) $order['id'], 2490, 'EUR'));
    $before = (int) Database::value('SELECT COUNT(*) FROM payment_sessions WHERE tenant_id = ? AND source_plugin = ? AND source_id = ?',
        [current_tenant_id(), 'shop', (string) $order['id']]);
    $r = ShopStoreAPI::startPayment($order, 'paypal');   // the caller's copy still says pending_payment
    assert_false($r['ok'], 'refused');
    assert_eq($before, (int) Database::value('SELECT COUNT(*) FROM payment_sessions WHERE tenant_id = ? AND source_plugin = ? AND source_id = ?',
        [current_tenant_id(), 'shop', (string) $order['id']]), 'no checkout was opened');
    assert_null(ShopStoreAPI::order((int) $order['id'])['payment_ref'], 'and the order took no reference');
});

unit('shop checkout form: a "Pay with" choice appears only when more than one gateway is offered', function (): void {
    $totals = ['lines' => [['title' => 'Cap', 'options_label' => null, 'qty' => 1, 'line_cents' => 1990, 'currency' => 'EUR']],
               'currency' => 'EUR', 'subtotal_cents' => 1990, 'delivery' => 'pickup'];
    $stripeWasOn = Payments::enabledFor('stripe', 'shop');
    try {
        // PayPal is configured (the fake); Stripe may or may not be on this install, so decide it explicitly.
        Payments::setEnabledFor('stripe', 'shop', false);
        $one = shop_view_checkout($totals);
        assert_true(!str_contains($one, 'name="payment_gateway"'), 'one gateway: no choice');
        assert_true(str_contains($one, 'through PayPal'), 'the note names the gateway');

        Payments::setEnabledFor('paypal', 'shop', false);
        assert_false(ShopStoreAPI::canTakePayments(), 'no gateway offered for the shop');
        $none = shop_view_checkout($totals);
        assert_true(str_contains($none, 'never reach this site') && !str_contains($none, 'through '), 'no gateway: the generic note');
        Payments::setEnabledFor('paypal', 'shop', true);

        $two = new class implements \Slate\Contracts\Payments\PaymentGateway {
            public function id(): string { return 'shpptwo'; }
            public function label(): string { return 'Second way'; }
            public function isConfigured(): bool { return true; }
            public function supports(string $c): bool { return $c === self::HOSTED; }
            public function mode(): string { return 'test'; }
            public function settingsUrl(): string { return '/'; }
            public function startCheckout(\Slate\Services\Payments\CheckoutRequest $r): \Slate\Services\Payments\CheckoutStart { throw new RuntimeException('unused'); }
            public function confirm(string $ref): ?PaymentEvent { return null; }
            public function refund(array $c, ?int $a, string $k = ''): array { return ['ok' => false, 'error' => 'unused']; }
        };
        Payments::register($two);
        try {
            $html = shop_view_checkout($totals, [], ['payment_gateway' => 'shpptwo']);
            assert_true(str_contains($html, 'value="paypal"') && str_contains($html, 'value="shpptwo" checked'), 'both offered, the posted choice kept');
        } finally {
            Payments::unregister('shpptwo');
        }
    } finally {
        Payments::setEnabledFor('stripe', 'shop', $stripeWasOn);
        Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key LIKE 'payments.off.%.shop'", [current_tenant_id()]);
        if (!$stripeWasOn) Payments::setEnabledFor('stripe', 'shop', false);
    }
});

} finally {
    Hook::removeAction('payment_event', $shppListener);
    $tid = current_tenant_id();
    Database::query("DELETE FROM shop_order_items WHERE tenant_id = ? AND order_id IN (SELECT id FROM shop_orders WHERE tenant_id = ? AND email = 'shpp@example.test')", [$tid, $tid]);
    Database::query("DELETE FROM shop_orders WHERE tenant_id = ? AND email = 'shpp@example.test'", [$tid]);
    fake_paypal_stop($SHPP);
}
