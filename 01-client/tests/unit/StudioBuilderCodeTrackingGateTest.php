<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the Code & tracking screen is for Studio administrators only.
 *
 * Autoloader only, no database. The screen writes scripts and CSS that run on every public page, so it refuses anyone
 * without `studio-builder.admin` (before it renders or reads a POST), and the admin navigation does not offer it to them.
 * Read from the source, because both are procedural admin scripts.
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

unit('code & tracking: a non-administrator gets a 403 before the screen reads a form or a setting', function (): void {
    $src = (string) file_get_contents(__DIR__ . '/../../plugins/studio-builder/admin/code-tracking.php');
    $gate = strpos($src, "if (!\$isAdmin) {\n    http_response_code(403);");
    assert_true($gate !== false, 'the 403 gate for non-administrators is present');
    assert_true(str_contains($src, "\$isAdmin = Auth::can('studio-builder.admin') || Auth::isSuperAdmin();"), 'administrator means studio-builder.admin or a super admin');
    $post = strpos($src, "\$_SERVER['REQUEST_METHOD'] === 'POST'");
    $read = strpos($src, 'Database::setting(StudioCodePolicy::SETTING_CUSTOM_CSS');
    assert_true($post !== false && $read !== false, 'the screen still reads settings and handles a form');
    assert_true($gate < $post && $gate < $read, 'the gate comes before any setting is read or any form is handled');
    assert_true(!str_contains($src, "Auth::can('studio-builder.edit')"), 'edit permission no longer opens the screen');
});

unit('code & tracking: the admin navigation hides the screen from non-administrators', function (): void {
    $src = (string) file_get_contents(__DIR__ . '/../../plugins/studio-builder/admin/_nav.php');
    assert_true(str_contains($src, "\$item['id'] !== 'code-tracking'"), 'the code-tracking item is filtered out');
    assert_true(str_contains($src, "Auth::can('studio-builder.admin') || Auth::isSuperAdmin()"), 'the filter keys on the administrator permission');
});
