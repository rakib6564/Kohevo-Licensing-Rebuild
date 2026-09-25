<?php
/**
 * Phase 1E C1 — PlanService (platform_plans + plan_entitlements, migration 0015).
 *
 * Distinct from plugins/membership's membership_plans: this is what the
 * PLATFORM sells to TENANTS, not what a tenant sells to its own customers.
 */

declare(strict_types=1);

use Slate\Services\Licensing\PlanService;

function pst_cleanup(int $planId): void
{
    Database::query("DELETE FROM plan_entitlements WHERE plan_id = ?", [$planId]);
    Database::query("DELETE FROM platform_plans WHERE id = ?", [$planId]);
}

unit('PlanService::list() returns the 4 seeded plans (Free/Starter/Professional/Enterprise) ordered by sort_order', function (): void {
    $plans = PlanService::list();
    $slugs = array_column($plans, 'slug');
    assert_true(in_array('free', $slugs, true) && in_array('enterprise', $slugs, true), 'the seeded plans must exist: ' . implode(',', $slugs));

    $sorted = $plans;
    usort($sorted, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);
    assert_eq(array_column($sorted, 'id'), array_column($plans, 'id'), 'list() must already be sorted by sort_order');
});

unit('PlanService::entitlementsFor() returns exactly the seeded features for the Free plan, and only known plugin slugs platform-wide', function (): void {
    $free = Database::row("SELECT id FROM platform_plans WHERE slug = 'free'");
    assert_eq(['forms'], PlanService::entitlementsFor((int) $free['id']));

    // Checked against the fixed set of slugs the seed migration was written
    // against (production has all 7 active; a given dev/test checkout may
    // have fewer plugins installed at all — that's an environment
    // difference, not a defect: EntitlementService::canAccess()'s own first
    // check, PluginLoader::isActive(), already makes an entitlement row for
    // a not-currently-installed plugin harmlessly inert).
    //
    // 'white_label' (0021_white_label_entitlement.php, Kohevo Brand
    // Persistence System — Phase 6) is the one deliberate exception: the
    // platform's first non-plugin, non-PluginLoader-backed capability key,
    // checked through EntitlementService::canAccessCapability() instead of
    // canAccess() specifically because it is NOT a plugin slug — see that
    // method's own docblock. This test's premise (every feature_key is a
    // plugin slug) predates that and is intentionally updated here, the
    // same way tests/integration/PlatformSignatureAuthShellTest.php's Phase
    // 2 footer-touch assertion was updated once Phase 3 legitimately
    // changed its premise.
    $knownSlugs = ['forms', 'booking', 'coaching', 'membership', 'media-library', 'multilang-translate', 'stripe-payment', 'white_label'];
    $allFeatureKeys = Database::rows("SELECT DISTINCT feature_key FROM plan_entitlements");
    foreach ($allFeatureKeys as $row) {
        assert_true(in_array($row['feature_key'], $knownSlugs, true), "feature_key '{$row['feature_key']}' must correspond to a known plugin slug or the one documented non-plugin capability key (white_label)");
    }
});

unit('PlanService::save() creates a plan with entitlements, rejects a duplicate slug, and records audit entries', function (): void {
    $slug = 'probe-plan-' . bin2hex(random_bytes(4));
    $planId = PlanService::save(null, [
        'name' => 'Probe Plan', 'slug' => $slug, 'description' => 'test',
        'is_active' => true, 'sort_order' => 99, 'limits' => ['max_users' => 10, 'max_customers' => ''],
    ], ['forms', 'booking']);
    try {
        assert_true($planId > 0);
        $plan = PlanService::find($planId);
        assert_eq('Probe Plan', $plan['name']);
        assert_eq(1, (int) $plan['is_active']);
        $limits = json_decode((string) $plan['limits'], true);
        assert_eq(10, $limits['max_users']);
        assert_null($limits['max_customers'], 'a blank limit must be stored as null (unlimited), not an empty string');

        assert_eq(['forms', 'booking'], PlanService::entitlementsFor($planId));

        assert_throws(\InvalidArgumentException::class, fn() => PlanService::save(null, ['name' => 'Dup', 'slug' => $slug], []));

        $created = Database::row("SELECT 1 FROM audit_log WHERE action = 'plan.created' AND target = ?", ["plan#$planId"]);
        assert_true($created !== null);
    } finally {
        pst_cleanup($planId);
    }
});

unit('PlanService::save() on an existing plan replaces its entitlement set rather than appending to it', function (): void {
    $slug = 'probe-plan-update-' . bin2hex(random_bytes(4));
    $planId = PlanService::save(null, ['name' => 'Update Probe', 'slug' => $slug], ['forms']);
    try {
        PlanService::save($planId, ['name' => 'Update Probe', 'slug' => $slug], ['booking', 'coaching']);
        assert_eq(['booking', 'coaching'], PlanService::entitlementsFor($planId), 'the old entitlement (forms) must be gone, replaced entirely by the new set');
    } finally {
        pst_cleanup($planId);
    }
});

unit('PlanService::save() rejects an invalid slug and an empty name', function (): void {
    assert_throws(\InvalidArgumentException::class, fn() => PlanService::save(null, ['name' => 'Bad', 'slug' => 'Not Valid!'], []));
    assert_throws(\InvalidArgumentException::class, fn() => PlanService::save(null, ['name' => '', 'slug' => 'no-name'], []));
});

unit('PlanService::delete() refuses to delete a plan still assigned to a tenant, and succeeds once unassigned', function (): void {
    $slug = 'probe-plan-delete-' . bin2hex(random_bytes(4));
    $planId = PlanService::save(null, ['name' => 'Delete Probe', 'slug' => $slug], []);
    $tenantSlug = 'probe-plan-tenant-' . bin2hex(random_bytes(4));
    $tenantId = \Slate\Services\Tenancy\TenantService::create(['name' => 'Plan Delete Tenant', 'slug' => $tenantSlug]);
    Database::query("UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?", [$planId, $tenantId]);
    try {
        assert_eq(1, PlanService::tenantCount($planId));
        assert_throws(\InvalidArgumentException::class, fn() => PlanService::delete($planId));

        Database::query("UPDATE tenant_profiles SET plan_id = NULL WHERE tenant_id = ?", [$tenantId]);
        PlanService::delete($planId);
        assert_null(PlanService::find($planId), 'the plan must be gone once no tenant references it');
    } finally {
        Database::query("DELETE FROM tenant_profiles WHERE tenant_id = ?", [$tenantId]);
        Database::query("DELETE FROM tenants WHERE id = ?", [$tenantId]);
        pst_cleanup($planId);
    }
});

unit('admin/plans.php: an ordinary tenant admin is refused (403) — plan management is platform-only', function (): void {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg('admin/plans.php') . ' ' . escapeshellarg('') . ' '
         . escapeshellarg('5301') . ' ' . escapeshellarg('0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    assert_eq(403, (int) $m[1]);
});
