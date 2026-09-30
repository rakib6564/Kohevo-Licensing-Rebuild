<?php
/**
 * Kohevo Studio — `core.container` renderer (structural; children pre-rendered by the pipeline).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class ContainerRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'core.container';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $gap = $scope->string('gap', 'md');
        if (!in_array($gap, CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, true)) {
            $gap = 'md';
        }
        $direction = $scope->string('direction', 'vertical') === 'horizontal' ? 'horizontal' : 'vertical';
        return '<div' . Html::classAttr(['sb-stack', 'sb-stack--' . $direction, 'sb-gap-' . $gap]) . '>' . $scope->childrenHtml() . '</div>';
    }
}
