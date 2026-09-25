<?php
/**
 * C2 regression: bin/mship-views.php must fail closed when reached over the
 * web/application request path.
 *
 * Every other bin/*.php script starts with `if (PHP_SAPI !== 'cli') { ...
 * exit(1); }` as its very first statement — this one didn't, so a request
 * that reached it directly (the .htaccess block on bin/ is the only thing
 * that stops that today) would run an unscoped membership_subscriptions
 * lookup and `require` view partials that assume router.php's auth context,
 * with no authentication at all. The fix adds the same guard, in the same
 * position, before config.php (and therefore before any DB access) loads.
 */

declare(strict_types=1);

unit('bin/mship-views.php: the PHP_SAPI guard runs before config.php / any DB access', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/bin/mship-views.php');

    $guardPos  = strpos($src, "PHP_SAPI !== 'cli'");
    $configPos = strpos($src, "require dirname(__DIR__) . '/config.php'");

    assert_true($guardPos !== false, 'the CLI-only guard must be present');
    assert_true($configPos !== false, 'the config.php require must still be present');
    assert_true(
        $guardPos !== false && $configPos !== false && $guardPos < $configPos,
        'the guard must run before config.php loads — nothing DB-backed may execute ahead of it'
    );

    $guardBlock = substr($src, $guardPos, $configPos - $guardPos);
    assert_true(str_contains($guardBlock, 'exit('), 'the guard must exit(), not merely warn and continue');
});

unit('bin/mship-views.php: a non-CLI request never reaches the membership-view / DB logic', function (): void {
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

    $script = dirname(__DIR__, 2) . '/bin/mship-views.php';
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
    // execution must never fall through to the membership lookup or view render.
    assert_false(str_contains($out, 'OK —'), 'a non-CLI request must never reach the view-render loop');
    assert_false(str_contains($out, 'membership_subscriptions'), 'a non-CLI request must never reach the DB query');
});
