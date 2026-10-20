<?php
/**
 * Kohevo Studio — `core.rich_text` renderer (output re-sanitized, never trusted raw).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\RichTextSanitizer;

final class RichTextRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'core.rich_text';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $classes = ['sb-rich-text'];
        $flow = Html::textFlow($scope, $classes);
        return '<div' . Html::classAttr($classes) . $flow . '>' . RichTextSanitizer::sanitize($scope->string('content')) . '</div>';
    }
}
