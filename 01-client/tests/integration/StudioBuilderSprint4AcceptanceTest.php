<?php
/**
 * Integration test for Kohevo Studio (studio-builder) — Sprint 4 Acceptance.
 *
 * Verifies Sprint 4 Acceptance Criteria (Section 60):
 *   A user can build a consistent multi-page website from reusable design primitives:
 *   - Global design tokens (saveDesignTokens, ThemeResolver, :root CSS compilation).
 *   - Reusable sections (saveTemplateFromPage, insertTemplate as owned copies).
 *   - Global components (createGlobalComponent, live global_ref references, live multi-page propagation).
 *   - Component detach (detachGlobalSection converts reference into owned local tree with fresh IDs).
 *   - Multi-page consistent rendering and public serving under HTTP 200.
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
    $studioS4IntStandalone = true;
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
use Slate\Module\StudioBuilder\Service\StudioGlobalComponentService;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const S4_MIGRATIONS = [
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

const S4_TENANT_A = 101;
const S4_TENANT_B = 202;

function s4_fresh_db(string $dbName): \PDO
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
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(S4_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function s4_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function s4_with_pdo(\PDO $pdo, callable $fn): mixed
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

unit('sprint4 integration: multi-page website from reusable design primitives (tokens, templates, components)', function (): void {
    $dbName = 'slate_s4_' . slate_test_ns();
    $pdo = s4_fresh_db($dbName);

    try {
        s4_with_pdo($pdo, static function (): void {
            // Provision core and tenants
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Admin', 'admin@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\')
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [S4_TENANT_A, 'Tenant A', 'tenant-a-s4', S4_TENANT_B, 'Tenant B', 'tenant-b-s4']
            );

            // Install studio-builder
            license_test_seed_cache(S4_TENANT_A, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder', 'studio-builder.tokens'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            license_test_seed_cache(S4_TENANT_B, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['studio-builder', 'studio-builder.tokens'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            \PluginLoader::installFromDisk('studio-builder');
            Media::ensureSchema();

            $rt = StudioRuntimeFactory::build();
            $actorA = StudioActor::authenticated(42, StudioPermissions::ALL);
            $actorB = StudioActor::authenticated(99, StudioPermissions::ALL);

            // 1. Save global design tokens for Tenant A
            $savedTokens = $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->saveDesignTokens($actorA, 'default', [
                'color.accent'    => '#6366f1',
                'surface.accent'  => '#4f46e5',
                'radius.md'       => '14px',
                'shadow.md'       => '0 4px 16px rgba(99,102,241,0.12)',
            ]));
            assert_true(is_array($savedTokens['tokens']), 'tokens returned');

            // 1b. Verify hostile injection token is rejected
            assert_throws(StudioValidationException::class, static function () use ($rt, $actorA): void {
                $rt->tenants->runAs(S4_TENANT_A, fn() => $rt->app->saveDesignTokens($actorA, 'default', [
                    'color.accent' => 'red; }</style><script>alert(1)</script>',
                ]));
            }, 'hostile CSS injection in design tokens is rejected');

            // 2. Create Global Component: Main Site Header
            $cmpCreated = $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->createGlobalComponent($actorA, 'Main Site Header', 'main-site-header'));
            $cmpId = (int) $cmpCreated['component']['id'];
            $cmpUuid = (string) $cmpCreated['component']['ref'];
            $cmpRevId = (int) $cmpCreated['component']['active_draft_revision_id'];
            assert_true($cmpId > 0, 'global component created');
            assert_true($cmpUuid !== '', 'global component has ref (uuid)');

            // Add Header navigation contents to the Global Component
            $secHeader = [
                'id' => CanonicalDocumentSchema::newSectionId(),
                'label' => 'Header Nav Bar',
                'layout' => CanonicalDocumentSchema::defaultSectionLayout(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'blocks' => [
                    [
                        'id' => CanonicalDocumentSchema::newBlockId(),
                        'type' => 'layout.container',
                        'version' => 1,
                        'props' => ['width' => 'constrained', 'alignment' => 'center'],
                        'style' => CanonicalDocumentSchema::defaultBlockStyle(),
                        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                        'bindings' => [],
                        'children' => [
                            [
                                'id' => CanonicalDocumentSchema::newBlockId(),
                                'type' => 'core.heading',
                                'version' => 1,
                                'props' => ['text' => 'Solaya Platform', 'level' => 'h3'],
                                'style' => CanonicalDocumentSchema::defaultBlockStyle(),
                                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                                'bindings' => [],
                                'children' => [],
                            ],
                            [
                                'id' => CanonicalDocumentSchema::newBlockId(),
                                'type' => 'core.button',
                                'version' => 1,
                                'props' => ['link' => ['href' => '/join', 'label' => 'Join Now', 'target' => '_self'], 'variant' => 'primary'],
                                'style' => CanonicalDocumentSchema::defaultBlockStyle(),
                                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                                'bindings' => [],
                                'children' => [],
                            ],
                        ],
                    ],
                ],
            ];
            $opCmpSec = new DocumentOperation('insert_section', ['index' => 0, 'section' => $secHeader]);
            $cmpRes = $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->applyDocumentOperation($actorA, $cmpId, [$opCmpSec], $cmpRevId, 'Add Header Elements'));
            $cmpRevId = (int) $cmpRes['revision']['id'];

            // Publish Global Component so consumer pages can render it live
            $cmpPub = $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->publish($actorA, $cmpId, $cmpRevId));
            assert_eq('published', $cmpPub['page']['status']);
            $cmpRevId = (int) $cmpPub['page']['active_draft_revision_id'];

            // 3. Create Page 1: Home Page referencing Global Component + Local Hero
            $homeCreated = $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->createPage($actorA, 'Home Page', 's4-home-page', 'page', 'standalone'));
            $homePageId = (int) $homeCreated['page']['id'];
            $homeRevId = (int) $homeCreated['revision']['id'];

            // Insert reference to Global Component (sec.global_ref = $cmpUuid)
            $refSec = [
                'id' => CanonicalDocumentSchema::newSectionId(),
                'label' => 'Global Header Ref',
                'global_ref' => $cmpUuid,
                'layout' => CanonicalDocumentSchema::defaultSectionLayout(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'blocks' => [],
            ];
            $opRef = new DocumentOperation('insert_section', ['index' => 0, 'section' => $refSec]);

            // Insert Local Hero Section
            $heroSecId = CanonicalDocumentSchema::newSectionId();
            $heroSec = [
                'id' => $heroSecId,
                'label' => 'Hero Banner Section',
                'layout' => CanonicalDocumentSchema::defaultSectionLayout(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'blocks' => [
                    [
                        'id' => CanonicalDocumentSchema::newBlockId(),
                        'type' => 'core.heading',
                        'version' => 1,
                        'props' => ['text' => 'Welcome to the Future of Visual Building', 'level' => 'h1'],
                        'style' => CanonicalDocumentSchema::defaultBlockStyle(),
                        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                        'bindings' => [],
                        'children' => [],
                    ],
                ],
            ];
            $opHero = new DocumentOperation('insert_section', ['index' => 1, 'section' => $heroSec]);

            $homeEdit = $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->applyDocumentOperation($actorA, $homePageId, [$opRef, $opHero], $homeRevId, 'Add Header Ref and Hero'));
            $homeRevId = (int) $homeEdit['revision']['id'];
            // insert_section always mints new IDs — read actual section IDs from the saved document
            $homeDoc = CanonicalJson::decode((string) $homeEdit['revision']['document_json']);
            $heroSecId = (string) $homeDoc['sections'][1]['id']; // hero is at index 1
            $homePub = $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->publish($actorA, $homePageId, $homeRevId));
            assert_eq('published', $homePub['page']['status']);
            $homeRevId = (int) $homePub['page']['active_draft_revision_id'];

            // 4. Create Page 2: About Page referencing the SAME Global Component
            $aboutCreated = $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->createPage($actorA, 'About Page', 's4-about-page', 'page', 'standalone'));
            $aboutPageId = (int) $aboutCreated['page']['id'];
            $aboutRevId = (int) $aboutCreated['revision']['id'];

            $aboutRefSecId = CanonicalDocumentSchema::newSectionId();
            $aboutRefSec = [
                'id' => $aboutRefSecId,
                'label' => 'Global Header Ref',
                'global_ref' => $cmpUuid,
                'layout' => CanonicalDocumentSchema::defaultSectionLayout(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                'blocks' => [],
            ];
            $opAboutRef = new DocumentOperation('insert_section', ['index' => 0, 'section' => $aboutRefSec]);
            $aboutEdit = $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->applyDocumentOperation($actorA, $aboutPageId, [$opAboutRef], $aboutRevId, 'Add Header Ref'));
            $aboutRevId = (int) $aboutEdit['revision']['id'];
            // insert_section always mints a new section ID — read the actual one from the saved document
            $aboutDoc = CanonicalJson::decode((string) $aboutEdit['revision']['document_json']);
            $aboutRefSecId = (string) $aboutDoc['sections'][0]['id'];
            $aboutPub = $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->publish($actorA, $aboutPageId, $aboutRevId));
            assert_eq('published', $aboutPub['page']['status']);
            $aboutRevId = (int) $aboutPub['page']['active_draft_revision_id'];

            // 5. Verify public rendering on both pages
            $homeServed = $rt->tenants->runAs(S4_TENANT_A, static fn() => $rt->publicRuntime->handlePath('s4-home-page'));
            assert_true($homeServed !== null, 'home page served');
            assert_eq(200, $homeServed->status);
            $homeHtml = $homeServed->body;
            assert_true(str_contains($homeHtml, '--sb-color-accent:#6366f1'), 'contains global design token color.accent');
            assert_true(str_contains($homeHtml, '--sb-radius-md:14px'), 'contains global design token radius.md');
            assert_true(str_contains($homeHtml, 'Solaya Platform'), 'home contains component heading');
            assert_true(str_contains($homeHtml, 'Join Now'), 'home contains component button');
            assert_true(str_contains($homeHtml, 'Welcome to the Future of Visual Building'), 'home contains local hero');

            $aboutServed = $rt->tenants->runAs(S4_TENANT_A, static fn() => $rt->publicRuntime->handlePath('s4-about-page'));
            assert_true($aboutServed !== null, 'about page served');
            assert_eq(200, $aboutServed->status);
            $aboutHtml = $aboutServed->body;
            assert_true(str_contains($aboutHtml, 'Solaya Platform'), 'about contains component heading');
            assert_true(str_contains($aboutHtml, 'Join Now'), 'about contains component button');

            // 6. Live Component Update & Propagation:
            // Update the CTA in the Global Component from "Join Now" to "Get Started Today" and republish
            $cmpDoc = CanonicalJson::decode((string) $cmpRes['revision']['document_json']);
            $btnBlockId = (string) $cmpDoc['sections'][0]['blocks'][0]['children'][1]['id'];
            $opUpdateBtn = new DocumentOperation('update_block_props', [
                'block_id' => $btnBlockId,
                'props' => ['link' => ['href' => '/get-started', 'label' => 'Get Started Today', 'target' => '_self'], 'variant' => 'primary'],
            ]);
            $cmpRes2 = $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->applyDocumentOperation($actorA, $cmpId, [$opUpdateBtn], $cmpRevId, 'Update CTA text'));
            $cmpRevId = (int) $cmpRes2['revision']['id'];
            $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->publish($actorA, $cmpId, $cmpRevId));

            // Immediately, both home and about reflect the updated global component without touching either page
            $homeServed2 = $rt->tenants->runAs(S4_TENANT_A, static fn() => $rt->publicRuntime->handlePath('s4-home-page'));
            assert_true(str_contains($homeServed2->body, 'Get Started Today'), 'home immediately renders updated global component CTA');

            $aboutServed2 = $rt->tenants->runAs(S4_TENANT_A, static fn() => $rt->publicRuntime->handlePath('s4-about-page'));
            assert_true(str_contains($aboutServed2->body, 'Get Started Today'), 'about immediately renders updated global component CTA');

            // 7. Component Detach on About Page
            $detachRes = $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->detachGlobalSection($actorA, $aboutPageId, $aboutRefSecId, $aboutRevId));
            $aboutRevId = (int) $detachRes['revision']['id'];
            $detachedDoc = CanonicalJson::decode((string) $detachRes['revision']['document_json']);
            assert_null($detachedDoc['sections'][0]['global_ref'] ?? null, 'detached section no longer has global_ref');
            assert_true(count($detachedDoc['sections'][0]['blocks']) > 0, 'detached section now owns local blocks');

            // 8. Reusable Section Templates:
            // Save the Hero Section from Home Page as a reusable template
            $tplRes = $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->saveTemplateFromPage(
                $actorA,
                $homePageId,
                $heroSecId,
                'hero-reusable-preset',
                'section_preset',
                'heroes',
                'Hero Reusable Preset',
                'A reusable hero section preset'
            ));
            assert_eq('hero-reusable-preset', $tplRes['template_key']);

            // Insert copy of Hero Reusable Preset into Page 3 (Pricing)
            $pricingCreated = $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->createPage($actorA, 'Pricing Page', 's4-pricing-page', 'page', 'standalone'));
            $pricingPageId = (int) $pricingCreated['page']['id'];
            $pricingRevId = (int) $pricingCreated['revision']['id'];

            $insertTplRes = $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->insertTemplate($actorA, $pricingPageId, 'hero-reusable-preset', 0, null, $pricingRevId));
            $pricingRevId = (int) $insertTplRes['revision']['id'];
            $pricingDoc = CanonicalJson::decode((string) $insertTplRes['revision']['document_json']);
            assert_eq(1, count($pricingDoc['sections']), 'pricing page has 1 section from template');
            assert_true($pricingDoc['sections'][0]['id'] !== $heroSecId, 'inserted section has brand new ID');

            $pricingPub = $rt->tenants->runAs(S4_TENANT_A, static fn(): array => $rt->app->publish($actorA, $pricingPageId, $pricingRevId));
            assert_eq('published', $pricingPub['page']['status']);

            $pricingServed = $rt->tenants->runAs(S4_TENANT_A, static fn() => $rt->publicRuntime->handlePath('s4-pricing-page'));
            assert_true($pricingServed !== null, 'pricing page served');
            assert_eq(200, $pricingServed->status);
            assert_true(str_contains($pricingServed->body, 'Welcome to the Future of Visual Building'), 'pricing page renders reusable template hero');

            // 9. Strict Multi-Tenant Isolation
            // Tenant B cannot view or use Tenant A's pages, templates, or components
            $servedB = $rt->tenants->runAs(S4_TENANT_B, static fn() => $rt->publicRuntime->handlePath('s4-home-page'));
            assert_null($servedB, 'Tenant B cannot serve Tenant A home page');

            assert_throws(StudioNotFoundException::class, static function () use ($rt, $actorB, $homePageId): void {
                $rt->tenants->runAs(S4_TENANT_B, fn() => $rt->app->loadEditorDocument($actorB, $homePageId));
            }, 'Tenant B cannot load Tenant A page');

            assert_throws(StudioNotFoundException::class, static function () use ($rt, $actorB, $homePageId, $cmpId): void {
                $rt->tenants->runAs(S4_TENANT_B, fn() => $rt->app->loadEditorDocument($actorB, $cmpId));
            }, 'Tenant B cannot view Tenant A component');
        });
    } finally {
        s4_drop_db($dbName);
    }
});
