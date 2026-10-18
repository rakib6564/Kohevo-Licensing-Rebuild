<?php
/**
 * bin/assign-tenant-plan.php and bin/check-tenant-plans.php, run as real
 * CLIs. No admin screen sets tenant_profiles.plan_id yet, so these are how
 * an operator gives a tenant (their own included) a plan before deploying
 * the central ModuleGuard, and checks nobody is about to lose a module.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/tenant-plan.php';

function ctpc_run(string $script, array $args = []): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/' . $script);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
    exec($cmd . ' 2>&1', $lines, $code);
    return [$code, implode("\n", $lines)];
}

unit('tenant plan CLIs: assigning a plan entitles the tenant and the preflight reports it; removing it is reported as denied', function (): void {
    $tid = current_tenant_id();
    $restore = slate_test_set_tenant_plan(null);
    try {
        [$code, $out] = ctpc_run('check-tenant-plans.php');
        assert_eq(2, $code, 'no plan: the preflight fails: ' . $out);
        assert_true(str_contains($out, "#{$tid} ") && str_contains($out, 'forms=DENIED (no plan)'), $out);

        [$code, $out] = ctpc_run('assign-tenant-plan.php', [(string) $tid, 'enterprise']);
        assert_eq(0, $code, $out);
        assert_eq((string) Database::value("SELECT id FROM platform_plans WHERE slug='enterprise'"),
                  (string) Database::value('SELECT plan_id FROM tenant_profiles WHERE tenant_id = ?', [$tid]));
        assert_true(ModuleGuard::isEntitled('booking'), 'the tenant is now entitled');
        assert_true((int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'tenant.plan_assigned' AND target = ?", ["tenant#{$tid}"]) >= 1, 'the change is audit-logged');

        [$code, $out] = ctpc_run('check-tenant-plans.php');
        assert_true(str_contains($out, "#{$tid} ") && str_contains($out, 'forms=allowed') && str_contains($out, 'booking=allowed'), $out);

        [$code] = ctpc_run('assign-tenant-plan.php', [(string) $tid, 'none']);
        assert_eq(0, $code);
        assert_false(ModuleGuard::isEntitled('forms'), 'plan removed: denied again');
    } finally {
        $restore();
    }
});

unit('tenant plan CLIs: an unknown tenant, an unknown plan and an inactive plan are refused and change nothing', function (): void {
    $tid = current_tenant_id();
    $restore = slate_test_set_tenant_plan('free');
    $freeId = (string) Database::value("SELECT id FROM platform_plans WHERE slug='free'");
    $starter = Database::row("SELECT id, is_active FROM platform_plans WHERE slug='starter'");
    try {
        [$code, $out] = ctpc_run('assign-tenant-plan.php', ['no-such-tenant-xyz', 'enterprise']);
        assert_eq(1, $code, $out);
        [$code, $out] = ctpc_run('assign-tenant-plan.php', [(string) $tid, 'no-such-plan']);
        assert_eq(1, $code, $out);
        assert_true(str_contains($out, 'Plans:'), 'lists the valid plans');
        Database::query('UPDATE platform_plans SET is_active = 0 WHERE id = ?', [$starter['id']]);
        [$code, $out] = ctpc_run('assign-tenant-plan.php', [(string) $tid, 'starter']);
        assert_eq(1, $code, 'inactive plan refused: ' . $out);
        assert_eq($freeId, (string) Database::value('SELECT plan_id FROM tenant_profiles WHERE tenant_id = ?', [$tid]), 'still on free');
    } finally {
        Database::query('UPDATE platform_plans SET is_active = ? WHERE id = ?', [$starter['is_active'], $starter['id']]);
        $restore();
    }
});
