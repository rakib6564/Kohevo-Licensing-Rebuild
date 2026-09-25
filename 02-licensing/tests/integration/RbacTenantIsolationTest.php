<?php
declare(strict_types=1);

use Slate\Services\Auth\Auth;
use Slate\Services\Auth\SessionRepository;
use Slate\Tenancy\TenantContext;

unit('non-super-admin permission resolution is tenant-bound even with a foreign role id', function (): void {
    $tenants = new TenantContext();
    $base = current_tenant_id();
    $other = $base + 97000;
    $perm = '__probe.cross_tenant.permission';
    $userId = 0;
    $localRole = 0;
    $foreignRole = 0;
    $session = 0;
    try {
        $localRole = Database::insert('roles', ['tenant_id' => $base, 'name' => 'Probe Local', 'slug' => '__probe-local-' . $base, 'is_system' => 0]);
        $userId = Database::insert('users', [
            'tenant_id' => $base,
            'email' => '__probe-rbac-' . $base . '@example.test',
            'password_hash' => password_hash('probe-rbac-pass', PASSWORD_DEFAULT),
            'name' => 'RBAC Probe',
            'role_id' => $localRole,
            'status' => 'active',
        ]);
        Database::insert('role_permissions', ['role_id' => $localRole, 'perm_key' => $perm, 'granted' => 1]);
        $foreignRole = $tenants->runAs($other, fn () => Database::insert('roles', ['tenant_id' => $other, 'name' => 'Probe Foreign', 'slug' => '__probe-foreign-' . $other, 'is_system' => 0]));
        $tenants->runAs($other, fn () => Database::insert('role_permissions', ['role_id' => $foreignRole, 'perm_key' => $perm, 'granted' => 1]));

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_write_close();
        }
        if (!headers_sent()) session_id('rbac_' . bin2hex(random_bytes(16)));
        Auth::startSession();
        $currentSessionHash = hash('sha256', session_id());
        Database::query('DELETE FROM admin_sessions WHERE tenant_id=? AND session_hash=?', [$base, $currentSessionHash]);
        $_SESSION['slate_user'] = ['id' => $userId, 'tenant_id' => $base, 'email' => 'rbac@example.test', 'role_id' => $foreignRole];
        $session = (new SessionRepository($tenants))->register($userId, session_id(), 'RBAC test', slate_test_ip('192.0.2.80'), 'SlateTest/1');
        assert_false(Auth::can($perm), 'foreign role permissions are denied in the current tenant');

        $_SESSION['slate_user'] = ['id' => $userId, 'tenant_id' => $base, 'email' => 'rbac@example.test', 'role_id' => $localRole];
        Auth::invalidatePermCache();
        assert_true(Auth::can($perm), 'local role permissions remain available');
        (new SessionRepository($tenants))->revokeById($userId, $session);
    } finally {
        if ($session > 0) (new SessionRepository($tenants))->revokeById($userId, $session);
        $_SESSION = [];
        if ($foreignRole > 0) $tenants->runAs($other, function () use ($foreignRole): void {
            Database::query('DELETE FROM role_permissions WHERE role_id=?', [$foreignRole]);
            Database::query('DELETE FROM roles WHERE id=? AND tenant_id=?', [$foreignRole, current_tenant_id()]);
        });
        if ($userId > 0) Database::query('DELETE FROM users WHERE id=? AND tenant_id=?', [$userId, $base]);
        if ($localRole > 0) {
            Database::query('DELETE FROM role_permissions WHERE role_id=?', [$localRole]);
            Database::query('DELETE FROM roles WHERE id=? AND tenant_id=?', [$localRole, $base]);
        }
    }
});
