<?php
/**
 * PayPal Payment — webhook endpoint.
 *
 * Point a webhook in your PayPal REST app at this URL and save its id on
 * the PayPal settings page. Every delivery is verified with PayPal
 * (verify-webhook-signature, against that webhook id) before anything is
 * read from it. Verified payment events go to the shared layer
 * (Payments::emit → `payment_event`); the raw event is also offered to
 * listeners as `paypal_webhook_event`.
 *
 * Events used: CHECKOUT.ORDER.APPROVED (captures an order whose customer
 * never came back), PAYMENT.CAPTURE.COMPLETED / DENIED / DECLINED /
 * REFUNDED / REVERSED, BILLING.SUBSCRIPTION.ACTIVATED, PAYMENT.SALE.COMPLETED
 * / REFUNDED / REVERSED.
 *
 * A failure while recording answers 500, so PayPal retries the delivery.
 */
require_once dirname(__DIR__, 3) . '/config.php';
slate_public_entry('paypal-payment');
require_once dirname(__DIR__) . '/PayPalPaymentAPI.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$payload = (string) file_get_contents('php://input');
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_PAYPAL_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
    }
}

if (PayPalPaymentAPI::webhookId() === '') {
    PayPalPaymentAPI::recordWebhookHealth('no_webhook_id');
    http_response_code(400);
    echo 'webhook not configured';
    exit;
}
if (!PayPalPaymentAPI::verifyWebhook($payload, $headers)) {
    PayPalPaymentAPI::recordWebhookHealth('bad_signature');
    slate_log('PayPal webhook: verification failed', 'warning');
    http_response_code(400);
    echo 'bad signature';
    exit;
}

$event = json_decode($payload, true);
PayPalPaymentAPI::recordWebhookHealth('ok', (string) ($event['event_type'] ?? ''));

try {
    $normalized = Payments::gateway('paypal')?->fromWebhook($event);
    if ($normalized !== null) Payments::emit($normalized);
} catch (\Throwable $e) {
    slate_log('PayPal webhook: handling ' . ($event['event_type'] ?? '?') . ' failed: ' . $e->getMessage(), 'error');
    http_response_code(500);
    echo 'retry';
    exit;
}

try {
    Hook::doAction('paypal_webhook_event', $event);
} catch (\Throwable $e) {
    slate_log('PayPal webhook: a paypal_webhook_event listener failed: ' . $e->getMessage(), 'error');
}

http_response_code(200);
echo 'ok';
