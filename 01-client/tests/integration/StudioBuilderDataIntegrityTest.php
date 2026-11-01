<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — data integrity (audit step 3, 2026-10-26).
 *
 * Real MySQL. Page lists are not cut short by archived pages; archiving frees the page's address; an archived page takes
 * no new draft, publish or restore; Restore goes through the revision service; and the site keeps one homepage.
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
    $studioDataIntegrityStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioApiResponse;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;

const SBDI_A = 141;

function sbdi_pdo(string $dbName, bool $create): ?\PDO
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
function sbdi_with_pdo(\PDO $pdo, callable $fn): void
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

function sbdi_call(StudioRuntime $rt, StudioActor $actor, string $action, array $input): StudioApiResponse
{
    $request = new StudioApiRequest('POST', $action, [], (string) json_encode($input, JSON_PRESERVE_ZERO_FRACTION), 'application/json', true, 'same-origin');
    return $rt->tenants->runAs(SBDI_A, static fn() => (new StudioAuthoringApi($rt->app))->handle($request, $actor));
}

/** @return array<string, mixed> */
function sbdi_ok(StudioApiResponse $r, string $what): array
{
    assert_true($r->isOk(), "{$what}: expected success, got {$r->status} " . substr($r->body(), 0, 600));
    return $r->data();
}

function sbdi_code(StudioApiResponse $r): string
{
    return (string) ($r->payload['error']['details']['errors'][0]['code'] ?? $r->payload['error']['code'] ?? '');
}

/** A new draft page. @return array{id: int, rev: int} */
function sbdi_page(StudioRuntime $rt, StudioActor $actor, string $slug, string $routeMode = 'standalone'): array
{
    $created = sbdi_ok(sbdi_call($rt, $actor, 'create_page', ['title' => ucfirst($slug), 'slug' => $slug, 'page_type' => 'page', 'route_mode' => $routeMode]), "create {$slug}");
    return ['id' => (int) $created['page']['id'], 'rev' => (int) $created['page']['active_draft_revision_id']];
}

function sbdi_value(string $sql, array $args = []): mixed
{
    return Database::value($sql, $args);
}

unit('data integrity integration: page lists, archived addresses, archived pages, restore and the homepage (real MySQL)', function (): void {
    $dbName = 'slate_sbdi_' . slate_test_ns();
    $pdo = sbdi_pdo($dbName, true);
    try {
        sbdi_with_pdo($pdo, static function (): void {
            $core = InstallationService::provisionCore();
            $iid = (string) $core['installation_id'];
            Database::query('INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\') ON DUPLICATE KEY UPDATE name = VALUES(name)', [SBDI_A, 'Tenant A', 'tenant-a-di']);
            $seed = static fn(int $t) => license_test_seed_cache($t, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'di', 'entitlements' => ['studio-builder'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            $seed((int) $core['tenant_id']);
            assert_true(CommercialModuleRegistry::validateSelection(['studio-builder'], ['studio-builder'])['ok']);
            $act = \PluginLoader::installFromDisk('studio-builder');
            assert_true(!empty($act['ok']), 'installFromDisk(studio-builder): ' . json_encode($act));
            $seed(SBDI_A);

            $rt = StudioRuntimeFactory::build();
            $editor    = StudioActor::authenticated(91, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
            $publisher = StudioActor::authenticated(92, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            $inA = static fn(callable $fn) => $rt->tenants->runAs(SBDI_A, $fn);

            // ── H10: archived pages changed most recently do not push live pages out of the list ─────────
            $live = sbdi_page($rt, $editor, 'still-here');
            Database::query("UPDATE studiobuilder_pages SET updated_at = '2020-01-01 00:00:00' WHERE tenant_id = ? AND id = ?", [SBDI_A, $live['id']]);
            $uuid = static function (): string {
                $h = bin2hex(random_bytes(16));
                return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
            };
            for ($i = 0; $i < 260; $i++) {
                Database::insert('studiobuilder_pages', [
                    'tenant_id' => SBDI_A, 'uuid' => $uuid(), 'title' => "Gone {$i}", 'slug' => "archived-{$i}-gone", 'page_type' => 'page',
                    'status' => 'archived', 'route_mode' => 'standalone', 'updated_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $listed = array_column($inA(static fn() => $rt->app->listPages($editor)), 'slug');
            assert_true(in_array('still-here', $listed, true), 'a live page is listed whatever was archived after it: ' . count($listed) . ' listed');
            assert_eq([], array_values(array_filter($listed, static fn(string $s): bool => str_starts_with($s, 'archived-'))), 'and no archived page is');

            // ── M12: archiving frees the address; an address an older archived page still holds is freed on use ──
            $first = sbdi_page($rt, $editor, 'reuse-me');
            sbdi_ok(sbdi_call($rt, $editor, 'archive_page', ['page_id' => $first['id']]), 'archive reuse-me');
            assert_eq('archived-' . $first['id'] . '-reuse-me', (string) sbdi_value('SELECT slug FROM studiobuilder_pages WHERE id = ?', [$first['id']]), 'the archived page moved to its own slug');
            $second = sbdi_page($rt, $editor, 'reuse-me');
            assert_true($second['id'] !== $first['id'], 'a new page takes the address');

            $legacy = sbdi_page($rt, $editor, 'legacy');
            Database::query("UPDATE studiobuilder_pages SET status = 'archived' WHERE id = ?", [$legacy['id']]); // archived before this change: still holds 'legacy'
            $renamed = sbdi_page($rt, $editor, 'renamed');
            sbdi_ok(sbdi_call($rt, $editor, 'update_page', ['page_id' => $renamed['id'], 'slug' => 'legacy']), 'a rename onto an address an old archived page holds');
            assert_eq('archived-' . $legacy['id'] . '-legacy', (string) sbdi_value('SELECT slug FROM studiobuilder_pages WHERE id = ?', [$legacy['id']]), 'the old archived page was moved off it');

            // ── M13: an archived page takes no draft, publish or restore (a builder tab left open on it) ───
            $tab = sbdi_page($rt, $publisher, 'open-tab');
            $doc = sbdi_ok(sbdi_call($rt, $publisher, 'operations', ['page_id' => $tab['id'], 'expected_revision_id' => $tab['rev'], 'operations' => [
                ['op' => 'insert_block', 'payload' => ['parent_id' => null, 'index' => 0, 'block' => ['type' => 'core.container', 'metadata' => ['label' => 'Main']]]],
            ]]), 'edit before archiving');
            $rev = (int) $doc['revision']['id'];
            sbdi_ok(sbdi_call($rt, $publisher, 'archive_page', ['page_id' => $tab['id']]), 'archive it elsewhere');
            assert_eq('page_archived', sbdi_code(sbdi_call($rt, $publisher, 'publish', ['page_id' => $tab['id'], 'expected_revision_id' => $rev])), 'publishing from the open tab is refused');
            assert_eq('page_archived', sbdi_code(sbdi_call($rt, $publisher, 'save_draft', ['page_id' => $tab['id'], 'expected_revision_id' => $rev, 'revision_kind' => 'manual', 'document' => $doc['document']])), 'so is saving');
            assert_eq('page_archived', sbdi_code(sbdi_call($rt, $publisher, 'rollback', ['page_id' => $tab['id'], 'target_revision_id' => $tab['rev'], 'expected_revision_id' => $rev])), 'and restoring');
            assert_eq('archived', (string) sbdi_value('SELECT status FROM studiobuilder_pages WHERE id = ?', [$tab['id']]), 'the page stays archived');

            // ── H7: Restore (rollback) goes through the revision service: checked against the current draft, with dependencies ──
            $restore = sbdi_page($rt, $editor, 'restore-me');
            $r = sbdi_ok(sbdi_call($rt, $editor, 'operations', ['page_id' => $restore['id'], 'expected_revision_id' => $restore['rev'], 'operations' => [
                ['op' => 'insert_block', 'payload' => ['parent_id' => null, 'index' => 0, 'block' => ['type' => 'core.container', 'metadata' => ['label' => 'Main']]]],
            ]]), 'a second revision');
            $stale = false;
            try {
                $inA(static fn() => $rt->app->rollback($editor, $restore['id'], $restore['rev'], $restore['rev']));
            } catch (\Slate\Module\StudioBuilder\Exception\StudioConcurrencyException) {
                $stale = true;
            }
            assert_true($stale, 'a Restore against a draft the screen no longer shows is refused');
            $back = $inA(static fn() => $rt->app->rollback($editor, $restore['id'], $restore['rev'], (int) $r['revision']['id']));
            assert_eq('rollback', (string) $back['revision']['revision_kind'], 'a restore is a new rollback revision');
            assert_eq((int) $back['revision']['id'], (int) sbdi_value('SELECT active_draft_revision_id FROM studiobuilder_pages WHERE id = ?', [$restore['id']]), 'and the page\'s draft');
            $reviews = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/reviews.php');
            assert_true(!str_contains($reviews, "Database::insert('studiobuilder_revisions'"), 'the Reviews screen no longer writes revisions itself');
            assert_true(str_contains($reviews, '->app->rollback('), 'it calls the application service');

            // ── M15: one homepage — the page that goes live as the homepage moves the old one to its address ──
            $oldHome = sbdi_page($rt, $publisher, 'old-home', 'homepage');
            sbdi_ok(sbdi_call($rt, $publisher, 'publish', ['page_id' => $oldHome['id'], 'expected_revision_id' => $oldHome['rev']]), 'publish the first homepage');
            $newHome = sbdi_page($rt, $publisher, 'new-home');
            sbdi_ok(sbdi_call($rt, $publisher, 'publish', ['page_id' => $newHome['id'], 'expected_revision_id' => $newHome['rev']]), 'publish a normal page');
            $draftHome = sbdi_page($rt, $publisher, 'draft-home', 'homepage');
            assert_eq('homepage', (string) sbdi_value('SELECT route_mode FROM studiobuilder_pages WHERE id = ?', [$oldHome['id']]), 'marking a DRAFT as the homepage leaves the live one in place');
            $inA(static fn() => $rt->app->updatePageAddress($publisher, $newHome['id'], ['route_mode' => 'homepage'])); // the page settings screen's "Site Homepage"
            assert_eq('standalone', (string) sbdi_value('SELECT route_mode FROM studiobuilder_pages WHERE id = ?', [$oldHome['id']]), 'the old homepage became a normal page');
            assert_eq('standalone', (string) sbdi_value('SELECT route_mode FROM studiobuilder_pages WHERE id = ?', [$draftHome['id']]), 'and so did the draft that claimed it');
            assert_eq([(int) $newHome['id']], array_map('intval', array_column(Database::all("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND route_mode = 'homepage' AND status <> 'archived'", [SBDI_A]), 'id')), 'the new page is the only homepage');
        });
    } finally {
        sbdi_pdo($dbName, false);
    }
});

if (!empty($studioDataIntegrityStandalone)) {
    exit(unit_summary());
}
