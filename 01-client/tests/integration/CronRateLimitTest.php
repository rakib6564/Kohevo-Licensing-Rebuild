<?php
/**
 * Phase 1D G1b — cron.php had no rate limiting at all. A leaked or guessed
 * CRON_SECRET could be replayed without limit, and a brute-force attempt
 * against a weak custom secret was never throttled (unlike every other
 * secret-gated endpoint in this codebase).
 *
 * Vulnerable-baseline reproduction (done manually before this fix, recorded
 * here for the record): 35 rapid wrong-key requests against the unpatched
 * cron.php all returned 403 individually with no throttling whatsoever.
 *
 * Fix reuses login_attempts (scope='cron') — the same table and DB-clock-
 * correct counting pattern Auth's login throttle uses — with two
 * independent ceilings checked before the key is even evaluated:
 *   - per-IP: 30 attempts / 10 minutes
 *   - global: 200 attempts / 1 hour
 * Every attempt is recorded (success included, never cleared), because a
 * correct-but-leaked secret replayed rapidly is exactly as damaging here as
 * failed guesses — unlike login, a success does not prove the caller is
 * legitimate. See cron.php's own top-of-file comment and
 * cron_rate_limited()/cron_record_attempt().
 *
 * Every test here that expects a 429 is safe to run for real: the rate
 * limiter runs BEFORE the key is evaluated, so a blocked request — even one
 * carrying the correct key — never reaches Hook::doAction. Only the
 * "legitimate execution below the limits" case sends a request that
 * reaches the actual auth check, and it deliberately uses a WRONG key
 * (proving the limiter lets it fall through to the normal 403, not that a
 * correct key succeeds) to avoid ever triggering real plugin side effects
 * (Booking::sendReminders, BackupRunner, GoogleCalendarSync) against this
 * shared test database.
 */

declare(strict_types=1);

/** Insert $count synthetic cron-scope attempts for one IP, "just now". */
function crlt_seed(string $ip, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        Database::insert('login_attempts', [
            'tenant_id'  => current_tenant_id(),
            'scope'      => 'cron',
            'ip'         => $ip,
            'identifier' => '',
        ]);
    }
}

function crlt_clean(): void
{
    Database::query("DELETE FROM login_attempts WHERE scope = 'cron'");
}

function crlt_probe(string $mode, string $ip): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/cron-probe.php') . ' '
         . escapeshellarg($mode) . ' ' . escapeshellarg($ip) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('cron-probe produced no STATUS line: ' . $out);
    }
    return [(int) $m[1], substr($out, strlen($m[0]))];
}

/** Snapshot cron_last_daily, return the exact undo — proves no daily_cron side effect fired. */
function crlt_snapshot_daily(): callable
{
    $tid = current_tenant_id();
    $prior = Database::value(
        'SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ?',
        [$tid, 'cron_last_daily']
    );
    return static function () use ($tid, $prior): void {
        if ($prior === null || $prior === false) {
            Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?', [$tid, 'cron_last_daily']);
        } else {
            Database::setSetting('cron_last_daily', (string) $prior, $tid);
        }
    };
}

crlt_clean();

unit('cron rate limit: per-IP ceiling blocks the offending IP but not a different one (no side effect)', function (): void {
    $restore = crlt_snapshot_daily();
    crlt_clean();
    $offender = '192.0.2.11';
    $bystander = '192.0.2.12';

    try {
        crlt_seed($offender, 30);

        [$status, $body] = crlt_probe('wrong', $offender);
        assert_eq(429, $status, "the 31st attempt from the same IP must be blocked: $body");
        assert_true(str_contains($body, '"rate_limited"'), "the response must identify the reason as rate limiting: $body");

        [$status2, $body2] = crlt_probe('wrong', $bystander);
        assert_eq(403, $status2, "a different IP with no prior attempts must NOT be blocked by another IP's per-IP ceiling: $body2");
    } finally {
        crlt_clean();
        $restore();
    }
});

unit('cron rate limit: global ceiling blocks even a brand-new IP once 200 total attempts exist (no side effect)', function (): void {
    $restore = crlt_snapshot_daily();
    crlt_clean();
    $background = '198.51.100.20';
    $freshIp    = '198.51.100.21';

    try {
        crlt_seed($background, 200);

        [$status, $body] = crlt_probe('wrong', $freshIp);
        assert_eq(429, $status, "a fresh IP with zero attempts of its own must still be blocked once the global ceiling is hit: $body");
        assert_true(str_contains($body, '"rate_limited"'), "the response must identify the reason as rate limiting: $body");
    } finally {
        crlt_clean();
        $restore();
    }
});

unit('cron rate limit: legitimate traffic below both ceilings is never blocked by the limiter', function (): void {
    crlt_clean();
    $ip = '192.0.2.13';

    try {
        crlt_seed($ip, 5); // well under the 30/10min per-IP ceiling, and under 200 globally

        // A wrong key deliberately — this proves the LIMITER let the request
        // through to the actual auth check (403, not 429), without ever
        // needing a valid key or reaching Hook::doAction.
        [$status, $body] = crlt_probe('wrong', $ip);
        assert_eq(403, $status, "under-threshold traffic must reach the normal auth check, not be rate-limited: $body");
        assert_true(str_contains($body, '"forbidden"'), "the rejection must be the ordinary wrong-key response, not a rate-limit response: $body");
    } finally {
        crlt_clean();
    }
});

unit('cron rate limit: attempts spread across many IPs still trip the global ceiling (per-IP rotation does not evade it)', function (): void {
    $restore = crlt_snapshot_daily();
    crlt_clean();

    try {
        $ips = [];
        for ($i = 0; $i < 10; $i++) {
            $ips[] = '203.0.113.' . (30 + $i);
        }
        foreach ($ips as $ip) {
            crlt_seed($ip, 20); // 10 IPs x 20 = 200 total, each individually far under the 30 per-IP cap
        }

        // One more attempt from an IP that is nowhere near ITS OWN per-IP
        // ceiling (20 of 30) must still be blocked — by the global ceiling.
        [$status, $body] = crlt_probe('wrong', $ips[0]);
        assert_eq(429, $status, "an IP well under its own per-IP ceiling must still be blocked once the global total reaches 200: $body");
        assert_true(str_contains($body, '"rate_limited"'), "the response must identify the reason as rate limiting: $body");
    } finally {
        crlt_clean();
        $restore();
    }
});

unit('cron rate limit: a request carrying the CORRECT secret is still rejected once at the ceiling, and no hook fires', function (): void {
    $restore = crlt_snapshot_daily();
    crlt_clean();
    $ip = '192.0.2.14';

    try {
        crlt_seed($ip, 30);

        [$status, $body] = crlt_probe('valid', $ip);
        assert_eq(429, $status, "a correct key must not bypass the rate limit once the ceiling is reached: $body");
        assert_true(str_contains($body, '"rate_limited"'), "the response must be the rate-limit rejection, not the normal success payload: $body");
        assert_false(str_contains($body, '"fired"'), "a blocked request must never report any action as fired: $body");
    } finally {
        crlt_clean();
        $restore();
    }
});

unit('cron rate limit: fails OPEN when login_attempts cannot be read or written', function (): void {
    crlt_clean();
    $ip = '192.0.2.15';
    $renamed = false;

    try {
        Database::query('RENAME TABLE login_attempts TO login_attempts_g1b_test_bak');
        $renamed = true;

        [$status, $body] = crlt_probe('wrong', $ip);
        assert_eq(403, $status, "with the rate-limit table unavailable, the request must still reach the normal auth check (fail open), not crash or hang: $body");
        assert_true(str_contains($body, '"forbidden"'), "the failure must be the ordinary wrong-key rejection, proving the limiter's own error was swallowed cleanly: $body");
        assert_false(str_contains($body, 'Fatal error'), "a broken rate-limit table must never crash the endpoint: $body");
    } finally {
        if ($renamed) {
            Database::query('RENAME TABLE login_attempts_g1b_test_bak TO login_attempts');
        }
        crlt_clean();
    }
});
