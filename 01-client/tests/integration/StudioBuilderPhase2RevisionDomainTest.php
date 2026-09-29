<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — Phase 2 Canonical
 * Document Domain: revision lifecycle, page address lifecycle, and template
 * lifecycle, against a real throwaway MySQL database.
 *
 * Unlike the Phase 1 provisioning suite, these tests do not exercise plugin
 * activation or commercial licensing — they execute `plugins/studio-builder/install.sql`
 * directly against the fresh test database to provision the `studiobuilder_*`
 * schema, then exercise the Phase 2
 * services (`StudioRevisionService`, `StudioPageAddressService`, `StudioTemplateService`)
 * directly, since entitlement/RBAC gating is already covered by Phase 1's suite
 * and belongs, for the full command pipeline, to a later phase.
 *
 * Verifies:
 *   1. StudioRevisionService: draft creation, autosave dedup, optimistic
 *      concurrency conflicts, publish, rollback, dependency indexing,
 *      revision immutability, and multi-tenant isolation.
 *   2. StudioPageAddressService: atomic page + first-revision creation,
 *      duplicate slug rejection, address updates, archiving, and multi-tenant
 *      slug independence.
 *   3. StudioTemplateService: save/update, system-template protection,
 *      template application onto a page, and multi-tenant isolation.
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
    $studioP2IntStandalone = true;
}

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Exception\StudioConcurrencyException;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Repository\DependencyRepository;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Module\StudioBuilder\Repository\TemplateRepository;
use Slate\Module\StudioBuilder\Service\StudioPageAddressService;
use Slate\Module\StudioBuilder\Service\StudioRevisionService;
use Slate\Module\StudioBuilder\Service\StudioTemplateService;
use Slate\Services\Installation\InstallationService;
use Slate\Tenancy\TenantContext;

const SBP2_CORE_MIGRATIONS = [
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

function sbp2_fresh_db(string $dbName): \PDO
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
    $runner = new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations');
    $runner->migrate(SBP2_CORE_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));

    $installSql = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/install.sql');
    $installSql = (string) preg_replace('/^--[^\n]*$/m', '', $installSql);
    foreach (array_filter(array_map('trim', explode(';', $installSql))) as $stmt) {
        $pdo->exec($stmt);
    }

    return $pdo;
}

function sbp2_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function sbp2_with_pdo(\PDO $pdo, callable $fn): mixed
{
    $property = new \ReflectionProperty(Database::class, 'pdo');
    $previous = $property->getValue();
    $property->setValue(null, $pdo);

    try {
        return $fn();
    } finally {
        unset($GLOBALS['SLATE_TENANT_OVERRIDE']);
        $property->setValue(null, $previous);
    }
}

/**
 * @return array{tenants: TenantContext, pages: PageRepository, revisions: RevisionRepository,
 *   deps: DependencyRepository, templates: TemplateRepository, registry: BlockRegistry,
 *   revisionService: StudioRevisionService, pageService: StudioPageAddressService,
 *   templateService: StudioTemplateService}
 */
function sbp2_services(): array
{
    $tenants   = new TenantContext();
    $pages     = new PageRepository($tenants);
    $revisions = new RevisionRepository($tenants);
    $deps      = new DependencyRepository($tenants);
    $templates = new TemplateRepository($tenants);
    $registry  = BlockRegistry::withCoreFoundationBlocks();

    $revisionService = new StudioRevisionService($tenants, $pages, $revisions, $deps, $registry);
    $pageService     = new StudioPageAddressService($tenants, $pages, $revisionService, $registry);
    $templateService = new StudioTemplateService($tenants, $templates, $pages, $revisionService, $registry);

    return compact('tenants', 'pages', 'revisions', 'deps', 'templates', 'registry', 'revisionService', 'pageService', 'templateService');
}

/**
 * @return array<string, mixed>
 */
function sbp2_doc(BlockRegistry $registry, string $heading = 'Hello'): array
{
    $hero = [
        'bindings'   => [],
        'children'   => [],
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'props'      => ['eyebrow' => '', 'heading' => $heading, 'subheading' => '', 'primary_cta' => null, 'media' => null, 'accent_token' => null],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'type'       => 'core.hero',
        'version'    => 1,
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ];
    $section = [
        'blocks'     => [$hero],
        'global_ref' => null,
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Main',
        'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ];
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', $heading);
    $doc['sections'] = [$section];
    return DocumentNormalizer::normalize($doc, $registry);
}

unit('phase2 integration: StudioRevisionService full lifecycle, concurrency, dependency indexing, immutability, and tenant isolation', function (): void {
    $dbName = 'slate_sbp2_' . slate_test_ns();
    $pdo = sbp2_fresh_db($dbName);

    try {
        sbp2_with_pdo($pdo, static function () use ($pdo): void {
            $core = InstallationService::provisionCore();
            $tenantId = (int) $core['tenant_id'];

            $svc = sbp2_services();
            ['tenants' => $tenants, 'pages' => $pages, 'revisions' => $revisions, 'deps' => $deps, 'registry' => $registry, 'revisionService' => $revisionService] = $svc;

            $pageId = $tenants->runAs($tenantId, static fn(): int => $pages->insert([
                'uuid'       => '11111111-1111-4111-8111-111111111111',
                'title'      => 'Home',
                'slug'       => 'home',
                'page_type'  => 'page',
                'status'     => 'draft',
                'route_mode' => 'homepage',
            ]));

            // 1. First draft revision (no expected_revision_id yet)
            $doc1 = sbp2_doc($registry, 'Welcome v1');
            $r1 = $tenants->runAs($tenantId, static fn(): array => $revisionService->createDraftRevision($pageId, $doc1, null, 1, 'manual', 'First draft'));
            assert_eq(1, (int) $r1['revision']['revision_number']);
            assert_false($r1['deduplicated']);
            $rev1Id = (int) $r1['revision']['id'];
            assert_eq($rev1Id, (int) $r1['page']['active_draft_revision_id']);

            // 2. Stale expected_revision_id must fail closed
            assert_throws(StudioConcurrencyException::class, function () use ($tenants, $tenantId, $revisionService, $pageId, $doc1): void {
                $tenants->runAs($tenantId, static fn() => $revisionService->createDraftRevision($pageId, $doc1, 999999, 1, 'manual'));
            });

            // 3. Autosave with identical content must deduplicate (no new revision row)
            $rDedup = $tenants->runAs($tenantId, static fn(): array => $revisionService->createDraftRevision($pageId, $doc1, $rev1Id, 1, 'autosave'));
            assert_true($rDedup['deduplicated']);
            assert_eq($rev1Id, (int) $rDedup['revision']['id']);

            // 4. Manual save with different content creates revision #2
            $doc2 = sbp2_doc($registry, 'Welcome v2');
            $r2 = $tenants->runAs($tenantId, static fn(): array => $revisionService->createDraftRevision($pageId, $doc2, $rev1Id, 1, 'manual', 'Second draft'));
            assert_eq(2, (int) $r2['revision']['revision_number']);
            assert_eq($rev1Id, (int) $r2['revision']['parent_revision_id']);
            $rev2Id = (int) $r2['revision']['id'];

            // 5. Publish the working revision
            $pub = $tenants->runAs($tenantId, static fn(): array => $revisionService->publishWorkingRevision($pageId, $rev2Id, 1, 'Go live'));
            assert_eq('publish', $pub['revision']['revision_kind']);
            $pubRevId = (int) $pub['revision']['id'];
            assert_eq($pubRevId, (int) $pub['page']['published_revision_id']);
            assert_eq($pubRevId, (int) $pub['page']['active_draft_revision_id']);
            assert_eq('published', $pub['page']['status']);
            assert_true($pub['page']['published_at'] !== null);

            // 6. Revisions are strictly immutable
            assert_throws(\LogicException::class, function () use ($revisions, $rev1Id): void {
                $revisions->update($rev1Id, ['summary' => 'tampered']);
            });
            assert_throws(\LogicException::class, function () use ($revisions, $rev1Id): void {
                $revisions->delete($rev1Id);
            });

            // 7. Rollback to revision #1 creates a NEW revision; published pointer untouched
            $rollback = $tenants->runAs($tenantId, static fn(): array => $revisionService->rollbackToRevision($pageId, $rev1Id, $pubRevId, 1, 'Back to v1'));
            assert_eq('rollback', $rollback['revision']['revision_kind']);
            assert_eq($rev1Id, $rollback['rolled_back_from_revision_id']);
            assert_eq(4, (int) $rollback['revision']['revision_number']);
            assert_eq($pubRevId, (int) $rollback['page']['published_revision_id'], 'rollback must not touch published_revision_id');
            $rollbackRevId = (int) $rollback['revision']['id'];
            assert_eq($rollbackRevId, (int) $rollback['page']['active_draft_revision_id']);

            // 8. Dependency indexing: a doc with a media_ref must produce a persisted dependency row
            $docWithMedia = sbp2_doc($registry, 'Has Media');
            $docWithMedia['sections'][0]['blocks'][0]['props']['media'] = ['media_id' => 55, 'alt' => 'Alt', 'focal_point' => [0.5, 0.5]];
            $docWithMedia = DocumentNormalizer::normalize($docWithMedia, $registry);
            $withMedia = $tenants->runAs($tenantId, static fn(): array => $revisionService->createDraftRevision($pageId, $docWithMedia, $rollbackRevId, 1, 'manual', 'With media'));
            $withMediaRevId = (int) $withMedia['revision']['id'];
            $depRows = $tenants->runAs($tenantId, static fn(): array => $deps->forPageRevision($pageId, $withMediaRevId));
            $mediaDepFound = false;
            foreach ($depRows as $row) {
                if ($row['dependency_type'] === 'media' && $row['dependency_key'] === '55') {
                    $mediaDepFound = true;
                }
            }
            assert_true($mediaDepFound, 'createDraftRevision must persist extracted dependency rows');

            // 9. Multi-tenant isolation: a second tenant cannot see or act on tenant 1's page
            $tenantId2 = $tenantId + 1000;
            assert_throws(StudioNotFoundException::class, function () use ($tenants, $tenantId2, $revisionService, $pageId, $doc1): void {
                $tenants->runAs($tenantId2, static fn() => $revisionService->createDraftRevision($pageId, $doc1, null, 1, 'manual'));
            });
            $tenants->runAs($tenantId2, static function () use ($pages, $pageId): void {
                assert_null($pages->find($pageId));
            });
        });
    } finally {
        sbp2_drop_db($dbName);
    }
});

unit('phase2 integration: StudioPageAddressService creates pages atomically, rejects duplicate slugs, updates addresses, archives, and isolates tenants', function (): void {
    $dbName = 'slate_sbp2_' . slate_test_ns();
    $pdo = sbp2_fresh_db($dbName);

    try {
        sbp2_with_pdo($pdo, static function () use ($pdo): void {
            $core = InstallationService::provisionCore();
            $tenantId = (int) $core['tenant_id'];

            $svc = sbp2_services();
            ['tenants' => $tenants, 'pageService' => $pageService, 'revisions' => $revisions] = $svc;

            $created = $tenants->runAs($tenantId, static fn(): array => $pageService->createPage('About Us', 'about-us', 'page', 'standalone', 1));
            assert_eq('About Us', $created['page']['title']);
            assert_eq(1, (int) $created['revision']['revision_number']);
            assert_eq((int) $created['revision']['id'], (int) $created['page']['active_draft_revision_id']);

            $decodedDoc = CanonicalJson::decode((string) $created['revision']['document_json']);
            assert_eq([], $decodedDoc['sections'], 'a freshly created page must start with a blank document');

            $pageId = (int) $created['page']['id'];

            // Duplicate slug + page_type must be rejected
            assert_throws(StudioValidationException::class, function () use ($tenants, $tenantId, $pageService): void {
                $tenants->runAs($tenantId, static fn() => $pageService->createPage('Duplicate', 'about-us', 'page', 'standalone', 1));
            });

            // A second page can be created and updateAddress can rename it without colliding
            $secondCreated = $tenants->runAs($tenantId, static fn(): array => $pageService->createPage('Contact', 'contact', 'page', 'standalone', 1));
            $secondId = (int) $secondCreated['page']['id'];

            assert_throws(StudioValidationException::class, function () use ($tenants, $tenantId, $pageService, $secondId): void {
                $tenants->runAs($tenantId, static fn() => $pageService->updateAddress($secondId, ['slug' => 'about-us'], 1));
            });

            $renamed = $tenants->runAs($tenantId, static fn(): array => $pageService->updateAddress($pageId, ['title' => 'About the Team', 'slug' => 'about-the-team'], 1));
            assert_eq('About the Team', $renamed['title']);
            assert_eq('about-the-team', $renamed['slug']);

            $archived = $tenants->runAs($tenantId, static fn(): array => $pageService->archivePage($pageId, 1));
            assert_eq('archived', $archived['status']);

            // Multi-tenant: a different tenant can use the exact same slug/type with no collision
            $tenantId2 = $tenantId + 2000;
            $created2 = $tenants->runAs($tenantId2, static fn(): array => $pageService->createPage('About Us', 'about-us', 'page', 'standalone', 1));
            assert_true((int) $created2['page']['id'] > 0);
            $tenants->runAs($tenantId, static function () use ($pageService): void {
                assert_null($pageService->findAddressBySlug('about-us', 'page'), 'tenant 1 already renamed its "about-us" page away');
            });
        });
    } finally {
        sbp2_drop_db($dbName);
    }
});

unit('phase2 integration: StudioTemplateService saves/updates templates, protects system templates, applies templates onto pages, and isolates tenants', function (): void {
    $dbName = 'slate_sbp2_' . slate_test_ns();
    $pdo = sbp2_fresh_db($dbName);

    try {
        sbp2_with_pdo($pdo, static function () use ($pdo): void {
            $core = InstallationService::provisionCore();
            $tenantId = (int) $core['tenant_id'];

            $svc = sbp2_services();
            ['tenants' => $tenants, 'pageService' => $pageService, 'templateService' => $templateService, 'templates' => $templates, 'registry' => $registry] = $svc;

            $templateDoc = sbp2_doc($registry, 'Preset Heading');

            $saved = $tenants->runAs($tenantId, static fn(): array => $templateService->saveTemplate(
                'hero-minimal', 'section_preset', 'hero', 'Minimal Hero', 'A minimal hero preset.', $templateDoc, 1
            ));
            assert_eq('hero-minimal', $saved['template_key']);

            // Update path: saving the same key again updates in place rather than duplicating
            $updatedDoc = sbp2_doc($registry, 'Preset Heading v2');
            $savedAgain = $tenants->runAs($tenantId, static fn(): array => $templateService->saveTemplate(
                'hero-minimal', 'section_preset', 'hero', 'Minimal Hero v2', null, $updatedDoc, 1
            ));
            assert_eq((int) $saved['id'], (int) $savedAgain['id'], 'saving an existing template_key must update, not duplicate');
            assert_eq('Minimal Hero v2', $savedAgain['name']);

            // System templates cannot be overwritten via saveTemplate
            $tenants->runAs($tenantId, static function () use ($templates, $saved): void {
                $templates->update((int) $saved['id'], ['is_system' => 1]);
            });
            assert_throws(StudioValidationException::class, function () use ($tenants, $tenantId, $templateService, $templateDoc): void {
                $tenants->runAs($tenantId, static fn() => $templateService->saveTemplate('hero-minimal', 'section_preset', 'hero', 'Hacked', null, $templateDoc, 1));
            });

            // Apply a (new, non-system) template onto a page
            $pageTemplateDoc = sbp2_doc($registry, 'Landing Preset');
            $tenants->runAs($tenantId, static fn(): array => $templateService->saveTemplate(
                'landing-basic', 'page_template', 'landing', 'Basic Landing', null, $pageTemplateDoc, 1
            ));

            $page = $tenants->runAs($tenantId, static fn(): array => $pageService->createPage('Launch', 'launch', 'page', 'standalone', 1));
            $pageId = (int) $page['page']['id'];

            $applied = $tenants->runAs($tenantId, static fn(): array => $templateService->applyTemplate('landing-basic', $pageId, 1));
            assert_eq(2, (int) $applied['revision']['revision_number'], 'applying a template must create a new draft revision');
            $appliedDoc = CanonicalJson::decode((string) $applied['revision']['document_json']);
            assert_eq('page', $appliedDoc['document_type'], 'applied document must adopt the target page type, not the template document_type');
            assert_eq(1, count($appliedDoc['sections']));

            // Applying a nonexistent template fails closed
            assert_throws(StudioNotFoundException::class, function () use ($tenants, $tenantId, $templateService, $pageId): void {
                $tenants->runAs($tenantId, static fn() => $templateService->applyTemplate('does-not-exist', $pageId, 1));
            });

            // Multi-tenant isolation: tenant 2 cannot see tenant 1's templates
            $tenantId2 = $tenantId + 3000;
            $tenants->runAs($tenantId2, static function () use ($templateService): void {
                assert_eq(0, count($templateService->listTemplates()));
            });
            assert_throws(StudioNotFoundException::class, function () use ($tenants, $tenantId2, $templateService, $pageId): void {
                $tenants->runAs($tenantId2, static fn() => $templateService->applyTemplate('landing-basic', $pageId, 1));
            });
        });
    } finally {
        sbp2_drop_db($dbName);
    }
});

if (!empty($studioP2IntStandalone)) {
    exit(unit_summary());
}
