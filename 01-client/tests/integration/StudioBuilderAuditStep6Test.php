<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — remaining backend findings (2026-10-26 builder audit, step 6).
 *
 * Real MySQL. An unchanged publish reuses its revision; old autosaves are pruned without touching anything still used;
 * a renamed menu keeps answering to its old key and concurrent menu writes keep each other's changes; the document type
 * follows the page; a draft cannot newly reference an archived component; an applied template gets fresh block ids.
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
    $studioAuditStep6Standalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\ValidatedDocument;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioApiResponse;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Menu\StudioMenus;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\Service\StudioRevisionService;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;

const SBA6_A = 143;

function sba6i_pdo(string $dbName, bool $create): ?\PDO
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $server = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '');
    $root = new \PDO($server . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    if (!$create) {
        return null;
    }
    $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4");
    $pdo = new \PDO($server . ";dbname={$dbName};charset=" . DB_CHARSET, DB_USER, DB_PASS, [
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate([
        '0001_core_init', '0002_identity_core', '0011_login_attempts', '0014_tenant_profiles', '0023_installation_identity',
        '0022_remote_license_cache', '0024_remote_license_metadata', '0025_remote_license_cache_installation_id', '0026_remote_license_cache_signed_payload',
    ]);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

/** Run $fn against $pdo with a fresh plugin loader and a test licence server; everything is put back afterwards. */
function sba6i_with_pdo(\PDO $pdo, callable $fn): void
{
    $statics = [
        [Database::class, 'pdo', $pdo],
        [\PluginLoader::class, 'active', []],
        [\PluginLoader::class, 'activeSlugs', null],
        [\PluginLoader::class, 'booted', false],
    ];
    $saved = [];
    foreach ($statics as [$class, $prop, $value]) {
        $p = new \ReflectionProperty($class, $prop);
        $saved[] = [$p, $p->getValue()];
        $p->setValue(null, $value);
    }
    $env = ['LICENSE_SERVER_URL' => 'https://license.test', 'LICENSE_SERVER_PUBLIC_KEY' => license_test_public_key(), 'LICENSE_PRODUCT' => 'kohevo', 'LICENSE_KEY' => 'test-key'];
    $prevEnv = [];
    foreach ($env as $k => $v) {
        $prevEnv[$k] = $_ENV[$k] ?? null;
        $_ENV[$k] = $v;
    }
    try {
        $fn();
    } finally {
        unset($GLOBALS['SLATE_TENANT_OVERRIDE']);
        StudioMenus::forget();
        foreach ($saved as [$p, $v]) {
            $p->setValue(null, $v);
        }
        foreach ($prevEnv as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }
}

function sba6i_call(StudioRuntime $rt, StudioActor $actor, string $action, array $input): StudioApiResponse
{
    $request = new StudioApiRequest('POST', $action, [], (string) json_encode($input, JSON_PRESERVE_ZERO_FRACTION), 'application/json', true, 'same-origin');
    return $rt->tenants->runAs(SBA6_A, static fn() => (new StudioAuthoringApi($rt->app))->handle($request, $actor));
}

/** @return array<string, mixed> */
function sba6i_ok(StudioApiResponse $r, string $what): array
{
    assert_true($r->isOk(), "{$what}: expected success, got {$r->status} " . substr($r->body(), 0, 600));
    return $r->data();
}

/** A new draft page. @return array{id: int, rev: int} */
function sba6i_page(StudioRuntime $rt, StudioActor $actor, string $slug, string $pageType = 'page'): array
{
    $created = sba6i_ok(sba6i_call($rt, $actor, 'create_page', ['title' => ucfirst($slug), 'slug' => $slug, 'page_type' => $pageType, 'route_mode' => 'standalone']), "create {$slug}");
    return ['id' => (int) $created['page']['id'], 'rev' => (int) $created['page']['active_draft_revision_id']];
}

/** Add a section so the draft changes; returns the new draft revision id. */
function sba6i_edit(StudioRuntime $rt, StudioActor $actor, int $pageId, int $rev, string $label): int
{
    $r = sba6i_ok(sba6i_call($rt, $actor, 'operations', ['page_id' => $pageId, 'expected_revision_id' => $rev, 'operations' => [
        ['op' => 'insert_block', 'payload' => ['parent_id' => null, 'index' => 0, 'block' => ['type' => 'core.container', 'metadata' => ['label' => $label]]]],
    ]]), "edit {$label}");
    return (int) $r['revision']['id'];
}

function sba6i_value(string $sql, array $args = []): mixed
{
    return Database::value($sql, $args);
}

unit('audit step 6 integration: revisions, menus, document type, component references and applied templates (real MySQL)', function (): void {
    $dbName = 'slate_sba6_' . slate_test_ns();
    $pdo = sba6i_pdo($dbName, true);
    try {
        sba6i_with_pdo($pdo, static function (): void {
            $core = InstallationService::provisionCore();
            $iid = (string) $core['installation_id'];
            Database::query('INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\') ON DUPLICATE KEY UPDATE name = VALUES(name)', [SBA6_A, 'Tenant A', 'tenant-a-a6']);
            $seed = static fn(int $t) => license_test_seed_cache($t, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'a6', 'entitlements' => ['studio-builder'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            $seed((int) $core['tenant_id']);
            assert_true(CommercialModuleRegistry::validateSelection(['studio-builder'], ['studio-builder'])['ok']);
            $act = \PluginLoader::installFromDisk('studio-builder');
            assert_true(!empty($act['ok']), 'installFromDisk(studio-builder): ' . json_encode($act));
            $seed(SBA6_A);

            $rt = StudioRuntimeFactory::build();
            $editor    = StudioActor::authenticated(91, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
            $publisher = StudioActor::authenticated(92, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            $inA = static fn(callable $fn) => $rt->tenants->runAs(SBA6_A, $fn);
            $revCount = static fn(int $pageId): int => (int) sba6i_value('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [SBA6_A, $pageId]);

            // ── #24a: publishing an unchanged page reuses its publish revision ──────────────────────────
            $p = sba6i_page($rt, $publisher, 'same-again');
            $draft = sba6i_edit($rt, $publisher, $p['id'], $p['rev'], 'Main');
            $first = sba6i_ok(sba6i_call($rt, $publisher, 'publish', ['page_id' => $p['id'], 'expected_revision_id' => $draft]), 'first publish');
            $pubId = (int) $first['revision']['id'];
            $before = $revCount($p['id']);
            $again = sba6i_ok(sba6i_call($rt, $publisher, 'publish', ['page_id' => $p['id'], 'expected_revision_id' => $pubId]), 'publish again, nothing changed');
            assert_eq($pubId, (int) $again['revision']['id'], 'the same publish revision');
            assert_eq($before, $revCount($p['id']), 'no new copy was stored');
            assert_eq('published', (string) sba6i_value('SELECT status FROM studiobuilder_pages WHERE id = ?', [$p['id']]));
            $changed = sba6i_edit($rt, $publisher, $p['id'], $pubId, 'More');
            $third = sba6i_ok(sba6i_call($rt, $publisher, 'publish', ['page_id' => $p['id'], 'expected_revision_id' => $changed]), 'publish a change');
            assert_true((int) $third['revision']['id'] > $changed, 'a changed document is a new publish revision');

            // ── #24b: old autosaves are pruned; anything still used is kept ─────────────────────────────
            $q = sba6i_page($rt, $editor, 'busy-page');
            $base = sba6i_edit($rt, $editor, $q['id'], $q['rev'], 'Body');
            $json = (string) sba6i_value('SELECT document_json FROM studiobuilder_revisions WHERE id = ?', [$base]);
            $n = (int) sba6i_value('SELECT MAX(revision_number) FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [SBA6_A, $q['id']]);
            $ids = [];
            for ($i = 1; $i <= 45; $i++) {
                $ids[$i] = Database::insert('studiobuilder_revisions', [
                    'tenant_id' => SBA6_A, 'page_id' => $q['id'], 'revision_number' => $n + $i, 'revision_kind' => 'autosave',
                    'document_json' => $json, 'parent_revision_id' => $i > 1 ? $ids[$i - 1] : $base,
                ]);
            }
            Database::query("UPDATE studiobuilder_revisions SET created_at = NOW() - INTERVAL 60 DAY WHERE tenant_id = ? AND page_id = ? AND revision_kind = 'autosave'", [SBA6_A, $q['id']]);
            Database::query('UPDATE studiobuilder_revisions SET created_at = NOW() WHERE id = ?', [$ids[16]]); // old by position, but recent
            // #2 is a restore's target, #3 has a compiled artifact, #4 has dependency rows (which go with it).
            $restore = Database::insert('studiobuilder_revisions', [
                'tenant_id' => SBA6_A, 'page_id' => $q['id'], 'revision_number' => $n + 46, 'revision_kind' => 'rollback', 'document_json' => $json, 'parent_revision_id' => $ids[2],
            ]);
            Database::insert('studiobuilder_compilations', [
                'tenant_id' => SBA6_A, 'page_id' => $q['id'], 'revision_id' => $ids[3], 'compile_mode' => 'preview', 'compiled_html' => '', 'compiled_css' => '', 'content_hash' => str_repeat('0', 64),
            ]);
            Database::insert('studiobuilder_dependencies', ['tenant_id' => SBA6_A, 'page_id' => $q['id'], 'revision_id' => $ids[4], 'node_id' => 'sec_x', 'dependency_type' => 'token_group', 'dependency_key' => 'x']);
            Database::query('UPDATE studiobuilder_pages SET active_draft_revision_id = ? WHERE id = ?', [$restore, $q['id']]);

            $doc = CanonicalJson::decode($json);
            $doc['containers'][0]['metadata']['label'] = 'Body edited';
            $inA(static fn() => $rt->app->saveDraft($editor, $q['id'], $doc, $restore, 'autosave'));
            $left = array_map('intval', array_column(Database::all("SELECT id FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ? AND revision_kind = 'autosave'", [SBA6_A, $q['id']]), 'id'));
            $gone = array_values(array_diff($ids, $left));
            // 46 autosaves: the newest 30 stay (#16-#45 and the new one); of #1-#15, #2 and #3 are still used.
            assert_eq(13, count($gone), 'old autosaves beyond the newest 30 were pruned: ' . json_encode($gone));
            foreach ([2 => 'a restore target', 3 => 'a compiled one', 16 => 'a recent one', 17 => 'one of the newest 30'] as $k => $why) {
                assert_true(in_array($ids[$k], $left, true), "kept: {$why}");
            }
            assert_false(in_array($ids[4], $left, true), 'an unused old autosave is pruned');
            assert_eq(0, (int) sba6i_value('SELECT COUNT(*) FROM studiobuilder_dependencies WHERE revision_id = ?', [$ids[4]]), 'its dependency rows went with it');
            assert_true((int) sba6i_value('SELECT COUNT(*) FROM studiobuilder_revisions WHERE id IN (?, ?)', [$base, $restore]) === 2, 'manual and rollback revisions are never pruned');

            // ── #28: a renamed menu keeps its old key; concurrent writes keep each other's changes ─────
            StudioMenus::forget();
            StudioMenus::save(SBA6_A, 'header', 'Header menu', [['label' => 'Home', 'url' => '/']]);
            $menu = StudioMenus::find(SBA6_A, 'header');
            StudioMenus::save(SBA6_A, 'main', 'Main menu', [['label' => 'Home', 'url' => '/']], 'header', StudioMenus::version($menu));
            assert_eq('main', StudioMenus::find(SBA6_A, 'header')['key'] ?? null, 'a Nav menu block saved with the old key still finds the menu');
            assert_eq(['main'], array_keys(StudioMenus::all(SBA6_A)), 'one menu, under its new key');
            StudioMenus::save(SBA6_A, 'main', 'Main menu', [['label' => 'Home', 'url' => '/'], ['label' => 'About', 'url' => '/about']], 'header');
            assert_eq(2, count(StudioMenus::find(SBA6_A, 'header')['items'] ?? []), 'an edit reached through the old key keeps working');

            // Another request adds a menu after this one read the set: it is not lost when this one writes.
            $other = json_decode((string) Database::setting(StudioMenus::SETTING_KEY, SBA6_A), true);
            $other['menus'][] = ['key' => 'footer', 'name' => 'Footer menu', 'items' => [['label' => 'Privacy', 'url' => '/privacy']]];
            Database::setSetting(StudioMenus::SETTING_KEY, json_encode($other), SBA6_A);
            StudioMenus::save(SBA6_A, 'services', 'Services menu', [['label' => 'Design', 'url' => '/design']]);
            StudioMenus::forget();
            assert_eq(['footer', 'main', 'services'], (static function (array $k): array { sort($k); return $k; })(array_keys(StudioMenus::all(SBA6_A))), 'both writes kept');

            $stale = StudioMenus::version(StudioMenus::find(SBA6_A, 'main'));
            StudioMenus::save(SBA6_A, 'main', 'Main menu', [['label' => 'Home', 'url' => '/']], 'main', $stale);
            $refused = false;
            try {
                StudioMenus::save(SBA6_A, 'main', 'Old tab', [['label' => 'Home', 'url' => '/']], 'main', $stale);
            } catch (\InvalidArgumentException $e) {
                $refused = $e->getCode() === StudioMenus::CONFLICT;
            }
            assert_true($refused, 'a save from a form showing an older version of the menu is refused as a conflict, not silently applied');
            assert_eq('Main menu', StudioMenus::find(SBA6_A, 'main')['name'] ?? null);

            // Submenus: the form's rows (each with its depth) are stored as a tree and read back as the same rows.
            $rows = [['label' => 'Home', 'url' => '/', 'depth' => 0], ['label' => 'Projets', 'url' => '', 'depth' => 0], ['label' => 'Nos actions', 'url' => '/nos-actions/', 'depth' => 1], ['label' => 'Gala', 'url' => '/gala/', 'depth' => 2]];
            StudioMenus::save(SBA6_A, 'main', 'Main menu', $rows, 'main');
            StudioMenus::forget();
            $nested = StudioMenus::find(SBA6_A, 'main');
            assert_eq('Gala', $nested['items'][1]['children'][0]['children'][0]['label'] ?? null, 'a submenu of a submenu survives the round trip');
            assert_eq(array_column($rows, 'depth'), array_column(StudioMenus::flatten($nested['items']), 'depth'), 'and shows in the form as it was entered');

            // ── document_type follows the page, never the client ───────────────────────────────────────
            $t = sba6i_page($rt, $editor, 'typed');
            $tDoc = $inA(static fn() => $rt->app->loadEditorDocument($editor, $t['id']))['document'];
            $tDoc['document_type'] = 'header_partial';
            $saved = sba6i_ok(sba6i_call($rt, $editor, 'save_draft', ['page_id' => $t['id'], 'expected_revision_id' => $t['rev'], 'revision_kind' => 'manual', 'document' => $tDoc]), 'save with a foreign type');
            $stored = CanonicalJson::decode((string) sba6i_value('SELECT document_json FROM studiobuilder_revisions WHERE id = ?', [(int) $saved['revision']['id']]));
            assert_eq('page', $stored['document_type'], 'stored as the page\'s own type');

            // ── a draft cannot newly reference a component that is archived (checked under its row lock) ──
            $comp = $inA(static fn() => $rt->app->createGlobalComponent($editor, 'Promo', 'promo'));
            $compRow = $comp['component'];
            $compUuid = (string) ($compRow['uuid'] ?? $compRow['ref'] ?? '');
            $compId = (int) ($compRow['id'] ?? $compRow['page_id'] ?? 0);
            assert_true($compUuid !== '' && $compId > 0, 'component created: ' . json_encode($compRow));
            sba6i_ok(sba6i_call($rt, $editor, 'archive_page', ['page_id' => $compId]), 'archive the unused component');
            $u = sba6i_page($rt, $editor, 'uses-archived');
            $u['rev'] = sba6i_edit($rt, $editor, $u['id'], $u['rev'], 'Promo');
            $uDoc = $inA(static fn() => $rt->app->loadEditorDocument($editor, $u['id']))['document'];
            $uDoc['containers'][0]['global_ref'] = $compUuid;
            $uDoc['containers'][0]['children'] = [];
            $service = new StudioRevisionService($rt->tenants, $rt->pages, $rt->revisions, $rt->dependencies, $rt->registry);
            $code = '';
            try {
                // No `partial_exists` option: only the revision service's own locked check stands in the way.
                $inA(static fn() => $service->createDraftRevision($u['id'], ValidatedDocument::from($uDoc, $rt->registry, []), $u['rev'], 91));
            } catch (StudioValidationException $e) {
                $code = (string) ($e->errors()[0]['code'] ?? '');
            }
            assert_eq('cross_tenant_or_missing_partial', $code, 'the archived component cannot be referenced');

            // ── an applied template gives the page fresh block ids ─────────────────────────────────────
            $src = sba6i_page($rt, $editor, 'template-source');
            sba6i_edit($rt, $editor, $src['id'], $src['rev'], 'Saved section');
            $admin = StudioActor::authenticated(93, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::ADMIN]);
            $inA(static fn() => $rt->app->saveTemplateFromPage($admin, $src['id'], null, 'a6-page', 'page_template', 'general', 'A6 page', null));
            $tplJson = (string) sba6i_value('SELECT document_json FROM studiobuilder_templates WHERE tenant_id = ? AND template_key = ?', [SBA6_A, 'a6-page']);
            $tplSectionIds = array_column((array) (CanonicalJson::decode($tplJson)['containers'] ?? []), 'id');
            $dest = sba6i_page($rt, $editor, 'template-dest');
            $applied = $inA(static fn() => $rt->app->applyTemplate($editor, 'a6-page', $dest['id'], $dest['rev']));
            $appliedIds = array_column((array) (CanonicalJson::decode((string) $applied['revision']['document_json'])['containers'] ?? []), 'id');
            assert_true($tplSectionIds !== [] && count($appliedIds) === count($tplSectionIds), 'the template\'s sections were applied');
            assert_eq([], array_values(array_intersect($tplSectionIds, $appliedIds)), 'with ids of their own');
        });
    } finally {
        sba6i_pdo($dbName, false);
    }
});

if (!empty($studioAuditStep6Standalone)) {
    exit(unit_summary());
}
