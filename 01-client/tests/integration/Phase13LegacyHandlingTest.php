<?php
/**
 * Phase 13 — existing Solaya / legacy handling
 * (docs/03-implementation/PHASE-13-LEGACY-HANDLING.md).
 *
 * The legacy client licensing data (licenses, platform_plans,
 * plan_entitlements, tenant_profiles.plan_id) and LICENSE_COMPAT_MODE=legacy
 * must never grant anything: not the Global License Guard, not a module
 * (ModuleGuard), not white_label. Every test seeds legacy data that grants
 * EVERYTHING (active local license on a local plan entitled to forms,
 * membership, booking and white_label) and checks that the answer is still
 * exactly what the signed remote state alone says.
 *
 * Entry-point probes run in child processes with SLATE_LICENSE_GUARD_LIVE=1,
 * the same real-guard convention as ModuleGuardTest / GlobalLicenseGuardTest.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/license_signing.php';

use Slate\Services\Content\PlatformIdentityPolicy;
use Slate\Services\Licensing\EntitlementService;
use Slate\Services\Licensing\LicenseService;
use Slate\Services\Licensing\PlanService;
use Slate\Services\Licensing\SlateLicenseCacheStore;
use Slate\Services\Tenancy\TenantService;
use Slate\Tenancy\TenantContext;

const P13_DAY = 86400;
const P13_MODULES = ['forms', 'membership', 'booking'];
const P13_ENV_KEYS = ['LICENSE_SERVER_URL', 'LICENSE_SERVER_PUBLIC_KEY', 'LICENSE_PRODUCT', 'LICENSE_KEY', 'LICENSE_COMPAT_MODE', 'LICENSE_COMPAT_UNTIL'];

/** Distinct from the other suites' identities ('7', '4', 'd', 'e' × 32). */
function p13_identity(): string { return str_repeat('3', 32); }

function p13_ensure_identity(): void {
    $row = Database::row('SELECT installation_id FROM installation_identity WHERE singleton_id = 1');
    if ($row === null) {
        Database::insert('installation_identity', ['singleton_id' => 1, 'tenant_id' => current_tenant_id(), 'installation_id' => p13_identity()]);
    } elseif ((string) $row['installation_id'] !== p13_identity()) {
        Database::update('installation_identity', ['installation_id' => p13_identity()], 'singleton_id = 1', []);
    }
}

/**
 * In-process env for EntitlementService / the Guard. $remote: all four
 * remote keys; $compat: LICENSE_COMPAT_MODE value (null = unset), with a
 * future LICENSE_COMPAT_UNTIL. The public key is always set so the signed
 * cache can be verified (the Guard needs only that).
 */
function p13_env(bool $remote, ?string $compat = 'legacy', ?string $until = null): array {
    $old = [];
    foreach (P13_ENV_KEYS as $key) { $old[$key] = $_ENV[$key] ?? null; unset($_ENV[$key]); }
    $_ENV['LICENSE_SERVER_PUBLIC_KEY'] = license_test_public_key();
    if ($remote) {
        $_ENV['LICENSE_SERVER_URL'] = 'https://license.test';
        $_ENV['LICENSE_PRODUCT'] = 'kohevo';
        $_ENV['LICENSE_KEY'] = 'test-key';
    }
    if ($compat !== null) {
        $_ENV['LICENSE_COMPAT_MODE'] = $compat;
        $_ENV['LICENSE_COMPAT_UNTIL'] = $until ?? gmdate('Y-m-d', time() + 30 * P13_DAY);
    }
    return $old;
}

function p13_restore(array $old): void {
    foreach ($old as $key => $value) { if ($value === null) unset($_ENV[$key]); else $_ENV[$key] = $value; }
}

/**
 * Legacy data granting everything to $tenantId: a local plan entitled to
 * every module + white_label, assigned to the tenant, with an active local
 * license. Returns a cleanup closure that restores the tenant's plan_id.
 */
function p13_seed_legacy(int $tenantId): callable {
    $planId = PlanService::save(null, ['name' => 'P13 Legacy All', 'slug' => 'p13-legacy-' . bin2hex(random_bytes(4))],
        [...P13_MODULES, 'white_label']);
    $profile = Database::row('SELECT id, plan_id FROM tenant_profiles WHERE tenant_id = ?', [$tenantId]);
    if ($profile === null) {
        Database::insert('tenant_profiles', ['tenant_id' => $tenantId, 'plan_id' => $planId]);
    } else {
        Database::query('UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?', [$planId, $tenantId]);
    }
    $license = LicenseService::issue($tenantId, $planId, ['status' => 'active']);

    // Non-vacuous: by the pre-Phase-13 legacy rules this data grants everything.
    assert_eq('active', LicenseService::effectiveStatus($tenantId), 'seed: active local license');
    $seededPlan = (int) Database::value('SELECT plan_id FROM tenant_profiles WHERE tenant_id = ?', [$tenantId]);
    assert_eq([], array_diff([...P13_MODULES, 'white_label'], PlanService::entitlementsFor($seededPlan)), 'seed: local plan entitles everything');

    return static function () use ($tenantId, $planId, $profile, $license): void {
        if ($profile === null) {
            Database::query('DELETE FROM tenant_profiles WHERE tenant_id = ?', [$tenantId]);
        } else {
            Database::query('UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?', [$profile['plan_id'], $tenantId]);
        }
        Database::query('DELETE FROM licenses WHERE id = ?', [$license['id']]);
        PlanService::delete($planId);
    };
}

function p13_clear_cache(int $tenantId): void {
    Database::query('DELETE FROM remote_license_cache WHERE tenant_id = ?', [$tenantId]);
}

/** A trusted signed snapshot for this installation. */
function p13_seed_signed(int $tenantId, array $fields): void {
    p13_ensure_identity();
    license_test_seed_cache($tenantId, $fields + [
        'status' => 'active', 'plan' => 'pro', 'entitlements' => [],
        'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'), 'installation_id' => p13_identity(),
    ]);
}

/**
 * The signed/cache states the brief lists, each as [setup, guardLocked,
 * formsUsable]. Every signed state grants only 'forms' remotely.
 */
function p13_states(): array {
    $now = time();
    $signed = static fn(array $f) => static function (int $t) use ($f): void {
        p13_seed_signed($t, $f + ['entitlements' => ['forms']]);
    };
    return [
        'active'                => [$signed(['status' => 'active']), false, true],
        'trial'                 => [$signed(['status' => 'trial']), false, true],
        'suspended'             => [$signed(['status' => 'suspended']), true, false],
        'revoked'               => [$signed(['status' => 'revoked']), true, false],
        'expired within grace'  => [$signed(['status' => 'expired', 'expires_at' => gmdate('Y-m-d H:i:s', $now - 2 * P13_DAY)]), false, true],
        'expired beyond grace'  => [$signed(['status' => 'expired', 'expires_at' => gmdate('Y-m-d H:i:s', $now - 8 * P13_DAY)]), true, false],
        'stale (8d offline)'    => [$signed(['status' => 'active', 'fetched_at' => gmdate('Y-m-d H:i:s', $now - 8 * P13_DAY)]), true, false],
        'missing cache'         => [static function (int $t): void { p13_clear_cache($t); }, true, false],
        'invalid signature'     => [static function (int $t) use ($signed): void {
            $signed(['status' => 'active'])($t);
            Database::query('UPDATE remote_license_cache SET raw_signature = ? WHERE tenant_id = ?', [base64_encode(str_repeat("\0", 64)), $t]);
        }, true, false],
        'tampered entitlements' => [static function (int $t) use ($signed): void {
            $signed(['status' => 'active'])($t);
            Database::query('UPDATE remote_license_cache SET entitlements = ? WHERE tenant_id = ?', [json_encode([...P13_MODULES, 'white_label']), $t]);
        }, true, false],
        'legacy unsigned row'   => [static function (int $t) use ($signed): void {
            // The pre-0026 cache shape: plain columns, no signed envelope.
            $signed(['status' => 'active'])($t);
            Database::query('UPDATE remote_license_cache SET raw_payload = NULL, raw_signature = NULL WHERE tenant_id = ?', [$t]);
        }, true, false],
    ];
}

// ── Authority mode ───────────────────────────────────────────────────────

unit('Phase 13: LICENSE_COMPAT_MODE legacy / missing / malformed / lapsed — none grants anything from fully-granting legacy data', function (): void {
    $tenant = current_tenant_id();
    $cleanup = p13_seed_legacy($tenant);
    try {
        $cases = [
            'legacy'             => ['legacy', null, 'legacy'],
            'LEGACY (case/space)' => [' LEGACY ', null, 'legacy'],
            'missing'            => [null, null, 'unconfigured'],
            'malformed'          => ['legacy;1', null, 'unconfigured'],
            'other value'        => ['true', null, 'unconfigured'],
            'lapsed window'      => ['legacy', gmdate('Y-m-d', time() - P13_DAY), 'unconfigured'],
            'bad until'          => ['legacy', 'not-a-date', 'unconfigured'],
        ];
        foreach ($cases as $label => [$mode, $until, $expectedMode]) {
            $old = p13_env(false, $mode, $until);
            try {
                assert_eq($expectedMode, EntitlementService::authorityMode(), "$label: authority mode");
                foreach ([...P13_MODULES, 'white_label'] as $key) {
                    assert_false(EntitlementService::canAccessCapability($tenant, $key), "$label: $key must not be granted by legacy data");
                }
                assert_eq([], EntitlementService::enabledFeaturesFor($tenant), "$label: no enabled features");
                PlatformIdentityPolicy::resetCacheForTests();
                assert_false(PlatformIdentityPolicy::whiteLabelActive(), "$label: Kohevo identity stays visible");
            } finally {
                p13_restore($old);
            }
        }
    } finally {
        PlatformIdentityPolicy::resetCacheForTests();
        $cleanup();
    }
});

unit('Phase 13: modern mode wins over LICENSE_COMPAT_MODE=legacy, and only the signed entitlements count', function (): void {
    $tenant = current_tenant_id();
    $cleanup = p13_seed_legacy($tenant);
    $old = p13_env(true, 'legacy');
    try {
        p13_seed_signed($tenant, ['entitlements' => ['forms']]);
        assert_eq('remote', EntitlementService::authorityMode());
        assert_true(EntitlementService::canAccessCapability($tenant, 'forms'));
        foreach (['membership', 'booking', 'white_label'] as $key) {
            assert_false(EntitlementService::canAccessCapability($tenant, $key), "$key: granted only by legacy data, so denied");
        }
        assert_eq(['forms'], EntitlementService::enabledFeaturesFor($tenant));
    } finally {
        p13_restore($old);
        p13_clear_cache($tenant);
        $cleanup();
    }
});

// ── License state matrix ─────────────────────────────────────────────────

unit('Phase 13: every license/cache state — the Guard and module access follow the signed state only, in legacy and modern mode alike', function (): void {
    $tenant = current_tenant_id();
    $cleanup = p13_seed_legacy($tenant);
    try {
        foreach (p13_states() as $label => [$setup, $locked, $formsUsable]) {
            foreach (['legacy only' => false, 'modern + legacy flag' => true] as $modeLabel => $remote) {
                $old = p13_env($remote, 'legacy');
                try {
                    $setup($tenant);
                    $guard = slate_license_guard_state();
                    assert_eq($locked, $guard['locked'], "$label / $modeLabel: Guard locked");
                    // Legacy mode never grants; modern mode grants forms only while the signed state is usable.
                    assert_eq($remote && $formsUsable, EntitlementService::canAccessCapability($tenant, 'forms'), "$label / $modeLabel: forms");
                    foreach (['membership', 'booking', 'white_label'] as $key) {
                        assert_false(EntitlementService::canAccessCapability($tenant, $key), "$label / $modeLabel: $key");
                    }
                } finally {
                    p13_restore($old);
                    p13_clear_cache($tenant);
                }
            }
        }
    } finally {
        $cleanup();
    }
});

// ── Existing installations with no new identity / cache ─────────────────

unit('Phase 13: an old installation (legacy data, no installation identity, no cache) is locked, and gets no identity or license as a side effect', function (): void {
    $tenant = current_tenant_id();
    $cleanup = p13_seed_legacy($tenant);
    $identity = Database::row('SELECT * FROM installation_identity WHERE singleton_id = 1');
    $old = p13_env(false, 'legacy');
    try {
        p13_clear_cache($tenant);
        Database::query('DELETE FROM installation_identity WHERE singleton_id = 1');
        $guard = slate_license_guard_state();
        assert_true($guard['locked'], 'no identity + no signed cache = locked');
        assert_eq('missing', $guard['reason']);
        foreach (P13_MODULES as $key) assert_false(EntitlementService::canAccessCapability($tenant, $key));
        assert_null(Database::row('SELECT * FROM installation_identity WHERE singleton_id = 1'), 'no Installation ID is fabricated by reading license state');
        assert_eq(0, (int) Database::value('SELECT COUNT(*) FROM remote_license_cache WHERE tenant_id = ?', [$tenant]), 'no cache row is fabricated');

        // A copied signed row cannot adopt this identity-less install either.
        p13_seed_signed($tenant, ['entitlements' => P13_MODULES]);
        Database::query('DELETE FROM installation_identity WHERE singleton_id = 1');
        $guard = slate_license_guard_state();
        assert_true($guard['locked'], 'a signed row with no local identity to match is untrusted');
    } finally {
        p13_restore($old);
        p13_clear_cache($tenant);
        Database::query('DELETE FROM installation_identity WHERE singleton_id = 1');
        if ($identity !== null) Database::insert('installation_identity', $identity);
        $cleanup();
    }
});

unit('Phase 13: an old unsigned or legacy-shaped check-in can never become or replace trusted modern state', function (): void {
    $tenant = current_tenant_id();
    $old = p13_env(true, 'legacy');
    try {
        p13_seed_signed($tenant, ['entitlements' => ['forms']]);
        $store = new SlateLicenseCacheStore($tenant, license_test_public_key());
        $before = Database::row('SELECT raw_payload, raw_signature FROM remote_license_cache WHERE tenant_id = ?', [$tenant]);

        // Unsigned (the pre-Phase-10 shape): refused outright.
        $threw = false;
        try {
            $store->save(['status' => 'active', 'plan' => 'pro', 'entitlements' => P13_MODULES, 'installation_id' => p13_identity()]);
        } catch (\InvalidArgumentException $e) { $threw = true; }
        assert_true($threw, 'an unsigned state is refused by the cache store');

        // Signed but older than what is cached (e.g. a replayed legacy response): refused.
        $threw = false;
        try {
            $store->save(license_test_signed_state([
                'status' => 'active', 'entitlements' => P13_MODULES, 'installation_id' => p13_identity(),
                'fetched_at' => gmdate('Y-m-d H:i:s', time() - 3600),
            ]));
        } catch (\LicenseCacheStaleException $e) { $threw = true; }
        assert_true($threw, 'an older signed state never replaces a newer one');

        $after = Database::row('SELECT raw_payload, raw_signature FROM remote_license_cache WHERE tenant_id = ?', [$tenant]);
        assert_eq($before, $after, 'the trusted row is untouched');
        assert_false(EntitlementService::canAccessCapability($tenant, 'booking'));
    } finally {
        p13_restore($old);
        p13_clear_cache($tenant);
    }
});

// ── Tenant isolation ─────────────────────────────────────────────────────

unit('Phase 13: tenant isolation — tenant A legacy data or signed state never grants tenant B, and B\'s legacy data grants B nothing', function (): void {
    $a = current_tenant_id();
    $b = TenantService::create(['name' => 'P13 Tenant B', 'slug' => 'p13-b-' . bin2hex(random_bytes(4))]);
    $cleanupA = p13_seed_legacy($a);
    $cleanupB = p13_seed_legacy($b);
    try {
        foreach (['legacy only' => false, 'modern' => true] as $label => $remote) {
            $old = p13_env($remote, 'legacy');
            try {
                p13_seed_signed($a, ['entitlements' => ['forms', 'booking']]);
                assert_eq($remote, EntitlementService::canAccessCapability($a, 'forms'), "$label: A → A forms");
                assert_false(EntitlementService::canAccessCapability($a, 'membership'), "$label: A → A membership (legacy-only grant)");
                foreach ([...P13_MODULES, 'white_label'] as $key) {
                    assert_false(EntitlementService::canAccessCapability($b, $key), "$label: A → B $key");
                }
                assert_false((new TenantContext())->runAs($b, static function () use ($b): bool {
                    PlatformIdentityPolicy::resetCacheForTests();
                    return PlatformIdentityPolicy::whiteLabelActive();
                }), "$label: B white_label");
            } finally {
                p13_restore($old);
                p13_clear_cache($a);
            }
        }
    } finally {
        PlatformIdentityPolicy::resetCacheForTests();
        $cleanupB();
        $cleanupA();
        Database::query('DELETE FROM licenses WHERE tenant_id = ?', [$b]);
        Database::query('DELETE FROM tenant_profiles WHERE tenant_id = ?', [$b]);
        Database::query('DELETE FROM tenants WHERE id = ?', [$b]);
    }
});

// ── Real entry points (child processes, live guards) ─────────────────────

/** Child-process env: live guards, compat legacy, and optionally the full remote configuration. */
function p13_child_env(bool $remote): string {
    $env = 'SLATE_LICENSE_GUARD_LIVE=1 '
         . 'LICENSE_SERVER_PUBLIC_KEY=' . escapeshellarg(license_test_public_key()) . ' '
         . 'LICENSE_COMPAT_MODE=legacy LICENSE_COMPAT_UNTIL=' . escapeshellarg(gmdate('Y-m-d', time() + 30 * P13_DAY)) . ' ';
    if ($remote) {
        $env .= 'LICENSE_SERVER_URL=' . escapeshellarg('https://license.test') . ' '
              . 'LICENSE_PRODUCT=kohevo LICENSE_KEY=' . escapeshellarg('test-key') . ' ';
    }
    return $env;
}

function p13_run(bool $remote, string $fixture, array $args): string {
    $cmd = p13_child_env($remote) . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/' . $fixture);
    foreach ($args as $arg) $cmd .= ' ' . escapeshellarg((string) $arg);
    return (string) shell_exec($cmd . ' 2>/dev/null');
}

function p13_probe(bool $remote, string $fixture, array $args): array {
    $out = p13_run($remote, $fixture, $args);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) ($m[1] ?? 0), 'body' => substr($out, strlen($m[0] ?? ''))];
}

function p13_mcp(bool $remote, string $tool): bool {
    return str_starts_with(p13_run($remote, 'mcp-tool-call-probe.php', [$tool, '{}']), "STATUS OK\n");
}

const P13_ADMIN = ['forms' => 'plugins/forms/admin/index.php', 'membership' => 'plugins/membership/admin/index.php', 'booking' => 'plugins/booking/admin/index.php'];
const P13_PUBLIC = ['forms' => 'plugins/forms/public/router.php', 'membership' => 'plugins/membership/public/landing.php', 'booking' => 'plugins/booking/public/router.php'];
const P13_MCP = ['forms' => 'slate_forms_list_definitions', 'membership' => 'slate_membership_list_plans', 'booking' => 'slate_booking_list_services'];

unit('Phase 13: entry points — legacy data granting every module unlocks none of them (admin GET/POST, public, API, MCP, cron), in legacy and modern mode', function (): void {
    $tenant = current_tenant_id();
    $cleanup = p13_seed_legacy($tenant);
    try {
        p13_seed_signed($tenant, ['entitlements' => []]); // valid license, zero modules: the Guard is open
        foreach (['legacy only' => false, 'modern' => true] as $label => $remote) {
            assert_eq(200, p13_probe($remote, 'admin-page-probe.php', ['admin/index.php'])['status'], "$label: Core dashboard open");
            foreach (P13_MODULES as $m) {
                assert_eq(403, p13_probe($remote, 'admin-page-probe.php', [P13_ADMIN[$m], '', 1, '1'])['status'], "$label: $m admin GET (platform admin)");
                assert_eq(403, p13_probe($remote, 'admin-page-post-probe.php', [P13_ADMIN[$m], '{}', 1, '0'])['status'], "$label: $m admin POST");
                assert_eq(404, p13_probe($remote, 'public-page-probe.php', [P13_PUBLIC[$m], ''])['status'], "$label: $m public");
                assert_false(p13_mcp($remote, P13_MCP[$m]), "$label: $m MCP");
                assert_eq('DENY', trim(p13_run($remote, 'module-guard-cron-probe.php', [$m])), "$label: $m cron listener gate");
            }
            assert_eq(403, p13_probe($remote, 'api-request-probe.php', ['booking/services', 'GET', '', ''])['status'], "$label: booking API");
        }

        // Positive control: the SAME probes open when the signed state grants the module.
        p13_seed_signed($tenant, ['entitlements' => ['booking']]);
        assert_eq(200, p13_probe(true, 'admin-page-probe.php', [P13_ADMIN['booking']])['status'], 'modern: signed booking grant opens booking admin');
        assert_eq('ALLOW', trim(p13_run(true, 'module-guard-cron-probe.php', ['booking'])), 'modern: signed booking grant opens the cron gate');
        assert_eq(403, p13_probe(false, 'admin-page-probe.php', [P13_ADMIN['booking']])['status'], 'legacy only: even a signed grant is not used without the remote configuration');
    } finally {
        p13_clear_cache($tenant);
        $cleanup();
    }
});

unit('Phase 13: entry points while locked — legacy mode and platform admins cannot get past the Guard; recovery pages stay reachable', function (): void {
    $tenant = current_tenant_id();
    $cleanup = p13_seed_legacy($tenant);
    try {
        foreach (['suspended' => ['status' => 'suspended'], 'missing cache' => null] as $state => $fields) {
            if ($fields === null) p13_clear_cache($tenant); else p13_seed_signed($tenant, $fields + ['entitlements' => P13_MODULES]);
            foreach (['legacy only' => false, 'modern' => true] as $label => $remote) {
                $dash = p13_probe($remote, 'admin-page-probe.php', ['admin/index.php', '', 1, '1']);
                assert_eq(403, $dash['status'], "$state / $label: dashboard (platform admin)");
                assert_true(str_contains($dash['body'], 'License inactive'), "$state / $label: the Guard's page, not a module's");
                foreach (['admin/licenses.php', 'admin/plans.php', 'admin/tenants.php'] as $legacyPage) {
                    assert_eq(403, p13_probe($remote, 'admin-page-probe.php', [$legacyPage, '', 1, '1'])['status'], "$state / $label: legacy page $legacyPage");
                }
                $post = p13_probe($remote, 'admin-page-post-probe.php', ['admin/licenses.php', json_encode(['_action' => 'issue', 'tenant_id' => (string) $tenant, 'status' => 'active']), 1, '1']);
                assert_eq(403, $post['status'], "$state / $label: legacy license issue POST");
                assert_eq(403, p13_probe($remote, 'api-request-probe.php', ['booking/services', 'GET', '', ''])['status'], "$state / $label: API");
                assert_eq(403, p13_probe($remote, 'public-page-probe.php', ['public.php', ''])['status'], "$state / $label: public site");
                assert_false(p13_mcp($remote, 'slate_booking_list_services'), "$state / $label: MCP");
                foreach (P13_MODULES as $m) {
                    assert_eq('DENY', trim(p13_run($remote, 'module-guard-cron-probe.php', [$m])), "$state / $label: $m cron gate");
                }
                assert_eq(200, p13_probe($remote, 'admin-page-probe.php', ['admin/license.php', '', 1, '0'])['status'], "$state / $label: License page (recovery) reachable");
            }
        }
    } finally {
        p13_clear_cache($tenant);
        $cleanup();
    }
});

unit('Phase 13: the dashboard never shows legacy local plan/license data as this installation\'s license', function (): void {
    $tenant = current_tenant_id();
    $cleanup = p13_seed_legacy($tenant);
    try {
        p13_seed_signed($tenant, ['entitlements' => ['forms'], 'plan' => 'signed-plan']);
        foreach (['legacy only' => false, 'modern' => true] as $label => $remote) {
            $res = p13_probe($remote, 'admin-page-probe.php', ['admin/index.php', '', 2, '0']);
            assert_eq(200, $res['status'], "$label: dashboard");
            assert_false(str_contains($res['body'], 'Your plan'), "$label: no legacy plan card");
            assert_false(str_contains($res['body'], 'P13 Legacy All'), "$label: the legacy plan name is not presented");
            assert_true(str_contains($res['body'], 'data-license-summary'), "$label: the signed-state summary is shown");
        }
    } finally {
        p13_clear_cache($tenant);
        $cleanup();
    }
});
