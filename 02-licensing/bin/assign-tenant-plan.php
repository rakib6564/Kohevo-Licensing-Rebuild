<?php
/**
 * Kohevo central — assign a platform plan to a tenant from the CLI.
 *
 * EntitlementService reads tenant_profiles.plan_id to decide which gated
 * modules (Forms, Booking, Membership, …) a tenant may use. The Tenants
 * screen (Edit > Plan) sets it; this CLI does the same through the same
 * TenantService::assignPlan(), for scripted deploys and recovery when no
 * one can reach the admin UI (e.g. the operator tenant itself).
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
    $plan = Database::row('SELECT id FROM platform_plans WHERE slug = ?', [$planArg]);
    if (!$plan) {
        $slugs = implode(', ', array_column(Database::rows('SELECT slug FROM platform_plans ORDER BY sort_order, id'), 'slug'));
        fwrite(STDERR, "No plan '{$planArg}'. Plans: {$slugs}\n");
        exit(1);
    }
    $planId = (int) $plan['id'];
}

try {
    $changed = Slate\Services\Tenancy\TenantService::assignPlan((int) $tenant['id'], $planId, 'cli');
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo "Tenant #{$tenant['id']} ({$tenant['name']}): " . (!$changed ? 'unchanged' : ($planId === null ? 'plan removed' : "plan set to {$planArg}")) . ".\n";
exit(0);
