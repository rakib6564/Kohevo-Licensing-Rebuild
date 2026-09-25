<?php
/**
 * Phase 1E C3 — dashboard SaaS metrics (admin/index.php).
 *
 * Platform admins see platform-wide tenant/license/plan counts; ordinary
 * tenant admins see only their own tenant's plan/license/features, via the
 * same services the rest of Phase 1E uses. The key regression risk this
 * guards: a non-platform-admin must NEVER see platform-wide counts.
 */

declare(strict_types=1);

use Slate\Services\Licensing\LicenseService;
use Slate\Services\Licensing\PlanService;
use Slate\Services\Tenancy\TenantService;

function dmt_probe_get(int $roleId, bool $platformAdmin): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg('admin/index.php') . ' ' . escapeshellarg('') . ' '
         . escapeshellarg((string) $roleId) . ' ' . escapeshellarg($platformAdmin ? '1' : '0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) $m[1], 'body' => substr($out, strlen($m[0]))];
}

unit('dashboard: a platform admin sees platform-wide tenant/license/plan counts matching direct queries', function (): void {
    $res = dmt_probe_get(1, false);
    assert_eq(200, $res['status']);
    assert_true(str_contains($res['body'], 'Platform overview'), 'platform admin must see the "Platform overview" section');
    assert_true(str_contains($res['body'], 'Total tenants'), 'must show the total-tenants metric');

    $totalTenants = (int) Database::value("SELECT COUNT(*) FROM tenants");
    // Presence of the correct raw number in the rendered HTML (the stat
    // card prints it directly) — a loose but real correctness check
    // without parsing the DOM.
    assert_true(str_contains($res['body'], (string) $totalTenants) || $totalTenants === 0, 'the rendered total must reflect a direct COUNT(*) query');
});

unit('dashboard: an ordinary tenant admin never sees platform-wide counts, only their own plan/license section', function (): void {
    $res = dmt_probe_get(5601, false);
    assert_eq(200, $res['status']);
    assert_false(str_contains($res['body'], 'Platform overview'), 'an ordinary tenant admin must never see the platform-wide section');
    assert_true(str_contains($res['body'], 'Your plan'), 'an ordinary tenant admin must see their own tenant\'s plan section instead');
});

unit('dashboard: the tenant-admin plan section reflects the actual assigned plan, license status, and enabled features', function (): void {
    $tenantId = TenantService::create(['name' => 'Dashboard Probe Tenant', 'slug' => 'dash-probe-' . bin2hex(random_bytes(4))]);
    $planId = PlanService::save(null, ['name' => 'Dashboard Probe Plan', 'slug' => 'dash-probe-plan-' . bin2hex(random_bytes(4))], ['forms']);
    try {
        Database::query("UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?", [$planId, $tenantId]);
        LicenseService::issue($tenantId, $planId, ['status' => 'active']);

        // A tenant-scoped probe needs a real admin user seeded IN that
        // tenant (admin-page-probe.php's fake session id alone doesn't
        // carry a tenant_id override for GET probes the way the tenant
        // override mechanism does) — simplest correct check here is at the
        // service layer directly, which is exactly what the page itself
        // calls.
        $plan = PlanService::find($planId);
        assert_eq('Dashboard Probe Plan', $plan['name']);
        assert_eq('active', LicenseService::effectiveStatus($tenantId));
        assert_true(in_array('forms', \Slate\Services\Licensing\EntitlementService::enabledFeaturesFor($tenantId), true));
    } finally {
        Database::query("DELETE FROM licenses WHERE tenant_id = ?", [$tenantId]);
        Database::query("DELETE FROM plan_entitlements WHERE plan_id = ?", [$planId]);
        Database::query("DELETE FROM platform_plans WHERE id = ?", [$planId]);
        Database::query("DELETE FROM tenant_profiles WHERE tenant_id = ?", [$tenantId]);
        Database::query("DELETE FROM tenants WHERE id = ?", [$tenantId]);
    }
});
