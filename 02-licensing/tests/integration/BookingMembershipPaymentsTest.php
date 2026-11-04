<?php
/**
 * Booking and Membership on the shared payment layer: a membership bought and a booking paid with PayPal
 * (tests/fixtures/fake-paypal.php), Stripe events arriving through the layer (inline card intents, and checkouts
 * started before the layer that only carry the old metadata), a declined payment, a retried one, and refunds.
 *
 * Every plan, customer, subscription, service, provider and appointment this file makes is removed afterwards.
 */

declare(strict_types=1);

use Slate\Services\Payments\PaymentEvent;
use Slate\Services\Payments\PaymentLedger;

require_once dirname(__DIR__, 2) . '/plugins/membership/MembershipAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/booking/BookingAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/stripe-payment/StripePaymentAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/paypal-payment/PayPalPaymentAPI.php';
require_once dirname(__DIR__) . '/support/fake_paypal.php';

const BMPT_EMAIL = 'bmpt@example.test';

function bmpt_customer(): int
{
    return (int) Database::insert('customers', ['tenant_id' => current_tenant_id(), 'email' => 'bmpt+' . bin2hex(random_bytes(4)) . '@example.test',
        'name' => 'Bmpt Member', 'status' => 'active']);
}

function bmpt_plan(int $price = 4900): array
{
    MembershipAPI::ensureSchema();
    $id = Database::insert('membership_plans', ['tenant_id' => current_tenant_id(), 'name' => 'Bmpt Gold', 'plan_type' => 'membership',
        'price_cents' => $price, 'currency' => 'EUR', 'duration_days' => 30, 'is_active' => 1]);
    return MembershipAPI::plan($id);
}

/** A pending appointment that needs $price paid in full. */
function bmpt_appointment(int $price = 4000): array
{
    $tid = current_tenant_id();
    $svc = Database::insert('booking_services', ['tenant_id' => $tid, 'name' => 'Bmpt Session', 'slug' => 'bmpt-' . bin2hex(random_bytes(4)),
        'duration_min' => 30, 'price_cents' => $price, 'currency' => 'EUR', 'payment_mode' => 'full', 'is_active' => 1]);
    $prov = Database::insert('booking_providers', ['tenant_id' => $tid, 'name' => 'Bmpt Coach', 'is_active' => 1]);
    $id = Database::insert('booking_appointments', [
        'tenant_id' => $tid, 'ref' => 'BK-' . strtoupper(bin2hex(random_bytes(4))), 'manage_token' => bin2hex(random_bytes(16)),
        'service_id' => $svc, 'provider_id' => $prov, 'customer_name' => 'Bmpt Client', 'customer_email' => BMPT_EMAIL,
        'party_size' => 1, 'starts_at' => slate_db_now(), 'ends_at' => slate_db_now(), 'status' => 'pending', 'source' => 'online',
        'price_cents' => $price, 'payment_status' => 'pending',
    ]);
    return Database::row('SELECT * FROM booking_appointments WHERE id = ?', [$id]);
}

function bmpt_appt(int $id): array
{
    return Database::row('SELECT * FROM booking_appointments WHERE id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
}

$BMPT = fake_paypal_start();
// An active plugin already listens (its boot()); stand in only for one that is not, so nothing runs twice.
$bmptListeners = [];
if (!PluginLoader::isActive('membership')) $bmptListeners[] = [MembershipAPI::class, 'handlePaymentEvent'];
if (!PluginLoader::isActive('booking')) $bmptListeners[] = [BookingAPI::class, 'handlePaymentEvent'];
foreach ($bmptListeners as $l) Hook::addAction('payment_event', $l);

try {

// ── Membership ──────────────────────────────────────────────

unit('membership + paypal: a plan bought with PayPal is activated on return, with the charge linked', function () use ($BMPT): void {
    $cid = bmpt_customer();
    $plan = bmpt_plan();
    $res = MembershipAPI::purchase($cid, (int) $plan['id'], false, 'paypal');
    assert_true(!empty($res['ok']) && str_contains((string) $res['url'], 'checkoutnow'), 'checkout started at PayPal: ' . json_encode($res));
    $sub = MembershipAPI::subscription((int) $res['sub_id']);
    assert_eq(['pending', 'paypal'], [$sub['status'], $sub['payment_gateway']]);
    $ref = (string) $sub['payment_ref'];
    assert_true(str_contains(Payments::returnUrl(Payments::session('paypal', $ref)), 'view=return&sub=' . $sub['id'] . '&gw=paypal&ref=' . $ref), 'returns to the member page');

    fake_paypal_hook($BMPT, '/__test/approve/' . $ref);
    $e = Payments::confirmReturn('paypal', $ref, 'membership', (string) $sub['id']);
    assert_eq(PaymentEvent::SUCCEEDED, $e?->type);
    $sub = MembershipAPI::subscription((int) $sub['id']);
    assert_eq('active', $sub['status'], 'activated');
    assert_eq($e->chargeId, (int) $sub['charge_id'], 'the PayPal charge is linked');
    assert_eq(['paypal', 4900], [Payments::getCharge((int) $sub['charge_id'])['gateway'], (int) $sub['amount_cents']]);
});

unit('membership: only the subscription\'s own checkout activates it', function (): void {
    $cid = bmpt_customer();
    $plan = bmpt_plan();
    $res = MembershipAPI::purchase($cid, (int) $plan['id'], false, 'paypal');
    $sub = MembershipAPI::subscription((int) $res['sub_id']);
    MembershipAPI::handlePaymentEvent(new PaymentEvent(type: PaymentEvent::SUCCEEDED, gateway: 'paypal', ref: 'ORDSOMEONEELSE',
        sourcePlugin: 'membership', sourceId: (string) $sub['id'], amountCents: 4900, currency: 'EUR'));
    assert_eq('pending', MembershipAPI::subscription((int) $sub['id'])['status'], 'a payment for another checkout does nothing');
});

unit('membership: a Stripe checkout started before the payment layer still activates its subscription', function (): void {
    $cid = bmpt_customer();
    $plan = bmpt_plan();
    $subId = (int) Database::insert('membership_subscriptions', ['tenant_id' => current_tenant_id(), 'customer_id' => $cid, 'plan_id' => (int) $plan['id'],
        'status' => 'pending', 'amount_cents' => 4900, 'currency' => 'EUR', 'activation' => 'online', 'stripe_session_id' => 'cs_bmpt_' . $cid]);
    $event = StripeGateway::normalize(['type' => 'checkout.session.completed', 'data' => ['object' => [
        'id' => 'cs_bmpt_' . $cid, 'payment_status' => 'paid', 'amount_total' => 4900, 'currency' => 'eur', 'payment_intent' => 'pi_bmpt_' . $cid,
        'metadata' => ['source_plugin' => 'membership', 'membership_sub_id' => (string) $subId, 'customer_id' => (string) $cid]]]]);
    $e = Payments::emit($event);
    $sub = MembershipAPI::subscription($subId);
    assert_eq('active', $sub['status'], 'activated from the old metadata');
    assert_eq($e->chargeId, (int) $sub['charge_id'], 'charge linked');
});

unit('membership: a failed payment is recorded and leaves the subscription pending', function (): void {
    $cid = bmpt_customer();
    $plan = bmpt_plan();
    $res = MembershipAPI::purchase($cid, (int) $plan['id'], false, 'paypal');
    $sub = MembershipAPI::subscription((int) $res['sub_id']);
    Payments::emit(new PaymentEvent(type: PaymentEvent::FAILED, gateway: 'paypal', ref: (string) $sub['payment_ref'], failureMessage: 'declined'));
    assert_eq('pending', MembershipAPI::subscription((int) $sub['id'])['status']);
    assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'membership.payment_failed' AND target = ? AND tenant_id = ?",
        [(string) $sub['id'], current_tenant_id()]), 'the failure is on record');
});

// ── Booking ─────────────────────────────────────────────────

unit('booking + paypal: a booking paid with PayPal is confirmed on return and can be refunded through PayPal', function () use ($BMPT): void {
    $appt = bmpt_appointment();
    $pay = BookingAPI::startPayment($appt, 'paypal');
    assert_true(!empty($pay['ok']), 'started: ' . ($pay['error'] ?? ''));
    parse_str((string) parse_url($pay['url'], PHP_URL_QUERY), $q);
    $s = Payments::session('paypal', (string) ($q['token'] ?? ''));
    assert_true($s !== null && $s['source_plugin'] === 'booking' && $s['source_id'] === (string) $appt['id'], 'the checkout is bound to the appointment');
    $ref = (string) $s['gateway_ref'];
    assert_true(str_contains(Payments::returnUrl($s), '/book/pay/done?token=' . $appt['manage_token'] . '&gw=paypal&ref=' . $ref));

    fake_paypal_hook($BMPT, '/__test/approve/' . $ref);
    Payments::confirmReturn('paypal', $ref, 'booking', (string) $appt['id']);
    $after = bmpt_appt((int) $appt['id']);
    assert_eq(['confirmed', 'paid', 4000], [$after['status'], $after['payment_status'], (int) $after['paid_cents']]);
    $charge = Payments::getCharge((int) $after['charge_id']);
    assert_eq(['paypal', 'booking', (string) $appt['id']], [$charge['gateway'], $charge['source_plugin'], $charge['source_id']]);
    assert_true(str_starts_with((string) $after['payment_ref'], 'CAP'), 'the capture id is the booking\'s payment ref');

    $r = BookingAPI::refundAppointment((int) $appt['id'], 1500);
    assert_true($r['ok'], 'partial refund: ' . ($r['error'] ?? ''));
    assert_eq('partially_refunded', bmpt_appt((int) $appt['id'])['payment_status']);
    assert_eq(1500, (int) Payments::getCharge((int) $after['charge_id'])['refunded_cents']);
});

unit('booking: Stripe\'s inline card intent and an older Stripe checkout both confirm their booking', function (): void {
    $a1 = bmpt_appointment(3000);
    $pi = 'pi_bmpt_' . $a1['id'];
    Payments::emit(StripeGateway::normalize(['type' => 'payment_intent.succeeded', 'data' => ['object' => [
        'id' => $pi, 'status' => 'succeeded', 'amount_received' => 3000, 'currency' => 'eur', 'receipt_email' => BMPT_EMAIL,
        'metadata' => ['source_plugin' => 'booking', 'source_id' => (string) $a1['id'], 'booking_appt_id' => (string) $a1['id']]]]]));
    assert_eq('paid', bmpt_appt((int) $a1['id'])['payment_status'], 'inline intent');
    assert_eq($pi, bmpt_appt((int) $a1['id'])['payment_ref']);

    $a2 = bmpt_appointment(3000);
    Payments::emit(StripeGateway::normalize(['type' => 'checkout.session.completed', 'data' => ['object' => [
        'id' => 'cs_bmpt_' . $a2['id'], 'payment_status' => 'paid', 'amount_total' => 3000, 'currency' => 'eur', 'payment_intent' => 'pi_bmptc_' . $a2['id'],
        'metadata' => ['booking_appt_id' => (string) $a2['id'], 'booking_token' => $a2['manage_token']]]]]));
    $after = bmpt_appt((int) $a2['id']);
    assert_eq(['paid', 'cs_bmpt_' . $a2['id']], [$after['payment_status'], $after['stripe_session_id']], 'a checkout with only the old metadata');
    assert_true((int) $after['charge_id'] > 0, 'charge linked');
});

unit('booking review: an older inline intent (no source_id) confirms; a repeated payment is counted once; a balance adds up', function (): void {
    $a = bmpt_appointment(3000);
    $pi = 'pi_bmptold_' . $a['id'];
    $ev = ['type' => 'payment_intent.succeeded', 'data' => ['object' => [
        'id' => $pi, 'status' => 'succeeded', 'amount_received' => 1000, 'currency' => 'eur', 'receipt_email' => BMPT_EMAIL,
        'metadata' => ['source_plugin' => 'booking', 'booking_appt_id' => (string) $a['id']]]]];
    Payments::emit(StripeGateway::normalize($ev));
    $after = bmpt_appt((int) $a['id']);
    assert_eq(['confirmed', 'deposit_paid', 1000], [$after['status'], $after['payment_status'], (int) $after['paid_cents']], 'confirmed as a deposit');
    $charge = (int) $after['charge_id'];
    // The same payment again (reload of the return page, webhook alongside it): no change.
    BookingAPI::markPaid((int) $a['id'], 1000, $pi);
    assert_eq(1000, (int) bmpt_appt((int) $a['id'])['paid_cents'], 'counted once');
    // The balance is a different payment: it adds up, and the first charge stays the refundable one.
    BookingAPI::markPaid((int) $a['id'], 2000, 'pi_bmptold_bal_' . $a['id']);
    $after = bmpt_appt((int) $a['id']);
    assert_eq(['paid', 3000], [$after['payment_status'], (int) $after['paid_cents']]);
    assert_eq($charge, (int) $after['charge_id']);
});

unit('booking: a declined card is recorded as failed, and paying the same intent afterwards confirms it', function (): void {
    $a = bmpt_appointment(2500);
    $pi = 'pi_bmptf_' . $a['id'];
    $intent = fn (string $status, array $extra = []) => ['type' => $status === 'succeeded' ? 'payment_intent.succeeded' : 'payment_intent.payment_failed',
        'data' => ['object' => $extra + ['id' => $pi, 'status' => $status, 'amount' => 2500, 'currency' => 'eur',
            'metadata' => ['source_plugin' => 'booking', 'source_id' => (string) $a['id'], 'booking_appt_id' => (string) $a['id']]]]];
    Payments::emit(StripeGateway::normalize($intent('requires_payment_method', ['last_payment_error' => ['message' => 'Your card was declined.']])));
    $after = bmpt_appt((int) $a['id']);
    assert_eq(['pending', 'failed'], [$after['status'], $after['payment_status']], 'kept pending, marked failed');
    $row = PaymentLedger::findByTxn('stripe', $pi);
    assert_eq('failed', $row['status'] ?? null, 'a failed ledger row');
    assert_true(str_contains((string) $row['meta_json'], 'Your card was declined.'), 'with the reason');

    $ok = Payments::emit(StripeGateway::normalize($intent('succeeded', ['amount_received' => 2500])));
    assert_eq($row['id'], $ok->chargeId, 'the same row');
    assert_true($ok->isNewCharge, 'now counted as a real charge');
    assert_eq('succeeded', PaymentLedger::get((int) $row['id'])['status']);
    assert_eq(['confirmed', 'paid'], [bmpt_appt((int) $a['id'])['status'], bmpt_appt((int) $a['id'])['payment_status']]);
});

} finally {
    foreach ($bmptListeners as $l) Hook::removeAction('payment_event', $l);
    $tid = current_tenant_id();
    $cids = array_map('intval', array_column(Database::rows("SELECT id FROM customers WHERE tenant_id = ? AND email LIKE 'bmpt+%@example.test'", [$tid]), 'id'));
    if ($cids) {
        $in = implode(',', $cids);
        Database::query("DELETE FROM membership_wallet_txns WHERE tenant_id = ? AND customer_id IN ($in)", [$tid]);
        Database::query("DELETE FROM membership_wallet WHERE tenant_id = ? AND customer_id IN ($in)", [$tid]);
        Database::query("DELETE FROM membership_subscriptions WHERE tenant_id = ? AND customer_id IN ($in)", [$tid]);
        Database::query("DELETE FROM customers WHERE tenant_id = ? AND id IN ($in)", [$tid]);
    }
    Database::query("DELETE FROM membership_plans WHERE tenant_id = ? AND name = 'Bmpt Gold'", [$tid]);
    Database::query("DELETE FROM booking_appointments WHERE tenant_id = ? AND customer_email = ?", [$tid, BMPT_EMAIL]);
    Database::query("DELETE FROM booking_services WHERE tenant_id = ? AND name = 'Bmpt Session'", [$tid]);
    Database::query("DELETE FROM booking_providers WHERE tenant_id = ? AND name = 'Bmpt Coach'", [$tid]);
    Database::query("DELETE FROM stripepayment_charges WHERE tenant_id = ? AND (stripe_payment_intent_id LIKE 'pi_bmpt%' OR stripe_session_id LIKE 'cs_bmpt%')", [$tid]);
    fake_paypal_stop($BMPT);
}
