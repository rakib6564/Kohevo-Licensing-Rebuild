<?php
/**
 * Kohevo Studio — `core.gallery` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class GalleryRenderer implements BlockRendererInterface
{
    private const COLS = ['2', '3', '4', '6'];
    private const GAPS = ['none', 'xs', 'sm', 'md', 'lg'];
    private const ASPECTS = ['auto', '1:1', '4:3', '16:9'];

    public function type(): string
    {
        return 'core.gallery';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $cols = $scope->string('columns', '3');
        if (!in_array($cols, self::COLS, true)) {
            $cols = '3';
        }
        $gap = $scope->string('gap', 'md');
        if (!in_array($gap, self::GAPS, true)) {
            $gap = 'md';
        }
        $aspect = $scope->string('aspect_ratio', '1:1');
        if (!in_array($aspect, self::ASPECTS, true)) {
            $aspect = '1:1';
        }
        $rounded = $scope->bool('rounded', true);

        $classes = [
            'sb-gallery',
            'sb-gallery--cols-' . $cols,
            'sb-gallery--gap-' . $gap,
        ];
        if ($rounded) {
            $classes[] = 'sb-gallery--rounded';
        }

        $images = $scope->prop('images');
        $html = '<div' . Html::classAttr($classes) . '>';

        if (is_array($images) && $images !== []) {
            foreach ($images as $img) {
                if (!is_array($img)) {
                    continue;
                }
                $src = (string) ($img['url'] ?? $img['src'] ?? '');
                $alt = (string) ($img['alt'] ?? '');
                $caption = (string) ($img['caption'] ?? '');
                if ($src === '') {
                    continue;
                }
                $html .= '<figure class="sb-gallery__item sb-gallery__item--aspect-' . str_replace(':', '-', $aspect) . '">';
                $html .= '<img src="' . Html::e($src) . '" alt="' . Html::e($alt) . '" loading="lazy">';
                if ($caption !== '') {
                    $html .= '<figcaption>' . Html::e($caption) . '</figcaption>';
                }
                $html .= '</figure>';
            }
        } else {
            // Default placeholder items for authoring
            for ($i = 1; $i <= (int) $cols; $i++) {
                $html .= '<figure class="sb-gallery__item sb-gallery__item--aspect-' . str_replace(':', '-', $aspect) . '">';
                $html .= '<div class="sb-gallery__placeholder" style="background:#f3f4f6;aspect-ratio:1;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:12px;">Image ' . $i . '</div>';
                $html .= '</figure>';
            }
        }

        $html .= '</div>';

        return $html;
    }
}
