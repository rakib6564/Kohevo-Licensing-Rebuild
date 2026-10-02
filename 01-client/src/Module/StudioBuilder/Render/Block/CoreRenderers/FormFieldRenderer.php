<?php
/**
 * Kohevo Studio — `core.form_field` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class FormFieldRenderer implements BlockRendererInterface
{
    private const FIELD_TYPES = ['text', 'email', 'tel', 'number', 'url', 'textarea', 'select', 'checkbox', 'radio'];

    public function type(): string
    {
        return 'core.form_field';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $scope->string('name', 'field')) ?: 'field';
        $label = $scope->string('label', 'Field');
        $fieldType = $scope->string('field_type', 'text');
        if (!in_array($fieldType, self::FIELD_TYPES, true)) {
            $fieldType = 'text';
        }

        $placeholder = $scope->string('placeholder');
        $required = $scope->bool('required', false);
        $helpText = $scope->string('help_text');
        $reqAttr = $required ? ' required' : '';
        $inputId = 'sb_f_' . $name . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

        $html = '<div class="sb-form-group sb-form-group--' . Html::e($fieldType) . '">';

        if ($fieldType === 'checkbox' || $fieldType === 'radio') {
            $html .= '<label class="sb-form-check">';
            $html .= '<input type="' . $fieldType . '" name="' . Html::e($name) . '" id="' . Html::e($inputId) . '"' . $reqAttr . '> ';
            $html .= Html::e($label);
            if ($required) {
                $html .= ' <span class="sb-required" aria-hidden="true">*</span>';
            }
            $html .= '</label>';
        } else {
            $html .= '<label for="' . Html::e($inputId) . '">';
            $html .= Html::e($label);
            if ($required) {
                $html .= ' <span class="sb-required" aria-hidden="true">*</span>';
            }
            $html .= '</label>';

            if ($fieldType === 'textarea') {
                $html .= '<textarea name="' . Html::e($name) . '" id="' . Html::e($inputId) . '" placeholder="' . Html::e($placeholder) . '"' . $reqAttr . '></textarea>';
            } elseif ($fieldType === 'select') {
                $rawOptions = $scope->string('options');
                $opts = array_map('trim', explode(',', $rawOptions));
                $html .= '<select name="' . Html::e($name) . '" id="' . Html::e($inputId) . '"' . $reqAttr . '>';
                if ($placeholder !== '') {
                    $html .= '<option value="">' . Html::e($placeholder) . '</option>';
                }
                foreach ($opts as $opt) {
                    if ($opt !== '') {
                        $html .= '<option value="' . Html::e($opt) . '">' . Html::e($opt) . '</option>';
                    }
                }
                $html .= '</select>';
            } else {
                $html .= '<input type="' . $fieldType . '" name="' . Html::e($name) . '" id="' . Html::e($inputId) . '" placeholder="' . Html::e($placeholder) . '"' . $reqAttr . '>';
            }
        }

        if ($helpText !== '') {
            $html .= '<span class="sb-form-help">' . Html::e($helpText) . '</span>';
        }

        $html .= '</div>';

        return $html;
    }
}
