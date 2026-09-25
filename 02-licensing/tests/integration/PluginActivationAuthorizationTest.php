<?php
/**
 * Phase 1C H4 — plugin activate/deactivate must be platform-admin-only.
 *
 * The `plugins` table is global with no tenant_id column (confirmed against
 * db/schema.sql) — activating or deactivating a plugin turns its hooks/
 * routes on or off for every tenant on this install, exactly like upload
 * and uninstall (both already gated by Auth::isPlatformSuperAdmin() inline,
 * a few lines above/below the actions this test targets). Activate/
 * deactivate had no such check: the page-level Auth::requirePerm(
 * 'plugins.manage') alone gated them, and that permission is an ordinary,
 * tenant-delegable one — any tenant admin holding it could flip a plugin
 * on or off platform-wide.
 *
 * Uses the mcp-gateway plugin as the toggle target: present with a `plugins`
 * row in every provisioned test database, inactive by default, and not
 * relied upon by any other suite's assumed-active state — restored to its
 * original status in every case, including denied attempts (which should
 * leave it untouched anyway).
 */

declare(strict_types=1);

/** Run admin/plugins.php's POST handler in a child process. Returns [status, body]. */
function paat_post(array $fields, int $roleId, bool $platformAdmin): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
         . escapeshellarg('admin/plugins.php') . ' '
         . escapeshellarg((string) json_encode($fields)) . ' '
         . escapeshellarg((string) $roleId) . ' ' . escapeshellarg($platformAdmin ? '1' : '0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) return [0, $out];
    return [(int) $m[1], substr($out, strlen($m[0]))];
}

function paat_plugin_status(string $slug): ?string
{
    $row = Database::row('SELECT status FROM plugins WHERE slug = ?', [$slug]);
    return $row ? (string) $row['status'] : null;
}

/** A throwaway tenant-scoped role granting plugins.manage. Returns role id. */
function paat_make_role(int $tid): int
{
    $roleId = Database::insert('roles', [
        'tenant_id' => $tid, 'name' => 'Probe PAAT', 'slug' => '__probe-paat-' . bin2hex(random_bytes(4)), 'is_system' => 0,
    ]);
    Database::insert('role_permissions', ['role_id' => $roleId, 'perm_key' => 'plugins.manage', 'granted' => 1]);
    return $roleId;
}

function paat_drop_role(int $roleId): void
{
    Database::query('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
    Database::query('DELETE FROM roles WHERE id = ?', [$roleId]);
}

$SLUG = 'mcp-gateway';
$tid  = current_tenant_id();

// Guard the whole file: only proceed if this checkout actually has the
// target plugin registered (every provisioned test DB does — see
// tests/bin/provision-test-db.sh / ci.yml), and record its real starting
// status so every test can restore it exactly, denied attempts included.
$originalStatus = paat_plugin_status($SLUG);

if ($originalStatus === null) {
    unit('plugin activation authorization: skipped — mcp-gateway plugin row not present in this database', function (): void {
        assert_true(true);
    });
} else {

unit('plugin activation: an ordinary tenant admin with plugins.manage cannot activate a plugin', function () use ($SLUG, $tid, $originalStatus): void {
    if ($originalStatus === 'active') {
        Database::update('plugins', ['status' => 'inactive'], 'slug = ?', [$SLUG]);
    }
    $roleId = paat_make_role($tid);
    try {
        [$status, $body] = paat_post(['_action' => 'activate', 'slug' => $SLUG], $roleId, false);
        assert_eq(200, $status);
        // lang/en.php already defines the 'only_super_admin' key with this
        // generic text, which __() resolves ahead of the (unused) inline
        // default in admin/plugins.php's own __('only_super_admin', '...')
        // calls — every one of the four actions renders this same string.
        assert_true(str_contains($body, 'Only super-admins can perform that action'), 'an ordinary tenant admin must be refused with the platform-admin message');
        assert_eq('inactive', paat_plugin_status($SLUG), 'the global plugins table must not change for a denied caller');
    } finally {
        paat_drop_role($roleId);
        Database::update('plugins', ['status' => $originalStatus], 'slug = ?', [$SLUG]);
    }
});

unit('plugin activation: a platform super admin can activate a plugin', function () use ($SLUG, $originalStatus): void {
    if ($originalStatus === 'active') {
        Database::update('plugins', ['status' => 'inactive'], 'slug = ?', [$SLUG]);
    }
    try {
        [$status, $body] = paat_post(['_action' => 'activate', 'slug' => $SLUG], 1, true);
        assert_eq(200, $status);
        assert_true(!str_contains($body, 'Only super-admins can perform that action'), 'a platform admin must not be refused');
        assert_eq('active', paat_plugin_status($SLUG), 'a platform admin\'s activation must actually take effect');
    } finally {
        Database::update('plugins', ['status' => $originalStatus], 'slug = ?', [$SLUG]);
    }
});

unit('plugin activation: an ordinary tenant admin with plugins.manage cannot deactivate a plugin', function () use ($SLUG, $tid, $originalStatus): void {
    Database::update('plugins', ['status' => 'active'], 'slug = ?', [$SLUG]);
    $roleId = paat_make_role($tid);
    try {
        [$status, $body] = paat_post(['_action' => 'deactivate', 'slug' => $SLUG], $roleId, false);
        assert_eq(200, $status);
        assert_true(str_contains($body, 'Only super-admins can perform that action'), 'an ordinary tenant admin must be refused with the platform-admin message');
        assert_eq('active', paat_plugin_status($SLUG), 'the global plugins table must not change for a denied caller');
    } finally {
        paat_drop_role($roleId);
        Database::update('plugins', ['status' => $originalStatus], 'slug = ?', [$SLUG]);
    }
});

unit('plugin activation: a platform super admin can deactivate a plugin', function () use ($SLUG, $originalStatus): void {
    Database::update('plugins', ['status' => 'active'], 'slug = ?', [$SLUG]);
    try {
        [$status, $body] = paat_post(['_action' => 'deactivate', 'slug' => $SLUG], 1, true);
        assert_eq(200, $status);
        assert_true(!str_contains($body, 'Only super-admins can perform that action'), 'a platform admin must not be refused');
        assert_eq('inactive', paat_plugin_status($SLUG), 'a platform admin\'s deactivation must actually take effect');
    } finally {
        Database::update('plugins', ['status' => $originalStatus], 'slug = ?', [$SLUG]);
    }
});

unit('plugin activation: upload and uninstall remain platform-admin-only, unchanged by this fix', function () use ($tid): void {
    $roleId = paat_make_role($tid);
    try {
        [$uploadStatus, $uploadBody] = paat_post(['_action' => 'upload'], $roleId, false);
        assert_eq(200, $uploadStatus);
        assert_true(str_contains($uploadBody, 'Only super-admins can perform that action'), 'upload must still refuse an ordinary tenant admin exactly as before');

        // A garbage slug is safe here: the platform-admin check runs before
        // any slug is even read, so this proves only the authorization gate,
        // never reaching PluginLoader::uninstall() for a real plugin.
        [$uninstallStatus, $uninstallBody] = paat_post(['_action' => 'uninstall', 'slug' => '__nonexistent-probe-slug'], $roleId, false);
        assert_eq(200, $uninstallStatus);
        assert_true(str_contains($uninstallBody, 'Only super-admins can perform that action'), 'uninstall must still refuse an ordinary tenant admin exactly as before');
    } finally {
        paat_drop_role($roleId);
    }
});

}
