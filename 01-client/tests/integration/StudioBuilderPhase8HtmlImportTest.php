<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — Phase 8B constrained
 * HTML/CSS import.
 *
 * Real MySQL (throwaway database), real plugin activation, real per-tenant
 * entitlements, the PRODUCTION wiring (StudioRuntimeFactory) and the builder's
 * real HTTP controller (StudioAuthoringApi). Tenants:
 *   - 101 (A): studio-builder — owns /uploads/media/2026/05/alpha.jpg
 *   - 202 (B): studio-builder — owns /uploads/media/2026/05/beta.jpg
 *   - 303 (C): nothing
 *
 * Covers: dry run (zero business writes, no audit row, byte-identical
 * repeat), create-page commit (import revision, no publish, stored document
 * valid, invariants), the report contract, media (tenant-local resolution,
 * explicit same-tenant map, foreign map/id refused, remote never fetched),
 * replace_draft (409 on stale, published revision untouched, foreign target
 * not found), route collision and reserved routes, the permission matrix
 * (view / no entitlement / guest / AI origins / CSRF), authorization before
 * parsing, fail-closed without DOM, tenant isolation, and post-commit audit.
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
    $studioP8bIntStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Exception\StudioAuthorizationException;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioApiResponse;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const SBH_CORE_MIGRATIONS = [
    '0001_core_init', '0002_identity_core', '0011_login_attempts', '0014_tenant_profiles', '0023_installation_identity',
    '0022_remote_license_cache', '0024_remote_license_metadata', '0025_remote_license_cache_installation_id', '0026_remote_license_cache_signed_payload',
];
const SBH_A = 101;
const SBH_B = 202;
const SBH_C = 303;
const SBH_TABLES = ['studiobuilder_pages', 'studiobuilder_revisions', 'studiobuilder_templates', 'studiobuilder_tokens', 'studiobuilder_dependencies', 'studiobuilder_compilations', 'studiobuilder_locks', 'media_files', 'audit_log'];

function sbh_fresh_db(string $dbName): \PDO
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $host = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '');
    $root = new \PDO($host . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4");
    $pdo = new \PDO($host . ";dbname={$dbName};charset=" . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(SBH_CORE_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function sbh_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    (new \PDO('mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function sbh_with_pdo(\PDO $pdo, callable $fn): mixed
{
    $property = new \ReflectionProperty(Database::class, 'pdo');
    $previous = $property->getValue();
    $property->setValue(null, $pdo);
    $props = [];
    foreach (['active', 'activeSlugs', 'booted'] as $name) {
        $props[$name] = new \ReflectionProperty(\PluginLoader::class, $name);
        $prev[$name] = $props[$name]->getValue();
    }
    $envKeys = ['LICENSE_SERVER_URL', 'LICENSE_SERVER_PUBLIC_KEY', 'LICENSE_PRODUCT', 'LICENSE_KEY'];
    $prevEnv = [];
    foreach ($envKeys as $k) {
        $prevEnv[$k] = $_ENV[$k] ?? null;
    }
    $_ENV['LICENSE_SERVER_URL'] = 'https://license.test';
    $_ENV['LICENSE_SERVER_PUBLIC_KEY'] = license_test_public_key();
    $_ENV['LICENSE_PRODUCT'] = 'kohevo';
    $_ENV['LICENSE_KEY'] = 'test-key';
    try {
        $props['active']->setValue(null, []);
        $props['activeSlugs']->setValue(null, null);
        $props['booted']->setValue(null, false);
        return $fn();
    } finally {
        unset($GLOBALS['SLATE_TENANT_OVERRIDE']);
        foreach ($props as $name => $p) {
            $p->setValue(null, $prev[$name]);
        }
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

function sbh_seed(int $tenantId, string $installationId, array $entitlements): void
{
    license_test_seed_cache($tenantId, [
        'installation_id' => $installationId, 'status' => 'active', 'plan' => 'phase8b-' . implode('-', $entitlements ?: ['none']),
        'entitlements' => $entitlements, 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
    ]);
}

/** One builder API call as $actor inside $tenantId (the tenant is NEVER taken from the request). */
function sbh_call(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $method, string $action, array $input = [], bool $csrf = true): StudioApiResponse
{
    $api = new StudioAuthoringApi($rt->app);
    $request = $method === 'POST'
        ? new StudioApiRequest('POST', $action, [], (string) json_encode($input, JSON_PRESERVE_ZERO_FRACTION), 'application/json', $csrf, 'same-origin')
        : new StudioApiRequest('GET', $action, array_map('strval', $input));
    return $rt->tenants->runAs($tenantId, static fn() => $api->handle($request, $actor));
}

function sbh_import(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $html, bool $dryRun, array $extra = []): StudioApiResponse
{
    return sbh_call($rt, $tenantId, $actor, 'POST', 'import_html', ['html' => $html, 'dry_run' => $dryRun] + $extra);
}

/** @return array<string, mixed> */
function sbh_ok(StudioApiResponse $r, string $what): array
{
    assert_true($r->isOk(), "{$what}: expected success, got {$r->status} " . substr($r->body(), 0, 800));
    return $r->data();
}

/** @return array<string, mixed> */
function sbh_report(StudioApiResponse $r): array
{
    return $r->isOk() ? ($r->data()['report'] ?? []) : ($r->payload['error']['details']['report'] ?? []);
}

/** @return list<string> */
function sbh_codes(array $report, ?string $severity = null): array
{
    $out = [];
    foreach ($report['issues'] ?? [] as $i) {
        if ($severity === null || $i['severity'] === $severity) {
            $out[] = $i['code'];
        }
    }
    return $out;
}

function sbh_count(string $sql, array $params = []): int
{
    return (int) Database::value($sql, $params);
}

/** @return array<string, int> */
function sbh_table_counts(): array
{
    $out = [];
    foreach (SBH_TABLES as $t) {
        $out[$t] = sbh_count("SELECT COUNT(*) FROM `{$t}`");
    }
    return $out;
}

/** @return array<string, mixed> the page's working document */
function sbh_draft(StudioRuntime $rt, int $tenantId, int $pageId): array
{
    return $rt->tenants->runAs($tenantId, static function () use ($rt, $pageId): array {
        $page = $rt->pages->find($pageId);
        $rev = $rt->revisions->findByIdForPage($pageId, (int) $page['active_draft_revision_id']);
        return CanonicalJson::decode((string) $rev['document_json']);
    });
}

/** @return list<array<string, mixed>> */
function sbh_blocks(array $doc): array
{
    $out = [];
    $stack = [];
    foreach (array_reverse($doc['sections']) as $s) {
        foreach (array_reverse($s['blocks']) as $b) {
            $stack[] = $b;
        }
    }
    while ($stack !== []) {
        $b = array_pop($stack);
        $out[] = $b;
        foreach (array_reverse($b['children']) as $c) {
            $stack[] = $c;
        }
    }
    return $out;
}

const SBH_PAGE = '<!doctype html><html><head><title>Alpha — Home ✓</title><style>.hero{text-align:center;padding:4rem 0}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem}</style>'
    . '<script>STEAL()</script><link rel="stylesheet" href="https://evil.example/x.css"></head><body>'
    . '<section class="hero"><p>New</p><h1>Welcome ✓</h1><p>Fresh every day.</p><a class="btn" href="/menu">Menu</a><img src="/uploads/media/2026/05/alpha.jpg" alt="Alpha"></section>'
    . '<section><h2>Why us</h2><div class="grid"><div><h3>Fast</h3><p>Quick</p></div><div><h3>Fresh</h3><p>Daily</p></div><div><h3>Local</h3><p>Near</p></div></div></section>'
    . '<section><h2>Story</h2><p>Plain <strong>text</strong> <a href="javascript:alert(1)">bad</a> <a href="https://example.com">good</a></p><img src="https://cdn.example.com/x.jpg" alt="remote"><iframe srcdoc="x"></iframe><p onclick="x()">End</p></section>'
    . '</body></html>';

unit('phase8b integration: constrained HTML/CSS import (real MySQL, tenants 101/202/303)', function (): void {
    $dbName = 'slate_sbp8b_' . slate_test_ns();
    $pdo = sbh_fresh_db($dbName);

    try {
        sbh_with_pdo($pdo, static function (): void {
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Studio Admin', 'studio-admin-p8b@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\'), (?,?,?,\'active\') ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [SBH_A, 'Tenant A', 'tenant-a-p8b', SBH_B, 'Tenant B', 'tenant-b-p8b', SBH_C, 'Tenant C', 'tenant-c-p8b']
            );
            sbh_seed($owner, $iid, ['studio-builder']);
            $sel = CommercialModuleRegistry::validateSelection(['studio-builder'], ['studio-builder']);
            assert_true($sel['ok'], 'selection: ' . json_encode($sel));
            $act = \PluginLoader::installFromDisk('studio-builder');
            assert_true(!empty($act['ok']), 'installFromDisk(studio-builder): ' . json_encode($act));
            Media::ensureSchema();
            sbh_seed(SBH_A, $iid, ['studio-builder']);
            sbh_seed(SBH_B, $iid, ['studio-builder']);
            sbh_seed(SBH_C, $iid, []);

            $rt = StudioRuntimeFactory::build();
            $viewer    = StudioActor::authenticated(80, [StudioPermissions::VIEW]);
            $editor    = StudioActor::authenticated(81, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
            $publisher = StudioActor::authenticated(84, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            $full      = StudioActor::authenticated(85, StudioPermissions::ALL);
            $tokenAi   = StudioActor::forMcpToken(9, 85, StudioPermissions::ALL);
            $S = new \stdClass();
            $S->alpha = $rt->tenants->runAs(SBH_A, static fn(): int => Media::register('/uploads/media/2026/05/alpha.jpg', ['mime' => 'image/jpeg']));
            $S->beta  = $rt->tenants->runAs(SBH_B, static fn(): int => Media::register('/uploads/media/2026/05/beta.jpg', ['mime' => 'image/jpeg']));
            $S->betaDoc = $rt->tenants->runAs(SBH_B, static fn(): int => Media::register('/uploads/media/2026/05/beta.pdf', ['mime' => 'application/pdf']));

            unit('phase8b int 1: a dry run analyses the page (html_css report, can_commit) with ZERO business writes and no audit row, and is byte-identical when repeated', function () use ($rt, $editor): void {
                $before = sbh_table_counts();
                $r1 = sbh_import($rt, SBH_A, $editor, SBH_PAGE, true, ['slug' => 'alpha-home']);
                $rep = sbh_report($r1);
                assert_eq(200, $r1->status);
                assert_eq('html_css', $rep['source_kind']);
                assert_true($rep['can_commit'] === true && $rep['dry_run'] === true, 'analysable: ' . json_encode(sbh_codes($rep, 'error')));
                assert_eq(1, $rep['summary']['pages_count']);
                assert_eq(1, preg_match('/^[0-9a-f]{64}$/', $rep['source_hash']));
                assert_true(($rep['conversion']['stripped_security'] ?? 0) >= 3, 'script, iframe, style counted');
                assert_eq([['action' => 'create_page', 'item' => 'html', 'slug' => 'alpha-home', 'page_type' => 'page', 'route_mode' => 'standalone', 'revision_kind' => 'import', 'publishes' => false]], $rep['planned_actions']);
                assert_eq(['mapped' => 0, 'resolved_locally' => 1, 'unresolved' => 0], $rep['dependencies']['media'], 'the local /uploads/ image resolves to THIS tenant\'s media');
                $codes = sbh_codes($rep);
                foreach (['security_stripped', 'unsafe_url', 'unresolved_media'] as $c) {
                    assert_true(in_array($c, $codes, true), "reported: {$c}");
                }
                assert_eq($before, sbh_table_counts(), 'no page, revision, dependency, media or audit row');
                $r2 = sbh_import($rt, SBH_A, $editor, SBH_PAGE, true, ['slug' => 'alpha-home']);
                assert_eq($r1->body(), $r2->body(), 'identical input + tenant/theme state -> byte-identical report');
            });

            unit('phase8b int 2: committing creates ONE draft page with an import revision — never published — whose stored document is valid and holds no raw CSS/HTML, handlers, remote images or foreign identity; audited after commit', function () use ($rt, $editor, $S): void {
                $auditBefore = sbh_count("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'studio.%imported'");
                $data = sbh_ok(sbh_import($rt, SBH_A, $editor, SBH_PAGE, false, ['slug' => 'alpha-home']), 'commit');
                $rep = $data['report'];
                assert_eq(true, $rep['ok']);
                $page = $rep['committed']['pages'][0];
                assert_eq(['created', 'alpha-home', false], [$page['mode'], $page['slug'], $page['published']]);
                $S->pageA = (int) $page['page_id'];
                $row = $rt->tenants->runAs(SBH_A, static fn() => $rt->pages->find($S->pageA));
                assert_eq('Alpha — Home ✓', $row['title'], 'the <title> became the page title');
                assert_null($row['published_revision_id'], 'import never publishes');
                assert_eq(['import', 'import'], array_column(Database::rows('SELECT revision_kind FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ? ORDER BY id', [SBH_A, $S->pageA]), 'revision_kind'));
                assert_eq(0, sbh_count("SELECT COUNT(*) FROM studiobuilder_revisions WHERE revision_kind = 'publish'"));

                $doc = sbh_draft($rt, SBH_A, $S->pageA);
                $options = ['media_exists' => static fn(int $id): bool => $id === $S->alpha];
                $validation = DocumentValidator::validate($doc, $rt->registry, $options);
                assert_true($validation->isValid(), 'the stored document passes the CURRENT validator: ' . json_encode($validation->errors()));
                assert_eq(['core.hero', 'core.heading', 'core.feature_list', 'core.heading', 'core.rich_text'], array_column(sbh_blocks($doc), 'type'), 'the paragraphs around the removed iframe merge into one text block');
                assert_eq($S->alpha, sbh_blocks($doc)[0]['props']['media']['media_id'], 'the tenant-local image resolved to THIS tenant\'s media id');
                foreach (sbh_blocks($doc) as $b) {
                    assert_true(str_starts_with($b['type'], 'core.'), 'no module block');
                    if ($b['type'] === 'core.rich_text') {
                        assert_null(FieldSchema::validateRichText($b['props']['content']));
                    }
                }
                $json = strtolower((string) Database::value('SELECT document_json FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ? ORDER BY id DESC LIMIT 1', [SBH_A, $S->pageA]));
                foreach (['text-align', 'padding:', 'grid-template', '<script', 'steal', 'onclick', 'srcdoc', 'javascript', 'cdn.example.com', 'evil.example', '<section', '<img', 'tenant_id', 'class=', 'style='] as $needle) {
                    assert_true(!str_contains($json, $needle), "stored document must not contain {$needle}");
                }
                assert_eq($auditBefore + 2, sbh_count("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'studio.%imported'"), 'page + package events, after the commit');
                $audit = Database::row("SELECT tenant_id, target, meta_json FROM audit_log WHERE action = 'studio.page.imported' ORDER BY id DESC LIMIT 1");
                $meta = json_decode((string) $audit['meta_json'], true);
                assert_eq(SBH_A, (int) $audit['tenant_id']);
                assert_eq(['html_css', 'import', 'created'], [$meta['source_kind'], $meta['revision_kind'], $meta['mode']]);
                assert_eq($rep['source_hash'], $meta['source_hash']);
            });

            unit('phase8b int 3: media — a path of ANOTHER tenant never resolves, an explicit same-tenant media_map does, a foreign media id in media_map is refused, a non-image is refused; nothing is fetched or created', function () use ($rt, $editor, $S): void {
                $html = '<article><h2>M</h2><img src="/uploads/media/2026/05/alpha.jpg" alt="A"></article>';
                $mediaBefore = sbh_count('SELECT COUNT(*) FROM media_files');
                $rep = sbh_report(sbh_import($rt, SBH_B, $editor, $html, true, ['slug' => 'media-probe']));
                assert_eq(['mapped' => 0, 'resolved_locally' => 0, 'unresolved' => 1], $rep['dependencies']['media'], 'A\'s path is not B\'s media');
                assert_true(in_array('unresolved_media', sbh_codes($rep, 'warning'), true));
                assert_eq(['core.heading'], array_column(sbh_blocks($rep['preview_documents'][0]['document']), 'type'), 'the image is omitted, never pointed at A\'s file');

                $mapped = sbh_report(sbh_import($rt, SBH_B, $editor, $html, true, ['slug' => 'media-probe', 'media_map' => ['/uploads/media/2026/05/alpha.jpg' => $S->beta]]));
                assert_eq(1, $mapped['dependencies']['media']['mapped']);
                assert_eq($S->beta, sbh_blocks($mapped['preview_documents'][0]['document'])[1]['props']['media']['media_id']);

                $foreign = sbh_report(sbh_import($rt, SBH_B, $editor, $html, true, ['slug' => 'media-probe', 'media_map' => ['/uploads/media/2026/05/alpha.jpg' => $S->alpha]]));
                assert_eq(['unresolved_media'], sbh_codes($foreign, 'error'), 'a foreign media id is never trusted');
                assert_false($foreign['can_commit']);
                $doc = sbh_report(sbh_import($rt, SBH_B, $editor, $html, true, ['slug' => 'media-probe', 'media_map' => ['/uploads/media/2026/05/alpha.jpg' => $S->betaDoc]]));
                assert_eq(['unresolved_media'], sbh_codes($doc, 'error'), 'a mapped non-image is refused');
                assert_eq($mediaBefore, sbh_count('SELECT COUNT(*) FROM media_files'), 'no media row is ever created');
            });

            unit('phase8b int 4: replace_draft writes one import revision onto the explicit target (the builder adopts it), 409 when stale, the published revision untouched, and another tenant\'s page is not found', function () use ($rt, $editor, $publisher, $S): void {
                $status = sbh_ok(sbh_call($rt, SBH_A, $editor, 'GET', 'status', ['page' => $S->pageA]), 'status')['page'];
                $pubRev = (int) sbh_ok(sbh_call($rt, SBH_A, $publisher, 'POST', 'publish', ['page_id' => $S->pageA, 'expected_revision_id' => (int) $status['active_draft_revision_id']]), 'publish')['revision']['id'];
                $status = sbh_ok(sbh_call($rt, SBH_A, $editor, 'GET', 'status', ['page' => $S->pageA]), 'status')['page'];
                $html = '<article><h2>Replaced</h2><p>new body</p></article>';
                $revCount = sbh_count('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ?', [SBH_A]);

                $stale = sbh_import($rt, SBH_A, $editor, $html, false, ['mode' => 'replace_draft', 'target_page_id' => $S->pageA, 'expected_revision_id' => (int) $status['active_draft_revision_id'] - 1]);
                assert_eq(409, $stale->status);
                assert_eq('concurrency_conflict', $stale->errorCode());
                assert_eq($revCount, sbh_count('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ?', [SBH_A]), 'a stale replace writes nothing');

                $foreign = sbh_report(sbh_import($rt, SBH_B, $editor, $html, true, ['mode' => 'replace_draft', 'target_page_id' => $S->pageA, 'expected_revision_id' => 1]));
                assert_eq(['target_not_found'], sbh_codes($foreign, 'error'), 'another tenant\'s page id is simply not found');

                $data = sbh_ok(sbh_import($rt, SBH_A, $editor, $html, false, ['mode' => 'replace_draft', 'target_page_id' => $S->pageA, 'expected_revision_id' => (int) $status['active_draft_revision_id']]), 'replace');
                assert_eq(201, sbh_import($rt, SBH_A, $editor, $html, false, ['mode' => 'replace_draft', 'target_page_id' => $S->pageA, 'expected_revision_id' => (int) $data['revision']['id']])->status);
                assert_eq('import', $data['revision']['revision_kind'], 'the builder receives the new import draft');
                assert_eq('replaced', $data['report']['committed']['pages'][0]['mode']);
                assert_true(is_array($data['document']) && (int) $data['page']['id'] === $S->pageA);
                $row = $rt->tenants->runAs(SBH_A, static fn() => $rt->pages->find($S->pageA));
                assert_eq($pubRev, (int) $row['published_revision_id'], 'the published revision is never replaced by an import');
                assert_eq('alpha-home', $row['slug'], 'replace keeps the target page address');
                assert_eq(['core.heading', 'core.rich_text'], array_column(sbh_blocks(sbh_draft($rt, SBH_A, $S->pageA)), 'type'));
            });

            unit('phase8b int 5: create mode never overwrites — an existing slug is a route_collision, a platform route is a reserved_route; both refuse the commit with the 422 report and write nothing', function () use ($rt, $editor): void {
                $before = sbh_table_counts();
                $collision = sbh_import($rt, SBH_A, $editor, '<p>x</p>', false, ['slug' => 'alpha-home']);
                assert_eq(422, $collision->status);
                assert_eq(['route_collision'], sbh_codes(sbh_report($collision), 'error'));
                $reserved = sbh_report(sbh_import($rt, SBH_A, $editor, '<p>x</p>', false, ['slug' => 'admin']));
                assert_true(in_array('reserved_route', sbh_codes($reserved, 'error'), true));
                $unsafe = sbh_import($rt, SBH_A, $editor, "<p>a\x00b</p>", false, ['slug' => 'nul-probe']);
                assert_eq(422, $unsafe->status);
                assert_eq(['invalid_source'], sbh_codes(sbh_report($unsafe), 'error'));
                $big = sbh_import($rt, SBH_A, $editor, str_repeat('a', 524289), true, ['slug' => 'big-probe']);
                assert_eq(['source_too_large'], sbh_codes(sbh_report($big), 'error'));
                $overflow = sbh_import($rt, SBH_A, $editor, str_repeat('<section><h2>S</h2><p>t</p></section>', 51), false, ['slug' => 'overflow-probe']);
                assert_eq(['output_limit_exceeded'], sbh_codes(sbh_report($overflow), 'error'));
                $countsAfter = sbh_table_counts();
                unset($before['audit_log'], $countsAfter['audit_log']);
                assert_eq($before, $countsAfter, 'refused imports write nothing');
            });

            unit('phase8b int 6: permission matrix — view refused, a tenant without Studio refused, guests refused, AI origins can never import, CSRF / same-origin / JSON enforced; publish permission never auto-publishes', function () use ($rt, $viewer, $editor, $publisher, $tokenAi): void {
                $html = '<p>perm</p>';
                assert_eq('authorization_error', sbh_import($rt, SBH_A, $viewer, $html, true, ['slug' => 'perm'])->errorCode());
                assert_eq('entitlement_error', sbh_import($rt, SBH_C, $editor, $html, true, ['slug' => 'perm'])->errorCode());
                assert_eq('authentication_error', sbh_import($rt, SBH_A, StudioActor::guest(), $html, true, ['slug' => 'perm'])->errorCode());
                $source = ['html' => $html, 'css' => '', 'title' => null, 'slug' => 'perm', 'page_type' => 'page'];
                assert_throws(StudioAuthorizationException::class, static fn() => $rt->tenants->runAs(SBH_A, static fn() => $rt->app->importHtml($tokenAi, $source, ['mode' => 'create'], true)), 'an MCP-token actor cannot import HTML');
                $assistant = StudioActor::authenticated(85, StudioPermissions::ALL)->asAdminAssistant();
                assert_throws(StudioAuthorizationException::class, static fn() => $rt->tenants->runAs(SBH_A, static fn() => $rt->app->importHtml($assistant, $source, ['mode' => 'create'], false)), 'nor can the admin assistant');
                assert_eq(403, sbh_call($rt, SBH_A, $editor, 'POST', 'import_html', ['html' => $html, 'dry_run' => true, 'slug' => 'perm'], false)->status, 'CSRF');
                $api = new StudioAuthoringApi($rt->app);
                $cross = new StudioApiRequest('POST', 'import_html', [], (string) json_encode(['html' => $html, 'dry_run' => true, 'slug' => 'perm']), 'application/json', true, 'cross-site');
                assert_eq(403, $rt->tenants->runAs(SBH_A, static fn() => $api->handle($cross, $editor))->status);
                $form = new StudioApiRequest('POST', 'import_html', [], 'html=x', 'application/x-www-form-urlencoded', true, 'same-origin');
                assert_eq(415, $rt->tenants->runAs(SBH_A, static fn() => $api->handle($form, $editor))->status);

                $data = sbh_ok(sbh_import($rt, SBH_A, $publisher, $html, false, ['slug' => 'publisher-probe']), 'publisher import');
                $row = $rt->tenants->runAs(SBH_A, static fn() => $rt->pages->find((int) $data['report']['committed']['pages'][0]['page_id']));
                assert_null($row['published_revision_id'], 'studio-builder.publish never turns an import into a publish');
            });

            unit('phase8b int 7: authorization runs BEFORE parsing — an unauthorized caller gets the error, never a parse report, even for hostile or oversized input', function () use ($rt, $viewer): void {
                foreach ([str_repeat('<div>', 300), "<p>\x00</p>", str_repeat('x', 524289)] as $hostile) {
                    $r = sbh_import($rt, SBH_A, $viewer, $hostile, true, ['slug' => 'hostile']);
                    assert_eq('authorization_error', $r->errorCode());
                    assert_true(!isset($r->payload['error']['details']['report']), 'no report: the source was never parsed');
                }
            });

            unit('phase8b int 8: a runtime without DOM/libxml fails closed through the real controller with html_import_unavailable and writes nothing', function (): void {
                $rt2 = StudioRuntimeFactory::build(['html_capability' => static fn(): bool => false]);
                $editor = StudioActor::authenticated(81, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
                $before = sbh_table_counts();
                $r = sbh_import($rt2, SBH_A, $editor, '<p>x</p>', false, ['slug' => 'no-dom']);
                assert_eq(422, $r->status);
                assert_eq(['html_import_unavailable'], sbh_codes(sbh_report($r), 'error'));
                assert_eq($before, sbh_table_counts());
            });

            unit('phase8b int 9: tenant isolation — the tenant comes only from the session; B\'s import is invisible to A, and a tenant_id in the request is refused', function () use ($rt, $editor): void {
                $data = sbh_ok(sbh_import($rt, SBH_B, $editor, '<article><h2>Beta</h2><img src="/uploads/media/2026/05/beta.jpg"></article>', false, ['slug' => 'beta-home']), 'B import');
                $pageB = (int) $data['report']['committed']['pages'][0]['page_id'];
                assert_eq(SBH_B, (int) Database::value('SELECT tenant_id FROM studiobuilder_pages WHERE id = ?', [$pageB]));
                assert_null($rt->tenants->runAs(SBH_A, static fn() => $rt->pages->find($pageB)), 'A cannot see B\'s imported page');
                assert_eq('not_found', sbh_call($rt, SBH_A, $editor, 'GET', 'document', ['page' => $pageB])->errorCode());
                $r = sbh_call($rt, SBH_B, $editor, 'POST', 'import_html', ['html' => '<p>x</p>', 'dry_run' => true, 'slug' => 'x', 'tenant_id' => SBH_A]);
                assert_eq('unknown_field', $r->payload['error']['details']['errors'][0]['code']);
                assert_eq('import', (string) Database::value('SELECT revision_kind FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ? ORDER BY id DESC LIMIT 1', [SBH_B, $pageB]));
            });

            unit('phase8b int 10: Phase 8A package import is unchanged by the shared commit path (kohevo_json report shape, import revision, audit without source_kind)', function () use ($rt, $editor, $S): void {
                $pkg = sbh_ok(sbh_call($rt, SBH_A, $editor, 'GET', 'export_package', ['page' => $S->pageA, 'include_components' => '0', 'include_template' => '0']), 'export')['package'];
                $pkg['items'][0]['slug'] = 'from-package';
                unset($pkg['items'][0]['content_hash']);
                $pkg['items'][0]['content_hash'] = \Slate\Module\StudioBuilder\Package\StudioPackageFormat::itemHash($pkg['items'][0]);
                $data = sbh_ok(sbh_call($rt, SBH_A, $editor, 'POST', 'import_package', ['package' => $pkg, 'dry_run' => false]), 'package import');
                assert_eq('kohevo_json', $data['report']['source_kind']);
                assert_true(!array_key_exists('source_hash', $data['report']) && !array_key_exists('conversion', $data['report']));
                $meta = json_decode((string) Database::value("SELECT meta_json FROM audit_log WHERE action = 'studio.package.imported' ORDER BY id DESC LIMIT 1"), true);
                assert_true(!array_key_exists('source_kind', $meta), 'the 8A audit event is unchanged');
            });
        });
    } finally {
        sbh_drop_db($dbName);
    }
});

if (!empty($studioP8bIntStandalone)) {
    exit(unit_summary());
}
