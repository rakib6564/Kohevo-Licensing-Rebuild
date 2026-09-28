<?php
/**
 * A change made on the Central Server (extended expiry) reaches the
 * client by itself — no cron, no re-entering the key — and the throttle that
 * keeps that cheap holds. Real Central Server logic, real client cache, one
 * process per call (tests/fixtures/license-sync-probe.php).
 *
 * Named so it loads after Phase10SynchronizationTest.php, whose p10_*
 * helpers it reuses.
 */

declare(strict_types=1);

const P10Z_CENTRAL = __DIR__ . '/../../../02-licensing/tests/fixtures/phase10-central.php';

/** @return array<string,mixed> */
function p10z_sync(string $mode, string $licenseKey, bool $networkUp = true): array {
    $cmd = 'LICENSE_SERVER_URL=' . escapeshellarg('https://license-phase10.test') . ' '
        . license_test_env_prefix()
        . 'LICENSE_PRODUCT=' . escapeshellarg('kohevo') . ' '
        . 'LICENSE_KEY=' . escapeshellarg($licenseKey) . ' '
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/license-sync-probe.php')
        . ' ' . escapeshellarg($mode) . ($networkUp ? ' ' . escapeshellarg((string) realpath(P10Z_CENTRAL)) : '');
    $out = trim((string) shell_exec($cmd . ' 2>/dev/null'));
    $decoded = json_decode($out, true);
    if (!is_array($decoded)) throw new \RuntimeException('license-sync probe failed: ' . $out);
    return $decoded;
}

/** Make the last verified copy look $seconds old (and forget the last attempt). */
function p10z_age(int $seconds): void {
    $old = gmdate('Y-m-d H:i:s', time() - $seconds);
    Database::query('UPDATE remote_license_cache SET fetched_at = ? WHERE tenant_id = ?', [$old, current_tenant_id()]);
    Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = 'license_sync.last_attempt'", [current_tenant_id()]);
}

function p10z_forget_attempt(): void {
    Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = 'license_sync.last_attempt'", [current_tenant_id()]);
}

unit('Auto-sync: a first automatic check pulls the license, then stays quiet until the interval has passed', function () {
    p10_ensure_identity();
    p10_clear();
    p10z_forget_attempt();
    try {
        $expiry = gmdate('Y-m-d H:i:s', time() + 30 * P10_DAY);
        $c = p10_central_setup(['expires_at' => $expiry]);

        $first = p10z_sync('auto', $c['license_key']);
        assert_eq('synced', $first['status'], 'nothing cached yet → due → synced');
        assert_eq($expiry, $first['expires_at']);

        assert_eq('not_due', p10z_sync('auto', $c['license_key'])['status'], 'a fresh copy is not re-fetched');
    } finally { p10_teardown(); p10z_forget_attempt(); }
});

unit('Auto-sync: an expiry changed on the Central Server shows up on the client once the interval passes — and immediately on "Check for updates now"', function () {
    p10_ensure_identity();
    p10_clear();
    p10z_forget_attempt();
    try {
        $c = p10_central_setup(['expires_at' => gmdate('Y-m-d H:i:s', time() + 30 * P10_DAY)]);
        p10z_sync('auto', $c['license_key']);

        $extended = gmdate('Y-m-d H:i:s', time() + 90 * P10_DAY);
        p10_central(['extend', $c['license_id'], $extended]);

        $early = p10z_sync('auto', $c['license_key']);
        assert_eq('not_due', $early['status'], 'within the interval the automatic check waits');
        assert_true($early['expires_at'] !== $extended, 'so the client still shows the old date');

        $manual = p10z_sync('force', $c['license_key']);
        assert_eq('synced', $manual['status'], 'the button always checks');
        assert_eq($extended, $manual['expires_at'], 'and the new expiry is there straight away');

        $renewed = gmdate('Y-m-d H:i:s', time() + 200 * P10_DAY);
        p10_central(['extend', $c['license_id'], $renewed]);
        p10z_age(slate_license_sync_interval(null) + 60);
        $auto = p10z_sync('auto', $c['license_key']);
        assert_eq('synced', $auto['status'], 'once the copy is older than the interval, a normal page load refreshes it');
        assert_eq($renewed, $auto['expires_at']);
    } finally { p10_teardown(); p10z_forget_attempt(); }
});

unit('Auto-sync: an unreachable license server leaves the trusted copy alone and is not hammered', function () {
    p10_ensure_identity();
    p10_clear();
    p10z_forget_attempt();
    try {
        $expiry = gmdate('Y-m-d H:i:s', time() + 30 * P10_DAY);
        $c = p10_central_setup(['expires_at' => $expiry]);
        p10z_sync('auto', $c['license_key']);
        p10z_age(slate_license_sync_interval(null) + 60);

        $down = p10z_sync('auto', $c['license_key'], false);
        assert_eq('failed', $down['status']);
        assert_eq('network', $down['reason']);
        assert_true($down['trusted'], 'the last verified copy is still trusted');
        assert_eq($expiry, $down['expires_at'], 'and unchanged');

        assert_eq('not_due', p10z_sync('auto', $c['license_key'], false)['status'], 'the failed attempt holds the next one off');
    } finally { p10_teardown(); p10z_forget_attempt(); }
});

unit('Auto-sync: without licensing env configured nothing is attempted', function () {
    $cmd = 'LICENSE_SERVER_URL= LICENSE_KEY= ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/license-sync-probe.php') . ' auto 2>/dev/null';
    $out = json_decode(trim((string) shell_exec($cmd)), true);
    assert_true(is_array($out));
    assert_eq('not_configured', $out['status']);
});
