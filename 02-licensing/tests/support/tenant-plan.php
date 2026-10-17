<?php
/**
 * Test support: give a tenant a real platform plan, then put back exactly
 * what was there before.
 *
 * In the central tree, Forms, Booking and Membership entry points are gated
 * by ModuleGuard -> EntitlementService::canAccess(): the plugin must be
 * active, the tenant licence status trial/active/none, the tenant's
 * tenant_profiles.plan_id must include the module, and the tenant toggle
 * must not be off. Tests that exercise those modules through real child
 * processes therefore need a tenant that is actually entitled, the same as
 * a real customer. This assigns one through the normal tables; it adds no
 * bypass to production code and does not define SLATE_TESTING in probes.
 */

declare(strict_types=1);

if (!function_exists('slate_test_set_tenant_plan')) {
    /**
     * Assign the plan with this slug (null = no plan) to the tenant and
     * return a restore callable that puts the previous tenant_profiles state
     * back: the previous plan_id, or no row at all if there was none.
     */
    function slate_test_set_tenant_plan(?string $slug, ?int $tenantId = null): callable
    {
        $tid = $tenantId ?? current_tenant_id();
        $prior = Database::row('SELECT plan_id FROM tenant_profiles WHERE tenant_id = ?', [$tid]);
        $planId = null;
        if ($slug !== null) {
            $planId = Database::value('SELECT id FROM platform_plans WHERE slug = ?', [$slug]);
            if (!$planId) {
                throw new RuntimeException("test setup: platform plan '{$slug}' is not seeded");
            }
        }
        if ($prior) {
            Database::query('UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?', [$planId, $tid]);
        } else {
            Database::query('INSERT INTO tenant_profiles (tenant_id, plan_id) VALUES (?, ?)', [$tid, $planId]);
        }
        return static function () use ($tid, $prior): void {
            if ($prior) {
                Database::query('UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?', [$prior['plan_id'], $tid]);
            } else {
                Database::query('DELETE FROM tenant_profiles WHERE tenant_id = ?', [$tid]);
            }
        };
    }

    /**
     * For a whole test file: entitle the current tenant to every module once
     * per process and restore at shutdown. Safe to call from several files
     * in one process (the single-process runner): only the first call
     * assigns and registers the restore.
     */
    function slate_test_entitle_current_tenant(string $slug = 'enterprise'): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        register_shutdown_function(slate_test_set_tenant_plan($slug));
    }
}
