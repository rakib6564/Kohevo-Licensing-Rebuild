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
        return '<' . $level . ' class="sb-heading">' . Html::e($scope->string('text')) . '</' . $level . '>';
    }
}
