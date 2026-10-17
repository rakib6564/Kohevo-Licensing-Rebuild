<?php
/**
 * bin/rekey-secrets.php — APP_SECRET rotation, run as the real CLI in a
 * child process against the test database.
 *
 * Seeds settings rows encrypted with an "old" secret (the database's real
 * APP_SECRET plays the new one), then checks: a dry run changes nothing; a
 * value that opens with neither secret stops the run before anything is
 * written (even with --apply); --apply re-encrypts every old value so it
 * opens with the current APP_SECRET and leaves current values untouched;
 * no plaintext is ever printed.
 */

declare(strict_types=1);

function rksc_seal(string $plain, string $secret): string
{
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', $secret, true), OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:v1:' . base64_encode($iv . $tag . $ct);
}

/** Run the CLI with OLD_APP_SECRET set. Returns [exit code, stdout+stderr]. */
function rksc_run(string $old, bool $apply): array
{
    $cmd = 'OLD_APP_SECRET=' . escapeshellarg($old) . ' ' . escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__, 2) . '/bin/rekey-secrets.php') . ($apply ? ' --apply' : '') . ' 2>&1';
    exec($cmd, $lines, $code);
    return [$code, implode("\n", $lines)];
}

function rksc_value(string $key): string
{
    return (string) Database::value('SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ?', [current_tenant_id(), $key]);
}

unit('rekey CLI: dry run reports, --apply re-encrypts old values to the current secret, current values are untouched', function (): void {
    assert_true(slate_has_app_secret(), 'precondition: APP_SECRET is configured for the integration suite');
    $old = 'rotation-old-' . bin2hex(random_bytes(6));
    $keys = ['test.rekey_old_a', 'test.rekey_old_b', 'test.rekey_current'];
    try {
        Database::setSetting('test.rekey_old_a', rksc_seal('PLAIN-ALPHA', $old));
        Database::setSetting('test.rekey_old_b', rksc_seal('PLAIN-BRAVO', $old));
        $current = slate_encrypt_secret('PLAIN-CURRENT');
        Database::setSetting('test.rekey_current', $current);
        $before = [rksc_value('test.rekey_old_a'), rksc_value('test.rekey_old_b')];

        [$code, $out] = rksc_run($old, false);
        assert_eq(0, $code, 'dry run exit code: ' . $out);
        assert_true(preg_match('/to re-encrypt: (\d+)/', $out, $m) === 1 && (int) $m[1] >= 2, 'dry run lists the old values: ' . $out);
        assert_eq($before, [rksc_value('test.rekey_old_a'), rksc_value('test.rekey_old_b')], 'a dry run changes nothing');
        assert_false(str_contains($out, 'PLAIN-'), 'no plaintext is printed');

        [$code, $out] = rksc_run($old, true);
        assert_eq(0, $code, 'apply exit code: ' . $out);
        assert_eq('PLAIN-ALPHA', slate_decrypt_secret(rksc_value('test.rekey_old_a')), 'opens with the current APP_SECRET after --apply');
        assert_eq('PLAIN-BRAVO', slate_decrypt_secret(rksc_value('test.rekey_old_b')));
        assert_eq($current, rksc_value('test.rekey_current'), 'a value already under the current secret is left byte-for-byte');
        assert_false(str_contains($out, 'PLAIN-'), 'no plaintext is printed');
    } finally {
        foreach ($keys as $k) Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?', [current_tenant_id(), $k]);
    }
});

unit('rekey CLI: a value that opens with neither secret stops the run and nothing is written, even with --apply', function (): void {
    $old = 'rotation-old-' . bin2hex(random_bytes(6));
    $keys = ['test.rekey_old_c', 'test.rekey_foreign'];
    try {
        Database::setSetting('test.rekey_old_c', rksc_seal('PLAIN-CHARLIE', $old));
        Database::setSetting('test.rekey_foreign', rksc_seal('PLAIN-DELTA', 'some-other-secret'));
        $before = rksc_value('test.rekey_old_c');
        [$code, $out] = rksc_run($old, true);
        assert_eq(2, $code, 'stops with exit 2: ' . $out);
        assert_true(str_contains($out, 'UNREADABLE settings.setting_value'), 'names the unreadable value by table, column and id');
        assert_eq($before, rksc_value('test.rekey_old_c'), 'the readable old value was not rewritten either');
        assert_false(str_contains($out, 'PLAIN-'), 'no plaintext is printed');
    } finally {
        foreach ($keys as $k) Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?', [current_tenant_id(), $k]);
    }
});

unit('rekey CLI: refuses to run without OLD_APP_SECRET, or when it equals APP_SECRET', function (): void {
    [$code, $out] = rksc_run('', false);
    assert_eq(1, $code, $out);
    assert_true(str_contains($out, 'OLD_APP_SECRET'));
    [$code] = rksc_run((string) APP_SECRET, false);
    assert_eq(1, $code, 'same secret is refused');
});
