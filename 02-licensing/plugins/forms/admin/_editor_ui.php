<?php
/**
 * Forms — record-editor UI shim.
 *
 * The editor kit now lives in the shared core component
 * includes/record_editor.php (slate_edit_* / slate_editor_*). This file keeps
 * the forms_edit_* / forms_editor_* names working so the forms admin pages need
 * no changes — each is a thin alias to the shared function.
 */
if (!defined('SLATE_ROOT')) exit;
require_once SLATE_ROOT . '/includes/record_editor.php';

if (!function_exists('forms_edit_icon')) {
    function forms_edit_icon(string $name, int $size = 16): string { return slate_edit_icon($name, $size); }
}
if (!function_exists('forms_edit_open')) {
    function forms_edit_open(array $a = []): void { slate_edit_open($a); }
}
if (!function_exists('forms_edit_close')) {
    function forms_edit_close(): void { slate_edit_close(); }
}
if (!function_exists('forms_edit_hero')) {
    function forms_edit_hero(array $a): void { slate_edit_hero($a); }
}
if (!function_exists('forms_edit_card_open')) {
    function forms_edit_card_open(array $a): void { slate_edit_card_open($a); }
}
if (!function_exists('forms_edit_card_close')) {
    function forms_edit_card_close(): void { slate_edit_card_close(); }
}
if (!function_exists('forms_edit_card_note')) {
    function forms_edit_card_note(string $html): void { slate_edit_card_note($html); }
}
if (!function_exists('forms_edit_backlink')) {
    function forms_edit_backlink(array $a): void { slate_edit_backlink($a); }
}
if (!function_exists('forms_edit_actionbar')) {
    function forms_edit_actionbar(array $a): void { slate_edit_actionbar($a); }
}
if (!function_exists('forms_edit_tabs')) {
    function forms_edit_tabs(array $tabs): void { slate_edit_tabs($tabs); }
}
if (!function_exists('forms_editor_css')) {
    function forms_editor_css(): void { slate_editor_css(); }
}
if (!function_exists('forms_editor_js')) {
    function forms_editor_js(): void { slate_editor_js(); }
}
