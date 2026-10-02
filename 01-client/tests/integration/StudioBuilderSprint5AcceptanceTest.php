<?php
/**
 * Integration test for Kohevo Studio (studio-builder) — Sprint 5 Acceptance.
 *
 * Verifies Sprint 5 Acceptance Criteria (Section 61):
 *   A user can create a blog/archive website without manually creating every content card:
 *   - Dynamic data providers: content.posts, content.authors, content.taxonomy.
 *   - Query builder & loop: core.query_loop block with filtering, ordering, pagination, and columns.
 *   - Dynamic property bindings on core blocks (core.heading, core.text) via field mapping.
 *   - Public rendering under HTTP 200 with full post cards, metadata, and pagination controls.
 *   - Strict multi-tenant isolation (Tenant B never sees Tenant A's posts).
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
    $studioS5IntStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const S5_MIGRATIONS = [
    '0001_core_init',
    '0002_identity_core',
    '0011_login_attempts',
    '0014_tenant_profiles',
    '0023_installation_identity',
    '0022_remote_license_cache',
    '0024_remote_license_metadata',
    '0025_remote_license_cache_installation_id',
    '0026_remote_license_cache_signed_payload',
];

const S5_TENANT_A = 101;
const S5_TENANT_B = 202;

function s5_fresh_db(string $dbName): \PDO
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    $root = new \PDO($dsn, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4");
    $dsn2 = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ";dbname={$dbName};charset=" . DB_CHARSET;
    $pdo  = new \PDO($dsn2, DB_USER, DB_PASS, [
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(S5_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function s5_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function s5_with_pdo(\PDO $pdo, callable $fn): mixed
{
    $property = new \ReflectionProperty(Database::class, 'pdo');
    $previous = $property->getValue();
    $property->setValue(null, $pdo);

    $activeProp = new \ReflectionProperty(\PluginLoader::class, 'active');
    $prevActive = $activeProp->getValue();
    $slugsProp  = new \ReflectionProperty(\PluginLoader::class, 'activeSlugs');
    $prevSlugs  = $slugsProp->getValue();
    $bootedProp = new \ReflectionProperty(\PluginLoader::class, 'booted');
    $prevBooted = $bootedProp->getValue();

    $envKeys = ['LICENSE_SERVER_URL', 'LICENSE_SERVER_PUBLIC_KEY', 'LICENSE_PRODUCT', 'LICENSE_KEY', 'DB_HOST', 'DB_USER', 'DB_PASS'];
    $prevEnv = [];
    foreach ($envKeys as $k) {
        $prevEnv[$k] = $_ENV[$k] ?? null;
    }
    $_ENV['LICENSE_SERVER_URL']        = 'https://license.test';
    $_ENV['LICENSE_SERVER_PUBLIC_KEY'] = license_test_public_key();
    $_ENV['LICENSE_PRODUCT']           = 'kohevo';
    $_ENV['LICENSE_KEY']               = 'test-key';
    $_ENV['DB_HOST']                   = DB_HOST;
    $_ENV['DB_USER']                   = DB_USER;
    $_ENV['DB_PASS']                   = DB_PASS;

    try {
        $activeProp->setValue(null, []);
        $slugsProp->setValue(null, null);
        $bootedProp->setValue(null, false);
        return $fn();
    } finally {
        unset($GLOBALS['SLATE_TENANT_OVERRIDE']);
        $activeProp->setValue(null, $prevActive);
        $slugsProp->setValue(null, $prevSlugs);
        $bootedProp->setValue(null, $prevBooted);
        $property->setValue(null, $previous);
        foreach ($prevEnv as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }
}

unit('sprint5 integration: dynamic bindings, query builder, loop, blog archive and multi-tenant isolation', function (): void {
    $dbName = 'slate_s5_' . slate_test_ns();
    $pdo    = s5_fresh_db($dbName);

    try {
        s5_with_pdo($pdo, static function () use ($pdo): void {
            // Provision core and tenants
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Admin', 'admin@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\')
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [S5_TENANT_A, 'Tenant A Blog Agency', 'tenant-a-s5', S5_TENANT_B, 'Tenant B Competitor', 'tenant-b-s5']
            );

            // Install studio-builder with licenses
            license_test_seed_cache(S5_TENANT_A, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder', 'studio-builder.tokens'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            license_test_seed_cache(S5_TENANT_B, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder', 'studio-builder.tokens'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            \PluginLoader::installFromDisk('studio-builder');
            Media::ensureSchema();

            // Seed Users / Authors
            Database::query(
                'INSERT INTO users (id, tenant_id, email, password_hash, name, role_id) VALUES (?, ?, ?, ?, ?, 1), (?, ?, ?, ?, ?, 1), (?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [10, S5_TENANT_A, 'alice@tenanta.test', 'hash', 'Alice Engineer', 11, S5_TENANT_A, 'bob@tenanta.test', 'hash', 'Bob Designer', 20, S5_TENANT_B, 'charlie@tenantb.test', 'hash', 'Charlie Outsider']
            );

            // Build StudioRuntime
            $rt = StudioRuntimeFactory::build();
            $actorA_Alice = StudioActor::authenticated(10, StudioPermissions::ALL);
            $actorA_Bob   = StudioActor::authenticated(11, StudioPermissions::ALL);
            $actorB       = StudioActor::authenticated(20, StudioPermissions::ALL);

            // Seed Published Blog Posts for Tenant A
            // Post 1: Engineering category
            $p1 = $rt->tenants->runAs(S5_TENANT_A, static fn() => $rt->app->createPage($actorA_Alice, 'Building Resilient Microservices', 'building-resilient-microservices', 'page', 'standalone'));
            $p1Id = (int) $p1['page']['id'];
            $p1Rev = (int) $p1['revision']['id'];
            $opSeo1 = new DocumentOperation('update_seo', ['seo' => [
                'description' => 'A deep dive into distributed systems patterns, failure domains, and recovery strategies.',
            ]]);
            $opSet1 = new DocumentOperation('update_settings', ['settings' => [
                'category' => 'Engineering',
            ]]);
            $p1Edit = $rt->tenants->runAs(S5_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA_Alice, $p1Id, [$opSeo1, $opSet1], $p1Rev, 'Set SEO and Settings'));
            $p1Rev = (int) $p1Edit['revision']['id'];
            $p1Pub = $rt->tenants->runAs(S5_TENANT_A, static fn() => $rt->app->publish($actorA_Alice, $p1Id, $p1Rev));
            assert_eq('published', $p1Pub['page']['status']);

            // Post 2: Engineering category
            $p2 = $rt->tenants->runAs(S5_TENANT_A, static fn() => $rt->app->createPage($actorA_Alice, 'Kubernetes Deployment Strategies', 'kubernetes-deployment-strategies', 'page', 'standalone'));
            $p2Id = (int) $p2['page']['id'];
            $p2Rev = (int) $p2['revision']['id'];
            $opSeo2 = new DocumentOperation('update_seo', ['seo' => [
                'description' => 'Blue-green vs canary deployments explained with production traffic routing examples.',
            ]]);
            $opSet2 = new DocumentOperation('update_settings', ['settings' => [
                'category' => 'Engineering',
            ]]);
            $p2Edit = $rt->tenants->runAs(S5_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA_Alice, $p2Id, [$opSeo2, $opSet2], $p2Rev, 'Set SEO and Settings'));
            $p2Rev = (int) $p2Edit['revision']['id'];
            $p2Pub = $rt->tenants->runAs(S5_TENANT_A, static fn() => $rt->app->publish($actorA_Alice, $p2Id, $p2Rev));
            assert_eq('published', $p2Pub['page']['status']);

            // Post 3: Design category
            $p3 = $rt->tenants->runAs(S5_TENANT_A, static fn() => $rt->app->createPage($actorA_Bob, 'Accessible Design Systems in 2026', 'accessible-design-systems', 'page', 'standalone'));
            $p3Id = (int) $p3['page']['id'];
            $p3Rev = (int) $p3['revision']['id'];
            $opSeo3 = new DocumentOperation('update_seo', ['seo' => [
                'description' => 'How to design inclusive color palettes, focus indicators, and semantic components.',
            ]]);
            $opSet3 = new DocumentOperation('update_settings', ['settings' => [
                'category' => 'Design',
            ]]);
            $p3Edit = $rt->tenants->runAs(S5_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA_Bob, $p3Id, [$opSeo3, $opSet3], $p3Rev, 'Set SEO and Settings'));
            $p3Rev = (int) $p3Edit['revision']['id'];
            $p3Pub = $rt->tenants->runAs(S5_TENANT_A, static fn() => $rt->app->publish($actorA_Bob, $p3Id, $p3Rev));
            assert_eq('published', $p3Pub['page']['status']);

            // Test DataProvider resolution directly under Tenant A
            $providers = StudioRuntimeFactory::dataProviders();
            assert_true($providers->has('content.posts'), 'content.posts provider registered');
            assert_true($providers->has('content.authors'), 'content.authors provider registered');
            assert_true($providers->has('content.taxonomy'), 'content.taxonomy provider registered');

            $allPosts = $rt->tenants->runAs(S5_TENANT_A, static fn() => $providers->resolve(
                'content.posts',
                ['limit' => 10],
                $rt->tenants,
                fn() => true,
                fn() => true
            ));
            assert_eq(3, count($allPosts), 'Tenant A has 3 published posts');

            $engPosts = $rt->tenants->runAs(S5_TENANT_A, static fn() => $providers->resolve(
                'content.posts',
                ['category' => 'Engineering', 'limit' => 10],
                $rt->tenants,
                fn() => true,
                fn() => true
            ));
            assert_eq(2, count($engPosts), 'Filtering by category Engineering yields 2 posts');
            assert_eq('Engineering', $engPosts[0]['category']);
            assert_eq('Engineering', $engPosts[1]['category']);

            // Create Blog Archive Page for Tenant A with core.query_loop block
            $blogPage = $rt->tenants->runAs(S5_TENANT_A, static fn() => $rt->app->createPage($actorA_Alice, 'Blog Archive', 'blog', 'page', 'standalone'));
            $blogPageId = (int) $blogPage['page']['id'];
            $blogRevId = (int) $blogPage['revision']['id'];

            // Insert Section with Query Loop block
            $loopBlock = [
                'id'         => CanonicalDocumentSchema::newBlockId(),
                'type'       => 'core.query_loop',
                'version'    => 1,
                'props'      => [
                    'source'            => 'posts',
                    'columns'           => 3,
                    'per_page'          => 6,
                    'card_variant'      => 'card',
                    'show_category'     => true,
                    'show_date'         => true,
                    'show_author'       => true,
                    'show_excerpt'      => true,
                    'read_more_text'    => 'Read Full Story',
                    'enable_pagination' => true,
                ],
                'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings'   => [
                    'items' => [
                        'provider' => 'content.posts',
                        'params'   => ['limit' => 6],
                    ],
                ],
                'children'   => [],
            ];

            // Also add a dynamic heading bound to the latest post title
            $dynamicHeadingBlock = [
                'id'         => CanonicalDocumentSchema::newBlockId(),
                'type'       => 'core.heading',
                'version'    => 1,
                'props'      => [
                    'text'  => 'Static Fallback Heading',
                    'level' => 'h2',
                ],
                'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings'   => [
                    'post' => [
                        'provider' => 'content.posts',
                        'mapping'  => [
                            'text' => 'title',
                        ],
                    ],
                ],
                'children'   => [],
            ];

            $section = [
                'id'         => CanonicalDocumentSchema::newSectionId(),
                'label'      => 'Blog Query Section',
                'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'blocks'     => [$dynamicHeadingBlock, $loopBlock],
            ];

            $opInsert = new DocumentOperation('insert_section', ['index' => 0, 'section' => $section]);
            $editRes = $rt->tenants->runAs(S5_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA_Alice, $blogPageId, [$opInsert], $blogRevId, 'Add Query Loop and Dynamic Heading'));
            $blogRevId = (int) $editRes['revision']['id'];

            // Publish Blog Archive Page
            $pubRes = $rt->tenants->runAs(S5_TENANT_A, static fn() => $rt->app->publish($actorA_Alice, $blogPageId, $blogRevId));
            assert_eq('published', $pubRes['page']['status']);

            // Public Serving under HTTP 200
            $served = $rt->tenants->runAs(S5_TENANT_A, static fn() => $rt->publicRuntime->handlePath('blog'));
            assert_true($served !== null, 'blog archive page served');
            assert_eq(200, $served->status);

            // Assert output contains all posts rendered dynamically
            assert_true(str_contains($served->body, 'Building Resilient Microservices'), 'renders post 1 title');
            assert_true(str_contains($served->body, 'Kubernetes Deployment Strategies'), 'renders post 2 title');
            assert_true(str_contains($served->body, 'Accessible Design Systems in 2026'), 'renders post 3 title');

            // Assert categories and authors
            assert_true(str_contains($served->body, 'Engineering'), 'renders Engineering category');
            assert_true(str_contains($served->body, 'Design'), 'renders Design category');
            assert_true(str_contains($served->body, 'Alice Engineer'), 'renders author Alice');

            // Assert excerpts
            assert_true(str_contains($served->body, 'distributed systems patterns'), 'renders post 1 excerpt');
            assert_true(str_contains($served->body, 'Blue-green vs canary'), 'renders post 2 excerpt');

            // Assert card layout & classes
            assert_true(str_contains($served->body, 'sb-query-loop'), 'contains sb-query-loop container');
            assert_true(str_contains($served->body, 'sb-post-card'), 'contains sb-post-card elements');
            assert_true(str_contains($served->body, 'Read Full Story'), 'contains configured button label');
            assert_true(str_contains($served->body, 'sb-pagination'), 'contains pagination controls');

            // Strict Multi-Tenant Isolation
            // Tenant B querying content.posts sees ZERO posts from Tenant A
            $postsB = $rt->tenants->runAs(S5_TENANT_B, static fn() => $providers->resolve(
                'content.posts',
                ['limit' => 10],
                $rt->tenants,
                fn() => true,
                fn() => true
            ));
            assert_eq(0, count($postsB), 'Tenant B sees 0 posts from Tenant A');

            // Tenant B cannot serve Tenant A blog page
            $servedB = $rt->tenants->runAs(S5_TENANT_B, static fn() => $rt->publicRuntime->handlePath('blog'));
            assert_null($servedB, 'Tenant B cannot serve Tenant A blog');

            // Tenant B cannot load editor document of Tenant A blog
            assert_throws(StudioNotFoundException::class, static function () use ($rt, $actorB, $blogPageId): void {
                $rt->tenants->runAs(S5_TENANT_B, fn() => $rt->app->loadEditorDocument($actorB, $blogPageId));
            }, 'Tenant B cannot load Tenant A editor document');
        });
    } finally {
        s5_drop_db($dbName);
    }
});
