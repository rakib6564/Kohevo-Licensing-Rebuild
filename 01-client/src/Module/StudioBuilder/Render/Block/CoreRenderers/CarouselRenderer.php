<?php
/**
 * Kohevo Studio — `core.carousel` renderer.
 *
 * Emits markup for the Phase 2 runtime's `initCarousel`: a `data-sb-carousel`
 * group containing a `data-sb-carousel-track` of `data-sb-slide` children,
 * plus the nav and dot controls the runtime drives.
 *
 * NO-JS CONTRACT — the important part. The track is NOT a horizontally
 * scrolling flex row by default, because with `overflow:hidden` (which the
 * runtime relies on) a script-less visitor would see slide one and nothing
 * else, with no way to reach slides 2..n. So the base stylesheet renders slides
 * stacked and scrollable; the runtime adds `data-sb-ready` on <html> and the
 * enhanced stylesheet switches to the clipped, transform-driven track. The
 * carousel degrades to a plain vertical list, which loses nothing.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class CarouselRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'core.carousel';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $slides = [];
        foreach ((array) $scope->prop('slides', []) as $index => $slide) {
            if (!is_array($slide)) {
                continue;
            }
            $caption = is_string($slide['caption'] ?? null) ? trim($slide['caption']) : '';
            $image   = $scope->image($slide['image'] ?? null);
            $quote   = is_string($slide['quote'] ?? null) ? trim($slide['quote']) : '';
            $author  = is_string($slide['author'] ?? null) ? trim($slide['author']) : '';
            if ($image === null && $quote === '' && $caption === '') {
                continue;
            }
            $slides[] = ['image' => $image, 'quote' => $quote, 'author' => $author, 'caption' => $caption];
        }

        if ($slides === []) {
            return '';
        }

        $options = (array) $scope->prop('options', []);
        $perView = is_numeric($options['per_view'] ?? null) ? (int) $options['per_view'] : 1;
        $perView = max(1, min(3, $perView));
        $loop    = ($options['loop'] ?? true) === true;
        $autoplay = (int) ($options['autoplay_ms'] ?? 0);

        $track = '';
        $dots  = '';
        foreach ($slides as $index => $slide) {
            $track .= '<figure class="sb-carousel__slide" data-sb-slide>';
            if ($slide['image'] !== null) {
                $track .= '<img class="sb-carousel__image" src="' . Html::e($slide['image']->url)
                    . '" alt="' . Html::e($slide['image']->alt) . '" loading="lazy" decoding="async">';
            }
            if ($slide['quote'] !== '') {
                $track .= '<blockquote class="sb-carousel__quote"><p>' . Html::text($slide['quote']) . '</p>';
                if ($slide['author'] !== '') {
                    $track .= '<cite class="sb-carousel__author">' . Html::e($slide['author']) . '</cite>';
                }
                $track .= '</blockquote>';
            }
            if ($slide['caption'] !== '') {
                $track .= '<figcaption class="sb-carousel__caption">' . Html::e($slide['caption']) . '</figcaption>';
            }
            $track .= '</figure>';

            $dots .= '<button'
                . ' type="button"'
                . ' class="sb-carousel__dot"'
                . ' data-sb-car-dot="' . $index . '"'
                . ' aria-label="' . Html::e(Html::t('studio_carousel_go', 'Go to slide') . ' ' . ($index + 1)) . '"'
                . ' aria-current="' . ($index === 0 ? 'true' : 'false') . '"'
                . '></button>';
        }

        // A single slide has nothing to carousel to. Emitting the group would
        // give the runtime controls that do nothing.
        $interactive = count($slides) > 1;

        $controls = '';
        if ($interactive) {
            $controls = '<div class="sb-carousel__nav">'
                . '<button type="button" class="sb-carousel__btn" data-sb-car-prev aria-label="'
                . Html::e(Html::t('studio_carousel_prev', 'Previous slide')) . '">&#8249;</button>'
                . '<div class="sb-carousel__dots" data-sb-car-dots role="tablist" aria-label="'
                . Html::e(Html::t('studio_carousel_choose', 'Choose slide')) . '">' . $dots . '</div>'
                . '<button type="button" class="sb-carousel__btn" data-sb-car-next aria-label="'
                . Html::e(Html::t('studio_carousel_next', 'Next slide')) . '">&#8250;</button>'
                . '</div>';
        }

        $label = (string) ($options['label'] ?? '');
        if ($label === '') {
            $label = Html::t('studio_carousel_label', 'Slideshow');
        }

        return '<div'
            . Html::classAttr(['sb-carousel'])
            . ' data-sb-carousel'
            . ' data-sb-per-view="' . $perView . '"'
            . ($loop && $interactive ? ' data-sb-loop' : '')
            . ($autoplay > 0 && $interactive ? ' data-sb-autoplay="' . $autoplay . '"' : '')
            . ' aria-roledescription="' . Html::e(Html::t('studio_carousel_desc', 'carousel')) . '"'
            . ' aria-label="' . Html::e($label) . '"'
            . '>'
            . '<div class="sb-carousel__track" data-sb-carousel-track>' . $track . '</div>'
            . $controls
            . '</div>';
    }
}