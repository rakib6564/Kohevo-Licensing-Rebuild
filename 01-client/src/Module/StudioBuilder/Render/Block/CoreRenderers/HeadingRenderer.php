<?php
/**
 * Kohevo Studio — `core.heading` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class HeadingRenderer implements BlockRendererInterface
{
    private const LEVELS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
    private const SIZES  = ['xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl'];
    private const ALIGN  = ['left', 'center', 'right'];

    public function type(): string
    {
        return 'core.heading';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $level = $scope->string('level', 'h2');
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'h2';
        }
        $classes = ['sb-heading'];
        $size = $scope->string('size');
        if ($size !== '' && in_array($size, self::SIZES, true)) {
            $classes[] = 'sb-heading--' . $size;
        }
        $align = $scope->string('align');
        if ($align !== '' && in_array($align, self::ALIGN, true)) {
            $classes[] = 'sb-heading--align-' . $align;
        }
        $colorToken = $scope->prop('color_token');
        if ($colorToken !== null) {
            $colorClass = $scope->tokenClass('fg', $colorToken);
            if ($colorClass !== '') {
                $classes[] = $colorClass;
            }
        }
        return '<' . $level . Html::classAttr($classes) . '>' . Html::highlighted($scope->string('text'), $scope->string('highlight')) . '</' . $level . '>';
    }
}
