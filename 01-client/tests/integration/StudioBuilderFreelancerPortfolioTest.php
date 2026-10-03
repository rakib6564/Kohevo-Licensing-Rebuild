<?php
/**
 * Integration test for Kohevo Studio (studio-builder) — Freelancer Portfolio Website Kit.
 *
 * Verifies end-to-end freelancer portfolio website generation, saving, publishing,
 * public rendering of custom widgets and styles, and tenant isolation:
 *  - StudioFreelancerPortfolio plugin boots and registers all 6 custom widgets.
 *  - Canonical document generator produces complete 6-section portfolio schema.
 *  - Save draft and publish compile clean semantic HTML and inject obsidian dark styling.
 *  - Public runtime serves 200 with custom project cards, metrics, and inquiry form.
 *  - Multi-tenant isolation: Tenant B cannot access Tenant A's portfolio.
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
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\Sdk\Studio;
use Slate\Module\StudioBuilder\Sdk\WidgetSdk;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const PORT_MIGRATIONS = [
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

const PORT_TENANT_A = 101;
const PORT_TENANT_B = 202;

function port_fresh_db(string $dbName): \PDO
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
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(PORT_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function port_with_pdo(\PDO $pdo, callable $fn): mixed
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

unit('freelancer portfolio integration: full website generation, publishing, custom widgets rendering, and tenant isolation', function (): void {
    WidgetSdk::reset();
    StudioFreelancerPortfolio::resetStyles();

    $dbName = 'slate_port_' . slate_test_ns();
    $pdo    = port_fresh_db($dbName);

    try {
        port_with_pdo($pdo, static function () use ($pdo): void {
            // 1. Provision Core & Tenants
            $core  = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid   = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Admin', 'admin@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\')
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [PORT_TENANT_A, 'Elena Studio', 'elena-portfolio', PORT_TENANT_B, 'Isolated Tenant B', 'tenant-b-port']
            );

            // 2. Seed licenses & install studio-builder
            license_test_seed_cache(PORT_TENANT_A, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder', 'studio-builder.tokens'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            license_test_seed_cache(PORT_TENANT_B, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder', 'studio-builder.tokens'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            \PluginLoader::installFromDisk('studio-builder');
            Media::ensureSchema();

            // 3. Seed users
            Database::query(
                'INSERT INTO users (id, tenant_id, email, password_hash, name, role_id) VALUES (?, ?, ?, ?, ?, 1), (?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [10, PORT_TENANT_A, 'elena@portfolio.test', 'hash', 'Elena Rostova', 20, PORT_TENANT_B, 'bob@tenantb.test', 'hash', 'Bob Isolated']
            );

            // 4. Boot StudioFreelancerPortfolio plugin
            $plugin = new StudioFreelancerPortfolio(
                'studio-freelancer-portfolio',
                ['version' => '1.0.0'],
                dirname(__DIR__, 2) . '/plugins/studio-freelancer-portfolio'
            );
            $plugin->boot();

            assert_true(Studio::widgets()->has('portfolio.nav_header'), 'Registers portfolio.nav_header');
            assert_true(Studio::widgets()->has('portfolio.footer'), 'Registers portfolio.footer');
            assert_true(Studio::widgets()->has('portfolio.status_pill'), 'Registers portfolio.status_pill');
            assert_true(Studio::widgets()->has('portfolio.eyebrow'), 'Registers portfolio.eyebrow');
            assert_true(Studio::widgets()->has('portfolio.reassurance_badges'), 'Registers portfolio.reassurance_badges');
            assert_true(Studio::widgets()->has('portfolio.project_card'), 'Registers portfolio.project_card');
            assert_true(Studio::widgets()->has('portfolio.stat_highlight'), 'Registers portfolio.stat_highlight');
            assert_true(Studio::widgets()->has('portfolio.skill_grid'), 'Registers portfolio.skill_grid');
            assert_true(Studio::widgets()->has('portfolio.experience_timeline'), 'Registers portfolio.experience_timeline');
            assert_true(Studio::widgets()->has('portfolio.service_card'), 'Registers portfolio.service_card');
            assert_true(Studio::widgets()->has('portfolio.testimonial_card'), 'Registers portfolio.testimonial_card');

            // 5. Initialize StudioRuntime & Actors
            $rt     = StudioRuntimeFactory::build();
            $actorA = StudioActor::authenticated(10, StudioPermissions::ALL);
            $actorB = StudioActor::authenticated(20, StudioPermissions::ALL);

            // 6. Tenant A creates portfolio page
            $created = $rt->tenants->runAs(PORT_TENANT_A, static fn() => $rt->app->createPage(
                $actorA,
                'Elena Rostova — Portfolio',
                'portfolio',
                'page',
                'standalone'
            ));
            $pageId = (int) $created['page']['id'];
            $revId  = (int) $created['revision']['id'];

            // 7. Generate canonical document and validate
            $doc = StudioFreelancerPortfolio::getPortfolioDocument();
            $valResult = DocumentValidator::validate($doc, $rt->registry);
            assert_true($valResult->isValid(), 'Canonical portfolio document passes validation against runtime registry');

            // 8. Save draft with portfolio document
            $saved = $rt->tenants->runAs(PORT_TENANT_A, static fn() => $rt->app->saveDraft(
                $actorA,
                $pageId,
                $doc,
                $revId,
                'manual',
                'Build complete modern freelancer portfolio'
            ));
            assert_true(isset($saved['revision']['id']), 'Portfolio draft saved successfully');
            $newRevId = (int) $saved['revision']['id'];

            // 9. Publish portfolio page
            $pubRes = $rt->tenants->runAs(PORT_TENANT_A, static fn() => $rt->app->publish(
                $actorA,
                $pageId,
                $newRevId,
                'Publish modern freelancer portfolio'
            ));
            assert_true(isset($pubRes['revision']['id']), 'Portfolio published successfully');

            // 10. Public Rendering Verification for Tenant A
            $renderRes = $rt->tenants->runAs(PORT_TENANT_A, static fn() => $rt->publicRuntime->handlePath('/portfolio'));
            assert_true($renderRes !== null, 'Public response received for Tenant A /portfolio');
            assert_eq(200, $renderRes->status, 'Public response HTTP 200');

            $html = $renderRes->body;

            // Assert stylesheet injection
            assert_true(str_contains($html, 'sb-portfolio-styles'), 'Output includes scoped portfolio stylesheet');

            // Assert custom widgets markup
            assert_true(str_contains($html, 'data-sb-custom-widget="portfolio.nav_header"'), 'Output includes nav header custom widget');
            assert_true(str_contains($html, 'data-sb-custom-widget="portfolio.footer"'), 'Output includes footer custom widget');
            assert_true(str_contains($html, 'data-sb-custom-widget="portfolio.status_pill"'), 'Output includes status pill custom widget');
            assert_true(str_contains($html, 'data-sb-custom-widget="portfolio.eyebrow"'), 'Output includes eyebrow custom widget');
            assert_true(str_contains($html, 'data-sb-custom-widget="portfolio.reassurance_badges"'), 'Output includes reassurance badges custom widget');
            assert_true(str_contains($html, 'data-sb-custom-widget="portfolio.project_card"'), 'Output includes project card custom widgets');
            assert_true(str_contains($html, 'data-sb-custom-widget="portfolio.stat_highlight"'), 'Output includes stat highlight custom widgets');
            assert_true(str_contains($html, 'data-sb-custom-widget="portfolio.service_card"'), 'Output includes service card custom widgets');
            assert_true(str_contains($html, 'data-sb-custom-widget="portfolio.skill_grid"'), 'Output includes skill grid custom widgets');
            assert_true(str_contains($html, 'data-sb-custom-widget="portfolio.testimonial_card"'), 'Output includes testimonial custom widgets');

            // Assert content fidelity
            assert_true(str_contains($html, 'Solaya Analytics Platform'), 'Output contains Solaya case study');
            assert_true(str_contains($html, '+185% Daily Active Users'), 'Output contains metric pill');
            assert_true(str_contains($html, 'Apex Enterprise Design System'), 'Output contains design system case study');
            assert_true(str_contains($html, 'Product Design &amp; Architecture') || str_contains($html, 'Product Design & Architecture'), 'Output contains service offering');
            assert_true(str_contains($html, 'TypeScript'), 'Output contains skill pill');
            assert_true(str_contains($html, 'Marcus Vance'), 'Output contains client testimonial author');
            assert_true(str_contains($html, '★★★★★'), 'Output contains 5-star testimonial rating');
            assert_true(str_contains($html, 'Send Project Inquiry'), 'Output contains inquiry submit button');

            // 11. Multi-Tenant Isolation: Tenant B cannot access Tenant A's portfolio
            $renderResB = $rt->tenants->runAs(PORT_TENANT_B, static fn() => $rt->publicRuntime->handlePath('/portfolio'));
            assert_null($renderResB, 'Tenant B querying /portfolio receives null (fails closed / not found)');
        });
    } finally {
        $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
        WidgetSdk::reset();
        StudioFreelancerPortfolio::resetStyles();
    }
});
