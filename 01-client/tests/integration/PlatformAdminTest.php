<?php
/**
 * Phase 0F — platform_admins prerequisite.
 *
 * Auth::isSuperAdmin() becomes, transitionally, `role_id === 1 OR
 * isPlatformAdmin()`. These tests pin both branches independently (a legacy
 * role_id=1 session and a platform_admins member must each be recognized on
 * their own) and, critically, prove the two concepts don't collapse into each
 * other: granting a tenant role every known permission is NOT the same as
 * platform authority, and platform_admins membership is unaffected by (and
 * does not affect) ordinary tenant permission resolution.
 *
 * Tenant A/B role isolation itself is already covered by
 * RbacTenantIsolationTest.php — nothing here duplicates it.
 *
 * Follows RbacTenantIsolationTest.php's convention: real seeded `roles`/
 * `users` rows, a real validated admin session via SessionRepository, and
 * $_SESSION['slate_user']['role_id'] set directly where a test needs to
 * simulate a specific legacy role id without depending on which role the
 * probe user's row actually points at (roleId() reads the session, not the
 * DB row — see Auth::user()/Auth::check()).
 */

declare(strict_types=1);

use Slate\Services\Auth\Auth;
use Slate\Services\Auth\SessionRepository;
use Slate\Tenancy\TenantContext;

/** Create a throwaway tenant-scoped role + admin user. Returns [$userId, $roleId]. */
function pa_make_user(int $tid, string $tag): array {
    $suffix = $tag . '-' . bin2hex(random_bytes(4));
    $roleId = Database::insert('roles', [
        'tenant_id'   => $tid,
        'name'        => 'Probe PA ' . $tag,
        'slug'        => '__probe-pa-' . $suffix,
        'is_system'   => 0,
    ]);
    $userId = Database::insert('users', [
        'tenant_id'      => $tid,
        'email'          => '__probe-pa-' . $suffix . '@example.test',
        'password_hash'  => password_hash('probe-pa-pass', PASSWORD_DEFAULT),
        'name'           => 'Platform Admin Probe ' . $tag,
        'role_id'        => $roleId,
        'status'         => 'active',
    ]);
    return [$userId, $roleId];
}

/** Log a probe user in with a real, validated admin session. Returns the admin_sessions row id. */
function pa_login(int $userId, int $roleId, int $tid): int {
    $tenants = new TenantContext();
    if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION = []; session_write_close(); }
    if (!headers_sent()) session_id('pa_' . bin2hex(random_bytes(16)));
    Auth::startSession();
    Database::query('DELETE FROM admin_sessions WHERE tenant_id=? AND session_hash=?', [$tid, hash('sha256', session_id())]);
    $_SESSION['slate_user'] = ['id' => $userId, 'tenant_id' => $tid, 'email' => 'pa-probe@example.test', 'role_id' => $roleId];
    return (new SessionRepository($tenants))->register(
        $userId, session_id(), 'Platform admin test', slate_test_ip('192.0.2.90'), 'SlateTest/1'
    );
}

function pa_cleanup(int $userId, int $roleId, int $tid, int $sessionRowId): void {
    if ($sessionRowId > 0) (new SessionRepository(new TenantContext()))->revokeById($userId, $sessionRowId);
    $_SESSION = [];
    Database::query('DELETE FROM platform_admins WHERE user_id=?', [$userId]);
    if ($userId > 0) Database::query('DELETE FROM users WHERE id=? AND tenant_id=?', [$userId, $tid]);
    if ($roleId > 0) {
        Database::query('DELETE FROM role_permissions WHERE role_id=?', [$roleId]);
        Database::query('DELETE FROM roles WHERE id=? AND tenant_id=?', [$roleId, $tid]);
    }
}

unit('platform_admins: a member is recognized as platform admin and super admin', function (): void {
    $tid = current_tenant_id();
    [$userId, $roleId] = pa_make_user($tid, 't1');
    $session = 0;
    try {
        $session = pa_login($userId, $roleId, $tid);
        assert_false(Auth::isSuperAdmin(), 'precondition: a plain probe role is not super admin yet');

        Auth::grantPlatformAdmin($userId);
        assert_true(Auth::isPlatformAdmin(), 'membership in platform_admins is recognized');
        assert_true(Auth::isSuperAdmin(), 'platform_admins membership alone grants isSuperAdmin()');
    } finally {
        pa_cleanup($userId, $roleId, $tid, $session);
    }
});

unit('platform_admins: legacy role_id=1 remains recognized as super admin without platform_admins membership', function (): void {
    $tid = current_tenant_id();
    [$userId, $roleId] = pa_make_user($tid, 't2');
    $session = 0;
    try {
        $session = pa_login($userId, $roleId, $tid);
        $_SESSION['slate_user']['role_id'] = 1;

        assert_true(Auth::isSuperAdmin(), 'legacy role_id=1 alone still grants isSuperAdmin() during the transition');
        assert_false(Auth::isPlatformAdmin(), 'role_id=1 alone is not platform_admins membership');
    } finally {
        pa_cleanup($userId, $roleId, $tid, $session);
    }
});

unit('platform_admins: an ordinary tenant user is neither platform admin nor super admin', function (): void {
    $tid = current_tenant_id();
    [$userId, $roleId] = pa_make_user($tid, 't3');
    $session = 0;
    try {
        $session = pa_login($userId, $roleId, $tid);

        assert_false(Auth::isPlatformAdmin(), 'no platform_admins row exists for this user');
        assert_false(Auth::isSuperAdmin(), 'no legacy role_id=1 and no platform_admins membership');
    } finally {
        pa_cleanup($userId, $roleId, $tid, $session);
    }
});

unit('platform_admins: a tenant role granted every known permission is still not platform/super admin', function (): void {
    $tid = current_tenant_id();
    [$userId, $roleId] = pa_make_user($tid, 't4');
    $session = 0;
    try {
        foreach (Auth::corePermissions() as $group) {
            foreach ($group as $perm) {
                Database::insert('role_permissions', ['role_id' => $roleId, 'perm_key' => $perm['key'], 'granted' => 1]);
            }
        }
        $session = pa_login($userId, $roleId, $tid);

        assert_true(Auth::can('users.edit'), 'sanity: the full permission grant actually took effect');
        assert_true(Auth::can('audit.view'), 'sanity: another granted permission also took effect');
        assert_false(Auth::isSuperAdmin(), 'every tenant permission is not the same as platform authority');
        assert_false(Auth::isPlatformAdmin(), 'granting permissions never touches platform_admins');
    } finally {
        pa_cleanup($userId, $roleId, $tid, $session);
    }
});

unit('platform_admins: ordinary permission grants/denials are unaffected', function (): void {
    $tid  = current_tenant_id();
    $perm = '__probe.pa.permission';
    [$userId, $roleId] = pa_make_user($tid, 't5');
    $session = 0;
    try {
        Database::insert('role_permissions', ['role_id' => $roleId, 'perm_key' => $perm, 'granted' => 1]);
        $session = pa_login($userId, $roleId, $tid);

        assert_true(Auth::can($perm), 'a granted permission is still allowed');
        assert_false(Auth::can('__probe.pa.ungranted'), 'an ungranted permission is still denied');
    } finally {
        pa_cleanup($userId, $roleId, $tid, $session);
    }
});

unit('platform_admins: a customer session never resolves as platform/super admin', function (): void {
    if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION = []; session_write_close(); }
    if (!headers_sent()) session_id('pa_cust_' . bin2hex(random_bytes(16)));
    Auth::startSession();
    try {
        $_SESSION['slate_customer'] = ['id' => 999999999, 'email' => 'pa-customer@example.test', 'tenant_id' => current_tenant_id()];

        assert_false(Auth::isSuperAdmin(), 'no admin session means no super admin, regardless of any customer session');
        assert_false(Auth::isPlatformAdmin(), 'no admin session means no platform admin either');
    } finally {
        $_SESSION = [];
    }
});

unit('platform_admins: legacy behavior is unaffected by unrelated platform_admins rows existing', function (): void {
    $tid = current_tenant_id();
    [$otherUserId, $otherRoleId] = pa_make_user($tid, 't7-other');
    [$userId, $roleId] = pa_make_user($tid, 't7');
    $session = 0;
    try {
        Auth::grantPlatformAdmin($otherUserId);

        $session = pa_login($userId, $roleId, $tid);
        $_SESSION['slate_user']['role_id'] = 1;

        assert_true(Auth::isSuperAdmin(), 'legacy role_id=1 still works with unrelated platform_admins rows present');
        assert_false(Auth::isPlatformAdmin(), 'this session is not itself a platform_admins member');
    } finally {
        pa_cleanup($userId, $roleId, $tid, $session);
        pa_cleanup($otherUserId, $otherRoleId, $tid, 0);
    }
});

unit('platform_admins: granting the same user twice does not duplicate membership or change behavior', function (): void {
    $tid = current_tenant_id();
    [$userId, $roleId] = pa_make_user($tid, 't9');
    $session = 0;
    try {
        $session = pa_login($userId, $roleId, $tid);

        Auth::grantPlatformAdmin($userId, $userId);
        Auth::grantPlatformAdmin($userId, $userId);

        $count = (int) Database::value('SELECT COUNT(*) FROM platform_admins WHERE user_id = ?', [$userId]);
        assert_eq(1, $count, 'granting twice must not create a second row (unique user_id)');
        assert_true(Auth::isSuperAdmin(), 'membership still grants super admin after a duplicate grant');
    } finally {
        pa_cleanup($userId, $roleId, $tid, $session);
    }
});

unit('platform_admins: revoking membership removes platform-admin status without touching legacy role_id=1', function (): void {
    $tid = current_tenant_id();
    [$userId, $roleId] = pa_make_user($tid, 't10');
    $session = 0;
    try {
        $session = pa_login($userId, $roleId, $tid);

        Auth::grantPlatformAdmin($userId);
        assert_true(Auth::isSuperAdmin(), 'precondition: membership granted super admin');

        Auth::revokePlatformAdmin($userId);
        assert_false(Auth::isPlatformAdmin(), 'membership row is gone');
        assert_false(Auth::isSuperAdmin(), 'no legacy role_id=1, so revoking membership removes super admin too');

        $_SESSION['slate_user']['role_id'] = 1;
        assert_true(Auth::isSuperAdmin(), 'legacy role_id=1 compatibility is untouched by revoking platform_admins membership');
    } finally {
        pa_cleanup($userId, $roleId, $tid, $session);
    }
});
