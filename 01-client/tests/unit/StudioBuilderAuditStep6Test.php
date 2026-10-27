<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — remaining backend findings (2026-10-26 builder audit, step 6).
 *
 * Autoloader only. Packages carry section and block background images.
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
use Slate\Module\StudioBuilder\Package\PackageDocumentMapper;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;

/** @return array<string, mixed> */
function sba6_background_document(): array
{
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Unit');
    $style = CanonicalDocumentSchema::defaultBlockStyle();
    $style['background'] = ['color' => '#112233', 'image' => ['media_id' => 9]];
    $doc['sections'] = [[
        'id' => 'sec_' . str_repeat('a', 24), 'label' => 'Hero', 'global_ref' => null,
        'style' => ['background' => ['image' => ['media_id' => 7], 'fit' => 'cover']],
        'blocks' => [[
            'id' => 'blk_' . str_repeat('b', 24), 'type' => 'core.heading', 'version' => 1, 'props' => ['text' => 'Hi', 'level' => 2],
            'style' => $style, 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
        ]],
    ]];
    return $doc;
}

unit('packages: section and block background images are exported, mapped on import, and removed when unavailable', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $doc = sba6_background_document();
    assert_eq([7, 9], PackageDocumentMapper::mediaIds($doc, $registry), 'background images are collected');

    $mapped = PackageDocumentMapper::rewriteMedia($doc, $registry, static fn(int $id): ?int => $id + 100);
    assert_eq(107, $mapped['document']['sections'][0]['style']['background']['image']['media_id']);
    assert_eq(109, $mapped['document']['sections'][0]['blocks'][0]['style']['background']['image']['media_id']);
    assert_eq([], $mapped['unresolved']);

    $none = PackageDocumentMapper::rewriteMedia($doc, $registry, static fn(int $id): ?int => null);
    $section = $none['document']['sections'][0];
    assert_true(!isset($section['style']['background']['image']), 'an unmapped section image is removed, never pointing at an unrelated image');
    assert_eq('cover', $section['style']['background']['fit']);
    assert_true(!isset($section['blocks'][0]['style']['background']['image']));
    assert_eq('#112233', $section['blocks'][0]['style']['background']['color'], 'the rest of the background is kept');
    assert_eq(['$.sections[0].style.background.image', '$.sections[0].blocks[0].style.background.image'], array_column($none['unresolved'], 'path'));
});
