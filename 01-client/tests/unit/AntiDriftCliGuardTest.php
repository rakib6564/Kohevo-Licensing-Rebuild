<?php
/**
 * Phase 1D A1 regression: bin/anti-drift.php must fail closed when reached
 * over the web/application request path.
 *
 * Every other bin/*.php script starts with `if (PHP_SAPI !== 'cli') { ...
 * exit(1); }` as its very first statement — this one didn't, so a request
 * that reached it directly (the .htaccess block on bin/ is the only thing
 * that stops that today) would run the full static-analysis scan (reading
 * source across admin/customer/includes/src/plugins/api and echoing
 * per-rule violation output, including file paths and matched snippets)
 * with no authentication at all. The fix adds the same guard, in the same
 * position, matching bin/mship-views.php's C2 fix exactly.
 */

declare(strict_types=1);

unit('bin/anti-drift.php: the PHP_SAPI guard runs before any scanning logic', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/bin/anti-drift.php');

    $guardPos = strpos($src, "PHP_SAPI !== 'cli'");
    $scanPos  = strpos($src, '$SCAN_DIRS');

    assert_true($guardPos !== false, 'the CLI-only guard must be present');
    assert_true($scanPos !== false, 'the scan-directory setup must still be present');
    assert_true(
        $guardPos !== false && $scanPos !== false && $guardPos < $scanPos,
        'the guard must run before any scanning/filesystem-reading logic'
    );

    $guardBlock = substr($src, $guardPos, $scanPos - $guardPos);
    assert_true(str_contains($guardBlock, 'exit('), 'the guard must exit(), not merely warn and continue');
});

unit('bin/anti-drift.php: a non-CLI request never reaches the scan/report logic', function (): void {
    // Real proof requires an actual non-CLI SAPI binary — PHP_SAPI cannot be
    // spoofed from within a CLI test process. php-cgi is a standard part of a
    // PHP install (it is exactly how a shared-host webserver would reach this
    // file), but isn't guaranteed present in every environment; the static
    // source check above already enforces the property either way, so this is
    // a best-effort dynamic confirmation, not the sole guard against a regression.
    $cgi = trim((string) shell_exec('command -v php-cgi 2>/dev/null'));
    if ($cgi === '') {
        return;
    }

    $script = dirname(__DIR__, 2) . '/bin/anti-drift.php';
    $cmd = sprintf(
        'REQUEST_METHOD=GET SCRIPT_FILENAME=%s GATEWAY_INTERFACE=CGI/1.1 SERVER_PROTOCOL=HTTP/1.1 %s -d cgi.force_redirect=0 -d display_errors=0 %s 2>&1',
        escapeshellarg($script),
        escapeshellarg($cgi),
        escapeshellarg($script)
    );
    $out = (string) shell_exec($cmd);

    // Whatever shape the guard's failure takes under a given non-CLI SAPI (a
    // clean "Run from the CLI" + exit(1), or the guard's own fwrite(STDERR,...)
    // faulting because STDERR isn't predefined outside the CLI SAPI — a quirk
    // shared by every other bin/*.php guard in this codebase, not new here),
    // execution must never fall through to the scan/report output.
    assert_false(str_contains($out, 'TENANT'), 'a non-CLI request must never reach the rule-report output');
    assert_false(str_contains($out, 'violation'), 'a non-CLI request must never reach the violation report');
});
