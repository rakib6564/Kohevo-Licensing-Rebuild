<?php
/**
 * Kohevo central — assign a platform plan to a tenant from the CLI.
 *
 * EntitlementService reads tenant_profiles.plan_id to decide which gated
 * modules (Forms, Booking, Membership, …) a tenant may use, but no admin
 * screen sets that column yet (admin/licenses.php stores a plan on the
 * licence, which entitlement checks do not read). Until a plan selector
 * exists on the Tenants screen, this is the supported way to give a tenant,
 * including the operator's own tenant, a plan before deploying.
 *
 * Run:  php bin/assign-tenant-plan.php <tenant-id|tenant-slug> <plan-slug|none>
 * e.g.: php bin/assign-tenant-plan.php 1 enterprise
 *       php bin/assign-tenant-plan.php acme none      (remove the plan)
 * Check the result with: php bin/check-tenant-plans.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/assign-tenant-plan.php <tenant> <plan>\n");
    exit(1);
}

require __DIR__ . '/../config.php';

[, $tenantArg, $planArg] = $argv + [null, '', ''];
if ($tenantArg === '' || $planArg === '') {
    fwrite(STDERR, "Usage: php bin/assign-tenant-plan.php <tenant-id|tenant-slug> <plan-slug|none>\n");
    exit(1);
}

// anti-drift-ignore: TENANT — platform operation on an explicitly named tenant
$tenant = ctype_digit($tenantArg)
    ? Database::row('SELECT id, name, slug FROM tenants WHERE id = ?', [(int) $tenantArg])
    : Database::row('SELECT id, name, slug FROM tenants WHERE slug = ?', [$tenantArg]);
if (!$tenant) {
    fwrite(STDERR, "No tenant '{$tenantArg}'.\n");
    exit(1);
}

$planId = null;
if ($planArg !== 'none') {
    $plan = Database::row('SELECT id, slug, name, is_active FROM platform_plans WHERE slug = ?', [$planArg]);
    if (!$plan) {
        $slugs = implode(', ', array_column(Database::rows('SELECT slug FROM platform_plans ORDER BY sort_order, id'), 'slug'));
        fwrite(STDERR, "No plan '{$planArg}'. Plans: {$slugs}\n");
        exit(1);
    }
    if (!(int) $plan['is_active']) {
        fwrite(STDERR, "Plan '{$planArg}' is inactive (retired from new assignments).\n");
        exit(1);
    }
    $planId = (int) $plan['id'];
}

$tid = (int) $tenant['id'];
// anti-drift-ignore: TENANT — tenant_profiles is keyed 1:1 by the named tenant's id
$prior = Database::row('SELECT plan_id FROM tenant_profiles WHERE tenant_id = ?', [$tid]);
if ($prior) {
    // anti-drift-ignore: TENANT — tenant_profiles is keyed 1:1 by the named tenant's id
    Database::query('UPDATE tenant_profiles SET plan_id = ?, updated_at = ? WHERE tenant_id = ?', [$planId, slate_db_now(), $tid]);
} else {
    // anti-drift-ignore: TENANT — creating the named tenant's own 1:1 profile row
    Database::insert('tenant_profiles', ['tenant_id' => $tid, 'plan_id' => $planId]);
}
AuditLog::record('tenant.plan_assigned', "tenant#{$tid}", ['from' => $prior['plan_id'] ?? null, 'to' => $planId, 'via' => 'cli']);
echo "Tenant #{$tid} ({$tenant['name']}): plan " . ($planId === null ? 'removed' : "set to {$planArg}") . ".\n";
exit(0);
