<?php
/**
 * Execute an ADMIN page's POST handler in a CHILD process, authenticated as
 * a configurable admin user, with a valid CSRF token attached automatically.
 *
 * Sibling to admin-page-probe.php (GET only). That fixture is documented as
 * GET-only because its whole purpose is proving a page is/isn't *reachable*
 * — a hard hard 403-and-exit gate. Some guards instead only fire inside a
 * POST handler and degrade gracefully to a flash message with a 200 (e.g.
 * admin/users.php's Super-Admin-role-assignment guard) — a GET probe proves
 * nothing about those, so this fixture exists to drive the real POST path.
 *
 * roleId defaults to 1 (Super Admin) like admin-page-probe.php; pass a
 * different value (any integer — nothing validates it against a real
 * `roles` row, see Auth::roleId()) plus platformAdmin=1 to instead act as a
 * `platform_admins` member, or a real tenant-scoped role id to test ordinary
 * permission-based access.
 *
 * tenantOverride, if given, sets $GLOBALS['SLATE_TENANT_OVERRIDE'] before
 * config.php boots, so current_tenant_id() resolves to it for the whole
 * request — the same global TenantContext::runAs() uses. This simulates a
 * request being served for a tenant other than this checkout's ambient
 * TENANT_ID, for probing whether a page's own queries actually scope by
 * tenant_id rather than trusting it.
 *
 * Usage: php tests/fixtures/admin-page-post-probe.php <page> <postFieldsJson> [roleId=1] [platformAdmin=0] [tenantOverride='']
 *
 * Prints:
 *     STATUS <code>
 *     <html …>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$page     = $argv[1] ?? '';
$postJson = $argv[2] ?? '{}';
$roleId   = isset($argv[3]) && $argv[3] !== '' ? (int) $argv[3] : 1;
$tenantOverride = isset($argv[5]) && $argv[5] !== '' ? (int) $argv[5] : null;
if ($page === '') { fwrite(STDERR, "usage: admin-page-post-probe.php <page> <postFieldsJson> [roleId] [platformAdmin] [tenantOverride]\n"); exit(2); }

$root = dirname(__DIR__, 2);
$file = $root . '/' . ltrim($page, '/');
if (!is_file($file)) { fwrite(STDERR, "no such page: {$file}\n"); exit(2); }

if ($tenantOverride !== null) {
    $GLOBALS['SLATE_TENANT_OVERRIDE'] = $tenantOverride;
}

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
$_SERVER['REQUEST_URI']    = '/' . ltrim($page, '/');
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SCRIPT_NAME']    = '/' . ltrim($page, '/');

// Buffering and the STATUS-line shutdown reporter must be registered BEFORE
// config.php loads, not after — a request-level check made at boot time
// (the Global License Guard, Phase 6) can itself call exit() from inside
// config.php's own require chain, before this fixture would otherwise reach
// this point. $fakeId/$grantPlatformAdmin are bound by reference and only
// actually assigned below, after config.php is available — the shutdown
// closure sees whatever those references hold at shutdown time.
$fakeId = null;
$grantPlatformAdmin = false;
ob_start();
$shutdownDone = false;
register_shutdown_function(static function () use (&$shutdownDone, &$fakeId, &$grantPlatformAdmin): void {
    if ($shutdownDone) return;
    $shutdownDone = true;
    $body = ob_get_length() !== false ? (string) ob_get_clean() : '';
    $code = http_response_code();
    fwrite(STDOUT, 'STATUS ' . ($code === false ? 200 : (int) $code) . "\n");
    fwrite(STDOUT, $body);
    if ($fakeId === null) return;
    if ($grantPlatformAdmin && class_exists('\Slate\Services\Auth\Auth')) {
        try { \Slate\Services\Auth\Auth::revokePlatformAdmin($fakeId); } catch (\Throwable $e) { /* best-effort */ }
    }
    try {
        \Database::query('DELETE FROM admin_sessions WHERE user_id = ?', [$fakeId]);
    } catch (\Throwable $e) {
        // best-effort; a leaked probe session row is harmless and namespaced by its random id
    }
});

require $root . '/config.php';

use Slate\Services\Auth\Auth;
use Slate\Services\Auth\SessionRepository;
use Slate\Tenancy\TenantContext;

$tid    = current_tenant_id();
$fakeId = 900000 + random_int(1, 99999);
$grantPlatformAdmin = ($argv[4] ?? '0') === '1';

session_id('adminpostprobe_' . bin2hex(random_bytes(12)));
Auth::startSession();
$_SESSION['slate_user'] = [
    'id'        => $fakeId,
    'tenant_id' => $tid,
    'email'     => 'post-probe@example.test',
    'role_id'   => $roleId,
];
(new SessionRepository(new TenantContext()))->register(
    $fakeId, session_id(), 'POST probe', '127.0.0.1', 'ProbeAgent/1'
);
if ($grantPlatformAdmin) {
    Auth::grantPlatformAdmin($fakeId);
}

$fields = json_decode($postJson, true);
if (!is_array($fields)) $fields = [];
$fields['_csrf'] = csrf_token();

$_POST = $fields;

require $file;
