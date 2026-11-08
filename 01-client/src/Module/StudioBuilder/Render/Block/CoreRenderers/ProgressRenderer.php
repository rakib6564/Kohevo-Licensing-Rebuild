<?php
/**
 * Kohevo Studio — `core.progress` renderer: labelled bars, each a `role="progressbar"` with its value. The bar is drawn
 * at its value; studio-runtime.js holds it empty until the block comes into view, then lets it grow (a transform, so
 * nothing reflows). Without script, and with reduced motion, the bars simply show their values.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class ProgressRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'core.progress';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $items = $scope->prop('items', []);
        $rows = '';
        foreach (is_array($items) ? array_slice($items, 0, 12) : [] as $item) {
            $label = is_array($item) ? trim((string) ($item['label'] ?? '')) : '';
            $value = is_array($item) && is_numeric($item['value'] ?? null) ? max(0, min(100, (float) $item['value'])) : null;
            if ($label === '' || $value === null) {
                continue;
            }
            $shown = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
            $rows .= '<div class="sb-progress__item"><div class="sb-progress__head"><span class="sb-progress__label">' . Html::e($label) . '</span>'
                . ($scope->bool('show_value', true) ? '<span class="sb-progress__value">' . $shown . '%</span>' : '') . '</div>'
                . '<div class="sb-progress__track" role="progressbar" aria-label="' . Html::e($label) . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $shown . '">'
                . '<span class="sb-progress__bar" style="--sb-pg-v:' . rtrim(rtrim(number_format($value / 100, 4, '.', ''), '0'), '.') . '"></span></div></div>';
        }
        if ($rows === '') {
            return '';
        }
        $vars = [];
        foreach (['bar_color' => '--sb-pg-bar', 'track_color' => '--sb-pg-track'] as $prop => $var) {
            $colour = trim($scope->string($prop));
            if ($colour !== '' && preg_match(Html::HIGHLIGHT_COLOR_PATTERN, $colour) === 1) {
                $vars[] = $var . ':' . $colour;
            }
        }
        $height = $scope->prop('height');
        if (is_numeric($height)) {
            $vars[] = '--sb-pg-h:' . max(2, min(40, (int) $height)) . 'px';
        }
        return '<div class="sb-progress" data-sb-progress-bars' . ($vars !== [] ? ' style="' . Html::e(implode(';', $vars)) . '"' : '') . '>' . $rows . '</div>';
    }
}
