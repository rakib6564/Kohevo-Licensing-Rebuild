<?php
/**
 * Kohevo Studio — `layout.flex` renderer.
 *
 * Declarative CSS Flexbox container with direction, wrap, alignment, justification, and gap.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class FlexRenderer implements BlockRendererInterface
{
    private const ALLOWED_DIRECTIONS = ['row', 'column', 'row_reverse', 'column_reverse'];
    private const ALLOWED_WRAPS      = ['nowrap', 'wrap', 'wrap_reverse'];
    private const ALLOWED_JUSTIFY    = ['start', 'center', 'end', 'between', 'around', 'evenly'];
    private const ALLOWED_ALIGN      = ['start', 'center', 'end', 'stretch', 'baseline'];

    public function type(): string
    {
        return 'layout.flex';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $direction = $scope->string('direction', 'row');
        if (!in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            $direction = 'row';
        }

        $wrap = $scope->string('wrap', 'nowrap');
        if (!in_array($wrap, self::ALLOWED_WRAPS, true)) {
            $wrap = 'nowrap';
        }

        $justify = $scope->string('justify', 'start');
        if (!in_array($justify, self::ALLOWED_JUSTIFY, true)) {
            $justify = 'start';
        }

        $align = $scope->string('align', 'start');
        if (!in_array($align, self::ALLOWED_ALIGN, true)) {
            $align = 'start';
        }

        $gap = $scope->string('gap', 'md');
        if (!in_array($gap, CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, true)) {
            $gap = 'md';
        }

        $classes = [
            'sb-flex',
            'sb-flex--' . str_replace('_', '-', $direction),
            'sb-flex--' . str_replace('_', '-', $wrap),
            'sb-flex--justify-' . $justify,
            'sb-flex--align-' . $align,
            'sb-gap-' . $gap,
        ];

        return '<div' . Html::classAttr($classes) . '>' . $scope->childrenHtml() . '</div>';
    }
}
