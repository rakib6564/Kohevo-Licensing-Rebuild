<?php
/**
 * The central tree's bundled Forms and Booking plugins call ModuleGuard at
 * every entry point. The class must exist here (it used to exist only in
 * 01-client, so those pages died with "Class ModuleGuard not found"), be the
 * same class under its unqualified alias, and answer from the central
 * EntitlementService chain.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/tenant-plan.php';

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

unit('central ModuleGuard: a super-admin gets each module page only when the tenant plan includes it (200), otherwise 403', function (): void {
    $pages = [
        'forms'      => 'plugins/forms/admin/index.php',
        'booking'    => 'plugins/booking/admin/index.php',
        'membership' => 'plugins/membership/admin/index.php',
    ];
    $expect = [
        null           => ['forms' => 403, 'booking' => 403, 'membership' => 403],
        'free'         => ['forms' => 200, 'booking' => 403, 'membership' => 403],
        'starter'      => ['forms' => 200, 'booking' => 200, 'membership' => 403],
        'professional' => ['forms' => 200, 'booking' => 200, 'membership' => 200],
    ];
    foreach ($expect as $plan => $byModule) {
        $restore = slate_test_set_tenant_plan($plan === '' ? null : $plan);
        try {
            foreach ($byModule as $module => $status) {
                [$got, $out] = cmg_probe($pages[$module]);
                $label = ($plan === '' ? 'no plan' : "plan {$plan}") . ", {$module}";
                assert_eq($status, $got, "{$label}: " . substr(preg_replace('/\s+/', ' ', strip_tags($out)), 0, 160));
            }
        } finally {
            $restore();
        }
    }
});

unit('central ModuleGuard: a plan id that does not exist denies like no plan (fail closed)', function (): void {
    $tid = current_tenant_id();
    $restore = slate_test_set_tenant_plan(null);
    try {
        Database::query('UPDATE tenant_profiles SET plan_id = 999999 WHERE tenant_id = ?', [$tid]);
        foreach (['forms', 'booking', 'membership'] as $module) {
            assert_false(ModuleGuard::isEntitled($module), "{$module}: an unknown plan grants nothing");
        }
        [$got] = cmg_probe('plugins/forms/admin/index.php');
        assert_eq(403, $got, 'forms admin with an unknown plan');
    } finally {
        $restore();
    }
});

unit('central ModuleGuard: APP_ENV=testing does not bypass the guard for a request served by a web server', function (): void {
    $restore = slate_test_set_tenant_plan(null);
    $port = 20000 + random_int(0, 9999);
    $cmd  = [PHP_BINARY, '-S', "127.0.0.1:{$port}", dirname(__DIR__) . '/fixtures/sapi-guard-probe.php'];
    $env  = array_merge(getenv(), ['APP_ENV' => 'testing']);
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname(__DIR__, 2), $env);
    try {
        assert_true(is_resource($proc), 'precondition: the built-in server started');
        $body = false;
        for ($i = 0; $i < 50 && $body === false; $i++) {
            usleep(100000);
            $body = @file_get_contents("http://127.0.0.1:{$port}/?m=booking");
        }
        $r = json_decode((string) $body, true);
        assert_true(is_array($r), 'the server answered: ' . var_export($body, true));
        assert_eq('cli-server', $r['sapi'], 'precondition: served by a web-server SAPI, not CLI');
        assert_eq('testing', $r['app_env'], 'precondition: APP_ENV=testing reached the request');
        assert_false($r['testing_constant'], 'precondition: no SLATE_TESTING constant in a web request');
        assert_false($r['entitled'], 'precondition: the tenant has no plan');
        assert_false($r['allows'], 'APP_ENV=testing must not let an unentitled tenant through on a web request');
    } finally {
        if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
        $restore();
    }
});

