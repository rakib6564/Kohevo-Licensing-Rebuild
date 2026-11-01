<?php
/**
 * FightxLife — install the site on a Kohevo install that held the Solaya site.
 *
 *   cd <site root> && php <bundle>/install.php
 *
 * 1. Removes the Solaya site completely (first run only): every Studio page, partial and theme template with their
 *    revisions, compilations, dependencies, locks and page settings; its own Studio templates (the built-in system
 *    presets stay); its menus, design tokens, custom CSS, head snippet and site settings; its `solaya-contact` form and
 *    submissions; its media rows and files (uploads/media/solaya-site, and the branding uploads).
 * 2. Copies the FightxLife images into uploads/media/fxl and registers them in the media library.
 * 3. Runs the FightxLife build (build/site.php): tokens, fonts, content types and fields, 73 pages, header, footer,
 *    5 single templates, redirects.
 * 4. Sets the admin and login branding (logo, colour, favicon, login photo and tagline).
 *
 * Idempotent: a second run deletes nothing (Solaya is gone), re-copies nothing that is already in place and rebuilds
 * the same pages in place. Reads only files inside this bundle; no network.
 *
 *   php <bundle>/install.php --shop   only the shop: images, the Shop products, settings and pages (e-shop, boutique,
 *                                     panier, commande, commande-recue), the product template and the header. Every
 *                                     other page stays as an editor left it.
 *
 * Needs the Shop and stripe-payment plugins active and the `shop` entitlement (the e-shop, cart and checkout).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$site = getcwd();
$bundle = __DIR__;
if (!is_file($site . '/config.php') || !is_dir($site . '/src/Module/StudioBuilder')) {
    fwrite(STDERR, "Run this from the site root: cd ~/public_html/fightxlife && php {$bundle}/install.php\n");
    exit(1);
}
require_once $site . '/config.php';

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Registry\BlockParts;
use Slate\Services\Media\Media;

const FXL_MARKER = 'fightxlife_installed_at';

function out(string $s): void { echo $s, "\n"; }
function fail(string $s): never { fwrite(STDERR, "! {$s}\n"); exit(1); }

// ── 0. The Studio this build needs ─────────────────────────────────────────────────────────────────────────────────
// The design is native and uses Site settings › Text, the "all but the first" part state and grid row spans
// (feat/studio-native-finish). An older Studio would refuse the documents halfway through, so stop before changing anything.
if (!str_contains(CanonicalDocumentSchema::TOKEN_REF_PATTERN, '|type|') || !isset(BlockParts::STATES['not_first'])
    || !isset(\Slate\Module\StudioBuilder\Render\Theme\ThemeResolver::DEFAULT_TOKENS['type.smoothing'])) {
    fail('This site runs a Studio without the text settings and part states FightxLife needs (feat/studio-native-finish, up to 142d39a). Deploy that code first; nothing was changed.');
}
$tid = current_tenant_id();
PluginLoader::activePlugins();
$shopOnly = in_array('--shop', $argv, true);
if ($shopOnly) {
    define('FXL_SHOP_ONLY', true);
}
if (!class_exists('ShopStoreAPI') || !\Slate\Services\Licensing\EntitlementService::canAccess($tid, 'shop')) {
    fail('The e-shop needs the Shop plugin active and licensed (with stripe-payment). Install and license it first; nothing was changed.');
}
$hasForms = (bool) Database::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'forms_definitions'");
out("FightxLife install — tenant {$tid}, site " . (defined('SLATE_URL') ? SLATE_URL : '?'));

// ── 1. Remove Solaya (first run only) ──────────────────────────────────────────────────────────────────────────────
$deleted = [];
$installedAt = (string) Database::setting(FXL_MARKER, $tid);
if ($installedAt === '' && $shopOnly) {
    fail('--shop updates an installed FightxLife site; run the full install first. Nothing was changed.');
}
if ($installedAt === '') {
    $pages = Database::all('SELECT id, page_type, status, slug FROM studiobuilder_pages WHERE tenant_id = ? ORDER BY id', [$tid]);
    $ids = array_map(static fn(array $p): int => (int) $p['id'], $pages);
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $args = array_merge([$tid], $ids);
        $counts = [];
        foreach (['studiobuilder_compilations', 'studiobuilder_dependencies', 'studiobuilder_locks', 'studiobuilder_revisions'] as $table) {
            $counts[$table] = (int) Database::value("SELECT COUNT(*) FROM {$table} WHERE tenant_id = ? AND page_id IN ({$in})", $args);
            Database::query("DELETE FROM {$table} WHERE tenant_id = ? AND page_id IN ({$in})", $args);
        }
        $metaKeys = array_map(static fn(int $id): string => PageMeta::SETTING_PREFIX . $id, $ids);
        $min = implode(',', array_fill(0, count($metaKeys), '?'));
        $counts['page settings'] = (int) Database::value("SELECT COUNT(*) FROM settings WHERE tenant_id = ? AND setting_key IN ({$min})", array_merge([$tid], $metaKeys));
        Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key IN ({$min})", array_merge([$tid], $metaKeys));
        Database::query("DELETE FROM studiobuilder_pages WHERE tenant_id = ? AND id IN ({$in})", $args);
        $byType = [];
        foreach ($pages as $p) {
            $byType[$p['page_type'] . ' ' . $p['status']] = ($byType[$p['page_type'] . ' ' . $p['status']] ?? 0) + 1;
        }
        ksort($byType);
        $deleted[] = count($ids) . ' Studio pages (' . implode(', ', array_map(static fn($k, $v) => "{$v} {$k}", array_keys($byType), $byType)) . ')';
        $deleted[] = "  with {$counts['studiobuilder_revisions']} revisions, {$counts['studiobuilder_compilations']} compilations, {$counts['studiobuilder_dependencies']} dependencies, {$counts['studiobuilder_locks']} locks, {$counts['page settings']} page settings";
        $deleted[] = '  published: ' . implode(', ', array_map(static fn(array $p): string => "{$p['page_type']}/{$p['slug']}", array_filter($pages, static fn(array $p): bool => $p['status'] === 'published')));
    }
    $own = Database::all('SELECT template_key FROM studiobuilder_templates WHERE tenant_id = ? AND is_system = 0 ORDER BY id', [$tid]);
    if ($own) {
        Database::query('DELETE FROM studiobuilder_templates WHERE tenant_id = ? AND is_system = 0', [$tid]);
        $deleted[] = count($own) . ' Studio templates of the site: ' . implode(', ', array_column($own, 'template_key')) . ' (the built-in system presets stay)';
    }
    $tokens = (int) Database::value('SELECT COUNT(*) FROM studiobuilder_tokens WHERE tenant_id = ?', [$tid]);
    if ($tokens) {
        Database::query('DELETE FROM studiobuilder_tokens WHERE tenant_id = ?', [$tid]);
        $deleted[] = "{$tokens} design token set(s)";
    }

    // Site settings that belong to the Solaya site (the build and the branding step below write FightxLife's own).
    $keys = ['site_name', 'studio_menus', 'studio_code_custom_css', 'studio_code_head_snippet', 'studio_code_footer_snippet', 'studio_active_theme',
        'studio_content_portfolio', 'studio_removed_post_types', 'studio_custom_post_types', 'studio_meta_fields', 'studio_redirects',
        'studio_seo_description', 'studio_seo_og_image_url', 'studio_seo_og_media_id', 'studio_seo_title_format',
        'brand_accent_color', 'brand_logo_path', 'brand_logo_dark_path', 'brand_favicon_path', 'brand_login_image_path', 'brand_login_tagline'];
    $global = Database::all("SELECT setting_key FROM settings WHERE tenant_id = ? AND setting_key LIKE 'studio\\_global\\_%'", [$tid]);
    $keys = array_merge($keys, array_column($global, 'setting_key'));
    $kin = implode(',', array_fill(0, count($keys), '?'));
    $present = array_column(Database::all("SELECT setting_key FROM settings WHERE tenant_id = ? AND setting_key IN ({$kin})", array_merge([$tid], $keys)), 'setting_key');
    if ($present) {
        Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key IN ({$kin})", array_merge([$tid], $keys));
        sort($present);
        $deleted[] = count($present) . ' site settings: ' . implode(', ', $present);
    }

    // Emails the site sends go out under the site's name, not Solaya's (the address and the mail server stay).
    if (stripos((string) Database::setting('smtp_from_name', $tid), 'solaya') !== false) {
        Database::setSetting('smtp_from_name', 'FightxLife', $tid);
        $deleted[] = 'email sender name smtp_from_name: Solaya → FightxLife';
    }

    // The Solaya contact form and what was sent through it.
    if ($hasForms) {
        $form = Database::row("SELECT id FROM forms_definitions WHERE tenant_id = ? AND slug = 'solaya-contact'", [$tid]);
        if ($form) {
            $subs = (int) Database::value('SELECT COUNT(*) FROM forms_submissions WHERE tenant_id = ? AND form_id = ?', [$tid, (int) $form['id']]);
            Database::query('DELETE FROM forms_submissions WHERE tenant_id = ? AND form_id = ?', [$tid, (int) $form['id']]);
            Database::query('DELETE FROM forms_definitions WHERE tenant_id = ? AND id = ?', [$tid, (int) $form['id']]);
            $deleted[] = "form solaya-contact and its {$subs} submission(s)";
        }
    }

    // Solaya's images and branding uploads: rows and files.
    $media = Database::all("SELECT id, path FROM media_files WHERE tenant_id = ? AND (path LIKE '/uploads/media/solaya-site/%' OR path LIKE '/uploads/branding/%')", [$tid]);
    $files = 0;
    foreach ($media as $m) {
        $abs = $site . '/' . ltrim((string) $m['path'], '/');
        if (is_file($abs) && @unlink($abs)) {
            $files++;
        }
        Database::query('DELETE FROM media_files WHERE tenant_id = ? AND id = ?', [$tid, (int) $m['id']]);
    }
    $dir = $site . '/uploads/media/solaya-site';
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } elseif (@unlink($f->getPathname())) {
                $files++;
            }
        }
        @rmdir($dir);
    }
    if ($media || $files) {
        $deleted[] = count($media) . " media rows and {$files} files (uploads/media/solaya-site, uploads/branding)";
    }
}

// ── 2. FightxLife images ───────────────────────────────────────────────────────────────────────────────────────────
$src = $bundle . '/media/fxl';
$dst = $site . '/uploads/media/fxl';
if (!is_dir($dst) && !mkdir($dst, 0755, true) && !is_dir($dst)) {
    fail("cannot create {$dst} (check the uploads folder is writable)");
}
$copied = 0;
$registered = 0;
foreach (scandir($src) ?: [] as $name) {
    if ($name[0] === '.' || !is_file("{$src}/{$name}")) {
        continue;
    }
    if (!is_file("{$dst}/{$name}") || filesize("{$dst}/{$name}") !== filesize("{$src}/{$name}")) {
        if (!copy("{$src}/{$name}", "{$dst}/{$name}")) {
            fail("cannot copy {$name} into {$dst}");
        }
        $copied++;
    }
    $path = '/uploads/media/fxl/' . $name;
    $size = @getimagesize("{$dst}/{$name}") ?: [null, null, 'mime' => mime_content_type("{$dst}/{$name}") ?: ''];
    $exists = (int) Database::value('SELECT id FROM media_files WHERE tenant_id = ? AND path = ?', [$tid, $path]);
    if ($exists <= 0) {
        Media::register($path, ['mime' => (string) ($size['mime'] ?? ''), 'size_bytes' => filesize("{$dst}/{$name}"), 'width' => $size[0] ?? null, 'height' => $size[1] ?? null, 'original_name' => $name]);
        $registered++;
    }
}
out("images: {$copied} copied, {$registered} registered, " . count(glob($dst . '/*') ?: []) . ' in uploads/media/fxl');

// ── 3. The build ───────────────────────────────────────────────────────────────────────────────────────────────────
out('build:');
require $bundle . '/build/site.php';

// A Studio form's Forms entry is named after its block (sb-<block id>). The two FightxLife forms keep their ids from
// build to build; an entry left by a form block that no page holds any more, with nothing sent through it, goes.
if ($hasForms && !$shopOnly) {
    $live = [];
    foreach (Database::all("SELECT r.document_json FROM studiobuilder_pages p JOIN studiobuilder_revisions r ON r.id = p.published_revision_id WHERE p.tenant_id = ? AND p.status = 'published'", [$tid]) as $row) {
        $walk = static function (array $blocks) use (&$walk, &$live): void {
            foreach ($blocks as $b) {
                if (($b['type'] ?? '') === 'core.form' && is_string($b['id'] ?? null)) {
                    $live['sb-' . str_replace('_', '', $b['id'])] = true;
                }
                $walk(is_array($b['children'] ?? null) ? $b['children'] : []);
            }
        };
        $document = json_decode((string) $row['document_json'], true);
        $walk((array) (\Slate\Module\StudioBuilder\Document\DocumentUpgrader::structure(is_array($document) ? $document : [])['containers'] ?? []));
    }
    $gone = [];
    foreach (Database::all("SELECT d.id, d.slug FROM forms_definitions d WHERE d.tenant_id = ? AND d.slug LIKE 'sb-blk%' AND NOT EXISTS (SELECT 1 FROM forms_submissions s WHERE s.form_id = d.id)", [$tid]) as $def) {
        if (!isset($live[$def['slug']])) {
            Database::query('DELETE FROM forms_definitions WHERE tenant_id = ? AND id = ?', [$tid, (int) $def['id']]);
            $gone[] = $def['slug'];
        }
    }
    if ($gone) {
        out('forms: removed ' . count($gone) . ' empty entries of form blocks no page holds any more');
    }
}

// ── 4. Admin and login branding ────────────────────────────────────────────────────────────────────────────────────
$brand = [
    'site_name'              => 'FightxLife',
    'brand_accent_color'     => '#e9008a',
    'brand_logo_path'        => '/uploads/media/fxl/2022-05-cropped-logo-fxl-01-e1638355780533-1.png',
    // The live site has no logo for dark backgrounds; the same one is used.
    'brand_logo_dark_path'   => '/uploads/media/fxl/2022-05-cropped-logo-fxl-01-e1638355780533-1.png',
    'brand_favicon_path'     => '/uploads/media/fxl/fxl-site-icon-192.png',
    'brand_login_image_path' => '/uploads/media/fxl/2022-06-woman-fighting-breast-cancer-awareness-scaled.jpg',
    'brand_login_tagline'    => 'La force de vivre',
];
foreach ($shopOnly ? [] : $brand as $key => $value) {
    if (str_starts_with($value, '/uploads/') && !is_file($site . $value)) {
        fail("branding file missing: {$value}");
    }
    Database::setSetting($key, $value, $tid);
}
out($shopOnly ? 'branding: left as it is (--shop)' : 'branding: ' . implode(', ', array_keys($brand)));

if ($installedAt === '') {
    Database::setSetting(FXL_MARKER, gmdate('c'), $tid);
}

// ── Summary ────────────────────────────────────────────────────────────────────────────────────────────────────────
out('');
out($installedAt === '' ? 'Removed the Solaya site:' : "Solaya: nothing to remove (it was removed by the first run, {$installedAt}).");
foreach ($deleted as $line) {
    out('  - ' . ltrim($line));
}
$live = Database::all("SELECT page_type, COUNT(*) n FROM studiobuilder_pages WHERE tenant_id = ? AND status = 'published' GROUP BY page_type ORDER BY page_type", [$tid]);
out('Published now: ' . implode(', ', array_map(static fn(array $r): string => "{$r['n']} {$r['page_type']}", $live)));
out('Open ' . (defined('SLATE_URL') ? rtrim((string) SLATE_URL, '/') : '') . '/ and ' . (defined('SLATE_URL') ? rtrim((string) SLATE_URL, '/') : '') . '/admin/login.php');
