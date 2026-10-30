<?php
/**
 * Kohevo Studio — `core.nav_menu` renderer: a list of links in a `<nav>`.
 *
 * A site menu's item with `children` opens a submenu (`.sb-nav__sub`, nested up to StudioMenus::MAX_DEPTH levels). In a
 * horizontal menu it drops down on hover and keyboard focus with CSS alone, so the canvas (no scripts) shows it too, and
 * the arrow is drawn in the link; in a vertical one (a drawer) it is listed indented, behind an arrow button. studio-runtime.js adds the toggle: its button sets `aria-expanded`
 * and `data-open`, a vertical menu folds its submenus away, and Escape or a click elsewhere closes them. An item with
 * no address of its own is the toggle itself.
 *
 * With a `toggle_breakpoint` the same list folds behind a menu button below that width (StudioStylesheet::navWidgetCss()),
 * as a dropdown under the header or an off-canvas panel. There is one list of links in the page whatever the width, so a
 * crawler reads each link once. The open state is a checkbox laid over the button: without scripts it opens the menu
 * by itself and is what the keyboard reaches. Once studio-runtime.js runs, the checkbox hides, the real
 * `<button aria-expanded aria-controls>` takes over and the runtime handles focus, Escape and closing on a link.
 *
 * Current page: a header is rendered once for every page, so the server cannot know which link is current;
 * studio-runtime.js marks it (`aria-current`, `is-current`) from the address the page is served at.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Menu\StudioMenus;
use Slate\Module\StudioBuilder\Render\Html;

final class NavMenuRenderer implements BlockRendererInterface
{
    public const LAYOUTS = ['horizontal', 'vertical'];
    public const ALIGNS  = ['left', 'center', 'right'];
    public const STYLES  = ['plain', 'underline', 'pill'];
    public const POINTERS   = ['none', 'underline', 'overline', 'double_line', 'framed', 'background', 'text'];
    public const ANIMATIONS = ['none', 'fade', 'slide', 'grow'];
    public const TOGGLES    = ['none', 'tablet', 'mobile', 'always'];
    public const MODES      = ['dropdown', 'offcanvas'];
    public const ICONS      = ['hamburger', 'dots', 'text_icon'];

    public function type(): string
    {
        return 'core.nav_menu';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $layout = self::pick($scope->string('layout', 'horizontal'), self::LAYOUTS, 'horizontal');
        $align  = self::pick($scope->string('align', 'left'), self::ALIGNS, 'left');
        $look   = self::pick($scope->string('style', 'plain'), self::STYLES, 'plain');
        $name   = trim($scope->string('aria_label', 'Menu'));

        // A chosen site menu wins; a menu that was since deleted falls back to this block's own links, so a header never goes blank.
        $links = (array) $scope->prop('items', []);
        $menuKey = trim($scope->string('menu', ''));
        $menu = $menuKey !== '' ? StudioMenus::find($scope->context()->tenantId, $menuKey) : null;
        if ($menu !== null) {
            $links = $menu['items'];
        }

        $items = $this->items($links, $scope, 0, 'sub');
        if ($items === '') {
            return '';
        }

        $classes = ['sb-nav', 'sb-nav--' . $layout, 'sb-nav--' . $align, 'sb-nav--' . $look];
        $pointer = self::pick($scope->string('pointer', 'none'), self::POINTERS, 'none');
        if ($pointer !== 'none') {
            $classes[] = 'sb-nav--pointer-' . $pointer;
            $classes[] = 'sb-nav--anim-' . self::pick($scope->string('pointer_animation', 'fade'), self::ANIMATIONS, 'fade');
        }
        if ($scope->bool('divider', false)) {
            $classes[] = 'sb-nav--divider';
        }
        $color = $pointer !== 'none' ? $scope->tokenVar($scope->prop('pointer_color')) : '';
        $style = $color !== '' ? ' style="--sb-nav-pointer:' . Html::e($color) . '"' : '';

        $toggle = self::pick($scope->string('toggle_breakpoint', 'none'), self::TOGGLES, 'none');
        $listId = $scope->domId('menu');
        $bar = '';
        if ($toggle !== 'none') {
            $mode = self::pick($scope->string('toggle_mode', 'dropdown'), self::MODES, 'dropdown');
            array_push($classes, 'sb-nav--toggle-' . $toggle, 'sb-nav--menu-' . $mode,
                'sb-nav--tgl-' . self::pick($scope->string('toggle_align', 'right'), self::ALIGNS, 'right'));
            if ($mode === 'dropdown' && $scope->bool('toggle_full_width', true)) {
                $classes[] = 'sb-nav--menu-full';
            }
            $bar = $this->bar($scope, $listId);
        }

        return '<nav' . Html::classAttr($classes)
            . ($name !== '' ? ' aria-label="' . Html::e($name) . '"' : '') . $style . ($toggle !== 'none' ? ' data-sb-nav-toggle' : '') . '>'
            . $bar . '<ul class="sb-nav__list"' . ($toggle !== 'none' ? ' id="' . Html::e($listId) . '"' : '') . '>' . $items . '</ul></nav>';
    }

    /**
     * The menu button. The button's `tabindex` keeps a page without scripts to one stop (the checkbox); the runtime removes it.
     * Both controls are named by the label, which only shows with the "text and icon" button. The backdrop of an off-canvas
     * panel is the checkbox's label, so it closes the panel without scripts too.
     */
    private function bar(BlockRenderScope $scope, string $listId): string
    {
        $label = trim($scope->string('toggle_label', 'Menu'));
        $label = $label !== '' ? $label : Html::t('studio_menu', 'Menu');
        $icon = self::pick($scope->string('toggle_icon', 'hamburger'), self::ICONS, 'hamburger');
        $checkId = $listId . '-open';
        return '<div class="sb-nav__bar"><span>'
            . '<input type="checkbox" class="sb-nav__check" id="' . Html::e($checkId) . '" aria-label="' . Html::e($label) . '">'
            . '<button type="button" class="sb-nav__burger" tabindex="-1" aria-expanded="false" aria-controls="' . Html::e($listId) . '"'
            . ($icon === 'text_icon' ? '>' : ' aria-label="' . Html::e($label) . '">')
            . '<span class="sb-nav__icon sb-nav__icon--' . ($icon === 'dots' ? 'dots' : 'hamburger') . '" aria-hidden="true"></span>'
            . ($icon === 'text_icon' ? '<span class="sb-nav__burger-label">' . Html::e($label) . '</span>' : '')
            . '</button></span></div>'
            . '<label class="sb-nav__backdrop" for="' . Html::e($checkId) . '" aria-hidden="true"></label>';
    }

    /**
     * The `<li>`s of one level of the menu, submenus included. A submenu's id is the block's and the item's place in the menu.
     *
     * @param array<int, mixed> $links
     */
    private function items(array $links, BlockRenderScope $scope, int $depth, string $path): string
    {
        $html = '';
        foreach ($links as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = (string) ($item['label'] ?? '');
            $children = $depth < StudioMenus::MAX_DEPTH && is_array($item['children'] ?? null) ? $item['children'] : [];
            $sub = $children !== [] ? $this->items($children, $scope, $depth + 1, $path . '-' . $i) : '';
            $caret = '<span class="sb-nav__caret" aria-hidden="true"></span>';
            // A site menu item's own CSS classes (checked by StudioMenus) go on its <li>, as a theme would expect.
            $own = preg_split('/\s+/', trim((string) ($item['class'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            // A link with a submenu carries the arrow inside it, so the arrow sits by its label whatever spacing the link is given.
            $anchor = Html::link(['href' => $item['url'] ?? null, 'label' => $label, 'target' => !empty($item['new_tab']) ? '_blank' : '_self'], 'sb-nav__link', $sub !== '' ? Html::e($label) . $caret : null);
            if ($sub === '') {
                if ($anchor !== '' && str_starts_with($anchor, '<a')) {
                    $html .= '<li' . Html::classAttr(['sb-nav__item', ...$own]) . '>' . $anchor . '</li>';
                }
                continue;
            }
            if (trim($label) === '') {
                continue;
            }
            $id = $scope->domId($path . '-' . $i);
            $expanded = ' aria-expanded="false" aria-controls="' . Html::e($id) . '"';
            $head = str_starts_with($anchor, '<a')
                ? $anchor . '<button type="button" class="sb-nav__toggle"' . $expanded . ' aria-label="' . Html::e(sprintf(Html::t('studio_nav_submenu', 'Show the links under %s'), $label)) . '"></button>'
                : '<button type="button" class="sb-nav__link sb-nav__parent"' . $expanded . '>' . Html::e($label) . $caret . '</button>';
            $html .= '<li' . Html::classAttr(['sb-nav__item', 'sb-nav__item--parent', ...$own]) . '>' . $head . '<ul class="sb-nav__sub sb-nav__sub--' . ($depth + 1) . '" id="' . Html::e($id) . '">' . $sub . '</ul></li>';
        }
        return $html;
    }

    /** @param list<string> $allowed */
    private static function pick(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
