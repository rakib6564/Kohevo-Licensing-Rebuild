<?php
/**
 * Slate — the contract a payment gateway plugin implements.
 *
 * A gateway (Stripe, PayPal, …) registers an instance through the
 * `payment_gateways` filter. Plugins that take money never talk to a gateway
 * directly: they go through \Slate\Services\Payments\Payments, which picks the
 * gateway, keeps the session and ledger rows, and turns each gateway's own
 * webhook into one normalised `payment_event` action.
 *
 * Amounts are always integer minor units (cents; the raw amount for
 * zero-decimal currencies), exactly as the ledger stores them.
 */

declare(strict_types=1);

namespace Slate\Contracts\Payments;

use Slate\Services\Payments\CheckoutRequest;
use Slate\Services\Payments\CheckoutStart;
use Slate\Services\Payments\PaymentEvent;

interface PaymentGateway
{
    /** Hosted checkout: the customer is redirected to the gateway to pay. */
    public const HOSTED = 'hosted';
    /** Recurring charges (CheckoutRequest::$interval = month|year). */
    public const RECURRING = 'recurring';
    public const REFUND = 'refund';
    public const PARTIAL_REFUND = 'partial_refund';
    /** An inline card form on the merchant's own page (gateway-specific JS). */
    public const INLINE_CARD = 'inline_card';
    /** In-person card readers. */
    public const CARD_PRESENT = 'card_present';

    /** Stable machine id, stored in the ledger: 'stripe', 'paypal'. */
    public function id(): string;

    /** Customer-facing name for a payment-method picker. */
    public function label(): string;

    /** Keys are present for the current mode, so a checkout can be started. */
    public function isConfigured(): bool;

    /** One of the capability constants above. */
    public function supports(string $capability): bool;

    /** 'test' or 'live' — recorded on every ledger row. */
    public function mode(): string;

    /** Admin URL of the gateway's own settings page (keys, webhooks). */
    public function settingsUrl(): string;

    /**
     * Create the checkout at the gateway. The request's success URL may contain
     * the literal `{PAYMENT_REF}`, which the gateway replaces (or arranges to
     * be replaced) with the ref it returns, so the return page can confirm it.
     *
     * Throws on any failure; never returns a half-made checkout.
     */
    public function startCheckout(CheckoutRequest $request): CheckoutStart;

    /**
     * Synchronously settle a checkout the customer just returned from.
     * Returns a `succeeded` event when the money is taken, a `failed` event
     * when the gateway says it never will be, or null when it is still
     * pending (or unknown) and the webhook will decide.
     *
     * Must be safe to call more than once and concurrently with the webhook.
     */
    public function confirm(string $ref): ?PaymentEvent;

    /**
     * Refund against a ledger row (an array from PaymentLedger::get()).
     * $amountCents null means the whole remaining amount.
     *
     * Returns ['ok' => true, 'refund_id' => string] or
     *         ['ok' => false, 'error' => string, 'uncertain' => bool] where
     * `uncertain` means the request may or may not have reached the gateway
     * (transport error) and the caller must not assume either outcome.
     */
    public function refund(array $charge, ?int $amountCents, string $idempotencyKey = ''): array;
}
