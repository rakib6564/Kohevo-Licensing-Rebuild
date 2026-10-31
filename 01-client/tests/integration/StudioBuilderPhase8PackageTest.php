<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — Phase 8A JSON package
 * export / import.
 *
 * Real MySQL (throwaway database), real plugin activation, real per-tenant
 * entitlements, the PRODUCTION wiring (StudioRuntimeFactory) and the builder's
 * real HTTP controller (StudioAuthoringApi). Tenants:
 *   - 101 (A): studio-builder + booking + membership + forms — the exporting site
 *   - 202 (B): studio-builder only — the importing site
 *   - 303 (C): nothing
 *
 * Covers: page export (content, internals stripped), package validation and
 * tampering, tenant_id rejection, dry-run zero writes / no audit / determinism,
 * create-page and replace-draft imports (import revision kind, no publish,
 * 409 on a stale draft), id re-minting (nested children too), global
 * component remapping (draft-only, inert until published), templates
 * (collision, system protection, admin boundary), tokens (opt-in, tokens
 * permission), media (explicit mapping, tenant-local resolution, downgrade,
 * foreign ids never trusted), reserved routes and route collisions, module
 * entitlement, the permission matrix, CSRF, tenant isolation, audit, and a
 * full A -> B round trip with every external reference explicitly mapped.
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
    $studioP8IntStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Exception\StudioAuthorizationException;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioApiResponse;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Package\StudioPackageFormat;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const SBP8_CORE_MIGRATIONS = [
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

const SBP8_A = 101;
const SBP8_B = 202;
const SBP8_C = 303;

/** Every Studio business table: a dry run must leave all of them untouched. */
const SBP8_TABLES = ['studiobuilder_pages', 'studiobuilder_revisions', 'studiobuilder_templates', 'studiobuilder_tokens', 'studiobuilder_dependencies', 'studiobuilder_compilations', 'studiobuilder_locks', 'media_files', 'audit_log'];

function sbp8_fresh_db(string $dbName): \PDO
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
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(SBP8_CORE_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function sbp8_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function sbp8_with_pdo(\PDO $pdo, callable $fn): mixed
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

function sbp8_seed(int $tenantId, string $installationId, array $entitlements): void
{
    license_test_seed_cache($tenantId, [
        'installation_id' => $installationId,
        'status'          => 'active',
        'plan'            => 'phase8-' . implode('-', $entitlements ?: ['none']),
        'entitlements'    => $entitlements,
        'expires_at'      => null,
        'fetched_at'      => gmdate('Y-m-d H:i:s'),
    ]);
}

/** One builder API call as $actor inside $tenantId (the tenant is NEVER taken from the request). */
function sbp8_call(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $method, string $action, array $input = [], bool $csrf = true): StudioApiResponse
{
    $api = new StudioAuthoringApi($rt->app);
    $request = $method === 'POST'
        ? new StudioApiRequest('POST', $action, [], (string) json_encode($input, JSON_PRESERVE_ZERO_FRACTION), 'application/json', $csrf, 'same-origin')
        : new StudioApiRequest('GET', $action, array_map('strval', $input));
    return $rt->tenants->runAs($tenantId, static fn() => $api->handle($request, $actor));
}

/** @return array<string, mixed> */
function sbp8_ok(StudioApiResponse $r, string $what): array
{
    assert_true($r->isOk(), "{$what}: expected success, got {$r->status} " . substr($r->body(), 0, 800));
    return $r->data();
}

function sbp8_count(string $sql, array $params = []): int
{
    return (int) Database::value($sql, $params);
}

/** @return array<string, int> */
function sbp8_table_counts(): array
{
    $out = [];
    foreach (SBP8_TABLES as $t) {
        $out[$t] = sbp8_count("SELECT COUNT(*) FROM `{$t}`");
    }
    return $out;
}

/** Import (dry run or commit) through the real controller. @return StudioApiResponse */
function sbp8_import(StudioRuntime $rt, int $tenantId, StudioActor $actor, array $package, bool $dryRun, array $extra = []): StudioApiResponse
{
    return sbp8_call($rt, $tenantId, $actor, 'POST', 'import_package', ['package' => $package, 'dry_run' => $dryRun] + $extra);
}

/** The report of an import response (success or refused). @return array<string, mixed> */
function sbp8_report(StudioApiResponse $r): array
{
    return $r->isOk() ? ($r->data()['report'] ?? []) : ($r->payload['error']['details']['report'] ?? []);
}

/** @return list<string> */
function sbp8_codes(array $report, ?string $severity = null): array
{
    $codes = [];
    foreach ($report['issues'] ?? [] as $issue) {
        if ($severity === null || $issue['severity'] === $severity) {
            $codes[] = (string) $issue['code'];
        }
    }
    return $codes;
}

/** Re-hash every item after a deliberate test edit (what a well-formed but hand-made package looks like). */
function sbp8_rehash(array $package): array
{
    foreach ($package['items'] as $i => $item) {
        $package['items'][$i]['content_hash'] = StudioPackageFormat::itemHash($item);
    }
    return $package;
}

/** @return array<string, mixed> a canonical document of the page's working draft */
function sbp8_draft(StudioRuntime $rt, int $tenantId, int $pageId): array
{
    return $rt->tenants->runAs($tenantId, static function () use ($rt, $pageId): array {
        $page = $rt->pages->find($pageId);
        $rev = $rt->revisions->findByIdForPage($pageId, (int) $page['active_draft_revision_id']);
        return CanonicalJson::decode((string) $rev['document_json']);
    });
}

/** @return list<string> every section/block/child id of a document */
function sbp8_ids(array $document): array
{
    $ids = [];
    $walk = static function (array $blocks) use (&$walk, &$ids): void {
        foreach ($blocks as $b) {
            $ids[] = (string) $b['id'];
            $walk(is_array($b['children'] ?? null) ? $b['children'] : []);
        }
    };
    foreach ($document['containers'] ?? [] as $s) {
        $ids[] = (string) $s['id'];
        $walk($s['children'] ?? []);
    }
    return $ids;
}

/** A document with ids blanked (structure comparison across re-minting). */
function sbp8_shape(array $document): array
{
    $strip = static function (array $blocks) use (&$strip): array {
        foreach ($blocks as $i => $b) {
            $blocks[$i]['id'] = '*';
            $blocks[$i]['children'] = $strip(is_array($b['children'] ?? null) ? $b['children'] : []);
        }
        return $blocks;
    };
    foreach ($document['containers'] ?? [] as $i => $s) {
        $document['containers'][$i]['id'] = '*';
        $document['containers'][$i]['children'] = $strip($s['children'] ?? []);
    }
    return $document;
}

/** A complete canonical block (hand-made packages must be canonical too). @return array<string, mixed> */
function sbp8_block(string $type, array $props, array $extra = []): array
{
    return $extra + [
        'id' => 'blk_' . bin2hex(random_bytes(12)), 'type' => $type, 'version' => ($type === 'core.container' ? 2 : 1), 'props' => $props,
        'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [], 'children' => [],
    ];
}

/**
 * A runtime whose registry is this site's plus two probe blocks at version 2 (TI-07): `probe.note`
 * declares the 1 -> 2 step (`caption` became `text`), `probe.legacy` declares none.
 */
function sbp8_probe_runtime(): StudioRuntime
{
    $registry = \Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions::studioRegistry();
    $probe = static fn(string $type, array $upgrades): \Slate\Module\StudioBuilder\Registry\DeclarativeBlockDefinition => new \Slate\Module\StudioBuilder\Registry\DeclarativeBlockDefinition(
        type: $type, version: 2, label: 'Probe', category: 'test', icon: 'box',
        schema: \Slate\Module\StudioBuilder\Schema\FieldSchema::define([['key' => 'text', 'type' => 'string', 'label' => 'Text', 'required' => false, 'default' => '', 'max_length' => 200]]),
        upgrades: $upgrades,
    );
    $registry->register($probe('probe.note', [1 => static fn(array $p): array => ['text' => (string) ($p['caption'] ?? '')]]));
    $registry->register($probe('probe.legacy', []));
    return StudioRuntimeFactory::build(['registry' => $registry]);
}

/**
 * A page as an older Studio left it: rows written directly, its draft (and, if published, its published
 * revision) holding `$document` as stored then. @return array{id: int, rev: int, uuid: string}
 */
function sbp8_stored_page(int $tenantId, string $slug, string $pageType, array $document, bool $published): array
{
    $uuid = sprintf('%08x-6666-4000-8000-%012x', random_int(0, 0x7fffffff), random_int(0, 0x7fffffff));
    $pageId = (int) Database::insert('studiobuilder_pages', [
        'tenant_id' => $tenantId, 'uuid' => $uuid, 'slug' => $slug, 'title' => $slug, 'page_type' => $pageType, 'route_mode' => 'standalone',
        'status' => $published ? 'published' : 'draft', 'seo_json' => '{}', 'settings_json' => '{}', 'created_by' => 1, 'updated_by' => 1,
        'created_at' => slate_db_now(), 'updated_at' => slate_db_now(), 'published_at' => $published ? slate_db_now() : null,
    ]);
    $revId = (int) Database::insert('studiobuilder_revisions', [
        'tenant_id' => $tenantId, 'page_id' => $pageId, 'revision_number' => 1, 'revision_kind' => 'manual', 'schema_version' => CanonicalDocumentSchema::SCHEMA_VERSION,
        'document_json' => CanonicalJson::encode($document), 'summary' => 'stored before the bump', 'created_by' => 1, 'created_at' => slate_db_now(),
    ]);
    Database::query('UPDATE studiobuilder_pages SET active_draft_revision_id = ?, published_revision_id = ? WHERE id = ? AND tenant_id = ?', [$revId, $published ? $revId : null, $pageId, $tenantId]);
    return ['id' => $pageId, 'rev' => $revId, 'uuid' => $uuid];
}

/** Rewrite media ids in a document through a map (for round-trip comparison). */
function sbp8_map_media(array $document, array $map): array
{
    $walk = static function (array $blocks) use (&$walk, $map): array {
        foreach ($blocks as $i => $b) {
            foreach ($b['props'] ?? [] as $k => $v) {
                if (is_array($v) && isset($v['media_id']) && isset($map[$v['media_id']])) {
                    $blocks[$i]['props'][$k]['media_id'] = $map[$v['media_id']];
                }
            }
            $blocks[$i]['children'] = $walk(is_array($b['children'] ?? null) ? $b['children'] : []);
        }
        return $blocks;
    };
    foreach ($document['containers'] ?? [] as $i => $s) {
        $document['containers'][$i]['children'] = $walk($s['children'] ?? []);
    }
    if (isset($document['seo']['og_image_media_id'], $map[$document['seo']['og_image_media_id']])) {
        $document['seo']['og_image_media_id'] = $map[$document['seo']['og_image_media_id']];
    }
    return $document;
}

unit('phase8a integration: JSON package export / import (real MySQL, tenants 101/202/303)', function (): void {
    $dbName = 'slate_sbp8_' . slate_test_ns();
    $pdo = sbp8_fresh_db($dbName);

    try {
        sbp8_with_pdo($pdo, static function (): void {
            // ── Setup ───────────────────────────────────────────────────────
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Studio Admin', 'studio-admin-p8@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\'), (?,?,?,\'active\')
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [SBP8_A, 'Tenant A', 'tenant-a-p8', SBP8_B, 'Tenant B', 'tenant-b-p8', SBP8_C, 'Tenant C', 'tenant-c-p8']
            );
            sbp8_seed($owner, $iid, ['forms', 'membership', 'booking', 'studio-builder', 'stripe-payment']);
            $stripe = \PluginLoader::installFromDisk('stripe-payment');
            assert_true(!empty($stripe['ok']), 'installFromDisk(stripe-payment): ' . json_encode($stripe));
            foreach (['booking', 'membership', 'forms', 'studio-builder'] as $slug) {
                $sel = CommercialModuleRegistry::validateSelection([$slug], ['forms', 'membership', 'booking', 'studio-builder']);
                assert_true($sel['ok'], "selection {$slug}: " . json_encode($sel));
                $act = \PluginLoader::installFromDisk($slug);
                assert_true(!empty($act['ok']), "installFromDisk({$slug}): " . json_encode($act));
            }
            Media::ensureSchema();
            sbp8_seed(SBP8_A, $iid, ['studio-builder', 'booking', 'membership', 'forms']);
            sbp8_seed(SBP8_B, $iid, ['studio-builder']);
            sbp8_seed(SBP8_C, $iid, []);

            $rt = StudioRuntimeFactory::build();
            $viewer    = StudioActor::authenticated(80, [StudioPermissions::VIEW]);
            $editor    = StudioActor::authenticated(81, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
            $admin     = StudioActor::authenticated(82, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::ADMIN]);
            $themer    = StudioActor::authenticated(83, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::TOKENS]);
            $publisher = StudioActor::authenticated(84, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            $full      = StudioActor::authenticated(85, StudioPermissions::ALL);
            $tokenAi   = StudioActor::forMcpToken(9, 85, StudioPermissions::ALL);
            $S = new \stdClass();

            // Media of A (and one of B under a different path).
            $S->heroA  = $rt->tenants->runAs(SBP8_A, static fn(): int => Media::register('/uploads/media/2026/01/alpha-hero.jpg', ['mime' => 'image/jpeg', 'width' => 800, 'height' => 600]));
            $S->imageA = $rt->tenants->runAs(SBP8_A, static fn(): int => Media::register('/uploads/media/2026/01/alpha-photo.png', ['mime' => 'image/png']));
            $S->heroB  = $rt->tenants->runAs(SBP8_B, static fn(): int => Media::register('/uploads/media/2026/03/beta-hero.jpg', ['mime' => 'image/jpeg']));
            $S->imageB = $rt->tenants->runAs(SBP8_B, static fn(): int => Media::register('/uploads/media/2026/03/beta-photo.png', ['mime' => 'image/png']));
            assert_true($S->heroA > 0 && $S->imageA > 0 && $S->heroB > 0 && $S->imageB > 0, 'media registered');

            // ── Source content in tenant A ──────────────────────────────────
            // A published global component (the "promo" band).
            $comp = sbp8_ok(sbp8_call($rt, SBP8_A, $editor, 'POST', 'create_component', ['title' => 'Promo band', 'slug' => 'promo-band']), 'component');
            $S->componentA = $comp['component'];
            $compPage = sbp8_ok(sbp8_call($rt, SBP8_A, $editor, 'GET', 'document', ['page' => $S->componentA['id']]), 'component doc');
            $r = sbp8_ok(sbp8_call($rt, SBP8_A, $editor, 'POST', 'operations', ['page_id' => $S->componentA['id'], 'expected_revision_id' => (int) $compPage['revision']['id'], 'operations' => [
                ['op' => 'insert_block', 'payload' => ['parent_id' => null, 'index' => 0, 'block' => ['type' => 'core.container', 'metadata' => ['label' => 'Promo']]]],
            ]]), 'component section');
            $r = sbp8_ok(sbp8_call($rt, SBP8_A, $editor, 'POST', 'operations', ['page_id' => $S->componentA['id'], 'expected_revision_id' => (int) $r['revision']['id'], 'operations' => [
                ['op' => 'insert_block', 'payload' => ['parent_id' => $r['document']['containers'][0]['id'], 'index' => 0, 'block' => ['type' => 'core.heading', 'props' => ['text' => 'Spring promo', 'level' => 'h2']]]],
            ]]), 'component block');
            sbp8_ok(sbp8_call($rt, SBP8_A, $publisher, 'POST', 'publish', ['page_id' => $S->componentA['id'], 'expected_revision_id' => (int) $r['revision']['id']]), 'publish component');

            // The source page: heading, hero (media), image (media), container with a nested child, + a component reference.
            $created = sbp8_ok(sbp8_call($rt, SBP8_A, $editor, 'POST', 'create_page', ['title' => 'Landing Alpha', 'slug' => 'landing-alpha', 'page_type' => 'page', 'route_mode' => 'standalone']), 'create A page');
            $S->pageA = (int) $created['page']['id'];
            $S->pageAUuid = (string) $rt->tenants->runAs(SBP8_A, static fn() => $rt->pages->find((int) $created['page']['id']))['uuid'];
            assert_eq(1, preg_match('/^[0-9a-f-]{36}$/', $S->pageAUuid));
            $rev = (int) $created['page']['active_draft_revision_id'];
            $r = sbp8_ok(sbp8_call($rt, SBP8_A, $editor, 'POST', 'operations', ['page_id' => $S->pageA, 'expected_revision_id' => $rev, 'operations' => [
                ['op' => 'insert_block', 'payload' => ['parent_id' => null, 'index' => 0, 'block' => ['type' => 'core.container', 'metadata' => ['label' => 'Main']]]],
                ['op' => 'update_seo', 'payload' => ['seo' => ['title' => 'Alpha', 'og_image_media_id' => $S->heroA]]],
                ['op' => 'update_settings', 'payload' => ['settings' => ['token_group' => 'default']]],
            ]]), 'A section');
            $sec = (string) $r['document']['containers'][0]['id'];
            $rev = (int) $r['revision']['id'];
            foreach ([
                ['type' => 'core.heading', 'props' => ['text' => 'Welcome to Alpha', 'level' => 'h1']],
                ['type' => 'core.hero', 'props' => ['heading' => 'Alpha hero', 'media' => ['media_id' => $S->heroA, 'alt' => 'Hero']]],
                ['type' => 'core.image', 'props' => ['media' => ['media_id' => $S->imageA, 'alt' => 'Photo'], 'caption' => 'A photo']],
                ['type' => 'core.container', 'props' => ['direction' => 'vertical', 'gap' => 'md']],
            ] as $i => $block) {
                $r = sbp8_ok(sbp8_call($rt, SBP8_A, $editor, 'POST', 'operations', ['page_id' => $S->pageA, 'expected_revision_id' => $rev, 'operations' => [
                    ['op' => 'insert_block', 'payload' => ['parent_id' => $sec, 'index' => $i, 'block' => $block]],
                ]]), "A block {$i}");
                $rev = (int) $r['revision']['id'];
            }
            $container = (string) $r['document']['containers'][0]['children'][3]['id'];
            $r = sbp8_ok(sbp8_call($rt, SBP8_A, $editor, 'POST', 'operations', ['page_id' => $S->pageA, 'expected_revision_id' => $rev, 'operations' => [
                ['op' => 'insert_block', 'payload' => ['parent_id' => $container, 'index' => 0, 'block' => ['type' => 'core.heading', 'props' => ['text' => 'Nested child', 'level' => 'h3']]]],
                ['op' => 'insert_block', 'payload' => ['parent_id' => null, 'index' => 1, 'block' => ['type' => 'core.container', 'metadata' => ['label' => 'Promo ref'], 'global_ref' => $S->componentA['ref'], 'children' => []]]],
            ]]), 'A nested child + reference');
            $S->pageARev = (int) $r['revision']['id'];

            // A page template of A that the page names, and stored tokens.
            sbp8_ok(sbp8_call($rt, SBP8_A, $admin, 'POST', 'save_template', ['page_id' => $S->pageA, 'node_id' => null, 'template_key' => 'alpha-layout', 'template_type' => 'page_template', 'category' => 'general', 'name' => 'Alpha layout', 'description' => 'Layout', 'thumbnail_media_id' => null]), 'A template');
            $r = sbp8_ok(sbp8_call($rt, SBP8_A, $editor, 'POST', 'operations', ['page_id' => $S->pageA, 'expected_revision_id' => $S->pageARev, 'operations' => [
                ['op' => 'update_template', 'payload' => ['template_key' => 'alpha-layout']],
            ]]), 'A names template');
            $S->pageARev = (int) $r['revision']['id'];
            sbp8_ok(sbp8_call($rt, SBP8_A, $themer, 'POST', 'save_tokens', ['group' => 'default', 'tokens' => ['surface.page' => '#fafafa', 'text.primary' => '#222222']]), 'A tokens');
            $S->docA = sbp8_draft($rt, SBP8_A, $S->pageA);

            // ── 1/2/22. Export: one page, internals stripped, audited ───────
            unit('phase8a int 1: a page export holds the page, its referenced component, template and tokens — with no database ids, uuid of the page, user, revision or tenant identity — and is audited', function () use ($rt, $viewer, $S): void {
                $before = sbp8_count("SELECT COUNT(*) FROM audit_log WHERE action = 'studio.package.exported'");
                $out = sbp8_ok(sbp8_call($rt, SBP8_A, $viewer, 'GET', 'export_package', ['page' => $S->pageA, 'include_components' => '1', 'include_template' => '1', 'include_tokens' => '1']), 'export (viewer)');
                $pkg = $out['package'];
                assert_eq([], StudioPackageFormat::validate($pkg), 'an export is a valid package');
                assert_eq(['page', 'global_component', 'template', 'tokens'], array_column($pkg['items'], 'kind'));
                assert_eq('kohevo-studio-landing-alpha.json', $out['filename']);
                assert_eq(StudioPackageFormat::packageHash($pkg), $out['package_hash']);

                $json = (string) json_encode($pkg);
                assert_true(!str_contains($json, 'tenant_id'), 'no tenant_id anywhere');
                assert_true(!str_contains($json, $S->pageAUuid), 'the page uuid is not exported');
                foreach (['"page_id"', '"revision_id"', '"created_by"', '"updated_by"', '"user_id"', '"lock_token"', '"csrf', '"active_draft_revision_id"', '"published_revision_id"', SLATE_ROOT] as $needle) {
                    assert_true(!str_contains($json, $needle), "export must not contain {$needle}");
                }
                $page = $pkg['items'][0];
                assert_eq(['content_hash', 'document', 'key', 'kind', 'media', 'page_type', 'route_mode', 'slug', 'title'], array_keys($page));
                assert_eq([['key' => 1, 'mime' => 'image/jpeg', 'path' => '/uploads/media/2026/01/alpha-hero.jpg'], ['key' => 2, 'mime' => 'image/png', 'path' => '/uploads/media/2026/01/alpha-photo.png']], $page['media'], 'media travel as descriptors, not ids or binaries');
                assert_eq(1, $page['document']['seo']['og_image_media_id'], 'media ids are renumbered to package-local keys');
                assert_eq(1, $page['document']['containers'][0]['children'][1]['props']['media']['media_id']);
                assert_eq(2, $page['document']['containers'][0]['children'][2]['props']['media']['media_id']);
                assert_eq($S->componentA['ref'], $pkg['items'][1]['source_ref'], 'a component carries its source uuid as a package-local reference only');
                assert_eq('Spring promo', $pkg['items'][1]['document']['containers'][0]['children'][0]['props']['text'], 'the component\'s published content');
                assert_eq(['surface.page' => '#fafafa', 'text.primary' => '#222222'], $pkg['items'][3]['tokens'], 'only the stored token overrides');
                assert_eq($before + 1, sbp8_count("SELECT COUNT(*) FROM audit_log WHERE action = 'studio.package.exported'"), 'export is audited');
                $row = Database::row("SELECT tenant_id, target, meta_json FROM audit_log WHERE action = 'studio.package.exported' ORDER BY id DESC LIMIT 1");
                assert_eq(SBP8_A, (int) $row['tenant_id']);
                assert_eq((string) $S->pageA, $row['target']);
                assert_eq($out['package_hash'], json_decode((string) $row['meta_json'], true)['package_hash']);

                // Minimal export: no components/template/tokens unless asked.
                $min = sbp8_ok(sbp8_call($rt, SBP8_A, $viewer, 'GET', 'export_package', ['page' => $S->pageA, 'include_components' => '0', 'include_template' => '0']), 'minimal export')['package'];
                assert_eq(['page'], array_column($min['items'], 'kind'));
                // Tenant isolation of export: B cannot export A's page.
                assert_eq(404, sbp8_call($rt, SBP8_B, $viewer, 'GET', 'export_package', ['page' => $S->pageA])->status);
                $S->pkg = $pkg;
                $S->minPkg = $min;
            });

            // ── 3/4/5/unknown keys: the envelope fails closed ──────────────
            unit('phase8a int 2: tampered items, unknown keys / kinds and tenant_id anywhere are rejected before anything is planned', function () use ($rt, $editor, $S): void {
                $tampered = $S->minPkg;
                $tampered['items'][0]['document']['containers'][0]['children'][0]['props']['text'] = 'Changed after export';
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, $tampered, true));
                assert_true(in_array('invalid_package', sbp8_codes($rep, 'error'), true));
                assert_true(str_contains(json_encode($rep['issues']), 'content_hash'), 'the hash mismatch is named');
                assert_false($rep['can_commit']);

                $extra = $S->minPkg + ['signature' => 'trust-me'];
                assert_eq(['unknown_package_key'], array_values(array_unique(sbp8_codes(sbp8_report(sbp8_import($rt, SBP8_B, $editor, $extra, true)), 'error'))));

                $kind = $S->minPkg;
                $kind['items'][0]['kind'] = 'script';
                assert_true(in_array('unknown_item_type', sbp8_codes(sbp8_report(sbp8_import($rt, SBP8_B, $editor, sbp8_rehash($kind), true))), true));

                $tenant = $S->minPkg;
                $tenant['items'][0]['document']['containers'][0]['children'][0]['props']['tenant_id'] = SBP8_A;
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, sbp8_rehash($tenant), true));
                assert_true(in_array('forbidden_tenant_id', sbp8_codes($rep, 'error'), true), 'tenant_id deep inside a document');
                $tenant2 = $S->minPkg + [];
                $tenant2['items'][0]['tenant_id'] = SBP8_B;
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, $tenant2, true));
                assert_true(in_array('forbidden_tenant_id', sbp8_codes($rep, 'error'), true), 'tenant_id on an item');
                // The request itself cannot name a tenant either.
                assert_eq(422, sbp8_call($rt, SBP8_B, $editor, 'POST', 'import_package', ['package' => $S->minPkg, 'dry_run' => true, 'tenant_id' => SBP8_A])->status);
            });

            // ── 20/23/28. Dry run: zero writes, no audit, deterministic ─────
            unit('phase8a int 3: a dry run writes nothing (no page, revision, template, token, dependency, media or audit row), is deterministic, and reports plan, dependencies, warnings and previews', function () use ($rt, $admin, $themer, $full, $S): void {
                $before = sbp8_table_counts();
                $opts = ['media_map' => ['/uploads/media/2026/01/alpha-hero.jpg' => $S->heroB], 'include_tokens' => true];
                $r1 = sbp8_import($rt, SBP8_B, $full, $S->pkg, true, $opts);
                $r2 = sbp8_import($rt, SBP8_B, $full, $S->pkg, true, $opts);
                assert_eq(200, $r1->status);
                assert_eq($before, sbp8_table_counts(), 'a dry run performs zero business writes and no audit');
                assert_eq($r1->body(), $r2->body(), 'the same package and options give a byte-identical report');
                $rep = sbp8_report($r1);
                assert_true($rep['ok'] && $rep['dry_run'] && $rep['valid'] && $rep['can_commit'], json_encode(array_slice($rep['issues'], 0, 5)));
                assert_eq('kohevo_json', $rep['source_kind']);
                assert_eq(['items_count' => 4, 'pages_count' => 1, 'templates_count' => 1, 'global_components_count' => 1, 'tokens_count' => 1], array_intersect_key($rep['summary'], array_flip(['items_count', 'pages_count', 'templates_count', 'global_components_count', 'tokens_count'])));
                assert_eq(['replace_token_group', 'create_global_component', 'create_template', 'create_page'], array_column($rep['planned_actions'], 'action'), 'dependency order');
                foreach ($rep['planned_actions'] as $a) {
                    assert_false($a['publishes'], 'nothing is ever published');
                }
                assert_eq(['mapped' => 4, 'resolved_locally' => 0, 'unresolved' => 2], $rep['dependencies']['media'], 'page and template: og + hero mapped, the photo unresolved');
                assert_eq('package', $rep['dependencies']['global_components'][0]['resolution']);
                assert_true(in_array('unresolved_media', sbp8_codes($rep, 'warning'), true));
                assert_true(in_array('component_unpublished', sbp8_codes($rep, 'warning'), true), 'the report says imported components stay unpublished');
                assert_true(in_array('tokens_replace_live', sbp8_codes($rep, 'warning'), true), 'tokens are live — said so explicitly');
                assert_eq(['global_component', 'template', 'page'], array_column($rep['preview_documents'], 'kind'), 'previews of every document item, in dependency order');
                assert_null($rep['committed']);
                $json = json_encode($rep);
                assert_true(!str_contains($json, 'tenant_id') && !str_contains($json, '"' . SBP8_B . '"'), 'no tenant metadata in the report');
            });

            // ── 15/7/8/9/13/14/18/19/21: create-page commit ────────────────
            unit('phase8a int 4: committing creates drafts only (import revisions), fresh ids everywhere, remapped component reference, mapped/omitted media, and audits the import', function () use ($rt, $full, $S): void {
                $auditBefore = sbp8_count("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'studio.%imported'");
                $r = sbp8_import($rt, SBP8_B, $full, $S->pkg, false, ['media_map' => ['/uploads/media/2026/01/alpha-hero.jpg' => $S->heroB], 'include_tokens' => true]);
                $data = sbp8_ok($r, 'commit');
                assert_eq(201, $r->status);
                $rep = $data['report'];
                assert_true($rep['ok'] && !$rep['dry_run']);
                $page = $rep['committed']['pages'][0];
                $component = $rep['committed']['global_components'][0];
                assert_eq('created', $page['mode']);
                assert_eq('landing-alpha', $page['slug']);
                assert_true(!array_key_exists('uuid', $page), 'page views never carry a uuid (Phase 5 allowlist)');
                $newUuid = (string) $rt->tenants->runAs(SBP8_B, static fn() => $rt->pages->find((int) $page['page_id']))['uuid'];
                assert_true($newUuid !== $S->pageAUuid, 'a fresh page uuid (from createPage)');
                assert_true($component['ref'] !== $S->componentA['ref'], 'a fresh component uuid');
                assert_eq([['item' => 'template', 'template_key' => 'alpha-layout']], $rep['committed']['templates']);
                assert_eq([['item' => 'tokens', 'token_group' => 'default']], $rep['committed']['tokens']);

                $row = $rt->tenants->runAs(SBP8_B, static fn() => $rt->pages->find((int) $page['page_id']));
                assert_eq('draft', $row['status']);
                assert_null($row['published_revision_id'], 'import never publishes');
                $compRow = $rt->tenants->runAs(SBP8_B, static fn() => $rt->pages->find((int) $component['page_id']));
                assert_eq('container_preset', $compRow['page_type']);
                assert_null($compRow['published_revision_id'], 'an imported component stays a draft');
                assert_eq(['import', 'import'], array_column(Database::rows('SELECT revision_kind FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ? ORDER BY id', [SBP8_B, (int) $page['page_id']]), 'revision_kind'), 'every revision written by the import is an import revision');
                assert_eq(0, sbp8_count("SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ? AND revision_kind = 'publish'", [SBP8_B]), 'no publish revision in B');

                $doc = sbp8_draft($rt, SBP8_B, (int) $page['page_id']);
                $srcIds = sbp8_ids($S->pkg['items'][0]['document']);
                $newIds = sbp8_ids($doc);
                assert_eq([], array_values(array_intersect($srcIds, $newIds)), 'every section/block id was re-minted');
                assert_eq(count($newIds), count(array_unique($newIds)));
                $blocks = $doc['containers'][0]['children'];
                assert_eq(['core.heading', 'core.hero', 'core.container'], array_column($blocks, 'type'), 'the unresolved core.image was omitted');
                assert_eq($S->heroB, $blocks[1]['props']['media']['media_id'], 'the explicitly mapped media id of B');
                assert_eq($S->heroB, $doc['seo']['og_image_media_id']);
                assert_eq('Nested child', $blocks[2]['children'][0]['props']['text']);
                assert_true(!in_array($blocks[2]['children'][0]['id'], $srcIds, true), 'nested child ids are re-minted too');
                assert_eq($component['ref'], $doc['containers'][1]['global_ref'], 'the reference points at the NEW component');
                assert_eq([], $doc['containers'][1]['children']);
                assert_eq('alpha-layout', $doc['template_key']);

                // Inert until published: the reference renders nothing publicly, and nothing is public yet at all.
                assert_null($rt->tenants->runAs(SBP8_B, static fn() => $rt->publicRuntime->handlePath('/landing-alpha', null)), 'a draft page is not public');
                // Audit: package + per-entity, attributed, after commit.
                assert_eq($auditBefore + 5, sbp8_count("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'studio.%imported'"), 'tokens, template, component, page and package events');
                $pkgRow = Database::row("SELECT tenant_id, target, meta_json FROM audit_log WHERE action = 'studio.package.imported' ORDER BY id DESC LIMIT 1");
                $meta = json_decode((string) $pkgRow['meta_json'], true);
                assert_eq(SBP8_B, (int) $pkgRow['tenant_id']);
                assert_eq($rep['package_hash'], $meta['package_hash']);
                assert_eq('session', $meta['origin']);
                assert_eq(85, $meta['user_id']);
                assert_eq([(int) $page['page_id']], $meta['page_ids']);
                $S->importedB = (int) $page['page_id'];
                $S->importedBComponent = $component;
            });

            // ── 11/10/12: templates, tokens, collisions, permissions ────────
            unit('phase8a int 5: templates keep the admin boundary, collide instead of overwriting, never touch a system template; tokens are opt-in and need studio-builder.tokens', function () use ($rt, $editor, $admin, $themer, $full, $S): void {
                // B already has 'alpha-layout' now (the previous import) — a second import collides; nothing is overwritten.
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $full, $S->pkg, true, ['include_tokens' => true]));
                assert_true(in_array('template_collision', sbp8_codes($rep, 'error'), true));
                assert_true(in_array('route_collision', sbp8_codes($rep, 'error'), true), 'the page and component slugs exist too');
                assert_false($rep['can_commit']);
                $refused = sbp8_import($rt, SBP8_B, $full, $S->pkg, false, ['include_tokens' => true]);
                assert_eq(422, $refused->status, 'a commit with errors is refused');
                assert_null(sbp8_report($refused)['committed']);

                // A system template of B with the same key is protected.
                $tplOnly = ['package_format' => 'kohevo-studio-package', 'package_version' => '1.0', 'exported_at' => '2026-09-30T00:00:00Z', 'items' => [$S->pkg['items'][2]]];
                $rt->tenants->runAs(SBP8_B, static fn() => $rt->templates->insert([
                    'uuid' => '0e0e0e0e-0e0e-4e0e-8e0e-0e0e0e0e0e0e', 'template_key' => 'kohevo-starter', 'template_type' => 'page_template', 'category' => 'system',
                    'name' => 'Starter', 'schema_version' => '2.0', 'document_json' => CanonicalJson::encode(CanonicalDocumentSchema::emptyDocument()), 'is_system' => 1,
                ]));
                $sys = $tplOnly;
                $sys['items'][0]['template_key'] = 'kohevo-starter';
                $sysBefore = Database::row("SELECT document_json, updated_at FROM studiobuilder_templates WHERE tenant_id = ? AND template_key = 'kohevo-starter'", [SBP8_B]);
                $r = sbp8_import($rt, SBP8_B, $admin, sbp8_rehash($sys), false);
                assert_eq(422, $r->status);
                assert_true(in_array('system_template_protected', sbp8_codes(sbp8_report($r), 'error'), true));
                assert_eq($sysBefore, Database::row("SELECT document_json, updated_at FROM studiobuilder_templates WHERE tenant_id = ? AND template_key = 'kohevo-starter'", [SBP8_B]), 'the system template is untouched');

                // An editor (no admin) cannot import templates.
                $fresh = $tplOnly;
                $fresh['items'][0]['template_key'] = 'alpha-layout-2';
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, sbp8_rehash($fresh), true));
                assert_true(in_array('permission_denied', sbp8_codes($rep, 'error'), true), 'templates keep the studio-builder.admin boundary');
                assert_true(sbp8_report(sbp8_import($rt, SBP8_B, $admin, sbp8_rehash($fresh), true))['can_commit'], 'an admin can');

                // Tokens: skipped unless included; included -> tokens permission required.
                $tokOnly = ['package_format' => 'kohevo-studio-package', 'package_version' => '1.0', 'exported_at' => '2026-09-30T00:00:00Z', 'items' => [$S->pkg['items'][3]]];
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, $tokOnly, true));
                assert_eq(['tokens_skipped'], sbp8_codes($rep));
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, $tokOnly, true, ['include_tokens' => true]));
                assert_eq(['permission_denied'], sbp8_codes($rep, 'error'), 'edit alone cannot import tokens');
                $bad = $tokOnly;
                $bad['items'][0]['tokens']['surface.page'] = 'url(javascript:alert(1))';
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $themer, sbp8_rehash($bad), true, ['include_tokens' => true]));
                assert_eq(['invalid_tokens'], array_values(array_unique(sbp8_codes($rep, 'error'))), 'token values pass the same sanitization as the theme panel');
                $bad['items'][0]['tokens'] = ['--custom-css' => 'x'];
                assert_true(!sbp8_report(sbp8_import($rt, SBP8_B, $themer, sbp8_rehash($bad), true, ['include_tokens' => true]))['can_commit'], 'only platform-defined token refs');
                assert_true(sbp8_report(sbp8_import($rt, SBP8_B, $themer, $tokOnly, true, ['include_tokens' => true]))['can_commit'], 'edit + tokens may');
            });

            // ── 16/17/19: replace the current draft of an explicit page ─────
            unit('phase8a int 6: replace_draft writes one import revision onto the explicit target, needs the expected revision (409 when stale), and never touches the published revision', function () use ($rt, $editor, $publisher, $S): void {
                // Publish the imported page first so we can prove the published revision is untouched.
                $pubRev = sbp8_ok(sbp8_call($rt, SBP8_B, $publisher, 'POST', 'publish', ['page_id' => $S->importedB, 'expected_revision_id' => (int) sbp8_ok(sbp8_call($rt, SBP8_B, $editor, 'GET', 'status', ['page' => $S->importedB]), 's')['page']['active_draft_revision_id']]), 'publish imported')['revision']['id'];
                $status = sbp8_ok(sbp8_call($rt, SBP8_B, $editor, 'GET', 'status', ['page' => $S->importedB]), 'status')['page'];
                $target = (int) $status['id'];
                $pkg = $S->minPkg;

                // Missing expected revision / wrong type / foreign target.
                assert_eq(422, sbp8_call($rt, SBP8_B, $editor, 'POST', 'import_package', ['package' => $pkg, 'dry_run' => false, 'mode' => 'replace_draft', 'target_page_id' => $target])->status, 'expected_revision_id is required');
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, $pkg, true, ['mode' => 'replace_draft', 'target_page_id' => $S->pageA, 'expected_revision_id' => 1]));
                assert_eq(['target_not_found'], sbp8_codes($rep, 'error'), 'another tenant\'s page id is simply not found');

                // Stale -> dry run reports it, commit is a 409 that writes nothing.
                $revCount = sbp8_count('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ?', [SBP8_B]);
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, $pkg, true, ['mode' => 'replace_draft', 'target_page_id' => $target, 'expected_revision_id' => (int) $status['active_draft_revision_id'] - 1]));
                assert_eq(['concurrency_conflict'], sbp8_codes($rep, 'error'));
                $r = sbp8_import($rt, SBP8_B, $editor, $pkg, false, ['mode' => 'replace_draft', 'target_page_id' => $target, 'expected_revision_id' => (int) $status['active_draft_revision_id'] - 1]);
                assert_eq(409, $r->status);
                assert_eq('concurrency_conflict', $r->errorCode());
                assert_eq($revCount, sbp8_count('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ?', [SBP8_B]), 'a stale replace writes nothing');

                // Correct expected revision: one new import draft; published revision untouched.
                $r = sbp8_import($rt, SBP8_B, $editor, $pkg, false, ['mode' => 'replace_draft', 'target_page_id' => $target, 'expected_revision_id' => (int) $status['active_draft_revision_id']]);
                $data = sbp8_ok($r, 'replace');
                assert_eq('replaced', $data['report']['committed']['pages'][0]['mode']);
                assert_eq('import', $data['revision']['revision_kind'], 'the builder receives the new import draft');
                assert_true(is_array($data['document']) && $data['page']['id'] === $S->importedB);
                assert_eq($revCount + 1, sbp8_count('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ?', [SBP8_B]));
                $row = $rt->tenants->runAs(SBP8_B, static fn() => $rt->pages->find($S->importedB));
                assert_eq((int) $pubRev, (int) $row['published_revision_id'], 'the published revision is never replaced by an import');
                assert_eq('landing-alpha', $row['slug'], 'replace keeps the target page address');
                assert_eq(['core.heading', 'core.hero', 'core.container'], array_column(sbp8_draft($rt, SBP8_B, $S->importedB)['containers'][0]['children'], 'type'));
            });

            // ── 24/25/6/unknown blocks/versions/27: document + route rules ──
            unit('phase8a int 7: reserved routes, route collisions, malformed documents, unknown blocks, wrong block versions and unentitled module blocks fail closed', function () use ($rt, $editor, $S): void {
                $base = $S->minPkg['items'][0];
                $one = static fn(array $item): array => sbp8_rehash(['package_format' => 'kohevo-studio-package', 'package_version' => '1.0', 'exported_at' => '2026-09-30T00:00:00Z', 'items' => [$item]]);

                $reserved = $base;
                $reserved['slug'] = 'admin';
                assert_true(in_array('reserved_route', sbp8_codes(sbp8_report(sbp8_import($rt, SBP8_B, $editor, $one($reserved), true)), 'error'), true));
                $reserved['slug'] = 'studio';
                $r = sbp8_import($rt, SBP8_B, $editor, $one($reserved), false);
                assert_eq(422, $r->status, 'a reserved route is refused on commit too');
                assert_null($rt->tenants->runAs(SBP8_B, static fn() => $rt->pages->findBySlug('studio', 'page')));

                $collide = $base; // landing-alpha exists in B already
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, $one($collide), true));
                assert_eq(['route_collision'], sbp8_codes($rep, 'error'), 'create mode never overwrites an existing page');
                $dup = ['package_format' => 'kohevo-studio-package', 'package_version' => '1.0', 'exported_at' => '2026-09-30T00:00:00Z', 'items' => [array_merge($base, ['key' => 'p1', 'slug' => 'twin']), array_merge($base, ['key' => 'p2', 'slug' => 'twin'])]];
                assert_true(in_array('route_collision', sbp8_codes(sbp8_report(sbp8_import($rt, SBP8_B, $editor, sbp8_rehash($dup), true)), 'error'), true), 'two items claiming one address');

                $fresh = array_merge($base, ['slug' => 'fresh-page']);
                $broken = $fresh;
                $broken['document']['containers'][0]['style']['layout']['content_size'] = 'enormous';
                assert_true(in_array('invalid_document', sbp8_codes(sbp8_report(sbp8_import($rt, SBP8_B, $editor, $one($broken), true)), 'error'), true));
                $unknown = $fresh;
                $unknown['document']['containers'][0]['children'][0]['type'] = 'evil.script';
                assert_eq(['unknown_block_type'], sbp8_codes(sbp8_report(sbp8_import($rt, SBP8_B, $editor, $one($unknown), true)), 'error'));
                $version = $fresh;
                $version['document']['containers'][0]['children'][0]['version'] = 99;
                assert_eq(['invalid_block_version'], sbp8_codes(sbp8_report(sbp8_import($rt, SBP8_B, $editor, $one($version), true)), 'error'));
                $script = $fresh;
                $script['document']['containers'][0]['children'][0] = sbp8_block('core.rich_text', ['content' => '<p>x</p><script>alert(1)</script>']);
                assert_true(in_array('invalid_document', sbp8_codes(sbp8_report(sbp8_import($rt, SBP8_B, $editor, $one($script), true)), 'error'), true), 'unsafe rich text is refused, never stored');

                $module = $fresh;
                $module['document']['containers'][0]['children'][0] = sbp8_block('booking.services', ['heading' => 'Book'], ['bindings' => ['items' => ['provider' => 'booking.services', 'params' => [], 'mapping' => []]]]);
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, $one($module), true));
                assert_true(in_array('unentitled_module', sbp8_codes($rep, 'error'), true), 'B has no booking entitlement');
                $rep = sbp8_report(sbp8_import($rt, SBP8_A, $editor, $one(array_merge($module, ['slug' => 'booking-ok'])), true));
                assert_true($rep['can_commit'], 'A is entitled to booking: ' . json_encode($rep['issues']));

                // Duplicate ids in the SOURCE are harmless: everything is re-minted.
                $dupIds = $fresh;
                $dupIds['document']['containers'][0]['children'][1]['id'] = $dupIds['document']['containers'][0]['children'][0]['id'];
                $dupIds['document']['containers'][1]['id'] = $dupIds['document']['containers'][0]['id'];
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, $one($dupIds), true));
                assert_true($rep['can_commit'], 'colliding source ids are re-minted, not trusted: ' . json_encode($rep['issues']));
            });

            // ── Media / component references never cross tenants ─────────────
            unit('phase8a int 8: foreign media ids and component uuids are never authoritative; explicit maps are verified tenant-local; external URLs are never fetched or stored', function () use ($rt, $editor, $S): void {
                $item = array_merge($S->minPkg['items'][0], ['slug' => 'media-probe']);
                // A package claiming A's REAL media id as its key, pointing at A's path.
                $item['media'] = [['key' => $S->imageA, 'mime' => 'image/png', 'path' => '/uploads/media/2026/01/alpha-photo.png']];
                $item['document']['seo']['og_image_media_id'] = $S->imageA;
                $item['document']['containers'][0]['children'] = [
                    sbp8_block('core.hero', ['heading' => 'H', 'media' => ['media_id' => $S->imageA, 'alt' => '']]),
                    sbp8_block('core.image', ['media' => ['media_id' => $S->imageA, 'alt' => '']]),
                ];
                $item['document']['containers'] = [$item['document']['containers'][0]];
                $pkg = sbp8_rehash(['package_format' => 'kohevo-studio-package', 'package_version' => '1.0', 'exported_at' => '2026-09-30T00:00:00Z', 'items' => [$item]]);
                $data = sbp8_ok(sbp8_import($rt, SBP8_B, $editor, $pkg, false), 'foreign media commit');
                $doc = sbp8_draft($rt, SBP8_B, (int) $data['report']['committed']['pages'][0]['page_id']);
                assert_null($doc['seo']['og_image_media_id'], 'the OG image becomes null');
                assert_eq(['core.hero'], array_column($doc['containers'][0]['children'], 'type'), 'the required image is omitted');
                assert_true(!isset($doc['containers'][0]['children'][0]['props']['media']) || $doc['containers'][0]['children'][0]['props']['media'] === null, 'the optional hero media becomes null');
                assert_true(!str_contains(json_encode($doc), '"media_id":' . $S->imageA), 'A\'s media id never becomes a canonical reference in B');

                // An explicit mapping onto ANOTHER tenant's media id is refused.
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, $pkg, true, ['media_map' => ['/uploads/media/2026/01/alpha-photo.png' => $S->imageA]]));
                assert_true(in_array('unresolved_media', sbp8_codes($rep, 'error'), true), 'a media_map target must be an image of THIS tenant');
                // Tenant-local resolution: the same managed path + mime in B resolves (and nothing else does).
                $local = $rt->tenants->runAs(SBP8_B, static fn(): int => Media::register('/uploads/media/2026/01/alpha-photo.png', ['mime' => 'image/png']));
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, sbp8_rehash(array_replace_recursive($pkg, ['items' => [['slug' => 'media-probe-2']]])), true));
                assert_eq(['mapped' => 0, 'resolved_locally' => 3, 'unresolved' => 0], $rep['dependencies']['media'], 'resolved against B\'s OWN managed media row');
                $preview = $rep['preview_documents'][0]['document'];
                assert_eq($local, $preview['seo']['og_image_media_id']);

                // External URLs: a descriptor pointing off-site is never fetched and never stored.
                $ext = $item;
                $ext['slug'] = 'external-probe';
                $ext['media'] = [['key' => $S->imageA, 'mime' => 'image/png', 'path' => 'https://evil.test/tracker.png']];
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, sbp8_rehash(['package_format' => 'kohevo-studio-package', 'package_version' => '1.0', 'exported_at' => '2026-09-30T00:00:00Z', 'items' => [$ext]]), true));
                assert_true($rep['can_commit'] && in_array('unresolved_media', sbp8_codes($rep, 'warning'), true), 'an external reference downgrades with a warning');
                assert_true(!str_contains(json_encode($rep['preview_documents']), 'evil.test'), 'no URL reaches a canonical document');
                $fs = $ext;
                $fs['media'][0]['path'] = '/etc/passwd';
                assert_true(in_array('invalid_package', sbp8_codes(sbp8_report(sbp8_import($rt, SBP8_B, $editor, sbp8_rehash(['package_format' => 'kohevo-studio-package', 'package_version' => '1.0', 'exported_at' => '2026-09-30T00:00:00Z', 'items' => [$fs]]), true)), 'error'), true), 'filesystem paths are refused');

                // Component references: an unmapped foreign uuid downgrades; a map onto A's component from B is refused.
                $ref = $S->minPkg['items'][0];
                $ref['slug'] = 'ref-probe';
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, sbp8_rehash(['package_format' => 'kohevo-studio-package', 'package_version' => '1.0', 'exported_at' => '2026-09-30T00:00:00Z', 'items' => [$ref]]), true));
                assert_true(in_array('unresolved_global_component', sbp8_codes($rep, 'warning'), true));
                assert_eq(null, $rep['preview_documents'][0]['document']['containers'][1]['global_ref'], 'the section became an empty owned section');
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, sbp8_rehash(['package_format' => 'kohevo-studio-package', 'package_version' => '1.0', 'exported_at' => '2026-09-30T00:00:00Z', 'items' => [$ref]]), true, ['component_map' => [$S->componentA['ref'] => $S->componentA['ref']]]));
                assert_true(in_array('unresolved_global_component', sbp8_codes($rep, 'error'), true), 'A\'s component is not a component of B');
                // Mapped onto B's own (imported) component: resolves.
                $rep = sbp8_report(sbp8_import($rt, SBP8_B, $editor, sbp8_rehash(['package_format' => 'kohevo-studio-package', 'package_version' => '1.0', 'exported_at' => '2026-09-30T00:00:00Z', 'items' => [$ref]]), true, ['component_map' => [$S->componentA['ref'] => $S->importedBComponent['ref']]]));
                assert_eq($S->importedBComponent['ref'], $rep['preview_documents'][0]['document']['containers'][1]['global_ref']);
            });

            // ── 26: permission matrix, entitlement, AI origin, CSRF ────────
            unit('phase8a int 9: permission matrix — view exports, edit imports, tenant without Studio refused, AI origins cannot import, CSRF and content type enforced, publish permission never auto-publishes', function () use ($rt, $viewer, $editor, $publisher, $tokenAi, $S): void {
                $pkg = sbp8_rehash(['package_format' => 'kohevo-studio-package', 'package_version' => '1.0', 'exported_at' => '2026-09-30T00:00:00Z', 'items' => [array_merge($S->minPkg['items'][0], ['slug' => 'perm-probe'])]]);
                assert_eq('authorization_error', sbp8_import($rt, SBP8_B, $viewer, $pkg, true)->errorCode(), 'view cannot even dry-run an import');
                assert_eq('authorization_error', sbp8_call($rt, SBP8_B, StudioActor::authenticated(90, []), 'GET', 'export_package', ['page' => $S->importedB])->errorCode(), 'no view, no export');
                assert_eq('entitlement_error', sbp8_import($rt, SBP8_C, $editor, $pkg, true)->errorCode(), 'a tenant without Studio');
                assert_eq('authentication_error', sbp8_import($rt, SBP8_B, StudioActor::guest(), $pkg, true)->errorCode());
                assert_throws(StudioAuthorizationException::class, static fn() => $rt->tenants->runAs(SBP8_B, static fn() => $rt->app->importPackage($tokenAi, $pkg, ['mode' => 'create'], true)), 'an MCP-token actor cannot import');
                $assistant = StudioActor::authenticated(85, StudioPermissions::ALL)->asAdminAssistant();
                assert_throws(StudioAuthorizationException::class, static fn() => $rt->tenants->runAs(SBP8_B, static fn() => $rt->app->importPackage($assistant, $pkg, ['mode' => 'create'], false)), 'nor can the admin assistant');

                assert_eq(403, sbp8_call($rt, SBP8_B, $editor, 'POST', 'import_package', ['package' => $pkg, 'dry_run' => true], false)->status, 'CSRF');
                $api = new StudioAuthoringApi($rt->app);
                $cross = new StudioApiRequest('POST', 'import_package', [], (string) json_encode(['package' => $pkg, 'dry_run' => true]), 'application/json', true, 'cross-site');
                assert_eq(403, $rt->tenants->runAs(SBP8_B, static fn() => $api->handle($cross, $editor))->status, 'cross-site request');
                $form = new StudioApiRequest('POST', 'import_package', [], 'package=x', 'application/x-www-form-urlencoded', true, 'same-origin');
                assert_eq(415, $rt->tenants->runAs(SBP8_B, static fn() => $api->handle($form, $editor))->status);
                assert_eq(405, sbp8_call($rt, SBP8_B, $editor, 'GET', 'import_package')->status, 'import is POST-only');
                assert_eq(422, sbp8_call($rt, SBP8_B, $editor, 'POST', 'import_package', ['package' => $pkg])->status, 'dry_run must be stated explicitly');

                $data = sbp8_ok(sbp8_import($rt, SBP8_B, $publisher, $pkg, false), 'publisher import');
                $row = $rt->tenants->runAs(SBP8_B, static fn() => $rt->pages->find((int) $data['report']['committed']['pages'][0]['page_id']));
                assert_null($row['published_revision_id'], 'studio-builder.publish never turns an import into a publish');
            });

            // ── 29: semantic round trip with every external reference mapped ──
            unit('phase8a int 10: export from A then import into B with every media reference mapped gives the same page (ids, media ids and component uuid aside)', function () use ($rt, $viewer, $full, $S): void {
                // Fresh B tenant state for the round trip: a new slug via a clean package from A.
                sbp8_ok(sbp8_call($rt, SBP8_A, $full, 'POST', 'create_page', ['title' => 'Round trip', 'slug' => 'round-trip', 'page_type' => 'page', 'route_mode' => 'standalone']), 'rt page');
                $pageId = (int) $rt->tenants->runAs(SBP8_A, static fn() => $rt->pages->findBySlug('round-trip', 'page'))['id'];
                $status = sbp8_ok(sbp8_call($rt, SBP8_A, $full, 'GET', 'status', ['page' => $pageId]), 'rt status')['page'];
                $doc = $S->docA;
                $doc['template_key'] = 'default';
                sbp8_ok(sbp8_call($rt, SBP8_A, $full, 'POST', 'save_draft', ['page_id' => $pageId, 'expected_revision_id' => (int) $status['active_draft_revision_id'], 'document' => $doc]), 'rt fill');
                $source = sbp8_draft($rt, SBP8_A, $pageId);

                $pkg = sbp8_ok(sbp8_call($rt, SBP8_A, $viewer, 'GET', 'export_package', ['page' => $pageId, 'include_components' => '0', 'include_template' => '0']), 'rt export')['package'];
                $newComponent = $S->importedBComponent['ref'];
                $data = sbp8_ok(sbp8_import($rt, SBP8_B, $full, $pkg, false, [
                    'media_map'     => ['/uploads/media/2026/01/alpha-hero.jpg' => $S->heroB, '/uploads/media/2026/01/alpha-photo.png' => $S->imageB],
                    'component_map' => [$S->componentA['ref'] => $newComponent],
                ]), 'rt import');
                assert_eq(0, $data['report']['summary']['warnings_count'], 'nothing downgraded: ' . json_encode($data['report']['issues']));
                $imported = sbp8_draft($rt, SBP8_B, (int) $data['report']['committed']['pages'][0]['page_id']);

                $expected = sbp8_map_media($source, [$S->heroA => $S->heroB, $S->imageA => $S->imageB]);
                $expected['containers'][1]['global_ref'] = $newComponent;
                assert_eq(sbp8_shape($expected), sbp8_shape($imported), 'semantically identical after the explicit mapping');
                assert_eq([], array_values(array_intersect(sbp8_ids($source), sbp8_ids($imported))), 'with fresh ids');
            });

            // ── TI-07: blocks saved at an older block version ───────────────
            unit('phase8a int 12: a block exported at an older version is upgraded on import and when a stored template is applied or inserted; without an upgrade path it is refused by name', function () use ($editor, $admin, $S): void {
                $rt2 = sbp8_probe_runtime();
                $one = static fn(array $item): array => sbp8_rehash(['package_format' => 'kohevo-studio-package', 'package_version' => '1.0', 'exported_at' => '2026-09-30T00:00:00Z', 'items' => [$item]]);
                $withBlock = static function (string $slug, array $block) use ($S): array {
                    $item = array_merge($S->minPkg['items'][0], ['slug' => $slug]);
                    $item['document']['template_key'] = 'default';
                    $item['document']['containers'] = [array_merge($item['document']['containers'][0], ['children' => [$block]])];
                    return $item;
                };

                // Import: the version-1 block arrives as version 2 with its props rewritten, and the report says so.
                $pkg = $one($withBlock('upgraded-import', sbp8_block('probe.note', ['caption' => 'Exported at v1'])));
                $dry = sbp8_report(sbp8_import($rt2, SBP8_B, $editor, $pkg, true));
                assert_eq([], sbp8_codes($dry, 'error'), 'the dry run accepts it');
                assert_true(in_array('block_upgraded', sbp8_codes($dry, 'warning'), true), 'and reports the upgrade');
                $data = sbp8_ok(sbp8_import($rt2, SBP8_B, $editor, $pkg, false), 'commit upgraded import');
                $block = sbp8_draft($rt2, SBP8_B, (int) $data['report']['committed']['pages'][0]['page_id'])['containers'][0]['children'][0];
                assert_eq(['probe.note', 2, ['text' => 'Exported at v1']], [$block['type'], $block['version'], $block['props']], 'stored at the current version');

                // No upgrade path: refused, and the report names the block and both versions.
                $rep = sbp8_report(sbp8_import($rt2, SBP8_B, $editor, $one($withBlock('legacy-import', sbp8_block('probe.legacy', ['text' => 'Old']))), true));
                assert_eq(['invalid_block_version'], sbp8_codes($rep, 'error'));
                $messages = array_column(array_filter($rep['issues'], static fn(array $i): bool => $i['code'] === 'invalid_block_version'), 'message');
                assert_true(str_contains((string) ($messages[0] ?? ''), "Block 'probe.legacy' is version 1; this site supports version 2"), 'named in the report: ' . json_encode($messages));

                // Stored templates written by an older Studio: applied and inserted at the current version.
                $section = static fn(string $caption): array => CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'Old'], 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'children' => [sbp8_block('probe.note', ['caption' => $caption])]]);
                $pageTpl = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Old page template');
                $pageTpl['containers'] = [$section('Applied from v1')];
                $presetTpl = CanonicalDocumentSchema::emptyDocument('container_preset', 'default', 'Old preset');
                $presetTpl['containers'] = [$section('Inserted from v1')];
                foreach ([['old-page-tpl', 'page_template', $pageTpl], ['old-section-tpl', 'container_preset', $presetTpl]] as [$key, $type, $doc]) {
                    $rt2->tenants->runAs(SBP8_B, static fn() => $rt2->templates->insert([
                        'uuid' => sprintf('%08x-5555-4000-8000-%012x', random_int(0, 0x7fffffff), random_int(0, 0x7fffffff)), 'template_key' => $key, 'template_type' => $type, 'category' => 'general',
                        'name' => $key, 'schema_version' => CanonicalDocumentSchema::SCHEMA_VERSION, 'document_json' => CanonicalJson::encode($doc), 'is_system' => 0,
                    ]));
                }
                $created = sbp8_ok(sbp8_call($rt2, SBP8_B, $editor, 'POST', 'create_page', ['title' => 'Old templates', 'slug' => 'old-templates', 'page_type' => 'page', 'route_mode' => 'standalone']), 'target page');
                $pageId = (int) $created['page']['id'];
                $r = sbp8_ok(sbp8_call($rt2, SBP8_B, $editor, 'POST', 'apply_template', ['page_id' => $pageId, 'template_key' => 'old-page-tpl', 'expected_revision_id' => (int) $created['page']['active_draft_revision_id']]), 'apply old template');
                $applied = sbp8_draft($rt2, SBP8_B, $pageId)['containers'][0]['children'][0];
                assert_eq([2, ['text' => 'Applied from v1']], [$applied['version'], $applied['props']], 'apply upgrades the stored template');
                sbp8_ok(sbp8_call($rt2, SBP8_B, $editor, 'POST', 'insert_template', ['page_id' => $pageId, 'template_key' => 'old-section-tpl', 'index' => 1, 'parent_id' => null, 'expected_revision_id' => (int) $r['revision']['id']]), 'insert old preset');
                $inserted = sbp8_draft($rt2, SBP8_B, $pageId)['containers'][1]['children'][0];
                assert_eq([2, ['text' => 'Inserted from v1']], [$inserted['version'], $inserted['props']], 'insert upgrades the stored preset');
            });

            // ── TI-07 (option C): content stored before a block-version bump ───────────
            unit('phase8a int 13: drafts and published pages stored at an older block version load, edit, publish, roll back, export and detach at the current version; the stored revision is never rewritten', function () use ($editor, $publisher, $viewer): void {
                $rt2 = sbp8_probe_runtime();
                $old = static function (string $caption, array $extra = []): array {
                    $doc = CanonicalDocumentSchema::emptyDocument($extra['type'] ?? 'page', 'default', 'Stored at v1');
                    $doc['containers'] = [CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'Old'], 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'children' => [sbp8_block('probe.note', ['caption' => $caption])]])];
                    return $doc;
                };
                $probeOf = static fn(array $doc, int $s = 0): array => [$doc['containers'][$s]['children'][0]['version'], $doc['containers'][$s]['children'][0]['props']];
                $page = $rt2->tenants->runAs(SBP8_B, static fn(): array => sbp8_stored_page(SBP8_B, 'stored-v1', 'page', $old('Saved at v1'), true));
                $storedJson = (string) Database::value('SELECT document_json FROM studiobuilder_revisions WHERE tenant_id = ? AND id = ?', [SBP8_B, $page['rev']]);

                // Editor load: the draft reads at the current version.
                $loaded = sbp8_ok(sbp8_call($rt2, SBP8_B, $editor, 'GET', 'document', ['page' => $page['id']]), 'load stored page');
                assert_eq([2, ['text' => 'Saved at v1']], $probeOf($loaded['document']), 'loaded at v2');

                // An edit saves a NEW revision at the current version; the stored one is untouched.
                $r = sbp8_ok(sbp8_call($rt2, SBP8_B, $editor, 'POST', 'operations', ['page_id' => $page['id'], 'expected_revision_id' => $page['rev'], 'operations' => [
                    ['op' => 'update_settings', 'payload' => ['settings' => ['token_group' => 'default']]],
                ]]), 'edit stored page');
                assert_eq([2, ['text' => 'Saved at v1']], $probeOf($r['document']), 'the edit saved the upgraded block');
                assert_eq($storedJson, (string) Database::value('SELECT document_json FROM studiobuilder_revisions WHERE tenant_id = ? AND id = ?', [SBP8_B, $page['rev']]), 'history is never rewritten');

                // Publish, then roll back to the version-1 revision: both write current versions.
                $pub = sbp8_ok(sbp8_call($rt2, SBP8_B, $publisher, 'POST', 'publish', ['page_id' => $page['id'], 'expected_revision_id' => (int) $r['revision']['id']]), 'publish');
                $published = CanonicalJson::decode((string) Database::value('SELECT r.document_json FROM studiobuilder_revisions r JOIN studiobuilder_pages p ON p.published_revision_id = r.id AND p.tenant_id = r.tenant_id WHERE p.tenant_id = ? AND p.id = ?', [SBP8_B, $page['id']]));
                assert_eq([2, ['text' => 'Saved at v1']], $probeOf($published), 'published at v2');
                $rb = sbp8_ok(sbp8_call($rt2, SBP8_B, $editor, 'POST', 'rollback', ['page_id' => $page['id'], 'target_revision_id' => $page['rev'], 'expected_revision_id' => (int) ($pub['revision']['id'] ?? 0)]), 'roll back to the v1 revision');
                assert_eq([2, ['text' => 'Saved at v1']], $probeOf(sbp8_draft($rt2, SBP8_B, $page['id'])), 'rolled back at v2');

                // Export carries the current version.
                $pkg = sbp8_ok(sbp8_call($rt2, SBP8_B, $viewer, 'GET', 'export_package', ['page' => $page['id'], 'include_components' => '0', 'include_template' => '0']), 'export')['package'];
                assert_eq([2, ['text' => 'Saved at v1']], $probeOf($pkg['items'][0]['document']), 'exported at v2');

                // Detaching a component stored at v1 copies its content at v2.
                $component = $rt2->tenants->runAs(SBP8_B, static fn(): array => sbp8_stored_page(SBP8_B, 'stored-component', 'container_preset', $old('Component at v1', ['type' => 'container_preset']), true));
                $host = $old('Host');
                $host['containers'][] = CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'Ref'], 'global_ref' => $component['uuid'], 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'children' => []]);
                $hostPage = $rt2->tenants->runAs(SBP8_B, static fn(): array => sbp8_stored_page(SBP8_B, 'stored-host', 'page', $host, false));
                sbp8_ok(sbp8_call($rt2, SBP8_B, $editor, 'POST', 'detach_component', ['page_id' => $hostPage['id'], 'container_id' => $host['containers'][1]['id'], 'expected_revision_id' => $hostPage['rev']]), 'detach stored component');
                $detached = sbp8_draft($rt2, SBP8_B, $hostPage['id']);
                assert_eq([2, ['text' => 'Component at v1']], $probeOf($detached, 1), 'detached copy at v2');
                assert_eq([2, ['text' => 'Host']], $probeOf($detached, 0), 'and the host page\'s own block too');
            });

            // ── Documents stored before Containers (schema 1.0) ─────────────
            unit('phase8a int 14: section-based documents stored as schema 1.0 load, edit, render, publish, roll back, diff, export, import, detach and act as templates; the stored revision is never rewritten', function () use ($editor, $publisher, $viewer): void {
                $rt2 = sbp8_probe_runtime();
                $heading = static fn(string $text): array => sbp8_block('core.heading', ['text' => $text, 'level' => 'h2']);
                $section = static fn(string $id, string $label, array $blocks, array $extra = []): array => $extra + [
                    'id' => 'sec_' . str_repeat($id, 16), 'label' => $label, 'global_ref' => null, 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                    'layout' => ['background_token' => 'surface.muted', 'columns' => ['base' => 1, 'md' => 2], 'gap' => 'lg', 'padding_y' => ['base' => 'sm', 'md' => 'lg'], 'width' => 'narrow'],
                    'blocks' => $blocks,
                ];
                $legacy = static fn(string $type, array $sections): array => ['schema_version' => '1.0', 'document_type' => $type, 'template_key' => 'default', 'settings' => [], 'seo' => [], 'sections' => $sections];
                $old = $legacy('page', [
                    $section('a', 'Intro', [$heading('Legacy one'), $heading('Legacy two')], ['style' => ['border' => ['top' => ['width' => '2px']]], 'animation' => ['type' => 'fade_up']]),
                    $section('b', 'Second', [sbp8_block('probe.note', ['caption' => 'Stored block'])]),
                ]);
                $page = $rt2->tenants->runAs(SBP8_B, static fn(): array => sbp8_stored_page(SBP8_B, 'stored-sections', 'page', $old, true));
                $storedJson = (string) Database::value('SELECT document_json FROM studiobuilder_revisions WHERE tenant_id = ? AND id = ?', [SBP8_B, $page['rev']]);
                $containerId = 'blk_' . str_repeat('a', 16);
                $assertUpgraded = static function (array $doc, string $what) use ($containerId): void {
                    assert_eq('2.0', $doc['schema_version'], "{$what}: the current format");
                    assert_true(!array_key_exists('sections', $doc), "{$what}: no sections key");
                    assert_eq($containerId, $doc['containers'][0]['id'], "{$what}: the same id on every read");
                    assert_eq('Intro', $doc['containers'][0]['metadata']['label'], "{$what}: the label");
                    assert_eq('core.container', $doc['containers'][0]['type'], "{$what}: a Container");
                    assert_eq('Legacy one', $doc['containers'][0]['children'][0]['props']['text'], "{$what}: its blocks");
                    assert_eq(['text' => 'Stored block'], $doc['containers'][1]['children'][0]['props'], "{$what}: block upgrades still run");
                };

                // A published page written by an older Studio is served as it is, before anyone opens it.
                $served = $rt2->tenants->runAs(SBP8_B, static fn() => $rt2->publicRuntime->handlePath('stored-sections'));
                assert_true($served !== null && $served->status === 200, 'the stored page is served');
                assert_true(str_contains($served->body, 'Legacy one'), 'with its content');
                assert_true((bool) preg_match('/<section class="[^"]*\bsb-section\b/', $served->body), 'a section element that keeps the class site CSS looks for');
                assert_true(str_contains($served->body, '--sb-box:var(--sb-layout-narrow,'), 'at its width');

                // Editor load: the current format, with stable ids.
                $loaded = sbp8_ok(sbp8_call($rt2, SBP8_B, $editor, 'GET', 'document', ['page' => $page['id']]), 'load 1.0 page');
                $assertUpgraded($loaded['document'], 'load');
                assert_eq($loaded['document'], sbp8_ok(sbp8_call($rt2, SBP8_B, $editor, 'GET', 'document', ['page' => $page['id']]), 'load again')['document'], 'two reads, one document');
                $canvas = $rt2->tenants->runAs(SBP8_B, static fn() => $rt2->app->renderForEditor($editor, $page['id']));
                assert_true(str_contains($canvas->html, 'data-sb-node="' . $containerId . '"'), 'the canvas selects the Container by the id the editor holds');

                // An edit by that id writes a NEW revision in the current format; the stored one is untouched.
                $r = sbp8_ok(sbp8_call($rt2, SBP8_B, $editor, 'POST', 'operations', ['page_id' => $page['id'], 'expected_revision_id' => $page['rev'], 'operations' => [
                    ['op' => 'update_block_meta', 'payload' => ['block_id' => $containerId, 'label' => 'Intro renamed']],
                    ['op' => 'insert_block', 'payload' => ['parent_id' => $containerId, 'index' => 2, 'block' => $heading('Added later')]],
                ]]), 'edit 1.0 page');
                $edited = CanonicalJson::decode((string) Database::value('SELECT document_json FROM studiobuilder_revisions WHERE tenant_id = ? AND id = ?', [SBP8_B, (int) $r['revision']['id']]));
                assert_eq('2.0', $edited['schema_version'], 'the next save writes the current format');
                assert_true(!str_contains((string) json_encode($edited), '"sections"'), 'and no trace of the old one');
                assert_eq($storedJson, (string) Database::value('SELECT document_json FROM studiobuilder_revisions WHERE tenant_id = ? AND id = ?', [SBP8_B, $page['rev']]), 'history is never rewritten');

                // A diff across the two formats reports the edit, nothing else.
                $review = sbp8_ok(sbp8_call($rt2, SBP8_B, $viewer, 'GET', 'diff', ['page' => $page['id'], 'base' => $page['rev'], 'proposed' => (int) $r['revision']['id']]), 'diff 1.0 and 2.0')['review'];
                $summary = $review['diff']['summary'] ?? $review['summary'] ?? [];
                assert_eq(0, $summary['containers_added'] ?? null, 'the upgrade itself is not a change');
                assert_eq(0, $summary['containers_removed'] ?? null);
                assert_eq(1, $summary['containers_updated'] ?? null, 'the renamed Container');
                assert_eq(1, $summary['blocks_added'] ?? null);
                assert_eq(0, $summary['blocks_updated'] ?? null, 'nor is the block upgrade');
                assert_false($summary['settings_changed'] ?? true, 'and the settings and SEO defaults are not');
                assert_false($summary['seo_changed'] ?? true);

                // Publish, then roll back to the 1.0 revision: both write the current format.
                $pub = sbp8_ok(sbp8_call($rt2, SBP8_B, $publisher, 'POST', 'publish', ['page_id' => $page['id'], 'expected_revision_id' => (int) $r['revision']['id']]), 'publish');
                $published = CanonicalJson::decode((string) Database::value('SELECT r.document_json FROM studiobuilder_revisions r JOIN studiobuilder_pages p ON p.published_revision_id = r.id AND p.tenant_id = r.tenant_id WHERE p.id = ? AND p.tenant_id = ?', [$page['id'], SBP8_B]));
                assert_eq('2.0', $published['schema_version'], 'published in the current format');
                sbp8_ok(sbp8_call($rt2, SBP8_B, $editor, 'POST', 'rollback', ['page_id' => $page['id'], 'target_revision_id' => $page['rev'], 'expected_revision_id' => (int) ($pub['revision']['id'] ?? 0)]), 'rollback to 1.0');
                $assertUpgraded(sbp8_draft($rt2, SBP8_B, $page['id']), 'rollback');

                // Export carries the current format, and a package written before Containers still imports.
                $pkg = sbp8_ok(sbp8_call($rt2, SBP8_B, $viewer, 'GET', 'export_package', ['page' => $page['id'], 'include_components' => '0', 'include_template' => '0']), 'export')['package'];
                $assertUpgraded($pkg['items'][0]['document'], 'export');
                $legacyItem = $pkg['items'][0];
                $legacyItem['slug'] = 'imported-sections';
                $legacyItem['document'] = $old;
                $legacyPkg = sbp8_rehash(array_replace($pkg, ['items' => [$legacyItem]]));
                $dry = sbp8_report(sbp8_import($rt2, SBP8_B, $editor, $legacyPkg, true));
                assert_eq([], sbp8_codes($dry, 'error'), 'a 1.0 package passes the dry run');
                $data = sbp8_ok(sbp8_import($rt2, SBP8_B, $editor, $legacyPkg, false), 'commit 1.0 package');
                $imported = sbp8_draft($rt2, SBP8_B, (int) $data['report']['committed']['pages'][0]['page_id']);
                assert_eq('2.0', $imported['schema_version'], 'stored in the current format');
                assert_eq('Legacy one', $imported['containers'][0]['children'][0]['props']['text'], 'with its content');
                assert_true($imported['containers'][0]['id'] !== $containerId, 'and fresh ids');

                // Templates stored as sections: applied and inserted as Containers, with fresh ids.
                foreach ([['old-format-page', 'page_template', $legacy('page', [$section('c', 'Applied', [$heading('From a stored page template')])])], ['old-format-preset', 'container_preset', $legacy('section_preset', [$section('d', 'Inserted', [$heading('From a stored preset')])])]] as [$key, $type, $doc]) {
                    $rt2->tenants->runAs(SBP8_B, static fn() => $rt2->templates->insert([
                        'uuid' => sprintf('%08x-7777-4000-8000-%012x', random_int(0, 0x7fffffff), random_int(0, 0x7fffffff)), 'template_key' => $key, 'template_type' => $type, 'category' => 'general',
                        'name' => $key, 'schema_version' => '1.0', 'document_json' => CanonicalJson::encode($doc), 'is_system' => 0,
                    ]));
                }
                $created = sbp8_ok(sbp8_call($rt2, SBP8_B, $editor, 'POST', 'create_page', ['title' => 'From old templates', 'slug' => 'from-old-format', 'page_type' => 'page', 'route_mode' => 'standalone']), 'target page');
                $target = (int) $created['page']['id'];
                $tr = sbp8_ok(sbp8_call($rt2, SBP8_B, $editor, 'POST', 'apply_template', ['page_id' => $target, 'template_key' => 'old-format-page', 'expected_revision_id' => (int) $created['page']['active_draft_revision_id']]), 'apply 1.0 template');
                sbp8_ok(sbp8_call($rt2, SBP8_B, $editor, 'POST', 'insert_template', ['page_id' => $target, 'template_key' => 'old-format-preset', 'index' => 1, 'parent_id' => null, 'expected_revision_id' => (int) $tr['revision']['id']]), 'insert 1.0 preset');
                $fromTemplates = sbp8_draft($rt2, SBP8_B, $target);
                assert_eq('2.0', $fromTemplates['schema_version']);
                assert_eq(['Applied', 'Inserted'], array_map(static fn(array $c): string => (string) $c['metadata']['label'], $fromTemplates['containers']), 'each a Container');
                assert_eq('From a stored preset', $fromTemplates['containers'][1]['children'][0]['props']['text']);
                assert_true($fromTemplates['containers'][1]['id'] !== 'blk_' . str_repeat('d', 16), 'an inserted preset gets fresh ids');

                // A component stored as sections, referenced from a section: rendered live, then detached into an owned copy.
                $component = $rt2->tenants->runAs(SBP8_B, static fn(): array => sbp8_stored_page(SBP8_B, 'stored-section-component', 'container_preset', $legacy('section_preset', [$section('e', 'Shared', [$heading('Shared legacy text')])]), true));
                $hostDoc = $legacy('page', [$section('f', 'Own', [$heading('Host text')]), $section('9', 'Ref', [], ['global_ref' => $component['uuid']])]);
                $host = $rt2->tenants->runAs(SBP8_B, static fn(): array => sbp8_stored_page(SBP8_B, 'stored-section-host', 'page', $hostDoc, false));
                $hostCanvas = $rt2->tenants->runAs(SBP8_B, static fn() => $rt2->app->renderForEditor($editor, $host['id']));
                assert_true(str_contains($hostCanvas->html, 'Shared legacy text'), 'a section reference still shows its component');
                sbp8_ok(sbp8_call($rt2, SBP8_B, $editor, 'POST', 'detach_component', ['page_id' => $host['id'], 'container_id' => 'blk_' . str_repeat('9', 16), 'expected_revision_id' => $host['rev']]), 'detach 1.0 reference');
                $detached = sbp8_draft($rt2, SBP8_B, $host['id']);
                assert_null($detached['containers'][1]['global_ref'] ?? null, 'an owned copy');
                assert_eq('Shared legacy text', $detached['containers'][1]['children'][0]['props']['text'], 'of the component\'s content');
                assert_eq('2.0', $detached['schema_version']);
            });

            // ── Rate limit: packages have their own small bucket ─────────────
            unit('phase8a int 11: the package actions count against their own rate-limit bucket on top of the command bucket', function (): void {
                $limiter = new \Slate\Module\StudioBuilder\Http\StudioApiRateLimiter(static fn(): int => 1000);
                $bucket = null;
                for ($i = 0; $i < \Slate\Module\StudioBuilder\Http\StudioApiRateLimiter::MAX_PACKAGES; $i++) {
                    assert_true($limiter->hit($bucket, 'POST', 'import_package'));
                }
                assert_false($limiter->hit($bucket, 'POST', 'import_package'), 'package bucket exhausted');
                assert_false($limiter->hit($bucket, 'GET', 'export_package'));
                assert_true($limiter->hit($bucket, 'POST', 'operations'), 'ordinary editing is unaffected');
            });
        });
    } finally {
        sbp8_drop_db($dbName);
    }
});

if (!empty($studioP8IntStandalone)) {
    exit(unit_summary());
}
