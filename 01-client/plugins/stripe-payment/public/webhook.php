<?php
/**
 * Stripe webhook endpoint.
 *
 * Subscribed events:
 *   - checkout.session.completed    — hosted Stripe Checkout flow
 *                                     (the customer paid on stripe.com)
 *   - payment_intent.succeeded       — embedded Payment Element flow
 *                                     (the customer paid on our page)
 *
 * Both events arrive server-to-server. We treat them as the source of
 * truth for "did this payment go through" — the browser-side paths
 * (success.php for hosted, the place_order POST for embedded) are
 * just UX fast paths. If the customer closes their browser before
 * the redirect/JS callback fires, the webhook still creates the order.
 *
 * Idempotency: all three paths (hosted success.php / embedded handler /
 * webhook) look up the same mapping row in stripepayment_sessions.
 * Whichever runs first stamps order_id; the others see it and exit.
 * This webhook's OWN internal race — two overlapping deliveries of the
 * same event (a Stripe retry, or genuinely concurrent delivery) both
 * reaching completePayment() for the same row before either one stamps
 * order_id — is closed by an atomic claim (see completePayment()). A race
 * between THIS webhook and one of the other two paths (success.php /
 * embedded handler) is not addressed here — each still uses the older,
 * unguarded check-then-act pattern this file used before this fix, and is
 * tracked as a separate concern.
 *
 * Security: every request is signature-verified with HMAC-SHA256
 * against the configured webhook_secret. Unsigned and stale (>5min)
 * requests are rejected with HTTP 400.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

require_once dirname(__DIR__) . '/StripePaymentAPI.php';
require_once dirname(__DIR__) . '/StripeAPI.php';

// Read raw payload BEFORE any framework parses it. Even a trailing
// newline difference would invalidate the HMAC.
$payload   = (string)file_get_contents('php://input');
$sigHeader = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

// Phase 2: verify + dispatch via the generic facade. Listeners on the
// `stripe_webhook_event` action receive the parsed event; Shop's
// completion logic continues to run inline below for back-compat.
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

// Back-compat below: Shop-specific completion. Only run if Shop is active —
// other plugins handle their own events via the `stripe_webhook_event` action.
if (!PluginLoader::isActive('shop') || !class_exists('ShopAPI')) {
    http_response_code(200);
    echo 'ok (no shop handler)';
    exit;
}

if (!is_array($obj)) {
    http_response_code(400);
    echo 'no object';
    exit;
}

// ──────────────────────────────────────────────────────────
// Dispatch on event type
// ──────────────────────────────────────────────────────────

if ($type === 'checkout.session.completed') {
    // Hosted flow. The Checkout Session object includes payment_status
    // ('paid'/'unpaid'/'no_payment_required') and customer_details.
    $sessionId = (string)($obj['id'] ?? '');
    if ($sessionId === '') {
        http_response_code(400);
        echo 'no session id';
        exit;
    }
    $row = lookupMappingRow('stripe_session_id', $sessionId);
    $payStatus = (string)($obj['payment_status'] ?? '');
    if ($payStatus !== 'paid' && $payStatus !== 'no_payment_required') {
        slate_log("Stripe webhook: session $sessionId payment_status=$payStatus", 'warning');
        http_response_code(200);
        echo 'not paid';
        exit;
    }
    $customerDetails = $obj['customer_details'] ?? [];
    $paidCents       = (int)($obj['amount_total'] ?? 0);
    $paidCurrency    = strtoupper((string)($obj['currency'] ?? ''));
    completePayment($row, $sessionId, $customerDetails, $paidCents, $paidCurrency);
    exit;
}

if ($type === 'payment_intent.succeeded') {
    // Embedded flow. The PaymentIntent object includes status,
    // charges/payment_method, and (in expanded mode) customer fields.
    $piId = (string)($obj['id'] ?? '');
    if ($piId === '') {
        http_response_code(400);
        echo 'no intent id';
        exit;
    }
    // PaymentIntent's 'status' is the trustworthy field here. We
    // subscribed specifically to .succeeded, but double-check.
    $status = (string)($obj['status'] ?? '');
    if ($status !== 'succeeded' && $status !== 'requires_capture') {
        slate_log("Stripe webhook: payment_intent $piId status=$status (not succeeded)", 'warning');
        http_response_code(200);
        echo 'not succeeded';
        exit;
    }
    $row = lookupMappingRow('payment_intent_id', $piId);

    // PaymentIntent doesn't carry the same customer_details Stripe
    // Checkout Session does — but the customer entered the billing
    // form on OUR checkout page first, and we stashed it as
    // billing_json on the mapping row, so we don't need a fallback.
    // For belt-and-braces use the receipt_email if Stripe attached one.
    $customerDetails = [
        'email' => $obj['receipt_email'] ?? '',
    ];
    $paidCents    = (int)($obj['amount_received'] ?? ($obj['amount'] ?? 0));
    $paidCurrency = strtoupper((string)($obj['currency'] ?? ''));
    completePayment($row, $piId, $customerDetails, $paidCents, $paidCurrency);
    exit;
}

// Anything else: acknowledge so Stripe stops retrying.
http_response_code(200);
echo 'ignored';
exit;

// ──────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────

/**
 * Look up the mapping row by either stripe_session_id (hosted) or
 * payment_intent_id (embedded). Returns null on not-found.
 */
function lookupMappingRow(string $column, string $value): ?array {
    if (!in_array($column, ['stripe_session_id', 'payment_intent_id'], true)) {
        return null; // defensive: don't allow arbitrary column names
    }
    return Database::row(
        "SELECT id, shop_sid, order_id, billing_json, status, flow
           FROM stripepayment_sessions
          WHERE {$column} = ?",
        [$value]
    );
}

/**
 * Shared completion path for hosted and embedded webhooks.
 *
 * - Row not found → log + HTTP 200 (so Stripe stops retrying; there's
 *   no way to recover a payment we have no cart mapping for).
 * - Row already has order_id → idempotent skip + HTTP 200.
 * - Otherwise: atomically claim the row (status: pending/abandoned →
 *   'claimed'), build billing, create order, stamp row, set the shop
 *   order's own status to 'processing' (paid). A checkout failure
 *   releases the claim back to 'pending' before the existing 500
 *   (Stripe-will-retry) response.
 */
function completePayment(?array $row, string $stripeId, array $customerDetails, int $paidCents = 0, string $paidCurrency = ''): void {
    if (!$row) {
        slate_log("Stripe webhook: unknown stripe id $stripeId", 'warning');
        http_response_code(200);
        echo 'unknown';
        return;
    }
    if (!empty($row['order_id'])) {
        http_response_code(200);
        echo 'already processed';
        return;
    }

    // Build billing: prefer stashed customer-entered data; fall back
    // to whatever Stripe collected.
    $billing = [];
    if (!empty($row['billing_json'])) {
        $decoded = json_decode((string)$row['billing_json'], true);
        if (is_array($decoded)) $billing = $decoded;
    }

    $cdAddr   = $customerDetails['address'] ?? [];
    $fallback = [
        'email'      => $customerDetails['email']        ?? '',
        'first_name' => '',
        'last_name'  => '',
        'address_1'  => $cdAddr['line1']                 ?? '',
        'address_2'  => $cdAddr['line2']                 ?? '',
        'city'       => $cdAddr['city']                  ?? '',
        'state'      => $cdAddr['state']                 ?? '',
        'postcode'   => $cdAddr['postal_code']           ?? '',
        'country'    => $cdAddr['country']               ?? '',
        'notes'      => '',
    ];
    if (!empty($customerDetails['name'])) {
        $parts = preg_split('/\s+/', trim((string)$customerDetails['name']), 2);
        $fallback['first_name'] = $parts[0] ?? '';
        $fallback['last_name']  = $parts[1] ?? '';
    }
    foreach ($fallback as $k => $v) {
        if (empty($billing[$k])) $billing[$k] = $v;
    }

    // Last sanity check — if even the email is missing, ShopAPI will
    // reject the order. Don't trigger a 500 retry loop on Stripe's
    // side over a bad cart; log and ack.
    if (empty($billing['email'])) {
        slate_log("Stripe webhook: no email for $stripeId, can't create order", 'error');
        http_response_code(200);
        echo 'missing email';
        return;
    }

    // Atomic claim — closes the TOCTOU between the order_id check above and
    // the order_id write below. Two overlapping deliveries of the same
    // webhook event (Stripe's own retry, or a genuinely concurrent second
    // delivery) both pass the `!empty($row['order_id'])` check above before
    // either one writes back; without this, both go on to call
    // ShopAPI::checkoutCart() and create two real, independent orders — it
    // has no de-duplication of its own (verified by reading its archived
    // source: cartItems()/createOrder() carry no uniqueness tied to
    // shop_sid or this mapping row).
    //
    // A single conditional UPDATE is atomic per-statement under InnoDB row
    // locking: a second concurrent caller's UPDATE blocks until the first
    // commits, then re-evaluates the WHERE clause against the now-committed
    // row and affects zero rows. No explicit transaction or locking read is
    // needed — reproduced live with two real concurrent processes racing
    // this exact statement before this fix was written.
    //
    // Known trade-off, accepted rather than adding a staleness/expiry
    // mechanism (which would need a new timestamp column): if the process
    // dies between claiming and either releasing or completing — a fatal
    // no \Throwable catch below can see, e.g. an OOM kill — the row is
    // stuck at status='claimed' until manually reset. This mirrors an
    // existing gap in this same function (the mapping-write failure caught
    // at the bottom of this function today is already log-only, no
    // auto-recovery) rather than introducing a materially new risk class.
    //
    // Named 'claimed', not 'processing', to stay unambiguous next to
    // ShopAPI::updateStatus($orderId, 'processing') a few lines down below
    // — that sets the unrelated shop_orders row's own status; this is the
    // stripepayment_sessions mapping row's claim state.
    $claimed = Database::update(
        'stripepayment_sessions',
        ['status' => 'claimed'],
        'id = ? AND order_id IS NULL AND status != ?',
        [(int)$row['id'], 'claimed']
    );
    if ($claimed === 0) {
        http_response_code(200);
        echo 'already claimed or processed';
        return;
    }

    try {
        $res = ShopAPI::checkoutCart($row['shop_sid'], $billing);
    } catch (\Throwable $e) {
        slate_log('Stripe webhook checkoutCart threw: ' . $e->getMessage(), 'error');
        releaseClaim((int)$row['id']);
        http_response_code(500);
        echo 'order creation failed';
        return;
    }

    if (empty($res['ok']) || empty($res['order_id'])) {
        slate_log('Stripe webhook order creation failed: ' . ($res['error'] ?? '?'), 'error');
        // Return 500 — Stripe will retry the webhook later. If the
        // failure is transient (DB down), eventually we'll succeed.
        releaseClaim((int)$row['id']);
        http_response_code(500);
        echo 'order creation failed';
        return;
    }

    $orderId = (int)$res['order_id'];

    // Reconcile the captured amount against the rebuilt order total. A
    // mismatch (cart drifted after intent creation) parks the order on
    // 'on-hold' for review rather than auto-advancing to 'processing'.
    $recon = ShopAPI::reconcilePaidAmount($orderId, $paidCents, $paidCurrency);

    try {
        Database::update('stripepayment_sessions', [
            'order_id'     => $orderId,
            'status'       => 'completed',
            'completed_at' => slate_db_now(),
        ], 'id = ?', [(int)$row['id']]);
        if (!empty($recon['ok'])) {
            ShopAPI::updateStatus($orderId, 'processing');
        }
    } catch (\Throwable $e) {
        slate_log('Stripe webhook mapping update failed: ' . $e->getMessage(), 'warning');
    }

    http_response_code(200);
    echo 'ok';
}

/**
 * Best-effort release of the 'claimed' state back to 'pending' so a later
 * retry (Stripe's own, or a subsequent concurrent delivery) can attempt
 * this row again. Called only on a checkout failure that the existing code
 * already catches and responds to with a 500 (transient-failure retry) —
 * never on success, where the row moves straight to 'completed' instead.
 */
function releaseClaim(int $rowId): void {
    try {
        Database::update('stripepayment_sessions', ['status' => 'pending'], 'id = ?', [$rowId]);
    } catch (\Throwable $e) {
        slate_log("Stripe webhook: failed to release claim on row $rowId: " . $e->getMessage(), 'warning');
    }
}
