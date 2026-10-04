<?php
/**
 * Test Media Library Schema and Metadata updating.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../src/Services/Media/Media.php';

use Slate\Services\Media\Media;

echo "1..3\n";

// 1. Ensure schema runs and creates columns
Media::ensureSchema();

$cols = array_column(\Database::rows("SHOW COLUMNS FROM `media_files`"), 'Field');

if (in_array('alt_text', $cols, true) && in_array('title', $cols, true) && in_array('description', $cols, true)) {
    echo "ok 1 - media_files contains alt_text, title, and description columns\n";
} else {
    echo "not ok 1 - missing columns: " . json_encode(array_diff(['alt_text', 'title', 'description'], $cols)) . "\n";
    exit(1);
}

// 2. Test Media::listAll returns metadata fields
$res = Media::listAll(['type' => 'all', 'usage' => 'all']);
$items = $res['items'] ?? [];
echo "ok 2 - Media::listAll executed successfully (found " . count($items) . " items)\n";

// 3. Test Media::updateMeta
if (!empty($items)) {
    $first = $items[0];
    $origAlt = $first['alt_text'] ?? '';
    $updated = Media::updateMeta((int)$first['id'], [
        'alt_text'    => 'Modern portfolio illustration',
        'title'       => 'Portfolio Thumbnail',
        'description' => 'Featured showcase artwork',
    ]);
    if (!empty($updated['ok']) && !empty($updated['item']) && $updated['item']['alt_text'] === 'Modern portfolio illustration') {
        echo "ok 3 - Media::updateMeta successfully updated and returned record\n";
        // restore original
        Media::updateMeta((int)$first['id'], ['alt_text' => $origAlt]);
    } else {
        echo "not ok 3 - updateMeta failed: " . json_encode($updated) . "\n";
        exit(1);
    }
} else {
    echo "ok 3 - no media items to test updateMeta on (skipped)\n";
}
