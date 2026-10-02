<?php
/**
 * Kohevo Studio — `layout.section` renderer.
 *
 * Primary section container supporting semantic HTML tag, boxed/full/narrow content width,
 * min-height, padding-y, and background tokens. Children are pre-rendered by the pipeline.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class SectionRenderer implements BlockRendererInterface
{
    private const ALLOWED_TAGS = ['section', 'header', 'footer', 'article', 'aside', 'div'];
    private const ALLOWED_WIDTHS = ['boxed', 'full', 'narrow'];
    private const ALLOWED_MIN_HEIGHTS = ['auto', 'screen', 'half_screen', 'sm', 'md', 'lg', 'xl'];

    public function type(): string
    {
        return 'layout.section';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $tag = $scope->string('tag', 'section');
        if (!in_array($tag, self::ALLOWED_TAGS, true)) {
            $tag = 'section';
        }

        $width = $scope->string('content_width', 'boxed');
        if (!in_array($width, self::ALLOWED_WIDTHS, true)) {
            $width = 'boxed';
        }

        $minHeight = $scope->string('min_height', 'auto');
        if (!in_array($minHeight, self::ALLOWED_MIN_HEIGHTS, true)) {
            $minHeight = 'auto';
        }

        $paddingY = $scope->string('padding_y', 'md');
        if (!in_array($paddingY, CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, true)) {
            $paddingY = 'md';
        }

        $classes = [
            'sb-layout-section',
            'sb-layout-section--' . $width,
            'sb-py-' . $paddingY,
        ];

        if ($minHeight !== 'auto') {
            $classes[] = 'sb-layout-section--min-' . str_replace('_', '-', $minHeight);
        }

        $bgToken = $scope->prop('background_token');
        if ($bgToken !== null) {
            $bgClass = $scope->tokenClass('bg', $bgToken);
            if ($bgClass !== '') {
                $classes[] = $bgClass;
            }
        }

        return '<' . $tag . Html::classAttr($classes) . '>'
            . $scope->childrenHtml()
            . '</' . $tag . '>';
    }
}
