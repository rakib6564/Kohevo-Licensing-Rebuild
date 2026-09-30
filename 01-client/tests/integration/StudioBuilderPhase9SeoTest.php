<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — Phase 9C SEO
 * settings, sitemap.xml, robots.txt and public-address hygiene.
 *
 * Real MySQL (throwaway database), real plugin install, real per-tenant
 * entitlements, the PRODUCTION wiring (StudioRuntimeFactory with a fixed
 * site context standing in for SLATE_URL), the real authoring API and the
 * real MCP adapter. Tenants:
 *   - 101 (A): studio-builder, many pages in every lifecycle state
 *   - 202 (B): studio-builder, its own pages and media
 *   - 303 (C): no studio-builder entitlement
 *
 * Covers: sitemap inclusion/exclusion rules, canonical policy, homepage,
 * tenant isolation, missing base URL, XML validity, draft -> publish ->
 * rollback behaviour of SEO and of the sitemap, `seo_json` being
 * non-authoritative, the SEO edit round-trip through the authoring API,
 * robots.txt, reserved-route and page/landing slug protection on every
 * command path (application, API, MCP, import, address update) and the
 * sitemap's query cost.
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
    $studioP9cIntStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Mcp\StudioMcpAdapter;
use Slate\Module\StudioBuilder\Mcp\StudioMcpToolException;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Runtime\PublicResponse;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\Runtime\StudioSitemapService;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const SBS9_CORE_MIGRATIONS = [
    '0001_core_init', '0002_identity_core', '0011_login_attempts', '0014_tenant_profiles', '0023_installation_identity',
    '0022_remote_license_cache', '0024_remote_license_metadata', '0025_remote_license_cache_installation_id', '0026_remote_license_cache_signed_payload',
];
const SBS9_A = 101;
const SBS9_B = 202;

function sbs9_fresh_db(string $dbName): \PDO
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $host = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '');
    $root = new \PDO($host . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4");
    $pdo = new \PDO($host . ";dbname={$dbName};charset=" . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(SBS9_CORE_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function sbs9_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    (new \PDO('mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function sbs9_with_pdo(\PDO $pdo, callable $fn): mixed
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

function sbs9_seed(int $tenantId, string $installationId, array $entitlements): void
{
    license_test_seed_cache($tenantId, [
        'installation_id' => $installationId, 'status' => 'active', 'plan' => 'phase9b-' . implode('-', $entitlements ?: ['none']),
        'entitlements' => $entitlements, 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
    ]);
}


function sbs9_block(string $type, array $props): array
{
    return ['bindings' => [], 'children' => [], 'id' => CanonicalDocumentSchema::newBlockId(), 'props' => $props,
        'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'type' => $type, 'version' => 1, 'visibility' => CanonicalDocumentSchema::defaultVisibility()];
}

/** @return array<string, mixed> */
function sbs9_doc(string $heading, array $seo = [], string $type = 'page'): array
{
    $doc = CanonicalDocumentSchema::emptyDocument($type, 'default', '');
    $doc['seo'] = array_merge($doc['seo'], $seo);
    $doc['sections'] = [[
        'blocks'     => [sbs9_block('core.heading', ['text' => $heading, 'level' => 'h2'])],
        'global_ref' => null,
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Main',
        'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ]];
    return $doc;
}

/** Create + draft + publish; returns [pageId, createRevisionId, publishedRevisionId]. */
function sbs9_publish(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $slug, array $doc, string $routeMode = 'standalone', string $pageType = 'page'): array
{
    return $rt->tenants->runAs($tenantId, static function () use ($rt, $actor, $slug, $doc, $routeMode, $pageType): array {
        $created = $rt->app->createPage($actor, ucfirst($slug), $slug, $pageType, $routeMode);
        $pageId = (int) $created['page']['id'];
        $saved = $rt->app->saveDraft($actor, $pageId, $doc, (int) $created['revision']['id']);
        $pub = $rt->app->publish($actor, $pageId, (int) $saved['revision']['id']);
        return [$pageId, (int) $created['revision']['id'], (int) $pub['revision']['id']];
    });
}

const SBS9_C = 303;
const SBS9_SITE = 'https://site.example';

function sbs9_file(StudioRuntime $rt, int $tenantId, string $path, ?string $ifNoneMatch = null): ?PublicResponse
{
    return $rt->tenants->runAs($tenantId, static fn() => $rt->publicRuntime->handlePath($path, $ifNoneMatch));
}

/** @return list<string> the <loc> values of a 200 sitemap, after asserting it is valid XML */
function sbs9_locs(?PublicResponse $r, string $what = 'sitemap'): array
{
    assert_true($r instanceof PublicResponse && $r->status === 200, "{$what}: expected 200, got " . ($r === null ? 'null' : $r->status));
    $dom = new DOMDocument();
    assert_true($dom->loadXML($r->body, LIBXML_NONET), "{$what}: valid XML");
    $locs = [];
    foreach ($dom->getElementsByTagName('loc') as $node) {
        $locs[] = $node->textContent;
    }
    return $locs;
}

/** @return list<string> */
function sbs9_sitemap(StudioRuntime $rt, int $tenantId): array
{
    return sbs9_locs(sbs9_file($rt, $tenantId, '/sitemap.xml'));
}

function sbs9_page_public(StudioRuntime $rt, int $tenantId, string $slug): string
{
    $r = $rt->tenants->runAs($tenantId, static fn() => $rt->publicRuntime->handlePath('/' . $slug));
    assert_true($r instanceof PublicResponse && $r->status === 200, "public /{$slug}: 200");
    return $r->body;
}

function sbs9_draft(StudioRuntime $rt, int $tenantId, StudioActor $actor, int $pageId, array $doc): int
{
    return $rt->tenants->runAs($tenantId, static function () use ($rt, $actor, $pageId, $doc): int {
        $page = $rt->pages->find($pageId);
        return (int) $rt->app->saveDraft($actor, $pageId, $doc, (int) $page['active_draft_revision_id'])['revision']['id'];
    });
}

function sbs9_publish_draft(StudioRuntime $rt, int $tenantId, StudioActor $actor, int $pageId): void
{
    $rt->tenants->runAs($tenantId, static function () use ($rt, $actor, $pageId): void {
        $page = $rt->pages->find($pageId);
        $rt->app->publish($actor, $pageId, (int) $page['active_draft_revision_id']);
    });
}

function sbs9_sql(string $sql, array $params): void
{
    Database::query($sql, $params);
}

/** @return array{string, array<string, mixed>} [error code, issue] of the first validation issue a create is refused with */
function sbs9_refusal(callable $fn): array
{
    try {
        $fn();
    } catch (StudioValidationException $e) {
        return [(string) $e->errors()[0]['code'], $e->errors()[0]];
    }
    assert_true(false, 'expected the create to be refused');
    return ['', []];
}

unit('phase9c integration: SEO settings, sitemap.xml, robots.txt and slug hygiene (real MySQL, tenants 101/202/303)', function (): void {
    $dbName = 'slate_sbp9c_' . slate_test_ns();
    $pdo = sbs9_fresh_db($dbName);
    try {
        sbs9_with_pdo($pdo, static function (): void {
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Studio Admin', 'studio-admin-p9c@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\'), (?,?,?,\'active\') ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [SBS9_A, 'Tenant A', 'tenant-a-p9c', SBS9_B, 'Tenant B', 'tenant-b-p9c', SBS9_C, 'Tenant C', 'tenant-c-p9c']
            );
            sbs9_seed($owner, $iid, ['studio-builder']);
            $sel = CommercialModuleRegistry::validateSelection(['studio-builder'], ['studio-builder']);
            assert_true($sel['ok'], 'selection: ' . json_encode($sel));
            $act = \PluginLoader::installFromDisk('studio-builder');
            assert_true(!empty($act['ok']), 'installFromDisk(studio-builder): ' . json_encode($act));
            Media::ensureSchema();
            sbs9_seed(SBS9_A, $iid, ['studio-builder']);
            sbs9_seed(SBS9_B, $iid, ['studio-builder']);
            sbs9_seed(SBS9_C, $iid, []);

            $site = static fn(): SiteContext => new SiteContext(SBS9_SITE, 'Site');
            $rt = StudioRuntimeFactory::build(['site' => $site, 'known_prefixes' => static fn(): array => ['custom-module', 'forms']]);
            $rtNoBase = StudioRuntimeFactory::build(['site' => static fn(): SiteContext => new SiteContext(SiteContext::configuredBaseUrl(''), 'Site')]);
            $editor    = StudioActor::authenticated(81, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
            $publisher = StudioActor::authenticated(84, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);

            $S = new \stdClass();
            $S->heroA = $rt->tenants->runAs(SBS9_A, static fn(): int => Media::register('/uploads/media/2026/01/hero-a.jpg', ['mime' => 'image/jpeg']));
            $S->heroB = $rt->tenants->runAs(SBS9_B, static fn(): int => Media::register('/uploads/media/2026/01/hero-b.jpg', ['mime' => 'image/jpeg']));
            $pub = ['title' => 'Published Title', 'description' => 'Published description', 'robots' => 'index,follow', 'og_image_media_id' => $S->heroA];
            [$S->about, , $S->aboutV1] = sbs9_publish($rt, SBS9_A, $publisher, 'about', sbs9_doc('About', $pub));
            [$S->home] = sbs9_publish($rt, SBS9_A, $publisher, 'home', sbs9_doc('Home'), 'homepage');
            [$S->team] = sbs9_publish($rt, SBS9_A, $publisher, 'team', sbs9_doc('Team', ['robots' => 'noindex,follow']));
            [$S->moved] = sbs9_publish($rt, SBS9_A, $publisher, 'moved', sbs9_doc('Moved', ['canonical_url' => '/about']));
            [$S->foreign] = sbs9_publish($rt, SBS9_A, $publisher, 'foreign', sbs9_doc('Foreign', ['canonical_url' => 'https://evil.example/x']));
            [$S->samehost] = sbs9_publish($rt, SBS9_A, $publisher, 'samehost', sbs9_doc('Same host', ['canonical_url' => SBS9_SITE . '/samehost']));
            [$S->promo] = sbs9_publish($rt, SBS9_A, $publisher, 'promo', sbs9_doc('Promo', [], 'landing'), 'standalone', 'landing');
            [$S->twin] = sbs9_publish($rt, SBS9_A, $publisher, 'twin', sbs9_doc('Twin page'));
            [$S->twinLanding] = sbs9_publish($rt, SBS9_A, $publisher, 'twin-l', sbs9_doc('Twin landing', [], 'landing'), 'standalone', 'landing');
            sbs9_sql('UPDATE studiobuilder_pages SET slug = ? WHERE tenant_id = ? AND id = ?', ['twin', SBS9_A, $S->twinLanding]);
            $rt->tenants->runAs(SBS9_A, static fn() => $rt->app->createPage($editor, 'Draft only', 'draftonly', 'page', 'standalone'));
            [$S->gone] = sbs9_publish($rt, SBS9_A, $publisher, 'gone', sbs9_doc('Gone'));
            $rt->tenants->runAs(SBS9_A, static fn() => $rt->app->archivePage($editor, $S->gone));
            [$S->offline] = sbs9_publish($rt, SBS9_A, $publisher, 'offline', sbs9_doc('Offline'));
            sbs9_sql("UPDATE studiobuilder_pages SET status = 'draft' WHERE tenant_id = ? AND id = ?", [SBS9_A, $S->offline]);
            [$S->sysres] = sbs9_publish($rt, SBS9_A, $publisher, 'sysres', sbs9_doc('Reserved'));
            sbs9_sql('UPDATE studiobuilder_pages SET slug = ? WHERE tenant_id = ? AND id = ?', ['shop', SBS9_A, $S->sysres]);
            [$S->aboutB] = sbs9_publish($rt, SBS9_B, $publisher, 'about', sbs9_doc('About B', ['title' => 'B title']));
            sbs9_publish($rt, SBS9_B, $publisher, 'bonly', sbs9_doc('B only'));

            $expectedA = [
                SBS9_SITE . '/', SBS9_SITE . '/about', SBS9_SITE . '/foreign', SBS9_SITE . '/promo', SBS9_SITE . '/samehost', SBS9_SITE . '/twin',
            ];

            unit('phase9c int 1: the sitemap lists exactly the published, reachable, indexable pages — with the right headers, in a deterministic order', function () use ($rt, $expectedA): void {
                $r = sbs9_file($rt, SBS9_A, '/sitemap.xml');
                assert_eq($expectedA, sbs9_locs($r), 'the expected URL set, homepage first then by URL');
                assert_eq('application/xml; charset=utf-8', $r->headers['Content-Type'], 'content type');
                assert_eq('public, no-cache', $r->headers['Cache-Control'], 'same revalidating policy as the pages');
                assert_eq('nosniff', $r->headers['X-Content-Type-Options']);
                assert_true(preg_match('/^"[a-f0-9]{64}"$/', $r->headers['ETag']) === 1, 'strong ETag');
                assert_eq($r->body, sbs9_file($rt, SBS9_A, 'sitemap.xml')->body, 'same bytes without the leading slash');
                assert_eq($r->body, sbs9_file($rt, SBS9_A, '/sitemap.xml?x=1')->body, 'a query string does not change it');
                assert_true(preg_match_all('~<lastmod>\d{4}-\d{2}-\d{2}</lastmod>~', $r->body) === count($expectedA), 'every entry has a date-only lastmod');
                $c = sbs9_file($rt, SBS9_A, '/sitemap.xml', $r->headers['ETag']);
                assert_eq(304, $c->status, 'If-None-Match');
                assert_eq('', $c->body);
                assert_eq(200, sbs9_file($rt, SBS9_A, '/sitemap.xml', '"' . str_repeat('0', 64) . '"')->status);
            });

            unit('phase9c int 2: every excluded state is excluded — draft-only, archived, unpublished, noindex, reserved/unreachable slug, shadowed landing, canonical elsewhere', function () use ($rt): void {
                $locs = implode("\n", sbs9_sitemap($rt, SBS9_A));
                foreach (['draftonly' => 'draft-only page', 'gone' => 'archived page', 'offline' => 'unpublished page', 'team' => 'noindex page', 'shop' => 'reserved route', 'moved' => 'page canonical to another URL', 'twin-l' => 'shadowed landing', '/home' => 'the homepage slug (it is /)'] as $needle => $why) {
                    assert_true(!str_contains($locs, $needle), "{$why} must not be listed: {$needle}");
                }
                assert_eq(1, substr_count($locs, SBS9_SITE . '/twin'), 'the page of a page/landing pair is listed once');
                // The excluded pages really exist (they are excluded, not missing).
                assert_eq(9, (int) Database::value("SELECT COUNT(*) FROM studiobuilder_pages WHERE tenant_id = ? AND slug IN ('draftonly','gone','offline','team','shop','moved','twin','home')", [SBS9_A]), 'the excluded fixtures exist (twin = page + landing)');
            });

            unit('phase9c int 3: canonical policy — a foreign canonical never reaches the sitemap, a same-host self canonical does, a canonical pointing elsewhere on the site drops the duplicate', function () use ($rt): void {
                $xml = sbs9_file($rt, SBS9_A, '/sitemap.xml')->body;
                assert_true(!str_contains($xml, 'evil.example'), 'no foreign host anywhere');
                $locs = sbs9_sitemap($rt, SBS9_A);
                assert_true(in_array(SBS9_SITE . '/foreign', $locs, true), 'the page is listed at its OWN url (its foreign canonical is ignored, as on the page)');
                assert_true(in_array(SBS9_SITE . '/samehost', $locs, true), 'an absolute same-host self canonical is fine');
                assert_true(!in_array(SBS9_SITE . '/moved', $locs, true), 'canonical -> /about: listed under /about only');
                assert_eq(1, count(array_keys($locs, SBS9_SITE . '/about', true)));
                $head = sbs9_page_public($rt, SBS9_A, 'foreign');
                assert_true(str_contains($head, '<link rel="canonical" href="' . SBS9_SITE . '/foreign">') && !str_contains($head, 'evil.example'), 'and the page head agrees with the sitemap');
            });

            unit('phase9c int 4: the homepage is listed once, as the site root — also when several pages are homepage-designated', function () use ($rt, $publisher, $editor, $S): void {
                $locs = sbs9_sitemap($rt, SBS9_A);
                assert_eq(1, count(array_keys($locs, SBS9_SITE . '/', true)), 'the root once');
                assert_eq(SBS9_SITE . '/', $locs[0], 'and first');
                [$home2] = sbs9_publish($rt, SBS9_A, $publisher, 'home2', sbs9_doc('Home two'), 'homepage');
                $locs = sbs9_sitemap($rt, SBS9_A);
                assert_eq(1, count(array_keys($locs, SBS9_SITE . '/', true)), 'still once with two homepage pages');
                assert_true(!in_array(SBS9_SITE . '/home', $locs, true) && !in_array(SBS9_SITE . '/home2', $locs, true), 'neither homepage page is ALSO listed by its slug');
                $rt->tenants->runAs(SBS9_A, static fn() => $rt->app->archivePage($editor, $home2));
                sbs9_sql("UPDATE studiobuilder_pages SET route_mode = 'standalone' WHERE tenant_id = ? AND id = ?", [SBS9_A, $home2]);
                assert_eq(1, count(array_keys(sbs9_sitemap($rt, SBS9_A), SBS9_SITE . '/', true)));
            });

            unit('phase9c int 5: tenants are isolated — each sitemap holds only its own pages, and an unentitled tenant has none', function () use ($rt): void {
                $a = sbs9_sitemap($rt, SBS9_A);
                $b = sbs9_sitemap($rt, SBS9_B);
                assert_eq([SBS9_SITE . '/about', SBS9_SITE . '/bonly'], $b, 'tenant B: its own two pages (no homepage page)');
                assert_true(!in_array(SBS9_SITE . '/bonly', $a, true), 'A does not list B\'s page');
                foreach (['team', 'promo', 'twin', 'samehost'] as $slug) {
                    assert_true(!in_array(SBS9_SITE . '/' . $slug, $b, true), "B does not list A's /{$slug}");
                }
                assert_null(sbs9_file($rt, SBS9_C, '/sitemap.xml'), 'tenant without the Studio entitlement: not ours (platform 404)');
                assert_null(sbs9_file($rt, SBS9_C, '/robots.txt'), 'likewise robots.txt');
            });

            unit('phase9c int 6: no configured base URL -> no sitemap (404) and a robots.txt with no Sitemap line; never a manufactured host', function () use ($rtNoBase): void {
                assert_null(sbs9_file($rtNoBase, SBS9_A, '/sitemap.xml'), 'no sitemap without a base URL');
                $robots = sbs9_file($rtNoBase, SBS9_A, '/robots.txt');
                assert_eq(200, $robots->status);
                assert_eq("User-agent: *\nAllow: /\n", $robots->body);
                assert_true(!str_contains($robots->body, 'http') && !str_contains($robots->body, 'Sitemap'), 'no invented host');
            });

            unit('phase9c int 7: robots.txt — 200, text/plain, the sitemap on the configured base, no tenant data, conditional GET', function () use ($rt): void {
                $r = sbs9_file($rt, SBS9_A, '/robots.txt');
                assert_eq(200, $r->status);
                assert_eq('text/plain; charset=utf-8', $r->headers['Content-Type']);
                assert_eq('public, no-cache', $r->headers['Cache-Control']);
                assert_eq("User-agent: *\nAllow: /\n\nSitemap: " . SBS9_SITE . "/sitemap.xml\n", $r->body);
                $b = sbs9_file($rt, SBS9_B, '/robots.txt');
                assert_eq($r->body, $b->body, 'tenant-local by construction: nothing tenant-specific in it');
                foreach (['about', 'team', 'bonly', 'draftonly', 'admin', 'studio', 'preview', 'tenant', (string) SBS9_A, (string) SBS9_B] as $leak) {
                    assert_true(!str_contains($r->body, $leak), "robots.txt does not mention {$leak}");
                }
                assert_eq(304, sbs9_file($rt, SBS9_A, '/robots.txt', $r->headers['ETag'])->status);
            });

            unit('phase9c int 8: the real HTTP adapter answers robots.txt and leaves POST alone; Host / forwarded headers never decide the URLs', function () use ($rt): void {
                $_SERVER['REQUEST_METHOD'] = 'GET';
                $_SERVER['HTTP_HOST'] = 'evil.example';
                $_SERVER['HTTP_X_FORWARDED_HOST'] = 'evil.example';
                try {
                    $out = '';
                    ob_start();
                    try {
                        $handled = $rt->tenants->runAs(SBS9_A, static fn(): bool => \StudioBuilder::servePublicPath(false, 'robots.txt'));
                    } finally {
                        $out = (string) ob_get_clean();
                    }
                    assert_true($handled === true, 'the plugin hook answers /robots.txt');
                    assert_true(!str_contains($out, 'evil.example'), 'the Host header is not used');
                    assert_true(str_starts_with($out, "User-agent: *\nAllow: /\n"), $out);
                    $xml = sbs9_file($rt, SBS9_A, '/sitemap.xml')->body;
                    assert_true(!str_contains($xml, 'evil.example'), 'sitemap ignores the Host header');
                    $_SERVER['REQUEST_METHOD'] = 'POST';
                    assert_true($rt->tenants->runAs(SBS9_A, static fn(): bool => \StudioBuilder::servePublicPath(false, 'robots.txt')) === false, 'POST keeps the platform behaviour');
                } finally {
                    unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_FORWARDED_HOST'], $_SERVER['REQUEST_METHOD']);
                }
            });

            unit('phase9c int 9: draft SEO changes nothing public; publishing changes the page head and the sitemap; rollback is a draft until republished, which restores the earlier SEO and sitemap', function () use ($rt, $editor, $publisher, $S): void {
                $before = sbs9_file($rt, SBS9_A, '/sitemap.xml')->body;
                $pageBefore = sbs9_page_public($rt, SBS9_A, 'about');
                assert_true(str_contains($pageBefore, '<title>Published Title</title>') && str_contains($pageBefore, 'content="Published description"'), 'v1 head');

                $draft = sbs9_doc('About v2', ['title' => 'Draft Title', 'description' => 'Draft description', 'robots' => 'noindex,nofollow', 'canonical_url' => '/promo']);
                $draft['seo']['og_image_media_id'] = $S->heroA;
                $v2 = sbs9_draft($rt, SBS9_A, $editor, $S->about, $draft);
                $seoJson = (string) Database::value('SELECT seo_json FROM studiobuilder_pages WHERE tenant_id = ? AND id = ?', [SBS9_A, $S->about]);
                assert_true(str_contains($seoJson, 'Draft Title'), 'precondition: seo_json holds the DRAFT seo');
                assert_eq($before, sbs9_file($rt, SBS9_A, '/sitemap.xml')->body, 'draft SEO: sitemap byte-identical');
                assert_eq($pageBefore, sbs9_page_public($rt, SBS9_A, 'about'), 'draft SEO: public page byte-identical');

                sbs9_publish_draft($rt, SBS9_A, $publisher, $S->about);
                $after = sbs9_page_public($rt, SBS9_A, 'about');
                assert_true(str_contains($after, '<title>Draft Title</title>') && str_contains($after, 'content="noindex,nofollow"'), 'published v2 head (title, robots)');
                assert_true(!in_array(SBS9_SITE . '/about', sbs9_sitemap($rt, SBS9_A), true), 'noindex published: gone from the sitemap');

                $rb = $rt->tenants->runAs(SBS9_A, static function () use ($rt, $editor, $S): array {
                    $page = $rt->pages->find($S->about);
                    return $rt->app->rollback($editor, $S->about, $S->aboutV1, (int) $page['active_draft_revision_id']);
                });
                assert_true((int) $rb['revision']['id'] > $v2, 'rollback creates a new draft revision from v1');
                assert_true(str_contains(sbs9_page_public($rt, SBS9_A, 'about'), '<title>Draft Title</title>'), 'rollback alone is a draft: the live page still shows v2');
                assert_true(!in_array(SBS9_SITE . '/about', sbs9_sitemap($rt, SBS9_A), true), 'and so does the sitemap');

                sbs9_publish_draft($rt, SBS9_A, $publisher, $S->about);
                $restored = sbs9_page_public($rt, SBS9_A, 'about');
                assert_true(str_contains($restored, '<title>Published Title</title>') && str_contains($restored, 'content="Published description"') && str_contains($restored, 'content="index,follow"'), 'republishing the rolled-back draft restores the earlier SEO');
                assert_eq($before, sbs9_file($rt, SBS9_A, '/sitemap.xml')->body, 'and the earlier sitemap, byte for byte');
            });

            unit('phase9c int 10: studiobuilder_pages.seo_json is non-authoritative — tampering with it changes neither the sitemap nor the public head', function () use ($rt, $S): void {
                $before = sbs9_file($rt, SBS9_A, '/sitemap.xml')->body;
                $head = sbs9_page_public($rt, SBS9_A, 'about');
                sbs9_sql('UPDATE studiobuilder_pages SET seo_json = ? WHERE tenant_id = ? AND id = ?', [json_encode(['title' => 'Tampered', 'robots' => 'noindex,nofollow', 'canonical_url' => 'https://evil.example/']), SBS9_A, $S->about]);
                sbs9_sql('UPDATE studiobuilder_pages SET seo_json = ? WHERE tenant_id = ? AND id = ?', [json_encode(['robots' => 'index,follow', 'canonical_url' => '/team']), SBS9_A, $S->team]);
                $rt->tenants->runAs(SBS9_A, static fn() => $rt->invalidator->invalidatePage($S->about));
                assert_eq($before, sbs9_file($rt, SBS9_A, '/sitemap.xml')->body, 'sitemap unchanged (about stays in, noindex team stays out)');
                assert_eq($head, sbs9_page_public($rt, SBS9_A, 'about'), 'public head unchanged');
                assert_true(!str_contains($head, 'Tampered') && !str_contains($head, 'evil.example'));
            });

            unit('phase9c int 11: the SEO editor round-trip through the real authoring API — title, description, canonical, og image, robots save to the draft, stay private until publish, then go live', function () use ($rt, $editor, $publisher, $S): void {
                [$flow] = sbs9_publish($rt, SBS9_A, $publisher, 'seo-flow', sbs9_doc('Flow'));
                $call = static function (StudioActor $actor, string $method, string $action, array $input) use ($rt): \Slate\Module\StudioBuilder\Http\StudioApiResponse {
                    $api = new StudioAuthoringApi($rt->app);
                    $request = $method === 'POST'
                        ? new StudioApiRequest('POST', $action, [], (string) json_encode($input, JSON_PRESERVE_ZERO_FRACTION), 'application/json', true, 'same-origin')
                        : new StudioApiRequest('GET', $action, array_map('strval', $input));
                    return $rt->tenants->runAs(SBS9_A, static fn() => $api->handle($request, $actor));
                };
                $draftId = static fn(): int => (int) Database::value('SELECT active_draft_revision_id FROM studiobuilder_pages WHERE tenant_id = ? AND id = ?', [SBS9_A, $flow]);
                $seoOp = static fn(array $seo): array => ['page_id' => $flow, 'expected_revision_id' => $draftId(), 'revision_kind' => 'manual', 'operations' => [['op' => 'update_seo', 'payload' => ['seo' => $seo]]]];

                $publicBefore = sbs9_page_public($rt, SBS9_A, 'seo-flow');
                $sitemapBefore = sbs9_file($rt, SBS9_A, '/sitemap.xml')->body;
                foreach ([
                    ['title' => 'Flow title'], ['description' => 'Flow description'], ['canonical_url' => '/seo-flow'], ['og_image_media_id' => $S->heroA], ['robots' => 'noindex,follow'],
                ] as $patch) {
                    $res = $call($editor, 'POST', 'operations', $seoOp($patch));
                    assert_true($res->isOk(), 'saved ' . json_encode($patch) . ': ' . substr($res->body(), 0, 300));
                    $doc = $call($editor, 'GET', 'document', ['page' => $flow])->data()['document']['seo'];
                    foreach ($patch as $k => $v) {
                        assert_eq($v, $doc[$k], "round-trip {$k}");
                    }
                }
                $seo = $call($editor, 'GET', 'document', ['page' => $flow])->data()['document']['seo'];
                assert_eq(['canonical_url' => '/seo-flow', 'description' => 'Flow description', 'og_image_media_id' => $S->heroA, 'robots' => 'noindex,follow', 'title' => 'Flow title'], $seo, 'all five fields in the one canonical seo object');
                assert_eq($publicBefore, sbs9_page_public($rt, SBS9_A, 'seo-flow'), 'draft: public page unchanged');
                assert_eq($sitemapBefore, sbs9_file($rt, SBS9_A, '/sitemap.xml')->body, 'draft: sitemap unchanged');

                // null clears the optional fields.
                assert_true($call($editor, 'POST', 'operations', $seoOp(['canonical_url' => null, 'og_image_media_id' => null, 'robots' => 'index,follow']))->isOk(), 'null clears canonical and image');
                $cleared = $call($editor, 'GET', 'document', ['page' => $flow])->data()['document']['seo'];
                assert_null($cleared['canonical_url']);
                assert_null($cleared['og_image_media_id']);
                assert_true($call($editor, 'POST', 'operations', $seoOp(['canonical_url' => '/seo-flow', 'og_image_media_id' => $S->heroA]))->isOk());

                $pub = $call($publisher, 'POST', 'publish', ['page_id' => $flow, 'expected_revision_id' => $draftId()]);
                assert_true($pub->isOk(), 'published: ' . substr($pub->body(), 0, 300));
                $live = sbs9_page_public($rt, SBS9_A, 'seo-flow');
                assert_true(str_contains($live, '<title>Flow title</title>') && str_contains($live, 'content="Flow description"'), 'title and description live');
                assert_true(str_contains($live, '<link rel="canonical" href="' . SBS9_SITE . '/seo-flow">'), 'canonical live');
                assert_true(str_contains($live, '<meta property="og:image" content="'), 'og image live');
                assert_true(str_contains($live, '<meta name="robots" content="index,follow">'), 'robots live');
                assert_true(in_array(SBS9_SITE . '/seo-flow', sbs9_sitemap($rt, SBS9_A), true), 'and in the sitemap');

                foreach ([
                    'canonical javascript:' => ['canonical_url' => 'javascript:alert(1)'], 'canonical protocol-relative' => ['canonical_url' => '//evil.example/x'],
                    'robots free text' => ['robots' => 'all'], 'title too long' => ['title' => str_repeat('t', 256)], 'description too long' => ['description' => str_repeat('d', 1001)],
                    'og image of another tenant' => ['og_image_media_id' => $S->heroB], 'og image that does not exist' => ['og_image_media_id' => 999999],
                ] as $label => $patch) {
                    $res = $call($editor, 'POST', 'operations', $seoOp($patch));
                    assert_eq('validation_error', $res->errorCode(), "{$label} is refused by the server");
                }
                $still = $call($editor, 'GET', 'document', ['page' => $flow])->data()['document']['seo'];
                assert_eq('Flow title', $still['title'], 'a refused edit leaves the draft as it was');
                assert_eq($S->heroA, $still['og_image_media_id'], 'the other tenant\'s media never got in');
            });

            unit('phase9c int 12: a reserved slug is refused at creation for page and landing — application, API and MCP all hit the same rule', function () use ($rt, $editor): void {
                $app = static fn(string $slug, string $type): array => sbs9_refusal(fn() => $rt->tenants->runAs(SBS9_A, fn() => $rt->app->createPage($editor, 'X', $slug, $type, 'standalone')));
                foreach (['admin', 'sitemap', 'robots', 'login', 'member', 'custom-module', 'forms'] as $slug) {
                    foreach (['page', 'landing'] as $type) {
                        assert_eq('reserved_route', $app($slug, $type)[0], "application: {$type} /{$slug}");
                    }
                }
                $count = (int) Database::value("SELECT COUNT(*) FROM studiobuilder_pages WHERE tenant_id = ? AND slug IN ('robots','login','member','custom-module','forms','sitemap')", [SBS9_A]);
                assert_eq(0, $count, 'nothing was created');

                $api = new StudioAuthoringApi($rt->app);
                $req = new StudioApiRequest('POST', 'create_page', [], (string) json_encode(['title' => 'X', 'slug' => 'admin', 'page_type' => 'page', 'route_mode' => 'standalone']), 'application/json', true, 'same-origin');
                $res = $rt->tenants->runAs(SBS9_A, static fn() => $api->handle($req, $editor));
                assert_eq('validation_error', $res->errorCode());
                assert_true(str_contains($res->body(), 'reserved_route'), 'API: ' . substr($res->body(), 0, 300));

                $adapter = new StudioMcpAdapter($rt->app, static fn(): StudioActor => $editor, static fn(int $u, int $t): array => [], static fn(): int => $rt->tenants->id());
                $ctx = ['origin' => StudioActor::ORIGIN_ADMIN_ASSISTANT, 'tenant_id' => SBS9_A];
                foreach (['admin', 'robots'] as $slug) {
                    try {
                        $rt->tenants->runAs(SBS9_A, static fn() => $adapter->call('studio_create_page', ['title' => 'X', 'slug' => $slug], $ctx));
                        assert_true(false, "MCP must refuse /{$slug}");
                    } catch (StudioMcpToolException $e) {
                        assert_eq('validation_error', $e->errorCode);
                        assert_true(str_contains(json_encode($e->details), 'reserved_route'), 'MCP: ' . json_encode($e->details));
                    }
                }
            });

            unit('phase9c int 13: a page and a landing never share a public address — refused both ways on every path; unrelated document types and other tenants are unaffected', function () use ($rt, $editor, $S): void {
                $create = static fn(int $tenant, string $slug, string $type) => $rt->tenants->runAs($tenant, static fn() => $rt->app->createPage($editor, 'X', $slug, $type, 'standalone'));
                // page 'about' exists -> landing 'about' refused; landing 'promo' exists -> page 'promo' refused.
                assert_eq('route_collision', sbs9_refusal(fn() => $create(SBS9_A, 'about', 'landing'))[0], 'landing over an existing page');
                assert_eq('route_collision', sbs9_refusal(fn() => $create(SBS9_A, 'promo', 'page'))[0], 'page over an existing landing');
                assert_eq('duplicate_slug', sbs9_refusal(fn() => $create(SBS9_A, 'about', 'page'))[0], 'the same-type duplicate keeps its own code');
                // archived pages still hold their address (it could be restored).
                assert_eq('route_collision', sbs9_refusal(fn() => $create(SBS9_A, 'gone', 'landing'))[0], 'archived page keeps its address');
                // draft-only pages count too.
                $create(SBS9_A, 'fresh-page', 'page');
                assert_eq('route_collision', sbs9_refusal(fn() => $create(SBS9_A, 'fresh-page', 'landing'))[0]);
                assert_eq(0, (int) Database::value("SELECT COUNT(*) FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'about' AND page_type = 'landing'", [SBS9_A]));

                // Non-public document types never occupy a public address.
                foreach (['section_preset', 'header_partial', 'footer_partial'] as $type) {
                    $created = $create(SBS9_A, 'about', $type);
                    assert_eq($type, (string) $created['page']['page_type'], "{$type} 'about' is allowed next to the page");
                }
                $create(SBS9_A, 'admin', 'section_preset'); // not a public address, so not a reserved-route problem
                // Existing valid slugs are still valid.
                foreach (['valid-slug', 'a', 'a1-b2', 'team-2026'] as $slug) {
                    $create(SBS9_A, $slug, 'page');
                }
                // Another tenant is unaffected in both directions.
                $create(SBS9_B, 'promo', 'page');
                $create(SBS9_B, 'team', 'landing');
                assert_eq('route_collision', sbs9_refusal(fn() => $create(SBS9_B, 'promo', 'landing'))[0], 'B\'s own promo page now blocks B\'s landing');
                assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'promo'", [SBS9_A]), 'A is untouched');

                // MCP and API share the rule.
                $adapter = new StudioMcpAdapter($rt->app, static fn(): StudioActor => $editor, static fn(int $u, int $t): array => [], static fn(): int => $rt->tenants->id());
                $ctx = ['origin' => StudioActor::ORIGIN_ADMIN_ASSISTANT, 'tenant_id' => SBS9_A];
                try {
                    $rt->tenants->runAs(SBS9_A, static fn() => $adapter->call('studio_create_page', ['title' => 'X', 'slug' => 'about', 'page_type' => 'landing'], $ctx));
                    assert_true(false, 'MCP must refuse the landing');
                } catch (StudioMcpToolException $e) {
                    assert_eq('validation_error', $e->errorCode);
                    assert_true(str_contains(json_encode($e->details), 'route_collision'), json_encode($e->details));
                }
                $ok = $rt->tenants->runAs(SBS9_A, static fn() => $adapter->call('studio_create_page', ['title' => 'MCP page', 'slug' => 'mcp-ok'], $ctx));
                assert_eq('mcp-ok', (string) $ok['page']['slug'], 'a free slug still works through MCP');
                $api = new StudioAuthoringApi($rt->app);
                $req = new StudioApiRequest('POST', 'create_page', [], (string) json_encode(['title' => 'X', 'slug' => 'promo', 'page_type' => 'page']), 'application/json', true, 'same-origin');
                $res = $rt->tenants->runAs(SBS9_A, static fn() => $api->handle($req, $editor));
                assert_true($res->errorCode() === 'validation_error' && str_contains($res->body(), 'route_collision'), 'API: ' . substr($res->body(), 0, 300));
            });

            unit('phase9c int 14: import and address updates obey the same address rules', function () use ($rt, $editor, $S): void {
                $api = new StudioAuthoringApi($rt->app);
                $html = static function (string $slug, string $type, bool $dry) use ($rt, $api, $editor): \Slate\Module\StudioBuilder\Http\StudioApiResponse {
                    $req = new StudioApiRequest('POST', 'import_html', [], (string) json_encode(['html' => '<section><h2>Imported</h2></section>', 'title' => 'Imported', 'slug' => $slug, 'page_type' => $type, 'dry_run' => $dry, 'mode' => 'create']), 'application/json', true, 'same-origin');
                    return $rt->tenants->runAs(SBS9_A, static fn() => $api->handle($req, $editor));
                };
                $reserved = $html('admin', 'page', true);
                assert_true(str_contains($reserved->body(), 'reserved_route'), 'dry run reports the reserved route: ' . substr($reserved->body(), 0, 300));
                $collision = $html('promo', 'page', true);
                assert_true(str_contains($collision->body(), 'route_collision'), 'dry run reports the page/landing collision: ' . substr($collision->body(), 0, 400));
                $committed = $html('promo', 'page', false);
                assert_true(!$committed->isOk(), 'the commit is refused');
                assert_eq(0, (int) Database::value("SELECT COUNT(*) FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'promo' AND page_type = 'page'", [SBS9_A]), 'nothing was written');
                $fine = $html('imported-fine', 'page', false);
                assert_true($fine->isOk(), 'a free address imports: ' . substr($fine->body(), 0, 300));

                // Address updates (application layer; not exposed by the Builder or MCP today).
                $update = static fn(int $id, array $changes) => $rt->tenants->runAs(SBS9_A, static fn() => $rt->app->updatePageAddress($editor, $id, $changes));
                $target = (int) Database::value("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'valid-slug' AND page_type = 'page'", [SBS9_A]);
                assert_eq('reserved_route', sbs9_refusal(fn() => $update($target, ['slug' => 'admin']))[0], 'address update: reserved');
                assert_eq('route_collision', sbs9_refusal(fn() => $update($target, ['slug' => 'promo']))[0], 'address update: landing collision');
                assert_eq('valid-slug', (string) Database::value('SELECT slug FROM studiobuilder_pages WHERE tenant_id = ? AND id = ?', [SBS9_A, $target]), 'unchanged');
                $moved = $update($target, ['slug' => 'valid-slug-2', 'title' => 'Renamed']);
                assert_eq('valid-slug-2', (string) $moved['slug'], 'a free slug still works');
                $update($target, ['title' => 'Renamed again']);
            });

            unit('phase9c int 15: the sitemap costs two queries however many pages there are — no per-page lookups, revision loads or rendering', function () use ($rt): void {
                $selects = static fn(): int => (int) (Database::rows("SHOW SESSION STATUS LIKE 'Com_select'")[0]['Value'] ?? -1);
                $compilations = static fn(): int => (int) Database::value('SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ?', [SBS9_A]);
                $entries = [];
                $c0 = $compilations();
                $delta = -1;
                $rt->tenants->runAs(SBS9_A, static function () use ($rt, &$entries, $selects, &$delta): void {
                    $site = new SiteContext(SBS9_SITE, 'Site');
                    $service = new StudioSitemapService($rt->tenants, $rt->pages, $rt->revisions, new \Slate\Module\StudioBuilder\Runtime\StudioReservedRoutes(), $rt->render);
                    $before = $selects();
                    $entries = $service->entries($site);
                    $delta = $selects() - $before;
                });
                assert_true(count($entries) >= 7, 'a realistic page count: ' . count($entries));
                assert_eq(2, $delta, 'one page query + one revision query');
                assert_eq($c0, $compilations(), 'no compilation was created or refreshed');
            });
        });
    } finally {
        sbs9_drop_db($dbName);
    }
});

if (!empty($studioP9cIntStandalone)) {
    exit(unit_summary());
}
