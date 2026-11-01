<?php
/**
 * Kohevo Studio — `theme.post_image` renderer: the featured image of the item a Loop Item is drawing, linked to it.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class PostImageRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'theme.post_image';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $context = $scope->context();
        $src = (string) $context->attribute('post_image', '');
        if ($src === '') {
            return $scope->showsDiagnostics() ? '<em>Featured image</em>' : '';
        }
        $img = '<img src="' . Html::e($src) . '" alt="" loading="lazy">';
        $url = (string) $context->attribute('post_url', '');
        // The title is the card's link for keyboards and screen readers; the image repeats it for the pointer only.
        return '<div class="sb-post-image">' . ($url !== '' && $scope->bool('link_to_post', true) ? '<a href="' . Html::e($url) . '" tabindex="-1" aria-hidden="true">' . $img . '</a>' : $img) . '</div>';
    }
}
