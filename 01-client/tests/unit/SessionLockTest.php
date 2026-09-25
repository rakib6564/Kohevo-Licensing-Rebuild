<?php
/**
 * The crawler deadlock, and the mechanism of its fix.
 *
 * MLT_Crawler::fetch() forwards the caller's cookies — SLATE_SID among them —
 * to a URL on this same host. With session.save_handler = files, PHP holds an
 * exclusive lock on the session file for the life of a request, so the inner
 * request blocked in session_start() waiting on a lock the outer request was
 * holding, and died at CURLOPT_TIMEOUT. Observed on production: all 63 URLs
 * failing at 7002–7005 ms with 0 bytes received, and a translations table that
 * had never held a single row.
 *
 * WHAT THIS TEST DOES NOT PROVE
 *
 * It does not prove the scan works. That needs two concurrent HTTP requests
 * sharing a session id — an authenticated browser scan against a deployed
 * build — and nothing here substitutes for it. A test that only asserted
 * "session_write_close() appears in the file" would be a spelling check
 * wearing a lab coat, and the fix would still be unverified.
 *
 * WHAT IT DOES PROVE, and why that is worth having
 *
 *   1. The lock is real on this PHP build, and session_write_close() releases
 *      it. If either were false the fix would be pointless — and neither is
 *      visible in the source, because it depends on save_handler, which is
 *      configuration.
 *   2. $_SESSION stays readable after the close. That is the property that
 *      makes closing safe rather than a different bug.
 *   3. scanBatch() closes BEFORE it fetches. Ordering is the whole fix; after
 *      the loop it would release a lock the fetches had already timed out on.
 *
 * 1 and 2 run against a temp save_path, so no real session is touched.
 */

declare(strict_types=1);

/** Run the probe fixture. Returns [stdout, elapsed_ms]. */
function slock_probe(array $args, bool $background = false): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(__DIR__ . '/fixtures/session-lock-probe.php') . ' '
         . implode(' ', array_map('escapeshellarg', $args));

    if ($background) {
        // Detach, so the caller can race a second opener against it.
        $h = popen($cmd . ' > /dev/null 2>&1 &', 'r');
        if (is_resource($h)) pclose($h);
        return ['', 0];
    }

    $t0  = microtime(true);
    $out = (string) shell_exec($cmd . ' 2>/dev/null');
    return [trim($out), (int) round((microtime(true) - $t0) * 1000)];
}

unit('the files session handler blocks a second opener, and closing releases it', function (): void {
    $dir = sys_get_temp_dir() . '/slate-slock-' . bin2hex(random_bytes(4));
    if (!@mkdir($dir, 0700, true)) { assert_true(false, "could not create {$dir}"); return; }

    $hold = 700;   // ms the holder keeps the lock

    try {
        // ── A. holder keeps the lock; a second opener must wait ──
        $sidA = 'probe' . bin2hex(random_bytes(6));
        slock_probe(['hold', $dir, $sidA, (string) $hold], true);
        usleep(200 * 1000);                        // let the holder acquire first
        [, $blockedMs] = slock_probe(['open', $dir, $sidA]);

        // ── B. holder closes at once; a second opener sails through ──
        $sidB = 'probe' . bin2hex(random_bytes(6));
        [$memAfterClose] = slock_probe(['hold', $dir, $sidB, (string) $hold, 'close']);
        $sidC = 'probe' . bin2hex(random_bytes(6));
        slock_probe(['hold', $dir, $sidC, (string) $hold, 'close'], true);
        usleep(200 * 1000);
        [, $freeMs] = slock_probe(['open', $dir, $sidC]);

        // If the lock were not real, A would be as quick as B, and the crawler
        // failure would have some other cause than the one this fix addresses.
        assert_true(
            $blockedMs >= 250,
            "a second opener should have waited on the held lock, took {$blockedMs}ms"
        );
        assert_true(
            $freeMs < $blockedMs,
            "after session_write_close() the lock should be free: {$freeMs}ms vs {$blockedMs}ms while held"
        );
        // The property that makes closing safe: reads still work afterwards.
        assert_eq('held', $memAfterClose, '$_SESSION stays readable after session_write_close()');
    } finally {
        foreach (glob($dir . '/sess_*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
});

unit('scanBatch() releases the session before it fetches, not after', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/plugins/multilang-translate/includes/Crawler.php');
    $fn  = strstr($src, 'public static function scanBatch(');
    $end = strpos($fn, "\n    public static function run(");
    if ($end !== false) $fn = substr($fn, 0, $end);

    $close  = strpos($fn, 'session_write_close()');
    $fetch  = strpos($fn, 'self::fetch(');
    $status = strpos($fn, 'session_status()');

    assert_true($close !== false, 'scanBatch() closes the session');
    assert_true($fetch !== false, 'scanBatch() still fetches — the anchor is valid');

    // Ordering IS the fix. Closing after the loop releases a lock the fetches
    // have already spent their whole timeout waiting on.
    assert_true(
        $close < $fetch,
        'session_write_close() must come BEFORE the first fetch, or it is a no-op'
    );

    // Guarded, so a CLI caller with no session is not warned at.
    assert_true(
        $status !== false && $status < $close,
        'the close is guarded on session_status()'
    );
});
