<?php
declare(strict_types=1);

function p4_admin_probe(string $page, int $roleId = 5101, bool $platform = false): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
        . escapeshellarg($page) . ' ' . escapeshellarg('') . ' '
        . escapeshellarg((string)$roleId) . ' ' . escapeshellarg($platform ? '1' : '0') . ' 2>/dev/null';
    $out = (string)shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('probe did not return a STATUS line: ' . $out);
    }
    return ['status' => (int)$m[1], 'body' => substr($out, strlen($m[0]))];
}

function p4_admin_post_probe(string $page, array $fields, int $roleId = 5101, bool $platform = false): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
        . escapeshellarg($page) . ' ' . escapeshellarg((string)json_encode($fields)) . ' '
        . escapeshellarg((string)$roleId) . ' ' . escapeshellarg($platform ? '1' : '0') . ' 2>/dev/null';
    $out = (string)shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('POST probe did not return a STATUS line: ' . $out);
    }
    return ['status' => (int)$m[1], 'body' => substr($out, strlen($m[0]))];
}

unit('Phase 4: ordinary tenant admins cannot reach platform licensing or tenant-surface routes', function (): void {
    foreach (['admin/licenses.php', 'admin/plans.php', 'admin/tenants.php', 'admin/exit-tenant.php'] as $page) {
        $res = p4_admin_probe($page);
        assert_eq(403, $res['status'], $page . ' must remain platform-admin-only');
    }
});

unit('Phase 4: ordinary tenant admins cannot POST local licensing or tenant mutations', function (): void {
    $requests = [
        ['admin/licenses.php', ['_action'=>'issue','tenant_id'=>'1','status'=>'active']],
        ['admin/plans.php', ['_action'=>'save','name'=>'Bypass','slug'=>'bypass-plan']],
        ['admin/tenants.php', ['_action'=>'create','name'=>'Bypass','slug'=>'bypass-tenant']],
        ['admin/exit-tenant.php', []],
    ];
    foreach ($requests as [$page, $fields]) {
        $res = p4_admin_post_probe($page, $fields);
        assert_eq(403, $res['status'], $page . ' POST must remain platform-admin-only');
    }
});

unit('Phase 4: internal support pages explicitly identify legacy licensing data as non-authoritative', function (): void {
    $licenses = file_get_contents(dirname(__DIR__, 2) . '/admin/licenses.php');
    $plans = file_get_contents(dirname(__DIR__, 2) . '/admin/plans.php');
    $tenants = file_get_contents(dirname(__DIR__, 2) . '/admin/tenants.php');
    assert_true(str_contains((string)$licenses, 'Remote License Server state controls commercial access'));
    assert_true(str_contains((string)$plans, 'do not override verified remote entitlements'));
    assert_true(str_contains((string)$tenants, 'ordinary client users cannot create, select, or switch tenants'));
});

unit('Phase 4: tenant override exit is server-side platform-admin protected', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2) . '/admin/exit-tenant.php');
    assert_true(str_contains((string)$source, 'Auth::requirePlatformAdmin();'));
});

unit('Phase 4: ordinary dashboard presents remote entitlement state instead of local plan/license authority', function (): void {
    $keys = ['LICENSE_SERVER_URL','LICENSE_SERVER_PUBLIC_KEY','LICENSE_PRODUCT','LICENSE_KEY'];
    foreach ($keys as $key) putenv($key . '=phase4-dashboard-test');
    try {
        $res = p4_admin_probe('admin/index.php');
        assert_eq(200, $res['status']);
        assert_true(str_contains($res['body'], 'Remote entitlement'));
        assert_true(str_contains($res['body'], 'Commercial access is controlled by the Kohevo License Server'));
    } finally {
        foreach ($keys as $key) putenv($key);
    }
});
