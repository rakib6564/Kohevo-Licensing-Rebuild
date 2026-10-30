<?php
/**
 * Kohevo Studio — `core.marquee` renderer: a line of text that scrolls without end, in CSS alone.
 *
 * The track holds two identical groups and moves by half its width, so the loop has no visible seam. Only the first
 * copy is read out; every repeat is hidden from assistive technology, and reduced motion shows that copy standing
 * still (see StudioStylesheet).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class MarqueeRenderer implements BlockRendererInterface
{
    public const DIRECTIONS = ['left', 'right'];

    public const MIN_SECONDS = 5;
    public const MAX_SECONDS = 180;

    /** Copies in one group: enough for a short line to fill a very wide screen. */
    private const COPIES = 8;

    public function type(): string
    {
        return 'core.marquee';
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

        $direction = $scope->string('direction', 'left');
        $classes = ['sb-marquee', 'sb-marquee--' . (in_array($direction, self::DIRECTIONS, true) ? $direction : 'left')];
        if ($scope->bool('pause_on_hover', false)) {
            $classes[] = 'sb-marquee--pause';
        }

        $seconds = $scope->prop('duration');
        $seconds = is_numeric($seconds) ? max(self::MIN_SECONDS, min(self::MAX_SECONDS, (int) $seconds)) : 30;

        $copy = Html::e($text);
        $repeat = '<span class="sb-marquee__text" aria-hidden="true">' . $copy . '</span>';

        return '<div' . Html::classAttr($classes) . '>'
            . '<div class="sb-marquee__track" style="animation-duration:' . $seconds . 's">'
            . '<div class="sb-marquee__group"><span class="sb-marquee__text">' . $copy . '</span>' . str_repeat($repeat, self::COPIES - 1) . '</div>'
            . '<div class="sb-marquee__group" aria-hidden="true">' . str_repeat($repeat, self::COPIES) . '</div>'
            . '</div></div>';
    }
}
