<?php
/**
 * Kohevo Studio — `core.accordion` renderer.
 *
 * Emits markup for the Phase 2 runtime's `initAccordion`, wrapped in
 * `data-sb-allow-multiple` when the author allows more than one panel open.
 *
 * The no-JS contract is the reason every panel is rendered OPEN. A collapsed
 * accordion with no script is a page of empty headings; an open one is a plain
 * list of headed sections, which reads fine. The runtime collapses all but the
 * first on mount, so the enhanced page is the interactive one and the static
 * page is still complete.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class AccordionRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'core.accordion';
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
            $heading = is_string($item['heading'] ?? null) ? trim($item['heading']) : '';
            $body    = is_string($item['body'] ?? null) ? $item['body'] : '';
            if ($heading === '' && $body === '') {
                continue;
            }
            $items[] = ['heading' => $heading, 'body' => $body];
        }

        if ($items === []) {
            return '';
        }

        foreach ($items as $i => $item) {
            $items[$i]['body'] = Html::text($item['body']);
        }
        return self::markup($scope, $items, ($scope->prop('allow_multiple', false)) === true);
    }

    /**
     * The accordion's markup, shared with a repeater field shown as an accordion.
     *
     * @param list<array{heading: string, body: string}> $items `body` is ready HTML
     */
    /**
     * The look an accordion widget asks for: `lines` (rows divided by a line, no box) and a `plus` that turns to a cross
     * when open, instead of the boxed rows and the chevron. Shared by every accordion (Accordion, Accordion rows, a
     * Repeater shown as an accordion).
     *
     * @return list<string>
     */
    public static function lookClasses(BlockRenderScope $scope): array
    {
        return array_values(array_filter([
            $scope->string('look', 'boxed') === 'lines' ? 'sb-accordion-block--lines' : '',
            $scope->string('icon', 'chevron') === 'plus' ? 'sb-accordion-block--plus' : '',
        ]));
    }

    public static function markup(BlockRenderScope $scope, array $items, bool $multiple): string
    {
        $rows = '';
        foreach ($items as $index => $item) {
            $key       = 'i' . ($index + 1);
            $buttonId  = $scope->domId($key);
            $panelId   = $buttonId . '-panel';
            $headingId = $buttonId . '-heading';
            // First panel open by default; the rest start closed. With no
            // script these are the real, final states.
            $open = $index === 0;

            $rows .= '<div class="sb-accordion__item" data-sb-accordion-item>'
                . '<h3 class="sb-accordion__heading" id="' . Html::e($headingId) . '">'
                . '<button'
                . ' type="button"'
                . ' class="sb-accordion__button"'
                . ' data-sb-accordion-button'
                . ' id="' . Html::e($buttonId) . '"'
                . ' aria-controls="' . Html::e($panelId) . '"'
                . ' aria-expanded="' . ($open ? 'true' : 'false') . '"'
                // Roving tabindex again: one Tab stop per accordion.
                . ' tabindex="0"'
                . '>'
                . Html::e($item['heading'] !== '' ? $item['heading'] : ('Item ' . ($index + 1)))
                . '<span class="sb-accordion__icon" aria-hidden="true">▾</span>'
                . '</button></h3>'
                . '<div'
                . ' class="sb-accordion__panel"'
                . ' data-sb-accordion-panel'
                . ' id="' . Html::e($panelId) . '"'
                . ' role="region"'
                . ' aria-labelledby="' . Html::e($buttonId) . '"'
                // Closed rows ship closed: with no script this is the final
                // state, and it keeps `aria-expanded="false"` truthful. The
                // runtime re-asserts the same state on mount, so there is no
                // flash of the wrong panel.
                . ($open ? '' : ' hidden')
                . '>'
                . $item['body']
                . '</div>'
                . '</div>';
        }

        return '<div'
            . Html::classAttr(['sb-accordion-block', ...self::lookClasses($scope)])
            . ' data-sb-accordion'
            . ($multiple ? ' data-sb-allow-multiple' : '')
            . '>'
            . '<div class="sb-accordion">' . $rows . '</div>'
            . '</div>';
    }
}