<?php
/**
 * Slate Components — loader + stylesheet emitter (Phase 3B B4).
 *
 * Requiring this file makes the whole slate_c_* catalogue available and exposes
 * the component stylesheet. Additive/opt-in: nothing auto-requires this yet; a
 * surface that wants Components pulls it in and emits the CSS once. Blocks compose
 * these Components in 3C.
 */

declare(strict_types=1);

require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/button.php';
require_once __DIR__ . '/card.php';
require_once __DIR__ . '/grid.php';
require_once __DIR__ . '/media.php';

if (!function_exists('slate_components_css')) {
    /** The component stylesheet wrapped in a <style> tag (reads --slate-* tokens). */
    function slate_components_css(): string
    {
        $css = @file_get_contents(__DIR__ . '/components.css');
        return $css === false ? '' : '<style id="slate-components">' . $css . '</style>';
    }
}

if (!function_exists('slate_components_emit')) {
    /** Echo the component stylesheet once per request (opt-in; guarded). */
    function slate_components_emit(): void
    {
        if (defined('SLATE_COMPONENTS_EMITTED')) {
            return;
        }
        define('SLATE_COMPONENTS_EMITTED', true);
        echo slate_components_css();
    }
}
