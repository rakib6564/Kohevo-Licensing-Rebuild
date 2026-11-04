<?php
/**
 * Slate — a checkout a gateway has created: where to send the customer, and
 * the gateway's own reference for it (Stripe session id, PayPal order id or
 * subscription id).
 */

declare(strict_types=1);

namespace Slate\Services\Payments;

final class CheckoutStart
{
    public function __construct(
        public readonly string $gateway,
        public readonly string $ref,
        public readonly string $redirectUrl,
    ) {
        if ($ref === '' || $redirectUrl === '') {
            throw new \RuntimeException("Gateway {$gateway} returned a checkout without a reference or redirect URL.");
        }
    }
}
