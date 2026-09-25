<?php
/**
 * Stripe checkout success endpoint.
 *
 * Flow:
 *   1. Customer pays on stripe.com's hosted Checkout page.
 *   2. Stripe redirects them back to:
 *        /plugins/stripe-payment/public/success.php
 *          ?session_id=cs_test_...
 *          &sid=<shop_sid>
 *   3. We look up the session in stripepayment_sessions to find the cart.
 *   4. If an order has already been created for this session (by an
 *      earlier visit, or by the webhook getting there first), redirect
 *      to its confirmation page. We never create a duplicate.
 *   5. Otherwise we verify payment status with Stripe, create the order,
 *      mark the mapping completed, clear the cart, redirect.
 *
 * The webhook (webhook.php) does the same thing server-to-server, and can
 * genuinely race with this file on the same row (both look up by
 * stripe_session_id for the hosted flow). That race is closed by an atomic
 * conditional claim — see the "Atomically claim" comment below — using the
 * same status='claimed' mechanism webhook.php's completePayment() uses.
 * (The embedded flow's return.php / handleEmbeddedCheckout() cannot reach
 * this same row: hosted rows always have payment_intent_id NULL and
 * embedded rows always have stripe_session_id NULL, so they never overlap
 * with this lookup.)
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

if (!PluginLoader::isActive('shop') || !class_exists('ShopAPI')) {
    http_response_code(503);
    echo 'Shop plugin not active.';
    exit;
}

require_once dirname(__DIR__) . '/StripeAPI.php';

$sessionId = (string)($_GET['session_id'] ?? '');
$sid       = (string)($_GET['sid'] ?? '');

if ($sessionId === '' || $sid === '') {
    header('Location: ' . SLATE_URL . '/shop/checkout?error=missing');
    exit;
}

// Look up the mapping row we wrote when the session was created.
$row = Database::row(
    "SELECT id, shop_sid, order_id, status, billing_json
       FROM stripepayment_sessions
      WHERE stripe_session_id = ?",
    [$sessionId]
);

if (!$row) {
    // Session id we've never seen — either expired DB, or someone
    // manipulating the query string. Don't create anything; bounce home.
    slate_log("Stripe success.php: unknown session_id $sessionId", 'warning');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=missing');
    exit;
}

// Bind to the browser that actually ran this checkout — same invariant
// return.php and handleEmbeddedCheckout() already enforce for their own
// redirect-back paths. The `sid` query parameter is NOT sufficient on its
// own here: Stripe echoes it straight back from the success_url we gave it
// at session-creation time, so a full success.php URL leaked via Referer,
// browser history, or a shared screenshot already carries a "matching" sid
// alongside the session_id — checking $_GET['sid'] against itself would
// prove nothing. The shop_sid COOKIE, set on this browser when its cart was
// created (SameSite=Lax, so it does ride along on Stripe's top-level
// redirect back here), is not part of that leaked artifact; only the
// browser that actually owns this checkout still has it.
$browserSid = (string)($_COOKIE['shop_sid'] ?? '');
if ($browserSid === '' || !hash_equals((string)$row['shop_sid'], $browserSid)) {
    slate_log("Stripe success.php: shop_sid mismatch on $sessionId", 'warning');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=missing');
    exit;
}

// Already converted to an order? Just redirect to its confirmation page.
if (!empty($row['order_id'])) {
    $order = ShopAPI::order((int)$row['order_id']);
    if ($order) {
        $key = !empty($order['view_token']) ? $order['view_token'] : $order['order_number'];
        header('Location: ' . SLATE_URL . '/shop/order?key=' . urlencode($key));
        exit;
    }
    // Mapping says we have an order_id, but it's gone — shouldn't happen,
    // but if it does treat as fresh and try again rather than failing.
}

// Verify payment with Stripe. The webhook would do the same check; we
// repeat it here because we can't trust query-string params alone.
$stripeSession = StripeAPI::getSession($sessionId);
if (!$stripeSession) {
    header('Location: ' . SLATE_URL . '/shop/checkout?error=payment_not_completed');
    exit;
}

// 'paid' is what we want for one-shot payments. Stripe also has
// 'no_payment_required' for $0 orders and 'unpaid' for failed/aborted.
$payStatus = (string)($stripeSession['payment_status'] ?? '');
if ($payStatus !== 'paid' && $payStatus !== 'no_payment_required') {
    slate_log("Stripe success.php: payment_status=$payStatus for $sessionId", 'warning');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=payment_not_completed');
    exit;
}

// Create the order. We prefer the billing details stashed at session-
// creation time over what Stripe collected, since the customer filled
// out the Slate checkout form first; Stripe's may be incomplete.
$billing = [];
if (!empty($row['billing_json'])) {
    $decoded = json_decode((string)$row['billing_json'], true);
    if (is_array($decoded)) $billing = $decoded;
}

// Fall back to Stripe's customer_details where our billing is empty.
$cd = $stripeSession['customer_details'] ?? [];
$cdAddr = $cd['address'] ?? [];

$fallback = [
    'email'      => $cd['email']        ?? '',
    'first_name' => '',
    'last_name'  => '',
    'address_1'  => $cdAddr['line1']    ?? '',
    'address_2'  => $cdAddr['line2']    ?? '',
    'city'       => $cdAddr['city']     ?? '',
    'state'      => $cdAddr['state']    ?? '',
    'postcode'   => $cdAddr['postal_code'] ?? '',
    'country'    => $cdAddr['country']  ?? '',
    'notes'      => '',
];
if (!empty($cd['name'])) {
    // Stripe gives one combined name field; split naively
    $parts = preg_split('/\s+/', trim((string)$cd['name']), 2);
    $fallback['first_name'] = $parts[0] ?? '';
    $fallback['last_name']  = $parts[1] ?? '';
}
foreach ($fallback as $k => $v) {
    if (empty($billing[$k])) $billing[$k] = $v;
}

// Atomically claim this row before doing any work — closes the TOCTOU
// between the order_id check above and the mapping write below. Without
// this, a concurrent webhook.php delivery (or another success.php hit on
// the same session, e.g. a reload or double-click) can pass the same
// order_id check and independently reach ShopAPI::checkoutCart(), creating
// a second real order; it has no de-duplication of its own. Same atomic
// conditional UPDATE and state name ('claimed') as webhook.php's
// completePayment() — see that file for the full mechanics writeup. A
// single conditional UPDATE is atomic per-statement under InnoDB row
// locking, reproduced live against this exact row/query shape.
$claimed = Database::update(
    'stripepayment_sessions',
    ['status' => 'claimed'],
    'id = ? AND order_id IS NULL AND status != ?',
    [(int)$row['id'], 'claimed']
);

if ($claimed === 0) {
    // Someone else — webhook.php, or another success.php hit on the same
    // session — is either actively processing this row or has already
    // finished. Give the in-flight case a short, tightly bounded window to
    // land (webhook delivery is normally near-instant server-to-server)
    // rather than immediately reporting failure for what may actually be a
    // success in progress. If order_id lands during the window we send the
    // customer to that order instead of duplicating the work ourselves.
    $winner = successWaitForCompletion((int)$row['id']);
    if ($winner !== null) {
        $order = ShopAPI::order((int)$winner['order_id']);
        if ($order) {
            $key = !empty($order['view_token']) ? $order['view_token'] : $order['order_number'];
            header('Location: ' . SLATE_URL . '/shop/order?key=' . urlencode($key));
            exit;
        }
    }
    slate_log("Stripe success.php: claim unavailable for session $sessionId (row {$row['id']})", 'warning');
    header('Location: ' . SLATE_URL . '/shop/checkout?error=order_failed');
    exit;
}

// Create the order via Shop. checkoutCart clears the cart on success.
try {
    $res = ShopAPI::checkoutCart($row['shop_sid'], $billing);
} catch (\Throwable $e) {
    slate_log('Stripe success.php checkoutCart threw: ' . $e->getMessage(), 'error');
    successReleaseClaim((int)$row['id']);
    header('Location: ' . SLATE_URL . '/shop/checkout?error=order_failed');
    exit;
}

if (empty($res['ok']) || empty($res['order_id'])) {
    slate_log('Stripe success.php order creation failed: ' .
        ($res['error'] ?? '?'), 'error');
    successReleaseClaim((int)$row['id']);
    header('Location: ' . SLATE_URL . '/shop/checkout?error=order_failed');
    exit;
}

$orderId = (int)$res['order_id'];

// Reconcile what Stripe actually charged against the order we just rebuilt
// from the cart. If the cart drifted between intent creation and payment,
// hold the order for review instead of auto-fulfilling a wrong total.
$paidCents    = (int)($stripeSession['amount_total'] ?? 0);
$paidCurrency = strtoupper((string)($stripeSession['currency'] ?? ''));
$recon        = ShopAPI::reconcilePaidAmount($orderId, $paidCents, $paidCurrency);

// Stamp the order_id back onto the mapping row + mark the order as paid.
// The atomic claim above already prevents a concurrent webhook.php (or
// another success.php hit) from reaching this point for the same row —
// no transaction is needed here, and none was actually in effect before
// this fix despite what an earlier version of this comment claimed.
try {
    Database::update('stripepayment_sessions', [
        'order_id'     => $orderId,
        'status'       => 'completed',
        'completed_at' => slate_db_now(),
    ], 'id = ?', [(int)$row['id']]);

    // Bump to 'processing' (paid + ready to fulfil) only when the charge
    // matches. On a mismatch reconcilePaidAmount() already set 'on-hold'.
    if (!empty($recon['ok'])) {
        ShopAPI::updateStatus($orderId, 'processing');
    }
} catch (\Throwable $e) {
    // The order exists — just log and continue to the confirmation
    // page. The webhook can heal the mapping if it gets there.
    slate_log('Stripe success.php mapping update failed: ' . $e->getMessage(), 'warning');
}

$order = ShopAPI::order($orderId);
if (!$order) {
    // Pathological: we just created it. Send to a generic landing.
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
 * 'completed' instead. Named distinctly from webhook.php's own
 * releaseClaim() since both files are separate top-level entry scripts;
 * not shared, per the current scope (no shared helper yet).
 */
function successReleaseClaim(int $rowId): void {
    try {
        Database::update('stripepayment_sessions', ['status' => 'pending'], 'id = ?', [$rowId]);
    } catch (\Throwable $e) {
        slate_log("Stripe success.php: failed to release claim on row $rowId: " . $e->getMessage(), 'warning');
    }
}

/**
 * Short, tightly bounded wait for a concurrent completion (webhook.php, or
 * another success.php hit on the same session) to finish claiming this row,
 * used only when our own claim attempt was rejected. Not a polling loop or
 * a long-lived wait — a fixed handful of very short re-reads (5 x 100ms =
 * 500ms worst case, only on the rare collision path). Returns the row once
 * order_id is populated, or null if it never lands within the window.
 */
function successWaitForCompletion(int $rowId): ?array {
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
