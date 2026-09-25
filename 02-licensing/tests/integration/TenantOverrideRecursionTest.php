<?php
/**
 * Phase 0H — the tenant-override branch of current_tenant_id() recursed
 * forever the first time it was ever exercised with a live, validated
 * session (found while writing Phase 0G's tests):
 *
 *   current_tenant_id() -> Auth::check() -> SessionRepository::validateAndTouch()
 *   -> Repository::query() -> TenantContext::id() -> current_tenant_id() -> ...
 *
 * SessionRepository's query is tenant-scoped through a fresh, unscoped
 * TenantContext — i.e. through current_tenant_id() itself — and nothing
 * changes $_SESSION between the outer and nested call, so the override
 * condition is still true on re-entry.
 *
 * The fix (includes/helpers.php) pins the nested resolution to the
 * session's own recorded home tenant ($_SESSION['slate_user']['tenant_id'],
 * set at login, never client-controlled) via TenantContext::runAs() — which
 * sets the CLI-override global that current_tenant_id()'s FIRST branch
 * returns from immediately, so the nested call never re-enters the override
 * branch. A session with no usable home tenant is denied the override
 * (fail closed) rather than risking recursion again.
 *
 * These tests exercise the real, fixed code path end-to-end with genuine
 * seeded roles/users and registered admin_sessions rows — the previous
 * (Phase 0G) attempt at this could not do so at all, because it hung.
 */

declare(strict_types=1);

use Slate\Services\Auth\Auth;
use Slate\Services\Auth\SessionRepository;
use Slate\Tenancy\TenantContext;

/** Seed a throwaway role + admin user directly in the given tenant. Returns [$userId, $roleId]. */
function tor_make_user(int $tid, string $tag): array {
    $suffix = $tag . '-' . bin2hex(random_bytes(4));
    $roleId = Database::insert('roles', [
        'tenant_id' => $tid, 'name' => 'Probe TOR ' . $tag,
        'slug' => '__probe-tor-' . $suffix, 'is_system' => 0,
    ]);
    $userId = Database::insert('users', [
        'tenant_id'     => $tid,
        'email'         => '__probe-tor-' . $suffix . '@example.test',
        'password_hash' => password_hash('probe-tor-pass', PASSWORD_DEFAULT),
        'name'          => 'Tenant Override Probe ' . $tag,
        'role_id'       => $roleId,
        'status'        => 'active',
    ]);
    return [$userId, $roleId];
}

/** Start a fresh PHP session and install a real, validated admin_sessions row for it, scoped to $homeTenant. */
function tor_login(TenantContext $tenants, int $userId, int $roleId, int $homeTenant): int {
    if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION = []; session_write_close(); }
    if (!headers_sent()) session_id('tor_' . bin2hex(random_bytes(16)));
    Auth::startSession();
    Database::query('DELETE FROM admin_sessions WHERE tenant_id=? AND session_hash=?', [$homeTenant, hash('sha256', session_id())]);
    $_SESSION['slate_user'] = ['id' => $userId, 'tenant_id' => $homeTenant, 'email' => 'tor-probe@example.test', 'role_id' => $roleId];
    return $tenants->runAs($homeTenant, function () use ($tenants, $userId) {
        return (new SessionRepository($tenants))->register(
            $userId, session_id(), 'Tenant override test', slate_test_ip('192.0.2.99'), 'SlateTest/1'
        );
    });
}

function tor_cleanup(TenantContext $tenants, int $userId, int $roleId, int $homeTenant, int $sessionRowId): void {
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

unit('tenant override: normal resolution is unaffected when no override is active', function (): void {
    $base = current_tenant_id();
    assert_eq($base, current_tenant_id(), 'idempotent, no session state involved');
});

unit('tenant override: a legacy role_id=1 admin can use the override, and it no longer recurses', function (): void {
    $tenants = new TenantContext();
    $base  = current_tenant_id();
    $other = $base + 87001;
    [$userId, $roleId] = tor_make_user($base, 't-legacy');
    $session = 0;
    try {
        $session = tor_login($tenants, $userId, $roleId, $base);
        $_SESSION['slate_user']['role_id'] = 1; // legacy Super Admin, per Auth::roleId() trusting the session

        $_SESSION['slate_override_tenant'] = $other;
        assert_eq($other, current_tenant_id(), 'legacy Super Admin reaches the override, and the call actually returns');

        // The nested runAs() must not leak: once resolution is done, the
        // CLI-override global is restored to empty and a fresh, override-free
        // call behaves normally again.
        assert_true(empty($GLOBALS['SLATE_TENANT_OVERRIDE']), 'the nested runAs() left no dangling global override');
        unset($_SESSION['slate_override_tenant']);
        assert_eq($base, current_tenant_id(), 'removing the override falls back to normal resolution');
    } finally {
        tor_cleanup($tenants, $userId, $roleId, $base, $session);
    }
});

unit('tenant override: a platform_admins member whose home tenant is NOT the base tenant can still use it', function (): void {
    $tenants = new TenantContext();
    $base   = current_tenant_id();
    $home   = $base + 87002; // this admin's own tenant — deliberately not $base
    $target = $base + 87003; // the tenant being viewed via the override
    [$userId, $roleId] = tor_make_user($home, 't-platform');
    $session = 0;
    try {
        $session = tor_login($tenants, $userId, $roleId, $home);
        Auth::grantPlatformAdmin($userId);

        $_SESSION['slate_override_tenant'] = $target;
        assert_eq($target, current_tenant_id(),
            'a platform_admins member is authorized via their OWN home tenant, not tenant 1 or the target');
    } finally {
        tor_cleanup($tenants, $userId, $roleId, $home, $session);
    }
});

unit('tenant override: an ordinary admin cannot use it even with slate_override_tenant set', function (): void {
    $tenants = new TenantContext();
    $base  = current_tenant_id();
    $other = $base + 87004;
    [$userId, $roleId] = tor_make_user($base, 't-ordinary');
    $session = 0;
    try {
        $session = tor_login($tenants, $userId, $roleId, $base);

        $_SESSION['slate_override_tenant'] = $other;
        assert_eq($base, current_tenant_id(), 'neither legacy role_id=1 nor platform_admins membership: override denied');
    } finally {
        tor_cleanup($tenants, $userId, $roleId, $base, $session);
    }
});

unit('tenant override: a session with no recorded home tenant is denied (fail closed), not recursed into', function (): void {
    if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION = []; session_write_close(); }
    if (!headers_sent()) session_id('tor_notenant_' . bin2hex(random_bytes(16)));
    Auth::startSession();
    try {
        $base = current_tenant_id();
        // No 'tenant_id' key at all — simulates a stale/malformed session.
        $_SESSION['slate_user'] = ['id' => 99999999, 'email' => 'tor-notenant@example.test', 'role_id' => 1];
        $_SESSION['slate_override_tenant'] = $base + 87005;

        assert_eq($base, current_tenant_id(), 'no usable home tenant on the session: override denied, default tenant returned');
    } finally {
        $_SESSION = [];
    }
});
