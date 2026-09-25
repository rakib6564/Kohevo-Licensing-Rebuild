<?php
/**
 * Create a Stripe PaymentIntent for a pending booking's amount due,
 * returning the client_secret to the booking widget's embedded
 * Payment Element. The browser-side mirror of the Shop create-intent.php.
 *
 * Security model:
 *   - POST only — a GET would leak the client_secret via the Referer.
 *   - The pending appointment is identified by its 32-hex manage_token,
 *     the same unguessable secret used for self-service manage links. We
 *     never trust an appointment id from the body.
 *   - The intent is stamped with metadata.booking_appt_id so both the
 *     return page and the webhook can bind the payment to THIS booking.
 *
 * Returns JSON:
 *   { client_secret: "pi_xxx_secret_yyy", payment_intent_id: "pi_xxx" }
 * or
 *   { error: "..." }  with an appropriate HTTP status.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

header('Content-Type: application/json');
// Never cache the client_secret response — a cached copy would hand a stale
// PaymentIntent (and its payment-method set) to the embedded form.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function bpi_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if (!PluginLoader::isActive('booking')) {
    bpi_out(503, ['error' => 'Booking plugin not active.']);
}
require_once dirname(__DIR__) . '/BookingAPI.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bpi_out(405, ['error' => 'POST required.']);
}
if (!class_exists('StripePaymentAPI') || !StripePaymentAPI::isConfigured()) {
    bpi_out(503, ['error' => 'Online payment is not available right now.']);
}

// Token comes from the JSON body (fetch) or a form POST fallback.
$token = (string)($_POST['token'] ?? '');
if ($token === '') {
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) $token = (string)($j['token'] ?? '');
    }
}
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    bpi_out(400, ['error' => 'Invalid payment link.']);
}

$appt = BookingAPI::findByManageToken($token, false);
if (!$appt) {
    bpi_out(404, ['error' => 'Booking not found.']);
}
if (($appt['payment_status'] ?? '') === 'paid') {
    bpi_out(409, ['error' => 'This booking is already paid.']);
}

// Amount due: deposit when set, otherwise the full price + tax, less
// anything already collected. Mirrors BookingAPI::startPayment().
$due = (int)$appt['deposit_cents'] > 0
     ? (int)$appt['deposit_cents']
     : ((int)$appt['price_cents'] + (int)$appt['tax_cents']);
$due -= (int)($appt['paid_cents'] ?? 0);
if ($due <= 0) {
    bpi_out(400, ['error' => 'Nothing to pay.']);
}

$currency = strtolower((string)($appt['currency'] ?? 'usd')) ?: 'usd';

$opts = [
    'currency'      => $currency,
    'receipt_email' => (string)$appt['customer_email'],
    'metadata'      => [
        'source_plugin'    => 'booking',
        'booking_appt_id'  => (string)$appt['id'],
        'booking_token'    => $token,
    ],
];

// Build the explicit method list from the admin toggles rather than using
// automatic_payment_methods, so the merchant controls exactly what shows on
// the inline form. Card is always present; Apple/Google Pay are card-based
// wallets that ride on 'card' (toggled client-side). Link and the extra
// methods (Bank, Klarna, Cash App, Amazon Pay, Bancontact) are added only
// when enabled. Bancontact is EUR-only, so never send it to Stripe for a
// currency where it cannot be used.
$on = static fn(string $k, string $d = '0'): bool =>
    (Database::setting('stripe-payment.' . $k) ?? $d) !== '0';

$methods = ['card'];
if ($on('wallet_link', '1'))         $methods[] = 'link';
if ($on('method_us_bank_account'))   $methods[] = 'us_bank_account';
if ($on('method_klarna'))            $methods[] = 'klarna';
if ($on('method_cashapp'))           $methods[] = 'cashapp';
if ($on('method_amazon_pay'))        $methods[] = 'amazon_pay';
if ($currency === 'eur' && $on('method_bancontact')) $methods[] = 'bancontact';
$opts['payment_method_types'] = $methods;

try {
    $pi = StripePaymentAPI::createPaymentIntent($due, $opts);
} catch (\Throwable $e) {
    // A toggled method that isn't activated on the Stripe account (or isn't
    // valid for this amount/currency) makes Stripe reject the whole intent.
    // Degrade gracefully to card-only rather than blocking the payment.
    if (count($methods) > 1) {
        slate_log('Booking pay-intent: method set [' . implode(',', $methods)
            . '] rejected (' . $e->getMessage() . ') — retrying card-only.', 'warning');
        $opts['payment_method_types'] = ['card'];
        try {
            $pi = StripePaymentAPI::createPaymentIntent($due, $opts);
        } catch (\Throwable $e2) {
            slate_log('Booking pay-intent: card-only retry failed: ' . $e2->getMessage(), 'error');
            bpi_out(502, ['error' => 'Could not initialise payment.']);
        }
    } else {
        slate_log('Booking pay-intent: createPaymentIntent failed: ' . $e->getMessage(), 'error');
        bpi_out(502, ['error' => 'Could not initialise payment.']);
    }
}

bpi_out(200, [
    'client_secret'     => (string)$pi['client_secret'],
    'payment_intent_id' => (string)$pi['id'],
]);
