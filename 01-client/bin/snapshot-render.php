<?php
/**
 * Kohevo Studio — golden render snapshot.
 *
 * Compiles every system section preset, every element variant and a style-rich
 * fixture through the real compiler and writes `<name>.html` / `<name>.css`
 * into a directory. Run it on two revisions and `diff -r` the directories to
 * prove a change leaves valid documents byte-identical (or shows exactly what
 * moved). Offline: no database.
 *
 * Usage:  php bin/snapshot-render.php <out-dir>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$out = $argv[1] ?? '';
if ($out === '') { fwrite(STDERR, "Usage: php bin/snapshot-render.php <out-dir>\n"); exit(1); }
if (!is_dir($out) && !mkdir($out, 0775, true)) { fwrite(STDERR, "cannot create $out\n"); exit(1); }

define('SLATE_TESTING', true);
define('SLATE_ROOT', dirname(__DIR__));
require_once SLATE_ROOT . '/src/autoload.php';

use Slate\Module\StudioBuilder\Document\{CanonicalDocumentSchema, CanonicalJson};
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Presets\{ElementVariantCatalog, SectionPresetCatalog};
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompiler;
use Slate\Module\StudioBuilder\Render\Media\{MediaResolverInterface, ResolvedMedia};
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Render\{DocumentRenderer, ProviderBindingResolver, RenderContext, SiteContext};
use Slate\Tenancy\TenantContext;

final class SnapMedia implements MediaResolverInterface { public function resolveImage(int $id): ?ResolvedMedia { return null; } }

$tenants = new TenantContext();
$reg     = ModuleBlockDefinitions::studioRegistry();
$rr      = BlockRendererRegistry::withStudioRenderers();
$media   = new SnapMedia();
$docr    = new DocumentRenderer($reg, $rr, $media, new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
$comp    = new StudioCompiler($tenants, $reg, $rr, $docr, new ThemeResolver(null, static fn() => []), new ChromeResolver(), $media);
$page    = new PageAddress(5, '11111111-2222-4333-8444-555555555555', 'T', 't', 'page', 'published', 'standalone', 9, 9);
$ctx     = RenderContext::forPublic(101, new SiteContext('https://a.test', 'A', 'https://a.test/l.png', 'https://a.test/f.png', 'en'), static fn() => true);

$snap = static function (string $name, array $doc) use ($comp, $tenants, $page, $ctx, $out): void {
    $json = CanonicalJson::encode($doc);
    $res  = $tenants->runAs(101, static fn() => $comp->compile($page, ['id' => 9, 'page_id' => 5, 'document_json' => $json], $ctx));
    $safe = preg_replace('/[^a-z0-9._-]+/i', '_', $name);
    // Form-field ids carry a per-render random suffix; normalise it so two runs of the same code are byte-identical.
    $html = (string) preg_replace('/(sb_f_[a-z0-9_]+?)_[0-9a-f]{8}\b/', '$1_X', (string) $res->html);
    file_put_contents("$out/$safe.html", $html);
    file_put_contents("$out/$safe.css", (string) $res->css);
};

$wrap = static function (array $blocks): array {
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'T');
    $doc['sections'] = [[
        'blocks' => $blocks, 'global_ref' => null, 'id' => 'sec_' . str_repeat('a', 24), 'label' => 'S',
        'layout' => CanonicalDocumentSchema::defaultSectionLayout(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ]];
    return $doc;
};

foreach (SectionPresetCatalog::all() as $preset) {
    $snap('preset-' . $preset['slug'], $preset['document']);
}

$n = 0;
$block = static function (string $type, array $props, array $style, array $children = []) use (&$n): array {
    $n++;
    return [
        'bindings' => [], 'children' => $children, 'id' => 'blk_' . str_pad((string) $n, 24, '0', STR_PAD_LEFT), 'props' => $props,
        'style' => $style + CanonicalDocumentSchema::defaultBlockStyle(), 'type' => $type, 'version' => 1,
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ];
};

foreach (ElementVariantCatalog::all() as $variant) {
    $snap('variant-' . ($variant['key'] ?? $variant['slug'] ?? md5(json_encode($variant))), $wrap([
        $block((string) $variant['type'], (array) ($variant['props'] ?? []), (array) ($variant['style'] ?? [])),
    ]));
}

// Every free-form style value the inspector writes, on one heading.
$rich = $block('core.heading', ['text' => 'Styled', 'level' => 'h2'], [
    'typography' => ['size' => '1.5rem', 'line_height' => '1.5', 'letter_spacing' => '-0.01em', 'font_family' => 'Inter, sans-serif', 'color' => '#e8734a', 'weight' => '600', 'transform' => 'uppercase'],
    'background' => ['color' => '#0b0c0f', 'gradient' => 'linear-gradient(135deg, #e8734a, #8a3d24)'],
    'border'     => ['style' => 'solid', 'width' => '2px', 'color' => '#2b3358', 'radius' => '1.25rem'],
    'shadow'     => '0 10px 25px rgba(0,0,0,.15)',
    'dimensions' => ['width' => '100%', 'min_height' => '20rem', 'max_width' => '60rem'],
    'opacity'    => 0.9,
    'z_index'    => 10,
]);
$snap('style-rich', $wrap([$rich]));

echo "snapshot written to $out (" . count(glob("$out/*.html")) . " pages)\n";
