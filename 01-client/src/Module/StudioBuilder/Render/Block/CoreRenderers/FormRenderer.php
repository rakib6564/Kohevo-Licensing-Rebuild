<?php
/**
 * Kohevo Studio — `core.form` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class FormRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'core.form';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $action = $scope->string('action');
        $method = strtolower($scope->string('method', 'post')) === 'get' ? 'get' : 'post';
        $formName = $scope->string('form_name', 'contact_form');
        $submitText = $scope->string('submit_text', 'Submit');
        $submitVariant = $scope->string('submit_variant', 'primary');

        $html = '<form class="sb-form" action="' . Html::e($action) . '" method="' . $method . '" data-sb-form="' . Html::e($formName) . '">';
        $html .= '<input type="hidden" name="form_name" value="' . Html::e($formName) . '">';
        $html .= $scope->childrenHtml();
        $html .= '<div class="sb-form__actions">';
        $html .= '<button type="submit" class="sb-button sb-button--' . Html::e($submitVariant) . '">' . Html::e($submitText) . '</button>';
        $html .= '</div>';
        $html .= '</form>';

        return $html;
    }
}
