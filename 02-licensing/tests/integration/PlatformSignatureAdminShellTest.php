<?php
/**
 * Kohevo Brand Persistence System — Phase 2 (Application Shell Integration).
 *
 * Renders a real admin page through the same authenticated-child-process
 * fixture AdminNavPhase2VisibilityTest uses (admin-page-probe.php), so this
 * exercises the actual live admin/partials/header.php output — not just its
 * source, which tests/unit/PlatformSignatureShellIntegrationTest.php already
 * covers structurally without a DB.
 */

declare(strict_types=1);

use Slate\Services\Content\PlatformIdentity;

function psast_probe(): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg('admin/index.php') . ' \'\' 1 0 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) $m[1], 'body' => substr($out, strlen($m[0]))];
}

unit('admin shell: the platform signature mark appears exactly once, sourced through PlatformIdentity', function () {
    $res = psast_probe();
    assert_eq(200, $res['status']);
    $markUrl = PlatformIdentity::markUrl();
    assert_eq(1, substr_count($res['body'], $markUrl), "expected exactly one occurrence of the platform mark URL ($markUrl)");
});

unit('admin shell: the sidebar signature wrapper renders, alongside the existing tenant sidebar-brand (tenant identity not replaced)', function () {
    $res = psast_probe();
    assert_eq(200, $res['status']);
    assert_true(str_contains($res['body'], 'class="sidebar-signature'), 'sidebar-signature wrapper must be present');
    assert_true(str_contains($res['body'], 'class="sidebar-brand'), 'the pre-existing tenant sidebar-brand block must still render');
});

unit('admin shell: existing sidebar-footer (tenant user info + logout) still renders alongside the new signature', function () {
    $res = psast_probe();
    assert_eq(200, $res['status']);
    assert_true(str_contains($res['body'], 'class="sidebar-footer"'), 'the pre-existing tenant/user sidebar-footer must be unaffected');
    assert_true(str_contains($res['body'], 'sidebar-footer-logout'), 'logout control must still render');
});

unit('admin shell: the platform signature is deterministic across two independent renders', function () {
    $a = psast_probe();
    $b = psast_probe();
    assert_eq(200, $a['status']);
    assert_eq(200, $b['status']);
    $markUrl = PlatformIdentity::markUrl();
    assert_eq(substr_count($a['body'], $markUrl), substr_count($b['body'], $markUrl));
});
