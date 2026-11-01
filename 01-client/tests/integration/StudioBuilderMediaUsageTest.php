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

if (!empty($studioMediaUsageStandalone)) {
    exit(unit_summary());
}
