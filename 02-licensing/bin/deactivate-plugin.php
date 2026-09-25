<?php
/**
 * Slate — deactivate a plugin from the CLI (same effect as the
 * "Deactivate" button on the admin Plugins page).
 *
 * Run:  php bin/deactivate-plugin.php <slug>
 * e.g.: php bin/deactivate-plugin.php flat-rate-shipping
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/deactivate-plugin.php <slug>\n");
    exit(1);
}

require __DIR__ . '/../config.php';

$slug = $argv[1] ?? null;
if ($slug === null) {
    fwrite(STDERR, "Usage: php bin/deactivate-plugin.php <slug>\n");
    exit(1);
}

$res = PluginLoader::deactivate($slug);
if (!empty($res['ok'])) {
    echo "Deactivated '$slug'" . (!empty($res['note']) ? " ({$res['note']})" : "") . ".\n";
} else {
    fwrite(STDERR, "Failed: " . ($res['error'] ?? 'unknown error') . "\n");
    exit(1);
}
