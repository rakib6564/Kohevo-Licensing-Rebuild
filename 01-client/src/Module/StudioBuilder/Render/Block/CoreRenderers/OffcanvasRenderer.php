<?php
/**
 * Kohevo Studio — `layout.offcanvas` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class OffcanvasRenderer implements BlockRendererInterface
{
    private const POSITIONS = ['left', 'right', 'top', 'bottom'];

    public function type(): string
    {
        return 'layout.offcanvas';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $rawId = $scope->string('drawer_id', 'drawer-1');
        $drawerId = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawId) ?: 'drawer-1';
        $title = $scope->string('title', 'Menu');
        $position = $scope->string('position', 'right');
        if (!in_array($position, self::POSITIONS, true)) {
            $position = 'right';
        }

        $triggerText = $scope->string('trigger_text');
        $triggerVariant = $scope->string('trigger_variant', 'outline');

        $html = '';
        if ($triggerText !== '') {
            $btnClasses = ['sb-button', 'sb-button--' . Html::e($triggerVariant)];
            $html .= '<a href="#' . Html::e($drawerId) . '"' . Html::classAttr($btnClasses) . ' data-sb-offcanvas-open="' . Html::e($drawerId) . '">'
                . Html::e($triggerText)
                . '</a>';
        }

        $offcanvasClasses = ['sb-offcanvas', 'sb-offcanvas--' . $position];
        $html .= '<div' . Html::classAttr($offcanvasClasses) . ' id="' . Html::e($drawerId) . '" role="dialog" aria-labelledby="' . Html::e($drawerId) . '-title" aria-modal="true" aria-hidden="true">'
            . '<a href="#" class="sb-offcanvas__backdrop" data-sb-offcanvas-close aria-label="Close"></a>'
            . '<aside class="sb-offcanvas__panel">'
            . '<header class="sb-offcanvas__header">'
            . '<h3 id="' . Html::e($drawerId) . '-title">' . Html::e($title) . '</h3>'
            . '<a href="#" class="sb-offcanvas__close" data-sb-offcanvas-close aria-label="Close">&times;</a>'
            . '</header>'
            . '<div class="sb-offcanvas__body">'
            . $scope->childrenHtml()
            . '</div>'
            . '</aside>'
            . '</div>';

        return $html;
    }
}
