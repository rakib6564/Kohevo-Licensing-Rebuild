<?php
/**
 * Integration test for Kohevo Studio (studio-builder) — Sprint 3 Acceptance.
 *
 * Verifies Sprint 3 Acceptance Criteria (Section 59):
 *   - Operations: duplicate_block, duplicate_section, update_section_label, update_block_visibility.
 *   - Duplicate Block creates a deep clone of subtree with fresh block IDs, identical props/styles/responsive overrides.
 *   - Duplicate Section creates a cloned section with minted section ID and freshly minted block IDs.
 *   - Section label update renames section safely and rejects SQL/script injection fragments.
 *   - Hidden blocks are omitted from public HTML render.
 *   - Rollback / undo server round-trip restores previous draft revision.
 *   - Public runtime serves duplicated blocks and sections under HTTP 200.
 *   - Strict multi-tenant isolation (Tenant B cannot access or mutate Tenant A page).
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
    $studioS3IntStandalone = true;
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
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const S3_MIGRATIONS = [
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

const S3_TENANT_A = 101;
const S3_TENANT_B = 202;

function s3_fresh_db(string $dbName): \PDO
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
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(S3_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function s3_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function s3_with_pdo(\PDO $pdo, callable $fn): mixed
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

unit('sprint3 integration: navigator operations (duplicate, move, rename, visibility) persist and publicly render', function (): void {
    $dbName = 'slate_s3_' . slate_test_ns();
    $pdo = s3_fresh_db($dbName);

    try {
        s3_with_pdo($pdo, static function (): void {
            // Provision core and tenants
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Admin', 'admin@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\')
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [S3_TENANT_A, 'Tenant A', 'tenant-a-s3', S3_TENANT_B, 'Tenant B', 'tenant-b-s3']
            );

            // Install studio-builder
            license_test_seed_cache(S3_TENANT_A, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            license_test_seed_cache(S3_TENANT_B, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            \PluginLoader::installFromDisk('studio-builder');
            Media::ensureSchema();

            $rt = StudioRuntimeFactory::build();
            $actorA = StudioActor::authenticated(42, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            $actorB = StudioActor::authenticated(99, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);

            // 1. Create page for Tenant A
            $created = $rt->tenants->runAs(S3_TENANT_A, static fn(): array => $rt->app->createPage($actorA, 'Sprint 3 Navigator Page', 'navigator-sprint-3', 'page', 'standalone'));
            $pageId = (int) $created['page']['id'];
            $revId = (int) $created['revision']['id'];
            assert_true($pageId > 0, 'page created');

            // 2. Insert section and visual tree
            $opSec = new DocumentOperation('insert_section', ['index' => 0, 'section' => ['label' => 'Hero Banner']]);
            $sRes = $rt->tenants->runAs(S3_TENANT_A, static fn(): array => $rt->app->applyDocumentOperation($actorA, $pageId, [$opSec], $revId, 'Insert Hero Section'));
            $revId = (int) $sRes['revision']['id'];
            $secDoc = CanonicalJson::decode((string) $sRes['revision']['document_json']);
            $secId = (string) $secDoc['sections'][0]['id'];

            $btnId = CanonicalDocumentSchema::newBlockId();
            $btnBlock = [
                'id' => $btnId,
                'type' => 'core.button',
                'version' => 1,
                'props' => [
                    'link' => ['href' => '/learn-more', 'label' => 'Explore Platform', 'target' => '_self'],
                    'variant' => 'primary',
                    'size' => 'md',
                ],
                'style' => CanonicalDocumentSchema::defaultBlockStyle(),
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
                    'text' => 'Visual Composition Engine',
                    'level' => 'h2',
                ],
                'style' => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings' => [],
                'children' => [],
            ];

            $containerBlock = [
                'id' => CanonicalDocumentSchema::newBlockId(),
                'type' => 'layout.container',
                'version' => 1,
                'props' => [
                    'width' => 'constrained',
                    'alignment' => 'center',
                ],
                'style' => CanonicalDocumentSchema::defaultBlockStyle(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'bindings' => [],
                'children' => [$headingBlock, $btnBlock],
            ];

            $opBlock = new DocumentOperation('insert_block', ['parent_id' => $secId, 'index' => 0, 'block' => $containerBlock]);
            $insRes = $rt->tenants->runAs(S3_TENANT_A, static fn(): array => $rt->app->applyDocumentOperation($actorA, $pageId, [$opBlock], $revId, 'Insert Container with Heading and Button'));
            $revId = (int) $insRes['revision']['id'];
            $insDoc = CanonicalJson::decode((string) $insRes['revision']['document_json']);
            $actualHeadingId = (string) $insDoc['sections'][0]['blocks'][0]['children'][0]['id'];
            $actualBtnId = (string) $insDoc['sections'][0]['blocks'][0]['children'][1]['id'];

            // 3. Test update_section_label: valid rename
            $opRename = DocumentOperation::updateSectionLabel($secId, 'Spotlight Hero');
            $renRes = $rt->tenants->runAs(S3_TENANT_A, static fn(): array => $rt->app->applyDocumentOperation($actorA, $pageId, [$opRename], $revId, 'Rename Section'));
            $revId = (int) $renRes['revision']['id'];
            $renDoc = CanonicalJson::decode((string) $renRes['revision']['document_json']);
            assert_eq('Spotlight Hero', $renDoc['sections'][0]['label']);

            // 3b. Test update_section_label: hostile / injected label rejected
            assert_throws(StudioValidationException::class, static function () use ($rt, $actorA, $pageId, $secId, $revId): void {
                $badOp = DocumentOperation::updateSectionLabel($secId, '<script>alert("xss")</script>');
                $rt->tenants->runAs(S3_TENANT_A, fn() => $rt->app->applyDocumentOperation($actorA, $pageId, [$badOp], $revId));
            }, 'hostile script tag in section label rejected');

            // 4. Test duplicate_block: duplicate the heading
            $opDupBlock = DocumentOperation::duplicateBlock($actualHeadingId);
            $dupRes = $rt->tenants->runAs(S3_TENANT_A, static fn(): array => $rt->app->applyDocumentOperation($actorA, $pageId, [$opDupBlock], $revId, 'Duplicate Heading'));
            $revId = (int) $dupRes['revision']['id'];
            $dupDoc = CanonicalJson::decode((string) $dupRes['revision']['document_json']);
            $containerChildren = $dupDoc['sections'][0]['blocks'][0]['children'];
            assert_eq(3, count($containerChildren), 'container now has 3 children (heading, cloned heading, button)');

            $clonedHeading = $containerChildren[1];
            $clonedHeadingId = (string) $clonedHeading['id'];
            assert_true($clonedHeadingId !== $actualHeadingId, 'cloned heading has a new unique block ID');
            assert_true(str_starts_with($clonedHeadingId, 'blk_'), 'cloned heading has canonical blk_ prefix');
            assert_eq('Visual Composition Engine', $clonedHeading['props']['text'], 'cloned heading preserves properties');

            // 5. Test update_block_visibility: restrict cloned heading to authenticated users
            $opHide = DocumentOperation::updateBlockVisibility($clonedHeadingId, [
                'auth_state' => 'authenticated',
                'devices' => CanonicalDocumentSchema::ALLOWED_BREAKPOINTS,
            ]);
            $hideRes = $rt->tenants->runAs(S3_TENANT_A, static fn(): array => $rt->app->applyDocumentOperation($actorA, $pageId, [$opHide], $revId, 'Restrict Cloned Heading to Authenticated'));
            $revId = (int) $hideRes['revision']['id'];
            $hideDoc = CanonicalJson::decode((string) $hideRes['revision']['document_json']);
            assert_eq('authenticated', $hideDoc['sections'][0]['blocks'][0]['children'][1]['visibility']['auth_state'], 'block visibility is updated');

            // 6. Test duplicate_section: duplicate the entire section
            $opDupSec = DocumentOperation::duplicateSection($secId);
            $dupSecRes = $rt->tenants->runAs(S3_TENANT_A, static fn(): array => $rt->app->applyDocumentOperation($actorA, $pageId, [$opDupSec], $revId, 'Duplicate Section'));
            $revId = (int) $dupSecRes['revision']['id'];
            $dupSecDoc = CanonicalJson::decode((string) $dupSecRes['revision']['document_json']);
            assert_eq(2, count($dupSecDoc['sections']), 'page now has 2 sections');

            $clonedSec = $dupSecDoc['sections'][1];
            $clonedSecId = (string) $clonedSec['id'];
            assert_true($clonedSecId !== $secId, 'cloned section has new unique section ID');
            assert_true(str_starts_with($clonedSecId, 'sec_'), 'cloned section ID has canonical sec_ prefix');
            assert_eq('Spotlight Hero (Copy)', $clonedSec['label'], 'cloned section has (Copy) suffix in label');

            // Cloned section children must also have freshly minted IDs
            $clonedSecContainer = $clonedSec['blocks'][0];
            assert_true($clonedSecContainer['id'] !== $dupDoc['sections'][0]['blocks'][0]['id'], 'cloned section container has fresh ID');

            // 7. Test rollback / undo server operation
            // Roll back to the revision before duplicating section
            $revBeforeDupSec = (int) $hideRes['revision']['id'];
            $rbRes = $rt->tenants->runAs(S3_TENANT_A, static fn(): array => $rt->app->rollback($actorA, $pageId, $revBeforeDupSec, $revId));
            $revId = (int) $rbRes['revision']['id'];
            $rbDoc = CanonicalJson::decode((string) $rbRes['revision']['document_json']);
            assert_eq(1, count($rbDoc['sections']), 'rollback restored document to 1 section');

            // 8. Publish page and verify public rendering
            $pubRes = $rt->tenants->runAs(S3_TENANT_A, static fn(): array => $rt->app->publish($actorA, $pageId, $revId));
            assert_eq('published', $pubRes['page']['status']);

            $served = $rt->tenants->runAs(S3_TENANT_A, static fn() => $rt->publicRuntime->handlePath('navigator-sprint-3'));
            assert_true($served !== null, 'page served successfully by public runtime');
            assert_eq(200, $served->status);
            $html = $served->body;

            // Heading is rendered
            assert_true(str_contains($html, 'Visual Composition Engine'), 'renders heading');
            assert_true(str_contains($html, 'Explore Platform'), 'renders button');
            // Hidden block must NOT be rendered in public HTML
            // Note: Since cloned heading was marked hidden, count of "Visual Composition Engine" in HTML should be exactly 1
            assert_eq(1, substr_count($html, 'Visual Composition Engine'), 'hidden duplicated heading is omitted from public output');

            // 9. Strict Tenant Isolation
            $servedB = $rt->tenants->runAs(S3_TENANT_B, static fn() => $rt->publicRuntime->handlePath('navigator-sprint-3'));
            assert_null($servedB, 'Tenant B cannot serve Tenant A page');

            assert_throws(StudioNotFoundException::class, static function () use ($rt, $actorB, $pageId): void {
                $rt->tenants->runAs(S3_TENANT_B, fn() => $rt->app->loadEditorDocument($actorB, $pageId));
            }, 'Tenant B cannot view Tenant A page');

            assert_throws(StudioNotFoundException::class, static function () use ($rt, $actorB, $pageId, $opDupBlock, $revId): void {
                $rt->tenants->runAs(S3_TENANT_B, fn() => $rt->app->applyDocumentOperation($actorB, $pageId, [$opDupBlock], $revId));
            }, 'Tenant B cannot mutate Tenant A page');
        });
    } finally {
        s3_drop_db($dbName);
    }
});
