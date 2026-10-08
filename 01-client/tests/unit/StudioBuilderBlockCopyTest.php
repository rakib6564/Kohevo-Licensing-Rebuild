<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Add-panel copy lives in the manifest.
 *
 * Autoloader only, no database. Every registered block declares a card title,
 * description, category and a known icon name, and has a French translation, so
 * the builder never needs a hard-coded per-type table (B2-P2a, R4).
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
}

use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;

/**
 * Icon names the builder UI can draw: the keys of ICON_BY_NAME in ui/src/components/blockIcons.jsx, read from the
 * source so a new block's icon cannot be added on one side only.
 *
 * @return list<string>
 */
function sbcopy_ui_icons(): array
{
    $src = (string) file_get_contents(__DIR__ . '/../../plugins/studio-builder/ui/src/components/blockIcons.jsx');
    assert_true(preg_match('/ICON_BY_NAME\s*=\s*\{(.*?)\n\};/s', $src, $m) === 1, 'ICON_BY_NAME is readable');
    preg_match_all("/^\s*(?:'([a-z0-9-]+)'|([a-z0-9]+))\s*:/m", $m[1], $keys, PREG_SET_ORDER);
    return array_map(static fn(array $k): string => $k[1] !== '' ? $k[1] : $k[2], $keys);
}

unit('block copy: every registered block has a title, description, category and a drawable icon', function (): void {
    $manifests = ModuleBlockDefinitions::studioRegistry()->editorManifests();
    assert_true(count($manifests) >= 31, 'the registry is populated');
    foreach ($manifests as $m) {
        $type = (string) $m['type'];
        assert_true(trim((string) $m['title']) !== '', "{$type}: title");
        assert_true(trim((string) $m['description']) !== '', "{$type}: description");
        assert_true(trim((string) $m['category']) !== '', "{$type}: category");
        assert_true(in_array($m['icon'], sbcopy_ui_icons(), true), "{$type}: icon '{$m['icon']}' is not in the UI icon map");
        assert_true(mb_strlen((string) $m['description']) <= 80, "{$type}: card description stays one line");
    }
});

unit('block copy: every registered block has a French title and description', function (): void {
    $fr = require SLATE_ROOT . '/plugins/studio-builder/lang/fr.php';
    assert_true(is_array($fr), 'fr.php returns an array');
    foreach (ModuleBlockDefinitions::studioRegistry()->editorManifests() as $m) {
        $slug = str_replace('.', '_', (string) $m['type']);
        foreach (['title', 'desc'] as $suffix) {
            $key = "studio_block_{$slug}_{$suffix}";
            assert_true(isset($fr[$key]) && trim((string) $fr[$key]) !== '', "{$key} has a French entry");
        }
    }
});

unit('block copy: French entries exist only for registered blocks (no stale keys)', function (): void {
    $fr = require SLATE_ROOT . '/plugins/studio-builder/lang/fr.php';
    $slugs = [];
    foreach (ModuleBlockDefinitions::studioRegistry()->editorManifests() as $m) {
        $slugs[str_replace('.', '_', (string) $m['type'])] = true;
    }
    $stale = [];
    foreach (array_keys($fr) as $key) {
        if (preg_match('/^studio_block_(.+)_(title|desc)$/', (string) $key, $mm) === 1 && !isset($slugs[$mm[1]])) {
            $stale[] = $key;
        }
    }
    assert_eq([], $stale);
});

