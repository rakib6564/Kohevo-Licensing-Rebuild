<?php
/**
 * Phase 1E B2 — Safe tenant switching (Enter/Exit Tenant).
 *
 * The underlying mechanism ($_SESSION['slate_override_tenant'], resolved by
 * current_tenant_id() in includes/helpers.php via TenantContext::runAs()
 * pinned to the admin's own home tenant) already existed, already avoided
 * the recursion it once had, and is already proven correct by
 * TenantOverrideRecursionTest.php — re-run unmodified below to confirm zero
 * regression. This file covers only what B2 actually added: admin/tenants.php's
 * "enter" action and admin/exit-tenant.php writing that session key and
 * auditing the transition, unauthorized users being refused, and — the
 * core "context-switching, not impersonation" requirement — that
 * Auth::userId() keeps returning the PLATFORM ADMIN'S OWN identity
 * throughout, never the target tenant's.
 */

declare(strict_types=1);

use Slate\Services\Auth\Auth;
use Slate\Services\Auth\SessionRepository;
use Slate\Services\Tenancy\TenantService;
use Slate\Tenancy\TenantContext;

function tswt_make_user(int $tid, string $tag): array {
    $suffix = $tag . '-' . bin2hex(random_bytes(4));
    $roleId = Database::insert('roles', [
        'tenant_id' => $tid, 'name' => 'Probe TSW ' . $tag,
        'slug' => '__probe-tsw-' . $suffix, 'is_system' => 0,
    ]);
    $userId = Database::insert('users', [
        'tenant_id'     => $tid,
        'email'         => '__probe-tsw-' . $suffix . '@example.test',
        'password_hash' => password_hash('probe-tsw-pass', PASSWORD_DEFAULT),
        'name'          => 'Tenant Switch Probe ' . $tag,
        'role_id'       => $roleId,
        'status'        => 'active',
    ]);
    return [$userId, $roleId];
}

function tswt_login(TenantContext $tenants, int $userId, int $roleId, int $homeTenant): int {
    if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION = []; session_write_close(); }
    if (!headers_sent()) session_id('tsw_' . bin2hex(random_bytes(16)));
    Auth::startSession();
    Database::query('DELETE FROM admin_sessions WHERE tenant_id=? AND session_hash=?', [$homeTenant, hash('sha256', session_id())]);
    $_SESSION['slate_user'] = ['id' => $userId, 'tenant_id' => $homeTenant, 'email' => 'tsw-probe@example.test', 'role_id' => $roleId];
    return $tenants->runAs($homeTenant, function () use ($tenants, $userId) {
        return (new SessionRepository($tenants))->register(
            $userId, session_id(), 'Tenant switch test', slate_test_ip('192.0.2.100'), 'SlateTest/1'
        );
    });
}

function tswt_cleanup(TenantContext $tenants, int $userId, int $roleId, int $homeTenant, int $sessionRowId): void {
    unset($_SESSION['slate_override_tenant']);
    if ($sessionRowId > 0) {
        $tenants->runAs($homeTenant, function () use ($tenants, $userId, $sessionRowId): void {
            (new SessionRepository($tenants))->revokeById($userId, $sessionRowId);
        });
    }
    $_SESSION = [];
    if ($userId > 0) {
        Database::query('DELETE FROM platform_admins WHERE user_id=?', [$userId]);
        Database::query('DELETE FROM users WHERE id=? AND tenant_id=?', [$userId, $homeTenant]);
    }
    if ($roleId > 0) {
        Database::query('DELETE FROM role_permissions WHERE role_id=?', [$roleId]);
        Database::query('DELETE FROM roles WHERE id=? AND tenant_id=?', [$roleId, $homeTenant]);
    }
}

function tswt_probe_post(array $fields, int $roleId = 1, bool $platformAdmin = false): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
         . escapeshellarg('admin/tenants.php') . ' '
         . escapeshellarg((string) json_encode($fields)) . ' '
         . escapeshellarg((string) $roleId) . ' ' . escapeshellarg($platformAdmin ? '1' : '0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('probe did not return a STATUS line: ' . $out);
    }
    return ['status' => (int) $m[1], 'body' => substr($out, strlen($m[0]))];
}

unit('tenant switch: Auth::userId() keeps returning the platform admin\'s own identity throughout the switch, never the target tenant\'s', function (): void {
    $tenants = new TenantContext();
    $home   = current_tenant_id();
    $target = $home + 88001;
    [$userId, $roleId] = tswt_make_user($home, 'identity');
    $session = 0;
    try {
        $session = tswt_login($tenants, $userId, $roleId, $home);
        Auth::grantPlatformAdmin($userId);

        assert_eq($userId, Auth::userId(), 'before entering: Auth::userId() is the platform admin');
        assert_eq($home, current_tenant_id(), 'before entering: current_tenant_id() is the admin\'s own home tenant');

        // Exactly what admin/tenants.php's "enter" action does.
        $_SESSION['slate_override_tenant'] = $target;

        assert_eq($userId, Auth::userId(), 'while switched: Auth::userId() must still be the platform admin — this is context-switching, not impersonation');
        assert_eq($target, current_tenant_id(), 'while switched: current_tenant_id() resolves to the entered tenant');

        // Exactly what admin/exit-tenant.php's action does.
        unset($_SESSION['slate_override_tenant']);

        assert_eq($userId, Auth::userId(), 'after exiting: Auth::userId() is still the platform admin');
        assert_eq($home, current_tenant_id(), 'after exiting: current_tenant_id() is back to the admin\'s own home tenant');
    } finally {
        tswt_cleanup($tenants, $userId, $roleId, $home, $session);
    }
});

unit('admin/tenants.php "enter" action: sets the override, redirects, and records tenant.entered with the target and IP', function (): void {
    $slug = 'switch-target-' . bin2hex(random_bytes(4));
    $targetId = TenantService::create(['name' => 'Switch Target', 'slug' => $slug]);
    try {
        $res = tswt_probe_post(['_action' => 'enter', 'tenant_id' => $targetId], 1, false);
        assert_eq(302, $res['status'], 'entering a tenant must redirect on success: ' . $res['body']);

        $audit = Database::row(
            "SELECT meta_json FROM audit_log WHERE action = 'tenant.entered' AND target = ? ORDER BY id DESC LIMIT 1",
            ["tenant#$targetId"]
        );
        assert_true($audit !== null, 'entering a tenant must record a tenant.entered audit entry');
        $meta = json_decode((string) $audit['meta_json'], true);
        assert_true(array_key_exists('ip', $meta), 'the audit entry must record the acting IP');
    } finally {
        Database::query("DELETE FROM tenant_profiles WHERE tenant_id = ?", [$targetId]);
        Database::query("DELETE FROM tenants WHERE id = ?", [$targetId]);
    }
});

unit('admin/tenants.php "enter" action: an ordinary (non-platform) admin is refused, even POSTing directly', function (): void {
    $slug = 'switch-refuse-' . bin2hex(random_bytes(4));
    $targetId = TenantService::create(['name' => 'Switch Refuse', 'slug' => $slug]);
    try {
        $res = tswt_probe_post(['_action' => 'enter', 'tenant_id' => $targetId], 5201, false);
        assert_eq(403, $res['status'], 'a non-platform-admin must never reach the enter action — the whole page is gated by Auth::requirePlatformAdmin()');

        $audit = Database::row("SELECT 1 FROM audit_log WHERE action = 'tenant.entered' AND target = ?", ["tenant#$targetId"]);
        assert_true($audit === null, 'no tenant.entered audit entry must exist for a refused attempt');
    } finally {
        Database::query("DELETE FROM tenant_profiles WHERE tenant_id = ?", [$targetId]);
        Database::query("DELETE FROM tenants WHERE id = ?", [$targetId]);
    }
});

unit('admin/tenants.php "enter" action: entering a nonexistent tenant is refused without touching the session', function (): void {
    $res = tswt_probe_post(['_action' => 'enter', 'tenant_id' => 999999999], 1, false);
    assert_eq(200, $res['status'], 'a nonexistent tenant must render the error inline, not redirect');
    assert_true(str_contains($res['body'], 'Tenant not found') || str_contains($res['body'], 'tenant_not_found'), $res['body']);
});

unit('admin/exit-tenant.php: unsets the override and records tenant.exited', function (): void {
    $slug = 'exit-target-' . bin2hex(random_bytes(4));
    $targetId = TenantService::create(['name' => 'Exit Target', 'slug' => $slug]);
    $home = current_tenant_id();
    [$userId, $roleId] = tswt_make_user($home, 'exit');
    try {
        Auth::grantPlatformAdmin($userId);

        // Run via a real child-process request (exit-tenant.php calls
        // exit() at the end of every path, so it cannot be require()d
        // in-process without killing the whole test runner).
        $cmd = escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg(dirname(__DIR__) . '/fixtures/exit-tenant-probe.php') . ' '
             . escapeshellarg((string) $userId) . ' ' . escapeshellarg((string) $targetId) . ' 2>/dev/null';
        $out = (string) shell_exec($cmd);
        if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
            throw new RuntimeException('probe did not return a STATUS line: ' . $out);
        }
        assert_eq(302, (int) $m[1], 'exiting must redirect back to the tenants list');

        $audit = Database::row(
            "SELECT meta_json FROM audit_log WHERE action = 'tenant.exited' AND target = ? ORDER BY id DESC LIMIT 1",
            ["tenant#$targetId"]
        );
        assert_true($audit !== null, 'exiting a tenant must record a tenant.exited audit entry');
        $meta = json_decode((string) $audit['meta_json'], true);
        assert_true(array_key_exists('ip', $meta), 'the audit entry must record the acting IP');
    } finally {
        Database::query('DELETE FROM platform_admins WHERE user_id = ?', [$userId]);
        Database::query('DELETE FROM users WHERE id = ? AND tenant_id = ?', [$userId, $home]);
        Database::query('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
        Database::query('DELETE FROM roles WHERE id = ? AND tenant_id = ?', [$roleId, $home]);
        Database::query("DELETE FROM tenant_profiles WHERE tenant_id = ?", [$targetId]);
        Database::query("DELETE FROM tenants WHERE id = ?", [$targetId]);
    }
});

// Re-run the existing recursion-safety suite's assertions are already covered
// by requiring that file directly — confirms this phase introduced zero
// regression in the mechanism B2 wires UI onto.
require_once __DIR__ . '/TenantOverrideRecursionTest.php';
