<?php
/**
 * Slate — move the leftover test/demo pages to Trash.
 *
 * Trashes (does NOT permanently delete) the 5 "Untitled Page" test drafts
 * left over from earlier Content Builder bug-fix verification, plus the
 * pre-existing "Demo landing" page — leaving only the 5 real construction
 * site pages (Home, About, Services, Projects, Contact) live.
 *
 * Safe / idempotent: matches by slug, skips anything already gone or
 * already trashed, and only ever sets status='trash' (reversible from the
 * admin Pages → Trash tab — never a permanent delete).
 *
 * Run:  php bin/cleanup-test-pages.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/cleanup-test-pages.php\n");
    exit(1);
}

require __DIR__ . '/../config.php';

if (!class_exists('ContentBuilderAPI')) {
    fwrite(STDERR, "Content Builder plugin isn't active.\n");
    exit(1);
}

$slugsToTrash = [
    'untitled-page',
    'untitled-page-3',
    'untitled-page-7',
    'untitled-page-14',
    'untitled-page-36',
    'demo',
];

foreach ($slugsToTrash as $slug) {
    $post = ContentBuilderAPI::getPostBySlug('page', $slug);
    if (!$post) {
        echo "Skipped '$slug' — not found.\n";
        continue;
    }
    if (($post['status'] ?? '') === 'trash') {
        echo "Already trashed: '{$post['title']}' (id={$post['id']}, slug=$slug)\n";
        continue;
    }
    ContentBuilderAPI::trash((int)$post['id']);
    echo "Trashed: '{$post['title']}' (id={$post['id']}, slug=$slug)\n";
}

echo "\nDone. The 5 construction site pages (home, about, services, projects, contact) were left untouched.\n";
echo "Anything trashed here can be restored from Content → Pages → Trash if needed.\n";
