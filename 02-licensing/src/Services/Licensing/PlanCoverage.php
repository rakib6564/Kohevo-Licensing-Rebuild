<?php
/**
 * Which tenants would be allowed or denied each plan-gated module, and why.
 *
 * Shared by the Tenants screen (a warning before anyone loses access) and
 * bin/check-tenant-plans.php (the deploy preflight). It asks
 * EntitlementService::canAccess() for the answer, so it is exactly what
 * ModuleGuard enforces; the only write is the licence last_validated_at
 * stamp every entitlement check makes.
 */

declare(strict_types=1);

namespace Slate\Services\Licensing;

final class PlanCoverage
{
    /** The modules ModuleGuard gates in this tree. */
    public const GATED_MODULES = ['forms', 'booking', 'membership'];

    /** The gated modules that are actually active on this install. */
    public static function activeModules(): array
    {
        return array_values(array_filter(self::GATED_MODULES, static fn(string $m): bool => \PluginLoader::isActive($m)));
    }

    /**
     * One row per tenant: id, name, status, plan label, licence status and
     * per-module ['allowed' => bool, 'reason' => ?string].
     */
    public static function report(): array
    {
        $modules = self::activeModules();
        // anti-drift-ignore: TENANT — platform-wide report over every tenant, by design
        $tenants = \Database::rows(
            "SELECT t.id, t.name, t.status, p.plan_id, pp.slug AS plan_slug
               FROM tenants t
          LEFT JOIN tenant_profiles p ON p.tenant_id = t.id
          LEFT JOIN platform_plans pp ON pp.id = p.plan_id
           ORDER BY t.id"
        );
        $out = [];
        foreach ($tenants as $t) {
            $tid = (int) $t['id'];
            $plan = $t['plan_id'] === null ? 'no plan' : ($t['plan_slug'] !== null ? "plan {$t['plan_slug']}" : "plan_id {$t['plan_id']} (no such plan)");
            $features = $t['plan_slug'] !== null ? PlanService::entitlementsFor((int) $t['plan_id']) : [];
            $licence = LicenseService::effectiveStatus($tid);
            $cells = [];
            foreach ($modules as $m) {
                if (EntitlementService::canAccess($tid, $m)) {
                    $cells[$m] = ['allowed' => true, 'reason' => null];
                    continue;
                }
                $why = !in_array($licence, ['trial', 'active', 'none'], true) ? "licence {$licence}"
                     : ($t['plan_slug'] === null ? $plan
                     : (!in_array($m, $features, true) ? 'not in plan' : 'tenant toggle off'));
                $cells[$m] = ['allowed' => false, 'reason' => $why];
            }
            $out[] = ['id' => $tid, 'name' => (string) $t['name'], 'status' => (string) $t['status'], 'plan' => $plan, 'licence' => $licence, 'modules' => $cells];
        }
        return $out;
    }

    /** Active tenants that would be denied at least one active gated module. */
    public static function gaps(): array
    {
        return array_values(array_filter(self::report(), static function (array $r): bool {
            if ($r['status'] !== 'active') return false;
            foreach ($r['modules'] as $c) if (!$c['allowed']) return true;
            return false;
        }));
    }
}
