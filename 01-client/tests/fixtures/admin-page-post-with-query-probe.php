<?php
/**
 * Sibling to admin-page-post-probe.php that ALSO sets $_GET from a query
 * string, for admin pages whose POST handler needs a query param to resolve
 * its target (e.g. admin/editor.php?id=<postId> — the editor resolves which
 * page a save/publish/preview/restore _editor_action applies to from $_GET,
 * not the POST body). admin-page-post-probe.php deliberately hardcodes
 * $_GET = [] because none of its existing callers need a query param
 * alongside POST fields; this fixture exists rather than changing that one,
 * matching this suite's convention of adding a new fixture for a genuinely
 * different capability instead of changing a shared one's behavior.
 *
 * Usage: php tests/fixtures/admin-page-post-with-query-probe.php <page> <query> <postFieldsJson> [roleId=1] [platformAdmin=0] [tenantOverride='']
 *
 * Prints:
 *     STATUS <code>
 *     <html …>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$page     = $argv[1] ?? '';
$query    = $argv[2] ?? '';
$postJson = $argv[3] ?? '{}';
$roleId   = isset($argv[4]) && $argv[4] !== '' ? (int) $argv[4] : 1;
$tenantOverride = isset($argv[6]) && $argv[6] !== '' ? (int) $argv[6] : null;
if ($page === '') { fwrite(STDERR, "usage: admin-page-post-with-query-probe.php <page> <query> <postFieldsJson> [roleId] [platformAdmin] [tenantOverride]\n"); exit(2); }

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
parse_str($query, $_GET);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/' . ltrim($page, '/') . ($query !== '' ? '?' . $query : '');
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SCRIPT_NAME']    = '/' . ltrim($page, '/');

// Buffering and the STATUS-line shutdown reporter must be registered BEFORE
// config.php loads, not after — a request-level check made at boot time
// (the Global License Guard, Phase 6) can itself call exit() from inside
// config.php's own require chain, before this fixture would otherwise reach
// this point. $fakeId/$grantPlatformAdmin are bound by reference and only
// actually assigned below, after config.php is available.
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
$grantPlatformAdmin = ($argv[5] ?? '0') === '1';

session_id('adminpostqprobe_' . bin2hex(random_bytes(12)));
Auth::startSession();
$_SESSION['slate_user'] = [
    'id'        => $fakeId,
    'tenant_id' => $tid,
    'email'     => 'post-query-probe@example.test',
    'role_id'   => $roleId,
];
(new SessionRepository(new TenantContext()))->register(
    $fakeId, session_id(), 'POST query probe', '127.0.0.1', 'ProbeAgent/1'
);
if ($grantPlatformAdmin) {
    Auth::grantPlatformAdmin($fakeId);
}

$fields = json_decode($postJson, true);
if (!is_array($fields)) $fields = [];
$fields['_csrf'] = csrf_token();

$_POST = $fields;

require $file;
