<?php
/**
 * Kohevo Studio — `core.icon_list` renderer: a list of items, each with an icon from the built-in library and an optional
 * link. Sizes and spacing that may differ per device reach the stylesheet as `--sb-il-*` variables.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\Icon\IconLibrary;

final class IconListRenderer implements BlockRendererInterface
{
    private const ALIGN = ['left', 'center', 'right'];
    private const V_ALIGN = ['top', 'center', 'bottom'];

    public function type(): string
    {
        return 'core.icon_list';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $rows = '';
        foreach ((array) $scope->prop('items', []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = is_string($item['text'] ?? null) ? trim($item['text']) : '';
            $svg = IconLibrary::svg(is_string($item['icon'] ?? null) ? $item['icon'] : '');
            if ($text === '' && $svg === '') {
                continue;
            }
            $inner = ($svg !== '' ? '<span class="sb-icon-list__icon">' . $svg . '</span>' : '') . '<span class="sb-icon-list__text">' . Html::text($text) . '</span>';
            $url = Html::safeUrl($item['url'] ?? null);
            if ($url !== null) {
                $inner = '<a class="sb-icon-list__link" href="' . Html::e($url) . '"' . (($item['new_tab'] ?? false) === true ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $inner . '</a>';
            } else {
                $inner = '<span class="sb-icon-list__link">' . $inner . '</span>';
            }
            $rows .= '<li class="sb-icon-list__item">' . $inner . '</li>';
        }
        if ($rows === '') {
            return '';
        }

        $classes = ['sb-icon-list'];
        if ($scope->string('layout', 'list') === 'inline') {
            $classes[] = 'sb-icon-list--inline';
        }
        if ($scope->string('apply_link', 'full') === 'inline') {
            $classes[] = 'sb-icon-list--link-inline';
        }
        if ($scope->bool('divider')) {
            $classes[] = 'sb-icon-list--divided';
        }
        $vAlign = $scope->string('icon_v_align', 'center');
        $classes[] = 'sb-icon-list--v-' . (in_array($vAlign, self::V_ALIGN, true) ? $vAlign : 'center');
        $iconAlign = $scope->string('icon_h_align', 'left');
        $classes[] = 'sb-icon-list--i-' . (in_array($iconAlign, self::ALIGN, true) ? $iconAlign : 'left');

        $vars = self::vars($scope, 'sp', 'space_between', 0, 100, 0)
            . self::vars($scope, 'is', 'icon_size', 6, 100, 16)
            . self::vars($scope, 'ig', 'icon_gap', 0, 50, 8)
            . self::vars($scope, 'io', 'icon_offset', -15, 15, 0)
            . self::alignVars($scope);

        return '<ul' . Html::classAttr($classes) . ' style="' . Html::e(rtrim($vars, ';')) . '">' . $rows . '</ul>';
    }

    private static function vars(BlockRenderScope $scope, string $name, string $prop, int $min, int $max, int $default): string
    {
        $value = $scope->prop($prop);
        $base = is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
        $devices = $scope->deviceCounts($prop, $min, $max, $base, $base);
        return "--sb-il-{$name}-d:{$base}px;--sb-il-{$name}-t:{$devices['tablet']}px;--sb-il-{$name}-m:{$devices['mobile']}px;";
    }

    private static function alignVars(BlockRenderScope $scope): string
    {
        $flex = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'];
        $base = $scope->string('list_align', 'left');
        $base = isset($flex[$base]) ? $base : 'left';
        $responsive = is_array($scope->block()['responsive'] ?? null) ? $scope->block()['responsive'] : [];
        $of = static function (string $device) use ($responsive, $flex, $base): string {
            $value = $responsive[$device]['props']['list_align'] ?? null;
            return is_string($value) && isset($flex[$value]) ? $flex[$value] : $flex[$base];
        };
        return "--sb-il-al-d:{$flex[$base]};--sb-il-al-t:{$of('tablet')};--sb-il-al-m:{$of('mobile')};";
    }
}
