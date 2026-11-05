<?php
/**
 * Kohevo Studio — `core.gallery` renderer.
 *
 * Layouts: a same-size grid, masonry, justified rows, a mosaic with larger tiles, and a sideways slider. Every image is
 * always in the markup, so search engines and a page without the runtime see all of them; paging, filters and masonry
 * placement are `initGalleries` in studio-runtime.js.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\DeviceColumns;
use Slate\Module\StudioBuilder\Render\Html;

final class GalleryRenderer implements BlockRendererInterface
{
    private const COLS = ['1', '2', '3', '4', '5', '6'];
    private const GAPS = ['none', 'xs', 'sm', 'md', 'lg'];
    private const ASPECTS = ['auto', '1:1', '4:3', '3:2', '16:9', '3:4', '4:5'];
    private const LAYOUTS = ['grid', 'masonry', 'justified', 'mosaic', 'slider'];
    private const HOVERS = ['none', 'zoom', 'lift', 'dim', 'color'];
    private const CAPTIONS = ['below', 'hover', 'none'];
    private const PAGINATION = ['none', 'load_more', 'infinite'];
    private const LINKS = ['lightbox', 'file', 'none'];

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
        $cols = self::choice($scope, 'columns', self::COLS, '3');
        $gap = self::choice($scope, 'gap', self::GAPS, 'md');
        $aspect = self::choice($scope, 'aspect_ratio', self::ASPECTS, '1:1');
        $layout = self::choice($scope, 'layout', self::LAYOUTS, 'grid');
        $hover = self::choice($scope, 'hover', self::HOVERS, 'none');
        $captions = self::choice($scope, 'captions', self::CAPTIONS, 'below');
        $pagination = $layout === 'slider' ? 'none' : self::choice($scope, 'pagination', self::PAGINATION, 'none');
        $rounded = $scope->bool('rounded', true);
        $linkTo = self::choice($scope, 'link_to', self::LINKS, 'lightbox');
        $random = $scope->string('order', 'default') === 'random';
        $lazy = $scope->bool('lazy_load', true);
        $overlayAlt = $captions === 'hover' && $scope->string('overlay_text', 'caption') === 'alt';

        $classes = [
            'sb-gallery',
            'sb-gallery--cols-' . $cols,
            'sb-gallery--gap-' . $gap,
        ];
        if ($rounded) {
            $classes[] = 'sb-gallery--rounded';
        }

        // Fewer columns on smaller screens unless the author chose otherwise: at most three on a tablet, two on a phone.
        $n = (int) $cols;
        $devices = $scope->deviceCounts('columns', 1, 6, min($n, 3), min($n, 2));
        $classes[] = 'sb-dcols';
        $vars = DeviceColumns::vars('dc', $n, $devices['tablet'], $devices['mobile']);
        if ($layout !== 'grid') {
            $classes[] = 'sb-gallery--' . $layout;
        }
        if ($hover !== 'none') {
            $classes[] = 'sb-gallery--hover-' . $hover;
        }
        if ($captions !== 'below') {
            $classes[] = 'sb-gallery--cap-' . $captions;
        }
        // Spacing 0 is "not set": the Gap choice applies, and Gap: None is how a gallery has no gap.
        $spacing = (int) $scope->prop('spacing', 0);
        if ($spacing > 0 || $scope->hasDeviceValue('spacing')) {
            $base = $spacing > 0 ? min(100, $spacing) : 16;
            $gaps = $scope->deviceCounts('spacing', 0, 100, $base, $base);
            $classes[] = 'sb-gallery--spaced';
            $vars .= ';--sb-gal-sd:' . $base . 'px;--sb-gal-st:' . $gaps['tablet'] . 'px;--sb-gal-sm:' . $gaps['mobile'] . 'px';
        }
        if ($layout === 'justified') {
            $vars .= ';--sb-gal-h:' . max(120, min(480, (int) $scope->prop('row_height', 240))) . 'px';
        }

        $images = [];
        foreach ((array) $scope->prop('images') as $img) {
            if (is_array($img) && (string) ($img['url'] ?? $img['src'] ?? '') !== '') {
                $images[] = $img;
            }
        }
        $perPage = max(2, min(100, (int) $scope->prop('per_page', 12)));
        $paged = $pagination !== 'none' && count($images) > $perPage;
        $categories = [];
        if ($scope->bool('show_filters', false)) {
            foreach ($images as $img) {
                $category = trim((string) ($img['category'] ?? ''));
                if ($category !== '' && !in_array($category, $categories, true)) {
                    $categories[] = $category;
                }
            }
        }
        $active = $layout !== 'grid' || $paged || $random || count($categories) > 1;

        $attrs = '';
        if ($active) {
            $attrs .= ' data-sb-gallery="' . $layout . '"';
            if ($paged) {
                $attrs .= ' data-sb-per-page="' . $perPage . '" data-sb-pagination="' . $pagination . '"';
            }
        }
        if ($random) {
            $attrs .= ' data-sb-order="random"';
        }
        if ($linkTo !== 'lightbox') {
            $attrs .= ' data-sb-no-lightbox';
        } else {
            // The viewer is built by the runtime, which reads its (translated) labels from here.
            $attrs .= ' data-sb-label-viewer="' . Html::e(Html::t('studio_lightbox_viewer', 'Image viewer')) . '"'
                . ' data-sb-label-prev="' . Html::e(Html::t('studio_lightbox_prev', 'Previous image')) . '"'
                . ' data-sb-label-next="' . Html::e(Html::t('studio_lightbox_next', 'Next image')) . '"'
                . ' data-sb-label-close="' . Html::e(Html::t('studio_lightbox_close', 'Close')) . '"';
        }

        $html = '<div' . Html::classAttr($classes) . ' style="' . $vars . '"' . $attrs . '>';
        $tile = 'sb-gallery__item' . ($layout === 'grid' || $layout === 'slider' ? ' sb-gallery__item--aspect-' . str_replace(':', '-', $aspect) : '');

        foreach ($images as $i => $img) {
            $width = (int) ($img['width'] ?? 0);
            $height = (int) ($img['height'] ?? 0);
            $class = $tile;
            if ($paged && $i >= $perPage) {
                $class .= ' is-more';
            }
            if ($layout === 'mosaic' && ($i % 10 === 0 || $i % 10 === 6)) {
                $class .= ' is-big';
            }
            $html .= '<figure class="' . $class . '"';
            $category = trim((string) ($img['category'] ?? ''));
            if ($category !== '') {
                $html .= ' data-sb-cat="' . Html::e($category) . '"';
            }
            if ($width > 0 && $height > 0) {
                $html .= ' style="--sb-gal-r:' . round($width / $height, 3) . '"';
            }
            $html .= '>';
            $src = Html::e((string) ($img['url'] ?? $img['src']));
            if ($linkTo === 'file') {
                $html .= '<a class="sb-gallery__link" href="' . $src . '" target="_blank" rel="noopener">';
            }
            $html .= '<img src="' . $src . '" alt="' . Html::e((string) ($img['alt'] ?? '')) . '"' . ($lazy ? ' loading="lazy"' : '');
            if ($width > 0 && $height > 0) {
                $html .= ' width="' . $width . '" height="' . $height . '"';
            }
            $html .= '>';
            if ($linkTo === 'file') {
                $html .= '</a>';
            }
            $caption = (string) ($img[$overlayAlt ? 'alt' : 'caption'] ?? '');
            if ($caption !== '') {
                $html .= '<figcaption>' . Html::e($caption) . '</figcaption>';
            }
            $html .= '</figure>';
        }
        if ($images === []) {
            // Default placeholder items for authoring
            for ($i = 1; $i <= (int) $cols; $i++) {
                $html .= '<figure class="sb-gallery__item sb-gallery__item--aspect-' . str_replace(':', '-', $aspect) . '">';
                $html .= '<div class="sb-gallery__placeholder" style="background:#f3f4f6;aspect-ratio:1;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:12px;">Image ' . $i . '</div>';
                $html .= '</figure>';
            }
        }

        $html .= '</div>';

        if (!$active) {
            return $html;
        }

        $bar = '';
        if (count($categories) > 1) {
            $bar = '<div class="sb-gallery__filters" role="group" aria-label="Filter images">'
                . '<button type="button" class="sb-gallery__filter" data-sb-filter="" aria-pressed="true">' . Html::e($scope->string('all_label', 'All')) . '</button>';
            foreach ($categories as $category) {
                $bar .= '<button type="button" class="sb-gallery__filter" data-sb-filter="' . Html::e($category) . '" aria-pressed="false">' . Html::e($category) . '</button>';
            }
            $bar .= '</div>';
        }
        $more = '';
        if ($pagination !== 'none') {
            $more = '<div class="sb-gallery__more"' . ($paged ? '' : ' hidden') . '><button type="button" class="sb-gallery__more-btn">' . Html::e($scope->string('load_more_text', 'Load more')) . '</button>'
                . '<span class="sb-gallery__count" aria-live="polite"></span></div>'
                . '<noscript><style>.sb-gallery__item.is-more{display:block}.sb-gallery__more{display:none}</style></noscript>';
        }

        return '<div class="sb-gallery-box">' . $bar . $html . $more . '</div>';
    }

    /** @param list<string> $allowed */
    private static function choice(BlockRenderScope $scope, string $key, array $allowed, string $default): string
    {
        $value = $scope->string($key, $default);
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
