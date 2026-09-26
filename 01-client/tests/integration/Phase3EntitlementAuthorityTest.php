<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/support/license_signing.php';

use Slate\Services\Content\PlatformIdentityPolicy;
use Slate\Services\Licensing\EntitlementService;
use Slate\Services\Licensing\LicenseService;
use Slate\Services\Licensing\PlanService;
use Slate\Services\Licensing\SlateLicenseCacheStore;
use Slate\Tenancy\TenantContext;
use Slate\Services\Tenancy\TenantService;

function p3_set_remote_env(bool $enabled): array {
    $keys = ['LICENSE_SERVER_URL','LICENSE_SERVER_PUBLIC_KEY','LICENSE_PRODUCT','LICENSE_KEY','LICENSE_COMPAT_MODE','LICENSE_COMPAT_UNTIL'];
    $old = [];
    foreach ($keys as $key) { $old[$key] = $_ENV[$key] ?? null; }
    if ($enabled) {
        $_ENV['LICENSE_SERVER_URL'] = 'https://license.test';
        $_ENV['LICENSE_SERVER_PUBLIC_KEY'] = license_test_public_key();
        $_ENV['LICENSE_PRODUCT'] = 'kohevo';
        $_ENV['LICENSE_KEY'] = 'test-key';
    } else {
        foreach (['LICENSE_SERVER_URL','LICENSE_SERVER_PUBLIC_KEY','LICENSE_PRODUCT','LICENSE_KEY'] as $key) unset($_ENV[$key]);
    }
    return $old;
}
function p3_restore_env(array $old): void {
    foreach ($old as $key => $value) { if ($value === null) unset($_ENV[$key]); else $_ENV[$key] = $value; }
}

/**
 * QA Fix Round 1 (Phase 4, Fix 4): SlateLicenseCacheStore::load() no longer
 * trusts a cache row whose installation_id doesn't match this install's own
 * local installation_identity -- this test exercises EntitlementService
 * against a synthetic, throwaway tenant (not the real single-tenant
 * install), but the identity check itself is global/singleton, not
 * tenant-scoped, so it still needs a matching local identity row to read
 * any of these save() calls back as trusted.
 */
function p3_local_identity(): string { return str_repeat('e', 32); }

function p3_ensure_local_identity(): void {
    $row = Database::row('SELECT installation_id FROM installation_identity WHERE singleton_id = 1');
    if ($row === null) {
        Database::insert('installation_identity', [
            'singleton_id' => 1, 'tenant_id' => 1, 'installation_id' => p3_local_identity(),
        ]);
    } elseif ((string) $row['installation_id'] !== p3_local_identity()) {
        Database::update('installation_identity', ['installation_id' => p3_local_identity()], 'singleton_id = 1', []);
    }
}

unit('Phase 3: verified remote state wins over conflicting local license/plan and controls white-label identity', function (): void {
    $tenantId = TenantService::create(['name'=>'Phase 3 Remote Authority', 'slug'=>'phase3-remote-'.bin2hex(random_bytes(4))]);
    $planId = PlanService::save(null, ['name'=>'Phase 3 Local Premium', 'slug'=>'phase3-premium-'.bin2hex(random_bytes(4))], ['white_label']);
    $old = p3_set_remote_env(true);
    try {
        p3_ensure_local_identity();
        Database::query('UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?', [$planId, $tenantId]);
        LicenseService::issue($tenantId, $planId, ['status'=>'active']);
        license_test_seed_cache($tenantId, ['status'=>'suspended','plan'=>null,'entitlements'=>[],'expires_at'=>null,'fetched_at'=>gmdate('Y-m-d H:i:s'),'installation_id'=>p3_local_identity()]);
        assert_false(EntitlementService::canAccessCapability($tenantId, 'white_label'), 'remote suspended state must defeat active local state');
        PlatformIdentityPolicy::resetCacheForTests();
        assert_false((new TenantContext())->runAs($tenantId, fn() => PlatformIdentityPolicy::whiteLabelActive()), 'Kohevo identity must remain visible without remote entitlement');

        license_test_seed_cache($tenantId, ['status'=>'active','plan'=>'pro','entitlements'=>['white_label'],'expires_at'=>null,'fetched_at'=>gmdate('Y-m-d H:i:s'),'installation_id'=>p3_local_identity()]);
        assert_true(EntitlementService::canAccessCapability($tenantId, 'white_label'));
        PlatformIdentityPolicy::resetCacheForTests();
        assert_true((new TenantContext())->runAs($tenantId, fn() => PlatformIdentityPolicy::whiteLabelActive()));

        license_test_seed_cache($tenantId, ['status'=>'active','plan'=>'pro','entitlements'=>['white_label'],'expires_at'=>null,'fetched_at'=>gmdate('Y-m-d H:i:s', time() - 8 * 86400),'installation_id'=>p3_local_identity()]);
        assert_false(EntitlementService::canAccessCapability($tenantId, 'white_label'), 'stale remote state must follow the existing seven-day grace policy');

        Database::query('DELETE FROM remote_license_cache WHERE tenant_id = ?', [$tenantId]);
        assert_false(EntitlementService::canAccessCapability($tenantId, 'white_label'), 'remote mode without verified cache must not fall back locally');
    } finally {
        p3_restore_env($old);
        Database::query('DELETE FROM remote_license_cache WHERE tenant_id = ?', [$tenantId]);
        Database::query('DELETE FROM licenses WHERE tenant_id = ?', [$tenantId]);
        Database::query('DELETE FROM tenant_profiles WHERE tenant_id = ?', [$tenantId]);
        Database::query('DELETE FROM tenants WHERE id = ?', [$tenantId]);
        PlanService::delete($planId);
    }
});

// Phase 13 (PHASE-13-LEGACY-HANDLING.md): LICENSE_COMPAT_MODE=legacy is
// still recognised and time-bounded, but it no longer grants anything from
// the local licenses/plans tables — before Phase 13 this asserted the
// white_label grant below was true.
unit('Phase 3/13: legacy compatibility is explicit, time-bounded, and never grants from local tables', function (): void {
    $tenantId = TenantService::create(['name'=>'Phase 3 Legacy Compatibility', 'slug'=>'phase3-legacy-'.bin2hex(random_bytes(4))]);
    $planId = PlanService::save(null, ['name'=>'Phase 3 Legacy Plan', 'slug'=>'phase3-legacy-plan-'.bin2hex(random_bytes(4))], ['white_label']);
    $old = p3_set_remote_env(false);
    try {
        Database::query('UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?', [$planId, $tenantId]);
        LicenseService::issue($tenantId, $planId, ['status'=>'active']);
        $_ENV['LICENSE_COMPAT_MODE'] = 'legacy';
        $_ENV['LICENSE_COMPAT_UNTIL'] = gmdate('Y-m-d', time() + 86400);
        assert_eq('legacy', EntitlementService::authorityMode());
        assert_false(EntitlementService::canAccessCapability($tenantId, 'white_label'), 'an active local license on an entitled local plan must not grant in legacy mode');
        assert_eq([], EntitlementService::enabledFeaturesFor($tenantId));
        $_ENV['LICENSE_COMPAT_UNTIL'] = gmdate('Y-m-d', time() - 86400);
        assert_eq('unconfigured', EntitlementService::authorityMode());
        assert_false(EntitlementService::canAccessCapability($tenantId, 'white_label'));
    } finally {
        p3_restore_env($old);
        Database::query('DELETE FROM licenses WHERE tenant_id = ?', [$tenantId]);
        Database::query('DELETE FROM tenant_profiles WHERE tenant_id = ?', [$tenantId]);
        Database::query('DELETE FROM tenants WHERE id = ?', [$tenantId]);
        PlanService::delete($planId);
    }
});
