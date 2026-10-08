<?php
define('SLATE_ALLOW_LIVE_DB', 1);
require_once __DIR__ . '/../config.php';

use Slate\Data\Database;

$backup = [
    'timestamp' => date('c'),
    'pages' => Database::all("SELECT * FROM studiobuilder_pages ORDER BY id ASC"),
    'revisions' => Database::all("SELECT * FROM studiobuilder_revisions ORDER BY id ASC"),
    'compilations' => Database::all("SELECT * FROM studiobuilder_compilations ORDER BY id ASC"),
    'templates' => Database::all("SELECT * FROM studiobuilder_templates ORDER BY id ASC"),
];

$dir = __DIR__ . '/../storage/backups';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$filename = $dir . '/pages-backup-' . date('Ymd-His') . '.json';
file_put_contents($filename, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Backup saved successfully to: {$filename} (" . count($backup['pages']) . " pages, " . count($backup['revisions']) . " revisions)\n";
