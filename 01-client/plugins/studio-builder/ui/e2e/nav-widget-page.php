<?php
/**
 * Prints a page holding one Nav menu, from the real renderer and stylesheet, for nav-widget.spec.mjs.
 *
 *   php e2e/nav-widget-page.php '<props as JSON>'
 */

declare(strict_types=1);

define('SLATE_TESTING', true);
define('SLATE_ROOT', dirname(__DIR__, 4));
require_once SLATE_ROOT . '/src/autoload.php';
require_once SLATE_ROOT . '/tests/unit/harness.php';
ob_start(); // the Elements suite's helpers render the block; its own tests print as they run
require_once SLATE_ROOT . '/tests/unit/StudioBuilderElementsTest.php';
ob_end_clean();

$props = (array) json_decode((string) ($argv[1] ?? '{}'), true);
$html = sbel_render([sbel_block('core.nav_menu', $props + ['items' => [
    ['label' => 'Home', 'url' => '/'],
    ['label' => 'Services', 'url' => '/services', 'children' => [['label' => 'Yoga', 'url' => '/yoga'], ['label' => 'Pilates', 'url' => '/pilates']]],
    ['label' => 'Contact', 'url' => '/contact'],
]])]);
echo '<!doctype html><html><head><meta charset="utf-8"><style>' . \Slate\Module\StudioBuilder\Render\StudioStylesheet::css() . '</style></head>'
    . '<body class="sb-body" style="margin:0"><header class="sb-site-header">' . $html . '</header><div style="height:2000px"></div></body></html>';
