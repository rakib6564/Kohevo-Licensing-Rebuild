<?php
/**
 * Kohevo Studio — `layout.timeline` renderer: the child blocks are steps on a vertical line. Each step gets a marker
 * (numbered, when `numbered`), drawn by the stylesheet on the step's `::before` (so a step Container's overlay does not
 * show). studio-runtime.js fills the line as the page scrolls (`--sb-tl-p`) and marks each step it passes
 * (`is-passed`); without script the line shows full.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class TimelineRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'layout.timeline';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $steps = $scope->childrenHtml();
        if (trim($steps) === '') {
            return $scope->showsDiagnostics() ? '<p class="sb-timeline__empty">' . Html::e(Html::t('studio_timeline_empty', 'Add blocks: each one is a step')) . '</p>' : '';
        }
        $vars = [];
        foreach (['line_color' => '--sb-tl-line', 'fill_color' => '--sb-tl-fill'] as $prop => $var) {
            $colour = trim($scope->string($prop));
            if ($colour !== '' && preg_match(Html::HIGHLIGHT_COLOR_PATTERN, $colour) === 1) {
                $vars[] = $var . ':' . $colour;
            }
        }
        return '<div class="sb-timeline' . ($scope->bool('numbered', true) ? ' sb-timeline--numbered' : '') . '" data-sb-timeline'
            . ($vars !== [] ? ' style="' . Html::e(implode(';', $vars)) . '"' : '') . '>'
            . '<span class="sb-timeline__rail" aria-hidden="true"><span class="sb-timeline__fill"></span></span>'
            . '<div class="sb-timeline__steps">' . $steps . '</div></div>';
    }
}
