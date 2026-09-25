<?php
/**
 * Phase 1D B3 — bin/backup-db.sh passed the database password on mysqldump's
 * command line (`-p"$DB_PASS"`), which is visible to any other local user via
 * `ps` for the life of the dump — a multi-tenant shared host is exactly the
 * environment this project targets, and a single install can be dumping for
 * minutes on a large database.
 *
 * Fix writes a temporary MySQL option file (`--defaults-extra-file`) holding
 * user/password instead, restricted to 0600 and removed via a trap on every
 * exit path (success or failure). This is the standard fix mysqldump's own
 * documentation recommends for this exact warning.
 *
 * This is a shell script, not PHP — the project has no shell-test harness, so
 * this drives the REAL bin/backup-db.sh as a real subprocess against a fake
 * mysqldump shadowing PATH, exactly as a shell test would, rather than
 * asserting on the script's source text alone.
 */

declare(strict_types=1);

unit('bin/backup-db.sh: the password never appears on mysqldump\'s command line, and the credentials file is 0600 and removed on exit', function (): void {
    if (!is_executable('/bin/bash') && !is_executable('/usr/bin/bash')) {
        return; // no bash on this host — nothing to drive the script with
    }

    // backup-db.sh's own truncation check (untouched by this fix, and out of
    // this finding's scope) pipes the produced .sql.gz through `zcat`. BSD
    // zcat (macOS, and some BSDs) refuses to read a plain .gz file unless it
    // is named *.Z — a pre-existing, platform-specific quirk of that
    // unrelated line, not something introduced here. GNU zcat (the project's
    // actual Linux deployment target — see this script's own cron/shared-host
    // framing) handles it correctly. Skip on a host where even a real gzip
    // stream fails zcat, rather than fail on an unrelated, pre-existing
    // platform quirk.
    $probeGz = sys_get_temp_dir() . '/slate-zcat-selftest-' . bin2hex(random_bytes(4)) . '.gz';
    file_put_contents($probeGz, gzencode('probe', 6));
    exec('zcat ' . escapeshellarg($probeGz) . ' > /dev/null 2>&1', $selfTestOut, $selfTestRc);
    @unlink($probeGz);
    if ($selfTestRc !== 0) {
        return; // this host's zcat can't read a plain .gz at all — nothing this test can prove here
    }

    $root = dirname(__DIR__, 2);
    $tmp  = sys_get_temp_dir() . '/slate-backup-probe-' . bin2hex(random_bytes(8));
    $app  = $tmp . '/app';
    $bin  = $tmp . '/fakebin';
    $out  = $tmp . '/out';
    mkdir($app, 0777, true);
    mkdir($bin, 0777, true);
    mkdir($out, 0777, true);

    $dbPass = 'S3cr3t_probe_pw_' . bin2hex(random_bytes(6));
    $dbUser = 'probe_user';
    $dbName = 'probe_db';

    file_put_contents($app . '/.env', "DB_USER={$dbUser}\nDB_NAME={$dbName}\nDB_PASS={$dbPass}\n");

    $argsCapture = $tmp . '/args-capture.txt';
    $cnfCapture  = $tmp . '/cnf-capture.txt';

    // A fake mysqldump: records the exact argv it was called with (proving —
    // or disproving — that the password ever appears there), inspects the
    // --defaults-extra-file it was given (path, permissions, content) WHILE
    // that file still exists (the real script's trap deletes it once this
    // process and the gzip it's piped into both finish), then emits a
    // minimal, validly-shaped dump so backup-db.sh's own truncation check
    // ("Dump completed" trailer) is satisfied.
    $fakeMysqldump = <<<'SH'
#!/usr/bin/env bash
printf '%s\n' "$@" > "$ARGS_CAPTURE"
for a in "$@"; do
    case "$a" in
        --defaults-extra-file=*)
            f="${a#--defaults-extra-file=}"
            perms="$(stat -f '%Lp' "$f" 2>/dev/null)"
            case "$perms" in
                ''|*[!0-9]*) perms="$(stat -c '%a' "$f" 2>/dev/null)" ;;
            esac
            content="$(tr '\n' '|' < "$f")"
            {
                printf 'PATH=%s\n' "$f"
                printf 'PERMS=%s\n' "$perms"
                printf 'CONTENT=%s\n' "$content"
            } > "$CNF_CAPTURE"
            ;;
    esac
done
echo "-- fake dump for probe"
echo "-- Dump completed on $(date +'%F %H:%M:%S')"
SH;
    file_put_contents($bin . '/mysqldump', $fakeMysqldump);
    chmod($bin . '/mysqldump', 0755);

    try {
        $cmd = sprintf(
            'PATH=%s:$PATH ARGS_CAPTURE=%s CNF_CAPTURE=%s BACKUP_DIR=%s bash %s %s 2>&1',
            escapeshellarg($bin),
            escapeshellarg($argsCapture),
            escapeshellarg($cnfCapture),
            escapeshellarg($out),
            escapeshellarg($root . '/bin/backup-db.sh'),
            escapeshellarg($app)
        );
        exec($cmd, $lines, $exitCode);
        $scriptOutput = implode("\n", $lines);

        assert_eq(0, $exitCode, 'backup-db.sh must still succeed end-to-end: ' . $scriptOutput);
        assert_true(is_file($argsCapture), 'the fake mysqldump must have been invoked');

        $args = (string) file_get_contents($argsCapture);
        assert_false(str_contains($args, $dbPass), 'the plaintext DB password must never appear in mysqldump\'s argv');
        assert_false(str_contains($args, '-p' . $dbPass), 'no -p<password> form of the old vulnerable call may remain');
        assert_true(str_contains($args, '--defaults-extra-file='), 'mysqldump must be invoked with --defaults-extra-file instead of -p on the command line');

        assert_true(is_file($cnfCapture), 'the --defaults-extra-file must actually have been readable while mysqldump ran');
        $cnfInfo = (string) file_get_contents($cnfCapture);
        assert_true(str_contains($cnfInfo, 'PERMS=600'), 'the credentials file must be mode 600 while mysqldump uses it: ' . $cnfInfo);
        assert_true(
            str_contains($cnfInfo, "user={$dbUser}") && str_contains($cnfInfo, "password={$dbPass}"),
            'the credentials file must actually carry the real user/password (functional correctness, not just absence from argv)'
        );

        // Cleanup: the real script's own trap must have removed the file by
        // the time it exits — extract the captured path and check it's gone.
        if (preg_match('/^PATH=(.+)$/m', $cnfInfo, $m)) {
            assert_false(file_exists($m[1]), 'the credentials file must be removed once backup-db.sh exits, on every exit path');
        } else {
            throw new RuntimeException('could not parse captured CNF path: ' . $cnfInfo);
        }

        // End-to-end functional check: the backup itself must still work.
        $dumps = glob($out . '/*.sql.gz') ?: [];
        assert_eq(1, count($dumps), 'exactly one backup file must have been produced');
        $decompressed = (string) shell_exec('zcat ' . escapeshellarg($dumps[0]) . ' 2>/dev/null');
        assert_true(str_contains($decompressed, 'Dump completed'), 'the produced backup must still contain a valid dump');
    } finally {
        // Best-effort recursive cleanup of the whole probe sandbox.
        $cleanup = function (string $path) use (&$cleanup): void {
            if (is_dir($path) && !is_link($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') continue;
                    $cleanup($path . '/' . $entry);
                }
                @rmdir($path);
            } else {
                @unlink($path);
            }
        };
        $cleanup($tmp);
    }
});
