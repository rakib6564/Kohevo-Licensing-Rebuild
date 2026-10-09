<?php
/**
 * Unit tests for the Element Manager's pure parts: per-site block availability (storage rules and the
 * insert guard) and the usage scan. No database: storage is injected as closures.
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
    $studioEmUnitStandalone = true;
}

use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Registry\BlockAvailability;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Registry\BlockUsage;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;

function sbem_store(?string &$value): BlockAvailability
{
    return new BlockAvailability(
        static function (int $tenantId) use (&$value): mixed { return $value; },
        static function (int $tenantId, string $json) use (&$value): void { $value = $json; },
    );
}

unit('element manager: nothing is disabled until a site saves a list, and a corrupt value reads as nothing', function (): void {
    $v = null;
    assert_eq([], sbem_store($v)->disabled(1));
    foreach (['', 'not json', '"x"', '{"a":1}', '123'] as $bad) {
        $v = $bad;
        assert_eq([], sbem_store($v)->disabled(1), "corrupt value {$bad}");
    }
    $v = '["core.hero","nope","core.hero","<b>x</b>","layout.flex"]';
    assert_eq(['core.hero', 'layout.flex'], sbem_store($v)->disabled(1), 'junk dropped, duplicates merged, sorted');
});

unit('element manager: saving accepts registered types only, stores a sorted unique list', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $types = array_slice(array_keys($registry->all()), 0, 3);
    assert_true(count($types) === 3, 'the registry has blocks');
    $v = null;
    $store = sbem_store($v);
    $saved = $store->save(1, [$types[2], $types[0], $types[2]], $registry);
    $expected = [$types[0], $types[2]];
    sort($expected, SORT_STRING);
    assert_eq($expected, $saved);
    assert_eq($expected, json_decode((string) $v, true));
    assert_eq($expected, $store->disabled(1));
    assert_eq([], $store->save(1, [], $registry), 'an empty list switches everything back on');

    foreach ([['core.nope'], [42], [['x']], ['core.hero' => true]] as $bad) {
        assert_throws(StudioValidationException::class, static fn () => $store->save(1, $bad, $registry), 'refused: ' . json_encode($bad));
    }
    assert_throws(StudioValidationException::class, static fn () => $store->save(1, array_fill(0, BlockAvailability::MAX_TYPES + 1, $types[0]), $registry), 'too long');
});

unit('element manager: only inserting a switched-off block is blocked, including inside a preset subtree', function (): void {
    $insert = static fn (array $block): DocumentOperation => new DocumentOperation(DocumentOperation::OP_INSERT_BLOCK, ['parent_id' => 'sec_a', 'index' => 0, 'block' => $block]);
    $disabled = ['core.countdown', 'layout.flex'];
    assert_eq([], BlockAvailability::blockedBy([$insert(['type' => 'core.countdown'])], []), 'nothing disabled, nothing blocked');
    assert_eq(['core.countdown'], BlockAvailability::blockedBy([$insert(['type' => 'core.countdown'])], $disabled));
    assert_eq([], BlockAvailability::blockedBy([$insert(['type' => 'core.heading'])], $disabled));
    $nested = $insert(['type' => 'layout.grid', 'children' => [['type' => 'layout.flex', 'children' => [['type' => 'core.countdown']]]]]);
    assert_eq(['core.countdown', 'layout.flex'], BlockAvailability::blockedBy([$nested], $disabled), 'a preset carrying disabled types is refused');
    $edit = new DocumentOperation(DocumentOperation::OP_UPDATE_BLOCK_PROPS, ['block_id' => 'blk_x', 'props' => []]);
    assert_eq([], BlockAvailability::blockedBy([$edit], $disabled), 'editing an existing block is never blocked');
});

unit('element manager: the usage scan counts blocks and pages, nested blocks included', function (): void {
    $doc = static fn (array $sections): array => ['sections' => $sections];
    $pages = [
        ['title' => 'Home', 'document' => $doc([
            ['blocks' => [['type' => 'core.heading'], ['type' => 'layout.flex', 'children' => [['type' => 'core.heading'], ['type' => 'core.button']]]]],
            ['blocks' => [['type' => 'core.heading']]],
        ])],
        ['title' => 'About', 'document' => $doc([['blocks' => [['type' => 'core.button']]]])],
        ['title' => 'Empty', 'document' => $doc([])],
        ['title' => 'Broken', 'document' => ['sections' => 'nope']],
    ];
    $usage = BlockUsage::count($pages);
    assert_eq(3, $usage['core.heading']['blocks']);
    assert_eq(1, $usage['core.heading']['pages']);
    assert_eq(2, $usage['core.button']['blocks']);
    assert_eq(2, $usage['core.button']['pages']);
    assert_eq(['Home', 'About'], $usage['core.button']['sample']);
    assert_eq(1, $usage['layout.flex']['blocks']);
    assert_true(!isset($usage['core.hero']), 'unused types are absent');
    assert_eq(array_keys($usage), ['core.button', 'core.heading', 'layout.flex'], 'sorted by type');

    $many = [];
    for ($i = 0; $i < 9; $i++) {
        $many[] = ['title' => "P{$i}", 'document' => $doc([['blocks' => [['type' => 'core.text']]]])];
    }
    $u = BlockUsage::count($many);
    assert_eq(9, $u['core.text']['pages']);
    assert_eq(BlockUsage::SAMPLE_PAGES, count($u['core.text']['sample']), 'the sample stays small');
});

if (!empty($studioEmUnitStandalone)) {
    exit(unit_summary());
}
