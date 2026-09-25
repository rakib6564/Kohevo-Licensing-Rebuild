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
require $root . '/config.php';

$_GET = [];
unset($_SERVER['HTTP_X_CRON_KEY']);
if ($mode === 'wrong') $_GET['key'] = 'not-the-real-secret';
if ($mode === 'valid') $_GET['key'] = defined('CRON_SECRET') ? (string) CRON_SECRET : '';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = $ip;

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

require $root . '/cron.php';
