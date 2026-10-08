<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — B2-P2a built-in section presets.
 *
 * Real MySQL (throwaway database), the production wiring and the builder's real HTTP
 * controller. Tenant 101 has studio-builder; 202 has studio-builder too (isolation); 303 has
 * nothing. Covers seeding (first call, idempotent, per tenant), system-template protection,
 * tenant-key collisions, permissions, and inserting every preset into a real page and rendering it.
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
    $studioPAIntStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Exception\StudioConcurrencyException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioApiResponse;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Presets\SectionPresetCatalog;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const SBPA_CORE_MIGRATIONS = [
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

const SBPA_TENANT_A = 101;
const SBPA_TENANT_B = 202;
const SBPA_TENANT_C = 303;

function sbpa_fresh_db(string $dbName): \PDO
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
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(SBPA_CORE_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function sbpa_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function sbpa_with_pdo(\PDO $pdo, callable $fn): mixed
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

function sbpa_seed(int $tenantId, string $installationId, array $entitlements): void
{
    license_test_seed_cache($tenantId, [
        'installation_id' => $installationId,
        'status'          => 'active',
        'plan'            => 'phase6-' . implode('-', $entitlements ?: ['none']),
        'entitlements'    => $entitlements,
        'expires_at'      => null,
        'fetched_at'      => gmdate('Y-m-d H:i:s'),
    ]);
}

/** Run one builder API call as $actor inside $tenantId (the tenant is NEVER taken from the request). */
function sbpa_call(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $method, string $action, array $input = [], bool $csrf = true): StudioApiResponse
{
    $api = new StudioAuthoringApi($rt->app);
    $request = $method === 'POST'
        ? new StudioApiRequest('POST', $action, [], (string) json_encode($input, JSON_PRESERVE_ZERO_FRACTION), 'application/json', $csrf, 'same-origin')
        : new StudioApiRequest('GET', $action, array_map('strval', $input));
    return $rt->tenants->runAs($tenantId, static fn() => $api->handle($request, $actor));
}

/** @return array<string, mixed> */
function sbpa_ok(StudioApiResponse $r, string $what): array
{
    assert_true($r->isOk(), "{$what}: expected success, got {$r->status} " . substr($r->body(), 0, 500));
    return $r->data();
}

function sbpa_first_code(StudioApiResponse $r): string
{
    return (string) ($r->payload['error']['details']['errors'][0]['code'] ?? $r->errorCode());
}

function sbpa_count(string $sql, array $params = []): int
{
    return (int) Database::value($sql, $params);
}

/** @return array<string, mixed> */
function sbpa_heading(string $text): array
{
    return ['type' => 'core.heading', 'props' => ['text' => $text, 'level' => 'h2']];
}

/**
 * Create a page and fill it with the given blocks in ONE section through the
 * command API. @return array{id: int, rev: int, section: string, document: array<string, mixed>}
 */
function sbpa_make_page(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $title, string $slug, string $pageType, array $blocks, array $settings = []): array
{
    $created = sbpa_ok(sbpa_call($rt, $tenantId, $actor, 'POST', 'create_page', ['title' => $title, 'slug' => $slug, 'page_type' => $pageType, 'route_mode' => 'standalone']), "create {$slug}");
    $pageId = (int) $created['page']['id'];
    $rev = (int) $created['page']['active_draft_revision_id'];
    $ops = [['op' => 'insert_section', 'payload' => ['index' => 0, 'section' => ['label' => $title . ' section']]]];
    if ($settings !== []) {
        $ops[] = ['op' => 'update_settings', 'payload' => ['settings' => $settings]];
    }
    $r = sbpa_ok(sbpa_call($rt, $tenantId, $actor, 'POST', 'operations', ['page_id' => $pageId, 'expected_revision_id' => $rev, 'operations' => $ops]), "section {$slug}");
    $rev = (int) $r['revision']['id'];
    $sectionId = (string) $r['document']['sections'][0]['id'];
    foreach ($blocks as $i => $block) {
        $r = sbpa_ok(sbpa_call($rt, $tenantId, $actor, 'POST', 'operations', ['page_id' => $pageId, 'expected_revision_id' => $rev, 'operations' => [
            ['op' => 'insert_block', 'payload' => ['parent_id' => $sectionId, 'index' => $i, 'block' => $block]],
        ]]), "block {$i} on {$slug}");
        $rev = (int) $r['revision']['id'];
    }
    return ['id' => $pageId, 'rev' => $rev, 'section' => $sectionId, 'document' => $r['document']];
}

function sbpa_publish(StudioRuntime $rt, int $tenantId, StudioActor $publisher, int $pageId): int
{
    $status = sbpa_ok(sbpa_call($rt, $tenantId, $publisher, 'GET', 'status', ['page' => $pageId]), 'status')['page'];
    $pub = sbpa_ok(sbpa_call($rt, $tenantId, $publisher, 'POST', 'publish', ['page_id' => $pageId, 'expected_revision_id' => (int) $status['active_draft_revision_id']]), "publish {$pageId}");
    return (int) $pub['revision']['id'];
}

function sbpa_public(StudioRuntime $rt, int $tenantId, string $path): ?string
{
    $res = $rt->tenants->runAs($tenantId, static fn() => $rt->publicRuntime->handlePath($path, null));
    return $res === null ? null : $res->body;
}

function sbpa_canvas(StudioRuntime $rt, int $tenantId, StudioActor $actor, int $pageId): string
{
    return $rt->tenants->runAs($tenantId, static fn(): string => $rt->app->renderForEditor($actor, $pageId)->html);
}

/** @return list<string> */
function sbpa_ids(array $document): array
{
    $ids = [];
    $walk = static function (array $blocks) use (&$walk, &$ids): void {
        foreach ($blocks as $b) {
            $ids[] = (string) $b['id'];
            $walk(is_array($b['children'] ?? null) ? $b['children'] : []);
        }
    };
    foreach ($document['sections'] ?? [] as $s) {
        $ids[] = (string) $s['id'];
        $walk($s['blocks'] ?? []);
    }
    return $ids;
}


unit('section presets integration: seeding, protection, isolation and insertion (real MySQL, tenants 101/202/303)', function (): void {
    $dbName = 'slate_sbpa_' . slate_test_ns();
    $pdo = sbpa_fresh_db($dbName);

    try {
        sbpa_with_pdo($pdo, static function (): void {
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Studio Admin', 'studio-admin-pa@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\'), (?,?,?,\'active\')
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [SBPA_TENANT_A, 'Tenant A', 'tenant-a-pa', SBPA_TENANT_B, 'Tenant B', 'tenant-b-pa', SBPA_TENANT_C, 'Tenant C', 'tenant-c-pa']
            );
            sbpa_seed($owner, $iid, ['studio-builder']);
            $act = \PluginLoader::installFromDisk('studio-builder');
            assert_true(!empty($act['ok']), 'installFromDisk(studio-builder): ' . json_encode($act));
            Media::ensureSchema();
            sbpa_seed(SBPA_TENANT_A, $iid, ['studio-builder']);
            sbpa_seed(SBPA_TENANT_B, $iid, ['studio-builder']);
            sbpa_seed(SBPA_TENANT_C, $iid, []);

            $rt = StudioRuntimeFactory::build();
            $viewer = StudioActor::authenticated(60, [StudioPermissions::VIEW]);
            $editor = StudioActor::authenticated(61, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
            $admin  = StudioActor::authenticated(64, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::ADMIN]);
            $expected = array_column(SectionPresetCatalog::all(), 'key');
            $sysCount = static fn(int $tenant): int => $rt->tenants->runAs($tenant, static fn(): int => sbpa_count("SELECT COUNT(*) FROM studiobuilder_templates WHERE tenant_id = ? AND is_system = 1", [$tenant]));

            // Nothing is seeded until the Add panel asks; the plain library listing stays tenant-owned only.
            assert_eq(0, $sysCount(SBPA_TENANT_A), 'no system rows before first use');
            assert_eq([], sbpa_ok(sbpa_call($rt, SBPA_TENANT_A, $editor, 'GET', 'templates'), 'plain library')['templates']);
            assert_eq(0, $sysCount(SBPA_TENANT_A), 'listing the library does not seed');

            // 1. First call seeds; the rows are system templates of the right shape.
            $first = sbpa_ok(sbpa_call($rt, SBPA_TENANT_A, $viewer, 'GET', 'section_presets'), 'first call (a viewer may read)')['presets'];
            assert_eq($expected, array_column($first, 'template_key'), 'every catalogue key is present, in catalogue order');
            assert_eq(count($expected), $sysCount(SBPA_TENANT_A));
            foreach ($first as $row) {
                assert_true($row['is_system'] === true && $row['template_type'] === 'section_preset', "{$row['template_key']} is a system section preset");
                assert_true(is_array($row['outline'] ?? null) && $row['outline'] !== [], "{$row['template_key']} has a wireframe outline");
                assert_true(!isset($row['document_json']) && !isset($row['document']), 'no document body reaches the panel');
            }
            $encoded = (string) json_encode($first);
            assert_true(!str_contains($encoded, 'Your business') && !str_contains($encoded, 'hello@example.com'), 'the outline carries structure only, never copy');

            // 2. Idempotent: a second call neither duplicates nor rewrites rows.
            $before = $rt->tenants->runAs(SBPA_TENANT_A, static fn() => Database::all("SELECT template_key, updated_at FROM studiobuilder_templates WHERE tenant_id = ? AND is_system = 1 ORDER BY template_key", [SBPA_TENANT_A]));
            // the per-request memo is process-wide: clear it so the second call exercises the DB path
            (new \ReflectionProperty(\Slate\Module\StudioBuilder\Application\StudioApplicationService::class, 'presetsSynced'))->setValue(null, []);
            sbpa_ok(sbpa_call($rt, SBPA_TENANT_A, $editor, 'GET', 'section_presets'), 'second call');
            $after = $rt->tenants->runAs(SBPA_TENANT_A, static fn() => Database::all("SELECT template_key, updated_at FROM studiobuilder_templates WHERE tenant_id = ? AND is_system = 1 ORDER BY template_key", [SBPA_TENANT_A]));
            assert_eq($before, $after, 'unchanged presets are not rewritten');
            assert_eq(count($expected), $sysCount(SBPA_TENANT_A), 'no duplicates');

            // 3. Isolation: tenant B has none until it asks; then it has its own copies; tenant C (no entitlement) is refused.
            assert_eq(0, $sysCount(SBPA_TENANT_B), 'tenant B untouched by tenant A');
            sbpa_ok(sbpa_call($rt, SBPA_TENANT_B, $editor, 'GET', 'section_presets'), 'tenant B call');
            assert_eq(count($expected), $sysCount(SBPA_TENANT_B));
            $c = sbpa_call($rt, SBPA_TENANT_C, $editor, 'GET', 'section_presets');
            assert_true(!$c->isOk(), 'a tenant without the studio-builder entitlement cannot read presets');
            assert_eq(0, $sysCount(SBPA_TENANT_C));

            // 4. System presets are immutable server-side (overwrite and delete), even for an admin.
            $page = sbpa_make_page($rt, SBPA_TENANT_A, $editor, 'Home', 'home', 'page', [['type' => 'core.heading', 'props' => ['text' => 'Home', 'level' => 'h1']]]);
            $overwrite = sbpa_call($rt, SBPA_TENANT_A, $admin, 'POST', 'save_template', ['page_id' => $page['id'], 'node_id' => $page['section'], 'template_key' => 'system-section-faq', 'template_type' => 'section_preset', 'category' => 'content', 'name' => 'Mine', 'description' => null, 'thumbnail_media_id' => null]);
            assert_eq('system_template_immutable', sbpa_first_code($overwrite), 'cannot overwrite a system preset');
            $delete = sbpa_call($rt, SBPA_TENANT_A, $admin, 'POST', 'delete_template', ['template_key' => 'system-section-faq']);
            assert_eq('system_template_immutable', sbpa_first_code($delete), 'cannot delete a system preset');

            // 5. A tenant template that already owns a preset key is never clobbered.
            sbpa_seed(SBPA_TENANT_C, $iid, ['studio-builder']);
            $own = sbpa_make_page($rt, SBPA_TENANT_C, $editor, 'Own', 'own', 'page', [['type' => 'core.heading', 'props' => ['text' => 'Mine', 'level' => 'h2']]]);
            sbpa_ok(sbpa_call($rt, SBPA_TENANT_C, $admin, 'POST', 'save_template', ['page_id' => $own['id'], 'node_id' => $own['section'], 'template_key' => 'system-section-cta', 'template_type' => 'section_preset', 'category' => 'cta', 'name' => 'Tenant owned CTA', 'description' => null, 'thumbnail_media_id' => null]), 'tenant template on a preset key');
            (new \ReflectionProperty(\Slate\Module\StudioBuilder\Application\StudioApplicationService::class, 'presetsSynced'))->setValue(null, []);
            sbpa_ok(sbpa_call($rt, SBPA_TENANT_C, $editor, 'GET', 'section_presets'), 'seed beside a colliding tenant key');
            $row = $rt->tenants->runAs(SBPA_TENANT_C, static fn() => $rt->templates->findByKey('system-section-cta'));
            assert_true((int) $row['is_system'] === 0 && $row['name'] === 'Tenant owned CTA', 'the tenant\'s own template survives untouched');
            assert_eq(count($expected) - 1, $sysCount(SBPA_TENANT_C), 'every other preset is still seeded');

            // 6. Every preset inserts as a real, editable section (fresh ids) and renders in the editor canvas.
            foreach ($expected as $key) {
                $status = sbpa_ok(sbpa_call($rt, SBPA_TENANT_A, $editor, 'GET', 'status', ['page' => $page['id']]), 'status')['page'];
                $r = sbpa_ok(sbpa_call($rt, SBPA_TENANT_A, $editor, 'POST', 'insert_template', ['page_id' => $page['id'], 'template_key' => $key, 'index' => 0, 'expected_revision_id' => (int) $status['active_draft_revision_id']]), "insert {$key}");
                $doc = $r['document'];
                assert_true(count($doc['sections']) >= 2, "{$key} added a section");
                $ids = sbpa_ids($doc);
                assert_eq(count($ids), count(array_unique($ids)), "{$key}: ids stay unique after insertion");
            }
            $html = sbpa_canvas($rt, SBPA_TENANT_A, $editor, $page['id']);
            assert_true(str_contains($html, 'A clear promise for your customers') && str_contains($html, 'Frequently asked questions') && str_contains($html, 'Simple pricing'), 'the presets render real content in the canvas');
            assert_true(!str_contains($html, '<script'), 'no script in rendered presets');
        });
    } finally {
        sbpa_drop_db($dbName);
    }
});

if (!empty($studioPAIntStandalone)) {
    exit(unit_summary());
}
