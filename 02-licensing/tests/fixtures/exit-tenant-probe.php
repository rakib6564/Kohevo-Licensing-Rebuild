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
require $root . '/config.php';

use Slate\Services\Auth\Auth;
use Slate\Services\Auth\SessionRepository;
use Slate\Tenancy\TenantContext;

$row = Database::row("SELECT id, tenant_id, role_id FROM users WHERE id = ?", [$userId]);
if (!$row) { fwrite(STDERR, "no such user id: {$userId}\n"); exit(2); }

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
$_GET  = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/admin/exit-tenant.php';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SCRIPT_NAME']    = '/admin/exit-tenant.php';

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

require $root . '/admin/exit-tenant.php';
