<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — the line between a draft and the live site (audit step 2, 2026-10-26).
 *
 * Real MySQL. Post lists, category counts and whether an address is served read what was PUBLISHED, never the draft;
 * a payment button is payable only while a published document holds it; a whole-document write (save, rollback, undo)
 * cannot edit or drop a locked layer; and archiving or readdressing a published page needs the publish permission and
 * is never done by an AI origin.
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
    $studioDraftLiveStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioApiResponse;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Payment\LivePaymentBlocks;
use Slate\Module\StudioBuilder\Provider\PostsProvider;
use Slate\Module\StudioBuilder\Provider\TaxonomyProvider;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;

const SBDL_A = 131;
const SBDL_B = 232;

function sbdl_pdo(string $dbName, bool $create): ?\PDO
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
function sbdl_with_pdo(\PDO $pdo, callable $fn): void
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

function sbdl_call(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $method, string $action, array $input): StudioApiResponse
{
    $api = new StudioAuthoringApi($rt->app);
    $request = $method === 'POST'
        ? new StudioApiRequest('POST', $action, [], (string) json_encode($input, JSON_PRESERVE_ZERO_FRACTION), 'application/json', true, 'same-origin')
        : new StudioApiRequest('GET', $action, array_map('strval', $input));
    return $rt->tenants->runAs($tenantId, static fn() => $api->handle($request, $actor));
}

/** @return array<string, mixed> */
function sbdl_ok(StudioApiResponse $r, string $what): array
{
    assert_true($r->isOk(), "{$what}: expected success, got {$r->status} " . substr($r->body(), 0, 600));
    return $r->data();
}

/** The first error code of a refused call. */
function sbdl_code(StudioApiResponse $r): string
{
    return (string) ($r->payload['error']['details']['errors'][0]['code'] ?? $r->payload['error']['code'] ?? '');
}

/** A page with one section holding `$blocks`, saved as a draft. @return array{id: int, rev: int, section: string, document: array<string, mixed>} */
function sbdl_page(StudioRuntime $rt, StudioActor $actor, string $slug, array $blocks, array $settings = []): array
{
    $created = sbdl_ok(sbdl_call($rt, SBDL_A, $actor, 'POST', 'create_page', ['title' => ucfirst($slug), 'slug' => $slug, 'page_type' => 'page', 'route_mode' => 'standalone']), "create {$slug}");
    $id = (int) $created['page']['id'];
    $ops = [['op' => 'insert_block', 'payload' => ['parent_id' => null, 'index' => 0, 'block' => ['type' => 'core.container', 'metadata' => ['label' => 'Main']]]]];
    if ($settings !== []) {
        $ops[] = ['op' => 'update_settings', 'payload' => ['settings' => $settings + CanonicalDocumentSchema::defaultSettings()]];
    }
    $r = sbdl_ok(sbdl_call($rt, SBDL_A, $actor, 'POST', 'operations', ['page_id' => $id, 'expected_revision_id' => (int) $created['page']['active_draft_revision_id'], 'operations' => $ops]), "{$slug} section");
    $section = (string) $r['document']['containers'][0]['id'];
    foreach ($blocks as $i => $block) {
        $r = sbdl_ok(sbdl_call($rt, SBDL_A, $actor, 'POST', 'operations', ['page_id' => $id, 'expected_revision_id' => (int) $r['revision']['id'], 'operations' => [
            ['op' => 'insert_block', 'payload' => ['parent_id' => $section, 'index' => $i, 'block' => $block]],
        ]]), "{$slug} block {$i}");
    }
    return ['id' => $id, 'rev' => (int) $r['revision']['id'], 'section' => $section, 'document' => $r['document']];
}

function sbdl_publish(StudioRuntime $rt, StudioActor $publisher, int $pageId, int $rev): int
{
    return (int) sbdl_ok(sbdl_call($rt, SBDL_A, $publisher, 'POST', 'publish', ['page_id' => $pageId, 'expected_revision_id' => $rev]), "publish {$pageId}")['revision']['id'];
}

unit('draft/live integration: lists, payments, locks and live changes follow what is PUBLISHED (real MySQL)', function (): void {
    $dbName = 'slate_sbdl_' . slate_test_ns();
    $pdo = sbdl_pdo($dbName, true);
    try {
        sbdl_with_pdo($pdo, static function (): void {
            $core = InstallationService::provisionCore();
            $iid = (string) $core['installation_id'];
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\') ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [SBDL_A, 'Tenant A', 'tenant-a-dl', SBDL_B, 'Tenant B', 'tenant-b-dl']
            );
            $seed = static fn(int $t, array $e) => license_test_seed_cache($t, ['installation_id' => $iid, 'status' => 'active', 'plan' => 'dl', 'entitlements' => $e, 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            $seed((int) $core['tenant_id'], ['studio-builder']);
            $sel = CommercialModuleRegistry::validateSelection(['studio-builder'], ['studio-builder']);
            assert_true($sel['ok'], json_encode($sel));
            $act = \PluginLoader::installFromDisk('studio-builder');
            assert_true(!empty($act['ok']), 'installFromDisk(studio-builder): ' . json_encode($act));
            $seed(SBDL_A, ['studio-builder']);
            $seed(SBDL_B, ['studio-builder']);

            $rt = StudioRuntimeFactory::build();
            $editor    = StudioActor::authenticated(91, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
            $publisher = StudioActor::authenticated(92, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            $ai        = StudioActor::forMcpToken(9, 92, StudioPermissions::ALL);
            $inA = static fn(callable $fn) => $rt->tenants->runAs(SBDL_A, $fn);

            // ── 1. Post lists, category counts and served addresses read the PUBLISHED settings ──────────
            $post = sbdl_page($rt, $publisher, 'post-a', [['type' => 'core.heading', 'props' => ['text' => 'Hello', 'level' => 'h2']]], ['category' => 'News', 'excerpt' => 'Live excerpt']);
            sbdl_publish($rt, $publisher, $post['id'], $post['rev']);
            sbdl_ok(sbdl_call($rt, SBDL_A, $editor, 'POST', 'operations', ['page_id' => $post['id'], 'expected_revision_id' => $post['rev'] + 1, 'operations' => [
                ['op' => 'update_settings', 'payload' => ['settings' => ['category' => 'Secret', 'excerpt' => 'Draft excerpt', 'template_type' => 'single'] + CanonicalDocumentSchema::defaultSettings()]],
            ]]), 'an unpublished settings change');
            $inA(static function () use ($rt): void {
                $posts = (new PostsProvider())->execute($rt->tenants, ['limit' => 10]);
                $mine = array_values(array_filter($posts, static fn(array $p): bool => $p['slug'] === 'post-a'));
                assert_eq(1, count($mine), 'the published post is listed: ' . json_encode($posts));
                assert_eq('News', $mine[0]['category'], 'with its published category, not the draft one');
                assert_eq('Live excerpt', $mine[0]['excerpt'], 'and its published excerpt');
                assert_eq([], (new PostsProvider())->execute($rt->tenants, ['category' => 'Secret']), 'a draft-only category matches nothing');
                $cats = array_column((new TaxonomyProvider())->execute($rt->tenants, ['type' => 'category']), 'count', 'name');
                assert_eq(1, $cats['News'] ?? null, 'counts use the published category');
                assert_true(!isset($cats['Secret']), 'not the draft one');
                $page = $rt->publicRuntime->handlePath('/post-a');
                assert_true($page !== null && $page->status === 200, 'a draft template_type does not take the live page offline');
            });

            // ── 2. A payment button is payable only while a PUBLISHED document holds it ──────────────────
            $button = ['type' => 'payment.button', 'props' => ['label' => 'Buy', 'amount' => 10, 'currency' => 'USD', 'description' => 'Starter', 'success_path' => '']];
            $pay = sbdl_page($rt, $publisher, 'pay', [$button]);
            $blockId = (string) $pay['document']['containers'][0]['children'][0]['id'];
            assert_null($inA(static fn() => LivePaymentBlocks::propsOf(SBDL_A, $blockId)), 'a button that only exists in a draft is not payable');
            $payRev = sbdl_publish($rt, $publisher, $pay['id'], $pay['rev']);
            $live = $inA(static fn() => LivePaymentBlocks::propsOf(SBDL_A, $blockId));
            assert_eq(10, (int) ($live['amount'] ?? 0), 'once published it is');
            assert_null($rt->tenants->runAs(SBDL_B, static fn() => LivePaymentBlocks::propsOf(SBDL_B, $blockId)), 'never for another tenant');
            $r = sbdl_ok(sbdl_call($rt, SBDL_A, $editor, 'POST', 'operations', ['page_id' => $pay['id'], 'expected_revision_id' => $payRev, 'operations' => [
                ['op' => 'insert_block', 'payload' => ['parent_id' => $pay['section'], 'index' => 1, 'block' => ['type' => 'payment.button', 'props' => ['label' => 'Gold', 'amount' => 0.5, 'currency' => 'USD', 'description' => 'Gold plan', 'success_path' => '']]]],
            ]]), 'a draft-only cheap button');
            $draftOnly = (string) $r['document']['containers'][0]['children'][1]['id'];
            assert_null($inA(static fn() => LivePaymentBlocks::propsOf(SBDL_A, $draftOnly)), 'an editor cannot mint a payable price in a draft');
            sbdl_ok(sbdl_call($rt, SBDL_A, $publisher, 'POST', 'archive_page', ['page_id' => $pay['id']]), 'archive the pay page');
            assert_null($inA(static fn() => LivePaymentBlocks::propsOf(SBDL_A, $blockId)), 'an archived page holds no payable button');

            // ── 3. Whole-document writes keep locked layers ──────────────────────────────────────────────
            $lockedPage = sbdl_page($rt, $editor, 'locked', [['type' => 'core.heading', 'props' => ['text' => 'Keep me', 'level' => 'h2']]]);
            $beforeExtra = $lockedPage['rev'];
            $r = sbdl_ok(sbdl_call($rt, SBDL_A, $editor, 'POST', 'operations', ['page_id' => $lockedPage['id'], 'expected_revision_id' => $beforeExtra, 'operations' => [
                ['op' => 'insert_block', 'payload' => ['parent_id' => null, 'index' => 1, 'block' => ['type' => 'core.container', 'metadata' => ['label' => 'Second']]]],
            ]]), 'second section');
            $beforeLock = (int) $r['revision']['id'];
            $r = sbdl_ok(sbdl_call($rt, SBDL_A, $editor, 'POST', 'operations', ['page_id' => $lockedPage['id'], 'expected_revision_id' => $beforeLock, 'operations' => [
                ['op' => 'update_block_meta', 'payload' => ['block_id' => $lockedPage['section'], 'locked' => true]],
            ]]), 'lock the first section');
            $rev = (int) $r['revision']['id'];
            $doc = sbdl_ok(sbdl_call($rt, SBDL_A, $editor, 'GET', 'document', ['page' => $lockedPage['id']]), 'document')['document'];

            $dropped = $doc;
            array_shift($dropped['containers']);
            $res = sbdl_call($rt, SBDL_A, $editor, 'POST', 'save_draft', ['page_id' => $lockedPage['id'], 'expected_revision_id' => $rev, 'revision_kind' => 'manual', 'document' => $dropped]);
            assert_eq('node_locked', sbdl_code($res), 'a saved document cannot drop a locked section: ' . substr($res->body(), 0, 300));
            $edited = $doc;
            $edited['containers'][0]['children'][0]['props']['text'] = 'Changed';
            assert_eq('node_locked', sbdl_code(sbdl_call($rt, SBDL_A, $editor, 'POST', 'save_draft', ['page_id' => $lockedPage['id'], 'expected_revision_id' => $rev, 'revision_kind' => 'manual', 'document' => $edited])), 'nor edit inside it');
            $other = $doc;
            $other['containers'][1]['label'] = 'Renamed second';
            $saved = sbdl_ok(sbdl_call($rt, SBDL_A, $editor, 'POST', 'save_draft', ['page_id' => $lockedPage['id'], 'expected_revision_id' => $rev, 'revision_kind' => 'manual', 'document' => $other]), 'an unlocked section can still change');
            $rev = (int) $saved['revision']['id'];
            assert_eq('node_locked', sbdl_code(sbdl_call($rt, SBDL_A, $ai, 'POST', 'rollback', ['page_id' => $lockedPage['id'], 'target_revision_id' => $beforeExtra - 1, 'expected_revision_id' => $rev])), 'a rollback to before the locked section existed is refused');
            $undo = sbdl_ok(sbdl_call($rt, SBDL_A, $editor, 'POST', 'rollback', ['page_id' => $lockedPage['id'], 'target_revision_id' => $beforeLock, 'expected_revision_id' => $rev]), 'undoing the lock itself still works');
            assert_true(empty($undo['document']['containers'][0]['locked']), 'the section is unlocked again');

            // ── 4. Changing a published page directly needs publish, and never an AI ─────────────────────
            assert_eq(403, sbdl_call($rt, SBDL_A, $editor, 'POST', 'archive_page', ['page_id' => $post['id']])->status, 'an editor cannot take a live page offline');
            assert_eq(403, sbdl_call($rt, SBDL_A, $ai, 'POST', 'archive_page', ['page_id' => $post['id']])->status, 'nor can an AI token, whatever its scopes');
            assert_eq(403, sbdl_call($rt, SBDL_A, $editor, 'POST', 'update_page', ['page_id' => $post['id'], 'slug' => 'moved'])->status, 'or move it');
            sbdl_ok(sbdl_call($rt, SBDL_A, $editor, 'POST', 'update_page', ['page_id' => $lockedPage['id'], 'title' => 'Draft page renamed']), 'a never-published page is the editor\'s to rename');
            sbdl_ok(sbdl_call($rt, SBDL_A, $editor, 'POST', 'archive_page', ['page_id' => $lockedPage['id']]), 'and to delete');
            sbdl_ok(sbdl_call($rt, SBDL_A, $publisher, 'POST', 'archive_page', ['page_id' => $post['id']]), 'a publisher can take a live page offline');
        });
    } finally {
        sbdl_pdo($dbName, false);
    }
});

if (!empty($studioDraftLiveStandalone)) {
    exit(unit_summary());
}
