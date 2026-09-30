<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — Phase 3 Application
 * Boundary, Entitlement, RBAC, Data Providers, and Cross-Reference Isolation.
 *
 * Real MySQL, real plugin activation (studio-builder + stripe-payment +
 * booking + membership + forms), real per-tenant license entitlement via
 * `license_test_seed_cache()`. Uses three explicit tenants:
 *   - tenant 101: fully entitled (studio-builder + booking + membership + forms)
 *   - tenant 202: entitled to studio-builder ONLY (no business modules)
 *   - tenant 303: entitled to NOTHING (proves the top-level Studio entitlement
 *     gate independently of any business-module gate)
 *
 * Verifies:
 *   1. Application boundary: unauthenticated, unscoped tenant, invalid tenant
 *      scope, missing Studio entitlement, missing Studio permission, valid
 *      authorized command.
 *   2. Block entitlement enforcement (allowed / rejected / revoked).
 *   3. RBAC matrix: edit cannot publish, publish cannot bypass entitlement,
 *      view cannot mutate, admin-only operations remain protected.
 *   4. Real data providers (booking.services, membership.plans, forms.form):
 *      registration, entitlement, permission, parameter validation, tenant
 *      isolation.
 *   5. Cross-tenant reference isolation: media, templates.
 *   6. `applyDocumentOperation` end-to-end: authorized, stale revision,
 *      invalid operation, invalid resulting document.
 *   7. Phase 2 finding F1 regression (clean duplicate-slug exception).
 *   8. Phase 2 finding F2 regression (document_type set before, not after,
 *      the full validate/normalize pipeline).
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
    $studioP3IntStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Application\StudioApplicationService;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Exception\StudioAuthenticationException;
use Slate\Module\StudioBuilder\Exception\StudioAuthorizationException;
use Slate\Module\StudioBuilder\Exception\StudioConcurrencyException;
use Slate\Module\StudioBuilder\Exception\StudioEntitlementException;
use Slate\Module\StudioBuilder\Exception\StudioTenantScopeException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Provider\BookingServicesProvider;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Provider\FormsFormProvider;
use Slate\Module\StudioBuilder\Provider\MembershipPlansProvider;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Registry\DeclarativeBlockDefinition;
use Slate\Module\StudioBuilder\Repository\DependencyRepository;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Module\StudioBuilder\Repository\TemplateRepository;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\Service\StudioPageAddressService;
use Slate\Module\StudioBuilder\Service\StudioRevisionService;
use Slate\Module\StudioBuilder\Service\StudioTemplateService;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;
use Slate\Tenancy\TenantContext;

const SBP3_CORE_MIGRATIONS = [
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

const SBP3_TENANT_A = 101; // fully entitled
const SBP3_TENANT_B = 202; // studio-builder only
const SBP3_TENANT_C = 303; // no entitlements at all

function sbp3_fresh_db(string $dbName): \PDO
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
    $runner->migrate(SBP3_CORE_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function sbp3_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function sbp3_with_pdo(\PDO $pdo, callable $fn): mixed
{
    $property = new \ReflectionProperty(Database::class, 'pdo');
    $previous = $property->getValue();
    $property->setValue(null, $pdo);

    $activeProp = new \ReflectionProperty(\PluginLoader::class, 'active');
    $prevActive = $activeProp->getValue();
    $slugsProp = new \ReflectionProperty(\PluginLoader::class, 'activeSlugs');
    $prevSlugs = $slugsProp->getValue();
    $bootedProp = new \ReflectionProperty(\PluginLoader::class, 'booted');
    $prevBooted = $bootedProp->getValue();

    $envKeys = ['LICENSE_SERVER_URL', 'LICENSE_SERVER_PUBLIC_KEY', 'LICENSE_PRODUCT', 'LICENSE_KEY'];
    $prevEnv = [];
    foreach ($envKeys as $k) {
        $prevEnv[$k] = $_ENV[$k] ?? null;
    }
    $_ENV['LICENSE_SERVER_URL']        = 'https://license.test';
    $_ENV['LICENSE_SERVER_PUBLIC_KEY'] = license_test_public_key();
    $_ENV['LICENSE_PRODUCT']           = 'kohevo';
    $_ENV['LICENSE_KEY']               = 'test-key';

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

/**
 * @return array{tenants: TenantContext, pages: PageRepository, revisions: RevisionRepository,
 *   templates: TemplateRepository, deps: DependencyRepository, registry: BlockRegistry,
 *   app: StudioApplicationService, providers: DataProviderRegistry}
 */
function sbp3_wire(): array
{
    $tenants   = new TenantContext();
    $pages     = new PageRepository($tenants);
    $revisions = new RevisionRepository($tenants);
    $templates = new TemplateRepository($tenants);
    $deps      = new DependencyRepository($tenants);
    $registry  = BlockRegistry::withCoreFoundationBlocks();

    // A block whose declared entitlement gate proves block-level enforcement.
    $registry->register(new DeclarativeBlockDefinition(
        type: 'test.gated',
        version: 1,
        label: 'Gated Test Block',
        category: 'test',
        icon: 'lock',
        schema: FieldSchema::define([]),
        requiredEntitlement: 'booking',
    ));

    $revisionService = new StudioRevisionService($tenants, $pages, $revisions, $deps, $registry);
    $pageService     = new StudioPageAddressService($tenants, $pages, $revisionService, $registry);
    $templateService = new StudioTemplateService($tenants, $templates, $pages, $revisionService, $registry);

    $providers = new DataProviderRegistry();
    $providers->register(new BookingServicesProvider());
    $providers->register(new MembershipPlansProvider());
    $providers->register(new FormsFormProvider());

    $app = new StudioApplicationService(
        $tenants, $pages, $templates, $revisions,
        $pageService, $revisionService, $templateService,
        $providers, $registry,
    );

    return compact('tenants', 'pages', 'revisions', 'templates', 'deps', 'registry', 'app', 'providers');
}

/**
 * @return array<string, mixed>
 */
function sbp3_doc(BlockRegistry $registry, string $heading = 'Hello', array $overrides = []): array
{
    $hero = array_merge([
        'bindings'   => [],
        'children'   => [],
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'props'      => ['eyebrow' => '', 'heading' => $heading, 'subheading' => '', 'primary_cta' => null, 'media' => null, 'accent_token' => null],
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'type'       => 'core.hero',
        'version'    => 1,
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ], $overrides);
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
    return $doc;
}

unit('phase3 integration: application boundary, entitlement, RBAC, data providers, and cross-tenant isolation', function (): void {
    $dbName = 'slate_sbp3_' . slate_test_ns();
    $pdo = sbp3_fresh_db($dbName);

    try {
        sbp3_with_pdo($pdo, static function () use ($pdo): void {
            $core = InstallationService::provisionCore();
            $ownerTenantId = (int) $core['tenant_id'];
            InstallationService::createAdminAccount(
                $ownerTenantId, 'Studio Admin', 'studio-admin@example.test', password_hash('password123', PASSWORD_DEFAULT)
            );

            // Additional explicit test tenants (101 fully entitled, 202 studio-only, 303 nothing).
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\'), (?,?,?,\'active\')
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [
                    SBP3_TENANT_A, 'Tenant A', 'tenant-a-' . SBP3_TENANT_A,
                    SBP3_TENANT_B, 'Tenant B', 'tenant-b-' . SBP3_TENANT_B,
                    SBP3_TENANT_C, 'Tenant C', 'tenant-c-' . SBP3_TENANT_C,
                ]
            );

            // Owner tenant needs every entitlement to be able to activate every plugin below.
            license_test_seed_cache($ownerTenantId, [
                'installation_id' => $core['installation_id'],
                'status'          => 'active',
                'plan'            => 'all-access',
                'entitlements'    => ['forms', 'membership', 'booking', 'studio-builder', 'stripe-payment'],
                'expires_at'      => null,
                'fetched_at'      => gmdate('Y-m-d H:i:s'),
            ]);

            // stripe-payment is supporting infrastructure, not a directly selectable module —
            // validateSelection() rejects it outright; install it directly instead.
            $stripeAct = \PluginLoader::installFromDisk('stripe-payment');
            assert_true(!empty($stripeAct['ok']), 'installFromDisk(stripe-payment) must succeed: ' . json_encode($stripeAct));

            foreach (['booking', 'membership', 'forms', 'studio-builder'] as $slug) {
                $sel = CommercialModuleRegistry::validateSelection([$slug], ['forms', 'membership', 'booking', 'studio-builder']);
                assert_true($sel['ok'], "selection for {$slug} must be valid: " . json_encode($sel));
                $act = \PluginLoader::installFromDisk($slug);
                assert_true(!empty($act['ok']), "installFromDisk({$slug}) must succeed: " . json_encode($act));
            }
            Media::ensureSchema();

            // Per-tenant entitlement snapshots for the actual test tenants.
            license_test_seed_cache(SBP3_TENANT_A, [
                'installation_id' => $core['installation_id'],
                'status'          => 'active',
                'plan'            => 'all-access',
                'entitlements'    => ['studio-builder', 'booking', 'membership', 'forms'],
                'expires_at'      => null,
                'fetched_at'      => gmdate('Y-m-d H:i:s'),
            ]);
            license_test_seed_cache(SBP3_TENANT_B, [
                'installation_id' => $core['installation_id'],
                'status'          => 'active',
                'plan'            => 'studio-only',
                'entitlements'    => ['studio-builder'],
                'expires_at'      => null,
                'fetched_at'      => gmdate('Y-m-d H:i:s'),
            ]);
            license_test_seed_cache(SBP3_TENANT_C, [
                'installation_id' => $core['installation_id'],
                'status'          => 'active',
                'plan'            => 'none',
                'entitlements'    => [],
                'expires_at'      => null,
                'fetched_at'      => gmdate('Y-m-d H:i:s'),
            ]);

            $wired = sbp3_wire();
            ['tenants' => $tenants, 'pages' => $pages, 'templates' => $templates, 'deps' => $deps, 'registry' => $registry, 'app' => $app] = $wired;

            $guest    = StudioActor::guest();
            $viewer   = StudioActor::authenticated(10, [StudioPermissions::VIEW]);
            $editor   = StudioActor::authenticated(11, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
            $publisher = StudioActor::authenticated(12, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            $admin    = StudioActor::authenticated(13, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH, StudioPermissions::ADMIN]);

            // ── 1. Application boundary ─────────────────────────────────────

            assert_throws(StudioAuthenticationException::class, function () use ($tenants, $app, $guest): void {
                $tenants->runAs(SBP3_TENANT_A, static fn() => $app->createPage($guest, 'Home', 'home', 'page', 'standalone'));
            }, 'unauthenticated access must fail closed');

            assert_throws(StudioTenantScopeException::class, function () use ($tenants, $app, $editor): void {
                $tenants->withoutScope(static fn() => $app->createPage($editor, 'Home', 'home', 'page', 'standalone'));
            }, 'unscoped tenant context must fail closed');

            assert_throws(StudioTenantScopeException::class, function () use ($tenants, $app, $editor): void {
                // runAs(0, ...) would NOT reach the override (current_tenant_id() tests
                // !empty(), and 0 is empty) — use -1, exactly like the Phase 1 foundation test does.
                $tenants->runAs(-1, static fn() => $app->createPage($editor, 'Home', 'home', 'page', 'standalone'));
            }, 'invalid (non-positive) tenant id must fail closed');

            assert_throws(StudioEntitlementException::class, function () use ($tenants, $app, $editor): void {
                $tenants->runAs(SBP3_TENANT_C, static fn() => $app->createPage($editor, 'Home', 'home', 'page', 'standalone'));
            }, 'missing studio-builder entitlement must fail closed regardless of permission');

            assert_throws(StudioAuthorizationException::class, function () use ($tenants, $app, $viewer): void {
                $tenants->runAs(SBP3_TENANT_A, static fn() => $app->createPage($viewer, 'Home', 'home', 'page', 'standalone'));
            }, 'missing studio-builder.edit permission must fail closed');

            $created = $tenants->runAs(SBP3_TENANT_A, static fn(): array => $app->createPage($editor, 'Home', 'home-a', 'page', 'standalone'));
            assert_true((int) $created['page']['id'] > 0, 'a fully authorized command must succeed');
            $pageIdA = (int) $created['page']['id'];

            // ── 2. Block entitlement enforcement ────────────────────────────

            $gatedDoc = sbp3_doc($registry, 'Gated', []);
            $gatedDoc['sections'][0]['blocks'][] = [
                'bindings' => [], 'children' => [], 'id' => CanonicalDocumentSchema::newBlockId(),
                'props' => [], 'style' => CanonicalDocumentSchema::defaultBlockStyle(),
                'type' => 'test.gated', 'version' => 1, 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            ];

            // Tenant A (entitled to booking) may use the gated block.
            $savedGated = $tenants->runAs(SBP3_TENANT_A, static fn(): array => $app->saveDraft($editor, $pageIdA, $gatedDoc, $created['revision']['id']));
            assert_true($savedGated['revision']['id'] > 0);

            // Tenant B (studio-builder only, NOT entitled to booking) must not be able to use it,
            // even though the top-level studio-builder entitlement check passes.
            $createdB = $tenants->runAs(SBP3_TENANT_B, static fn(): array => $app->createPage($editor, 'Home', 'home-b', 'page', 'standalone'));
            $pageIdB = (int) $createdB['page']['id'];
            $gatedRejected = false;
            try {
                $tenants->runAs(SBP3_TENANT_B, static fn() => $app->saveDraft($editor, $pageIdB, $gatedDoc, $createdB['revision']['id']));
            } catch (StudioValidationException $e) {
                $codes = array_column($e->errors(), 'code');
                $gatedRejected = in_array('block_module_not_entitled', $codes, true);
            }
            assert_true($gatedRejected, 'an unentitled tenant must not be able to persist a document containing an entitlement-gated block');

            // Entitlement revocation: remove booking from tenant A and retry the same gated document.
            license_test_seed_cache(SBP3_TENANT_A, [
                'installation_id' => $core['installation_id'],
                'status'          => 'active',
                'plan'            => 'studio-only-now',
                'entitlements'    => ['studio-builder'],
                'expires_at'      => null,
                'fetched_at'      => gmdate('Y-m-d H:i:s'),
            ]);
            $revokedRejected = false;
            try {
                $tenants->runAs(SBP3_TENANT_A, static fn() => $app->saveDraft($editor, $pageIdA, $gatedDoc, $savedGated['revision']['id']));
            } catch (StudioValidationException $e) {
                $codes = array_column($e->errors(), 'code');
                $revokedRejected = in_array('block_module_not_entitled', $codes, true);
            }
            assert_true($revokedRejected, 'revoking booking entitlement must be respected immediately, not cached from an earlier check');

            // Restore tenant A's full entitlement for the remaining scenarios.
            license_test_seed_cache(SBP3_TENANT_A, [
                'installation_id' => $core['installation_id'],
                'status'          => 'active',
                'plan'            => 'all-access',
                'entitlements'    => ['studio-builder', 'booking', 'membership', 'forms'],
                'expires_at'      => null,
                'fetched_at'      => gmdate('Y-m-d H:i:s'),
            ]);

            // ── 3. RBAC matrix ───────────────────────────────────────────────

            assert_throws(StudioAuthorizationException::class, function () use ($tenants, $app, $editor, $pageIdA): void {
                $tenants->runAs(SBP3_TENANT_A, static fn() => $app->publish($editor, $pageIdA, null));
            }, 'edit cannot publish');

            assert_throws(StudioEntitlementException::class, function () use ($tenants, $app, $publisher): void {
                $tenants->runAs(SBP3_TENANT_C, static fn() => $app->publish($publisher, 999999, null));
            }, 'publish cannot bypass entitlement, even with the publish permission');

            assert_throws(StudioAuthorizationException::class, function () use ($tenants, $app, $viewer, $pageIdA, $registry): void {
                $tenants->runAs(SBP3_TENANT_A, static fn() => $app->saveDraft($viewer, $pageIdA, sbp3_doc($registry, 'x'), null));
            }, 'view cannot mutate');

            assert_throws(StudioAuthorizationException::class, function () use ($tenants, $app, $editor, $registry): void {
                $tenants->runAs(SBP3_TENANT_A, static fn() => $app->saveTemplate($editor, 'edit-cannot-admin', 'section_preset', 'test', 'X', null, sbp3_doc($registry, 'X')));
            }, 'admin-only template administration remains protected from a mere editor');

            $tplResult = $tenants->runAs(SBP3_TENANT_A, static fn(): array => $app->saveTemplate($admin, 'admin-template', 'section_preset', 'test', 'Admin Template', null, sbp3_doc($registry, 'Admin Preset')));
            assert_eq('admin-template', $tplResult['template_key']);

            // ── 4. Real data providers ───────────────────────────────────────

            $tenants->runAs(SBP3_TENANT_A, static function (): void {
                Database::insert('booking_services', [
                    'tenant_id' => SBP3_TENANT_A, 'name' => 'Haircut', 'slug' => 'haircut',
                    'duration_min' => 45, 'price_cents' => 5000, 'currency' => 'USD', 'is_active' => 1,
                ]);
                Database::insert('membership_plans', [
                    'tenant_id' => SBP3_TENANT_A, 'name' => 'Gold Plan', 'plan_type' => 'membership',
                    'price_cents' => 9900, 'currency' => 'USD', 'duration_days' => 365, 'is_active' => 1,
                ]);
                Database::insert('forms_definitions', [
                    'tenant_id' => SBP3_TENANT_A, 'slug' => 'contact-us', 'title' => 'Contact Us',
                    'fields_json' => '[]', 'status' => 'published',
                ]);
                Database::insert('forms_definitions', [
                    'tenant_id' => SBP3_TENANT_A, 'slug' => 'draft-form', 'title' => 'Draft Form',
                    'fields_json' => '[]', 'status' => 'draft',
                ]);
            });

            $bookingRows = $tenants->runAs(SBP3_TENANT_A, static fn(): array => $app->runDataProvider($viewer, 'booking.services', []));
            assert_eq(1, count($bookingRows));
            assert_eq('Haircut', $bookingRows[0]['name']);
            assert_eq(5000, $bookingRows[0]['price_cents']);

            $planRows = $tenants->runAs(SBP3_TENANT_A, static fn(): array => $app->runDataProvider($viewer, 'membership.plans', []));
            assert_eq(1, count($planRows));
            assert_eq('Gold Plan', $planRows[0]['name']);

            $formRows = $tenants->runAs(SBP3_TENANT_A, static fn(): array => $app->runDataProvider($viewer, 'forms.form', ['slug' => 'contact-us']));
            assert_eq(1, count($formRows));
            assert_eq('Contact Us', $formRows[0]['title']);

            // A draft form must never be returned, even by slug.
            $draftFormRows = $tenants->runAs(SBP3_TENANT_A, static fn(): array => $app->runDataProvider($viewer, 'forms.form', ['slug' => 'draft-form']));
            assert_eq(0, count($draftFormRows), 'a draft form must not be embeddable');

            // Missing required parameter fails closed.
            assert_throws(StudioValidationException::class, function () use ($tenants, $app, $viewer): void {
                $tenants->runAs(SBP3_TENANT_A, static fn() => $app->runDataProvider($viewer, 'forms.form', []));
            });

            // Unknown provider key fails closed.
            assert_throws(\Slate\Module\StudioBuilder\Exception\StudioNotFoundException::class, function () use ($tenants, $app, $viewer): void {
                $tenants->runAs(SBP3_TENANT_A, static fn() => $app->runDataProvider($viewer, 'does.not_exist', []));
            });

            // Tenant B is not entitled to booking at all.
            assert_throws(StudioEntitlementException::class, function () use ($tenants, $app, $viewer): void {
                $tenants->runAs(SBP3_TENANT_B, static fn() => $app->runDataProvider($viewer, 'booking.services', []));
            });

            // Tenant isolation: tenant B, even if hypothetically entitled, would see none of tenant A's rows
            // (BookingAPI::getActiveServices() is tenant-scoped via current_tenant_id()).
            license_test_seed_cache(SBP3_TENANT_B, [
                'installation_id' => $core['installation_id'],
                'status' => 'active', 'plan' => 'temp-all-access',
                'entitlements' => ['studio-builder', 'booking'],
                'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $bookingRowsB = $tenants->runAs(SBP3_TENANT_B, static fn(): array => $app->runDataProvider($viewer, 'booking.services', []));
            assert_eq(0, count($bookingRowsB), 'tenant B must not see tenant A\'s booking services');
            license_test_seed_cache(SBP3_TENANT_B, [
                'installation_id' => $core['installation_id'],
                'status' => 'active', 'plan' => 'studio-only',
                'entitlements' => ['studio-builder'],
                'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
            ]);

            // ── 5. Cross-tenant reference isolation: media + templates ──────

            $mediaIdA = $tenants->runAs(SBP3_TENANT_A, static fn(): int => Media::register('/uploads/media/2026/01/test-a.jpg', ['mime' => 'image/jpeg']));
            assert_true($mediaIdA > 0);

            $docWithMedia = sbp3_doc($registry, 'Has Media');
            $docWithMedia['seo']['og_image_media_id'] = $mediaIdA;

            $savedWithMedia = $tenants->runAs(SBP3_TENANT_A, static fn(): array => $app->saveDraft($editor, $pageIdA, $docWithMedia, $savedGated['revision']['id']));
            assert_true($savedWithMedia['revision']['id'] > 0, 'tenant A must be able to reference its own media');

            $crossTenantMediaRejected = false;
            try {
                $tenants->runAs(SBP3_TENANT_B, static fn() => $app->saveDraft($editor, $pageIdB, $docWithMedia, $createdB['revision']['id']));
            } catch (StudioValidationException $e) {
                $codes = array_column($e->errors(), 'code');
                $crossTenantMediaRejected = in_array('cross_tenant_or_missing_media', $codes, true);
            }
            assert_true($crossTenantMediaRejected, 'tenant B must not be able to reference tenant A\'s media_id');

            $docWithTemplate = sbp3_doc($registry, 'Has Template');
            $docWithTemplate['template_key'] = 'admin-template'; // owned by tenant A

            $crossTenantTemplateRejected = false;
            try {
                $tenants->runAs(SBP3_TENANT_B, static fn() => $app->saveDraft($editor, $pageIdB, $docWithTemplate, $createdB['revision']['id']));
            } catch (StudioValidationException $e) {
                $codes = array_column($e->errors(), 'code');
                $crossTenantTemplateRejected = in_array('cross_tenant_or_missing_template', $codes, true);
            }
            assert_true($crossTenantTemplateRejected, 'tenant B must not be able to reference tenant A\'s template_key');

            // ── 6. applyDocumentOperation end-to-end ─────────────────────────

            $opInsertSection = new DocumentOperation(DocumentOperation::OP_INSERT_SECTION, ['index' => 1, 'section' => ['label' => 'New Section']]);
            $opResult = $tenants->runAs(SBP3_TENANT_A, static fn(): array => $app->applyDocumentOperation($editor, $pageIdA, [$opInsertSection], $savedWithMedia['revision']['id']));
            assert_true($opResult['revision']['id'] > 0);
            $opRevId = (int) $opResult['revision']['id'];

            assert_throws(StudioConcurrencyException::class, function () use ($tenants, $app, $editor, $pageIdA, $opInsertSection): void {
                $tenants->runAs(SBP3_TENANT_A, static fn() => $app->applyDocumentOperation($editor, $pageIdA, [$opInsertSection], 999999));
            }, 'a stale expected_revision_id must fail closed');

            assert_throws(StudioValidationException::class, function () use ($opInsertSection): void {
                new DocumentOperation('not_a_real_op', []);
            });

            $badBlockOp = new DocumentOperation(DocumentOperation::OP_INSERT_BLOCK, [
                'parent_id' => 'sec_does_not_matter_00000000',
                'index'     => 0,
                'block'     => ['type' => 'not.a.real.block'],
            ]);
            assert_throws(StudioValidationException::class, function () use ($tenants, $app, $editor, $pageIdA, $badBlockOp, $opRevId): void {
                $tenants->runAs(SBP3_TENANT_A, static fn() => $app->applyDocumentOperation($editor, $pageIdA, [$badBlockOp], $opRevId));
            }, 'an operation referencing an unknown block type must fail closed before persistence');

            // ── 7. Phase 2 finding F1 regression: clean duplicate-slug exception ─

            assert_throws(StudioValidationException::class, function () use ($tenants, $app, $editor): void {
                $tenants->runAs(SBP3_TENANT_A, static fn() => $app->createPage($editor, 'Duplicate', 'home-a', 'page', 'standalone'));
            }, 'duplicate slug via the normal pre-check path must raise a clean domain exception');

            // Directly confirm the DB-level trigger condition F1's catch block translates:
            // a genuine (tenant_id, slug, page_type) collision raises SQLSTATE 23000.
            $rawDuplicateRaised23000 = false;
            $tenants->runAs(SBP3_TENANT_A, static function () use ($pages, &$rawDuplicateRaised23000): void {
                try {
                    $pages->insert([
                        'uuid' => '99999999-9999-4999-8999-999999999999', 'title' => 'Raw dup',
                        'slug' => 'home-a', 'page_type' => 'page', 'status' => 'draft', 'route_mode' => 'standalone',
                    ]);
                } catch (\PDOException $e) {
                    $rawDuplicateRaised23000 = ((string) $e->getCode() === '23000');
                }
            });
            assert_true($rawDuplicateRaised23000, 'the unique constraint F1\'s catch block handles must actually fire for a genuine race');

            // ── 8. Phase 2 finding F2 regression: document_type set before, not after, validation ─

            $appliedTemplate = $tenants->runAs(SBP3_TENANT_A, static fn(): array => $app->applyTemplate($editor, 'admin-template', $pageIdA));
            $appliedDoc = CanonicalJson::decode((string) $appliedTemplate['revision']['document_json']);
            assert_eq('page', $appliedDoc['document_type']);
            $reNormalized = DocumentNormalizer::normalize($appliedDoc, $registry);
            assert_eq(
                CanonicalJson::fingerprint($appliedDoc),
                CanonicalJson::fingerprint($reNormalized),
                'the persisted document must already be the exact output of one full validate/normalize pass, not hand-patched afterward'
            );
        });
    } finally {
        sbp3_drop_db($dbName);
    }
});

if (!empty($studioP3IntStandalone)) {
    exit(unit_summary());
}
