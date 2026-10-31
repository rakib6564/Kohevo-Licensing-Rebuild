<?php
/**
 * Kohevo Studio — `core.container` renderer (structural; children pre-rendered by the pipeline).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\WrapperClassesInterface;
use Slate\Module\StudioBuilder\Render\Html;

final class ContainerRenderer implements BlockRendererInterface, WrapperClassesInterface
{
    public function type(): string
    {
        return 'core.container';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    /**
     * Shape dividers, as paths in a 1000 x 100 box drawn from the top edge (the bottom divider is the same shape turned
     * over). Names are stored in documents: add, never rename.
     */
    public const SHAPES = [
        'wave'      => 'M0,0 L0,60 C150,100 350,0 500,40 C650,80 850,10 1000,50 L1000,0 Z',
        'curve'     => 'M0,0 L0,30 Q500,130 1000,30 L1000,0 Z',
        'tilt'      => 'M0,0 L1000,0 L1000,100 Z',
        'triangle'  => 'M0,0 L500,100 L1000,0 Z',
        'mountains' => 'M0,0 L0,70 L160,30 L300,80 L480,20 L640,75 L800,35 L1000,85 L1000,0 Z',
        'waves'     => 'M0,0 L0,50 C100,90 150,90 250,50 C350,10 400,10 500,50 C600,90 650,90 750,50 C850,10 900,10 1000,50 L1000,0 Z',
        'curve_asym' => 'M0,0 L0,30 Q750,140 1000,30 L1000,0 Z',
        'triangle_asym' => 'M0,0 L700,100 L1000,0 Z',
        'pyramids'  => 'M0,0 L0,100 L125,30 L250,100 L375,30 L500,100 L625,30 L750,100 L875,30 L1000,100 L1000,0 Z',
        'zigzag'    => 'M0,0 L0,40 L50,70 L100,40 L150,70 L200,40 L250,70 L300,40 L350,70 L400,40 L450,70 L500,40 L550,70 L600,40 L650,70 L700,40 L750,70 L800,40 L850,70 L900,40 L950,70 L1000,40 L1000,0 Z',
        'drops'     => 'M0,0 L0,30 C15,30 25,55 40,55 C55,55 65,30 80,30 C95,30 105,75 120,75 C135,75 145,30 160,30 C175,30 185,95 200,95 C215,95 225,30 240,30 C255,30 265,70 280,70 C295,70 305,30 320,30 C335,30 345,90 360,90 C375,90 385,30 400,30 C415,30 425,65 440,65 C455,65 465,30 480,30 C495,30 505,85 520,85 C535,85 545,30 560,30 C575,30 585,60 600,60 C615,60 625,30 640,30 C655,30 665,80 680,80 C695,80 705,30 720,30 C735,30 745,55 760,55 C775,55 785,30 800,30 C815,30 825,75 840,75 C855,75 865,30 880,30 C895,30 905,95 920,95 C935,95 945,30 960,30 L1000,30 L1000,0 Z',
        'clouds'    => 'M0,0 L0,45 Q50,100 100,45 Q150,100 200,45 Q250,100 300,45 Q350,100 400,45 Q450,100 500,45 Q550,100 600,45 Q650,100 700,45 Q750,100 800,45 Q850,100 900,45 Q950,100 1000,45 L1000,0 Z',
        'arrow'     => 'M0,0 L0,40 L460,40 L500,100 L540,40 L1000,40 L1000,0 Z',
        'split'     => 'M0,0 L0,100 L480,100 L500,40 L520,100 L1000,100 L1000,0 Z',
        'tilt_opacity' => 'M0,0 L1000,0 L1000,100 Z|M0,0 L1000,0 L1000,60 Z|M0,0 L1000,0 L1000,25 Z',
        'fan_opacity' => 'M0,0 L0,100 L500,50 L1000,100 L1000,0 Z|M0,0 L0,70 L500,50 L1000,70 L1000,0 Z|M0,0 L0,35 L500,50 L1000,35 L1000,0 Z',
        'waves_opacity' => 'M0,0 L0,70 C150,100 350,40 500,70 C650,100 850,40 1000,70 L1000,0 Z|M0,0 L0,45 C200,80 300,10 500,45 C700,80 800,10 1000,45 L1000,0 Z|M0,0 L0,20 C150,45 350,0 500,20 C650,45 850,0 1000,20 L1000,0 Z',
        'book'      => 'M0,0 L0,20 Q250,20 500,100 Q750,20 1000,20 L1000,0 Z',
    ];
    public const SHAPE_NAMES = ['none', 'wave', 'curve', 'tilt', 'triangle', 'mountains', 'waves', 'curve_asym', 'triangle_asym', 'pyramids', 'zigzag', 'drops', 'clouds', 'arrow', 'split', 'tilt_opacity', 'fan_opacity', 'waves_opacity', 'book'];

    /**
     * The children go straight into the block's own wrapper, which is the flex box the Layout settings style. A background
     * video and the shape dividers come first: they are out of the flow (absolutely placed) and hidden from assistive tech.
     */
    public function render(BlockRenderScope $scope): string
    {
        return self::video($scope) . self::slides($scope) . self::shape($scope, 'top') . self::shape($scope, 'bottom') . $scope->childrenHtml();
    }

    private static function video(BlockRenderScope $scope): string
    {
        $url = Html::safeUrl($scope->prop('bg_video'));
        if ($url === null) {
            return '';
        }
        return '<video class="sb-bg-video" src="' . Html::e($url) . '" autoplay muted loop playsinline preload="metadata" aria-hidden="true" tabindex="-1"></video>';
    }

    /** @return list<string> the slideshow's safe image addresses */
    private static function slideUrls(BlockRenderScope $scope): array
    {
        $urls = [];
        $slides = $scope->prop('bg_slides');
        foreach (is_array($slides) ? $slides : [] as $slide) {
            $url = is_array($slide) ? Html::safeUrl($slide['url'] ?? null) : null;
            if ($url !== null) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /** Stacked pictures that cross-fade in CSS alone, one after the other; reduced motion shows the first, still. */
    private static function slides(BlockRenderScope $scope): string
    {
        $urls = self::slideUrls($scope);
        if ($urls === []) {
            return '';
        }
        $seconds = $scope->prop('bg_slide_seconds');
        $seconds = is_int($seconds) ? max(2, min(30, $seconds)) : 5;
        $n = count($urls);
        $html = '<div class="sb-bg-slides" aria-hidden="true" style="--sb-slides:' . $n . ';--sb-slide-s:' . $seconds . 's">';
        foreach ($urls as $i => $url) {
            // url() quotes are escaped by Html::e(); safeUrl() has already refused anything but a page or http(s) address.
            $html .= '<span style="--sb-slide:' . $i . ';background-image:url(&quot;' . Html::e(str_replace(['"', '\\', "\n"], '', $url)) . '&quot;)"></span>';
        }
        return $html . '</div>';
    }

    private static function shape(BlockRenderScope $scope, string $side): string
    {
        $name = $scope->string('shape_' . $side, 'none');
        if (!isset(self::SHAPES[$name])) {
            return '';
        }
        $classes = ['sb-shape', 'sb-shape--' . $side, $scope->tokenClass('fg', $scope->prop('shape_' . $side . '_color'))];
        if ($scope->bool('shape_' . $side . '_flip')) {
            $classes[] = 'sb-shape--flip';
        }
        if ($scope->bool('shape_' . $side . '_front')) {
            $classes[] = 'sb-shape--front';
        }
        // Only clamped integers reach the style attribute.
        $vars = [];
        $width = $scope->prop('shape_' . $side . '_width');
        if (is_int($width) && $width > 100) {
            $vars[] = '--sb-shape-w:' . min(300, $width) . '%';
        }
        $height = $scope->prop('shape_' . $side . '_height');
        if (is_int($height) && $height > 0) {
            $vars[] = '--sb-shape-h:' . min(500, $height) . 'px';
        }
        return '<div' . Html::classAttr($classes) . ' aria-hidden="true"' . ($vars !== [] ? ' style="' . implode(';', $vars) . '"' : '') . '>'
            . '<svg viewBox="0 0 1000 100" preserveAspectRatio="none" focusable="false">' . self::paths(self::SHAPES[$name]) . '</svg></div>';
    }

    /** A shape's paths: `|` separates layers, each see-through so that they build up. */
    private static function paths(string $shape): string
    {
        $layers = explode('|', $shape);
        $opacity = count($layers) > 1 ? ' fill-opacity="0.35"' : '';
        return implode('', array_map(static fn(string $d): string => '<path' . $opacity . ' d="' . $d . '"/>', $layers));
    }

    public function wrapperClasses(BlockRenderScope $scope): array
    {
        $gap = $scope->string('gap', 'md');
        if (!in_array($gap, CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, true)) {
            $gap = 'md';
        }
        $direction = $scope->string('direction', 'vertical') === 'horizontal' ? 'horizontal' : 'vertical';
        $classes = ['sb-stack', 'sb-stack--' . $direction, 'sb-gap-' . $gap];
        // A horizontal container wraps by default (as it always has); "nowrap" keeps one row, like a Flex row.
        if ($direction === 'horizontal' && $scope->string('wrap', 'wrap') === 'nowrap') {
            $classes[] = 'sb-stack--nowrap';
        }
        // A video or a shape divider sits behind the children, in the block's own stacking context.
        if (Html::safeUrl($scope->prop('bg_video')) !== null || self::slideUrls($scope) !== [] || isset(self::SHAPES[$scope->string('shape_top')]) || isset(self::SHAPES[$scope->string('shape_bottom')])) {
            $classes[] = 'sb-layered';
        }
        return $classes;
    }
}
