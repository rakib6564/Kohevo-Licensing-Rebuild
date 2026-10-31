<?php
/**
 * Kohevo Studio — `layout.mega_item` renderer: one link of a Mega menu and, when it holds blocks, the panel it opens.
 *
 * The block's wrapper is the menu item (`wrapperClasses()`), so the link and the panel are direct children of it, as the
 * Nav menu's are. Its `columns` are the panel's grid; each child block is a column.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\WrapperClassesInterface;
use Slate\Module\StudioBuilder\Render\Html;

final class MegaItemRenderer implements BlockRendererInterface, WrapperClassesInterface
{
    public const MIN_COLUMNS = 1;
    public const MAX_COLUMNS = 6;

    public function type(): string
    {
        return 'layout.mega_item';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function wrapperClasses(BlockRenderScope $scope): array
    {
        return ($scope->block()['children'] ?? []) !== [] ? ['sb-nav__item', 'sb-nav__item--parent'] : ['sb-nav__item'];
    }

    public function render(BlockRenderScope $scope): string
    {
        $label = trim($scope->string('label'));
        if ($label === '') {
            return '';
        }

        $panel = $scope->childrenHtml();
        $url = trim($scope->string('url'));
        $caret = '<span class="sb-nav__caret" aria-hidden="true"></span>';
        $anchor = $url !== '' ? Html::link(['href' => $url, 'label' => $label, 'target' => '_self'], 'sb-nav__link', $panel !== '' ? Html::e($label) . $caret : null) : '';
        $linked = str_starts_with($anchor, '<a');
        if (trim($panel) === '') {
            return $linked ? $anchor : '<span class="sb-nav__link">' . Html::e($label) . '</span>';
        }

        $columns = $scope->prop('columns');
        $columns = is_numeric($columns) ? max(self::MIN_COLUMNS, min(self::MAX_COLUMNS, (int) $columns)) : 3;
        $id = $scope->domId('panel');
        $expanded = ' aria-expanded="false" aria-controls="' . Html::e($id) . '"';
        $head = $linked
            ? $anchor . '<button type="button" class="sb-nav__toggle"' . $expanded . ' aria-label="' . Html::e(sprintf(Html::t('studio_nav_submenu', 'Show the links under %s'), $label)) . '"></button>'
            : '<button type="button" class="sb-nav__link sb-nav__parent"' . $expanded . '>' . Html::e($label) . $caret . '</button>';

        return $head . '<div class="sb-nav__sub sb-nav__mega" id="' . Html::e($id) . '"><div class="sb-mega__grid" style="--sb-mega-cols:' . $columns . '">' . $panel . '</div></div>';
    }
}
