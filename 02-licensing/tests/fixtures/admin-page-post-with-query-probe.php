<?php
/**
 * Sibling to admin-page-post-probe.php that ALSO sets $_GET from a query
 * string, for admin pages whose POST handler needs a query param to resolve
 * its target (e.g. plugins/licensing/admin/install.php?id=<installId>, which
 * resolves the install an action applies to from $_GET, not the POST body).
 * admin-page-post-probe.php deliberately hardcodes
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
$grantPlatformAdmin = ($argv[5] ?? '0') === '1';
$tenantOverride = isset($argv[6]) && $argv[6] !== '' ? (int) $argv[6] : null;
if ($page === '') { fwrite(STDERR, "usage: admin-page-post-with-query-probe.php <page> <query> <postFieldsJson> [roleId] [platformAdmin] [tenantOverride]\n"); exit(2); }

$root = dirname(__DIR__, 2);
$file = $root . '/' . ltrim($page, '/');
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

parse_str($query, $_GET);
$_POST = $fields;
$_SERVER['REQUEST_METHOD'] = 'POST';
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
