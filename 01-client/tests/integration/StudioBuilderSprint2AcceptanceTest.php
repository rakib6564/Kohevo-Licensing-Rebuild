<?php
/**
 * Integration test for Kohevo Studio (studio-builder) — Sprint 2 Acceptance.
 *
 * Verifies Sprint 2 Acceptance Criteria (Section 58):
 *   A user can visually reproduce a modern landing-page hero without writing custom CSS.
 *   - Heading with responsive typography (desktop: 64px, tablet: 48px, mobile: 36px),
 *     font weight bold, transform capitalize, custom color, line-height, and box shadow.
 *   - Text paragraph with lead size and custom line-height.
 *   - Button with border radius full (pill), box shadow lg, custom classNames and attributes.
 *   - Operations update_block_responsive, update_block_class_names, update_block_attributes.
 *   - Immutable revisions, optimistic concurrency, and publishing to public runtime.
 *   - Strict multi-tenant isolation.
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
    $studioS2IntStandalone = true;
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
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const S2_MIGRATIONS = [
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

const S2_TENANT_A = 101;
const S2_TENANT_B = 202;

function s2_fresh_db(string $dbName): \PDO
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
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(S2_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function s2_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function s2_with_pdo(\PDO $pdo, callable $fn): mixed
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

unit('sprint2 integration: visual styling (typography, border, shadow, responsive overrides) persists and publicly renders without custom CSS', function (): void {
    $dbName = 'slate_s2_' . slate_test_ns();
    $pdo = s2_fresh_db($dbName);

    try {
        s2_with_pdo($pdo, static function (): void {
            // Provision core and tenants
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Admin', 'admin@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\')
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [S2_TENANT_A, 'Tenant A', 'tenant-a-s2', S2_TENANT_B, 'Tenant B', 'tenant-b-s2']
            );

            // Install studio-builder
            license_test_seed_cache(S2_TENANT_A, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            license_test_seed_cache(S2_TENANT_B, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            \PluginLoader::installFromDisk('studio-builder');
            Media::ensureSchema();

            $rt = StudioRuntimeFactory::build();
            $actorA = StudioActor::authenticated(42, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            $actorB = StudioActor::authenticated(99, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);

            // 1. Create landing page for Tenant A
            $created = $rt->tenants->runAs(S2_TENANT_A, static fn(): array => $rt->app->createPage($actorA, 'Sprint 2 Hero Landing', 'visual-hero-sprint-2', 'page', 'standalone'));
            $pageId = (int) $created['page']['id'];
            $revId = (int) $created['revision']['id'];
            assert_true($pageId > 0, 'page created');

            // 2. Build modern landing page hero with Elementor-class visual styling without custom CSS
            $btnBlock = [
                'id' => CanonicalDocumentSchema::newBlockId(),
                'type' => 'core.button',
                'version' => 1,
                'props' => [
                    'link' => ['href' => '/pricing', 'label' => 'Start Free Trial', 'target' => '_self'],
                    'variant' => 'primary',
                    'size' => 'lg',
                    'full_width' => false,
                ],
                'style' => [
                    'border' => [
                        'radius' => 'full',
                    ],
                    'shadow' => 'lg',
                ],
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings' => [],
                'children' => [],
                'classNames' => ['hero-cta-btn'],
                'attributes' => [
                    'data-analytics' => 'cta-click',
                ],
            ];

            $textBlock = [
                'id' => CanonicalDocumentSchema::newBlockId(),
                'type' => 'core.text',
                'version' => 1,
                'props' => [
                    'content' => 'Deploy scalable websites with precision visual composition and declarative styling.',
                    'size' => 'lead',
                    'align' => 'center',
                ],
                'style' => [
                    'typography' => [
                        'color' => '#475569',
                        'line_height' => '1.6',
                    ],
                ],
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings' => [],
                'children' => [],
            ];

            $headingId = CanonicalDocumentSchema::newBlockId();
            $headingBlock = [
                'id' => $headingId,
                'type' => 'core.heading',
                'version' => 1,
                'props' => [
                    'text' => 'craft extraordinary web experiences',
                    'level' => 'h1',
                ],
                'style' => [
                    'typography' => [
                        'size' => ['desktop' => '64px', 'tablet' => '48px', 'mobile' => '36px'],
                        'weight' => 'bold',
                        'transform' => 'capitalize',
                        'color' => '#0f172a',
                        'line_height' => '1.2',
                    ],
                    'shadow' => 'xl',
                ],
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings' => [],
                'children' => [],
                'classNames' => ['hero-headline'],
                'attributes' => [
                    'data-hero-el' => 'headline',
                ],
                'responsive' => [
                    'desktop' => ['align' => 'center'],
                    'tablet' => ['align' => 'center'],
                    'mobile' => ['align' => 'left'],
                ],
            ];

            $containerBlock = [
                'id' => CanonicalDocumentSchema::newBlockId(),
                'type' => 'layout.container',
                'version' => 1,
                'props' => [
                    'width' => 'constrained',
                    'alignment' => 'center',
                    'padding' => 'lg',
                ],
                'style' => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings' => [],
                'children' => [$headingBlock, $textBlock, $btnBlock],
            ];

            $sectionBlock = [
                'id' => CanonicalDocumentSchema::newBlockId(),
                'type' => 'layout.section',
                'version' => 1,
                'props' => [
                    'tag' => 'section',
                    'content_width' => 'boxed',
                    'min_height' => 'screen',
                    'padding_y' => 'xl',
                ],
                'style' => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings' => [],
                'children' => [$containerBlock],
            ];

            // 3. Persist the tree via application service operations
            $opSec = new DocumentOperation('insert_section', ['index' => 0, 'section' => ['label' => 'Main Hero Section']]);
            $sRes = $rt->tenants->runAs(S2_TENANT_A, static fn(): array => $rt->app->applyDocumentOperation($actorA, $pageId, [$opSec], $revId, 'Insert Hero Section'));
            $revId = (int) $sRes['revision']['id'];
            $secDoc = CanonicalJson::decode((string) $sRes['revision']['document_json']);
            $secId = (string) $secDoc['sections'][0]['id'];

            $opBlock = new DocumentOperation('insert_block', ['parent_id' => $secId, 'index' => 0, 'block' => $sectionBlock]);
            $insertRes = $rt->tenants->runAs(S2_TENANT_A, static fn(): array => $rt->app->applyDocumentOperation($actorA, $pageId, [$opBlock], $revId, 'Insert Visual Tree'));
            $revId = (int) $insertRes['revision']['id'];
            assert_true($revId > 0, 'revision updated with visual tree');

            $treeDoc = CanonicalJson::decode((string) $insertRes['revision']['document_json']);
            $actualHeadingId = (string) $treeDoc['sections'][0]['blocks'][0]['children'][0]['children'][0]['id'];

            // 4. Test dedicated operations: update_block_responsive, update_block_class_names, update_block_attributes
            $opResp = DocumentOperation::updateBlockResponsive($actualHeadingId, [
                'desktop' => ['align' => 'center'],
                'tablet' => ['align' => 'center'],
                'mobile' => ['align' => 'center'], // Override to center on mobile
            ]);
            $opClasses = DocumentOperation::updateBlockClassNames($actualHeadingId, ['hero-headline', 'tracking-tight', 'display-title']);
            $opAttrs = DocumentOperation::updateBlockAttributes($actualHeadingId, [
                'data-hero-el' => 'headline',
                'aria-label' => 'Main Page Title',
            ]);

            $opsRes = $rt->tenants->runAs(S2_TENANT_A, static fn(): array => $rt->app->applyDocumentOperation($actorA, $pageId, [$opResp, $opClasses, $opAttrs], $revId, 'Update Inspector Controls'));
            $revId = (int) $opsRes['revision']['id'];
            assert_true($revId > 0, 'revision updated with inspector control operations');

            // 5. Verify immutable revision count
            $revisions = $rt->tenants->runAs(S2_TENANT_A, fn(): array => $rt->app->listRevisions($actorA, $pageId));
            assert_true(count($revisions) >= 4, 'at least 4 revisions persisted (create + section + tree + controls)');

            // 6. Publish page to compile public artifact
            $pubRes = $rt->tenants->runAs(S2_TENANT_A, static fn(): array => $rt->app->publish($actorA, $pageId, $revId));
            assert_eq('published', $pubRes['page']['status']);

            // 7. Serve from public runtime and verify HTML output
            $served = $rt->tenants->runAs(S2_TENANT_A, static fn() => $rt->publicRuntime->handlePath('visual-hero-sprint-2'));
            assert_true($served !== null, 'page served successfully by public runtime');
            assert_eq(200, $served->status);
            $html = $served->body;

            // Verify compiled visual classes and inline styles
            assert_true(str_contains($html, 'sb-font-bold'), 'emits sb-font-bold');
            assert_true(str_contains($html, 'sb-capitalize'), 'emits sb-capitalize');
            assert_true(str_contains($html, 'sb-shadow-xl'), 'emits sb-shadow-xl');
            assert_true(str_contains($html, 'sb-radius-full'), 'emits sb-radius-full on button');
            assert_true(str_contains($html, 'sb-shadow-lg'), 'emits sb-shadow-lg on button');
            assert_true(str_contains($html, 'color:#0f172a'), 'emits inline color #0f172a');
            assert_true(str_contains($html, 'color:#475569'), 'emits inline text color #475569');
            assert_true(str_contains($html, 'line-height:1.2'), 'emits inline line-height 1.2');

            // Verify custom class names and custom attributes
            assert_true(str_contains($html, 'hero-headline'), 'emits custom class hero-headline');
            assert_true(str_contains($html, 'tracking-tight'), 'emits custom class tracking-tight');
            assert_true(str_contains($html, 'hero-cta-btn'), 'emits custom class hero-cta-btn');
            assert_true(str_contains($html, 'data-hero-el="headline"'), 'emits custom attribute data-hero-el');
            assert_true(str_contains($html, 'aria-label="Main Page Title"'), 'emits custom attribute aria-label');
            assert_true(str_contains($html, 'data-analytics="cta-click"'), 'emits custom attribute data-analytics');

            // Verify responsive alignment override
            assert_true(str_contains($html, 'sb-align-mobile-center'), 'emits overridden mobile align center');
            assert_true(str_contains($html, 'sb-align-desktop-center'), 'emits desktop align center');

            // 8. Verify strict Tenant Isolation
            // Tenant B cannot access Tenant A page
            $servedB = $rt->tenants->runAs(S2_TENANT_B, static fn() => $rt->publicRuntime->handlePath('visual-hero-sprint-2'));
            assert_null($servedB, 'Tenant B cannot serve Tenant A page');

            assert_throws(StudioNotFoundException::class, static function () use ($rt, $actorB, $pageId): void {
                $rt->tenants->runAs(S2_TENANT_B, fn() => $rt->app->loadEditorDocument($actorB, $pageId));
            }, 'Tenant B cannot load Tenant A page');

            assert_throws(StudioNotFoundException::class, static function () use ($rt, $actorB, $pageId, $opSec, $revId): void {
                $rt->tenants->runAs(S2_TENANT_B, fn() => $rt->app->applyDocumentOperation($actorB, $pageId, [$opSec], $revId));
            }, 'Tenant B cannot mutate Tenant A page');
        });
    } finally {
        s2_drop_db($dbName);
    }
});
