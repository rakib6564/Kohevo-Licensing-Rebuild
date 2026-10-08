<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the composed section preset catalogue.
 *
 * Autoloader only, no database. Every preset is a real, valid, deterministic canonical
 * document built from registered blocks; none is a bare primitive; names and descriptions
 * have French; the wireframe outline of each is small and carries no copy.
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
use Slate\Module\StudioBuilder\Presets\SectionPresetCatalog;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;

/** Blocks that on their own would be a bare primitive, not a composed section. */
const SBSP_PRIMITIVES = ['core.heading', 'core.text', 'core.button', 'core.image', 'core.rich_text', 'core.video'];

/** @return list<array<string, mixed>> flat list of every block in a document */
function sbsp_blocks(array $document): array
{
    $out = [];
    $walk = static function (array $blocks) use (&$walk, &$out): void {
        foreach ($blocks as $b) {
            $out[] = $b;
            $walk(is_array($b['children'] ?? null) ? $b['children'] : []);
        }
    };
    foreach ($document['sections'] as $s) {
        $walk($s['blocks']);
    }
    return $out;
}

unit('section presets: the catalogue has the planned categories and unique, reserved keys', function (): void {
    $all = SectionPresetCatalog::all();
    assert_true(count($all) >= 16, 'at least 16 composed presets');
    $keys = array_column($all, 'key');
    assert_eq(count($keys), count(array_unique($keys)), 'keys are unique');
    foreach ($all as $p) {
        assert_true(str_starts_with($p['key'], SectionPresetCatalog::KEY_PREFIX), "{$p['key']} is reserved");
        assert_true(preg_match(CanonicalDocumentSchema::TEMPLATE_KEY_PATTERN, $p['key']) === 1, "{$p['key']} is a valid template key");
        assert_true(in_array($p['category'], SectionPresetCatalog::CATEGORIES, true), "{$p['key']} category {$p['category']}");
        assert_true(trim($p['name']) !== '' && trim($p['description']) !== '', "{$p['key']} has copy");
        assert_true(mb_strlen($p['description']) <= 80, "{$p['key']} description stays one line");
    }
    $present = array_unique(array_column($all, 'category'));
    foreach (SectionPresetCatalog::CATEGORIES as $c) {
        assert_true(in_array($c, $present, true), "category {$c} has a preset");
    }
});

unit('section presets: every preset validates through the real pipeline and normalizes idempotently', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    foreach (SectionPresetCatalog::all() as $p) {
        $once  = ValidatedDocument::from($p['document'], $registry, [])->toArray();
        $twice = ValidatedDocument::from($once, $registry, [])->toArray();
        assert_eq(CanonicalJson::encode($once), CanonicalJson::encode($twice), "{$p['key']} normalizes idempotently");
        assert_eq('section_preset', $once['document_type']);
        assert_eq(1, count($once['sections']), "{$p['key']} holds exactly one section");
        assert_true(empty($once['sections'][0]['global_ref']), "{$p['key']} owns its content (no live reference)");
    }
});

unit('section presets: no preset is a bare primitive, and every block type is registered', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    foreach (SectionPresetCatalog::all() as $p) {
        $blocks = sbsp_blocks($p['document']);
        $top = $p['document']['sections'][0]['blocks'];
        assert_true(!(count($top) === 1 && $blocks[0]['children'] === [] && in_array($top[0]['type'], SBSP_PRIMITIVES, true)), "{$p['key']} is not a bare primitive");
        foreach ($blocks as $b) {
            assert_true($registry->get((string) $b['type']) !== null, "{$p['key']}: {$b['type']} is registered");
        }
        assert_true(count($blocks) >= 1, "{$p['key']} has content");
    }
});

unit('section presets: deterministic — rebuilding gives byte-identical documents with unique ids', function (): void {
    $first = [];
    foreach (SectionPresetCatalog::all() as $p) {
        $first[$p['key']] = CanonicalJson::encode($p['document']);
    }
    $ref = new \ReflectionProperty(SectionPresetCatalog::class, 'memo');
    $ref->setValue(null, null);
    foreach (SectionPresetCatalog::all() as $p) {
        assert_eq($first[$p['key']], CanonicalJson::encode($p['document']), "{$p['key']} is stable across rebuilds");
        $ids = array_map(static fn(array $b): string => (string) $b['id'], sbsp_blocks($p['document']));
        $ids[] = (string) $p['document']['sections'][0]['id'];
        assert_eq(count($ids), count(array_unique($ids)), "{$p['key']} ids are unique");
        foreach ($ids as $id) {
            assert_true(preg_match(CanonicalDocumentSchema::BLOCK_ID_PATTERN, $id) === 1 || preg_match(CanonicalDocumentSchema::SECTION_ID_PATTERN, $id) === 1, "{$p['key']} id {$id} matches the id pattern");
        }
    }
});

unit('section presets: every preset has a French name and description, and there are no stale French keys', function (): void {
    $fr = require SLATE_ROOT . '/plugins/studio-builder/lang/fr.php';
    $slugs = [];
    foreach (SectionPresetCatalog::all() as $p) {
        $slug = str_replace('-', '_', $p['slug']);
        $slugs[$slug] = true;
        foreach (['name', 'desc'] as $suffix) {
            assert_true(isset($fr["studio_preset_{$slug}_{$suffix}"]) && trim((string) $fr["studio_preset_{$slug}_{$suffix}"]) !== '', "studio_preset_{$slug}_{$suffix}");
        }
    }
    $stale = [];
    foreach (array_keys($fr) as $key) {
        if (preg_match('/^studio_preset_(.+)_(name|desc)$/', (string) $key, $m) === 1 && !isset($slugs[$m[1]])) {
            $stale[] = $key;
        }
    }
    assert_eq([], $stale);
});

unit('section presets: the wireframe outline is structure only, bounded, and the UI fixture matches it (no drift)', function (): void {
    $live = [];
    foreach (SectionPresetCatalog::all() as $p) {
        $outline = \Slate\Module\StudioBuilder\Presets\WireframeOutline::fromDocument($p['document']);
        $json = (string) json_encode($outline);
        foreach (['Your business', 'hello@example.com', 'customers', '/contact', 'href'] as $copy) {
            assert_true(!str_contains($json, $copy), "{$p['key']} outline must not carry copy ({$copy})");
        }
        $count = 0;
        $walk = static function (array $nodes) use (&$walk, &$count): void {
            foreach ($nodes as $n) {
                $count++;
                $walk($n['k'] ?? []);
            }
        };
        foreach ($outline as $s) {
            $walk($s['nodes']);
        }
        assert_true($count >= 1 && $count <= \Slate\Module\StudioBuilder\Presets\WireframeOutline::MAX_NODES, "{$p['key']} outline size {$count}");
        $live[$p['key']] = $outline;
    }
    $file = SLATE_ROOT . '/plugins/studio-builder/ui/tests/fixtures/preset-outlines.json';
    $fixture = json_decode((string) @file_get_contents($file), true);
    assert_true(is_array($fixture), 'ui/tests/fixtures/preset-outlines.json exists');
    assert_eq(json_decode((string) json_encode($live), true), $fixture, 'ui/tests/fixtures/preset-outlines.json is stale — regenerate it from the catalogue');
});
