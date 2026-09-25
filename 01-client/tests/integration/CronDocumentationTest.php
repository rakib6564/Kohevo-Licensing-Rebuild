<?php
/**
 * Phase 1D G1a — cron.php's own docs recommended the less-safe way to call it.
 *
 * cron.php has always supported two ways to deliver CRON_SECRET: the
 * `X-Cron-Key` header, or a `?key=` query parameter. Both still work — this
 * item is documentation-only, deferred rate-limiting (G1b) is a separate,
 * later design step — but the file's own "Recommended cron line" promoted
 * the query-parameter form, which lands the secret in the web server's
 * access log, in this process's argv (visible to other local users via
 * `ps`), and in shell history if ever run by hand. The fix flips the
 * recommendation to the header form and documents why.
 *
 * This suite does NOT drive cron.php's success path end-to-end: a valid key
 * reaches Hook::doAction('frequent_cron'/'daily_cron'), which real plugins
 * listen on (Booking::sendReminders sends real customer notifications;
 * BackupRunner triggers real backup operations; GoogleCalendarSync makes
 * real network calls) — nothing a test suite should trigger against a
 * shared test database. Instead: the doc text itself is pinned, the
 * unchanged auth-resolution line is pinned (proving dual-method support is
 * still intact, by construction — the line is the code, not a restatement
 * of it), and both REJECTION paths (wrong key, no key) are driven live,
 * since both return 403 and exit before any hook ever fires.
 */

declare(strict_types=1);

unit('cron.php: the recommended cron line now uses the X-Cron-Key header, not the secret in the URL', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/cron.php');

    assert_true(
        str_contains($src, "curl -fsS -H 'X-Cron-Key: YOUR_CRON_SECRET' 'https://yoursite/cron.php'"),
        'the recommended cron line must use the header form'
    );
    assert_false(
        str_contains($src, "cron.php?key=YOUR_CRON_SECRET"),
        'the old query-parameter recommendation must be gone from the recommended cron line'
    );
});

unit('cron.php: both delivery methods (header and query) are still supported — the doc fix changed no behavior', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/cron.php');

    // This is the literal source line, not a restatement of it — if it's
    // present unchanged, both delivery methods work by construction: PHP's
    // ?? tries $_GET['key'] first, then $_SERVER['HTTP_X_CRON_KEY'].
    assert_true(
        str_contains($src, "\$provided = (string) (\$_GET['key'] ?? (\$_SERVER['HTTP_X_CRON_KEY'] ?? ''));"),
        'the dual-method secret resolution must be untouched by this documentation-only fix'
    );
});

unit('cron.php: a wrong or missing key is still rejected with 403 (live, safe — never reaches Hook::doAction)', function (): void {
    foreach (['wrong', 'none'] as $mode) {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg(dirname(__DIR__) . '/fixtures/cron-probe.php') . ' '
             . escapeshellarg($mode) . ' 2>&1';
        $out = (string) shell_exec($cmd);

        assert_true(str_starts_with($out, 'STATUS 403'), "mode '$mode' must be rejected with 403: $out");
        assert_true(str_contains($out, '"ok":false'), "mode '$mode' must report ok:false: $out");
    }
});
