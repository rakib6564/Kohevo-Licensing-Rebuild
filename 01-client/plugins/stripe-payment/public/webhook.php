<?php
/**
 * Stripe webhook endpoint.
 *
 * Subscribed events:
 *   - checkout.session.completed    — hosted Stripe Checkout
 *   - payment_intent.succeeded       — a PaymentIntent a plugin created
 *                                     (inline card, Terminal)
 *   - invoice.paid                   — a subscription's renewal (the first
 *                                     period is recorded by its checkout
 *                                     session, so only renewals count here)
 *
 * Every event arrives server-to-server and is the source of truth for
 * "did this payment go through": the return pages are UX fast paths.
 * dispatchWebhook() verifies the signature, normalises the event for the
 * shared payment layer (Payments::emit → payment_event, which records the
 * ledger row and settles the consuming plugin's record) and fires
 * stripe_webhook_event for Stripe-specific listeners (Terminal, inline
 * card). The charge rows written below are idempotent with the layer's.
 *
 * Security: every request is signature-verified with HMAC-SHA256
 * against the configured webhook_secret. Unsigned and stale (>5min)
 * requests are rejected with HTTP 400.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
// An inactive plugin takes no deliveries (Stripe retries them once it is back).
slate_public_entry('stripe-payment');

require_once dirname(__DIR__) . '/StripePaymentAPI.php';

// Read raw payload BEFORE any framework parses it. Even a trailing
// newline difference would invalidate the HMAC.
$payload   = (string)file_get_contents('php://input');
$sigHeader = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

// Verify + dispatch via the generic facade: the payment layer and
// `stripe_webhook_event` listeners receive the parsed event.
$event = StripePaymentAPI::dispatchWebhook($payload, $sigHeader);
if ($event === null) {
    slate_log('Stripe webhook: signature failed or webhook not configured', 'warning');
    http_response_code(400);
    echo 'bad signature';
    exit;
}

$type = (string)$event['type'];
$obj  = $event['data']['object'] ?? null;

// Always record successful charges into stripepayment_charges so the
// Charges admin page sees every event, regardless of source plugin.
// Listeners on `stripe_webhook_event` can also call StripePaymentAPI::recordCharge
// directly if they prefer; recordCharge() is idempotent.
if ($type === 'checkout.session.completed' && is_array($obj)) {
    $meta = $obj['metadata'] ?? [];
    StripePaymentAPI::recordCharge([
        'source_plugin'            => (string)($meta['source_plugin'] ?? 'unknown'),
        'source_id'                => (string)($meta['source_id'] ?? ($meta['cart_sid'] ?? '')),
        'stripe_session_id'        => (string)($obj['id'] ?? ''),
        'stripe_payment_intent_id' => (string)($obj['payment_intent'] ?? ''),
        'customer_email'           => (string)($obj['customer_details']['email'] ?? ($obj['customer_email'] ?? '')),
        'amount_cents'             => (int)($obj['amount_total'] ?? 0),
        'currency'                 => strtoupper((string)($obj['currency'] ?? 'usd')),
        'meta'                     => $meta,
    ]);
}
if ($type === 'invoice.paid' && is_array($obj) && ($obj['billing_reason'] ?? '') === 'subscription_cycle') {
    // The subscription's metadata, where the checkout copied it (the API's newer and older invoice shapes).
    $meta = $obj['parent']['subscription_details']['metadata'] ?? ($obj['subscription_details']['metadata'] ?? []);
    StripePaymentAPI::recordCharge([
        'source_plugin'            => (string)($meta['source_plugin'] ?? 'unknown'),
        'source_id'                => (string)($meta['source_id'] ?? ''),
        // The invoice id stands in for a session id: it is unique per renewal, so a redelivery is not counted twice.
        'stripe_session_id'        => (string)($obj['id'] ?? ''),
        'customer_email'           => (string)($obj['customer_email'] ?? ''),
        'amount_cents'             => (int)($obj['amount_paid'] ?? 0),
        'currency'                 => strtoupper((string)($obj['currency'] ?? 'usd')),
        'meta'                     => $meta,
    ]);
}
if ($type === 'payment_intent.succeeded' && is_array($obj)) {
    $meta = $obj['metadata'] ?? [];
    StripePaymentAPI::recordCharge([
        'source_plugin'            => (string)($meta['source_plugin'] ?? 'unknown'),
        'source_id'                => (string)($meta['source_id'] ?? ($meta['cart_sid'] ?? '')),
        'stripe_payment_intent_id' => (string)($obj['id'] ?? ''),
        'customer_email'           => (string)($obj['receipt_email'] ?? ''),
        'amount_cents'             => (int)($obj['amount_received'] ?? ($obj['amount'] ?? 0)),
        'currency'                 => strtoupper((string)($obj['currency'] ?? 'usd')),
        'meta'                     => $meta,
    ]);
}

// Every consumer reacts through the payment layer (payment_event) or the
// stripe_webhook_event action, both fired by dispatchWebhook() above.
http_response_code(200);
echo 'ok';
