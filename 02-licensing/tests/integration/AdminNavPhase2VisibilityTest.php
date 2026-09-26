<?php
/**
 * The visual page editor and the Content section (Pages, Posts, Templates,
 * Navigation) were removed: admin/editor.php, editor-preview.php, posts.php,
 * post-edit.php, templates.php, template-export.php and menus.php are gone,
 * along with the content-page services and the public content-page route.
 * These tests pin that nothing in the sidebar still points at them and that
 * an unknown public path is a plain 404 (it used to fall through to the
 * content-page lookup, which fataled on installs without content_pages).
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

unit('sidebar has no Editor or Content items', function () {
    $res = anpv_probe();
    assert_eq(200, $res['status']);
    foreach (['admin/editor.php', 'admin/posts.php', 'admin/templates.php', 'admin/menus.php'] as $href) {
        assert_false(str_contains($res['body'], $href), "sidebar must not link {$href}");
    }
    assert_false(str_contains($res['body'], 'Visual Page Editor'), 'no Visual Page Editor item');
    assert_true(str_contains($res['body'], 'admin/media.php'), 'unrelated items (Media Library) are still there');
});

unit('the removed admin pages are gone from disk', function () {
    $root = dirname(__DIR__, 2);
    foreach (['editor', 'editor-preview', 'posts', 'post-edit', 'templates', 'template-export', 'menus'] as $page) {
        assert_false(is_file("{$root}/admin/{$page}.php"), "admin/{$page}.php must not exist");
    }
});

unit('an unknown public path is a 404, not a content-page lookup', function () {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/public-page-probe.php') . ' '
         . escapeshellarg('public.php') . ' '
         . escapeshellarg('_path=no-such-page-' . bin2hex(random_bytes(4))) . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    assert_eq(404, (int) ($m[1] ?? 0), 'unknown public path must be 404');
});
