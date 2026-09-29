<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Phase 1 Foundation.
 *
 * Verifies:
 *   1. Manifest (`plugins/studio-builder/plugin.json`), RBAC permissions, and bootstrap class
 *   2. Canonical baseline SQL (`plugins/studio-builder/install.sql` & `uninstall.sql`) and absence of `DEFAULT 1` on `tenant_id`
 *   3. Single Schema Authority (`StudioSchemaManager` has no duplicated `CREATE TABLE` DDL; `db/migrations/` untouched)
 *   4. Client `CommercialModuleRegistry` registration for `studio-builder`
 *   5. `StudioRepository` & concrete repositories tenant scoping and fail-closed guards
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!class_exists('PluginLoader', false)) {
    class_alias(\Slate\Kernel\Module\PluginLoader::class, 'PluginLoader');
}
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
    $studioUnitStandalone = true;
}

use Slate\Data\QueryBuilder;
use Slate\Kernel\Module\PluginLoader;
use Slate\Module\StudioBuilder\Infrastructure\StudioSchemaManager;
use Slate\Module\StudioBuilder\Repository\CompilationRepository;
use Slate\Module\StudioBuilder\Repository\DependencyRepository;
use Slate\Module\StudioBuilder\Repository\LockRepository;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Module\StudioBuilder\Repository\StudioRepository;
use Slate\Module\StudioBuilder\Repository\TemplateRepository;
use Slate\Module\StudioBuilder\Repository\TokenRepository;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Tenancy\TenantContext;

/** Test double exposing StudioRepository protected query builder for unit inspection. */
final class _StudioPageRepoProbe extends StudioRepository
{
    protected string $table = 'studiobuilder_pages';

    public function q(): QueryBuilder
    {
        return $this->query();
    }
}

/** Test double verifying non-studiobuilder_ table names are rejected. */
final class _InvalidPrefixStudioRepo extends StudioRepository
{
    protected string $table = 'pages';
}

unit('studio-builder phase 1: plugin.json validates and declares commercial_module + 5 RBAC permissions', function (): void {
    $manifestPath = SLATE_ROOT . '/plugins/studio-builder/plugin.json';
    assert_true(is_file($manifestPath), 'plugins/studio-builder/plugin.json must exist');

    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    assert_true(is_array($manifest), 'plugin.json must be valid JSON');

    $validation = PluginLoader::validateManifest($manifest);
    assert_true($validation['ok'], 'plugin.json must pass PluginLoader::validateManifest: ' . ($validation['error'] ?? ''));

    assert_eq('studio-builder', $manifest['slug']);
    assert_eq('Kohevo Studio', $manifest['name']);
    assert_eq('1.0.0', $manifest['version']);
    assert_eq('Kohevo', $manifest['author']);
    assert_eq('studio-builder', $manifest['commercial_module']['entitlement'] ?? null);

    $permKeys = array_map(static fn(array $p): string => (string) ($p['key'] ?? ''), $manifest['permissions'] ?? []);
    assert_eq([
        'studio-builder.view',
        'studio-builder.edit',
        'studio-builder.publish',
        'studio-builder.tokens',
        'studio-builder.admin',
    ], $permKeys, 'plugin.json must declare the exact 5 Phase 1 Studio RBAC permissions');

    $bootstrapPath = SLATE_ROOT . '/plugins/studio-builder/' . PluginLoader::slugToClass('studio-builder') . '.php';
    assert_true(is_file($bootstrapPath), 'StudioBuilder.php bootstrap file must exist');
});

unit('studio-builder phase 1: install.sql and uninstall.sql pass validatePluginSql and enforce tenant_id NOT NULL with no DEFAULT 1', function (): void {
    $installPath   = SLATE_ROOT . '/plugins/studio-builder/install.sql';
    $uninstallPath = SLATE_ROOT . '/plugins/studio-builder/uninstall.sql';

    assert_true(is_file($installPath), 'install.sql must exist');
    assert_true(is_file($uninstallPath), 'uninstall.sql must exist');

    $installSql   = (string) file_get_contents($installPath);
    $uninstallSql = (string) file_get_contents($uninstallPath);

    $sqlValidation = PluginLoader::validatePluginSql('studio-builder', $installSql, $uninstallSql);
    assert_true($sqlValidation['ok'], 'install.sql/uninstall.sql must pass validatePluginSql: ' . ($sqlValidation['error'] ?? ''));

    // Extract all CREATE TABLE blocks and verify exact 7 studiobuilder_* tables
    preg_match_all(
        '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?([a-z0-9_]+)`?\s*\((.*?)\)\s*ENGINE=/is',
        $installSql,
        $matches,
        PREG_SET_ORDER
    );

    $expectedTables = array_keys(StudioSchemaManager::REQUIRED_TABLES);
    $foundTables    = array_map(static fn(array $m): string => $m[1], $matches);
    assert_eq($expectedTables, $foundTables, 'install.sql must define the exact 7 studiobuilder_* tables in order');

    foreach ($matches as $m) {
        $table = $m[1];
        $body  = $m[2];

        assert_true(
            (bool) preg_match('/`tenant_id`\s+INT\s+UNSIGNED\s+NOT\s+NULL\s*,/i', $body),
            "Table {$table} must declare `tenant_id` INT UNSIGNED NOT NULL without a default"
        );
        assert_false(
            (bool) preg_match('/`tenant_id`[^\n,]*DEFAULT/i', $body),
            "Table {$table} must NOT declare any DEFAULT on tenant_id"
        );

        foreach (StudioSchemaManager::REQUIRED_TABLES[$table] as $requiredCol) {
            assert_true(
                str_contains($body, "`{$requiredCol}`"),
                "install.sql table {$table} must define column `{$requiredCol}`"
            );
        }

        assert_true(
            str_contains($uninstallSql, "DROP TABLE IF EXISTS `{$table}`;"),
            "uninstall.sql must drop `{$table}`"
        );
    }
});

unit('studio-builder phase 1: single schema authority — no CREATE TABLE in StudioSchemaManager and no studiobuilder_* in db/migrations', function (): void {
    $managerSrc = (string) file_get_contents(
        SLATE_ROOT . '/src/Module/StudioBuilder/Infrastructure/StudioSchemaManager.php'
    );

    // Strip comments to verify executable PHP code contains zero CREATE TABLE definitions
    $codeOnly = '';
    foreach (token_get_all($managerSrc) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $codeOnly .= is_array($token) ? $token[1] : $token;
    }
    assert_false(
        stripos($codeOnly, 'CREATE TABLE') !== false,
        'StudioSchemaManager executable code must not contain duplicate CREATE TABLE DDL'
    );

    // Verify 01-client/db/migrations/ has no studiobuilder_* tables and dormant content migrations remain untouched
    foreach (glob(SLATE_ROOT . '/db/migrations/*.php') ?: [] as $migFile) {
        $migSrc = (string) file_get_contents($migFile);
        assert_false(
            stripos($migSrc, 'studiobuilder_') !== false,
            'Core migration ' . basename($migFile) . ' must not reference studiobuilder_* tables'
        );
    }

    foreach ([
        '0004_content_revisions.php',
        '0012_contentbuilder_draft_published.php',
        '0018_content_compilation_artifacts.php',
        '0019_content_pages.php',
        '0020_document_templates.php',
    ] as $dormantFile) {
        assert_true(
            is_file(SLATE_ROOT . '/db/migrations/' . $dormantFile),
            "Existing migration {$dormantFile} must remain present and untouched"
        );
    }
});

unit('studio-builder phase 1: CommercialModuleRegistry includes studio-builder and validates selection', function (): void {
    $catalog = CommercialModuleRegistry::buildValidatedCatalog();
    assert_true(isset($catalog['studio-builder']), 'CommercialModuleRegistry catalog must contain studio-builder');
    assert_eq('Kohevo Studio', $catalog['studio-builder']['display_name']);
    assert_eq('studio-builder', $catalog['studio-builder']['plugin_slug']);
    assert_true($catalog['studio-builder']['v1_available']);
    assert_true($catalog['studio-builder']['commercial']);
    assert_eq([], $catalog['studio-builder']['required_infrastructure']);

    $entitled = CommercialModuleRegistry::entitledDefinitions(['forms', 'studio-builder']);
    assert_true(isset($entitled['studio-builder']), 'entitledDefinitions must include studio-builder when licensed');

    $notEntitled = CommercialModuleRegistry::entitledDefinitions(['forms']);
    assert_false(isset($notEntitled['studio-builder']), 'entitledDefinitions must exclude studio-builder when not licensed');

    $selOk = CommercialModuleRegistry::validateSelection(['studio-builder'], ['forms', 'studio-builder']);
    assert_true($selOk['ok'], 'Selecting studio-builder when entitled must succeed');
    assert_eq(['studio-builder'], $selOk['selected']);

    $infra = CommercialModuleRegistry::resolveInfrastructure(['studio-builder']);
    assert_true($infra['ok']);
    assert_eq([], $infra['infrastructure']);
});

unit('studio-builder phase 1: StudioRepository and all 7 concrete repositories enforce tenant scoping and fail-closed guards', function (): void {
    $tenants = new TenantContext();

    $repos = [
        'studiobuilder_pages'        => new PageRepository($tenants),
        'studiobuilder_revisions'    => new RevisionRepository($tenants),
        'studiobuilder_compilations' => new CompilationRepository($tenants),
        'studiobuilder_dependencies' => new DependencyRepository($tenants),
        'studiobuilder_templates'    => new TemplateRepository($tenants),
        'studiobuilder_tokens'       => new TokenRepository($tenants),
        'studiobuilder_locks'        => new LockRepository($tenants),
    ];

    foreach ($repos as $expectedTable => $repo) {
        assert_eq($expectedTable, $repo->tableName());
    }

    // Verify query builder SQL includes WHERE `tenant_id` = ? for the active tenant
    $probe = new _StudioPageRepoProbe($tenants);
    $tenants->runAs(42, static function () use ($probe): void {
        assert_eq('SELECT * FROM `studiobuilder_pages` WHERE `tenant_id` = ?', $probe->q()->toSelectSql());
        assert_eq([42], $probe->q()->whereBindings());
    });

    // Verify fail-closed when scoped TenantContext has id <= 0
    $caughtZeroTenant = false;
    try {
        $tenants->runAs(-1, static function () use ($probe): void {
            $probe->q();
        });
    } catch (\RuntimeException $e) {
        $caughtZeroTenant = str_contains($e->getMessage(), 'requires a valid positive tenant_id');
    }
    assert_true($caughtZeroTenant, 'Scoped StudioRepository must fail closed when tenant_id <= 0');

    // Verify non-studiobuilder_ table prefix is rejected
    $caughtPrefix = false;
    try {
        new _InvalidPrefixStudioRepo($tenants);
    } catch (\InvalidArgumentException $e) {
        $caughtPrefix = str_contains($e->getMessage(), 'studiobuilder_*');
    }
    assert_true($caughtPrefix, 'StudioRepository must reject table names not starting with studiobuilder_');
});

if (!empty($studioUnitStandalone)) {
    exit(unit_summary());
}
