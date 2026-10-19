<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the built-in header catalogue (HeaderPresetCatalog).
 *
 * Autoloader only, no database. Five headers, each a real, valid, deterministic header document built from registered
 * blocks; each reaches the whole menu on a phone and on a wide screen; menus name the site's header menu and keep
 * their own links as the fallback; names and descriptions have French.
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

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\ValidatedDocument;
use Slate\Module\StudioBuilder\Presets\HeaderPresetCatalog;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;

/**
 * Every block with the devices it shows on: a block shows only where it AND each of its ancestors show.
 *
 * @return list<array{block: array<string, mixed>, devices: list<string>}>
 */
function sbhp_blocks(array $document): array
{
    $out = [];
    $walk = static function (array $blocks, array $devices) use (&$walk, &$out): void {
        foreach ($blocks as $b) {
            $own = is_array($b['visibility']['devices'] ?? null) ? $b['visibility']['devices'] : CanonicalDocumentSchema::ALLOWED_BREAKPOINTS;
            $shown = array_values(array_intersect($devices, $own));
            $out[] = ['block' => $b, 'devices' => $shown];
            $walk(is_array($b['children'] ?? null) ? $b['children'] : [], $shown);
        }
    };
    foreach ($document['sections'] as $s) {
        $walk($s['blocks'], CanonicalDocumentSchema::ALLOWED_BREAKPOINTS);
    }
    return $out;
}

unit('header presets: five headers with unique, reserved keys and one-line copy', function (): void {
    $all = HeaderPresetCatalog::all();
    assert_eq(['classic', 'centered', 'split', 'minimal', 'two-tier'], array_column($all, 'slug'));
    $keys = array_column($all, 'key');
    assert_eq(count($keys), count(array_unique($keys)), 'keys are unique');
    foreach ($all as $p) {
        assert_true(str_starts_with($p['key'], HeaderPresetCatalog::KEY_PREFIX), "{$p['key']} is reserved");
        assert_true(preg_match(CanonicalDocumentSchema::TEMPLATE_KEY_PATTERN, $p['key']) === 1, "{$p['key']} is a valid template key");
        assert_eq(HeaderPresetCatalog::CATEGORY, $p['category']);
        assert_true(trim($p['name']) !== '' && trim($p['description']) !== '', "{$p['key']} has copy");
        assert_true(mb_strlen($p['description']) <= 80, "{$p['key']} description stays one line");
        assert_eq($p, HeaderPresetCatalog::find($p['key']), "find() returns {$p['key']}");
    }
    assert_eq(null, HeaderPresetCatalog::find('system-section-hero-classic'), 'find() only knows headers');
});

unit('header presets: every header validates through the real pipeline as a header document, idempotently', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    foreach (HeaderPresetCatalog::all() as $p) {
        $once  = ValidatedDocument::from($p['document'], $registry, [])->toArray();
        $twice = ValidatedDocument::from($once, $registry, [])->toArray();
        assert_eq(CanonicalJson::encode($once), CanonicalJson::encode($twice), "{$p['key']} normalizes idempotently");
        assert_eq('header_partial', $once['document_type']);
        assert_eq('header', $once['settings']['template_type'] ?? null, "{$p['key']} is a header");
        assert_true(!isset($once['settings']['conditions']), "{$p['key']} carries no display conditions: using it never activates it");
        foreach ($once['sections'] as $s) {
            assert_true(empty($s['global_ref']), "{$p['key']} owns its content");
            assert_eq(['base' => 1, 'md' => 1], $s['layout']['columns'], "{$p['key']} section {$s['label']} is one column wide");
        }
    }
});

unit('header presets: each header reaches the whole menu on a phone and on a wide screen, and links home', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    foreach (HeaderPresetCatalog::all() as $p) {
        $blocks = sbhp_blocks($p['document']);
        foreach ($blocks as $entry) {
            assert_true($registry->get((string) $entry['block']['type']) !== null, "{$p['key']}: {$entry['block']['type']} is registered");
        }
        foreach (['base', 'sm', 'md', 'lg'] as $device) {
            $menus = array_filter($blocks, static fn(array $e): bool => $e['block']['type'] === 'core.nav_menu' && in_array($device, $e['devices'], true));
            assert_true($menus !== [], "{$p['key']} shows a menu (inline or in its drawer) on {$device}");
            $home = array_filter($blocks, static fn(array $e): bool => $e['block']['type'] === 'core.link'
                && ($e['block']['props']['link']['href'] ?? '') === '/' && in_array($device, $e['devices'], true));
            assert_true($home !== [], "{$p['key']} shows the brand link home on {$device}");
        }
    }
});

unit('header presets: menus name the site header menu and keep their own links as the fallback', function (): void {
    foreach (HeaderPresetCatalog::all() as $p) {
        foreach (sbhp_blocks($p['document']) as $entry) {
            $b = $entry['block'];
            if ($b['type'] !== 'core.nav_menu') {
                continue;
            }
            assert_true(count($b['props']['items']) >= 2, "{$p['key']}: a menu renders even before the site has a header menu");
            // The split header shows two halves of a menu, which one named menu cannot express.
            if ($p['slug'] === 'split' && $b['props']['layout'] === 'horizontal') {
                assert_eq('', $b['props']['menu']);
                continue;
            }
            assert_eq(HeaderPresetCatalog::MENU_KEY, $b['props']['menu'], "{$p['key']} uses the site's header menu");
        }
    }
});

unit('header presets: deterministic, with unique ids and drawer ids that are valid fragment names', function (): void {
    $first = [];
    foreach (HeaderPresetCatalog::all() as $p) {
        $first[$p['key']] = CanonicalJson::encode($p['document']);
    }
    $ref = new \ReflectionProperty(HeaderPresetCatalog::class, 'memo');
    $ref->setValue(null, null);
    foreach (HeaderPresetCatalog::all() as $p) {
        assert_eq($first[$p['key']], CanonicalJson::encode($p['document']), "{$p['key']} is stable across rebuilds");
        $ids = array_map(static fn(array $e): string => (string) $e['block']['id'], sbhp_blocks($p['document']));
        foreach ($p['document']['sections'] as $s) {
            $ids[] = (string) $s['id'];
            assert_true(preg_match(CanonicalDocumentSchema::SECTION_ID_PATTERN, (string) $s['id']) === 1, "{$p['key']} section id");
        }
        assert_eq(count($ids), count(array_unique($ids)), "{$p['key']} ids are unique");
        foreach (sbhp_blocks($p['document']) as $entry) {
            $b = $entry['block'];
            assert_true(preg_match(CanonicalDocumentSchema::BLOCK_ID_PATTERN, (string) $b['id']) === 1, "{$p['key']} block id {$b['id']}");
            if ($b['type'] === 'layout.offcanvas') {
                assert_true(preg_match('/^[a-z][a-z0-9-]{1,63}$/', (string) $b['props']['drawer_id']) === 1, "{$p['key']} drawer id {$b['props']['drawer_id']}");
            }
        }
    }
});

unit('header presets: every header has a French name and description, and there are no stale French keys', function (): void {
    $fr = require SLATE_ROOT . '/plugins/studio-builder/lang/fr.php';
    $slugs = [];
    foreach (HeaderPresetCatalog::all() as $p) {
        $slug = str_replace('-', '_', $p['slug']);
        $slugs[$slug] = true;
        foreach (['name', 'desc'] as $suffix) {
            assert_true(isset($fr["studio_header_preset_{$slug}_{$suffix}"]) && trim((string) $fr["studio_header_preset_{$slug}_{$suffix}"]) !== '', "studio_header_preset_{$slug}_{$suffix}");
        }
    }
    $stale = [];
    foreach (array_keys($fr) as $key) {
        if (preg_match('/^studio_header_preset_(.+)_(name|desc)$/', (string) $key, $m) === 1 && !isset($slugs[$m[1]])) {
            $stale[] = $key;
        }
    }
    assert_eq([], $stale);
});
