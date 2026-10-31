<?php
/**
 * Integration tests for the Studio whole-site backup: real MySQL, real files, the production wiring.
 *
 * Tenant A builds a site, backs it up, changes it, and restores. Tenant B stands in for another site: it starts empty and
 * must end up with A's pages, by uuid, with its own media ids. Covers: what a backup holds, the preview writing nothing, the
 * safety backup, restores that change nothing, pages not in the backup, archived pages, tampered files, and permissions.
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
    $studioBackupIntStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Backup\StudioBackupFormat;
use Slate\Module\StudioBuilder\Backup\StudioBackupStore;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Exception\StudioAuthorizationException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Menu\StudioMenus;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const SBBK_MIGRATIONS = [
    '0001_core_init', '0002_identity_core', '0011_login_attempts', '0014_tenant_profiles', '0023_installation_identity',
    '0022_remote_license_cache', '0024_remote_license_metadata', '0025_remote_license_cache_installation_id', '0026_remote_license_cache_signed_payload',
];
const SBBK_A = 101;
const SBBK_B = 202;

function sbbki_with_db(string $dbName, callable $fn): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $host = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '');
    $root = new \PDO($host . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4");
    $pdo = new \PDO($host . ";dbname={$dbName};charset=" . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(SBBK_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));

    $property = new \ReflectionProperty(Database::class, 'pdo');
    $previous = $property->getValue();
    $property->setValue(null, $pdo);
    $props = array_map(static fn(string $n): \ReflectionProperty => new \ReflectionProperty(\PluginLoader::class, $n), ['active', 'activeSlugs', 'booted']);
    $saved = array_map(static fn(\ReflectionProperty $p): mixed => $p->getValue(), $props);
    $env = ['LICENSE_SERVER_URL' => 'https://license.test', 'LICENSE_SERVER_PUBLIC_KEY' => license_test_public_key(), 'LICENSE_PRODUCT' => 'kohevo', 'LICENSE_KEY' => 'test-key'];
    $prevEnv = array_map(static fn(string $k): mixed => $_ENV[$k] ?? null, array_keys($env));
    foreach ($env as $k => $v) {
        $_ENV[$k] = $v;
    }
    try {
        $props[0]->setValue(null, []);
        $props[1]->setValue(null, null);
        $props[2]->setValue(null, false);
        $fn();
    } finally {
        unset($GLOBALS['SLATE_TENANT_OVERRIDE']);
        foreach ($props as $i => $p) {
            $p->setValue(null, $saved[$i]);
        }
        $property->setValue(null, $previous);
        foreach (array_keys($env) as $i => $k) {
            if ($prevEnv[$i] === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $prevEnv[$i];
            }
        }
        $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    }
}

function sbbki_seed(int $tenantId, string $installationId, array $entitlements): void
{
    license_test_seed_cache($tenantId, ['installation_id' => $installationId, 'status' => 'active', 'plan' => 'backup', 'entitlements' => $entitlements, 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
}

/** Replace a page's draft with one section holding a heading and, when given, an image. @return int the new revision id */
function sbbki_heading(StudioRuntime $rt, StudioActor $actor, int $pageId, string $text, ?int $mediaId = null): int
{
    $state = $rt->app->loadEditorDocument($actor, $pageId);
    $ops = [];
    foreach ($state['document']['containers'] as $section) {
        $ops[] = new DocumentOperation('remove_block', ['block_id' => $section['id']]);
    }
    $ops[] = new DocumentOperation('insert_block', ['parent_id' => null, 'index' => 0, 'block' => ['type' => 'core.container', 'metadata' => ['label' => 'Main']]]);
    $rev = (int) $rt->app->applyDocumentOperation($actor, $pageId, $ops, (int) $state['revision']['id'])['revision']['id'];
    $state = $rt->app->loadEditorDocument($actor, $pageId);
    $section = (string) $state['document']['containers'][0]['id'];
    $blocks = [new DocumentOperation('insert_block', ['parent_id' => $section, 'index' => 0, 'block' => ['type' => 'core.heading', 'props' => ['text' => $text]]])];
    if ($mediaId !== null) {
        $blocks[] = new DocumentOperation('insert_block', ['parent_id' => $section, 'index' => 1, 'block' => ['type' => 'core.image', 'props' => ['media' => ['media_id' => $mediaId, 'alt' => 'x']]]]);
    }
    return (int) $rt->app->applyDocumentOperation($actor, $pageId, $blocks, $rev)['revision']['id'];
}

/** The text of a revision's first heading, and its first image's media id. @return array{0: ?string, 1: ?int} */
function sbbki_content(StudioRuntime $rt, int $pageId, int $revisionId): array
{
    $rev = $rt->revisions->findByIdForPage($pageId, $revisionId);
    $text = null;
    $media = null;
    foreach (CanonicalJson::decode((string) $rev['document_json'])['containers'] ?? [] as $section) {
        foreach ($section['blocks'] as $block) {
            $text ??= $block['type'] === 'core.heading' ? (string) $block['props']['text'] : null;
            $media ??= $block['type'] === 'core.image' ? (int) $block['props']['media']['media_id'] : null;
        }
    }
    return [$text, $media];
}

/** A copy of a backup with some entries replaced. */
function sbbki_tampered(string $source, array $entries): string
{
    $copy = $source . '-tampered.zip';
    copy($source, $copy);
    $zip = new \ZipArchive();
    $zip->open($copy);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, is_string($content) ? $content : json_encode($content));
    }
    $zip->close();
    return $copy;
}

unit('studio backup integration: back up, change, restore, and move to another site (real MySQL and files)', function (): void {
    if (!class_exists(\ZipArchive::class)) {
        return;
    }
    $root = sys_get_temp_dir() . '/sbbki-' . bin2hex(random_bytes(4));
    $mediaPath = '/uploads/media/backup-test-' . bin2hex(random_bytes(4)) . '.png';
    $mediaAbs = SLATE_ROOT . $mediaPath;
    if (!is_dir(dirname($mediaAbs))) {
        mkdir(dirname($mediaAbs), 0755, true);
    }
    file_put_contents($mediaAbs, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

    try {
        sbbki_with_db('slate_sbbk_' . slate_test_ns(), static function () use ($root, $mediaPath, $mediaAbs): void {
            $core = InstallationService::provisionCore();
            $owner = (int) $core['tenant_id'];
            $iid = (string) $core['installation_id'];
            InstallationService::createAdminAccount($owner, 'Studio Admin', 'studio-admin-backup@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query("INSERT INTO tenants (id, name, slug, status) VALUES (?,?,?,'active'), (?,?,?,'active') ON DUPLICATE KEY UPDATE name = VALUES(name)", [SBBK_A, 'A', 'tenant-a-bk', SBBK_B, 'B', 'tenant-b-bk']);
            sbbki_seed($owner, $iid, ['studio-builder']);
            $sel = CommercialModuleRegistry::validateSelection(['studio-builder'], ['studio-builder']);
            assert_true($sel['ok'], json_encode($sel));
            $act = \PluginLoader::installFromDisk('studio-builder');
            assert_true(!empty($act['ok']), json_encode($act));
            Media::ensureSchema();
            // ensureSchema() runs once per process, so in the full suite an earlier test has used it up on another database.
            $mediaColumns = array_column(Database::rows('SHOW COLUMNS FROM media_files'), 'Field');
            foreach (['alt_text' => 'VARCHAR(255) NULL', 'title' => 'VARCHAR(255) NULL', 'description' => 'TEXT NULL'] as $column => $definition) {
                if (!in_array($column, $mediaColumns, true)) {
                    Database::query("ALTER TABLE media_files ADD COLUMN `{$column}` {$definition}");
                }
            }
            sbbki_seed(SBBK_A, $iid, ['studio-builder']);
            sbbki_seed(SBBK_B, $iid, ['studio-builder']);

            $rt = StudioRuntimeFactory::build();
            $full = StudioActor::authenticated(85, StudioPermissions::ALL);
            $storeA = new StudioBackupStore(SBBK_A, $root);
            $storeB = new StudioBackupStore(SBBK_B, $root);
            $app = $rt->app;

            // ── Tenant A's site ───────────────────────────────────────────────
            [$backupPath, $home, $draft, $mediaId, $componentUuid] = $rt->tenants->runAs(SBBK_A, static function () use ($rt, $app, $full, $storeA, $mediaPath, $mediaAbs): array {
                $mediaId = Media::register($mediaPath, ['mime' => 'image/png', 'size_bytes' => (int) filesize($mediaAbs), 'original_name' => 'dot.png']);
                Media::updateMeta($mediaId, ['alt_text' => 'A dot']);

                $home = (int) $app->createPage($full, 'Home', 'home', 'page', 'homepage')['page']['id'];
                $rev = sbbki_heading($rt, $full, $home, 'Live text', $mediaId);
                $app->publish($full, $home, $rev);
                sbbki_heading($rt, $full, $home, 'Draft text', $mediaId);

                $draft = (int) $app->createPage($full, 'Plans', 'plans', 'page', 'standalone')['page']['id'];
                sbbki_heading($rt, $full, $draft, 'Plans draft');

                $app->createGlobalComponent($full, 'Promo', 'promo');
                $component = $rt->pages->findBySlug('promo', 'container_preset');
                $componentId = (int) $component['id'];
                $app->publish($full, $componentId, sbbki_heading($rt, $full, $componentId, 'Promo text'));
                $withRef = (int) $app->createPage($full, 'Offers', 'offers', 'page', 'standalone')['page']['id'];
                $offers = $app->loadEditorDocument($full, $withRef);
                $app->applyDocumentOperation($full, $withRef, [new DocumentOperation('insert_block', ['parent_id' => null, 'index' => 0, 'block' => ['type' => 'core.container', 'metadata' => ['label' => 'Promo'], 'global_ref' => (string) $component['uuid'], 'children' => []]])], (int) $offers['revision']['id']);
                $app->saveTemplateFromPage($full, $draft, null, 'bk-plans', 'page_template', 'general', 'Plans template', null);
                $app->saveDesignTokens($full, 'default', ['color.accent' => '#123456']);

                Database::setSetting(StudioMenus::SETTING_KEY, json_encode([['key' => 'header', 'name' => 'Header', 'items' => [['label' => 'Home', 'url' => '/', 'new_tab' => false]]]]), SBBK_A);
                Database::setSetting('studio_seo_description', 'The site', SBBK_A);
                Database::setSetting('studio_seo_og_media_id', (string) $mediaId, SBBK_A);
                Database::setSetting('studio_secret_key', 'do-not-export', SBBK_A);
                StudioMenus::forget();

                $made = $app->createBackup($full, $storeA);
                assert_eq(4, $made['counts']['pages']);
                assert_eq(1, $made['counts']['templates']);
                assert_eq(1, $made['counts']['token_groups']);
                assert_eq(1, $made['counts']['files']);
                return [$storeA->path($made['id']), $home, $draft, $mediaId, (string) $rt->pages->find($home)['uuid']];
            });

            // What the file holds.
            $zip = new \ZipArchive();
            assert_true($zip->open($backupPath) === true);
            $settings = json_decode((string) $zip->getFromName(StudioBackupFormat::ENTRY_SETTINGS), true);
            assert_eq('The site', $settings['studio_seo_description']);
            assert_false(isset($settings['studio_secret_key']), 'a setting off the allowlist stays out of the file');
            assert_eq(file_get_contents($mediaAbs), $zip->getFromName(StudioBackupFormat::fileEntry($mediaPath)));
            $zip->close();
            assert_false(is_file($backupPath . '.part'), 'no temporary file left');

            // ── Change the site, then restore ─────────────────────────────────
            $rt->tenants->runAs(SBBK_A, static function () use ($rt, $app, $full, $storeA, $backupPath, $home, $draft, $mediaId): void {
                $rev = sbbki_heading($rt, $full, $home, 'Broken text');
                $app->publish($full, $home, $rev);
                $app->archivePage($full, $draft);
                $later = (int) $app->createPage($full, 'Later', 'later', 'page', 'standalone')['page']['id'];
                Database::setSetting('studio_seo_description', 'Changed', SBBK_A);

                $pagesBefore = (int) Database::value('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ?', [SBBK_A]);
                $preview = $app->previewBackup($full, $backupPath);
                assert_eq(4, count($preview['update']), 'every page in the backup, the archived one too');
                assert_eq(0, count($preview['new']));
                assert_eq(['Later'], array_column($preview['kept'], 'title'), 'a page not in the backup is named');
                assert_eq([], $preview['blocked']);
                assert_eq(1, $preview['media']['existing']);
                assert_eq($pagesBefore, (int) Database::value('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ?', [SBBK_A]), 'a preview writes nothing');
                assert_eq('Changed', Database::setting('studio_seo_description', SBBK_A));

                $result = $app->restoreBackup($full, $storeA, $backupPath);
                assert_eq([], $result['failed'], 'nothing failed: ' . json_encode($result['failed']));
                assert_eq(2, $result['updated']);
                assert_eq(2, $result['unchanged'], 'a page nothing happened to gets no new revision');
                assert_true($storeA->path($result['safety_id']) !== null, 'a safety backup was taken first');

                $row = $rt->pages->find($home);
                assert_eq(['Live text', $mediaId], sbbki_content($rt, $home, (int) $row['published_revision_id']), 'the live version is back');
                assert_eq(['Draft text', $mediaId], sbbki_content($rt, $home, (int) $row['active_draft_revision_id']), 'and the draft on top of it');
                assert_eq('homepage', (string) $row['route_mode']);
                $plans = $rt->pages->find($draft);
                assert_eq('plans', (string) $plans['slug'], 'an archived page gets its address back');
                assert_eq('draft', (string) $plans['status']);
                assert_eq('Plans draft', sbbki_content($rt, $draft, (int) $plans['active_draft_revision_id'])[0]);
                assert_true($rt->pages->find($later) !== null, 'a page not in the backup is left alone');
                assert_eq('The site', Database::setting('studio_seo_description', SBBK_A));
                assert_eq('import', (string) $rt->revisions->findByIdForPage($home, (int) $row['active_draft_revision_id'])['revision_kind'], 'a restore is a revision in the page history');

                // Restoring the same file again changes nothing.
                $again = $app->restoreBackup($full, $storeA, $backupPath);
                assert_eq(4, $again['unchanged'], 'second restore: ' . json_encode($again));
                assert_eq(0, $again['updated']);

                // The safety backup puts the changed state back.
                $undo = $app->restoreBackup($full, $storeA, (string) $storeA->path($result['safety_id']));
                assert_eq([], $undo['failed'], json_encode($undo['failed']));
                assert_eq('Broken text', sbbki_content($rt, $home, (int) $rt->pages->find($home)['published_revision_id'])[0]);
            });

            // ── Another site, starting empty ──────────────────────────────────
            $rt->tenants->runAs(SBBK_B, static function () use ($rt, $app, $full, $storeB, $backupPath, $componentUuid, $mediaId): void {
                $preview = $app->previewBackup($full, $backupPath);
                assert_eq(4, count($preview['new']));
                assert_eq([], $preview['blocked'], 'references inside the backup count as present');
                assert_eq(1, $preview['media']['new']);

                $result = $app->restoreBackup($full, $storeB, $backupPath);
                assert_eq([], $result['failed'], json_encode($result['failed']));
                assert_eq(4, $result['created']);
                assert_eq(1, $result['templates']);
                assert_true($rt->templates->findByKey('bk-plans') !== null, 'the template moved');
                assert_eq(['default'], $result['token_groups']);
                assert_eq('#123456', (string) json_decode((string) Database::value("SELECT tokens_json FROM studiobuilder_tokens WHERE tenant_id = ? AND token_group = 'default'", [SBBK_B]), true)['color.accent'], 'the tokens moved');
                $promo = $rt->pages->findBySlug('promo', 'container_preset');
                assert_eq('published', (string) $promo['status'], 'the component is live');
                $offers = $rt->pages->findBySlug('offers', 'page');
                $doc = CanonicalJson::decode((string) $rt->revisions->findByIdForPage((int) $offers['id'], (int) $offers['active_draft_revision_id'])['document_json']);
                assert_eq((string) $promo['uuid'], (string) $doc['containers'][0]['global_ref'], 'a reference to a component still resolves');
                $row = $rt->pages->findByUuid($componentUuid);
                assert_true($row !== null, 'a moved page keeps its uuid');
                assert_eq('published', (string) $row['status']);
                assert_eq('homepage', (string) $row['route_mode'], 'and its homepage role');
                [$text, $image] = sbbki_content($rt, (int) $row['id'], (int) $row['published_revision_id']);
                assert_eq('Live text', $text);
                $local = Database::row('SELECT id, alt_text FROM media_files WHERE tenant_id = ? AND id = ?', [SBBK_B, $image]);
                assert_true($local !== null, 'the image points at this site\'s own media row');
                assert_eq('A dot', (string) $local['alt_text']);
                assert_eq((string) $image, Database::setting('studio_seo_og_media_id', SBBK_B), 'the site share image is remapped too');
                assert_true($image !== $mediaId, 'media ids are the target site\'s');
            });

            // ── Tampered files ────────────────────────────────────────────────
            $rt->tenants->runAs(SBBK_B, static function () use ($app, $full, $storeB, $backupPath, $mediaPath): void {
                $notBackup = $backupPath . '-text.zip';
                file_put_contents($notBackup, 'not a zip');
                assert_throws(StudioValidationException::class, static fn() => $app->previewBackup($full, $notBackup));
                $before = count($storeB->all());
                assert_throws(StudioValidationException::class, static fn() => $app->restoreBackup($full, $storeB, sbbki_tampered($backupPath, [StudioBackupFormat::ENTRY_MANIFEST => ['format' => 'other']])));
                assert_eq($before, count($storeB->all()), 'a file that is not a backup costs no safety backup');

                $evilPath = '/uploads/media/evil-' . bin2hex(random_bytes(3)) . '.php';
                $renamed = '/uploads/media/renamed-' . bin2hex(random_bytes(3)) . '.png';
                $bad = sbbki_tampered($backupPath, [
                    StudioBackupFormat::ENTRY_SETTINGS => ['studio_secret_key' => 'injected', 'studio_code_head_snippet' => '<?php echo 1; ?>'],
                    StudioBackupFormat::ENTRY_MEDIA => [
                        ['id' => 901, 'path' => $evilPath, 'sha256' => hash('sha256', '<?php')],
                        ['id' => 902, 'path' => '/../../config.php', 'sha256' => ''],
                        ['id' => 903, 'path' => $renamed, 'sha256' => hash('sha256', '<?php')],
                    ],
                    StudioBackupFormat::fileEntry($evilPath) => '<?php',
                    StudioBackupFormat::fileEntry($renamed) => '<?php',
                ]);
                $result = $app->restoreBackup($full, $storeB, $bad);
                assert_false(is_file(SLATE_ROOT . $evilPath), 'a .php path is never written');
                assert_false(is_file(SLATE_ROOT . $renamed), 'PHP named .png is refused by its bytes');
                assert_false(is_file(SLATE_ROOT . $renamed . '.restore'), 'no temporary file left');
                assert_true(in_array('media_skipped', array_column($result['warnings'], 'code'), true), json_encode($result['warnings']));
                assert_eq('', (string) Database::setting('studio_secret_key', SBBK_B), 'a setting off the allowlist is not restored');
                assert_true(!str_contains((string) Database::setting('studio_code_head_snippet', SBBK_B), '<?php'), 'a snippet passes the same check as its screen');
            });

            // ── Who may ───────────────────────────────────────────────────────
            $rt->tenants->runAs(SBBK_A, static function () use ($app, $storeA, $backupPath): void {
                $admin = StudioActor::authenticated(82, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::ADMIN]);
                $viewer = StudioActor::authenticated(80, [StudioPermissions::VIEW]);
                $ai = StudioActor::forMcpToken(9, 85, StudioPermissions::ALL);
                foreach ([$viewer, $admin, $ai] as $actor) {
                    assert_throws(StudioAuthorizationException::class, static fn() => $app->createBackup($actor, $storeA));
                    assert_throws(StudioAuthorizationException::class, static fn() => $app->restoreBackup($actor, $storeA, $backupPath));
                    assert_throws(StudioAuthorizationException::class, static fn() => $app->previewBackup($actor, $backupPath));
                }
            });
        });
    } finally {
        @unlink($mediaAbs);
        exec('rm -rf ' . escapeshellarg($root));
    }
});

if (!empty($studioBackupIntStandalone)) {
    exit(unit_summary());
}
