<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — Phase 1 Provisioning,
 * Schema Verification, RBAC Registration, Entitlement Gating & Tenant Isolation.
 *
 * Exercises against a real throwaway MySQL database:
 *   1. Fresh-install Step 5 provisioning of `studio-builder`
 *   2. Baseline schema creation (`install.sql`), `StudioSchemaManager::schemaIsCurrent()`,
 *      and `StudioSchemaManager::ensureVerified()` + additive upgrade helpers (`ensureColumn`, `ensureIndex`)
 *   3. Database-level rejection of inserts missing `tenant_id` (proving no `DEFAULT 1` fallback)
 *   4. RBAC permission registration in `Auth::knownPermissions()` upon plugin activation
 *   5. Licensing entitlement gating via `EntitlementService`, `ModuleGuard`, and `StudioBuilder::isEntitled()`
 *   6. Strict multi-tenant isolation across `PageRepository`, `RevisionRepository`, `CompilationRepository`,
 *      `DependencyRepository`, `TemplateRepository`, `TokenRepository`, and `LockRepository`
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
    $studioIntStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Infrastructure\StudioSchemaManager;
use Slate\Module\StudioBuilder\Repository\CompilationRepository;
use Slate\Module\StudioBuilder\Repository\DependencyRepository;
use Slate\Module\StudioBuilder\Repository\LockRepository;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Module\StudioBuilder\Repository\TemplateRepository;
use Slate\Module\StudioBuilder\Repository\TokenRepository;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Licensing\EntitlementService;
use Slate\Services\Licensing\ModuleGuard;
use Slate\Tenancy\TenantContext;

const SBP1_CORE_MIGRATIONS = [
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

function sbp1_fresh_db(string $dbName): \PDO
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
    $runner->migrate(SBP1_CORE_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function sbp1_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function sbp1_with_pdo(\PDO $pdo, callable $fn): mixed
{
    $property = new \ReflectionProperty(\Slate\Data\Database::class, 'pdo');
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

unit('studio-builder phase 1 integration: provisioning, schema verification, RBAC permissions, licensing entitlement gating, and multi-tenant isolation', function (): void {
    $dbName = 'slate_sbp1_' . slate_test_ns();
    $pdo    = sbp1_fresh_db($dbName);

    try {
        sbp1_with_pdo($pdo, static function () use ($pdo): void {
            $core = InstallationService::provisionCore();
            InstallationService::createAdminAccount(
                $core['tenant_id'],
                'Studio Admin',
                'studio-admin@example.test',
                password_hash('password123', PASSWORD_DEFAULT)
            );

            // 1. Before plugin activation, Studio schema is not yet present
            assert_false(StudioSchemaManager::schemaIsCurrent(), 'Schema must be false before studio-builder is provisioned');

            // 2. Seed license without studio-builder first: entitlement & ModuleGuard must deny
            license_test_seed_cache((int) TENANT_ID, [
                'installation_id' => $core['installation_id'],
                'status'          => 'active',
                'plan'            => 'forms-only',
                'entitlements'    => ['forms'],
                'expires_at'      => null,
                'fetched_at'      => gmdate('Y-m-d H:i:s'),
            ]);
            assert_false(EntitlementService::canAccessCapability((int) TENANT_ID, 'studio-builder'));
            assert_false(ModuleGuard::isEntitled('studio-builder'));
            assert_false(\StudioBuilder::isEntitled());

            // 3. Seed license WITH studio-builder, validate Step 5 selection, and installFromDisk('studio-builder')
            license_test_seed_cache((int) TENANT_ID, [
                'installation_id' => $core['installation_id'],
                'status'          => 'active',
                'plan'            => 'studio-pro',
                'entitlements'    => ['forms', 'studio-builder'],
                'expires_at'      => null,
                'fetched_at'      => gmdate('Y-m-d H:i:s'),
            ]);
            assert_true(EntitlementService::canAccessCapability((int) TENANT_ID, 'studio-builder'));
            // Still false on ModuleGuard until plugin is activated
            assert_false(ModuleGuard::isEntitled('studio-builder'));

            $selection = CommercialModuleRegistry::validateSelection(['studio-builder'], ['forms', 'studio-builder']);
            assert_true($selection['ok']);

            $act = \PluginLoader::installFromDisk('studio-builder');
            assert_true(!empty($act['ok']), 'PluginLoader::installFromDisk(studio-builder) must succeed');
            assert_true(\PluginLoader::isActive('studio-builder'), 'studio-builder must be active');

            // 4. Boot plugin and verify schema stamps & StudioSchemaManager::schemaIsCurrent()
            $plugin = \PluginLoader::get('studio-builder');
            assert_true($plugin instanceof \StudioBuilder, 'Booted plugin instance must be StudioBuilder');
            assert_true(StudioSchemaManager::schemaIsCurrent(), 'All 7 studiobuilder_* tables and columns must be verified');
            assert_eq('1.0.0', (string) $plugin->setting('applied_version'));
            assert_eq('1.0.0', (string) $plugin->setting('schema_verified'));

            // Now both license entitlement AND active plugin are satisfied
            assert_true(ModuleGuard::isEntitled('studio-builder'));
            assert_true(\StudioBuilder::isEntitled());

            // 5. Verify RBAC permissions are registered in Auth::knownPermissions()
            \Auth::invalidatePermCache();
            $knownPerms = \Auth::knownPermissions();
            assert_true(isset($knownPerms['Kohevo Studio (plugin)']), 'Auth::knownPermissions() must include Kohevo Studio (plugin) group');
            $studioPermKeys = array_map(
                static fn(array $p): string => (string) ($p['key'] ?? ''),
                $knownPerms['Kohevo Studio (plugin)']
            );
            assert_eq([
                'studio-builder.view',
                'studio-builder.edit',
                'studio-builder.publish',
                'studio-builder.tokens',
                'studio-builder.admin',
            ], $studioPermKeys, 'All 5 studio-builder.* permissions must be registered in Auth::knownPermissions()');

            // 6. Verify DB-level tenant_id NOT NULL with no DEFAULT 1 on every studiobuilder_* table
            foreach (array_keys(StudioSchemaManager::REQUIRED_TABLES) as $table) {
                $colMeta = \Database::row(
                    "SELECT IS_NULLABLE, COLUMN_DEFAULT
                       FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = ?
                        AND COLUMN_NAME = 'tenant_id'",
                    [$table]
                );
                assert_true($colMeta !== null, "{$table}.tenant_id must exist");
                assert_eq('NO', strtoupper((string) $colMeta['IS_NULLABLE']), "{$table}.tenant_id must be NOT NULL");
                assert_null($colMeta['COLUMN_DEFAULT'], "{$table}.tenant_id must have NO default value");
            }

            // Verify raw insert omitting tenant_id fails at the database level under strict SQL mode
            $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
            $omittedTenantFailed = false;
            try {
                $pdo->exec("INSERT INTO `studiobuilder_pages` (`uuid`, `title`, `slug`) VALUES ('00000000-0000-4000-8000-000000000001', 'No Tenant', 'no-tenant')");
            } catch (\PDOException $e) {
                $omittedTenantFailed = true;
            }
            assert_true($omittedTenantFailed, 'Direct SQL insert into studiobuilder_pages without tenant_id must fail');

            // 7. Verify multi-tenant repository isolation across tenants 1 and 2
            $tenants  = new TenantContext();
            $pages    = new PageRepository($tenants);
            $revs     = new RevisionRepository($tenants);
            $comps    = new CompilationRepository($tenants);
            $deps     = new DependencyRepository($tenants);
            $tpls     = new TemplateRepository($tenants);
            $tokens   = new TokenRepository($tenants);
            $locks    = new LockRepository($tenants);

            $page1Id = $tenants->runAs(1, static fn(): int => $pages->insert([
                'uuid'       => '11111111-1111-4111-8111-111111111111',
                'title'      => 'Tenant 1 Home',
                'slug'       => 'home',
                'page_type'  => 'page',
                'status'     => 'draft',
                'route_mode' => 'homepage',
            ]));
            assert_true($page1Id > 0);

            // Tenant 2 can use the exact same slug & UUID without collision and cannot see Tenant 1's page
            $tenants->runAs(2, static function () use ($pages, $page1Id): void {
                assert_null($pages->find($page1Id), 'Tenant 2 must not find Tenant 1 page by ID');
                assert_null($pages->findBySlug('home'), 'Tenant 2 must not find Tenant 1 page by slug');
                assert_eq(0, $pages->count(), 'Tenant 2 page count must be 0');

                // Attempt foreign tenant_id spoofing during Tenant 2 scoped insert: must force tenant_id = 2
                $page2Id = $pages->insert([
                    'tenant_id'  => 1, // spoof attempt
                    'uuid'       => '22222222-2222-4222-8222-222222222222',
                    'title'      => 'Tenant 2 Home',
                    'slug'       => 'home',
                    'page_type'  => 'page',
                    'status'     => 'published',
                    'route_mode' => 'homepage',
                ]);
                $p2Row = $pages->find($page2Id);
                assert_true($p2Row !== null);
                assert_eq(2, (int) $p2Row['tenant_id'], 'Scoped insert must enforce TenantContext tenant_id and block spoofing');

                // Cross-tenant update/delete attempts must affect 0 rows
                assert_eq(0, $pages->update($page1Id, ['title' => 'Hijacked']));
                assert_eq(0, $pages->delete($page1Id));
            });

            $tenants->runAs(1, static function () use ($pages, $page1Id): void {
                assert_eq('Tenant 1 Home', (string) ($pages->find($page1Id)['title'] ?? ''));
            });

            // Verify RevisionRepository, CompilationRepository, DependencyRepository, TemplateRepository, TokenRepository, LockRepository
            $rev1Id = $tenants->runAs(1, static fn(): int => $revs->insert([
                'page_id'         => $page1Id,
                'revision_number' => 1,
                'revision_kind'   => 'manual',
                'schema_version'  => '1.0',
                'document_json'   => '{"version":"1.0","root":{"id":"root","type":"container","children":[]}}',
                'summary'         => 'Initial draft',
            ]));
            assert_true($rev1Id > 0);
            $tenants->runAs(2, static function () use ($revs, $rev1Id, $page1Id): void {
                assert_null($revs->find($rev1Id));
                assert_eq(0, count($revs->forPage($page1Id)));
            });
            $tenants->runAs(1, static function () use ($revs, $page1Id): void {
                assert_eq(1, count($revs->forPage($page1Id)));
            });

            $comp1Id = $tenants->runAs(1, static fn(): int => $comps->insert([
                'page_id'       => $page1Id,
                'revision_id'   => $rev1Id,
                'compile_mode'  => 'preview',
                'compiled_html' => '<div class="kb-root"></div>',
                'compiled_css'  => '.kb-root{}',
                'content_hash'  => str_repeat('a', 64),
            ]));
            assert_true($comp1Id > 0);
            $tenants->runAs(2, static function () use ($comps, $page1Id): void {
                assert_null($comps->findByPageAndMode($page1Id, 'preview'));
            });
            $tenants->runAs(1, static function () use ($comps, $page1Id): void {
                assert_true($comps->findByPageAndMode($page1Id, 'preview') !== null);
            });

            $dep1Id = $tenants->runAs(1, static fn(): int => $deps->insert([
                'page_id'         => $page1Id,
                'revision_id'     => $rev1Id,
                'node_id'         => 'node-hero-1',
                'dependency_type' => 'media',
                'dependency_key'  => 'media:101',
            ]));
            assert_true($dep1Id > 0);
            $tenants->runAs(1, static function () use ($deps, $page1Id, $rev1Id): void {
                assert_eq(1, count($deps->forPageRevision($page1Id, $rev1Id)));
            });
            $tenants->runAs(2, static function () use ($deps, $page1Id, $rev1Id): void {
                assert_eq(0, count($deps->forPageRevision($page1Id, $rev1Id)));
            });

            $tpl1Id = $tenants->runAs(1, static fn(): int => $tpls->insert([
                'uuid'          => '33333333-3333-4333-8333-333333333333',
                'template_key'  => 'hero-minimal',
                'template_type' => 'section_preset',
                'category'      => 'hero',
                'name'          => 'Minimal Hero',
                'document_json' => '{"version":"1.0","root":{}}',
            ]));
            assert_true($tpl1Id > 0);
            $tenants->runAs(2, static function () use ($tpls): void {
                assert_null($tpls->findByKey('hero-minimal'));
            });
            $tenants->runAs(1, static function () use ($tpls): void {
                assert_true($tpls->findByKey('hero-minimal') !== null);
            });

            $tok1Id = $tenants->runAs(1, static fn(): int => $tokens->insert([
                'token_group'       => 'default',
                'tokens_json'       => '{"color":{"primary":"#111111"}}',
                'compiled_css_vars' => ':root{--kb-color-primary:#111111;}',
            ]));
            assert_true($tok1Id > 0);
            $tenants->runAs(2, static function () use ($tokens): void {
                assert_null($tokens->findByGroupKey('default'));
            });
            $tenants->runAs(1, static function () use ($tokens): void {
                assert_true($tokens->findByGroupKey('default') !== null);
            });

            $lock1Id = $tenants->runAs(1, static fn(): int => $locks->insert([
                'page_id'    => $page1Id,
                'user_id'    => 1,
                'lock_token' => '44444444-4444-4444-8444-444444444444',
                'expires_at' => '2030-01-01 00:00:00',
            ]));
            assert_true($lock1Id > 0);
            $tenants->runAs(2, static function () use ($locks, $page1Id): void {
                assert_null($locks->findByPageId($page1Id));
            });
            $tenants->runAs(1, static function () use ($locks, $page1Id): void {
                assert_true($locks->findByPageId($page1Id) !== null);
            });

            // 8. Verify StudioSchemaManager idempotent additive helpers (ensureColumn + ensureIndex)
            StudioSchemaManager::ensureColumn('studiobuilder_pages', 'phase1_probe_col', 'VARCHAR(32) NULL DEFAULT NULL');
            // Second call must be a safe idempotent no-op
            StudioSchemaManager::ensureColumn('studiobuilder_pages', 'phase1_probe_col', 'VARCHAR(32) NULL DEFAULT NULL');
            StudioSchemaManager::ensureIndex('studiobuilder_pages', 'idx_studiobuilder_pages_probe', '(`tenant_id`, `phase1_probe_col`)');
            StudioSchemaManager::ensureIndex('studiobuilder_pages', 'idx_studiobuilder_pages_probe', '(`tenant_id`, `phase1_probe_col`)');

            $probeColExists = (int) \Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'studiobuilder_pages'
                    AND COLUMN_NAME = 'phase1_probe_col'"
            );
            assert_eq(1, $probeColExists);

            // 9. Verify deactivation preserves tables and stops entitlement access
            \PluginLoader::deactivate('studio-builder');
            assert_false(\PluginLoader::isActive('studio-builder'));
            assert_false(ModuleGuard::isEntitled('studio-builder'));
            assert_true(StudioSchemaManager::schemaIsCurrent(), 'Deactivation must preserve studiobuilder_* tables');
        });
    } finally {
        sbp1_drop_db($dbName);
    }
});

if (!empty($studioIntStandalone)) {
    exit(unit_summary());
}
