<?php
/**
 * Kohevo Studio — `core.circle_text` renderer: words set round a circle (an SVG text path the renderer draws, never
 * author markup), turning once every `spin_seconds` (0: still), with an optional icon from the library in the middle.
 * The text stretches to fill the circle exactly. Screen readers get the words once, from the wrapper's label; the
 * drawing is hidden from them. Reduced motion: it stands still.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\Icon\IconLibrary;

final class CircleTextRenderer implements BlockRendererInterface
{
    /** The circle's radius in the 120-unit drawing, and its length, which the text is stretched to. */
    private const RADIUS = 46;

    public function type(): string
    {
        return 'core.circle_text';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $text = trim($scope->string('text'));
        if ($text === '') {
            return '';
        }
        $num = static fn(string $key, int $min, int $max, int $default): int => is_numeric($scope->prop($key)) ? max($min, min($max, (int) $scope->prop($key))) : $default;
        $size = $num('size', 60, 400, 120);
        $font = $num('font_size', 6, 24, 11);
        $spin = $num('spin_seconds', 0, 120, 14);
        $reverse = $scope->string('direction', 'clockwise') === 'anticlockwise';
        $path = $scope->domId('circle');
        $r = self::RADIUS;
        $length = number_format(2 * M_PI * $r, 1, '.', '');

        $icon = '';
        $name = $scope->string('icon', 'none');
        if ($name !== 'none' && IconLibrary::has($name)) {
            $icon = '<span class="sb-circle-text__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
                . IconLibrary::markup($name) . '</svg></span>';
        }
        $classes = 'sb-circle-text' . ($spin > 0 ? ' sb-circle-text--spin' : '') . ($reverse ? ' sb-circle-text--reverse' : '');
        return '<div class="' . $classes . '" role="img" aria-label="' . Html::e($text) . '" style="--sb-ct-size:' . $size . 'px' . ($spin > 0 ? ';--sb-ct-spin:' . $spin . 's' : '') . '">'
            . '<svg class="sb-circle-text__ring" viewBox="0 0 120 120" aria-hidden="true" focusable="false">'
            . '<defs><path id="' . Html::e($path) . '" d="M60,60 m-' . $r . ',0 a' . $r . ',' . $r . ' 0 1,1 ' . (2 * $r) . ',0 a' . $r . ',' . $r . ' 0 1,1 -' . (2 * $r) . ',0"></path></defs>'
            . '<text font-size="' . $font . '" fill="currentColor"><textPath href="#' . Html::e($path) . '" textLength="' . $length . '" lengthAdjust="spacing">' . Html::e($text) . '</textPath></text>'
            . '</svg>' . $icon . '</div>';
    }
}
