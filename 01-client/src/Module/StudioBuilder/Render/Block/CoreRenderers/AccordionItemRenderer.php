<?php
/**
 * Kohevo Studio — `layout.accordion_item` renderer: one row of an Accordion with blocks. The block's wrapper is the row
 * (`wrapperClasses()`), so rows sit next to each other as the core Accordion's do. A row ships open or closed as its
 * `open` setting says, which is also its state without script; the editor canvas shows every row open.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\WrapperClassesInterface;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\RenderMode;

final class AccordionItemRenderer implements BlockRendererInterface, WrapperClassesInterface
{
    public function type(): string
    {
        return 'layout.accordion_item';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function wrapperClasses(BlockRenderScope $scope): array
    {
        return ['sb-accordion__item'];
    }

    public function render(BlockRenderScope $scope): string
    {
        $title = trim($scope->string('title'));
        if ($title === '') {
            return '';
        }
        $id = $scope->domId('row');
        // Every row open on the editor canvas (it runs no script), as set everywhere else, Preview included.
        $open = $scope->bool('open') || $scope->mode() === RenderMode::Editor;
        $body = $scope->childrenHtml();
        if (trim($body) === '' && $scope->showsDiagnostics()) {
            $body = '<p class="sb-accordion__empty">' . Html::e(Html::t('studio_accordion_row_empty', 'Add blocks to this row')) . '</p>';
        }
        return '<h3 class="sb-accordion__heading"><button type="button" class="sb-accordion__button" data-sb-accordion-button'
            . ' id="' . Html::e($id) . '" aria-controls="' . Html::e($id) . '-panel" aria-expanded="' . ($open ? 'true' : 'false') . '">'
            . Html::e($title) . '<span class="sb-accordion__icon" aria-hidden="true">▾</span></button></h3>'
            . '<div class="sb-accordion__panel" data-sb-accordion-panel id="' . Html::e($id) . '-panel" role="region" aria-labelledby="' . Html::e($id) . '"'
            . ($open ? '' : ' hidden') . '>' . $body . '</div>';
    }
}
