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

        $svg = '<svg class="sb-icon__svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
            . ($label !== '' ? ' role="img" aria-label="' . Html::e($label) . '"' : ' aria-hidden="true" focusable="false"')
            . '>' . $inner . '</svg>';

        return '<div' . Html::classAttr(['sb-icon', 'sb-icon--' . $size, 'sb-icon--' . $align, $color]) . '>' . $svg . '</div>';
    }
}
