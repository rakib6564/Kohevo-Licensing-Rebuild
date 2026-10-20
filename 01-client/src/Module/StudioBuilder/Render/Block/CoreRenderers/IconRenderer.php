<?php
/**
 * Kohevo Studio — `core.icon` renderer: one built-in icon, sized, optionally tinted by a theme token.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\Icon\IconLibrary;

final class IconRenderer implements BlockRendererInterface
{
    public const SIZES  = ['sm', 'md', 'lg', 'xl'];
    public const ALIGNS = ['left', 'center', 'right'];
    public const VIEWS  = ['default', 'stacked', 'framed'];

    public function type(): string
    {
        return 'core.icon';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $name = $scope->string('name', 'star');
        $inner = IconLibrary::markup($name);
        if ($inner === '') {
            return '';
        }
        $size = $scope->string('size', 'md');
        $size = in_array($size, self::SIZES, true) ? $size : 'md';
        $align = $scope->string('align', 'left');
        $align = in_array($align, self::ALIGNS, true) ? $align : 'left';
        $color = $scope->tokenClass('fg', $scope->prop('color_token'));
        $label = trim($scope->string('label'));
        $view = $scope->string('view', 'default');
        $view = in_array($view, self::VIEWS, true) ? $view : 'default';
        $shape = $scope->string('shape', 'circle') === 'square' ? 'square' : 'circle';

        // Only clamped integers reach these style attributes, never authored text.
        $svgStyle = [];
        $px = self::int($scope->prop('size_px'), 0, 300);
        if ($px > 0) {
            $svgStyle[] = 'width:' . $px . 'px;height:' . $px . 'px';
        }
        $deg = self::int($scope->prop('rotate'), -360, 360);
        if ($deg !== 0) {
            $svgStyle[] = 'transform:rotate(' . $deg . 'deg)';
        }
        $secondary = $view === 'default' ? '' : $scope->tokenClass('fg', $scope->prop('secondary_token'));

        $svg = '<svg' . Html::classAttr(['sb-icon__svg', $secondary]) . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
            . ($svgStyle !== [] ? ' style="' . implode(';', $svgStyle) . '"' : '')
            . ($label !== '' ? ' role="img" aria-label="' . Html::e($label) . '"' : ' aria-hidden="true" focusable="false"')
            . '>' . $inner . '</svg>';

        // Stacked (a filled shape) and framed (an outlined one) put the icon in a frame, with its own padding and border width.
        if ($view !== 'default') {
            $frameStyle = [];
            $pad = self::int($scope->prop('frame_padding'), 0, 100);
            if ($pad > 0) {
                $frameStyle[] = 'padding:' . $pad . 'px';
            }
            $border = self::int($scope->prop('frame_border'), 0, 20);
            if ($view === 'framed' && $border > 0) {
                $frameStyle[] = 'border-width:' . $border . 'px';
            }
            $svg = '<span class="sb-icon__frame"' . ($frameStyle !== [] ? ' style="' . implode(';', $frameStyle) . '"' : '') . '>' . $svg . '</span>';
        }

        $url = Html::safeUrl($scope->prop('url'));
        if ($url !== null) {
            $svg = '<a class="sb-icon__link" href="' . Html::e($url) . '"' . ($scope->bool('new_tab') ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $svg . '</a>';
        }

        $classes = ['sb-icon', 'sb-icon--' . $size, 'sb-icon--' . $align, $color];
        if ($view !== 'default') {
            $classes[] = 'sb-icon--' . $view;
            $classes[] = 'sb-icon--' . $shape;
        }
        return '<div' . Html::classAttr($classes) . '>' . $svg . '</div>';
    }

    private static function int(mixed $value, int $min, int $max): int
    {
        return is_int($value) ? max($min, min($max, $value)) : 0;
    }
}
