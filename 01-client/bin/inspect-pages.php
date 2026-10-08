<?php
define('SLATE_ALLOW_LIVE_DB', 1);
require_once __DIR__ . '/../config.php';

use Slate\Data\Database;

echo "=== STUDIOBUILDER PAGES ===\n";
try {
    $rows = Database::all("SELECT id, slug, title, status, page_type, route_mode FROM studiobuilder_pages ORDER BY id ASC");
    foreach ($rows as $r) {
        printf("ID: %-4d | Type: %-15s | Status: %-10s | Route: %-12s | Slug: %-30s | Title: %s\n",
            $r['id'], $r['page_type'], $r['status'], $r['route_mode'], $r['slug'], $r['title']
        );
    }
} catch (\Throwable $e) {
    echo "Error querying studiobuilder_pages: " . $e->getMessage() . "\n";
}

echo "\n=== CB_POSTS / CONTENT BUILDER PAGES ===\n";
try {
    $rows = Database::all("SELECT id, post_type, post_title, post_name, post_status FROM cb_posts ORDER BY id ASC");
    foreach ($rows as $r) {
        printf("ID: %-4d | Type: %-10s | Status: %-10s | Slug: %-25s | Title: %s\n",
            $r['id'], $r['post_type'], $r['post_status'], $r['post_name'], $r['post_title']
        );
    }
} catch (\Throwable $e) {
    echo "No cb_posts or error: " . $e->getMessage() . "\n";
}
