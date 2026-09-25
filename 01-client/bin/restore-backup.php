<?php
/**
 * Slate — restore a Backups-plugin SQL dump into the CURRENT .env's database.
 *
 * This is deliberately CLI-only and never exposed over the web — restoring
 * overwrites live data and must never be a single accidental click away
 * (see plugins/backups/README.md — there is no web restore button by design).
 *
 * Usage:
 *   php bin/restore-backup.php <path-to-dump.sql>             (dry run — describes what would happen, changes nothing)
 *   php bin/restore-backup.php <path-to-dump.sql> --confirm    (actually restores, after typing the DB name to confirm)
 *
 * Get dump.sql by downloading the latest zip from your "Slate Backups"
 * Google Drive folder and extracting it. See docs/13-Operations/backups.md
 * for the full migration runbook (this script only restores the database —
 * you still need to copy the extracted uploads/ folder into place yourself).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/restore-backup.php <path-to-dump.sql> [--confirm]\n");
    exit(1);
}

require __DIR__ . '/../config.php';

$args    = array_slice($argv, 1);
$confirm = in_array('--confirm', $args, true);
$args    = array_values(array_filter($args, fn($a) => $a !== '--confirm'));
$path    = $args[0] ?? '';

if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php bin/restore-backup.php <path-to-dump.sql> [--confirm]\n");
    exit(1);
}

$sql = file_get_contents($path);
if ($sql === false) {
    fwrite(STDERR, "Could not read {$path}\n");
    exit(1);
}

$sizeKb    = round(strlen($sql) / 1024, 1);
$stmtCount = substr_count($sql, ";\n");

echo "Target database : " . DB_NAME . " @ " . DB_HOST . "\n";
echo "Dump file       : {$path} ({$sizeKb} KB, ~{$stmtCount} statements)\n\n";
echo "THIS WILL DROP AND RECREATE TABLES IN THE TARGET DATABASE, DESTROYING\n";
echo "ANY DATA CURRENTLY THERE THAT ISN'T IN THIS DUMP.\n\n";

if (!$confirm) {
    echo "Dry run only — nothing was changed. Re-run with --confirm to actually restore.\n";
    exit(0);
}

echo "Type the database name (" . DB_NAME . ") to proceed: ";
$typed = trim((string) fgets(STDIN));
if ($typed !== DB_NAME) {
    fwrite(STDERR, "Confirmation did not match. Aborted — nothing was changed.\n");
    exit(1);
}

echo "Restoring...\n";
try {
    // A dump this tool generates is our own trusted SQL, not user input —
    // PDO_MYSQL's default multi-statement exec() is exactly what a bulk
    // restore needs, so no per-statement splitting is attempted here.
    Database::get()->exec($sql);
} catch (\Throwable $e) {
    fwrite(STDERR, "Restore failed partway through: " . $e->getMessage() . "\n");
    fwrite(STDERR, "The database may now be in a partially-restored state — check it before using this site.\n");
    exit(1);
}

echo "Done. Remember to also copy the backup's uploads/ folder into place —\n";
echo "see docs/13-Operations/backups.md.\n";
