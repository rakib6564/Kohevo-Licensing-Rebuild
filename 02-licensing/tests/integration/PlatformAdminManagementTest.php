<?php
/**
 * Phase 1E A2 — Platform Administrator management UI (admin/platform-admins.php).
 *
 * The backend (platform_admins table, migration 0013; Auth::isPlatformAdmin()/
 * grantPlatformAdmin()/revokePlatformAdmin()/isPlatformSuperAdmin()/
 * requirePlatformAdmin()) already existed and is covered by
 * PlatformAdminTest.php / PlatformAdminGateTest.php. This file covers only
 * the new page itself: access control, the add-by-email flow (which can only
 * ever grant to a real, existing user — never a forged/nonexistent id), the
 * last-admin and self-revocation guards, and audit logging.
 */

declare(strict_types=1);

use Slate\Services\Auth\Auth;

function pamt_probe_get(string $page, int $roleId = 1, bool $platformAdmin = false): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg('') . ' '
         . escapeshellarg((string) $roleId) . ' ' . escapeshellarg($platformAdmin ? '1' : '0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('probe did not return a STATUS line: ' . $out);
    }
    return ['status' => (int) $m[1], 'body' => substr($out, strlen($m[0]))];
}

function pamt_probe_post(array $fields, int $roleId = 1, bool $platformAdmin = false): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
         . escapeshellarg('admin/platform-admins.php') . ' '
         . escapeshellarg((string) json_encode($fields)) . ' '
         . escapeshellarg((string) $roleId) . ' ' . escapeshellarg($platformAdmin ? '1' : '0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('probe did not return a STATUS line: ' . $out);
    }
    return ['status' => (int) $m[1], 'body' => substr($out, strlen($m[0]))];
}

/** Create a real, throwaway user row (email lookup requires a genuine account). */
function pamt_make_user(string $emailPrefix): array
{
    $tid   = current_tenant_id();
    $email = "{$emailPrefix}-" . bin2hex(random_bytes(6)) . '@example.test';
    $roleId = (int) (Database::value("SELECT id FROM roles WHERE tenant_id = ? ORDER BY id LIMIT 1", [$tid]) ?: 1);
    $id = Database::insert('users', [
        'tenant_id'     => $tid,
        'email'         => $email,
        'password_hash' => password_hash('probe-pass', PASSWORD_DEFAULT),
        'name'          => ucfirst($emailPrefix) . ' Probe',
        'role_id'       => $roleId,
        'status'        => 'active',
    ]);
    return ['id' => $id, 'email' => $email];
}

function pamt_cleanup_user(int $userId): void
{
    Database::query("DELETE FROM platform_admins WHERE user_id = ?", [$userId]);
    Database::query("DELETE FROM users WHERE id = ?", [$userId]);
}

unit('platform-admins.php: an ordinary tenant admin (not a platform admin) is refused (403)', function (): void {
    $res = pamt_probe_get('admin/platform-admins.php', 5001, false);
    assert_eq(403, $res['status'], 'a non-platform-admin must be refused');
});

unit('platform-admins.php: a legacy Super Admin (role_id=1) can reach the page even without a platform_admins row', function (): void {
    $res = pamt_probe_get('admin/platform-admins.php', 1, false);
    assert_eq(200, $res['status'], 'role_id=1 satisfies isPlatformSuperAdmin() via isSuperAdmin(), independent of platform_admins membership');
});

unit('platform-admins.php: a platform_admins member (non-role_id=1) can also reach the page', function (): void {
    $res = pamt_probe_get('admin/platform-admins.php', 5002, true);
    assert_eq(200, $res['status']);
});

unit('platform-admins.php: adding by email grants a real existing user and records an audit entry', function (): void {
    $u = pamt_make_user('grantee');
    try {
        $res = pamt_probe_post(['_action' => 'add', 'email' => $u['email']], 1, false);
        assert_eq(200, $res['status']);
        assert_true(str_contains($res['body'], 'now a platform administrator') || str_contains($res['body'], 'platform_admin_added'),
            'success flash must render: ' . $res['body']);

        assert_true((bool) Database::value("SELECT 1 FROM platform_admins WHERE user_id = ?", [$u['id']]), 'the user must now be a platform_admins member');

        $audit = Database::row("SELECT action, target FROM audit_log WHERE action = 'platform_admin.granted' AND target = ? ORDER BY id DESC LIMIT 1", ["user#{$u['id']}"]);
        assert_true($audit !== null, 'granting must record an audit_log entry');
    } finally {
        pamt_cleanup_user((int) $u['id']);
    }
});

unit('platform-admins.php: adding a nonexistent email is refused and creates no row (cannot grant to a forged/nonexistent user)', function (): void {
    $fakeEmail = 'does-not-exist-' . bin2hex(random_bytes(6)) . '@example.test';
    $before = (int) Database::value("SELECT COUNT(*) FROM platform_admins");

    $res = pamt_probe_post(['_action' => 'add', 'email' => $fakeEmail], 1, false);
    assert_eq(200, $res['status']);
    assert_true(str_contains($res['body'], 'No user with that email') || str_contains($res['body'], 'user_not_found'), 'must show a not-found error: ' . $res['body']);

    $after = (int) Database::value("SELECT COUNT(*) FROM platform_admins");
    assert_eq($before, $after, 'no platform_admins row must be created for a nonexistent user');
});

unit('platform-admins.php: a platform admin cannot remove their own access', function (): void {
    $u = pamt_make_user('selfremove');
    Auth::grantPlatformAdmin((int) $u['id']);
    try {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-as-user-probe.php') . ' '
             . escapeshellarg('admin/platform-admins.php') . ' '
             . escapeshellarg((string) json_encode(['_action' => 'remove', 'user_id' => $u['id']])) . ' '
             . escapeshellarg((string) $u['id']) . ' 2>/dev/null';
        $out = (string) shell_exec($cmd);
        if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
            throw new RuntimeException('probe did not return a STATUS line: ' . $out);
        }
        $body = substr($out, strlen($m[0]));

        assert_eq(200, (int) $m[1]);
        assert_true(str_contains($body, 'cannot remove your own') || str_contains($body, 'cannot_remove_self'), 'must show the self-removal error: ' . $body);
        assert_true((bool) Database::value("SELECT 1 FROM platform_admins WHERE user_id = ?", [$u['id']]), 'the row must still exist after a blocked self-removal attempt');
    } finally {
        pamt_cleanup_user((int) $u['id']);
    }
});

unit('platform-admins.php: the sole remaining platform admin cannot be removed by someone else', function (): void {
    // Caller reaches the page via legacy role_id=1 (isSuperAdmin() bypass),
    // NOT via platform_admins membership — so the table can legitimately
    // hold exactly one row (a different user) while the caller is someone
    // else entirely, exercising the "last admin" guard independently of the
    // "cannot remove self" guard. Snapshot/restore rather than a blind
    // DELETE, so this test doesn't disturb rows any other test relies on.
    $existing = Database::rows("SELECT user_id, granted_by, granted_at FROM platform_admins");
    Database::query("DELETE FROM platform_admins");
    $sole = pamt_make_user('sole');
    Auth::grantPlatformAdmin((int) $sole['id']);
    try {
        assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM platform_admins"), 'setup: exactly one platform admin must exist');

        $res = pamt_probe_post(['_action' => 'remove', 'user_id' => $sole['id']], 1, false);
        assert_eq(200, $res['status']);
        assert_true(str_contains($res['body'], 'At least one platform administrator') || str_contains($res['body'], 'cannot_remove_last'), 'must show the last-admin error: ' . $res['body']);
        assert_true((bool) Database::value("SELECT 1 FROM platform_admins WHERE user_id = ?", [$sole['id']]), 'the sole platform admin must not have been removed');
    } finally {
        pamt_cleanup_user((int) $sole['id']);
        Database::query("DELETE FROM platform_admins");
        foreach ($existing as $row) {
            Database::insert('platform_admins', $row);
        }
    }
});

unit('platform-admins.php: removing a platform admin (not the last, not self) succeeds and records an audit entry', function (): void {
    $keep = pamt_make_user('keep');
    $drop = pamt_make_user('drop');
    Auth::grantPlatformAdmin((int) $keep['id']);
    Auth::grantPlatformAdmin((int) $drop['id']);
    try {
        $res = pamt_probe_post(['_action' => 'remove', 'user_id' => $drop['id']], 1, false);
        assert_eq(200, $res['status']);
        assert_false((bool) Database::value("SELECT 1 FROM platform_admins WHERE user_id = ?", [$drop['id']]), 'the removed admin must no longer be a member');
        assert_true((bool) Database::value("SELECT 1 FROM platform_admins WHERE user_id = ?", [$keep['id']]), 'the other admin must be untouched');

        $audit = Database::row("SELECT 1 FROM audit_log WHERE action = 'platform_admin.revoked' AND target = ? ORDER BY id DESC LIMIT 1", ["user#{$drop['id']}"]);
        assert_true($audit !== null, 'revoking must record an audit_log entry');
    } finally {
        pamt_cleanup_user((int) $keep['id']);
        pamt_cleanup_user((int) $drop['id']);
    }
});

unit('platform-admins.php: an invalid CSRF token is rejected and grants nothing', function (): void {
    $u = pamt_make_user('csrfcheck');
    try {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-badcsrf-probe.php') . ' '
             . escapeshellarg('admin/platform-admins.php') . ' '
             . escapeshellarg((string) json_encode(['_action' => 'add', 'email' => $u['email']])) . ' '
             . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>/dev/null';
        $out = (string) shell_exec($cmd);
        if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
            throw new RuntimeException('probe did not return a STATUS line: ' . $out);
        }
        $body = substr($out, strlen($m[0]));

        assert_false(str_contains($body, 'now a platform administrator'), 'a forged CSRF token must not result in a grant');
        assert_false((bool) Database::value("SELECT 1 FROM platform_admins WHERE user_id = ?", [$u['id']]), 'no row must be created when csrf_verify() rejects the request');
    } finally {
        pamt_cleanup_user((int) $u['id']);
    }
});
