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
 * the Tenants screen's Plan selector or bin/assign-tenant-plan.php). It changes no settings, plans or profiles; the only write is the
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

use Slate\Services\Licensing\PlanCoverage;

$modules = PlanCoverage::activeModules();
if (!$modules) {
    echo "No gated module (forms, booking, membership) is active. Nothing to check.\n";
    exit(0);
}

$denied = 0;
echo 'Gated modules active: ' . implode(', ', $modules) . "\n\n";
foreach (PlanCoverage::report() as $r) {
    $cells = [];
    foreach ($r['modules'] as $m => $c) {
        $cells[] = $c['allowed'] ? "{$m}=allowed" : "{$m}=DENIED ({$c['reason']})";
        if (!$c['allowed'] && $r['status'] === 'active') $denied++;
    }
    printf("#%d %s [%s, %s, licence %s]\n    %s\n", $r['id'], $r['name'], $r['status'], $r['plan'], $r['licence'], implode('  ', $cells));
}

echo "\n";
if ($denied) {
    echo "{$denied} module(s) would be denied to active tenants. Assign a plan before deploying: Platform > Tenants > Edit > Plan, or php bin/assign-tenant-plan.php <tenant> <plan>\n";
    exit(2);
}
echo "Every active tenant is entitled to every active gated module.\n";
exit(0);
