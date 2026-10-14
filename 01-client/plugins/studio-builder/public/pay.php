<?php
/**
 * Kohevo Studio — the public endpoint behind a Payment Button block.
 *
 * A visitor's click posts the button's own signed terms here (see src/Module/StudioBuilder/Payment/PaymentOffer.php); a
 * verified offer becomes a Stripe Checkout session made by the Stripe Payment plugin, and the visitor is redirected to it
 * (303). Successful payments are recorded by that plugin's webhook, source `studio-builder`, so they appear in its Charges
 * page. Everything decided here is in PayRequest, which has its own tests; this file only wires it to the platform.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
slate_public_entry('studio-builder');
slate_public_entry('stripe-payment');

use Slate\Module\StudioBuilder\Payment\PayRequest;
use Slate\Module\StudioBuilder\Payment\PayThrottle;

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$result = PayRequest::handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $_POST,
    [
        'tenantId'   => (int) current_tenant_id(),
        'siteUrl'    => (string) SLATE_URL,
        'referer'    => (string) ($_SERVER['HTTP_REFERER'] ?? ''),
        'visitor'    => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'configured' => class_exists('StripePaymentAPI') && StripePaymentAPI::isConfigured(),
    ],
    static fn (string $context, string $value, string $candidate): bool => slate_sign_equals($context, $value, $candidate),
    static function (int $cents, string $currency, string $name, string $successUrl, string $cancelUrl): string {
        $session = StripePaymentAPI::createCheckout(
            [['name' => $name, 'amount_cents' => $cents]],
            [
                'currency'    => strtolower($currency),
                'success_url' => $successUrl,
                'cancel_url'  => $cancelUrl,
                'metadata'    => ['source_plugin' => 'studio-builder', 'source_id' => 'payment-button'],
            ]
        );
        return (string) $session['url'];
    },
    static fn (string $visitor): bool => (new PayThrottle(sys_get_temp_dir() . '/kohevo-studio-pay'))->allow($visitor)
);

if ($result['status'] === 303 && isset($result['location'])) {
    header('Location: ' . $result['location'], true, 303);
    exit;
}

http_response_code($result['status']);
header('Content-Type: text/plain; charset=utf-8');
echo (string) ($result['message'] ?? 'Something went wrong.');
