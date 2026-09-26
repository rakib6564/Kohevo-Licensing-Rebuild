<?php
/**
 * Phase 6: the Global License Guard (includes/license_guard.php,
 * slate_license_guard()), wired into config.php itself so it applies to
 * EVERY entry point that boots the app — admin, API, AJAX, POST, the
 * public site, cron.php — not just the two pages slate_license_gate()
 * (Phase 5, see LicenseGateTest.php) covered.
 *
 * Every probe below passes SLATE_LICENSE_GUARD_LIVE=1 to the shared test
 * fixtures (tests/fixtures/*-probe.php), which by default define
 * SLATE_TESTING and so bypass this new Guard — that default is what keeps
 * every OTHER existing integration suite (booking, coaching, tenancy,
 * stripe, etc.) unaffected by its addition. This file is the one place
 * that deliberately asks for the real thing.
 */

declare(strict_types=1);

use Slate\Services\Licensing\SlateLicenseCacheStore;

function glg_shell(string $fixture, array $args): array {
    $cmd = 'SLATE_LICENSE_GUARD_LIVE=1 ' . escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/' . $fixture);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string) $arg);
    }
    $cmd .= ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) ($m[1] ?? 0), 'body' => substr($out, strlen($m[0] ?? ''))];
}

function glg_public(string $page, string $query = ''): array {
    return glg_shell('public-page-probe.php', [$page, $query]);
}

function glg_admin(string $page, string $query = '', int $roleId = 1, string $platformAdmin = '0'): array {
    return glg_shell('admin-page-probe.php', [$page, $query, $roleId, $platformAdmin]);
}

function glg_admin_post(string $page, array $fields = [], int $roleId = 1): array {
    return glg_shell('admin-page-post-probe.php', [$page, json_encode($fields), $roleId, '0']);
}

function glg_api(string $routePath, string $method = 'GET'): array {
    return glg_shell('api-request-probe.php', [$routePath, $method, '', '']);
}

function glg_cron(string $mode): array {
    return glg_shell('cron-probe.php', [$mode]);
}

function glg_clear(): void {
    Database::query('DELETE FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]);
}

/** Shared with LicenseGateTest.php's own convention: one canonical local identity. */
function glg_local_identity(): string { return str_repeat('d', 32); }

function glg_ensure_local_identity(?string $installationId = null): void {
    $installationId ??= glg_local_identity();
    $row = Database::row('SELECT installation_id FROM installation_identity WHERE singleton_id = 1');
    if ($row === null) {
        Database::insert('installation_identity', [
            'singleton_id' => 1, 'tenant_id' => current_tenant_id(), 'installation_id' => $installationId,
        ]);
    } elseif ((string) $row['installation_id'] !== $installationId) {
        Database::update('installation_identity', ['installation_id' => $installationId], 'singleton_id = 1', []);
    }
}

function glg_seed(string $status, string $fetchedAt, ?string $expiresAt = null): void {
    glg_ensure_local_identity();
    (new SlateLicenseCacheStore(current_tenant_id()))->save([
        'status' => $status, 'plan' => 'pro', 'entitlements' => ['white_label'],
        'expires_at' => $expiresAt, 'fetched_at' => $fetchedAt,
        'installation_id' => glg_local_identity(),
    ]);
}

// ── Regression: a genuinely active installation ─────────────────────────

unit('global guard: active license — admin dashboard, API root, and the public landing page all remain reachable', function () {
    glg_clear();
    try {
        glg_seed('active', gmdate('Y-m-d H:i:s'));

        $admin = glg_admin('admin/index.php');
        assert_eq(200, $admin['status'], 'admin dashboard must remain reachable for an active license');

        $api = glg_api('');
        assert_eq(200, $api['status'], 'API root must remain reachable for an active license');

        $pub = glg_public('index.php');
        assert_eq(200, $pub['status']);
    } finally {
        glg_clear();
    }
});

// ── Fail-closed license states ───────────────────────────────────────────

unit('global guard: no cache row at all (never activated) locks the admin dashboard — unconfigured means locked, not unrestricted (06 §7)', function () {
    glg_clear();
    $res = glg_admin('admin/index.php');
    assert_eq(403, $res['status']);
});

unit('global guard: an untrusted cache row (installation_id mismatch — tampering/copied state) locks the admin dashboard', function () {
    glg_clear();
    glg_ensure_local_identity(str_repeat('a', 32));
    try {
        (new SlateLicenseCacheStore(current_tenant_id()))->save([
            'status' => 'active', 'plan' => 'pro', 'entitlements' => ['white_label'],
            'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
            'installation_id' => str_repeat('b', 32), // a DIFFERENT installation's identity
        ]);
        $res = glg_admin('admin/index.php');
        assert_eq(403, $res['status'], 'a cache row bound to a different installation must not unlock this one');
    } finally {
        glg_clear();
    }
});

unit('global guard: suspended locks the admin dashboard', function () {
    glg_clear();
    try {
        glg_seed('suspended', gmdate('Y-m-d H:i:s'));
        $res = glg_admin('admin/index.php');
        assert_eq(403, $res['status']);
    } finally {
        glg_clear();
    }
});

unit('global guard: revoked locks the admin dashboard', function () {
    glg_clear();
    try {
        glg_seed('revoked', gmdate('Y-m-d H:i:s'));
        $res = glg_admin('admin/index.php');
        assert_eq(403, $res['status']);
    } finally {
        glg_clear();
    }
});

unit('global guard: expired past the 7-day commercial grace period locks the admin dashboard', function () {
    glg_clear();
    try {
        glg_seed('active', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() - 8 * 86400));
        $res = glg_admin('admin/index.php');
        assert_eq(403, $res['status'], 'expires_at + grace_days already passed -- must lock even though the stored status is still "active"');
    } finally {
        glg_clear();
    }
});

unit('global guard: expired but still within the 7-day commercial grace period does NOT lock', function () {
    glg_clear();
    try {
        glg_seed('active', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() - 2 * 86400));
        $res = glg_admin('admin/index.php');
        assert_eq(200, $res['status'], 'inside grace, full access must continue (08 §2)');
    } finally {
        glg_clear();
    }
});

unit('global guard: a signed snapshot stale past the 7-day offline-tolerance window locks, even though its own status/expiry math would say Active', function () {
    glg_clear();
    try {
        glg_seed('active', gmdate('Y-m-d H:i:s', time() - 8 * 86400), null);
        $res = glg_admin('admin/index.php');
        assert_eq(403, $res['status'], '08 §5: stale past offline tolerance + server unreachable must fail closed regardless of the stale snapshot\'s own math');
    } finally {
        glg_clear();
    }
});

// ── Bypass attempts while locked ─────────────────────────────────────────

unit('global guard: direct admin URL access cannot bypass the lock', function () {
    glg_clear();
    try {
        glg_seed('suspended', gmdate('Y-m-d H:i:s'));
        $res = glg_admin('admin/settings.php');
        assert_eq(403, $res['status']);
    } finally {
        glg_clear();
    }
});

unit('global guard: a direct POST to a protected admin endpoint cannot bypass the lock', function () {
    glg_clear();
    try {
        glg_seed('suspended', gmdate('Y-m-d H:i:s'));
        $res = glg_admin_post('admin/notifications-read.php', []);
        assert_eq(403, $res['status'], 'the Guard must reject the POST before the handler ever marks anything read');
    } finally {
        glg_clear();
    }
});

unit('global guard: a direct API request cannot bypass the lock, and gets a machine-readable JSON error, not the HTML page', function () {
    glg_clear();
    try {
        glg_seed('suspended', gmdate('Y-m-d H:i:s'));
        $res = glg_api('');
        assert_eq(403, $res['status']);
        assert_true(str_contains($res['body'], 'LICENSE_INACTIVE'), 'API callers must get ApiRouter\'s own JSON error shape, not a branded HTML page');
    } finally {
        glg_clear();
    }
});

unit('global guard: an AJAX-style admin endpoint cannot bypass the lock', function () {
    glg_clear();
    try {
        glg_seed('suspended', gmdate('Y-m-d H:i:s'));
        $res = glg_admin('admin/notifications-poll.php');
        assert_eq(403, $res['status']);
    } finally {
        glg_clear();
    }
});

unit('global guard: an authenticated Super Admin session does NOT bypass the lock (06 §7 -- the core fail-open fix)', function () {
    glg_clear();
    try {
        glg_seed('revoked', gmdate('Y-m-d H:i:s'));
        $res = glg_admin('admin/index.php', '', 1, '0'); // roleId=1 == Super Admin
        assert_eq(403, $res['status'], 'admin sessions must no longer bypass the Guard, only reach login/logout/recovery');

        $platformAdminRes = glg_admin('admin/index.php', '', 1, '1'); // + platform_admins membership
        assert_eq(403, $platformAdminRes['status'], 'platform-admin membership must not bypass it either');
    } finally {
        glg_clear();
    }
});

// ── Whitelist: the recovery surface stays reachable while locked ────────

unit('global guard: admin/license.php (the recovery screen) stays reachable while fully locked', function () {
    glg_clear();
    try {
        glg_seed('revoked', gmdate('Y-m-d H:i:s'));
        $res = glg_admin('admin/license.php');
        assert_eq(200, $res['status'], 'without this, a locked install has no way back to a licensed state');
        assert_true(str_contains($res['body'], 'License'), 'sanity check: this is actually the recovery page, not an error shell');
    } finally {
        glg_clear();
    }
});

unit('global guard: admin/login.php and admin/logout.php stay reachable while fully locked', function () {
    glg_clear();
    try {
        glg_seed('revoked', gmdate('Y-m-d H:i:s'));

        // Unauthenticated -- an already-authenticated session would make
        // login.php itself redirect (302) to the (locked) dashboard, which
        // would prove nothing about the Guard's whitelist.
        $login = glg_public('admin/login.php');
        assert_eq(200, $login['status'], 'an admin must be able to authenticate in order to reach the recovery screen at all');

        $logout = glg_public('admin/logout.php');
        assert_true(in_array($logout['status'], [302, 200], true), 'logout must not be blocked by the Guard (it redirects, so 302 is the normal outcome, not 403)');
    } finally {
        glg_clear();
    }
});

unit('global guard: the route whitelist cannot be abused to reach a DIFFERENT, non-whitelisted admin page', function () {
    glg_clear();
    try {
        glg_seed('revoked', gmdate('Y-m-d H:i:s'));
        // admin/license.php itself is whitelisted; admin/licenses.php (the
        // unrelated legacy Licenses management page) is a completely
        // different script and must NOT ride along on the same allowance.
        $res = glg_admin('admin/licenses.php');
        assert_eq(403, $res['status'], 'the whitelist is exact-path, not a prefix/substring match');
    } finally {
        glg_clear();
    }
});

unit('global guard: cron.php stays reachable while fully locked -- its own CRON_SECRET gate still runs, not the license lock', function () {
    glg_clear();
    try {
        glg_seed('revoked', gmdate('Y-m-d H:i:s'));
        $wrong = glg_cron('wrong');
        assert_eq(403, $wrong['status']);
        assert_true(str_contains($wrong['body'], 'forbidden'), 'the 403 must be cron.php\'s own "forbidden" (bad key), not the Guard\'s "license_inactive" -- proves the Guard did not preempt cron.php\'s own auth');
    } finally {
        glg_clear();
    }
});
