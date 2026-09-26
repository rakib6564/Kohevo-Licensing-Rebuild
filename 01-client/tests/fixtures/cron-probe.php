<?php
/**
 * Hit the real cron.php in a CHILD process with a chosen key mode and
 * client IP, and report what it returned.
 *
 * 'wrong' and 'none' return 403 and exit before Hook::doAction is ever
 * reached — always safe to run for real.
 *
 * 'valid' uses the real CRON_SECRET and, if NOT already rate-limited, WILL
 * reach Hook::doAction('frequent_cron'/'daily_cron'), which real plugins
 * listen on (Booking::sendReminders — real customer notifications;
 * BackupRunner::runFrequentCron/runDailyCron — real backup operations;
 * GoogleCalendarSync — real network calls). Only use 'valid' in a test that
 * has first driven the rate limiter to (or past) its ceiling for the given
 * IP/globally, so the request is expected to be rejected by the limiter
 * before the key is ever evaluated. Never use 'valid' to probe a
 * below-threshold "does auth succeed" case — that risk is exactly why
 * CronDocumentationTest.php proves dual-method support via a static/logic
 * check instead of a live successful run.
 *
 * The optional IP argument sets $_SERVER['REMOTE_ADDR'] before cron.php
 * loads, so a test can simulate distinct callers without depending on
 * whatever REMOTE_ADDR happens to be unset to under the CLI SAPI (which
 * cron_client_ip() would otherwise resolve to its '0.0.0.0' fallback for
 * every invocation, making every probe indistinguishable from every other).
 *
 * A child, not the current process, because cron.php calls exit() after
 * writing its response — requiring it inline would end the test runner.
 *
 * Usage: php cron-probe.php wrong|none|valid [ip]
 * Prints:
 *     STATUS <code>
 *     <json body>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$mode = $argv[1] ?? '';
$ip   = $argv[2] ?? '0.0.0.0';
if (!in_array($mode, ['wrong', 'none', 'valid'], true)) {
    fwrite(STDERR, "usage: cron-probe.php wrong|none|valid [ip]\n");
    exit(2);
}

$root = dirname(__DIR__, 2);

// Phase 6 (Global License Guard) test toggle — see
// docs/02-architecture/06-GLOBAL-LICENSE-GUARD.md §3a. By default this
// probe bypasses the Guard exactly like every other non-licensing
// integration test (SLATE_TESTING, D19 LOCKED) so existing suites are
// unaffected by its addition. GlobalLicenseGuardTest.php sets
// SLATE_LICENSE_GUARD_LIVE=1 to instead prove cron.php's OWN
// CRON_SECRET-gated auth still runs even when the Guard is live and this
// installation is fully locked (cron.php is on the Guard's own whitelist —
// see includes/license_guard.php's docblock for why).
if (getenv('SLATE_LICENSE_GUARD_LIVE') !== '1') {
    define('SLATE_TESTING', true);
}

// Set BEFORE config.php loads (not after) so a request-level check made at
// boot time — the Global License Guard — sees the same simulated request
// context a real HTTP request would already have from its first line.
$_GET = [];
unset($_SERVER['HTTP_X_CRON_KEY']);
if ($mode === 'wrong') $_GET['key'] = 'not-the-real-secret';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = $ip;
$_SERVER['SCRIPT_NAME']    = '/cron.php';

// Buffering and the STATUS-line shutdown reporter must be registered BEFORE
// config.php loads, not after — a request-level check made at boot time
// (the Global License Guard, Phase 6) can itself call exit() from inside
// config.php's own require chain, before this fixture would otherwise reach
// this point.
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

require $root . '/config.php';

if ($mode === 'valid') $_GET['key'] = defined('CRON_SECRET') ? (string) CRON_SECRET : '';

require $root . '/cron.php';
