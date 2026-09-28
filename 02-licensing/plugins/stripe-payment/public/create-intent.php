<?php
/**
 * Create (or reuse) a Stripe PaymentIntent for the current cart,
 * returning the client_secret to the browser. Called by the embedded
 * Payment Element's JS during checkout-page render.
 *
 * Security:
 *   - Same-origin only (caller is our own checkout page's JS, fetch()
 *     with credentials: 'same-origin' which carries cookies).
 *   - Identifies the cart by the shop_sid cookie that the Shop plugin
 *     sets — same trust model as the rest of the storefront.
 *   - Idempotency: if there's already a pending mapping row with a PI
 *     id for this shop_sid, we reuse it (calling
 *     stripe.updatePaymentIntent to refresh the amount if the cart
 *     total changed since). This avoids racking up dozens of intents
 *     when the customer reloads the checkout page or toggles between
 *     payment methods.
 *
 * Returns JSON:
 *   { client_secret: "pi_xxx_secret_yyy", payment_intent_id: "pi_xxx" }
 * or
 *   { error: "...", status: <http_code> }
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

header('Content-Type: application/json');

function out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if (!PluginLoader::isActive('shop') || !class_exists('ShopAPI')) {
    out(503, ['error' => 'Shop plugin not active.']);
}
require_once dirname(__DIR__) . '/StripeAPI.php';

// Only POST; GET would leak the client_secret into the Referer header.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(405, ['error' => 'POST required.']);
}

if (!StripeAPI::isConfigured()) {
    out(503, ['error' => 'Stripe is not configured.']);
}

// Identify the cart via the shop_sid cookie. We don't accept the sid
// in the request body — that would let any attacker mint an intent
// against any cart sid they can guess.
require_once SLATE_ROOT . '/plugins/shop/storefront/includes/layout.php';
$sid = sf_session_id();

$cart = ShopAPI::cartTotals($sid);
if (empty($cart['items'])) {
    out(400, ['error' => 'Your cart is empty.']);
}

$total = (float)($cart['total'] ?? 0);
if ($total <= 0) {
    out(400, ['error' => 'Cart total must be positive.']);
}

$currency = strtolower((string)($cart['currency']
    ?? Database::setting('shop.currency') ?? 'usd'));

// Reuse path: look for a pending row for this shop_sid that already
// has a PaymentIntent.
$existing = Database::row(
    "SELECT id, payment_intent_id
       FROM stripepayment_sessions
      WHERE shop_sid = ?
        AND flow = 'embedded'
        AND status = 'pending'
        AND order_id IS NULL
        AND payment_intent_id IS NOT NULL
      ORDER BY id DESC
      LIMIT 1",
    [$sid]
);

if ($existing) {
    // Refresh the intent's amount in case the cart changed (qty bumps,
    // coupon applied, etc) since we created it.
    try {
        // Recompute amount in smallest unit
        $zeroDecimal = [
            'bif','clp','djf','gnf','jpy','kmf','krw','mga',
            'pyg','rwf','ugx','vnd','vuv','xaf','xof','xpf',
        ];
        $mult   = in_array($currency, $zeroDecimal, true) ? 1 : 100;
        $amount = (int)round($total * $mult);

        $pi = StripeAPI::updatePaymentIntent($existing['payment_intent_id'], [
            'amount' => $amount,
        ]);
    } catch (\Throwable $e) {
        // Intent might be cancelled/expired on Stripe's side — fall
        // through to create a fresh one. Log and clear the mapping row.
        slate_log('Stripe updatePaymentIntent failed: ' . $e->getMessage()
            . ' — creating fresh intent.', 'warning');
        try {
            Database::update('stripepayment_sessions',
                ['status' => 'abandoned'],
                'id = ?', [(int)$existing['id']]);
        } catch (\Throwable $_) { /* ignore */ }
        $existing = null;
    }
    if ($existing) {
        out(200, [
            'client_secret'     => (string)$pi['client_secret'],
            'payment_intent_id' => (string)$pi['id'],
        ]);
    }
}

// Fresh-create path.
try {
    $pi = StripeAPI::createPaymentIntent($sid, [
        'metadata' => [
            'shop_sid' => $sid,
            'flow'     => 'embedded',
        ],
    ]);
} catch (\Throwable $e) {
    slate_log('Stripe createPaymentIntent failed: ' . $e->getMessage(), 'error');
    out(502, ['error' => 'Could not initialise payment.']);
}

try {
    Database::insert('stripepayment_sessions', [
        'stripe_session_id' => null,
        'payment_intent_id' => (string)$pi['id'],
        'shop_sid'          => $sid,
        'flow'              => 'embedded',
        'status'            => 'pending',
        'billing_json'      => null,   // filled in at place_order time
        'created_at'        => slate_db_now(),
    ]);
} catch (\Throwable $e) {
    // Don't return the intent to the client if we couldn't record it —
    // we'd never be able to map back when payment completes.
    slate_log('Stripe embedded intent mapping insert failed: ' . $e->getMessage(), 'error');
    out(500, ['error' => 'Could not record payment session.']);
}

out(200, [
    'client_secret'     => (string)$pi['client_secret'],
    'payment_intent_id' => (string)$pi['id'],
]);
