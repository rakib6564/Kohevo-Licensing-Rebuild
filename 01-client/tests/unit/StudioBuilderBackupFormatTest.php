<?php
/**
 * Unit tests for the Studio backup file: its shape checks and where files are kept.
 */

declare(strict_types=1);

if (!function_exists('unit')) {
    define('SLATE_TESTING', true);
    require_once dirname(__DIR__, 2) . '/src/autoload.php';
    require_once __DIR__ . '/harness.php';
    $studioBackupStandalone = true;
}

use Slate\Module\StudioBuilder\Backup\StudioBackupFormat as Format;
use Slate\Module\StudioBuilder\Backup\StudioBackupStore;

function sbbk_page(array $changes = []): array
{
    return $changes + [
        'uuid' => '11111111-2222-4333-8444-555555555555', 'title' => 'Home', 'slug' => 'home', 'page_type' => 'page',
        'status' => 'published', 'route_mode' => 'homepage', 'working' => ['sections' => []], 'published' => null,
    ];
}

unit('studio backup: a manifest is accepted only in the current format', function (): void {
    $ok = ['format' => Format::FORMAT, 'version' => Format::VERSION, 'counts' => ['pages' => 3]];
    assert_true(Format::manifestProblems($ok) === []);
    assert_true(Format::manifestProblems(['format' => 'zip']) !== [], 'another format');
    assert_true(Format::manifestProblems('text') !== [], 'not an object');
    assert_true(Format::manifestProblems(['version' => '2.0'] + $ok) !== [], 'a newer version');
    assert_true(Format::manifestProblems(['counts' => ['pages' => -1]] + $ok) !== [], 'a negative count');
    assert_true(Format::manifestProblems(['counts' => ['pages' => Format::MAX_PAGES + 1]] + $ok) !== [], 'a count over the cap');
});

unit('studio backup: a page record must be complete and of a known type', function (): void {
    assert_true(Format::pageProblem(sbbk_page()) === null);
    assert_true(Format::pageProblem(sbbk_page(['published' => ['sections' => []]])) === null, 'with a published document');
    assert_true(Format::pageProblem(sbbk_page(['uuid' => '../../evil'])) !== null, 'a uuid that is not one');
    assert_true(Format::pageProblem(sbbk_page(['page_type' => 'user'])) !== null, 'an unknown type');
    assert_true(Format::pageProblem(sbbk_page(['working' => 'x'])) !== null, 'no working document');
    assert_true(Format::pageProblem(sbbk_page(['published' => 'x'])) !== null, 'a published document of the wrong shape');
    assert_true(Format::pageProblem(null) !== null);
});

unit('studio backup: only site content is in the settings allowlist', function (): void {
    foreach (Format::SETTING_KEYS as $key) {
        assert_true(str_starts_with($key, 'studio_'), $key);
        assert_false((bool) preg_match('/key|secret|token|password/i', $key), "{$key} looks like a credential");
    }
    assert_true(in_array('studio_menus', Format::SETTING_KEYS, true));
});

unit('studio backup: the store only ever opens names it made, and keeps each kind to its limit', function (): void {
    $root = sys_get_temp_dir() . '/sbbk-' . bin2hex(random_bytes(4));
    try {
        $store = new StudioBackupStore(7, $root);
        $path = $store->newPath(StudioBackupStore::KIND_MANUAL);
        file_put_contents($path, 'x');
        assert_true(str_contains($path, '/studio/7/'), 'one folder per tenant');
        assert_true(is_file($root . '/.htaccess') || is_file(dirname($path, 3) . '/.htaccess'), 'denied to the web');
        assert_true($store->path(basename($path)) === $path);
        foreach (['../../config.php', 'x.zip', basename($path) . '/..', '', 'studio-manual-1.zip'] as $bad) {
            assert_true($store->path($bad) === null, "refused: {$bad}");
        }
        assert_true((new StudioBackupStore(8, $root))->path(basename($path)) === null, 'another tenant cannot reach it');

        for ($i = 0; $i < StudioBackupStore::KEEP[StudioBackupStore::KIND_UPLOAD] + 2; $i++) {
            $p = $store->newPath(StudioBackupStore::KIND_UPLOAD);
            file_put_contents($p, 'x');
            touch($p, time() - 100 + $i);
        }
        assert_eq(2, count($store->prune(StudioBackupStore::KIND_UPLOAD)));
        $uploads = array_filter($store->all(), static fn(array $b): bool => $b['kind'] === StudioBackupStore::KIND_UPLOAD);
        assert_eq(StudioBackupStore::KEEP[StudioBackupStore::KIND_UPLOAD], count($uploads));
        assert_true($store->path(basename($path)) === $path, 'another kind is untouched');
        assert_true($store->delete(basename($path)));
        assert_false($store->delete('../../config.php'));
    } finally {
        exec('rm -rf ' . escapeshellarg($root));
    }
});

unit('studio backup: a media file sits under files/ in the zip', function (): void {
    assert_eq('files/uploads/media/a.jpg', Format::fileEntry('/uploads/media/a.jpg'));
    assert_eq('pages/0012.json', Format::pageEntry(12));
    assert_true(Format::mediaProblem(['id' => 1, 'path' => '/uploads/media/a.jpg']) === null);
    assert_true(Format::mediaProblem(['id' => 0, 'path' => 'x']) !== null);
});

if (!empty($studioBackupStandalone)) {
    exit(unit_summary());
}
