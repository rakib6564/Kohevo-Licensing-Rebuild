<?php
/**
 * Return URL for the embedded Payment Element flow.
 *
 * Stripe's `stripe.confirmPayment({redirect: 'if_required'})` mostly
 * handles 3D Secure as a modal popup on the customer's current page.
 * But for some payment methods (notably certain bank-redirect 3DS
 * flows in Europe and some Indian card networks), Stripe MUST
 * redirect the customer's browser to an authorisation page. After
 * authorisation, the bank redirects back here with two query params:
 *
 *   ?payment_intent=pi_xxx
 *   &payment_intent_client_secret=pi_xxx_secret_yyy
 *
 * Our job: look up the mapping row by PaymentIntent id, check the
 * final status, then send the customer to either:
 *   - the order confirmation page (success), or
 *   - back to /shop/checkout with an error (failure / cancelled)
 *
 * The webhook (payment_intent.succeeded) is the source of truth for
 * order creation and can genuinely race with this file on the same row
 * (both look up by payment_intent_id). That race is closed by an atomic
 * conditional claim — see the "Atomically claim" comment below — using
 * the same status='claimed' mechanism webhook.php's completePayment()
 * and success.php use. If the webhook (or another return.php hit) wins
 * the claim first, we wait a moment and check again for its order_id,
 * then either use it or bounce to checkout — this is what this docblock
 * always claimed to do, now actually implemented.
 *
 * KNOWN RESIDUAL GAP (tracked separately, not closed by this fix):
 * StripePayment::handleEmbeddedCheckout() looks up this same row by
 * payment_intent_id but never reads status — it is blind to this claim,
 * exactly as this file was blind to webhook.php's claim before this fix.
 * A concurrent handleEmbeddedCheckout() call can still independently
 * reach ShopAPI::checkoutCart() regardless of what this file does. Closing
 * that requires also fixing handleEmbeddedCheckout() (tracked as its own
 * finding) with the same claim semantics.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

if (!PluginLoader::isActive('shop') || !class_exists('ShopAPI')) {
    http_response_code(503);
    echo 'Shop plugin not active.';
    exit;
}

require_once dirname(__DIR__) . '/StripeAPI.php';

$piId = (string)($_GET['payment_intent'] ?? '');
if ($piId === '') {
    header('Location: ' . SLATE_URL . '/shop/checkout?error=missing');
    exit;
}

// Look up our mapping row. If we've never seen this PI id, treat as
// a redirect from a forged/expired URL.
$row = Database::row(
    "SELECT id, shop_sid, order_id, billing_json, status
       FROM stripepayment_sessions
      WHERE payment_intent_id = ?",
    [$piId]
);
if (!$row) {
    slate_log("Stripe return.php: unknown payment_intent_id $piId", 'warning');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=missing');
    exit;
}

// Bind to the browser that created this PaymentIntent. The shop_sid
// cookie rides along on the bank's top-level redirect (SameSite=Lax),
// so a legitimate return matches. A request carrying a guessed or leaked
// pi_id from a different session must not create orders, clear another
// session's cart, or reveal someone else's confirmation page.
$browserSid = (string)($_COOKIE['shop_sid'] ?? '');
if ($browserSid === '' || !hash_equals((string)$row['shop_sid'], $browserSid)) {
    slate_log("Stripe return.php: shop_sid mismatch on $piId", 'warning');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=missing');
    exit;
}

// Already converted by the webhook or earlier visit? Just redirect.
if (!empty($row['order_id'])) {
    $order = ShopAPI::order((int)$row['order_id']);
    if ($order) {
        $key = !empty($order['view_token']) ? $order['view_token'] : $order['order_number'];
        header('Location: ' . SLATE_URL . '/shop/order?key=' . urlencode($key));
        exit;
    }
}

// Verify the PaymentIntent status with Stripe. If the bank redirect
// was a success, status is 'succeeded'. If the customer cancelled,
// status stays at 'requires_payment_method' or 'requires_action'.
try {
    $pi = StripeAPI::getPaymentIntent($piId);
} catch (\Throwable $e) {
    slate_log('Stripe return.php getPaymentIntent failed: ' . $e->getMessage(), 'error');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=payment_not_completed');
    exit;
}

$status = (string)($pi['status'] ?? '');
if ($status !== 'succeeded' && $status !== 'requires_capture') {
    // Payment didn't go through. Cart is still intact (we didn't
    // clear it yet); customer can retry.
    header('Location: ' . SLATE_URL . '/shop/checkout?error=payment_not_completed');
    exit;
}

// Payment succeeded server-side but webhook hasn't created the order
// yet. Create it ourselves — same idempotent path the webhook uses.
$billing = [];
if (!empty($row['billing_json'])) {
    $decoded = json_decode((string)$row['billing_json'], true);
    if (is_array($decoded)) $billing = $decoded;
}

// Fall back to Stripe's receipt_email if we have nothing else (rare —
// the customer normally filled out the Slate form first).
if (empty($billing['email']) && !empty($pi['receipt_email'])) {
    $billing['email'] = (string)$pi['receipt_email'];
}

if (empty($billing['email'])) {
    slate_log("Stripe return.php: no billing on $piId", 'error');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=order_failed');
    exit;
}

// Atomically claim this row before doing any work — closes the TOCTOU
// between the order_id check above and the mapping write below. Without
// this, a concurrent webhook.php delivery (or another return.php hit on
// the same session, e.g. a slow bank redirect retried) can pass the same
// order_id check and independently reach ShopAPI::checkoutCart(), creating
// a second real order; it has no de-duplication of its own. Same atomic
// conditional UPDATE and state name ('claimed') as webhook.php's
// completePayment() and success.php — see webhook.php for the full
// mechanics writeup. Does NOT protect against handleEmbeddedCheckout(),
// which never reads status — see the docblock's KNOWN RESIDUAL GAP note.
$claimed = Database::update(
    'stripepayment_sessions',
    ['status' => 'claimed'],
    'id = ? AND order_id IS NULL AND status != ?',
    [(int)$row['id'], 'claimed']
);

if ($claimed === 0) {
    // Someone else — webhook.php, or another return.php hit on the same
    // session — is either actively processing this row or has already
    // finished. Give the in-flight case a short, tightly bounded window to
    // land rather than immediately reporting failure for what may actually
    // be a success in progress.
    $winner = returnWaitForCompletion((int)$row['id']);
    if ($winner !== null) {
        $order = ShopAPI::order((int)$winner['order_id']);
        if ($order) {
            $key = !empty($order['view_token']) ? $order['view_token'] : $order['order_number'];
            header('Location: ' . SLATE_URL . '/shop/order?key=' . urlencode($key));
            exit;
        }
    }
    slate_log("Stripe return.php: claim unavailable for payment_intent $piId (row {$row['id']})", 'warning');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=order_failed');
    exit;
}

try {
    $res = ShopAPI::checkoutCart($row['shop_sid'], $billing);
} catch (\Throwable $e) {
    slate_log('Stripe return.php checkoutCart threw: ' . $e->getMessage(), 'error');
    returnReleaseClaim((int)$row['id']);
    header('Location: ' . SLATE_URL . '/shop/checkout?error=order_failed');
    exit;
}

if (empty($res['ok']) || empty($res['order_id'])) {
    slate_log('Stripe return.php order creation failed: ' . ($res['error'] ?? '?'), 'error');
    returnReleaseClaim((int)$row['id']);
    header('Location: ' . SLATE_URL . '/shop/checkout?error=order_failed');
    exit;
}

$orderId = (int)$res['order_id'];

// Reconcile the captured amount against the rebuilt order total; park
// on-hold on a mismatch instead of auto-fulfilling (mirrors success.php).
$paidCents    = (int)($pi['amount_received'] ?? $pi['amount'] ?? 0);
$paidCurrency = strtoupper((string)($pi['currency'] ?? ''));
$recon        = ShopAPI::reconcilePaidAmount($orderId, $paidCents, $paidCurrency);

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
    slate_log('Stripe return.php mapping update failed: ' . $e->getMessage(), 'warning');
}

$order = ShopAPI::order($orderId);
if (!$order) {
    header('Location: ' . SLATE_URL . '/shop/');
    exit;
}
$key = !empty($order['view_token']) ? $order['view_token'] : $order['order_number'];
header('Location: ' . SLATE_URL . '/shop/order?key=' . urlencode($key));
exit;

// ──────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────

/**
 * Best-effort release of the 'claimed' state back to 'pending' so a later
 * retry (a page reload, or the webhook) can attempt this row again. Called
 * only on a checkout failure path that already redirects to
 * ?error=order_failed — never on success, where the row moves straight to
 * 'completed' instead. Named distinctly from webhook.php's releaseClaim()
 * and success.php's successReleaseClaim(), since all three files are
 * separate top-level entry scripts; not shared, per the current scope (no
 * shared helper yet).
 */
function returnReleaseClaim(int $rowId): void {
    try {
        Database::update('stripepayment_sessions', ['status' => 'pending'], 'id = ?', [$rowId]);
    } catch (\Throwable $e) {
        slate_log("Stripe return.php: failed to release claim on row $rowId: " . $e->getMessage(), 'warning');
    }
}

/**
 * Short, tightly bounded wait for a concurrent completion (webhook.php, or
 * another return.php hit on the same session) to finish claiming this row,
 * used only when our own claim attempt was rejected. Not a polling loop or
 * a long-lived wait — a fixed handful of very short re-reads (5 x 100ms =
 * 500ms worst case, only on the rare collision path). Returns the row once
 * order_id is populated, or null if it never lands within the window.
 *
 * Does not, and cannot, observe a competing handleEmbeddedCheckout() call:
 * that function never claims the row (see the file docblock's KNOWN
 * RESIDUAL GAP note) — this wait only ever finds an order_id here if
 * webhook.php or another return.php hit wrote it via the claim protocol.
 */
function returnWaitForCompletion(int $rowId): ?array {
    for ($i = 0; $i < 5; $i++) {
        usleep(100000);
        $fresh = Database::row(
            "SELECT order_id FROM stripepayment_sessions WHERE id = ?",
            [$rowId]
        );
        if ($fresh && !empty($fresh['order_id'])) {
            return $fresh;
        }
    }
    return null;
}
