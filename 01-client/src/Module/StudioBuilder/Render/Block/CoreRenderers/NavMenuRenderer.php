<?php
/**
 * Kohevo Studio — `core.nav_menu` renderer: a list of links in a `<nav>`.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
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

        $items = '';
        foreach ((array) $scope->prop('items', []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $anchor = Html::link(['href' => $item['url'] ?? null, 'label' => $item['label'] ?? ''], 'sb-nav__link');
            if ($anchor !== '') {
                $items .= '<li class="sb-nav__item">' . $anchor . '</li>';
            }
        }
        if ($items === '') {
            return '';
        }

        return '<nav' . Html::classAttr(['sb-nav', 'sb-nav--' . $layout, 'sb-nav--' . $align, 'sb-nav--' . $style])
            . ($name !== '' ? ' aria-label="' . Html::e($name) . '"' : '') . '>'
            . '<ul class="sb-nav__list">' . $items . '</ul></nav>';
    }

    /** @param list<string> $allowed */
    private static function pick(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
