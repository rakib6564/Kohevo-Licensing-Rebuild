<?php
/**
 * Slate — plugin packager.
 *
 * Reads a plugin directory, validates its manifest + SQL, and writes
 * a `<slug>-v<version>.zip` containing the plugin folder. The ZIP is
 * the exact thing you upload via /admin/plugins.php.
 *
 * Usage:
 *
 *   php bin/package-plugin.php plugins/hello-world
 *   php bin/package-plugin.php plugins/hello-world --out=/tmp
 *   php bin/package-plugin.php plugins/hello-world --dist          # alias for --out=plugins/_dist
 *
 * Exits 0 on success, 1 on validation or zip failure.
 */

declare(strict_types=1);

// Resolve project root and load just enough core to call validators
define('SLATE_ROOT', dirname(__DIR__));

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script is for CLI use only.\n";
    exit(1);
}

if ($argc < 2 || in_array($argv[1] ?? '', ['-h', '--help'], true)) {
    fwrite(STDERR, <<<USAGE
Slate plugin packager

Usage:
  php bin/package-plugin.php <plugin-dir> [--out=<dir>] [--dist]

Arguments:
  <plugin-dir>   Path to the plugin directory (must contain plugin.json)

Options:
  --out=<dir>    Write the ZIP into this directory (default: same dir as plugin)
  --dist         Shortcut for --out=plugins/_dist (recommended for shipping)
  -h, --help     Show this help

Examples:
  php bin/package-plugin.php plugins/hello-world
  php bin/package-plugin.php plugins/hello-world --dist
  php bin/package-plugin.php /path/to/my-plugin --out=/tmp


USAGE);
    exit($argc < 2 ? 1 : 0);
}

$pluginDir = rtrim($argv[1], '/');
$outDir    = dirname($pluginDir);

// Parse flags
for ($i = 2; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '--dist') {
        $outDir = SLATE_ROOT . '/plugins/_dist';
    } elseif (str_starts_with($a, '--out=')) {
        $outDir = substr($a, 6);
    }
}

// ─── Validate plugin directory ──────────────────────────────
if (!is_dir($pluginDir)) {
    fwrite(STDERR, "Error: plugin directory not found: $pluginDir\n");
    exit(1);
}

$manifestPath = $pluginDir . '/plugin.json';
if (!file_exists($manifestPath)) {
    fwrite(STDERR, "Error: plugin.json not found in $pluginDir\n");
    exit(1);
}

$manifest = json_decode((string)file_get_contents($manifestPath), true);
if (!is_array($manifest)) {
    fwrite(STDERR, "Error: plugin.json is not valid JSON.\n");
    exit(1);
}

// Load PluginLoader purely for its static validators (no DB needed).
require SLATE_ROOT . '/includes/PluginLoader.php';

$check = PluginLoader::validateManifest($manifest);
if (!$check['ok']) {
    fwrite(STDERR, "Manifest invalid: {$check['error']}\n");
    exit(1);
}

$slug    = $manifest['slug'];
$version = $manifest['version'];
$name    = $manifest['name'];

// Slug must match folder name
if (basename(realpath($pluginDir)) !== $slug) {
    fwrite(STDERR, "Error: plugin folder name '" . basename($pluginDir) . "' must match manifest slug '$slug'.\n");
    exit(1);
}

// Bootstrap class file must exist
$className     = PluginLoader::slugToClass($slug);
$bootstrapPath = $pluginDir . '/' . $className . '.php';
if (!file_exists($bootstrapPath)) {
    fwrite(STDERR, "Error: bootstrap file $className.php missing.\n");
    exit(1);
}

// install.sql + uninstall.sql must exist
foreach (['install.sql', 'uninstall.sql'] as $sqlFile) {
    if (!file_exists($pluginDir . '/' . $sqlFile)) {
        fwrite(STDERR, "Error: $sqlFile missing.\n");
        exit(1);
    }
}

// Validate SQL contents
$sqlCheck = PluginLoader::validatePluginSql(
    $slug,
    (string)file_get_contents($pluginDir . '/install.sql'),
    (string)file_get_contents($pluginDir . '/uninstall.sql')
);
if (!$sqlCheck['ok']) {
    fwrite(STDERR, "SQL validation failed: {$sqlCheck['error']}\n");
    exit(1);
}

// ─── Ensure output dir exists ───────────────────────────────
if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) {
    fwrite(STDERR, "Error: could not create output directory: $outDir\n");
    exit(1);
}
$outDir = rtrim(realpath($outDir) ?: $outDir, '/');

$zipPath = $outDir . "/{$slug}-v{$version}.zip";
if (file_exists($zipPath)) {
    unlink($zipPath);
}

// ─── Build the ZIP ──────────────────────────────────────────
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Error: could not create ZIP at $zipPath\n");
    exit(1);
}

// Files we never include in a plugin ZIP
$skipDirs  = ['.git', 'node_modules', 'vendor', '.idea', '.vscode', '__MACOSX'];
$skipFiles = ['.DS_Store', 'Thumbs.db', '.gitignore', '.gitkeep'];

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$fileCount = 0;
foreach ($it as $entry) {
    /** @var SplFileInfo $entry */
    $rel = ltrim(str_replace($pluginDir, '', $entry->getPathname()), '/\\');

    // Skip blacklisted dirs at any depth
    $parts = explode('/', str_replace('\\', '/', $rel));
    foreach ($parts as $part) {
        if (in_array($part, $skipDirs, true)) continue 2;
    }
    if (in_array(basename($rel), $skipFiles, true)) continue;

    $entryName = $slug . '/' . str_replace('\\', '/', $rel);

    if ($entry->isDir()) {
        $zip->addEmptyDir($entryName);
    } else {
        $zip->addFile($entry->getPathname(), $entryName);
        $fileCount++;
    }
}

if (!$zip->close()) {
    fwrite(STDERR, "Error: failed to write ZIP.\n");
    @unlink($zipPath);
    exit(1);
}

$size = filesize($zipPath);
$sizeKb = number_format($size / 1024, 1);
$rel    = $zipPath;
$cwd    = getcwd();
if ($cwd && str_starts_with($zipPath, $cwd . '/')) {
    $rel = substr($zipPath, strlen($cwd) + 1);
}

echo "✓ Packaged $name v$version\n";
echo "  Slug:    $slug\n";
echo "  Files:   $fileCount\n";
echo "  Size:    {$sizeKb} KB\n";
echo "  Output:  $rel\n";

exit(0);
