<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the builder's own image picker, transport helpers.
 * Autoloader only, no database: query reduction and record shaping are pure.
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

use Slate\Module\StudioBuilder\Http\StudioMediaApi;

unit('media api: a query is reduced to a bounded search, a page and a short list of positive ids', function (): void {
    $q = StudioMediaApi::listQuery(['q' => '  hero  ', 'page' => '3', 'ids' => '5, 7,5,abc,-2,0,9']);
    assert_eq('hero', $q['search']);
    assert_eq(3, $q['page']);
    assert_eq([5, 7, 9], $q['ids']);

    $bad = StudioMediaApi::listQuery(['q' => ['x'], 'page' => '-4', 'ids' => ['1']]);
    assert_eq('', $bad['search']);
    assert_eq(1, $bad['page']);
    assert_eq([], $bad['ids']);

    assert_eq(StudioMediaApi::MAX_SEARCH, mb_strlen(StudioMediaApi::listQuery(['q' => str_repeat('a', 500)])['search']));
    $many = StudioMediaApi::listQuery(['ids' => implode(',', range(1, 100))]);
    assert_eq(StudioMediaApi::MAX_IDS, count($many['ids']));
    assert_eq(1, StudioMediaApi::listQuery(['page' => '99999999'])['page']);
});

unit('media api: only usable images are shaped, with just the fields the editor needs', function (): void {
    $row = ['id' => 7, 'url' => '/uploads/media/2026/10/a.png', 'kind' => 'image', 'mime' => 'image/png', 'size_bytes' => 1234, 'width' => 800, 'height' => 0,
        'original_name' => 'a.png', 'alt_text' => 'An A', 'path' => 'uploads/media/2026/10/a.png', 'folder' => 'secret', 'usage_count' => 3];
    $item = StudioMediaApi::shapeItem($row);
    assert_eq(['id', 'url', 'original_name', 'alt_text', 'mime', 'size_bytes', 'width', 'height'], array_keys($item));
    assert_eq(800, $item['width']);
    assert_null($item['height'], 'a zero dimension is unknown, not 0');

    assert_null(StudioMediaApi::shapeItem(array_merge($row, ['kind' => 'document'])), 'only images');
    assert_null(StudioMediaApi::shapeItem(['url' => '/x.png', 'kind' => 'image']), 'no id');
    foreach (['javascript:alert(1)', 'data:image/png;base64,AAAA', '//evil.test/x.png', '', 'ftp://x/y.png'] as $url) {
        assert_null(StudioMediaApi::shapeItem(['id' => 1, 'kind' => 'image', 'url' => $url]), 'refuses url: ' . $url);
    }
    assert_true(StudioMediaApi::shapeItem(['id' => 1, 'kind' => 'image', 'url' => 'https://cdn.example.com/a.webp']) !== null);

    $list = StudioMediaApi::shapeList(['items' => [$row, ['id' => 2, 'kind' => 'document', 'url' => '/d.pdf'], 'junk'], 'total' => 3, 'page' => 0, 'pages' => 0]);
    assert_eq(1, count($list['items']));
    assert_eq(3, $list['total']);
    assert_eq(1, $list['page']);
    assert_eq(1, $list['pages']);
});

unit('media api: the endpoint is gated like the authoring API and cannot be reached around it', function (): void {
    $src = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/media-api.php');
    foreach (['Auth::check()', "EntitlementService::canAccess(current_tenant_id(), 'studio-builder')", 'StudioPermissions::EDIT', 'csrf_verify()', '->hit(', 'Media::imageMimes()', 'Media::IMAGE_EXTS', 'MAX_UPLOAD_BYTES'] as $needle) {
        assert_true(str_contains($src, $needle), 'media-api.php must contain ' . $needle);
    }
    assert_true(strpos($src, 'csrf_verify()') < strrpos($src, 'Media::upload('), 'CSRF is checked before anything is stored');
    assert_true(strpos($src, 'StudioPermissions::EDIT') < strrpos($src, 'Media::listAll('), 'permission is checked before listing');
});
