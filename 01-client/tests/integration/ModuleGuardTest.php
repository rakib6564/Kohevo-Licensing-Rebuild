<?php
/**
 * Phase 7: the Module Entitlement Guard
 * (src/Services/Licensing/ModuleGuard.php), wired into every Forms/
 * Membership/Booking admin entry point, public route, the Booking API
 * handler, and the cron/webhook listeners that perform module-specific
 * background work (docs/02-architecture/07-MODULE-GUARD-ARCHITECTURE.md).
 *
 * Every probe below passes SLATE_LICENSE_GUARD_LIVE=1 to the shared test
 * fixtures (tests/fixtures/*-probe.php), which by default define
 * SLATE_TESTING and so bypass BOTH the Global License Guard and this new
 * Module Guard (ModuleGuard::isBypassed() mirrors slate_license_guard()'s
 * own SLATE_TESTING/SLATE_MIGRATING/APP_ENV=testing bypass by design — see
 * ModuleGuard.php's own docblock) — that default is what keeps every OTHER
 * existing Forms/Membership/Booking integration suite (which exercises
 * these exact same admin/public entry points with zero entitlement state
 * seeded) unaffected by this phase's addition. This file is the one place
 * that deliberately asks for the real thing, exactly like
 * GlobalLicenseGuardTest.php does for Phase 6.
 *
 * Every test seeds a globally VALID, trusted license (status=active,
 * fresh fetched_at, correct installation_id) so the Global License Guard
 * always passes — what varies is only the `entitlements` array, isolating
 * these assertions to the Module Guard's own decision.
 */

declare(strict_types=1);

use Slate\Services\Licensing\SlateLicenseCacheStore;

/**
 * EntitlementService::authorityMode() (the dependency ModuleGuard::
 * isEntitled() ultimately reads through) only consults
 * SlateLicenseCacheStore at all when REMOTE_REQUIRED_ENV is fully present
 * (EntitlementService::remoteConfigured()) — otherwise it falls through to
 * 'legacy'/'unconfigured' and never looks at what mg_seed() writes. This
 * local/CI environment's own .env has none of those four vars configured
 * by default. Every probe below is a fresh child process (shell_exec()),
 * so these are passed as real environment variables, not $_ENV mutations
 * in the parent — mirrors Phase3EntitlementAuthorityTest.php's own
 * p3_set_remote_env(true) values exactly (arbitrary but well-formed; no
 * signature verification happens on this read path, only
 * RemoteLicenseClient's write path uses the key for real).
 */
function mg_env_prefix(): string {
    return 'SLATE_LICENSE_GUARD_LIVE=1 '
        . 'LICENSE_SERVER_URL=' . escapeshellarg('https://license.test') . ' '
        . 'LICENSE_SERVER_PUBLIC_KEY=' . escapeshellarg(base64_encode(str_repeat('p', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES))) . ' '
        . 'LICENSE_PRODUCT=' . escapeshellarg('kohevo') . ' '
        . 'LICENSE_KEY=' . escapeshellarg('test-key') . ' ';
}

function mg_shell(string $fixture, array $args): array {
    $cmd = mg_env_prefix() . escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/' . $fixture);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string) $arg);
    }
    $cmd .= ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) ($m[1] ?? 0), 'body' => substr($out, strlen($m[0] ?? ''))];
}

function mg_admin(string $page, string $query = '', int $roleId = 1, string $platformAdmin = '0'): array {
    return mg_shell('admin-page-probe.php', [$page, $query, $roleId, $platformAdmin]);
}

function mg_admin_post(string $page, array $fields = [], int $roleId = 1): array {
    return mg_shell('admin-page-post-probe.php', [$page, json_encode($fields), $roleId, '0']);
}

function mg_public(string $page, string $query = ''): array {
    return mg_shell('public-page-probe.php', [$page, $query]);
}

function mg_api(string $routePath, string $method = 'GET'): array {
    return mg_shell('api-request-probe.php', [$routePath, $method, '', '']);
}

function mg_cron(string $module): string {
    $cmd = mg_env_prefix() . escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/module-guard-cron-probe.php')
         . ' ' . escapeshellarg($module) . ' 2>/dev/null';
    return trim((string) shell_exec($cmd));
}

/** Shared, local to this suite — deliberately distinct from GlobalLicenseGuardTest's own 'd'*32 identity. */
function mg_local_identity(): string { return str_repeat('7', 32); }

function mg_ensure_local_identity(?string $installationId = null): void {
    $installationId ??= mg_local_identity();
    $row = Database::row('SELECT installation_id FROM installation_identity WHERE singleton_id = 1');
    if ($row === null) {
        Database::insert('installation_identity', [
            'singleton_id' => 1, 'tenant_id' => current_tenant_id(), 'installation_id' => $installationId,
        ]);
    } elseif ((string) $row['installation_id'] !== $installationId) {
        Database::update('installation_identity', ['installation_id' => $installationId], 'singleton_id = 1', []);
    }
}

function mg_clear(): void {
    Database::query('DELETE FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]);
}

/** A globally valid, trusted license with exactly the given module entitlements. */
function mg_seed(array $entitlements): void {
    mg_ensure_local_identity();
    (new SlateLicenseCacheStore(current_tenant_id()))->save([
        'status' => 'active', 'plan' => 'pro', 'entitlements' => $entitlements,
        'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
        'installation_id' => mg_local_identity(),
    ]);
}

// ── Core is never gated by the Module Guard ──────────────────────────────

unit('module guard: Core (admin dashboard) remains reachable on a valid license with ZERO module entitlements', function () {
    mg_clear();
    try {
        mg_seed([]); // valid license, no optional modules at all
        $res = mg_admin('admin/index.php');
        assert_eq(200, $res['status'], 'Core must not require any Module Guard call (04 §2)');
    } finally {
        mg_clear();
    }
});

// ── "None" combination: every optional module blocked, Core unaffected ──

unit('module guard: no entitlements — Forms, Membership, and Booking admin are all blocked (403), Core still works', function () {
    mg_clear();
    try {
        mg_seed([]);
        assert_eq(403, mg_admin('plugins/forms/admin/index.php')['status']);
        assert_eq(403, mg_admin('plugins/membership/admin/index.php')['status']);
        assert_eq(403, mg_admin('plugins/booking/admin/index.php')['status']);
        assert_eq(200, mg_admin('admin/index.php')['status']);
    } finally {
        mg_clear();
    }
});

unit('module guard: no entitlements — the blocked admin page body names the license reason, not a generic error', function () {
    mg_clear();
    try {
        mg_seed([]);
        $res = mg_admin('plugins/booking/admin/index.php');
        assert_eq(403, $res['status']);
        assert_true(str_contains($res['body'], 'not included in your current license'),
            'must be the Module Guard\'s own message, not Global Guard\'s "license is not currently active" (they are different, independent reasons)');
    } finally {
        mg_clear();
    }
});

unit('module guard: no entitlements — public routes return 404 (anti-enumeration), not 403', function () {
    mg_clear();
    try {
        mg_seed([]);
        assert_eq(404, mg_public('plugins/forms/public/router.php')['status']);
        assert_eq(404, mg_public('plugins/booking/public/router.php')['status']);
        assert_eq(404, mg_public('plugins/membership/public/landing.php')['status']);
    } finally {
        mg_clear();
    }
});

unit('module guard: no entitlements — the Booking API route is blocked with a machine-readable JSON error', function () {
    mg_clear();
    try {
        mg_seed([]);
        $res = mg_api('booking/services');
        assert_eq(403, $res['status']);
        assert_true(str_contains($res['body'], 'MODULE_NOT_ENTITLED'), 'API callers must get a machine-readable code, not an HTML page');
        assert_true(str_contains($res['body'], '"module"'), 'the error body must name which module was not entitled');
    } finally {
        mg_clear();
    }
});

// ── The 8 valid combinations (∅ covered above; the 7 non-empty subsets here) ──

unit('module guard: Forms only — Forms works, Membership and Booking blocked', function () {
    mg_clear();
    try {
        mg_seed(['forms']);
        assert_eq(200, mg_admin('plugins/forms/admin/index.php')['status']);
        assert_eq(403, mg_admin('plugins/membership/admin/index.php')['status']);
        assert_eq(403, mg_admin('plugins/booking/admin/index.php')['status']);
    } finally {
        mg_clear();
    }
});

unit('module guard: Membership only — Membership works, Forms and Booking blocked', function () {
    mg_clear();
    try {
        mg_seed(['membership']);
        assert_eq(403, mg_admin('plugins/forms/admin/index.php')['status']);
        assert_eq(200, mg_admin('plugins/membership/admin/index.php')['status']);
        assert_eq(403, mg_admin('plugins/booking/admin/index.php')['status']);
    } finally {
        mg_clear();
    }
});

unit('module guard: Booking only — Booking works (admin + API), Forms and Membership blocked', function () {
    mg_clear();
    try {
        mg_seed(['booking']);
        assert_eq(403, mg_admin('plugins/forms/admin/index.php')['status']);
        assert_eq(403, mg_admin('plugins/membership/admin/index.php')['status']);
        assert_eq(200, mg_admin('plugins/booking/admin/index.php')['status']);
        assert_eq(200, mg_api('booking/services')['status'], 'Booking entitlement alone must also unlock its API route');
    } finally {
        mg_clear();
    }
});

unit('module guard: Forms + Membership — both work, Booking blocked', function () {
    mg_clear();
    try {
        mg_seed(['forms', 'membership']);
        assert_eq(200, mg_admin('plugins/forms/admin/index.php')['status']);
        assert_eq(200, mg_admin('plugins/membership/admin/index.php')['status']);
        assert_eq(403, mg_admin('plugins/booking/admin/index.php')['status']);
    } finally {
        mg_clear();
    }
});

unit('module guard: Forms + Booking — both work, Membership blocked', function () {
    mg_clear();
    try {
        mg_seed(['forms', 'booking']);
        assert_eq(200, mg_admin('plugins/forms/admin/index.php')['status']);
        assert_eq(403, mg_admin('plugins/membership/admin/index.php')['status']);
        assert_eq(200, mg_admin('plugins/booking/admin/index.php')['status']);
    } finally {
        mg_clear();
    }
});

unit('module guard: Membership + Booking — both work, Forms blocked', function () {
    mg_clear();
    try {
        mg_seed(['membership', 'booking']);
        assert_eq(403, mg_admin('plugins/forms/admin/index.php')['status']);
        assert_eq(200, mg_admin('plugins/membership/admin/index.php')['status']);
        assert_eq(200, mg_admin('plugins/booking/admin/index.php')['status']);
    } finally {
        mg_clear();
    }
});

unit('module guard: all three — Forms, Membership, and Booking all work, including the Booking API', function () {
    mg_clear();
    try {
        mg_seed(['forms', 'membership', 'booking']);
        assert_eq(200, mg_admin('plugins/forms/admin/index.php')['status']);
        assert_eq(200, mg_admin('plugins/membership/admin/index.php')['status']);
        assert_eq(200, mg_admin('plugins/booking/admin/index.php')['status']);
        assert_eq(200, mg_api('booking/services')['status']);
    } finally {
        mg_clear();
    }
});

// ── Direct-access bypass attempts on an unentitled module (Booking) ─────

unit('module guard: a DIFFERENT, non-index admin page for an unentitled module is also blocked, not just the landing page', function () {
    mg_clear();
    try {
        mg_seed(['forms']); // booking NOT entitled
        assert_eq(403, mg_admin('plugins/booking/admin/settings.php')['status']);
        assert_eq(403, mg_admin('plugins/booking/admin/appointments.php')['status']);
        assert_eq(200, mg_admin('plugins/forms/admin/submissions.php')['status'], 'sanity: forms IS entitled here, so its own second page must be 200');
    } finally {
        mg_clear();
    }
});

unit('module guard: a direct POST to an unentitled module\'s admin endpoint is rejected before any mutation runs', function () {
    mg_clear();
    try {
        mg_seed([]); // booking NOT entitled
        $res = mg_admin_post('plugins/booking/admin/settings.php', ['_action' => 'save_general']);
        assert_eq(403, $res['status'], 'the Guard must reject the POST before the settings handler ever writes anything — the guard call precedes all mutation logic in every edited entry point');
    } finally {
        mg_clear();
    }
});

unit('module guard: an authenticated Super Admin session does NOT bypass an unentitled module', function () {
    mg_clear();
    try {
        mg_seed(['forms']); // booking NOT entitled
        $res = mg_admin('plugins/booking/admin/index.php', '', 1, '0'); // roleId=1 == Super Admin
        assert_eq(403, $res['status'], 'RBAC/session privilege must not substitute for entitlement (07 §6)');

        $platformAdminRes = mg_admin('plugins/booking/admin/index.php', '', 1, '1'); // + platform_admins membership
        assert_eq(403, $platformAdminRes['status'], 'platform-admin membership must not bypass it either');
    } finally {
        mg_clear();
    }
});

// ── Tampering: a mismatched installation_id cannot unlock a module ──────

unit('module guard: a cache row bound to a DIFFERENT installation cannot unlock a module, even with generous entitlements', function () {
    mg_clear();
    try {
        mg_ensure_local_identity(str_repeat('a', 32)); // THIS install's real identity
        (new SlateLicenseCacheStore(current_tenant_id()))->save([
            'status' => 'active', 'plan' => 'pro', 'entitlements' => ['forms', 'membership', 'booking'],
            'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
            'installation_id' => str_repeat('b', 32), // a DIFFERENT installation's identity — tampered/copied row
        ]);
        $res = mg_admin('plugins/booking/admin/index.php');
        assert_eq(403, $res['status'], 'an untrusted row must not grant entitlement, matching the Global Guard\'s own trust boundary (10 §4, D14)');
    } finally {
        mg_clear();
    }
});

// ── Cross-module isolation ────────────────────────────────────────────────

unit('module guard: granting one module never leaks entitlement to another (Booking-only does not unlock Membership\'s API-adjacent admin surface)', function () {
    mg_clear();
    try {
        mg_seed(['booking']);
        assert_eq(403, mg_admin('plugins/membership/admin/plans.php')['status']);
        assert_eq(403, mg_admin('plugins/forms/admin/edit.php')['status']);
    } finally {
        mg_clear();
    }
});

// ── Background/cron: the exact boolean the listeners gate on ────────────

unit('module guard: background jobs — ModuleGuard::allows() denies an unentitled module and allows an entitled one', function () {
    mg_clear();
    try {
        mg_seed(['forms']); // booking and membership NOT entitled
        assert_eq('DENY', mg_cron('booking'), 'Booking::sendReminders()/GoogleCalendarSync::runCron()/runMessagingNudgeCron() and Booking::onStripeEvent() all gate on this exact call (07 §5)');
        assert_eq('DENY', mg_cron('membership'), 'Membership::onStripeEvent() gates on this exact call');
        assert_eq('ALLOW', mg_cron('forms'), 'sanity: forms IS entitled here');

        mg_seed(['booking', 'membership']);
        assert_eq('ALLOW', mg_cron('booking'));
        assert_eq('ALLOW', mg_cron('membership'));
    } finally {
        mg_clear();
    }
});
