<?php
/**
 * The Extensions screen lists only plugins that can be acted on: a `plugins` row with no plugin.json on disk and not active
 * (a client install ships plugins/licensing/client but never the licence-server plugin, whose row stays from the first install
 * and answered "plugin.json missing on disk" when someone tried to activate it) is not shown. An active plugin whose manifest
 * has gone stays listed so a real fault is visible. Filesystem only, no database.
 */

declare(strict_types=1);

use Slate\Kernel\Module\PluginLoader;

unit('PluginLoader::hasManifestOnDisk: true only for a plugin folder that holds plugin.json', function (): void {
    $root = sys_get_temp_dir() . '/slate-plugins-' . bin2hex(random_bytes(4));
    mkdir($root . '/real-plugin', 0777, true);
    file_put_contents($root . '/real-plugin/plugin.json', '{}');
    mkdir($root . '/licensing/client', 0777, true);                // only the check-in library, no manifest
    file_put_contents($root . '/licensing/client/RemoteLicenseClient.php', '<?php');
    try {
        assert_true(PluginLoader::hasManifestOnDisk('real-plugin', $root), 'a folder with plugin.json');
        assert_true(!PluginLoader::hasManifestOnDisk('licensing', $root), 'a folder with only client/ has no manifest');
        assert_true(!PluginLoader::hasManifestOnDisk('gone', $root), 'no folder at all');
        assert_true(!PluginLoader::hasManifestOnDisk('../real-plugin', $root), 'a path is never a slug');
        assert_true(!PluginLoader::hasManifestOnDisk('', $root), 'an empty slug');
    } finally {
        @unlink($root . '/real-plugin/plugin.json');
        @unlink($root . '/licensing/client/RemoteLicenseClient.php');
        @rmdir($root . '/licensing/client');
        @rmdir($root . '/licensing');
        @rmdir($root . '/real-plugin');
        @rmdir($root);
    }
});

unit('PluginLoader::listableRows: hides inactive/installed rows with no manifest, keeps real plugins and any ACTIVE row', function (): void {
    $root = sys_get_temp_dir() . '/slate-plugins-' . bin2hex(random_bytes(4));
    mkdir($root . '/shop', 0777, true);
    file_put_contents($root . '/shop/plugin.json', '{}');
    mkdir($root . '/licensing/client', 0777, true);
    try {
        $rows = [
            ['slug' => 'shop',      'status' => 'active'],
            ['slug' => 'licensing', 'status' => 'installed'],      // ghost: nothing to activate
            ['slug' => 'old-thing', 'status' => 'inactive'],       // ghost
            ['slug' => 'broken',    'status' => 'active'],         // active but manifest gone: a fault worth seeing
            ['slug' => 'shop',      'status' => 'inactive'],       // real folder, inactive: still listed
        ];
        $kept = array_map(static fn(array $r): string => $r['slug'] . ':' . $r['status'], PluginLoader::listableRows($rows, $root));
        assert_eq(['shop:active', 'broken:active', 'shop:inactive'], $kept);
        assert_eq([], PluginLoader::listableRows([], $root), 'an empty list stays empty');
    } finally {
        @unlink($root . '/shop/plugin.json');
        @rmdir($root . '/shop');
        @rmdir($root . '/licensing/client');
        @rmdir($root . '/licensing');
        @rmdir($root);
    }
});
