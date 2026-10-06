<?php
/**
 * Kohevo Studio — `core.tabs` renderer.
 *
 * Emits the markup the Phase 2 runtime's `initTabs` binds to, and — just as
 * importantly — markup that still READS with no JavaScript at all. Every panel
 * is visible by default and the runtime hides all but the selected one, so a
 * script-less visitor sees all the content rather than a tab strip leading
 * nowhere. That is the same progressive-enhancement contract the modal keeps:
 * the `:target` fallback there, stacked panels here.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class TabsRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'core.tabs';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $items = [];
        foreach ((array) $scope->prop('items', []) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $label   = is_string($item['label'] ?? null) ? trim($item['label']) : '';
            $content = is_string($item['content'] ?? null) ? $item['content'] : '';
            if ($label === '' && $content === '') {
                continue;
            }
            $items[] = [
                'label'   => $label !== '' ? $label : ('Tab ' . ($index + 1)),
                'content' => $content,
            ];
        }

        if ($items === []) {
            return '';
        }

        $options  = (array) $scope->prop('options', []);
        $position = in_array($options['position'] ?? 'top', ['top', 'bottom'], true) ? $options['position'] : 'top';

        $tabs = '';
        $panels = '';
        foreach ($items as $index => $item) {
            // `t1`, `t2` … — the runtime matches a tab to its panel by this
            // key, so it must be unique within the block and must not be
            // reused by a second tabs block on the same page.
            $key   = 't' . ($index + 1);
            $id    = $scope->domId($key);
            $sel   = $index === 0;
            $pid   = $id . '-panel';

            $tabs .= '<button'
                . ' type="button"'
                . ' class="sb-tabs__tab"'
                . ' role="tab"'
                . ' id="' . Html::e($id) . '"'
                . ' data-sb-tab="' . Html::e($key) . '"'
                . ' aria-controls="' . Html::e($pid) . '"'
                . ' aria-selected="' . ($sel ? 'true' : 'false') . '"'
                // Roving tabindex: one Tab stop for the whole strip, arrows
                // move within it. This is the APG tabs pattern.
                . ' tabindex="' . ($sel ? '0' : '-1') . '"'
                . '>' . Html::e($item['label']) . '</button>';

            $panels .= '<div'
                . ' class="sb-tabs__panel"'
                . ' role="tabpanel"'
                . ' id="' . Html::e($pid) . '"'
                . ' data-sb-panel="' . Html::e($key) . '"'
                . ' aria-labelledby="' . Html::e($id) . '"'
                . ' tabindex="0"'
                . '>' . Html::text($item['content']) . '</div>';
        }

        return '<div'
            . Html::classAttr(['sb-tabs-block', 'sb-tabs-block--' . $position])
            . ' data-sb-tabs>'
            . '<div class="sb-tabs" role="tablist" aria-label="'
            . Html::e(Html::t('studio_tabs_label', 'Content tabs')) . '">' . $tabs . '</div>'
            . '<div class="sb-tabs__panels">' . $panels . '</div>'
            . '</div>';
    }
}