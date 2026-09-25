<?php
/**
 * Phase 1D B2 — SMTP OAuth access token was stored and read as plaintext.
 *
 * includes/SmtpOAuth.php's accessToken() cached the short-lived OAuth access
 * token in the settings table with no encryption, and admin/oauth_callback.php
 * did the same when first persisting the token from the code exchange —
 * unlike smtp_oauth_client_secret and smtp_oauth_refresh_token, both already
 * routed through slate_encrypt_secret()/slate_decrypt_secret() at every write
 * and read site in these same two files. The access token's lifetime is short
 * (provider-defined, typically ~1 hour), which bounds the exposure window
 * compared to the long-lived refresh token — but it is still a live bearer
 * credential for the connected mailbox (or, for Microsoft, live Graph API
 * access), and there was no reason for it to be the one field left in the
 * clear next to two siblings that were already protected.
 *
 * Fix applies the exact existing convention already used for the client
 * secret and refresh token in these same two files: encrypt on write,
 * decrypt on read, guarded by the same function_exists() checks this file
 * already uses elsewhere (fromSettings()'s $dec closure, the refresh-token
 * persist a few lines below the fix). No new crypto, no schema change.
 */

declare(strict_types=1);

/** Snapshot every smtp_oauth_* / smtp_auth_type setting, return the exact undo. */
function soaet_snapshot(): callable
{
    $tid = current_tenant_id();
    $keys = [
        'smtp_auth_type', 'smtp_oauth_provider', 'smtp_oauth_client_id',
        'smtp_oauth_client_secret', 'smtp_oauth_refresh_token', 'smtp_oauth_email',
        'smtp_oauth_access_token', 'smtp_oauth_expires',
    ];
    $prior = [];
    foreach ($keys as $k) {
        $prior[$k] = Database::value(
            'SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ?',
            [$tid, $k]
        );
    }
    return static function () use ($tid, $keys, $prior): void {
        foreach ($keys as $k) {
            if ($prior[$k] === null || $prior[$k] === false) {
                Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?', [$tid, $k]);
            } else {
                Database::setSetting($k, (string) $prior[$k], $tid);
            }
        }
    };
}

unit('SmtpOAuth::fromSettings()->bearerToken() decrypts the cached access token, never returns the raw stored ciphertext', function (): void {
    $restore = soaet_snapshot();
    $plaintext = 'ya29.probe_access_token_' . bin2hex(random_bytes(12));

    try {
        // Minimum fields for fromSettings() to build an instance and for
        // accessToken() to hit its cache branch without a network refresh.
        Database::setSetting('smtp_auth_type', 'xoauth2');
        Database::setSetting('smtp_oauth_provider', 'google');
        Database::setSetting('smtp_oauth_client_id', 'probe-client-id');
        Database::setSetting('smtp_oauth_client_secret', slate_encrypt_secret('probe-client-secret'));
        Database::setSetting('smtp_oauth_refresh_token', slate_encrypt_secret('probe-refresh-token'));
        Database::setSetting('smtp_oauth_email', 'probe@example.test');
        Database::setSetting('smtp_oauth_access_token', slate_encrypt_secret($plaintext));
        Database::setSetting('smtp_oauth_expires', (string) (time() + 3600));

        $oauth = SmtpOAuth::fromSettings();
        assert_true($oauth !== null, 'fromSettings() must build an instance from a fully-configured xoauth2 setup');

        $tid = current_tenant_id();
        $storedRaw = (string) Database::value(
            'SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ?',
            [$tid, 'smtp_oauth_access_token']
        );
        assert_true(str_starts_with($storedRaw, 'enc:v1:'), 'the stored value must carry the standard encryption envelope, matching the client secret / refresh token');
        assert_false(str_contains($storedRaw, $plaintext), 'the plaintext access token must not appear anywhere in the stored ciphertext');

        assert_eq($plaintext, $oauth->bearerToken(), 'bearerToken() must decrypt the cached value back to the exact original token (no network refresh needed — cache is still valid)');
    } finally {
        $restore();
    }
});

unit('SmtpOAuth.php: accessToken() encrypts on write and decrypts on read (fix present in the shipped file)', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/includes/SmtpOAuth.php');

    $fnPos = strpos($src, 'private function accessToken(): string {');
    assert_true($fnPos !== false, 'accessToken() must still exist');
    $fnEnd = strpos($src, "\n        }", $fnPos);
    $fnBody = substr($src, $fnPos, ($fnEnd !== false ? $fnEnd - $fnPos : 800));

    assert_true(
        str_contains($fnBody, "slate_decrypt_secret(\$cachedRaw)"),
        'accessToken() must decrypt the cached value via slate_decrypt_secret() before using it'
    );
    assert_true(
        str_contains($fnBody, "slate_encrypt_secret(\$access)"),
        'accessToken() must encrypt the freshly-refreshed token via slate_encrypt_secret() before persisting it'
    );
});

unit('admin/oauth_callback.php: the freshly-exchanged access token is encrypted before being persisted (fix present in the shipped file)', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/admin/oauth_callback.php');

    $encVarPos    = strpos($src, "\$enc = function_exists('slate_encrypt_secret');");
    $accessSetPos = strpos($src, "Database::setSetting('smtp_oauth_access_token', \$enc ? slate_encrypt_secret(\$accessToken) : \$accessToken);");

    assert_true($encVarPos !== false, 'the existing $enc capability-check must still be present');
    assert_true($accessSetPos !== false, 'the access-token persist must route through the same $enc ? slate_encrypt_secret(...) convention as the client secret and refresh token');
    assert_true($encVarPos < $accessSetPos, 'the capability check must be defined before the access-token persist uses it');
});
