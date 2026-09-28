<?php
/**
 * Stripe Payment — generic API facade (Phase 2).
 *
 * The class plugins should call when they want to take a payment via
 * Stripe. Pre-Phase-2, `StripeAPI` did this for Shop specifically;
 * `StripePaymentAPI` is the slug-agnostic version any plugin can use.
 *
 *   $session = StripePaymentAPI::createCheckout(
 *       lineItems: [
 *           ['name' => 'Strategy session', 'amount_cents' => 15000, 'quantity' => 1],
 *           ['name' => 'Booking deposit',  'amount_cents' =>  5000, 'quantity' => 1],
 *       ],
 *       opts: [
 *           'customer_email' => $customer['email'],
 *           'success_url'    => SLATE_URL . '/book/thanks?ref=' . $ref,
 *           'cancel_url'     => SLATE_URL . '/book?cancelled=1',
 *           'currency'       => 'usd',
 *           'metadata'       => [
 *               'source_plugin' => 'booking',
 *               'source_id'     => (string)$appointmentId,
 *               'ref'           => $ref,
 *           ],
 *       ]
 *   );
 *   // → ['session_id' => 'cs_test_…', 'url' => 'https://checkout.stripe.com/…']
 *   header('Location: ' . $session['url']); exit;
 *
 * After the customer pays, Stripe fires a webhook. The Stripe Payment
 * plugin's webhook endpoint dispatches a `stripe_webhook_event` action
 * with the parsed event payload. Consuming plugins listen:
 *
 *   Hook::addAction('stripe_webhook_event', function (array $event) {
 *       if ($event['type'] !== 'checkout.session.completed') return;
 *       $session = $event['data']['object'] ?? [];
 *       $meta    = $session['metadata'] ?? [];
 *       if (($meta['source_plugin'] ?? '') !== 'booking') return;
 *       // …mark the appointment paid, send a receipt…
 *   });
 *
 * On any successful checkout we also insert a row into the
 * `stripepayment_charges` table so the admin Charges page can show
 * everything across plugins, and refunds become a one-liner.
 *
 * Modes:
 *   stripe-payment.mode = 'test' | 'live'
 *   The class reads (test_|live_)secret_key + publishable_key + webhook_secret.
 *   Legacy `secret_key`/`publishable_key`/`webhook_secret` (no prefix)
 *   are honoured as fallbacks so existing installs keep working.
 */

class StripePaymentAPI {

    private const API_BASE    = 'https://api.stripe.com';
    private const API_VERSION = '2024-06-20';

    /** Set true once per request after the charges table is ensured. */
    private static bool $chargesSchemaChecked = false;

    // ── Mode + key resolution ────────────────────────────────

    public static function mode(): string {
        $m = (string) Database::setting('stripe-payment.mode');
        return $m === 'live' ? 'live' : 'test';
    }

    public static function secretKey(): string {
        return self::keyByMode('secret_key');
    }
    public static function publishableKey(): string {
        return self::keyByMode('publishable_key');
    }
    public static function webhookSecret(): string {
        return self::keyByMode('webhook_secret');
    }

    private static function keyByMode(string $name): string {
        $prefix = self::mode();   // 'test' or 'live'
        $modal  = (string) Database::setting('stripe-payment.' . $prefix . '_' . $name);
        // Legacy single-key fallback (pre-Phase-2 installs that only
        // ever held one set of keys).
        $raw = $modal !== '' ? $modal : (string) Database::setting('stripe-payment.' . $name);

        // Secret material (secret + webhook signing keys) is stored
        // encrypted (enc:v1:… envelope). The publishable key is public and
        // stored as-is. slate_decrypt_secret() returns its input unchanged
        // when it isn't an envelope, so legacy plaintext keys keep working
        // and are transparently re-encrypted next time settings are saved.
        if ($raw !== '' && ($name === 'secret_key' || $name === 'webhook_secret')
            && function_exists('slate_decrypt_secret')) {
            return (string) (slate_decrypt_secret($raw) ?? '');
        }
        return $raw;
    }

    public static function isConfigured(): bool {
        return self::secretKey() !== '' && self::publishableKey() !== '';
    }

    // ── Checkout ─────────────────────────────────────────────

    /**
     * Create a Stripe Checkout Session over a list of line items.
     *
     * $lineItems is a list of arrays:
     *   ['name' => string,           // required, max 250 chars
     *    'amount_cents' => int,      // required, positive (zero-decimal currencies use raw amount)
     *    'quantity' => int,          // default 1
     *    'description' => string]    // optional
     *
     * $opts:
     *   'currency'       string default 'usd'
     *   'customer_email' string
     *   'success_url'    string required
     *   'cancel_url'     string required
     *   'metadata'       array<string,string>
     *   'collect_shipping_address' bool (default false)
     *   'allowed_countries' array<string> (only if collect_shipping_address)
     *
     * Returns ['session_id' => 'cs_…', 'url' => '…'] or throws.
     */
    public static function createCheckout(array $lineItems, array $opts): array {
        if (!self::isConfigured()) {
            throw new \RuntimeException('Stripe is not configured.');
        }
        if (empty($lineItems)) {
            throw new \RuntimeException('No line items.');
        }
        if (empty($opts['success_url']) || empty($opts['cancel_url'])) {
            throw new \RuntimeException('success_url and cancel_url are required.');
        }

        $currency = strtolower((string)($opts['currency'] ?? 'usd'));

        $params = [
            'mode'        => 'payment',
            'success_url' => (string)$opts['success_url'],
            'cancel_url'  => (string)$opts['cancel_url'],
        ];

        // Bancontact is delivered by Stripe, so it belongs in this gateway
        // rather than in a second, parallel payment integration. Checkout
        // otherwise uses Stripe's Dashboard-configured automatic methods.
        // When the admin expressly enables Bancontact, pin the compatible
        // method pair for EUR checkouts. The card fallback keeps Checkout
        // usable for customers who do not select Bancontact.
        if ($currency === 'eur'
            && (Database::setting('stripe-payment.method_bancontact') ?? '0') === '1') {
            $params['payment_method_types[0]'] = 'card';
            $params['payment_method_types[1]'] = 'bancontact';
        }

        $i = 0;
        foreach ($lineItems as $li) {
            $name   = mb_substr((string)($li['name'] ?? 'Item'), 0, 250);
            $amount = (int)($li['amount_cents'] ?? 0);
            $qty    = max(1, (int)($li['quantity'] ?? 1));
            if ($amount <= 0) continue;

            $params["line_items[$i][quantity]"]                      = $qty;
            $params["line_items[$i][price_data][currency]"]          = $currency;
            $params["line_items[$i][price_data][unit_amount]"]       = $amount;
            $params["line_items[$i][price_data][product_data][name]"] = $name;
            if (!empty($li['description'])) {
                $params["line_items[$i][price_data][product_data][description]"]
                    = mb_substr((string)$li['description'], 0, 500);
            }
            $i++;
        }
        if ($i === 0) {
            throw new \RuntimeException('All line items had zero or negative amounts.');
        }

        if (!empty($opts['customer_email'])) {
            $params['customer_email'] = (string)$opts['customer_email'];
        }
        if (!empty($opts['metadata']) && is_array($opts['metadata'])) {
            foreach ($opts['metadata'] as $mk => $mv) {
                $params["metadata[$mk]"] = (string)$mv;
            }
        }

        if (!empty($opts['collect_shipping_address'])) {
            $countries = $opts['allowed_countries']
                      ?? array_filter(array_map('trim', explode(',',
                            (string) Database::setting('stripe-payment.allowed_countries') ?: 'US,CA,GB,AU')));
            foreach (array_values((array)$countries) as $idx => $cc) {
                $params["shipping_address_collection[allowed_countries][$idx]"] = strtoupper((string)$cc);
            }
        }

        $resp = self::httpRequest('POST', '/v1/checkout/sessions', $params);
        if (empty($resp['id']) || empty($resp['url'])) {
            throw new \RuntimeException('Stripe response missing id/url.');
        }
        return ['session_id' => (string)$resp['id'], 'url' => (string)$resp['url']];
    }

    /**
     * Create a PaymentIntent for the embedded Payment Element flow.
     *
     * $opts:
     *   'currency'             string default 'usd'
     *   'receipt_email'        string
     *   'metadata'             array<string,string>
     *   'payment_method_types' array<string> — when given, restricts the
     *                          intent to exactly these methods instead of
     *                          using automatic_payment_methods. Use e.g.
     *                          ['card'] to suppress Link/redirect methods.
     *
     * Returns the raw PaymentIntent payload (id, client_secret, …).
     */
    public static function createPaymentIntent(int $amountCents, array $opts = []): array {
        if (!self::isConfigured()) {
            throw new \RuntimeException('Stripe is not configured.');
        }
        if ($amountCents <= 0) {
            throw new \RuntimeException('Amount must be positive.');
        }
        $params = [
            'amount'   => $amountCents,
            'currency' => strtolower((string)($opts['currency'] ?? 'usd')),
        ];
        // Either pin an explicit method list, or let Stripe pick from the
        // dashboard-enabled set. Card-based wallets (Apple/Google Pay) ride
        // on the 'card' type, so ['card'] keeps them while dropping Link.
        $pmTypes = $opts['payment_method_types'] ?? null;
        if (is_array($pmTypes) && $pmTypes) {
            foreach (array_values($pmTypes) as $idx => $t) {
                $params["payment_method_types[$idx]"] = (string)$t;
            }
        } else {
            $params['automatic_payment_methods[enabled]'] = 'true';
        }
        if (!empty($opts['receipt_email'])) {
            $params['receipt_email'] = (string)$opts['receipt_email'];
        }
        if (!empty($opts['metadata']) && is_array($opts['metadata'])) {
            foreach ($opts['metadata'] as $mk => $mv) {
                $params["metadata[$mk]"] = (string)$mv;
            }
        }
        $resp = self::httpRequest('POST', '/v1/payment_intents', $params);
        if (empty($resp['id']) || empty($resp['client_secret'])) {
            throw new \RuntimeException('Stripe PaymentIntent response missing fields.');
        }
        return $resp;
    }

    public static function getSession(string $sessionId): ?array {
        $id = trim($sessionId);
        if ($id === '') return null;
        try {
            return self::httpRequest('GET', '/v1/checkout/sessions/' . urlencode($id), []);
        } catch (\Throwable $e) {
            slate_log('StripePaymentAPI::getSession failed: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    public static function getPaymentIntent(string $piId): ?array {
        $id = trim($piId);
        if ($id === '') return null;
        try {
            return self::httpRequest('GET', '/v1/payment_intents/' . urlencode($id), []);
        } catch (\Throwable $e) {
            slate_log('StripePaymentAPI::getPaymentIntent failed: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    // ── Webhook signature + dispatch ─────────────────────────

    /**
     * Verify a Stripe webhook signature header (HMAC-SHA256).
     * Matches Stripe's t=…,v1=… format with replay-tolerance window.
     */
    public static function verifyWebhookSignature(
        string $payload,
        string $sigHeader,
        string $secret,
        int $tolerance = 300
    ): bool {
        if ($secret === '' || $sigHeader === '') return false;

        $parts = [];
        foreach (explode(',', $sigHeader) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            $k = trim($k); $v = trim($v);
            if ($k === '' || $v === '') continue;
            $parts[$k][] = $v;
        }
        if (empty($parts['t']) || empty($parts['v1'])) return false;
        $t = (int)$parts['t'][0];

        // Reject timestamps outside the tolerance window in EITHER direction.
        // Only guarding against too-old left future-dated timestamps (clock
        // skew or a forged header) accepted, weakening replay protection.
        if (abs(time() - $t) > $tolerance) return false;

        // anti-drift-ignore: HMAC — keyed on Stripe's webhook signing secret,
        // not APP_SECRET, and the format is dictated by Stripe (t.payload).
        // Emptiness is already refused at the top of this method, so there is
        // no path here that signs under a blank key.
        // anti-drift-ignore: HMAC — Stripe's webhook signature scheme, keyed on
        // the endpoint's signing secret, not APP_SECRET. The payload format is
        // dictated by Stripe; $secret is already refused when empty upstream in
        // dispatchWebhook(), so this path cannot run under a blank key.
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        foreach ($parts['v1'] as $candidate) {
            if (hash_equals($expected, (string)$candidate)) return true;
        }
        return false;
    }

    /**
     * Verify a webhook payload + signature, parse the event, and fire
     * the `stripe_webhook_event` action so any number of consuming
     * plugins can react. Returns the parsed event array (or null on
     * verification failure).
     *
     * Listeners receive ONE argument — the full event array.
     */
    public static function dispatchWebhook(string $payload, string $sigHeader): ?array {
        $secret = self::webhookSecret();
        if ($secret === '') { self::recordWebhookHealth('no_secret'); return null; }
        if (!self::verifyWebhookSignature($payload, $sigHeader, $secret)) {
            self::recordWebhookHealth('bad_signature');
            return null;
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['type'])) {
            self::recordWebhookHealth('unreadable_payload');
            return null;
        }

        self::recordWebhookHealth('ok', (string) $event['type']);
        Hook::doAction('stripe_webhook_event', $event);
        return $event;
    }

    /**
     * Remember the outcome of the last webhook delivery.
     *
     * A rejected webhook is otherwise completely silent: Stripe sees a 400 and
     * retries into the void while the site looks healthy. That is exactly how
     * an empty signing secret went unnoticed here until a parent paid tuition
     * and the enrollment stayed unpaid. Settings rather than a table, so this
     * needs no migration; counters are cheap and the admin can show "last
     * delivery: rejected, 4 minutes ago" instead of nothing at all.
     *
     * Never throws — a health note must not be the reason a webhook fails.
     */
    public static function recordWebhookHealth(string $outcome, string $eventType = ''): void {
        try {
            $ok  = $outcome === 'ok';
            $pre = 'stripe-payment.wh_' . ($ok ? 'ok' : 'fail');
            $n   = (int) Database::setting($pre . '_count');
            Database::setSetting($pre . '_count', (string) ($n + 1));
            Database::setSetting($pre . '_at', slate_db_now());
            if ($ok) {
                Database::setSetting('stripe-payment.wh_ok_type', $eventType);
            } else {
                Database::setSetting('stripe-payment.wh_fail_reason', $outcome);
            }
        } catch (\Throwable $e) {
            // Deliberately swallowed.
        }
    }

    /**
     * Webhook health for the settings panel.
     *
     * @return array{secret:bool,ok_count:int,ok_at:string,ok_type:string,
     *               fail_count:int,fail_at:string,fail_reason:string,state:string}
     */
    public static function webhookHealth(): array {
        $s = static fn (string $k): string => (string) Database::setting('stripe-payment.' . $k);
        $h = [
            'secret'       => self::webhookSecret() !== '',
            'ok_count'     => (int) $s('wh_ok_count'),
            'ok_at'        => $s('wh_ok_at'),
            'ok_type'      => $s('wh_ok_type'),
            'fail_count'   => (int) $s('wh_fail_count'),
            'fail_at'      => $s('wh_fail_at'),
            'fail_reason'  => $s('wh_fail_reason'),
        ];

        // The distinction that matters: nothing has ever arrived (Stripe has no
        // endpoint, or it can't reach us) versus deliveries arriving and being
        // thrown away (secret wrong or missing). They need different fixes.
        if (!$h['secret'])                       { $h['state'] = 'no_secret'; }
        elseif ($h['ok_count'] === 0 && $h['fail_count'] === 0) { $h['state'] = 'never'; }
        elseif ($h['fail_at'] > $h['ok_at'])     { $h['state'] = 'rejecting'; }
        else                                     { $h['state'] = 'ok'; }

        return $h;
    }

    // ── Charges ──────────────────────────────────────────────

    /**
     * Lazily create the stripepayment_charges table on first access
     * so existing installs auto-migrate without a deploy step.
     */
    public static function ensureChargesSchema(): void {
        if (self::$chargesSchemaChecked) return;
        self::$chargesSchemaChecked = true;
        try {
            Database::get()->exec(
                "CREATE TABLE IF NOT EXISTS `stripepayment_charges` (
                    `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`                INT UNSIGNED NOT NULL DEFAULT 1,
                    `mode`                     ENUM('test','live') NOT NULL DEFAULT 'test',
                    `source_plugin`            VARCHAR(80) NOT NULL,
                    `source_id`                VARCHAR(190) NULL,
                    `stripe_payment_intent_id` VARCHAR(255) NULL,
                    `stripe_session_id`        VARCHAR(255) NULL,
                    `customer_email`           VARCHAR(200) NULL,
                    `amount_cents`             INT UNSIGNED NOT NULL DEFAULT 0,
                    `refunded_cents`           INT UNSIGNED NOT NULL DEFAULT 0,
                    `currency`                 VARCHAR(8) NOT NULL DEFAULT 'USD',
                    `status`                   ENUM('succeeded','refunded','partial_refund','failed') NOT NULL DEFAULT 'succeeded',
                    `meta_json`                LONGTEXT NULL,
                    `created_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_tenant_created`   (`tenant_id`, `created_at`),
                    KEY `idx_source`           (`tenant_id`, `source_plugin`, `source_id`),
                    UNIQUE KEY `uniq_pi`       (`tenant_id`, `stripe_payment_intent_id`),
                    UNIQUE KEY `uniq_session`  (`tenant_id`, `stripe_session_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            slate_log('StripePaymentAPI: ensure charges schema failed: ' . $e->getMessage(), 'error');
        }

        // Migrate existing installs from the old non-unique idx_pi/idx_session
        // to unique keys so concurrent webhook retries can't double-insert a
        // charge (NULL PI/session rows are exempt — MySQL allows many NULLs in
        // a UNIQUE key). If legacy duplicates already exist the ALTER throws;
        // we log and leave the non-unique index in place.
        foreach ([
            ['idx_pi',      'uniq_pi',      'stripe_payment_intent_id'],
            ['idx_session', 'uniq_session', 'stripe_session_id'],
        ] as [$oldIdx, $newIdx, $col]) {
            try {
                $hasNew = (int) Database::value(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'stripepayment_charges'
                        AND INDEX_NAME = ?", [$newIdx]);
                if ($hasNew > 0) continue;
                $hasOld = (int) Database::value(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'stripepayment_charges'
                        AND INDEX_NAME = ?", [$oldIdx]);
                if ($hasOld > 0) {
                    Database::get()->exec("ALTER TABLE `stripepayment_charges` DROP INDEX `{$oldIdx}`");
                }
                Database::get()->exec(
                    "ALTER TABLE `stripepayment_charges` ADD UNIQUE KEY `{$newIdx}` (`tenant_id`, `{$col}`)");
            } catch (\Throwable $e) {
                slate_log("StripePaymentAPI: could not add unique key {$newIdx} "
                    . '(legacy duplicate charges?): ' . $e->getMessage(), 'warning');
            }
        }
    }

    /**
     * Insert a row in stripepayment_charges. Idempotent on
     * (stripe_payment_intent_id) OR (stripe_session_id) — a repeated
     * webhook delivery won't double-insert.
     *
     * Required keys: source_plugin, amount_cents.
     * One of stripe_payment_intent_id or stripe_session_id should be set.
     */
    public static function recordCharge(array $args): ?int {
        self::ensureChargesSchema();
        $tid = current_tenant_id();

        $piId  = trim((string)($args['stripe_payment_intent_id'] ?? ''));
        $csId  = trim((string)($args['stripe_session_id']        ?? ''));

        // Idempotency
        if ($piId !== '') {
            $existing = Database::row(
                "SELECT id FROM stripepayment_charges WHERE stripe_payment_intent_id = ? AND tenant_id = ?",
                [$piId, $tid]
            );
            if ($existing) return (int)$existing['id'];
        }
        if ($csId !== '') {
            $existing = Database::row(
                "SELECT id FROM stripepayment_charges WHERE stripe_session_id = ? AND tenant_id = ?",
                [$csId, $tid]
            );
            if ($existing) return (int)$existing['id'];
        }

        $meta = $args['meta'] ?? null;
        try {
            return Database::insert('stripepayment_charges', [
                'tenant_id'                => $tid,
                'mode'                     => self::mode(),
                'source_plugin'            => mb_substr((string)($args['source_plugin'] ?? 'unknown'), 0, 80),
                'source_id'                => isset($args['source_id']) ? mb_substr((string)$args['source_id'], 0, 190) : null,
                'stripe_payment_intent_id' => $piId !== '' ? $piId : null,
                'stripe_session_id'        => $csId !== '' ? $csId : null,
                'customer_email'           => isset($args['customer_email']) ? mb_substr((string)$args['customer_email'], 0, 200) : null,
                'amount_cents'             => max(0, (int)($args['amount_cents'] ?? 0)),
                'currency'                 => strtoupper(mb_substr((string)($args['currency'] ?? 'USD'), 0, 8)),
                'status'                   => in_array(($args['status'] ?? 'succeeded'), ['succeeded','refunded','partial_refund','failed'], true)
                                              ? $args['status'] : 'succeeded',
                'meta_json'                => $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (\PDOException $e) {
            // A concurrent webhook won the race on the unique key. Re-read
            // and return the row it inserted instead of erroring.
            if ($e->getCode() === '23000') {
                if ($piId !== '') {
                    $row = Database::row(
                        "SELECT id FROM stripepayment_charges WHERE stripe_payment_intent_id = ? AND tenant_id = ?",
                        [$piId, $tid]);
                    if ($row) return (int)$row['id'];
                }
                if ($csId !== '') {
                    $row = Database::row(
                        "SELECT id FROM stripepayment_charges WHERE stripe_session_id = ? AND tenant_id = ?",
                        [$csId, $tid]);
                    if ($row) return (int)$row['id'];
                }
            }
            slate_log('StripePaymentAPI::recordCharge failed: ' . $e->getMessage(), 'error');
            return null;
        } catch (\Throwable $e) {
            slate_log('StripePaymentAPI::recordCharge failed: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    public static function getCharge(int $id): ?array {
        self::ensureChargesSchema();
        return Database::row(
            "SELECT * FROM stripepayment_charges WHERE id = ? AND tenant_id = ?",
            [$id, current_tenant_id()]
        );
    }

    /**
     * Fetch a paginated list of charges with optional filters.
     * $filters: source_plugin, mode, status, email_like, days
     */
    public static function listCharges(array $filters = [], int $limit = 100): array {
        self::ensureChargesSchema();
        $where  = ['tenant_id = ?'];
        $params = [current_tenant_id()];

        if (!empty($filters['source_plugin'])) { $where[]='source_plugin = ?'; $params[]=$filters['source_plugin']; }
        if (!empty($filters['mode']) && in_array($filters['mode'], ['test','live'], true)) {
            $where[] = 'mode = ?'; $params[] = $filters['mode'];
        }
        if (!empty($filters['status'])) { $where[]='status = ?'; $params[]=$filters['status']; }
        if (!empty($filters['email_like'])) {
            $where[] = 'customer_email LIKE ?'; $params[] = '%' . $filters['email_like'] . '%';
        }
        if (!empty($filters['days']) && (int)$filters['days'] > 0) {
            $where[] = 'created_at >= NOW() - INTERVAL ' . (int)$filters['days'] . ' DAY';
        }
        $sql = "SELECT * FROM stripepayment_charges
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY created_at DESC LIMIT " . max(1, min(500, $limit));
        return Database::rows($sql, $params);
    }

    /**
     * Refund a charge via Stripe + update local row.
     * If $amountCents is null, refunds the remaining un-refunded amount.
     *
     * Returns ['ok'=>true, 'refund_id'=>'re_…', 'refunded_cents'=>int]
     *      or ['ok'=>false, 'error'=>string].
     */
    public static function refundCharge(int $chargeId, ?int $amountCents = null): array {
        self::ensureChargesSchema();
        $row = self::getCharge($chargeId);
        if (!$row) return ['ok' => false, 'error' => 'Charge not found.'];

        $piId = (string)($row['stripe_payment_intent_id'] ?? '');
        if ($piId === '') {
            // Best-effort: look up the PaymentIntent from the session.
            $csId = (string)($row['stripe_session_id'] ?? '');
            if ($csId !== '') {
                $sess = self::getSession($csId);
                if (!empty($sess['payment_intent'])) {
                    $piId = (string)$sess['payment_intent'];
                }
            }
        }
        if ($piId === '') return ['ok' => false, 'error' => 'No PaymentIntent to refund against.'];

        $remaining = max(0, (int)$row['amount_cents'] - (int)$row['refunded_cents']);
        if ($remaining <= 0) return ['ok' => false, 'error' => 'Already fully refunded.'];
        $refundAmount = ($amountCents === null) ? $remaining : (int)$amountCents;
        if ($refundAmount <= 0 || $refundAmount > $remaining) {
            return ['ok' => false, 'error' => 'Refund amount out of range.'];
        }

        $params = ['payment_intent' => $piId];
        if ($refundAmount < $remaining) {
            $params['amount'] = $refundAmount;
        }
        try {
            $resp = self::httpRequest('POST', '/v1/refunds', $params);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (empty($resp['id']) || ($resp['status'] ?? '') === 'failed') {
            return ['ok' => false, 'error' => 'Stripe refund failed: ' . ($resp['failure_reason'] ?? 'unknown')];
        }

        $newRefunded = (int)$row['refunded_cents'] + $refundAmount;
        $newStatus   = ($newRefunded >= (int)$row['amount_cents']) ? 'refunded' : 'partial_refund';

        Database::update('stripepayment_charges', [
            'refunded_cents' => $newRefunded,
            'status'         => $newStatus,
        ], 'id = ?', [$chargeId]);

        AuditLog::record('stripe.refunded', (string)$chargeId, [
            'refund_id' => $resp['id'],
            'amount'    => $refundAmount,
        ]);
        return ['ok' => true, 'refund_id' => (string)$resp['id'], 'refunded_cents' => $newRefunded];
    }

    // ── HTTP transport ───────────────────────────────────────

    public static function httpRequest(string $method, string $path, array $params = []): array {
        $secret = self::secretKey();
        if ($secret === '') throw new \RuntimeException('Stripe secret key not configured.');
        if (!function_exists('curl_init')) throw new \RuntimeException('PHP cURL extension is required.');

        $url = self::API_BASE . $path;
        $ch  = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secret,
                'Stripe-Version: ' . self::API_VERSION,
            ],
            CURLOPT_USERAGENT      => 'Slate-Stripe-Payment/1.2',
        ]);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS,
                http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        } else {
            $qs = $params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '';
            curl_setopt($ch, CURLOPT_URL, $url . $qs);
        }

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Stripe HTTP transport error: ' . $err);
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Stripe response was not JSON (HTTP ' . $code . '): '
                . substr((string)$body, 0, 200));
        }
        if ($code >= 400) {
            $msg = $json['error']['message'] ?? ('HTTP ' . $code);
            throw new \RuntimeException('Stripe API error: ' . $msg);
        }
        return $json;
    }
}
