<?php
/**
 * Probe: does the `files` session handler block a second opener, and does
 * session_write_close() release it?
 *
 * This is the mechanism behind the crawler deadlock, isolated from HTTP. It
 * uses its own save_path in a temp directory, so it neither reads nor writes
 * any real session and cannot disturb a logged-in user.
 *
 * Two roles:
 *   hold <path> <sid> <ms> [close]  open the session, wait <ms>, exit.
 *                                   With `close`, call session_write_close()
 *                                   immediately after opening instead of
 *                                   holding the lock for the wait.
 *   open <path> <sid>               time how long session_start() takes.
 *                                   Prints the elapsed milliseconds.
 *
 * Usage is orchestrated by SessionLockTest; running it by hand does nothing
 * harmful.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$role = $argv[1] ?? '';
$path = $argv[2] ?? '';
$sid  = $argv[3] ?? '';

if ($role === '' || $path === '' || $sid === '') {
    fwrite(STDERR, "usage: session-lock-probe.php hold|open <save_path> <sid> [ms] [close]\n");
    exit(2);
}

ini_set('session.save_handler', 'files');
ini_set('session.save_path', $path);
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
session_id($sid);

if ($role === 'hold') {
    $ms    = (int) ($argv[4] ?? 400);
    $close = ($argv[5] ?? '') === 'close';

    session_start();
    $_SESSION['probe'] = 'held';

    if ($close) {
        session_write_close();
        // The value stays readable in memory after the close. That is the
        // property the fix depends on: Auth::userId() and friends keep
        // working through the fetch loop.
        echo ($_SESSION['probe'] ?? 'GONE'), "\n";
    }

    usleep($ms * 1000);
    exit(0);
}

if ($role === 'open') {
    $t0 = microtime(true);
    session_start();
    printf("%d\n", (int) round((microtime(true) - $t0) * 1000));
    session_write_close();
    exit(0);
}

fwrite(STDERR, "unknown role: {$role}\n");
exit(2);
