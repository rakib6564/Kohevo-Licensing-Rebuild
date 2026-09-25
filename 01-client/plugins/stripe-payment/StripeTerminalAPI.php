<?php
/**
 * Stripe Terminal extension for the stripe-payment plugin.
 *
 * Card-present (in-person) payments via Stripe Terminal internet readers
 * (Stripe Reader S700 / BBPOS WisePOS E). Built for a SERVER-DRIVEN flow so
 * no browser SDK / connection token is required for the common case — the
 * POS asks our server to charge an order on a reader, and the server drives
 * Stripe: create a card_present PaymentIntent (manual capture) → push it to
 * the reader → poll the reader / PI until it resolves → capture.
 *
 * Every method is a thin wrapper over StripePaymentAPI::httpRequest() so any
 * plugin can reuse Terminal without re-implementing transport, key handling
 * or mode (test/live) resolution.
 *
 *   StripeTerminalAPI::ensureLocation([...address...], 'My Restaurant')
 *   StripeTerminalAPI::registerReader($code, $label, $locationId)
 *   StripeTerminalAPI::listReaders()
 *   $pi = StripeTerminalAPI::createCardPresentIntent($cents, [...]);
 *   StripeTerminalAPI::processOnReader($readerId, $pi['id']);
 *   // poll …
 *   StripeTerminalAPI::capture($pi['id']);
 *
 * Settings (stored under the stripe-payment slug, mode-agnostic):
 *   stripe-payment.terminal_location_id   the one Stripe Terminal Location
 *
 * Requires StripePaymentAPI (loaded by StripePayment::boot()).
 */
if (!defined('SLATE_ROOT')) exit;

class StripeTerminalAPI {

    // ── Location ──────────────────────────────────────────────
    //
    // Terminal requires at least one Location even for a single physical
    // site. We store the created Location id in settings and reuse it.

    public static function locationId(): string {
        return (string) Database::setting('stripe-payment.terminal_location_id');
    }

    public static function setLocationId(string $id): void {
        Database::setSetting('stripe-payment.terminal_location_id', $id);
    }

    /**
     * Return the configured Location id, creating one if necessary.
     * $address keys: line1, line2, city, state, postal_code, country (ISO-2).
     * Returns ['ok'=>true,'id'=>'tml_…'] or ['ok'=>false,'error'=>…].
     */
    public static function ensureLocation(array $address, string $displayName): array {
        $existing = self::locationId();
        if ($existing !== '') {
            // Verify it still exists on the account; otherwise fall through
            // and create a fresh one.
            try {
                $loc = StripePaymentAPI::httpRequest('GET', '/v1/terminal/locations/' . urlencode($existing), []);
                if (!empty($loc['id']) && empty($loc['deleted'])) {
                    return ['ok' => true, 'id' => (string)$loc['id']];
                }
            } catch (\Throwable $e) {
                // fall through to create
            }
        }
        $country = strtoupper(trim((string)($address['country'] ?? 'US'))) ?: 'US';
        $params = [
            'display_name'          => mb_substr($displayName !== '' ? $displayName : 'Restaurant', 0, 200),
            'address[line1]'        => (string)($address['line1'] ?? ''),
            'address[city]'         => (string)($address['city'] ?? ''),
            'address[state]'        => (string)($address['state'] ?? ''),
            'address[postal_code]'  => (string)($address['postal_code'] ?? ''),
            'address[country]'      => $country,
        ];
        if (!empty($address['line2'])) $params['address[line2]'] = (string)$address['line2'];
        if ($params['address[line1]'] === '') {
            return ['ok' => false, 'error' => 'A street address (line1) is required to create a Terminal Location.'];
        }
        try {
            $resp = StripePaymentAPI::httpRequest('POST', '/v1/terminal/locations', $params);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (empty($resp['id'])) return ['ok' => false, 'error' => 'Stripe did not return a Location id.'];
        self::setLocationId((string)$resp['id']);
        return ['ok' => true, 'id' => (string)$resp['id']];
    }

    // ── Readers ───────────────────────────────────────────────

    /** List readers on the account (optionally filtered to our Location). */
    public static function listReaders(?string $locationId = null): array {
        $params = ['limit' => 100];
        $loc = $locationId ?? self::locationId();
        if ($loc !== '') $params['location'] = $loc;
        try {
            $resp = StripePaymentAPI::httpRequest('GET', '/v1/terminal/readers', $params);
            return is_array($resp['data'] ?? null) ? $resp['data'] : [];
        } catch (\Throwable $e) {
            slate_log('StripeTerminalAPI::listReaders failed: ' . $e->getMessage(), 'error');
            return [];
        }
    }

    public static function getReader(string $readerId): ?array {
        $id = trim($readerId);
        if ($id === '') return null;
        try {
            return StripePaymentAPI::httpRequest('GET', '/v1/terminal/readers/' . urlencode($id), []);
        } catch (\Throwable $e) {
            slate_log('StripeTerminalAPI::getReader failed: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Register an internet reader by its pairing code (the 3-word code the
     * device shows). Returns ['ok'=>true,'reader'=>[…]] or error.
     */
    public static function registerReader(string $registrationCode, string $label, ?string $locationId = null): array {
        $code = trim($registrationCode);
        if ($code === '') return ['ok' => false, 'error' => 'A registration code is required.'];
        $loc = $locationId ?? self::locationId();
        if ($loc === '') return ['ok' => false, 'error' => 'Create a Terminal Location first.'];
        $params = [
            'registration_code' => $code,
            'location'          => $loc,
        ];
        if ($label !== '') $params['label'] = mb_substr($label, 0, 120);
        try {
            $resp = StripePaymentAPI::httpRequest('POST', '/v1/terminal/readers', $params);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (empty($resp['id'])) return ['ok' => false, 'error' => 'Stripe did not return a reader id.'];
        return ['ok' => true, 'reader' => $resp];
    }

    public static function deleteReader(string $readerId): array {
        $id = trim($readerId);
        if ($id === '') return ['ok' => false, 'error' => 'Missing reader id.'];
        try {
            StripePaymentAPI::httpRequest('DELETE', '/v1/terminal/readers/' . urlencode($id), []);
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Card-present PaymentIntent lifecycle ──────────────────

    /**
     * Create a card_present PaymentIntent with manual capture (the Terminal
     * server-driven default). $opts: currency, metadata[], receipt_email.
     * Returns the raw PaymentIntent (id, status, …).
     */
    public static function createCardPresentIntent(int $amountCents, array $opts = []): array {
        if (!StripePaymentAPI::isConfigured()) throw new \RuntimeException('Stripe is not configured.');
        if ($amountCents <= 0) throw new \RuntimeException('Amount must be positive.');
        $params = [
            'amount'                  => $amountCents,
            'currency'                => strtolower((string)($opts['currency'] ?? 'usd')),
            'payment_method_types[0]' => 'card_present',
            'capture_method'          => 'manual',
        ];
        if (!empty($opts['receipt_email'])) $params['receipt_email'] = (string)$opts['receipt_email'];
        if (!empty($opts['metadata']) && is_array($opts['metadata'])) {
            foreach ($opts['metadata'] as $k => $v) $params["metadata[$k]"] = (string)$v;
        }
        $resp = StripePaymentAPI::httpRequest('POST', '/v1/payment_intents', $params);
        if (empty($resp['id'])) throw new \RuntimeException('Stripe PaymentIntent response missing id.');
        return $resp;
    }

    /** Push a PaymentIntent to a reader for card collection (server-driven). */
    public static function processOnReader(string $readerId, string $piId): array {
        $rid = trim($readerId); $pi = trim($piId);
        if ($rid === '' || $pi === '') return ['ok' => false, 'error' => 'Missing reader or PaymentIntent.'];
        try {
            $resp = StripePaymentAPI::httpRequest(
                'POST',
                '/v1/terminal/readers/' . urlencode($rid) . '/process_payment_intent',
                ['payment_intent' => $pi]
            );
            return ['ok' => true, 'reader' => $resp];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Cancel whatever the reader is currently doing (abort a collection). */
    public static function cancelReaderAction(string $readerId): array {
        $rid = trim($readerId);
        if ($rid === '') return ['ok' => false, 'error' => 'Missing reader.'];
        try {
            $resp = StripePaymentAPI::httpRequest(
                'POST', '/v1/terminal/readers/' . urlencode($rid) . '/cancel_action', []);
            return ['ok' => true, 'reader' => $resp];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Capture a previously-authorised manual-capture PaymentIntent. */
    public static function capture(string $piId, ?int $amountToCapture = null): array {
        $pi = trim($piId);
        if ($pi === '') return ['ok' => false, 'error' => 'Missing PaymentIntent.'];
        $params = [];
        if ($amountToCapture !== null && $amountToCapture > 0) {
            $params['amount_to_capture'] = $amountToCapture;
        }
        try {
            $resp = StripePaymentAPI::httpRequest(
                'POST', '/v1/payment_intents/' . urlencode($pi) . '/capture', $params);
            return ['ok' => true, 'payment_intent' => $resp];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function cancelIntent(string $piId): array {
        $pi = trim($piId);
        if ($pi === '') return ['ok' => false, 'error' => 'Missing PaymentIntent.'];
        try {
            $resp = StripePaymentAPI::httpRequest(
                'POST', '/v1/payment_intents/' . urlencode($pi) . '/cancel', []);
            return ['ok' => true, 'payment_intent' => $resp];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Dev helpers (test mode only) ──────────────────────────

    /**
     * Simulate a card tap on a SIMULATED reader. Only works for readers of
     * type 'simulated' in test mode; lets you exercise the full flow without
     * hardware. No-op error in live mode (Stripe rejects the test endpoint).
     */
    public static function simulatePresentCard(string $readerId): array {
        $rid = trim($readerId);
        if ($rid === '') return ['ok' => false, 'error' => 'Missing reader.'];
        try {
            $resp = StripePaymentAPI::httpRequest(
                'POST', '/v1/test_helpers/terminal/readers/' . urlencode($rid) . '/present_payment_method', []);
            return ['ok' => true, 'reader' => $resp];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Create a simulated reader (test mode) so the flow is exercisable. */
    public static function createSimulatedReader(string $label, ?string $locationId = null): array {
        $loc = $locationId ?? self::locationId();
        if ($loc === '') return ['ok' => false, 'error' => 'Create a Terminal Location first.'];
        try {
            $resp = StripePaymentAPI::httpRequest('POST', '/v1/terminal/readers', [
                'registration_code' => 'simulated-wpe',
                'location'          => $loc,
                'label'             => $label !== '' ? mb_substr($label, 0, 120) : 'Simulated reader',
            ]);
            return ['ok' => true, 'reader' => $resp];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Connection token — only needed for a browser/JS Terminal SDK
     * (client-driven) flow. Provided for completeness; the POS uses the
     * server-driven path above and does not need this.
     */
    public static function createConnectionToken(): array {
        try {
            $resp = StripePaymentAPI::httpRequest('POST', '/v1/terminal/connection_tokens', []);
            return ['ok' => true, 'secret' => (string)($resp['secret'] ?? '')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
