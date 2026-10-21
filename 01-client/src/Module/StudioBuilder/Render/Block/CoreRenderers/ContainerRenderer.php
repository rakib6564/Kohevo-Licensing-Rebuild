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
    ];
    public const SHAPE_NAMES = ['none', 'wave', 'curve', 'tilt', 'triangle', 'mountains'];

    /**
     * The children go straight into the block's own wrapper, which is the flex box the Layout settings style. A background
     * video and the shape dividers come first: they are out of the flow (absolutely placed) and hidden from assistive tech.
     */
    public function render(BlockRenderScope $scope): string
    {
        return self::video($scope) . self::shape($scope, 'top') . self::shape($scope, 'bottom') . $scope->childrenHtml();
    }

    private static function video(BlockRenderScope $scope): string
    {
        $url = Html::safeUrl($scope->prop('bg_video'));
        if ($url === null) {
            return '';
        }
        return '<video class="sb-bg-video" src="' . Html::e($url) . '" autoplay muted loop playsinline preload="metadata" aria-hidden="true" tabindex="-1"></video>';
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
            . '<svg viewBox="0 0 1000 100" preserveAspectRatio="none" focusable="false"><path d="' . self::SHAPES[$name] . '"/></svg></div>';
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
        if (Html::safeUrl($scope->prop('bg_video')) !== null || isset(self::SHAPES[$scope->string('shape_top')]) || isset(self::SHAPES[$scope->string('shape_bottom')])) {
            $classes[] = 'sb-layered';
        }
        return $classes;
    }
}
