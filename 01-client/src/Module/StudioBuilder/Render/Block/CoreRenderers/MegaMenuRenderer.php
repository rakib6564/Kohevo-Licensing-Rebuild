<?php
/**
 * Kohevo Studio — `layout.mega_menu` renderer: a row of links, each able to open a wide panel built from any blocks.
 *
 * Each child is a `layout.mega_item`. It speaks the Nav menu's markup (`.sb-nav__item`, `.sb-nav__sub`), so the stylesheet's
 * hover and keyboard rules, the pointer, the menu button and studio-runtime.js's submenu code all work unchanged; folded,
 * the panels become accordion rows. The list is a `<div>` because every block sits in its own wrapper `<div>`.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class MegaMenuRenderer implements BlockRendererInterface
{
    public const WIDTHS = ['full', 'content'];

    public function type(): string
    {
        return 'layout.mega_menu';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $items = $scope->childrenHtml();
        if (trim($items) === '') {
            return $scope->showsDiagnostics() ? '<p class="sb-mega__empty">' . Html::e(Html::t('studio_mega_empty', 'Add a Mega menu item')) . '</p>' : '';
        }

        $name = trim($scope->string('aria_label', 'Menu'));
        $align = NavChrome::pick($scope->string('align', 'left'), NavChrome::ALIGNS, 'left');
        $width = NavChrome::pick($scope->string('panel_width', 'content'), self::WIDTHS, 'content');
        $listId = $scope->domId('menu');
        [$pointer, $style] = NavChrome::pointer($scope);
        [$toggle, $bar] = NavChrome::toggle($scope, $listId);
        $classes = ['sb-nav', 'sb-nav--horizontal', 'sb-nav--' . $align, 'sb-nav--plain', 'sb-nav--mega', 'sb-nav--mega-' . $width, ...$pointer, ...$toggle];

        return '<nav' . Html::classAttr($classes)
            . ($name !== '' ? ' aria-label="' . Html::e($name) . '"' : '') . $style . ($toggle !== [] ? ' data-sb-nav-toggle' : '') . ' data-sb-mega>'
            . $bar . '<div class="sb-nav__list" id="' . Html::e($listId) . '">' . $items . NavChrome::cta($scope, 'div') . '</div></nav>';
    }
}
