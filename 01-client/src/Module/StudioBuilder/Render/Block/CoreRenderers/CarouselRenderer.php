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
use Slate\Module\StudioBuilder\Render\Block\DeviceColumns;
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
            $alt     = is_array($slide['image'] ?? null) && is_string($slide['image']['alt'] ?? null) ? $slide['image']['alt'] : '';
            $quote   = is_string($slide['quote'] ?? null) ? trim($slide['quote']) : '';
            $author  = is_string($slide['author'] ?? null) ? trim($slide['author']) : '';
            if ($image === null && $quote === '' && $caption === '') {
                continue;
            }
            $slides[] = ['image' => $image, 'alt' => $alt, 'quote' => $quote, 'author' => $author, 'caption' => $caption];
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
                    . '" alt="' . Html::e($slide['alt']) . '" loading="lazy" decoding="async">';
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
                // A plain group of buttons (each with aria-current), valid with or without the runtime.
                . '<div class="sb-carousel__dots" data-sb-car-dots role="group" aria-label="'
                . Html::e(Html::t('studio_carousel_choose', 'Choose slide')) . '">' . $dots . '</div>'
                . '<button type="button" class="sb-carousel__btn" data-sb-car-next aria-label="'
                . Html::e(Html::t('studio_carousel_next', 'Next slide')) . '">&#8250;</button>'
                // Autoplay can always be stopped, on touch screens too (WCAG 2.2.2). The runtime shows it.
                . ($autoplay > 0 && $loop
                    ? '<button type="button" class="sb-carousel__btn sb-carousel__pause" data-sb-car-pause aria-pressed="false"'
                        . ' data-sb-label-pause="' . Html::e(Html::t('studio_carousel_pause', 'Pause slideshow')) . '"'
                        . ' data-sb-label-play="' . Html::e(Html::t('studio_carousel_play', 'Play slideshow')) . '"'
                        . ' aria-label="' . Html::e(Html::t('studio_carousel_pause', 'Pause slideshow')) . '">&#10073;&#10073;</button>'
                    : '')
                . '</div>';
        }

        $label = (string) ($options['label'] ?? '');
        if ($label === '') {
            $label = Html::t('studio_carousel_label', 'Slideshow');
        }

        // Several slides per view become one on a phone and at most two on a tablet, unless the author chose per device.
        $devices = $scope->deviceCounts('options.per_view', 1, 3, min($perView, 2), 1);
        $perDevice = $perView > 1 || $devices['tablet'] > 1 || $devices['mobile'] > 1;

        return '<div'
            . Html::classAttr(['sb-carousel', $perDevice ? 'sb-dpv' : ''])
            . ' data-sb-carousel'
            . ' data-sb-per-view="' . $perView . '"'
            . ($perDevice ? ' style="' . DeviceColumns::vars('pv', $perView, $devices['tablet'], $devices['mobile']) . '"' : '')
            . ($loop && $interactive ? ' data-sb-loop' : '')
            . ($autoplay > 0 && $interactive ? ' data-sb-autoplay="' . $autoplay . '"' : '')
            . ' aria-roledescription="' . Html::e(Html::t('studio_carousel_desc', 'carousel')) . '"'
            // The runtime labels slides and rebuilt dots with these, so a translated page stays translated.
            . ' data-sb-label-slide="' . Html::e(Html::t('studio_carousel_slide', 'slide')) . '"'
            . ' data-sb-label-slide-of="' . Html::e(Html::t('studio_carousel_slide_of', '%1$s of %2$s')) . '"'
            . ' data-sb-label-go="' . Html::e(Html::t('studio_carousel_go', 'Go to slide')) . '"'
            . ' aria-label="' . Html::e($label) . '"'
            . '>'
            . '<div class="sb-carousel__track" data-sb-carousel-track>' . $track . '</div>'
            . $controls
            . '</div>';
    }
}