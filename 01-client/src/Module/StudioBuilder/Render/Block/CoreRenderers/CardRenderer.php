<?php
/**
 * Kohevo Studio — `core.card` renderer: a padded surface that holds other blocks.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class CardRenderer implements BlockRendererInterface
{
    public const VARIANTS = ['outlined', 'filled', 'shadow'];
    public const PADDINGS = ['sm', 'md', 'lg'];

    public function type(): string
    {
        return 'core.card';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $variant = $scope->string('variant', 'outlined');
        $variant = in_array($variant, self::VARIANTS, true) ? $variant : 'outlined';
        $padding = $scope->string('padding', 'md');
        $padding = in_array($padding, self::PADDINGS, true) ? $padding : 'md';
        $surface = $scope->tokenClass('bg', $scope->prop('surface_token'));

        return '<div' . Html::classAttr(['sb-card', 'sb-card--' . $variant, 'sb-card--pad-' . $padding, $surface]) . '>'
            . $scope->childrenHtml()
            . '</div>';
    }
}
