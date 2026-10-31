<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the Mega menu, and the menu button's full-screen mode, opening animation
 * and button, shared with the Nav menu. Dependency-free (no database); drives the real compiler.
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
require_once __DIR__ . '/StudioBuilderElementsTest.php';

use Slate\Module\StudioBuilder\Registry\BlockParts;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;

/** @param array<string, mixed> $props */
function sbmm_menu(array $props = []): string
{
    $panel = sbel_block('layout.mega_item', ['label' => 'Cours', 'url' => '/cours/', 'columns' => 2]);
    $panel['children'] = [sbel_block('core.text', ['content' => 'Panel text'])];
    return sbel_render([array_merge(sbel_block('layout.mega_menu', $props), ['children' => [
        $panel,
        sbel_block('layout.mega_item', ['label' => 'Contact', 'url' => '/contact/']),
    ]])]);
}

unit('mega menu: only items go in the menu, and an item holds any block', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    assert_eq(['layout.mega_item'], $registry->get('layout.mega_menu')->allowedChildTypes());
    assert_eq([], $registry->get('layout.mega_item')->allowedChildTypes());
    assert_true($registry->get('layout.mega_item')->allowsChildren());
});

unit('mega menu: an item with blocks is a Nav menu parent, so the submenu CSS and runtime drive it', function (): void {
    $html = sbmm_menu(['toggle_breakpoint' => 'none']);
    assert_true(str_contains($html, 'sb-nav--mega sb-nav--mega-content'), 'the panel content is boxed by default');
    assert_true(str_contains($html, 'data-sb-mega'), 'the runtime finds it');
    assert_true((bool) preg_match('/class="[^"]*sb-block--layout-mega-item[^"]*sb-nav__item sb-nav__item--parent/', $html) || str_contains($html, 'sb-nav__item sb-nav__item--parent'), 'the wrapper is the menu item');
    assert_true(str_contains($html, 'sb-nav__sub sb-nav__mega'), 'the panel is a submenu');
    assert_true(str_contains($html, '--sb-mega-cols:2'), 'the item sets the columns');
    assert_true(str_contains($html, 'Panel text'), 'the blocks render inside');
    assert_true(str_contains($html, 'class="sb-nav__toggle"'), 'a linked item keeps a keyboard toggle');
    assert_false(str_contains($html, '<ul class="sb-nav__list"'), 'block wrappers are divs, so the list is one too');
});

unit('mega menu: an item with no blocks is a plain link; with no address it opens by a button', function (): void {
    assert_eq(1, substr_count(sbmm_menu(), 'sb-nav__sub'), 'Contact has no panel');
    $panel = sbel_block('layout.mega_item', ['label' => 'Open']);
    $panel['children'] = [sbel_block('core.text', ['content' => 'x'])];
    $html = sbel_render([array_merge(sbel_block('layout.mega_menu', []), ['children' => [$panel]])]);
    assert_true(str_contains($html, '<button type="button" class="sb-nav__link sb-nav__parent"'));
});

unit('mega menu: it folds behind the menu button from tablet by default', function (): void {
    $html = sbmm_menu();
    assert_true(str_contains($html, 'sb-nav--toggle-tablet'));
    assert_true(str_contains($html, 'data-sb-nav-toggle'));
});

unit('menu button: full screen holds no full-width class and the opening animation is optional', function (): void {
    $html = sbmm_menu(['toggle_mode' => 'fullscreen', 'toggle_animation' => 'slide']);
    assert_true(str_contains($html, 'sb-nav--menu-fullscreen'));
    assert_true(str_contains($html, 'sb-nav--open-slide'));
    assert_false(str_contains($html, 'sb-nav--menu-full '), 'full width is a dropdown setting');
    assert_false(str_contains(sbmm_menu(), 'sb-nav--open-'), 'no animation unless chosen');
    assert_false(ModuleBlockDefinitions::studioRegistry()->get('core.nav_menu')->validateProps(['toggle_mode' => 'sideways'])->isValid());
});

unit('menu button: the open menu can end in a button, in both widgets', function (): void {
    $cta = ['toggle_breakpoint' => 'tablet', 'toggle_cta_label' => 'Donner', 'toggle_cta_url' => '/don/'];
    $mega = sbmm_menu($cta);
    assert_true(str_contains($mega, '<div class="sb-nav__item sb-nav__cta">'), 'a div in a mega list');
    $nav = sbel_render([sbel_block('core.nav_menu', $cta + ['items' => [['label' => 'Home', 'url' => '/']]])]);
    assert_true(str_contains($nav, '<li class="sb-nav__item sb-nav__cta">'), 'an li in a nav list');
    assert_true(str_contains($nav, 'sb-nav__cta-link'));
    assert_false(str_contains(sbmm_menu(['toggle_breakpoint' => 'none', 'toggle_cta_label' => 'Donner', 'toggle_cta_url' => '/don/']), 'sb-nav__cta"'), 'no button, no folded menu, no extra link');
    assert_false(str_contains(sbmm_menu(['toggle_cta_label' => 'Donner']), 'sb-nav__cta"'), 'a label alone is not a link');
});

unit('mega menu: the stylesheet, parts and runtime carry it', function (): void {
    $css = StudioStylesheet::css();
    foreach (['.sb-mega__grid', '.sb-nav--menu-fullscreen .sb-nav__list', 'sb-nav-slide', '.sb-nav__cta{display:none}'] as $rule) {
        assert_true(str_contains($css, $rule), $rule);
    }
    assert_true(isset(BlockParts::for('layout.mega_menu')['submenu']));
    $js = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true(str_contains($js, "['megaMenus', initMegaMenus]"));
    assert_true(str_contains($js, "nav.classList.contains('sb-nav--menu-fullscreen')"), 'a full-screen menu holds focus like a side panel');
});
