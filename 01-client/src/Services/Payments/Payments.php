<?php
/**
 * Slate — the one payment API every plugin uses.
 *
 * A plugin that takes money does four things, none of them gateway-specific:
 *
 *   1. Offer a gateway:   Payments::available('shop')  (or the picker UI)
 *   2. Start a checkout:  Payments::startCheckout($gatewayId, new CheckoutRequest(...))
 *                         → redirect to $start->redirectUrl
 *   3. Confirm on return: Payments::confirmReturn($gatewayId, $ref, 'shop', $orderId)
 *   4. Listen:            Hook::addAction('payment_event', fn (PaymentEvent $e) => ...)
 *                         and act when $e->isFor('shop') — the same event
 *                         arrives from the return page and from the webhook,
 *                         so the handler must be idempotent (it is told which
 *                         emit inserted the ledger row: $e->isNewCharge).
 *
 * Refunds go through Payments::refundCharge($chargeId), which finds the
 * gateway that took the money.
 *
 * Gateways register themselves with Payments::register() when their API
 * file loads (or through the `payment_gateways` filter). Nothing here depends
 * on plugin load order: the list is read when a payment is made.
 */

declare(strict_types=1);

namespace Slate\Services\Payments;

use Slate\Contracts\Payments\PaymentGateway;

final class Payments
{
    /** @var array<string,PaymentGateway>|null */
    private static ?array $gateways = null;
    /** @var array<string,PaymentGateway> */
    private static array $registered = [];

    /** Add a gateway. Registering the same id again replaces it. */
    public static function register(PaymentGateway $gateway): void
    {
        self::$registered[$gateway->id()] = $gateway;
        self::$gateways = null;
    }

    /**
     * Every registered gateway, configured or not, keyed by id, in
     * registration order.
     *
     * @return array<string,PaymentGateway>
     */
    public static function gateways(): array
    {
        if (self::$gateways !== null) return self::$gateways;
        $out = self::$registered;
        foreach ((array) \Hook::applyFilters('payment_gateways', []) as $gw) {
            if ($gw instanceof PaymentGateway && !isset($out[$gw->id()])) {
                $out[$gw->id()] = $gw;
            }
        }
        return self::$gateways = $out;
    }

    /** Remove a registered gateway (tests, or a plugin being deactivated mid-request). */
    public static function unregister(string $id): void
    {
        unset(self::$registered[$id]);
        self::$gateways = null;
    }

    /** Test hook: re-read the `payment_gateways` filter on next use. */
    public static function reset(): void
    {
        self::$gateways = null;
    }

    public static function gateway(string $id): ?PaymentGateway
    {
        return self::gateways()[$id] ?? null;
    }

    /**
     * Gateways a customer may pay with for $forPlugin right now: configured,
     * supporting every capability in $needs, and not switched off for that
     * plugin on the Payments → Gateways page. The site's default gateway, if
     * set, comes first.
     *
     * @param list<string> $needs PaymentGateway::* capability constants
     * @return array<string,PaymentGateway>
     */
    public static function available(string $forPlugin, array $needs = [PaymentGateway::HOSTED]): array
    {
        $out = [];
        foreach (self::gateways() as $id => $gw) {
            if (!$gw->isConfigured() || !self::enabledFor($id, $forPlugin)) continue;
            foreach ($needs as $cap) {
                if (!$gw->supports($cap)) continue 2;
            }
            $out[$id] = $gw;
        }
        $default = (string) (\Database::setting('payments.default_gateway') ?? '');
        if ($default !== '' && isset($out[$default])) {
            $out = [$default => $out[$default]] + $out;
        }
        return $out;
    }

    /** True when at least one gateway can take a payment for $forPlugin. */
    public static function canCharge(string $forPlugin, array $needs = [PaymentGateway::HOSTED]): bool
    {
        return self::available($forPlugin, $needs) !== [];
    }

    /** Whether the admin left $gatewayId on for $plugin (default on). */
    public static function enabledFor(string $gatewayId, string $plugin): bool
    {
        return (string) (\Database::setting("payments.off.{$gatewayId}.{$plugin}") ?? '') !== '1';
    }

    public static function setEnabledFor(string $gatewayId, string $plugin, bool $on): void
    {
        \Database::setSetting("payments.off.{$gatewayId}.{$plugin}", $on ? '0' : '1');
    }

    /**
     * Pick the gateway for a checkout: the one the customer chose if it is
     * available for this plugin, otherwise the first available one.
     */
    public static function choose(string $forPlugin, ?string $chosen, array $needs = [PaymentGateway::HOSTED]): ?PaymentGateway
    {
        $available = self::available($forPlugin, $needs);
        if ($chosen !== null && $chosen !== '' && isset($available[$chosen])) {
            return $available[$chosen];
        }
        return $available ? reset($available) : null;
    }

    /**
     * Plugins that take payments and are active, as slug => label, for the
     * Payments → Gateways page. A third-party plugin adds itself through the
     * `payment_consumers` filter.
     *
     * @return array<string,string>
     */
    public static function consumers(): array
    {
        $known = [
            'shop'           => __('payments_consumer_shop', 'Shop'),
            'booking'        => __('payments_consumer_booking', 'Booking'),
            'membership'     => __('payments_consumer_membership', 'Membership'),
            'restaurant'     => __('payments_consumer_restaurant', 'Restaurant online orders'),
            'studio-builder' => __('payments_consumer_studio', 'Website payment buttons'),
        ];
        $out = [];
        foreach ($known as $slug => $label) {
            if (\PluginLoader::isActive($slug)) $out[$slug] = $label;
        }
        return (array) \Hook::applyFilters('payment_consumers', $out);
    }

    // ── Checkout ──────────────────────────────────────────────

    /**
     * Create a checkout at $gatewayId and remember which plugin record it
     * pays for. Throws when the gateway is unknown, unconfigured, cannot do
     * what the request asks (e.g. recurring), or the gateway call fails.
     */
    public static function startCheckout(string $gatewayId, CheckoutRequest $request): CheckoutStart
    {
        $gw = self::gateway($gatewayId);
        if ($gw === null || !$gw->isConfigured()) {
            throw new \RuntimeException("Payment gateway '{$gatewayId}' is not available.");
        }
        if (!self::enabledFor($gatewayId, $request->sourcePlugin)) {
            throw new \RuntimeException("Payment gateway '{$gatewayId}' is switched off for {$request->sourcePlugin}.");
        }
        if ($request->interval !== '' && !$gw->supports(PaymentGateway::RECURRING)) {
            throw new \RuntimeException("Payment gateway '{$gatewayId}' cannot take recurring payments.");
        }

        if ($request->idempotencyKey !== '') {
            $request = new CheckoutRequest($request->lineItems, [
                'currency' => $request->currency, 'success_url' => $request->successUrl, 'cancel_url' => $request->cancelUrl,
                'source_plugin' => $request->sourcePlugin, 'source_id' => $request->sourceId,
                'customer_email' => $request->customerEmail, 'interval' => $request->interval,
                'metadata' => $request->metadata, 'options' => $request->options,
                'idempotency_key' => self::scopedKey($request->idempotencyKey),
            ]);
        }
        $start = $gw->startCheckout($request);

        // Without this row the webhook could not route the payment back
        // (PayPal carries no usable metadata), so a failed write fails the
        // checkout before the customer is sent away to pay.
        PaymentLedger::createSession([
            'gateway'        => $gatewayId,
            'gateway_ref'    => $start->ref,
            'mode'           => $gw->mode(),
            'source_plugin'  => $request->sourcePlugin,
            'source_id'      => $request->sourceId,
            'amount_cents'   => $request->totalCents(),
            'currency'       => $request->currency,
            'interval'       => $request->interval,
            'customer_email' => $request->customerEmail,
            // Where to send the customer once a gateway's own return page
            // has settled the payment (PayPal returns to its plugin first).
            'meta'           => [
                'success_url' => $request->successUrl,
                'cancel_url'  => $request->cancelUrl,
                'metadata'    => $request->metadata,
            ],
        ]);
        return $start;
    }

    /**
     * The plugin's own success (or cancel) URL for a session, with
     * {PAYMENT_REF} filled in. Only ever a URL the plugin itself passed to
     * startCheckout(), so a gateway return page can redirect to it safely.
     */
    public static function returnUrl(array $session, bool $success = true): string
    {
        $meta = json_decode((string) ($session['meta_json'] ?? ''), true);
        $url  = is_array($meta) ? (string) ($meta[$success ? 'success_url' : 'cancel_url'] ?? '') : '';
        if ($url === '') return (defined('SLATE_URL') ? SLATE_URL : '') . '/';
        return str_replace('{PAYMENT_REF}', rawurlencode((string) $session['gateway_ref']), $url);
    }

    /** The payment_sessions row for a gateway checkout, or null. */
    public static function session(string $gatewayId, string $ref): ?array
    {
        return PaymentLedger::findSession($gatewayId, $ref);
    }

    /**
     * Settle a checkout the customer has just returned from.
     *
     * The ref comes from the browser, so it is only acted on when the
     * session it names was started by $sourcePlugin for $sourceId — a
     * customer cannot replay another order's ref to mark their own paid.
     * Returns the event (succeeded / failed) or null while still pending.
     */
    public static function confirmReturn(string $gatewayId, string $ref, string $sourcePlugin, string $sourceId = ''): ?PaymentEvent
    {
        $gw = self::gateway($gatewayId);
        if ($gw === null || $ref === '') return null;
        $session = self::session($gatewayId, $ref);
        if ($session !== null) {
            if ($session['source_plugin'] !== $sourcePlugin) return null;
            if ($sourceId !== '' && !hash_equals((string) ($session['source_id'] ?? ''), $sourceId)) return null;
        }

        try {
            $event = $gw->confirm($ref);
        } catch (\Throwable $e) {
            slate_log("Payments::confirmReturn({$gatewayId}) failed: " . $e->getMessage(), 'warning');
            return null;
        }
        if ($event === null) return null;
        if ($session === null) {
            // A checkout started before this layer has no session row; the
            // gateway's own record of it must then name the same plugin record.
            $evPlugin = $event->sourcePlugin !== '' ? $event->sourcePlugin : (string) ($event->metadata['source_plugin'] ?? '');
            $evId     = $event->sourceId !== '' ? $event->sourceId : (string) ($event->metadata['source_id'] ?? '');
            if ($evPlugin !== $sourcePlugin) return null;
            if ($sourceId !== '' && !hash_equals($evId, $sourceId)) return null;
        }
        return self::emit($event);
    }

    /**
     * An idempotency key made unique to this site: two installs sharing one
     * gateway account (or ids reused after a restore) never replay each
     * other's checkouts or refunds.
     */
    public static function scopedKey(string $key): string
    {
        if ($key === '') return '';
        $salt = (string) \Database::setting('payments.idem_salt');
        if ($salt === '') {
            $salt = bin2hex(random_bytes(8));
            \Database::setSetting('payments.idem_salt', $salt);
        }
        return 'k' . substr(hash('sha256', $salt . '|' . current_tenant_id()), 0, 10) . '-' . $key;
    }

    // ── Events ────────────────────────────────────────────────

    /**
     * Record and broadcast what a gateway observed. Called by gateways (from
     * their webhook and confirm paths), never by consuming plugins.
     *
     * The plugin record is taken from payment_sessions when the checkout was
     * started through startCheckout(); otherwise from the event's own
     * source_plugin/source_id metadata (checkouts made before this layer).
     */
    public static function emit(PaymentEvent $event): PaymentEvent
    {
        $session = PaymentLedger::findSession($event->gateway, $event->ref);
        if ($session !== null) {
            $event->sourcePlugin = (string) $session['source_plugin'];
            $event->sourceId     = (string) ($session['source_id'] ?? '');
        } elseif ($event->sourcePlugin === '') {
            $event->sourcePlugin = (string) ($event->metadata['source_plugin'] ?? '');
            $event->sourceId     = (string) ($event->metadata['source_id'] ?? '');
        }

        $gw   = self::gateway($event->gateway);
        $mode = $gw?->mode() ?? (string) ($session['mode'] ?? 'test');

        if ($event->isPaid()) {
            $email = $event->customerEmail !== '' ? $event->customerEmail : (string) ($session['customer_email'] ?? '');
            // A renewal's ref is the subscription (for routing); its ledger
            // row is keyed on the renewal's own txn, so each period counts once.
            $res = PaymentLedger::record([
                'gateway'        => $event->gateway,
                'mode'           => $mode,
                'source_plugin'  => $event->sourcePlugin,
                'source_id'      => $event->sourceId,
                'txn_id'         => $event->txnId,
                'ref'            => $event->type === PaymentEvent::RENEWED
                                        ? (string) ($event->metadata['ledger_ref'] ?? '')
                                        : $event->ref,
                'customer_email' => $email,
                'amount_cents'   => $event->amountCents,
                'currency'       => $event->currency,
                'meta'           => $event->metadata ?: null,
            ]);
            $event->chargeId    = $res['id'];
            $event->isNewCharge = $res['new'];
            if ($session !== null && $event->type === PaymentEvent::SUCCEEDED) {
                PaymentLedger::settleSession((int) $session['id'], 'completed');
            }
        } elseif ($event->type === PaymentEvent::FAILED) {
            if ($session !== null) PaymentLedger::settleSession((int) $session['id'], 'failed');
        } elseif ($event->type === PaymentEvent::REFUNDED) {
            // A refund made in the gateway's own dashboard: bring the ledger up to date.
            $row = PaymentLedger::findByTxn($event->gateway, $event->txnId);
            if ($row !== null) {
                $event->chargeId = (int) $row['id'];
                PaymentLedger::setRefunded((int) $row['id'], $event->refundedCents);
            }
        }

        \Hook::doAction('payment_event', $event);
        return $event;
    }

    // ── Ledger ────────────────────────────────────────────────

    public static function getCharge(int $id): ?array
    {
        return PaymentLedger::get($id);
    }

    /** $filters: gateway, source_plugin, mode, status, email_like, days */
    public static function listCharges(array $filters = [], int $limit = 100): array
    {
        return PaymentLedger::list($filters, $limit);
    }

    /**
     * Refund a ledger row through the gateway that took the money.
     * $amountCents null refunds whatever is left.
     *
     * Returns ['ok' => true, 'refund_id' => string, 'refunded_cents' => int (new total)]
     *      or ['ok' => false, 'error' => string, 'uncertain' => bool].
     */
    public static function refundCharge(int $chargeId, ?int $amountCents = null, string $idempotencyKey = ''): array
    {
        $row = PaymentLedger::get($chargeId);
        if (!$row) return self::refusal(__('payments_err_charge_not_found', 'Charge not found.'));

        $gw = self::gateway((string) $row['gateway']);
        if ($gw === null || !$gw->supports(PaymentGateway::REFUND)) {
            return self::refusal(__('payments_err_gateway_cannot_refund', 'The payment gateway for this charge is not available to refund it.'));
        }

        $remaining = max(0, (int) $row['amount_cents'] - (int) $row['refunded_cents']);
        if ($remaining <= 0) return self::refusal(__('payments_err_already_refunded', 'Already fully refunded.'));
        $amount = $amountCents ?? $remaining;
        if ($amount <= 0 || $amount > $remaining) {
            return self::refusal(__('payments_err_refund_out_of_range', 'Refund amount out of range.'));
        }
        if ($amount < $remaining && !$gw->supports(PaymentGateway::PARTIAL_REFUND)) {
            return self::refusal(__('payments_err_no_partial_refund', 'This gateway can only refund the full amount.'));
        }

        // Without a key from the caller, the charge, what was refunded before
        // and this amount make one, so a retry is replayed, not repeated.
        $key = $idempotencyKey !== '' ? $idempotencyKey
            : 'refund-' . $chargeId . '-' . (int) $row['refunded_cents'] . '-' . $amount;
        $res = $gw->refund($row, $amount === $remaining ? null : $amount, self::scopedKey($key));
        if (empty($res['ok'])) {
            return [
                'ok'        => false,
                'error'     => (string) ($res['error'] ?? 'Refund failed.'),
                'uncertain' => !empty($res['uncertain']),
            ];
        }

        $after = PaymentLedger::addRefund($chargeId, $amount, (string) ($res['refund_id'] ?? ''));
        \AuditLog::record('payments.refunded', (string) $chargeId, [
            'gateway'   => $row['gateway'],
            'refund_id' => (string) ($res['refund_id'] ?? ''),
            'amount'    => $amount,
        ]);
        return [
            'ok'             => true,
            'refund_id'      => (string) ($res['refund_id'] ?? ''),
            'refunded_cents' => (int) ($after['refunded_cents'] ?? 0),
        ];
    }

    // ── Money ─────────────────────────────────────────────────

    /** ISO currencies with no minor unit: the stored amount is the whole amount. */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
                                  'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** "12.50" (major units, as typed or as a gateway sends it) → 1250. */
    public static function toMinor(string $amount, string $currency): int
    {
        $amount = trim(str_replace(',', '.', $amount));
        if ($amount === '' || !is_numeric($amount)) return 0;
        return (int) round(((float) $amount) * (10 ** self::decimals($currency)));
    }

    /** 1250 → "12.50" (a plain decimal string, as gateway APIs expect). */
    public static function toMajor(int $minor, string $currency): string
    {
        $d = self::decimals($currency);
        return number_format($minor / (10 ** $d), $d, '.', '');
    }

    private static function refusal(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'uncertain' => false];
    }
}
