<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the admin screens (2026-10-26 builder audit: admin findings).
 *
 * Autoloader only, no database. Content items are deleted by a key, not a position; the Reviews screen keeps the
 * revisions the diff and the builder's Undo still read; every screen that writes redirects after the change (POST/
 * redirect/GET) and checks the Studio licence; and the smaller fixes (new page with a failed template, a system override
 * route, the redirects "required" check, icons) hold. The screens are procedural, so some checks read their source.
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
require_once SLATE_ROOT . '/plugins/studio-builder/admin/_revisions.php';
require_once SLATE_ROOT . '/plugins/studio-builder/admin/_nav.php';

function sbas_admin(string $file): string
{
    return (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/' . $file);
}

unit('reviews: the draft, the live revision and the draft\'s recent lineage are kept; older history is not', function (): void {
    // Page 1: 1 <- 2 <- 3 <- 4 (draft); 2 is published. Page 2: draft 10, nothing published.
    $parentOf = [2 => 1, 3 => 2, 4 => 3, 10 => 0];
    $kept = sb_revisions_kept([['d' => 4, 'p' => 2], ['d' => 10, 'p' => null]], $parentOf);
    assert_eq('current draft', $kept[4]);
    assert_eq('published', $kept[2], 'the published reason is not overwritten by the lineage');
    assert_eq('history', $kept[3], 'the draft\'s parent: the default diff compares with it, Undo goes back to it');
    assert_eq('history', $kept[1]);
    assert_eq('current draft', $kept[10]);

    $long = [];
    for ($i = 2; $i <= 120; $i++) {
        $long[$i] = $i - 1;
    }
    $kept = sb_revisions_kept([['d' => 120, 'p' => 0]], $long);
    assert_true(isset($kept[70]) && !isset($kept[69]), 'the lineage is kept as deep as the builder\'s Undo (50 steps), no deeper');

    $cycle = sb_revisions_kept([['d' => 5, 'p' => 0]], [5 => 6, 6 => 5]);
    assert_eq([5 => 'current draft', 6 => 'history'], $cycle, 'a loop in the links ends the walk');

    $rollback = sb_revisions_kept([['d' => 30, 'p' => 0]], [30 => 12, 29 => 28, 12 => 11]);
    assert_true(isset($rollback[12], $rollback[11]), 'a restore\'s parent is the snapshot it went back to, and its lineage');
    assert_false(isset($rollback[29]), 'a revision that is not in the draft\'s lineage can go');
});

unit('reviews: the screen deletes and marks "in use" with the same rule', function (): void {
    $src = sbas_admin('reviews.php');
    assert_eq(2, substr_count($src, 'sb_revisions_kept_for($tenantId, '), 'the delete and the list ask the same helper');
    assert_true(str_contains($src, "sb_flash_redirect('reviews.php?' . http_build_query(\$listQuery), \$restored);"), 'a restore redirects back to the list it came from');
    assert_false(str_contains($src, '$e->getMessage()'), 'no exception text is shown');
});

unit('admin screens: each change redirects (303) with its message in the session, and the screen shows it once', function (): void {
    foreach (['templates.php', 'types-fields.php', 'redirects.php', 'media.php', 'seo.php', 'code-tracking.php', 'reviews.php'] as $file) {
        $src = sbas_admin($file);
        assert_true(str_contains($src, 'sb_flash_redirect('), "{$file} redirects after a change");
        assert_true(str_contains($src, '$flash = $flash ?? sb_take_flash();'), "{$file} shows the message after the redirect");
    }
    $ai = sbas_admin('ai-mcp.php');
    assert_false(str_contains($ai, "'POST'") || str_contains($ai, '<form'), 'the AI screen is read-only: nothing to redirect');

    $_SESSION = ['sb_flash' => ['type' => 'success', 'msg' => 'Saved.', 'published_routes' => ['portfolio']]];
    assert_eq(['type' => 'success', 'msg' => 'Saved.', 'published_routes' => ['portfolio']], sb_take_flash(), 'extra keys travel with the message');
    assert_null(sb_take_flash(), 'shown once');
    $_SESSION = ['sb_flash' => 'junk'];
    assert_null(sb_take_flash(), 'anything else is ignored');
    $_SESSION = [];
});

unit('admin screens: a screen that writes settings or rows itself checks the Studio licence first', function (): void {
    foreach (['templates.php', 'types-fields.php', 'redirects.php', 'media.php', 'seo.php', 'code-tracking.php', 'reviews.php', 'menu-new.php', 'menu-edit.php'] as $file) {
        assert_true(str_contains(sbas_admin($file), '!sb_studio_entitled()'), "{$file} checks the entitlement");
    }
    assert_true(str_contains(sbas_admin('_nav.php'), "ModuleGuard::allows('studio-builder')"), 'the same entitlement the application service asks for');
});

unit('types & fields: the screen reads the field list exactly as the pages do, so a first field keeps the starters', function (): void {
    $src = sbas_admin('types-fields.php');
    assert_true(str_contains($src, '$metaFields = PageMeta::allFields($tenantId);'));
    assert_false(str_contains($src, "Database::setting('studio_meta_fields'"), 'no second reading of the setting');
});

unit('new page: a template that cannot be applied leaves the created page, says so, and links to it', function (): void {
    $src = sbas_admin('page-new.php');
    $create = strpos($src, '$studioApp->createPage(');
    $apply = strpos($src, '$studioApp->applyTemplate(');
    $inner = strpos($src, "sb_flash_redirect('page-edit.php?id=' . \$newId");
    assert_true($create !== false && $apply !== false && $inner !== false && $create < $apply && $apply < $inner, 'its own try, then the page settings');
    assert_true(str_contains($src, 'studio_page_created_template_failed'));
    assert_true(str_contains(sbas_admin('page-edit.php'), '$flash = $flash ?? sb_take_flash();'), 'the settings screen shows the message');
});

unit('page settings: a route mode the form does not offer (a system override) is kept on save', function (): void {
    $src = sbas_admin('page-edit.php');
    assert_true(str_contains($src, "if (!in_array((string) \$page['route_mode'], ['standalone', 'homepage'], true)):"));
    assert_true(str_contains($src, '<option value="<?= e((string) $page[\'route_mode\']) ?>" selected>'), 'selected, so the posted value is unchanged');
});

unit('pages: bulk delete forgets the deleted pages\' custom field values, like a single delete', function (): void {
    $src = sbas_admin('_page_bulk.php');
    assert_true(str_contains($src, '$forget ??= static fn(int $pageId) => PageMeta::forget(current_tenant_id(), $pageId);'));
    assert_true(str_contains($src, "\$app->archivePage(\$actor, \$pageId);\n                \$forget(\$pageId);"), 'only after the archive succeeded');
});

unit('redirects: an empty field is refused before the paths are normalised to "/"', function (): void {
    $src = sbas_admin('redirects.php');
    $missing = strpos($src, "\$missing = \$fromPath === '' || \$toPath === '';");
    $normalise = strpos($src, "\$fromPath = '/' . ltrim(\$fromPath, '/');");
    assert_true($missing !== false && $normalise !== false && $missing < $normalise);
    assert_true(str_contains($src, 'if ($missing) {'));
});

unit('admin screens: every icon a screen asks for is in the icon set', function (): void {
    $nav = sbas_admin('_nav.php');
    preg_match('/\$icons = \[(.*?)\n        \];/s', $nav, $m);
    preg_match_all("/^\s+'([a-z0-9-]+)' =>/m", $m[1] ?? '', $defined);
    $defined = array_flip($defined[1]);
    foreach (glob(SLATE_ROOT . '/plugins/studio-builder/admin/*.php') as $file) {
        preg_match_all("/sb_svg\('([a-z0-9-]+)'/", (string) file_get_contents($file), $used);
        foreach ($used[1] as $icon) {
            assert_true(isset($defined[$icon]), basename($file) . " uses the icon '{$icon}'");
        }
    }
});

unit('admin screens: the empty Pages filter and the empty Overview have keys of their own', function (): void {
    assert_true(str_contains(sbas_admin('pages.php'), "__('studio_no_pages_match', 'No Studio pages match this filter')"));
    assert_false(str_contains(sbas_admin('pages.php'), "__('studio_no_pages',"), 'the Overview\'s "no pages yet" is not reused for "none match"');
    $fr = require SLATE_ROOT . '/plugins/studio-builder/lang/fr.php';
    foreach (['studio_no_pages', 'studio_no_pages_match', 'studio_no_pages_match_hint', 'studio_no_pages_first'] as $k) {
        assert_true(isset($fr[$k]), "fr: {$k}");
    }
});

unit('menus: every string the menu screens and the add panel show has a French translation, and the form posts each item\'s classes', function (): void {
    $fr = require SLATE_ROOT . '/plugins/studio-builder/lang/fr.php';
    $src = sbas_admin('_menu_form.php') . sbas_admin('menu-new.php') . sbas_admin('menu-edit.php') . (string) file_get_contents(SLATE_ROOT . '/src/Module/StudioBuilder/Menu/MenuSources.php');
    preg_match_all("/(?:__|self::t)\('(studio_menu[a-z_]*)',/", $src, $m);
    foreach (array_unique($m[1]) as $key) {
        assert_true(isset($fr[$key]), "fr: {$key}");
    }
    assert_true(isset($fr['studio_menu_source_product'], $fr['studio_menu_type_product']), 'fr: Shop products in the add panel');
    assert_true(str_contains(sbas_admin('_menu_form.php'), 'name="items[<?= e($i) ?>][class]"'), 'the form posts an item\'s CSS classes');
    foreach (['menu-new.php', 'menu-edit.php'] as $file) {
        assert_true(str_contains(sbas_admin($file), "'class' => (string) (\$r['class'] ?? '')"), "{$file} keeps the posted classes when a save is refused");
    }
    assert_true(str_contains(sbas_admin('menu-edit.php'), 'id="sb-menu-delete"') && str_contains(sbas_admin('_menu_form.php'), 'form="<?= e($menuDeleteForm) ?>"'), 'Delete menu sits by Save menu but posts its own form');
});
