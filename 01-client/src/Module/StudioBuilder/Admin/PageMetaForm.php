<?php
/**
 * Kohevo Studio — the "Content type & custom fields" card of the page settings screen.
 *
 * `card()` returns the whole form card: a content type select and, for each type, the inputs of that type's meta fields
 * (one input kind per `MetaFieldTypes` key). Only the chosen type's group is shown and posted; the other groups are
 * disabled by the small script at the end, so their inputs never reach the server.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Admin;

use Slate\Module\StudioBuilder\Meta\MetaFieldTypes;

final class PageMetaForm
{
    /**
     * @param array<string, array<string, mixed>> $types       key => post type
     * @param array<string, list<array<string, mixed>>> $fields post type key => its fields
     * @param array<string, mixed> $values                      the page's saved values (for its own type)
     * @param array<string, string> $errors                     field key => message
     * @param array<string, array<int, string>> $relationPages  post type key => [page id => title]
     */
    public static function card(string $self, int $pageId, string $current, array $types, array $fields, array $values, array $errors, array $relationPages, bool $canEdit, string $typesUrl): string
    {
        $h = static fn(string $s): string => \e($s);
        $dis = $canEdit ? '' : ' disabled';
        $out = '<form method="post" class="card sb-form-card" id="sb-meta-form" data-testid="page-meta-form">' . \csrf_field()
            . '<input type="hidden" name="id" value="' . $pageId . '"><input type="hidden" name="action" value="save_meta">'
            . '<div class="card-header"><div><h2>' . $h(\__('studio_page_content_type', 'Content type & custom fields')) . '</h2>'
            . '<p class="sb-field__hint">' . $h(\__('studio_page_content_type_sub', 'Pick what this page is, then fill in that type\'s custom fields. Define fields under Types & fields.')) . '</p></div></div>';

        if (isset($errors['_type'])) {
            $out .= '<div class="sb-alert sb-alert--error">' . $h($errors['_type']) . '</div>';
        }
        $out .= '<div class="sb-form-grid"><div class="sb-field"><label class="sb-field__label" for="sb-ctype">' . $h(\__('studio_page_content_type_label', 'Content type')) . '</label>'
            . '<select id="sb-ctype" name="post_type"' . $dis . '><option value="">' . $h(\__('studio_page_no_type', 'None (a plain page)')) . '</option>';
        foreach ($types as $key => $t) {
            $out .= '<option value="' . $h((string) $key) . '"' . ($key === $current ? ' selected' : '') . '>' . $h((string) ($t['singular'] ?? $key)) . '</option>';
        }
        $out .= '</select></div></div>';

        foreach ($types as $key => $t) {
            $list = $fields[$key] ?? [];
            $out .= '<div class="sb-meta-group" data-type="' . $h((string) $key) . '"' . ($key === $current ? '' : ' hidden') . '>';
            if ($list === []) {
                $out .= '<p class="sb-field__hint">' . $h(\__('studio_page_no_fields', 'This type has no custom fields yet.')) . ' <a href="' . $h($typesUrl) . '">' . $h(\__('studio_types_fields', 'Types & fields')) . '</a></p>';
            } else {
                $out .= '<div class="sb-form-grid">';
                foreach ($list as $f) {
                    $fk = (string) ($f['key'] ?? '');
                    $id = 'sb-m-' . $key . '-' . $fk;
                    $wide = in_array(MetaFieldTypes::normalize((string) ($f['type'] ?? 'text')), ['textarea', 'richtext', 'gallery', 'list', 'keyvalue', 'checkboxes', 'multiselect', 'relations'], true);
                    $out .= '<div class="sb-field' . ($wide ? ' sb-field--wide' : '') . '"><label class="sb-field__label" for="' . $h($id) . '">' . $h((string) ($f['label'] ?? $fk))
                        . (!empty($f['required']) ? ' <span style="color:#ef4444;">*</span>' : '') . '</label>'
                        . self::input($f, $key === $current ? ($values[$fk] ?? null) : null, $id, $relationPages[(string) ($f['options']['target'] ?? '')] ?? [], $canEdit);
                    if (!empty($f['help'])) {
                        $out .= '<p class="sb-field__hint">' . $h((string) $f['help']) . '</p>';
                    }
                    if (isset($errors[$fk]) && $key === $current) {
                        $out .= '<p class="sb-field__hint" style="color:#b91c1c;">' . $h($errors[$fk]) . '</p>';
                    }
                    $out .= '</div>';
                }
                $out .= '</div>';
            }
            $out .= '</div>';
        }
        if ($canEdit) {
            $out .= '<div class="sb-form-actions"><button type="submit" class="btn btn-primary"><span>' . $h(\__('save', 'Save changes')) . '</span></button></div>';
        }
        $out .= '</form><script>(function(){var f=document.getElementById("sb-meta-form");if(!f)return;var s=f.querySelector("#sb-ctype");'
            . 'function sync(){f.querySelectorAll(".sb-meta-group").forEach(function(g){var on=g.getAttribute("data-type")===s.value;g.hidden=!on;'
            . 'g.querySelectorAll("input,select,textarea").forEach(function(i){i.disabled=!on||' . ($canEdit ? 'false' : 'true') . ';});});}'
            . 's.addEventListener("change",sync);sync();})();</script>';
        return $out;
    }

    /**
     * One input for one field.
     *
     * @param array<string, mixed> $f
     * @param array<int, string> $pages page id => title, for a relation
     */
    public static function input(array $f, mixed $value, string $id, array $pages, bool $canEdit): string
    {
        $h = static fn(mixed $s): string => \e(is_scalar($s) ? (string) $s : '');
        $type = MetaFieldTypes::normalize((string) ($f['type'] ?? 'text'));
        $name = 'meta[' . (string) ($f['key'] ?? '') . ']';
        $opt = is_array($f['options'] ?? null) ? $f['options'] : [];
        $ph = ' placeholder="' . $h($f['placeholder'] ?? '') . '"';
        $req = !empty($f['required']) && !in_array($type, ['boolean', 'multiselect', 'checkboxes', 'gallery', 'list', 'relations', 'keyvalue', 'daterange'], true) ? ' required' : '';
        $dis = $canEdit ? '' : ' disabled';
        $base = ' id="' . $h($id) . '" name="' . $h($name) . '"' . $req . $dis;
        $attr = '';
        foreach (['min', 'max', 'step'] as $k) {
            if (isset($opt[$k])) {
                $attr .= ' ' . $k . '="' . $h($opt[$k]) . '"';
            }
        }
        $arr = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
        $lines = static fn(array $a): string => implode("\n", array_map(static fn($x): string => is_scalar($x) ? (string) $x : '', $a));
        $choices = is_array($opt['choices'] ?? null) ? $opt['choices'] : [];

        switch ($type) {
            case 'textarea':
            case 'richtext':
                return '<textarea' . $base . ' rows="' . ($type === 'richtext' ? 6 : 4) . '"' . $ph . (isset($opt['max_length']) ? ' maxlength="' . (int) $opt['max_length'] . '"' : '') . '>' . $h($value) . '</textarea>';
            case 'number':
            case 'price':
            case 'rating':
                return '<input type="number" step="' . $h($opt['step'] ?? ($type === 'price' ? '0.01' : 'any')) . '"' . $base . $attr . $ph . ' value="' . $h($value) . '">';
            case 'range':
                return '<input type="range"' . $base . $attr . ' value="' . $h($value ?? ($opt['min'] ?? 0)) . '">';
            case 'select':
                $o = '<select' . $base . '><option value="">' . $h(\__('studio_choose', 'Choose…')) . '</option>';
                foreach ($choices as $c) {
                    $o .= '<option value="' . $h($c['value'] ?? '') . '"' . ((string) ($c['value'] ?? '') === (string) $value ? ' selected' : '') . '>' . $h($c['label'] ?? $c['value'] ?? '') . '</option>';
                }
                return $o . '</select>';
            case 'radio':
                $o = '';
                foreach ($choices as $i => $c) {
                    $o .= '<label class="sb-check"><input type="radio" name="' . $h($name) . '" value="' . $h($c['value'] ?? '') . '"' . ((string) ($c['value'] ?? '') === (string) $value ? ' checked' : '') . $dis . '> ' . $h($c['label'] ?? $c['value'] ?? '') . '</label> ';
                }
                return '<div id="' . $h($id) . '">' . $o . '</div>';
            case 'multiselect':
                $o = '<select multiple size="' . min(8, max(3, count($choices))) . '" id="' . $h($id) . '" name="' . $h($name) . '[]"' . $dis . '>';
                foreach ($choices as $c) {
                    $o .= '<option value="' . $h($c['value'] ?? '') . '"' . (in_array((string) ($c['value'] ?? ''), array_map('strval', $arr), true) ? ' selected' : '') . '>' . $h($c['label'] ?? $c['value'] ?? '') . '</option>';
                }
                return $o . '</select>';
            case 'checkboxes':
                $o = '';
                foreach ($choices as $c) {
                    $o .= '<label class="sb-check"><input type="checkbox" name="' . $h($name) . '[]" value="' . $h($c['value'] ?? '') . '"' . (in_array((string) ($c['value'] ?? ''), array_map('strval', $arr), true) ? ' checked' : '') . $dis . '> ' . $h($c['label'] ?? $c['value'] ?? '') . '</label> ';
                }
                return '<div id="' . $h($id) . '">' . $o . '</div>';
            case 'boolean':
                return '<label class="sb-check"><input type="checkbox" id="' . $h($id) . '" name="' . $h($name) . '" value="1"' . ($value ? ' checked' : '') . $dis . '> ' . $h(\__('studio_yes', 'Yes')) . '</label>';
            case 'date':
            case 'time':
                return '<input type="' . $type . '"' . $base . ' value="' . $h($value) . '">';
            case 'datetime':
                return '<input type="datetime-local"' . $base . ' value="' . $h($value) . '">';
            case 'daterange':
                $a = is_array($value) ? ($value['start'] ?? '') : '';
                $b = is_array($value) ? ($value['end'] ?? '') : '';
                return '<div class="sb-two-up"><input type="date" id="' . $h($id) . '" name="' . $h($name) . '[start]" value="' . $h($a) . '"' . $dis . '><input type="date" name="' . $h($name) . '[end]" value="' . $h($b) . '"' . $dis . '></div>';
            case 'color':
                return '<input type="color"' . $base . ' value="' . $h(is_string($value) && $value !== '' ? $value : '#000000') . '">';
            case 'email':
            case 'url':
                return '<input type="' . $type . '"' . $base . $ph . ' value="' . $h($value) . '">';
            case 'phone':
                return '<input type="tel"' . $base . $ph . ' value="' . $h($value) . '">';
            case 'relation':
                $o = '<select' . $base . '><option value="">' . $h(\__('studio_choose', 'Choose…')) . '</option>';
                foreach ($pages as $pid => $title) {
                    $o .= '<option value="' . (int) $pid . '"' . ((string) $pid === (string) $value ? ' selected' : '') . '>' . $h($title) . '</option>';
                }
                return $o . '</select>';
            case 'relations':
                $o = '<select multiple size="' . min(8, max(3, count($pages))) . '" id="' . $h($id) . '" name="' . $h($name) . '[]"' . $dis . '>';
                foreach ($pages as $pid => $title) {
                    $o .= '<option value="' . (int) $pid . '"' . (in_array((string) $pid, array_map('strval', $arr), true) ? ' selected' : '') . '>' . $h($title) . '</option>';
                }
                return $o . '</select>';
            case 'gallery':
            case 'list':
                return '<textarea' . $base . ' rows="4"' . ($type === 'gallery' ? ' placeholder="https://…"' : $ph) . '>' . $h($lines($arr)) . '</textarea>';
            case 'keyvalue':
                $pairs = [];
                foreach (is_array($value) ? $value : [] as $k => $v) {
                    $pairs[] = $k . ' : ' . (is_scalar($v) ? $v : '');
                }
                return '<textarea' . $base . ' rows="4" placeholder="key : value">' . $h(implode("\n", $pairs)) . '</textarea>';
            default: // text, url-like fallbacks, image, file, video, location
                return '<input type="text"' . $base . $ph . (isset($opt['max_length']) ? ' maxlength="' . (int) $opt['max_length'] . '"' : '') . ' value="' . $h($value) . '">';
        }
    }
}
