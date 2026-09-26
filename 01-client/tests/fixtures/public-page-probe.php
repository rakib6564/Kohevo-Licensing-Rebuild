<?php
/**
 * Execute a plugin's public entry point in a CHILD process and report what it
 * returned.
 *
 * A child, not the current process, for two reasons. The page calls exit(), so
 * requiring it inline would end the suite. And it needs a plugin's active/
 * inactive state to differ from the one the runner booted with — toggling that
 * in-process would change what the other suites see, whereas a child reads the
 * plugins table fresh and leaves the parent's boot untouched.
 *
 * Prints one header line the caller can parse, then the body:
 *
 *     STATUS <code>
 *     <html …>
 *
 * Usage: php tests/fixtures/public-page-probe.php <page-path-from-repo-root> [query]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$page  = $argv[1] ?? '';
$query = $argv[2] ?? '';
if ($page === '') { fwrite(STDERR, "usage: public-page-probe.php <page> [query]\n"); exit(2); }

$root = dirname(__DIR__, 2);
$file = $root . '/' . ltrim($page, '/');
if (!is_file($file)) { fwrite(STDERR, "no such page: {$file}\n"); exit(2); }

// Phase 6 (Global License Guard) test toggle — see
// docs/02-architecture/06-GLOBAL-LICENSE-GUARD.md §3a. By default this
// probe bypasses the Guard exactly like every other non-licensing
// integration test (SLATE_TESTING, D19 LOCKED) so existing suites
// (including LicenseGateTest.php's own slate_license_gate()-only
// assertions) are unaffected by its addition. GlobalLicenseGuardTest.php
// sets SLATE_LICENSE_GUARD_LIVE=1 to instead exercise the real Guard
// end-to-end.
if (getenv('SLATE_LICENSE_GUARD_LIVE') !== '1') {
    define('SLATE_TESTING', true);
}

parse_str($query, $_GET);
$_POST   = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/' . ltrim($page, '/') . ($query !== '' ? '?' . $query : '');
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SCRIPT_NAME']    = '/' . ltrim($page, '/');

// The page writes its status with http_response_code() and its body with echo.
// Buffer the body so the status line can be emitted first regardless of the
// order the page produced them in.
ob_start();
$shutdownDone = false;
register_shutdown_function(static function () use (&$shutdownDone): void {
    if ($shutdownDone) return;
    $shutdownDone = true;
    $body = ob_get_length() !== false ? (string) ob_get_clean() : '';
    $code = http_response_code();
    fwrite(STDOUT, 'STATUS ' . ($code === false ? 200 : (int) $code) . "\n");
    fwrite(STDOUT, $body);
});

require $file;
