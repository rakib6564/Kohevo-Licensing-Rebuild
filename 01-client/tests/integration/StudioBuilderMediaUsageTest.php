<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — the media a page uses (audit H5, 2026-10-26).
 *
 * Real MySQL. The media library refuses to delete a file that a non-archived Studio page's current draft or
 * published revision uses (Studio answers the `media_usage` filter from its dependency rows). A page whose image
 * was deleted before that guard existed stays editable: a reference the page already had passes, while a NEW
 * reference to a missing or another tenant's file is still refused.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!function_exists('unit')) {
    require_once dirname(__DIR__, 2) . '/config.php';
    require_once dirname(__DIR__) . '/guard.php';
    slate_require_test_database();
    require_once dirname(__DIR__) . '/unit/harness.php';
    $studioMediaUsageStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioApiResponse;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const SBMU_A = 111;
const SBMU_B = 222;

function sbmu_pdo(string $dbName, bool $create): ?\PDO
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $server = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '');
    $root = new \PDO($server . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    if (!$create) {
        return null;
    }
    $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4");
    $pdo = new \PDO($server . ";dbname={$dbName};charset=" . DB_CHARSET, DB_USER, DB_PASS, [
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate([
        '0001_core_init', '0002_identity_core', '0011_login_attempts', '0014_tenant_profiles', '0023_installation_identity',
        '0022_remote_license_cache', '0024_remote_license_metadata', '0025_remote_license_cache_installation_id', '0026_remote_license_cache_signed_payload',
    ]);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

/** Run $fn against $pdo with a fresh plugin loader and a test licence server; everything is put back afterwards. */
function sbmu_with_pdo(\PDO $pdo, callable $fn): void
{
    $statics = [
        [Database::class, 'pdo', $pdo],
        [\PluginLoader::class, 'active', []],
        [\PluginLoader::class, 'activeSlugs', null],
        [\PluginLoader::class, 'booted', false],
    ];
    $saved = [];
    foreach ($statics as [$class, $prop, $value]) {
        $p = new \ReflectionProperty($class, $prop);
        $saved[] = [$p, $p->getValue()];
        $p->setValue(null, $value);
    }
    $env = ['LICENSE_SERVER_URL' => 'https://license.test', 'LICENSE_SERVER_PUBLIC_KEY' => license_test_public_key(), 'LICENSE_PRODUCT' => 'kohevo', 'LICENSE_KEY' => 'test-key'];
    $prevEnv = [];
    foreach ($env as $k => $v) {
        $prevEnv[$k] = $_ENV[$k] ?? null;
        $_ENV[$k] = $v;
    }
    try {
        $fn();
    } finally {
        unset($GLOBALS['SLATE_TENANT_OVERRIDE']);
        foreach ($saved as [$p, $v]) {
            $p->setValue(null, $v);
        }
        foreach ($prevEnv as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }
}

function sbmu_call(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $action, array $input): StudioApiResponse
{
    $api = new StudioAuthoringApi($rt->app);
    $request = new StudioApiRequest('POST', $action, [], (string) json_encode($input, JSON_PRESERVE_ZERO_FRACTION), 'application/json', true, 'same-origin');
    return $rt->tenants->runAs($tenantId, static fn() => $api->handle($request, $actor));
}

/** @return array<string, mixed> */
function sbmu_ok(StudioApiResponse $r, string $what): array
{
    assert_true($r->isOk(), "{$what}: expected success, got {$r->status} " . substr($r->body(), 0, 600));
    return $r->data();
}

unit('media usage integration: a file a Studio page uses cannot be deleted; a page that lost one stays editable (real MySQL)', function (): void {
    $dbName = 'slate_sbmu_' . slate_test_ns();
    $pdo = sbmu_pdo($dbName, true);
    try {
        sbmu_with_pdo($pdo, static function (): void {
            $core = InstallationService::provisionCore();
            $iid = (string) $core['installation_id'];
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\') ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [SBMU_A, 'Tenant A', 'tenant-a-mu', SBMU_B, 'Tenant B', 'tenant-b-mu']
            );
            $seed = static fn(int $t, array $e) => license_test_seed_cache($t, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'mu', 'entitlements' => $e, 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            $seed((int) $core['tenant_id'], ['studio-builder']);
            $sel = CommercialModuleRegistry::validateSelection(['studio-builder'], ['studio-builder']);
            assert_true($sel['ok'], json_encode($sel));
            $act = \PluginLoader::installFromDisk('studio-builder');
            assert_true(!empty($act['ok']), 'installFromDisk(studio-builder): ' . json_encode($act));
            Media::ensureSchema();
            $seed(SBMU_A, ['studio-builder']);
            $seed(SBMU_B, ['studio-builder']);

            $rt = StudioRuntimeFactory::build();
            $editor = StudioActor::authenticated(91, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            $inA = static fn(callable $fn) => $rt->tenants->runAs(SBMU_A, $fn);

            $photo = $inA(static fn(): int => Media::register('/uploads/media/2026/10/mu-photo.png', ['mime' => 'image/png']));
            $hero  = $inA(static fn(): int => Media::register('/uploads/media/2026/10/mu-hero.jpg', ['mime' => 'image/jpeg']));
            $spare = $inA(static fn(): int => Media::register('/uploads/media/2026/10/mu-spare.png', ['mime' => 'image/png']));
            $other = $rt->tenants->runAs(SBMU_B, static fn(): int => Media::register('/uploads/media/2026/10/mu-other.png', ['mime' => 'image/png']));

            // A video file (the Video widget's picker): kept as kind "other", listed only by the video filter, per tenant.
            $clip = $inA(static fn(): int => Media::register('/uploads/media/2026/10/mu-clip.mp4', ['mime' => 'video/mp4']));
            $rt->tenants->runAs(SBMU_B, static fn(): int => Media::register('/uploads/media/2026/10/mu-other.webm', ['mime' => 'video/webm']));
            $videos = $inA(static fn(): array => Media::listAll(['type' => 'video']));
            assert_eq([$clip], array_column($videos['items'], 'id'), 'tenant A sees its one video, not B\'s, and no image');
            assert_eq('other', $videos['items'][0]['kind'] ?? null);
            assert_false(in_array($clip, array_column($inA(static fn(): array => Media::listAll(['type' => 'image']))['items'], 'id'), true), 'a video is not in the image list');
            $shaped = \Slate\Module\StudioBuilder\Http\StudioMediaApi::shapeList($videos, 'video');
            assert_eq([$clip], array_column($shaped['items'], 'id'), 'the picker shapes it as a video');

            // A page with an image block and a section background image.
            $created = sbmu_ok(sbmu_call($rt, SBMU_A, $editor, 'create_page', ['title' => 'Uses media', 'slug' => 'uses-media', 'page_type' => 'page', 'route_mode' => 'standalone']), 'create');
            $pageId = (int) $created['page']['id'];
            $r = sbmu_ok(sbmu_call($rt, SBMU_A, $editor, 'operations', ['page_id' => $pageId, 'expected_revision_id' => (int) $created['page']['active_draft_revision_id'], 'operations' => [
                ['op' => 'insert_block', 'payload' => ['parent_id' => null, 'index' => 0, 'block' => ['type' => 'core.container', 'metadata' => ['label' => 'Main'], 'style' => ['background' => ['image' => ['media_id' => $hero, 'alt' => '']]]]]],
            ]]), 'section with background');
            $sec = (string) $r['document']['containers'][0]['id'];
            $r = sbmu_ok(sbmu_call($rt, SBMU_A, $editor, 'operations', ['page_id' => $pageId, 'expected_revision_id' => (int) $r['revision']['id'], 'operations' => [
                ['op' => 'insert_block', 'payload' => ['parent_id' => $sec, 'index' => 0, 'block' => ['type' => 'core.image', 'props' => ['media' => ['media_id' => $photo, 'alt' => 'Photo']]]]],
                ['op' => 'insert_block', 'payload' => ['parent_id' => $sec, 'index' => 1, 'block' => ['type' => 'core.heading', 'props' => ['text' => 'Hello', 'level' => 'h2']]]],
            ]]), 'image block');
            $rev = (int) $r['revision']['id'];
            $heading = (string) $r['document']['containers'][0]['children'][1]['id'];

            $inA(static function () use ($photo, $hero, $spare): void {
                assert_eq(1, Media::usageCountByPath('/uploads/media/2026/10/mu-photo.png'), 'the image block counts');
                assert_eq(1, Media::usageCountByPath('/uploads/media/2026/10/mu-hero.jpg'), 'the background image counts');
                assert_eq(0, Media::usageCountByPath('/uploads/media/2026/10/mu-spare.png'), 'an unused file does not');
                $refused = Media::delete($photo);
                assert_false($refused['ok'], 'a file the page uses is not deleted');
                assert_eq(1, (int) ($refused['usage_count'] ?? 0));
                assert_true(Media::get($photo) !== null, 'and its row is still there');
            });
            $rt->tenants->runAs(SBMU_B, static function (): void {
                assert_eq(0, Media::usageCountByPath('/uploads/media/2026/10/mu-photo.png'), 'tenant B sees none of tenant A\'s usage');
            });

            // The media screen: SQL filters, counts, details and the change event (media library Phase 1).
            $inA(static function () use ($photo, $hero, $spare, $clip): void {
                Media::flushUsageCache();
                $inUse = array_column(Media::listAll(['usage' => 'in_use'])['items'], 'id');
                sort($inUse);
                $want = [$photo, $hero];
                sort($want);
                assert_eq($want, $inUse, 'in use: the two files the page uses');
                $unused = array_column(Media::listAll(['usage' => 'unused'])['items'], 'id');
                assert_true(in_array($spare, $unused, true) && in_array($clip, $unused, true) && !in_array($photo, $unused, true), 'unused: everything else');

                $c = Media::counts();
                assert_eq(4, $c['all'], json_encode($c));
                assert_eq(3, $c['image']);
                assert_eq(1, $c['video']);
                assert_eq(0, $c['document'], 'a video is not a document');
                assert_eq(2, $c['in_use']);
                assert_eq(2, $c['unused']);

                $paged = Media::listAll(['type' => 'image', 'sort' => 'name', 'per_page' => 2, 'page' => 2]);
                assert_eq(3, $paged['total']);
                assert_eq(2, $paged['pages']);
                assert_eq(['mu-spare.png'], array_column($paged['items'], 'original_name'), 'page 2 of the A–Z list');

                $saved = Media::updateMeta($spare, ['alt_text' => 'A red bicycle', 'title' => 'Bike']);
                assert_true(!empty($saved['ok']), 'details save (the schema has alt_text and title): ' . json_encode($saved));
                assert_eq([$spare], array_column(Media::listAll(['search' => 'red bicycle'])['items'], 'id'), 'search reads alt text');
                assert_eq([$spare], array_column(Media::listAll(['search' => 'Bike'])['items'], 'id'), 'and the title');
                assert_eq([], Media::listAll(['search' => '%'])['items'], 'a % is a character, not a wildcard');

                assert_eq([$spare, $photo], array_column(Media::getMany([$spare, 999999, $photo, $spare]), 'id'), 'getMany: asked order, unknown and repeated ids left out');

                $details = Media::usageDetails($photo);
                assert_eq(1, count($details), json_encode($details));
                assert_eq('Studio', $details[0]['source']);
                assert_true(str_starts_with($details[0]['label'], 'Uses media'), $details[0]['label']);
                assert_true(str_contains($details[0]['url'], 'admin/builder.php?page='), $details[0]['url']);
                assert_eq([], Media::usageDetails($spare), 'an unused file is used nowhere');
            });
            $seen = [];
            $listener = static function (mixed $id = 0) use (&$seen): void { $seen[] = $id; };
            \Hook::addAction('media_updated', $listener);
            $inA(static fn() => Media::updateMeta($photo, ['alt_text' => 'Photo of the team']));
            \Hook::removeAction('media_updated', $listener);
            assert_eq([$photo], $seen, 'saving details announces the change, so Studio drops the pages that bake it');

            // The file was deleted before this guard existed: the page must stay editable.
            Database::query('DELETE FROM media_files WHERE tenant_id = ? AND id = ?', [SBMU_A, $photo]);
            $r = sbmu_ok(sbmu_call($rt, SBMU_A, $editor, 'operations', ['page_id' => $pageId, 'expected_revision_id' => $rev, 'operations' => [
                ['op' => 'update_block_props', 'payload' => ['block_id' => $heading, 'props' => ['text' => 'Hello again', 'level' => 'h2']]],
            ]]), 'an unrelated edit after the image was deleted');
            $rev = (int) $r['revision']['id'];
            $r = sbmu_ok(sbmu_call($rt, SBMU_A, $editor, 'publish', ['page_id' => $pageId, 'expected_revision_id' => $rev]), 'publish after the image was deleted');
            $rev = (int) $r['revision']['id'];

            // A NEW reference to a missing or another tenant's file is still refused.
            foreach (['missing' => 999999, 'another tenant' => $other] as $what => $id) {
                $bad = sbmu_call($rt, SBMU_A, $editor, 'operations', ['page_id' => $pageId, 'expected_revision_id' => $rev, 'operations' => [
                    ['op' => 'insert_block', 'payload' => ['parent_id' => $sec, 'index' => 2, 'block' => ['type' => 'core.image', 'props' => ['media' => ['media_id' => $id, 'alt' => '']]]]],
                ]]);
                assert_eq(422, $bad->status, "a new image of a {$what} file is refused: " . substr($bad->body(), 0, 300));
            }

            // Archiving the page releases its files.
            sbmu_ok(sbmu_call($rt, SBMU_A, $editor, 'archive_page', ['page_id' => $pageId]), 'archive');
            $inA(static function (): void {
                assert_eq(0, Media::usageCountByPath('/uploads/media/2026/10/mu-hero.jpg'), 'an archived page holds no file');
            });
        });
    } finally {
        sbmu_pdo($dbName, false);
    }
});

/** A $w × $h JPEG or PNG, left half red, right half blue. */
function sbmu_image(string $abs, int $w, int $h): void
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, intdiv($w, 2) - 1, $h - 1, imagecolorallocate($im, 255, 0, 0));
    imagefilledrectangle($im, intdiv($w, 2), 0, $w - 1, $h - 1, imagecolorallocate($im, 0, 0, 255));
    str_ends_with($abs, '.png') ? imagepng($im, $abs) : imagejpeg($im, $abs, 90);
}

unit('media images integration: WebP sizes, srcset, rotate and crop, versioned URLs, cleanup on delete (real MySQL)', function (): void {
    if (!\Slate\Services\Media\ImageProcessor::available()) {
        return; // GD without WebP: the library keeps working, it just makes no copies
    }
    $dbName = 'slate_sbmi_' . slate_test_ns();
    $dirRel = '/uploads/media/zz-sbmi-' . slate_test_ns();
    $dirAbs = SLATE_ROOT . $dirRel;
    $pdo = sbmu_pdo($dbName, true);
    $uploadsExisted = is_dir(SLATE_ROOT . '/uploads');
    @mkdir($dirAbs, 0775, true);
    $copies = []; // files a copy-edit writes outside the test folder, removed even when an assertion fails
    try {
        sbmu_with_pdo($pdo, static function () use ($dirRel, $dirAbs, &$copies): void {
            InstallationService::provisionCore();
            Media::ensureSchema();
            $tenants = new \Slate\Tenancy\TenantContext();
            $tenants->runAs(SBMU_A, static function () use ($dirRel, $dirAbs, $tenants, &$copies): void {
                sbmu_image($dirAbs . '/big.jpg', 2000, 1000);
                sbmu_image($dirAbs . '/small.png', 300, 200);
                $big   = Media::register($dirRel . '/big.jpg', ['mime' => 'image/jpeg', 'width' => 2000, 'height' => 1000]);
                $small = Media::register($dirRel . '/small.png', ['mime' => 'image/png', 'width' => 300, 'height' => 200]);
                assert_eq(2, Media::pendingVariantsCount(), 'both are unchecked');

                $r = Media::generateMissing(10);
                assert_eq(['done' => 2, 'files' => 3, 'left' => 0], $r, 'three copies for the big one, none needed for the small one');
                $row = Media::get($big);
                assert_eq([480, 960, 1600], array_keys($row['variants']));
                foreach ([480, 960, 1600] as $w) {
                    assert_true(is_file($dirAbs . '/big-' . $w . 'w.webp'), "the {$w} px copy is on disk");
                }
                assert_true(str_ends_with($row['thumb_url'], '/big-480w.webp'), 'the grid shows the smallest copy: ' . $row['thumb_url']);
                assert_eq([], Media::get($small)['variants']);
                assert_eq(0, Media::pendingVariantsCount(), 'the small one is marked checked, not offered again');

                $resolved = (new \Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver($tenants))->resolveImage($big);
                assert_true($resolved !== null && !str_contains($resolved->url, '?v='), 'an untouched file keeps its plain URL');
                assert_eq([480, 960, 1600], array_keys($resolved->srcset));
                $attrs = \Slate\Module\StudioBuilder\Render\Block\CoreRenderers\ImageRenderer::srcsetAttrs($resolved->url, $resolved->width, $resolved->srcset);
                assert_true(str_contains($attrs, 'big-960w.webp 960w') && str_contains($attrs, 'big.jpg 2000w') && str_contains($attrs, 'sizes="auto, 100vw"'), $attrs);

                // Rotate in place: same id, new shape, copies rebuilt for the new width, URL versioned, listeners told.
                $seen = [];
                $listener = static function (mixed $id = 0) use (&$seen): void { $seen[] = $id; };
                \Hook::addAction('media_updated', $listener);
                $edit = Media::editImage($big, ['rotate' => 90]);
                \Hook::removeAction('media_updated', $listener);
                assert_true(!empty($edit['ok']), json_encode($edit));
                $row = Media::get($big);
                assert_eq([1000, 2000, 1], [$row['width'], $row['height'], $row['file_version']]);
                assert_eq([480, 960], array_keys($row['variants']), 'a 1000 px wide image has no 1600 copy');
                assert_false(is_file($dirAbs . '/big-1600w.webp'), 'the stale copy is gone');
                assert_true(str_ends_with($row['variants'][480], '?v=1'), 'copies carry the version too: ' . $row['variants'][480]);
                assert_eq([$big], $seen, 'Studio is told so pages using it rebuild');
                $resolved = (new \Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver($tenants))->resolveImage($big);
                assert_true(str_ends_with($resolved->url, '/big.jpg?v=1'), $resolved->url);

                // Crop as a copy: a new item, the original untouched.
                $copy = Media::editImage($big, ['crop' => [0, 0, 1000, 1000]], true);
                assert_true(!empty($copy['ok']), json_encode($copy));
                $copyRow = $copy['item'];
                $copies[] = SLATE_ROOT . $copyRow['path'];
                assert_true($copyRow['id'] !== $big && $copyRow['original_name'] === 'big-edited.jpg', json_encode($copyRow));
                assert_eq([1000, 1000, 0], [$copyRow['width'], $copyRow['height'], $copyRow['file_version']]);
                assert_eq(1, Media::get($big)['file_version'], 'the original is not touched');
                assert_false(!empty(Media::editImage($small, ['crop' => [0, 0, 300, 200]])['ok']), 'a whole-image crop changes nothing');
                assert_eq(0, Media::get($small)['file_version'], 'and does not bump the version');

                // The importer does not turn the copies into library items.
                assert_true(Media::isVariantFile('big-480w.webp') && !Media::isVariantFile('big.webp') && !Media::isVariantFile('team-2024w.webp'));
                $import = Media::importFolder(substr($dirRel, strlen('/uploads/')));
                assert_eq(0, $import['added'], 'nothing new: ' . json_encode($import));

                // Deleting the file removes its copies too.
                $del = Media::delete($big);
                assert_true(!empty($del['ok']), json_encode($del));
                assert_false(is_file($dirAbs . '/big.jpg') || is_file($dirAbs . '/big-480w.webp') || is_file($dirAbs . '/big-960w.webp'), 'file and copies are gone');
                Media::delete((int)$copyRow['id']);
                Media::delete($small);
            });
        });
    } finally {
        foreach (glob($dirAbs . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @unlink($dirAbs . '/.htaccess');
        @rmdir($dirAbs);
        foreach ($copies as $abs) {
            foreach (array_merge([$abs], glob(preg_replace('/\.[a-z]+$/i', '', $abs) . '-*w.webp') ?: []) as $f) {
                @unlink($f);
            }
        }
        if (!$uploadsExisted) {
            // A checkout without an uploads folder gets none left behind.
            exec('rm -rf ' . escapeshellarg(SLATE_ROOT . '/uploads'));
        }
        sbmu_pdo($dbName, false);
    }
});

unit('media organising integration: folders, tags, missing alt, trash and restore, purge, duplicate lookup (real MySQL)', function (): void {
    $dbName = 'slate_sbmo_' . slate_test_ns();
    $dirRel = '/uploads/media/zz-sbmo-' . slate_test_ns();
    $dirAbs = SLATE_ROOT . $dirRel;
    $pdo = sbmu_pdo($dbName, true);
    $uploadsExisted = is_dir(SLATE_ROOT . '/uploads');
    @mkdir($dirAbs, 0775, true);
    try {
        sbmu_with_pdo($pdo, static function () use ($dirRel, $dirAbs): void {
            InstallationService::provisionCore();
            Media::ensureSchema();
            (new \Slate\Tenancy\TenantContext())->runAs(SBMU_A, static function () use ($dirRel, $dirAbs): void {
                $ids = [];
                foreach (['a', 'b', 'c', 'd'] as $n) {
                    file_put_contents($dirAbs . "/{$n}.png", "png-{$n}");
                    $ids[$n] = Media::register("{$dirRel}/{$n}.png", ['mime' => 'image/png']);
                }

                // Folders: a tree with counts; files are labels only.
                $events = Media::createFolder('  Events  ');
                $summer = Media::createFolder('Summer', $events);
                assert_true($events > 0 && $summer > 0);
                assert_eq(0, Media::createFolder('   '), 'a blank name is refused');
                assert_eq(0, Media::createFolder('Orphan', 999999), 'an unknown parent is refused');
                assert_eq(2, Media::moveToFolder([$ids['a'], $ids['b']], $summer));
                assert_eq(1, Media::moveToFolder([$ids['c']], $events));
                assert_eq(0, Media::moveToFolder([$ids['d']], 999999), 'an unknown folder moves nothing');
                $tree = Media::folders();
                assert_eq([['Events', 0, 1], ['Summer', 1, 2]], array_map(fn($f) => [$f['name'], $f['depth'], $f['count']], $tree), json_encode($tree));
                assert_true(is_file($dirAbs . '/a.png'), 'nothing moved on disk');
                assert_eq([$ids['b'], $ids['a']], array_column(Media::listAll(['folder' => $summer])['items'], 'id'));
                assert_eq([$ids['d']], array_column(Media::listAll(['folder' => 'none'])['items'], 'id'));
                assert_true(Media::renameFolder($summer, 'Summer 2026'));
                assert_true(Media::deleteFolder($events), 'remove the parent');
                $tree = Media::folders();
                assert_eq([['Summer 2026', 0, 2]], array_map(fn($f) => [$f['name'], $f['depth'], $f['count']], $tree), 'the sub-folder moved up');
                assert_eq(null, Media::get($ids['c'])['folder_id'], 'its file moved up to no folder');

                // Tags: set, add in bulk, filter, counts, unused tags pruned.
                assert_true(Media::setTags($ids['a'], 'Beach, beach , Sunset'));
                assert_eq(['Beach', 'Sunset'], array_column(Media::get($ids['a'])['tags'], 'name'), 'case-insensitive duplicates collapse');
                assert_eq(2, Media::addTags([$ids['b'], $ids['c']], 'beach'));
                $tags = array_column(Media::tags(), 'count', 'name');
                assert_eq(['Beach' => 3, 'Sunset' => 1], $tags, json_encode($tags));
                $beach = (int)array_values(array_filter(Media::tags(), fn($t) => $t['name'] === 'Beach'))[0]['id'];
                $tagged = array_column(Media::listAll(['tag' => $beach])['items'], 'id');
                sort($tagged);
                assert_eq([$ids['a'], $ids['b'], $ids['c']], $tagged);
                Media::setTags($ids['a'], '');
                assert_eq(['Beach'], array_column(Media::tags(), 'name'), 'Sunset was on no file any more');

                // Missing alt text.
                Media::updateMeta($ids['a'], ['alt_text' => 'Sea at dusk']);
                assert_eq(3, Media::counts()['missing_alt']);
                assert_false(in_array($ids['a'], array_column(Media::listAll(['alt' => 'missing'])['items'], 'id'), true));

                // Trash: in-use files are refused; trashed files leave the lists and counts; restore brings them back.
                Media::attach($ids['b'], ['context' => 'test', 'object_type' => 'page', 'object_id' => '1']);
                assert_eq(['trashed' => 2, 'refused' => 1], Media::trash([$ids['b'], $ids['c'], $ids['d']]));
                $c = Media::counts();
                assert_eq([2, 2], [$c['all'], $c['trash']], json_encode($c));
                assert_false(in_array($ids['c'], array_column(Media::listAll()['items'], 'id'), true), 'not in the normal list');
                $inTrash = array_column(Media::listAll(['trash' => true])['items'], 'id');
                sort($inTrash);
                assert_eq([$ids['c'], $ids['d']], $inTrash);
                assert_eq(['Beach' => 1], array_column(Media::tags(), 'count', 'name'), 'b keeps the tag; trashed c no longer counts');
                assert_eq(1, Media::restore([$ids['c']]));
                assert_eq(1, Media::counts()['trash']);

                // Purge: the daily job keeps recent ones; "Empty trash" removes the file from disk.
                assert_eq(0, Media::purgeTrash(), 'trashed today: kept for 30 days');
                Database::query('UPDATE media_files SET deleted_at = NOW() - INTERVAL 31 DAY WHERE id = ?', [$ids['d']]);
                assert_eq(1, Media::purgeTrash(), 'older than 30 days: removed');
                assert_eq(null, Media::get($ids['d']));
                assert_false(is_file($dirAbs . '/d.png'));
                Media::trash([$ids['c']]);
                assert_eq(1, Media::purgeTrash(0), 'Empty trash');
                assert_false(is_file($dirAbs . '/c.png'));

                // Duplicates are found by content hash, never in the trash.
                $hash = hash('sha256', 'png-a');
                Database::query('UPDATE media_files SET file_hash = ? WHERE id = ?', [$hash, $ids['a']]);
                assert_eq($ids['a'], Media::findByHash($hash)['id'] ?? null);
                assert_eq(null, Media::findByHash('not-a-hash'));
                Media::trash([$ids['a']]);
                assert_eq(null, Media::findByHash($hash), 'a trashed file is not handed out again');
                Media::restore([$ids['a']]);
                Media::detach($ids['b']);
                foreach ([$ids['a'], $ids['b']] as $id) {
                    Media::delete($id);
                }
            });
        });
    } finally {
        foreach (glob($dirAbs . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @unlink($dirAbs . '/.htaccess');
        @rmdir($dirAbs);
        if (!$uploadsExisted) {
            exec('rm -rf ' . escapeshellarg(SLATE_ROOT . '/uploads'));
        }
        sbmu_pdo($dbName, false);
    }
});

/** A tiny MP3 (ID3 tag and three silent frames) that finfo reads as audio/mpeg; $salt makes the bytes unique. */
function sbmu_mp3(string $salt = ''): string
{
    return "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xFF\xFB\x90\x00" . str_repeat("\x00", 413), 3) . $salt;
}

unit('media import integration: shared ingest, audio type, chunked upload, storage stats, MCP media tools (real MySQL)', function (): void {
    $dbName = 'slate_sbmi_' . slate_test_ns();
    $pdo = sbmu_pdo($dbName, true);
    $uploadsExisted = is_dir(SLATE_ROOT . '/uploads');
    $chunksExisted = is_dir(SLATE_ROOT . '/data/media-chunks');
    $made = [];
    try {
        require_once SLATE_ROOT . '/plugins/mcp-gateway/CoreMcpTools.php';
        sbmu_with_pdo($pdo, static function () use (&$made): void {
            InstallationService::provisionCore();
            Media::ensureSchema();
            (new \Slate\Tenancy\TenantContext())->runAs(SBMU_A, static function () use (&$made): void {
                $tmp = static function (string $bytes): string {
                    $f = (string) tempnam(sys_get_temp_dir(), 'sbmi');
                    file_put_contents($f, $bytes);
                    return $f;
                };

                // ingestLocal: the same checks as an upload, file moved into /uploads/media/<Y>/<m>/.
                $src = $tmp(sbmu_mp3('a'));
                $mp3 = Media::ingestLocal($src, 'Episode 1.mp3');
                assert_true(!empty($mp3['id']), json_encode($mp3));
                $made[] = (int) $mp3['id'];
                assert_eq(['audio/mpeg', 'Episode 1.mp3', 'other'], [$mp3['mime'], $mp3['original_name'], $mp3['kind']]);
                assert_true(str_starts_with($mp3['path'], '/uploads/media/' . date('Y/m') . '/') && is_file(SLATE_ROOT . $mp3['path']));
                assert_false(is_file($src), 'moved, not copied');

                $again = $tmp(sbmu_mp3('a'));
                $dup = Media::ingestLocal($again, 'copy.mp3');
                assert_eq([true, (int) $mp3['id']], [!empty($dup['duplicate']), (int) $dup['id']], 'same bytes: the existing item');
                @unlink($again);

                $bad = $tmp('<?php echo 1;');
                assert_eq(false, Media::ingestLocal($bad, 'x.mp3')['ok'] ?? null, 'content that is not audio is refused');
                assert_eq(false, Media::ingestLocal($bad, 'x.php')['ok'] ?? null, 'a .php name is refused');
                @unlink($bad);
                $big = $tmp(str_repeat('a', 2048));
                assert_eq(false, Media::ingestLocal($big, 'a.mp3', ['max_bytes' => 1024])['ok'] ?? null, 'over the size limit');
                @unlink($big);

                // Audio counts and filters on its own; documents and videos leave it out.
                $pdf = Media::ingestLocal($tmp("%PDF-1.4\n%sbmi\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"), 'notes.pdf');
                $made[] = (int) $pdf['id'];
                $c = Media::counts();
                assert_eq([1, 1, 0, 2], [$c['audio'], $c['document'], $c['video'], $c['all']], json_encode($c));
                assert_eq([(int) $mp3['id']], array_column(Media::listAll(['type' => 'audio'])['items'], 'id'));
                assert_eq([(int) $pdf['id']], array_column(Media::listAll(['type' => 'document'])['items'], 'id'));

                // Chunked upload: pieces in order, owned by one user, checked again on finish.
                $bytes = sbmu_mp3(str_repeat('z', 3000));
                $st = \Slate\Services\Media\ChunkedUpload::start('Long show.mp3', strlen($bytes), 7);
                assert_true($st['ok'], json_encode($st));
                assert_false(\Slate\Services\Media\ChunkedUpload::start('evil.php', 10, 7)['ok']);
                assert_false(\Slate\Services\Media\ChunkedUpload::start('huge.mp3', Media::AUDIO_MAX_BYTES + 1, 7)['ok']);
                $pieces = str_split($bytes, 1000);
                $p0 = $tmp($pieces[0]);
                assert_false(\Slate\Services\Media\ChunkedUpload::append($st['token'], 8, 0, $p0)['ok'], 'another user cannot add to it');
                assert_true(\Slate\Services\Media\ChunkedUpload::append($st['token'], 7, 0, $p0)['ok']);
                $r = \Slate\Services\Media\ChunkedUpload::append($st['token'], 7, 0, $p0);
                assert_eq([false, 1000], [$r['ok'], $r['received'] ?? null], 'a resent piece is refused and says where the file is');
                assert_false(\Slate\Services\Media\ChunkedUpload::finish($st['token'], 7)['ok'] ?? true, 'not complete yet');
                $offset = 1000;
                foreach (array_slice($pieces, 1) as $piece) {
                    $pf = $tmp($piece);
                    $r = \Slate\Services\Media\ChunkedUpload::append($st['token'], 7, $offset, $pf);
                    assert_true($r['ok'], json_encode($r));
                    $offset = $r['received'];
                    @unlink($pf);
                }
                @unlink($p0);
                $done = \Slate\Services\Media\ChunkedUpload::finish($st['token'], 7);
                assert_true(!empty($done['id']), json_encode($done));
                $made[] = (int) $done['id'];
                assert_eq([strlen($bytes), 'Long show.mp3'], [(int) $done['size_bytes'], $done['original_name']]);
                assert_eq($bytes, file_get_contents(SLATE_ROOT . $done['path']));
                assert_eq([], glob(SLATE_ROOT . '/data/media-chunks/' . SBMU_A . '/' . $st['token'] . '.*') ?: [], 'the part is gone');
                $old = \Slate\Services\Media\ChunkedUpload::start('old.mp3', 10, 7);
                touch(SLATE_ROOT . '/data/media-chunks/' . SBMU_A . '/' . $old['token'] . '.json', time() - 2 * 86400);
                assert_eq(1, \Slate\Services\Media\ChunkedUpload::cleanup(), 'an upload left for two days is cleared');

                // Storage stats.
                $stats = Media::storageStats();
                assert_eq(2, $stats['types']['audio']['count']);
                assert_eq((int) $mp3['size_bytes'] + strlen($bytes), $stats['types']['audio']['bytes']);
                assert_eq(3, $stats['files_on_disk']);
                assert_eq(3, $stats['unused']['count']);
                assert_eq((int) $done['id'], $stats['largest'][0]['id'], 'biggest first');

                // URL import refuses a private address before sending anything.
                $r = Media::importUrl('http://127.0.0.1/secret.jpg');
                assert_eq(false, $r['ok'] ?? null);
                assert_true(str_contains((string) $r['error'], 'public'), (string) $r['error']);

                // MCP tools.
                $ctx = ['scopes' => ['mcp-gateway.media.read', 'mcp-gateway.media.write']];
                $names = array_column(\CoreMcpTools::filterTools([], $ctx), 'name');
                foreach (['slate_media_list', 'slate_media_get', 'slate_media_folders', 'slate_media_stats', 'slate_media_upload', 'slate_media_import_url', 'slate_media_update', 'slate_media_delete', 'slate_media_restore'] as $n) {
                    assert_true(in_array($n, $names, true), $n);
                }
                $call = static fn(string $n, array $a) => \CoreMcpTools::callTool(null, $n, $a, $ctx);
                $folder = Media::createFolder('Podcasts');
                $u = $call('slate_media_update', ['id' => (int) $mp3['id'], 'title' => 'Episode one', 'tags' => ['Show', 'S1'], 'folder_id' => $folder]);
                assert_eq(['Episode one', $folder, ['S1', 'Show']], [$u['item']['title'], $u['item']['folder_id'], array_column($u['item']['tags'], 'name')]);
                $list = $call('slate_media_list', ['type' => 'audio', 'folder' => (string) $folder]);
                assert_eq([(int) $mp3['id']], array_column($list['items'], 'id'));
                assert_eq([(int) $pdf['id']], array_column($call('slate_media_list', ['type' => 'document'])['items'], 'id'));
                assert_true(array_key_exists('used_in', $call('slate_media_get', ['id' => (int) $mp3['id']])));
                assert_eq('Podcasts', $call('slate_media_folders', [])['folders'][0]['name']);
                assert_eq(1, $call('slate_media_stats', [])['counts']['document']);
                $up = $call('slate_media_upload', ['filename' => 'clip.mp3', 'content_base64' => base64_encode(sbmu_mp3('mcp')), 'folder_id' => $folder]);
                $made[] = (int) $up['id'];
                assert_eq([$folder, 'audio/mpeg'], [$up['folder_id'], $up['mime']], 'MCP upload goes through the same ingest path');
                assert_eq(['trashed' => 1, 'refused' => 0], $call('slate_media_delete', ['id' => (int) $up['id']]), 'delete moves to the trash');
                assert_eq(['restored' => 1], $call('slate_media_restore', ['ids' => [(int) $up['id']]]));
                $threw = false;
                try {
                    $call('slate_media_update', ['id' => (int) $mp3['id'], 'folder_id' => 999999]);
                } catch (\InvalidArgumentException $e) {
                    $threw = true;
                }
                assert_true($threw, 'an unknown folder is refused');
                $threw = false;
                try {
                    \CoreMcpTools::callTool(null, 'slate_media_update', ['id' => (int) $mp3['id'], 'title' => 'x'], ['scopes' => ['mcp-gateway.media.read']]);
                } catch (\RuntimeException $e) {
                    $threw = true;
                }
                assert_true($threw, 'writing needs the write scope');
            });
        });
    } finally {
        sbmu_with_pdo($pdo, static function () use ($made): void {
            (new \Slate\Tenancy\TenantContext())->runAs(SBMU_A, static function () use ($made): void {
                foreach ($made as $id) {
                    $row = Media::get($id);
                    if ($row) {
                        @unlink(SLATE_ROOT . $row['path']);
                    }
                }
            });
        });
        // The month folder ingest created, if nothing else is in it.
        $month = SLATE_ROOT . '/uploads/media/' . date('Y/m');
        if ((glob($month . '/*') ?: []) === []) {
            @unlink($month . '/.htaccess');
            @rmdir($month);
            @rmdir(dirname($month));
            @rmdir(dirname($month, 2));
        }
        if (!$chunksExisted) {
            exec('rm -rf ' . escapeshellarg(SLATE_ROOT . '/data/media-chunks'));
        }
        if (!$uploadsExisted) {
            exec('rm -rf ' . escapeshellarg(SLATE_ROOT . '/uploads'));
        }
        sbmu_pdo($dbName, false);
    }
});

if (!empty($studioMediaUsageStandalone)) {
    exit(unit_summary());
}
