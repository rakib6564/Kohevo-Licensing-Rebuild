<?php
/**
 * Kohevo Studio — `core.hero` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class HeroRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'core.hero';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $eyebrow    = $scope->string('eyebrow');
        $subheading = $scope->string('subheading');
        $cta        = $scope->prop('primary_cta');
        $mediaRef   = $scope->prop('media');
        $image      = $scope->image($mediaRef);
        $accent     = $scope->tokenClass('fg', $scope->prop('accent_token'));

        $html = '<div' . Html::classAttr(['sb-hero', $image !== null ? 'sb-hero--with-media' : '']) . '>'
            . '<div class="sb-hero__content">'
            . ($eyebrow !== '' ? '<p' . Html::classAttr(['sb-hero__eyebrow', $accent]) . '>' . Html::e($eyebrow) . '</p>' : '')
            . '<h1 class="sb-hero__heading">' . Html::highlighted($scope->string('heading'), $scope->string('highlight')) . '</h1>'
            . ($subheading !== '' ? '<p class="sb-hero__subheading">' . Html::text($subheading) . '</p>' : '')
            . (is_array($cta) ? '<p class="sb-button-row">' . Html::link($cta, 'sb-button sb-button--primary') . '</p>' : '')
            . '</div>';

        if ($image !== null) {
            $alt = is_array($mediaRef) && is_string($mediaRef['alt'] ?? null) ? $mediaRef['alt'] : '';
            $html .= '<div class="sb-hero__media">' . ImageRenderer::img($image->url, $alt, $image->width, $image->height, $mediaRef, false) . '</div>';
        }

        return $html . '</div>';
    }
}
