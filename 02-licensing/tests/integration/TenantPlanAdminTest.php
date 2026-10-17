<?php
/**
 * Tenants screen: Plan selector (admin/tenants.php, _action=assign_plan)
 * and the "would be refused a module" warning, driven through the real page
 * in a child process, plus TenantService::assignPlan() rules.
 *
 * Uses a throw-away tenant created and deleted here, so tenant 1 and its
 * plan are never touched.
 */

declare(strict_types=1);

use Slate\Services\Tenancy\TenantService;

function tpa_probe(string $fixture, array $fields, bool $platformAdmin, int $roleId = 1): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/' . $fixture) . ' '
         . escapeshellarg('admin/tenants.php') . ' '
         . escapeshellarg($fields === [] ? '' : (string) json_encode($fields)) . ' '
         . escapeshellarg((string) $roleId) . ' ' . escapeshellarg($platformAdmin ? '1' : '0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)$/m', $out, $m)) {
        throw new RuntimeException('probe produced no STATUS line: ' . substr($out, 0, 300));
    }
    return [(int) $m[1], $out];
}

function tpa_get_list(bool $platformAdmin = true): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg('admin/tenants.php') . ' ' . escapeshellarg('') . ' '
         . escapeshellarg('1') . ' ' . escapeshellarg($platformAdmin ? '1' : '0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)$/m', $out, $m);
    return [(int) ($m[1] ?? 0), $out];
}

function tpa_plan_id(string $slug): int
{
    return (int) Database::value('SELECT id FROM platform_plans WHERE slug = ?', [$slug]);
}

function tpa_stored_plan(int $tid): ?int
{
    $v = Database::value('SELECT plan_id FROM tenant_profiles WHERE tenant_id = ?', [$tid]);
    return $v === null || $v === false ? null : (int) $v;
}

/** Create a throw-away active tenant; returns [id, cleanup]. */
function tpa_tenant(): array
{
    $id = TenantService::create(['name' => 'Plan Probe ' . bin2hex(random_bytes(3)), 'slug' => 'plan-probe-' . bin2hex(random_bytes(4))]);
    return [$id, static function () use ($id): void {
        Database::query('DELETE FROM tenant_profiles WHERE tenant_id = ?', [$id]);
        Database::query('DELETE FROM tenants WHERE id = ?', [$id]);
    }];
}

unit('tenants screen: a platform admin assigns, changes and removes a plan; each change is audit-logged', function (): void {
    [$tid, $cleanup] = tpa_tenant();
    try {
        assert_null(tpa_stored_plan($tid), 'a new tenant starts with no plan (nothing assigned silently)');
        [$status] = tpa_probe('admin-page-post-probe.php', ['_action' => 'assign_plan', 'tenant_id' => $tid, 'plan_id' => (string) tpa_plan_id('professional')], true);
        assert_true(in_array($status, [200, 302, 303], true), "save responds normally, got {$status}");
        assert_eq(tpa_plan_id('professional'), tpa_stored_plan($tid), 'professional assigned');

        tpa_probe('admin-page-post-probe.php', ['_action' => 'assign_plan', 'tenant_id' => $tid, 'plan_id' => (string) tpa_plan_id('free')], true);
        assert_eq(tpa_plan_id('free'), tpa_stored_plan($tid), 'changed to free');

        tpa_probe('admin-page-post-probe.php', ['_action' => 'assign_plan', 'tenant_id' => $tid, 'plan_id' => ''], true);
        assert_null(tpa_stored_plan($tid), 'No plan removes it');

        $events = (int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'tenant.plan_assigned' AND target = ?", ["tenant#{$tid}"]);
        assert_eq(3, $events, 'three changes, three audit entries');
    } finally {
        $cleanup();
    }
});

unit('tenants screen: an unknown plan id, a non-numeric value and an inactive plan are refused and change nothing', function (): void {
    [$tid, $cleanup] = tpa_tenant();
    $starter = Database::row("SELECT id, is_active FROM platform_plans WHERE slug = 'starter'");
    try {
        TenantService::assignPlan($tid, tpa_plan_id('free'), 'test');
        foreach (['999999', 'abc', '-3'] as $bad) {
            [, $body] = tpa_probe('admin-page-post-probe.php', ['_action' => 'assign_plan', 'tenant_id' => $tid, 'plan_id' => $bad], true);
            assert_true(str_contains($body, 'Plan not saved'), "{$bad}: the page says the plan was not saved");
            assert_eq(tpa_plan_id('free'), tpa_stored_plan($tid), "{$bad}: still on free");
        }
        Database::query('UPDATE platform_plans SET is_active = 0 WHERE id = ?', [$starter['id']]);
        [, $body] = tpa_probe('admin-page-post-probe.php', ['_action' => 'assign_plan', 'tenant_id' => $tid, 'plan_id' => (string) $starter['id']], true);
        assert_true(str_contains($body, 'inactive'), 'an inactive plan is refused with a reason');
        assert_eq(tpa_plan_id('free'), tpa_stored_plan($tid), 'still on free');
    } finally {
        Database::query('UPDATE platform_plans SET is_active = ? WHERE id = ?', [$starter['is_active'], $starter['id']]);
        $cleanup();
    }
});

unit('tenants screen: re-saving a tenant\'s current plan after it was retired is a no-op, not an error, and access is kept', function (): void {
    [$tid, $cleanup] = tpa_tenant();
    $starter = Database::row("SELECT id, is_active FROM platform_plans WHERE slug = 'starter'");
    try {
        TenantService::assignPlan($tid, (int) $starter['id'], 'test');
        Database::query('UPDATE platform_plans SET is_active = 0 WHERE id = ?', [$starter['id']]);
        assert_false(TenantService::assignPlan($tid, (int) $starter['id'], 'test'), 'unchanged plan: nothing to do');
        assert_eq((int) $starter['id'], tpa_stored_plan($tid));
        assert_true(\Slate\Services\Licensing\EntitlementService::canAccess($tid, 'booking'), 'documented policy: a retired plan keeps granting its modules to tenants already on it');
    } finally {
        Database::query('UPDATE platform_plans SET is_active = ? WHERE id = ?', [$starter['is_active'], $starter['id']]);
        $cleanup();
    }
});

unit('tenants screen: only platform admins can assign a plan, and a request without a valid CSRF token changes nothing', function (): void {
    [$tid, $cleanup] = tpa_tenant();
    try {
        [$status] = tpa_probe('admin-page-post-probe.php', ['_action' => 'assign_plan', 'tenant_id' => $tid, 'plan_id' => (string) tpa_plan_id('enterprise')], false, 5101);
        assert_eq(403, $status, 'an ordinary tenant admin is refused');
        assert_null(tpa_stored_plan($tid));
        tpa_probe('admin-page-post-badcsrf-probe.php', ['_action' => 'assign_plan', 'tenant_id' => $tid, 'plan_id' => (string) tpa_plan_id('enterprise')], true);
        assert_null(tpa_stored_plan($tid), 'a bad CSRF token changes nothing');
    } finally {
        $cleanup();
    }
});

unit('tenants list: warns about active tenants that would be refused a module, and the warning clears once they have a plan', function (): void {
    [$tid, $cleanup] = tpa_tenant();
    try {
        [$status, $page] = tpa_get_list();
        assert_eq(200, $status);
        assert_true(str_contains($page, 'id="tenant-plan-gaps"') && str_contains($page, "edit={$tid}#tenant-plan"), 'the new tenant is listed as at risk');
        TenantService::assignPlan($tid, tpa_plan_id('professional'), 'test');
        [, $page] = tpa_get_list();
        assert_false(str_contains($page, "edit={$tid}#tenant-plan"), 'with a plan it is no longer listed');
    } finally {
        $cleanup();
    }
});
