<?php
/**
 * booking/public/message.php must go away when client messaging is off.
 *
 * The endpoint had no activation check, so turning the feature off did not
 * turn it off. It kept resolving manage tokens, kept running
 * BookingPlusAPI::ensureSchema(), and kept writing client_message rows for a
 * capability the operator believed was disabled. booking/public/pay-intent.php:36
 * has had the check all along — this was a one-line divergence between
 * siblings, which is the shape CORE-1 exists to end.
 *
 * The page moved here from the booking-plus plugin when Booking absorbed it;
 * the switch it answers to is now Booking's `client_messaging` capability
 * rather than a second plugin's activation row.
 *
 * WHY BOTH DIRECTIONS ARE TESTED
 *
 * A guard hard-wired to refuse would satisfy a refusal test on its own, so the
 * suite pins both ends: the endpoint must serve while client_messaging is on
 * (its manifest default) and refuse while it is off. The "off" case flips the
 * capability setting for the duration and restores the exact prior state —
 * absent and '0' are different states, and leaving a row behind would change
 * what the next run sees.
 *
 * Both probes run in a CHILD process (tests/fixtures/public-page-probe.php) so
 * the parent's boot is never touched.
 */

declare(strict_types=1);

/** Run a public page in a child process. Returns [status, body]. */
function bppg_probe(string $page, string $query = ''): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/public-page-probe.php') . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg($query) . ' 2>/dev/null';

    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) return [0, $out];

    return [(int) $m[1], substr($out, strlen($m[0]))];
}

/** Is a Booking capability enabled in the database this run is pointed at? */
function bppg_capability_on(string $cap): bool
{
    return PluginLoader::isCapabilityEnabled('booking', $cap);
}

/** Turn a Booking capability off for the duration; hand back the exact undo. */
function bppg_disable_capability(string $cap): callable
{
    $key   = 'booking.cap.' . $cap;
    $prior = Database::row('SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ?',
                           [current_tenant_id(), $key]);

    Database::query(
        "INSERT INTO settings (tenant_id, setting_key, setting_value) VALUES (?, ?, '0')
         ON DUPLICATE KEY UPDATE setting_value = '0'",
        [current_tenant_id(), $key]
    );

    return static function () use ($prior, $key): void {
        if ($prior === null) {
            Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?',
                            [current_tenant_id(), $key]);
        } else {
            Database::query('UPDATE settings SET setting_value = ? WHERE tenant_id = ? AND setting_key = ?',
                            [(string) $prior['setting_value'], current_tenant_id(), $key]);
        }
    };
}

unit('booking message.php serves while client_messaging is enabled', function (): void {
    // Stated, not assumed. If CI stops exercising the real manifest this fails
    // here with a clear reason rather than further down for an opaque one.
    assert_true(
        bppg_capability_on('client_messaging'),
        'precondition: booking client_messaging is enabled (manifest default)'
    );

    [$status, ] = bppg_probe(
        'plugins/booking/public/message.php',
        't=' . str_repeat('a', 32)
    );

    // 404 — the token is bogus, which is the point: the request got past the
    // capability guard and as far as the lookup.
    assert_eq(404, $status, 'an enabled capability gets past the guard to the token lookup');
});

unit('booking message.php refuses with 503 while client_messaging is disabled', function (): void {
    $restore = bppg_disable_capability('client_messaging');

    try {
        [$status, $body] = bppg_probe(
            'plugins/booking/public/message.php',
            't=' . str_repeat('a', 32)
        );

        // 503 specifically, matching pay-intent.php rather than a new code.
        assert_eq(503, $status, 'a disabled capability serves 503');

        // The token was never resolved and no form was offered — the guard runs
        // before the lookup, not after it.
        assert_true(
            !str_contains($body, '<textarea'),
            'no message form is rendered'
        );
    } finally {
        $restore();
    }
});
