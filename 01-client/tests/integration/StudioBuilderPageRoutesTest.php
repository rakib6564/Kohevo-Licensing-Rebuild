<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — content type URL route prefixes (real MySQL).
 *
 * A page of a content type whose route prefix is `/events` is served at `/events/{slug}`, its old `/{slug}` answers
 * with a 301 there, and a wrong prefix is not found. Its canonical tag, the sitemap and Query Loop links name the
 * prefixed address, and the site's trailing-slash setting adds the slash WordPress sites use. A Query Loop card shows
 * the post's social-share image.
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
    $studioRoutesStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PageRoutes;
use Slate\Module\StudioBuilder\Provider\PostsProvider;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioScheduledPublisher;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;

const SBPR_TENANT = 141;

function sbpr_db(string $dbName, bool $create): ?\PDO
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
function sbpr_with(\PDO $pdo, callable $fn): void
{
    $statics = [[Database::class, 'pdo', $pdo], [\PluginLoader::class, 'active', []], [\PluginLoader::class, 'activeSlugs', null], [\PluginLoader::class, 'booted', false]];
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
        PageRoutes::forget();
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

/** Create, draft and publish a page with one heading; its content type and category are set. */
function sbpr_page(StudioRuntime $rt, StudioActor $actor, string $slug, string $text, string $type = '', string $category = '', int $ogImage = 0): int
{
    return $rt->tenants->runAs(SBPR_TENANT, static function () use ($rt, $actor, $slug, $text, $type, $category, $ogImage): int {
        $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', ucfirst($slug));
        if ($ogImage > 0) {
            $doc['seo']['og_image_media_id'] = $ogImage;
        }
        if ($category !== '') {
            $doc['settings']['category'] = $category;
        }
        $doc['containers'] = [CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'Main'], 'children' => [[
                'bindings' => [], 'children' => [], 'id' => CanonicalDocumentSchema::newBlockId(), 'props' => ['text' => $text, 'level' => 'h2'],
                'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'type' => 'core.heading', 'version' => 1, 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            ]], 'visibility' => CanonicalDocumentSchema::defaultVisibility()])];
        $created = $rt->app->createPage($actor, ucfirst($slug), $slug, 'page', 'standalone');
        $id = (int) $created['page']['id'];
        if ($type !== '') {
            PageMeta::save(SBPR_TENANT, $id, $type, []);
        }
        $saved = $rt->app->saveDraft($actor, $id, $doc, (int) $created['revision']['id']);
        $rt->app->publish($actor, $id, (int) $saved['revision']['id']);
        return $id;
    });
}

function sbpr_get(StudioRuntime $rt, string $path): ?\Slate\Module\StudioBuilder\Runtime\PublicResponse
{
    return $rt->tenants->runAs(SBPR_TENANT, static fn() => $rt->publicRuntime->handlePath($path));
}

function sbpr_types(array $routes): void
{
    $types = [];
    foreach ($routes as $key => $route) {
        $types[$key] = ['key' => $key, 'singular' => ucfirst($key), 'plural' => ucfirst($key) . 's', 'route' => $route, 'is_system' => false, 'description' => ''];
    }
    Database::setSetting('studio_custom_post_types', json_encode($types, JSON_UNESCAPED_SLASHES), SBPR_TENANT);
    PageRoutes::forget();
}

unit('page routes: a content type route prefix gives its pages their own address (real MySQL)', function (): void {
    $dbName = 'slate_sbpr_' . slate_test_ns();
    $pdo = sbpr_db($dbName, true);
    try {
        sbpr_with($pdo, static function (): void {
            $core = InstallationService::provisionCore();
            InstallationService::createAdminAccount((int) $core['tenant_id'], 'Studio Admin', 'routes-admin@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query("INSERT INTO tenants (id, name, slug, status) VALUES (?, 'Routes', 'routes-t', 'active')", [SBPR_TENANT]);
            license_test_seed_cache(SBPR_TENANT, ['installation_id' => (string) $core['installation_id'], 'status' => 'active', 'plan' => 'routes',
                'entitlements' => ['studio-builder'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            license_test_seed_cache((int) $core['tenant_id'], ['installation_id' => (string) $core['installation_id'], 'status' => 'active', 'plan' => 'routes',
                'entitlements' => ['studio-builder'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
            $sel = CommercialModuleRegistry::validateSelection(['studio-builder'], ['studio-builder']);
            assert_true($sel['ok'], 'selection');
            assert_true(!empty(\PluginLoader::installFromDisk('studio-builder')['ok']), 'install studio-builder');

            $rt = StudioRuntimeFactory::build();
            $actor = StudioActor::authenticated(31, StudioPermissions::ALL, true);
            $base = defined('SLATE_URL') ? rtrim((string) SLATE_URL, '/') : '';

            sbpr_types(['evenement' => '/events', 'produit' => '/produit', 'note' => '/']);
            \Slate\Services\Media\Media::ensureSchema();
            $card = (int) Database::insert('media_files', ['tenant_id' => SBPR_TENANT, 'path' => '/uploads/media/routes/marche.jpg', 'kind' => 'image', 'mime' => 'image/jpeg', 'folder' => 'routes', 'original_name' => 'marche.jpg']);
            $event = sbpr_page($rt, $actor, 'chaque-pas-compte', 'Marche solidaire', 'evenement', 'Actualites', $card);
            $product = sbpr_page($rt, $actor, 't-shirt', 'T-shirt FightxLife', 'produit');
            $note = sbpr_page($rt, $actor, 'a-note', 'A plain note', 'note');
            sbpr_page($rt, $actor, 'contact', 'Contacte-nous', '');
            sbpr_page($rt, $actor, 'events', 'All events', '');

            $r = sbpr_get($rt, '/events/chaque-pas-compte/');
            assert_true($r !== null && $r->status === 200 && str_contains($r->body, 'Marche solidaire'), 'served under its prefix');
            assert_true(sbpr_get($rt, '/events/chaque-pas-compte')?->status === 200, 'with or without the slash');
            assert_true(sbpr_get($rt, '/produit/t-shirt/')?->status === 200, 'a second type, its own prefix');
            if ($base !== '') {
                assert_true(str_contains($r->body, '<link rel="canonical" href="' . $base . '/events/chaque-pas-compte">'), 'canonical names the prefixed address');
            }

            $moved = sbpr_get($rt, '/chaque-pas-compte/');
            assert_true($moved !== null && $moved->status === 301, 'the flat address moves');
            assert_true(str_ends_with((string) ($moved->headers['Location'] ?? ''), '/events/chaque-pas-compte'), 'to the prefixed one: ' . ($moved->headers['Location'] ?? ''));

            assert_eq(null, sbpr_get($rt, '/produit/chaque-pas-compte/'), 'a page is not served under another type\'s prefix');
            assert_eq(null, sbpr_get($rt, '/events/contact/'), 'an untyped page is not served under a prefix');
            assert_eq(null, sbpr_get($rt, '/events/nothing-here/'), 'an unknown slug under a prefix is not found');
            assert_true(sbpr_get($rt, '/contact/')?->status === 200, 'an untyped page keeps /{slug}');
            assert_true(sbpr_get($rt, '/events/')?->status === 200, 'a page whose slug is a prefix still has its own address');
            assert_true(sbpr_get($rt, '/a-note')?->status === 200 && sbpr_get($rt, '/note/a-note') === null, 'a type whose route is / has no prefix');

            $rows = $rt->tenants->runAs(SBPR_TENANT, static fn() => (new PostsProvider())->execute($rt->tenants, ['category' => 'Actualites', 'limit' => 5]));
            assert_eq('/events/chaque-pas-compte', $rows[0]['url'] ?? null, 'Query Loop links use the prefixed address');
            assert_true(str_ends_with((string) ($rows[0]['featured_image'] ?? ''), '/uploads/media/routes/marche.jpg'), 'the card image is the page\'s social-share image: ' . ($rows[0]['featured_image'] ?? ''));

            if ($base !== '') {
                $xml = (string) sbpr_get($rt, '/sitemap.xml?format=xml')?->body;
                assert_true(str_contains($xml, '<loc>' . $base . '/events/chaque-pas-compte</loc>') && str_contains($xml, '<loc>' . $base . '/produit/t-shirt</loc>'), 'the sitemap lists the prefixed addresses');
                assert_true(!str_contains($xml, '<loc>' . $base . '/chaque-pas-compte</loc>'), 'and not the flat ones');
            }

            // A type's public settings. Saved types carry only what the screen stored; the rest stays as it was.
            $setType = static function (array $change): void {
                $types = json_decode((string) Database::setting('studio_custom_post_types', SBPR_TENANT), true);
                $types['evenement'] = $change + $types['evenement'];
                PageMeta::savePostTypes(SBPR_TENANT, $types);
            };
            $listed = static fn(array $params = []): array => array_column($rt->tenants->runAs(SBPR_TENANT, static fn() => (new PostsProvider())->execute($rt->tenants, $params + ['limit' => 50])), 'slug');
            assert_true(in_array('chaque-pas-compte', $listed(), true), 'listed by default');
            assert_true(in_array('chaque-pas-compte', $listed(['search' => 'chaque']), true), 'found by default');

            $setType(['exclude_from_search' => true]);
            assert_eq([], $listed(['search' => 'chaque']), 'excluded from search');
            assert_true(in_array('chaque-pas-compte', $listed(), true), 'still in other Query Loops');
            assert_true(sbpr_get($rt, '/events/chaque-pas-compte')?->status === 200, 'and still has its page');

            $setType(['exclude_from_search' => false, 'publicly_queryable' => false]);
            assert_eq(null, sbpr_get($rt, '/events/chaque-pas-compte'), 'not queryable: no page of its own');
            assert_eq(null, sbpr_get($rt, '/chaque-pas-compte'), 'nor a redirect to one');
            assert_true(in_array('chaque-pas-compte', $listed(), true), 'Query Loops still list it');
            if ($base !== '') {
                assert_false(str_contains((string) sbpr_get($rt, '/sitemap.xml?format=xml')?->body, '/events/chaque-pas-compte'), 'out of the sitemap');
            }

            $setType(['publicly_queryable' => true, 'public' => false]);
            assert_eq(null, sbpr_get($rt, '/events/chaque-pas-compte'), 'not public: no page');
            assert_false(in_array('chaque-pas-compte', $listed(), true), 'and no Query Loop');
            $setType(['public' => true]);
            assert_true(sbpr_get($rt, '/events/chaque-pas-compte')?->status === 200, 'public again');

            // Has archive: the type's own list at its prefix, by the Archive template; a page already there keeps the address.
            $template = static function (string $slug, string $title, string $role, array $blocks) use ($rt, $actor): array {
                return $rt->tenants->runAs(SBPR_TENANT, static function () use ($rt, $actor, $slug, $title, $role, $blocks): array {
                    $doc = CanonicalDocumentSchema::emptyDocument('system', 'default', $title);
                    $doc['settings']['template_type'] = $role;
                    $doc['containers'] = [CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'Main'], 'children' => $blocks, 'visibility' => CanonicalDocumentSchema::defaultVisibility()])];
                    $created = $rt->app->createPage($actor, $title, $slug, 'system', 'standalone');
                    $saved = $rt->app->saveDraft($actor, (int) $created['page']['id'], $doc, (int) $created['revision']['id']);
                    $rt->app->publish($actor, (int) $created['page']['id'], (int) $saved['revision']['id']);
                    return $created['page'];
                });
            };
            $block = static fn(string $type, array $props): array => ['bindings' => [], 'children' => [], 'id' => CanonicalDocumentSchema::newBlockId(), 'props' => $props, 'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'type' => $type, 'version' => 1, 'visibility' => CanonicalDocumentSchema::defaultVisibility()];
            sbpr_page($rt, $actor, 'other-item', 'Other item', 'produit');
            assert_eq(null, sbpr_get($rt, '/produit'), 'no archive by default');
            $template('archive', 'Archive', 'archive', [$block('core.heading', ['text' => 'Archive layout', 'level' => 'h1']), $block('core.query_loop', [])]);
            assert_eq(null, sbpr_get($rt, '/produit'), 'an Archive template alone gives a type no archive');
            $types = json_decode((string) Database::setting('studio_custom_post_types', SBPR_TENANT), true);
            $types['produit']['has_archive'] = true;
            PageMeta::savePostTypes(SBPR_TENANT, $types);
            $archive = sbpr_get($rt, '/produit');
            assert_true($archive !== null && $archive->status === 200 && str_contains($archive->body, 'Archive layout'), 'the archive is served');
            assert_true(str_contains($archive->body, '/produit/t-shirt'), 'it lists the type');
            assert_false(str_contains($archive->body, '/events/chaque-pas-compte'), 'and only the type');
            assert_true(str_contains((string) sbpr_get($rt, '/events/')?->body, 'All events'), 'a page at a prefix keeps it');

            // An item pinned to a Single template shows it; a template gone gives the choice back to the conditions.
            $pinned = $template('single-pinned', 'Pinned single', 'single', [$block('core.heading', ['text' => 'Pinned layout', 'level' => 'h1']), $block('theme.post_content', [])]);
            assert_true(in_array((string) $pinned['uuid'], array_column($rt->tenants->runAs(SBPR_TENANT, static fn() => $rt->app->singleTemplates($actor)), 'uuid'), true), 'offered on the item screen');
            $template('single-general', 'General single', 'single', [$block('core.heading', ['text' => 'General layout', 'level' => 'h1']), $block('theme.post_content', [])]);
            assert_true(str_contains((string) sbpr_get($rt, '/produit/t-shirt')?->body, 'General layout'), 'the latest template without a pin');
            PageMeta::setTemplate(SBPR_TENANT, $product, (string) $pinned['uuid']);
            $body = (string) sbpr_get($rt, '/produit/t-shirt')?->body;
            assert_true(str_contains($body, 'Pinned layout') && !str_contains($body, 'General layout'), 'the pinned template, with no recompile by hand');
            assert_true(str_contains((string) sbpr_get($rt, '/produit/other-item')?->body, 'General layout'), 'other items keep theirs');
            PageMeta::save(SBPR_TENANT, $product, 'produit', []);
            assert_eq((string) $pinned['uuid'], PageMeta::read(SBPR_TENANT, $product)['template'], 'saving the fields keeps the pin');
            PageMeta::setTemplate(SBPR_TENANT, $product, '');
            assert_true(str_contains((string) sbpr_get($rt, '/produit/t-shirt')?->body, 'General layout'), 'the pin can be taken off');
            $rt->tenants->runAs(SBPR_TENANT, static function () use ($rt, $actor): void {
                foreach ($rt->app->listPages($actor) as $p) {
                    if (in_array($p['slug'], ['single-pinned', 'single-general', 'archive'], true)) {
                        $rt->app->archivePage($actor, (int) $p['id']);
                    }
                }
            });

            // Scheduled: off the site until the time comes, then live with that date; a sweep before then does nothing.
            $at = static fn(string $offset): string => (new \DateTimeImmutable(slate_db_now()))->modify($offset)->format('Y-m-d H:i:s');
            $later = $at('+1 day');
            $rt->tenants->runAs(SBPR_TENANT, static fn() => $rt->app->schedulePublish($actor, $product, $later));
            assert_eq(null, sbpr_get($rt, '/produit/t-shirt'), 'a scheduled item is off the site');
            assert_eq(0, StudioScheduledPublisher::run(), 'not yet');
            $due = $at('-1 hour');
            Database::query('UPDATE studiobuilder_pages SET scheduled_for = ? WHERE id = ?', [$due, $product]);
            assert_eq(1, StudioScheduledPublisher::run(), 'the sweep publishes it');
            assert_true(sbpr_get($rt, '/produit/t-shirt')?->status === 200, 'live');
            $view = $rt->tenants->runAs(SBPR_TENANT, static fn() => $rt->app->pageStatus($actor, $product));
            assert_eq([$due, null], [(string) $view['published_at'], $view['scheduled_for']], 'dated when it was scheduled for, and no longer scheduled');
            assert_eq(0, StudioScheduledPublisher::run(), 'once');
            $rt->tenants->runAs(SBPR_TENANT, static fn() => $rt->app->schedulePublish($actor, $product, $later));
            $rt->tenants->runAs(SBPR_TENANT, static fn() => $rt->app->unpublish($actor, $product));
            $view = $rt->tenants->runAs(SBPR_TENANT, static fn() => $rt->app->pageStatus($actor, $product));
            assert_eq(['draft', null], [$view['status'], $view['scheduled_for']], 'draft cancels a schedule');
            $rt->tenants->runAs(SBPR_TENANT, static fn() => $rt->app->publish($actor, $product, null));

            // A Query Loop naming a content type lists only that type, wherever it sits.
            $homeLoop = sbpr_page($rt, $actor, 'home-loop', 'Home loop', '');
            $rt->tenants->runAs(SBPR_TENANT, static function () use ($rt, $actor, $homeLoop): void {
                $loaded = $rt->app->loadEditorDocument($actor, $homeLoop);
                $doc = $loaded['document'];
                $doc['containers'][0]['children'][] = ['bindings' => [], 'children' => [], 'id' => CanonicalDocumentSchema::newBlockId(), 'props' => ['content_type' => 'produit'], 'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'type' => 'core.query_loop', 'version' => 1, 'visibility' => CanonicalDocumentSchema::defaultVisibility()];
                $saved = $rt->app->saveDraft($actor, $homeLoop, $doc, (int) $loaded['revision']['id']);
                $rt->app->publish($actor, $homeLoop, (int) $saved['revision']['id']);
            });
            $body = (string) sbpr_get($rt, '/home-loop')?->body;
            assert_true(str_contains($body, '/produit/t-shirt'), 'it lists the type');
            assert_false(str_contains($body, '/events/chaque-pas-compte'), 'and nothing else');

            // Back to draft and live again; a renamed key takes its values; Delete metadata keeps item and template.
            $rt->tenants->runAs(SBPR_TENANT, static fn() => $rt->app->unpublish($actor, $product));
            assert_eq(null, sbpr_get($rt, '/produit/t-shirt'), 'a draft is off the site');
            $rt->tenants->runAs(SBPR_TENANT, static fn() => $rt->app->publish($actor, $product, null));
            assert_true(sbpr_get($rt, '/produit/t-shirt')?->status === 200, 'and back');
            $rt->tenants->runAs(SBPR_TENANT, static fn() => $rt->app->setPublishDate($actor, $product, '2020-05-04 10:00:00'));
            assert_eq('2020-05-04 10:00:00', (string) $rt->tenants->runAs(SBPR_TENANT, static fn() => $rt->app->pageStatus($actor, $product))['published_at'], 'the date is set');
            PageMeta::replaceFieldsFor(SBPR_TENANT, 'produit', [['id' => 'f_00000001', 'post_type' => 'produit', 'label' => 'Size', 'key' => 'size', 'type' => 'text', 'options' => []]]);
            PageMeta::save(SBPR_TENANT, $product, 'produit', ['size' => 'L']);
            PageMeta::replaceFieldsFor(SBPR_TENANT, 'produit', [['id' => 'f_00000001', 'post_type' => 'produit', 'label' => 'Size', 'key' => 'taille', 'type' => 'text', 'options' => []]]);
            assert_eq(['taille' => 'L'], PageMeta::read(SBPR_TENANT, $product)['values'], 'a renamed key keeps its value');
            assert_eq([$product => ['taille' => 'L']], array_intersect_key(PageMeta::valuesByPage(SBPR_TENANT, 'produit'), [$product => 1]), 'values for the list');
            assert_true(PageMeta::clearValues(SBPR_TENANT, 'produit') >= 1, 'items are counted');
            $cleared = PageMeta::read(SBPR_TENANT, $product);
            assert_eq(['produit', []], [$cleared['type'], $cleared['values']], 'Delete metadata keeps the type and empties the values');
            PageMeta::saveFields(SBPR_TENANT, []);

            // The site's trailing-slash setting: canonical, sitemap and links end in `/`; the old slash-less flat URL still moves.
            Database::setSetting(PageRoutes::SETTING_TRAILING_SLASH, '1', SBPR_TENANT);
            PageRoutes::forget();
            if ($base !== '') {
                $r = sbpr_get($rt, '/events/chaque-pas-compte');
                assert_true(str_contains((string) $r?->body, '<link rel="canonical" href="' . $base . '/events/chaque-pas-compte/">'), 'canonical ends with a slash');
                $c = sbpr_get($rt, '/contact');
                assert_true(str_contains((string) $c?->body, '<link rel="canonical" href="' . $base . '/contact/">'), 'for every page');
                $xml = (string) sbpr_get($rt, '/sitemap.xml?format=xml')?->body;
                assert_true(str_contains($xml, '<loc>' . $base . '/contact/</loc>') && str_contains($xml, '<loc>' . $base . '/events/chaque-pas-compte/</loc>'), 'the sitemap uses the slash');
            }
            assert_true(str_ends_with((string) (sbpr_get($rt, '/chaque-pas-compte')?->headers['Location'] ?? ''), '/events/chaque-pas-compte/'), 'the 301 goes to the slashed address');

            // Changing the type's route moves its pages at once.
            sbpr_types(['evenement' => '/agenda', 'produit' => '/produit', 'note' => '/']);
            assert_true(sbpr_get($rt, '/agenda/chaque-pas-compte/')?->status === 200, 'served under the new prefix');
            assert_eq(null, sbpr_get($rt, '/events/chaque-pas-compte/'), 'and no longer under the old one');
            unset($product, $note, $event);
        });
    } finally {
        sbpr_db($dbName, false);
    }
});

if (!empty($studioRoutesStandalone)) {
    exit(unit_summary());
}
