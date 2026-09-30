<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — Phase 9A tenant
 * public locale.
 *
 * Real MySQL (throwaway database), real plugin activation, real per-tenant
 * entitlements, the PRODUCTION wiring (StudioRuntimeFactory) and the real
 * core I18n. French is made a supported language the way the platform does it
 * (the `i18n_supported_languages` filter). Tenants:
 *   - 101 (A): studio-builder, site language `en`
 *   - 202 (B): studio-builder, site language `fr`
 *
 * Covers: the tenant's `default_language` is the ONE public Studio locale
 * (`<html lang>` + Studio-generated strings); `?lang=`, the session and
 * `X-Slate-Force-Locale` cannot change it; alternating visitor locales never
 * recompile the one stored artifact (it is reused as stored); publish compiles
 * for the tenant locale, not the publisher's session; two tenants with
 * different languages never share output; invalid settings fall back to
 * English without a fatal; a changed setting takes effect; preview and every
 * non-Studio locale keep following the visitor.
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
    $studioP9IntStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Runtime\PublicResponse;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\I18n\I18n;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const SBL_CORE_MIGRATIONS = [
    '0001_core_init', '0002_identity_core', '0011_login_attempts', '0014_tenant_profiles', '0023_installation_identity',
    '0022_remote_license_cache', '0024_remote_license_metadata', '0025_remote_license_cache_installation_id', '0026_remote_license_cache_signed_payload',
];
const SBL_A = 101;
const SBL_B = 202;

function sbl_fresh_db(string $dbName): \PDO
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $host = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '');
    $root = new \PDO($host . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4");
    $pdo = new \PDO($host . ";dbname={$dbName};charset=" . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(SBL_CORE_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function sbl_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    (new \PDO('mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function sbl_with_pdo(\PDO $pdo, callable $fn): mixed
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

function sbl_seed(int $tenantId, string $installationId, array $entitlements): void
{
    license_test_seed_cache($tenantId, [
        'installation_id' => $installationId, 'status' => 'active', 'plan' => 'phase9a-' . implode('-', $entitlements ?: ['none']),
        'entitlements' => $entitlements, 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
    ]);
}

/** Every visitor-controlled locale input, cleared. */
function sbl_clear_visitor(): void
{
    unset($_GET['lang'], $_SESSION['slate_lang'], $_SERVER['HTTP_X_SLATE_FORCE_LOCALE']);
}

/** Run $fn with ONE visitor locale input set to $locale ('query' | 'session' | 'header' | 'none'). */
function sbl_as_visitor(string $via, string $locale, callable $fn): mixed
{
    sbl_clear_visitor();
    match ($via) {
        'query'   => $_GET['lang'] = $locale,
        'session' => $_SESSION['slate_lang'] = $locale,
        'header'  => $_SERVER['HTTP_X_SLATE_FORCE_LOCALE'] = $locale,
        default   => null,
    };
    try {
        return $fn();
    } finally {
        sbl_clear_visitor();
    }
}

/** @return array<string, mixed> */
function sbl_doc(): array
{
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', '');
    $doc['sections'] = [[
        'blocks' => [
            ['bindings' => [], 'children' => [], 'id' => CanonicalDocumentSchema::newBlockId(), 'props' => ['text' => 'Hello', 'level' => 'h2'],
                'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'type' => 'core.heading', 'version' => 1, 'visibility' => CanonicalDocumentSchema::defaultVisibility()],
            ['bindings' => [], 'children' => [], 'id' => CanonicalDocumentSchema::newBlockId(), 'props' => ['title' => 'Why', 'columns' => 2, 'items' => [['heading' => 'Fast', 'body' => 'Very', 'url' => '/fast']]],
                'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'type' => 'core.feature_list', 'version' => 1, 'visibility' => CanonicalDocumentSchema::defaultVisibility()],
        ],
        'global_ref' => null,
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Main',
        'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ]];
    return $doc;
}

/** Create + draft + publish; returns the page id. */
function sbl_publish(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $slug): int
{
    return $rt->tenants->runAs($tenantId, static function () use ($rt, $actor, $slug): int {
        $created = $rt->app->createPage($actor, ucfirst($slug), $slug, 'page', 'standalone');
        $pageId = (int) $created['page']['id'];
        $saved = $rt->app->saveDraft($actor, $pageId, sbl_doc(), (int) $created['revision']['id']);
        $rt->app->publish($actor, $pageId, (int) $saved['revision']['id']);
        return $pageId;
    });
}

function sbl_public(StudioRuntime $rt, int $tenantId, string $path): PublicResponse
{
    $res = $rt->tenants->runAs($tenantId, static fn() => $rt->publicRuntime->handlePath($path, null));
    assert_true($res instanceof PublicResponse && $res->status === 200, "{$path} as tenant {$tenantId}: expected a 200 public page");
    return $res;
}

function sbl_lang(string $html): string
{
    return preg_match('~<html lang="([^"]*)">~', $html, $m) === 1 ? $m[1] : '(none)';
}

/** @return array<string, mixed> the stored published compilation row */
function sbl_artifact(StudioRuntime $rt, int $tenantId, int $pageId): array
{
    $row = $rt->tenants->runAs($tenantId, static fn() => $rt->compilations->findByPageAndMode($pageId, 'published'));
    assert_true(is_array($row), "tenant {$tenantId} page {$pageId}: a stored published artifact");
    return $row;
}

function sbl_set_language(int $tenantId, string $value): void
{
    Database::setSetting('default_language', $value, $tenantId);
}

unit('phase9a integration: the tenant site language is the one public Studio locale (real MySQL, tenants 101/202)', function (): void {
    $dbName = 'slate_sbp9a_' . slate_test_ns();
    $pdo = sbl_fresh_db($dbName);
    // One process runs every suite: a page an earlier suite served through the
    // real adapter left the request-long pin behind, exactly as a real request would.
    I18n::pinLocale(null);
    I18n::resetCache();

    $supportFr = static fn(array $langs): array => $langs + ['fr' => 'Français'];
    \Hook::addFilter('i18n_supported_languages', $supportFr);
    $studioLang = static function (array $paths): array {
        foreach (['fr', 'en'] as $loc) {
            $paths[$loc][] = SLATE_ROOT . '/plugins/studio-builder/lang';
        }
        return $paths;
    };
    // The plugin registers its language pack on boot(); make sure it is present
    // here without booting the whole plugin (removed again below).
    $addedLangPath = !in_array(SLATE_ROOT . '/plugins/studio-builder/lang', \Hook::applyFilters('i18n_lang_paths', [])['fr'] ?? [], true);
    if ($addedLangPath) {
        \Hook::addFilter('i18n_lang_paths', $studioLang);
    }

    try {
        sbl_with_pdo($pdo, static function (): void {
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Studio Admin', 'studio-admin-p9a@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\') ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [SBL_A, 'Tenant A', 'tenant-a-p9a', SBL_B, 'Tenant B', 'tenant-b-p9a']
            );
            sbl_seed($owner, $iid, ['studio-builder']);
            $sel = CommercialModuleRegistry::validateSelection(['studio-builder'], ['studio-builder']);
            assert_true($sel['ok'], 'selection: ' . json_encode($sel));
            $act = \PluginLoader::installFromDisk('studio-builder');
            assert_true(!empty($act['ok']), 'installFromDisk(studio-builder): ' . json_encode($act));
            Media::ensureSchema();
            sbl_seed(SBL_A, $iid, ['studio-builder']);
            sbl_seed(SBL_B, $iid, ['studio-builder']);
            sbl_set_language(SBL_A, 'en');
            sbl_set_language(SBL_B, 'fr');

            $rt = StudioRuntimeFactory::build();
            $publisher = StudioActor::authenticated(84, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            sbl_clear_visitor();

            // Both tenants publish the SAME slug; the publisher's own session is French
            // throughout, so publish-time compilation must not take its locale from it.
            [$pageA, $pageB] = sbl_as_visitor('session', 'fr', static fn(): array => [
                sbl_publish($rt, SBL_A, $publisher, 'about'),
                sbl_publish($rt, SBL_B, $publisher, 'about'),
            ]);
            // The artifacts exactly as publish stored them, before any public request.
            $atPublishA = sbl_artifact($rt, SBL_A, $pageA);
            $atPublishB = sbl_artifact($rt, SBL_B, $pageB);

            unit('phase9a int 1: tenant `en` serves lang="en" with English Studio strings; tenant `fr` serves lang="fr" with French Studio strings — same slug, separate output', function () use ($rt): void {
                $a = sbl_public($rt, SBL_A, '/about')->body;
                $b = sbl_public($rt, SBL_B, '/about')->body;
                assert_eq('en', sbl_lang($a));
                assert_true(str_contains($a, 'Learn more') && !str_contains($a, 'En savoir plus'), 'tenant en: English Studio string');
                assert_eq('fr', sbl_lang($b));
                assert_true(str_contains($b, 'En savoir plus') && !str_contains($b, 'Learn more'), 'tenant fr: French Studio string');
                assert_true(str_contains($b, 'Hello') && str_contains($b, 'Fast'), 'authored content is unchanged single-language content');
            });

            unit('phase9a int 2: ?lang=fr, a French session and X-Slate-Force-Locale: fr never change tenant en\'s public page — and ?lang is not written to the session', function () use ($rt): void {
                $baseline = sbl_public($rt, SBL_A, '/about')->body;
                foreach (['query', 'session', 'header'] as $via) {
                    $html = sbl_as_visitor($via, 'fr', static function () use ($rt, $via): string {
                        $html = sbl_public($rt, SBL_A, '/about')->body;
                        if ($via === 'query') {
                            assert_true(!isset($_SESSION['slate_lang']), '?lang= on a Studio public page is not persisted to the session');
                        }
                        return $html;
                    });
                    assert_eq('en', sbl_lang($html), "{$via}: lang stays en");
                    assert_true(!str_contains($html, 'En savoir plus'), "{$via}: no French Studio string");
                    assert_eq($baseline, $html, "{$via}: byte-identical to the unaffected page");
                }
                // The reverse: tenant fr stays fr for an English visitor.
                foreach (['query', 'session', 'header'] as $via) {
                    $html = sbl_as_visitor($via, 'en', static fn(): string => sbl_public($rt, SBL_B, '/about')->body);
                    assert_eq('fr', sbl_lang($html), "{$via}=en: tenant fr stays fr");
                }
            });

            unit('phase9a int 3: alternating visitor locales reuse the ONE stored artifact as stored — no recompile, no alternating compilation state', function () use ($rt, $pageA): void {
                $before = sbl_artifact($rt, SBL_A, $pageA);
                // A marker only the stored artifact carries: if any request recompiled, it would vanish.
                Database::query("UPDATE studiobuilder_compilations SET compiled_html = CONCAT(compiled_html, '<!--sbl-stored-->') WHERE id = ?", [(int) $before['id']]);
                $sequence = [['query', 'fr'], ['none', ''], ['session', 'fr'], ['header', 'fr'], ['query', 'en'], ['session', 'fr'], ['none', '']];
                foreach ($sequence as $i => [$via, $loc]) {
                    $html = sbl_as_visitor($via, $loc, static fn(): string => sbl_public($rt, SBL_A, '/about')->body);
                    assert_true(str_contains($html, '<!--sbl-stored-->'), "request {$i} ({$via} {$loc}): served the stored artifact, not a recompile");
                    $row = sbl_artifact($rt, SBL_A, $pageA);
                    assert_eq((int) $before['id'], (int) $row['id'], "request {$i}: same artifact row");
                    assert_eq((string) $before['content_hash'], (string) $row['content_hash'], "request {$i}: same compilation identity");
                    assert_eq((string) $before['compiled_at'], (string) $row['compiled_at'], "request {$i}: never re-stored");
                }
                assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?", [SBL_A, $pageA]), 'still one artifact for the page');
            });

            unit('phase9a int 4: publish compiled each artifact for its tenant\'s site locale (not the publisher\'s French session) — public requests then reuse it — and the two tenants never share compilation identity', function () use ($rt, $pageA, $pageB, $atPublishA, $atPublishB): void {
                $a = $atPublishA;
                $b = $atPublishB;
                foreach ([[SBL_A, $pageA, $a], [SBL_B, $pageB, $b]] as [$tenant, $page, $stored]) {
                    $now = sbl_artifact($rt, $tenant, $page);
                    assert_eq((string) $stored['compiled_at'], (string) $now['compiled_at'], "tenant {$tenant}: the publish-time artifact was never re-stored by public requests");
                    assert_eq((string) $stored['content_hash'], (string) $now['content_hash'], "tenant {$tenant}: same compilation identity as at publish");
                }
                $siteA = json_decode((string) $a['head_assets_json'], true)['inputs']['site'] ?? null;
                $siteB = json_decode((string) $b['head_assets_json'], true)['inputs']['site'] ?? null;
                $expectA = $rt->tenants->runAs(SBL_A, static fn() => (new SiteContext(defined('SLATE_URL') ? (string) SLATE_URL : '', \Slate\Services\Content\TenantBranding::siteName(), \Slate\Services\Content\TenantBranding::logoUrls()['light'] ?? '', \Slate\Services\Content\TenantBranding::faviconUrl(), 'en'))->fingerprint());
                assert_eq($expectA, $siteA, 'tenant A artifact was compiled for locale en');
                assert_true($siteA !== $siteB, 'different tenant languages -> different site fingerprints');
                assert_true((string) $a['content_hash'] !== (string) $b['content_hash'], 'no shared compilation identity across tenants');
                assert_true(!str_contains((string) $a['compiled_html'], 'En savoir plus'), 'tenant A stored artifact holds no French string');
                assert_true(str_contains((string) $b['compiled_html'], 'En savoir plus'), 'tenant B stored artifact holds its French string');
                // Interleaved requests keep each tenant on its own language.
                for ($i = 0; $i < 3; $i++) {
                    assert_eq('en', sbl_lang(sbl_public($rt, SBL_A, '/about')->body));
                    assert_eq('fr', sbl_lang(sbl_public($rt, SBL_B, '/about')->body));
                }
            });

            unit('phase9a int 5: an unsupported, malformed or empty site language falls back to English without a fatal; a valid change takes effect on the next request', function () use ($rt): void {
                foreach (['de', 'xx-yy-zz', 'fr"><script>', '../fr', ''] as $bad) {
                    sbl_set_language(SBL_B, $bad);
                    $html = sbl_as_visitor('query', 'fr', static fn(): string => sbl_public($rt, SBL_B, '/about')->body);
                    assert_eq('en', sbl_lang($html), "setting '{$bad}' -> en");
                    assert_true(!str_contains($html, '<script>'), "setting '{$bad}' never reaches output");
                }
                sbl_set_language(SBL_B, 'FR');
                assert_eq('fr', sbl_lang(sbl_public($rt, SBL_B, '/about')->body), 'normalized like core I18n (FR -> fr)');
                sbl_set_language(SBL_B, 'en');
                assert_eq('en', sbl_lang(sbl_public($rt, SBL_B, '/about')->body), 'a changed setting recompiles for the new locale');
                sbl_set_language(SBL_B, 'fr');
                assert_eq('fr', sbl_lang(sbl_public($rt, SBL_B, '/about')->body));
            });

            unit('phase9a int 6: preview and every non-Studio locale lookup keep following the visitor; nothing stays pinned after a render', function () use ($rt, $publisher, $pageA): void {
                $preview = sbl_as_visitor('session', 'fr', static fn() => $rt->tenants->runAs(SBL_A, static fn() => $rt->app->renderPreview($publisher, $pageA)));
                assert_eq('fr', sbl_lang($preview->html), 'authoring preview is unchanged: it follows the editor\'s own locale');
                foreach (['query', 'session', 'header'] as $via) {
                    $loc = sbl_as_visitor($via, 'fr', static function () use ($rt): string {
                        sbl_public($rt, SBL_A, '/about');
                        return $rt->tenants->runAs(SBL_A, static fn(): string => I18n::currentLocale());
                    });
                    assert_eq('fr', $loc, "{$via}: after a Studio render, core I18n is the visitor's again");
                }
                assert_eq('en', $rt->tenants->runAs(SBL_A, static fn(): string => I18n::currentLocale()), 'no visitor input -> tenant default, as before');
            });

            unit('phase9a int 7: the HTTP adapter pins the tenant locale for the rest of a served Studio response (so later output filters see it), and a miss pins nothing', function () use ($rt): void {
                try {
                    $_GET['lang'] = 'fr';
                    ob_start();
                    $miss = $rt->tenants->runAs(SBL_A, static fn(): bool => \StudioBuilder::servePublicPath(false, 'no-such-page'));
                    ob_end_clean();
                    assert_true($miss === false, 'unknown slug: not served');
                    assert_eq('fr', $rt->tenants->runAs(SBL_A, static fn(): string => I18n::currentLocale()), 'a miss leaves the visitor locale (the ordinary 404 is unchanged)');

                    ob_start();
                    $served = $rt->tenants->runAs(SBL_A, static fn(): bool => \StudioBuilder::servePublicPath(false, 'about'));
                    $body = (string) ob_get_clean();
                    assert_true($served === true, 'published page served');
                    assert_eq('en', sbl_lang($body));
                    assert_eq('en', $rt->tenants->runAs(SBL_A, static fn(): string => I18n::currentLocale()), 'after serving, the request stays on the tenant locale despite ?lang=fr');
                } finally {
                    I18n::pinLocale(null);
                    sbl_clear_visitor();
                }
            });
        });
    } finally {
        I18n::pinLocale(null);
        I18n::resetCache();
        sbl_clear_visitor();
        \Hook::removeFilter('i18n_supported_languages', $supportFr);
        if ($addedLangPath) {
            \Hook::removeFilter('i18n_lang_paths', $studioLang);
        }
        sbl_drop_db($dbName);
    }
});

if (!empty($studioP9IntStandalone)) {
    exit(unit_summary());
}
