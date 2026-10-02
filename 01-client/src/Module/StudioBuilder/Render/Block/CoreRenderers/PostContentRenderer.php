<?php
/**
 * Kohevo Studio — `theme.post_content` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class PostContentRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'theme.post_content';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $content = (string) $scope->prop('content');
        if ($content === '') {
            $content = (string) $scope->context()->attribute('content', $scope->context()->attribute('post_content', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.'));
        }

        $body = strip_tags($content) === $content ? nl2br(Html::e($content)) : $content;
        return '<div class="sb-post-content">' . $body . '</div>';
    }
}
