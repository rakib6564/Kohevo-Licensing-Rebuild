<?php
/**
 * Phase 0I — delegate three previously platform-only, but genuinely
 * tenant-scoped, operations to real permissions:
 *
 *   - admin/roles.php               -> roles.manage
 *   - admin/audit.php clear-log     -> audit.manage (audit.view unchanged)
 *   - admin/repair-settings.php     -> reuses the existing settings.edit
 *     (no new key: every value it can touch is already settable to
 *     anything via admin/settings.php under settings.edit; this tool can
 *     only reset a flagged key to one fixed safe default, never an
 *     arbitrary value, so it grants nothing settings.edit doesn't already)
 *
 * Every site keeps its existing Auth::isSuperAdmin() fallback, so legacy
 * role_id=1 and Phase 0F platform_admins members are unaffected. The
 * Super Admin role (id=1) protections in admin/roles.php are independent
 * of the page gate and are re-verified here under the NEW, wider caller
 * population the permission opens up.
 */

declare(strict_types=1);

use Slate\Tenancy\TenantContext;

/** Run an admin page (GET) in a child process. Returns [status, body]. */
function tda_get(string $page, string $query = '', int $roleId = 1, bool $platformAdmin = false): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg($query) . ' '
         . escapeshellarg((string) $roleId) . ' ' . escapeshellarg($platformAdmin ? '1' : '0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) return [0, $out];
    return [(int) $m[1], substr($out, strlen($m[0]))];
}

/** Run an admin page (POST) in a child process. Returns [status, body]. */
function tda_post(string $page, array $fields, int $roleId = 1, bool $platformAdmin = false): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg((string) json_encode($fields)) . ' '
         . escapeshellarg((string) $roleId) . ' ' . escapeshellarg($platformAdmin ? '1' : '0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) return [0, $out];
    return [(int) $m[1], substr($out, strlen($m[0]))];
}

/** Create a throwaway tenant-scoped role with the given permissions granted. Returns role id. */
function tda_make_role(int $tid, string $tag, array $perms): int {
    $roleId = Database::insert('roles', [
        'tenant_id' => $tid,
        'name'      => 'Probe TDA ' . $tag,
        'slug'      => '__probe-tda-' . $tag . '-' . bin2hex(random_bytes(4)),
        'is_system' => 0,
    ]);
    foreach ($perms as $perm) {
        Database::insert('role_permissions', ['role_id' => $roleId, 'perm_key' => $perm, 'granted' => 1]);
    }
    return $roleId;
}

function tda_drop_role(int $roleId): void {
    Database::query('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
    Database::query('DELETE FROM roles WHERE id = ?', [$roleId]);
}

// ── admin/roles.php: roles.manage ────────────────────────────────────

unit('roles.manage: can list, create, update, and delete an ordinary tenant role', function (): void {
    $tid = current_tenant_id();
    $actorRoleId = tda_make_role($tid, 'lifecycle', ['roles.manage']);
    $newRoleId = 0;
    try {
        [$listStatus, $listBody] = tda_get('admin/roles.php', '', $actorRoleId, false);
        assert_eq(200, $listStatus);
        assert_true(str_contains($listBody, 'Roles control what admin'), 'the real roles list page rendered');

        // No leading underscore: admin/roles.php's create handler slugifies the
        // posted slug (collapses/strips leading non-alphanumeric runs), so a
        // "__probe-..." prefix would silently become "probe-..." on save —
        // use an already-conformant slug so it round-trips unchanged.
        $slug = 'probe-tda-newrole-' . bin2hex(random_bytes(4));
        [$createStatus] = tda_post('admin/roles.php', [
            '_action' => 'create', 'name' => 'TDA New Role', 'slug' => $slug,
            'description' => '', 'permissions' => ['audit.view'],
        ], $actorRoleId, false);
        assert_eq(200, $createStatus);
        $newRoleId = (int) Database::value('SELECT id FROM roles WHERE tenant_id = ? AND slug = ?', [$tid, $slug]);
        assert_true($newRoleId > 0, 'the role was actually created');

        [$updateStatus] = tda_post('admin/roles.php', [
            '_action' => 'update', 'role_id' => (string) $newRoleId, 'name' => 'TDA Renamed',
            'slug' => $slug, 'description' => 'renamed', 'permissions' => ['audit.view'],
        ], $actorRoleId, false);
        assert_eq(200, $updateStatus);
        assert_eq('TDA Renamed', (string) Database::value('SELECT name FROM roles WHERE id = ?', [$newRoleId]), 'the role was actually renamed');

        [$deleteStatus] = tda_post('admin/roles.php', ['_action' => 'delete', 'role_id' => (string) $newRoleId], $actorRoleId, false);
        assert_eq(200, $deleteStatus);
        assert_true(Database::value('SELECT id FROM roles WHERE id = ?', [$newRoleId]) === null, 'the role was actually deleted');
        $newRoleId = 0;
    } finally {
        if ($newRoleId > 0) tda_drop_role($newRoleId);
        tda_drop_role($actorRoleId);
    }
});

unit('roles.manage: cannot modify or delete the Super Admin role (id=1)', function (): void {
    $tid = current_tenant_id();
    $actorRoleId = tda_make_role($tid, 'super-guard', ['roles.manage']);
    try {
        $nameBefore  = (string) Database::value('SELECT name FROM roles WHERE id = 1');
        $permsBefore = (int) Database::value('SELECT COUNT(*) FROM role_permissions WHERE role_id = 1');

        [$updateStatus, $updateBody] = tda_post('admin/roles.php', [
            '_action' => 'update', 'role_id' => '1', 'name' => 'Hacked', 'slug' => 'hacked',
            'description' => '', 'permissions' => ['plugins.manage'],
        ], $actorRoleId, false);
        assert_eq(200, $updateStatus);
        assert_true(str_contains($updateBody, 'Super Admin role cannot be modified'), 'update is refused');
        assert_eq($nameBefore, (string) Database::value('SELECT name FROM roles WHERE id = 1'), 'name unchanged');
        assert_eq($permsBefore, (int) Database::value('SELECT COUNT(*) FROM role_permissions WHERE role_id = 1'),
            'permissions unchanged — saveRolePermissions() never ran for id=1');

        [$deleteStatus, $deleteBody] = tda_post('admin/roles.php', ['_action' => 'delete', 'role_id' => '1'], $actorRoleId, false);
        assert_eq(200, $deleteStatus);
        assert_true(str_contains($deleteBody, 'System roles cannot be deleted'), 'delete is refused');
        assert_true(Database::value('SELECT id FROM roles WHERE id = 1') !== null, 'role 1 still exists');
    } finally {
        tda_drop_role($actorRoleId);
    }
});

unit('roles.manage: an ordinary user without it is denied', function (): void {
    $tid = current_tenant_id();
    $actorRoleId = tda_make_role($tid, 'denied', ['users.view']);
    try {
        [$status, $body] = tda_get('admin/roles.php', '', $actorRoleId, false);
        assert_eq(403, $status);
        assert_true(!str_contains($body, 'Roles control what admin'), 'no roles content leaked');
    } finally {
        tda_drop_role($actorRoleId);
    }
});

unit('roles.manage + users.edit: still cannot grant role_id=1 or reach platform_admins', function (): void {
    $tid   = current_tenant_id();
    $email = '__probe-tda-esc-' . bin2hex(random_bytes(6)) . '@example.test';
    $actorRoleId = tda_make_role($tid, 'esc', ['roles.manage', 'users.edit', 'users.view']);
    $createdId = 0;
    try {
        $platformAdminsBefore = (int) Database::value('SELECT COUNT(*) FROM platform_admins');

        [$roleUpdateStatus] = tda_post('admin/roles.php', [
            '_action' => 'update', 'role_id' => '1', 'name' => 'x', 'slug' => 'x', 'description' => '', 'permissions' => [],
        ], $actorRoleId, false);
        assert_eq(200, $roleUpdateStatus);
        assert_eq($platformAdminsBefore, (int) Database::value('SELECT COUNT(*) FROM platform_admins'),
            'admin/roles.php has no code path that touches platform_admins');

        [$userCreateStatus, $userCreateBody] = tda_post('admin/users.php', [
            '_action' => 'create', 'name' => 'TDA Esc Probe', 'email' => $email,
            'role_id' => '1', 'status' => 'active', 'password' => 'probe-tda-pass-1234',
        ], $actorRoleId, false);
        assert_eq(200, $userCreateStatus);
        assert_true(str_contains($userCreateBody, 'Only a Super Admin can assign the Super Admin role'),
            "admin/users.php's Phase 0G guard still blocks role_id=1, even combined with roles.manage + users.edit");

        $createdId = (int) Database::value('SELECT id FROM users WHERE tenant_id = ? AND email = ?', [$tid, $email]);
        assert_eq(0, $createdId, 'no role_id=1 user was created');
    } finally {
        if ($createdId > 0) Database::query('DELETE FROM users WHERE id = ?', [$createdId]);
        tda_drop_role($actorRoleId);
    }
});

unit('saveRolePermissions(): a roles.manage-only role can grant a managed role a permission it does not itself hold (documented, not fixed)', function (): void {
    $tid = current_tenant_id();
    $actorRoleId = tda_make_role($tid, 'grant-beyond-own', ['roles.manage']); // deliberately nothing else
    $managedSlug = 'probe-tda-managed-' . bin2hex(random_bytes(4)); // slugify-safe, see the note above
    $managedRoleId = 0;
    try {
        [$status] = tda_post('admin/roles.php', [
            '_action' => 'create', 'name' => 'TDA Managed', 'slug' => $managedSlug,
            'description' => '', 'permissions' => ['plugins.manage'], // the ACTOR does not hold this
        ], $actorRoleId, false);
        assert_eq(200, $status);

        $managedRoleId = (int) Database::value('SELECT id FROM roles WHERE tenant_id = ? AND slug = ?', [$tid, $managedSlug]);
        assert_true($managedRoleId > 0);
        $granted = (int) Database::value(
            "SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND perm_key = 'plugins.manage' AND granted = 1",
            [$managedRoleId]
        );
        assert_eq(1, $granted, 'roles.manage alone is sufficient to grant a permission the actor does not themselves possess — a known, deliberate property, not altered in this phase');
    } finally {
        if ($managedRoleId > 0) tda_drop_role($managedRoleId);
        tda_drop_role($actorRoleId);
    }
});

// ── admin/audit.php: audit.manage ────────────────────────────────────

unit('audit.manage: clears only the current tenant\'s audit log, not another tenant\'s', function (): void {
    $tenants = new TenantContext();
    $base  = current_tenant_id();
    $other = $base + 91001;
    $actorRoleId = tda_make_role($base, 'auditmanage', ['audit.view', 'audit.manage']);
    try {
        AuditLog::record('__probe.tda.own', 'own-tenant-row');
        $tenants->runAs($other, static function (): void {
            AuditLog::record('__probe.tda.other', 'other-tenant-row');
        });
        assert_true((int) Database::value("SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = '__probe.tda.own'", [$base]) > 0, 'precondition: own row exists');
        assert_true((int) Database::value("SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = '__probe.tda.other'", [$other]) > 0, 'precondition: other-tenant row exists');

        tda_post('admin/audit.php', ['_action' => 'clear', 'scope' => 'all'], $actorRoleId, false);

        assert_eq(0, (int) Database::value("SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = '__probe.tda.own'", [$base]), 'own-tenant rows were cleared');
        assert_true((int) Database::value("SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = '__probe.tda.other'", [$other]) > 0, "another tenant's rows were never touched");
    } finally {
        Database::query('DELETE FROM audit_log WHERE action IN (?, ?)', ['__probe.tda.own', '__probe.tda.other']);
        tda_drop_role($actorRoleId);
    }
});

unit('audit.manage: audit.view alone cannot clear the log', function (): void {
    $base = current_tenant_id();
    $actorRoleId = tda_make_role($base, 'auditview-only', ['audit.view']);
    try {
        AuditLog::record('__probe.tda.viewonly', 'view-only-row');
        assert_true((int) Database::value("SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = '__probe.tda.viewonly'", [$base]) > 0, 'precondition: row exists');

        tda_post('admin/audit.php', ['_action' => 'clear', 'scope' => 'all'], $actorRoleId, false);

        assert_true((int) Database::value("SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = '__probe.tda.viewonly'", [$base]) > 0, 'audit.view alone did not clear anything');
    } finally {
        Database::query('DELETE FROM audit_log WHERE action = ?', ['__probe.tda.viewonly']);
        tda_drop_role($actorRoleId);
    }
});

// ── admin/repair-settings.php: reuses settings.edit ──────────────────

unit('settings.edit: can access repair-settings.php; a user without it is denied', function (): void {
    $tid = current_tenant_id();
    $withPerm = tda_make_role($tid, 'settingsedit', ['settings.edit']);
    $withoutPerm = tda_make_role($tid, 'nosettings', ['users.view']);
    try {
        [$allowedStatus, $allowedBody] = tda_get('admin/repair-settings.php', '', $withPerm, false);
        assert_eq(200, $allowedStatus);
        assert_true(str_contains($allowedBody, 'Repair settings'), 'the real page rendered');

        [$deniedStatus, $deniedBody] = tda_get('admin/repair-settings.php', '', $withoutPerm, false);
        assert_eq(403, $deniedStatus);
        assert_true(!str_contains($deniedBody, 'Repair settings'), 'no page content leaked');
    } finally {
        tda_drop_role($withPerm);
        tda_drop_role($withoutPerm);
    }
});

// ── Backward compatibility: legacy role_id=1 and Phase 0F platform_admins ──

unit('legacy role_id=1 still reaches all three delegated pages unchanged', function (): void {
    [$rolesStatus, $rolesBody] = tda_get('admin/roles.php', '', 1, false);
    assert_eq(200, $rolesStatus);
    assert_true(str_contains($rolesBody, 'Roles control what admin'), 'admin/roles.php');

    [$repairStatus, $repairBody] = tda_get('admin/repair-settings.php', '', 1, false);
    assert_eq(200, $repairStatus);
    assert_true(str_contains($repairBody, 'Repair settings'), 'admin/repair-settings.php');

    [$auditStatus, $auditBody] = tda_get('admin/audit.php', '', 1, false);
    assert_eq(200, $auditStatus);
    assert_true(str_contains($auditBody, 'Audit log'), 'admin/audit.php');
});

unit('a platform_admins member (no role_id=1) reaches all three delegated pages unchanged', function (): void {
    [$rolesStatus] = tda_get('admin/roles.php', '', 700001, true);
    assert_eq(200, $rolesStatus, 'admin/roles.php');

    [$repairStatus] = tda_get('admin/repair-settings.php', '', 700002, true);
    assert_eq(200, $repairStatus, 'admin/repair-settings.php');

    [$auditStatus] = tda_get('admin/audit.php', '', 700003, true);
    assert_eq(200, $auditStatus, 'admin/audit.php');
});
