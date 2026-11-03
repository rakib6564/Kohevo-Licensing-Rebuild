<?php
/**
 * Slate — one thing that happened to a payment, in gateway-neutral terms.
 *
 * Gateways build these from their own webhooks or return pages and hand them
 * to Payments::emit(), which fills in the source (from payment_sessions),
 * records the ledger row and fires `payment_event` with the event as its one
 * argument. A listener checks $event->sourcePlugin and acts on its own
 * $event->sourceId — it never needs to know which gateway took the money.
 *
 *   succeeded  money was taken for a checkout
 *   renewed    a recurring charge was taken (a later period)
 *   failed     the payment was declined or abandoned; nothing was taken
 *   refunded   money went back (refundedCents is the new running total)
 */

declare(strict_types=1);

namespace Slate\Services\Payments;

final class PaymentEvent
{
    public const SUCCEEDED = 'succeeded';
    public const RENEWED   = 'renewed';
    public const FAILED    = 'failed';
    public const REFUNDED  = 'refunded';

    /** Ledger row id, set by Payments::emit() for succeeded/renewed events. */
    public ?int $chargeId = null;
    /** True only for the one emit that inserted the ledger row (redeliveries see false). */
    public bool $isNewCharge = false;

    /**
     * @param string $ref    the checkout reference (session / order / subscription id) — what
     *                       payment_sessions is keyed on, so the source can be found
     * @param string $txnId  the refundable money movement (PaymentIntent / capture / sale id); '' when unknown
     * @param array  $raw    the gateway's own payload, for listeners that need a gateway detail
     */
    public function __construct(
        public readonly string $type,
        public readonly string $gateway,
        public readonly string $ref,
        public readonly string $txnId = '',
        public string $sourcePlugin = '',
        public string $sourceId = '',
        public readonly int $amountCents = 0,
        public readonly string $currency = 'USD',
        public readonly string $customerEmail = '',
        public readonly string $failureMessage = '',
        public readonly array $raw = [],
        public readonly array $metadata = [],
        public readonly int $refundedCents = 0,
    ) {
        if (!in_array($type, [self::SUCCEEDED, self::RENEWED, self::FAILED, self::REFUNDED], true)) {
            throw new \InvalidArgumentException("Unknown payment event type '{$type}'.");
        }
    }

    public function isPaid(): bool
    {
        return $this->type === self::SUCCEEDED || $this->type === self::RENEWED;
    }

    /** True when this event is for $plugin's record $id. */
    public function isFor(string $plugin, string $id = ''): bool
    {
        if ($this->sourcePlugin !== $plugin) return false;
        return $id === '' || hash_equals($this->sourceId, $id);
    }
}
