<?php
/**
 * Kohevo Studio — `theme.post_link` renderer: a text link to the item a Loop Item is drawing.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class PostLinkRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'theme.post_link';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $url = (string) $scope->context()->attribute('post_url', '');
        $text = $scope->string('text', 'Read more');
        if ($url === '') {
            return $scope->showsDiagnostics() ? '<em>' . Html::e($text) . '</em>' : '';
        }
        return '<a class="sb-post-link" href="' . Html::e($url) . '">' . Html::e($text) . '</a>';
    }
}
