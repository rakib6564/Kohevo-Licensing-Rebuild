<?php
/**
 * Kohevo Studio — `layout.grid` renderer.
 *
 * CSS Grid container supporting configurable columns, gap, and cross-axis alignment.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class GridRenderer implements BlockRendererInterface
{
    private const ALLOWED_ALIGN = ['start', 'center', 'end', 'stretch'];

    public function type(): string
    {
        return 'layout.grid';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $cols = (int) ($scope->prop('columns', 2));
        if ($cols < 1 || $cols > 12) {
            $cols = 2;
        }

        $gap = $scope->string('gap', 'md');
        if (!in_array($gap, CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, true)) {
            $gap = 'md';
        }

        $align = $scope->string('align', 'stretch');
        if (!in_array($align, self::ALLOWED_ALIGN, true)) {
            $align = 'stretch';
        }

        $classes = [
            'sb-grid',
            'sb-cols-' . $cols,
            'sb-grid--align-' . $align,
            'sb-gap-' . $gap,
        ];

        return '<div' . Html::classAttr($classes) . '>' . $scope->childrenHtml() . '</div>';
    }
}
