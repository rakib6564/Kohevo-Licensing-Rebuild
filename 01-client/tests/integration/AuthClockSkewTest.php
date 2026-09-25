<?php
/**
 * Guards the PHP-clock vs MySQL-clock boundary in Auth.
 *
 * This host runs PHP in UTC and MySQL in SYSTEM (UTC-4). Any code that builds a
 * timestamp with one clock and compares it against the other is wrong by that
 * offset, and the failure is silent — the login throttle simply stopped
 * engaging, and auth tokens simply outlived their TTL. Neither raises an error.
 *
 * These tests assert the invariant rather than the offset, so they stay valid on
 * a host where the two clocks happen to agree (CI, for one) and still fail if
 * someone reintroduces PHP-side time arithmetic against a DB column.
 */

declare(strict_types=1);

use Slate\Tenancy\TenantContext;

define('_SKEW_TENANT', slate_test_tenant(918200));

unit('login throttle counts attempts regardless of PHP/MySQL clock skew', function (): void {
    $ip = slate_test_ip('198.51.100.201');
    $oldIp = $_SERVER['REMOTE_ADDR'] ?? null;
    $oldMax = Database::setting('max_login_attempts', _SKEW_TENANT);
    $oldMin = Database::setting('lockout_minutes', _SKEW_TENANT);

    Database::query('DELETE FROM login_attempts WHERE ip=?', [$ip]);
    try {
        Database::setSetting('max_login_attempts', '2', _SKEW_TENANT);
        Database::setSetting('lockout_minutes', '15', _SKEW_TENANT);

        (new TenantContext())->runAs(_SKEW_TENANT, function () use ($ip): void {
            $_SERVER['REMOTE_ADDR'] = $ip;

            assert_eq(0, Auth::loginBlockedSeconds('customer'), 'no attempts means no block');

            Auth::recordLoginFailure('customer', 'skew@example.test');
            assert_eq(0, Auth::loginBlockedSeconds('customer'), 'one attempt is below the threshold');

            Auth::recordLoginFailure('customer', 'skew@example.test');

            // The regression: attempted_at is written by MySQL's CURRENT_TIMESTAMP,
            // so a window built from PHP's clock excluded every row and this
            // returned 0 no matter how many failures were recorded.
            $blocked = Auth::loginBlockedSeconds('customer');
            assert_true($blocked > 0, 'threshold reached must block (got ' . $blocked . 's)');
            assert_true($blocked <= 15 * 60, 'block never exceeds lockout_minutes');

            Auth::clearLoginFailures('customer');
            assert_eq(0, Auth::loginBlockedSeconds('customer'), 'clearing failures lifts the block');
        });
    } finally {
        Database::query('DELETE FROM login_attempts WHERE ip=?', [$ip]);
        if ($oldMax === null) Database::query('DELETE FROM settings WHERE tenant_id=? AND setting_key=?', [_SKEW_TENANT, 'max_login_attempts']);
        else Database::setSetting('max_login_attempts', $oldMax, _SKEW_TENANT);
        if ($oldMin === null) Database::query('DELETE FROM settings WHERE tenant_id=? AND setting_key=?', [_SKEW_TENANT, 'lockout_minutes']);
        else Database::setSetting('lockout_minutes', $oldMin, _SKEW_TENANT);
        if ($oldIp === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $oldIp;
    }
});

unit('login_attempts window is evaluated on the database clock', function (): void {
    $ip = slate_test_ip('198.51.100.202');
    Database::query('DELETE FROM login_attempts WHERE ip=?', [$ip]);
    try {
        Database::insert('login_attempts', [
            'tenant_id'  => _SKEW_TENANT,
            'scope'      => 'customer',
            'ip'         => $ip,
            'identifier' => 'window@example.test',
        ]);

        // A row inserted with the column default must fall inside a one-minute
        // window measured by the same clock that wrote it. If PHP's clock were
        // used to build the boundary, the skew would push the row outside it.
        $inWindow = (int) Database::value(
            "SELECT COUNT(*) FROM login_attempts
              WHERE ip = ? AND attempted_at > NOW() - INTERVAL 1 MINUTE",
            [$ip]
        );
        assert_eq(1, $inWindow, 'a just-written attempt is inside a DB-clock window');
    } finally {
        Database::query('DELETE FROM login_attempts WHERE ip=?', [$ip]);
    }
});

unit('customer auth token expiry is written on the database clock', function (): void {
    // issueCustomerToken() is private and its two public callers both send mail,
    // which a test must not do. Reflection lets this assert the real method
    // rather than a restatement of the pattern — without it the test would pass
    // against the very bug it exists to catch.
    $issue = new \ReflectionMethod(Auth::class, 'issueCustomerToken');
    $issue->setAccessible(true);

    $customerId = slate_test_tenant(918299);   // synthetic; the token table carries no FK
    $ttl        = 3600;

    Database::query('DELETE FROM customer_auth_tokens WHERE customer_id=?', [$customerId]);
    try {
        (new TenantContext())->runAs(_SKEW_TENANT, function () use ($issue, $customerId, $ttl): void {
            $issue->invoke(null, $customerId, 'password_reset', $ttl);

            $row = Database::row(
                'SELECT expires_at FROM customer_auth_tokens WHERE customer_id=? ORDER BY id DESC LIMIT 1',
                [$customerId]
            );
            assert_true($row !== null, 'a token row was written');

            // The invariant: expires_at must sit ~$ttl ahead of the clock that
            // consumeCustomerToken() compares it against — MySQL's NOW(), not
            // PHP's. Built from PHP's clock it inherited the skew and the token
            // stayed valid for $ttl plus that offset.
            $drift = (int) Database::value(
                'SELECT TIMESTAMPDIFF(SECOND, NOW(), ?)', [$row['expires_at']]
            );
            assert_true(
                abs($drift - $ttl) <= 5,
                "expiry must be ~{$ttl}s ahead of the DB clock, got {$drift}s"
            );

            // And it must satisfy the exact predicate the consumer uses.
            $live = (int) Database::value(
                'SELECT COUNT(*) FROM customer_auth_tokens WHERE customer_id=? AND expires_at > NOW()',
                [$customerId]
            );
            assert_eq(1, $live, 'a freshly issued token passes expires_at > NOW()');
        });
    } finally {
        Database::query('DELETE FROM customer_auth_tokens WHERE customer_id=?', [$customerId]);
    }
});
