<?php
/**
 * Gateway-neutral payment layer (src/Services/Payments): the Payments facade,
 * the shared ledger, payment_sessions routing, the `payment_event` action, and
 * Stripe's adapter onto it.
 *
 * A stand-in gateway (id `pltest`) plays the gateway's part, so nothing here
 * talks to a real payment service. Every row and setting it creates is
 * removed in pltest_cleanup().
 */

declare(strict_types=1);

use Slate\Contracts\Payments\PaymentGateway;
use Slate\Services\Payments\CheckoutRequest;
use Slate\Services\Payments\CheckoutStart;
use Slate\Services\Payments\PaymentEvent;
use Slate\Services\Payments\PaymentLedger;

require_once dirname(__DIR__, 2) . '/plugins/stripe-payment/StripePaymentAPI.php';

final class PlTestGateway implements PaymentGateway
{
    public bool $configured = true;
    public bool $recurring = false;
    /** @var array<string,string> ref → 'paid' | 'declined' | 'pending' */
    public array $state = [];
    public array $refunds = [];
    public ?array $refundReply = null;
    private int $seq = 0;

    public function id(): string { return 'pltest'; }
    public function label(): string { return 'Test gateway'; }
    public function isConfigured(): bool { return $this->configured; }
    public function mode(): string { return 'test'; }
    public function settingsUrl(): string { return '/'; }
    public function supports(string $c): bool
    {
        return in_array($c, [self::HOSTED, self::REFUND, self::PARTIAL_REFUND], true)
            || ($c === self::RECURRING && $this->recurring);
    }

    public function startCheckout(CheckoutRequest $r): CheckoutStart
    {
        $ref = 'PLT-' . getmypid() . '-' . (++$this->seq) . '-' . bin2hex(random_bytes(3));
        $this->state[$ref] = 'pending';
        return new CheckoutStart('pltest', $ref, 'https://pay.invalid/' . $ref);
    }

    public function confirm(string $ref): ?PaymentEvent
    {
        $s = $this->state[$ref] ?? 'pending';
        if ($s === 'pending') return null;
        return new PaymentEvent(
            type: $s === 'paid' ? PaymentEvent::SUCCEEDED : PaymentEvent::FAILED,
            gateway: 'pltest',
            ref: $ref,
            txnId: $s === 'paid' ? 'CAP-' . $ref : '',
            amountCents: 2500,
            currency: 'EUR',
            customerEmail: 'payer@example.test',
            // Metadata that tries to claim another plugin's record: the
            // session row must win.
            metadata: ['source_plugin' => 'evil', 'source_id' => '1'],
        );
    }

    public function refund(array $charge, ?int $amountCents, string $key = ''): array
    {
        $this->refunds[] = [$charge['id'], $amountCents, $key];
        return $this->refundReply ?? ['ok' => true, 'refund_id' => 'RF-' . count($this->refunds)];
    }
}

function pltest_cleanup(): void
{
    $tid = current_tenant_id();
    Database::query("DELETE FROM stripepayment_charges WHERE tenant_id = ? AND (gateway = 'pltest' OR source_plugin LIKE 'pltest%')", [$tid]);
    Database::query("DELETE FROM payment_sessions WHERE tenant_id = ? AND (gateway = 'pltest' OR source_plugin LIKE 'pltest%')", [$tid]);
    Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key LIKE 'payments.off.pltest.%'", [$tid]);
}

function pltest_request(string $sourceId, array $extra = []): CheckoutRequest
{
    return new CheckoutRequest(
        [['name' => 'Lesson', 'amount_cents' => 1250, 'quantity' => 2]],
        $extra + [
            'success_url'   => 'https://site.invalid/ok?ref={PAYMENT_REF}',
            'cancel_url'    => 'https://site.invalid/cancel',
            'source_plugin' => 'pltest-shop',
            'source_id'     => $sourceId,
            'currency'      => 'eur',
        ]
    );
}

PaymentLedger::ensureSchema();
pltest_cleanup();
$PLT = new PlTestGateway();
Payments::register($PLT);

/** @var list<PaymentEvent> */
$PLT_EVENTS = [];
Hook::addAction('payment_event', function (PaymentEvent $e) use (&$PLT_EVENTS): void {
    if ($e->gateway === 'pltest' || str_starts_with($e->sourcePlugin, 'pltest')) $PLT_EVENTS[] = $e;
});

try {

unit('payments: a registered, configured gateway is available; an unconfigured one is not', function () use ($PLT): void {
    assert_true(isset(Payments::gateways()['pltest']), 'registered gateway listed');
    assert_true(isset(Payments::available('pltest-shop')['pltest']), 'configured gateway available');
    $PLT->configured = false;
    try {
        assert_false(isset(Payments::available('pltest-shop')['pltest']), 'unconfigured gateway not offered');
    } finally {
        $PLT->configured = true;
    }
});

unit('payments: switching a gateway off for one plugin hides it there only', function (): void {
    Payments::setEnabledFor('pltest', 'pltest-shop', false);
    assert_false(isset(Payments::available('pltest-shop')['pltest']), 'off for pltest-shop');
    assert_true(isset(Payments::available('pltest-booking')['pltest']), 'still on for another plugin');
    assert_throws(\RuntimeException::class, fn () => Payments::startCheckout('pltest', pltest_request('1')), 'a switched-off gateway refuses a checkout');
    Payments::setEnabledFor('pltest', 'pltest-shop', true);
    assert_true(isset(Payments::available('pltest-shop')['pltest']), 'back on');
});

unit('payments: a capability the gateway lacks filters it out and refuses the checkout', function (): void {
    assert_false(isset(Payments::available('pltest-shop', [PaymentGateway::RECURRING])['pltest']), 'no recurring support');
    assert_throws(\RuntimeException::class,
        fn () => Payments::startCheckout('pltest', pltest_request('1', ['interval' => 'month'])),
        'recurring checkout refused');
});

unit('payments: CheckoutRequest rejects what a gateway would reject later', function (): void {
    assert_throws(\InvalidArgumentException::class, fn () => new CheckoutRequest([['name' => 'x', 'amount_cents' => 0]], [
        'success_url' => 'a', 'cancel_url' => 'b', 'source_plugin' => 'p']), 'no positive line item');
    assert_throws(\InvalidArgumentException::class, fn () => new CheckoutRequest([['name' => 'x', 'amount_cents' => 5]], [
        'success_url' => 'a', 'cancel_url' => 'b']), 'missing source_plugin');
    assert_throws(\InvalidArgumentException::class, fn () => new CheckoutRequest([['name' => 'x', 'amount_cents' => 5]], [
        'success_url' => 'a', 'cancel_url' => 'b', 'source_plugin' => 'p', 'interval' => 'week']), 'bad interval');
    assert_eq(2500, pltest_request('1')->totalCents(), 'total = unit × quantity');
});

unit('payments: startCheckout records the session that routes the payment back', function (): void {
    $start = Payments::startCheckout('pltest', pltest_request('order-7'));
    $row = Payments::session('pltest', $start->ref);
    assert_true($row !== null, 'session row written');
    assert_eq('pltest-shop', $row['source_plugin']);
    assert_eq('order-7', $row['source_id']);
    assert_eq(2500, (int) $row['amount_cents']);
    assert_eq('EUR', $row['currency']);
    assert_eq('pending', $row['status']);
});

unit('payments: confirmReturn only acts for the plugin record that started the checkout', function () use ($PLT): void {
    $start = Payments::startCheckout('pltest', pltest_request('order-8'));
    $PLT->state[$start->ref] = 'paid';
    assert_null(Payments::confirmReturn('pltest', $start->ref, 'pltest-other', 'order-8'), 'another plugin cannot confirm it');
    assert_null(Payments::confirmReturn('pltest', $start->ref, 'pltest-shop', 'order-9'), 'another record cannot confirm it');
    assert_null(Payments::confirmReturn('pltest', 'PLT-unknown', 'pltest-shop', 'order-8'), 'an unknown ref does nothing');
    $none = Database::value("SELECT COUNT(*) FROM stripepayment_charges WHERE gateway = 'pltest' AND gateway_ref = ? AND tenant_id = ?",
        [$start->ref, current_tenant_id()]);
    assert_eq(0, (int) $none, 'refused confirmations recorded nothing');
});

unit('payments: a paid return records one charge, fires payment_event, and a repeat is not new', function () use ($PLT, &$PLT_EVENTS): void {
    $start = Payments::startCheckout('pltest', pltest_request('order-10'));
    assert_null(Payments::confirmReturn('pltest', $start->ref, 'pltest-shop', 'order-10'), 'still pending → null');

    $PLT->state[$start->ref] = 'paid';
    $before = count($PLT_EVENTS);
    $e1 = Payments::confirmReturn('pltest', $start->ref, 'pltest-shop', 'order-10');
    assert_true($e1 !== null && $e1->type === PaymentEvent::SUCCEEDED, 'succeeded');
    assert_true($e1->isFor('pltest-shop', 'order-10'), 'routed from the session, not the event metadata');
    assert_true($e1->isNewCharge && $e1->chargeId > 0, 'first emit inserts');
    assert_eq($before + 1, count($PLT_EVENTS), 'payment_event fired once');

    $e2 = Payments::confirmReturn('pltest', $start->ref, 'pltest-shop', 'order-10');
    assert_eq($e1->chargeId, $e2->chargeId, 'same ledger row');
    assert_false($e2->isNewCharge, 'repeat is not new');

    $row = Payments::getCharge((int) $e1->chargeId);
    assert_eq('pltest', $row['gateway']);
    assert_eq('CAP-' . $start->ref, $row['gateway_txn_id']);
    assert_eq($start->ref, $row['gateway_ref']);
    assert_null($row['stripe_payment_intent_id'], 'non-Stripe rows leave the Stripe columns empty');
    assert_eq('payer@example.test', $row['customer_email']);
    assert_eq('completed', Payments::session('pltest', $start->ref)['status']);
});

unit('payments: a declined return marks the session failed and records no charge', function () use ($PLT): void {
    $start = Payments::startCheckout('pltest', pltest_request('order-11'));
    $PLT->state[$start->ref] = 'declined';
    $e = Payments::confirmReturn('pltest', $start->ref, 'pltest-shop', 'order-11');
    assert_true($e !== null && $e->type === PaymentEvent::FAILED, 'failed event');
    assert_null($e->chargeId, 'no ledger row');
    assert_eq('failed', Payments::session('pltest', $start->ref)['status']);
});

unit('payments: refunds go through the gateway and keep a running total', function () use ($PLT): void {
    $start = Payments::startCheckout('pltest', pltest_request('order-12'));
    $PLT->state[$start->ref] = 'paid';
    $id = (int) Payments::confirmReturn('pltest', $start->ref, 'pltest-shop', 'order-12')->chargeId;

    $r1 = Payments::refundCharge($id, 1000, 'k1');
    assert_true($r1['ok'], 'partial refund ok');
    assert_eq(1000, $r1['refunded_cents']);
    assert_eq([$id, 1000, 'k1'], end($PLT->refunds), 'gateway got the partial amount and key');
    assert_eq('partial_refund', Payments::getCharge($id)['status']);

    $r2 = Payments::refundCharge($id, 2000);
    assert_false($r2['ok'], 'more than what is left is refused');
    assert_eq(1000, (int) Payments::getCharge($id)['refunded_cents'], 'refusal changed nothing');

    $r3 = Payments::refundCharge($id);
    assert_true($r3['ok'], 'refund the rest');
    assert_null(end($PLT->refunds)[1], 'the rest is sent as a full (null) amount');
    assert_eq('refunded', Payments::getCharge($id)['status']);
    assert_false(Payments::refundCharge($id)['ok'], 'nothing left to refund');
});

unit('payments: an uncertain gateway failure is reported as such and leaves the ledger alone', function () use ($PLT): void {
    $start = Payments::startCheckout('pltest', pltest_request('order-13'));
    $PLT->state[$start->ref] = 'paid';
    $id = (int) Payments::confirmReturn('pltest', $start->ref, 'pltest-shop', 'order-13')->chargeId;
    $PLT->refundReply = ['ok' => false, 'error' => 'timeout', 'uncertain' => true];
    try {
        $r = Payments::refundCharge($id, 500);
        assert_false($r['ok']);
        assert_true($r['uncertain'], 'uncertain flag passed through');
        assert_eq(0, (int) Payments::getCharge($id)['refunded_cents'], 'no refund recorded');
    } finally {
        $PLT->refundReply = null;
    }
});

unit('payments: a refund webhook raises the ledger total but never lowers it', function () use ($PLT): void {
    $start = Payments::startCheckout('pltest', pltest_request('order-14'));
    $PLT->state[$start->ref] = 'paid';
    $id = (int) Payments::confirmReturn('pltest', $start->ref, 'pltest-shop', 'order-14')->chargeId;
    $refund = fn (int $total) => Payments::emit(new PaymentEvent(
        type: PaymentEvent::REFUNDED, gateway: 'pltest', ref: $start->ref, txnId: 'CAP-' . $start->ref, refundedCents: $total));
    $refund(1500);
    assert_eq(1500, (int) Payments::getCharge($id)['refunded_cents']);
    $refund(500);
    assert_eq(1500, (int) Payments::getCharge($id)['refunded_cents'], 'an older, smaller total is ignored');
    $refund(2500);
    assert_eq('refunded', Payments::getCharge($id)['status']);
});

unit('payments: money helpers respect zero-decimal currencies', function (): void {
    assert_eq(1250, Payments::toMinor('12.50', 'EUR'));
    assert_eq(1250, Payments::toMinor('12,5', 'eur'), 'comma decimal');
    assert_eq(500, Payments::toMinor('500', 'JPY'), 'JPY has no minor unit');
    assert_eq('12.50', Payments::toMajor(1250, 'USD'));
    assert_eq('500', Payments::toMajor(500, 'JPY'));
});

// ── Stripe on the shared layer ──────────────────────────────

unit('stripe adapter: StripePaymentAPI::recordCharge still dedupes on its own columns', function (): void {
    $pi = 'pi_pltest_' . bin2hex(random_bytes(4));
    $cs = 'cs_pltest_' . bin2hex(random_bytes(4));
    $a = StripePaymentAPI::recordCharge(['source_plugin' => 'pltest-legacy', 'stripe_session_id' => $cs, 'amount_cents' => 900, 'currency' => 'usd']);
    $b = StripePaymentAPI::recordCharge(['source_plugin' => 'pltest-legacy', 'stripe_session_id' => $cs, 'stripe_payment_intent_id' => $pi, 'amount_cents' => 900]);
    $c = StripePaymentAPI::recordCharge(['source_plugin' => 'pltest-legacy', 'stripe_payment_intent_id' => $pi, 'amount_cents' => 900]);
    assert_true($a > 0, 'inserted');
    assert_eq($a, $b, 'same session → same row');
    $row = StripePaymentAPI::getCharge($a);
    assert_eq('stripe', $row['gateway']);
    assert_eq($cs, $row['stripe_session_id']);
    assert_null($row['gateway_ref'], 'Stripe keeps using its own columns');
    assert_true($c !== $a || $row['stripe_payment_intent_id'] === null, 'PI lookup is independent of the session row');
});

unit('stripe adapter: webhook events normalise to the right payment events', function (): void {
    $paid = ['type' => 'checkout.session.completed', 'data' => ['object' => [
        'id' => 'cs_x', 'payment_status' => 'paid', 'payment_intent' => 'pi_x', 'amount_total' => 4200,
        'currency' => 'eur', 'customer_details' => ['email' => 'a@b.test'],
        'metadata' => ['source_plugin' => 'shop', 'source_id' => '55'],
    ]]];
    $e = StripeGateway::normalize($paid);
    assert_eq(PaymentEvent::SUCCEEDED, $e->type);
    assert_eq(['cs_x', 'pi_x', 4200, 'EUR', 'a@b.test', 'shop', '55'],
        [$e->ref, $e->txnId, $e->amountCents, $e->currency, $e->customerEmail, $e->sourcePlugin, $e->sourceId]);

    $paid['data']['object']['payment_status'] = 'unpaid';
    assert_null(StripeGateway::normalize($paid), 'an async session still settling is not an outcome yet');

    assert_null(StripeGateway::normalize(['type' => 'payment_intent.succeeded', 'data' => ['object' => [
        'id' => 'pi_h', 'status' => 'succeeded', 'amount' => 100, 'metadata' => []]]]),
        'a hosted checkout\'s metadata-less intent is left to the session events');

    $exp = StripeGateway::normalize(['type' => 'checkout.session.expired', 'data' => ['object' => ['id' => 'cs_e', 'metadata' => []]]]);
    assert_eq(PaymentEvent::FAILED, $exp->type);

    $renew = StripeGateway::normalize(['type' => 'invoice.paid', 'data' => ['object' => [
        'id' => 'in_1', 'billing_reason' => 'subscription_cycle', 'amount_paid' => 990, 'currency' => 'usd',
        'subscription' => 'sub_1', 'payment_intent' => 'pi_r',
        'subscription_details' => ['metadata' => ['source_plugin' => 'studio-builder', 'source_id' => 'payment-button']],
    ]]]);
    assert_eq(PaymentEvent::RENEWED, $renew->type);
    assert_eq(['sub_1', 'pi_r', 'in_1'], [$renew->ref, $renew->txnId, $renew->metadata['ledger_ref']]);
    assert_null(StripeGateway::normalize(['type' => 'invoice.paid', 'data' => ['object' => ['billing_reason' => 'subscription_create']]]),
        'the first invoice is the checkout itself');
});

unit('stripe adapter: a verified webhook emits payment_event before stripe_webhook_event', function () use (&$PLT_EVENTS): void {
    $tid  = current_tenant_id();
    // Mode, secret and the webhook-health counters this delivery bumps.
    $saved = Database::rows(
        "SELECT setting_key, setting_value FROM settings WHERE tenant_id = ?
            AND (setting_key IN ('stripe-payment.mode', 'stripe-payment.test_webhook_secret') OR setting_key LIKE 'stripe-payment.wh\\_%')",
        [$tid]);
    $order = [];
    $legacy = function (array $ev) use (&$order): void {
        if (($ev['data']['object']['metadata']['source_plugin'] ?? '') === 'pltest-hook') $order[] = 'legacy';
    };
    $neutral = function (PaymentEvent $e) use (&$order): void {
        if ($e->sourcePlugin === 'pltest-hook') $order[] = 'payment_event:' . ($e->isNewCharge ? 'new' : 'old');
    };
    Hook::addAction('stripe_webhook_event', $legacy);
    Hook::addAction('payment_event', $neutral);
    try {
        Database::setSetting('stripe-payment.mode', 'test');
        Database::setSetting('stripe-payment.test_webhook_secret', 'whsec_pltest');
        $cs = 'cs_pltest_' . bin2hex(random_bytes(4));
        $payload = json_encode(['id' => 'evt_pltest', 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => $cs, 'payment_status' => 'paid', 'payment_intent' => 'pi_' . substr($cs, 3), 'amount_total' => 700,
            'currency' => 'usd', 'metadata' => ['source_plugin' => 'pltest-hook', 'source_id' => '3'],
        ]]]);
        $t = time();
        $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, 'whsec_pltest');
        assert_true(StripePaymentAPI::dispatchWebhook($payload, $sig) !== null, 'verified');
        assert_eq(['payment_event:new', 'legacy'], $order, 'neutral event first, and it inserted the row');
        $n = Database::value("SELECT COUNT(*) FROM stripepayment_charges WHERE stripe_session_id = ? AND tenant_id = ?", [$cs, $tid]);
        assert_eq(1, (int) $n, 'one ledger row');

        // Redelivery: the row exists, so the event is not new.
        $order = [];
        StripePaymentAPI::dispatchWebhook($payload, $sig);
        assert_eq(['payment_event:old', 'legacy'], $order, 'a redelivery is not a new charge');
    } finally {
        Hook::removeAction('stripe_webhook_event', $legacy);
        Hook::removeAction('payment_event', $neutral);
        Database::query("DELETE FROM settings WHERE tenant_id = ?
            AND (setting_key IN ('stripe-payment.mode', 'stripe-payment.test_webhook_secret') OR setting_key LIKE 'stripe-payment.wh\\_%')", [$tid]);
        foreach ($saved as $row) Database::setSetting($row['setting_key'], $row['setting_value'], $tid);
        Database::query("DELETE FROM stripepayment_charges WHERE tenant_id = ? AND source_plugin = 'pltest-hook'", [$tid]);
    }
});

} finally {
    Payments::unregister('pltest');
    pltest_cleanup();
}
