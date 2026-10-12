<?php
/**
 * Kohevo Studio — `layout.container` renderer.
 *
 * Inner layout container providing max-width constraints, alignment, and padding.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\WrapperClassesInterface;

final class LayoutContainerRenderer implements BlockRendererInterface, WrapperClassesInterface
{
    private const ALLOWED_WIDTHS = ['full', 'constrained', 'compact'];
    private const ALLOWED_ALIGNMENTS = ['left', 'center', 'right'];

    public function type(): string
    {
        return 'layout.container';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    /** The children go straight into the block's own wrapper, which is the box the Layout settings style. */
    public function render(BlockRenderScope $scope): string
    {
        return $scope->childrenHtml();
    }

    public function wrapperClasses(BlockRenderScope $scope): array
    {
        $width = $scope->string('width', 'constrained');
        if (!in_array($width, self::ALLOWED_WIDTHS, true)) {
            $width = 'constrained';
        }

        $align = $scope->string('alignment', 'center');
        if (!in_array($align, self::ALLOWED_ALIGNMENTS, true)) {
            $align = 'center';
        }

        $padding = $scope->string('padding', 'none');
        if (!in_array($padding, CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, true)) {
            $padding = 'none';
        }

        $classes = [
            'sb-container',
            'sb-container--' . $width,
            'sb-container--align-' . $align,
        ];
        if ($padding !== 'none') {
            $classes[] = 'sb-pad-' . $padding;
        }

        return $classes;
    }
}
