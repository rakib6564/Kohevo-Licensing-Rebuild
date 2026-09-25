<?php
/**
 * Slate — activate an on-disk plugin from the CLI (the counterpart to
 * bin/deactivate-plugin.php, and the same effect as the admin Plugins page).
 *
 * The admin page lists PluginLoader::listAll(), which reads the `plugins`
 * TABLE — not the plugins/ directory. A plugin shipped in the repo therefore
 * stays invisible in the UI until something registers a row for it, and the
 * only path that does is the ZIP upload. That makes a fresh checkout awkward
 * to bring up: every bundled plugin has to be packaged and re-uploaded to
 * reach a state the repo already describes.
 *
 * This registers the row straight from plugins/<slug>/plugin.json and then
 * hands off to PluginLoader::activate(), so manifest validation, install.sql
 * and permission registration all run exactly as they do from the UI.
 *
 * The actual per-slug "seed a row if missing, then activate" work lives in
 * PluginLoader::installFromDisk() — install.php's plugin-selection step
 * uses the exact same method, so a fresh checkout reaches the same state
 * whether it's brought up via this CLI script or the web installer.
 *
 * Run:  php bin/activate-plugin.php <slug> [<slug> ...]
 *       php bin/activate-plugin.php --all      (every plugin in plugins/)
 * e.g.: php bin/activate-plugin.php booking membership coaching
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/activate-plugin.php <slug>\n");
    exit(1);
}

require __DIR__ . '/../config.php';

$args = array_slice($argv, 1);
if (!$args) {
    fwrite(STDERR, "Usage: php bin/activate-plugin.php <slug> [<slug> ...]\n"
                 . "       php bin/activate-plugin.php --all\n");
    exit(1);
}

if (in_array('--all', $args, true)) {
    $args = array_column(PluginLoader::discoverOnDisk(), 'slug');
    sort($args);
    if (!$args) {
        fwrite(STDERR, "No plugins with a valid plugin.json found under plugins/.\n");
        exit(1);
    }
}

$failed = 0;

foreach ($args as $slug) {
    $alreadyRegistered = (bool) Database::row("SELECT id FROM plugins WHERE slug = ?", [$slug]);

    $res = PluginLoader::installFromDisk($slug);
    if (!empty($res['ok'])) {
        if (!$alreadyRegistered) echo "  + $slug — registered\n";
        $note = !empty($res['note']) ? " ({$res['note']})" : '';
        echo "  ✓ $slug$note\n";
    } else {
        fwrite(STDERR, "  ✗ $slug — " . ($res['error'] ?? 'unknown error') . "\n");
        $failed++;
    }
}

if ($failed > 0) {
    fwrite(STDERR, "\n$failed plugin(s) failed.\n");
    exit(1);
}

echo "\nDone. Check Admin → Plugins.\n";
