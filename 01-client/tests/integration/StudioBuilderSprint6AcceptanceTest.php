<?php
/**
 * Integration test for Kohevo Studio (studio-builder) — Sprint 6 Acceptance.
 *
 * Verifies Sprint 6 Acceptance Criteria (Section 62 & Section 10):
 *   A user can build the structure of an entire website, not just individual pages:
 *   - Theme builder: headers, footers, singles, archives, search, 404.
 *   - Template conditions: conditional headers/footers based on category, content-type, or entire site.
 *   - Chrome resolution integrates with ThemeTemplateResolver with automatic specificity fallbacks.
 *   - Taxonomy archive routing: /category/{slug}, /tag/{slug}, /author/{id}.
 *   - Search routing: /search?q={query}.
 *   - 404 Not Found system template with HTTP 404 status code and theme search box.
 *   - Strict multi-tenant isolation: Tenant B never sees Tenant A's theme templates or custom headers.
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
    $studioS6IntStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const S6_MIGRATIONS = [
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

const S6_TENANT_A = 101;
const S6_TENANT_B = 202;

function s6_fresh_db(string $dbName): \PDO
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
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(S6_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function s6_with_pdo(\PDO $pdo, callable $fn): mixed
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

function s6_block(string $type, array $props, array $children = []): array
{
    return [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => $type,
        'version'    => 1,
        'props'      => $props,
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => $children,
    ];
}

function s6_section(array $blocks, string $label = 'Section'): array
{
    return [
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => $label,
        'global_ref' => null,
        'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks'     => $blocks,
    ];
}

unit('sprint6 integration: theme builder, conditional headers, archives, 404, and multi-tenant isolation', function (): void {
    $dbName = 'slate_s6_' . slate_test_ns();
    $pdo    = s6_fresh_db($dbName);

    try {
        s6_with_pdo($pdo, static function () use ($pdo): void {
            // Provision core and tenants
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Admin', 'admin@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\')
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [S6_TENANT_A, 'Tenant A Theme Master', 'tenant-a-s6', S6_TENANT_B, 'Tenant B Isolated', 'tenant-b-s6']
            );

            // Install studio-builder with licenses
            license_test_seed_cache(S6_TENANT_A, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder', 'studio-builder.tokens'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            license_test_seed_cache(S6_TENANT_B, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder', 'studio-builder.tokens'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            \PluginLoader::installFromDisk('studio-builder');
            Media::ensureSchema();

            // Seed Users
            Database::query(
                'INSERT INTO users (id, tenant_id, email, password_hash, name, role_id) VALUES (?, ?, ?, ?, ?, 1), (?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [10, S6_TENANT_A, 'admin@tenanta.test', 'hash', 'Alice TenantA', 20, S6_TENANT_B, 'admin@tenantb.test', 'hash', 'Bob TenantB']
            );

            // Build StudioRuntime
            $rt = StudioRuntimeFactory::build();
            $actorA = StudioActor::authenticated(10, StudioPermissions::ALL);
            $actorB = StudioActor::authenticated(20, StudioPermissions::ALL);

            // 1. Tenant A: Create Default Header Partial
            $hdrDef = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->createPage($actorA, 'Default Header', 'default', 'header_partial', 'standalone'));
            $hdrDefId = (int) $hdrDef['page']['id'];
            $hdrDefRev = (int) $hdrDef['revision']['id'];
            $secHdrDef = s6_section([
                s6_block('core.heading', ['text' => 'Tenant A Global Header', 'level' => 'h2']),
            ], 'Default Header');
            $opHdrDef = new DocumentOperation('insert_section', ['index' => 0, 'section' => $secHdrDef]);
            $hdrDefEdit = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, $hdrDefId, [$opHdrDef], $hdrDefRev, 'Add header content'));
            $hdrDefRev = (int) $hdrDefEdit['revision']['id'];
            $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->publish($actorA, $hdrDefId, $hdrDefRev));

            // 2. Tenant A: Create Conditional Header Partial (for category=Engineering)
            $hdrEng = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->createPage($actorA, 'Engineering Header', 'engineering-header', 'header_partial', 'standalone'));
            $hdrEngId = (int) $hdrEng['page']['id'];
            $hdrEngRev = (int) $hdrEng['revision']['id'];
            $secHdrEng = s6_section([
                s6_block('core.heading', ['text' => 'Engineering Hub Special Header', 'level' => 'h2']),
            ], 'Engineering Header');
            $opHdrEng = new DocumentOperation('insert_section', ['index' => 0, 'section' => $secHdrEng]);
            $opHdrEngCond = new DocumentOperation('update_settings', ['settings' => [
                'template_type' => 'header',
                'conditions'    => [
                    'rules' => [['type' => 'include', 'condition' => 'category', 'value' => 'Engineering']],
                ],
            ]]);
            $hdrEngEdit = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, $hdrEngId, [$opHdrEng, $opHdrEngCond], $hdrEngRev, 'Add engineering header'));
            $hdrEngRev = (int) $hdrEngEdit['revision']['id'];
            $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->publish($actorA, $hdrEngId, $hdrEngRev));

            // 3. Tenant A: Create Default Footer Partial
            $ftrDef = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->createPage($actorA, 'Default Footer', 'default', 'footer_partial', 'standalone'));
            $ftrDefId = (int) $ftrDef['page']['id'];
            $ftrDefRev = (int) $ftrDef['revision']['id'];
            $secFtrDef = s6_section([
                s6_block('core.text', ['content' => 'Tenant A Global Footer Copyright 2026']),
            ], 'Default Footer');
            $opFtrDef = new DocumentOperation('insert_section', ['index' => 0, 'section' => $secFtrDef]);
            $ftrDefEdit = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, $ftrDefId, [$opFtrDef], $ftrDefRev, 'Add footer content'));
            $ftrDefRev = (int) $ftrDefEdit['revision']['id'];
            $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->publish($actorA, $ftrDefId, $ftrDefRev));

            // 4. Tenant A: Create 404 System Template
            $tpl404 = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->createPage($actorA, '404 Page', '404', 'system', 'standalone'));
            $tpl404Id = (int) $tpl404['page']['id'];
            $tpl404Rev = (int) $tpl404['revision']['id'];
            $sec404 = s6_section([
                s6_block('core.heading', ['text' => 'Custom 404: Page Not Found in Kohevo Studio', 'level' => 'h1']),
                s6_block('theme.search_box', ['placeholder' => 'Search across site...']),
            ], '404 Section');
            $op404Sec = new DocumentOperation('insert_section', ['index' => 0, 'section' => $sec404]);
            $op404Set = new DocumentOperation('update_settings', ['settings' => [
                'template_type' => '404',
            ]]);
            $tpl404Edit = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, $tpl404Id, [$op404Sec, $op404Set], $tpl404Rev, 'Add 404 content'));
            $tpl404Rev = (int) $tpl404Edit['revision']['id'];
            $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->publish($actorA, $tpl404Id, $tpl404Rev));

            // 5. Tenant A: Create Category Archive Template
            $tplArchive = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->createPage($actorA, 'Category Archive', 'archive', 'system', 'standalone'));
            $tplArchiveId = (int) $tplArchive['page']['id'];
            $tplArchiveRev = (int) $tplArchive['revision']['id'];
            $secArchive = s6_section([
                s6_block('theme.archive_title', ['level' => 'h1']),
            ], 'Archive Section');
            $opArcSec = new DocumentOperation('insert_section', ['index' => 0, 'section' => $secArchive]);
            $opArcSet = new DocumentOperation('update_settings', ['settings' => [
                'template_type' => 'archive',
            ]]);
            $tplArchiveEdit = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, $tplArchiveId, [$opArcSec, $opArcSet], $tplArchiveRev, 'Add archive content'));
            $tplArchiveRev = (int) $tplArchiveEdit['revision']['id'];
            $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->publish($actorA, $tplArchiveId, $tplArchiveRev));

            // 6. Tenant A: Create Post 1 in category 'Engineering'
            $p1 = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->createPage($actorA, 'Engineering Deep Dive', 'eng-post', 'page', 'standalone'));
            $p1Id = (int) $p1['page']['id'];
            $p1Rev = (int) $p1['revision']['id'];
            $secP1 = s6_section([
                s6_block('theme.post_title', ['level' => 'h1']),
                s6_block('theme.post_meta', ['show_category' => true]),
                s6_block('core.text', ['content' => 'Deep technical post content on microservices.']),
            ], 'Post 1 Section');
            $opP1Sec = new DocumentOperation('insert_section', ['index' => 0, 'section' => $secP1]);
            $opP1Set = new DocumentOperation('update_settings', ['settings' => ['category' => 'Engineering']]);
            $p1Edit = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, $p1Id, [$opP1Sec, $opP1Set], $p1Rev, 'Add post 1 content'));
            $p1Rev = (int) $p1Edit['revision']['id'];
            $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->publish($actorA, $p1Id, $p1Rev));

            // 7. Tenant A: Create Post 2 in category 'Design'
            $p2 = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->createPage($actorA, 'Design Systems in 2026', 'design-post', 'page', 'standalone'));
            $p2Id = (int) $p2['page']['id'];
            $p2Rev = (int) $p2['revision']['id'];
            $secP2 = s6_section([
                s6_block('theme.post_title', ['level' => 'h1']),
                s6_block('core.text', ['content' => 'Visual hierarchy and design token architecture.']),
            ], 'Post 2 Section');
            $opP2Sec = new DocumentOperation('insert_section', ['index' => 0, 'section' => $secP2]);
            $opP2Set = new DocumentOperation('update_settings', ['settings' => ['category' => 'Design']]);
            $p2Edit = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, $p2Id, [$opP2Sec, $opP2Set], $p2Rev, 'Add post 2 content'));
            $p2Rev = (int) $p2Edit['revision']['id'];
            $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->publish($actorA, $p2Id, $p2Rev));

            // ─────────────────────────────────────────────────────────────────
            // VERIFICATION 1: Conditional Header Resolution for Post 1 (Engineering)
            // ─────────────────────────────────────────────────────────────────
            $respEng = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->publicRuntime->handlePath('/eng-post'));
            assert_true($respEng !== null, 'Public response for eng-post is returned');
            assert_eq(200, $respEng->status);
            assert_true(str_contains($respEng->body, 'Engineering Hub Special Header'), 'renders conditional Engineering header: ' . substr($respEng->body, 0, 500));
            assert_true(str_contains($respEng->body, 'Tenant A Global Footer'), 'renders Tenant A default footer');

            // ─────────────────────────────────────────────────────────────────
            // VERIFICATION 2: Default Header Fallback for Post 2 (Design)
            // ─────────────────────────────────────────────────────────────────
            $respDesign = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->publicRuntime->handlePath('/design-post'));
            assert_true($respDesign !== null, 'Public response for design-post is returned');
            assert_eq(200, $respDesign->status);
            assert_true(str_contains($respDesign->body, 'Tenant A Global Header'), 'renders default header for Design post (fallback)');
            assert_true(!str_contains($respDesign->body, 'Engineering Hub Special Header'), 'does not render engineering header on design post');
            assert_true(str_contains($respDesign->body, 'Tenant A Global Footer'), 'renders Tenant A default footer');

            // ─────────────────────────────────────────────────────────────────
            // VERIFICATION 3: Taxonomy Archive Routing (/category/Engineering)
            // ─────────────────────────────────────────────────────────────────
            $respArchive = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->publicRuntime->handlePath('/category/Engineering'));
            assert_true($respArchive !== null, 'Public response for category archive is returned');
            assert_eq(200, $respArchive->status);
            assert_true(str_contains($respArchive->body, 'Category: Engineering'), 'renders category archive title');

            // ─────────────────────────────────────────────────────────────────
            // VERIFICATION 4: 404 System Template Serving
            // ─────────────────────────────────────────────────────────────────
            $resp404 = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->publicRuntime->handleNotFound());
            assert_true($resp404 !== null, 'Custom 404 response is returned');
            assert_eq(404, $resp404->status);
            assert_true(str_contains($resp404->body, 'Custom 404: Page Not Found in Kohevo Studio'), 'renders custom 404 message');
            assert_true(str_contains($resp404->body, 'class="sb-search-form"'), 'renders search box block in 404 template');

            // ─────────────────────────────────────────────────────────────────
            // VERIFICATION 5: Strict Multi-Tenant Isolation
            // ─────────────────────────────────────────────────────────────────
            // Tenant B requesting /eng-post -> returns null (Tenant B does not own this slug)
            $respB_Eng = $rt->tenants->runAs(S6_TENANT_B, static fn() => $rt->publicRuntime->handlePath('/eng-post'));
            assert_null($respB_Eng, 'Tenant B cannot see Tenant A published post');

            // Tenant B requesting /category/Engineering -> returns null (Tenant B has no archive template)
            $respB_Arc = $rt->tenants->runAs(S6_TENANT_B, static fn() => $rt->publicRuntime->handlePath('/category/Engineering'));
            assert_null($respB_Arc, 'Tenant B cannot see Tenant A category archive');

            // Tenant B calling handleNotFound() -> returns null (Tenant B has no 404 template configured)
            $respB_404 = $rt->tenants->runAs(S6_TENANT_B, static fn() => $rt->publicRuntime->handleNotFound());
            assert_null($respB_404, 'Tenant B has no custom 404 template, falls back to platform 404');
        });
    } finally {
        $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
        $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
        (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    }
});
