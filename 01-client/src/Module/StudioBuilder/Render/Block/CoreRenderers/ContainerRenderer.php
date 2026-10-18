<?php
/**
 * Kohevo Studio — `core.container` renderer (structural; children pre-rendered by the pipeline).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\WrapperClassesInterface;

final class ContainerRenderer implements BlockRendererInterface, WrapperClassesInterface
{
    public function type(): string
    {
        return 'core.container';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    /** The children go straight into the block's own wrapper, which is the flex box the Layout settings style. */
    public function render(BlockRenderScope $scope): string
    {
        return $scope->childrenHtml();
    }

    public function wrapperClasses(BlockRenderScope $scope): array
    {
        $gap = $scope->string('gap', 'md');
        if (!in_array($gap, CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, true)) {
            $gap = 'md';
        }
        $direction = $scope->string('direction', 'vertical') === 'horizontal' ? 'horizontal' : 'vertical';
        $classes = ['sb-stack', 'sb-stack--' . $direction, 'sb-gap-' . $gap];
        // A horizontal container wraps by default (as it always has); "nowrap" keeps one row, like a Flex row.
        if ($direction === 'horizontal' && $scope->string('wrap', 'wrap') === 'nowrap') {
            $classes[] = 'sb-stack--nowrap';
        }
        return $classes;
    }
}
