<?php
/**
 * PayPal as a gateway of the shared payment layer.
 *
 * One-time payments are Orders v2 with intent CAPTURE: the customer approves
 * on PayPal, comes back to public/return.php, and the order is captured
 * there — or by the webhook (CHECKOUT.ORDER.APPROVED) if they never come
 * back. A capture is idempotent, so the two can race safely.
 *
 * Recurring payments (CheckoutRequest::$interval) are Subscriptions v1 on a
 * billing plan made for that amount. The ledger gets one row per period:
 * the first sale fills the row the activation made; later sales are
 * `renewed` events.
 *
 *   ref  order id (one-time) or subscription id I-… (recurring)
 *   txn  capture id (one-time) or sale id (recurring) — what a refund needs
 */

use Slate\Contracts\Payments\PaymentGateway;
use Slate\Services\Payments\CheckoutRequest;
use Slate\Services\Payments\CheckoutStart;
use Slate\Services\Payments\PaymentEvent;
use Slate\Services\Payments\PaymentLedger;
use Slate\Services\Payments\Payments;

final class PayPalGateway implements PaymentGateway
{
    public function id(): string { return 'paypal'; }

    public function label(): string { return __('payments_gateway_paypal', 'PayPal'); }

    public function isConfigured(): bool { return PayPalPaymentAPI::isConfigured(); }

    public function mode(): string { return PayPalPaymentAPI::ledgerMode(); }

    public function settingsUrl(): string { return plugin_url('paypal-payment', 'admin/settings.php'); }

    public function supports(string $capability): bool
    {
        return in_array($capability, [self::HOSTED, self::RECURRING, self::REFUND, self::PARTIAL_REFUND], true);
    }

    public function startCheckout(CheckoutRequest $request): CheckoutStart
    {
        $cur = strtoupper($request->currency);
        foreach ($request->lineItems as $li) {
            if (!PayPalPaymentAPI::representable($li['amount_cents'], $cur)) {
                throw new \RuntimeException("PayPal only accepts whole amounts in {$cur}.");
            }
        }
        return $request->interval !== ''
            ? $this->startSubscription($request, $cur)
            : $this->startOrder($request, $cur);
    }

    private function startOrder(CheckoutRequest $r, string $cur): CheckoutStart
    {
        $items = [];
        foreach ($r->lineItems as $li) {
            $item = [
                'name'        => mb_substr($li['name'], 0, 127),
                'quantity'    => (string) $li['quantity'],
                'unit_amount' => ['currency_code' => $cur, 'value' => PayPalPaymentAPI::value($li['amount_cents'], $cur)],
            ];
            if ($li['description'] !== '') $item['description'] = mb_substr($li['description'], 0, 127);
            $items[] = $item;
        }
        $total = PayPalPaymentAPI::value($r->totalCents(), $cur);

        $order = PayPalPaymentAPI::createOrder([
            'intent'         => 'CAPTURE',
            'purchase_units' => [[
                'custom_id'   => mb_substr($r->sourcePlugin . ':' . $r->sourceId, 0, 127),
                'description' => mb_substr($r->lineItems[0]['name'], 0, 127),
                'amount'      => [
                    'currency_code' => $cur,
                    'value'         => $total,
                    'breakdown'     => ['item_total' => ['currency_code' => $cur, 'value' => $total]],
                ],
                'items'       => $items,
            ]],
            'payment_source' => ['paypal' => ['experience_context' => $this->experience($r, true)]
                + ($r->customerEmail !== '' ? ['email_address' => $r->customerEmail] : [])],
        ], $r->idempotencyKey !== '' ? 'order-' . $r->idempotencyKey : '');

        $url = PayPalPaymentAPI::link($order);
        return new CheckoutStart('paypal', (string) ($order['id'] ?? ''), $url);
    }

    private function startSubscription(CheckoutRequest $r, string $cur): CheckoutStart
    {
        $name = $r->lineItems[0]['name'] . (count($r->lineItems) > 1 ? ' +' . (count($r->lineItems) - 1) : '');
        $plan = PayPalPaymentAPI::planFor($r->totalCents(), $cur, $r->interval, $name);
        $body = [
            'plan_id'             => $plan,
            'custom_id'           => mb_substr($r->sourcePlugin . ':' . $r->sourceId, 0, 127),
            'application_context' => $this->experience($r, false),
        ];
        if ($r->customerEmail !== '') $body['subscriber'] = ['email_address' => $r->customerEmail];

        $sub = PayPalPaymentAPI::createSubscription($body, $r->idempotencyKey !== '' ? 'sub-' . $r->idempotencyKey : '');
        return new CheckoutStart('paypal', (string) ($sub['id'] ?? ''), PayPalPaymentAPI::link($sub, ['approve']));
    }

    private function experience(CheckoutRequest $r, bool $order): array
    {
        $ctx = [
            'brand_name'          => mb_substr(trim((string) Database::setting('site_name')) ?: 'Website', 0, 127),
            'shipping_preference' => !empty($r->options['collect_shipping_address']) ? 'GET_FROM_FILE' : 'NO_SHIPPING',
            'user_action'         => $order ? 'PAY_NOW' : 'SUBSCRIBE_NOW',
            // PayPal comes back to this plugin first; it settles the payment,
            // then sends the customer on to the plugin's own success URL.
            'return_url'          => SLATE_URL . '/plugins/paypal-payment/public/return.php',
            'cancel_url'          => SLATE_URL . '/plugins/paypal-payment/public/return.php?cancel=1',
        ];
        $locale = self::locale();
        if ($locale !== '') $ctx['locale'] = $locale;
        return $ctx;
    }

    private static function locale(): string
    {
        $lang = class_exists('I18n') ? (string) I18n::currentLocale() : '';
        return match (substr($lang, 0, 2)) {
            'fr' => 'fr-FR', 'en' => 'en-US', 'nl' => 'nl-NL', 'de' => 'de-DE', 'es' => 'es-ES', default => '',
        };
    }

    public function confirm(string $ref): ?PaymentEvent
    {
        if (str_starts_with($ref, 'I-')) {
            $sub = PayPalPaymentAPI::getSubscription($ref);
            if (!is_array($sub)) return null;
            return match ((string) ($sub['status'] ?? '')) {
                'ACTIVE'                => self::subscriptionActivated($sub),
                'CANCELLED', 'EXPIRED'  => new PaymentEvent(type: PaymentEvent::FAILED, gateway: 'paypal', ref: $ref,
                                               failureMessage: __('pp_subscription_not_started', 'The subscription was not started.'), raw: $sub),
                default                 => null, // APPROVAL_PENDING / APPROVED: activation follows
            };
        }

        $order = PayPalPaymentAPI::getOrder($ref);
        if (!is_array($order)) return null;
        if (($order['status'] ?? '') === 'APPROVED') {
            try {
                $order = PayPalPaymentAPI::captureOrder($ref);
            } catch (PayPalApiError $e) {
                // A server error or an order already captured elsewhere may
                // well have taken the money: not yet known, so no event —
                // the capture webhook, or the next look, settles it.
                if ($e->status >= 500 || $e->issue === 'ORDER_ALREADY_CAPTURED') return null;
                return new PaymentEvent(type: PaymentEvent::FAILED, gateway: 'paypal', ref: $ref,
                    failureMessage: $e->issue === 'INSTRUMENT_DECLINED'
                        ? __('pp_instrument_declined', 'PayPal declined that payment method. Please try again with another one.')
                        : $e->getMessage(),
                    raw: $order);
            }
        }
        return self::fromOrder($order);
    }

    public function refund(array $charge, ?int $amountCents, string $idempotencyKey = ''): array
    {
        $txn = PaymentLedger::txnOf($charge);
        if ($txn === '') {
            return ['ok' => false, 'uncertain' => false,
                    'error' => __('pp_err_nothing_to_refund', 'This PayPal payment has no capture to refund yet.')];
        }
        if ($amountCents !== null && !PayPalPaymentAPI::representable($amountCents, (string) $charge['currency'])) {
            // HUF/TWD are whole units at PayPal: a part amount would be cut short.
            return ['ok' => false, 'uncertain' => false,
                    'error' => __('pp_err_refund_whole_units', 'PayPal refunds in this currency must be whole units.')];
        }
        $meta = json_decode((string) ($charge['meta_json'] ?? ''), true) ?: [];
        $isSale = ($meta['paypal_kind'] ?? '') === 'sale';
        $key = $idempotencyKey !== '' ? 'refund-' . $idempotencyKey : '';
        try {
            $resp = $isSale
                ? PayPalPaymentAPI::refundSale($txn, $amountCents, (string) $charge['currency'], $key)
                : PayPalPaymentAPI::refundCapture($txn, $amountCents, (string) $charge['currency'], $key);
        } catch (PayPalTransportError $e) {
            return ['ok' => false, 'uncertain' => true, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'uncertain' => false, 'error' => $e->getMessage()];
        }
        $state = strtoupper((string) ($resp['status'] ?? ($resp['state'] ?? '')));
        if (empty($resp['id']) || in_array($state, ['FAILED', 'CANCELLED'], true)) {
            return ['ok' => false, 'uncertain' => false, 'error' => 'PayPal refund failed: ' . ($state ?: 'unknown')];
        }
        return ['ok' => true, 'refund_id' => (string) $resp['id']];
    }

    // ── Webhook → PaymentEvent ────────────────────────────────

    /**
     * Turn a verified PayPal webhook event into a payment event, or null.
     * May call PayPal (an approved order is captured here as a backstop).
     */
    public function fromWebhook(array $event): ?PaymentEvent
    {
        $type = (string) ($event['event_type'] ?? '');
        $res  = (array) ($event['resource'] ?? []);

        switch ($type) {
            case 'CHECKOUT.ORDER.APPROVED':
                // The customer approved but may never come back: capture now.
                return isset($res['id']) ? $this->confirm((string) $res['id']) : null;

            case 'PAYMENT.CAPTURE.COMPLETED':
                $orderId = (string) ($res['supplementary_data']['related_ids']['order_id'] ?? '');
                if ($orderId === '' || ($res['status'] ?? '') !== 'COMPLETED') return null;
                return self::captureEvent(PaymentEvent::SUCCEEDED, $orderId, $res);

            case 'PAYMENT.CAPTURE.DENIED':
            case 'PAYMENT.CAPTURE.DECLINED':
                $orderId = (string) ($res['supplementary_data']['related_ids']['order_id'] ?? '');
                return $orderId === '' ? null : self::captureEvent(PaymentEvent::FAILED, $orderId, $res,
                    __('payments_payment_failed', 'The payment failed.'));

            case 'PAYMENT.CAPTURE.REFUNDED':
            case 'PAYMENT.CAPTURE.REVERSED':
                // The resource is the refund (or, for a reversal, the money
                // pulled back by PayPal); its "up" link names the capture.
                $captureId = '';
                foreach ((array) ($res['links'] ?? []) as $l) {
                    if (($l['rel'] ?? '') === 'up') $captureId = basename((string) parse_url((string) $l['href'], PHP_URL_PATH));
                }
                $amt = (array) ($res['amount'] ?? []);
                $total = (array) ($res['seller_payable_breakdown']['total_refunded_amount'] ?? []);
                return self::refundEvent($event, $captureId, (string) ($res['id'] ?? ''),
                    (string) ($amt['value'] ?? ''), (string) ($total['value'] ?? ''),
                    (string) ($total['currency_code'] ?? ($amt['currency_code'] ?? '')), $type === 'PAYMENT.CAPTURE.REVERSED');

            case 'PAYMENT.SALE.REFUNDED':
            case 'PAYMENT.SALE.REVERSED':
                // A subscription payment refunded (v1 sale refund object).
                $amt = (array) ($res['amount'] ?? []);
                $total = (array) ($res['total_refunded_amount'] ?? []);
                return self::refundEvent($event, (string) ($res['sale_id'] ?? ''), (string) ($res['id'] ?? ''),
                    (string) ($amt['total'] ?? ''), (string) ($total['value'] ?? ''),
                    (string) ($total['currency'] ?? ($amt['currency'] ?? '')), $type === 'PAYMENT.SALE.REVERSED');

            case 'BILLING.SUBSCRIPTION.ACTIVATED':
                return isset($res['id']) ? self::subscriptionActivated($res) : null;

            case 'PAYMENT.SALE.COMPLETED':
                $subId = (string) ($res['billing_agreement_id'] ?? '');
                return $subId === '' ? null : self::saleEvent($subId, $res);
        }
        return null;
    }

    // ── Builders ──────────────────────────────────────────────

    /**
     * Money given back on a capture or sale. $amount is this one refund;
     * $total, when PayPal sends it, is everything refunded so far. A reversal
     * (chargeback) without amounts pulls back the whole payment. Each refund
     * is counted once by its id, so a redelivery or a refund already recorded
     * from the admin adds nothing.
     */
    private static function refundEvent(array $event, string $txnId, string $refundId, string $amount, string $total,
                                        string $cur, bool $reversal): ?PaymentEvent
    {
        if ($txnId === '') return null;
        $row = PaymentLedger::findByTxn('paypal', $txnId);
        $cur = strtoupper($cur !== '' ? $cur : (string) ($row['currency'] ?? 'USD'));
        $one = $amount !== '' ? abs(PayPalPaymentAPI::minor(ltrim($amount, '-'), $cur)) : 0;
        $sum = $total !== '' ? abs(PayPalPaymentAPI::minor(ltrim($total, '-'), $cur)) : 0;
        if ($reversal && $one === 0 && $sum === 0 && $row !== null) $sum = (int) $row['amount_cents'];
        if ($one === 0 && $sum === 0) return null;
        return new PaymentEvent(type: PaymentEvent::REFUNDED, gateway: 'paypal', ref: '', txnId: $txnId,
            currency: $cur, raw: $event, refundedCents: $sum,
            metadata: ['refund_id' => $refundId, 'refund_amount' => $one, 'reversal' => $reversal]);
    }

    private static function fromOrder(array $order): ?PaymentEvent
    {
        $ref = (string) ($order['id'] ?? '');
        $status = (string) ($order['status'] ?? '');
        $capture = (array) ($order['purchase_units'][0]['payments']['captures'][0] ?? []);
        if ($status === 'VOIDED') {
            return new PaymentEvent(type: PaymentEvent::FAILED, gateway: 'paypal', ref: $ref,
                failureMessage: __('pp_order_voided', 'The PayPal order was cancelled.'), raw: $order);
        }
        if ($status !== 'COMPLETED' || !$capture) return null;
        $cStatus = (string) ($capture['status'] ?? '');
        if ($cStatus === 'DECLINED' || $cStatus === 'FAILED') {
            return self::captureEvent(PaymentEvent::FAILED, $ref, $capture, __('payments_payment_failed', 'The payment failed.'),
                (string) ($order['payer']['email_address'] ?? ''));
        }
        // PENDING (e.g. an eCheck) settles later through PAYMENT.CAPTURE.COMPLETED.
        if ($cStatus !== 'COMPLETED') return null;
        return self::captureEvent(PaymentEvent::SUCCEEDED, $ref, $capture, '', (string) ($order['payer']['email_address'] ?? ''));
    }

    private static function captureEvent(string $type, string $orderId, array $capture, string $failure = '', string $email = ''): PaymentEvent
    {
        $amt = (array) ($capture['amount'] ?? []);
        $cur = (string) ($amt['currency_code'] ?? 'USD');
        return new PaymentEvent(
            type: $type, gateway: 'paypal', ref: $orderId, txnId: (string) ($capture['id'] ?? ''),
            amountCents: PayPalPaymentAPI::minor((string) ($amt['value'] ?? '0'), $cur), currency: $cur,
            customerEmail: $email, failureMessage: $failure, raw: $capture,
            metadata: ['paypal_kind' => 'capture'],
        );
    }

    /**
     * The subscription is live. Its first payment is the session's amount;
     * the matching sale id arrives separately (PAYMENT.SALE.COMPLETED) and
     * is attached to this same ledger row.
     */
    private static function subscriptionActivated(array $sub): PaymentEvent
    {
        $id = (string) $sub['id'];
        $session = Payments::session('paypal', $id);
        return new PaymentEvent(
            type: PaymentEvent::SUCCEEDED, gateway: 'paypal', ref: $id,
            amountCents: (int) ($session['amount_cents'] ?? 0), currency: (string) ($session['currency'] ?? 'USD'),
            customerEmail: (string) ($sub['subscriber']['email_address'] ?? ''), raw: $sub,
            metadata: ['paypal_kind' => 'sale'],
        );
    }

    /**
     * A subscription payment. The first one completes the activation's row
     * (or stands in for it if it arrives first); every later one is a renewal.
     */
    private static function saleEvent(string $subId, array $sale): ?PaymentEvent
    {
        $saleId = (string) ($sale['id'] ?? '');
        $amt = (array) ($sale['amount'] ?? []);
        $cur = (string) ($amt['currency'] ?? 'USD');
        $minor = PayPalPaymentAPI::minor((string) ($amt['total'] ?? '0'), $cur);

        if (PaymentLedger::findByTxn('paypal', $saleId) !== null) {
            // Redelivery of a sale already in the ledger: replay it as it was.
            return new PaymentEvent(type: PaymentEvent::RENEWED, gateway: 'paypal', ref: $subId, txnId: $saleId,
                amountCents: $minor, currency: $cur, raw: $sale, metadata: ['paypal_kind' => 'sale', 'ledger_ref' => $saleId]);
        }
        $first = PaymentLedger::findByRef('paypal', $subId);
        if ($first === null) {
            // The sale beat the activation: it IS the first period.
            return new PaymentEvent(type: PaymentEvent::SUCCEEDED, gateway: 'paypal', ref: $subId, txnId: $saleId,
                amountCents: $minor, currency: $cur, raw: $sale, metadata: ['paypal_kind' => 'sale']);
        }
        if (PaymentLedger::txnOf($first) === '' && strtotime((string) ($first['created_at'] ?? '')) > slate_db_time() - 3 * 86400) {
            // The activation's row is waiting for its first sale id. A sale
            // weeks later is a renewal even if the first one never arrived.
            PaymentLedger::attachTxn((int) $first['id'], $saleId);
            return null;
        }
        return new PaymentEvent(type: PaymentEvent::RENEWED, gateway: 'paypal', ref: $subId, txnId: $saleId,
            amountCents: $minor, currency: $cur, raw: $sale, metadata: ['paypal_kind' => 'sale', 'ledger_ref' => $saleId]);
    }
}
