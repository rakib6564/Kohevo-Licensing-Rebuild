<?php
/**
 * Phase 8: the client License page (admin/license.php) and the dashboard's
 * license summary (admin/index.php), rendered end-to-end through the real
 * entry points in a child process — the same fixtures and the same
 * SLATE_LICENSE_GUARD_LIVE=1 opt-in GlobalLicenseGuardTest.php and
 * ModuleGuardTest.php use, so the Global License Guard is live for every
 * request here unless a test says otherwise.
 *
 * Remote authority env values mirror ModuleGuardTest.php's mg_env_prefix()
 * (arbitrary but well-formed; nothing on this read path verifies a
 * signature). The values are deliberately distinctive so the "never
 * rendered" assertions below are meaningful.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/license_signing.php';

use Slate\Services\Licensing\SlateLicenseCacheStore;

const CLU_SERVER_URL  = 'https://license-phase8.test';
const CLU_LICENSE_KEY = 'PH8-RAW-LICENSE-KEY-7c1e0f9a';
const CLU_PLAN        = 'Phase8 Professional';

function clu_public_key(): string {
    return license_test_public_key();
}

function clu_env_prefix(bool $live = true, bool $remote = true): string {
    $prefix = $live ? 'SLATE_LICENSE_GUARD_LIVE=1 ' : '';
    if (!$remote) {
        // Phase 10: the cache's verification key alone (never enough to
        // switch remote mode on) so a seeded signed snapshot still verifies.
        $prefix .= license_test_env_prefix();
    }
    if ($remote) {
        $prefix .= 'LICENSE_SERVER_URL=' . escapeshellarg(CLU_SERVER_URL) . ' '
            . 'LICENSE_SERVER_PUBLIC_KEY=' . escapeshellarg(clu_public_key()) . ' '
            . 'LICENSE_PRODUCT=' . escapeshellarg('kohevo') . ' '
            . 'LICENSE_KEY=' . escapeshellarg(CLU_LICENSE_KEY) . ' ';
    }
    return $prefix;
}

function clu_shell(string $fixture, array $args, bool $live = true, bool $remote = true): array {
    $cmd = clu_env_prefix($live, $remote) . escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/' . $fixture);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string) $arg);
    }
    $cmd .= ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) ($m[1] ?? 0), 'body' => substr($out, strlen($m[0] ?? ''))];
}

function clu_get(string $page = 'admin/license.php', int $roleId = 1, bool $live = true): array {
    return clu_shell('admin-page-probe.php', [$page, '', $roleId, '0'], $live);
}

function clu_post(array $fields, int $roleId = 1, bool $remote = true): array {
    return clu_shell('admin-page-post-probe.php', ['admin/license.php', json_encode($fields), $roleId, '0'], true, $remote);
}

function clu_post_badcsrf(array $fields): array {
    return clu_shell('admin-page-post-badcsrf-probe.php', ['admin/license.php', json_encode($fields), 1, '0']);
}

/** Distinct from GlobalLicenseGuardTest ('d'*32) and ModuleGuardTest ('7'*32). */
function clu_identity(): string { return str_repeat('e', 32); }

function clu_ensure_identity(string $installationId): void {
    $row = Database::row('SELECT installation_id FROM installation_identity WHERE singleton_id = 1');
    if ($row === null) {
        Database::insert('installation_identity', [
            'singleton_id' => 1, 'tenant_id' => current_tenant_id(), 'installation_id' => $installationId,
        ]);
    } elseif ((string) $row['installation_id'] !== $installationId) {
        Database::update('installation_identity', ['installation_id' => $installationId], 'singleton_id = 1', []);
    }
}

function clu_clear(): void {
    Database::query('DELETE FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]);
}

function clu_seed(array $overrides = []): void {
    clu_ensure_identity(clu_identity());
    license_test_seed_cache(current_tenant_id(), $overrides + [
        'status' => 'active', 'plan' => CLU_PLAN, 'entitlements' => ['forms', 'booking'],
        'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
        'installation_id' => clu_identity(),
    ]);
}

function clu_module_state(string $body, string $module): ?string {
    return preg_match('/data-module="' . preg_quote($module, '/') . '" data-module-state="([a-z_]+)"/', $body, $m) ? $m[1] : null;
}

function clu_license_state(string $body): ?string {
    return preg_match('/data-license-state="([a-z_]+)"/', $body, $m) ? $m[1] : null;
}

/** Nothing sensitive may appear in ANY rendered license UI body. */
function clu_assert_no_secrets(string $body, string $context): void {
    assert_false(str_contains($body, CLU_LICENSE_KEY), "$context: the raw license key must never be rendered");
    assert_false(str_contains($body, clu_public_key()), "$context: the license server public key must never be rendered");
    assert_false(str_contains($body, CLU_SERVER_URL), "$context: the license server URL must never be rendered");
    assert_false(str_contains($body, clu_identity()), "$context: the installation ID must never be rendered");
    assert_false(str_contains($body, str_repeat('f', 32)), "$context: a foreign installation ID must never be rendered");
    // (The word "signature" alone is the Kohevo platform-branding sidebar
    // row, not a cryptographic value — match the actual sensitive fields.)
    assert_false((bool) preg_match('/raw_signature|signature_valid|raw_payload|private[_ ]key|PDOException|SQLSTATE/i', $body), "$context: no signature/payload internals or SQL errors");
}

function clu_make_role(string $tag, array $perms): int {
    $roleId = Database::insert('roles', [
        'tenant_id' => current_tenant_id(),
        'name'      => 'Probe CLU ' . $tag,
        'slug'      => '__probe-clu-' . $tag . '-' . bin2hex(random_bytes(4)),
        'is_system' => 0,
    ]);
    foreach ($perms as $perm) {
        Database::insert('role_permissions', ['role_id' => $roleId, 'perm_key' => $perm, 'granted' => 1]);
    }
    return $roleId;
}

function clu_drop_role(int $roleId): void {
    Database::query('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
    Database::query('DELETE FROM roles WHERE id = ?', [$roleId]);
}

// ── A. Active license ────────────────────────────────────────────────────

unit('Phase 8 license UI: an active license renders status, plan, expiry and module entitlements inside the normal admin shell', function () {
    clu_clear();
    try {
        $expires = gmdate('Y-m-d H:i:s', time() + 60 * 86400);
        clu_seed(['expires_at' => $expires]);
        $res = clu_get();
        assert_eq(200, $res['status']);
        assert_eq('active', clu_license_state($res['body']));
        assert_true(str_contains($res['body'], '> Active<'), 'Active badge label');
        assert_true(str_contains($res['body'], CLU_PLAN), 'plan must render');
        assert_true(str_contains($res['body'], date('M j, Y', strtotime($expires))), 'expiry date must render');
        assert_eq('enabled', clu_module_state($res['body'], 'forms'));
        assert_eq('enabled', clu_module_state($res['body'], 'booking'));
        assert_eq('not_included', clu_module_state($res['body'], 'membership'), 'an unentitled module must not be shown as enabled');
        assert_true(str_contains($res['body'], 'Form Builder') && str_contains($res['body'], 'Membership') && str_contains($res['body'], 'Booking'));
        assert_true(str_contains($res['body'], 'class="sidebar') || str_contains($res['body'], 'admin/license.php" class="nav'),
            'an unlocked installation renders the page inside the normal admin chrome');
        assert_false(str_contains($res['body'], 'currently <strong>locked</strong>'));
        clu_assert_no_secrets($res['body'], 'active');
    } finally {
        clu_clear();
    }
});

unit('Phase 8 license UI: Core is never presented as an optional module; Editor/Content are not introduced', function () {
    clu_clear();
    try {
        clu_seed(['entitlements' => ['forms', 'membership', 'booking', 'dashboard', 'admin-user', 'site-settings']]);
        $res = clu_get();
        assert_eq(200, $res['status']);
        preg_match_all('/data-module="([a-z_-]+)"/', $res['body'], $m);
        assert_eq(['forms', 'membership', 'booking'], $m[1], 'exactly the three optional modules, in order');
        assert_true(str_contains($res['body'], 'included with every valid license'), 'Core is described as always included');
    } finally {
        clu_clear();
    }
});

unit('Phase 8 license UI: a license with no optional modules shows none as enabled', function () {
    clu_clear();
    try {
        clu_seed(['entitlements' => []]);
        $res = clu_get();
        assert_eq(200, $res['status']);
        assert_false(str_contains($res['body'], 'data-module-state="enabled"'));
    } finally {
        clu_clear();
    }
});

// ── B/C. Expiry: within grace vs beyond grace ───────────────────────────

unit('Phase 8 license UI: expired within the existing grace window is labelled grace and stays unlocked', function () {
    clu_clear();
    try {
        clu_seed(['expires_at' => gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);
        $res = clu_get();
        assert_eq(200, $res['status']);
        assert_eq('grace', clu_license_state($res['body']));
        assert_false(str_contains($res['body'], 'currently <strong>locked</strong>'));
        // Phase 9 §12 supersedes the Phase 8 assertion here: entitled
        // modules now stay live through commercial grace (EntitlementService
        // no longer cuts them off at expires_at), and the page reports that
        // truthfully — while an unentitled module is still not enabled.
        assert_eq('enabled', clu_module_state($res['body'], 'forms'), 'entitled modules remain enabled during grace (Phase 9 §12)');
        assert_eq('enabled', clu_module_state($res['body'], 'booking'));
        assert_eq('not_included', clu_module_state($res['body'], 'membership'), 'grace never grants an unentitled module');
        // Phase 9 owns the countdown: the grace end and remaining time render.
        assert_true(str_contains($res['body'], 'data-license-grace-ends'), 'Phase 9: grace end date is shown');
        assert_true((bool) preg_match('/\d+ days?( \d+ hours?)? remaining/', $res['body']), 'Phase 9: remaining grace time is shown');
    } finally {
        clu_clear();
    }
});

unit('Phase 8 license UI: expired beyond grace renders as locked, with the recovery form, and no enabled module', function () {
    clu_clear();
    try {
        clu_seed(['expires_at' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        $res = clu_get();
        assert_eq(200, $res['status'], 'the recovery screen must stay reachable while locked');
        assert_eq('expired', clu_license_state($res['body']));
        assert_true(str_contains($res['body'], 'currently <strong>locked</strong>'));
        assert_true(str_contains($res['body'], 'name="license_key"'), 'recovery form must be present');
        assert_false(str_contains($res['body'], 'data-module-state="enabled"'));
    } finally {
        clu_clear();
    }
});

// ── D/E. Suspended / revoked ─────────────────────────────────────────────

unit('Phase 8 license UI: suspended and revoked render safely as locked with no enabled module', function () {
    foreach (['suspended', 'revoked'] as $status) {
        clu_clear();
        try {
            clu_seed(['status' => $status]);
            $res = clu_get();
            assert_eq(200, $res['status'], "$status: recovery page reachable");
            assert_eq($status, clu_license_state($res['body']));
            assert_true(str_contains($res['body'], 'currently <strong>locked</strong>'));
            assert_false(str_contains($res['body'], 'data-module-state="enabled"'));
            assert_false(str_contains($res['body'], 'data-module-state="not_included"'), "$status: must not make claims about what the license includes");
            clu_assert_no_secrets($res['body'], $status);
        } finally {
            clu_clear();
        }
    }
});

// ── F/G. Missing license / missing cache ────────────────────────────────

unit('Phase 8 license UI: a missing license (no cache row) shows "Not activated" and no plan or expiry', function () {
    clu_clear();
    clu_ensure_identity(clu_identity());
    $res = clu_get();
    assert_eq(200, $res['status']);
    assert_eq('missing', clu_license_state($res['body']));
    assert_true(str_contains($res['body'], 'Not activated'));
    assert_true((bool) preg_match('/data-license-plan>—</u', $res['body']), 'no plan fabricated');
    assert_true((bool) preg_match('/data-license-expiry>—</u', $res['body']), 'no expiry fabricated');
    assert_false(str_contains($res['body'], 'data-module-state="enabled"'));
});

// ── H. Stale cache ───────────────────────────────────────────────────────

unit('Phase 8 license UI: a stale cache is not presented as a valid license and does not show its plan', function () {
    clu_clear();
    try {
        clu_seed(['fetched_at' => gmdate('Y-m-d H:i:s', time() - 10 * 86400)]);
        $res = clu_get();
        assert_eq(200, $res['status']);
        assert_eq('stale', clu_license_state($res['body']));
        assert_false(str_contains($res['body'], CLU_PLAN), 'a stale snapshot must not be shown as current plan data');
        assert_false(str_contains($res['body'], 'data-module-state="enabled"'));
    } finally {
        clu_clear();
    }
});

// ── I/J. Installation ID mismatch / malformed row ───────────────────────

unit('Phase 8 license UI: an installation-ID-mismatched cache row exposes no license information', function () {
    clu_clear();
    try {
        clu_ensure_identity(clu_identity());
        license_test_seed_cache(current_tenant_id(), [
            'status' => 'active', 'plan' => CLU_PLAN, 'entitlements' => ['forms', 'membership', 'booking'],
            'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
            'installation_id' => str_repeat('f', 32), // another installation's identity
        ]);
        $res = clu_get();
        assert_eq(200, $res['status']);
        assert_eq('untrusted', clu_license_state($res['body']));
        assert_false(str_contains($res['body'], CLU_PLAN), 'untrusted plan must not be rendered');
        assert_false(str_contains($res['body'], 'data-module-state="enabled"'));
        clu_assert_no_secrets($res['body'], 'mismatch');
    } finally {
        clu_clear();
    }
});

unit('Phase 8 license UI: a malformed / pre-migration cache row (NULL installation_id) exposes no license information', function () {
    clu_clear();
    try {
        clu_ensure_identity(clu_identity());
        Database::insert('remote_license_cache', [
            'tenant_id' => current_tenant_id(), 'status' => 'active', 'plan' => CLU_PLAN,
            'entitlements' => json_encode(['forms']), 'fetched_at' => gmdate('Y-m-d H:i:s'),
            'installation_id' => null,
        ]);
        $res = clu_get();
        assert_eq(200, $res['status']);
        assert_eq('untrusted', clu_license_state($res['body']));
        assert_false(str_contains($res['body'], CLU_PLAN));
    } finally {
        clu_clear();
    }
});

// ── Authorization ────────────────────────────────────────────────────────

unit('Phase 8 license UI: a user without settings.view is refused server-side (GET and POST) and sees no license data', function () {
    clu_clear();
    $roleId = clu_make_role('noperm', ['media.view']);
    try {
        clu_seed();
        $get = clu_get('admin/license.php', $roleId);
        assert_eq(403, $get['status']);
        assert_false(str_contains($get['body'], CLU_PLAN));

        $post = clu_post(['license_key' => 'anything'], $roleId);
        assert_eq(403, $post['status'], 'POST must be refused too, not only hidden in the UI');
    } finally {
        clu_drop_role($roleId);
        clu_clear();
    }
});

unit('Phase 8 license UI: settings.view alone may view but may not change the license key', function () {
    clu_clear();
    $roleId = clu_make_role('viewonly', ['settings.view']);
    try {
        clu_seed();
        $get = clu_get('admin/license.php', $roleId);
        assert_eq(200, $get['status']);
        assert_true(str_contains($get['body'], CLU_PLAN));
        assert_false(str_contains($get['body'], 'name="license_key"'), 'no key form for a view-only role');

        $post = clu_post(['license_key' => 'anything'], $roleId);
        assert_eq(403, $post['status'], 'a crafted POST is refused server-side');
        assert_true(str_contains($post['body'], 'do not have permission'));
    } finally {
        clu_drop_role($roleId);
        clu_clear();
    }
});

unit('Phase 8 license UI: settings.view + settings.edit may use the license key form', function () {
    clu_clear();
    $roleId = clu_make_role('manage', ['settings.view', 'settings.edit']);
    try {
        clu_seed();
        $get = clu_get('admin/license.php', $roleId);
        assert_eq(200, $get['status']);
        assert_true(str_contains($get['body'], 'name="license_key"'));
    } finally {
        clu_drop_role($roleId);
        clu_clear();
    }
});

unit('Phase 8 license UI: no platform-level licensing management is exposed to an ordinary client admin', function () {
    clu_clear();
    // An ordinary client admin role (settings access, no Super Admin role
    // and no platform_admins membership). role_id=1 is deliberately not
    // used here: Auth::isPlatformSuperAdmin() matches it through the
    // legacy rule, which predates and is outside Phase 8.
    $roleId = clu_make_role('client-admin', ['settings.view', 'settings.edit']);
    try {
        clu_seed();
        $res = clu_get('admin/license.php', $roleId);
        assert_eq(200, $res['status']);
        foreach (['admin/licenses.php', 'admin/plans.php', 'admin/tenants.php', 'admin/platform-admins.php'] as $platformPage) {
            assert_false(str_contains($res['body'], $platformPage), "$platformPage must not be linked for a non-platform admin");
        }
        assert_false((bool) preg_match('/tenant[_ ]id/i', $res['body']), 'no tenant concept on the license page');
    } finally {
        clu_drop_role($roleId);
        clu_clear();
    }
});

// ── CSRF / key handling / recovery path ──────────────────────────────────

unit('Phase 8 license UI: a POST with a bad CSRF token is rejected before any license operation', function () {
    clu_clear();
    try {
        clu_seed(['status' => 'revoked']);
        $before = Database::row('SELECT status, fetched_at FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]);
        $res = clu_post_badcsrf(['license_key' => 'PH8-SUBMITTED-KEY-badcsrf']);
        assert_true(str_contains($res['body'], 'session expired'), 'CSRF failure message');
        assert_false(str_contains($res['body'], 'PH8-SUBMITTED-KEY-badcsrf'));
        $after = Database::row('SELECT status, fetched_at FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]);
        assert_eq($before, $after, 'cache must be untouched');
    } finally {
        clu_clear();
    }
});

unit('Phase 8 license UI: the recovery POST still runs while locked, and a submitted key is never echoed back', function () {
    clu_clear();
    try {
        clu_seed(['status' => 'revoked']);
        // No license-server settings in this child's env: the existing
        // handler must answer with its safe "not configured" message
        // without any network call, and must not echo the key.
        $res = clu_post(['license_key' => 'PH8-SUBMITTED-KEY-0042'], 1, false);
        assert_eq(200, $res['status'], 'the Guard must not block the recovery POST');
        assert_true(str_contains($res['body'], 'Licensing is not configured for this build'));
        assert_false(str_contains($res['body'], 'PH8-SUBMITTED-KEY-0042'), 'submitted key must never be rendered');
        assert_false((bool) preg_match('/name="license_key"[^>]*value=/', $res['body']), 'the input is never pre-filled');

        $empty = clu_post(['license_key' => '   '], 1, false);
        assert_true(str_contains($empty['body'], 'Enter your license key.'), 'server-side validation still applies');
    } finally {
        clu_clear();
    }
});

// ── Dashboard summary ────────────────────────────────────────────────────

unit('Phase 8 dashboard: the license summary shows status, plan, expiry, enabled modules and links to the License page', function () {
    clu_clear();
    try {
        $expires = gmdate('Y-m-d H:i:s', time() + 90 * 86400);
        clu_seed(['expires_at' => $expires, 'entitlements' => ['membership']]);
        $res = clu_get('admin/index.php');
        assert_eq(200, $res['status']);
        assert_true(str_contains($res['body'], 'data-license-summary'));
        assert_true(str_contains($res['body'], CLU_PLAN));
        assert_true(str_contains($res['body'], date('M j, Y', strtotime($expires))));
        assert_true(str_contains($res['body'], '1 / 3'), 'enabled optional modules count');
        assert_true(str_contains($res['body'], SLATE_URL . '/admin/license.php'), 'link to the License page');
        assert_true(str_contains($res['body'], 'Remote entitlement'), 'existing Phase 4 heading preserved');
        clu_assert_no_secrets($res['body'], 'dashboard');
    } finally {
        clu_clear();
    }
});

unit('Phase 8 dashboard: an ordinary client admin sees the same license summary', function () {
    clu_clear();
    $roleId = clu_make_role('dash', ['settings.view']);
    try {
        clu_seed(['entitlements' => ['forms', 'booking']]);
        $res = clu_get('admin/index.php', $roleId);
        assert_eq(200, $res['status']);
        assert_true(str_contains($res['body'], 'data-license-summary'));
        assert_true(str_contains($res['body'], '2 / 3'));
        assert_true(str_contains($res['body'], 'Form Builder, Booking'));
        assert_false(str_contains($res['body'], 'Platform overview'), 'no platform-wide counts for a client admin');
    } finally {
        clu_drop_role($roleId);
        clu_clear();
    }
});

unit('Phase 8 dashboard: untrusted state never fabricates a plan or enabled module on the dashboard summary', function () {
    clu_clear();
    try {
        clu_ensure_identity(clu_identity());
        license_test_seed_cache(current_tenant_id(), [
            'status' => 'active', 'plan' => CLU_PLAN, 'entitlements' => ['forms'],
            'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
            'installation_id' => str_repeat('f', 32),
        ]);
        // Guard bypassed (SLATE_TESTING) only so the dashboard renders at
        // all; the presenter still reads the real, untrusted state.
        $res = clu_get('admin/index.php', 1, false);
        assert_eq(200, $res['status']);
        assert_true(str_contains($res['body'], 'data-license-summary'));
        assert_true(str_contains($res['body'], 'Unverified'));
        assert_false(str_contains($res['body'], CLU_PLAN));
        assert_true(str_contains($res['body'], '0 / 3'));
    } finally {
        clu_clear();
    }
});

unit('Phase 8 nav: the sidebar links this installation\'s License page for a settings admin', function () {
    clu_clear();
    try {
        clu_seed();
        $res = clu_get('admin/index.php');
        assert_eq(200, $res['status']);
        assert_true(str_contains($res['body'], SLATE_URL . '/admin/license.php'));
    } finally {
        clu_clear();
    }
});

// ── Guard boundaries unchanged ───────────────────────────────────────────

unit('Phase 8: the Global License Guard whitelist is unchanged and still blocks other admin pages while locked', function () {
    clu_clear();
    try {
        clu_seed(['status' => 'suspended']);
        assert_eq(403, clu_get('admin/index.php')['status'], 'dashboard still locked');
        assert_eq(403, clu_get('admin/settings.php')['status'], 'settings still locked');
        assert_eq(200, clu_get('admin/license.php')['status'], 'license page still whitelisted');

        $guard = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/license_guard.php');
        preg_match('/static \$whitelist = \[(.*?)\];/s', $guard, $m);
        preg_match_all("/'([^']+)'/", $m[1] ?? '', $paths);
        assert_eq(['install.php', 'cron.php', 'admin/login.php', 'admin/logout.php', 'admin/license.php'], $paths[1]);
    } finally {
        clu_clear();
    }
});

unit('Phase 8: the License page never calls into the Guard or ModuleGuard enforcement paths itself', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/license.php');
    $code = preg_replace('#/\*.*?\*/#s', '', $src);
    assert_false(str_contains($code, 'slate_license_guard()'));
    assert_false(str_contains($code, 'slate_license_gate('));
    assert_false((bool) preg_match('/ModuleGuard::(require|allows)/', $code));
});
