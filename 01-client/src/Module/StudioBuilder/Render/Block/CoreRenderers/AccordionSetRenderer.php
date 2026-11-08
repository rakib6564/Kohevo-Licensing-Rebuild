<?php
/**
 * Kohevo Studio — `layout.accordion` renderer: rows that open and close, each a `layout.accordion_item` whose child
 * blocks are its answer (AccordionItemRenderer). The markup keeps the core Accordion's contract (`data-sb-accordion`,
 * `.sb-accordion__item` rows, `data-sb-accordion-button` / `-panel`), so studio-runtime.js opens and closes it the same way.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class AccordionSetRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'layout.accordion';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $rows = $scope->childrenHtml();
        if (trim($rows) === '') {
            return $scope->showsDiagnostics() ? '<p class="sb-accordion__empty">' . Html::e(Html::t('studio_accordion_empty', 'Add an Accordion row')) . '</p>' : '';
        }
        return '<div class="sb-accordion-block sb-accordion-block--blocks" data-sb-accordion' . ($scope->bool('allow_multiple') ? ' data-sb-allow-multiple' : '') . '>'
            . '<div class="sb-accordion">' . $rows . '</div></div>';
    }
}
