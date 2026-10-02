<?php
/**
 * Kohevo Studio — `core.modal` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class ModalRenderer implements BlockRendererInterface
{
    private const SIZES = ['sm', 'md', 'lg', 'full'];

    public function type(): string
    {
        return 'core.modal';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $rawId = $scope->string('modal_id', 'modal-1');
        $modalId = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawId) ?: 'modal-1';
        $title = $scope->string('title', 'Modal Title');
        $size = $scope->string('size', 'md');
        if (!in_array($size, self::SIZES, true)) {
            $size = 'md';
        }

        $triggerText = $scope->string('trigger_text');
        $triggerVariant = $scope->string('trigger_variant', 'primary');

        $html = '';
        if ($triggerText !== '') {
            $btnClasses = ['sb-button', 'sb-button--' . Html::e($triggerVariant)];
            $html .= '<a href="#' . Html::e($modalId) . '"' . Html::classAttr($btnClasses) . ' data-sb-modal-open="' . Html::e($modalId) . '">'
                . Html::e($triggerText)
                . '</a>';
        }

        $modalClasses = ['sb-modal', 'sb-modal--' . $size];
        $html .= '<div' . Html::classAttr($modalClasses) . ' id="' . Html::e($modalId) . '" role="dialog" aria-labelledby="' . Html::e($modalId) . '-title" aria-modal="true" aria-hidden="true">'
            . '<a href="#" class="sb-modal__backdrop" aria-label="Close"></a>'
            . '<div class="sb-modal__dialog">'
            . '<header class="sb-modal__header">'
            . '<h3 id="' . Html::e($modalId) . '-title">' . Html::e($title) . '</h3>'
            . '<a href="#" class="sb-modal__close" data-sb-modal-close aria-label="Close">&times;</a>'
            . '</header>'
            . '<div class="sb-modal__body">'
            . $scope->childrenHtml()
            . '</div>'
            . '</div>'
            . '</div>';

        return $html;
    }
}
