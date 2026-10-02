<?php
/**
 * Integration test for Kohevo Studio (studio-builder) — Sprint 8 Acceptance.
 *
 * Verifies Sprint 8 Acceptance Criteria (Section 64 & Sections 31, 32):
 *   A developer can create and install a new widget without modifying core Studio Builder files:
 *   - Widget SDK: `Studio::widgets()->register([...])` provides clean extension registration.
 *   - Third-Party Loading: `StudioTestimonialWidget` plugin boots and registers `acme.testimonial`.
 *   - Canonical Document Lifecycle: Custom widgets save, validate, compile, and publicly render.
 *   - Fail-Closed Sandboxing: Throwing custom renderers fail closed without breaking page output.
 *   - Version Migrations: Deterministic document upgrades via `Studio::migrate()` / `WidgetMigrator`.
 *   - Security Validator: Enforces namespace isolation and blocks reserved namespaces (`core.*`, `layout.*`).
 *   - Multi-Tenant Isolation: Tenant B cannot access or serve Tenant A's custom widget pages.
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
    $studioS8IntStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-testimonial-widget/StudioTestimonialWidget.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\Sdk\CustomWidgetDefinition;
use Slate\Module\StudioBuilder\Sdk\Studio;
use Slate\Module\StudioBuilder\Sdk\WidgetMigrator;
use Slate\Module\StudioBuilder\Sdk\WidgetSdk;
use Slate\Module\StudioBuilder\Sdk\WidgetSecurityValidator;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const S8_MIGRATIONS = [
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

const S8_TENANT_A = 101;
const S8_TENANT_B = 202;

function s8_fresh_db(string $dbName): \PDO
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
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(S8_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function s8_with_pdo(\PDO $pdo, callable $fn): mixed
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

function s8_make_block(string $type, array $props, int $version = 1, array $children = []): array
{
    return [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => $type,
        'version'    => $version,
        'props'      => $props,
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings'   => [],
        'children'   => $children,
    ];
}

function s8_make_section(array $blocks, string $label = 'Custom Widget Section'): array
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

unit('sprint8 integration: developer widget SDK, third-party plugin loading, versioning, sandboxing, and isolation', function (): void {
    WidgetSdk::reset();

    $dbName = 'slate_s8_' . slate_test_ns();
    $pdo    = s8_fresh_db($dbName);

    try {
        s8_with_pdo($pdo, static function () use ($pdo): void {
            // Provision core and tenants
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Admin', 'admin@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\')
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [S8_TENANT_A, 'Tenant A SDK Pro', 'tenant-a-s8', S8_TENANT_B, 'Tenant B Isolated', 'tenant-b-s8']
            );

            // Install studio-builder with licenses
            license_test_seed_cache(S8_TENANT_A, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder', 'studio-builder.tokens'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            license_test_seed_cache(S8_TENANT_B, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder', 'studio-builder.tokens'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            \PluginLoader::installFromDisk('studio-builder');
            Media::ensureSchema();

            // Seed Users
            Database::query(
                'INSERT INTO users (id, tenant_id, email, password_hash, name, role_id) VALUES (?, ?, ?, ?, ?, 1), (?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [10, S8_TENANT_A, 'alice@tenanta.test', 'hash', 'Alice TenantA', 20, S8_TENANT_B, 'bob@tenantb.test', 'hash', 'Bob TenantB']
            );

            // 1. Boot StudioTestimonialWidget plugin to load `acme.testimonial`
            $testimonialPlugin = new StudioTestimonialWidget(
                'studio-testimonial-widget',
                ['version' => '1.0.0'],
                dirname(__DIR__, 2) . '/plugins/studio-testimonial-widget'
            );
            $testimonialPlugin->boot();
            assert_true(Studio::widgets()->has('acme.testimonial'), 'Plugin registered acme.testimonial');

            // 2. Register a second versioned custom widget: `partner.pricing_card` (v2)
            Studio::widgets()->register([
                'type'        => 'partner.pricing_card',
                'version'     => 2,
                'label'       => 'Pricing Card',
                'category'    => 'commerce',
                'icon'        => 'credit-card',
                'schema'      => [
                    ['key' => 'plan_name', 'type' => 'string', 'label' => 'Plan Name', 'required' => true],
                    ['key' => 'price', 'type' => 'number', 'label' => 'Price', 'required' => true],
                    ['key' => 'billing_cycle', 'type' => 'string', 'label' => 'Billing Cycle', 'default' => 'monthly'],
                ],
                'renderer'    => static function (BlockRenderScope $scope): string {
                    $plan   = $scope->string('plan_name', 'Standard');
                    $price  = $scope->prop('price', 0);
                    $cycle  = $scope->string('billing_cycle', 'monthly');
                    return '<div class="partner-pricing-card" data-sb-custom-widget="partner.pricing_card">'
                        . '<h3>' . Html::e($plan) . '</h3>'
                        . '<div class="price">$' . Html::e((string) $price) . ' / ' . Html::e($cycle) . '</div>'
                        . '</div>';
                },
                'migration'   => static function (int $from, int $to, array $props): array {
                    if ($from === 1 && $to === 2) {
                        // v1 used 'cost' and lacked billing_cycle; v2 standardizes to 'price' and 'billing_cycle'
                        if (isset($props['cost']) && !isset($props['price'])) {
                            $props['price'] = $props['cost'];
                            unset($props['cost']);
                        }
                        if (!isset($props['billing_cycle'])) {
                            $props['billing_cycle'] = 'monthly';
                        }
                    }
                    return $props;
                },
            ]);

            // 3. Register a third custom widget: `sandbox.faulty_widget` that throws inside renderer
            Studio::widgets()->register([
                'type'     => 'sandbox.faulty_widget',
                'version'  => 1,
                'label'    => 'Faulty Widget',
                'renderer' => static function (BlockRenderScope $scope): string {
                    throw new \RuntimeException('Simulated third-party widget crash');
                },
            ]);

            // Build StudioRuntime
            $rt = StudioRuntimeFactory::build();
            $actorA = StudioActor::authenticated(10, StudioPermissions::ALL);
            $actorB = StudioActor::authenticated(20, StudioPermissions::ALL);

            // 4. Verify Widget Registry population in runtime
            assert_true($rt->registry->has('acme.testimonial'), 'Runtime block registry has acme.testimonial');
            assert_true($rt->registry->has('partner.pricing_card'), 'Runtime block registry has partner.pricing_card');
            assert_true($rt->registry->has('sandbox.faulty_widget'), 'Runtime block registry has sandbox.faulty_widget');

            // 5. Tenant A creates page containing the custom widgets
            $resPage = $rt->tenants->runAs(S8_TENANT_A, static fn() => $rt->app->createPage($actorA, 'Showcase Page', 'showcase', 'page', 'standalone'));
            $pageId = (int) $resPage['page']['id'];
            $revId  = (int) $resPage['revision']['id'];

            $testimonialBlock = s8_make_block('acme.testimonial', [
                'author'     => 'Marcus Vance',
                'role'       => 'Chief Architect',
                'company'    => 'CloudScale Inc',
                'quote'      => 'The performance and extensibility are unrivaled.',
                'rating'     => 5,
                'avatar_url' => 'https://assets.example.test/marcus.jpg',
            ], 1);

            $pricingBlock = s8_make_block('partner.pricing_card', [
                'plan_name'     => 'Enterprise Scale',
                'price'         => 299,
                'billing_cycle' => 'yearly',
            ], 2);

            $faultyBlock = s8_make_block('sandbox.faulty_widget', [], 1);

            $customSection = s8_make_section([$testimonialBlock, $pricingBlock, $faultyBlock], 'Custom Widgets');

            // Apply operation to insert custom section
            $op = new DocumentOperation('insert_section', ['index' => 0, 'section' => $customSection]);
            $saveRes = $rt->tenants->runAs(S8_TENANT_A, static fn() => $rt->app->applyDocumentOperation(
                $actorA,
                $pageId,
                [$op],
                $revId,
                'Add custom widgets'
            ));
            assert_true(isset($saveRes['revision']['id']), 'Document saved successfully');
            $newRevId = (int) $saveRes['revision']['id'];

            // 6. Publish page
            $pubRes = $rt->tenants->runAs(S8_TENANT_A, static fn() => $rt->app->publish($actorA, $pageId, $newRevId));
            assert_true(isset($pubRes['revision']['id']), 'Tenant A published page');

            // 7. Verify Public Rendering
            $renderRes = $rt->tenants->runAs(S8_TENANT_A, static fn() => $rt->publicRuntime->handlePath('/showcase'));
            assert_true($renderRes !== null, 'Public render returns response');
            assert_eq(200, $renderRes->status, 'Public response status 200');

            $html = $renderRes->body;

            // Verify Testimonial widget markup & escaping
            assert_true(str_contains($html, 'data-sb-custom-widget="acme.testimonial"'), 'Outputs testimonial widget root attribute');
            assert_true(str_contains($html, 'sb-widget-testimonial'), 'Outputs testimonial CSS class');
            assert_true(str_contains($html, 'Marcus Vance'), 'Outputs author name');
            assert_true(str_contains($html, 'Chief Architect'), 'Outputs role');
            assert_true(str_contains($html, 'CloudScale Inc'), 'Outputs company');
            assert_true(str_contains($html, 'The performance and extensibility are unrivaled.'), 'Outputs quote');
            assert_true(str_contains($html, '★★★★★'), 'Outputs 5 star rating');
            assert_true(str_contains($html, 'https://assets.example.test/marcus.jpg'), 'Outputs avatar image URL');

            // Verify Pricing card widget markup
            assert_true(str_contains($html, 'data-sb-custom-widget="partner.pricing_card"'), 'Outputs pricing card widget root attribute');
            assert_true(str_contains($html, 'Enterprise Scale'), 'Outputs plan name');
            assert_true(str_contains($html, '$299 / yearly'), 'Outputs formatted price and billing cycle');

            // Verify fail-closed sandboxing: faulty widget caught gracefully without crashing public page
            assert_true(str_contains($html, 'data-sb-custom-widget="acme.testimonial"'), 'Testimonial renders despite faulty sibling');
            assert_true(str_contains($html, 'data-sb-custom-widget="partner.pricing_card"'), 'Pricing renders despite faulty sibling');

            // 8. Multi-tenant isolation: Tenant B cannot access or serve Tenant A's showcase page
            $tenantBRender = $rt->tenants->runAs(S8_TENANT_B, static fn() => $rt->publicRuntime->handlePath('/showcase'));
            assert_null($tenantBRender, 'Tenant B public render returns null for Tenant A page');

            $tenantBAuthoringAccess = false;
            try {
                $rt->tenants->runAs(S8_TENANT_B, static fn() => $rt->app->loadEditorDocument($actorB, $pageId));
            } catch (\Throwable $e) {
                $tenantBAuthoringAccess = true;
            }
            assert_true($tenantBAuthoringAccess, 'Tenant B authoring access denied to Tenant A page');

            // 9. Document Version Migration: Upgrade v1 block to v2 via Studio::migrate() / WidgetMigrator
            $v1Block = [
                'id'      => 'blk_pricing_old',
                'type'    => 'partner.pricing_card',
                'version' => 1,
                'props'   => [
                    'plan_name' => 'Legacy Starter',
                    'cost'      => 49,
                ],
            ];

            $oldDoc = CanonicalDocumentSchema::emptyDocument();
            $oldDoc['sections'] = [
                s8_make_section([$v1Block], 'Old Pricing Section'),
            ];

            $migratedDoc = Studio::migrate($oldDoc);
            $migratedBlock = $migratedDoc['sections'][0]['blocks'][0];

            assert_eq(2, $migratedBlock['version'], 'Block version upgraded to 2');
            assert_eq(49, $migratedBlock['props']['price'], 'Prop "cost" migrated to "price"');
            assert_eq('monthly', $migratedBlock['props']['billing_cycle'], 'Prop "billing_cycle" assigned default');
            assert_false(isset($migratedBlock['props']['cost']), 'Old prop "cost" removed');

            // 10. Security Validator: reject reserved namespaces and hostile schemas
            $blockedTypes = [
                'core.injected',
                'layout.hacked',
                'theme.override',
                'unnamespaced',
                'bad..name',
            ];
            foreach ($blockedTypes as $badType) {
                $exceptionThrown = false;
                try {
                    WidgetSecurityValidator::validate([
                        'type'    => $badType,
                        'version' => 1,
                        'label'   => 'Bad Widget',
                    ]);
                } catch (StudioValidationException $e) {
                    $exceptionThrown = true;
                }
                assert_true($exceptionThrown, "Validator rejected forbidden type {$badType}");
            }
        });
    } finally {
        $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
        WidgetSdk::reset();
    }
});
