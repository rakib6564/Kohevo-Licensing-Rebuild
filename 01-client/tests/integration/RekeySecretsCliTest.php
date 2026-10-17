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

        assert_true(preg_match('/Backup of the previous envelopes \(ciphertext only\): (\S+)/', $out, $bm) === 1, 'apply names its backup file');
        $backup = $bm[1];
        assert_true(is_file($backup), 'the backup file exists');
        assert_eq('600', substr(sprintf('%o', fileperms($backup)), -3), 'the backup is readable by the owner only');
        $raw = (string) file_get_contents($backup);
        assert_true(str_contains($raw, $before[0]) && str_contains($raw, $before[1]), 'the backup holds the previous envelopes');
        assert_false(str_contains($raw, 'PLAIN-'), 'the backup holds no plaintext');
        @unlink($backup);

        [$code, $out] = rksc_run($old, false);
        assert_eq(0, $code, 'a rerun is safe');
        assert_false(str_contains($out, 're-encrypt settings.setting_value id='), 'a rerun finds nothing of ours left to re-encrypt: ' . $out);
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

unit('rekey CLI: refuses to run when the new APP_SECRET is not configured, and writes nothing', function (): void {
    $code = "define('APP_SECRET', '');"
          . '$argv = ["rekey-secrets.php", "--apply"]; $argc = 2; $_SERVER["argv"] = $argv;'
          . 'require ' . var_export(dirname(__DIR__, 2) . '/bin/rekey-secrets.php', true) . ';';
    exec('OLD_APP_SECRET=whatever ' . escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($code) . ' 2>&1', $lines, $exit);
    assert_eq(1, $exit, implode("\n", $lines));
    assert_true(str_contains(implode("\n", $lines), 'APP_SECRET (the new secret) is not configured'));
});

/** Run the CLI with APP_SECRET forced to $appSecret (a constant, so defined before config.php). */
function rksc_run_as(string $appSecret, array $args, string $oldSecret = ''): array
{
    $code = 'define("APP_SECRET", ' . var_export($appSecret, true) . ');'
          . '$argv = ' . var_export(array_merge(['rekey-secrets.php'], $args), true) . '; $argc = count($argv); $_SERVER["argv"] = $argv;'
          . 'require ' . var_export(dirname(__DIR__, 2) . '/bin/rekey-secrets.php', true) . ';';
    $env = $oldSecret !== '' ? 'OLD_APP_SECRET=' . escapeshellarg($oldSecret) . ' ' : 'OLD_APP_SECRET= ';
    exec($env . escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($code) . ' 2>&1', $lines, $exit);
    return [$exit, implode("\n", $lines)];
}

function rksc_opens_with(string $envelope, string $secret): ?string
{
    $raw = base64_decode(substr($envelope, 7), true);
    if (!str_starts_with($envelope, 'enc:v1:') || $raw === false || strlen($raw) < 28) return null;
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', $secret, true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? null : $p;
}

unit('rekey CLI: a failure part-way through --apply rolls the whole run back (nothing half-rotated)', function (): void {
    $old = 'rotation-old-' . bin2hex(random_bytes(6));
    $tid = current_tenant_id();
    try {
        Database::setSetting('test.rekey_first', rksc_seal('PLAIN-FIRST', $old));
        Database::setSetting('test.rekey_trip', rksc_seal('PLAIN-TRIP', $old));
        $before = [rksc_value('test.rekey_first'), rksc_value('test.rekey_trip')];
        // A real database error on the second write (no hook in the CLI itself).
        Database::query("CREATE TRIGGER rksc_trip BEFORE UPDATE ON settings FOR EACH ROW BEGIN IF NEW.setting_key = 'test.rekey_trip' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rksc forced failure'; END IF; END");
        [$code, $out] = rksc_run($old, true);
        assert_eq(3, $code, 'the run reports the rollback: ' . $out);
        assert_true(str_contains($out, 'Rolled back, nothing changed'), $out);
        assert_eq($before, [rksc_value('test.rekey_first'), rksc_value('test.rekey_trip')], 'the value written before the failure was rolled back too');
        assert_false(str_contains($out, 'PLAIN-'), 'no plaintext is printed');
        foreach (glob(dirname(__DIR__, 2) . '/data/rekey-backup-*.json') ?: [] as $f) @unlink($f);
    } finally {
        Database::query('DROP TRIGGER IF EXISTS rksc_trip');
        foreach (['test.rekey_first', 'test.rekey_trip'] as $k) Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?', [$tid, $k]);
    }
});

unit('rekey CLI: rotate, then roll back by a reverse run; and restore from the backup (refused under the wrong secret)', function (): void {
    $old = 'rotation-old-' . bin2hex(random_bytes(6));
    $new = (string) APP_SECRET;
    $tid = current_tenant_id();
    try {
        $orig = rksc_seal('PLAIN-ECHO', $old);
        Database::setSetting('test.rekey_cycle', $orig);
        [$code, $out] = rksc_run($old, true);
        assert_eq(0, $code, $out);
        $rotated = rksc_value('test.rekey_cycle');
        assert_eq('PLAIN-ECHO', rksc_opens_with($rotated, $new), 'rotated to the current secret');
        preg_match('/\(ciphertext only\): (\S+)/', $out, $bm);
        $backup = $bm[1] ?? '';
        assert_true(is_file($backup), 'a backup was written');

        // Restore refuses while APP_SECRET is still the new secret (the backup holds old-secret envelopes).
        [$code, $out] = rksc_run_as($new, ['--restore=' . $backup]);
        assert_eq(2, $code, 'restore under the wrong secret is refused: ' . $out);
        assert_eq($rotated, rksc_value('test.rekey_cycle'), 'nothing changed');

        // Restore with the old secret configured again: byte-identical original envelope back.
        [$code, $out] = rksc_run_as($old, ['--restore=' . $backup]);
        assert_eq(0, $code, 'restore: ' . $out);
        assert_eq($orig, rksc_value('test.rekey_cycle'), 'the original envelope is back, byte for byte');

        // Rotate again, then roll back with a reverse run (APP_SECRET=old, OLD_APP_SECRET=new).
        rksc_run($old, true);
        [$code, $out] = rksc_run_as($old, ['--apply'], $new);
        assert_eq(0, $code, 'reverse run: ' . $out);
        assert_eq('PLAIN-ECHO', rksc_opens_with(rksc_value('test.rekey_cycle'), $old), 'opens with the old secret again after the reverse run');
        assert_false(str_contains($out, 'PLAIN-'), 'no plaintext is printed');
    } finally {
        foreach (glob(dirname(__DIR__, 2) . '/data/rekey-backup-*.json') ?: [] as $f) @unlink($f);
        Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?', [$tid, 'test.rekey_cycle']);
    }
});

unit('rekey CLI: a non-transactional table is refused before anything is written; corrupt ciphertext stops the run', function (): void {
    $old = 'rotation-old-' . bin2hex(random_bytes(6));
    $tid = current_tenant_id();
    try {
        Database::query('CREATE TABLE rksc_myisam (id INT UNSIGNED NOT NULL PRIMARY KEY, v TEXT) ENGINE=MyISAM');
        $env = rksc_seal('PLAIN-FOXTROT', $old);
        Database::query('INSERT INTO rksc_myisam (id, v) VALUES (1, ?)', [$env]);
        [$code, $out] = rksc_run($old, true);
        assert_eq(4, $code, 'MyISAM is refused: ' . $out);
        assert_true(str_contains($out, 'rksc_myisam (MyISAM)'), $out);
        assert_eq($env, (string) Database::value('SELECT v FROM rksc_myisam WHERE id = 1'), 'nothing changed');
        Database::query('DROP TABLE rksc_myisam');

        Database::setSetting('test.rekey_corrupt', 'enc:v1:' . base64_encode(random_bytes(40)));
        [$code, $out] = rksc_run($old, true);
        assert_eq(2, $code, 'corrupt ciphertext stops the run: ' . $out);
        assert_true(str_contains($out, 'UNREADABLE settings.setting_value'), $out);
    } finally {
        Database::query('DROP TABLE IF EXISTS rksc_myisam');
        foreach (glob(dirname(__DIR__, 2) . '/data/rekey-backup-*.json') ?: [] as $f) @unlink($f);
        Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?', [$tid, 'test.rekey_corrupt']);
    }
});

