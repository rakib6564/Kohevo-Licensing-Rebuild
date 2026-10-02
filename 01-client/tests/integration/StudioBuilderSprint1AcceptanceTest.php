<?php
/**
 * Integration test for Kohevo Studio (studio-builder) — Sprint 1 Acceptance.
 *
 * Verifies Sprint 1 Acceptance Criteria (Section 57):
 *   A developer must be able to create:
 *     Hero Section (layout.section)
 *      └─ Container (layout.container)
 *          ├─ Heading (core.heading)
 *          ├─ Text (core.text)
 *          └─ Button (core.button)
 *   configured across desktop, tablet, and mobile,
 *   safely persisted in immutable revisions, and publicly rendered,
 *   with strict multi-tenant isolation preserved.
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
    $studioS1IntStandalone = true;
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
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const S1_MIGRATIONS = [
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

const S1_TENANT_A = 101;
const S1_TENANT_B = 202;

function s1_fresh_db(string $dbName): \PDO
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
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(S1_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function s1_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function s1_with_pdo(\PDO $pdo, callable $fn): mixed
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

unit('sprint1 integration: full nested visual tree (Hero -> Container -> Heading, Text, Button) persists and publicly renders', function (): void {
    $dbName = 'slate_s1_' . slate_test_ns();
    $pdo = s1_fresh_db($dbName);

    try {
        s1_with_pdo($pdo, static function (): void {
            // Provision core and tenants
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Admin', 'admin@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\')
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [S1_TENANT_A, 'Tenant A', 'tenant-a-s1', S1_TENANT_B, 'Tenant B', 'tenant-b-s1']
            );

            // Install studio-builder
            license_test_seed_cache(S1_TENANT_A, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            license_test_seed_cache(S1_TENANT_B, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            \PluginLoader::installFromDisk('studio-builder');
            Media::ensureSchema();

            $rt = StudioRuntimeFactory::build();
            $actorA = StudioActor::authenticated(42, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            $actorB = StudioActor::authenticated(99, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);

            // 1. Create page for Tenant A
            $created = $rt->tenants->runAs(S1_TENANT_A, static fn(): array => $rt->app->createPage($actorA, 'Sprint 1 Acceptance', 'hero-sprint-1', 'page', 'standalone'));
            $pageId = (int) $created['page']['id'];
            $revId = (int) $created['revision']['id'];
            assert_true($pageId > 0, 'page created');

            // 2. Build the nested Sprint 1 Acceptance visual tree:
            // Section -> Container -> [Heading, Text, Button]
            $btnBlock = [
                'id' => CanonicalDocumentSchema::newBlockId(),
                'type' => 'core.button',
                'version' => 1,
                'props' => [
                    'link' => ['href' => '/get-started', 'label' => 'Get Started Now', 'target' => '_self'],
                    'variant' => 'primary',
                    'size' => 'lg',
                    'full_width' => false,
                ],
                'style' => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings' => [],
                'children' => [],
            ];

            $textBlock = [
                'id' => CanonicalDocumentSchema::newBlockId(),
                'type' => 'core.text',
                'version' => 1,
                'props' => [
                    'content' => 'Visual composition runtime for modern multi-tenant sites.',
                    'size' => 'lead',
                    'align' => 'center',
                ],
                'style' => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings' => [],
                'children' => [],
            ];

            $headingBlock = [
                'id' => CanonicalDocumentSchema::newBlockId(),
                'type' => 'core.heading',
                'version' => 1,
                'props' => [
                    'text' => 'Build something remarkable',
                    'level' => 'h1',
                ],
                'style' => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings' => [],
                'children' => [],
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
                    'padding' => 'md',
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
                    'background_token' => null,
                ],
                'style' => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings' => [],
                'children' => [$containerBlock],
            ];

            // 3. Persist the tree via application service operations
            // Insert an outer section first, then the nested layout tree into it
            $opSec = new DocumentOperation('insert_section', ['index' => 0, 'section' => ['label' => 'Hero Section']]);
            $sRes = $rt->tenants->runAs(S1_TENANT_A, static fn(): array => $rt->app->applyDocumentOperation($actorA, $pageId, [$opSec], $revId, 'Insert Hero Section'));
            $revId = (int) $sRes['revision']['id'];
            $secDoc = CanonicalJson::decode((string) $sRes['revision']['document_json']);
            $secId = (string) $secDoc['sections'][0]['id'];

            $opBlock = new DocumentOperation('insert_block', ['parent_id' => $secId, 'index' => 0, 'block' => $sectionBlock]);
            $insertRes = $rt->tenants->runAs(S1_TENANT_A, static fn(): array => $rt->app->applyDocumentOperation($actorA, $pageId, [$opBlock], $revId, 'Insert Visual Tree'));
            $revId = (int) $insertRes['revision']['id'];
            assert_true($revId > 0, 'revision updated with visual tree');

            // 4. Verify canonical document structure and immutability
            $revisions = $rt->tenants->runAs(S1_TENANT_A, fn(): array => $rt->app->listRevisions($actorA, $pageId));
            assert_true(count($revisions) >= 3, 'immutable revision history persisted (created + section + blocks)');
            $editorState = $rt->tenants->runAs(S1_TENANT_A, fn(): array => $rt->app->loadEditorDocument($actorA, $pageId));
            $latestDoc = $editorState['document'];
            assert_eq('layout.section', $latestDoc['sections'][0]['blocks'][0]['type']);
            assert_eq('layout.container', $latestDoc['sections'][0]['blocks'][0]['children'][0]['type']);
            assert_eq(3, count($latestDoc['sections'][0]['blocks'][0]['children'][0]['children']));

            // 5. Publish page to compile public artifact
            $pubRes = $rt->tenants->runAs(S1_TENANT_A, static fn(): array => $rt->app->publish($actorA, $pageId, $revId));
            assert_eq('published', $pubRes['page']['status']);

            // 6. Serve from public runtime and verify HTML output
            $served = $rt->tenants->runAs(S1_TENANT_A, static fn() => $rt->publicRuntime->handlePath('hero-sprint-1'));
            assert_true($served !== null, 'page served successfully by public runtime');
            assert_eq(200, $served->status);
            $html = $served->body;

            // Verify compiled layout structure
            assert_true(str_contains($html, 'sb-layout-section sb-layout-section--boxed sb-py-xl sb-layout-section--min-screen'), 'hero section classes present');
            assert_true(str_contains($html, 'sb-container sb-container--constrained sb-container--align-center sb-pad-md'), 'layout container classes present');
            assert_true(str_contains($html, 'Build something remarkable'), 'heading text present');
            assert_true(str_contains($html, 'Visual composition runtime for modern multi-tenant sites.'), 'body text present');
            assert_true(str_contains($html, 'Get Started Now'), 'button text present');
            assert_true(str_contains($html, 'sb-button sb-button--primary sb-button--lg'), 'button classes present');

            // Verify responsive configuration
            assert_true(str_contains($html, 'sb-align-desktop-center'), 'desktop responsive align present');
            assert_true(str_contains($html, 'sb-align-tablet-center'), 'tablet responsive align present');
            assert_true(str_contains($html, 'sb-align-mobile-left'), 'mobile responsive align present');

            // 7. Verify strict Tenant Isolation
            // Tenant B cannot serve or see Tenant A's page
            $servedB = $rt->tenants->runAs(S1_TENANT_B, static fn() => $rt->publicRuntime->handlePath('hero-sprint-1'));
            assert_null($servedB, 'Tenant B cannot serve Tenant A page');

            // Tenant B cannot load or mutate Tenant A's page
            assert_throws(StudioNotFoundException::class, static function () use ($rt, $actorB, $pageId): void {
                $rt->tenants->runAs(S1_TENANT_B, fn() => $rt->app->loadEditorDocument($actorB, $pageId));
            }, 'Tenant B cannot get Tenant A page');

            assert_throws(StudioNotFoundException::class, static function () use ($rt, $actorB, $pageId, $opSec, $revId): void {
                $rt->tenants->runAs(S1_TENANT_B, fn() => $rt->app->applyDocumentOperation($actorB, $pageId, [$opSec], $revId));
            }, 'Tenant B cannot mutate Tenant A page');
        });
    } finally {
        s1_drop_db($dbName);
    }
});
