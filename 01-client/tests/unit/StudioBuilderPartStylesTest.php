<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — styling the parts of a widget (BlockParts, `block.style_parts`).
 *
 * Autoloader only, no database. Reuses sbdc_block / sbdc_doc / sbdc_render from StudioBuilderDeviceColumnsTest (loaded
 * first, alphabetically).
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Document\StyleSurface;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Operation\DocumentOperationApplier;
use Slate\Module\StudioBuilder\Registry\BlockParts;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\RenderCollector;

function sbps_tabs(array $parts = []): array
{
    $b = sbdc_block('core.tabs', ['items' => [['label' => 'One', 'content' => 'A'], ['label' => 'Two', 'content' => 'B']]]);
    return $parts !== [] ? $b + ['style_parts' => $parts] : $b;
}

unit('part styles: a part of the block\'s type takes a closed partial style, alone or on hover', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $valid = static fn (array $b): bool => DocumentValidator::validate(sbdc_doc($b), $registry)->isValid();
    assert_true($valid(sbps_tabs([
        'tab'        => ['color' => 'text.muted', 'typography' => ['size' => '15px', 'weight' => 'semibold', 'transform' => 'uppercase', 'letter_spacing' => '-0.01em'], 'padding' => ['top' => '8px', 'bottom' => '8px']],
        'tab:hover'  => ['color' => '#111827', 'background' => ['color' => '#f3f4f6']],
        'tab_active' => ['border' => ['width' => '2px', 'style' => 'solid', 'color' => 'color.accent', 'radius' => '6px'], 'shadow' => ['x' => '0px', 'y' => '2px', 'blur' => '6px', 'color' => '#00000022']],
    ])), 'a full set of tab parts');
    foreach ([
        'a part another widget has' => ['number' => ['color' => 'red']],
        'an unknown state'          => ['tab:focus' => ['color' => 'red']],
        'a group parts cannot set'  => ['tab' => ['text_shadow' => ['x' => '1px', 'y' => '1px', 'color' => '#000000']]],
        'a CSS injection'           => ['tab' => ['color' => 'red;}body{display:none']],
        'an unknown weight'         => ['tab' => ['typography' => ['weight' => '950']]],
        'a part as a list'          => ['tab' => ['red']],
        'parts as a list'           => [['color' => 'red']],
    ] as $why => $parts) {
        assert_false($valid(sbps_tabs($parts)), "refused: {$why}");
    }
    $norm = DocumentNormalizer::normalize(sbdc_doc(sbps_tabs(['tab' => ['color' => '#000000'], 'panel' => []])), $registry);
    assert_eq(['tab' => ['color' => '#000000']], $norm['sections'][0]['blocks'][0]['style_parts'], 'an empty part is dropped');
});

unit('part styles: the rules sit under the block\'s own class, important, with hover after the base', function (): void {
    $rules = StyleSurface::partRules([
        'tab:hover'  => ['color' => '#111827'],
        'tab'        => ['color' => 'text.muted', 'typography' => ['weight' => 'semibold', 'size' => '15px']],
        'tab_active' => ['border' => ['color' => 'color.accent', 'width' => '2px']],
    ], 'core.tabs');
    assert_eq([
        ['selectors' => ['.sb-tabs__tab'], 'declarations' => 'color:var(--sb-text-muted) !important;font-size:15px !important;font-weight:600 !important'],
        ['selectors' => ['.sb-tabs__tab[aria-selected="true"]'], 'declarations' => 'border-width:2px !important;border-color:var(--sb-color-accent) !important'],
        ['selectors' => ['.sb-tabs__tab:hover'], 'declarations' => 'color:#111827 !important'],
    ], $rules);

    $c = new RenderCollector();
    $class = $c->scopedRule('blk_aaaaaaaaaaaaaaaa', '', false, [], [], StyleSurface::partRules(['title' => ['color' => '#123456']], 'core.query_loop'));
    assert_true(str_contains($c->css(), '.' . $class . ' .sb-post-card__title,.' . $class . ' .sb-post-card__title a{color:#123456 !important}'), $c->css());

    $html = sbdc_render(sbps_tabs(['tab' => ['color' => '#ff0000']]));
    assert_true((bool) preg_match('/<div class="sb-block sb-block--core-tabs [^"]*\bsb-x-[0-9a-f]{16}\b/', $html), 'the block carries its scoped class: ' . substr($html, 0, 300));
    assert_true(str_contains($html, 'class="sb-tabs__tab'), 'and the part it styles is inside it');
});

unit('part styles: the operation sets and clears them', function (): void {
    $doc = sbdc_doc(sbps_tabs());
    $id = $doc['sections'][0]['blocks'][0]['id'];
    $set = DocumentOperationApplier::applyOne($doc, new DocumentOperation(DocumentOperation::OP_UPDATE_BLOCK_STYLE_PARTS, ['block_id' => $id, 'style_parts' => ['panel' => ['padding' => ['top' => '24px']]]]), BlockRegistry::withAllCoreBlocks());
    assert_eq(['panel' => ['padding' => ['top' => '24px']]], $set['sections'][0]['blocks'][0]['style_parts']);
    $cleared = DocumentOperationApplier::applyOne($set, new DocumentOperation(DocumentOperation::OP_UPDATE_BLOCK_STYLE_PARTS, ['block_id' => $id, 'style_parts' => []]), BlockRegistry::withAllCoreBlocks());
    assert_false(array_key_exists('style_parts', $cleared['sections'][0]['blocks'][0]), 'an empty map removes the key');
});

unit('part styles: every selector names a class the widget\'s renderers still write, and the editor manifest lists the parts', function (): void {
    $sources = '';
    foreach (glob(dirname(__DIR__, 2) . '/src/Module/StudioBuilder/Render/Block/{Core,Module}Renderers/*.php', GLOB_BRACE) ?: [] as $file) {
        $sources .= (string) file_get_contents($file);
    }
    $sources .= (string) file_get_contents(dirname(__DIR__, 2) . '/src/Module/StudioBuilder/Render/Html.php');
    $manifests = [];
    foreach (ModuleBlockDefinitions::studioRegistry()->editorManifests() as $m) {
        $manifests[$m['type']] = $m;
    }
    foreach (BlockParts::types() as $type) {
        assert_true(isset($manifests[$type]), "{$type} is a block");
        assert_eq(array_keys(BlockParts::for($type)), array_column($manifests[$type]['style_parts'], 'key'), "{$type} parts reach the editor");
        foreach (BlockParts::for($type) as $part => $def) {
            foreach ($def['selectors'] as $selector) {
                preg_match_all('/\.(sb-[a-z0-9_-]+)/', $selector, $m);
                foreach ($m[1] as $cls) {
                    assert_true(str_contains($sources, $cls), "{$type}.{$part}: no renderer writes .{$cls}");
                }
            }
        }
    }
    assert_eq([], $manifests['core.text']['style_parts'], 'a widget without parts lists none');
});
