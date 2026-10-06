<?php
/**
 * Kohevo Studio — `core.stats` renderer.
 *
 * A row of animated counters. The key detail is the counter TEXT: the server
 * renders the FINAL value and the runtime rewrites it to 0 and counts up on
 * mount. That ordering is deliberate — a script-less visitor, a crawler, and a
 * visitor whose `prefers-reduced-motion` is set all see the real number,
 * because the runtime snaps to the final value for them and never blanks it.
 * Shipping `0` in the markup would make the no-JS case a row of zeroes.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class StatsRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'core.stats';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $items = [];
        foreach ((array) $scope->prop('items', []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $value  = is_numeric($item['value'] ?? null) ? (float) $item['value'] : null;
            $label  = is_string($item['label'] ?? null) ? trim($item['label']) : '';
            $prefix = is_string($item['prefix'] ?? null) ? $item['prefix'] : '';
            $suffix = is_string($item['suffix'] ?? null) ? $item['suffix'] : '';
            $decimals = is_numeric($item['decimals'] ?? null) ? (int) $item['decimals'] : 0;
            $decimals = max(0, min(3, $decimals));
            if ($value === null || $label === '') {
                continue;
            }
            $items[] = [
                'value'    => $value,
                'label'    => $label,
                'prefix'   => $prefix,
                'suffix'   => $suffix,
                'decimals' => $decimals,
            ];
        }

        if ($items === []) {
            return '';
        }

        $cells = '';
        foreach ($items as $item) {
            // Rendered exactly as the runtime's `render()` would: same grouping,
            // same prefix/suffix, same decimals. Keeping the two in step is
            // what makes the count-up seamless instead of a visible jump.
            $formatted = number_format($item['value'], $item['decimals'], '.', ',');
            $cells .= '<div class="sb-stat">'
                . '<span class="sb-stat__value"'
                . ' data-sb-counter'
                . ' data-sb-to="' . Html::e((string) $item['value']) . '"'
                . ' data-sb-decimals="' . $item['decimals'] . '"'
                . ($item['prefix'] === '' ? '' : ' data-sb-prefix="' . Html::e($item['prefix']) . '"')
                . ($item['suffix'] === '' ? '' : ' data-sb-suffix="' . Html::e($item['suffix']) . '"')
                . '>' . Html::e($item['prefix'] . $formatted . $item['suffix']) . '</span>'
                . '<span class="sb-stat__label">' . Html::e($item['label']) . '</span>'
                . '</div>';
        }

        $columns = count($items);
        $columns = max(2, min(4, $columns));

        return '<div' . Html::classAttr(['sb-stats', 'sb-cols-2', 'sb-md-cols-' . $columns]) . '>'
            . $cells
            . '</div>';
    }
}