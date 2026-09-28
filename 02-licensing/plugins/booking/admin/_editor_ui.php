<?php
/**
 * Booking — record-editor UI shim.
 *
 * The editor kit now lives in the shared core component
 * includes/record_editor.php (slate_edit_* / slate_editor_*). This file keeps
 * the historical booking_edit_* / booking_editor_* names working so the booking
 * admin pages need no changes — each is a thin alias to the shared function.
 */
if (!defined('SLATE_ROOT')) exit;
require_once SLATE_ROOT . '/includes/record_editor.php';

if (!function_exists('booking_edit_icon')) {
    function booking_edit_icon(string $name, int $size = 16): string { return slate_edit_icon($name, $size); }
}
if (!function_exists('booking_edit_switch')) {
    function booking_edit_switch(string $name, bool $checked, array $opts = []): string { return slate_edit_switch($name, $checked, $opts); }
}
if (!function_exists('booking_edit_open')) {
    function booking_edit_open(array $a = []): void { slate_edit_open($a); }
}
if (!function_exists('booking_edit_close')) {
    function booking_edit_close(): void { slate_edit_close(); }
}
if (!function_exists('booking_edit_hero')) {
    function booking_edit_hero(array $a): void { slate_edit_hero($a); }
}
if (!function_exists('booking_edit_card_open')) {
    function booking_edit_card_open(array $a): void { slate_edit_card_open($a); }
}
if (!function_exists('booking_edit_card_close')) {
    function booking_edit_card_close(): void { slate_edit_card_close(); }
}
if (!function_exists('booking_edit_card_note')) {
    function booking_edit_card_note(string $html): void { slate_edit_card_note($html); }
}
if (!function_exists('booking_edit_days_open')) {
    function booking_edit_days_open(): void { slate_edit_days_open(); }
}
if (!function_exists('booking_edit_days_close')) {
    function booking_edit_days_close(): void { slate_edit_days_close(); }
}
if (!function_exists('booking_edit_day_row')) {
    function booking_edit_day_row(array $a): void { slate_edit_day_row($a); }
}
if (!function_exists('booking_edit_toggle_row')) {
    function booking_edit_toggle_row(array $a): void { slate_edit_toggle_row($a); }
}
if (!function_exists('booking_edit_backlink')) {
    function booking_edit_backlink(array $a): void { slate_edit_backlink($a); }
}
if (!function_exists('booking_edit_actionbar')) {
    function booking_edit_actionbar(array $a): void { slate_edit_actionbar($a); }
}
if (!function_exists('booking_edit_tabs')) {
    function booking_edit_tabs(array $tabs): void { slate_edit_tabs($tabs); }
}
if (!function_exists('booking_editor_css')) {
    function booking_editor_css(): void { slate_editor_css(); }
}
if (!function_exists('booking_editor_js')) {
    function booking_editor_js(): void { slate_editor_js(); }
}
