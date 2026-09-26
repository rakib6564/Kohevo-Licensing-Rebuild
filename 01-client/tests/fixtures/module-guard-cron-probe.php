<?php
/**
 * Probe Slate\Services\Licensing\ModuleGuard::allows($module) in a CHILD
 * process, under the exact same SLATE_TESTING/live toggle every other
 * shared probe already uses (Phase 6, D19 LOCKED — see
 * docs/02-architecture/06-GLOBAL-LICENSE-GUARD.md §3a). This is the one
 * boolean Booking::sendReminders(), GoogleCalendarSync::runCron(),
 * Booking::runMessagingNudgeCron(), and both Booking's and Membership's
 * onStripeEvent() listeners gate their module-specific side effect on
 * (docs/02-architecture/07-MODULE-GUARD-ARCHITECTURE.md §5).
 *
 * A direct, minimal-blast-radius check of that boolean, rather than
 * driving the real cron.php dispatch — which also fires every OTHER
 * frequent_cron listener (BackupRunner, GoogleCalendarSync's real network
 * calls, real reminder emails) — exactly the risk
 * GlobalLicenseGuardTest.php's own docblock explains why that suite never
 * exercises cron.php in 'valid' mode either (see tests/fixtures/cron-probe.php's
 * own docblock).
 *
 * $_SERVER['REQUEST_METHOD'] is set before config.php loads so this counts
 * as a simulated web/cron request, not "bare CLI ops tooling" —
 * ModuleGuard::allows() (like slate_license_guard()) treats a bare CLI
 * invocation with no request context as out-of-scope background tooling
 * and always allows it, which would make this probe meaningless without
 * this line.
 *
 * Usage: php tests/fixtures/module-guard-cron-probe.php <moduleKey>
 * Prints: ALLOW | DENY
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$module = $argv[1] ?? '';
if ($module === '') { fwrite(STDERR, "usage: module-guard-cron-probe.php <moduleKey>\n"); exit(2); }

$root = dirname(__DIR__, 2);

if (getenv('SLATE_LICENSE_GUARD_LIVE') !== '1') {
    define('SLATE_TESTING', true);
}

$_GET  = [];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME']    = '/cron.php';

require $root . '/config.php';

echo ModuleGuard::allows($module) ? 'ALLOW' : 'DENY';
