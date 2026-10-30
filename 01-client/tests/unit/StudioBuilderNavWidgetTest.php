<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the Nav menu widget: menu button, pointers, divider, submenus typed by
 * hand, sticky shrink and the header presets that use them. Dependency-free (no database); drives the real compiler.
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

use Slate\Module\StudioBuilder\Document\ScrollEffects;
use Slate\Module\StudioBuilder\Presets\HeaderPresetCatalog;
use Slate\Module\StudioBuilder\Registry\BlockParts;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;

/** @param array<string, mixed> $props */
function sbnw_nav(array $props = []): string
{
    return sbel_render([sbel_block('core.nav_menu', $props + ['items' => [
        ['label' => 'Home', 'url' => '/'],
        ['label' => 'Services', 'url' => '/services', 'children' => [['label' => 'Yoga', 'url' => '/yoga']]],
    ]])]);
}

unit('nav widget: a menu without a toggle keeps its plain markup, so existing pages do not change', function (): void {
    $html = sbnw_nav();
    assert_true(str_contains($html, '<nav class="sb-nav sb-nav--horizontal sb-nav--left sb-nav--plain" aria-label="Menu"><ul class="sb-nav__list">'), 'no extra classes, attributes or id');
    foreach (['sb-nav__bar', 'sb-nav__check', 'sb-nav__burger', 'data-sb-nav-toggle'] as $absent) {
        assert_false(str_contains($html, $absent), "{$absent} only comes with a toggle");
    }
});

unit('nav widget: the toggle wraps ONE nav and one list: a button with aria-expanded and aria-controls, and a no-script checkbox', function (): void {
    $html = sbnw_nav(['toggle_breakpoint' => 'mobile', 'toggle_align' => 'left']);
    assert_eq(1, substr_count($html, '<nav'), 'one nav');
    assert_eq(1, substr_count($html, 'class="sb-nav__list"'), 'one list of links');
    assert_eq(1, substr_count($html, 'href="/yoga"'), 'each link is in the page once');
    assert_true(str_contains($html, 'sb-nav--toggle-mobile sb-nav--menu-dropdown sb-nav--tgl-left sb-nav--menu-full'), 'the classes carry the options');
    assert_true(str_contains($html, ' data-sb-nav-toggle>'), 'the runtime finds it');
    preg_match('~<button type="button" class="sb-nav__burger" tabindex="-1" aria-expanded="false" aria-controls="([^"]+)" aria-label="Menu">~', $html, $m);
    assert_true(isset($m[1]), 'a real button, named, closed, out of the tab order until the runtime hands it over');
    assert_true(str_contains($html, '<ul class="sb-nav__list" id="' . $m[1] . '">'), 'aria-controls names the list');
    assert_true(str_contains($html, '<input type="checkbox" class="sb-nav__check" id="' . $m[1] . '-open" aria-label="Menu">'), 'the checkbox is named and reachable without scripts');
    assert_true(str_contains($html, '<label class="sb-nav__backdrop" for="' . $m[1] . '-open" aria-hidden="true"></label>'), 'the backdrop closes it without scripts');
});

unit('nav widget: the button options: off-canvas, dots, text and icon, label, and unknown values', function (): void {
    assert_true(str_contains(sbnw_nav(['toggle_breakpoint' => 'always', 'toggle_mode' => 'offcanvas']), 'sb-nav--toggle-always sb-nav--menu-offcanvas sb-nav--tgl-right"'), 'off-canvas has no full-width flag');
    assert_false(str_contains(sbnw_nav(['toggle_breakpoint' => 'tablet', 'toggle_full_width' => false]), 'sb-nav--menu-full'), 'a dropdown as wide as the menu');
    assert_true(str_contains(sbnw_nav(['toggle_breakpoint' => 'tablet', 'toggle_icon' => 'dots']), 'sb-nav__icon--dots'));
    $text = sbnw_nav(['toggle_breakpoint' => 'tablet', 'toggle_icon' => 'text_icon', 'toggle_label' => 'A"><b>']);
    assert_true(str_contains($text, '<span class="sb-nav__burger-label">A&quot;&gt;&lt;b&gt;</span>'), 'the label shows, escaped');
    assert_false((bool) preg_match('~class="sb-nav__burger"[^>]*aria-label~', $text), 'a visible label needs no aria-label');
    assert_true(str_contains(sbnw_nav(['toggle_breakpoint' => 'tablet', 'toggle_label' => '   ']), 'aria-label="Menu"'), 'an empty label still names the button');
});

unit('nav widget: pointers and the divider add classes; the colour is a theme token, and text has no drawn line', function (): void {
    assert_true(str_contains(sbnw_nav(['pointer' => 'double_line', 'pointer_animation' => 'grow', 'divider' => true]), 'sb-nav--pointer-double_line sb-nav--anim-grow sb-nav--divider"'));
    assert_false(str_contains(sbnw_nav(['pointer' => 'none', 'pointer_animation' => 'grow']), 'sb-nav--anim-'), 'no pointer, no animation');
    assert_true(str_contains(sbnw_nav(['pointer' => 'underline', 'pointer_color' => 'color.accent']), 'style="--sb-nav-pointer:var('), 'a theme colour');
    assert_false(str_contains(sbnw_nav(['pointer' => 'underline', 'pointer_color' => 'no.such;token']), '--sb-nav-pointer'), 'an unknown token is dropped');
});

unit('nav widget: a submenu typed on the block renders nested, and items keep validating with and without children', function (): void {
    $html = sbnw_nav();
    assert_true((bool) preg_match('~sb-nav__item--parent"><a class="sb-nav__link" href="/services">Services<span class="sb-nav__caret" aria-hidden="true"></span></a><button type="button" class="sb-nav__toggle" aria-expanded="false" aria-controls="[^"]+" aria-label="[^"]+"></button><ul class="sb-nav__sub sb-nav__sub--1"~', $html), 'link, arrow and nested list');
    $schema = ModuleBlockDefinitions::studioRegistry()->get('core.nav_menu')->schema();
    assert_true($schema->validate(['items' => [['label' => 'A', 'url' => '/a'], ['label' => 'B', 'url' => '/b', 'children' => [['label' => 'C', 'url' => '/c']]]]])->isValid(), 'both shapes');
    assert_false($schema->validate(['items' => [['label' => 'B', 'url' => '/b', 'children' => [['label' => 'C', 'url' => 'javascript:alert(1)']]]]])->isValid(), 'a submenu link is checked too');
});

unit('nav widget: inline text addresses top-level links only, so a submenu link cannot shift the index', function (): void {
    $spec = ModuleBlockDefinitions::studioRegistry()->get('core.nav_menu')->toEditorManifest()['inline_text'][0];
    assert_eq('.sb-nav__list > .sb-nav__item > .sb-nav__link', $spec['selector']);
});

unit('nav widget: the current page and the menu button can be styled as parts', function (): void {
    $parts = BlockParts::for('core.nav_menu');
    assert_true(in_array('.sb-nav__list>.sb-nav__item>.sb-nav__link[aria-current="page"]', $parts['current']['selectors'], true), 'the current link outranks the Links colour');
    assert_true(in_array('.sb-nav__list .sb-nav__item.is-current-parent>:is(.sb-nav__link,.sb-nav__parent)', $parts['current']['selectors'], true), 'a parent of the current page lights up too');
    assert_eq(['.sb-nav__burger'], $parts['burger']['selectors']);
    assert_true(isset($parts['panel']));
});

unit('nav widget: the stylesheet folds at each breakpoint, draws every pointer and honours reduced motion', function (): void {
    $css = StudioStylesheet::css();
    foreach (['@media (max-width:1023.98px){.sb-nav--toggle-tablet{position:relative}', '@media (max-width:767.98px){.sb-nav--toggle-mobile{position:relative}', '.sb-nav--toggle-always{position:relative}'] as $rule) {
        assert_true(str_contains($css, $rule), $rule);
    }
    assert_true(str_contains($css, 'html[data-sb-ready] .sb-nav__check{display:none}'), 'the runtime takes over from the checkbox');
    assert_true(str_contains($css, 'html[data-sb-ready] .sb-nav--toggle-mobile .sb-nav__item--parent:not([data-open])>.sb-nav__sub{display:none}'), 'submenus only fold when something can open them');
    foreach (['underline', 'overline', 'double_line', 'framed', 'background', 'text'] as $pointer) {
        assert_true(str_contains($css, '.sb-nav--pointer-' . $pointer), $pointer);
    }
    assert_true(str_contains($css, '@media (hover:hover){[class*="sb-nav--pointer-"]'), 'a pointer stays off touch screens');
    assert_true((bool) preg_match('~@media \(prefers-reduced-motion:reduce\)\{\[class\*="sb-nav--anim-"\]~', $css), 'no motion on request');
});

unit('nav widget: sticky shrink validates, renders as an effect and only on the top edge', function (): void {
    assert_eq([], ScrollEffects::issues(['sticky' => ['edge' => 'top', 'shrink' => true]], '$'));
    assert_true(ScrollEffects::issues(['sticky' => ['edge' => 'top', 'shrink' => 'yes']], '$') !== [], 'a boolean');
    assert_true(str_contains(ScrollEffects::render(['interactions' => ['sticky' => ['edge' => 'top', 'shrink' => true]]])['attrs'], 'data-sb-sticky-effect="shrink"'));
    assert_false(str_contains(ScrollEffects::render(['interactions' => ['sticky' => ['edge' => 'bottom', 'shrink' => true]]])['attrs'], 'shrink'));
});

unit('nav widget: every header preset uses the built-in button, none a drawer, and Hamburger only exists', function (): void {
    foreach (HeaderPresetCatalog::all() as $p) {
        $json = json_encode($p['document']);
        assert_false(str_contains((string) $json, 'layout.offcanvas'), "{$p['key']} has no separate drawer");
        assert_true(str_contains((string) $json, '"toggle_breakpoint"'), "{$p['key']} has a menu button");
    }
    $ham = HeaderPresetCatalog::find('system-header-hamburger');
    assert_true($ham !== null, 'the preset exists');
    assert_true(str_contains((string) json_encode($ham['document']), '"toggle_breakpoint":"always"'), 'a hamburger on every screen');
    assert_true(str_contains((string) json_encode($ham['document']), '"toggle_mode":"offcanvas"'));
});
