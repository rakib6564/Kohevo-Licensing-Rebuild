<?php
/**
 * Kohevo central — read-only deploy preflight for module entitlements.
 *
 * In this tree the Forms, Booking and Membership plugins are gated per
 * tenant by ModuleGuard -> EntitlementService::canAccess(): plugin active,
 * tenant licence status trial/active/none, a plan in tenant_profiles that
 * includes the module, and the tenant toggle not switched off. A tenant
 * with no plan (including the operator's own tenant) gets 403 on those
 * admin pages and 404 on their public routes.
 *
 * This lists, for every tenant, which of those modules it would be allowed
 * or denied, and why, so plans can be assigned before a deploy (with
 * bin/assign-tenant-plan.php: no admin screen sets tenant_profiles.plan_id
 * yet). It changes no settings, plans or profiles; the only write is the
 * licences' last_validated_at stamp that every entitlement check makes.
 *
 * Run:  php bin/check-tenant-plans.php
 * Exit: 0 when every active tenant is entitled to every active gated module,
 *       2 when at least one would be denied (the list says which and why).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/check-tenant-plans.php\n");
    exit(1);
}

require __DIR__ . '/../config.php';

use Slate\Services\Licensing\EntitlementService;
use Slate\Services\Licensing\LicenseService;
use Slate\Services\Licensing\PlanService;

$modules = array_values(array_filter(['forms', 'booking', 'membership'], static fn(string $m): bool => PluginLoader::isActive($m)));
if (!$modules) {
    echo "No gated module (forms, booking, membership) is active. Nothing to check.\n";
    exit(0);
}

// anti-drift-ignore: TENANT — platform-wide preflight over every tenant, by design
$tenants = Database::rows(
    "SELECT t.id, t.name, t.status, p.plan_id, pp.slug AS plan_slug
       FROM tenants t
  LEFT JOIN tenant_profiles p ON p.tenant_id = t.id
  LEFT JOIN platform_plans pp ON pp.id = p.plan_id
   ORDER BY t.id"
);

$denied = 0;
echo 'Gated modules active: ' . implode(', ', $modules) . "\n\n";
foreach ($tenants as $t) {
    $tid = (int) $t['id'];
    $planLabel = $t['plan_id'] === null ? 'no plan' : ($t['plan_slug'] !== null ? "plan {$t['plan_slug']}" : "plan_id {$t['plan_id']} (no such plan)");
    $features = $t['plan_slug'] !== null ? PlanService::entitlementsFor((int) $t['plan_id']) : [];
    $licence = LicenseService::effectiveStatus($tid);
    $cells = [];
    foreach ($modules as $m) {
        if (EntitlementService::canAccess($tid, $m)) {
            $cells[] = "{$m}=allowed";
            continue;
        }
        $why = !in_array($licence, ['trial', 'active', 'none'], true) ? "licence {$licence}"
             : ($t['plan_slug'] === null ? $planLabel
             : (!in_array($m, $features, true) ? 'not in plan'
             : 'tenant toggle off'));
        $cells[] = "{$m}=DENIED ({$why})";
        if ($t['status'] === 'active') $denied++;
    }
    printf("#%d %s [%s, %s, licence %s]\n    %s\n", $tid, $t['name'], $t['status'], $planLabel, $licence, implode('  ', $cells));
}

echo "\n";
if ($denied) {
    echo "{$denied} module(s) would be denied to active tenants. Assign a plan before deploying: php bin/assign-tenant-plan.php <tenant> <plan>\n";
    exit(2);
}
echo "Every active tenant is entitled to every active gated module.\n";
exit(0);
