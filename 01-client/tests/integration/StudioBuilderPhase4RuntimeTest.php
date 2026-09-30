<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — Phase 4 Server
 * Renderer, Preview & Public Runtime.
 *
 * Real MySQL (throwaway database), real plugin activation (studio-builder,
 * stripe-payment, booking, membership, forms), real per-tenant entitlement via
 * `license_test_seed_cache()`, the PRODUCTION service wiring
 * (`StudioRuntimeFactory::build()`), the real core Media library, and the real
 * PublicRouter. Tenants:
 *   - 101: studio-builder + booking + membership + forms
 *   - 202: studio-builder only
 *   - 303: nothing
 *
 * Each numbered section is its own reported test; they share one database.
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
    $studioP4IntStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Exception\StudioAuthenticationException;
use Slate\Module\StudioBuilder\Exception\StudioAuthorizationException;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Exception\StudioRenderException;
use Slate\Module\StudioBuilder\Registry\DeclarativeBlockDefinition;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const SBP4_CORE_MIGRATIONS = [
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

const SBP4_TENANT_A = 101;
const SBP4_TENANT_B = 202;
const SBP4_TENANT_C = 303;

/** A static block whose renderer always throws — proves publish/compile atomicity. */
final class _Sbp4BoomRenderer implements BlockRendererInterface
{
    public function type(): string { return 'test.boom'; }
    public function isDynamic(): bool { return false; }
    public function render(BlockRenderScope $scope): string { throw new \RuntimeException('boom'); }
}

function sbp4_fresh_db(string $dbName): \PDO
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
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(SBP4_CORE_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function sbp4_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

function sbp4_with_pdo(\PDO $pdo, callable $fn): mixed
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

function sbp4_seed(int $tenantId, string $installationId, array $entitlements): void
{
    license_test_seed_cache($tenantId, [
        'installation_id' => $installationId,
        'status'          => 'active',
        'plan'            => 'phase4-' . implode('-', $entitlements ?: ['none']),
        'entitlements'    => $entitlements,
        'expires_at'      => null,
        'fetched_at'      => gmdate('Y-m-d H:i:s'),
    ]);
}

/** @return array<string, mixed> */
function sbp4_block(string $type, array $props = [], array $extra = []): array
{
    return array_merge([
        'bindings'   => [],
        'children'   => [],
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'props'      => $props,
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'type'       => $type,
        'version'    => 1,
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ], $extra);
}

/** @return array<string, mixed> */
function sbp4_doc(array $blocks, string $type = 'page', array $settings = [], array $seo = []): array
{
    $doc = CanonicalDocumentSchema::emptyDocument($type, 'default', '');
    $doc['settings'] = array_merge($doc['settings'], $settings);
    $doc['seo'] = array_merge($doc['seo'], $seo);
    $doc['sections'] = [[
        'blocks'     => $blocks,
        'global_ref' => null,
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Main',
        'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ]];
    return $doc;
}

function sbp4_heading(string $text): array
{
    return sbp4_block('core.heading', ['text' => $text, 'level' => 'h2']);
}

/**
 * Create + draft + publish a page as $tenantId. Returns [pageId, publishedRevisionId].
 *
 * @return array{0: int, 1: int}
 */
function sbp4_publish_page(StudioRuntime $rt, int $tenantId, StudioActor $actor, string $slug, array $doc, string $pageType = 'page', string $routeMode = 'standalone'): array
{
    return $rt->tenants->runAs($tenantId, static function () use ($rt, $actor, $slug, $doc, $pageType, $routeMode): array {
        $created = $rt->app->createPage($actor, ucfirst($slug), $slug, $pageType, $routeMode);
        $pageId  = (int) $created['page']['id'];
        $doc['document_type'] = $pageType;
        $saved = $rt->app->saveDraft($actor, $pageId, $doc, (int) $created['revision']['id']);
        $pub   = $rt->app->publish($actor, $pageId, (int) $saved['revision']['id']);
        return [$pageId, (int) $pub['revision']['id']];
    });
}

function sbp4_public(StudioRuntime $rt, int $tenantId, string $path, ?string $ifNoneMatch = null): ?\Slate\Module\StudioBuilder\Runtime\PublicResponse
{
    return $rt->tenants->runAs($tenantId, static fn() => $rt->publicRuntime->handlePath($path, $ifNoneMatch));
}

function sbp4_count(string $sql, array $params = []): int
{
    return (int) Database::value($sql, $params);
}

function sbp4_capture(callable $fn): string
{
    ob_start();
    try {
        $fn();
    } finally {
        $out = (string) ob_get_clean();
    }
    return $out;
}

unit('phase4 integration: server renderer, preview and public runtime (real MySQL, tenants 101/202/303)', function (): void {
    $dbName = 'slate_sbp4_' . slate_test_ns();
    $pdo = sbp4_fresh_db($dbName);

    try {
        sbp4_with_pdo($pdo, static function (): void {
            // ── Setup ───────────────────────────────────────────────────────
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Studio Admin', 'studio-admin@example.test', password_hash('password123', PASSWORD_DEFAULT));

            Database::query(
                'INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,\'active\'), (?,?,?,\'active\'), (?,?,?,\'active\')
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [SBP4_TENANT_A, 'Tenant A', 'tenant-a-p4', SBP4_TENANT_B, 'Tenant B', 'tenant-b-p4', SBP4_TENANT_C, 'Tenant C', 'tenant-c-p4']
            );

            sbp4_seed($owner, $iid, ['forms', 'membership', 'booking', 'studio-builder', 'stripe-payment']);
            $stripe = \PluginLoader::installFromDisk('stripe-payment');
            assert_true(!empty($stripe['ok']), 'installFromDisk(stripe-payment): ' . json_encode($stripe));
            foreach (['booking', 'membership', 'forms', 'studio-builder'] as $slug) {
                $sel = CommercialModuleRegistry::validateSelection([$slug], ['forms', 'membership', 'booking', 'studio-builder']);
                assert_true($sel['ok'], "selection {$slug}: " . json_encode($sel));
                $act = \PluginLoader::installFromDisk($slug);
                assert_true(!empty($act['ok']), "installFromDisk({$slug}): " . json_encode($act));
            }
            Media::ensureSchema();

            sbp4_seed(SBP4_TENANT_A, $iid, ['studio-builder', 'booking', 'membership', 'forms']);
            sbp4_seed(SBP4_TENANT_B, $iid, ['studio-builder']);
            sbp4_seed(SBP4_TENANT_C, $iid, []);

            Database::setSetting('site_name', 'Alpha Studio', SBP4_TENANT_A);
            Database::setSetting('site_name', 'Beta Studio', SBP4_TENANT_B);

            $rt = StudioRuntimeFactory::build();
            $base = defined('SLATE_URL') ? rtrim((string) SLATE_URL, '/') : '';

            $viewer    = StudioActor::authenticated(20, [StudioPermissions::VIEW]);
            $editor    = StudioActor::authenticated(21, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
            $publisher = StudioActor::authenticated(22, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
            $admin     = StudioActor::authenticated(23, StudioPermissions::ALL);

            $S = new \stdClass();

            // ── 1. Same slug, independent tenants; published output never crosses ──
            unit('phase4 int 1: same slug published independently per tenant, output tenant-isolated', function () use ($rt, $publisher, $S): void {
                [$S->aboutA, $S->aboutARev] = sbp4_publish_page($rt, SBP4_TENANT_A, $publisher, 'about', sbp4_doc([sbp4_heading('Alpha About Content')], 'page', [], ['description' => 'About Alpha']));
                [$S->aboutB, $S->aboutBRev] = sbp4_publish_page($rt, SBP4_TENANT_B, $publisher, 'about', sbp4_doc([sbp4_heading('Beta About Content')]));

                $a = sbp4_public($rt, SBP4_TENANT_A, '/about');
                $b = sbp4_public($rt, SBP4_TENANT_B, '/about');
                assert_true($a !== null && $a->status === 200, 'tenant A /about must render');
                assert_true($b !== null && $b->status === 200, 'tenant B /about must render');
                assert_true(str_contains($a->body, 'Alpha About Content') && !str_contains($a->body, 'Beta About Content'), 'tenant A sees only its own page');
                assert_true(str_contains($b->body, 'Beta About Content') && !str_contains($b->body, 'Alpha About Content'), 'tenant B sees only its own page');
                assert_true(str_contains($a->body, 'Alpha Studio') && !str_contains($a->body, 'Beta Studio'), 'tenant chrome/branding stays per tenant');
                assert_eq('public, no-cache', $a->headers['Cache-Control']);
                assert_true(isset($a->headers['ETag']) && $a->headers['ETag'] !== $b->headers['ETag'], 'distinct ETags per tenant');
                foreach (['page_id', 'revision_id', 'published_revision', 'data-sb-node', 'studiobuilder', 'tenant_id', 'content_hash'] as $leak) {
                    assert_true(!str_contains($a->body, $leak), "public HTML must not expose internal identifier '{$leak}'");
                }
                assert_true(!str_contains($a->headers['ETag'], (string) $S->aboutARev) || strlen(trim($a->headers['ETag'], '"')) === 64, 'the ETag is an opaque hash');
                assert_null(sbp4_public($rt, SBP4_TENANT_C, '/about'), 'a tenant with no page there gets the ordinary 404 path');
            });

            // ── 2. Compilation generated from the published revision ──────
            unit('phase4 int 2: publish compiles the artifact from the published revision (hash + fingerprint match)', function () use ($rt, $S): void {
                $rt->tenants->runAs(SBP4_TENANT_A, static function () use ($rt, $S): void {
                    $row = $rt->compilations->findByPageAndMode($S->aboutA, 'published');
                    assert_true($row !== null, 'publish must have stored a published compilation');
                    assert_eq($S->aboutARev, (int) $row['revision_id'], 'artifact source = published revision');
                    $rev = $rt->revisions->findByIdForPage($S->aboutA, $S->aboutARev);
                    $head = json_decode((string) $row['head_assets_json'], true);
                    assert_eq(hash('sha256', (string) $rev['document_json']), $head['document_fingerprint'], 'artifact records the exact canonical document fingerprint');
                    $page = PageAddress::fromRow($rt->pages->find($S->aboutA));
                    $ctx = RenderContext::forPublic(SBP4_TENANT_A, $rt->render->siteContext());
                    assert_eq($rt->compiler->fingerprint($page, $rev, $ctx), (string) $row['content_hash'], 'content_hash equals the current compile-input fingerprint');
                    assert_true(str_contains((string) $row['compiled_html'], 'Alpha About Content'));
                    assert_eq(0, sbp4_count("SELECT COUNT(*) FROM studiobuilder_compilations WHERE compile_mode = 'preview'"), 'no preview artifact is ever stored');
                });
                assert_eq(1, sbp4_count('SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [SBP4_TENANT_B, $S->aboutB]), 'tenant B artifact stored under tenant B');
            });

            // ── 3. Draft never public; unpublished page 404; archived 404 ──
            unit('phase4 int 3: drafts never reach public output or the public artifact', function () use ($rt, $editor, $publisher, $S): void {
                $rt->tenants->runAs(SBP4_TENANT_A, static function () use ($rt, $editor, $S): void {
                    $page = $rt->pages->find($S->aboutA);
                    $saved = $rt->app->saveDraft($editor, $S->aboutA, sbp4_doc([sbp4_heading('Alpha DRAFT Change')]), (int) $page['active_draft_revision_id']);
                    $S->aboutADraftRev = (int) $saved['revision']['id'];
                });
                $a = sbp4_public($rt, SBP4_TENANT_A, '/about');
                assert_true(str_contains($a->body, 'Alpha About Content') && !str_contains($a->body, 'DRAFT'), 'public output keeps serving the published revision');
                $row = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->compilations->findByPageAndMode($S->aboutA, 'published'));
                assert_eq($S->aboutARev, (int) $row['revision_id']);
                assert_true(!str_contains((string) $row['compiled_html'], 'DRAFT'), 'the draft never enters the public artifact');

                // A page that only has drafts is not public.
                $rt->tenants->runAs(SBP4_TENANT_A, static function () use ($rt, $editor): void {
                    $created = $rt->app->createPage($editor, 'Secret', 'secret-draft', 'page', 'standalone');
                    $rt->app->saveDraft($editor, (int) $created['page']['id'], sbp4_doc([sbp4_heading('Unpublished secret')]), (int) $created['revision']['id']);
                });
                assert_null(sbp4_public($rt, SBP4_TENANT_A, '/secret-draft'), 'a draft-only page is an ordinary 404');

                // Archived pages disappear from public immediately.
                [$archId] = sbp4_publish_page($rt, SBP4_TENANT_A, $publisher, 'to-archive', sbp4_doc([sbp4_heading('Soon archived')]));
                assert_true(sbp4_public($rt, SBP4_TENANT_A, '/to-archive')?->status === 200);
                $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->app->archivePage($publisher, $archId));
                assert_null(sbp4_public($rt, SBP4_TENANT_A, '/to-archive'), 'an archived page is an ordinary 404');
                assert_eq(0, sbp4_count('SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [SBP4_TENANT_A, $archId]), 'archiving drops the artifact');
            });

            // ── 4. Unknown / reserved routes; unlicensed Studio ─────────────
            unit('phase4 int 4: unknown, reserved and multi-segment routes plus unlicensed Studio all fall through to 404', function () use ($rt, $iid): void {
                foreach (['/nope-not-here', '/admin', '/forms', '/book', '/member', '/about/team', '/ABOUT', '/'] as $path) {
                    assert_null(sbp4_public($rt, SBP4_TENANT_A, $path), "path {$path} must not be served by Studio");
                }
                sbp4_seed(SBP4_TENANT_B, $iid, []);
                assert_null(sbp4_public($rt, SBP4_TENANT_B, '/about'), 'an unlicensed Studio serves nothing — indistinguishable from an unknown route');
                sbp4_seed(SBP4_TENANT_B, $iid, ['studio-builder']);
                assert_true(sbp4_public($rt, SBP4_TENANT_B, '/about')?->status === 200, 'restored entitlement serves again');
            });

            // ── 5. Real PublicRouter integration + anti-enumeration ────────
            unit('phase4 int 5: PublicRouter last-resort hook serves Studio pages; misses are byte-identical ordinary 404s', function () use ($rt): void {
                $cb = [\StudioBuilder::class, 'servePublicPath'];
                $withoutHook = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => sbp4_capture(static fn() => \PublicRouter::dispatch('nope-not-here')));
                \Hook::addFilter('public_fallback', $cb, 10, 2);
                try {
                    $page     = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => sbp4_capture(static fn() => \PublicRouter::dispatch('about')));
                    $unknown  = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => sbp4_capture(static fn() => \PublicRouter::dispatch('nope-not-here')));
                    $draft    = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => sbp4_capture(static fn() => \PublicRouter::dispatch('secret-draft')));
                    $archived = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => sbp4_capture(static fn() => \PublicRouter::dispatch('to-archive')));
                } finally {
                    \Hook::removeFilter('public_fallback', $cb, 10);
                }
                assert_true(str_contains($page, 'Alpha About Content'), 'the router serves the published Studio page');
                assert_true($withoutHook !== '' && str_contains($withoutHook, '404') || str_contains(strtolower($withoutHook), 'not found'), 'baseline is the platform 404 page');
                assert_eq($withoutHook, $unknown, 'unknown path: identical to the platform 404 without Studio');
                assert_eq($withoutHook, $draft, 'draft-only page: identical to an unknown route (no enumeration)');
                assert_eq($withoutHook, $archived, 'archived page: identical to an unknown route');
            });

            // ── 6. Preview security ──────────────────────────────────────────
            unit('phase4 int 6: preview is authorized, tenant/page/revision-bound, noindex/no-store and side-effect free', function () use ($rt, $editor, $viewer, $S): void {
                $compilationsBefore = sbp4_count('SELECT COUNT(*) FROM studiobuilder_compilations');
                $revisionsBefore    = sbp4_count('SELECT COUNT(*) FROM studiobuilder_revisions');
                $auditBefore        = sbp4_count('SELECT COUNT(*) FROM audit_log');

                $preview = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->app->renderPreview($viewer, $S->aboutA));
                assert_true(str_contains($preview->html, 'Alpha DRAFT Change'), 'preview renders the working draft');
                assert_true(str_contains($preview->html, '<meta name="robots" content="noindex,nofollow">') && !str_contains($preview->html, 'rel="canonical"'));
                assert_eq('private, no-store, max-age=0', $preview->headers['Cache-Control']);
                assert_eq('noindex, nofollow', $preview->headers['X-Robots-Tag']);

                $historical = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->app->renderPreview($viewer, $S->aboutA, $S->aboutARev));
                assert_true(str_contains($historical->html, 'Alpha About Content'), 'an explicit revision OF THIS PAGE can be previewed');

                assert_eq($compilationsBefore, sbp4_count('SELECT COUNT(*) FROM studiobuilder_compilations'), 'preview never writes a compilation');
                assert_eq($revisionsBefore, sbp4_count('SELECT COUNT(*) FROM studiobuilder_revisions'), 'preview never writes a revision');
                assert_eq($auditBefore, sbp4_count('SELECT COUNT(*) FROM audit_log'), 'preview has no audit side effect');

                assert_throws(StudioAuthenticationException::class, static fn() => $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->app->renderPreview(StudioActor::guest(), $S->aboutA)), 'unauthenticated preview denied');
                assert_throws(StudioAuthorizationException::class, static fn() => $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->app->renderPreview(StudioActor::authenticated(30, []), $S->aboutA)), 'preview needs studio-builder.view');
                assert_throws(StudioNotFoundException::class, static fn() => $rt->tenants->runAs(SBP4_TENANT_B, static fn() => $rt->app->renderPreview($viewer, $S->aboutA)), 'tenant B cannot preview tenant A\'s page by id');
                assert_throws(StudioNotFoundException::class, static fn() => $rt->tenants->runAs(SBP4_TENANT_B, static fn() => $rt->app->renderPreview($viewer, $S->aboutB, $S->aboutADraftRev)), 'tenant B cannot render tenant A\'s draft revision through its own page');
                assert_throws(StudioNotFoundException::class, static fn() => $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->app->renderPreview($viewer, $S->aboutA, 99999999)), 'arbitrary revision id is refused');
                assert_throws(\Slate\Module\StudioBuilder\Exception\StudioEntitlementException::class, static fn() => $rt->tenants->runAs(SBP4_TENANT_C, static fn() => $rt->app->renderPreview($viewer, $S->aboutA)), 'unlicensed tenant cannot preview');

                assert_throws(StudioAuthorizationException::class, static fn() => $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->app->renderForEditor($viewer, $S->aboutA)), 'editor canvas requires studio-builder.edit');
                $canvas = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->app->renderForEditor($editor, $S->aboutA));
                assert_true(str_contains($canvas->html, 'data-sb-node="blk_') && str_contains($canvas->html, 'Alpha DRAFT Change'), 'editor render carries node metadata over the working draft');
                assert_eq('private, no-store, max-age=0', $canvas->headers['Cache-Control']);
            });

            // ── 7. Dynamic providers through the published page ────────────
            unit('phase4 int 7: dynamic provider blocks resolve per request, tenant-scoped, never baked; entitlement loss hides them', function () use ($rt, $publisher, $iid, $S): void {
                $rt->tenants->runAs(SBP4_TENANT_A, static function (): void {
                    Database::insert('booking_services', ['tenant_id' => SBP4_TENANT_A, 'name' => 'Alpha Haircut', 'slug' => 'alpha-haircut', 'duration_min' => 45, 'price_cents' => 5000, 'currency' => 'USD', 'is_active' => 1]);
                    Database::insert('booking_services', ['tenant_id' => SBP4_TENANT_A, 'name' => 'Alpha Hidden', 'slug' => 'alpha-hidden', 'duration_min' => 10, 'price_cents' => 100, 'currency' => 'USD', 'is_active' => 0]);
                    Database::insert('membership_plans', ['tenant_id' => SBP4_TENANT_A, 'name' => 'Alpha Gold', 'plan_type' => 'membership', 'price_cents' => 9900, 'currency' => 'USD', 'duration_days' => 365, 'is_active' => 1]);
                    Database::insert('forms_definitions', ['tenant_id' => SBP4_TENANT_A, 'slug' => 'contact-us', 'title' => 'Alpha Contact', 'fields_json' => '[]', 'status' => 'published']);
                });
                $rt->tenants->runAs(SBP4_TENANT_B, static function (): void {
                    Database::insert('booking_services', ['tenant_id' => SBP4_TENANT_B, 'name' => 'Beta Massage', 'slug' => 'beta-massage', 'duration_min' => 60, 'price_cents' => 7000, 'currency' => 'USD', 'is_active' => 1]);
                });

                $doc = sbp4_doc([
                    sbp4_heading('Alpha Services Page'),
                    sbp4_block('booking.services', ['heading' => 'Book with us'], ['bindings' => ['items' => ['provider' => 'booking.services']]]),
                    sbp4_block('membership.plans', ['heading' => 'Plans'], ['bindings' => ['items' => ['provider' => 'membership.plans']]]),
                    sbp4_block('forms.form_card', [], ['bindings' => ['form' => ['provider' => 'forms.form', 'params' => ['slug' => 'contact-us']]]]),
                ]);
                [$S->servicesA] = sbp4_publish_page($rt, SBP4_TENANT_A, $publisher, 'services', $doc);

                $r = sbp4_public($rt, SBP4_TENANT_A, '/services');
                assert_true($r !== null && $r->status === 200);
                assert_true(str_contains($r->body, 'Alpha Haircut') && str_contains($r->body, '50.00 USD'), 'booking provider data rendered');
                assert_true(!str_contains($r->body, 'Alpha Hidden'), 'inactive services never appear');
                assert_true(str_contains($r->body, 'Alpha Gold'), 'membership provider data rendered');
                assert_true(str_contains($r->body, 'Alpha Contact') && str_contains($r->body, '/forms/contact-us'), 'published form card links to the Forms module route');
                assert_true(!str_contains($r->body, 'Beta Massage'), 'provider data never crosses tenants');
                assert_true(!str_contains($r->body, '<!--sb-dyn'), 'all dynamic markers resolved');

                $row = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->compilations->findByPageAndMode($S->servicesA, 'published'));
                assert_true(!str_contains((string) $row['compiled_html'], 'Alpha Haircut') && !str_contains((string) $row['compiled_html'], 'Alpha Gold'), 'dynamic data is never baked into the stored artifact');
                assert_eq(3, count(json_decode((string) $row['dynamic_manifest_json'], true)['nodes']));

                // Live provider data: a new active service shows up without recompiling.
                $rt->tenants->runAs(SBP4_TENANT_A, static fn() => Database::insert('booking_services', ['tenant_id' => SBP4_TENANT_A, 'name' => 'Alpha Colour', 'slug' => 'alpha-colour', 'duration_min' => 90, 'price_cents' => 12000, 'currency' => 'USD', 'is_active' => 1]));
                assert_true(str_contains(sbp4_public($rt, SBP4_TENANT_A, '/services')->body, 'Alpha Colour'), 'provider data is live per request');

                // Entitlement revoked for booking: the block disappears, the page still serves, nothing is recompiled.
                $compiledAt = (string) $row['compiled_at'];
                sbp4_seed(SBP4_TENANT_A, $iid, ['studio-builder', 'membership', 'forms']);
                $revoked = sbp4_public($rt, SBP4_TENANT_A, '/services');
                assert_true($revoked !== null && $revoked->status === 200, 'the page keeps serving');
                assert_true(!str_contains($revoked->body, 'Alpha Haircut') && !str_contains($revoked->body, 'Book with us'), 'unentitled booking block is removed, provider not executed');
                assert_true(!str_contains($revoked->body, 'sb-block--booking-services') && !str_contains($revoked->body, 'class="sb-unavailable"'), 'no licence-state hint in public output');
                assert_true(str_contains($revoked->body, 'Alpha Gold') && str_contains($revoked->body, 'Alpha Services Page'), 'the rest of the page is unaffected');
                $rowAfter = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->compilations->findByPageAndMode($S->servicesA, 'published'));
                assert_eq($compiledAt, (string) $rowAfter['compiled_at'], 'entitlement changes need no recompilation (dynamic nodes are never baked)');
                sbp4_seed(SBP4_TENANT_A, $iid, ['studio-builder', 'booking', 'membership', 'forms']);
                assert_true(str_contains(sbp4_public($rt, SBP4_TENANT_A, '/services')->body, 'Alpha Haircut'), 'restoring the entitlement restores the block immediately');
            });

            // ── 8. Media through the core Media service ─────────────────────
            unit('phase4 int 8: media resolves only for the owning tenant and only as a managed image path', function () use ($rt, $editor, $publisher, $base, $S): void {
                $S->mediaA = $rt->tenants->runAs(SBP4_TENANT_A, static fn(): int => Media::register('/uploads/media/2026/01/alpha-hero.jpg', ['mime' => 'image/jpeg', 'width' => 800, 'height' => 600]));
                $unsafeUrl = $rt->tenants->runAs(SBP4_TENANT_A, static fn(): int => Media::register('https://evil.test/tracker.jpg', ['mime' => 'image/jpeg']));
                $unsafePath = $rt->tenants->runAs(SBP4_TENANT_A, static fn(): int => Media::register('/uploads/media/../../config.php', ['mime' => 'image/jpeg']));
                assert_true($S->mediaA > 0 && $unsafeUrl > 0 && $unsafePath > 0, 'fixtures registered');

                $img = static fn(int $id): array => sbp4_block('core.image', ['media' => ['media_id' => $id, 'alt' => 'Alt ' . $id]]);
                [$S->galleryA] = sbp4_publish_page($rt, SBP4_TENANT_A, $publisher, 'gallery', sbp4_doc([$img($S->mediaA), $img($unsafeUrl), $img($unsafePath)], 'page', [], ['og_image_media_id' => $S->mediaA]));

                $r = sbp4_public($rt, SBP4_TENANT_A, '/gallery');
                $main = substr($r->body, (int) strpos($r->body, '<main'), (int) strpos($r->body, '</main>') - (int) strpos($r->body, '<main'));
                assert_true(str_contains($main, $base . '/uploads/media/2026/01/alpha-hero.jpg'), 'own managed image served through Media::url()');
                assert_true(!str_contains($r->body, 'evil.test') && !str_contains($r->body, 'config.php'), 'a media row holding an external URL or a traversal path is never emitted');
                assert_eq(1, substr_count($main, '<img'));
                assert_true(str_contains($r->body, '<meta property="og:image" content="' . $base . '/uploads/media/2026/01/alpha-hero.jpg">'), 'og:image resolved from the tenant media');

                $resolverB = new CoreMediaResolver($rt->tenants);
                assert_null($rt->tenants->runAs(SBP4_TENANT_B, static fn() => $resolverB->resolveImage($S->mediaA)), 'tenant B can never resolve tenant A\'s media id');
                assert_true($rt->tenants->runAs(SBP4_TENANT_A, static fn() => (new CoreMediaResolver($rt->tenants))->resolveImage($S->mediaA)) !== null);

                // Media removed -> explicit dependency invalidation -> recompiled without the image.
                $rt->tenants->runAs(SBP4_TENANT_A, static fn() => Media::unregister('/uploads/media/2026/01/alpha-hero.jpg'));
                $invalidated = $rt->tenants->runAs(SBP4_TENANT_A, static fn(): int => $rt->invalidator->invalidateMedia($S->mediaA));
                assert_true($invalidated >= 1, 'the dependency index finds the pages using the media');
                assert_eq(0, sbp4_count('SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [SBP4_TENANT_A, $S->galleryA]));
                $after = sbp4_public($rt, SBP4_TENANT_A, '/gallery');
                assert_true(!str_contains($after->body, 'alpha-hero.jpg'), 'recompiled artifact drops the removed media');
            });

            // ── 9. Cache / compilation invalidation ─────────────────────────
            unit('phase4 int 9: stale artifacts are detected (theme, tamper, template dependency, republish) and ETag 304 works', function () use ($rt, $admin, $publisher, $S): void {
                $hashOf = static fn(int $pageId): string => (string) $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->compilations->findByPageAndMode($pageId, 'published'))['content_hash'];
                $h1 = $hashOf($S->aboutA);

                // Theme/branding change: detected on the next request, no event needed.
                Database::setSetting('brand_accent_color', '#123abc', SBP4_TENANT_A);
                $r = sbp4_public($rt, SBP4_TENANT_A, '/about');
                assert_true(str_contains($r->body, '--sb-color-accent:#123abc'), 'new branding reaches output immediately');
                assert_true($hashOf($S->aboutA) !== $h1, 'the artifact was recompiled with a new content hash');
                assert_true(!str_contains(sbp4_public($rt, SBP4_TENANT_B, '/about')->body, '#123abc'), 'tenant A branding never reaches tenant B');

                // Stored Studio tokens: sanitized values only; compiled_css_vars is never trusted/emitted raw.
                $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->tokens->insert([
                    'token_group'       => 'default',
                    'tokens_json'       => json_encode(['surface.primary' => '#fafafa', 'text.primary' => 'red;}</style><script>alert(1)</script>']),
                    'compiled_css_vars' => '</style><script>alert(2)</script>',
                ]));
                $tok = sbp4_public($rt, SBP4_TENANT_A, '/about');
                assert_true(str_contains($tok->body, '--sb-surface-primary:#fafafa'), 'a stored tenant token reaches the theme (and triggers recompilation)');
                assert_true(!str_contains($tok->body, 'alert(1)') && !str_contains($tok->body, 'alert(2)'), 'unsafe token values / raw compiled_css_vars never reach output');
                assert_true(!str_contains(sbp4_public($rt, SBP4_TENANT_B, '/about')->body, '#fafafa'), 'tenant A tokens never reach tenant B');

                // Tampered/stale stored hash: never served, recompiled.
                Database::query('UPDATE studiobuilder_compilations SET content_hash = ?, compiled_html = ? WHERE tenant_id = ? AND page_id = ?', [str_repeat('0', 64), '<main>TAMPERED</main>', SBP4_TENANT_A, $S->aboutA]);
                $r2 = sbp4_public($rt, SBP4_TENANT_A, '/about');
                assert_true(!str_contains($r2->body, 'TAMPERED') && str_contains($r2->body, 'Alpha About Content'), 'a stale/mismatched artifact is recompiled from the revision');

                // Old-revision reuse after publish: a new publish replaces the artifact's source revision.
                $newRev = $rt->tenants->runAs(SBP4_TENANT_A, static function () use ($rt, $publisher, $S): int {
                    $page = $rt->pages->find($S->aboutA);
                    return (int) $rt->app->publish($publisher, $S->aboutA, (int) $page['active_draft_revision_id'])['revision']['id'];
                });
                $r3 = sbp4_public($rt, SBP4_TENANT_A, '/about');
                assert_true(str_contains($r3->body, 'Alpha DRAFT Change') && !str_contains($r3->body, 'Alpha About Content'), 'publishing the draft switches public output');
                $row = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->compilations->findByPageAndMode($S->aboutA, 'published'));
                assert_eq($newRev, (int) $row['revision_id'], 'the artifact follows the new published revision');

                // ETag revalidation.
                $etag = $r3->headers['ETag'];
                $nm = sbp4_public($rt, SBP4_TENANT_A, '/about', $etag);
                assert_true($nm !== null && $nm->status === 304 && $nm->body === '', 'matching If-None-Match answers 304');

                // Template dependency invalidation through the dependency index.
                $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->app->saveTemplate($admin, 'promo', 'page_template', 'general', 'Promo', null, sbp4_doc([sbp4_heading('Promo template')])));
                $promoDoc = sbp4_doc([sbp4_heading('Promo page')]);
                $promoDoc['template_key'] = 'promo';
                [$S->promoA] = sbp4_publish_page($rt, SBP4_TENANT_A, $publisher, 'promo', $promoDoc);
                assert_eq(1, sbp4_count('SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [SBP4_TENANT_A, $S->promoA]));
                $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->app->saveTemplate($admin, 'promo', 'page_template', 'general', 'Promo v2', null, sbp4_doc([sbp4_heading('Promo template v2')])));
                assert_eq(0, sbp4_count('SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [SBP4_TENANT_A, $S->promoA]), 'saving a template drops artifacts of pages that depend on it');
                assert_eq(1, sbp4_count('SELECT COUNT(*) FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [SBP4_TENANT_A, $S->aboutA]), 'unrelated pages keep their artifacts');
                assert_true(str_contains(sbp4_public($rt, SBP4_TENANT_A, '/promo')->body, 'Promo page'), 'and the next request recompiles it');
            });

            // ── 10. Publish failure is atomic ───────────────────────────────
            unit('phase4 int 10: a compilation failure rolls back the whole publish (no partial state exposed)', function () use ($rt, $editor, $publisher, $S): void {
                $registry = ModuleBlockDefinitions::studioRegistry();
                $registry->register(new DeclarativeBlockDefinition(type: 'test.boom', version: 1, label: 'Boom', category: 'test', icon: 'x', schema: FieldSchema::define([])));
                $renderers = BlockRendererRegistry::withStudioRenderers();
                $renderers->register(new _Sbp4BoomRenderer());
                $boomRt = StudioRuntimeFactory::build(['tenants' => $rt->tenants, 'registry' => $registry, 'renderers' => $renderers]);

                $before = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->pages->find($S->aboutA));
                $compBefore = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->compilations->findByPageAndMode($S->aboutA, 'published'));
                $revCount = sbp4_count('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [SBP4_TENANT_A, $S->aboutA]);

                $draftId = $rt->tenants->runAs(SBP4_TENANT_A, static fn(): int => (int) $boomRt->app->saveDraft($editor, $S->aboutA, sbp4_doc([sbp4_heading('Would break'), sbp4_block('test.boom')]), (int) $before['active_draft_revision_id'])['revision']['id']);
                assert_throws(StudioRenderException::class, static fn() => $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $boomRt->app->publish($publisher, $S->aboutA, $draftId)), 'publish must fail when the artifact cannot be compiled');

                $after = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->pages->find($S->aboutA));
                assert_eq((int) $before['published_revision_id'], (int) $after['published_revision_id'], 'published pointer unchanged');
                assert_eq($draftId, (int) $after['active_draft_revision_id'], 'the draft pointer is exactly what the (committed) saveDraft left');
                assert_eq($revCount + 1, sbp4_count('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [SBP4_TENANT_A, $S->aboutA]), 'only the draft revision exists; the failed publish revision was rolled back');
                $compAfter = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->compilations->findByPageAndMode($S->aboutA, 'published'));
                assert_eq((int) $compBefore['revision_id'], (int) $compAfter['revision_id'], 'the previous artifact is untouched');
                $pub = sbp4_public($rt, SBP4_TENANT_A, '/about');
                assert_true(str_contains($pub->body, 'Alpha DRAFT Change') && !str_contains($pub->body, 'Would break'), 'public keeps serving the last good publish');
            });

            // ── 11. Chrome partials, homepage, SEO, platform identity ──────
            unit('phase4 int 11: published chrome partials, homepage route, SEO head and protected platform signature', function () use ($rt, $editor, $publisher, $base, $S): void {
                [$headerId] = sbp4_publish_page($rt, SBP4_TENANT_A, $publisher, 'default', sbp4_doc([sbp4_heading('Alpha Site Header')], 'header_partial'), 'header_partial');
                $r = sbp4_public($rt, SBP4_TENANT_A, '/about');
                assert_true(str_contains($r->body, 'Alpha Site Header'), 'inherit uses the tenant\'s published site header partial (artifact recompiled automatically)');
                assert_true(!str_contains(sbp4_public($rt, SBP4_TENANT_B, '/about')->body, 'Alpha Site Header'), 'partials never cross tenants');
                assert_null(sbp4_public($rt, SBP4_TENANT_A, '/default'), 'a header partial is never routable as a page');

                $rt->tenants->runAs(SBP4_TENANT_A, static function () use ($rt, $editor, $headerId): void {
                    $p = $rt->pages->find($headerId);
                    $rt->app->saveDraft($editor, $headerId, sbp4_doc([sbp4_heading('Alpha Header DRAFT')], 'header_partial'), (int) $p['active_draft_revision_id']);
                });
                assert_true(!str_contains(sbp4_public($rt, SBP4_TENANT_A, '/about')->body, 'Alpha Header DRAFT'), 'a draft partial never leaks into pages');

                $hidden = sbp4_doc([sbp4_heading('Bare page')], 'page', ['header_mode' => 'hidden', 'footer_mode' => 'hidden']);
                sbp4_publish_page($rt, SBP4_TENANT_A, $publisher, 'bare', $hidden);
                $bare = sbp4_public($rt, SBP4_TENANT_A, '/bare')->body;
                assert_true(!str_contains($bare, '<header') && !str_contains($bare, '<footer'), 'hidden chrome');
                assert_true(str_contains($bare, '<div class="sb-platform-signature">') && str_contains($bare, 'Kohevo'), 'the platform signature survives hidden chrome');

                Database::setSetting('site_name', 'Kohevo Impostor Ltd', SBP4_TENANT_A);
                $imp = sbp4_public($rt, SBP4_TENANT_A, '/bare')->body;
                assert_true(str_contains($imp, 'Powered by Kohevo') || str_contains($imp, 'Kohevo</div>') || str_contains($imp, ' Kohevo'), 'tenant branding cannot replace the platform identity');
                Database::setSetting('site_name', 'Alpha Studio', SBP4_TENANT_A);

                $about = sbp4_public($rt, SBP4_TENANT_A, '/about')->body;
                if ($base !== '') {
                    assert_true(str_contains($about, '<link rel="canonical" href="' . $base . '/about">'), 'canonical = site URL + slug');
                }
                assert_true(str_contains($about, '<meta name="robots" content="index,follow">'));

                // Homepage route mode.
                assert_null($rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->publicRuntime->handleHomepage()), 'no homepage published yet: existing landing page is preserved');
                sbp4_publish_page($rt, SBP4_TENANT_A, $publisher, 'home', sbp4_doc([sbp4_heading('Alpha Homepage')]), 'page', 'homepage');
                $home = $rt->tenants->runAs(SBP4_TENANT_A, static fn() => $rt->publicRuntime->handleHomepage());
                assert_true($home !== null && str_contains($home->body, 'Alpha Homepage'), 'published homepage served at /');
                if ($base !== '') {
                    assert_true(str_contains($home->body, '<link rel="canonical" href="' . $base . '/">'), 'homepage canonical is the site root');
                }
                assert_null($rt->tenants->runAs(SBP4_TENANT_B, static fn() => $rt->publicRuntime->handleHomepage()), 'tenant B has no homepage: landing preserved');
            });
        });
    } finally {
        sbp4_drop_db($dbName);
    }
});

if (!empty($studioP4IntStandalone)) {
    exit(unit_summary());
}
