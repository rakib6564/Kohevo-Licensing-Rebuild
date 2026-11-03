<?php
/**
 * Slate — what a plugin asks a gateway to charge for.
 *
 * Built by the consuming plugin, handed to Payments::startCheckout(). The
 * constructor validates everything a gateway would otherwise reject later,
 * so a bad request fails in the plugin, not halfway through a redirect.
 */

declare(strict_types=1);

namespace Slate\Services\Payments;

final class CheckoutRequest
{
    /** @var list<array{name:string,amount_cents:int,quantity:int,description:string}> */
    public readonly array $lineItems;
    public readonly string $currency;
    public readonly string $successUrl;
    public readonly string $cancelUrl;
    public readonly string $sourcePlugin;
    public readonly string $sourceId;
    public readonly string $customerEmail;
    /** '' (one-time), 'month' or 'year'. */
    public readonly string $interval;
    /** @var array<string,string> extra key/values a gateway may attach to its own object */
    public readonly array $metadata;
    public readonly string $idempotencyKey;
    /** @var array<string,mixed> gateway-specific options (e.g. Stripe's collect_shipping_address) */
    public readonly array $options;

    /**
     * $lineItems: list of ['name' => string, 'amount_cents' => int (unit price),
     *              'quantity' => int = 1, 'description' => string = '']
     *
     * $opts: success_url, cancel_url (both required), source_plugin (required),
     *        source_id, currency = 'usd', customer_email, interval, metadata,
     *        idempotency_key, options
     */
    public function __construct(array $lineItems, array $opts)
    {
        $items = [];
        foreach ($lineItems as $li) {
            $amount = (int) ($li['amount_cents'] ?? 0);
            if ($amount <= 0) continue;
            $items[] = [
                'name'         => mb_substr(trim((string) ($li['name'] ?? '')) ?: 'Item', 0, 250),
                'amount_cents' => $amount,
                'quantity'     => max(1, (int) ($li['quantity'] ?? 1)),
                'description'  => mb_substr((string) ($li['description'] ?? ''), 0, 500),
            ];
        }
        if (!$items) {
            throw new \InvalidArgumentException('A checkout needs at least one line item with a positive amount.');
        }

        $success = (string) ($opts['success_url'] ?? '');
        $cancel  = (string) ($opts['cancel_url'] ?? '');
        if ($success === '' || $cancel === '') {
            throw new \InvalidArgumentException('success_url and cancel_url are required.');
        }

        $source = trim((string) ($opts['source_plugin'] ?? ''));
        if ($source === '') {
            throw new \InvalidArgumentException('source_plugin is required, so the payment can be routed back.');
        }

        $currency = strtolower(trim((string) ($opts['currency'] ?? 'usd')));
        if (!preg_match('/^[a-z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('currency must be a 3-letter ISO code.');
        }

        $interval = (string) ($opts['interval'] ?? '');
        if (!in_array($interval, ['', 'month', 'year'], true)) {
            throw new \InvalidArgumentException("interval must be '', 'month' or 'year'.");
        }

        $meta = [];
        foreach ((array) ($opts['metadata'] ?? []) as $k => $v) {
            $meta[(string) $k] = (string) $v;
        }

        $this->lineItems      = $items;
        $this->currency       = $currency;
        $this->successUrl     = $success;
        $this->cancelUrl      = $cancel;
        $this->sourcePlugin   = mb_substr($source, 0, 80);
        $this->sourceId       = mb_substr((string) ($opts['source_id'] ?? ''), 0, 190);
        $this->customerEmail  = trim((string) ($opts['customer_email'] ?? ''));
        $this->interval       = $interval;
        $this->metadata       = $meta;
        $this->idempotencyKey = (string) ($opts['idempotency_key'] ?? '');
        $this->options        = (array) ($opts['options'] ?? []);
    }

    /** Sum of unit price × quantity — what the customer pays (per period, when recurring). */
    public function totalCents(): int
    {
        $sum = 0;
        foreach ($this->lineItems as $li) {
            $sum += $li['amount_cents'] * $li['quantity'];
        }
        return $sum;
    }
}
