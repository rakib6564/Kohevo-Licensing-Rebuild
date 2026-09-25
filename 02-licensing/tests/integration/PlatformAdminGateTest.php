<?php
/**
 * Phase 0G — genuinely platform-only call sites now gate on the dedicated
 * Auth::isPlatformSuperAdmin() / Auth::requirePlatformAdmin() concept instead
 * of the generic isSuperAdmin() bypass:
 *
 *   - admin/opcache-reset.php, admin/diag.php   (requirePlatformAdmin())
 *   - admin/plugins.php upload/uninstall + UI    (isPlatformSuperAdmin())
 *   - includes/helpers.php tenant-override read  (isPlatformSuperAdmin())
 *   - admin/users.php Super-Admin-role-assignment guard (isPlatformSuperAdmin())
 *
 * Both new methods are currently identical to isSuperAdmin() (legacy
 * role_id=1 OR platform_admins membership) by design — see Auth.php's
 * docblocks. These tests prove that identity holds through the REAL
 * production gates (not just in Auth itself, which PlatformAdminTest.php
 * already covers), and that an ordinary tenant-permissioned user — even one
 * holding the exact permission the page's own Auth::requirePerm() already
 * demands — still cannot reach the platform-only branch.
 *
 * GET gates are probed via admin-page-probe.php (extended in this phase with
 * optional roleId/platformAdmin args, default-compatible with its existing
 * callers). The users.php guard only fires inside a POST handler and
 * degrades to a flash message with 200, not a hard 403 — a GET probe would
 * prove nothing about it, so admin-page-post-probe.php (new in this phase)
 * drives the real POST path instead.
 */

declare(strict_types=1);

/** Run an admin page (GET) in a child process. Returns [status, body]. */
function pag_get(string $page, string $query = '', int $roleId = 1, bool $platformAdmin = false): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg($query) . ' '
         . escapeshellarg((string) $roleId) . ' ' . escapeshellarg($platformAdmin ? '1' : '0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) return [0, $out];
    return [(int) $m[1], substr($out, strlen($m[0]))];
}

/** Run an admin page (POST) in a child process. Returns [status, body]. */
function pag_post(string $page, array $fields, int $roleId = 1, bool $platformAdmin = false): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg((string) json_encode($fields)) . ' '
         . escapeshellarg((string) $roleId) . ' ' . escapeshellarg($platformAdmin ? '1' : '0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) return [0, $out];
    return [(int) $m[1], substr($out, strlen($m[0]))];
}

/** Create a throwaway tenant-scoped role with the given permissions granted. Returns role id. */
function pag_make_role(int $tid, string $tag, array $perms): int {
    $roleId = Database::insert('roles', [
        'tenant_id' => $tid,
        'name'      => 'Probe PAG ' . $tag,
        'slug'      => '__probe-pag-' . $tag . '-' . bin2hex(random_bytes(4)),
        'is_system' => 0,
    ]);
    foreach ($perms as $perm) {
        Database::insert('role_permissions', ['role_id' => $roleId, 'perm_key' => $perm, 'granted' => 1]);
    }
    return $roleId;
}

function pag_drop_role(int $roleId): void {
    Database::query('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
    Database::query('DELETE FROM roles WHERE id = ?', [$roleId]);
}

// ── includes/helpers.php: tenant-override read ─────────────────────────
//
// NOT exercised with a live session here. Doing so (real Auth::check() with
// $_SESSION['slate_override_tenant'] set) was tried and hits a PRE-EXISTING,
// unrelated infinite recursion: current_tenant_id()'s override branch calls
// Auth::check(), which validates the session via SessionRepository, which
// scopes its own query through a default (unscoped) TenantContext, whose
// id() calls back into current_tenant_id() — which re-enters the same
// override branch, since nothing has changed $_SESSION in between. This
// exists identically for the old `Auth::isSuperAdmin()` check (isPlatformSuperAdmin()
// simply delegates to it) — it is not something this phase's edit introduced,
// and it has never fired in production because nothing writes
// slate_override_tenant. Fixing it is a separate, unrelated concern (touches
// current_tenant_id()/SessionRepository/TenantContext, not RBAC call-site
// classification) and is out of scope for Phase 0G — flagged in the report
// instead. Verified by code review only: helpers.php's override branch reads
// `Auth::isPlatformSuperAdmin()`, not `Auth::isSuperAdmin()` directly.

// ── admin/opcache-reset.php (Auth::requirePlatformAdmin()) ──────────────

unit('platform admin gate: opcache-reset.php allows legacy role_id=1', function (): void {
    [$status, $body] = pag_get('admin/opcache-reset.php', '', 1, false);
    assert_eq(200, $status, 'legacy Super Admin still reaches a platform-only page');
    assert_true(str_contains($body, 'Opcache reset'), 'the real page rendered');
});

unit('platform admin gate: opcache-reset.php allows a platform_admins member without role_id=1', function (): void {
    [$status, $body] = pag_get('admin/opcache-reset.php', '', 424242, true);
    assert_eq(200, $status, 'platform_admins membership alone reaches a platform-only page');
    assert_true(str_contains($body, 'Opcache reset'), 'the real page rendered');
});

unit('platform admin gate: opcache-reset.php denies an ordinary admin', function (): void {
    [$status, $body] = pag_get('admin/opcache-reset.php', '', 424243, false);
    assert_eq(403, $status, 'neither legacy Super Admin nor platform_admins membership: denied');
    assert_true(!str_contains($body, 'Opcache reset'), 'no page content leaks on denial');
});

// ── admin/plugins.php upload/uninstall UI (Auth::isPlatformSuperAdmin()) ─

unit('platform admin gate: plugins.php shows upload UI for legacy role_id=1', function (): void {
    [$status, $body] = pag_get('admin/plugins.php', '', 1, false);
    assert_eq(200, $status);
    assert_true(str_contains($body, 'id="plug-upload-form"'), 'legacy Super Admin sees the upload form');
});

unit('platform admin gate: plugins.php shows upload UI for a platform_admins member holding plugins.manage', function (): void {
    $tid    = current_tenant_id();
    $roleId = pag_make_role($tid, 'plugview', ['plugins.manage']);
    try {
        [$status, $body] = pag_get('admin/plugins.php', '', $roleId, true);
        assert_eq(200, $status);
        assert_true(str_contains($body, 'id="plug-upload-form"'), 'platform_admins membership alone shows the upload form');
    } finally {
        pag_drop_role($roleId);
    }
});

unit('platform admin gate: plugins.php hides upload UI for an ordinary user holding only plugins.manage', function (): void {
    $tid    = current_tenant_id();
    $roleId = pag_make_role($tid, 'plugordinary', ['plugins.manage']);
    try {
        [$status, $body] = pag_get('admin/plugins.php', '', $roleId, false);
        assert_eq(200, $status, 'plugins.manage alone still lets the page render');
        assert_true(!str_contains($body, 'id="plug-upload-form"'), 'holding the tenant permission is not platform authority — no upload form');
    } finally {
        pag_drop_role($roleId);
    }
});

// ── admin/users.php Super-Admin-role-assignment guard ────────────────────

unit('platform admin gate: users.php lets a platform_admins member (no role_id=1) grant the Super Admin role', function (): void {
    $tid   = current_tenant_id();
    $email = '__probe-pag-grant-' . bin2hex(random_bytes(6)) . '@example.test';
    $createdId = 0;
    try {
        [$status, $body] = pag_post('admin/users.php', [
            '_action'  => 'create',
            'name'     => 'PAG Grant Probe',
            'email'    => $email,
            'role_id'  => '1',
            'status'   => 'active',
            'password' => 'probe-pag-pass-1234',
        ], 424244, true);

        assert_eq(200, $status);
        assert_true(!str_contains($body, 'Only a Super Admin can assign the Super Admin role'),
            'a platform_admins member is allowed to grant role_id=1');

        $createdId = (int) Database::value('SELECT id FROM users WHERE tenant_id = ? AND email = ?', [$tid, $email]);
        assert_true($createdId > 0, 'the user was actually created');
        assert_eq(1, (int) Database::value('SELECT role_id FROM users WHERE id = ?', [$createdId]),
            'role_id=1 was actually persisted, not silently downgraded');
    } finally {
        if ($createdId > 0) Database::query('DELETE FROM users WHERE id = ?', [$createdId]);
    }
});

unit('platform admin gate: users.php refuses an ordinary users.edit-permissioned admin trying to grant the Super Admin role', function (): void {
    $tid    = current_tenant_id();
    $roleId = pag_make_role($tid, 'usersedit', ['users.view', 'users.edit']);
    $email  = '__probe-pag-deny-' . bin2hex(random_bytes(6)) . '@example.test';
    try {
        [$status, $body] = pag_post('admin/users.php', [
            '_action'  => 'create',
            'name'     => 'PAG Deny Probe',
            'email'    => $email,
            'role_id'  => '1',
            'status'   => 'active',
            'password' => 'probe-pag-pass-1234',
        ], $roleId, false);

        assert_eq(200, $status, 'the page still renders — this is a soft denial via flash, not a hard 403');
        assert_true(str_contains($body, 'Only a Super Admin can assign the Super Admin role'), 'the escalation guard fires');

        $existing = Database::value('SELECT id FROM users WHERE tenant_id = ? AND email = ?', [$tid, $email]);
        assert_true($existing === null, 'no role_id=1 user was created despite holding users.edit');
    } finally {
        pag_drop_role($roleId);
    }
});
