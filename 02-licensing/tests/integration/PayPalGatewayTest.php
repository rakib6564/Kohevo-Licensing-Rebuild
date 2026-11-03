<?php
/**
 * PayPal gateway (plugins/paypal-payment) on the shared payment layer, against
 * tests/fixtures/fake-paypal.php: orders and capture, the webhook backstop,
 * refunds, subscriptions and renewals, webhook verification, token refresh,
 * and the plugin's two public endpoints over real HTTP.
 */

declare(strict_types=1);

use Slate\Services\Payments\CheckoutRequest;
use Slate\Services\Payments\PaymentEvent;
use Slate\Services\Payments\PaymentLedger;

require_once dirname(__DIR__, 2) . '/plugins/paypal-payment/PayPalPaymentAPI.php';
require_once dirname(__DIR__) . '/support/fake_paypal.php';

function ppt_request(string $sourceId, array $extra = [], ?array $items = null): CheckoutRequest
{
    return new CheckoutRequest(
        $items ?? [['name' => 'Gloves', 'amount_cents' => 1250, 'quantity' => 2, 'description' => 'Size M']],
        $extra + [
            'success_url'   => 'https://site.invalid/paid?ref={PAYMENT_REF}',
            'cancel_url'    => 'https://site.invalid/cart?cancelled=1',
            'source_plugin' => 'ppt-shop',
            'source_id'     => $sourceId,
            'currency'      => 'eur',
            'customer_email' => 'ana@example.test',
        ]
    );
}

function ppt_count(array $h, string $method, string $pathRegex): int
{
    return count(array_filter(fake_paypal_requests($h), fn ($r) => $r['method'] === $method && preg_match($pathRegex, $r['path']) && !$r['replay']));
}

function ppt_webhook(string $type, array $resource): array
{
    return ['id' => 'WH-EVT-' . bin2hex(random_bytes(4)), 'event_type' => $type, 'resource' => $resource];
}

$PPH = fake_paypal_start();
$PPG = Payments::gateway('paypal');
/** @var list<PaymentEvent> */
$PPT_EVENTS = [];
$pptListener = function (PaymentEvent $e) use (&$PPT_EVENTS): void {
    if ($e->gateway === 'paypal') $PPT_EVENTS[] = $e;
};
Hook::addAction('payment_event', $pptListener);

try {

unit('paypal: registered, configured from the stored sandbox credentials, and offered', function () use ($PPG): void {
    assert_true($PPG instanceof PayPalGateway, 'gateway registered');
    assert_true($PPG->isConfigured(), 'configured');
    assert_eq('test', $PPG->mode(), 'sandbox records as test mode');
    assert_true(isset(Payments::available('ppt-shop', ['hosted', 'recurring', 'partial_refund'])['paypal']), 'offers hosted, recurring and partial refunds');
    $secret = (string) Database::setting('paypal-payment.sandbox_client_secret');
    assert_true(str_starts_with($secret, 'enc:v1:'), 'the secret is stored encrypted');
});

unit('paypal: a one-time checkout creates an order with the items, total and return URLs', function () use ($PPH): void {
    $start = Payments::startCheckout('paypal', ppt_request('o-1'));
    assert_true(str_starts_with($start->ref, 'ORD'), 'order id is the ref');
    assert_true(str_contains($start->redirectUrl, 'checkoutnow?token=' . $start->ref), 'customer goes to the approval link');
    $create = array_values(array_filter(fake_paypal_requests($PPH), fn ($r) => $r['path'] === '/v2/checkout/orders'))[0]['body'];
    $pu = $create['purchase_units'][0];
    assert_eq('CAPTURE', $create['intent']);
    assert_eq(['EUR', '25.00', '25.00'], [$pu['amount']['currency_code'], $pu['amount']['value'], $pu['amount']['breakdown']['item_total']['value']]);
    assert_eq(['Gloves', '2', '12.50', 'Size M'], [$pu['items'][0]['name'], $pu['items'][0]['quantity'], $pu['items'][0]['unit_amount']['value'], $pu['items'][0]['description']]);
    assert_eq('ppt-shop:o-1', $pu['custom_id']);
    $ctx = $create['payment_source']['paypal']['experience_context'];
    assert_true(str_ends_with($ctx['return_url'], '/plugins/paypal-payment/public/return.php'), 'PayPal returns to the plugin first');
    assert_eq('NO_SHIPPING', $ctx['shipping_preference']);
    assert_eq('ana@example.test', $create['payment_source']['paypal']['email_address']);
    $s = Payments::session('paypal', $start->ref);
    assert_eq(['ppt-shop', 'o-1', 2500, 'EUR'], [$s['source_plugin'], $s['source_id'], (int) $s['amount_cents'], $s['currency']]);
    assert_eq('https://site.invalid/paid?ref=' . $start->ref, Payments::returnUrl($s), 'the plugin\'s success URL with the ref filled in');
    assert_eq('https://site.invalid/cart?cancelled=1', Payments::returnUrl($s, false));
});

unit('paypal: confirming before approval waits; after approval it captures once and records the charge', function () use ($PPH, &$PPT_EVENTS): void {
    $start = Payments::startCheckout('paypal', ppt_request('o-2'));
    assert_null(Payments::confirmReturn('paypal', $start->ref, 'ppt-shop', 'o-2'), 'not approved yet → still pending');
    fake_paypal_hook($PPH, '/__test/approve/' . $start->ref);

    $before = count($PPT_EVENTS);
    $e = Payments::confirmReturn('paypal', $start->ref, 'ppt-shop', 'o-2');
    assert_true($e !== null && $e->type === PaymentEvent::SUCCEEDED, 'captured');
    assert_true($e->isNewCharge, 'new ledger row');
    assert_eq($before + 1, count($PPT_EVENTS), 'payment_event fired');
    $row = Payments::getCharge((int) $e->chargeId);
    assert_eq(['paypal', $start->ref, 2500, 'EUR', 'buyer@example.test', 'test'],
        [$row['gateway'], $row['gateway_ref'], (int) $row['amount_cents'], $row['currency'], $row['customer_email'], $row['mode']]);
    assert_true(str_starts_with((string) $row['gateway_txn_id'], 'CAP'), 'the capture id is the refundable txn');

    $again = Payments::confirmReturn('paypal', $start->ref, 'ppt-shop', 'o-2');
    assert_eq($e->chargeId, $again->chargeId, 'a second confirm finds the same charge');
    assert_false($again->isNewCharge);
    assert_eq(1, ppt_count($PPH, 'POST', '#/v2/checkout/orders/' . $start->ref . '/capture$#'), 'captured exactly once');
});

unit('paypal: an approved order whose buyer never returns is captured by the webhook, once', function () use ($PPH, $PPG): void {
    $start = Payments::startCheckout('paypal', ppt_request('o-3'));
    fake_paypal_hook($PPH, '/__test/approve/' . $start->ref);

    $e1 = Payments::emit($PPG->fromWebhook(ppt_webhook('CHECKOUT.ORDER.APPROVED', ['id' => $start->ref, 'status' => 'APPROVED'])));
    assert_eq(PaymentEvent::SUCCEEDED, $e1->type, 'the approval webhook captured it');
    assert_true($e1->isFor('ppt-shop', 'o-3'), 'routed by the session');
    $capture = Payments::getCharge((int) $e1->chargeId)['gateway_txn_id'];

    $e2 = Payments::emit($PPG->fromWebhook(ppt_webhook('PAYMENT.CAPTURE.COMPLETED', [
        'id' => $capture, 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '25.00'],
        'supplementary_data' => ['related_ids' => ['order_id' => $start->ref]],
    ])));
    assert_eq($e1->chargeId, $e2->chargeId, 'the capture webhook lands on the same row');
    assert_false($e2->isNewCharge);
    $e3 = Payments::confirmReturn('paypal', $start->ref, 'ppt-shop', 'o-3');
    assert_eq($e1->chargeId, $e3->chargeId, 'a late return finds it too');
    assert_eq(1, ppt_count($PPH, 'POST', '#/capture$#') - 1, 'one capture for this order (one from the previous test)');
});

unit('paypal: a declined capture is a failed payment and records nothing', function () use ($PPH): void {
    $start = Payments::startCheckout('paypal', ppt_request('o-4'));
    fake_paypal_hook($PPH, '/__test/approve/' . $start->ref);
    fake_paypal_hook($PPH, '/__test/decline_next_capture');
    $e = Payments::confirmReturn('paypal', $start->ref, 'ppt-shop', 'o-4');
    assert_true($e !== null && $e->type === PaymentEvent::FAILED, 'failed');
    assert_true(str_contains($e->failureMessage, 'declined'), 'a message the customer can act on');
    assert_null($e->chargeId);
    assert_eq('failed', Payments::session('paypal', $start->ref)['status']);
});

unit('paypal: partial then full refunds go to the capture, and the ledger keeps the total', function () use ($PPH): void {
    $start = Payments::startCheckout('paypal', ppt_request('o-5'));
    fake_paypal_hook($PPH, '/__test/approve/' . $start->ref);
    $id = (int) Payments::confirmReturn('paypal', $start->ref, 'ppt-shop', 'o-5')->chargeId;
    $capture = Payments::getCharge($id)['gateway_txn_id'];

    $r1 = Payments::refundCharge($id, 1000, 'k-o5-1');
    assert_true($r1['ok'], 'partial refund: ' . ($r1['error'] ?? ''));
    $refunds = array_values(array_filter(fake_paypal_requests($PPH), fn ($r) => str_contains($r['path'], "/captures/{$capture}/refund")));
    assert_eq(['10.00', 'EUR'], [$refunds[0]['body']['amount']['value'], $refunds[0]['body']['amount']['currency_code']]);
    assert_eq('refund-k-o5-1', $refunds[0]['request_id'], 'the idempotency key reaches PayPal');
    assert_eq('partial_refund', Payments::getCharge($id)['status']);

    $r2 = Payments::refundCharge($id);
    assert_true($r2['ok'], 'refund the rest');
    $refunds = array_values(array_filter(fake_paypal_requests($PPH), fn ($r) => str_contains($r['path'], "/captures/{$capture}/refund")));
    assert_true(!isset($refunds[1]['body']['amount']), 'the rest is sent without an amount');
    assert_eq(['refunded', 2500], [Payments::getCharge($id)['status'], (int) Payments::getCharge($id)['refunded_cents']]);

    // A refund made in the PayPal dashboard reaches the ledger through the webhook.
    $start2 = Payments::startCheckout('paypal', ppt_request('o-5b'));
    fake_paypal_hook($PPH, '/__test/approve/' . $start2->ref);
    $id2 = (int) Payments::confirmReturn('paypal', $start2->ref, 'ppt-shop', 'o-5b')->chargeId;
    $cap2 = Payments::getCharge($id2)['gateway_txn_id'];
    $ev = Payments::emit(Payments::gateway('paypal')->fromWebhook(ppt_webhook('PAYMENT.CAPTURE.REFUNDED', [
        'id' => 'REFX', 'status' => 'COMPLETED',
        'seller_payable_breakdown' => ['total_refunded_amount' => ['currency_code' => 'EUR', 'value' => '7.50']],
        'links' => [['rel' => 'up', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/' . $cap2]],
    ])));
    assert_eq(PaymentEvent::REFUNDED, $ev->type);
    assert_eq(750, (int) Payments::getCharge($id2)['refunded_cents'], 'dashboard refund recorded');
});

unit('paypal: amounts follow PayPal\'s decimals per currency', function () use ($PPH): void {
    assert_eq('500', PayPalPaymentAPI::value(500, 'JPY'), 'JPY has no decimals');
    assert_eq('1500', PayPalPaymentAPI::value(150000, 'HUF'), 'HUF is stored in cents but sent whole');
    assert_eq('12.50', PayPalPaymentAPI::value(1250, 'usd'));
    assert_eq(1250, PayPalPaymentAPI::minor('12.50', 'USD'));
    assert_throws(\RuntimeException::class,
        fn () => Payments::startCheckout('paypal', ppt_request('o-6', ['currency' => 'huf'], [['name' => 'x', 'amount_cents' => 150050]])),
        'a HUF amount PayPal cannot take is refused before anything is created');
});

unit('paypal: the access token is cached, and a rejected one is replaced once', function () use ($PPH): void {
    $before = ppt_count($PPH, 'POST', '#^/v1/oauth2/token$#');
    PayPalPaymentAPI::getOrder('ORDNOPE');
    PayPalPaymentAPI::getOrder('ORDNOPE');
    assert_eq($before, ppt_count($PPH, 'POST', '#^/v1/oauth2/token$#'), 'no new token while the cached one is valid');
    fake_paypal_hook($PPH, '/__test/expire_tokens');
    $start = Payments::startCheckout('paypal', ppt_request('o-7'));
    assert_true($start->ref !== '', 'the request succeeded after a refresh');
    assert_eq($before + 1, ppt_count($PPH, 'POST', '#^/v1/oauth2/token$#'), 'exactly one refresh');
});

unit('paypal: webhook deliveries are verified with PayPal against the configured webhook id', function (): void {
    $payload = json_encode(ppt_webhook('PAYMENT.CAPTURE.COMPLETED', ['id' => 'X']));
    $h = ['paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://api.paypal.com/cert', 'paypal-transmission-id' => 't1',
          'paypal-transmission-sig' => 'good', 'paypal-transmission-time' => gmdate('c')];
    assert_true(PayPalPaymentAPI::verifyWebhook($payload, $h), 'a genuine delivery passes');
    assert_false(PayPalPaymentAPI::verifyWebhook($payload, ['paypal-transmission-sig' => 'bad'] + $h), 'a forged one fails');
    $missing = $h; unset($missing['paypal-cert-url']);
    assert_false(PayPalPaymentAPI::verifyWebhook($payload, $missing), 'missing headers fail without asking PayPal');
    $id = Database::setting('paypal-payment.sandbox_webhook_id');
    Database::setSetting('paypal-payment.sandbox_webhook_id', '');
    try {
        assert_false(PayPalPaymentAPI::verifyWebhook($payload, $h), 'no webhook id → nothing is trusted');
    } finally {
        Database::setSetting('paypal-payment.sandbox_webhook_id', (string) $id);
    }
});

unit('paypal: monthly payments use one reusable plan; activation, first sale and renewals each count once', function () use ($PPH, $PPG): void {
    $req = fn (string $id) => ppt_request($id, ['interval' => 'month'], [['name' => 'Club membership', 'amount_cents' => 990]]);
    $start = Payments::startCheckout('paypal', $req('s-1'));
    assert_true(str_starts_with($start->ref, 'I-'), 'subscription id is the ref');
    assert_true(str_contains($start->redirectUrl, 'ba_token='), 'customer goes to the subscription approval link');
    Payments::startCheckout('paypal', $req('s-2'));
    assert_eq(1, ppt_count($PPH, 'POST', '#^/v1/catalogs/products$#'), 'one catalog product');
    assert_eq(1, ppt_count($PPH, 'POST', '#^/v1/billing/plans$#'), 'the plan for 9.90 EUR monthly is reused');
    $plan = array_values(array_filter(fake_paypal_requests($PPH), fn ($r) => $r['path'] === '/v1/billing/plans'))[0]['body'];
    assert_eq(['MONTH', '9.90', 'EUR'], [$plan['billing_cycles'][0]['frequency']['interval_unit'],
        $plan['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'], $plan['billing_cycles'][0]['pricing_scheme']['fixed_price']['currency_code']]);

    assert_null(Payments::confirmReturn('paypal', $start->ref, 'ppt-shop', 's-1'), 'pending until activated');
    fake_paypal_hook($PPH, '/__test/activate/' . $start->ref);
    $act = Payments::confirmReturn('paypal', $start->ref, 'ppt-shop', 's-1');
    assert_eq(PaymentEvent::SUCCEEDED, $act->type, 'activation is the first period');
    $row = Payments::getCharge((int) $act->chargeId);
    assert_eq([990, $start->ref], [(int) $row['amount_cents'], $row['gateway_ref']]);
    assert_null($row['gateway_txn_id'], 'no sale id yet');

    $sale = fn (string $saleId) => ppt_webhook('PAYMENT.SALE.COMPLETED', [
        'id' => $saleId, 'state' => 'completed', 'billing_agreement_id' => $start->ref, 'amount' => ['total' => '9.90', 'currency' => 'EUR']]);
    assert_null($PPG->fromWebhook($sale('SALE1')), 'the first sale completes the activation row');
    assert_eq('SALE1', Payments::getCharge((int) $act->chargeId)['gateway_txn_id']);

    $renew = Payments::emit($PPG->fromWebhook($sale('SALE2')));
    assert_eq(PaymentEvent::RENEWED, $renew->type);
    assert_true($renew->isNewCharge && $renew->isFor('ppt-shop', 's-1'), 'a renewal is a new row for the same record');
    $again = Payments::emit($PPG->fromWebhook($sale('SALE2')));
    assert_eq($renew->chargeId, $again->chargeId, 'a redelivered renewal is not counted twice');
    assert_false($again->isNewCharge);

    $r = Payments::refundCharge((int) $renew->chargeId, 490, 'k-s1');
    assert_true($r['ok'], 'a subscription payment is refunded as a sale: ' . ($r['error'] ?? ''));
    assert_eq(1, ppt_count($PPH, 'POST', '#^/v1/payments/sale/SALE2/refund$#'));
});

unit('paypal: a sale that arrives before the activation stands in for it', function () use ($PPH, $PPG): void {
    $start = Payments::startCheckout('paypal', ppt_request('s-3', ['interval' => 'month'], [['name' => 'Club membership', 'amount_cents' => 990]]));
    fake_paypal_hook($PPH, '/__test/activate/' . $start->ref);
    $first = Payments::emit($PPG->fromWebhook(ppt_webhook('PAYMENT.SALE.COMPLETED', [
        'id' => 'SALE9', 'billing_agreement_id' => $start->ref, 'amount' => ['total' => '9.90', 'currency' => 'EUR']])));
    assert_eq(PaymentEvent::SUCCEEDED, $first->type);
    $act = Payments::emit($PPG->fromWebhook(ppt_webhook('BILLING.SUBSCRIPTION.ACTIVATED', ['id' => $start->ref, 'status' => 'ACTIVE'])));
    assert_eq($first->chargeId, $act->chargeId, 'the activation finds the sale\'s row');
    assert_false($act->isNewCharge);
});

unit('paypal settings: the secret is write-only, and new credentials are checked with PayPal before saving', function (): void {
    assert_true(slate_has_app_secret(), 'precondition: APP_SECRET must be configured for the integration suite');
    $probe = function (?array $fields): array {
        $cmd = 'PAYPAL_API_BASE=' . escapeshellarg((string) $_ENV['PAYPAL_API_BASE']) . ' '
             . escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg(dirname(__DIR__) . '/fixtures/' . ($fields === null ? 'admin-page-probe.php' : 'admin-page-post-probe.php')) . ' '
             . escapeshellarg('plugins/paypal-payment/admin/settings.php') . ' '
             . escapeshellarg($fields === null ? '' : (string) json_encode($fields)) . ' 1 0 2>/dev/null';
        $out = (string) shell_exec($cmd);
        if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) throw new RuntimeException('no STATUS line: ' . substr($out, 0, 300));
        return [(int) $m[1], substr($out, strlen($m[0]))];
    };
    $stored = fn () => (string) Database::setting('paypal-payment.sandbox_client_secret');
    $before = $stored();

    [$code, $html] = $probe(null);
    assert_eq(200, $code);
    assert_false(str_contains($html, 'fake-secret') || str_contains($html, $before), 'the secret is never rendered back');
    assert_true(str_contains($html, 'fake-client-id-0000000000'), 'the public client id is shown');

    [$code] = $probe(['_action' => 'save', 'mode' => 'sandbox', 'client_id' => 'fake-client-id-0000000000', 'client_secret' => '', 'webhook_id' => 'WH-FAKE-0002']);
    assert_eq(200, $code);
    assert_eq($before, $stored(), 'a blank secret field keeps the stored secret');
    assert_eq('WH-FAKE-0002', Database::setting('paypal-payment.sandbox_webhook_id'), 'the other fields saved');

    [, $html] = $probe(['_action' => 'save', 'mode' => 'sandbox', 'client_id' => 'fake-client-id-0000000000', 'client_secret' => 'wrong-secret', 'webhook_id' => 'WH-FAKE-0002']);
    assert_true(str_contains($html, 'rejected'), 'credentials PayPal refuses are reported');
    assert_eq($before, $stored(), 'and not saved');

    [, $html] = $probe(['_action' => 'save', 'mode' => 'sandbox', 'client_id' => 'fake-client-id-0000000000', 'client_secret' => 'fake-secret', 'webhook_id' => 'WH-FAKE-0001']);
    assert_true(str_contains($html, 'Settings saved') || str_contains($html, 'enregistr'), 'good credentials save');
    assert_true(str_starts_with($stored(), 'enc:v1:') && $stored() !== $before, 'a new secret is stored as a fresh envelope');
    assert_eq('fake-secret', slate_decrypt_secret($stored()));
});

// ── The public endpoints, over HTTP ─────────────────────────

unit('paypal endpoints: return.php settles and forwards; webhook.php verifies before it records', function () use ($PPH): void {
    $tid = current_tenant_id();
    $wasActive = Database::value("SELECT status FROM plugins WHERE slug = 'paypal-payment'");
    if ($wasActive === null) {
        Database::query("INSERT INTO plugins (slug,name,version,status,manifest_json) VALUES ('paypal-payment','PayPal Payment Gateway','1.0.0','active','{\"slug\":\"paypal-payment\",\"name\":\"PayPal Payment Gateway\"}')");
    } elseif ($wasActive !== 'active') {
        Database::query("UPDATE plugins SET status = 'active' WHERE slug = 'paypal-payment'");
    }
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $srv = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', SLATE_ROOT, SLATE_ROOT . '/tests/fixtures/testing-router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, SLATE_ROOT,
        ['PAYPAL_API_BASE' => $_ENV['PAYPAL_API_BASE'], 'PATH' => (string) getenv('PATH')]);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
    $http = function (string $method, string $path, string $body = '', array $headers = []) use ($port): array {
        $ch = curl_init('http://127.0.0.1:' . $port . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers] + ($body !== '' ? [CURLOPT_POSTFIELDS => $body] : []));
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        preg_match('/^Location:\s*(\S+)/mi', $raw, $loc);
        return [$code, $loc[1] ?? '', $raw];
    };
    try {
        $start = Payments::startCheckout('paypal', ppt_request('h-1'));
        fake_paypal_hook($PPH, '/__test/approve/' . $start->ref);
        [$code, $loc] = $http('GET', '/plugins/paypal-payment/public/return.php?token=' . $start->ref . '&PayerID=PAYER1');
        assert_eq(303, $code, 'return.php redirects');
        assert_eq('https://site.invalid/paid?ref=' . $start->ref, $loc, 'to the plugin\'s own success URL');
        $row = PaymentLedger::findByRef('paypal', $start->ref);
        assert_true($row !== null && $row['status'] === 'succeeded', 'the payment was captured and recorded on the way through');

        [, $loc] = $http('GET', '/plugins/paypal-payment/public/return.php?token=' . $start->ref . '&cancel=1');
        assert_eq('https://site.invalid/cart?cancelled=1', $loc, 'cancel goes to the plugin\'s cancel URL');
        [, $loc] = $http('GET', '/plugins/paypal-payment/public/return.php?token=ORDUNKNOWN1');
        assert_true(!str_contains($loc, 'site.invalid'), 'an unknown order cannot steer the redirect');

        $start2 = Payments::startCheckout('paypal', ppt_request('h-2'));
        fake_paypal_hook($PPH, '/__test/approve/' . $start2->ref);
        $payload = json_encode(ppt_webhook('CHECKOUT.ORDER.APPROVED', ['id' => $start2->ref, 'status' => 'APPROVED']));
        $hdr = fn (string $sig) => ['Content-Type: application/json', 'PAYPAL-AUTH-ALGO: SHA256withRSA', 'PAYPAL-CERT-URL: https://api.paypal.com/c',
            'PAYPAL-TRANSMISSION-ID: t2', 'PAYPAL-TRANSMISSION-SIG: ' . $sig, 'PAYPAL-TRANSMISSION-TIME: ' . gmdate('c')];
        [$code] = $http('POST', '/plugins/paypal-payment/public/webhook.php', $payload, $hdr('forged'));
        assert_eq(400, $code, 'a forged delivery is refused');
        assert_null(PaymentLedger::findByRef('paypal', $start2->ref), 'and records nothing');
        assert_eq('rejecting', PayPalPaymentAPI::webhookHealth()['state'], 'the settings page will say so');
        [$code] = $http('POST', '/plugins/paypal-payment/public/webhook.php', $payload, $hdr('good'));
        assert_eq(200, $code, 'a verified delivery is accepted');
        assert_true(PaymentLedger::findByRef('paypal', $start2->ref) !== null, 'the approved order was captured by the webhook');
        assert_eq('ok', PayPalPaymentAPI::webhookHealth()['state']);
    } finally {
        proc_terminate($srv);
        proc_close($srv);
        if ($wasActive === null) Database::query("DELETE FROM plugins WHERE slug = 'paypal-payment'");
        elseif ($wasActive !== 'active') Database::query("UPDATE plugins SET status = ? WHERE slug = 'paypal-payment'", [$wasActive]);
    }
});

} finally {
    Hook::removeAction('payment_event', $pptListener);
    fake_paypal_stop($PPH);
}
