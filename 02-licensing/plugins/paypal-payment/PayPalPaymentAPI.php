<?php
/**
 * PayPal Payment — REST client (Orders v2, Payments v2, Subscriptions v1,
 * webhook verification).
 *
 * Plugins do not call this class: they use the shared Payments API, and
 * PayPalGateway turns those calls into the requests below.
 *
 * Settings (all paypal-payment.*):
 *   mode                       'sandbox' | 'live'
 *   {mode}_client_id           REST app client id (public)
 *   {mode}_client_secret       REST app secret — stored as an enc:v1 envelope
 *   {mode}_webhook_id          id of the webhook created in the PayPal app
 *   {mode}_token / _token_exp  cached OAuth access token (envelope) and its expiry
 *   {mode}_product_id          catalog product the subscription plans hang off
 *   plan.{mode}.{cur}.{amount}.{interval}   cached billing plan ids
 *
 * Amounts in and out are integer minor units, like the rest of the platform.
 */

use Slate\Services\Payments\Payments;

final class PayPalPaymentAPI
{
    private const LIVE_BASE    = 'https://api-m.paypal.com';
    private const SANDBOX_BASE = 'https://api-m.sandbox.paypal.com';

    /** PayPal refuses decimals for these, even where ISO has a minor unit. */
    private const NO_DECIMALS = ['HUF', 'JPY', 'TWD'];

    // ── Mode + credentials ───────────────────────────────────

    /** 'sandbox' or 'live' — PayPal's own word for test mode. */
    public static function mode(): string
    {
        return Database::setting('paypal-payment.mode') === 'live' ? 'live' : 'sandbox';
    }

    /** The ledger's word for the mode: 'test' or 'live'. */
    public static function ledgerMode(): string
    {
        return self::mode() === 'live' ? 'live' : 'test';
    }

    public static function clientId(): string
    {
        return trim((string) Database::setting('paypal-payment.' . self::mode() . '_client_id'));
    }

    public static function clientSecret(): string
    {
        $raw = (string) Database::setting('paypal-payment.' . self::mode() . '_client_secret');
        return $raw === '' ? '' : (string) (slate_decrypt_secret($raw) ?? '');
    }

    public static function webhookId(): string
    {
        return trim((string) Database::setting('paypal-payment.' . self::mode() . '_webhook_id'));
    }

    public static function isConfigured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    /**
     * api-m.paypal.com / api-m.sandbox.paypal.com, or a local stand-in named
     * by PAYPAL_API_BASE. Only a plain-http loopback address is honoured, so
     * the setting can never send credentials or payments to another host.
     */
    public static function apiBase(): string
    {
        $override = function_exists('env') ? trim((string) env('PAYPAL_API_BASE', '')) : '';
        if ($override !== '' && preg_match('#^http://(127\.0\.0\.1|localhost)(:\d{1,5})?$#', $override) === 1) {
            return $override;
        }
        return (self::$checkMode ?? self::mode()) === 'live' ? self::LIVE_BASE : self::SANDBOX_BASE;
    }

    /**
     * The mode a credentials check runs in, for this request only. Checking
     * typed keys never switches the stored mode, so checkouts and webhooks
     * running meanwhile keep using the site's own mode.
     */
    private static ?string $checkMode = null;

    // ── Money ────────────────────────────────────────────────

    /** Minor units → the decimal string PayPal expects for $currency. */
    public static function value(int $minor, string $currency): string
    {
        $cur = strtoupper($currency);
        if (in_array($cur, self::NO_DECIMALS, true) && Payments::decimals($cur) === 2) {
            // Stored in cents, but PayPal wants whole units (HUF, TWD).
            return (string) intdiv($minor, 100);
        }
        return Payments::toMajor($minor, $cur);
    }

    /** PayPal's decimal string → minor units. */
    public static function minor(string $value, string $currency): int
    {
        return Payments::toMinor($value, strtoupper($currency));
    }

    /** Whether $minor can be sent to PayPal without losing an amount (HUF/TWD must be whole). */
    public static function representable(int $minor, string $currency): bool
    {
        $cur = strtoupper($currency);
        return !(in_array($cur, self::NO_DECIMALS, true) && Payments::decimals($cur) === 2 && $minor % 100 !== 0);
    }

    // ── OAuth ────────────────────────────────────────────────

    /**
     * A bearer token, from the cache while it has more than a minute left.
     * $clientId/$secret override the stored credentials (settings "Test
     * connection" checks typed keys before saving them).
     */
    public static function accessToken(bool $fresh = false, ?string $clientId = null, ?string $secret = null, ?string $checkMode = null): string
    {
        $mode     = self::mode();
        $override = $clientId !== null;
        if ($override && $checkMode !== null) {
            self::$checkMode = $checkMode === 'live' ? 'live' : 'sandbox';
            try {
                return self::accessToken(true, $clientId, $secret);
            } finally {
                self::$checkMode = null;
            }
        }
        $clientId = $clientId ?? self::clientId();
        $secret   = $secret ?? self::clientSecret();
        if ($clientId === '' || $secret === '') throw new \RuntimeException('PayPal API credentials are not configured.');

        if (!$fresh && !$override) {
            $exp = (int) Database::setting("paypal-payment.{$mode}_token_exp");
            $tok = (string) Database::setting("paypal-payment.{$mode}_token");
            if ($tok !== '' && $exp - 60 > slate_db_time()) {
                $plain = slate_decrypt_secret($tok);
                if ($plain) return $plain;
            }
        }

        $resp = self::send('POST', '/v1/oauth2/token', 'grant_type=client_credentials', [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $secret),
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        $token = (string) ($resp['access_token'] ?? '');
        if ($token === '') throw new \RuntimeException('PayPal returned no access token.');

        if (!$override && slate_has_app_secret()) {
            Database::setSetting("paypal-payment.{$mode}_token", slate_encrypt_secret($token));
            Database::setSetting("paypal-payment.{$mode}_token_exp", (string) (slate_db_time() + (int) ($resp['expires_in'] ?? 300)));
        }
        return $token;
    }

    /** Forget the cached token (credentials changed). */
    public static function forgetToken(): void
    {
        foreach (['sandbox', 'live'] as $m) {
            Database::setSetting("paypal-payment.{$m}_token", '');
            Database::setSetting("paypal-payment.{$m}_token_exp", '0');
        }
    }

    // ── Orders ───────────────────────────────────────────────

    /**
     * Create an order for immediate capture. Returns the order (id, status,
     * links). $requestId makes a retried create return the same order.
     */
    public static function createOrder(array $body, string $requestId = ''): array
    {
        return self::request('POST', '/v2/checkout/orders', $body, $requestId);
    }

    public static function getOrder(string $id): ?array
    {
        try {
            return self::request('GET', '/v2/checkout/orders/' . rawurlencode($id));
        } catch (\Throwable $e) {
            slate_log('PayPal getOrder failed: ' . $e->getMessage(), 'warning');
            return null;
        }
    }

    /**
     * Capture an approved order. An order that was already captured (the
     * webhook and the return page both try) answers with the order as it
     * now stands instead of an error.
     */
    public static function captureOrder(string $id): array
    {
        try {
            return self::request('POST', '/v2/checkout/orders/' . rawurlencode($id) . '/capture', new \stdClass(), 'capture-' . $id);
        } catch (PayPalApiError $e) {
            if (in_array($e->issue, ['ORDER_ALREADY_CAPTURED', 'ORDER_NOT_APPROVED'], true)) {
                $order = self::getOrder($id);
                if ($order !== null) return $order;
            }
            throw $e;
        }
    }

    public static function refundCapture(string $captureId, ?int $amountMinor, string $currency, string $requestId = ''): array
    {
        $body = $amountMinor === null ? new \stdClass()
            : ['amount' => ['value' => self::value($amountMinor, $currency), 'currency_code' => strtoupper($currency)]];
        return self::request('POST', '/v2/payments/captures/' . rawurlencode($captureId) . '/refund', $body, $requestId);
    }

    /** Subscription payments are "sales" with their own refund endpoint. */
    public static function refundSale(string $saleId, ?int $amountMinor, string $currency, string $requestId = ''): array
    {
        $body = $amountMinor === null ? new \stdClass()
            : ['amount' => ['total' => self::value($amountMinor, $currency), 'currency' => strtoupper($currency)]];
        return self::request('POST', '/v1/payments/sale/' . rawurlencode($saleId) . '/refund', $body, $requestId);
    }

    // ── Subscriptions ────────────────────────────────────────

    /**
     * The billing plan for "$amount $currency every $interval", created the
     * first time it is needed (with this site's catalog product) and reused.
     */
    public static function planFor(int $amountMinor, string $currency, string $interval, string $name): string
    {
        $mode = self::mode();
        $cur  = strtoupper($currency);
        $key  = "paypal-payment.plan.{$mode}.{$cur}.{$amountMinor}.{$interval}";
        $planId = (string) Database::setting($key);
        if ($planId !== '') return $planId;

        $productKey = "paypal-payment.{$mode}_product_id";
        $productId  = (string) Database::setting($productKey);
        if ($productId === '') {
            $site = trim((string) Database::setting('site_name')) ?: 'Website';
            $product = self::request('POST', '/v1/catalogs/products', [
                'name' => mb_substr($site . ' payments', 0, 127),
                'type' => 'SERVICE',
            ], 'product-' . $mode . '-' . current_tenant_id());
            $productId = (string) ($product['id'] ?? '');
            if ($productId === '') throw new \RuntimeException('PayPal returned no product id.');
            Database::setSetting($productKey, $productId);
        }

        $plan = self::request('POST', '/v1/billing/plans', [
            'product_id'     => $productId,
            'name'           => mb_substr($name, 0, 127),
            'status'         => 'ACTIVE',
            'billing_cycles' => [[
                'frequency'      => ['interval_unit' => $interval === 'year' ? 'YEAR' : 'MONTH', 'interval_count' => 1],
                'tenure_type'    => 'REGULAR',
                'sequence'       => 1,
                'total_cycles'   => 0,
                'pricing_scheme' => ['fixed_price' => ['value' => self::value($amountMinor, $cur), 'currency_code' => $cur]],
            ]],
            'payment_preferences' => ['auto_bill_outstanding' => true, 'payment_failure_threshold' => 3],
        ], 'plan-' . md5($key . '-' . current_tenant_id()));
        $planId = (string) ($plan['id'] ?? '');
        if ($planId === '') throw new \RuntimeException('PayPal returned no plan id.');
        Database::setSetting($key, $planId);
        return $planId;
    }

    public static function createSubscription(array $body, string $requestId = ''): array
    {
        return self::request('POST', '/v1/billing/subscriptions', $body, $requestId);
    }

    public static function getSubscription(string $id): ?array
    {
        try {
            return self::request('GET', '/v1/billing/subscriptions/' . rawurlencode($id));
        } catch (\Throwable $e) {
            slate_log('PayPal getSubscription failed: ' . $e->getMessage(), 'warning');
            return null;
        }
    }

    /** The link a customer is sent to, from a PayPal object's HATEOAS links. */
    public static function link(array $object, array $rels = ['payer-action', 'approve']): string
    {
        foreach ($rels as $rel) {
            foreach ((array) ($object['links'] ?? []) as $l) {
                if (($l['rel'] ?? '') === $rel && !empty($l['href'])) return (string) $l['href'];
            }
        }
        return '';
    }

    // ── Webhooks ─────────────────────────────────────────────

    /**
     * Ask PayPal whether a delivery really came from it, for this app's
     * webhook. $headers are the request headers, lower-cased.
     */
    public static function verifyWebhook(string $payload, array $headers): bool
    {
        $webhookId = self::webhookId();
        $event = json_decode($payload, true);
        if ($webhookId === '' || !is_array($event)) return false;
        foreach (['paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time'] as $h) {
            if (trim((string) ($headers[$h] ?? '')) === '') return false;
        }
        // PayPal checks the signature over the event exactly as it sent it,
        // so the received bytes are spliced in rather than decoded and
        // re-encoded ({} would become [], number and escape forms change).
        $meta = json_encode([
            'auth_algo'         => (string) $headers['paypal-auth-algo'],
            'cert_url'          => (string) $headers['paypal-cert-url'],
            'transmission_id'   => (string) $headers['paypal-transmission-id'],
            'transmission_sig'  => (string) $headers['paypal-transmission-sig'],
            'transmission_time' => (string) $headers['paypal-transmission-time'],
            'webhook_id'        => $webhookId,
        ], JSON_UNESCAPED_SLASHES);
        try {
            $resp = self::request('POST', '/v1/notifications/verify-webhook-signature',
                substr($meta, 0, -1) . ',"webhook_event":' . trim($payload) . '}');
        } catch (\Throwable $e) {
            slate_log('PayPal webhook verification call failed: ' . $e->getMessage(), 'warning');
            return false;
        }
        return ($resp['verification_status'] ?? '') === 'SUCCESS';
    }

    /** Same shape and storage as Stripe's, so the settings pages read alike. */
    public static function recordWebhookHealth(string $outcome, string $eventType = ''): void
    {
        try {
            $now = slate_db_now();
            if ($outcome === 'ok') {
                Database::setSetting('paypal-payment.wh_ok_count', (string) ((int) Database::setting('paypal-payment.wh_ok_count') + 1));
                Database::setSetting('paypal-payment.wh_ok_at', $now);
                Database::setSetting('paypal-payment.wh_ok_type', mb_substr($eventType, 0, 80));
            } else {
                Database::setSetting('paypal-payment.wh_fail_count', (string) ((int) Database::setting('paypal-payment.wh_fail_count') + 1));
                Database::setSetting('paypal-payment.wh_fail_at', $now);
                Database::setSetting('paypal-payment.wh_fail_reason', mb_substr($outcome, 0, 80));
            }
        } catch (\Throwable $e) {
            // A health note must never be the reason a webhook fails.
        }
    }

    /** ['state' => ok|rejecting|never|no_webhook_id, 'ok_at', 'ok_count', 'ok_type', 'fail_at', 'fail_count', 'fail_reason'] */
    public static function webhookHealth(): array
    {
        $g = static fn (string $k) => (string) (Database::setting('paypal-payment.' . $k) ?? '');
        $h = [
            'ok_at' => $g('wh_ok_at'), 'ok_count' => (int) $g('wh_ok_count'), 'ok_type' => $g('wh_ok_type'),
            'fail_at' => $g('wh_fail_at'), 'fail_count' => (int) $g('wh_fail_count'), 'fail_reason' => $g('wh_fail_reason'),
        ];
        if (self::webhookId() === '') $h['state'] = 'no_webhook_id';
        elseif ($h['ok_count'] === 0 && $h['fail_count'] === 0) $h['state'] = 'never';
        elseif ($h['fail_at'] !== '' && ($h['ok_at'] === '' || strcmp($h['fail_at'], $h['ok_at']) > 0)) $h['state'] = 'rejecting';
        else $h['state'] = 'ok';
        return $h;
    }

    // ── HTTP ─────────────────────────────────────────────────

    /**
     * JSON request with a bearer token. A 401 refreshes the token once.
     * $requestId becomes PayPal-Request-Id: PayPal answers a repeat with the
     * first result instead of acting twice.
     *
     * @param array|\stdClass|null $body
     */
    public static function request(string $method, string $path, $body = null, string $requestId = ''): array
    {
        $headers = ['Content-Type: application/json', 'Prefer: return=representation'];
        if ($requestId !== '') {
            $headers[] = 'PayPal-Request-Id: ' . substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', $requestId), 0, 108);
        }
        // A string body is sent as it is (already JSON).
        $json = $body === null ? null : (is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        try {
            return self::send($method, $path, $json, array_merge($headers, ['Authorization: Bearer ' . self::accessToken()]));
        } catch (PayPalApiError $e) {
            if ($e->status !== 401) throw $e;
            return self::send($method, $path, $json, array_merge($headers, ['Authorization: Bearer ' . self::accessToken(true)]));
        }
    }

    private static function send(string $method, string $path, ?string $body, array $headers): array
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('PHP cURL extension is required.');
        $ch = curl_init(self::apiBase() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'Kohevo-PayPal-Payment/1.0',
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        ]);
        if ($body !== null && strtoupper($method) !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        if ($raw === false) throw new PayPalTransportError('PayPal HTTP transport error: ' . $err);
        $json = $raw === '' ? [] : json_decode((string) $raw, true);
        if (!is_array($json)) {
            throw new PayPalTransportError('PayPal response was not JSON (HTTP ' . $code . '): ' . substr((string) $raw, 0, 200));
        }
        if ($code >= 400) {
            $issue = (string) ($json['details'][0]['issue'] ?? ($json['name'] ?? ($json['error'] ?? '')));
            $msg   = (string) ($json['details'][0]['description'] ?? ($json['message'] ?? ($json['error_description'] ?? ('HTTP ' . $code))));
            throw new PayPalApiError('PayPal API error: ' . $msg, $code, $issue);
        }
        return $json;
    }
}

/** PayPal answered with an error: the request reached it and was refused. */
class PayPalApiError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly string $issue)
    {
        parent::__construct($message, $status);
    }
}

/** No usable answer: the request may or may not have been acted on. */
class PayPalTransportError extends \RuntimeException {}

// Register PayPal with the shared payment layer as soon as this API is
// loaded — by the plugin's boot() or by a public endpoint that requires it
// directly — so refunds and payment_event routing always find it.
require_once __DIR__ . '/PayPalGateway.php';
Payments::register(new PayPalGateway());
