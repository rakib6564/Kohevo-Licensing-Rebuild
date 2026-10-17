<?php
/**
 * Settings audit: the Stripe secret key and webhook signing secret are
 * write-only on plugins/stripe-payment/admin/settings.php.
 *
 * Drives the real POST handler and GET render in a child process (the same
 * admin-page probes the other Stripe tests use) and checks the stored rows:
 *   - a blank secret field keeps the stored secret instead of wiping it
 *   - a secret saved before encryption at rest (plaintext) is upgraded to an
 *     enc:v1 envelope on that save and still decrypts to the same key
 *   - the page never renders the stored secret back into the HTML
 *
 * The publishable key is posted unchanged and the secret left blank, so the
 * handler's "keys changed" branch (which calls Stripe) is never taken.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/stripe-payment/StripePayment.php';

/** Snapshot every stripe-payment.* setting for this tenant, return the exact undo. */
function swos_snapshot(): callable
{
    $tid = current_tenant_id();
    $rows = Database::rows(
        "SELECT setting_key, setting_value FROM settings WHERE tenant_id = ? AND setting_key LIKE 'stripe-payment.%'",
        [$tid]
    );
    return static function () use ($tid, $rows): void {
        Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key LIKE 'stripe-payment.%'", [$tid]);
        foreach ($rows as $row) {
            Database::setSetting($row['setting_key'], $row['setting_value'], $tid);
        }
    };
}

function swos_stored(string $key): string
{
    return (string) Database::value(
        'SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ?',
        [current_tenant_id(), 'stripe-payment.' . $key]
    );
}

/** Run the settings page in a child process. $fields = null means GET. Returns [status, body]. */
function swos_probe(?array $fields): array
{
    $fixture = $fields === null ? 'admin-page-probe.php' : 'admin-page-post-probe.php';
    $second  = $fields === null ? '' : (string) json_encode($fields);
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/' . $fixture) . ' '
         . escapeshellarg('plugins/stripe-payment/admin/settings.php') . ' '
         . escapeshellarg($second) . ' '
         . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('probe produced no STATUS line: ' . substr($out, 0, 400));
    }
    return [(int) $m[1], substr($out, strlen($m[0]))];
}

/** Seed test-mode keys exactly as an install from before encryption at rest would hold them. */
function swos_seed_legacy_plaintext(string $pub, string $secret, string $webhook): void
{
    Database::setSetting('stripe-payment.mode', 'test');
    foreach (['publishable_key' => $pub, 'secret_key' => $secret, 'webhook_secret' => $webhook] as $k => $v) {
        Database::setSetting('stripe-payment.' . $k, $v);
        Database::setSetting('stripe-payment.test_' . $k, $v);
    }
    Database::setSetting('stripe-payment.verified_at', slate_db_now());
}

unit('stripe settings: a blank secret field keeps the stored keys and upgrades legacy plaintext to an envelope', function (): void {
    if (!slate_has_app_secret()) { assert_true(true, 'skipped: APP_SECRET not configured in the test environment'); return; }
    $restore = swos_snapshot();
    $pub     = 'pk_test_swos' . bin2hex(random_bytes(10));
    $secret  = 'sk_test_swos' . bin2hex(random_bytes(10));
    $webhook = 'whsec_swos' . bin2hex(random_bytes(10));
    try {
        swos_seed_legacy_plaintext($pub, $secret, $webhook);

        [$status, $body] = swos_probe([
            'mode' => 'test', 'publishable_key' => $pub, 'secret_key' => '', 'webhook_secret' => '',
        ]);
        assert_eq(200, $status, 'settings save must succeed');
        assert_false(str_contains($body, $secret), 'the response must not contain the secret key');
        assert_false(str_contains($body, $webhook), 'the response must not contain the webhook secret');

        foreach (['secret_key' => $secret, 'webhook_secret' => $webhook] as $k => $plain) {
            foreach (['test_' . $k, $k] as $slot) {
                $stored = swos_stored($slot);
                assert_true(str_starts_with($stored, 'enc:v1:'), "{$slot} must be stored as an encryption envelope after the save");
                assert_false(str_contains($stored, $plain), "{$slot} must not contain the plaintext");
                assert_eq($plain, slate_decrypt_secret($stored), "{$slot} must still decrypt to the original value");
            }
        }
        assert_eq($secret, StripePaymentAPI::secretKey(), 'the runtime still resolves the same secret key');
        assert_eq($webhook, StripePaymentAPI::webhookSecret(), 'the runtime still resolves the same webhook secret');
    } finally {
        $restore();
    }
});

unit('stripe settings: an already-encrypted secret is kept byte-for-byte when the field is left blank', function (): void {
    if (!slate_has_app_secret()) { assert_true(true, 'skipped: APP_SECRET not configured in the test environment'); return; }
    $restore = swos_snapshot();
    $pub    = 'pk_test_swos' . bin2hex(random_bytes(10));
    $secret = 'sk_test_swos' . bin2hex(random_bytes(10));
    try {
        swos_seed_legacy_plaintext($pub, '', '');
        $envelope = slate_encrypt_secret($secret);
        Database::setSetting('stripe-payment.secret_key', $envelope);
        Database::setSetting('stripe-payment.test_secret_key', $envelope);

        [$status] = swos_probe(['mode' => 'test', 'publishable_key' => $pub, 'secret_key' => '', 'webhook_secret' => '']);
        assert_eq(200, $status);
        assert_eq($envelope, swos_stored('test_secret_key'), 'the stored envelope must not be rewritten or wiped');
        assert_eq('', swos_stored('test_webhook_secret'), 'an empty webhook secret stays empty');
    } finally {
        $restore();
    }
});

unit('stripe settings: the page shows that a secret is configured without rendering it', function (): void {
    if (!slate_has_app_secret()) { assert_true(true, 'skipped: APP_SECRET not configured in the test environment'); return; }
    $restore = swos_snapshot();
    $pub     = 'pk_test_swos' . bin2hex(random_bytes(10));
    $secret  = 'sk_test_swos' . bin2hex(random_bytes(10));
    $webhook = 'whsec_swos' . bin2hex(random_bytes(10));
    try {
        swos_seed_legacy_plaintext($pub, slate_encrypt_secret($secret), slate_encrypt_secret($webhook));

        [$status, $body] = swos_probe(null);
        assert_eq(200, $status);
        assert_false(str_contains($body, $secret), 'the secret key must not appear anywhere in the page');
        assert_false(str_contains($body, $webhook), 'the webhook secret must not appear anywhere in the page');
        assert_true((bool) preg_match('/name="secret_key"\s+value=""/', $body), 'the secret key input renders empty');
        assert_true(str_contains($body, 'Leave blank to keep existing secret'), 'the field explains that blank keeps the stored secret');
    } finally {
        $restore();
    }
});

unit('stripe settings: Disconnect clears the keys of BOTH modes and leaves unrelated settings alone', function (): void {
    if (!slate_has_app_secret()) { assert_true(true, 'skipped: APP_SECRET not configured in the test environment'); return; }
    $restore = swos_snapshot();
    try {
        Database::setSetting('stripe-payment.mode', 'live');
        foreach (['test', 'live'] as $m) {
            Database::setSetting("stripe-payment.{$m}_publishable_key", "pk_{$m}_swos" . bin2hex(random_bytes(6)));
            Database::setSetting("stripe-payment.{$m}_secret_key", slate_encrypt_secret("sk_{$m}_swos" . bin2hex(random_bytes(6))));
            Database::setSetting("stripe-payment.{$m}_webhook_secret", slate_encrypt_secret('whsec_swos' . bin2hex(random_bytes(6))));
        }
        Database::setSetting('stripe-payment.secret_key', slate_encrypt_secret('sk_live_legacy'));
        Database::setSetting('stripe-payment.allowed_countries', 'BE,NL');
        Database::setSetting('stripe-payment.wallet_link', '1');
        assert_true(StripePaymentAPI::isConfigured(), 'precondition: Stripe is configured');

        [$status] = swos_probe(['_action' => 'clear_keys']);
        assert_eq(200, $status);

        foreach (['test', 'live'] as $m) {
            foreach (['publishable_key', 'secret_key', 'webhook_secret'] as $k) {
                assert_eq('', swos_stored("{$m}_{$k}"), "{$m}_{$k} must be cleared");
            }
        }
        foreach (['publishable_key', 'secret_key', 'webhook_secret', 'mode'] as $k) {
            assert_eq('', swos_stored($k), "legacy {$k} must be cleared");
        }
        assert_false(StripePaymentAPI::isConfigured(), 'Stripe must no longer be configured after Disconnect');
        assert_eq('BE,NL', swos_stored('allowed_countries'), 'unrelated settings are preserved');
        assert_eq('1', swos_stored('wallet_link'), 'unrelated toggles are preserved');
        $audited = (int) Database::value(
            "SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = 'stripe.keys_cleared' AND created_at >= NOW() - INTERVAL 5 MINUTE",
            [current_tenant_id()]
        );
        assert_true($audited >= 1, 'Disconnect must be recorded in the audit log');
    } finally {
        $restore();
    }
});
