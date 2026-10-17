<?php
/**
 * The central tree's bundled Forms and Booking plugins call ModuleGuard at
 * every entry point. The class must exist here (it used to exist only in
 * 01-client, so those pages died with "Class ModuleGuard not found"), be the
 * same class under its unqualified alias, and answer from the central
 * EntitlementService chain.
 */

declare(strict_types=1);

function cmg_probe(string $page): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg('') . ' ' . escapeshellarg('1') . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $status = preg_match('/^STATUS (\d+)$/m', $out, $m) ? (int) $m[1] : 0;
    return [$status, $out];
}

unit('central ModuleGuard: the unqualified alias is the namespaced class', function (): void {
    assert_true(class_exists('ModuleGuard'), 'ModuleGuard must be loadable in the central tree');
    assert_eq(\Slate\Services\Licensing\ModuleGuard::class, (new ReflectionClass('ModuleGuard'))->getName());
});

unit('central ModuleGuard: isEntitled() is the central EntitlementService answer, and a tenant toggle turns it off', function (): void {
    $tid = current_tenant_id();
    foreach (['forms', 'booking'] as $module) {
        $before = Database::value('SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ?', [$tid, "entitlement.$module.enabled"]);
        try {
            assert_eq(\Slate\Services\Licensing\EntitlementService::canAccess($tid, $module), ModuleGuard::isEntitled($module), "{$module}: same answer as canAccess()");
            Database::setSetting("entitlement.$module.enabled", '0', $tid);
            assert_false(ModuleGuard::isEntitled($module), "{$module}: switched off for the tenant");
            assert_true(ModuleGuard::allows($module), "{$module}: allows() keeps the CLI/test bypass, as in 01-client");
        } finally {
            if ($before === null || $before === false) {
                Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?', [$tid, "entitlement.$module.enabled"]);
            } else {
                Database::setSetting("entitlement.$module.enabled", (string) $before, $tid);
            }
        }
    }
});

unit('central ModuleGuard: Forms and Booking admin pages no longer die on a missing class', function (): void {
    foreach (['plugins/forms/admin/index.php', 'plugins/booking/admin/index.php'] as $page) {
        [$status, $out] = cmg_probe($page);
        assert_false(str_contains($out, 'Class "ModuleGuard" not found') || str_contains($out, "Class 'ModuleGuard' not found"), "{$page}: " . substr($out, 0, 300));
        assert_true($status !== 0 && $status < 500, "{$page}: expected a non-error status, got {$status}");
    }
});
