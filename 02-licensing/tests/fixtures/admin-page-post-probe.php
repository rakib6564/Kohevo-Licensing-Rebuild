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
$grantPlatformAdmin = ($argv[4] ?? '0') === '1';
$tenantOverride = isset($argv[5]) && $argv[5] !== '' ? (int) $argv[5] : null;
if ($page === '') { fwrite(STDERR, "usage: admin-page-post-probe.php <page> <postFieldsJson> [roleId] [platformAdmin] [tenantOverride]\n"); exit(2); }

// <page> may carry a trailing '?query=string' — needed to probe a page
// (e.g. admin/license.php?id=5) whose POST handler reads an id or other
// scoping value out of $_GET rather than the POST body. Splitting it off
// here rather than in every caller keeps this fixture the one place that
// knows how a probed page's request is assembled. Backward compatible: no
// existing caller's <page> argument contains '?'.
[$pagePath, $pageQuery] = array_pad(explode('?', $page, 2), 2, '');

$root = dirname(__DIR__, 2);
$file = $root . '/' . ltrim($pagePath, '/');
if (!is_file($file)) { fwrite(STDERR, "no such page: {$file}\n"); exit(2); }

if ($tenantOverride !== null) {
    $GLOBALS['SLATE_TENANT_OVERRIDE'] = $tenantOverride;
}

require $root . '/config.php';

use Slate\Services\Auth\Auth;
use Slate\Services\Auth\SessionRepository;
use Slate\Tenancy\TenantContext;

$tid    = current_tenant_id();
$fakeId = 900000 + random_int(1, 99999);

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
parse_str($pageQuery, $_GET);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/' . ltrim($pagePath, '/') . ($pageQuery !== '' ? '?' . $pageQuery : '');
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SCRIPT_NAME']    = '/' . ltrim($pagePath, '/');

ob_start();
$shutdownDone = false;
register_shutdown_function(static function () use (&$shutdownDone, $fakeId, $grantPlatformAdmin): void {
    if ($shutdownDone) return;
    $shutdownDone = true;
    $body = ob_get_length() !== false ? (string) ob_get_clean() : '';
    $code = http_response_code();
    fwrite(STDOUT, 'STATUS ' . ($code === false ? 200 : (int) $code) . "\n");
    fwrite(STDOUT, $body);
    if ($grantPlatformAdmin) {
        try { Auth::revokePlatformAdmin($fakeId); } catch (\Throwable $e) { /* best-effort */ }
    }
    try {
        \Database::query('DELETE FROM admin_sessions WHERE user_id = ?', [$fakeId]);
    } catch (\Throwable $e) {
        // best-effort; a leaked probe session row is harmless and namespaced by its random id
    }
});

require $file;
