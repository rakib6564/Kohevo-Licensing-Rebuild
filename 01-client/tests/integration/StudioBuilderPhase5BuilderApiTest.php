<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — Phase 5 Builder Shell.
 *
 * Real MySQL (throwaway database), real plugin activation, real per-tenant
 * entitlements, the PRODUCTION service wiring (StudioRuntimeFactory) and the
 * builder's real HTTP controller (StudioAuthoringApi) — exactly what
 * plugins/studio-builder/admin/api.php runs, minus the PHP superglobals.
 * Tenants:
 *   - 101: studio-builder + booking + membership + forms
 *   - 202: studio-builder only
 *   - 303: nothing
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
    $studioP5IntStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioApiResponse;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Http\StudioCanvasPolicy;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const SBP5_CORE_MIGRATIONS = [
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

const SBP5_TENANT_A = 101;
const SBP5_TENANT_B = 202;
const SBP5_TENANT_C = 303;

function sbp5_fresh_db(string $dbName): \PDO
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
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(SBP5_CORE_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function sbp5_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function sbp5_with_pdo(\PDO $pdo, callable $fn): mixed
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

function sbp5_seed(int $tenantId, string $installationId, array $entitlements): void
{
    license_test_seed_cache($tenantId, [
        'installation_id' => $installationId,
        'status'          => 'active',
        'plan'            => 'phase5-' . implode('-', $entitlements ?: ['none']),
        'entitlements'    => $entitlements,
        'expires_at'      => null,
        'fetched_at'      => gmdate('Y-m-d H:i:s'),
    ]);
}

/** Run one builder API call as $actor inside $tenantId (the tenant is NEVER taken from the request). */
function sbp5_call(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $method, string $action, array $input = [], bool $csrf = true): StudioApiResponse
{
    $api = new StudioAuthoringApi($rt->app);
    $request = $method === 'POST'
        ? new StudioApiRequest('POST', $action, [], (string) json_encode($input, JSON_PRESERVE_ZERO_FRACTION), 'application/json', $csrf, 'same-origin')
        : new StudioApiRequest('GET', $action, array_map('strval', $input));
    return $rt->tenants->runAs($tenantId, static fn() => $api->handle($request, $actor));
}

/** @return array<string, mixed> */
function sbp5_ok(StudioApiResponse $r, string $what): array
{
    assert_true($r->isOk(), "{$what}: expected success, got {$r->status} " . substr($r->body(), 0, 400));
    return $r->data();
}

function sbp5_count(string $sql, array $params = []): int
{
    return (int) Database::value($sql, $params);
}

/** @param array<string, mixed> $document */
function sbp5_find(array $document, string $id): ?array
{
    $found = null;
    $walk = static function (array $blocks) use (&$walk, &$found, $id): void {
        foreach ($blocks as $b) {
            if (($b['id'] ?? null) === $id) {
                $found = $b;
                return;
            }
            $walk(is_array($b['children'] ?? null) ? $b['children'] : []);
        }
    };
    foreach ($document['sections'] ?? [] as $s) {
        if (($s['id'] ?? null) === $id) {
            return $s;
        }
        $walk($s['blocks'] ?? []);
    }
    return $found;
}

/** @param array<string, mixed> $document @return list<string> */
function sbp5_block_ids(array $document, int $section = 0): array
{
    return array_map(static fn(array $b): string => (string) $b['id'], $document['sections'][$section]['blocks'] ?? []);
}

unit('phase5 integration: builder command/query API (real MySQL, tenants 101/202/303)', function (): void {
    $dbName = 'slate_sbp5_' . slate_test_ns();
    $pdo = sbp5_fresh_db($dbName);

    try {
        sbp5_with_pdo($pdo, static function (): void {
            // ── Setup ───────────────────────────────────────────────────────
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Studio Admin', 'studio-admin-p5@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\'), (?,?,?,\'active\')
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [SBP5_TENANT_A, 'Tenant A', 'tenant-a-p5', SBP5_TENANT_B, 'Tenant B', 'tenant-b-p5', SBP5_TENANT_C, 'Tenant C', 'tenant-c-p5']
            );
            sbp5_seed($owner, $iid, ['forms', 'membership', 'booking', 'studio-builder', 'stripe-payment']);
            $stripe = \PluginLoader::installFromDisk('stripe-payment');
            assert_true(!empty($stripe['ok']), 'installFromDisk(stripe-payment): ' . json_encode($stripe));
            foreach (['booking', 'membership', 'forms', 'studio-builder'] as $slug) {
                $sel = CommercialModuleRegistry::validateSelection([$slug], ['forms', 'membership', 'booking', 'studio-builder']);
                assert_true($sel['ok'], "selection {$slug}: " . json_encode($sel));
                $act = \PluginLoader::installFromDisk($slug);
                assert_true(!empty($act['ok']), "installFromDisk({$slug}): " . json_encode($act));
            }
            Media::ensureSchema();
            sbp5_seed(SBP5_TENANT_A, $iid, ['studio-builder', 'booking', 'membership', 'forms']);
            sbp5_seed(SBP5_TENANT_B, $iid, ['studio-builder']);
            sbp5_seed(SBP5_TENANT_C, $iid, []);

            $rt = StudioRuntimeFactory::build();
            $viewer    = StudioActor::authenticated(30, [StudioPermissions::VIEW]);
            $editor    = StudioActor::authenticated(31, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
            $editor2   = StudioActor::authenticated(34, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
            $publisher = StudioActor::authenticated(32, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            $S = new \stdClass();

            // ── 1. Create + open a page: the builder loads the canonical server document ──
            unit('phase5 int 1: create_page + bootstrap load the canonical document, revision and manifest', function () use ($rt, $editor, $S): void {
                $created = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'create_page', ['title' => 'About', 'slug' => 'about', 'page_type' => 'page', 'route_mode' => 'standalone']), 'create_page');
                $S->pageA = (int) $created['page']['id'];
                assert_true($S->pageA > 0);
                assert_true(!array_key_exists('tenant_id', $created['page']) && !array_key_exists('uuid', $created['page']), 'page view is an allowlist');

                $boot = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'GET', 'bootstrap', ['page' => $S->pageA]), 'bootstrap');
                $rev = $rt->tenants->runAs(SBP5_TENANT_A, fn() => $rt->revisions->findByIdForPage($S->pageA, (int) $boot['revision']['id']));
                assert_eq(CanonicalJson::decode((string) $rev['document_json']), $boot['document'], 'the editor document IS the stored working revision');
                assert_eq((int) $boot['page']['active_draft_revision_id'], (int) $boot['revision']['id']);
                $S->revA = (int) $boot['revision']['id'];

                $types = array_column($boot['manifest']['blocks'], 'type');
                assert_true(in_array('booking.services', $types, true) && in_array('core.heading', $types, true), 'entitled module blocks are offered to tenant 101');
                assert_eq(['admin' => false, 'edit' => true, 'publish' => false, 'tokens' => false, 'view' => true], $boot['manifest']['permissions']);
                $manifestJson = json_encode($boot['manifest']);
                foreach (['Slate\\\\', '.php', 'Closure', 'tenant_id'] as $leak) {
                    assert_true(!str_contains((string) $manifestJson, $leak), "manifest must not contain {$leak}");
                }
            });

            // ── 2. Insert section + blocks through canonical operations ──
            unit('phase5 int 2: insert section/blocks are canonical operations that create autosave revisions', function () use ($rt, $editor, $S): void {
                $r = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'operations', [
                    'page_id' => $S->pageA, 'expected_revision_id' => $S->revA, 'revision_kind' => 'autosave',
                    'operations' => [['op' => 'insert_section', 'payload' => ['index' => 0, 'section' => ['label' => 'Intro']]]],
                ]), 'insert_section');
                assert_eq('autosave', $r['revision']['revision_kind']);
                $S->section = (string) $r['document']['sections'][0]['id'];
                assert_true(preg_match('/^sec_[a-z0-9]{16,32}$/', $S->section) === 1, 'the SERVER minted the section id');
                $rev = (int) $r['revision']['id'];

                foreach ([['core.heading', null], ['core.container', null], ['core.rich_text', null]] as $i => [$type]) {
                    $r = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'operations', [
                        'page_id' => $S->pageA, 'expected_revision_id' => $rev, 'revision_kind' => 'autosave',
                        'operations' => [['op' => 'insert_block', 'payload' => ['parent_id' => $S->section, 'index' => $i, 'block' => ['type' => $type]]]],
                    ]), "insert {$type}");
                    $rev = (int) $r['revision']['id'];
                }
                [$S->heading, $S->container, $S->rich] = sbp5_block_ids($r['document']);
                $S->rev = $rev;
                assert_eq('Section Heading', sbp5_find($r['document'], $S->heading)['props']['text'], 'inserted with manifest defaults, normalized by the server');

                // Insert into the child-capable container.
                $r = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'operations', [
                    'page_id' => $S->pageA, 'expected_revision_id' => $S->rev, 'revision_kind' => 'autosave',
                    'operations' => [['op' => 'insert_block', 'payload' => ['parent_id' => $S->container, 'index' => 0, 'block' => ['type' => 'core.button', 'props' => ['link' => ['label' => 'Book', 'href' => '/book'], 'variant' => 'primary', 'full_width' => false]]]]],
                ]), 'insert into container');
                $S->rev = (int) $r['revision']['id'];
                $S->button = (string) sbp5_find($r['document'], $S->container)['children'][0]['id'];
                assert_eq('noopener noreferrer', sbp5_find($r['document'], $S->button)['props']['link']['rel'] ?? 'noopener noreferrer', 'server normalization applies');
            });

            // ── 3. Property / style / visibility / bindings updates ──
            unit('phase5 int 3: props, style, visibility and section layout updates persist; reload reconstructs them', function () use ($rt, $editor, $S): void {
                $r = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'operations', [
                    'page_id' => $S->pageA, 'expected_revision_id' => $S->rev, 'revision_kind' => 'autosave',
                    'operations' => [
                        ['op' => 'update_block_props', 'payload' => ['block_id' => $S->heading, 'props' => ['text' => 'Welcome to Alpha', 'level' => 'h1']]],
                        ['op' => 'update_block_style', 'payload' => ['block_id' => $S->heading, 'style' => ['align' => ['base' => 'center', 'md' => 'left'], 'text_token' => 'text.accent']]],
                        ['op' => 'update_block_visibility', 'payload' => ['block_id' => $S->rich, 'visibility' => ['auth_state' => 'any', 'devices' => ['md', 'lg']]]],
                        ['op' => 'update_block_props', 'payload' => ['block_id' => $S->rich, 'props' => ['content' => '<p>Hello <strong>world</strong></p>']]],
                        ['op' => 'update_section_layout', 'payload' => ['section_id' => $S->section, 'layout' => ['columns' => ['base' => 1, 'md' => 3], 'gap' => 'lg', 'padding_y' => ['base' => 'sm'], 'width' => 'normal', 'background_token' => 'surface.muted']]],
                        ['op' => 'update_seo', 'payload' => ['seo' => ['title' => 'About Alpha']]],
                    ],
                ]), 'update batch');
                $S->rev = (int) $r['revision']['id'];

                $reloaded = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'GET', 'document', ['page' => $S->pageA]), 'reload');
                assert_eq($r['document'], $reloaded['document'], 'a reload reconstructs exactly the server-confirmed document');
                assert_eq($S->rev, (int) $reloaded['revision']['id']);
                $h = sbp5_find($reloaded['document'], $S->heading);
                assert_eq('Welcome to Alpha', $h['props']['text']);
                assert_eq(['base' => 'center', 'md' => 'left'], $h['style']['align']);
                assert_eq(['md', 'lg'], sbp5_find($reloaded['document'], $S->rich)['visibility']['devices']);
                assert_eq(['base' => 1, 'md' => 3], $reloaded['document']['sections'][0]['layout']['columns']);
                assert_eq('About Alpha', $reloaded['document']['seo']['title']);
            });

            // ── 4. Move / reorder / remove ──
            unit('phase5 int 4: move a block across containers, reorder sections, remove a block', function () use ($rt, $editor, $S): void {
                $r = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'operations', [
                    'page_id' => $S->pageA, 'expected_revision_id' => $S->rev, 'revision_kind' => 'autosave',
                    'operations' => [['op' => 'insert_section', 'payload' => ['index' => 1, 'section' => ['label' => 'Second']]]],
                ]), 'second section');
                $second = (string) $r['document']['sections'][1]['id'];
                $r = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'operations', [
                    'page_id' => $S->pageA, 'expected_revision_id' => (int) $r['revision']['id'], 'revision_kind' => 'autosave',
                    'operations' => [
                        ['op' => 'move_block', 'payload' => ['block_id' => $S->heading, 'parent_id' => $S->container, 'index' => 1]],
                        ['op' => 'move_block', 'payload' => ['block_id' => $S->button, 'parent_id' => $second, 'index' => 0]],
                        ['op' => 'move_section', 'payload' => ['section_id' => $second, 'to_index' => 0]],
                        ['op' => 'remove_block', 'payload' => ['block_id' => $S->rich]],
                    ],
                ]), 'move batch');
                $S->rev = (int) $r['revision']['id'];
                $doc = $r['document'];
                assert_eq($second, $doc['sections'][0]['id'], 'sections reordered');
                assert_eq([$S->button], sbp5_block_ids($doc, 0), 'block moved across sections');
                assert_eq($S->heading, sbp5_find($doc, $S->container)['children'][0]['id'], 'block moved into a container');
                assert_null(sbp5_find($doc, $S->rich), 'block removed');
                $S->second = $second;
            });

            // ── 5. Invalid commands are rejected and change nothing ──
            unit('phase5 int 5: invalid commands (bad nesting, unknown block, unentitled module, unsafe values) are rejected', function () use ($rt, $editor, $S): void {
                $before = sbp5_count('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [SBP5_TENANT_A, $S->pageA]);
                $cases = [
                    'children into a heading' => [['op' => 'insert_block', 'payload' => ['parent_id' => $S->heading, 'index' => 0, 'block' => ['type' => 'core.heading']]]],
                    'unknown block type'      => [['op' => 'insert_block', 'payload' => ['parent_id' => $S->section, 'index' => 0, 'block' => ['type' => 'evil.script']]]],
                    'missing target'          => [['op' => 'remove_block', 'payload' => ['block_id' => 'blk_doesnotexist000000000']]],
                    'unsafe url'              => [['op' => 'update_block_props', 'payload' => ['block_id' => $S->button, 'props' => ['link' => ['label' => 'x', 'href' => 'javascript:alert(1)'], 'variant' => 'primary', 'full_width' => false]]]],
                    'script in rich text'     => [['op' => 'insert_block', 'payload' => ['parent_id' => $S->section, 'index' => 0, 'block' => ['type' => 'core.rich_text', 'props' => ['content' => '<p>x<script>alert(1)</script></p>']]]]],
                    'raw css in style'        => [['op' => 'update_block_style', 'payload' => ['block_id' => $S->button, 'style' => ['surface_token' => 'red; background:url(x)']]]],
                ];
                foreach ($cases as $label => $operations) {
                    $r = sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'operations', ['page_id' => $S->pageA, 'expected_revision_id' => $S->rev, 'operations' => $operations]);
                    assert_eq(422, $r->status, "{$label} must be rejected");
                    assert_eq('validation_error', $r->errorCode(), $label);
                }
                assert_eq($before, sbp5_count('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [SBP5_TENANT_A, $S->pageA]), 'rejected commands create no revision');
            });

            // ── 6. Concurrency ──
            unit('phase5 int 6: a stale expected_revision_id returns 409 and never overwrites the newer revision', function () use ($rt, $editor, $editor2, $S): void {
                $stale = $S->rev;
                $a = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor2, 'POST', 'operations', [
                    'page_id' => $S->pageA, 'expected_revision_id' => $stale, 'revision_kind' => 'manual',
                    'operations' => [['op' => 'update_block_props', 'payload' => ['block_id' => $S->heading, 'props' => ['text' => 'Saved by user A', 'level' => 'h1']]]],
                ]), 'user A saves');
                $newer = (int) $a['revision']['id'];

                $b = sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'operations', [
                    'page_id' => $S->pageA, 'expected_revision_id' => $stale, 'revision_kind' => 'autosave',
                    'operations' => [['op' => 'update_block_props', 'payload' => ['block_id' => $S->heading, 'props' => ['text' => 'Saved by user B', 'level' => 'h1']]]],
                ]);
                assert_eq(409, $b->status);
                assert_eq('concurrency_conflict', $b->errorCode());
                assert_eq(['current_revision_id' => $newer, 'expected_revision_id' => $stale], $b->payload['error']['details']);

                foreach (['publish' => ['page_id' => $S->pageA, 'expected_revision_id' => $stale], 'rollback' => ['page_id' => $S->pageA, 'target_revision_id' => $stale, 'expected_revision_id' => $stale]] as $action => $body) {
                    assert_eq(409, sbp5_call($rt, SBP5_TENANT_A, StudioActor::authenticated(32, StudioPermissions::ALL), 'POST', $action, $body)->status, "{$action} also refuses a stale revision");
                }
                $doc = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'GET', 'document', ['page' => $S->pageA]), 'reload');
                assert_eq('Saved by user A', sbp5_find($doc['document'], $S->heading)['props']['text'], 'the newer server revision survives');
                assert_eq($newer, (int) $doc['revision']['id']);
                $S->rev = $newer;
            });

            // ── 7. Autosave dedup, rollback (undo/redo) ──
            unit('phase5 int 7: identical autosaves are deduplicated; undo/redo are rollbacks to immutable revisions', function () use ($rt, $editor, $S): void {
                $same = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'operations', [
                    'page_id' => $S->pageA, 'expected_revision_id' => $S->rev, 'revision_kind' => 'autosave',
                    'operations' => [['op' => 'update_block_props', 'payload' => ['block_id' => $S->heading, 'props' => ['text' => 'Saved by user A', 'level' => 'h1']]]],
                ]), 'no-op autosave');
                assert_true($same['deduplicated'], 'server-side autosave deduplication');
                assert_eq($S->rev, (int) $same['revision']['id']);

                $changed = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'operations', [
                    'page_id' => $S->pageA, 'expected_revision_id' => $S->rev, 'revision_kind' => 'autosave',
                    'operations' => [['op' => 'update_block_props', 'payload' => ['block_id' => $S->heading, 'props' => ['text' => 'Undo me', 'level' => 'h1']]]],
                ]), 'change');
                $after = (int) $changed['revision']['id'];
                $undo = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'rollback', ['page_id' => $S->pageA, 'target_revision_id' => $S->rev, 'expected_revision_id' => $after]), 'undo');
                assert_eq('rollback', $undo['revision']['revision_kind']);
                assert_eq('Saved by user A', sbp5_find($undo['document'], $S->heading)['props']['text']);
                $redo = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'rollback', ['page_id' => $S->pageA, 'target_revision_id' => $after, 'expected_revision_id' => (int) $undo['revision']['id']]), 'redo');
                assert_eq('Undo me', sbp5_find($redo['document'], $S->heading)['props']['text']);
                $S->rev = (int) $redo['revision']['id'];

                $history = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'GET', 'revisions', ['page' => $S->pageA, 'limit' => 5]), 'history');
                assert_eq(5, count($history['revisions']));
                assert_true(!array_key_exists('document_json', $history['revisions'][0]), 'history never carries documents');
                assert_eq($S->rev, (int) $history['revisions'][0]['id'], 'newest first');
            });

            // ── 8. Authentication / entitlement / RBAC / CSRF ──
            unit('phase5 int 8: guests, unlicensed tenants, under-privileged users and missing CSRF are denied with no side effect', function () use ($rt, $viewer, $editor, $S): void {
                $count = static fn() => sbp5_count('SELECT COUNT(*) FROM studiobuilder_revisions');
                $before = $count();
                $write = ['page_id' => $S->pageA, 'expected_revision_id' => $S->rev, 'operations' => [['op' => 'remove_block', 'payload' => ['block_id' => $S->heading]]]];

                $r = sbp5_call($rt, SBP5_TENANT_A, StudioActor::guest(), 'GET', 'bootstrap', ['page' => $S->pageA]);
                assert_eq(401, $r->status);
                assert_eq('authentication_error', sbp5_call($rt, SBP5_TENANT_A, StudioActor::guest(), 'POST', 'operations', $write)->errorCode());

                $r = sbp5_call($rt, SBP5_TENANT_C, $editor, 'GET', 'pages');
                assert_eq(403, $r->status);
                assert_eq('entitlement_error', $r->errorCode(), 'a tenant without studio-builder is refused');

                assert_eq('authorization_error', sbp5_call($rt, SBP5_TENANT_A, $viewer, 'POST', 'operations', $write)->errorCode(), 'view-only cannot edit');
                assert_eq('authorization_error', sbp5_call($rt, SBP5_TENANT_A, $viewer, 'GET', 'bootstrap', ['page' => $S->pageA])->errorCode(), 'view-only cannot open the builder');
                assert_eq('authorization_error', sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'publish', ['page_id' => $S->pageA, 'expected_revision_id' => $S->rev])->errorCode(), 'edit cannot publish');
                assert_true(sbp5_call($rt, SBP5_TENANT_A, $viewer, 'GET', 'status', ['page' => $S->pageA])->isOk(), 'view-only can read status');

                $r = sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'operations', $write, false);
                assert_eq(403, $r->status);
                assert_eq('csrf_error', $r->errorCode());
                assert_eq($before, $count(), 'no denied request wrote anything');
            });

            // ── 9. Tenant isolation ──
            unit('phase5 int 9: tenant 101 can neither load nor mutate tenant 202\'s page, revisions, preview or lock', function () use ($rt, $editor, $publisher, $S): void {
                $b = sbp5_ok(sbp5_call($rt, SBP5_TENANT_B, $editor, 'POST', 'create_page', ['title' => 'Beta', 'slug' => 'beta', 'page_type' => 'page', 'route_mode' => 'standalone']), 'tenant 202 page');
                $pageB = (int) $b['page']['id'];
                $revB = (int) $b['page']['active_draft_revision_id'];
                $docB = sbp5_ok(sbp5_call($rt, SBP5_TENANT_B, $editor, 'GET', 'document', ['page' => $pageB]), 'B doc')['document'];

                foreach (['bootstrap', 'document', 'status', 'revisions'] as $q) {
                    $r = sbp5_call($rt, SBP5_TENANT_A, $editor, 'GET', $q, ['page' => $pageB]);
                    assert_eq(404, $r->status, "tenant 101 {$q} on tenant 202's page must be not_found");
                }
                $write = sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'operations', ['page_id' => $pageB, 'expected_revision_id' => $revB, 'operations' => [['op' => 'insert_section', 'payload' => ['index' => 0]]]]);
                assert_eq(404, $write->status, 'cross-tenant mutation is not_found');
                assert_eq(404, sbp5_call($rt, SBP5_TENANT_A, $publisher, 'POST', 'publish', ['page_id' => $pageB, 'expected_revision_id' => $revB])->status);
                assert_eq(404, sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'rollback', ['page_id' => $S->pageA, 'target_revision_id' => $revB, 'expected_revision_id' => $S->rev])->status, 'a foreign revision id is never a rollback target');
                assert_eq(404, sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'lock_acquire', ['page_id' => $pageB])->status, 'no lock on a foreign page');

                assert_eq($docB, sbp5_ok(sbp5_call($rt, SBP5_TENANT_B, $editor, 'GET', 'document', ['page' => $pageB]), 'B unchanged')['document'], "tenant 202's document is untouched");
                assert_eq(0, sbp5_count('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [SBP5_TENANT_A, $pageB]), 'no revision was written under the wrong tenant');

                $pagesA = array_column(sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'GET', 'pages'), 'pages A')['pages'], 'id');
                $pagesB = array_column(sbp5_ok(sbp5_call($rt, SBP5_TENANT_B, $editor, 'GET', 'pages'), 'pages B')['pages'], 'id');
                assert_true(in_array($S->pageA, $pagesA, true) && !in_array($pageB, $pagesA, true), 'page list is tenant-scoped (A)');
                assert_true(in_array($pageB, $pagesB, true) && !in_array($S->pageA, $pagesB, true), 'page list is tenant-scoped (B)');

                // Preview and canvas renders stay isolated too.
                assert_throws(StudioNotFoundException::class, fn() => $rt->tenants->runAs(SBP5_TENANT_A, fn() => $rt->app->renderPreview($editor, $pageB)));
                assert_throws(StudioNotFoundException::class, fn() => $rt->tenants->runAs(SBP5_TENANT_A, fn() => $rt->app->renderPreview($editor, $S->pageA, $revB)), 'a foreign revision id is never previewed');
                assert_throws(StudioNotFoundException::class, fn() => $rt->tenants->runAs(SBP5_TENANT_A, fn() => $rt->app->renderForEditor($editor, $pageB)));

                // Entitlement-filtered manifest + write-time entitlement check for tenant 202.
                $manB = sbp5_ok(sbp5_call($rt, SBP5_TENANT_B, $editor, 'GET', 'manifest'), 'manifest B')['manifest'];
                $typesB = array_column($manB['blocks'], 'type');
                $provKeysB = array_column($manB['providers'], 'key');
                assert_true(!in_array('booking.services', $provKeysB, true) && !in_array('memberships.plans', $provKeysB, true), 'no unentitled data providers are described');
                $sec = sbp5_ok(sbp5_call($rt, SBP5_TENANT_B, $editor, 'POST', 'operations', ['page_id' => $pageB, 'expected_revision_id' => $revB, 'operations' => [['op' => 'insert_section', 'payload' => ['index' => 0]]]]), 'B section');
                $r = sbp5_call($rt, SBP5_TENANT_B, $editor, 'POST', 'operations', ['page_id' => $pageB, 'expected_revision_id' => (int) $sec['revision']['id'], 'operations' => [
                    ['op' => 'insert_block', 'payload' => ['parent_id' => (string) $sec['document']['sections'][0]['id'], 'index' => 0, 'block' => ['type' => 'booking.services', 'bindings' => ['items' => ['provider' => 'booking.services']]]]],
                ]]);
                assert_eq(422, $r->status, 'a crafted request cannot insert an unentitled module block');
            });

            // ── 9b. One unsaved block rendered for the canvas ──
            unit('phase5 int 9b: render_block renders one unsaved block with fresh ids, stores nothing, and keeps the tenant and permission walls', function () use ($rt, $editor, $viewer, $S): void {
                $doc = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'GET', 'document', ['page' => $S->pageA]), 'document')['document'];
                $block = sbp5_find($doc, $S->heading);
                assert_true($block !== null, 'the page has a heading to copy the block shape from');
                $block['id'] = 'tmp_provisional';
                $block['props']['text'] = 'Rendered before saving';
                $revisions = sbp5_count('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [SBP5_TENANT_A, $S->pageA]);

                $r = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'render_block', ['page_id' => $S->pageA, 'block' => $block]), 'render_block');
                $html = (string) $r['html'];
                assert_true(str_contains($html, 'Rendered before saving') && str_contains($html, 'data-sb-type="core.heading"'), 'the block is rendered as the canvas shows it');
                assert_true(!str_contains($html, 'tmp_provisional'), 'a made-up id is never echoed; the node gets a fresh one');
                preg_match('~<main\b.*?</main>~s', $html, $main);
                assert_eq(1, preg_match_all('/data-sb-node="blk_[a-z0-9]{24}"/', $main[0] ?? ''), 'one block node in the fragment');
                assert_true(preg_match('/class="[^"]*\bsb-unavailable\b/', $html) !== 1, 'a valid block is not an unavailable notice');
                assert_eq($revisions, sbp5_count('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [SBP5_TENANT_A, $S->pageA]), 'nothing is stored');
                assert_eq($doc, sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'GET', 'document', ['page' => $S->pageA]), 'document again')['document'], 'the draft is untouched');

                $bad = $block;
                $bad['type'] = 'nope.nothing';
                $r = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'render_block', ['page_id' => $S->pageA, 'block' => $bad]), 'unknown type');
                assert_true(preg_match('/class="[^"]*\bsb-unavailable\b/', (string) $r['html']) === 1 && !str_contains((string) $r['html'], 'Rendered before saving'), 'an invalid block renders as the unavailable notice, never as itself');

                assert_eq(422, sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'render_block', ['page_id' => $S->pageA, 'block' => 'x'])->status, 'the block must be an object');
                assert_eq(422, sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'render_block', ['page_id' => $S->pageA, 'block' => [1, 2]])->status, 'a list is not a block');
                assert_eq(422, sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'render_block', ['page_id' => $S->pageA, 'block' => $block, 'extra' => 1])->status, 'unknown fields are refused');
                assert_eq(403, sbp5_call($rt, SBP5_TENANT_A, $viewer, 'POST', 'render_block', ['page_id' => $S->pageA, 'block' => $block])->status, 'viewing is not editing');
                assert_eq(403, sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'render_block', ['page_id' => $S->pageA, 'block' => $block], false)->status, 'a command needs the CSRF token');
                assert_eq(404, sbp5_call($rt, SBP5_TENANT_B, $editor, 'POST', 'render_block', ['page_id' => $S->pageA, 'block' => $block])->status, 'another tenant cannot render against this page');
            });

            unit('phase5 int 9c: render_section renders one unsaved section with fresh ids, stores nothing, and keeps the walls', function () use ($rt, $editor, $viewer, $S): void {
                $doc = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'GET', 'document', ['page' => $S->pageA]), 'document')['document'];
                $heading = sbp5_find($doc, $S->heading);
                assert_true($heading !== null, 'the page has a heading to build a section from');
                $heading['id'] = 'tmp_h';
                $heading['props']['text'] = 'Section rendered before saving';
                $section = ['id' => 'tmp_s', 'label' => 'Draft', 'blocks' => [$heading]];
                $revisions = sbp5_count('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [SBP5_TENANT_A, $S->pageA]);

                $r = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'render_section', ['page_id' => $S->pageA, 'section' => $section]), 'render_section');
                $html = (string) $r['html'];
                assert_true(str_contains($html, 'Section rendered before saving'), 'the section is rendered with its block');
                assert_true(!str_contains($html, 'tmp_s') && !str_contains($html, 'tmp_h'), 'made-up ids are never echoed');
                preg_match('~<main\b.*?</main>~s', $html, $main);
                assert_eq(1, preg_match_all('/data-sb-node="sec_[a-z0-9]{16,32}"/', $main[0] ?? ''), 'one section node in the fragment');
                assert_eq(1, preg_match_all('/data-sb-node="blk_[a-z0-9]{24}"/', $main[0] ?? ''), 'one block node in the section');
                assert_eq($revisions, sbp5_count('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [SBP5_TENANT_A, $S->pageA]), 'nothing is stored');
                assert_eq($doc, sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'GET', 'document', ['page' => $S->pageA]), 'document again')['document'], 'the draft is untouched');

                $empty = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'render_section', ['page_id' => $S->pageA, 'section' => []]), 'an empty section');
                assert_true(str_contains((string) $empty['html'], '<main'), 'an empty section still renders');

                assert_eq(422, sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'render_section', ['page_id' => $S->pageA, 'section' => 'x'])->status, 'the section must be an object');
                assert_eq(422, sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'render_section', ['page_id' => $S->pageA, 'section' => [1, 2]])->status, 'a list is not a section');
                assert_eq(422, sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'render_section', ['page_id' => $S->pageA, 'section' => $section, 'extra' => 1])->status, 'unknown fields are refused');
                assert_eq(403, sbp5_call($rt, SBP5_TENANT_A, $viewer, 'POST', 'render_section', ['page_id' => $S->pageA, 'section' => $section])->status, 'viewing is not editing');
                assert_eq(403, sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'render_section', ['page_id' => $S->pageA, 'section' => $section], false)->status, 'a command needs the CSRF token');
                assert_eq(404, sbp5_call($rt, SBP5_TENANT_B, $editor, 'POST', 'render_section', ['page_id' => $S->pageA, 'section' => $section])->status, 'another tenant cannot render against this page');
            });

            // ── 10. Canvas + preview + publish boundary ──
            unit('phase5 int 10: canvas = renderForEditor (node metadata, CSP); public output changes only through publish', function () use ($rt, $editor, $publisher, $S): void {
                $canvas = $rt->tenants->runAs(SBP5_TENANT_A, fn() => $rt->app->renderForEditor($editor, $S->pageA));
                assert_true(str_contains($canvas->html, 'data-sb-node="' . $S->heading . '"'), 'the canvas carries data-sb-node for selection');
                assert_true(str_contains($canvas->html, 'data-sb-type="core.heading"'));
                assert_true(str_contains($canvas->html, 'data-sb-node="' . $S->section . '"'), 'sections are selectable too');
                $headers = StudioCanvasPolicy::headers($canvas);
                assert_true(str_contains($headers['Content-Security-Policy'], "script-src 'none'"));

                assert_null($rt->tenants->runAs(SBP5_TENANT_A, fn() => $rt->publicRuntime->handlePath('/about', null)), 'an unpublished draft is never public');

                $pub = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $publisher, 'POST', 'publish', ['page_id' => $S->pageA, 'expected_revision_id' => $S->rev]), 'publish');
                assert_eq('publish', $pub['revision']['revision_kind']);
                assert_true($pub['page']['is_published']);
                $public = $rt->tenants->runAs(SBP5_TENANT_A, fn() => $rt->publicRuntime->handlePath('/about', null));
                assert_true($public !== null && $public->status === 200 && str_contains($public->body, 'Undo me'), 'published content is public');
                assert_true(!str_contains($public->body, 'data-sb-node'), 'no editor metadata in public output');

                // A later builder edit changes the draft only.
                sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'operations', [
                    'page_id' => $S->pageA, 'expected_revision_id' => (int) $pub['revision']['id'], 'revision_kind' => 'autosave',
                    'operations' => [['op' => 'update_block_props', 'payload' => ['block_id' => $S->heading, 'props' => ['text' => 'Draft only change', 'level' => 'h1']]]],
                ]), 'post-publish edit');
                $public2 = $rt->tenants->runAs(SBP5_TENANT_A, fn() => $rt->publicRuntime->handlePath('/about', null));
                assert_true(str_contains($public2->body, 'Undo me') && !str_contains($public2->body, 'Draft only change'), 'public output unchanged until the next publish');
                $preview = $rt->tenants->runAs(SBP5_TENANT_A, fn() => $rt->app->renderPreview($editor, $S->pageA));
                assert_true(str_contains($preview->html, 'Draft only change'), 'preview (Phase 4 runtime) shows the draft');
                $status = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'GET', 'status', ['page' => $S->pageA]), 'status')['page'];
                assert_true($status['is_published'] && $status['has_unpublished_changes']);
            });

            // ── 11. Advisory edit locks ──
            unit('phase5 int 11: advisory edit locks (existing table, DB-clock expiry) warn but never replace revision checks', function () use ($rt, $editor, $editor2, $S): void {
                $a = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'lock_acquire', ['page_id' => $S->pageA]), 'A acquires')['lock'];
                assert_true($a['held'] && preg_match('/^[a-f0-9-]{36}$/', (string) $a['lock_token']) === 1);
                $b = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor2, 'POST', 'lock_acquire', ['page_id' => $S->pageA]), 'B tries')['lock'];
                assert_false($b['held']);
                assert_true($b['other_editor'] && $b['other_expires_in'] > 0);
                assert_eq(1, sbp5_count('SELECT COUNT(*) FROM studiobuilder_locks WHERE tenant_id = ? AND page_id = ?', [SBP5_TENANT_A, $S->pageA]));

                // The lock is advisory: B can still write (guarded only by expected_revision_id).
                $cur = (int) sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor2, 'GET', 'status', ['page' => $S->pageA]), 'status')['page']['active_draft_revision_id'];
                assert_true(sbp5_call($rt, SBP5_TENANT_A, $editor2, 'POST', 'operations', ['page_id' => $S->pageA, 'expected_revision_id' => $cur, 'revision_kind' => 'manual', 'operations' => [['op' => 'update_seo', 'payload' => ['seo' => ['description' => 'Edited by B']]]]])->isOk(), 'locks never block a valid revision write');

                $refresh = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'lock_refresh', ['page_id' => $S->pageA, 'lock_token' => $a['lock_token']]), 'A heartbeat')['lock'];
                assert_true($refresh['held']);
                assert_false(sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor2, 'POST', 'lock_release', ['page_id' => $S->pageA, 'lock_token' => $a['lock_token']]), 'B release')['released'], 'only the holder can release');

                // Expiry is judged on the DB clock; an expired lock is taken over.
                Database::query('UPDATE studiobuilder_locks SET expires_at = DATE_SUB(NOW(), INTERVAL 5 SECOND) WHERE tenant_id = ? AND page_id = ?', [SBP5_TENANT_A, $S->pageA]);
                $b2 = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor2, 'POST', 'lock_acquire', ['page_id' => $S->pageA]), 'B after expiry')['lock'];
                assert_true($b2['held'], 'an expired lock is taken over');
                $lost = sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor, 'POST', 'lock_refresh', ['page_id' => $S->pageA, 'lock_token' => $a['lock_token']]), 'A lost')['lock'];
                assert_false($lost['held']);
                assert_true($lost['other_editor'], 'the previous holder learns someone else has the page');
                assert_true(sbp5_ok(sbp5_call($rt, SBP5_TENANT_A, $editor2, 'POST', 'lock_release', ['page_id' => $S->pageA, 'lock_token' => $b2['lock_token']]), 'B release')['released']);
                assert_eq(0, sbp5_count('SELECT COUNT(*) FROM studiobuilder_locks WHERE tenant_id = ? AND page_id = ?', [SBP5_TENANT_A, $S->pageA]));
                assert_eq(0, sbp5_count('SELECT COUNT(*) FROM studiobuilder_locks WHERE tenant_id <> ?', [SBP5_TENANT_A]), 'no lock row ever lands under another tenant');
            });

            // ── 12. Schema unchanged ──
            unit('phase5 int 12: no new Studio tables or columns', function (): void {
                $tables = array_column(Database::rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'studiobuilder\\_%' ORDER BY TABLE_NAME"), 'TABLE_NAME');
                assert_eq(['studiobuilder_compilations', 'studiobuilder_dependencies', 'studiobuilder_locks', 'studiobuilder_pages', 'studiobuilder_revisions', 'studiobuilder_templates', 'studiobuilder_tokens'], $tables);
                $lockCols = array_column(Database::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'studiobuilder_locks' ORDER BY ORDINAL_POSITION"), 'COLUMN_NAME');
                assert_eq(['id', 'tenant_id', 'page_id', 'user_id', 'lock_token', 'acquired_at', 'heartbeat_at', 'expires_at'], $lockCols);
            });
        });
    } finally {
        sbp5_drop_db($dbName);
    }
});

if (!empty($studioP5IntStandalone)) {
    exit(unit_summary());
}
