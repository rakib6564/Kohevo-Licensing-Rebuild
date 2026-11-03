<?php
/**
 * Stripe as a gateway of the shared payment layer (Payments / payment_event).
 *
 * Wraps the existing StripePaymentAPI calls; it adds no new Stripe traffic.
 * Hosted checkout = a Stripe Checkout Session (ref: cs_…). Inline cards
 * (Payment Element) and Terminal readers stay Stripe-specific APIs that
 * plugins call directly; supports() advertises them so a plugin can tell.
 *
 * normalize() is the single translation from a Stripe webhook event to a
 * PaymentEvent. StripePaymentAPI::dispatchWebhook() runs it for every
 * verified delivery, before the legacy `stripe_webhook_event` action.
 */

use Slate\Contracts\Payments\PaymentGateway;
use Slate\Services\Payments\CheckoutRequest;
use Slate\Services\Payments\CheckoutStart;
use Slate\Services\Payments\PaymentEvent;

final class StripeGateway implements PaymentGateway
{
    public function id(): string { return 'stripe'; }

    public function label(): string
    {
        return __('payments_gateway_stripe', 'Card (Stripe)');
    }

    public function isConfigured(): bool { return StripePaymentAPI::isConfigured(); }

    public function mode(): string { return StripePaymentAPI::mode(); }

    public function settingsUrl(): string { return plugin_url('stripe-payment', 'admin/settings.php'); }

    public function supports(string $capability): bool
    {
        return in_array($capability, [
            self::HOSTED, self::RECURRING, self::REFUND, self::PARTIAL_REFUND,
            self::INLINE_CARD, self::CARD_PRESENT,
        ], true);
    }

    public function startCheckout(CheckoutRequest $request): CheckoutStart
    {
        // Stripe fills its own placeholder in the success URL.
        $success = str_replace('{PAYMENT_REF}', '{CHECKOUT_SESSION_ID}', $request->successUrl);

        // source_plugin/source_id still ride along as metadata, so the
        // existing ledger writes in public/webhook.php keep attributing rows.
        $meta = $request->metadata + [
            'source_plugin' => $request->sourcePlugin,
            'source_id'     => $request->sourceId,
        ];

        $opts = $request->options + [
            'currency'        => $request->currency,
            'success_url'     => $success,
            'cancel_url'      => $request->cancelUrl,
            'metadata'        => $meta,
            'interval'        => $request->interval,
            'idempotency_key' => $request->idempotencyKey,
        ];
        if ($request->customerEmail !== '') $opts['customer_email'] = $request->customerEmail;

        $s = StripePaymentAPI::createCheckout($request->lineItems, $opts);
        return new CheckoutStart('stripe', (string) $s['session_id'], (string) $s['url']);
    }

    public function confirm(string $ref): ?PaymentEvent
    {
        if (str_starts_with($ref, 'pi_')) {
            $pi = StripePaymentAPI::getPaymentIntent($ref);
            return is_array($pi) ? self::fromPaymentIntent($pi, true) : null;
        }
        $s = StripePaymentAPI::getSession($ref);
        if (!is_array($s)) return null;
        if (($s['status'] ?? '') === 'expired') {
            return self::sessionEvent(PaymentEvent::FAILED, $s, __('payments_checkout_expired', 'The checkout expired before payment.'));
        }
        if (in_array(($s['payment_status'] ?? ''), ['paid', 'no_payment_required'], true)) {
            return self::sessionEvent(PaymentEvent::SUCCEEDED, $s);
        }
        return null;
    }

    public function refund(array $charge, ?int $amountCents, string $idempotencyKey = ''): array
    {
        $piId = (string) ($charge['stripe_payment_intent_id'] ?? '');
        if ($piId === '' && ($charge['stripe_session_id'] ?? '') !== '') {
            // Best-effort: a hosted checkout recorded before its PaymentIntent was known.
            $sess = StripePaymentAPI::getSession((string) $charge['stripe_session_id']);
            $piId = (string) ($sess['payment_intent'] ?? '');
        }
        if ($piId === '') {
            return ['ok' => false, 'uncertain' => false,
                    'error' => __('sp_err_no_paymentintent_to_refund_against', 'No PaymentIntent to refund against.')];
        }

        $params = ['payment_intent' => $piId];
        if ($amountCents !== null) $params['amount'] = $amountCents;
        try {
            $resp = StripePaymentAPI::httpRequest('POST', '/v1/refunds', $params, $idempotencyKey);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // A transport failure or garbled reply may have reached Stripe;
            // an API error (4xx/5xx with a JSON body) definitely did not refund.
            $uncertain = str_starts_with($msg, 'Stripe HTTP transport error')
                      || str_starts_with($msg, 'Stripe response was not JSON');
            return ['ok' => false, 'error' => $msg, 'uncertain' => $uncertain];
        }
        if (empty($resp['id']) || ($resp['status'] ?? '') === 'failed') {
            return ['ok' => false, 'uncertain' => false,
                    'error' => 'Stripe refund failed: ' . ($resp['failure_reason'] ?? 'unknown')];
        }
        return ['ok' => true, 'refund_id' => (string) $resp['id']];
    }

    // ── Webhook → PaymentEvent ────────────────────────────────

    /** Translate a verified Stripe event, or null when it is not about a payment outcome. */
    public static function normalize(array $event): ?PaymentEvent
    {
        $type = (string) ($event['type'] ?? '');
        $obj  = $event['data']['object'] ?? null;
        if (!is_array($obj)) return null;

        switch ($type) {
            case 'checkout.session.completed':
                return in_array(($obj['payment_status'] ?? ''), ['paid', 'no_payment_required'], true)
                    ? self::sessionEvent(PaymentEvent::SUCCEEDED, $obj)
                    : null; // async method still settling; async_payment_* follows
            case 'checkout.session.async_payment_succeeded':
                return self::sessionEvent(PaymentEvent::SUCCEEDED, $obj);
            case 'checkout.session.async_payment_failed':
                return self::sessionEvent(PaymentEvent::FAILED, $obj, __('payments_payment_failed', 'The payment failed.'));
            case 'checkout.session.expired':
                return self::sessionEvent(PaymentEvent::FAILED, $obj, __('payments_checkout_expired', 'The checkout expired before payment.'));
            case 'payment_intent.succeeded':
            case 'payment_intent.payment_failed':
                // A hosted checkout's PaymentIntent carries no metadata; its
                // session events already cover it, so only intents a plugin
                // created itself (inline card) are reported here.
                if (($obj['metadata']['source_plugin'] ?? '') === '') return null;
                return self::fromPaymentIntent($obj, false);
            case 'invoice.paid':
                if (($obj['billing_reason'] ?? '') !== 'subscription_cycle') return null;
                $meta = $obj['parent']['subscription_details']['metadata'] ?? ($obj['subscription_details']['metadata'] ?? []);
                $sub  = $obj['parent']['subscription_details']['subscription'] ?? ($obj['subscription'] ?? '');
                return new PaymentEvent(
                    type:          PaymentEvent::RENEWED,
                    gateway:       'stripe',
                    ref:           is_string($sub) ? $sub : '',
                    txnId:         is_string($obj['payment_intent'] ?? null) ? $obj['payment_intent'] : '',
                    sourcePlugin:  (string) ($meta['source_plugin'] ?? ''),
                    sourceId:      (string) ($meta['source_id'] ?? ''),
                    amountCents:   (int) ($obj['amount_paid'] ?? 0),
                    currency:      strtoupper((string) ($obj['currency'] ?? 'usd')),
                    customerEmail: (string) ($obj['customer_email'] ?? ''),
                    raw:           $event,
                    // The invoice id keys the renewal's ledger row, as public/webhook.php does.
                    metadata:      (array) $meta + ['ledger_ref' => (string) ($obj['id'] ?? '')],
                );
            case 'charge.refunded':
                return new PaymentEvent(
                    type:          PaymentEvent::REFUNDED,
                    gateway:       'stripe',
                    ref:           (string) ($obj['payment_intent'] ?? ''),
                    txnId:         (string) ($obj['payment_intent'] ?? ''),
                    amountCents:   (int) ($obj['amount'] ?? 0),
                    currency:      strtoupper((string) ($obj['currency'] ?? 'usd')),
                    raw:           $event,
                    metadata:      (array) ($obj['metadata'] ?? []),
                    refundedCents: (int) ($obj['amount_refunded'] ?? 0),
                );
        }
        return null;
    }

    private static function sessionEvent(string $type, array $s, string $failure = ''): PaymentEvent
    {
        $meta = (array) ($s['metadata'] ?? []);
        return new PaymentEvent(
            type:           $type,
            gateway:        'stripe',
            ref:            (string) ($s['id'] ?? ''),
            txnId:          is_string($s['payment_intent'] ?? null) ? $s['payment_intent'] : '',
            sourcePlugin:   (string) ($meta['source_plugin'] ?? ''),
            sourceId:       (string) ($meta['source_id'] ?? ''),
            amountCents:    (int) ($s['amount_total'] ?? 0),
            currency:       strtoupper((string) ($s['currency'] ?? 'usd')),
            customerEmail:  (string) ($s['customer_details']['email'] ?? ($s['customer_email'] ?? '')),
            failureMessage: $failure,
            raw:            $s,
            metadata:       $meta,
        );
    }

    /** $pending: on a return page a still-processing intent is "not yet", not "failed". */
    private static function fromPaymentIntent(array $pi, bool $pending): ?PaymentEvent
    {
        $status = (string) ($pi['status'] ?? '');
        if (in_array($status, ['succeeded', 'requires_capture'], true)) {
            $type = PaymentEvent::SUCCEEDED;
        } elseif ($status === 'canceled' || (!empty($pi['last_payment_error']) && !$pending)) {
            $type = PaymentEvent::FAILED;
        } else {
            return null;
        }
        $meta = (array) ($pi['metadata'] ?? []);
        return new PaymentEvent(
            type:           $type,
            gateway:        'stripe',
            ref:            (string) ($pi['id'] ?? ''),
            txnId:          (string) ($pi['id'] ?? ''),
            sourcePlugin:   (string) ($meta['source_plugin'] ?? ''),
            sourceId:       (string) ($meta['source_id'] ?? ''),
            amountCents:    (int) ($pi['amount_received'] ?? 0) ?: (int) ($pi['amount'] ?? 0),
            currency:       strtoupper((string) ($pi['currency'] ?? 'usd')),
            customerEmail:  (string) ($pi['receipt_email'] ?? ''),
            failureMessage: (string) ($pi['last_payment_error']['message'] ?? ''),
            raw:            $pi,
            metadata:       $meta,
        );
    }
}
