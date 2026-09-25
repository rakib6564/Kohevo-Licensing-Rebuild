<?php
/**
 * Sibling to admin-page-post-probe.php, for exactly one purpose: proving a
 * page's csrf_verify() actually rejects a request that does NOT carry the
 * session's real CSRF token. admin-page-post-probe.php always overwrites
 * `_csrf` with a valid token (by design, for testing what a legitimate POST
 * does) — this fixture deliberately does the opposite, sending whatever
 * `_csrf` value (or absence of one) is passed in, so a caller can assert the
 * shared csrf_verify() guard actually fires rather than being silently
 * bypassed by a probe that never exercised it.
 *
 * Usage: php admin-page-post-badcsrf-probe.php <page> <postFieldsJson> [roleId=1] [platformAdmin=0]
 * Prints: STATUS <code>\n<html…>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$page     = $argv[1] ?? '';
$postJson = $argv[2] ?? '{}';
$roleId   = isset($argv[3]) && $argv[3] !== '' ? (int) $argv[3] : 1;
$grantPlatformAdmin = ($argv[4] ?? '0') === '1';
if ($page === '') { fwrite(STDERR, "usage: admin-page-post-badcsrf-probe.php <page> <postFieldsJson> [roleId] [platformAdmin]\n"); exit(2); }

$root = dirname(__DIR__, 2);
$file = $root . '/' . ltrim($page, '/');
if (!is_file($file)) { fwrite(STDERR, "no such page: {$file}\n"); exit(2); }

require $root . '/config.php';

use Slate\Services\Auth\Auth;
use Slate\Services\Auth\SessionRepository;
use Slate\Tenancy\TenantContext;

$tid    = current_tenant_id();
$fakeId = 900000 + random_int(1, 99999);

session_id('adminbadcsrfprobe_' . bin2hex(random_bytes(12)));
Auth::startSession();
$_SESSION['slate_user'] = [
    'id'        => $fakeId,
    'tenant_id' => $tid,
    'email'     => 'badcsrf-probe@example.test',
    'role_id'   => $roleId,
];
(new SessionRepository(new TenantContext()))->register(
    $fakeId, session_id(), 'Bad-CSRF probe', '127.0.0.1', 'ProbeAgent/1'
);
if ($grantPlatformAdmin) {
    Auth::grantPlatformAdmin($fakeId);
}

$fields = json_decode($postJson, true);
if (!is_array($fields)) $fields = [];
// Deliberately NOT setting a valid _csrf here — the caller controls it (or
// omits it entirely) so csrf_verify()'s rejection path is what gets tested.
if (!array_key_exists('_csrf', $fields)) {
    $fields['_csrf'] = 'deliberately-wrong-token';
}

$_POST = $fields;
$_GET  = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/' . ltrim($page, '/');
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
