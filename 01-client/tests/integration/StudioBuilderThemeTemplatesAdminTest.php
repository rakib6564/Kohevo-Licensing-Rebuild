<?php
/**
 * Integration test for Studio Builder Theme Templates Admin.
 *
 * Verifies:
 * - Templates creation across all types (header, footer, single, archive, not_found).
 * - studiobuilder_templates schema conformance (template_type ENUM, Canonical schema v1.0).
 * - studiobuilder_pages backing page linkage (page_type, route_mode, active_draft_revision_id).
 * - ThemeTemplateResolver resolution when published.
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

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Module\StudioBuilder\Theme\ThemeTemplateResolver;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const SB_TPL_MIGRATIONS = [
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

const SB_TPL_TENANT = 101;

function sb_tpl_fresh_db(string $dbName): \PDO
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
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(SB_TPL_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function sb_tpl_with_pdo(\PDO $pdo, callable $fn): mixed
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

unit('theme templates admin: creation, canonical schema, backing page, and resolver attachment', function (): void {
    $dbName = 'slate_test_studio_tpl_' . bin2hex(random_bytes(4));
    $pdo = sb_tpl_fresh_db($dbName);

    try {
        sb_tpl_with_pdo($pdo, static function () use ($pdo): void {
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];

            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\')
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [SB_TPL_TENANT, 'Tenant A Theme Master', 'tenant-a-tpl']
            );

            license_test_seed_cache(SB_TPL_TENANT, [
                'installation_id' => $iid,
                'status' => 'active',
                'plan' => 'pro',
                'entitlements' => ['studio-builder', 'studio-builder.tokens'],
                'expires_at' => null,
                'fetched_at' => gmdate('Y-m-d H:i:s'),
            ]);

            \PluginLoader::installFromDisk('studio-builder');
            Media::ensureSchema();

            Database::query(
                'INSERT INTO users (id, tenant_id, email, password_hash, name, role_id) VALUES (?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [10, SB_TPL_TENANT, 'admin@tenanta.test', 'hash', 'Alice TenantA']
            );

            $rt = StudioRuntimeFactory::build();
            $actor = StudioActor::authenticated(10, StudioPermissions::ALL);

            $typesToTest = [
                'header'    => ['expected_tpl_type' => 'header_preset', 'expected_page_type' => 'header_partial', 'expected_role' => 'header'],
                'footer'    => ['expected_tpl_type' => 'footer_preset', 'expected_page_type' => 'footer_partial', 'expected_role' => 'footer'],
                'single'    => ['expected_tpl_type' => 'page_template', 'expected_page_type' => 'page',            'expected_role' => 'single'],
                'archive'   => ['expected_tpl_type' => 'page_template', 'expected_page_type' => 'system',          'expected_role' => 'archive'],
                'not_found' => ['expected_tpl_type' => 'page_template', 'expected_page_type' => 'system',          'expected_role' => '404'],
            ];

            $now = date('Y-m-d H:i:s');

            foreach ($typesToTest as $uiType => $expected) {
                $name = "Custom " . ucfirst($uiType);
                $cleanType = str_replace('_', '-', $uiType);
                $templateKey = $cleanType . '-test-' . substr(bin2hex(random_bytes(2)), 0, 4);

                $conditionRules = match($uiType) {
                    'header' => [['type' => 'include', 'condition' => 'entire_site']],
                    default  => [['type' => 'include', 'condition' => 'entire_site']],
                };

                $starterDoc = CanonicalDocumentSchema::emptyDocument($expected['expected_page_type'], 'default', $name);
                $starterDoc['settings']['conditions'] = ['rules' => $conditionRules];
                $starterDoc['settings']['template_type'] = $expected['expected_role'];

                $genUuid = static fn(): string => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                );

                $tplId = Database::insert('studiobuilder_templates', [
                    'tenant_id'          => SB_TPL_TENANT,
                    'uuid'               => $genUuid(),
                    'template_key'       => $templateKey,
                    'template_type'      => $expected['expected_tpl_type'],
                    'category'           => $uiType,
                    'name'               => $name,
                    'description'        => "Test {$uiType}",
                    'schema_version'     => CanonicalDocumentSchema::SCHEMA_VERSION,
                    'document_json'      => CanonicalJson::encode($starterDoc),
                    'is_system'          => 0,
                    'created_by'         => 10,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]);

                assert_true($tplId > 0, "Inserted {$uiType} template into studiobuilder_templates");

                $pageSlug = 'template-' . $templateKey;
                $pageId = Database::insert('studiobuilder_pages', [
                    'tenant_id'                => SB_TPL_TENANT,
                    'uuid'                     => $genUuid(),
                    'slug'                     => $pageSlug,
                    'title'                    => "[Theme] {$name}",
                    'page_type'                => $expected['expected_page_type'],
                    'route_mode'               => 'standalone',
                    'status'                   => 'draft',
                    'active_draft_revision_id' => null,
                    'published_revision_id'    => null,
                    'seo_json'                 => json_encode(['noindex' => true]),
                    'settings_json'            => CanonicalJson::encode([
                        'is_theme_template' => true,
                        'template_id'       => $tplId,
                        'template_type'     => $expected['expected_role'],
                        'conditions'        => ['rules' => $conditionRules],
                    ]),
                    'created_by'               => 10,
                    'updated_by'               => 10,
                    'created_at'               => $now,
                    'updated_at'               => $now,
                ]);

                assert_true($pageId > 0, "Inserted backing page for {$uiType}");

                $revId = Database::insert('studiobuilder_revisions', [
                    'tenant_id'          => SB_TPL_TENANT,
                    'page_id'            => $pageId,
                    'revision_number'    => 1,
                    'revision_kind'      => 'manual',
                    'schema_version'     => CanonicalDocumentSchema::SCHEMA_VERSION,
                    'document_json'      => CanonicalJson::encode($starterDoc),
                    'summary'            => "Theme template created: {$name}",
                    'parent_revision_id' => null,
                    'created_by'         => 10,
                    'created_at'         => $now,
                ]);

                Database::query("UPDATE studiobuilder_pages SET active_draft_revision_id = ? WHERE id = ? AND tenant_id = ?", [$revId, $pageId, SB_TPL_TENANT]);

                // Verify loadEditorDocument works
                $editorState = $rt->tenants->runAs(SB_TPL_TENANT, static fn() => $rt->app->loadEditorDocument($actor, $pageId));
                assert_true(!empty($editorState['document']), "Editor loads document for {$uiType}");
                assert_eq(CanonicalDocumentSchema::SCHEMA_VERSION, $editorState['document']['schema_version']);

                // Publish template
                try {
                    $publishResult = $rt->tenants->runAs(SB_TPL_TENANT, static fn() => $rt->app->publish($actor, $pageId, $revId, "Published {$name}"));
                } catch (\Throwable $ve) {
                    $errs = method_exists($ve, 'errors') ? json_encode($ve->errors()) : $ve->getMessage();
                    assert_true(false, "Error for {$uiType} (" . get_class($ve) . "): " . $errs);
                }
                assert_true(!empty($publishResult['page']['published_revision_id']), "Published {$uiType}");

                $updatedPage = Database::row("SELECT status, published_revision_id FROM studiobuilder_pages WHERE id = ?", [$pageId]);
                assert_eq('published', $updatedPage['status']);
                assert_true((int) $updatedPage['published_revision_id'] > 0);
            }

            // Verify ThemeTemplateResolver resolves published header
            $targetPage = PageAddress::fromRow([
                'id' => 999,
                'uuid' => '99999999-9999-9999-9999-999999999999',
                'title' => 'Sample Page',
                'slug' => 'sample-page',
                'page_type' => 'page',
                'status' => 'published',
                'route_mode' => 'standalone',
                'published_revision_id' => 1,
            ]);

            $resolver = new ThemeTemplateResolver($rt->pages, $rt->revisions);
            $chrome = $rt->tenants->runAs(SB_TPL_TENANT, static fn() => $resolver->resolveHeader($targetPage));
            assert_true($chrome !== null, 'ThemeTemplateResolver successfully resolved published custom header');

            $notfound = $rt->tenants->runAs(SB_TPL_TENANT, static fn() => $resolver->resolveNotFound());
            assert_true($notfound !== null, 'ThemeTemplateResolver successfully resolved 404 template');
            assert_eq('system', $notfound['page']['page_type']);
        });
    } finally {
        $root = new \PDO('mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET, DB_USER, DB_PASS);
        $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    }
});
