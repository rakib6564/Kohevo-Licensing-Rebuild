<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — B2-P2c page commands (rename, duplicate, archive).
 *
 * Real MySQL (throwaway database), the production wiring and the builder's real HTTP
 * controller. Tenant 101 has studio-builder; 202 has studio-builder too (isolation); 303 has
 * nothing. Covers rename (title/slug, duplicates, reserved slugs), duplicate (document copied, new ids, never published,
 * never the homepage), archive, permissions, tenant isolation and the audit trail.
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
    $studioPGIntStandalone = true;
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

const SBPG_CORE_MIGRATIONS = [
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

const SBPG_TENANT_A = 101;
const SBPG_TENANT_B = 202;
const SBPG_TENANT_C = 303;

function sbpg_fresh_db(string $dbName): \PDO
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
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(SBPG_CORE_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function sbpg_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function sbpg_with_pdo(\PDO $pdo, callable $fn): mixed
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

function sbpg_seed(int $tenantId, string $installationId, array $entitlements): void
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
function sbpg_call(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $method, string $action, array $input = [], bool $csrf = true): StudioApiResponse
{
    $api = new StudioAuthoringApi($rt->app);
    $request = $method === 'POST'
        ? new StudioApiRequest('POST', $action, [], (string) json_encode($input, JSON_PRESERVE_ZERO_FRACTION), 'application/json', $csrf, 'same-origin')
        : new StudioApiRequest('GET', $action, array_map('strval', $input));
    return $rt->tenants->runAs($tenantId, static fn() => $api->handle($request, $actor));
}

/** @return array<string, mixed> */
function sbpg_ok(StudioApiResponse $r, string $what): array
{
    assert_true($r->isOk(), "{$what}: expected success, got {$r->status} " . substr($r->body(), 0, 500));
    return $r->data();
}

function sbpg_first_code(StudioApiResponse $r): string
{
    return (string) ($r->payload['error']['details']['errors'][0]['code'] ?? $r->errorCode());
}

function sbpg_count(string $sql, array $params = []): int
{
    return (int) Database::value($sql, $params);
}

/** @return array<string, mixed> */
function sbpg_heading(string $text): array
{
    return ['type' => 'core.heading', 'props' => ['text' => $text, 'level' => 'h2']];
}

/**
 * Create a page and fill it with the given blocks in ONE section through the
 * command API. @return array{id: int, rev: int, section: string, document: array<string, mixed>}
 */
function sbpg_make_page(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $title, string $slug, string $pageType, array $blocks, array $settings = []): array
{
    $created = sbpg_ok(sbpg_call($rt, $tenantId, $actor, 'POST', 'create_page', ['title' => $title, 'slug' => $slug, 'page_type' => $pageType, 'route_mode' => 'standalone']), "create {$slug}");
    $pageId = (int) $created['page']['id'];
    $rev = (int) $created['page']['active_draft_revision_id'];
    $ops = [['op' => 'insert_section', 'payload' => ['index' => 0, 'section' => ['label' => $title . ' section']]]];
    if ($settings !== []) {
        $ops[] = ['op' => 'update_settings', 'payload' => ['settings' => $settings]];
    }
    $r = sbpg_ok(sbpg_call($rt, $tenantId, $actor, 'POST', 'operations', ['page_id' => $pageId, 'expected_revision_id' => $rev, 'operations' => $ops]), "section {$slug}");
    $rev = (int) $r['revision']['id'];
    $sectionId = (string) $r['document']['sections'][0]['id'];
    foreach ($blocks as $i => $block) {
        $r = sbpg_ok(sbpg_call($rt, $tenantId, $actor, 'POST', 'operations', ['page_id' => $pageId, 'expected_revision_id' => $rev, 'operations' => [
            ['op' => 'insert_block', 'payload' => ['parent_id' => $sectionId, 'index' => $i, 'block' => $block]],
        ]]), "block {$i} on {$slug}");
        $rev = (int) $r['revision']['id'];
    }
    return ['id' => $pageId, 'rev' => $rev, 'section' => $sectionId, 'document' => $r['document']];
}

function sbpg_publish(StudioRuntime $rt, int $tenantId, StudioActor $publisher, int $pageId): int
{
    $status = sbpg_ok(sbpg_call($rt, $tenantId, $publisher, 'GET', 'status', ['page' => $pageId]), 'status')['page'];
    $pub = sbpg_ok(sbpg_call($rt, $tenantId, $publisher, 'POST', 'publish', ['page_id' => $pageId, 'expected_revision_id' => (int) $status['active_draft_revision_id']]), "publish {$pageId}");
    return (int) $pub['revision']['id'];
}

function sbpg_public(StudioRuntime $rt, int $tenantId, string $path): ?string
{
    $res = $rt->tenants->runAs($tenantId, static fn() => $rt->publicRuntime->handlePath($path, null));
    return $res === null ? null : $res->body;
}

function sbpg_canvas(StudioRuntime $rt, int $tenantId, StudioActor $actor, int $pageId): string
{
    return $rt->tenants->runAs($tenantId, static fn(): string => $rt->app->renderForEditor($actor, $pageId)->html);
}

/** @return list<string> */
function sbpg_ids(array $document): array
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


unit('page commands integration: rename, duplicate, archive, permissions and isolation (real MySQL, tenants 101/202)', function (): void {
    $dbName = 'slate_sbpg_' . slate_test_ns();
    $pdo = sbpg_fresh_db($dbName);

    try {
        sbpg_with_pdo($pdo, static function (): void {
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Studio Admin', 'studio-admin-pg@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\') ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [SBPG_TENANT_A, 'Tenant A', 'tenant-a-pg', SBPG_TENANT_B, 'Tenant B', 'tenant-b-pg']
            );
            sbpg_seed($owner, $iid, ['studio-builder']);
            $act = \PluginLoader::installFromDisk('studio-builder');
            assert_true(!empty($act['ok']), 'installFromDisk(studio-builder): ' . json_encode($act));
            Media::ensureSchema();
            sbpg_seed(SBPG_TENANT_A, $iid, ['studio-builder']);
            sbpg_seed(SBPG_TENANT_B, $iid, ['studio-builder']);

            $rt = StudioRuntimeFactory::build();
            $viewer = StudioActor::authenticated(60, [StudioPermissions::VIEW]);
            $editor = StudioActor::authenticated(61, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
            $publisher = StudioActor::authenticated(62, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            $audits = static fn(string $event): int => sbpg_count("SELECT COUNT(*) FROM audit_log WHERE action = ?", [$event]);

            $src = sbpg_make_page($rt, SBPG_TENANT_A, $publisher, 'About us', 'about', 'page', [sbpg_heading('Our story'), sbpg_heading('Our team')]);
            sbpg_publish($rt, SBPG_TENANT_A, $publisher, $src['id']);

            // ── rename ────────────────────────────────────────────────────────────
            $r = sbpg_ok(sbpg_call($rt, SBPG_TENANT_A, $editor, 'POST', 'update_page', ['page_id' => $src['id'], 'title' => 'About Kohevo']), 'rename title');
            assert_eq('About Kohevo', $r['page']['title']);
            assert_eq('about', $r['page']['slug'], 'a title-only rename keeps the address');
            assert_true($r['page']['is_published'] === true, 'a rename does not unpublish');
            $r = sbpg_ok(sbpg_call($rt, SBPG_TENANT_A, $editor, 'POST', 'update_page', ['page_id' => $src['id'], 'slug' => 'about-kohevo']), 'rename slug');
            assert_eq('/about-kohevo', $r['page']['public_path']);
            assert_eq('invalid_field', sbpg_first_code(sbpg_call($rt, SBPG_TENANT_A, $editor, 'POST', 'update_page', ['page_id' => $src['id']])), 'nothing to change is refused');
            assert_true(!sbpg_call($rt, SBPG_TENANT_A, $viewer, 'POST', 'update_page', ['page_id' => $src['id'], 'title' => 'x'])->isOk(), 'a viewer cannot rename');
            assert_true(!sbpg_call($rt, SBPG_TENANT_A, $editor, 'POST', 'update_page', ['page_id' => $src['id'], 'title' => 'x'], false)->isOk(), 'a rename needs the CSRF token');
            assert_true(!sbpg_call($rt, SBPG_TENANT_A, $editor, 'POST', 'update_page', ['page_id' => $src['id'], 'slug' => 'Not A Slug!'])->isOk(), 'an invalid slug is refused');
            assert_true(!sbpg_call($rt, SBPG_TENANT_A, $editor, 'POST', 'update_page', ['page_id' => $src['id'], 'slug' => 'admin'])->isOk(), 'a reserved slug is refused');
            $other = sbpg_make_page($rt, SBPG_TENANT_A, $editor, 'Contact', 'contact', 'page', []);
            assert_eq('duplicate_slug', sbpg_first_code(sbpg_call($rt, SBPG_TENANT_A, $editor, 'POST', 'update_page', ['page_id' => $other['id'], 'slug' => 'about-kohevo'])), 'a taken slug is refused');

            // ── duplicate ─────────────────────────────────────────────────────────
            $dup = sbpg_ok(sbpg_call($rt, SBPG_TENANT_A, $editor, 'POST', 'duplicate_page', ['page_id' => $src['id'], 'title' => 'About us (copy)', 'slug' => 'about-copy']), 'duplicate');
            $copy = $dup['page'];
            assert_true((int) $copy['id'] !== $src['id'], 'a new page');
            assert_true($copy['is_published'] === false && $copy['status'] === 'draft', 'the copy is a draft');
            assert_eq('standalone', $copy['route_mode'], 'never the homepage');
            assert_eq('/about-copy', $copy['public_path']);
            $doc = sbpg_ok(sbpg_call($rt, SBPG_TENANT_A, $editor, 'GET', 'document', ['page' => (int) $copy['id']]), 'copy document')['document'];
            $texts = array_map(static fn(array $b): string => (string) $b['props']['text'], $doc['sections'][0]['blocks']);
            assert_eq(['Our story', 'Our team'], $texts, 'the document is copied');
            $srcDoc = sbpg_ok(sbpg_call($rt, SBPG_TENANT_A, $editor, 'GET', 'document', ['page' => $src['id']]), 'source document')['document'];
            assert_eq(count($srcDoc['sections'][0]['blocks']), count($doc['sections'][0]['blocks']), 'the source is untouched');
            assert_true($rt->tenants->runAs(SBPG_TENANT_A, static fn() => sbpg_public($rt, SBPG_TENANT_A, '/about-copy')) === null, 'a copy is not publicly served until published');
            assert_eq('duplicate_slug', sbpg_first_code(sbpg_call($rt, SBPG_TENANT_A, $editor, 'POST', 'duplicate_page', ['page_id' => $src['id'], 'title' => 'Again', 'slug' => 'about-copy'])), 'a taken slug is refused');
            $count = sbpg_count('SELECT COUNT(*) FROM studiobuilder_pages WHERE tenant_id = ?', [SBPG_TENANT_A]);
            sbpg_call($rt, SBPG_TENANT_A, $editor, 'POST', 'duplicate_page', ['page_id' => $src['id'], 'title' => 'Again', 'slug' => 'about-copy']);
            assert_eq($count, sbpg_count('SELECT COUNT(*) FROM studiobuilder_pages WHERE tenant_id = ?', [SBPG_TENANT_A]), 'a refused duplicate leaves no page behind');
            assert_true(!sbpg_call($rt, SBPG_TENANT_A, $viewer, 'POST', 'duplicate_page', ['page_id' => $src['id'], 'title' => 'v', 'slug' => 'v'])->isOk(), 'a viewer cannot duplicate');
            assert_true(!sbpg_call($rt, SBPG_TENANT_A, $editor, 'POST', 'duplicate_page', ['page_id' => 999999, 'title' => 'n', 'slug' => 'n'])->isOk(), 'an unknown page cannot be duplicated');

            // ── archive ───────────────────────────────────────────────────────────
            $arch = sbpg_ok(sbpg_call($rt, SBPG_TENANT_A, $editor, 'POST', 'archive_page', ['page_id' => (int) $copy['id']]), 'archive');
            assert_eq('archived', $arch['page']['status']);
            $ids = array_column(sbpg_ok(sbpg_call($rt, SBPG_TENANT_A, $editor, 'GET', 'pages'), 'pages')['pages'], 'id');
            assert_true(!in_array((int) $copy['id'], $ids, true) && in_array($src['id'], $ids, true), 'archived pages leave the list');
            assert_true(!sbpg_call($rt, SBPG_TENANT_A, $viewer, 'POST', 'archive_page', ['page_id' => $src['id']])->isOk(), 'a viewer cannot archive');

            // ── isolation ─────────────────────────────────────────────────────────
            foreach ([['update_page', ['title' => 'hijack']], ['duplicate_page', ['title' => 'steal', 'slug' => 'steal']], ['archive_page', []]] as [$action, $extra]) {
                $res = sbpg_call($rt, SBPG_TENANT_B, $editor, 'POST', $action, ['page_id' => $src['id']] + $extra);
                assert_true(!$res->isOk(), "tenant B cannot {$action} tenant A's page");
            }
            assert_eq('About Kohevo', sbpg_ok(sbpg_call($rt, SBPG_TENANT_A, $editor, 'GET', 'status', ['page' => $src['id']]), 'still intact')['page']['title'], 'tenant A\'s page is unchanged');
            assert_eq([], sbpg_ok(sbpg_call($rt, SBPG_TENANT_B, $editor, 'GET', 'pages'), 'tenant B pages')['pages'], 'tenant B has no pages');

            // ── audit ─────────────────────────────────────────────────────────────
            assert_true($audits('studio.page.address_updated') >= 2, 'renames are audited');
            assert_true($audits('studio.page.duplicated') === 1, 'a duplicate is audited once');
            assert_true($audits('studio.page.archived') === 1, 'an archive is audited once');
        });
    } finally {
        sbpg_drop_db($dbName);
    }
});

if (!empty($studioPGIntStandalone)) {
    exit(unit_summary());
}
