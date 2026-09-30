<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — Phase 9D production
 * hardening.
 *
 * Real MySQL (throwaway database), real plugin activation, real per-tenant
 * entitlements, the PRODUCTION wiring (StudioRuntimeFactory) and the real
 * AuditLog. Tenants:
 *   - 101 (A): studio-builder, site language `en`
 *   - 202 (B): studio-builder, site language `fr`
 *   - 303 (C): NOT entitled to studio-builder
 *
 * Covers: Content-Language = the tenant's locale (never the visitor's) on
 * pages, revalidations and not on the SEO files; Permissions-Policy on public
 * pages; audited security denials (permission, entitlement, CSRF/cross-site,
 * rate limit) as real audit_log rows with safe metadata, written outside any
 * transaction and throttled; routine refusals not audited; structured, leak-
 * free logs for a failed public render and a failed API call; errors stay
 * detail-free, no-store and not cached as content.
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
    $studioP9dIntStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Runtime\PublicResponse;
use Slate\Module\StudioBuilder\Runtime\StudioDenialAudit;
use Slate\Module\StudioBuilder\Runtime\StudioHttpResponder;
use Slate\Module\StudioBuilder\Runtime\StudioLog;
use Slate\Module\StudioBuilder\Runtime\StudioRequestId;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\I18n\I18n;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const SBD_CORE_MIGRATIONS = [
    '0001_core_init', '0002_identity_core', '0011_login_attempts', '0014_tenant_profiles', '0023_installation_identity',
    '0022_remote_license_cache', '0024_remote_license_metadata', '0025_remote_license_cache_installation_id', '0026_remote_license_cache_signed_payload',
];
const SBD_A = 101;
const SBD_B = 202;
const SBD_C = 303;

function sbd_db_params(): array
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    return ['mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : ''), $port];
}

function sbd_fresh_db(string $dbName): \PDO
{
    [$host] = sbd_db_params();
    $root = new \PDO($host . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4");
    $pdo = new \PDO($host . ";dbname={$dbName};charset=" . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(SBD_CORE_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function sbd_drop_db(string $dbName): void
{
    [$host] = sbd_db_params();
    (new \PDO($host . ';charset=' . DB_CHARSET, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function sbd_with_pdo(\PDO $pdo, callable $fn): mixed
{
    $property = new \ReflectionProperty(Database::class, 'pdo');
    $previous = $property->getValue();
    $property->setValue(null, $pdo);
    $props = [];
    $prev = [];
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

function sbd_seed(int $tenantId, string $installationId, array $entitlements): void
{
    license_test_seed_cache($tenantId, [
        'installation_id' => $installationId, 'status' => 'active', 'plan' => 'phase9d-' . implode('-', $entitlements ?: ['none']),
        'entitlements' => $entitlements, 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
    ]);
}

function sbd_clear_request(): void
{
    unset($_GET['lang'], $_SESSION['slate_lang'], $_SERVER['HTTP_X_SLATE_FORCE_LOCALE'], $_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['REQUEST_METHOD']);
    I18n::pinLocale(null);
}

/** @return array<string, mixed> */
function sbd_doc(string $heading): array
{
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', '');
    $doc['sections'] = [[
        'blocks'     => [[
            'bindings' => [], 'children' => [], 'id' => CanonicalDocumentSchema::newBlockId(), 'props' => ['text' => $heading, 'level' => 'h2'],
            'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'type' => 'core.heading', 'version' => 1, 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        ]],
        'global_ref' => null,
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Main',
        'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ]];
    return $doc;
}

function sbd_publish(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $slug, array $doc): int
{
    return $rt->tenants->runAs($tenantId, static function () use ($rt, $actor, $slug, $doc): int {
        $created = $rt->app->createPage($actor, ucfirst($slug), $slug, 'page', 'standalone');
        $pageId = (int) $created['page']['id'];
        $saved = $rt->app->saveDraft($actor, $pageId, $doc, (int) $created['revision']['id']);
        $rt->app->publish($actor, $pageId, (int) $saved['revision']['id']);
        return $pageId;
    });
}

function sbd_public(StudioRuntime $rt, int $tenantId, string $path, ?string $ifNoneMatch = null): ?PublicResponse
{
    return $rt->tenants->runAs($tenantId, static fn() => $rt->publicRuntime->handlePath($path, $ifNoneMatch));
}

function sbd_ok(?PublicResponse $r, string $what): PublicResponse
{
    assert_true($r instanceof PublicResponse && $r->status === 200, "{$what}: expected a 200 public page, got " . ($r === null ? 'null' : $r->status));
    return $r;
}

/** @return list<array<string, mixed>> */
function sbd_denials(): array
{
    return Database::rows("SELECT id, tenant_id, action, target, meta_json FROM audit_log WHERE action LIKE 'studio.denied.%' ORDER BY id");
}

unit('phase9d integration: production hardening (real MySQL, tenants 101/202/303, real AuditLog)', function (): void {
    $dbName = 'slate_sbp9d_' . slate_test_ns();
    $pdo = sbd_fresh_db($dbName);
    sbd_clear_request();
    I18n::resetCache();

    $supportFr = static fn(array $langs): array => $langs + ['fr' => 'Français'];
    \Hook::addFilter('i18n_supported_languages', $supportFr);
    $studioLang = static function (array $paths): array {
        foreach (['fr', 'en'] as $loc) {
            $paths[$loc][] = SLATE_ROOT . '/plugins/studio-builder/lang';
        }
        return $paths;
    };
    $addedLangPath = !in_array(SLATE_ROOT . '/plugins/studio-builder/lang', \Hook::applyFilters('i18n_lang_paths', [])['fr'] ?? [], true);
    if ($addedLangPath) {
        \Hook::addFilter('i18n_lang_paths', $studioLang);
    }

    try {
        sbd_with_pdo($pdo, static function (): void {
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Studio Admin', 'studio-admin-p9d@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\'), (?,?,?,\'active\') ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [SBD_A, 'Tenant A', 'tenant-a-p9d', SBD_B, 'Tenant B', 'tenant-b-p9d', SBD_C, 'Tenant C', 'tenant-c-p9d']
            );
            sbd_seed($owner, $iid, ['studio-builder']);
            $sel = CommercialModuleRegistry::validateSelection(['studio-builder'], ['studio-builder']);
            assert_true($sel['ok'], 'selection: ' . json_encode($sel));
            $act = \PluginLoader::installFromDisk('studio-builder');
            assert_true(!empty($act['ok']), 'installFromDisk(studio-builder): ' . json_encode($act));
            Media::ensureSchema();
            sbd_seed(SBD_A, $iid, ['studio-builder']);
            sbd_seed(SBD_B, $iid, ['studio-builder']);
            sbd_seed(SBD_C, $iid, []); // tenant C: licensed for nothing
            Database::setSetting('default_language', 'en', SBD_A);
            Database::setSetting('default_language', 'fr', SBD_B);

            $rt = StudioRuntimeFactory::build();
            $publisher = StudioActor::authenticated(84, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            $S = new \stdClass();
            $S->aboutA = sbd_publish($rt, SBD_A, $publisher, 'about', sbd_doc('Hello'));
            $S->aboutB = sbd_publish($rt, SBD_B, $publisher, 'about', sbd_doc('Bonjour'));
            $S->fragile = sbd_publish($rt, SBD_A, $publisher, 'fragile', sbd_doc('Fragile'));

            unit('phase9d int 1: Content-Language is the tenant\'s site locale — never the visitor\'s — and matches <html lang>; tenants stay isolated', function () use ($rt): void {
                $langOf = static fn(string $html): string => preg_match('~<html lang="([^"]*)">~', $html, $m) === 1 ? $m[1] : '(none)';
                foreach (['none' => [], '?lang=fr' => ['get'], 'session fr' => ['session'], 'header fr' => ['header']] as $label => $via) {
                    sbd_clear_request();
                    if ($via === ['get']) {
                        $_GET['lang'] = 'fr';
                    } elseif ($via === ['session']) {
                        $_SESSION['slate_lang'] = 'fr';
                    } elseif ($via === ['header']) {
                        $_SERVER['HTTP_X_SLATE_FORCE_LOCALE'] = 'fr';
                    }
                    $r = sbd_ok(sbd_public($rt, SBD_A, '/about'), "tenant A ({$label})");
                    sbd_clear_request();
                    assert_eq('en', $r->headers['Content-Language'] ?? null, "tenant A ({$label}): the tenant's locale, not the visitor's");
                    assert_eq($langOf($r->body), $r->headers['Content-Language'], "tenant A ({$label}): header = <html lang>");
                }
                $b = sbd_ok(sbd_public($rt, SBD_B, '/about'), 'tenant B');
                assert_eq('fr', $b->headers['Content-Language'] ?? null, 'tenant B has its own locale');
                assert_eq('fr', $langOf($b->body));

                // Revalidation: the 304 repeats the header (and nothing about it changes the ETag).
                $a = sbd_ok(sbd_public($rt, SBD_A, '/about'), 'tenant A');
                $etag = $a->headers['ETag'];
                $c = sbd_public($rt, SBD_A, '/about', $etag);
                assert_true($c instanceof PublicResponse && $c->status === 304, 'a matching If-None-Match is a 304');
                assert_eq('en', $c->headers['Content-Language'] ?? null, 'the 304 carries Content-Language');
                assert_eq($etag, $c->headers['ETag'] ?? null);

                // Public-page hardening headers ride along; the existing cache contract is unchanged.
                assert_eq('public, no-cache', $a->headers['Cache-Control']);
                assert_eq('camera=(), microphone=(), geolocation=(), payment=(), usb=()', $a->headers['Permissions-Policy'] ?? null);
                assert_eq('nosniff', $a->headers['X-Content-Type-Options']);
                assert_true(!isset($a->headers['Set-Cookie']) && !isset($a->headers['X-Request-Id']), 'no cookie or request id on a cacheable page');

                // The SEO files are not HTML pages: no Content-Language, no Permissions-Policy.
                $robots = $rt->tenants->runAs(SBD_A, static fn() => $rt->publicRuntime->handlePath('/robots.txt', null));
                assert_true($robots instanceof PublicResponse && $robots->status === 200, 'robots.txt is served');
                assert_true(!isset($robots->headers['Content-Language']) && !isset($robots->headers['Permissions-Policy']), 'only HTML pages carry them');
            });

            unit('phase9d int 2: denials of a signed-in user are real audit rows with safe metadata — permission, entitlement, CSRF, cross-site, rate limit — written outside any transaction; routine refusals are not audited', function () use ($rt, $S): void {
                Database::query('DELETE FROM audit_log');
                StudioRequestId::reset();
                $state = null;
                $inTransaction = [];
                $auditor = static function (string $code, array $meta) use (&$state, &$inTransaction): void {
                    $inTransaction[] = Database::get()->inTransaction();
                    StudioDenialAudit::record($state, $code, $meta);
                };
                $viewer = StudioActor::authenticated(81, [StudioPermissions::VIEW]);
                $everything = StudioActor::authenticated(84, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
                $post = static fn(string $action, array $body, bool $csrf = true, ?string $site = 'same-origin'): StudioApiRequest
                    => new StudioApiRequest('POST', $action, [], (string) json_encode($body), 'application/json', $csrf, $site);
                $run = static fn(int $tenant, StudioAuthoringApi $api, StudioApiRequest $req, StudioActor $actor) => $rt->tenants->runAs($tenant, static fn() => $api->handle($req, $actor));

                $api = new StudioAuthoringApi($rt->app, null, null, $auditor);
                $secretBody = ['page_id' => $S->aboutA, 'expected_revision_id' => 1, 'summary' => 'SECRET-BODY-MARKER hunter2'];

                // 1. permission: a viewer tries to publish
                $r = $run(SBD_A, $api, $post('publish', $secretBody), $viewer);
                assert_eq(403, $r->status);
                assert_eq('authorization_error', $r->errorCode());
                $rows = sbd_denials();
                assert_eq(1, count($rows), 'one audit row for the permission denial');
                assert_eq('studio.denied.authorization_error', $rows[0]['action']);
                assert_eq(SBD_A, (int) $rows[0]['tenant_id'], 'recorded against the tenant it happened in');
                $meta = json_decode((string) $rows[0]['meta_json'], true);
                assert_eq(['status' => 403, 'method' => 'POST', 'action' => 'publish', 'request_id' => StudioRequestId::current()], $meta, 'exactly these fields');
                assert_true(!str_contains((string) $rows[0]['meta_json'], 'SECRET-BODY-MARKER') && !str_contains((string) $rows[0]['meta_json'], 'hunter2'), 'no request body in the audit row');
                assert_eq(StudioRequestId::current(), $r->headers()['X-Request-Id'], 'the response header carries the id the audit row holds');

                // 2. entitlement: a fully-permitted actor on a tenant that is not licensed
                $r = $run(SBD_C, $api, new StudioApiRequest('GET', 'pages'), $everything);
                assert_eq(403, $r->status);
                assert_eq('entitlement_error', $r->errorCode());
                $rows = sbd_denials();
                assert_eq(2, count($rows));
                assert_eq('studio.denied.entitlement_error', $rows[1]['action']);
                assert_eq(SBD_C, (int) $rows[1]['tenant_id']);

                // 3. CSRF token and cross-site fetch; 4. rate limit
                $r = $run(SBD_A, $api, $post('publish', $secretBody, false), $everything);
                assert_eq('csrf_error', $r->errorCode());
                $r = $run(SBD_A, $api, $post('publish', $secretBody, true, 'cross-site'), $everything);
                assert_eq('csrf_error', $r->errorCode());
                $limited = new StudioAuthoringApi($rt->app, static fn(): bool => false, null, $auditor);
                $r = $run(SBD_A, $limited, $post('publish', $secretBody), $everything);
                assert_eq(429, $r->status);
                $byAction = array_column(sbd_denials(), 'action');
                assert_eq(['studio.denied.authorization_error', 'studio.denied.entitlement_error', 'studio.denied.csrf_error', 'studio.denied.rate_limited'], $byAction, 'the second CSRF denial (cross-site) falls inside the throttle window');
                foreach ($inTransaction as $flag) {
                    assert_true($flag === false, 'auditing never runs inside an open transaction (AuditLog can implicitly commit)');
                }

                // Throttle: a flood of the same denial is ONE row per window (+ a count once the window closes).
                $before = count(sbd_denials());
                for ($i = 0; $i < 25; $i++) {
                    $run(SBD_A, $api, $post('publish', $secretBody), $viewer);
                }
                assert_eq($before, count(sbd_denials()), '25 more permission denials in the window add no rows');
                $state['authorization_error']['t'] -= StudioDenialAudit::WINDOW_SECONDS;
                $run(SBD_A, $api, $post('publish', $secretBody), $viewer);
                $rows = sbd_denials();
                assert_eq($before + 1, count($rows));
                assert_eq(25, json_decode((string) end($rows)['meta_json'], true)['suppressed_since_last'] ?? null, 'the skipped denials are counted onto the next row');

                // Routine refusals leave no trail.
                $before = count(sbd_denials());
                $run(SBD_A, $api, new StudioApiRequest('GET', 'pages'), StudioActor::guest());                                   // 401
                $run(SBD_A, $api, new StudioApiRequest('GET', 'nope'), $everything);                                              // 404
                $run(SBD_A, $api, $post('publish', ['page_id' => 'not-a-number']), $everything);                                  // 422
                $run(SBD_A, $api, $post('publish', ['page_id' => 999999, 'expected_revision_id' => 1]), $everything);             // 404 page
                assert_eq($before, count(sbd_denials()), 'unauthenticated, unknown, invalid and not-found requests are not audited');
            });

            unit('phase9d int 3: failures are logged as structured, leak-free lines; the client gets a generic body, an id header and no-store', function () use ($rt, $S): void {
                $lines = [];
                StudioLog::useSink(static function (string $line, string $level) use (&$lines): void {
                    $lines[] = [$level, $line];
                });
                StudioRequestId::reset();
                $everything = StudioActor::authenticated(84, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
                try {
                    // (a) a public render failure: the published document cannot be decoded
                    $revId = (int) Database::value('SELECT published_revision_id FROM studiobuilder_pages WHERE tenant_id = ? AND id = ?', [SBD_A, $S->fragile]);
                    $original = (string) Database::value('SELECT document_json FROM studiobuilder_revisions WHERE tenant_id = ? AND id = ?', [SBD_A, $revId]);
                    try {
                        Database::query("UPDATE studiobuilder_revisions SET document_json = '{\"broken\": \"TENANT-DOC-CONTENT\"' WHERE tenant_id = ? AND id = ?", [SBD_A, $revId]);
                        $rt->tenants->runAs(SBD_A, static fn() => $rt->invalidator->invalidatePage($S->fragile));
                        $err = sbd_public($rt, SBD_A, '/fragile');
                    } finally {
                        Database::query('UPDATE studiobuilder_revisions SET document_json = ? WHERE tenant_id = ? AND id = ?', [$original, SBD_A, $revId]);
                    }
                    assert_true($err instanceof PublicResponse && $err->status === 500, 'a published page that cannot render is a 500');
                    assert_eq('', $err->body);
                    assert_eq(['Cache-Control' => 'no-store'], $err->headers, 'not cacheable, no ETag, no Content-Language, no Studio header');
                    assert_true(!isset($err->headers['Content-Language']) && !isset($err->headers['ETag']), 'an error is never content');
                    assert_eq(1, count($lines), 'one log line for the failure: ' . json_encode($lines));
                    [$level, $line] = $lines[0];
                    assert_eq('error', $level);
                    assert_true(str_starts_with($line, 'req=' . StudioRequestId::current() . ' studio.public.render failed: '), $line);
                    foreach (['TENANT-DOC-CONTENT', 'broken', 'document_json', 'studiobuilder_', 'SELECT', '/Users/', SLATE_ROOT] as $leak) {
                        assert_true(!str_contains($line, $leak), "the log line has no {$leak}");
                    }
                    assert_true(preg_match('/ at [A-Za-z]+\.php:\d+/', $line) === 1, 'it says where: ' . $line);
                    assert_true(!str_contains($line, (string) SBD_A) || preg_match('/req=[a-f0-9]{16}/', $line) === 1, 'the request id is random, not a tenant/page id');
                    sbd_public($rt, SBD_A, '/fragile'); // recovered once readable (and recompiled)
                    $lines = [];

                    // (b) an API failure that is NOT a Studio exception: the database is gone
                    $apiLines = [];
                    $api = new StudioAuthoringApi($rt->app, null, static function (string $m) use (&$apiLines): void {
                        $apiLines[] = $m;
                    });
                    $deniedBefore = count(sbd_denials());
                    Database::query('RENAME TABLE studiobuilder_pages TO studiobuilder_pages_offline');
                    try {
                        $res = $rt->tenants->runAs(SBD_A, static fn() => $api->handle(new StudioApiRequest('GET', 'pages'), $everything));
                    } finally {
                        Database::query('RENAME TABLE studiobuilder_pages_offline TO studiobuilder_pages');
                    }
                    assert_eq(500, $res->status);
                    assert_eq('server_error', $res->errorCode());
                    $body = $res->body();
                    foreach (['studiobuilder_pages', 'SQLSTATE', 'PDOException', 'Base table', SLATE_ROOT, '.php'] as $leak) {
                        assert_true(!str_contains($body, $leak), "the API 500 body has no {$leak}");
                    }
                    assert_eq('private, no-store, max-age=0', $res->headers()['Cache-Control']);
                    assert_eq(StudioRequestId::current(), $res->headers()['X-Request-Id'], 'the client can quote the id');
                    assert_eq(1, count($apiLines));
                    assert_true(preg_match('/^req=[a-f0-9]{16} studio\.api\.pages failed: PDOException at [A-Za-z]+\.php:\d+ sqlstate=[0-9A-Z]{5}$/', $apiLines[0]) === 1, 'class, location and SQLSTATE only: ' . $apiLines[0]);
                    assert_true(!str_contains($apiLines[0], 'studiobuilder_pages') && !str_contains($apiLines[0], 'Base table'), 'and not the SQL or table name from the driver message');
                    assert_eq($deniedBefore, count(sbd_denials()), 'a server failure is logged, not audited as a denial');
                } finally {
                    StudioLog::useSink(null);
                }
            });

            unit('phase9d int 4: a public 404 stays anti-enumeration-safe — draft, archived, unknown and unlicensed are indistinguishable "not ours"', function () use ($rt, $publisher): void {
                $draftOnly = $rt->tenants->runAs(SBD_A, static function () use ($rt, $publisher): int {
                    $created = $rt->app->createPage($publisher, 'Secret draft', 'secret-draft', 'page', 'standalone');
                    return (int) $created['page']['id'];
                });
                assert_true($draftOnly > 0);
                $unknown = sbd_public($rt, SBD_A, '/does-not-exist');
                $draft = sbd_public($rt, SBD_A, '/secret-draft');
                $unlicensed = sbd_public($rt, SBD_C, '/about');
                $otherTenant = sbd_public($rt, SBD_B, '/fragile');
                foreach (['unknown' => $unknown, 'draft-only' => $draft, 'unlicensed tenant' => $unlicensed, 'other tenant\'s page' => $otherTenant] as $what => $r) {
                    assert_null($r, "{$what}: null -> the platform's ordinary 404, byte for byte");
                }
            });
        });
    } finally {
        sbd_clear_request();
        I18n::resetCache();
        \Hook::removeFilter('i18n_supported_languages', $supportFr);
        if ($addedLangPath) {
            \Hook::removeFilter('i18n_lang_paths', $studioLang);
        }
        sbd_drop_db($dbName);
    }
});

if (!empty($studioP9dIntStandalone)) {
    exit(unit_summary());
}
