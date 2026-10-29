<?php
/**
 * Kohevo Studio — `core.form_field` renderer.
 *
 * One input of a Studio form. Radio buttons and several checkboxes are a group built from the field's options; one
 * checkbox is a single tick (an acceptance box). The field's width is its share of the form's row, written on the block's
 * wrapper (the form's flex item) per device: two halves sit side by side, and a phone stacks them unless set per device.
 *
 * A field can be filled from the page address (`url_param`, e.g. `reason`, or several: `activity, subject`), with
 * `url_values` turning a value into its option or readable text (`cours=Question sur un cours`). The page is cached, so
 * the runtime does the filling from the data attributes written here. A list field (select, radio) only ever takes one of
 * its own options, so an unknown value keeps its default; a text field takes the values, still editable.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Document\DeviceProps;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\WrapperClassesInterface;
use Slate\Module\StudioBuilder\Render\Block\WrapperStyleInterface;
use Slate\Module\StudioBuilder\Render\Html;

final class FormFieldRenderer implements BlockRendererInterface, WrapperClassesInterface, WrapperStyleInterface
{
    public const FIELD_TYPES = ['text', 'email', 'tel', 'number', 'url', 'textarea', 'select', 'checkbox', 'radio', 'checkboxes', 'date', 'time', 'file', 'hidden'];

    /** Width choice => share of the row. */
    private const WIDTHS = ['100' => '1', '75' => '.75', '66' => '.6667', '50' => '.5', '33' => '.3333', '25' => '.25'];

    public function type(): string
    {
        return 'core.form_field';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    /** The field name Forms stores the answer under: lower-case letters, digits and underscores, starting with a letter. */
    public static function fieldName(string $raw): string
    {
        $name = strtolower((string) preg_replace('/[^a-zA-Z0-9_]/', '_', $raw));
        $name = trim((string) preg_replace('/_+/', '_', $name), '_');
        if ($name === '' || !ctype_alpha($name[0])) {
            $name = 'field_' . $name;
        }
        return substr(rtrim($name, '_'), 0, 50);
    }

    /**
     * The address fill of a field: its parameter names and its value map (lower-cased value => text), or null when it
     * has none. For a list field the map keeps only values that name one of its options.
     *
     * @param list<string>|null $options the field's options, for a list field
     * @return array{params: list<string>, values: array<string, string>}|null
     */
    public static function urlFill(string $params, string $values, ?array $options): ?array
    {
        $names = array_values(array_unique(array_filter(array_map('trim', explode(',', $params)), static fn(string $p): bool => preg_match('/^[A-Za-z0-9_-]{1,40}$/', $p) === 1)));
        if ($names === []) {
            return null;
        }
        $map = [];
        foreach (preg_split('/\R/', $values) ?: [] as $line) {
            $at = strpos($line, '=');
            if ($at === false) {
                continue;
            }
            $key = strtolower(trim(substr($line, 0, $at)));
            $text = trim(substr($line, $at + 1));
            if ($key === '' || $text === '' || ($options !== null && !in_array($text, $options, true))) {
                continue;
            }
            $map[$key] = $text;
        }
        if ($options !== null && $map === []) {
            return null;
        }
        return ['params' => $names, 'values' => $map];
    }

    /** The data attributes the runtime fills a field from (none without an address fill). */
    private static function urlFillAttributes(BlockRenderScope $scope, ?array $options): string
    {
        $fill = self::urlFill($scope->string('url_param'), $scope->string('url_values'), $options);
        if ($fill === null) {
            return '';
        }
        return ' data-sb-url-param="' . Html::e(implode(',', $fill['params'])) . '"'
            . ($fill['values'] !== [] ? ' data-sb-url-values="' . Html::e((string) json_encode($fill['values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"' : '');
    }

    /** @return list<string> the options, one per comma or line */
    public static function options(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,\n]/', $raw) ?: []), static fn(string $o): bool => $o !== ''));
    }

    public function wrapperClasses(BlockRenderScope $scope): array
    {
        return $scope->string('field_type') === 'hidden' ? ['sb-form-hidden'] : ['sb-fw'];
    }

    public function wrapperStyle(BlockRenderScope $scope): string
    {
        $desktop = self::WIDTHS[$scope->string('width', '100')] ?? '1';
        $over = DeviceProps::overrides($scope->block(), 'width');
        $tablet = self::WIDTHS[(string) ($over['tablet'] ?? '')] ?? $desktop;
        $mobile = self::WIDTHS[(string) ($over['mobile'] ?? '')] ?? '1';
        return '--sb-fw-d:' . $desktop . ';--sb-fw-t:' . $tablet . ';--sb-fw-m:' . $mobile;
    }

    public function render(BlockRenderScope $scope): string
    {
        $name = self::fieldName($scope->string('name', 'field'));
        $label = $scope->string('label', 'Field');
        $fieldType = $scope->string('field_type', 'text');
        if (!in_array($fieldType, self::FIELD_TYPES, true)) {
            $fieldType = 'text';
        }

        $placeholder = $scope->string('placeholder');
        $required = $scope->bool('required', false);
        $helpText = $scope->string('help_text');
        $default = $scope->string('default_value');
        $reqAttr = $required ? ' required' : '';
        // Derived from the block's node id, so the same document always renders the same markup.
        $inputId = 'sb_f_' . $name . '_' . substr(hash('sha256', $scope->domId()), 0, 8);
        $helpId = $inputId . '_help';
        $describedBy = $helpText !== '' ? ' aria-describedby="' . Html::e($helpId) . '"' : '';
        $mark = $required ? ' <span class="sb-required" aria-hidden="true">*</span>' : '';

        if ($fieldType === 'hidden') {
            return '<input type="hidden" name="' . Html::e($name) . '" value="' . Html::e($default) . '"' . self::urlFillAttributes($scope, null) . '>';
        }

        $html = '<div class="sb-form-group sb-form-group--' . Html::e($fieldType) . '" data-sb-field="' . Html::e($name) . '">';

        if ($fieldType === 'checkbox') {
            // One tick: an acceptance box ("I agree…"), its label beside it.
            $html .= '<label class="sb-form-check">'
                . '<input type="checkbox" name="' . Html::e($name) . '" id="' . Html::e($inputId) . '" value="1"' . $reqAttr . $describedBy . '> '
                . '<span>' . Html::e($label) . $mark . '</span></label>';
        } elseif ($fieldType === 'radio' || $fieldType === 'checkboxes') {
            $type = $fieldType === 'radio' ? 'radio' : 'checkbox';
            $html .= '<fieldset class="sb-form-choices"' . $describedBy . ($type === 'radio' ? self::urlFillAttributes($scope, self::options($scope->string('options'))) : '') . '><legend>' . Html::e($label) . $mark . '</legend>';
            foreach (self::options($scope->string('options')) as $i => $opt) {
                // A radio group is required as a whole (any one input carrying `required` does that); several checkboxes
                // are checked by Forms, as the browser cannot ask for "at least one".
                $html .= '<label class="sb-form-check">'
                    . '<input type="' . $type . '" name="' . Html::e($name) . ($type === 'checkbox' ? '[]' : '') . '" id="' . Html::e($inputId . '_' . $i) . '" value="' . Html::e($opt) . '"'
                    . ($opt === $default ? ' checked' : '') . ($type === 'radio' ? $reqAttr : '') . '> '
                    . '<span>' . Html::e($opt) . '</span></label>';
            }
            $html .= '</fieldset>';
        } else {
            $html .= '<label for="' . Html::e($inputId) . '">' . Html::e($label) . $mark . '</label>';
            $common = ' name="' . Html::e($name) . '" id="' . Html::e($inputId) . '"' . $reqAttr . $describedBy
                . (in_array($fieldType, ['file', 'number', 'date', 'time'], true) ? '' : self::urlFillAttributes($scope, $fieldType === 'select' ? self::options($scope->string('options')) : null));
            if ($fieldType === 'textarea') {
                $html .= '<textarea' . $common . ' placeholder="' . Html::e($placeholder) . '">' . Html::e($default) . '</textarea>';
            } elseif ($fieldType === 'select') {
                $html .= '<select' . $common . '>';
                if ($placeholder !== '') {
                    $html .= '<option value="">' . Html::e($placeholder) . '</option>';
                }
                foreach (self::options($scope->string('options')) as $opt) {
                    $html .= '<option value="' . Html::e($opt) . '"' . ($opt === $default ? ' selected' : '') . '>' . Html::e($opt) . '</option>';
                }
                $html .= '</select>';
            } elseif ($fieldType === 'file') {
                $html .= '<input type="file"' . $common . ' accept="image/*,application/pdf,.doc,.docx,.txt">';
            } else {
                $autocomplete = ['email' => 'email', 'tel' => 'tel', 'url' => 'url'][$fieldType] ?? '';
                $html .= '<input type="' . $fieldType . '"' . $common . ' placeholder="' . Html::e($placeholder) . '"'
                    . ($default !== '' ? ' value="' . Html::e($default) . '"' : '')
                    . ($autocomplete !== '' ? ' autocomplete="' . $autocomplete . '"' : '') . '>';
            }
        }

        if ($helpText !== '') {
            $html .= '<span class="sb-form-help" id="' . Html::e($helpId) . '">' . Html::e($helpText) . '</span>';
        }
        // Where the runtime writes this field's error from the server, read out with the field.
        $html .= '<span class="sb-form-error" data-sb-field-error hidden></span>';
        $html .= '</div>';

        return $html;
    }
}
