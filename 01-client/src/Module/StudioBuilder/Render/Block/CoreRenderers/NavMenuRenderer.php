<?php
/**
 * Kohevo Studio — `core.nav_menu` renderer: a list of links in a `<nav>`.
 *
 * A site menu's item with `children` opens a submenu (`.sb-nav__sub`, nested up to StudioMenus::MAX_DEPTH levels). In a
 * horizontal menu it drops down on hover and keyboard focus with CSS alone, so the canvas (no scripts) shows it too; in
 * a vertical one (a drawer) it is listed indented. studio-runtime.js adds the toggle: its button sets `aria-expanded`
 * and `data-open`, a vertical menu folds its submenus away, and Escape or a click elsewhere closes them. An item with
 * no address of its own is the toggle itself.
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
        $style  = self::pick($scope->string('style', 'plain'), self::STYLES, 'plain');
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

        return '<nav' . Html::classAttr(['sb-nav', 'sb-nav--' . $layout, 'sb-nav--' . $align, 'sb-nav--' . $style])
            . ($name !== '' ? ' aria-label="' . Html::e($name) . '"' : '') . '>'
            . '<ul class="sb-nav__list">' . $items . '</ul></nav>';
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
            $anchor = Html::link(['href' => $item['url'] ?? null, 'label' => $label, 'target' => !empty($item['new_tab']) ? '_blank' : '_self'], 'sb-nav__link');
            if ($sub === '') {
                if ($anchor !== '' && str_starts_with($anchor, '<a')) {
                    $html .= '<li class="sb-nav__item">' . $anchor . '</li>';
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
                : '<button type="button" class="sb-nav__link sb-nav__parent"' . $expanded . '>' . Html::e($label) . '<span class="sb-nav__caret" aria-hidden="true"></span></button>';
            $html .= '<li class="sb-nav__item sb-nav__item--parent">' . $head . '<ul class="sb-nav__sub sb-nav__sub--' . ($depth + 1) . '" id="' . Html::e($id) . '">' . $sub . '</ul></li>';
        }
        return $html;
    }

    /** @param list<string> $allowed */
    private static function pick(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
