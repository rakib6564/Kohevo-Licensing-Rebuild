<?php
/**
 * Restaurant — the money-critical paths (Phase 1): refunds, idempotent payment
 * creation, Terminal settlement (capture, poll, webhook, decline, cancel) and
 * the receipt.
 *
 * Runs the real RestaurantAPI and stripe-payment code against
 * tests/fixtures/fake-stripe.php, served on a loopback port for the length of
 * this file (StripePaymentAPI::apiBase() honours STRIPE_API_BASE for loopback
 * only). The fake records every request with its Idempotency-Key and replays a
 * repeated key the way Stripe does, so "one PaymentIntent" and "one refund"
 * are asserted on what actually reached the API.
 *
 * Settings it changes (Stripe test keys, receipt text) are restored at the
 * end; every restaurant row it creates is deleted.
 */

declare(strict_types=1);

require_once SLATE_ROOT . '/plugins/stripe-payment/StripePaymentAPI.php';
require_once SLATE_ROOT . '/plugins/stripe-payment/StripeTerminalAPI.php';
require_once SLATE_ROOT . '/plugins/restaurant/Restaurant.php';
require_once SLATE_ROOT . '/plugins/restaurant/includes/receipt.php';

$rmt = (function (): array {
    $state = sys_get_temp_dir() . '/slate-fake-stripe-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($state, 0700, true);
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $proc = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, SLATE_ROOT . '/tests/fixtures/fake-stripe.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $state . '/server.out', 'a'], 2 => ['file', $state . '/server.out', 'a']],
        $pipes, null, ['FAKE_STRIPE_STATE' => $state, 'PATH' => (string) getenv('PATH')]
    );
    for ($i = 0; $i < 50; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.1);
        if ($c) { fclose($c); break; }
        usleep(100000);
    }
    return ['state' => $state, 'port' => $port, 'proc' => $proc, 'orders' => [], 'items' => [], 'readers' => [],
            'settings' => []];
})();

$rmtSet = function (string $key, ?string $value) use (&$rmt): void {
    if (!array_key_exists($key, $rmt['settings'])) {
        $rmt['settings'][$key] = Database::setting($key);
    }
    if ($value === null) {
        Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
    } else {
        Database::setSetting($key, $value);
    }
};

/** Requests the fake received, optionally only one method+path, split into first-time and replayed. */
$rmtRequests = function (string $method = '', string $pathRe = '') use (&$rmt): array {
    $out = [];
    $f = $rmt['state'] . '/requests.log';
    if (!is_file($f)) return $out;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if ($method !== '' && $r['method'] !== $method) continue;
        if ($pathRe !== '' && !preg_match($pathRe, $r['path'])) continue;
        $out[] = $r;
    }
    return $out;
};
$rmtFresh = fn(array $reqs): array => array_values(array_filter($reqs, fn($r) => !$r['replay']));
$rmtFake  = function () use (&$rmt): array {
    return json_decode((string) file_get_contents($rmt['state'] . '/objects.json'), true);
};

/** A takeout check with two of the test item: subtotal 20.00, tax 2.00, total 22.00. */
$rmtOrder = function () use (&$rmt): int {
    $id = RestaurantAPI::openOrder(['type' => 'takeout']);
    $rmt['orders'][] = $id;
    RestaurantAPI::addLine($id, ['item_id' => $rmt['items'][0], 'qty' => 2]);
    return $id;
};
$rmtPay = fn(int $id): array => Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$id]);
$rmtOrd = fn(int $id): array => Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$id]);

/** Start a Terminal charge and have the fake reader approve (or decline) the card. */
$rmtTerminal = function (int $orderId, int $amount, int $tip, bool $decline = false) use (&$rmt): array {
    $res = RestaurantAPI::beginTerminalPayment($orderId, $rmt['readers'][0], $amount, $tip);
    if (empty($res['ok'])) throw new \Exception('beginTerminalPayment failed: ' . ($res['error'] ?? '?'));
    StripePaymentAPI::httpRequest('POST', '/v1/test_helpers/terminal/readers/tmr_fake_reader/present_payment_method',
        $decline ? ['card_present' => ['number' => '4000000000000002']] : []);
    return $res;
};

try {
    // ── Fixture ───────────────────────────────────────────────
    $_ENV['STRIPE_API_BASE'] = 'http://127.0.0.1:' . $rmt['port'];
    $rmtSet('stripe-payment.mode', 'test');
    $rmtSet('stripe-payment.test_secret_key', 'sk_test_fake_restaurant');
    $rmtSet('stripe-payment.test_publishable_key', 'pk_test_fake_restaurant');
    $rmtSet('restaurant.currency', 'USD');
    $rmtSet('restaurant.service_charge_auto', '0');

    $manifest = json_decode((string) file_get_contents(SLATE_ROOT . '/plugins/restaurant/plugin.json'), true);
    (new Restaurant('restaurant', $manifest, SLATE_ROOT . '/plugins/restaurant'))->boot();

    $tid = current_tenant_id();
    $rmt['items'][] = Database::insert('restaurant_items', [
        'tenant_id' => $tid, 'name' => 'RMT Brisket plate', 'slug' => 'rmt-brisket-' . bin2hex(random_bytes(3)),
        'price_cents' => 1000, 'tax_rate' => 10, 'is_active' => 1,
    ]);
    $rmt['readers'][] = Database::insert('restaurant_readers', [
        'tenant_id' => $tid, 'label' => 'RMT reader', 'stripe_reader_id' => 'tmr_fake_reader',
    ]);

    unit('restaurant 0.3.0 migration: refunds table and the refund/charge columns exist after boot', function (): void {
        $cols = fn(string $t) => array_column(Database::rows(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t]),
            'COLUMN_NAME');
        assert_true(in_array('refunded_cents', $cols('restaurant_payments'), true), 'payments.refunded_cents');
        assert_true(in_array('central_charge_id', $cols('restaurant_payments'), true), 'payments.central_charge_id');
        assert_true(in_array('refunded_cents', $cols('restaurant_orders'), true), 'orders.refunded_cents');
        assert_true(in_array('payment_id', $cols('restaurant_refunds'), true), 'restaurant_refunds table');
        assert_true(RestaurantAPI::terminalAvailable(), 'Stripe Terminal should be available against the fake');
    });

    // Shared across the Terminal card tests below.
    $card = ['order' => 0, 'payment' => 0];

    unit('terminal begin fired twice: one payment row, one PaymentIntent and one reader push, each with an Idempotency-Key', function () use (&$card, $rmtOrder, $rmtRequests, $rmtFresh, $rmt): void {
        $card['order'] = $rmtOrder();
        $a = RestaurantAPI::beginTerminalPayment($card['order'], $rmt['readers'][0], 2200, 300);
        $b = RestaurantAPI::beginTerminalPayment($card['order'], $rmt['readers'][0], 2200, 300);
        assert_true($a['ok'] && $b['ok'], 'both begins succeed');
        assert_eq($a['payment_id'], $b['payment_id'], 'same payment row');
        assert_eq($a['pi_id'], $b['pi_id'], 'same PaymentIntent');
        $card['payment'] = (int) $a['payment_id'];
        assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM restaurant_payments WHERE order_id = ?", [$card['order']]));

        $creates = $rmtRequests('POST', '#^/v1/payment_intents$#');
        assert_eq(1, count($rmtFresh($creates)), 'one PaymentIntent created at Stripe');
        assert_eq(2, count($creates), 'the second create was sent and replayed');
        assert_true($creates[0]['idempotency_key'] !== '' && $creates[0]['idempotency_key'] === $creates[1]['idempotency_key'],
            'both creates carry the same key');
        assert_eq('2500', (string) $creates[0]['params']['amount'], 'amount + tip charged');
        $pushes = $rmtRequests('POST', '#/process_payment_intent$#');
        assert_eq(1, count($rmtFresh($pushes)), 'reader prompted once');
        assert_true($pushes[0]['idempotency_key'] !== '', 'reader push keyed');
    });

    unit('terminal poll path: card presented, poll captures, payment settles and the check is paid with its tip', function () use (&$card, $rmtPay, $rmtOrd, $rmtRequests): void {
        StripeTerminalAPI::simulatePresentCard('tmr_fake_reader');
        $poll = RestaurantAPI::pollTerminalPayment($card['payment']);
        assert_eq('paid', $poll['state'], 'poll result');
        assert_eq(1, count($rmtRequests('POST', '#/capture$#')), 'captured once');

        $p = $rmtPay($card['payment']);
        assert_eq('paid', $p['status']);
        assert_true((int) $p['central_charge_id'] > 0, 'central charge id stored on the payment');
        $charge = StripePaymentAPI::getCharge((int) $p['central_charge_id']);
        assert_eq(2500, (int) $charge['amount_cents'], 'central charge amount');

        $o = $rmtOrd($card['order']);
        assert_eq('paid', $o['status']);
        assert_eq(300, (int) $o['tip_cents']);
        assert_eq(2500, (int) $o['total_cents']);
        assert_eq(2500, (int) $o['paid_cents']);
        assert_eq('paid', RestaurantAPI::pollTerminalPayment($card['payment'])['state'], 'polling again stays paid');
        assert_eq(1, count($rmtRequests('POST', '#/capture$#')), 'and does not capture again');
    });

    unit('partial card refund goes to Stripe for that amount; payment stays paid, order paid drops, tip kept', function () use (&$card, $rmtPay, $rmtOrd, $rmtRequests): void {
        $r = RestaurantAPI::refundPayment($card['payment'], 1000, 'cold brisket');
        assert_true($r['ok'], $r['error'] ?? '');
        $refunds = $rmtRequests('POST', '#^/v1/refunds$#');
        assert_eq(1, count($refunds));
        assert_eq('1000', (string) $refunds[0]['params']['amount']);
        assert_true($refunds[0]['idempotency_key'] !== '', 'refund keyed');

        $p = $rmtPay($card['payment']);
        assert_eq('paid', $p['status']);
        assert_eq(1000, (int) $p['refunded_cents']);
        $o = $rmtOrd($card['order']);
        assert_eq('paid', $o['status'], 'a refund never reopens the check');
        assert_eq(1500, (int) $o['paid_cents']);
        assert_eq(1000, (int) $o['refunded_cents']);
        assert_eq(300, (int) $o['tip_cents'], 'refund comes out of the amount before the tip');
        $row = Database::row("SELECT * FROM restaurant_refunds WHERE payment_id = ?", [$card['payment']]);
        assert_eq('succeeded', $row['status']);
        assert_eq('cold brisket', $row['reason']);
        assert_true(str_starts_with((string) $row['stripe_refund_id'], 're_fake'), 'Stripe refund id kept');
    });

    unit('refund larger than what is left is rejected before Stripe is called', function () use (&$card, $rmtRequests): void {
        $r = RestaurantAPI::refundPayment($card['payment'], 1501);
        assert_false($r['ok']);
        assert_eq(1, count($rmtRequests('POST', '#^/v1/refunds$#')), 'no new refund request');
    });

    unit('refunding the rest marks the payment refunded, takes the tip back, and a further refund is rejected', function () use (&$card, $rmtPay, $rmtOrd, $rmtRequests, $rmtFake): void {
        $r = RestaurantAPI::refundPayment($card['payment'], null);
        assert_true($r['ok'], $r['error'] ?? '');
        assert_eq(2500, $r['refunded_cents']);
        $p = $rmtPay($card['payment']);
        assert_eq('refunded', $p['status']);
        $o = $rmtOrd($card['order']);
        assert_eq(0, (int) $o['paid_cents']);
        assert_eq(2500, (int) $o['refunded_cents']);
        assert_eq(0, (int) $o['tip_cents']);
        assert_eq(2200, (int) $o['total_cents'], 'total without the refunded tip');

        $again = RestaurantAPI::refundPayment($card['payment'], null);
        assert_false($again['ok'], 'second full refund rejected');
        assert_eq(2, count($rmtRequests('POST', '#^/v1/refunds$#')), 'and never reached Stripe');

        $pi = $rmtFake()['pi'][$p['stripe_pi_id']];
        assert_eq(2500, (int) $pi['refunded'], 'Stripe returned exactly what was charged');
        $charge = StripePaymentAPI::getCharge((int) $p['central_charge_id']);
        assert_eq('refunded', $charge['status']);
        assert_eq(2500, (int) $charge['refunded_cents']);
    });

    unit('Stripe refusing a refund releases the claim and records the refusal; the retry gets a new key and succeeds', function () use ($rmtOrder, $rmtTerminal, $rmtPay, $rmtRequests, $rmtFresh): void {
        $order = $rmtOrder();
        $res   = $rmtTerminal($order, 2200, 0);
        assert_eq('paid', RestaurantAPI::pollTerminalPayment($res['payment_id'])['state']);

        StripePaymentAPI::httpRequest('POST', '/__test/fail_next_refund', []);
        $r = RestaurantAPI::refundPayment($res['payment_id'], 500);
        assert_false($r['ok']);
        assert_true(str_contains($r['error'], 'Insufficient funds'), 'Stripe message surfaced: ' . $r['error']);
        $p = $rmtPay($res['payment_id']);
        assert_eq(0, (int) $p['refunded_cents'], 'claim released');
        assert_eq('paid', $p['status']);
        assert_eq('failed', Database::value("SELECT status FROM restaurant_refunds WHERE payment_id = ?", [$res['payment_id']]));

        $r2 = RestaurantAPI::refundPayment($res['payment_id'], 500);
        assert_true($r2['ok'], $r2['error'] ?? '');
        $keys = array_column(array_slice($rmtRequests('POST', '#^/v1/refunds$#'), -2), 'idempotency_key');
        assert_true($keys[0] !== $keys[1], 'retry after a refusal uses a fresh key');
        assert_eq(500, (int) $rmtPay($res['payment_id'])['refunded_cents']);
    });

    unit('declined card: poll reports the decline and the payment fails, the check stays open', function () use ($rmtOrder, $rmtTerminal, $rmtPay, $rmtOrd): void {
        $order = $rmtOrder();
        $res   = $rmtTerminal($order, 2200, 0, true);
        $poll  = RestaurantAPI::pollTerminalPayment($res['payment_id']);
        assert_eq('failed', $poll['state']);
        assert_eq('Your card was declined.', $poll['error']);
        assert_eq('failed', $rmtPay($res['payment_id'])['status']);
        assert_eq('open', $rmtOrd($order)['status']);
        assert_eq(0, (int) $rmtOrd($order)['paid_cents']);
    });

    unit('cancel at the reader: reader action and PaymentIntent cancelled, payment failed, a new begin makes a new PaymentIntent', function () use ($rmtOrder, $rmtPay, $rmtFake, $rmt): void {
        $order = $rmtOrder();
        $a = RestaurantAPI::beginTerminalPayment($order, $rmt['readers'][0], 2200, 0);
        assert_true(RestaurantAPI::cancelTerminalPayment($a['payment_id'])['ok']);
        assert_eq('failed', $rmtPay($a['payment_id'])['status']);
        assert_eq('canceled', $rmtFake()['pi'][$a['pi_id']]['status']);
        assert_eq('failed', RestaurantAPI::pollTerminalPayment($a['payment_id'])['state']);

        $b = RestaurantAPI::beginTerminalPayment($order, $rmt['readers'][0], 2200, 0);
        assert_true($b['payment_id'] !== $a['payment_id'] && $b['pi_id'] !== $a['pi_id'], 'retry is a new attempt');
        RestaurantAPI::cancelTerminalPayment($b['payment_id']);
    });

    unit('deleting a reader sends a real DELETE to Stripe and reports what Stripe answered', function () use ($rmtRequests): void {
        $res = StripeTerminalAPI::deleteReader('tmr_gone');
        assert_true($res['ok'], $res['error'] ?? '');
        assert_eq(1, count($rmtRequests('DELETE', '#^/v1/terminal/readers/tmr_gone$#')));
        assert_eq(0, count($rmtRequests('GET', '#^/v1/terminal/readers/tmr_gone$#')), 'not sent as a GET');
    });

    unit('webhook settlement: payment_intent.succeeded settles once; a repeated delivery changes nothing', function () use ($rmtOrder, $rmtTerminal, $rmtPay, $rmtOrd): void {
        $order = $rmtOrder();
        $res   = $rmtTerminal($order, 2200, 200);
        $cap   = StripeTerminalAPI::capture($res['pi_id']);
        assert_true($cap['ok'], 'captured');
        $event = ['type' => 'payment_intent.succeeded', 'data' => ['object' => $cap['payment_intent']]];
        RestaurantAPI::handleStripeEvent($event);
        RestaurantAPI::handleStripeEvent($event);

        $p = $rmtPay($res['payment_id']);
        assert_eq('paid', $p['status']);
        assert_eq(1, (int) Database::value(
            "SELECT COUNT(*) FROM stripepayment_charges WHERE stripe_payment_intent_id = ? AND tenant_id = ?",
            [$res['pi_id'], current_tenant_id()]), 'one central charge');
        $o = $rmtOrd($order);
        assert_eq('paid', $o['status']);
        assert_eq(2400, (int) $o['paid_cents']);
        assert_eq(2400, (int) $o['total_cents']);

        $failed = ['type' => 'payment_intent.payment_failed', 'data' => ['object' => $cap['payment_intent']]];
        RestaurantAPI::handleStripeEvent($failed);
        assert_eq('paid', $rmtPay($res['payment_id'])['status'], 'a late failure event never un-pays a settled payment');
    });

    unit('cash refund is recorded without calling Stripe; partial then full', function () use ($rmtOrder, $rmtPay, $rmtOrd, $rmtRequests): void {
        $before = count($rmtRequests('POST', '#^/v1/refunds$#'));
        $order  = $rmtOrder();
        $pid    = RestaurantAPI::recordCashPayment($order, 2200, 100);
        assert_eq('paid', $rmtOrd($order)['status']);

        assert_true(RestaurantAPI::refundPayment($pid, 700, 'wrong side')['ok']);
        assert_eq('paid', $rmtPay($pid)['status']);
        assert_eq(1600, (int) $rmtOrd($order)['paid_cents']);
        assert_true(RestaurantAPI::refundPayment($pid, null)['ok']);
        assert_eq('refunded', $rmtPay($pid)['status']);
        assert_eq(0, (int) $rmtOrd($order)['paid_cents']);
        assert_eq(2300, (int) $rmtOrd($order)['refunded_cents']);
        assert_false(RestaurantAPI::refundPayment($pid, 1)['ok'], 'nothing left to refund');
        assert_eq($before, count($rmtRequests('POST', '#^/v1/refunds$#')), 'no Stripe refund for cash');
        assert_eq(2, (int) Database::value(
            "SELECT COUNT(*) FROM restaurant_refunds WHERE payment_id = ? AND status = 'succeeded' AND method = 'cash'", [$pid]));
    });

    unit('a pending or failed payment cannot be refunded', function () use ($rmtOrder, $rmt): void {
        $order = $rmtOrder();
        $a = RestaurantAPI::beginTerminalPayment($order, $rmt['readers'][0], 2200, 0);
        assert_false(RestaurantAPI::refundPayment($a['payment_id'], 100)['ok'], 'pending');
        RestaurantAPI::cancelTerminalPayment($a['payment_id']);
        assert_false(RestaurantAPI::refundPayment($a['payment_id'], 100)['ok'], 'failed');
    });

    unit('online Payment Element begin fired twice: one payment row and one PaymentIntent', function () use ($rmtOrder, $rmtRequests, $rmtFresh): void {
        $order  = $rmtOrder();
        $before = count($rmtFresh($rmtRequests('POST', '#^/v1/payment_intents$#')));
        $a = RestaurantAPI::beginOnlinePaymentIntent($order);
        $b = RestaurantAPI::beginOnlinePaymentIntent($order);
        assert_true($a['ok'] && $b['ok']);
        assert_eq($a['pi_id'], $b['pi_id']);
        assert_eq($a['client_secret'], $b['client_secret']);
        assert_eq($a['payment_id'], $b['payment_id']);
        assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM restaurant_payments WHERE order_id = ?", [$order]));
        $creates = $rmtFresh($rmtRequests('POST', '#^/v1/payment_intents$#'));
        assert_eq($before + 1, count($creates), 'one new PaymentIntent');
        assert_true(end($creates)['idempotency_key'] !== '', 'keyed');
    });

    unit('hosted Checkout begin fired twice: one payment row and one session', function () use ($rmtOrder, $rmtRequests, $rmtFresh): void {
        $order = $rmtOrder();
        $a = RestaurantAPI::beginOnlinePayment($order, 'https://example.test/ok', 'https://example.test/cancel');
        $b = RestaurantAPI::beginOnlinePayment($order, 'https://example.test/ok', 'https://example.test/cancel');
        assert_true($a['ok'] && $b['ok']);
        assert_eq($a['url'], $b['url']);
        assert_eq($a['payment_id'], $b['payment_id']);
        assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM restaurant_payments WHERE order_id = ?", [$order]));
        $creates = $rmtFresh($rmtRequests('POST', '#^/v1/checkout/sessions$#'));
        assert_eq(1, count($creates));
        assert_true($creates[0]['idempotency_key'] !== '', 'keyed');
    });

    unit('hosted Stripe Checkout settles from its checkout.session.completed event through the payment layer, once', function () use ($rmtOrder, $rmtPay, $rmtOrd): void {
        $order = $rmtOrder();
        $a = RestaurantAPI::beginOnlinePayment($order, 'https://example.test/ok?gw=stripe&ref={PAYMENT_REF}', 'https://example.test/cancel', 'stripe');
        assert_true($a['ok'], $a['error'] ?? '');
        $p = $rmtPay($a['payment_id']);
        assert_eq(['stripe', $p['stripe_session_id']], [$p['payment_gateway'], $p['payment_ref']], 'the session is the payment ref');
        $session = ['id' => $p['payment_ref'], 'payment_status' => 'paid', 'payment_intent' => 'pi_rmt_hosted_' . bin2hex(random_bytes(3)),
                    'amount_total' => 2200, 'currency' => 'usd', 'metadata' => []];
        $event = ['type' => 'checkout.session.completed', 'data' => ['object' => $session]];
        Payments::emit(StripeGateway::normalize($event));
        Payments::emit(StripeGateway::normalize($event));
        $p = $rmtPay($a['payment_id']);
        assert_eq(['paid', $session['payment_intent']], [$p['status'], $p['stripe_pi_id']]);
        assert_eq(2200, (int) $rmtOrd($order)['paid_cents']);
        $charge = Payments::getCharge((int) $p['central_charge_id']);
        assert_eq(['stripe', 'restaurant', (string) $order], [$charge['gateway'], $charge['source_plugin'], $charge['source_id']]);
        assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'restaurant.payment_paid' AND target = ?",
            [(string) $a['payment_id']]), 'settled once');
    });

    unit('receipt: header, footer, business, lines with modifiers, money breakdown, payments and refunds', function () use (&$card, $rmtSet, $rmtOrder): void {
        $rmtSet('restaurant.receipt_header', "RMT HEADER LINE\nSecond line");
        $rmtSet('restaurant.receipt_footer', 'RMT FOOTER thanks');
        $rmtSet('restaurant.biz_name', 'RMT Smokehouse');
        $rmtSet('restaurant.addr_line1', '1 Test Street');

        $paid = $rmtOrder();
        RestaurantAPI::recordCashPayment($paid, 2200, 0);
        $html = restaurant_receipt_html(RestaurantAPI::getOrder($paid));
        foreach (['RMT HEADER LINE<br />', 'Second line', 'RMT FOOTER thanks', 'RMT Smokehouse', '1 Test Street',
                  'RMT Brisket plate', '$20.00', '$2.00', '$22.00', 'Cash'] as $needle) {
            assert_true(str_contains($html, $needle), 'receipt shows ' . $needle);
        }
        assert_false(str_contains($html, 'Balance due'), 'no balance on a paid check');

        $refunded = restaurant_receipt_html(RestaurantAPI::getOrder($card['order']));
        assert_true(str_contains($refunded, 'Refund'), 'refunds listed');
        assert_true(str_contains($refunded, '-$15.00') && str_contains($refunded, '-$10.00'), 'each refund amount');

        $rmtSet('restaurant.receipt_header', '<script>alert(1)</script>');
        $esc = restaurant_receipt_html(RestaurantAPI::getOrder($paid));
        assert_false(str_contains($esc, '<script>'), 'header text is escaped');
    });
} finally {
    foreach ($rmt['orders'] as $oid) {
        $lines = array_column(Database::rows("SELECT id FROM restaurant_order_items WHERE order_id = ?", [$oid]), 'id');
        foreach ($lines as $lid) Database::delete('restaurant_order_item_modifiers', 'order_item_id = ?', [$lid]);
        Database::delete('restaurant_order_items', 'order_id = ?', [$oid]);
        Database::delete('restaurant_refunds', 'order_id = ?', [$oid]);
        Database::delete('restaurant_payments', 'order_id = ?', [$oid]);
        Database::delete('restaurant_orders', 'id = ?', [$oid]);
    }
    foreach ($rmt['items'] as $iid) Database::delete('restaurant_items', 'id = ?', [$iid]);
    foreach ($rmt['readers'] as $rid) Database::delete('restaurant_readers', 'id = ?', [$rid]);
    $run = is_file($rmt['state'] . '/objects.json')
        ? (string) (json_decode((string) file_get_contents($rmt['state'] . '/objects.json'), true)['run'] ?? '') : '';
    if ($run !== '') {
        Database::query("DELETE FROM stripepayment_charges WHERE source_plugin = 'restaurant' AND stripe_payment_intent_id LIKE ?",
            ['pi_fake' . $run . '\_%']);
    }
    Database::query("DELETE FROM stripepayment_charges WHERE tenant_id = ? AND source_plugin = 'restaurant' AND stripe_payment_intent_id LIKE 'pi\_rmt\_hosted\_%'", [current_tenant_id()]);
    if ($run !== '') {
        Database::query("DELETE FROM payment_sessions WHERE tenant_id = ? AND gateway = 'stripe' AND source_plugin = 'restaurant' AND gateway_ref LIKE ?",
            [current_tenant_id(), 'cs_fake' . $run . '\_%']);
    }
    foreach ($rmt['settings'] as $key => $orig) {
        if ($orig === null) {
            Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
        } else {
            Database::setSetting($key, $orig);
        }
    }
    unset($_ENV['STRIPE_API_BASE']);
    proc_terminate($rmt['proc']);
    proc_close($rmt['proc']);
    array_map('unlink', glob($rmt['state'] . '/*') ?: []);
    @rmdir($rmt['state']);
}
