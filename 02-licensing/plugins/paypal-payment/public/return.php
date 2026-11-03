<?php
/**
 * PayPal Payment — where PayPal sends the customer back.
 *
 * PayPal appends the order id (?token=) or, for a subscription,
 * ?subscription_id=. That id must name a checkout this site started
 * (payment_sessions); the payment is settled here — an approved order is
 * captured — and the customer is sent on to the URL the module gave when it
 * started the checkout. The module's own return page may confirm again;
 * a capture is idempotent, so that is safe.
 *
 * Nothing here trusts the query string beyond looking the id up: the
 * redirect target always comes from the database.
 */
require_once dirname(__DIR__, 3) . '/config.php';
slate_public_entry('paypal-payment');
require_once dirname(__DIR__) . '/PayPalPaymentAPI.php';

$ref = trim((string) ($_GET['subscription_id'] ?? ($_GET['token'] ?? '')));
$session = ($ref !== '' && preg_match('/^[A-Za-z0-9-]{6,64}$/', $ref)) ? Payments::session('paypal', $ref) : null;
if ($session === null) {
    header('Location: ' . SLATE_URL . '/', true, 303);
    exit;
}

if (!empty($_GET['cancel'])) {
    header('Location: ' . Payments::returnUrl($session, false), true, 303);
    exit;
}

$event = null;
try {
    $event = Payments::gateway('paypal')?->confirm($ref);
    if ($event !== null) $event = Payments::emit($event);
} catch (\Throwable $e) {
    // Leave it to the webhook (CHECKOUT.ORDER.APPROVED captures it too);
    // the module's page shows "processing" until then.
    slate_log('PayPal return: settling ' . $ref . ' failed: ' . $e->getMessage(), 'error');
}

$failed = $event !== null && $event->type === PaymentEvent::FAILED;
header('Location: ' . Payments::returnUrl($session, !$failed), true, 303);
exit;
