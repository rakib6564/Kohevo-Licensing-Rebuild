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

            // The public entry point serves that 404 template for an address nothing else serves (TB-04).
            $capture = static function (callable $fn): array {
                ob_start();
                try {
                    $handled = $fn();
                } finally {
                    $out = (string) ob_get_clean();
                }
                return [$handled, $out];
            };
            [$handled404, $out404] = $capture(static fn() => $rt->tenants->runAs(S6_TENANT_A, static fn(): bool => \StudioBuilder::servePublicPath(false, 'no-such-page')));
            assert_true($handled404 === true, 'an unknown address is answered by Studio');
            assert_true(str_contains($out404, 'Custom 404: Page Not Found in Kohevo Studio'), 'with the published 404 template');
            [$handledB404] = $capture(static fn() => $rt->tenants->runAs(S6_TENANT_B, static fn(): bool => \StudioBuilder::servePublicPath(false, 'no-such-page')));
            assert_true($handledB404 === false, 'a tenant with no 404 template keeps the platform 404');

            // The search template receives the visitor's query, and the search box shows each visitor
            // their own query rather than one baked into the page (TB-05, TB-07, W-F15).
            $tplSearch = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->createPage($actorA, 'Search Results', 'search-results', 'system', 'standalone'));
            $tplSearchId = (int) $tplSearch['page']['id'];
            $secSearch = s6_section([
                s6_block('theme.archive_title', []),
                s6_block('theme.search_box', ['placeholder' => 'Search across site...']),
            ], 'Search Section');
            $tplSearchEdit = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, $tplSearchId, [
                new DocumentOperation('insert_section', ['index' => 0, 'section' => $secSearch]),
                new DocumentOperation('update_settings', ['settings' => ['template_type' => 'search']]),
            ], (int) $tplSearch['revision']['id'], 'Add search content'));
            $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->publish($actorA, $tplSearchId, (int) $tplSearchEdit['revision']['id']));
            $savedGet = $_GET;
            try {
                $_GET = ['q' => 'kubernetes'];
                [$handledSearch, $outSearch] = $capture(static fn() => $rt->tenants->runAs(S6_TENANT_A, static fn(): bool => \StudioBuilder::servePublicPath(false, 'search')));
                assert_true($handledSearch === true, '/search is served');
                assert_true(str_contains($outSearch, 'Search Results: kubernetes'), 'the template title names the query');
                assert_true(str_contains($outSearch, 'value="kubernetes"'), 'the search box holds the query');
                assert_true((bool) preg_match('~<input type="search"[^>]* aria-label="Search across site\.\.\."~', $outSearch), 'the search box has an accessible name');

                $_GET = ['s' => '<b>docker</b>'];
                [, $outNext] = $capture(static fn() => $rt->tenants->runAs(S6_TENANT_A, static fn(): bool => \StudioBuilder::servePublicPath(false, 'search')));
                assert_true(str_contains($outNext, 'value="&lt;b&gt;docker&lt;/b&gt;"'), 'the next visitor sees their own query, escaped');
                assert_false(str_contains($outNext, 'kubernetes'), 'and not the previous visitor\'s');
                assert_false(str_contains($outNext, '<b>docker</b>'), 'a query is never output as markup');

                $_GET = [];
                [, $outEmpty] = $capture(static fn() => $rt->tenants->runAs(S6_TENANT_A, static fn(): bool => \StudioBuilder::servePublicPath(false, 'search')));
                assert_true(str_contains($outEmpty, '>Search<') && str_contains($outEmpty, 'value=""'), 'no query: the plain search page');
            } finally {
                $_GET = $savedGet;
            }

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

            // TB-09: on a site with more than a hundred pages, a template titled late in the alphabet is
            // still found. 150 published system pages and 150 pages titled "A…" sort before "Search Results".
            $cols = array_values(array_diff($pdo->query('SHOW COLUMNS FROM studiobuilder_pages')->fetchAll(\PDO::FETCH_COLUMN), ['id', 'uuid', 'title', 'slug', 'page_type']));
            $colList = implode(', ', array_map(static fn(string $c): string => "`{$c}`", $cols));
            $sourceId = (int) $pdo->query("SELECT id FROM studiobuilder_pages WHERE tenant_id = " . S6_TENANT_A . " AND slug = 'eng-post'")->fetchColumn();
            assert_true($sourceId > 0, 'a published page to copy');
            $insert = $pdo->prepare("INSERT INTO studiobuilder_pages (uuid, title, slug, page_type, {$colList}) SELECT ?, ?, ?, ?, {$colList} FROM studiobuilder_pages WHERE id = ?");
            foreach (['system', 'page'] as $k => $type) {
                for ($i = 1; $i <= 150; $i++) {
                    $n = $k * 1000 + $i;
                    $insert->execute([sprintf('%08x-0000-4000-8000-%012x', $n, $n), sprintf('A filler %s %03d', $type, $i), sprintf('a-filler-%s-%03d', $type, $i), $type, $sourceId]);
                }
            }
            assert_true((int) $pdo->query("SELECT COUNT(*) FROM studiobuilder_pages WHERE tenant_id = " . S6_TENANT_A . " AND slug LIKE 'a-filler-%' AND status = 'published'")->fetchColumn() === 300, '300 published filler pages');
            $savedGet = $_GET;
            try {
                $_GET = ['q' => 'filler'];
                ob_start();
                try {
                    $served = $rt->tenants->runAs(S6_TENANT_A, static fn(): bool => \StudioBuilder::servePublicPath(false, 'search'));
                } finally {
                    $outMany = (string) ob_get_clean();
                }
                assert_true($served === true && str_contains($outMany, 'Search Results: filler'), 'the search template is still used with 300 more pages titled before it');
            } finally {
                $_GET = $savedGet;
            }

            // TB-08 / W-F19: a published Single template lays out the pages its conditions match. Its Post title
            // and Post meta show the page, its Post content block is the page's own content, and the template
            // itself is never a public page.
            $tplSingle = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->createPage($actorA, 'Engineering Single', 'single-eng', 'system', 'standalone'));
            $tplSingleId = (int) $tplSingle['page']['id'];
            $singleSection = s6_section([
                s6_block('core.heading', ['text' => 'Engineering single layout', 'level' => 'h2']),
                s6_block('theme.post_title', ['level' => 'h1']),
                s6_block('theme.post_meta', ['show_category' => true, 'show_author' => false, 'show_date' => false]),
                s6_block('theme.post_content', []),
            ], 'Single Section');
            $tplSingleEdit = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, $tplSingleId, [
                new DocumentOperation('insert_section', ['index' => 0, 'section' => $singleSection]),
                new DocumentOperation('update_settings', ['settings' => [
                    'template_type' => 'single',
                    'conditions'    => ['rules' => [['condition' => 'category', 'value' => 'Engineering', 'type' => 'include']]],
                ]]),
            ], (int) $tplSingle['revision']['id'], 'Single layout'));
            $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->publish($actorA, $tplSingleId, (int) $tplSingleEdit['revision']['id']));

            $single = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->publicRuntime->handlePath('/eng-post'));
            assert_true($single !== null && $single->status === 200, 'the matching page is served');
            assert_true(str_contains($single->body, 'Engineering single layout'), 'inside its Single template');
            assert_true((bool) preg_match('~<h1 class="sb-post-title[^"]*">Engineering Deep Dive</h1>~', $single->body), 'Post title shows the page title');
            assert_true(str_contains($single->body, 'sb-meta-category">Engineering<'), 'Post meta shows the page category');
            assert_true((bool) preg_match('~class="sb-block sb-block--theme-post-content sb-post-body">.*Deep technical post content on microservices\.~s', $single->body), 'Post content is the page\'s own content');
            assert_false(str_contains($single->body, 'Lorem ipsum') || str_contains($single->body, 'Single Post Title'), 'no placeholder text anywhere');
            assert_true(strpos($single->body, 'Engineering single layout') < strpos($single->body, 'Deep technical post content'), 'the template content comes first, as laid out');

            $other = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->publicRuntime->handlePath('/design-post'));
            assert_true($other !== null && !str_contains($other->body, 'Engineering single layout'), 'a page the conditions do not match keeps its own layout');
            assert_null($rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->publicRuntime->handlePath('/single-eng')), 'the template is not a public page');
            $editor = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->renderForEditor($actorA, $p1Id));
            assert_false(str_contains($editor->html, 'Engineering single layout'), 'the editor canvas edits the page itself, without the template');

            // Publishing a change to the template reaches the page without republishing the page.
            $tplSingleDraft = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->loadEditorDocument($actorA, $tplSingleId));
            $headingId = (string) $tplSingleDraft['document']['sections'][0]['blocks'][0]['id'];
            $tplSingleEdit2 = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, $tplSingleId, [
                new DocumentOperation('update_block_props', ['block_id' => $headingId, 'props' => ['text' => 'Engineering layout v2', 'level' => 'h2']]),
            ], (int) $tplSingleDraft['revision']['id'], 'Rename'));
            $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->publish($actorA, $tplSingleId, (int) $tplSingleEdit2['revision']['id']));
            $again = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->publicRuntime->handlePath('/eng-post'));
            assert_true($again !== null && str_contains($again->body, 'Engineering layout v2') && !str_contains($again->body, 'Engineering single layout'), 'the page shows the republished template');

            // TB-01 residual: header conditions read the PUBLISHED page's category and tags. A page published with
            // neither, whose unpublished draft adds category=Engineering and tag "launch", keeps the default header.
            $hdrTag = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->createPage($actorA, 'Launch Header', 'launch-header', 'header_partial', 'standalone'));
            $hdrTagEdit = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, (int) $hdrTag['page']['id'], [
                new DocumentOperation('insert_section', ['index' => 0, 'section' => s6_section([s6_block('core.heading', ['text' => 'Launch Tag Header', 'level' => 'h2'])], 'Launch Header')]),
                new DocumentOperation('update_settings', ['settings' => ['template_type' => 'header', 'conditions' => ['rules' => [['action' => 'include', 'condition' => 'tag', 'value' => 'launch']]]]]),
            ], (int) $hdrTag['revision']['id'], 'Launch header'));
            $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->publish($actorA, (int) $hdrTag['page']['id'], (int) $hdrTagEdit['revision']['id']));

            $plain = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->createPage($actorA, 'Plain Post', 'plain-post', 'page', 'standalone'));
            $plainEdit = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, (int) $plain['page']['id'], [
                new DocumentOperation('insert_section', ['index' => 0, 'section' => s6_section([s6_block('core.text', ['content' => 'Plain post body.'])], 'Plain')]),
            ], (int) $plain['revision']['id'], 'Plain content'));
            $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->publish($actorA, (int) $plain['page']['id'], (int) $plainEdit['revision']['id']));
            $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, (int) $plain['page']['id'], [
                new DocumentOperation('update_settings', ['settings' => ['category' => 'Engineering', 'tags' => ['launch']]]),
            ], (int) $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->loadEditorDocument($actorA, (int) $plain['page']['id']))['revision']['id'], 'Draft category and tag'));
            $plainDraftSettings = (string) $pdo->query('SELECT settings_json FROM studiobuilder_pages WHERE id = ' . (int) $plain['page']['id'])->fetchColumn();
            assert_true(str_contains($plainDraftSettings, 'Engineering'), 'precondition: the draft category is in the page row');
            $plainLive = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->publicRuntime->handlePath('/plain-post'));
            assert_true($plainLive !== null && str_contains($plainLive->body, 'Tenant A Global Header'), 'the live page keeps the default header');
            assert_false(str_contains($plainLive->body, 'Engineering Hub Special Header'), 'a draft category does not pick the live header');
            assert_false(str_contains($plainLive->body, 'Launch Tag Header'), 'nor does a draft tag');

            $tagged = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->createPage($actorA, 'Tagged Post', 'tagged-post', 'page', 'standalone'));
            $taggedEdit = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, (int) $tagged['page']['id'], [
                new DocumentOperation('update_settings', ['settings' => ['tags' => ['launch']]]),
            ], (int) $tagged['revision']['id'], 'Tag'));
            $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->publish($actorA, (int) $tagged['page']['id'], (int) $taggedEdit['revision']['id']));
            $taggedLive = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->publicRuntime->handlePath('/tagged-post'));
            assert_true($taggedLive !== null && str_contains($taggedLive->body, 'Launch Tag Header'), 'a published tag picks its header');

            // TB-14: "Preview as page" on a page that hides its header and footer keeps them hidden, as the live site does.
            $bare = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->createPage($actorA, 'Bare Landing', 'bare-landing', 'page', 'standalone'));
            $bareEdit = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, (int) $bare['page']['id'], [
                new DocumentOperation('insert_section', ['index' => 0, 'section' => s6_section([s6_block('core.text', ['content' => 'Bare landing body.'])], 'Bare')]),
                new DocumentOperation('update_settings', ['settings' => ['header_mode' => 'hidden', 'footer_mode' => 'hidden']]),
            ], (int) $bare['revision']['id'], 'No chrome'));
            $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->publish($actorA, (int) $bare['page']['id'], (int) $bareEdit['revision']['id']));
            $bareLive = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->publicRuntime->handlePath('/bare-landing'));
            assert_true($bareLive !== null && !str_contains($bareLive->body, 'Tenant A Global Header'), 'precondition: the live page has no header');
            $hdrOnBare = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->renderPreview($actorA, $hdrEngId, null, (int) $bare['page']['id']));
            assert_true(str_contains($hdrOnBare->html, 'Bare landing body.'), 'the host page is shown');
            assert_false(str_contains($hdrOnBare->html, 'Engineering Hub Special Header'), 'without the previewed header');
            $ftrOnBare = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->renderPreview($actorA, $ftrDefId, null, (int) $bare['page']['id']));
            assert_false(str_contains($ftrOnBare->html, 'Tenant A Global Footer'), 'and without the previewed footer');
            $hdrOnDesign = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->renderPreview($actorA, $hdrEngId, null, $p2Id));
            assert_true(str_contains($hdrOnDesign->html, 'Engineering Hub Special Header'), 'a page with a header still previews the edited one');

            // TB-10: two "entire site" footers tie; the one published last wins, and renaming either changes nothing.
            $siteFooter = static function (string $title, string $slug, string $text) use ($rt, $actorA): int {
                $f = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->createPage($actorA, $title, $slug, 'footer_partial', 'standalone'));
                $e = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, (int) $f['page']['id'], [
                    new DocumentOperation('insert_section', ['index' => 0, 'section' => s6_section([s6_block('core.text', ['content' => $text])], $title)]),
                    new DocumentOperation('update_settings', ['settings' => ['template_type' => 'footer', 'conditions' => ['rules' => [['action' => 'include', 'condition' => 'entire_site']]]]]),
                ], (int) $f['revision']['id'], $title));
                $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->publish($actorA, (int) $f['page']['id'], (int) $e['revision']['id']));
                return (int) $f['page']['id'];
            };
            $alphaId = $siteFooter('Alpha Footer', 'alpha-footer', 'Alpha footer text');
            $zuluId  = $siteFooter('Zulu Footer', 'zulu-footer', 'Zulu footer text');
            $pdo->prepare('UPDATE studiobuilder_pages SET published_at = ? WHERE id = ?')->execute(['2026-01-01 10:00:00', $alphaId]);
            $pdo->prepare('UPDATE studiobuilder_pages SET published_at = ? WHERE id = ?')->execute(['2026-02-01 10:00:00', $zuluId]);
            $tie = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->publicRuntime->handlePath('/design-post'));
            assert_true($tie !== null && str_contains($tie->body, 'Zulu footer text') && !str_contains($tie->body, 'Alpha footer text'), 'the footer published last wins the tie');
            $pdo->prepare('UPDATE studiobuilder_pages SET title = ? WHERE id = ?')->execute(['Zulu Footer', $alphaId]);
            $pdo->prepare('UPDATE studiobuilder_pages SET title = ? WHERE id = ?')->execute(['Alpha Footer', $zuluId]);
            $tieRenamed = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->publicRuntime->handlePath('/design-post'));
            assert_true($tieRenamed !== null && str_contains($tieRenamed->body, 'Zulu footer text'), 'swapping the titles keeps the same footer');

            // An older Single template stored as a page is not a public page and is not in the sitemap.
            $legacy = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->createPage($actorA, 'Old Single', 'template-old-single', 'page', 'standalone'));
            $legacyEdit = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->applyDocumentOperation($actorA, (int) $legacy['page']['id'], [
                new DocumentOperation('update_settings', ['settings' => ['template_type' => 'single']]),
            ], (int) $legacy['revision']['id'], 'Mark as template'));
            $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->app->publish($actorA, (int) $legacy['page']['id'], (int) $legacyEdit['revision']['id']));
            $pdo->prepare("UPDATE studiobuilder_pages SET settings_json = ? WHERE id = ?")->execute(['{"is_theme_template":true,"template_type":"single"}', (int) $legacy['page']['id']]);
            assert_null($rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->publicRuntime->handlePath('/template-old-single')), 'an old Single template page is not served');
            $sitemap = $rt->tenants->runAs(S6_TENANT_A, static fn() => $rt->publicRuntime->handlePath('sitemap.xml?format=xml'));
            assert_true($sitemap !== null && !str_contains($sitemap->body, 'template-old-single') && !str_contains($sitemap->body, 'single-eng'), 'and not listed in the sitemap');

        });
    } finally {
        $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
        $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
        (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    }
});
