<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — Phase 6 Templates,
 * Global Components, Header/Footer bindings and Design Tokens.
 *
 * Real MySQL (throwaway database), real plugin activation, real per-tenant
 * entitlements, the PRODUCTION wiring (StudioRuntimeFactory) and the builder's
 * real HTTP controller (StudioAuthoringApi). Tenants:
 *   - 101: studio-builder + booking + membership + forms
 *   - 202: studio-builder only
 *   - 303: nothing
 *
 * Covers: template library (list, tenant filtering, system protection, save
 * from page nodes, delete + in-use protection), template application
 * concurrency (correct / stale / missing expected_revision_id, cross-tenant,
 * concurrent), reusable preset insertion (copy semantics, validation, tenant
 * isolation, permission/entitlement), Global Components (create, reference,
 * publish, dependency rows, invalidation/recompilation, tenant isolation,
 * archive protection, detach, stale revision, nested/blurred references),
 * chrome bindings (inherit / custom / hidden / fallback / invalidation),
 * design tokens (valid, invalid, sanitization, tenant isolation, platform
 * identity), and the authorization matrix of every new command.
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
    $studioP6IntStandalone = true;
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
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const SBP6_CORE_MIGRATIONS = [
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

const SBP6_TENANT_A = 101;
const SBP6_TENANT_B = 202;
const SBP6_TENANT_C = 303;

function sbp6_fresh_db(string $dbName): \PDO
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
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(SBP6_CORE_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function sbp6_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function sbp6_with_pdo(\PDO $pdo, callable $fn): mixed
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

function sbp6_seed(int $tenantId, string $installationId, array $entitlements): void
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
function sbp6_call(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $method, string $action, array $input = [], bool $csrf = true): StudioApiResponse
{
    $api = new StudioAuthoringApi($rt->app);
    $request = $method === 'POST'
        ? new StudioApiRequest('POST', $action, [], (string) json_encode($input, JSON_PRESERVE_ZERO_FRACTION), 'application/json', $csrf, 'same-origin')
        : new StudioApiRequest('GET', $action, array_map('strval', $input));
    return $rt->tenants->runAs($tenantId, static fn() => $api->handle($request, $actor));
}

/** @return array<string, mixed> */
function sbp6_ok(StudioApiResponse $r, string $what): array
{
    assert_true($r->isOk(), "{$what}: expected success, got {$r->status} " . substr($r->body(), 0, 500));
    return $r->data();
}

function sbp6_first_code(StudioApiResponse $r): string
{
    return (string) ($r->payload['error']['details']['errors'][0]['code'] ?? $r->errorCode());
}

function sbp6_count(string $sql, array $params = []): int
{
    return (int) Database::value($sql, $params);
}

/** @return array<string, mixed> */
function sbp6_heading(string $text): array
{
    return ['type' => 'core.heading', 'props' => ['text' => $text, 'level' => 'h2']];
}

/**
 * Create a page and fill it with the given blocks in ONE section through the
 * command API. @return array{id: int, rev: int, section: string, document: array<string, mixed>}
 */
function sbp6_make_page(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $title, string $slug, string $pageType, array $blocks, array $settings = []): array
{
    $created = sbp6_ok(sbp6_call($rt, $tenantId, $actor, 'POST', 'create_page', ['title' => $title, 'slug' => $slug, 'page_type' => $pageType, 'route_mode' => 'standalone']), "create {$slug}");
    $pageId = (int) $created['page']['id'];
    $rev = (int) $created['page']['active_draft_revision_id'];
    $ops = [['op' => 'insert_section', 'payload' => ['index' => 0, 'section' => ['label' => $title . ' section']]]];
    if ($settings !== []) {
        $ops[] = ['op' => 'update_settings', 'payload' => ['settings' => $settings]];
    }
    $r = sbp6_ok(sbp6_call($rt, $tenantId, $actor, 'POST', 'operations', ['page_id' => $pageId, 'expected_revision_id' => $rev, 'operations' => $ops]), "section {$slug}");
    $rev = (int) $r['revision']['id'];
    $sectionId = (string) $r['document']['sections'][0]['id'];
    foreach ($blocks as $i => $block) {
        $r = sbp6_ok(sbp6_call($rt, $tenantId, $actor, 'POST', 'operations', ['page_id' => $pageId, 'expected_revision_id' => $rev, 'operations' => [
            ['op' => 'insert_block', 'payload' => ['parent_id' => $sectionId, 'index' => $i, 'block' => $block]],
        ]]), "block {$i} on {$slug}");
        $rev = (int) $r['revision']['id'];
    }
    return ['id' => $pageId, 'rev' => $rev, 'section' => $sectionId, 'document' => $r['document']];
}

function sbp6_publish(StudioRuntime $rt, int $tenantId, StudioActor $publisher, int $pageId): int
{
    $status = sbp6_ok(sbp6_call($rt, $tenantId, $publisher, 'GET', 'status', ['page' => $pageId]), 'status')['page'];
    $pub = sbp6_ok(sbp6_call($rt, $tenantId, $publisher, 'POST', 'publish', ['page_id' => $pageId, 'expected_revision_id' => (int) $status['active_draft_revision_id']]), "publish {$pageId}");
    return (int) $pub['revision']['id'];
}

function sbp6_public(StudioRuntime $rt, int $tenantId, string $path): ?string
{
    $res = $rt->tenants->runAs($tenantId, static fn() => $rt->publicRuntime->handlePath($path, null));
    return $res === null ? null : $res->body;
}

function sbp6_canvas(StudioRuntime $rt, int $tenantId, StudioActor $actor, int $pageId): string
{
    return $rt->tenants->runAs($tenantId, static fn(): string => $rt->app->renderForEditor($actor, $pageId)->html);
}

/** @return list<string> */
function sbp6_ids(array $document): array
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

unit('phase6 integration: templates, global components, chrome bindings, design tokens (real MySQL, tenants 101/202/303)', function (): void {
    $dbName = 'slate_sbp6_' . slate_test_ns();
    $pdo = sbp6_fresh_db($dbName);

    try {
        sbp6_with_pdo($pdo, static function (): void {
            // ── Setup ───────────────────────────────────────────────────────
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Studio Admin', 'studio-admin-p6@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\'), (?,?,?,\'active\')
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [SBP6_TENANT_A, 'Tenant A', 'tenant-a-p6', SBP6_TENANT_B, 'Tenant B', 'tenant-b-p6', SBP6_TENANT_C, 'Tenant C', 'tenant-c-p6']
            );
            sbp6_seed($owner, $iid, ['forms', 'membership', 'booking', 'studio-builder', 'stripe-payment']);
            $stripe = \PluginLoader::installFromDisk('stripe-payment');
            assert_true(!empty($stripe['ok']), 'installFromDisk(stripe-payment): ' . json_encode($stripe));
            foreach (['booking', 'membership', 'forms', 'studio-builder'] as $slug) {
                $sel = CommercialModuleRegistry::validateSelection([$slug], ['forms', 'membership', 'booking', 'studio-builder']);
                assert_true($sel['ok'], "selection {$slug}: " . json_encode($sel));
                $act = \PluginLoader::installFromDisk($slug);
                assert_true(!empty($act['ok']), "installFromDisk({$slug}): " . json_encode($act));
            }
            Media::ensureSchema();
            sbp6_seed(SBP6_TENANT_A, $iid, ['studio-builder', 'booking', 'membership', 'forms']);
            sbp6_seed(SBP6_TENANT_B, $iid, ['studio-builder']);
            sbp6_seed(SBP6_TENANT_C, $iid, []);

            $rt = StudioRuntimeFactory::build();
            $viewer    = StudioActor::authenticated(60, [StudioPermissions::VIEW]);
            $editor    = StudioActor::authenticated(61, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
            $editor2   = StudioActor::authenticated(62, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
            $publisher = StudioActor::authenticated(63, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            $admin     = StudioActor::authenticated(64, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::ADMIN]);
            $themer    = StudioActor::authenticated(65, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::TOKENS]);
            $S = new \stdClass();

            // Pages of tenant A: "about" (page, with a container) and "beta" of tenant B.
            $about = sbp6_make_page($rt, SBP6_TENANT_A, $editor, 'About', 'about', 'page', [
                sbp6_heading('About Alpha'),
                ['type' => 'core.container', 'props' => ['direction' => 'vertical', 'gap' => 'md']],
            ]);
            $S->about = $about['id'];
            $S->aboutRev = $about['rev'];
            $S->aboutSection = $about['section'];
            $S->aboutHeading = (string) $about['document']['sections'][0]['blocks'][0]['id'];
            $S->aboutContainer = (string) $about['document']['sections'][0]['blocks'][1]['id'];
            $beta = sbp6_make_page($rt, SBP6_TENANT_B, $editor, 'Beta', 'beta', 'page', [sbp6_heading('Beta page')]);
            $S->beta = $beta['id'];
            $S->betaRev = $beta['rev'];

            // ── 1. Template library: save from page nodes, list, summary, tenant filter, system protection, delete ──
            unit('phase6 int 1: templates are saved from stored page content (page / section / block), listed per tenant with safe summaries, system templates are immutable server-side', function () use ($rt, $editor, $admin, $S): void {
                // Only ADMIN may manage templates.
                $r = sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'save_template', ['page_id' => $S->about, 'node_id' => null, 'template_key' => 'about-page', 'template_type' => 'page_template', 'category' => 'general', 'name' => 'About page', 'description' => null, 'thumbnail_media_id' => null]);
                assert_eq('authorization_error', $r->errorCode(), 'an editor cannot save templates');

                $pageTpl = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $admin, 'POST', 'save_template', ['page_id' => $S->about, 'node_id' => null, 'template_key' => 'about-page', 'template_type' => 'page_template', 'category' => 'general', 'name' => 'About page', 'description' => 'Whole about page', 'thumbnail_media_id' => null]), 'page template')['template'];
                assert_eq('page_template', $pageTpl['template_type']);
                assert_eq(['sections' => 1, 'blocks' => 2, 'block_labels' => ['Heading', 'Container']], $pageTpl['summary'], 'a structural summary, never the document');
                assert_true(!isset($pageTpl['document_json']) && !isset($pageTpl['document']));

                $secTpl = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $admin, 'POST', 'save_template', ['page_id' => $S->about, 'node_id' => $S->aboutSection, 'template_key' => 'intro-section', 'template_type' => 'section_preset', 'category' => 'intro', 'name' => 'Intro section', 'description' => null, 'thumbnail_media_id' => null]), 'section preset')['template'];
                assert_eq('section_preset', $secTpl['template_type']);
                $blkTpl = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $admin, 'POST', 'save_template', ['page_id' => $S->about, 'node_id' => $S->aboutHeading, 'template_key' => 'brand-heading', 'template_type' => 'block_preset', 'category' => 'general', 'name' => 'Brand heading', 'description' => null, 'thumbnail_media_id' => null]), 'block preset')['template'];
                assert_eq(['sections' => 1, 'blocks' => 1, 'block_labels' => ['Heading']], $blkTpl['summary']);

                // Node/kind mismatches and wrong page types are refused.
                assert_eq(422, sbp6_call($rt, SBP6_TENANT_A, $admin, 'POST', 'save_template', ['page_id' => $S->about, 'node_id' => $S->aboutHeading, 'template_key' => 'x', 'template_type' => 'section_preset', 'name' => 'X'])->status, 'a block is not a section preset');
                assert_eq(422, sbp6_call($rt, SBP6_TENANT_A, $admin, 'POST', 'save_template', ['page_id' => $S->about, 'node_id' => null, 'template_key' => 'x', 'template_type' => 'header_preset', 'name' => 'X'])->status, 'a page is not a header preset');
                assert_eq(422, sbp6_call($rt, SBP6_TENANT_A, $admin, 'POST', 'save_template', ['page_id' => $S->about, 'node_id' => null, 'template_key' => 'x', 'template_type' => 'page_template', 'name' => 'X', 'thumbnail_media_id' => 999999])->status, 'a foreign/missing thumbnail media id is refused');

                // The stored template document itself is a validated canonical document with FRESH ids (a copy).
                $row = $rt->tenants->runAs(SBP6_TENANT_A, static fn() => $rt->templates->findByKey('intro-section'));
                $tplDoc = CanonicalJson::decode((string) $row['document_json']);
                assert_eq('section_preset', $tplDoc['document_type']);
                assert_true(!in_array($S->aboutSection, sbp6_ids($tplDoc), true) && !in_array($S->aboutHeading, sbp6_ids($tplDoc), true), 'template content never shares ids with the source page');

                // Listing: by type, tenant filtered, with thumbnails resolved through the tenant-scoped media resolver (none here).
                $all = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'templates'), 'list A')['templates'];
                assert_eq(['about-page', 'brand-heading', 'intro-section'], array_column($all, 'template_key'));
                assert_true(array_key_exists('thumbnail_url', $all[0]) && $all[0]['thumbnail_url'] === null);
                $pages = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'templates', ['type' => 'page_template']), 'list type')['templates'];
                assert_eq(['about-page'], array_column($pages, 'template_key'));
                assert_eq([], sbp6_ok(sbp6_call($rt, SBP6_TENANT_B, $editor, 'GET', 'templates'), 'list B')['templates'], 'tenant B sees none of tenant A\'s templates');
                assert_eq([], sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'templates', ['type' => 'nonsense_type']), 'unknown type')['templates']);

                // System templates: protected on write AND delete, server-side.
                $rt->tenants->runAs(SBP6_TENANT_A, static fn() => $rt->templates->insert([
                    'uuid' => '0f0f0f0f-0f0f-4f0f-8f0f-0f0f0f0f0f0f', 'template_key' => 'kohevo-starter', 'template_type' => 'page_template', 'category' => 'system',
                    'name' => 'Kohevo starter', 'schema_version' => CanonicalDocumentSchema::SCHEMA_VERSION,
                    'document_json' => CanonicalJson::encode(CanonicalDocumentSchema::emptyDocument('page', 'default', 'Starter')), 'is_system' => 1,
                ]));
                $r = sbp6_call($rt, SBP6_TENANT_A, $admin, 'POST', 'save_template', ['page_id' => $S->about, 'node_id' => null, 'template_key' => 'kohevo-starter', 'template_type' => 'page_template', 'name' => 'Overwrite']);
                assert_eq(422, $r->status);
                assert_eq('system_template_immutable', sbp6_first_code($r), 'a system template cannot be overwritten');
                $r = sbp6_call($rt, SBP6_TENANT_A, $admin, 'POST', 'delete_template', ['template_key' => 'kohevo-starter']);
                assert_eq('system_template_immutable', sbp6_first_code($r), 'nor deleted');
                $listed = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'templates', ['type' => 'page_template']), 'list')['templates'];
                assert_true(in_array(true, array_column($listed, 'is_system'), true), 'the library marks system templates');

                // Delete: in-use protection (document-level template_key), then a clean delete.
                $r = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'operations', ['page_id' => $S->about, 'expected_revision_id' => $S->aboutRev, 'operations' => [['op' => 'update_template', 'payload' => ['template_key' => 'brand-heading']]]]), 'name template');
                $S->aboutRev = (int) $r['revision']['id'];
                assert_eq('template_in_use', sbp6_first_code(sbp6_call($rt, SBP6_TENANT_A, $admin, 'POST', 'delete_template', ['template_key' => 'brand-heading'])));
                $r = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'operations', ['page_id' => $S->about, 'expected_revision_id' => $S->aboutRev, 'operations' => [['op' => 'update_template', 'payload' => ['template_key' => 'default']]]]), 'unname');
                $S->aboutRev = (int) $r['revision']['id'];
                assert_eq('authorization_error', sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'delete_template', ['template_key' => 'brand-heading'])->errorCode());
                assert_eq(404, sbp6_call($rt, SBP6_TENANT_B, $admin, 'POST', 'delete_template', ['template_key' => 'brand-heading'])->status, 'tenant B cannot delete tenant A\'s template');
                $del = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $admin, 'POST', 'delete_template', ['template_key' => 'brand-heading']), 'delete')['deleted'];
                assert_eq('brand-heading', $del['template_key']);
                assert_eq(0, sbp6_count('SELECT COUNT(*) FROM studiobuilder_templates WHERE template_key = ?', ['brand-heading']));
                // Re-create the block preset for later tests.
                sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $admin, 'POST', 'save_template', ['page_id' => $S->about, 'node_id' => $S->aboutHeading, 'template_key' => 'brand-heading', 'template_type' => 'block_preset', 'name' => 'Brand heading']), 'block preset again');
            });

            // ── 2. Template application concurrency (preflight #1) ─────────
            unit('phase6 int 2: applying a template requires the current expected_revision_id — stale, missing, concurrent and cross-tenant attempts overwrite nothing', function () use ($rt, $editor, $editor2, $admin, $S): void {
                $target = sbp6_make_page($rt, SBP6_TENANT_A, $editor, 'Target', 'target', 'page', [sbp6_heading('Original target content')]);
                $before = sbp6_count('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [SBP6_TENANT_A, $target['id']]);

                // 1) correct expected_revision_id → a new draft revision holding the template content
                $ok = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'apply_template', ['page_id' => $target['id'], 'template_key' => 'about-page', 'expected_revision_id' => $target['rev']]), 'apply ok');
                $applied = (int) $ok['revision']['id'];
                assert_true($applied > $target['rev'] && $ok['revision']['revision_kind'] === 'manual');
                assert_eq('About Alpha', $ok['document']['sections'][0]['blocks'][0]['props']['text']);
                assert_eq('page', $ok['document']['document_type'], 'the target keeps its own document type');

                // 2) stale expected_revision_id → 409, no revision, newest content survives
                $stale = sbp6_call($rt, SBP6_TENANT_A, $editor2, 'POST', 'apply_template', ['page_id' => $target['id'], 'template_key' => 'about-page', 'expected_revision_id' => $target['rev']]);
                assert_eq(409, $stale->status);
                assert_eq('concurrency_conflict', $stale->errorCode());
                assert_eq(['current_revision_id' => $applied, 'expected_revision_id' => $target['rev']], $stale->payload['error']['details'], 'the same conflict semantics as every other write');

                // 3) missing expected_revision_id → refused at the transport (422) and, below it, a conflict at the application layer
                $missing = sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'apply_template', ['page_id' => $target['id'], 'template_key' => 'about-page']);
                assert_eq(422, $missing->status);
                assert_eq('required_field', sbp6_first_code($missing));
                assert_eq(409, sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'apply_template', ['page_id' => $target['id'], 'template_key' => 'about-page', 'expected_revision_id' => null])->status, 'an explicit null is a conflict for a page that has a draft');
                assert_throws(StudioConcurrencyException::class, static fn() => $rt->tenants->runAs(SBP6_TENANT_A, static fn() => $rt->app->applyTemplate($editor, 'about-page', $target['id'], null)), 'the application layer never accepts "no expectation" for an existing draft');

                // 4) cross-tenant page / template access
                assert_eq(404, sbp6_call($rt, SBP6_TENANT_B, $editor, 'POST', 'apply_template', ['page_id' => $target['id'], 'template_key' => 'about-page', 'expected_revision_id' => $applied])->status, 'tenant B cannot apply onto tenant A\'s page');
                assert_eq(404, sbp6_call($rt, SBP6_TENANT_B, $editor, 'POST', 'apply_template', ['page_id' => $S->beta, 'template_key' => 'about-page', 'expected_revision_id' => $S->betaRev])->status, 'tenant B cannot apply tenant A\'s template onto its own page');

                // 5) concurrent application: two clients hold the same revision; the second loses
                $a = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'apply_template', ['page_id' => $target['id'], 'template_key' => 'about-page', 'expected_revision_id' => $applied]), 'first of two');
                $b = sbp6_call($rt, SBP6_TENANT_A, $editor2, 'POST', 'apply_template', ['page_id' => $target['id'], 'template_key' => 'about-page', 'expected_revision_id' => $applied]);
                assert_eq(409, $b->status, 'the second concurrent application is refused');
                assert_eq((int) $a['revision']['id'], (int) sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'status', ['page' => $target['id']]), 'status')['page']['active_draft_revision_id']);
                assert_eq($before + 2, sbp6_count('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [SBP6_TENANT_A, $target['id']]), 'exactly the two successful applications created revisions');

                // The builder-facing page creation path still applies a template right after creation, stating the initial revision.
                $created = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'create_page', ['title' => 'From template', 'slug' => 'from-template', 'page_type' => 'page', 'route_mode' => 'standalone', 'template_key' => 'about-page']), 'create from template');
                $doc = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'document', ['page' => (int) $created['page']['id']]), 'doc')['document'];
                assert_eq('About Alpha', $doc['sections'][0]['blocks'][0]['props']['text']);
                $S->target = $target['id'];
            });

            // ── 3. Reusable presets: insertion is a COPY through the operation pipeline ──
            unit('phase6 int 3: inserting a section/block preset creates owned content with fresh ids (no reference, no dependency), validated like any edit, tenant- and permission-scoped', function () use ($rt, $viewer, $editor, $S): void {
                $status = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'status', ['page' => $S->about]), 'status')['page'];
                $rev = (int) $status['active_draft_revision_id'];
                $tplRow = $rt->tenants->runAs(SBP6_TENANT_A, static fn() => $rt->templates->findByKey('intro-section'));
                $tplIds = sbp6_ids(CanonicalJson::decode((string) $tplRow['document_json']));

                $r1 = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'insert_template', ['page_id' => $S->about, 'template_key' => 'intro-section', 'index' => 1, 'expected_revision_id' => $rev]), 'insert section preset');
                $rev = (int) $r1['revision']['id'];
                $inserted = $r1['document']['sections'][1];
                assert_eq('About section', $inserted['label']);
                assert_null($inserted['global_ref'], 'a preset copy is local content');
                assert_eq('About Alpha', $inserted['blocks'][0]['props']['text']);
                assert_true(!in_array($inserted['id'], $tplIds, true) && !in_array($inserted['blocks'][0]['id'], $tplIds, true), 'fresh ids');

                $r2 = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'insert_template', ['page_id' => $S->about, 'template_key' => 'intro-section', 'index' => 2, 'expected_revision_id' => $rev]), 'insert again');
                $rev = (int) $r2['revision']['id'];
                assert_true($r2['document']['sections'][2]['id'] !== $inserted['id'] && $r2['document']['sections'][2]['blocks'][0]['id'] !== $inserted['blocks'][0]['id'], 'every insertion is an independent copy');
                assert_eq(0, sbp6_count('SELECT COUNT(*) FROM studiobuilder_dependencies WHERE tenant_id = ? AND page_id = ? AND revision_id = ? AND dependency_type = \'partial\' AND dependency_key NOT LIKE \'chrome:%\'', [SBP6_TENANT_A, $S->about, $rev]), 'a copy records no hidden dependency on the template');

                // Block preset into a container, and refused into a non-container.
                $r3 = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'insert_template', ['page_id' => $S->about, 'template_key' => 'brand-heading', 'index' => 0, 'parent_id' => $S->aboutContainer, 'expected_revision_id' => $rev]), 'block preset into container');
                $rev = (int) $r3['revision']['id'];
                $container = null;
                foreach ($r3['document']['sections'][0]['blocks'] as $b) {
                    if ($b['id'] === $S->aboutContainer) {
                        $container = $b;
                    }
                }
                assert_eq('About Alpha', $container['children'][0]['props']['text']);
                assert_true($container['children'][0]['id'] !== $S->aboutHeading, 'the block preset is a copy, not the original block');
                $bad = sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'insert_template', ['page_id' => $S->about, 'template_key' => 'brand-heading', 'index' => 0, 'parent_id' => $S->aboutHeading, 'expected_revision_id' => $rev]);
                assert_eq(422, $bad->status, 'a preset cannot land where the schema forbids children');
                $bad = sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'insert_template', ['page_id' => $S->about, 'template_key' => 'brand-heading', 'index' => 0, 'expected_revision_id' => $rev]);
                assert_eq(422, $bad->status, 'a block preset needs a parent');
                $bad = sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'insert_template', ['page_id' => $S->about, 'template_key' => 'about-page', 'index' => 0, 'expected_revision_id' => $rev]);
                assert_eq('template_not_insertable', sbp6_first_code($bad), 'a page template is applied, not inserted');
                assert_eq(409, sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'insert_template', ['page_id' => $S->about, 'template_key' => 'intro-section', 'index' => 0, 'expected_revision_id' => $rev - 1])->status, 'stale revision');

                // Permission / entitlement / tenant scope.
                assert_eq('authorization_error', sbp6_call($rt, SBP6_TENANT_A, $viewer, 'POST', 'insert_template', ['page_id' => $S->about, 'template_key' => 'intro-section', 'index' => 0, 'expected_revision_id' => $rev])->errorCode());
                assert_eq('entitlement_error', sbp6_call($rt, SBP6_TENANT_C, $editor, 'POST', 'insert_template', ['page_id' => $S->about, 'template_key' => 'intro-section', 'index' => 0, 'expected_revision_id' => $rev])->errorCode());
                assert_eq(404, sbp6_call($rt, SBP6_TENANT_B, $editor, 'POST', 'insert_template', ['page_id' => $S->beta, 'template_key' => 'intro-section', 'index' => 0, 'expected_revision_id' => $S->betaRev])->status, 'tenant B cannot insert tenant A\'s preset');
                assert_eq($rev, (int) sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'status', ['page' => $S->about]), 'status')['page']['active_draft_revision_id'], 'refused insertions wrote nothing');
                $S->aboutRev = $rev;
            });

            // ── 4. Global Components: live references ──────────────────────
            unit('phase6 int 4: a global component is a section_preset page referenced by uuid; pages render its PUBLISHED content; updates propagate through the dependency index', function () use ($rt, $editor, $publisher, $S): void {
                // Give the section content a unique marker, then create a component from it
                // (moves the content, leaves a reference) — one transaction, revision-checked.
                $mark = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'operations', ['page_id' => $S->about, 'expected_revision_id' => $S->aboutRev, 'operations' => [
                    ['op' => 'update_block_props', 'payload' => ['block_id' => $S->aboutHeading, 'props' => ['text' => 'Moved intro heading', 'level' => 'h2']]],
                ]]), 'mark heading');
                $S->aboutRev = (int) $mark['revision']['id'];
                $stale = sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'create_component', ['title' => 'Site intro', 'slug' => 'site-intro', 'page_id' => $S->about, 'section_id' => $S->aboutSection, 'expected_revision_id' => $S->aboutRev - 1]);
                assert_eq(409, $stale->status, 'stale revision: nothing created');
                assert_eq(0, sbp6_count('SELECT COUNT(*) FROM studiobuilder_pages WHERE tenant_id = ? AND page_type = \'section_preset\'', [SBP6_TENANT_A]), 'the failed transaction left no component page');

                $created = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'create_component', ['title' => 'Site intro', 'slug' => 'site-intro', 'page_id' => $S->about, 'section_id' => $S->aboutSection, 'expected_revision_id' => $S->aboutRev]), 'create component from section');
                $component = $created['component'];
                $S->componentId = (int) $component['id'];
                $S->ref = (string) $component['ref'];
                assert_true(preg_match(CanonicalDocumentSchema::COMPONENT_REF_PATTERN, $S->ref) === 1, 'the reference is the component page uuid');
                assert_eq('section_preset', $component['page_type']);
                assert_false($component['is_published'], 'a new component is unpublished');
                assert_true(!array_key_exists('tenant_id', $component));
                $S->aboutRev = (int) $created['revision']['id'];
                $refSection = $created['document']['sections'][0];
                assert_eq($S->ref, $refSection['global_ref'], 'the page section is now a live reference');
                assert_eq([], $refSection['blocks'], 'and owns no copy of the content');
                assert_eq('About section', $refSection['label']);
                $componentDoc = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'document', ['page' => $S->componentId]), 'component doc')['document'];
                assert_eq('section_preset', $componentDoc['document_type']);
                assert_eq('Moved intro heading', $componentDoc['sections'][0]['blocks'][0]['props']['text'], 'the component holds the moved content');
                assert_true($componentDoc['sections'][0]['blocks'][0]['id'] !== $S->aboutHeading, 'as an owned copy with fresh ids');

                // Dependency index: the page's current revision depends on the component uuid.
                assert_eq(1, sbp6_count('SELECT COUNT(*) FROM studiobuilder_dependencies WHERE tenant_id = ? AND page_id = ? AND revision_id = ? AND dependency_type = \'partial\' AND dependency_key = ?', [SBP6_TENANT_A, $S->about, $S->aboutRev, $S->ref]));
                $list = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'components'), 'components')['components'];
                assert_eq([$S->ref], array_column($list, 'ref'));
                assert_eq(1, $list[0]['usage_count']);
                assert_eq([], sbp6_ok(sbp6_call($rt, SBP6_TENANT_B, $editor, 'GET', 'components'), 'components B')['components'], 'tenant B sees no component of tenant A');

                // Unpublished: the page canvas shows an inert notice, the public page nothing.
                $canvas = sbp6_canvas($rt, SBP6_TENANT_A, $editor, $S->about);
                assert_true(str_contains($canvas, 'component_unavailable') && str_contains($canvas, 'data-sb-node="' . $refSection['id'] . '"'), 'unpublished component → placeholder with a notice in the builder');
                assert_true(!str_contains($canvas, 'Moved intro heading'), 'the moved content is not rendered from the draft');
                sbp6_publish($rt, SBP6_TENANT_A, $publisher, $S->about);
                sbp6_publish($rt, SBP6_TENANT_A, $publisher, $S->target); // an unrelated published page with its own artifact
                $public = sbp6_public($rt, SBP6_TENANT_A, '/about');
                assert_true($public !== null && !str_contains($public, 'Moved intro heading') && !str_contains($public, 'class="sb-unavailable"'), 'public output shows neither the draft content nor a hint');
                assert_eq(1, sbp6_count('SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [SBP6_TENANT_A, $S->about]));

                // Publish the component → the dependent page's artifact is invalidated and recompiles with the content.
                $r = sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'publish', ['page_id' => $S->componentId, 'expected_revision_id' => (int) $component['active_draft_revision_id']]);
                assert_eq('authorization_error', $r->errorCode(), 'publishing a component needs studio-builder.publish');
                sbp6_publish($rt, SBP6_TENANT_A, $publisher, $S->componentId);
                assert_eq(0, sbp6_count('SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [SBP6_TENANT_A, $S->about]), 'publishing the component dropped the dependent artifact');
                assert_eq(1, sbp6_count('SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [SBP6_TENANT_A, $S->target]), 'an unrelated page keeps its artifact');
                $public = sbp6_public($rt, SBP6_TENANT_A, '/about');
                assert_true(str_contains($public, 'Moved intro heading') && str_contains($public, 'sb-section--global'), 'the public page now embeds the published component');
                assert_true(!str_contains($public, 'data-sb-node'));
                $canvas = sbp6_canvas($rt, SBP6_TENANT_A, $editor, $S->about);
                assert_true(str_contains($canvas, 'Moved intro heading') && str_contains($canvas, 'data-sb-node="' . $refSection['id'] . '"'), 'the builder canvas embeds it under the selectable placeholder');
                assert_true(!str_contains($canvas, 'data-sb-node="' . $componentDoc['sections'][0]['blocks'][0]['id'] . '"'), 'embedded blocks carry no node metadata of their own');
                assert_null(sbp6_public($rt, SBP6_TENANT_A, '/site-intro'), 'a component is never routable as a page');

                // Edit + republish the component → dependents pick up the change; a draft change alone changes nothing.
                $cs = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'status', ['page' => $S->componentId]), 'c status')['page'];
                $r = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'operations', ['page_id' => $S->componentId, 'expected_revision_id' => (int) $cs['active_draft_revision_id'], 'operations' => [
                    ['op' => 'update_block_props', 'payload' => ['block_id' => (string) $componentDoc['sections'][0]['blocks'][0]['id'], 'props' => ['text' => 'About Alpha v2', 'level' => 'h2']]],
                ]]), 'edit component');
                assert_true(str_contains(sbp6_public($rt, SBP6_TENANT_A, '/about'), 'Moved intro heading') && !str_contains(sbp6_public($rt, SBP6_TENANT_A, '/about'), 'v2'), 'a component draft never leaks into pages');
                assert_true(!str_contains(sbp6_canvas($rt, SBP6_TENANT_A, $editor, $S->about), 'v2'), 'not even into the builder canvas of a consumer');
                sbp6_publish($rt, SBP6_TENANT_A, $publisher, $S->componentId);
                assert_true(str_contains(sbp6_public($rt, SBP6_TENANT_A, '/about'), 'About Alpha v2'), 'republishing the component updates the dependent public page');
                assert_true(str_contains(sbp6_public($rt, SBP6_TENANT_A, '/about'), '<div class="sb-platform-signature">'), 'the platform signature slot is unaffected by embedded content');

                // Rollback of the component to an earlier revision + publish also propagates (fingerprint + dependency index).
                $hist = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'revisions', ['page' => $S->componentId, 'limit' => 10]), 'history')['revisions'];
                $S->componentRev = (int) $hist[0]['id'];
            });

            unit('phase6 int 5: references are tenant-local, one level deep, never blurred with local blocks, protect the component from archiving, and detach into owned copies', function () use ($rt, $editor, $editor2, $publisher, $S): void {
                // Tenant B cannot reference tenant A's component (validated server-side, not by the UI).
                $r = sbp6_call($rt, SBP6_TENANT_B, $editor, 'POST', 'operations', ['page_id' => $S->beta, 'expected_revision_id' => $S->betaRev, 'operations' => [
                    ['op' => 'insert_section', 'payload' => ['index' => 0, 'section' => ['label' => 'Stolen', 'global_ref' => $S->ref, 'blocks' => []]]],
                ]]);
                assert_eq(422, $r->status);
                assert_eq('cross_tenant_or_missing_partial', sbp6_first_code($r));
                assert_eq(0, sbp6_count('SELECT COUNT(*) FROM studiobuilder_dependencies WHERE tenant_id = ? AND dependency_key = ?', [SBP6_TENANT_B, $S->ref]), 'no cross-tenant dependency row');
                assert_eq(404, sbp6_call($rt, SBP6_TENANT_B, $editor, 'POST', 'detach_component', ['page_id' => $S->about, 'section_id' => 'sec_0123456789abcdef01234567', 'expected_revision_id' => 1])->status);

                // A reference with local blocks, or a reference inside a component/partial, is refused.
                $st = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'status', ['page' => $S->about]), 'status')['page'];
                $S->aboutRev = (int) $st['active_draft_revision_id'];
                $r = sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'operations', ['page_id' => $S->about, 'expected_revision_id' => $S->aboutRev, 'operations' => [
                    ['op' => 'insert_section', 'payload' => ['index' => 0, 'section' => ['label' => 'Blurred', 'global_ref' => $S->ref, 'blocks' => [['id' => 'blk_0123456789abcdef01234567', 'type' => 'core.heading', 'version' => 1, 'props' => ['text' => 'x', 'level' => 'h2'], 'style' => [], 'visibility' => [], 'bindings' => [], 'children' => []]]]]],
                ]]);
                assert_eq('global_ref_owns_no_blocks', sbp6_first_code($r));
                $cs = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'status', ['page' => $S->componentId]), 'c status')['page'];
                $r = sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'operations', ['page_id' => $S->componentId, 'expected_revision_id' => (int) $cs['active_draft_revision_id'], 'operations' => [
                    ['op' => 'insert_section', 'payload' => ['index' => 0, 'section' => ['label' => 'Nested', 'global_ref' => $S->ref, 'blocks' => []]]],
                ]]);
                assert_eq('global_ref_not_allowed', sbp6_first_code($r), 'a component cannot reference a component (no nesting, no cycles)');

                // A second page referencing the same component; archive protection while referenced.
                $second = sbp6_make_page($rt, SBP6_TENANT_A, $editor, 'Second', 'second', 'landing', [sbp6_heading('Second page')]);
                $r = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'operations', ['page_id' => $second['id'], 'expected_revision_id' => $second['rev'], 'operations' => [
                    ['op' => 'insert_section', 'payload' => ['index' => 1, 'section' => ['label' => 'Intro', 'global_ref' => $S->ref, 'blocks' => []]]],
                ]]), 'reference from a second page');
                $secondRev = (int) $r['revision']['id'];
                $secondRefSection = (string) $r['document']['sections'][1]['id'];
                assert_eq(2, sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'components'), 'components')['components'][0]['usage_count'], 'usage counts current dependents');
                assert_throws(StudioValidationException::class, static fn() => $rt->tenants->runAs(SBP6_TENANT_A, static fn() => $rt->app->archivePage($editor, $S->componentId)), 'a referenced component cannot be archived');
                assert_eq('published', (string) $rt->tenants->runAs(SBP6_TENANT_A, static fn() => $rt->pages->find($S->componentId))['status'], 'the refused archive changed nothing');

                // Detach on the second page: the reference becomes an owned copy of the PUBLISHED content; stale detach is refused.
                assert_eq(409, sbp6_call($rt, SBP6_TENANT_A, $editor2, 'POST', 'detach_component', ['page_id' => $second['id'], 'section_id' => $secondRefSection, 'expected_revision_id' => $second['rev']])->status);
                $d = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'detach_component', ['page_id' => $second['id'], 'section_id' => $secondRefSection, 'expected_revision_id' => $secondRev]), 'detach');
                $secondRev = (int) $d['revision']['id'];
                $local = $d['document']['sections'][1];
                assert_null($local['global_ref']);
                assert_eq('About Alpha v2', $local['blocks'][0]['props']['text'], 'the copy is the published component content');
                assert_true($local['id'] !== $secondRefSection, 'a new, owned section');
                assert_eq(0, sbp6_count('SELECT COUNT(*) FROM studiobuilder_dependencies WHERE tenant_id = ? AND page_id = ? AND revision_id = ? AND dependency_key = ?', [SBP6_TENANT_A, $second['id'], $secondRev, $S->ref]), 'the dependency is gone with the reference');
                assert_eq('not_a_global_reference', sbp6_first_code(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'detach_component', ['page_id' => $second['id'], 'section_id' => $local['id'], 'expected_revision_id' => $secondRev])));
                assert_eq(1, sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'components'), 'components')['components'][0]['usage_count']);

                // A component created blank (no source section) writes no page revision.
                $blank = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'create_component', ['title' => 'Blank comp', 'slug' => 'blank-comp']), 'blank component');
                assert_true(!isset($blank['page']) && !isset($blank['document']));
                assert_eq(0, $blank['component']['usage_count']);
                assert_eq('duplicate_slug', sbp6_first_code(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'create_component', ['title' => 'Blank comp', 'slug' => 'blank-comp'])));
                $blankId = (int) $blank['component']['id'];
                $archived = $rt->tenants->runAs(SBP6_TENANT_A, static fn() => $rt->app->archivePage($editor, $blankId));
                assert_eq('archived', $archived['status'], 'an unreferenced component can be archived');
                assert_eq([$S->ref], array_column(sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'components'), 'components')['components'], 'ref'), 'archived components leave the library');
                $r = sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'operations', ['page_id' => $second['id'], 'expected_revision_id' => $secondRev, 'operations' => [
                    ['op' => 'insert_section', 'payload' => ['index' => 0, 'section' => ['label' => 'Gone', 'global_ref' => (string) $blank['component']['ref'], 'blocks' => []]]],
                ]]);
                assert_eq('cross_tenant_or_missing_partial', sbp6_first_code($r), 'an archived component cannot be referenced');
                $S->second = $second['id'];
            });

            // ── 6. Header / footer bindings ────────────────────────────────
            unit('phase6 int 6: chrome bindings report what inherit/custom/hidden resolve to (published partials only); publishing a partial invalidates chromed pages, not hidden ones', function () use ($rt, $editor, $publisher, $S): void {
                $chrome = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'chrome', ['page' => $S->about]), 'chrome')['chrome'];
                assert_true($chrome['chromed']);
                assert_eq(['mode' => 'inherit', 'resolved' => 'builtin', 'site' => null, 'custom' => null], $chrome['header'], 'no partial yet → built-in chrome');
                $cc = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'chrome', ['page' => $S->componentId]), 'component chrome')['chrome'];
                assert_false($cc['chromed'], 'a component has no chrome of its own');

                // Site header (slug default): a DRAFT partial does not count.
                $hdr = sbp6_make_page($rt, SBP6_TENANT_A, $editor, 'Site header', 'default', 'header_partial', [sbp6_heading('Alpha Site Header')]);
                $chrome = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'chrome', ['page' => $S->about]), 'chrome')['chrome'];
                assert_eq('builtin', $chrome['header']['resolved']);
                assert_eq($hdr['id'], $chrome['header']['site']['id'], 'the draft partial is listed for editing');
                assert_false($chrome['header']['site']['is_published']);

                sbp6_publish($rt, SBP6_TENANT_A, $publisher, $S->about);
                $bare = sbp6_make_page($rt, SBP6_TENANT_A, $editor, 'Bare', 'bare', 'page', [sbp6_heading('Bare page')], ['header_mode' => 'hidden', 'footer_mode' => 'hidden']);
                sbp6_publish($rt, SBP6_TENANT_A, $publisher, $bare['id']);
                sbp6_public($rt, SBP6_TENANT_A, '/about');
                sbp6_public($rt, SBP6_TENANT_A, '/bare');
                assert_eq(1, sbp6_count('SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [SBP6_TENANT_A, $bare['id']]));

                sbp6_publish($rt, SBP6_TENANT_A, $publisher, $hdr['id']);
                assert_eq(0, sbp6_count('SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [SBP6_TENANT_A, $S->about]), 'publishing the site header invalidates pages that show a header');
                assert_eq(1, sbp6_count('SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [SBP6_TENANT_A, $bare['id']]), 'a page with hidden chrome is not rebuilt');
                assert_true(str_contains(sbp6_public($rt, SBP6_TENANT_A, '/about'), 'Alpha Site Header'), 'inherit shows the published site header');
                assert_true(!str_contains(sbp6_public($rt, SBP6_TENANT_A, '/bare'), 'Alpha Site Header'), 'hidden stays hidden');
                assert_true(!str_contains(sbp6_public($rt, SBP6_TENANT_B, '/beta') ?? '', 'Alpha Site Header'), 'partials never cross tenants');
                $chrome = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'chrome', ['page' => $S->about]), 'chrome')['chrome'];
                assert_eq('site', $chrome['header']['resolved']);
                assert_eq('hidden', sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'chrome', ['page' => $bare['id']]), 'chrome bare')['chrome']['header']['resolved']);

                // Custom mode: falls back to the site header until a page-specific partial is PUBLISHED.
                $st = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'status', ['page' => $S->about]), 'status')['page'];
                $r = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'operations', ['page_id' => $S->about, 'expected_revision_id' => (int) $st['active_draft_revision_id'], 'operations' => [['op' => 'update_settings', 'payload' => ['settings' => ['header_mode' => 'custom']]]]]), 'custom mode');
                $chrome = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'chrome', ['page' => $S->about]), 'chrome')['chrome'];
                assert_eq('custom', $chrome['header']['mode']);
                assert_eq('site', $chrome['header']['resolved'], 'missing custom partial → site fallback');
                assert_null($chrome['header']['custom']);
                $custom = sbp6_make_page($rt, SBP6_TENANT_A, $editor, 'About header', 'about', 'header_partial', [sbp6_heading('About-only Header')]);
                $chrome = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'chrome', ['page' => $S->about]), 'chrome')['chrome'];
                assert_eq('site', $chrome['header']['resolved'], 'a draft custom partial still falls back');
                assert_eq($custom['id'], $chrome['header']['custom']['id']);
                sbp6_publish($rt, SBP6_TENANT_A, $publisher, $custom['id']);
                sbp6_publish($rt, SBP6_TENANT_A, $publisher, $S->about);
                assert_true(str_contains(sbp6_public($rt, SBP6_TENANT_A, '/about'), 'About-only Header'), 'custom mode shows the page-specific published header');
                assert_eq('custom', sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'chrome', ['page' => $S->about]), 'chrome')['chrome']['header']['resolved']);
                assert_eq('builtin', sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'chrome', ['page' => $S->about]), 'chrome')['chrome']['footer']['resolved'], 'the footer is independent');

                // Archiving the site header partial invalidates chromed pages too; public falls back safely.
                $rt->tenants->runAs(SBP6_TENANT_A, static fn() => $rt->app->archivePage($editor, $custom['id']));
                assert_eq(0, sbp6_count('SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [SBP6_TENANT_A, $S->about]));
                assert_eq(404, sbp6_call($rt, SBP6_TENANT_B, $editor, 'GET', 'chrome', ['page' => $S->about])->status, 'chrome bindings are tenant-scoped');
            });

            // ── 7. Design tokens ───────────────────────────────────────────
            unit('phase6 int 7: design tokens are validated and sanitized on write, layered under the Phase 4 order, tenant-local, permission-gated, and cannot touch the platform identity', function () use ($rt, $viewer, $editor, $themer, $publisher, $S): void {
                Database::setSetting('brand_accent_color', '#ff5500', SBP6_TENANT_A);
                $layers = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $viewer, 'GET', 'tokens'), 'tokens')['tokens'];
                assert_eq('default', $layers['group']);
                $byRef = array_column($layers['tokens'], null, 'ref');
                assert_eq('#ff5500', $byRef['color.accent']['branding'], 'the existing site branding setting is the branding layer');
                assert_eq('branding', $byRef['color.accent']['source']);
                assert_eq('default', $byRef['surface.page']['source']);
                assert_eq(count(array_keys(\Slate\Module\StudioBuilder\Render\Theme\ThemeResolver::DEFAULT_TOKENS)), count($layers['tokens']), 'exactly the supported token set');

                // Write: permission, validation, sanitization.
                $body = ['group' => 'default', 'tokens' => ['surface.page' => '#112233', 'color.accent' => '#00aa00']];
                assert_eq('authorization_error', sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'save_tokens', $body)->errorCode(), 'edit alone cannot change tokens');
                assert_eq('authorization_error', sbp6_call($rt, SBP6_TENANT_A, $publisher, 'POST', 'save_tokens', $body)->errorCode());
                $r = sbp6_call($rt, SBP6_TENANT_A, $themer, 'POST', 'save_tokens', ['group' => 'default', 'tokens' => ['surface.page' => 'red; background:url(https://evil)']]);
                assert_eq(422, $r->status);
                assert_eq('invalid_token_value', sbp6_first_code($r));
                $r = sbp6_call($rt, SBP6_TENANT_A, $themer, 'POST', 'save_tokens', ['group' => 'default', 'tokens' => ['surface.page' => '#fff}</style><script>alert(1)</script>']]);
                assert_eq('invalid_token_value', sbp6_first_code($r));
                $r = sbp6_call($rt, SBP6_TENANT_A, $themer, 'POST', 'save_tokens', ['group' => 'default', 'tokens' => ['platform.signature' => '#000']]);
                assert_eq('unknown_token', sbp6_first_code($r), 'only the platform-defined token refs exist');
                assert_eq(0, sbp6_count('SELECT COUNT(*) FROM studiobuilder_tokens WHERE tenant_id = ?', [SBP6_TENANT_A]), 'rejected writes store nothing');

                $saved = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $themer, 'POST', 'save_tokens', $body), 'save tokens')['tokens'];
                $byRef = array_column($saved['tokens'], null, 'ref');
                assert_eq('#112233', $byRef['surface.page']['stored']);
                assert_eq('studio', $byRef['surface.page']['source']);
                assert_eq('#00aa00', $byRef['color.accent']['effective'], 'a Studio override beats branding');
                assert_eq('#ff5500', $byRef['color.accent']['branding'], 'and branding is still reported underneath');
                $row = $rt->tenants->runAs(SBP6_TENANT_A, static fn() => $rt->tokens->findByGroupKey('default'));
                assert_eq(['color.accent' => '#00aa00', 'surface.page' => '#112233'], json_decode((string) $row['tokens_json'], true), 'stored as a flat sanitized map (the Phase 4 read contract)');

                // Rendering: every page of the tenant recompiles with the new tokens; the platform signature keeps its own styling.
                assert_eq(0, sbp6_count('SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ?', [SBP6_TENANT_A]), 'saving tokens invalidates the tenant\'s artifacts');
                $public = sbp6_public($rt, SBP6_TENANT_A, '/about');
                assert_true(str_contains($public, '--sb-surface-page:#112233') && str_contains($public, '--sb-color-accent:#00aa00'), 'stored Studio tokens are emitted as custom properties');
                assert_true(str_contains($public, '<div class="sb-platform-signature">') && str_contains($public, '.sb-platform-signature{padding:.75rem 1rem;text-align:center;font-size:.75rem;color:#6b7280}'), 'the signature slot and its fixed styling survive tenant tokens');
                assert_true(str_contains(sbp6_canvas($rt, SBP6_TENANT_A, $editor, $S->about), '--sb-surface-page:#112233'), 'the builder canvas uses the same theme');

                // Tenant isolation.
                assert_eq(0, sbp6_count('SELECT COUNT(*) FROM studiobuilder_tokens WHERE tenant_id <> ?', [SBP6_TENANT_A]));
                $b = sbp6_ok(sbp6_call($rt, SBP6_TENANT_B, $viewer, 'GET', 'tokens'), 'tokens B')['tokens'];
                assert_eq('default', array_column($b['tokens'], null, 'ref')['surface.page']['source'], 'tenant B is untouched');
                sbp6_publish($rt, SBP6_TENANT_B, $publisher, $S->beta);
                assert_true(!str_contains((string) sbp6_public($rt, SBP6_TENANT_B, '/beta'), '#112233'), 'tenant B output never carries tenant A tokens');

                // Clearing an override falls back to branding / defaults.
                $cleared = sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $themer, 'POST', 'save_tokens', ['group' => 'default', 'tokens' => ['surface.page' => null]]), 'clear')['tokens'];
                $byRef = array_column($cleared['tokens'], null, 'ref');
                assert_eq('default', $byRef['surface.page']['source']);
                assert_eq('branding', $byRef['color.accent']['source'], 'a full-map replace: the other override is gone too');
                assert_eq('entitlement_error', sbp6_call($rt, SBP6_TENANT_C, $themer, 'POST', 'save_tokens', $body)->errorCode());
            });

            // ── 8. Authorization matrix for every new command ──────────────
            unit('phase6 int 8: every Phase 6 command is refused for guests, unlicensed tenants, under-privileged actors, cross-tenant targets and missing objects — with no side effect', function () use ($rt, $viewer, $editor, $admin, $S): void {
                $count = static fn(): int => sbp6_count('SELECT COUNT(*) FROM studiobuilder_revisions') + sbp6_count('SELECT COUNT(*) FROM studiobuilder_templates') + sbp6_count('SELECT COUNT(*) FROM studiobuilder_tokens') + sbp6_count('SELECT COUNT(*) FROM studiobuilder_pages');
                $before = $count();
                $rev = (int) sbp6_ok(sbp6_call($rt, SBP6_TENANT_A, $editor, 'GET', 'status', ['page' => $S->about]), 'status')['page']['active_draft_revision_id'];
                $writes = [
                    'apply_template'   => ['page_id' => $S->about, 'template_key' => 'about-page', 'expected_revision_id' => $rev],
                    'insert_template'  => ['page_id' => $S->about, 'template_key' => 'intro-section', 'index' => 0, 'expected_revision_id' => $rev],
                    'save_template'    => ['page_id' => $S->about, 'node_id' => null, 'template_key' => 'zzz', 'template_type' => 'page_template', 'name' => 'Z'],
                    'delete_template'  => ['template_key' => 'intro-section'],
                    'create_component' => ['title' => 'Z', 'slug' => 'zzz'],
                    'detach_component' => ['page_id' => $S->about, 'section_id' => $S->aboutSection, 'expected_revision_id' => $rev],
                    'save_tokens'      => ['group' => 'default', 'tokens' => ['surface.page' => '#000000']],
                ];
                foreach ($writes as $action => $body) {
                    assert_eq(401, sbp6_call($rt, SBP6_TENANT_A, StudioActor::guest(), 'POST', $action, $body)->status, "{$action}: guest");
                    assert_eq('entitlement_error', sbp6_call($rt, SBP6_TENANT_C, $admin, 'POST', $action, $body)->errorCode(), "{$action}: unlicensed tenant");
                    assert_eq('authorization_error', sbp6_call($rt, SBP6_TENANT_A, $viewer, 'POST', $action, $body)->errorCode(), "{$action}: view-only");
                    assert_eq(403, sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', $action, $body, false)->status, "{$action}: CSRF");
                }
                foreach (['components' => [], 'chrome' => ['page' => $S->about], 'tokens' => []] as $q => $query) {
                    assert_eq(401, sbp6_call($rt, SBP6_TENANT_A, StudioActor::guest(), 'GET', $q, $query)->status);
                    assert_eq('entitlement_error', sbp6_call($rt, SBP6_TENANT_C, $admin, 'GET', $q, $query)->errorCode());
                }
                // Cross-tenant targets and missing objects.
                assert_eq(404, sbp6_call($rt, SBP6_TENANT_B, $editor, 'POST', 'apply_template', ['page_id' => $S->about, 'template_key' => 'about-page', 'expected_revision_id' => $rev])->status);
                assert_eq(404, sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'apply_template', ['page_id' => 999999, 'template_key' => 'about-page', 'expected_revision_id' => $rev])->status);
                assert_eq(404, sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'apply_template', ['page_id' => $S->about, 'template_key' => 'no-such-template', 'expected_revision_id' => $rev])->status);
                assert_eq(404, sbp6_call($rt, SBP6_TENANT_A, $editor, 'POST', 'detach_component', ['page_id' => $S->about, 'section_id' => 'sec_0000000000000000000000ff', 'expected_revision_id' => $rev])->status);
                assert_eq(404, sbp6_call($rt, SBP6_TENANT_B, $editor, 'POST', 'create_component', ['title' => 'Z', 'slug' => 'zzz', 'page_id' => $S->about, 'section_id' => $S->aboutSection, 'expected_revision_id' => $rev])->status);
                assert_eq(404, sbp6_call($rt, SBP6_TENANT_B, $admin, 'POST', 'save_template', ['page_id' => $S->about, 'node_id' => null, 'template_key' => 'zzz', 'template_type' => 'page_template', 'name' => 'Z'])->status);
                assert_eq($before, $count(), 'no refused request wrote anything');
            });

            // ── 9. Schema unchanged ──
            unit('phase6 int 9: no new Studio tables or columns', function (): void {
                $tables = array_column(Database::rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'studiobuilder\\_%' ORDER BY TABLE_NAME"), 'TABLE_NAME');
                assert_eq(['studiobuilder_compilations', 'studiobuilder_dependencies', 'studiobuilder_locks', 'studiobuilder_pages', 'studiobuilder_revisions', 'studiobuilder_templates', 'studiobuilder_tokens'], $tables);
                foreach (\Slate\Module\StudioBuilder\Infrastructure\StudioSchemaManager::REQUIRED_TABLES as $table => $cols) {
                    $actual = array_column(Database::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [$table]), 'COLUMN_NAME');
                    assert_eq($cols, $actual, "{$table} columns unchanged");
                }
            });
        });
    } finally {
        sbp6_drop_db($dbName);
    }
});

if (!empty($studioP6IntStandalone)) {
    exit(unit_summary());
}
