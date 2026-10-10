<?php
/**
 * Kohevo Studio — `core.spacer` renderer: empty vertical space. The size is an allowlisted word; the heights are in the
 * stylesheet (and shrink on a phone), so the markup never carries a number.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class SpacerRenderer implements BlockRendererInterface
{
    public const SIZES = ['xs', 'sm', 'md', 'lg', 'xl', '2xl'];

    public function type(): string
    {
        return 'core.spacer';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $size = $scope->string('size', 'md');
        $size = in_array($size, self::SIZES, true) ? $size : 'md';

        return '<div' . Html::classAttr(['sb-spacer', 'sb-spacer--' . $size]) . ' aria-hidden="true"></div>';
    }
}
