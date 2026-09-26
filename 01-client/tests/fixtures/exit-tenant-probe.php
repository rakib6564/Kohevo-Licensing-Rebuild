<?php
/**
 * Drives admin/exit-tenant.php in a CHILD process (it calls exit() at the
 * end of every path, so it cannot be `require`d in-process by a test
 * runner — that would terminate the whole test run, not just the probe).
 *
 * Logs in as the given, already-existing user id (see
 * admin-page-post-as-user-probe.php for the same rationale — a fresh fake
 * id can't be pre-existing platform-admin state the caller already set up),
 * seeds $_SESSION['slate_override_tenant'] to the given tenant id (exactly
 * what admin/tenants.php's "enter" action would already have set in a real
 * request), then POSTs to exit-tenant.php.
 *
 * Usage: php exit-tenant-probe.php <userId> <overrideTenantId>
 * Prints: STATUS <code>\n<html…>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$userId           = (int) ($argv[1] ?? 0);
$overrideTenantId = (int) ($argv[2] ?? 0);
if ($userId <= 0 || $overrideTenantId <= 0) {
    fwrite(STDERR, "usage: exit-tenant-probe.php <userId> <overrideTenantId>\n");
    exit(2);
}

$root = dirname(__DIR__, 2);

// Phase 6 (Global License Guard) test toggle — see
// docs/02-architecture/06-GLOBAL-LICENSE-GUARD.md §3a. By default this
// probe bypasses the Guard exactly like every other non-licensing
// integration test (SLATE_TESTING, D19 LOCKED) so existing suites are
// unaffected by its addition. GlobalLicenseGuardTest.php sets
// SLATE_LICENSE_GUARD_LIVE=1 to instead exercise the real Guard end-to-end.
if (getenv('SLATE_LICENSE_GUARD_LIVE') !== '1') {
    define('SLATE_TESTING', true);
}

// Set BEFORE config.php loads (not after) so a request-level check made at
// boot time — the Global License Guard — sees the same simulated request
// context a real HTTP request would already have from its first line.
$_GET  = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/admin/exit-tenant.php';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SCRIPT_NAME']    = '/admin/exit-tenant.php';

// Buffering and the STATUS-line shutdown reporter must be registered BEFORE
// config.php loads, not after — a request-level check made at boot time
// (the Global License Guard, Phase 6) can itself call exit() from inside
// config.php's own require chain, before this fixture would otherwise reach
// this point.
ob_start();
$shutdownDone = false;
register_shutdown_function(static function () use (&$shutdownDone, $userId): void {
    if ($shutdownDone) return;
    $shutdownDone = true;
    $body = ob_get_length() !== false ? (string) ob_get_clean() : '';
    $code = http_response_code();
    fwrite(STDOUT, 'STATUS ' . ($code === false ? 200 : (int) $code) . "\n");
    fwrite(STDOUT, $body);
    try {
        \Database::query('DELETE FROM admin_sessions WHERE user_id = ?', [$userId]);
    } catch (\Throwable $e) {
        // best-effort; a leaked probe session row is harmless and namespaced by its random id
    }
});

require $root . '/config.php';

use Slate\Services\Auth\Auth;
use Slate\Services\Auth\SessionRepository;
use Slate\Tenancy\TenantContext;

$row = Database::row("SELECT id, tenant_id, role_id FROM users WHERE id = ?", [$userId]);
if (!$row) {
    // Usage error, not a probe result — skip the STATUS-line reporter
    // entirely so this still exits exactly as it did before the shutdown
    // function had to be registered earlier (to also catch a Guard exit
    // during config.php's own load, above).
    $shutdownDone = true;
    ob_end_clean();
    fwrite(STDERR, "no such user id: {$userId}\n");
    exit(2);
}

session_id('exittenantprobe_' . bin2hex(random_bytes(12)));
Auth::startSession();
$_SESSION['slate_user'] = [
    'id'        => (int) $row['id'],
    'tenant_id' => (int) $row['tenant_id'],
    'email'     => 'exit-tenant-probe@example.test',
    'role_id'   => (int) $row['role_id'],
];
(new SessionRepository(new TenantContext()))->register(
    (int) $row['id'], session_id(), 'Exit-tenant probe', '127.0.0.1', 'ProbeAgent/1'
);
$_SESSION['slate_override_tenant'] = $overrideTenantId;

$_POST = ['_csrf' => csrf_token()];

require $root . '/admin/exit-tenant.php';
