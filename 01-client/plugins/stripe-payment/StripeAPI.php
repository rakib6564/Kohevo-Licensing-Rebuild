<?php
/**
 * StripeAPI — Shop-specific wrapper around StripePaymentAPI.
 *
 * As of Phase 2 (plugin v1.2.0) the generic Stripe transport lives in
 * `StripePaymentAPI`. This class is kept as a thin Shop-focused
 * convenience layer:
 *
 *   - It still loads cart totals from ShopAPI and builds Stripe
 *     line_items for the Checkout Session / PaymentIntent flow.
 *   - Mode / key resolution / HTTP transport / webhook signature
 *     verification all delegate to StripePaymentAPI so we don't keep
 *     two copies of cURL + HMAC logic in sync.
 *
 * Anywhere in plugin code that used `StripeAPI::secretKey()` etc.
 * continues to work. New plugins should use `StripePaymentAPI`
 * directly for their own checkout flows.
 */

require_once __DIR__ . '/StripePaymentAPI.php';

class StripeAPI {

    public static function secretKey(): string      { return StripePaymentAPI::secretKey(); }
    public static function publishableKey(): string { return StripePaymentAPI::publishableKey(); }
    public static function webhookSecret(): string  { return StripePaymentAPI::webhookSecret(); }
    public static function mode(): string           { return StripePaymentAPI::mode(); }
    public static function isConfigured(): bool     { return StripePaymentAPI::isConfigured(); }

    /**
     * Create a Stripe Checkout Session over a Shop cart sid.
     *
     * Returns ['id' => 'cs_test_...', 'url' => 'https://...']. Throws
     * on transport / API errors.
     *
     * $opts forwarded to StripePaymentAPI::createCheckout — typically:
     *   - customer_email : prefill on Stripe's page
     *   - metadata       : key=>string array
     */
    public static function createCheckoutSession(
        string $sid,
        string $successUrl,
        string $cancelUrl,
        array $opts = []
    ): array {
        $cart = ShopAPI::cartTotals($sid);
        if (empty($cart['items'])) throw new \RuntimeException('Cart is empty.');

        $currency    = strtolower((string)($cart['currency'] ?? Database::setting('shop.currency') ?? 'usd'));
        $zeroDecimal = self::zeroDecimalCurrencies();
        $mult        = in_array($currency, $zeroDecimal, true) ? 1 : 100;

        $lineItems = [];
        foreach ($cart['items'] as $item) {
            $name = (string)($item['product_name'] ?? 'Product');
            if (!empty($item['variant_value'])) $name .= ' (' . $item['variant_value'] . ')';
            $lineItems[] = [
                'name'         => $name,
                'amount_cents' => (int) round(((float)$item['unit_price']) * $mult),
                'quantity'     => max(1, (int)$item['qty']),
            ];
        }
        if (!empty($cart['shipping']) && (float)$cart['shipping'] > 0) {
            $lineItems[] = [
                'name'         => 'Shipping',
                'amount_cents' => (int) round(((float)$cart['shipping']) * $mult),
                'quantity'     => 1,
            ];
        }
        if (!empty($cart['tax']) && (float)$cart['tax'] > 0) {
            $lineItems[] = [
                'name'         => 'Tax',
                'amount_cents' => (int) round(((float)$cart['tax']) * $mult),
                'quantity'     => 1,
            ];
        }

        $metadata = $opts['metadata'] ?? [];
        if (!isset($metadata['source_plugin'])) $metadata['source_plugin'] = 'shop';
        if (!isset($metadata['cart_sid']))      $metadata['cart_sid']      = $sid;

        $result = StripePaymentAPI::createCheckout($lineItems, [
            'currency'                 => $currency,
            'success_url'              => $successUrl,
            'cancel_url'               => $cancelUrl,
            'customer_email'           => $opts['customer_email'] ?? null,
            'metadata'                 => $metadata,
            'collect_shipping_address' => true,
        ]);

        return ['id' => $result['session_id'], 'url' => $result['url']];
    }

    /**
     * Create a PaymentIntent over the current Shop cart.
     * Returns the raw Stripe response.
     */
    public static function createPaymentIntent(string $sid, array $opts = []): array {
        $cart = ShopAPI::cartTotals($sid);
        if (empty($cart['items'])) throw new \RuntimeException('Cart is empty.');

        $currency    = strtolower((string)($cart['currency'] ?? Database::setting('shop.currency') ?? 'usd'));
        $zeroDecimal = self::zeroDecimalCurrencies();
        $mult        = in_array($currency, $zeroDecimal, true) ? 1 : 100;
        $amount      = (int) round(((float)$cart['total']) * $mult);
        if ($amount <= 0) throw new \RuntimeException('Cart total must be positive.');

        $metadata = $opts['metadata'] ?? [];
        if (!isset($metadata['source_plugin'])) $metadata['source_plugin'] = 'shop';
        if (!isset($metadata['cart_sid']))      $metadata['cart_sid']      = $sid;

        return StripePaymentAPI::createPaymentIntent($amount, [
            'currency'      => $currency,
            'receipt_email' => $opts['receipt_email'] ?? null,
            'metadata'      => $metadata,
        ]);
    }

    public static function updatePaymentIntent(string $piId, array $params): array {
        $id = trim($piId);
        if ($id === '') throw new \RuntimeException('Empty PaymentIntent id.');
        return StripePaymentAPI::httpRequest('POST', '/v1/payment_intents/' . urlencode($id), $params);
    }

    public static function getPaymentIntent(string $piId): array {
        $id = trim($piId);
        if ($id === '') throw new \RuntimeException('Empty PaymentIntent id.');
        $row = StripePaymentAPI::getPaymentIntent($id);
        if ($row === null) throw new \RuntimeException('PaymentIntent not found or fetch failed.');
        return $row;
    }

    public static function getSession(string $sessionId): ?array {
        return StripePaymentAPI::getSession($sessionId);
    }

    public static function verifyWebhookSignature(
        string $payload, string $sigHeader, string $secret, int $tolerance = 300
    ): bool {
        return StripePaymentAPI::verifyWebhookSignature($payload, $sigHeader, $secret, $tolerance);
    }

    private static function zeroDecimalCurrencies(): array {
        return [
            'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
            'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
        ];
    }
}
