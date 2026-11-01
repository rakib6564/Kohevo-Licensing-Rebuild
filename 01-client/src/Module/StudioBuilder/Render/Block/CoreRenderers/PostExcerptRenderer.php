<?php
/**
 * Kohevo Studio — `theme.post_excerpt` renderer: the short description of the item a Loop Item is drawing.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class PostExcerptRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'theme.post_excerpt';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $text = (string) $scope->context()->attribute('post_excerpt', '');
        if ($text === '') {
            return $scope->showsDiagnostics() ? '<em>Excerpt</em>' : '';
        }
        return '<p class="sb-post-excerpt">' . Html::e($text) . '</p>';
    }
}
