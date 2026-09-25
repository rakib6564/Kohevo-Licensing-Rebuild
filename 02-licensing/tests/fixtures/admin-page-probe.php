<?php
/**
 * Execute an ADMIN page in a CHILD process, authenticated as a super-admin,
 * and report what it returned.
 *
 * Sibling to public-page-probe.php, same reasons for a child process (the
 * page calls exit(); its capability/permission state must be read fresh,
 * not inherited from the parent's boot). The extra step here is standing up
 * a real, validated admin session: Auth::check() requires BOTH
 * $_SESSION['slate_user'] AND a matching, unexpired, unrevoked row in
 * admin_sessions (SessionRepository::validateAndTouch()) — session data
 * alone is not enough.
 *
 * role_id => 1 is used by default (Super Admin, per Auth::isSuperAdmin()),
 * which short-circuits every Auth::can()/requirePerm() check — this probe is
 * about capability enforcement, not permission enforcement, so the seeded
 * super-admin sidesteps an unrelated variable. An optional 3rd arg overrides
 * role_id (any integer; nothing validates it against a real `roles` row —
 * see Auth::roleId(), which trusts the session), and an optional 4th arg
 * ("1") grants the fake user `platform_admins` membership for the duration
 * of the probe (Phase 0G) — for proving a platform-admin-table member (not
 * role_id=1) reaches the same platform-only gates.
 *
 * GET only: proving an admin page is/isn't reachable needs no POST body or
 * CSRF token, and every test in the sibling suite this pairs with is a GET
 * probe for the same reason. See admin-page-post-probe.php for POST gates.
 *
 * Prints one header line the caller can parse, then the body:
 *
 *     STATUS <code>
 *     <html …>
 *
 * Usage: php tests/fixtures/admin-page-probe.php <page-path-from-repo-root> [query] [roleId=1] [platformAdmin=0]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$page   = $argv[1] ?? '';
$query  = $argv[2] ?? '';
$roleId = isset($argv[3]) && $argv[3] !== '' ? (int) $argv[3] : 1;
$grantPlatformAdmin = ($argv[4] ?? '0') === '1';
if ($page === '') { fwrite(STDERR, "usage: admin-page-probe.php <page> [query] [roleId] [platformAdmin]\n"); exit(2); }

$root = dirname(__DIR__, 2);
$file = $root . '/' . ltrim($page, '/');
if (!is_file($file)) { fwrite(STDERR, "no such page: {$file}\n"); exit(2); }

require $root . '/config.php';

use Slate\Services\Auth\Auth;
use Slate\Services\Auth\SessionRepository;
use Slate\Tenancy\TenantContext;

$tid    = current_tenant_id();
$fakeId = 900000 + random_int(1, 99999);

session_id('adminprobe_' . bin2hex(random_bytes(12)));
Auth::startSession();
$_SESSION['slate_user'] = [
    'id'        => $fakeId,
    'tenant_id' => $tid,
    'email'     => 'capability-probe@example.test',
    'role_id'   => $roleId,
];
(new SessionRepository(new TenantContext()))->register(
    $fakeId, session_id(), 'Capability probe', '127.0.0.1', 'ProbeAgent/1'
);
if ($grantPlatformAdmin) {
    Auth::grantPlatformAdmin($fakeId);
}

parse_str($query, $_GET);
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/' . ltrim($page, '/') . ($query !== '' ? '?' . $query : '');
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SCRIPT_NAME']    = '/' . ltrim($page, '/');

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
