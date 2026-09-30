<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — Phase 9B public
 * runtime hardening.
 *
 * Real MySQL (throwaway database), real plugin activation (studio-builder;
 * multilang-translate's schema), real per-tenant entitlements, the PRODUCTION
 * wiring (StudioRuntimeFactory), the real plugin HTTP adapter
 * (StudioBuilder::servePublicPath / servePublicHomepage / publicResponse) and
 * multilang-translate's REAL output-buffer callback (its private obCallback,
 * started exactly as its boot() starts it). Tenants:
 *   - 101 (A): studio-builder, site language `en`
 *   - 202 (B): studio-builder, site language `fr`, multilang-translate strings
 *
 * Covers: one stored artifact per page whatever the visitor locale; the ETag
 * is a hash of the exact body sent and the conditional-request matrix; a
 * content-rewriting output buffer suppresses the ETag/304 so a changed
 * translation is never served as "not modified"; no base URL -> no canonical /
 * og:url / og:image; public SEO never reads the draft `seo_json`; a stale
 * compiler version recompiles; the public error paths (render 500 with no
 * detail, lookup and entitlement failures -> not ours); POST/HEAD; the
 * homepage hook; rollback and import never touch the live artifact.
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
    $studioP9bIntStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Render\Cache\StudioCacheKey;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompiler;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Runtime\PublicResponse;
use Slate\Module\StudioBuilder\Runtime\StudioHttpResponder;
use Slate\Module\StudioBuilder\Runtime\StudioPublicRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioReservedRoutes;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\I18n\I18n;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const SBR_CORE_MIGRATIONS = [
    '0001_core_init', '0002_identity_core', '0011_login_attempts', '0014_tenant_profiles', '0023_installation_identity',
    '0022_remote_license_cache', '0024_remote_license_metadata', '0025_remote_license_cache_installation_id', '0026_remote_license_cache_signed_payload',
];
const SBR_A = 101;
const SBR_B = 202;

function sbr_fresh_db(string $dbName): \PDO
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $host = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '');
    $root = new \PDO($host . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4");
    $pdo = new \PDO($host . ";dbname={$dbName};charset=" . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(SBR_CORE_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function sbr_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    (new \PDO('mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function sbr_with_pdo(\PDO $pdo, callable $fn): mixed
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

function sbr_seed(int $tenantId, string $installationId, array $entitlements): void
{
    license_test_seed_cache($tenantId, [
        'installation_id' => $installationId, 'status' => 'active', 'plan' => 'phase9b-' . implode('-', $entitlements ?: ['none']),
        'entitlements' => $entitlements, 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
    ]);
}

/** Every request-level input the tests set, cleared (and no pin left behind). */
function sbr_clear_request(): void
{
    unset($_GET['lang'], $_SESSION['slate_lang'], $_SERVER['HTTP_X_SLATE_FORCE_LOCALE'], $_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['REQUEST_METHOD']);
    I18n::pinLocale(null);
}

/** @return array<string, mixed> */
function sbr_block(string $type, array $props): array
{
    return ['bindings' => [], 'children' => [], 'id' => CanonicalDocumentSchema::newBlockId(), 'props' => $props,
        'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'type' => $type, 'version' => 1, 'visibility' => CanonicalDocumentSchema::defaultVisibility()];
}

/** @return array<string, mixed> */
function sbr_doc(string $heading, array $seo = []): array
{
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', '');
    $doc['seo'] = array_merge($doc['seo'], $seo);
    $doc['sections'] = [[
        'blocks'     => [sbr_block('core.heading', ['text' => $heading, 'level' => 'h2'])],
        'global_ref' => null,
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Main',
        'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ]];
    return $doc;
}

/** Create + draft + publish; returns [pageId, createRevisionId, publishedRevisionId]. */
function sbr_publish(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $slug, array $doc, string $routeMode = 'standalone'): array
{
    return $rt->tenants->runAs($tenantId, static function () use ($rt, $actor, $slug, $doc, $routeMode): array {
        $created = $rt->app->createPage($actor, ucfirst($slug), $slug, 'page', $routeMode);
        $pageId = (int) $created['page']['id'];
        $saved = $rt->app->saveDraft($actor, $pageId, $doc, (int) $created['revision']['id']);
        $pub = $rt->app->publish($actor, $pageId, (int) $saved['revision']['id']);
        return [$pageId, (int) $created['revision']['id'], (int) $pub['revision']['id']];
    });
}

function sbr_public(StudioRuntime $rt, int $tenantId, string $path, ?string $ifNoneMatch = null): ?PublicResponse
{
    return $rt->tenants->runAs($tenantId, static fn() => $rt->publicRuntime->handlePath($path, $ifNoneMatch));
}

function sbr_ok(?PublicResponse $r, string $what): PublicResponse
{
    assert_true($r instanceof PublicResponse && $r->status === 200, "{$what}: expected a 200 public page, got " . ($r === null ? 'null' : $r->status));
    return $r;
}

/** @return array<string, mixed> */
function sbr_artifact(StudioRuntime $rt, int $tenantId, int $pageId): array
{
    $row = $rt->tenants->runAs($tenantId, static fn() => $rt->compilations->findByPageAndMode($pageId, 'published'));
    assert_true(is_array($row), "tenant {$tenantId} page {$pageId}: a stored published artifact");
    return $row;
}

/** The bare 64-hex ETag value of a response. */
function sbr_etag(PublicResponse $r): string
{
    $raw = (string) ($r->headers['ETag'] ?? '');
    assert_true(preg_match('/^"([a-f0-9]{64})"$/', $raw, $m) === 1, 'a strong, quoted 64-hex ETag: ' . $raw);
    return $m[1];
}

/** The ETag a body would get under this page's stored artifact identity. */
function sbr_expected_etag(StudioRuntime $rt, int $tenantId, int $pageId, string $body): string
{
    $row = sbr_artifact($rt, $tenantId, $pageId);
    return StudioCacheKey::etag(StudioCacheKey::published($tenantId, SiteContext::DEFAULT_SITE_KEY, $pageId, (int) $row['revision_id'], (string) $row['content_hash']), $body);
}

function sbr_capture(callable $fn): string
{
    ob_start();
    try {
        $fn();
    } finally {
        $out = (string) ob_get_clean();
    }
    return $out;
}

function sbr_lang(string $html): string
{
    return preg_match('~<html lang="([^"]*)">~', $html, $m) === 1 ? $m[1] : '(none)';
}

unit('phase9b integration: public runtime hardening (real MySQL, tenants 101/202, real multilang-translate buffer)', function (): void {
    $dbName = 'slate_sbp9b_' . slate_test_ns();
    $pdo = sbr_fresh_db($dbName);
    sbr_clear_request();
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
        sbr_with_pdo($pdo, static function (): void {
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Studio Admin', 'studio-admin-p9b@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\') ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [SBR_A, 'Tenant A', 'tenant-a-p9b', SBR_B, 'Tenant B', 'tenant-b-p9b']
            );
            sbr_seed($owner, $iid, ['studio-builder']);
            $sel = CommercialModuleRegistry::validateSelection(['studio-builder'], ['studio-builder']);
            assert_true($sel['ok'], 'selection: ' . json_encode($sel));
            $act = \PluginLoader::installFromDisk('studio-builder');
            assert_true(!empty($act['ok']), 'installFromDisk(studio-builder): ' . json_encode($act));
            // multilang-translate's schema only — NOT activated: PluginLoader lazily boots every
            // active plugin, and its boot() would leave its real output buffer and hooks running
            // for every later suite in this process. int 4 starts that same buffer explicitly.
            (new \ReflectionMethod(\PluginLoader::class, 'executeSqlFile'))->invoke(null, SLATE_ROOT . '/plugins/multilang-translate/install.sql');
            require_once SLATE_ROOT . '/plugins/multilang-translate/MultilangTranslate.php';
            Media::ensureSchema();
            sbr_seed(SBR_A, $iid, ['studio-builder']);
            sbr_seed(SBR_B, $iid, ['studio-builder']);
            Database::setSetting('default_language', 'en', SBR_A);
            Database::setSetting('default_language', 'fr', SBR_B);

            $rt = StudioRuntimeFactory::build();
            $editor    = StudioActor::authenticated(81, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
            $publisher = StudioActor::authenticated(84, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            $S = new \stdClass();
            $S->heroA = $rt->tenants->runAs(SBR_A, static fn(): int => Media::register('/uploads/media/2026/01/hero-a.jpg', ['mime' => 'image/jpeg']));
            $pubSeo = ['title' => 'Published Title', 'description' => 'Published description', 'robots' => 'index,follow', 'og_image_media_id' => $S->heroA];
            [$S->aboutA] = sbr_publish($rt, SBR_A, $publisher, 'about', sbr_doc('Hello', $pubSeo));
            [$S->aboutB] = sbr_publish($rt, SBR_B, $publisher, 'about', sbr_doc('Hello'));
            [$S->homeA] = sbr_publish($rt, SBR_A, $publisher, 'home', sbr_doc('Home heading'), 'homepage');
            [$S->fragile] = sbr_publish($rt, SBR_A, $publisher, 'fragile', sbr_doc('Fragile'));
            [$S->flow, $S->flowCreate, $S->flowPub] = sbr_publish($rt, SBR_A, $publisher, 'flow', sbr_doc('Flow live'));

            unit('phase9b int 1: requests with no override, ?lang=fr, a French session and X-Slate-Force-Locale: fr all get tenant A\'s one locale and the SAME stored artifact; tenant B (fr) stays isolated', function () use ($rt, $S): void {
                $before = sbr_artifact($rt, SBR_A, $S->aboutA);
                $bodies = [];
                foreach (['A none' => [], 'B ?lang=fr' => ['get'], 'C session fr' => ['session'], 'D header fr' => ['header']] as $label => $via) {
                    sbr_clear_request();
                    if ($via === ['get']) {
                        $_GET['lang'] = 'fr';
                    } elseif ($via === ['session']) {
                        $_SESSION['slate_lang'] = 'fr';
                    } elseif ($via === ['header']) {
                        $_SERVER['HTTP_X_SLATE_FORCE_LOCALE'] = 'fr';
                    }
                    $body = sbr_ok(sbr_public($rt, SBR_A, '/about'), $label)->body;
                    sbr_clear_request();
                    assert_eq('en', sbr_lang($body), "{$label}: tenant locale");
                    $row = sbr_artifact($rt, SBR_A, $S->aboutA);
                    foreach (['id', 'revision_id', 'content_hash', 'compiled_at', 'compiler_version'] as $col) {
                        assert_eq((string) $before[$col], (string) $row[$col], "{$label}: artifact {$col} unchanged");
                    }
                    $bodies[$label] = $body;
                }
                assert_eq(1, count(array_unique($bodies)), 'all four requests got the byte-identical page');
                $b = sbr_ok(sbr_public($rt, SBR_B, '/about'), 'tenant B')->body;
                assert_eq('fr', sbr_lang($b));
                assert_true((string) sbr_artifact($rt, SBR_B, $S->aboutB)['content_hash'] !== (string) $before['content_hash'], 'tenant B has its own compilation identity');
            });

            unit('phase9b int 2: the ETag is a strong hash of exactly the body sent; exact, unquoted, weak, * and list If-None-Match answer 304, a non-matching one 200, and a republished page never matches the old ETag', function () use ($rt, $S, $publisher): void {
                $r = sbr_ok(sbr_public($rt, SBR_A, '/fragile'), 'fragile');
                $tag = sbr_etag($r);
                assert_eq(sbr_expected_etag($rt, SBR_A, $S->fragile, $r->body), $tag, 'ETag = hash(artifact identity, final body)');
                assert_true(!str_contains($r->headers['ETag'], (string) $S->fragile) || preg_match('/^"[a-f0-9]{64}"$/', $r->headers['ETag']) === 1, 'opaque: no tenant/page/revision id in clear');

                foreach (['"' . $tag . '"' => 304, $tag => 304, 'W/"' . $tag . '"' => 304, '*' => 304, '"nope", "' . $tag . '"' => 304, '"' . str_repeat('0', 64) . '"' => 200, 'W/"nope"' => 200, '' => 200] as $inm => $status) {
                    $c = sbr_public($rt, SBR_A, '/fragile', $inm);
                    assert_true($c instanceof PublicResponse, "If-None-Match {$inm}: a response");
                    assert_eq($status, $c->status, 'If-None-Match ' . json_encode($inm));
                    if ($status === 304) {
                        assert_eq('', $c->body, '304 carries no body');
                        assert_eq($r->headers['ETag'], $c->headers['ETag'] ?? null, '304 repeats the validator');
                    } else {
                        assert_eq($r->body, $c->body, '200 carries the full page');
                    }
                }

                // A changed final response: republish, then the old ETag must not validate.
                $rt->tenants->runAs(SBR_A, static function () use ($rt, $publisher, $S): void {
                    $page = $rt->pages->find($S->fragile);
                    $saved = $rt->app->saveDraft($publisher, $S->fragile, sbr_doc('Fragile v2'), (int) $page['active_draft_revision_id']);
                    $rt->app->publish($publisher, $S->fragile, (int) $saved['revision']['id']);
                });
                $after = sbr_ok(sbr_public($rt, SBR_A, '/fragile', '"' . $tag . '"'), 'republished');
                assert_true(str_contains($after->body, 'Fragile v2'), 'the new content, in full');
                assert_true(sbr_etag($after) !== $tag, 'a new ETag');
            });

            unit('phase9b int 3: through the real adapter with plain buffering, the sent bytes are exactly what the ETag hashes and a matching If-None-Match gets 304', function () use ($rt, $S): void {
                try {
                    $_SERVER['REQUEST_METHOD'] = 'GET';
                    $resp = sbr_capture(static function () use ($rt, &$outResp): void {
                        $outResp = $rt->tenants->runAs(SBR_A, static fn() => \StudioBuilder::publicResponse(static fn(?string $inm) => $rt->publicRuntime->handlePath('/about', $inm)));
                    });
                    assert_eq('', $resp);
                    assert_true($outResp instanceof PublicResponse && $outResp->status === 200, 'publicResponse under ob_start(): 200');
                    $tag = sbr_etag($outResp);

                    $sent = sbr_capture(static fn() => $rt->tenants->runAs(SBR_A, static fn(): bool => \StudioBuilder::servePublicPath(false, 'about')));
                    I18n::pinLocale(null);
                    assert_eq($outResp->body, $sent, 'the adapter sends the body it hashed');
                    assert_eq(sbr_expected_etag($rt, SBR_A, $S->aboutA, $sent), $tag, 'the ETag describes the client-visible bytes');

                    $_SERVER['HTTP_IF_NONE_MATCH'] = '"' . $tag . '"';
                    $cond = null;
                    sbr_capture(static function () use ($rt, &$cond): void {
                        $cond = $rt->tenants->runAs(SBR_A, static fn() => \StudioBuilder::publicResponse(static fn(?string $inm) => $rt->publicRuntime->handlePath('/about', $inm)));
                    });
                    assert_eq(304, $cond->status, 'unchanged final body -> 304');
                } finally {
                    sbr_clear_request();
                }
            });

            unit('phase9b int 4: with multilang-translate\'s real output buffer rewriting the page, no ETag is published and an old If-None-Match never yields 304 — a changed translation reaches the client', function () use ($rt, $S): void {
                // Tenant B: site language fr; multilang-translate default en with a published fr translation.
                \MLT_LangRepo::ensureDefault(SBR_B);
                \MLT_StringRepo::harvest(SBR_B, 'Hello', 'frontend');
                $stringId = (int) Database::value('SELECT id FROM multilangtranslate_strings WHERE tenant_id = ? AND text_hash = ?', [SBR_B, \MLT_Tokenizer::hash('Hello')]);
                \MLT_StringRepo::saveCell(SBR_B, $stringId, 'fr', 'Bonjour', 'published');
                $mlt = new \MultilangTranslate('multilang-translate', (array) json_decode((string) file_get_contents(SLATE_ROOT . '/plugins/multilang-translate/plugin.json'), true), SLATE_ROOT . '/plugins/multilang-translate');
                // Exactly the buffer MultilangTranslate::boot() starts on every request.
                $startMltBuffer = \Closure::bind(function (int $tid): void {
                    ob_start(function (string $html) use ($tid) {
                        return $this->obCallback($tid, $html);
                    });
                }, $mlt, \MultilangTranslate::class);
                $resetDict = static function (): void {
                    foreach (['dict', 'dictKey'] as $p) {
                        (new \ReflectionProperty(\MLT_Replacer::class, $p))->setValue(null, null);
                    }
                };

                $studio = sbr_ok(sbr_public($rt, SBR_B, '/about'), 'tenant B studio page');
                $studioTag = sbr_etag($studio);

                /** One visitor request through the adapter under the MLT buffer: [client bytes, the response the adapter built]. */
                $request = static function () use ($rt, $startMltBuffer, $studioTag): array {
                    $_SERVER['REQUEST_METHOD'] = 'GET';
                    $_SERVER['HTTP_IF_NONE_MATCH'] = '"' . $studioTag . '"';
                    $built = null;
                    $level = ob_get_level();
                    ob_start();
                    try {
                        $rt->tenants->runAs(SBR_B, static function () use ($rt, $startMltBuffer, &$built): void {
                            $startMltBuffer(SBR_B);
                            $built = \StudioBuilder::publicResponse(static fn(?string $inm) => $rt->publicRuntime->handlePath('/about', $inm));
                            \StudioBuilder::servePublicPath(false, 'about');
                            ob_end_flush(); // the MLT callback rewrites the page, as at the end of a real request
                        });
                    } finally {
                        while (ob_get_level() > $level + 1) {
                            ob_end_clean();
                        }
                        $client = (string) ob_get_clean();
                        sbr_clear_request();
                    }
                    return [$client, $built];
                };

                [$client, $built] = $request();
                assert_true($built instanceof PublicResponse && $built->status === 200, 'the old If-None-Match is ignored: 200, not 304');
                assert_true(!isset($built->headers['ETag']), 'no ETag is published for a body a later buffer rewrites');
                assert_true(str_contains($client, 'Bonjour') && !str_contains($client, '>Hello<'), 'the client got the translated page, in full');
                assert_true(StudioCacheKey::etag(StudioCacheKey::published(SBR_B, 'default', $S->aboutB, (int) sbr_artifact($rt, SBR_B, $S->aboutB)['revision_id'], (string) sbr_artifact($rt, SBR_B, $S->aboutB)['content_hash']), $client) !== $studioTag,
                    'the Studio ETag does not describe the rewritten body (why it must not be sent)');

                \MLT_StringRepo::saveCell(SBR_B, $stringId, 'fr', 'Salut', 'published');
                $resetDict(); // per-process dictionary cache; every real request starts empty
                [$client2, $built2] = $request();
                assert_true($built2 instanceof PublicResponse && $built2->status === 200 && !isset($built2->headers['ETag']), 'still 200 without an ETag');
                assert_true(str_contains($client2, 'Salut') && !str_contains($client2, 'Bonjour'), 'the changed translation reaches the client — never a stale 304');
                $resetDict();
            });

            unit('phase9b int 5: with no configured base URL the public page has no canonical, og:url or og:image — never another host; with SLATE_URL they stay on the configured host', function () use ($rt, $S): void {
                $html = $rt->tenants->runAs(SBR_A, static function () use ($rt, $S): string {
                    $page = PageAddress::fromRow($rt->pages->find($S->aboutA));
                    $ctx = RenderContext::forPublic(SBR_A, new SiteContext(SiteContext::configuredBaseUrl(''), 'Tenant A'), static fn(): bool => true);
                    return $rt->render->renderPublished($page, $ctx)->html;
                });
                assert_true(str_contains($html, '<title>Published Title</title>'));
                foreach (['rel="canonical"', 'og:url', 'greenlightinduction', 'rakibhasaan'] as $absent) {
                    assert_true(!str_contains($html, $absent), "no base URL: no {$absent}");
                }
                // og:image is built by core Media::url() from SLATE_URL itself: with SLATE_URL set it is
                // absolute on the install's own host; with SLATE_URL empty it would be site-relative,
                // which SeoHead now drops (unit: phase9b canonical ... no base URL).
                $slate = defined('SLATE_URL') ? (string) SLATE_URL : '';
                if (preg_match('~<meta property="og:image" content="([^"]+)">~', $html, $og) === 1) {
                    assert_true($slate !== '' && str_starts_with($og[1], $slate . '/uploads/'), 'og:image is only ever on the configured install host: ' . $og[1]);
                }
                $normal = sbr_ok(sbr_public($rt, SBR_A, '/about'), 'with SLATE_URL')->body;
                $base = SiteContext::configuredBaseUrl(defined('SLATE_URL') ? (string) SLATE_URL : '');
                if ($base !== '') {
                    assert_true(str_contains($normal, '<link rel="canonical" href="' . $base . '/about">'), 'canonical on the configured base');
                    assert_true(str_contains($normal, '<meta property="og:image" content="' . $base . '/uploads/media/2026/01/hero-a.jpg">'), 'og:image absolute on the configured base');
                }
            });

            unit('phase9b int 6: public SEO comes only from the published revision — the draft SEO in studiobuilder_pages.seo_json (even tampered) never reaches public output; preview shows the draft, noindex', function () use ($rt, $S, $editor, $publisher): void {
                $rt->tenants->runAs(SBR_A, static function () use ($rt, $S, $editor): void {
                    $page = $rt->pages->find($S->aboutA);
                    $rt->app->saveDraft($editor, $S->aboutA, sbr_doc('Hello draft', ['title' => 'Draft Title', 'description' => 'Draft description', 'robots' => 'noindex,nofollow']), (int) $page['active_draft_revision_id']);
                });
                $seoJson = (string) Database::value('SELECT seo_json FROM studiobuilder_pages WHERE tenant_id = ? AND id = ?', [SBR_A, $S->aboutA]);
                assert_true(str_contains($seoJson, 'Draft Title'), 'precondition: seo_json holds the DRAFT seo');
                Database::query('UPDATE studiobuilder_pages SET seo_json = ? WHERE tenant_id = ? AND id = ?', [json_encode(['title' => 'Tampered', 'canonical_url' => 'https://evil.example/', 'robots' => 'noindex,nofollow']), SBR_A, $S->aboutA]);
                $rt->tenants->runAs(SBR_A, static fn() => $rt->invalidator->invalidatePage($S->aboutA)); // force a recompile from the revision

                $public = sbr_ok(sbr_public($rt, SBR_A, '/about'), 'public')->body;
                assert_true(str_contains($public, '<title>Published Title</title>') && str_contains($public, 'content="Published description"'), 'published title/description');
                assert_true(str_contains($public, '<meta name="robots" content="index,follow">'), 'published robots');
                foreach (['Draft Title', 'Draft description', 'Tampered', 'evil.example', 'Hello draft', 'noindex'] as $absent) {
                    assert_true(!str_contains($public, $absent), "public never shows {$absent}");
                }
                $preview = $rt->tenants->runAs(SBR_A, static fn() => $rt->app->renderPreview($publisher, $S->aboutA))->html;
                assert_true(str_contains($preview, '<title>Draft Title</title>') && str_contains($preview, 'content="noindex,nofollow"') && !str_contains($preview, 'rel="canonical"'), 'preview: the draft, never indexable');
            });

            unit('phase9b int 7: a stored artifact from an older compiler version is not reused — the page recompiles and the new artifact carries the current version', function () use ($rt, $S): void {
                sbr_ok(sbr_public($rt, SBR_A, '/flow'), 'warm');
                $row = sbr_artifact($rt, SBR_A, $S->flow);
                assert_eq(StudioCompiler::COMPILER_VERSION, (string) $row['compiler_version']);
                Database::query("UPDATE studiobuilder_compilations SET compiler_version = '1.0.0', compiled_html = CONCAT(compiled_html, '<!--sbr-old-compiler-->') WHERE id = ?", [(int) $row['id']]);
                $body = sbr_ok(sbr_public($rt, SBR_A, '/flow'), 'after downgrade')->body;
                assert_true(!str_contains($body, 'sbr-old-compiler'), 'the old-version artifact was not served');
                $now = sbr_artifact($rt, SBR_A, $S->flow);
                assert_eq(StudioCompiler::COMPILER_VERSION, (string) $now['compiler_version'], 'recompiled with the current version');
                assert_eq((int) $row['revision_id'], (int) $now['revision_id'], 'still the published revision');
                assert_true(!str_contains((string) $now['compiled_html'], 'sbr-old-compiler'));
            });

            unit('phase9b int 8: public error paths — a render failure is a detail-free 500; a lookup or entitlement failure is "not ours" (null)', function () use ($rt, $S): void {
                $revId = (int) Database::value('SELECT published_revision_id FROM studiobuilder_pages WHERE tenant_id = ? AND id = ?', [SBR_A, $S->fragile]);
                $original = (string) Database::value('SELECT document_json FROM studiobuilder_revisions WHERE tenant_id = ? AND id = ?', [SBR_A, $revId]);
                try {
                    Database::query("UPDATE studiobuilder_revisions SET document_json = '{\"broken\": ' WHERE tenant_id = ? AND id = ?", [SBR_A, $revId]);
                    $rt->tenants->runAs(SBR_A, static fn() => $rt->invalidator->invalidatePage($S->fragile));
                    $err = sbr_public($rt, SBR_A, '/fragile');
                    assert_true($err instanceof PublicResponse, 'a published page that fails to render is still answered');
                    assert_eq(500, $err->status);
                    assert_eq('', $err->body);
                    assert_eq(['Cache-Control' => 'no-store'], $err->headers, 'no ETag, no Studio header');
                    $page = sbr_capture(static fn() => StudioHttpResponder::sendPublic($err));
                    assert_true($page !== '' && str_contains($page, 'Something went wrong'), 'the platform\'s generic error page');
                    foreach (['StudioRenderException', 'compil', 'document_json', 'broken', 'Syntax', 'Stack', '#0 '] as $leak) {
                        assert_true(stripos($page, $leak) === false, "no internal detail: {$leak}");
                    }
                    assert_eq(0, (int) Database::value('SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [SBR_A, $S->fragile]), 'nothing stored for a failed render');
                } finally {
                    Database::query('UPDATE studiobuilder_revisions SET document_json = ? WHERE tenant_id = ? AND id = ?', [$original, SBR_A, $revId]);
                }
                sbr_ok(sbr_public($rt, SBR_A, '/fragile'), 'recovered once the revision is readable');

                Database::query('RENAME TABLE studiobuilder_pages TO studiobuilder_pages_offline');
                try {
                    assert_null(sbr_public($rt, SBR_A, '/about'), 'lookup failure: not ours (the ordinary 404)');
                    assert_null($rt->tenants->runAs(SBR_A, static fn() => $rt->publicRuntime->handleHomepage(null)), 'homepage lookup failure: not ours');
                } finally {
                    Database::query('RENAME TABLE studiobuilder_pages_offline TO studiobuilder_pages');
                }

                $throwing = new StudioPublicRuntime($rt->tenants, $rt->pages, $rt->render, new StudioReservedRoutes(), static function (): bool {
                    throw new \RuntimeException('entitlement backend down');
                });
                assert_null($rt->tenants->runAs(SBR_A, static fn() => $throwing->handlePath('/about', null)), 'entitlement failure: not ours, fail closed');
            });

            unit('phase9b int 9: the adapter serves GET and HEAD alike, never POST; the homepage hook serves only a published homepage', function () use ($rt): void {
                try {
                    $_SERVER['REQUEST_METHOD'] = 'GET';
                    $get = sbr_capture(static fn() => assert_true($rt->tenants->runAs(SBR_A, static fn(): bool => \StudioBuilder::servePublicPath(false, 'about')) === true));
                    I18n::pinLocale(null);
                    $_SERVER['REQUEST_METHOD'] = 'HEAD';
                    $head = sbr_capture(static fn() => assert_true($rt->tenants->runAs(SBR_A, static fn(): bool => \StudioBuilder::servePublicPath(false, 'about')) === true, 'HEAD is served'));
                    I18n::pinLocale(null);
                    assert_eq($get, $head, 'HEAD takes the same path as GET (the web server drops a HEAD body)');
                    foreach (['POST', 'PUT', 'DELETE'] as $method) {
                        $_SERVER['REQUEST_METHOD'] = $method;
                        $out = sbr_capture(static function () use ($rt, $method): void {
                            assert_true($rt->tenants->runAs(SBR_A, static fn(): bool => \StudioBuilder::servePublicPath(false, 'about')) === false, "{$method}: not served");
                            assert_true($rt->tenants->runAs(SBR_A, static fn(): bool => \StudioBuilder::servePublicHomepage(false)) === false, "{$method}: homepage not served");
                        });
                        assert_eq('', $out, "{$method}: nothing written");
                    }
                    $_SERVER['REQUEST_METHOD'] = 'GET';
                    $home = sbr_capture(static fn() => assert_true($rt->tenants->runAs(SBR_A, static fn(): bool => \StudioBuilder::servePublicHomepage(false)) === true, 'tenant A has a published homepage'));
                    I18n::pinLocale(null);
                    assert_true(str_contains($home, 'Home heading'));
                    $none = sbr_capture(static fn() => assert_true($rt->tenants->runAs(SBR_B, static fn(): bool => \StudioBuilder::servePublicHomepage(false)) === false, 'tenant B has none: the landing page stays'));
                    assert_eq('', $none);
                } finally {
                    sbr_clear_request();
                }
            });

            unit('phase9b int 10: rollback and an HTML import into a published page change only the draft — the live artifact and page are untouched until the next publish recompiles', function () use ($rt, $S, $editor, $publisher): void {
                $live = sbr_ok(sbr_public($rt, SBR_A, '/flow'), 'flow')->body;
                $artifact = sbr_artifact($rt, SBR_A, $S->flow);
                $unchanged = static function (string $what) use ($rt, $S, $live, $artifact): void {
                    assert_eq($live, sbr_ok(sbr_public($rt, SBR_A, '/flow'), $what)->body, "{$what}: public page unchanged");
                    $row = sbr_artifact($rt, SBR_A, $S->flow);
                    foreach (['id', 'revision_id', 'content_hash', 'compiled_at'] as $col) {
                        assert_eq((string) $artifact[$col], (string) $row[$col], "{$what}: artifact {$col} unchanged");
                    }
                    assert_eq($S->flowPub, (int) Database::value('SELECT published_revision_id FROM studiobuilder_pages WHERE tenant_id = ? AND id = ?', [SBR_A, $S->flow]), "{$what}: published revision unchanged");
                };

                $rt->tenants->runAs(SBR_A, static function () use ($rt, $S, $editor): void {
                    $page = $rt->pages->find($S->flow);
                    $rt->app->rollback($editor, $S->flow, $S->flowCreate, (int) $page['active_draft_revision_id']);
                });
                $unchanged('rollback');

                $draft = (int) Database::value('SELECT active_draft_revision_id FROM studiobuilder_pages WHERE tenant_id = ? AND id = ?', [SBR_A, $S->flow]);
                $api = new StudioAuthoringApi($rt->app);
                $req = new StudioApiRequest('POST', 'import_html', [], (string) json_encode(['html' => '<section><h2>Imported heading</h2></section>', 'dry_run' => false, 'mode' => 'replace_draft', 'target_page_id' => $S->flow, 'expected_revision_id' => $draft]), 'application/json', true, 'same-origin');
                $res = $rt->tenants->runAs(SBR_A, static fn() => $api->handle($req, $editor));
                assert_true($res->isOk(), 'import committed: ' . substr($res->body(), 0, 400));
                $unchanged('import');

                $rt->tenants->runAs(SBR_A, static function () use ($rt, $S, $publisher): void {
                    $page = $rt->pages->find($S->flow);
                    $rt->app->publish($publisher, $S->flow, (int) $page['active_draft_revision_id']);
                });
                $after = sbr_ok(sbr_public($rt, SBR_A, '/flow'), 'after publish')->body;
                assert_true(str_contains($after, 'Imported heading') && !str_contains($after, 'Flow live'), 'the publish made the imported draft live');
                assert_true((int) sbr_artifact($rt, SBR_A, $S->flow)['revision_id'] !== (int) $artifact['revision_id'], 'recompiled from the new published revision');
            });
        });
    } finally {
        sbr_clear_request();
        I18n::resetCache();
        \Hook::removeFilter('i18n_supported_languages', $supportFr);
        if ($addedLangPath) {
            \Hook::removeFilter('i18n_lang_paths', $studioLang);
        }
        sbr_drop_db($dbName);
    }
});

if (!empty($studioP9bIntStandalone)) {
    exit(unit_summary());
}
