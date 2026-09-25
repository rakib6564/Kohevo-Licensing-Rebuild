<?php
/**
 * admin/partials/header.php previously hid Pages/Posts/Editor/Templates from
 * the sidebar whenever the archived content-builder plugin wasn't active —
 * which is now permanently the case, since that plugin was never migrated
 * and can't be reinstalled. That left the Phase 2 editor fully working but
 * unreachable from the nav. Fixed by gating those items on editor_phase2_enabled
 * instead; Navigation (menus.php) stays gated on the plugin, since it's a
 * literal bridge into files that no longer exist.
 */

declare(strict_types=1);

function anpv_probe(): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg('admin/index.php') . ' \'\' 1 0 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) $m[1], 'body' => substr($out, strlen($m[0]))];
}

unit('sidebar shows Pages/Posts/Editor/Templates by default, but not Navigation', function () {
    $res = anpv_probe();
    assert_eq(200, $res['status']);
    assert_true(str_contains($res['body'], 'admin/posts.php?type=page'), 'Pages must be linked');
    assert_true(str_contains($res['body'], 'admin/posts.php?type=post'), 'Posts must be linked');
    assert_true(str_contains($res['body'], 'admin/templates.php'), 'Templates must be linked');
    assert_false(str_contains($res['body'], 'admin/menus.php'), 'Navigation must stay hidden — menus.php still bridges into an archived plugin file');
});

unit('sidebar hides Pages/Posts/Editor/Templates when editor_phase2_enabled is disabled', function () {
    Database::setSetting('editor_phase2_enabled', '0');
    try {
        $res = anpv_probe();
        assert_eq(200, $res['status']);
        assert_false(str_contains($res['body'], 'admin/posts.php?type=page'), 'Pages must be hidden while the Phase 2 editor is disabled');
        assert_false(str_contains($res['body'], 'admin/templates.php'), 'Templates must be hidden while the Phase 2 editor is disabled');
    } finally {
        Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?', [current_tenant_id(), 'editor_phase2_enabled']);
    }
});
