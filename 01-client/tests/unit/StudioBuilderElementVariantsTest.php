<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — B2-P2b Add-panel element variants (Columns, Stack, Row,
 * Text area, Dropdown): ready-set configurations of block types that already exist.
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

use Slate\Module\StudioBuilder\Presets\ElementVariantCatalog;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;

unit('variants: keys are unique, lower-case, and map to an existing block type', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $keys = [];
    foreach (ElementVariantCatalog::all() as $v) {
        assert_true(preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $v['key']) === 1, "{$v['key']}: key shape");
        assert_true(!isset($keys[$v['key']]), "{$v['key']}: unique");
        $keys[$v['key']] = true;
        assert_true($registry->has($v['type']), "{$v['key']}: {$v['type']} is registered (a variant never invents a type)");
        assert_true(mb_strlen($v['description']) <= 80, "{$v['key']}: description stays one line");
    }
    assert_true(count($keys) >= 7, 'columns x3, stack, row, text area, dropdown');
});

unit('variants: every variant\'s props are valid for its block, and adopt non-default values', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    foreach (ElementVariantCatalog::all() as $v) {
        $def = $registry->get($v['type']);
        $merged = array_merge($def->schema()->defaults(), $v['props']);
        $result = $def->validateProps($merged);
        assert_true($result->isValid(), "{$v['key']}: props validate against {$v['type']}");
        $defaults = $def->schema()->defaults();
        $differs = false;
        foreach ($v['props'] as $k => $val) {
            $differs = $differs || ($defaults[$k] ?? null) !== $val;
        }
        assert_true($differs, "{$v['key']}: a variant that changes nothing is just the block");
        assert_true($v['category'] !== '' && in_array($v['icon'], sbcopy_variant_icons(), true), "{$v['key']}: category and a drawable icon");
    }
});

/** @return list<string> keys of ICON_BY_NAME in the UI */
function sbcopy_variant_icons(): array
{
    $src = (string) file_get_contents(__DIR__ . '/../../plugins/studio-builder/ui/src/components/blockIcons.jsx');
    preg_match('/ICON_BY_NAME\s*=\s*\{(.*?)\n\};/s', $src, $m);
    preg_match_all("/^\s*(?:'([a-z0-9-]+)'|([a-z0-9]+))\s*:/m", $m[1] ?? '', $keys, PREG_SET_ORDER);
    return array_map(static fn(array $k): string => $k[1] !== '' ? $k[1] : $k[2], $keys);
}

unit('variants: French copy exists for every variant and nothing stale', function (): void {
    $fr = require SLATE_ROOT . '/plugins/studio-builder/lang/fr.php';
    $slugs = [];
    foreach (ElementVariantCatalog::all() as $v) {
        $slug = str_replace('-', '_', $v['key']);
        $slugs[$slug] = true;
        foreach (['title', 'desc'] as $suffix) {
            assert_true(isset($fr["studio_variant_{$slug}_{$suffix}"]) && trim((string) $fr["studio_variant_{$slug}_{$suffix}"]) !== '', "studio_variant_{$slug}_{$suffix}");
        }
    }
    foreach (array_keys($fr) as $key) {
        if (preg_match('/^studio_variant_(.+)_(title|desc)$/', (string) $key, $m) === 1) {
            assert_true(isset($slugs[$m[1]]), "stale French key {$key}");
        }
    }
});

unit('variants: the UI fixture matches the catalogue (no drift)', function (): void {
    $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../plugins/studio-builder/ui/tests/fixtures/manifest.json'), true);
    assert_eq(ElementVariantCatalog::all(), $fixture['variants'], 'ui/tests/fixtures/manifest.json variants are stale — regenerate them from ElementVariantCatalog::all()');
});
