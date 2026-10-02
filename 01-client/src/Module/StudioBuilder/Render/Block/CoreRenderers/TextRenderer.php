<?php
/**
 * Kohevo Studio — `core.text` renderer.
 *
 * Typography text block supporting content, sizing scale, alignment, and color tokens.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class TextRenderer implements BlockRendererInterface
{
    private const SIZES = ['xs', 'sm', 'base', 'lg', 'xl', 'lead'];
    private const ALIGN = ['left', 'center', 'right', 'justify'];

    public function type(): string
    {
        return 'core.text';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $content = $scope->string('content', '');
        $size = $scope->string('size', 'base');
        if (!in_array($size, self::SIZES, true)) {
            $size = 'base';
        }

        $align = $scope->string('align', 'left');
        if (!in_array($align, self::ALIGN, true)) {
            $align = 'left';
        }

        $classes = [
            'sb-text',
            'sb-text--' . $size,
            'sb-text--align-' . $align,
        ];

        $colorToken = $scope->prop('color_token');
        if ($colorToken !== null) {
            $colorClass = $scope->tokenClass('fg', $colorToken);
            if ($colorClass !== '') {
                $classes[] = $colorClass;
            }
        }

        return '<p' . Html::classAttr($classes) . '>' . Html::text($content) . '</p>';
    }
}
