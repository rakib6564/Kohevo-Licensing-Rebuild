<?php
/**
 * Kohevo Studio — the public endpoint behind a Payment Button block.
 *
 * A visitor's click posts the button's own signed terms here (see src/Module/StudioBuilder/Payment/PaymentOffer.php),
 * plus the gateway they clicked (`gw`); a verified offer becomes a checkout at that gateway through the shared payment
 * layer, and the visitor is redirected to it (303). Payments are recorded by the gateway (source `studio-builder`), so
 * they appear under Payments → Transactions. Everything decided here is in PayRequest, which has its own tests; this file
 * only wires it to the platform.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
slate_public_entry('studio-builder');

use Slate\Module\StudioBuilder\Payment\LivePaymentBlocks;
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
        // The client address; a proxy's forwarding header counts only from STUDIO_TRUSTED_PROXIES (e.g. the CDN's ranges).
        'visitor'    => PayThrottle::visitor($_SERVER, (string) env('STUDIO_TRUSTED_PROXIES', '')),
        'configured' => class_exists('Payments') && Payments::canCharge('studio-builder'),
        'now'        => time(),
    ],
    static fn (string $context, string $value, string $candidate): bool => slate_sign_equals($context, $value, $candidate),
    static function (int $cents, string $currency, string $name, string $successUrl, string $cancelUrl, string $interval = '', string $gateway = ''): string {
        $gw = Payments::choose('studio-builder', $gateway, $interval !== '' ? ['hosted', 'recurring'] : ['hosted']);
        if ($gw === null) {
            throw new RuntimeException('No payment gateway can take this payment.');
        }
        return Payments::startCheckout($gw->id(), new CheckoutRequest(
            [['name' => $name, 'amount_cents' => $cents]],
            [
                'source_plugin' => 'studio-builder',
                'source_id'     => 'payment-button',
                'currency'      => strtolower($currency),
                'success_url'   => $successUrl,
                'cancel_url'    => $cancelUrl,
                'interval'      => $interval,
            ]
        ))->redirectUrl;
    },
    // The temp directory first, the app's own storage as a fallback; when neither can be written the start is refused.
    static fn (string $visitor): bool => (new PayThrottle(sys_get_temp_dir() . '/kohevo-studio-pay', $root . '/data/studio-pay'))->allow($visitor),
    static fn (string $blockId): ?array => LivePaymentBlocks::propsOf((int) current_tenant_id(), $blockId)
);

if ($result['status'] === 303 && isset($result['location'])) {
    header('Location: ' . $result['location'], true, 303);
    exit;
}

http_response_code($result['status']);
header('Content-Type: text/plain; charset=utf-8');
echo (string) ($result['message'] ?? 'Something went wrong.');
