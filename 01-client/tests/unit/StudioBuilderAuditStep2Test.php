<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the draft/live boundary (2026-10-26 builder audit, step 2).
 *
 * Autoloader only. Whole-document writes keep locked layers; the live payment button is found by its id in a published
 * document; template-ness can be judged from published settings; the visitor preview runs only the platform's own
 * scripts; menus and live page changes need the publish permission.
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

use Slate\Module\StudioBuilder\Document\LayerLock;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Http\StudioPreviewPolicy;
use Slate\Module\StudioBuilder\Payment\LivePaymentBlocks;
use Slate\Module\StudioBuilder\Runtime\StudioPublicRuntime;

/** A small document: section s1 (block b1 with child b2), section s2. */
function sba2_doc(array $over = []): array
{
    $doc = ['containers' => [
        \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'One'], 'id' => 's1', 'children' => [
            ['id' => 'b1', 'type' => 'core.container', 'props' => ['gap' => 'md'], 'children' => [
                ['id' => 'b2', 'type' => 'core.heading', 'props' => ['text' => 'Hi', 'level' => 'h2'], 'children' => []],
            ]],
        ]]),
        \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'Two'], 'id' => 's2', 'children' => []]),
    ]];
    return array_replace_recursive($doc, $over);
}

unit('layer lock: a whole-document write cannot drop or edit a locked layer, but can undo the lock itself', function (): void {
    $current = sba2_doc();
    $current['containers'][0]['locked'] = true;

    $dropped = $current;
    array_shift($dropped['containers']);
    assert_throws(StudioValidationException::class, static fn() => LayerLock::assertPreserved($current, $dropped), 'removing a locked section');

    $edited = $current;
    $edited['containers'][0]['children'][0]['children'][0]['props']['text'] = 'Changed';
    assert_throws(StudioValidationException::class, static fn() => LayerLock::assertPreserved($current, $edited), 'editing a block inside it');

    $filled = $current;
    $filled['containers'][0]['children'][] = ['id' => 'b9', 'type' => 'core.heading', 'props' => [], 'children' => []];
    assert_throws(StudioValidationException::class, static fn() => LayerLock::assertPreserved($current, $filled), 'inserting into it');

    $unlocked = $current;
    unset($unlocked['containers'][0]['locked']);
    LayerLock::assertPreserved($current, $unlocked); // undoing the lock (or an unlock) is the one change it accepts

    $other = $current;
    $other['containers'][1]['label'] = 'Renamed';
    $other['containers'][] = \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'New'], 'id' => 's3', 'children' => []]);
    LayerLock::assertPreserved($current, $other); // unlocked layers change freely

    $reordered = $current;
    $reordered['containers'][0] = array_reverse($current['containers'][0], true);
    LayerLock::assertPreserved($current, $reordered); // key order is not a change

    LayerLock::assertPreserved(sba2_doc(), ['containers' => []]); // no lock, nothing to keep
    assert_true(true);
});

unit('layer lock: a locked block (metadata.locked) is kept the same way, children included', function (): void {
    $current = sba2_doc();
    $current['containers'][0]['children'][0]['metadata'] = ['locked' => true];
    $edited = $current;
    $edited['containers'][0]['children'][0]['children'] = [];
    assert_throws(StudioValidationException::class, static fn() => LayerLock::assertPreserved($current, $edited), 'emptying a locked container');
    $unlocked = $current;
    unset($unlocked['containers'][0]['children'][0]['metadata']);
    LayerLock::assertPreserved($current, $unlocked);
    assert_true(true);
});

unit('payments: the live button is found by its id anywhere in a document, and only a payment button counts', function (): void {
    $doc = sba2_doc();
    $doc['containers'][1]['children'][] = ['id' => 'blk_pay', 'type' => 'payment.button', 'props' => ['amount' => 10, 'currency' => 'USD'], 'children' => []];
    $doc['containers'][0]['children'][0]['children'][] = ['id' => 'blk_nested', 'type' => 'payment.button', 'props' => ['amount' => 20], 'children' => []];
    assert_eq(['amount' => 10, 'currency' => 'USD'], LivePaymentBlocks::findInDocument($doc, 'blk_pay'));
    assert_eq(['amount' => 20], LivePaymentBlocks::findInDocument($doc, 'blk_nested'), 'nested in a container');
    assert_null(LivePaymentBlocks::findInDocument($doc, 'b2'), 'a heading with that id is not a payment button');
    assert_null(LivePaymentBlocks::findInDocument($doc, 'blk_missing'));
    assert_null(LivePaymentBlocks::propsOf(1, 'bad id"'), 'an id that is not a plain id is never looked up');
});

unit('theme templates: whether an address is a template can be judged from the published settings, not the draft', function (): void {
    $row = ['slug' => 'about', 'settings_json' => '{"template_type":"single"}'];
    assert_true(StudioPublicRuntime::isThemeTemplate($row), 'the row alone mirrors the draft');
    assert_false(StudioPublicRuntime::isThemeTemplate($row, []), 'published settings without a template type: a page');
    assert_true(StudioPublicRuntime::isThemeTemplate(['settings_json' => '{}'], ['template_type' => 'single']), 'published as a template');
    $runtime = (string) file_get_contents(SLATE_ROOT . '/src/Module/StudioBuilder/Runtime/StudioPublicRuntime.php');
    assert_true(str_contains($runtime, 'self::isThemeTemplate($row, PublishedPageData::settingsOf($this->tenants->id(), $row))'), 'the public lookup passes the published settings');
    foreach (['Provider/PostsProvider.php', 'Provider/TaxonomyProvider.php'] as $file) {
        $src = (string) file_get_contents(SLATE_ROOT . '/src/Module/StudioBuilder/' . $file);
        assert_true(str_contains($src, 'PublishedPageData::forRows('), "{$file} reads the published settings");
        assert_true(str_contains($src, "slug NOT LIKE 'template-%'"), "{$file} skips theme template pages");
    }
    $posts = (string) file_get_contents(SLATE_ROOT . '/src/Module/StudioBuilder/Provider/PostsProvider.php');
    assert_true(str_contains($posts, "LIMIT ' . self::BATCH . ' OFFSET '"), 'posts are read in bounded batches');
});

unit('visitor preview: only the platform\'s own script files may run in the preview frame', function (): void {
    assert_true(str_contains(StudioPreviewPolicy::CONTENT_SECURITY_POLICY, "script-src 'self'"));
    assert_true(str_contains(StudioPreviewPolicy::CONTENT_SECURITY_POLICY, "frame-ancestors 'self'"));
    assert_false(str_contains(StudioPreviewPolicy::CONTENT_SECURITY_POLICY, 'unsafe-inline'), 'no inline script');
    $preview = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/preview.php');
    assert_true(str_contains($preview, 'StudioPreviewPolicy::headers($result)'), 'preview.php sends it');
});

unit('live changes: menus and a published page\'s address, archive and custom fields need the publish permission', function (): void {
    foreach (['menus.php', 'menu-new.php', 'menu-edit.php'] as $file) {
        $src = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/' . $file);
        assert_true(str_contains($src, "\$canEdit    = Auth::can('studio-builder.publish') || Auth::isSuperAdmin();"), "{$file} needs publish");
    }
    $app = (string) file_get_contents(SLATE_ROOT . '/src/Module/StudioBuilder/Application/StudioApplicationService.php');
    assert_eq(2, substr_count($app, '$this->authorizeLiveChange($actor, $current);'), 'archive and readdress check it');
    assert_true(str_contains($app, 'if ($actor->isAiOrigin()) {'), 'an AI origin is refused');
    $edit = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/page-edit.php');
    assert_true(str_contains($edit, "\$action === 'save_meta' && \$hasMeta && !\$studioApp->canChangeLive(\$studioActor, \$page)"), 'custom fields of a published page');
    $catalog = (string) file_get_contents(SLATE_ROOT . '/src/Module/StudioBuilder/Mcp/StudioMcpToolCatalog.php');
    assert_true(str_contains($catalog, 'A published page is refused'), 'the MCP tool says so');
});
