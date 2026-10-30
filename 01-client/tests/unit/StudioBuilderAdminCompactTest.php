<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the compact admin (UI audit phases 0 and 1).
 *
 * Autoloader only, no database. The Studio screens are listed once, in the platform sidebar, grouped under Content, Site,
 * Search and Advanced; Code & tracking is for Studio administrators there too; the screens share one stylesheet, one
 * wrapper, one way to show a message and the platform's danger button; every Studio string they show has a French entry.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
}
require_once SLATE_ROOT . '/includes/helpers.php';
if (!class_exists('Plugin')) {
    require_once SLATE_ROOT . '/includes/Plugin.php';
}
require_once SLATE_ROOT . '/plugins/studio-builder/StudioBuilder.php';
require_once SLATE_ROOT . '/plugins/studio-builder/admin/_nav.php';

/** @return list<string> the Studio admin screens that render the platform admin layout */
function sbac_screens(): array
{
    $files = [];
    foreach (glob(SLATE_ROOT . '/plugins/studio-builder/admin/*.php') ?: [] as $path) {
        if (str_contains((string) file_get_contents($path), "/admin/partials/header.php'")) {
            $files[] = basename($path);
        }
    }
    sort($files);
    return $files;
}

unit('compact admin: the sidebar lists each Studio screen once, Home first, then Content, Site, Search and Advanced', function (): void {
    $items = StudioBuilder::navItems();
    $slugs = array_column($items, 'slug');
    assert_eq(count($slugs), count(array_unique($slugs)), 'no screen twice');
    assert_eq('studio-overview', $slugs[0], 'Home first');
    assert_eq('', $items[0]['section'], 'Home has no section label');
    $sections = [];
    foreach (array_slice($items, 1) as $item) {
        if (end($sections) !== $item['section']) {
            $sections[] = $item['section'];
        }
    }
    assert_eq(['Content', 'Site', 'Search', 'Advanced'], $sections, 'each section once, in this order');
    $bySlug = array_column($items, null, 'slug');
    assert_eq('History', $bySlug['studio-reviews']['label'], 'revisions are called History');
    assert_eq('Tracking & code', $bySlug['studio-code-tracking']['label']);
    assert_false(isset($bySlug['studio-media']), 'Media is the platform Media Library, not a Studio entry');
    foreach ($items as $item) {
        $file = strtok($item['file'], '?');
        assert_true(is_file(SLATE_ROOT . '/plugins/studio-builder/' . $file), "{$item['slug']} opens {$file}");
    }
});

unit('compact admin: Code & tracking is in the sidebar only for Studio administrators, as on the screen itself', function (): void {
    $bySlug = array_column(StudioBuilder::navItems(), null, 'slug');
    assert_eq('studio-builder.admin', $bySlug['studio-code-tracking']['perm'] ?? null);
    assert_true(str_contains((string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/code-tracking.php'), "\$isAdmin = Auth::can('studio-builder.admin') || Auth::isSuperAdmin();\nif (!\$isAdmin) {"), 'the screen refuses everyone else');
    foreach (StudioBuilder::navItems() as $item) {
        if ($item['slug'] !== 'studio-code-tracking') {
            assert_true(!isset($item['perm']), "{$item['slug']} uses the Studio view permission");
        }
    }
});

unit('compact admin: the platform sidebar can label a run of items inside a group', function (): void {
    $header = (string) file_get_contents(SLATE_ROOT . '/admin/partials/header.php');
    assert_true(str_contains($header, '<li class="sidebar-subsection" role="presentation">'), 'a sub-section label');
    assert_true(str_contains($header, 'html.sidebar-collapsed .sidebar-subsection { display: none; }'), 'hidden on the icon rail');
});

unit('compact admin: no second Studio tab bar or banner; the screens share one stylesheet and one wrapper', function (): void {
    $nav = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/_nav.php');
    assert_false(function_exists('sb_render_admin_nav'), 'the in-page tab bar is gone');
    assert_false(str_contains($nav, '<style>'), 'no inline stylesheet printed by every screen');
    assert_true(is_file(SLATE_ROOT . '/plugins/studio-builder/assets/admin/studio-admin.css'));
    $plugin = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/StudioBuilder.php');
    assert_true(str_contains($plugin, "\\Hook::addAction('admin_head', [\$this, 'adminHead']);"), 'linked in the admin head');
    $screens = sbac_screens();
    assert_true(count($screens) >= 15, 'found the screens: ' . implode(', ', $screens));
    foreach ($screens as $file) {
        $src = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/' . $file);
        assert_eq(1, substr_count($src, '<div class="sb-admin">'), "{$file}: one wrapper");
        assert_false(str_contains($src, 'content-wrapper') || str_contains($src, 'sb-admin-wrapper'), "{$file}: no old wrapper");
        assert_false(str_contains($src, 'alert-danger'), "{$file}: the platform has no .alert-danger");
        assert_false((bool) preg_match('/style="color: ?#ef4444/', $src), "{$file}: no hand-coloured danger text");
        assert_true(str_contains($src, 'sb-screen-head') || str_contains($src, 'sb-page-head'), "{$file}: the shared screen header");
    }
});

unit('compact admin: a message is shown the same way everywhere, errors announced at once', function (): void {
    ob_start();
    sb_render_flash(['type' => 'danger', 'msg' => 'Nope <b>']);
    sb_render_flash(['type' => 'success', 'msg' => 'Saved']);
    sb_render_flash(null);
    $html = (string) ob_get_clean();
    assert_true(str_contains($html, '<div class="alert alert-error sb-alert" role="alert">Nope &lt;b&gt;</div>'), $html);
    assert_true(str_contains($html, '<div class="alert alert-success sb-alert" role="status">Saved</div>'), $html);
});

unit('compact admin: the density switch only changes spacing values, compact by default', function (): void {
    $css = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/assets/admin/studio-admin.css');
    assert_true(str_contains($css, 'html[data-sb-density="comfortable"] {'), 'a comfortable override');
    preg_match('/html\[data-sb-density="comfortable"\] \{(.*?)\}/s', $css, $m);
    preg_match_all('/^\s*([a-z-]+):/m', $m[1] ?? '', $props);
    foreach ($props[1] as $prop) {
        assert_true(str_starts_with($prop, '--sb-'), "only --sb-* values change: {$prop}");
    }
});

unit('compact admin: every Studio string the screens and the sidebar show has a French entry', function (): void {
    $fr = require SLATE_ROOT . '/plugins/studio-builder/lang/fr.php';
    $src = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/StudioBuilder.php');
    foreach (['seo.php', 'reviews.php', 'redirects.php', 'templates.php', 'types-fields.php', 'index.php', 'media.php'] as $file) {
        $src .= (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/' . $file);
    }
    preg_match_all("/(?:__|\\\$t)\\('(studio_[a-z0-9_]+)',/", $src, $m);
    $missing = array_values(array_filter(array_unique($m[1]), static fn (string $k): bool => !isset($fr[$k]) && !str_starts_with($k, 'studio_header_preset_')));
    assert_eq([], $missing, 'fr: ' . implode(', ', $missing));
});
