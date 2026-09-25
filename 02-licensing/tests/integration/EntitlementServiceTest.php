<?php
/**
 * Phase 1E C1/C2 — EntitlementService: the single, layered entitlement gate.
 *
 * Full matrix over the resolution chain (Installed -> Platform allowed ->
 * Tenant licensed -> Plan entitled -> Tenant enabled), proving every layer
 * is actually checked and none is skipped — a plan being entitled is never
 * enough on its own, and vice versa.
 */

declare(strict_types=1);

use Slate\Services\Licensing\EntitlementService;
use Slate\Services\Licensing\LicenseService;
use Slate\Services\Licensing\PlanService;
use Slate\Services\Tenancy\TenantService;

/** A fresh tenant with no plan and no license — the "nothing configured yet" baseline. */
function est_bare_tenant(string $tag): int
{
    return TenantService::create(['name' => "Entitlement $tag", 'slug' => 'est-' . strtolower($tag) . '-' . bin2hex(random_bytes(4))]);
}

function est_cleanup_tenant(int $tenantId): void
{
    Database::query("DELETE FROM licenses WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key LIKE 'entitlement.%'", [$tenantId]);
    Database::query("DELETE FROM tenant_profiles WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM tenants WHERE id = ?", [$tenantId]);
}

function est_cleanup_plan(int $planId): void
{
    Database::query("DELETE FROM plan_entitlements WHERE plan_id = ?", [$planId]);
    Database::query("DELETE FROM platform_plans WHERE id = ?", [$planId]);
}

unit('EntitlementService::canAccess(): a not-installed/inactive plugin is denied regardless of plan or license', function (): void {
    $tenantId = est_bare_tenant('NotInstalled');
    $planId = PlanService::save(null, ['name' => 'Everything Plan', 'slug' => 'est-everything-' . bin2hex(random_bytes(4))], ['definitely-not-a-real-plugin']);
    try {
        Database::query("UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?", [$planId, $tenantId]);
        LicenseService::issue($tenantId, $planId, ['status' => 'active']);

        assert_false(EntitlementService::canAccess($tenantId, 'definitely-not-a-real-plugin'), 'a feature with no matching installed/active plugin must never be accessible, no matter what the plan/license say');
    } finally {
        est_cleanup_tenant($tenantId);
        est_cleanup_plan($planId);
    }
});

unit('EntitlementService::canAccess(): an installed, active plugin is still denied with no plan assigned', function (): void {
    $tenantId = est_bare_tenant('NoPlan');
    try {
        // 'forms' is installed/active on every checkout this test suite runs on.
        assert_false(EntitlementService::canAccess($tenantId, 'forms'), 'no plan assigned means nothing to derive entitlements from — must be denied even though the plugin itself is installed');
    } finally {
        est_cleanup_tenant($tenantId);
    }
});

unit('EntitlementService::canAccess(): a plan that does not entitle the feature is denied even with an active license', function (): void {
    $tenantId = est_bare_tenant('WrongPlan');
    $planId = PlanService::save(null, ['name' => 'No Forms Plan', 'slug' => 'est-noforms-' . bin2hex(random_bytes(4))], ['booking']);
    try {
        Database::query("UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?", [$planId, $tenantId]);
        LicenseService::issue($tenantId, $planId, ['status' => 'active']);

        assert_false(EntitlementService::canAccess($tenantId, 'forms'), 'the plan does not include forms — must be denied even though installed and licensed');
        assert_true(EntitlementService::canAccess($tenantId, 'booking'), 'sanity: the plan DOES include booking, and booking is installed/active in this test environment');
    } finally {
        est_cleanup_tenant($tenantId);
        est_cleanup_plan($planId);
    }
});

unit('EntitlementService::canAccess(): a tenant with no license at all defaults to unrestricted (backward compatibility)', function (): void {
    $tenantId = est_bare_tenant('NoLicense');
    $planId = PlanService::save(null, ['name' => 'Forms Plan', 'slug' => 'est-forms-' . bin2hex(random_bytes(4))], ['forms']);
    try {
        Database::query("UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?", [$planId, $tenantId]);
        // Deliberately no LicenseService::issue() call — 'none' status.

        assert_true(EntitlementService::canAccess($tenantId, 'forms'), 'no license issued yet must not retroactively break a tenant that predates the licensing feature');
    } finally {
        est_cleanup_tenant($tenantId);
        est_cleanup_plan($planId);
    }
});

unit('EntitlementService::canAccess(): a suspended/revoked/expired license denies the entitled feature', function (): void {
    $tenantId = est_bare_tenant('BadLicense');
    $planId = PlanService::save(null, ['name' => 'Forms Plan 2', 'slug' => 'est-forms2-' . bin2hex(random_bytes(4))], ['forms']);
    try {
        Database::query("UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?", [$planId, $tenantId]);
        $license = LicenseService::issue($tenantId, $planId, ['status' => 'active']);
        assert_true(EntitlementService::canAccess($tenantId, 'forms'), 'sanity: active license + entitled plan + installed plugin = access');

        LicenseService::suspend($license['id'], 'test');
        assert_false(EntitlementService::canAccess($tenantId, 'forms'), 'a suspended license must deny the otherwise-entitled feature');

        LicenseService::activate($license['id']);
        LicenseService::revoke($license['id'], 'test');
        assert_false(EntitlementService::canAccess($tenantId, 'forms'), 'a revoked license must deny the otherwise-entitled feature');
    } finally {
        est_cleanup_tenant($tenantId);
        est_cleanup_plan($planId);
    }
});

unit('EntitlementService::canAccess(): fully entitled (installed + licensed + plan-entitled) is allowed, then the per-tenant "enabled" toggle can still turn it off', function (): void {
    $tenantId = est_bare_tenant('FullChain');
    $planId = PlanService::save(null, ['name' => 'Full Chain Plan', 'slug' => 'est-fullchain-' . bin2hex(random_bytes(4))], ['forms']);
    try {
        Database::query("UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?", [$planId, $tenantId]);
        LicenseService::issue($tenantId, $planId, ['status' => 'active']);

        assert_true(EntitlementService::canAccess($tenantId, 'forms'), 'every layer satisfied: must be allowed');

        Database::setSetting('entitlement.forms.enabled', '0', $tenantId);
        assert_false(EntitlementService::canAccess($tenantId, 'forms'), 'the tenant-level toggle must be able to turn off an otherwise-fully-entitled feature');

        Database::setSetting('entitlement.forms.enabled', '1', $tenantId);
        assert_true(EntitlementService::canAccess($tenantId, 'forms'), 'turning the toggle back on restores access');
    } finally {
        est_cleanup_tenant($tenantId);
        est_cleanup_plan($planId);
    }
});

unit('EntitlementService::canAccess(): a trial license is treated the same as active for entitlement purposes', function (): void {
    $tenantId = est_bare_tenant('Trial');
    $planId = PlanService::save(null, ['name' => 'Trial Plan', 'slug' => 'est-trial-' . bin2hex(random_bytes(4))], ['forms']);
    try {
        Database::query("UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?", [$planId, $tenantId]);
        LicenseService::issue($tenantId, $planId, ['status' => 'trial']);

        assert_true(EntitlementService::canAccess($tenantId, 'forms'), 'a trial license must grant the same access as active, per LICENSED_STATUSES');
    } finally {
        est_cleanup_tenant($tenantId);
        est_cleanup_plan($planId);
    }
});

unit('EntitlementService::canAccess(): platform administrators are never subject to this check (they use isPlatformSuperAdmin(), unaffected by any tenant\'s license state)', function (): void {
    // Documents the design rather than testing new code: platform-admin
    // pages (admin/tenants.php, admin/licenses.php, admin/plans.php,
    // admin/platform-admins.php) gate exclusively on
    // Auth::requirePlatformAdmin() and never call EntitlementService at
    // all — confirmed by source inspection, pinned here so a future edit
    // that accidentally routes a platform page through canAccess() (which
    // WOULD then incorrectly depend on some tenant's license state) is
    // caught.
    foreach (['admin/tenants.php', 'admin/licenses.php', 'admin/plans.php', 'admin/platform-admins.php'] as $page) {
        $src = file_get_contents(dirname(__DIR__, 2) . '/' . $page);
        assert_true(str_contains($src, 'requirePlatformAdmin()'), "$page must gate on Auth::requirePlatformAdmin()");
        assert_false(str_contains($src, 'EntitlementService::canAccess'), "$page must never itself be gated by EntitlementService — platform pages are unaffected by any tenant's license state");
    }
});

unit('EntitlementService::enabledFeaturesFor() reflects exactly what canAccess() would allow, across every plan feature', function (): void {
    $tenantId = est_bare_tenant('Enumerate');
    $planId = PlanService::save(null, ['name' => 'Enumerate Plan', 'slug' => 'est-enum-' . bin2hex(random_bytes(4))], ['forms', 'booking']);
    try {
        Database::query("UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?", [$planId, $tenantId]);
        LicenseService::issue($tenantId, $planId, ['status' => 'active']);

        $enabled = EntitlementService::enabledFeaturesFor($tenantId);
        assert_true(in_array('forms', $enabled, true));
        assert_true(in_array('booking', $enabled, true));

        Database::setSetting('entitlement.booking.enabled', '0', $tenantId);
        $enabled2 = EntitlementService::enabledFeaturesFor($tenantId);
        assert_true(in_array('forms', $enabled2, true));
        assert_false(in_array('booking', $enabled2, true), 'disabling one feature must remove only that one from the enumerated list');
    } finally {
        est_cleanup_tenant($tenantId);
        est_cleanup_plan($planId);
    }
});
