<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the builder page shell.
 *
 * Source-level pins (no database): the builder is a full-screen app, so the
 * multilang-translate plugin must not float its language switcher over it, and
 * the document language must follow the active locale rather than a fixed "en".
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

unit('builder page: opts out of the multilang floating switcher and honours the opt-out', function (): void {
    $page = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/builder.php');
    $mlt  = (string) file_get_contents(SLATE_ROOT . '/plugins/multilang-translate/MultilangTranslate.php');

    $define = strpos($page, "define('SLATE_NO_LANG_SWITCHER', true)");
    $output = strpos($page, '<!DOCTYPE html>');
    assert_true($define !== false && $output !== false && $define < $output, 'the builder defines SLATE_NO_LANG_SWITCHER before any output is flushed');

    $guard  = strpos($mlt, "defined('SLATE_NO_LANG_SWITCHER')");
    $inject = strpos($mlt, "preg_replace('/<\\/body>/i'");
    assert_true($guard !== false && $inject !== false && $guard < $inject, 'the plugin checks the opt-out before appending the widget');
});

unit('builder canvas: the editing document never gets the language-switcher widget', function (): void {
    $canvas = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/canvas.php');
    assert_true(str_contains($canvas, "define('SLATE_NO_LANG_SWITCHER', true)"), 'canvas.php opts out of the switcher');
    assert_true(strpos($canvas, "define('SLATE_NO_LANG_SWITCHER'") < strpos($canvas, 'StudioRuntimeFactory::'), 'and does so before it renders');
});

unit('builder page: the document language follows the active locale', function (): void {
    $page = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/builder.php');
    assert_true(!str_contains($page, '<html lang="en">'), 'no hard-coded English document language');
    assert_true(str_contains($page, 'I18n::currentLocale()'), 'lang comes from I18n::currentLocale()');
});
