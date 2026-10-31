<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — content type URL route prefixes (real MySQL).
 *
 * A page of a content type whose route prefix is `/events` is served at `/events/{slug}`, its old `/{slug}` answers
 * with a 301 there, and a wrong prefix is not found. Its canonical tag, the sitemap and Query Loop links name the
 * prefixed address, and the site's trailing-slash setting adds the slash WordPress sites use. A Query Loop card shows
 * the post's social-share image.
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
    $studioRoutesStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PageRoutes;
use Slate\Module\StudioBuilder\Provider\PostsProvider;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;

const SBPR_TENANT = 141;

function sbpr_db(string $dbName, bool $create): ?\PDO
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
function sbpr_with(\PDO $pdo, callable $fn): void
{
    $statics = [[Database::class, 'pdo', $pdo], [\PluginLoader::class, 'active', []], [\PluginLoader::class, 'activeSlugs', null], [\PluginLoader::class, 'booted', false]];
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
        PageRoutes::forget();
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

/** Create, draft and publish a page with one heading; its content type and category are set. */
function sbpr_page(StudioRuntime $rt, StudioActor $actor, string $slug, string $text, string $type = '', string $category = '', int $ogImage = 0): int
{
    return $rt->tenants->runAs(SBPR_TENANT, static function () use ($rt, $actor, $slug, $text, $type, $category, $ogImage): int {
        $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', ucfirst($slug));
        if ($ogImage > 0) {
            $doc['seo']['og_image_media_id'] = $ogImage;
        }
        if ($category !== '') {
            $doc['settings']['category'] = $category;
        }
        $doc['containers'] = [CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'Main'], 'children' => [[
                'bindings' => [], 'children' => [], 'id' => CanonicalDocumentSchema::newBlockId(), 'props' => ['text' => $text, 'level' => 'h2'],
                'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'type' => 'core.heading', 'version' => 1, 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            ]], 'visibility' => CanonicalDocumentSchema::defaultVisibility()])];
        $created = $rt->app->createPage($actor, ucfirst($slug), $slug, 'page', 'standalone');
        $id = (int) $created['page']['id'];
        if ($type !== '') {
            PageMeta::save(SBPR_TENANT, $id, $type, []);
        }
        $saved = $rt->app->saveDraft($actor, $id, $doc, (int) $created['revision']['id']);
        $rt->app->publish($actor, $id, (int) $saved['revision']['id']);
        return $id;
    });
}

function sbpr_get(StudioRuntime $rt, string $path): ?\Slate\Module\StudioBuilder\Runtime\PublicResponse
{
    return $rt->tenants->runAs(SBPR_TENANT, static fn() => $rt->publicRuntime->handlePath($path));
}

function sbpr_types(array $routes): void
{
    $types = [];
    foreach ($routes as $key => $route) {
        $types[$key] = ['key' => $key, 'singular' => ucfirst($key), 'plural' => ucfirst($key) . 's', 'route' => $route, 'is_system' => false, 'description' => ''];
    }
    Database::setSetting('studio_custom_post_types', json_encode($types, JSON_UNESCAPED_SLASHES), SBPR_TENANT);
    PageRoutes::forget();
}

unit('page routes: a content type route prefix gives its pages their own address (real MySQL)', function (): void {
    $dbName = 'slate_sbpr_' . slate_test_ns();
    $pdo = sbpr_db($dbName, true);
    try {
        sbpr_with($pdo, static function (): void {
            $core = InstallationService::provisionCore();
            InstallationService::createAdminAccount((int) $core['tenant_id'], 'Studio Admin', 'routes-admin@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query("INSERT INTO tenants (id, name, slug, status) VALUES (?, 'Routes', 'routes-t', 'active')", [SBPR_TENANT]);
            license_test_seed_cache(SBPR_TENANT, ['installation_id' => (string) $core['installation_id'], 'status' => 'active', 'plan' => 'routes',
                'entitlements' => ['studio-builder'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            license_test_seed_cache((int) $core['tenant_id'], ['installation_id' => (string) $core['installation_id'], 'status' => 'active', 'plan' => 'routes',
                'entitlements' => ['studio-builder'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            $sel = CommercialModuleRegistry::validateSelection(['studio-builder'], ['studio-builder']);
            assert_true($sel['ok'], 'selection');
            assert_true(!empty(\PluginLoader::installFromDisk('studio-builder')['ok']), 'install studio-builder');

            $rt = StudioRuntimeFactory::build();
            $actor = StudioActor::authenticated(31, StudioPermissions::ALL, true);
            $base = defined('SLATE_URL') ? rtrim((string) SLATE_URL, '/') : '';

            sbpr_types(['evenement' => '/events', 'produit' => '/produit', 'note' => '/']);
            \Slate\Services\Media\Media::ensureSchema();
            $card = (int) Database::insert('media_files', ['tenant_id' => SBPR_TENANT, 'path' => '/uploads/media/routes/marche.jpg', 'kind' => 'image', 'mime' => 'image/jpeg', 'folder' => 'routes', 'original_name' => 'marche.jpg']);
            $event = sbpr_page($rt, $actor, 'chaque-pas-compte', 'Marche solidaire', 'evenement', 'Actualites', $card);
            $product = sbpr_page($rt, $actor, 't-shirt', 'T-shirt FightxLife', 'produit');
            $note = sbpr_page($rt, $actor, 'a-note', 'A plain note', 'note');
            sbpr_page($rt, $actor, 'contact', 'Contacte-nous', '');
            sbpr_page($rt, $actor, 'events', 'All events', '');

            $r = sbpr_get($rt, '/events/chaque-pas-compte/');
            assert_true($r !== null && $r->status === 200 && str_contains($r->body, 'Marche solidaire'), 'served under its prefix');
            assert_true(sbpr_get($rt, '/events/chaque-pas-compte')?->status === 200, 'with or without the slash');
            assert_true(sbpr_get($rt, '/produit/t-shirt/')?->status === 200, 'a second type, its own prefix');
            if ($base !== '') {
                assert_true(str_contains($r->body, '<link rel="canonical" href="' . $base . '/events/chaque-pas-compte">'), 'canonical names the prefixed address');
            }

            $moved = sbpr_get($rt, '/chaque-pas-compte/');
            assert_true($moved !== null && $moved->status === 301, 'the flat address moves');
            assert_true(str_ends_with((string) ($moved->headers['Location'] ?? ''), '/events/chaque-pas-compte'), 'to the prefixed one: ' . ($moved->headers['Location'] ?? ''));

            assert_eq(null, sbpr_get($rt, '/produit/chaque-pas-compte/'), 'a page is not served under another type\'s prefix');
            assert_eq(null, sbpr_get($rt, '/events/contact/'), 'an untyped page is not served under a prefix');
            assert_eq(null, sbpr_get($rt, '/events/nothing-here/'), 'an unknown slug under a prefix is not found');
            assert_true(sbpr_get($rt, '/contact/')?->status === 200, 'an untyped page keeps /{slug}');
            assert_true(sbpr_get($rt, '/events/')?->status === 200, 'a page whose slug is a prefix still has its own address');
            assert_true(sbpr_get($rt, '/a-note')?->status === 200 && sbpr_get($rt, '/note/a-note') === null, 'a type whose route is / has no prefix');

            $rows = $rt->tenants->runAs(SBPR_TENANT, static fn() => (new PostsProvider())->execute($rt->tenants, ['category' => 'Actualites', 'limit' => 5]));
            assert_eq('/events/chaque-pas-compte', $rows[0]['url'] ?? null, 'Query Loop links use the prefixed address');
            assert_true(str_ends_with((string) ($rows[0]['featured_image'] ?? ''), '/uploads/media/routes/marche.jpg'), 'the card image is the page\'s social-share image: ' . ($rows[0]['featured_image'] ?? ''));

            if ($base !== '') {
                $xml = (string) sbpr_get($rt, '/sitemap.xml?format=xml')?->body;
                assert_true(str_contains($xml, '<loc>' . $base . '/events/chaque-pas-compte</loc>') && str_contains($xml, '<loc>' . $base . '/produit/t-shirt</loc>'), 'the sitemap lists the prefixed addresses');
                assert_true(!str_contains($xml, '<loc>' . $base . '/chaque-pas-compte</loc>'), 'and not the flat ones');
            }

            // The site's trailing-slash setting: canonical, sitemap and links end in `/`; the old slash-less flat URL still moves.
            Database::setSetting(PageRoutes::SETTING_TRAILING_SLASH, '1', SBPR_TENANT);
            PageRoutes::forget();
            if ($base !== '') {
                $r = sbpr_get($rt, '/events/chaque-pas-compte');
                assert_true(str_contains((string) $r?->body, '<link rel="canonical" href="' . $base . '/events/chaque-pas-compte/">'), 'canonical ends with a slash');
                $c = sbpr_get($rt, '/contact');
                assert_true(str_contains((string) $c?->body, '<link rel="canonical" href="' . $base . '/contact/">'), 'for every page');
                $xml = (string) sbpr_get($rt, '/sitemap.xml?format=xml')?->body;
                assert_true(str_contains($xml, '<loc>' . $base . '/contact/</loc>') && str_contains($xml, '<loc>' . $base . '/events/chaque-pas-compte/</loc>'), 'the sitemap uses the slash');
            }
            assert_true(str_ends_with((string) (sbpr_get($rt, '/chaque-pas-compte')?->headers['Location'] ?? ''), '/events/chaque-pas-compte/'), 'the 301 goes to the slashed address');

            // Changing the type's route moves its pages at once.
            sbpr_types(['evenement' => '/agenda', 'produit' => '/produit', 'note' => '/']);
            assert_true(sbpr_get($rt, '/agenda/chaque-pas-compte/')?->status === 200, 'served under the new prefix');
            assert_eq(null, sbpr_get($rt, '/events/chaque-pas-compte/'), 'and no longer under the old one');
            unset($product, $note, $event);
        });
    } finally {
        sbpr_db($dbName, false);
    }
});

if (!empty($studioRoutesStandalone)) {
    exit(unit_summary());
}
