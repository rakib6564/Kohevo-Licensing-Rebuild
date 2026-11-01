<?php
/**
 * Kohevo Studio — the repeating rows of a post type's edit screen: one meta field, one admin column, one admin filter.
 *
 * Each row is also printed once as a `<template>` with `__i__` as its index; the screen's script clones it for Add and
 * Duplicate. The posted order is the order on screen, so reordering a row needs no bookkeeping.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Admin;

use Slate\Module\StudioBuilder\Meta\MetaFieldTypes;

final class PostTypeForm
{
    public const INDEX = '__i__';

    /** The grip, move, duplicate and delete buttons of a row. */
    private static function rowTools(bool $duplicate = true): string
    {
        $button = static fn(string $act, string $icon, string $label): string => '<button type="button" class="btn btn-sm btn-ghost" data-row-act="' . $act . '" title="' . \e($label) . '" aria-label="' . \e($label) . '">' . $icon . '</button>';
        return '<span class="sb-row-tools">'
            . $button('up', '↑', \__('studio_tf_move_up', 'Move up'))
            . $button('down', '↓', \__('studio_tf_move_down', 'Move down'))
            . ($duplicate ? $button('duplicate', '⧉', \__('studio_tf_duplicate', 'Duplicate')) : '')
            . $button('delete', '✕', \__('delete', 'Delete'))
            . '</span>';
    }

    private static function field(string $label, string $control, string $hint = '', string $extra = ''): string
    {
        return '<label class="sb-field' . ($extra !== '' ? ' ' . $extra : '') . '"><span class="sb-field__label">' . \e($label) . '</span>' . $control
            . ($hint !== '' ? '<span class="sb-field__hint">' . $hint . '</span>' : '') . '</label>';
    }

    /**
     * One meta field as an accordion row: its label, key and type on the closed row, everything else inside.
     *
     * @param array<string, mixed>  $f
     * @param array<string, string> $typeOptions post type key => name, for a relation
     */
    public static function fieldRow(int|string $i, array $f, array $typeOptions, bool $open = false): string
    {
        $h = static fn(mixed $s): string => \e(is_scalar($s) ? (string) $s : '');
        $name = static fn(string $k): string => 'fields[' . $i . '][' . $k . ']';
        $type = MetaFieldTypes::normalize((string) ($f['type'] ?? 'text'));
        $opt = is_array($f['options'] ?? null) ? $f['options'] : [];
        $choices = implode("\n", array_map(static fn(array $c): string => (string) ($c['value'] ?? '') . ' : ' . (string) ($c['label'] ?? ''), is_array($opt['choices'] ?? null) ? $opt['choices'] : []));

        $subfields = implode("\n", array_map(static fn(array $s): string => (string) ($s['key'] ?? '') . ' : ' . (string) ($s['label'] ?? '') . ' : ' . (string) ($s['type'] ?? 'text'), MetaFieldTypes::subfields($f)));

        $select = '<select name="' . $name('type') . '" data-field-type>';
        foreach (MetaFieldTypes::GROUPS as $group => $groupLabel) {
            $select .= '<optgroup label="' . $h($groupLabel) . '">';
            foreach (MetaFieldTypes::all() as $tk => $def) {
                if ($def['group'] === $group) {
                    $select .= '<option value="' . $h($tk) . '" data-options="' . $h(implode(' ', $def['options'])) . '"' . ($tk === $type ? ' selected' : '') . '>' . $h($def['label']) . '</option>';
                }
            }
            $select .= '</optgroup>';
        }
        $select .= '</select>';

        $target = '<select name="' . $name('target') . '">';
        foreach ($typeOptions as $tk => $tName) {
            $target .= '<option value="' . $h($tk) . '"' . ((string) ($opt['target'] ?? '') === (string) $tk ? ' selected' : '') . '>' . $h($tName) . '</option>';
        }
        $target .= '</select>';

        $number = static fn(string $k, string $attr = 'step="any"'): string => '<input type="number" ' . $attr . ' name="' . $name($k) . '" value="' . $h($opt[$k] ?? '') . '">';
        $options = '<div class="sb-form-grid sb-field-options" data-field-options>'
            . self::field(\__('studio_tf_choices', 'Choices'), '<textarea name="' . $name('choices') . '" rows="4">' . $h($choices) . '</textarea>', sprintf(\e(\__('studio_tf_choices_hint', 'One per line, as %1$s or %2$s.')), '<code>value</code>', '<code>value : Label</code>'), 'sb-field--wide" data-opt="choices')
            . self::field(\__('studio_tf_subfields', 'Sub-fields'), '<textarea name="' . $name('subfields') . '" rows="4">' . $h($subfields) . '</textarea>', \e(\__('studio_tf_subfields_hint', 'One per line, as key : Label : type. Types: text, textarea, richtext, image, url, number.')), 'sb-field--wide" data-opt="subfields')
            . self::field(\__('studio_tf_links_to', 'Links to'), $target, '', '" data-opt="target')
            . self::field(\__('studio_tf_min', 'Minimum'), $number('min'), '', '" data-opt="min')
            . self::field(\__('studio_tf_max', 'Maximum'), $number('max'), '', '" data-opt="max')
            . self::field(\__('studio_tf_step', 'Step'), $number('step'), '', '" data-opt="step')
            . self::field(\__('studio_tf_max_length', 'Maximum length'), $number('max_length', 'min="1" max="65535"'), '', '" data-opt="max_length')
            . self::field(\__('studio_tf_extensions', 'Allowed file types'), '<input type="text" name="' . $name('extensions') . '" value="' . $h(is_array($opt['extensions'] ?? null) ? implode(', ', $opt['extensions']) : ($opt['extensions'] ?? '')) . '" placeholder="pdf, docx, zip">', '', '" data-opt="extensions')
            . '</div>';

        return '<details class="sb-acc" data-row data-testid="meta-field-row"' . ($open ? ' open' : '') . '>'
            . '<summary class="sb-acc__head" draggable="true"><span class="sb-acc__grip" aria-hidden="true">⋮⋮</span>'
            . '<strong data-show="label">' . $h($f['label'] ?? '') . '</strong> <code data-show="key">' . $h($f['key'] ?? '') . '</code> <span class="sb-acc__type" data-show="type">' . $h(MetaFieldTypes::label($type)) . '</span>'
            . self::rowTools() . '</summary>'
            . '<div class="sb-acc__body"><input type="hidden" name="' . $name('id') . '" value="' . $h($f['id'] ?? '') . '">'
            . '<div class="sb-form-grid">'
            . self::field(\__('studio_tf_field_label', 'Field Label'), '<input type="text" name="' . $name('label') . '" maxlength="120" value="' . $h($f['label'] ?? '') . '" data-mirror="label" data-slug-source>')
            . self::field(\__('studio_tf_field_key', 'Field Key'), '<input type="text" name="' . $name('key') . '" maxlength="64" value="' . $h($f['key'] ?? '') . '" data-mirror="key" data-slug-target>', \e(\__('studio_tf_field_key_hint', 'Renaming a key keeps the values saved under it.')))
            . self::field(\__('studio_tf_field_type', 'Field Type'), $select)
            . self::field(\__('studio_tf_placeholder', 'Placeholder / Default'), '<input type="text" name="' . $name('placeholder') . '" maxlength="255" value="' . $h($f['placeholder'] ?? '') . '">')
            . self::field(\__('studio_tf_help', 'Help text'), '<input type="text" name="' . $name('help') . '" maxlength="300" value="' . $h($f['help'] ?? '') . '">', '', 'sb-field--wide')
            . '</div>' . $options
            . '<label class="sb-check"><input type="checkbox" name="' . $name('required') . '" value="1"' . (!empty($f['required']) ? ' checked' : '') . '> ' . $h(\__('studio_tf_required_field', 'Required field')) . '</label>'
            . '</div></details>';
    }

    /** @param array<string, mixed> $c */
    public static function columnRow(int|string $i, array $c): string
    {
        $h = static fn(mixed $s): string => \e(is_scalar($s) ? (string) $s : '');
        $name = static fn(string $k): string => 'columns[' . $i . '][' . $k . ']';
        return '<div class="sb-line" data-row data-testid="admin-column-row">'
            . self::field(\__('studio_tf_col_label', 'Column title'), '<input type="text" name="' . $name('label') . '" maxlength="80" value="' . $h($c['label'] ?? '') . '">')
            . self::field(\__('studio_tf_col_source', 'Shows'), '<input type="text" list="sb-col-sources" name="' . $name('source') . '" value="' . $h($c['source'] ?? '') . '">')
            . self::field(\__('studio_tf_col_prefix', 'Before the value'), '<input type="text" name="' . $name('prefix') . '" maxlength="20" value="' . $h($c['prefix'] ?? '') . '">')
            . self::field(\__('studio_tf_col_suffix', 'After the value'), '<input type="text" name="' . $name('suffix') . '" maxlength="20" value="' . $h($c['suffix'] ?? '') . '">')
            . '<label class="sb-check"><input type="checkbox" name="' . $name('sortable') . '" value="1"' . (!empty($c['sortable']) ? ' checked' : '') . '> ' . $h(\__('studio_tf_col_sortable', 'Sortable')) . '</label>'
            . self::rowTools(false) . '</div>';
    }

    /** @param array<string, mixed> $c */
    public static function filterRow(int|string $i, array $c): string
    {
        $h = static fn(mixed $s): string => \e(is_scalar($s) ? (string) $s : '');
        $name = static fn(string $k): string => 'filters[' . $i . '][' . $k . ']';
        return '<div class="sb-line" data-row data-testid="admin-filter-row">'
            . self::field(\__('studio_tf_filter_label', 'Filter title'), '<input type="text" name="' . $name('label') . '" maxlength="80" value="' . $h($c['label'] ?? '') . '">')
            . self::field(\__('studio_tf_filter_source', 'Meta field'), '<input type="text" list="sb-filter-sources" name="' . $name('source') . '" value="' . $h($c['source'] ?? '') . '">')
            . self::rowTools(false) . '</div>';
    }

    /** The script of the edit screen: add, duplicate, delete, reorder, and each field type's own options. */
    public static function script(): string
    {
        return <<<'HTML'
<script>
(function () {
    var form = document.getElementById('sb-type-form');
    if (!form) return;
    var counter = 1000;
    function fresh(html) { counter += 1; return html.split('__i__').join('n' + counter); }
    function syncOptions(row) {
        var sel = row.querySelector('[data-field-type]');
        var box = row.querySelector('[data-field-options]');
        if (!sel || !box) return;
        var opt = sel.options[sel.selectedIndex];
        var wanted = (opt.getAttribute('data-options') || '').split(' ');
        box.querySelectorAll('[data-opt]').forEach(function (el) {
            var on = wanted.indexOf(el.getAttribute('data-opt')) !== -1;
            el.hidden = !on;
            el.querySelectorAll('input,select,textarea').forEach(function (c) { c.disabled = !on; });
        });
        box.hidden = !box.querySelector('[data-opt]:not([hidden])');
        var show = row.querySelector('[data-show="type"]');
        if (show) show.textContent = opt.textContent;
    }
    function insert(html, list, after) {
        var holder = document.createElement('div');
        holder.innerHTML = fresh(html);
        var row = holder.firstElementChild;
        if (after) after.after(row); else list.appendChild(row);
        syncOptions(row);
        return row;
    }
    form.querySelectorAll('[data-add]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var name = btn.getAttribute('data-add');
            var row = insert(document.getElementById('sb-tpl-' + name).innerHTML, document.getElementById('sb-list-' + name));
            var first = row.querySelector('input[type="text"]');
            if (first) first.focus();
        });
    });
    form.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-row-act]');
        if (!btn) return;
        e.preventDefault();
        var row = btn.closest('[data-row]');
        var act = btn.getAttribute('data-row-act');
        if (act === 'delete') {
            row.remove();
        } else if (act === 'up' && row.previousElementSibling) {
            row.previousElementSibling.before(row);
        } else if (act === 'down' && row.nextElementSibling) {
            row.nextElementSibling.after(row);
        } else if (act === 'duplicate') {
            var old = (row.querySelector('input[name$="[label]"]').name.match(/\[([^\]]+)\]\[label\]/) || [])[1];
            var values = [];
            row.querySelectorAll('input,select,textarea').forEach(function (c) { values.push(c.type === 'checkbox' ? c.checked : c.value); });
            var copy = insert(row.outerHTML.split('[' + old + ']').join('[__i__]'), row.parentNode, row);
            copy.querySelectorAll('input,select,textarea').forEach(function (c, n) {
                if (c.type === 'checkbox') c.checked = values[n]; else c.value = values[n];
            });
            copy.querySelector('input[name$="[id]"]').value = '';
            var key = copy.querySelector('input[name$="[key]"]');
            key.value += '_copy';
            copy.querySelector('[data-show="key"]').textContent = key.value;
            copy.open = true;
        }
    });
    form.addEventListener('input', function (e) {
        var t = e.target;
        var row = t.closest('[data-row]');
        if (!row) return;
        var mirror = t.getAttribute('data-mirror');
        if (mirror) row.querySelector('[data-show="' + mirror + '"]').textContent = t.value;
        if (t.hasAttribute('data-slug-source')) {
            var target = row.querySelector('[data-slug-target]');
            if (target && (target.value === '' || target.dataset.auto === '1')) {
                target.dataset.auto = '1';
                target.value = t.value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
                row.querySelector('[data-show="key"]').textContent = target.value;
            }
        }
        if (t.hasAttribute('data-slug-target')) t.dataset.auto = '';
    });
    form.addEventListener('change', function (e) {
        if (e.target.hasAttribute('data-field-type')) syncOptions(e.target.closest('[data-row]'));
    });
    // Drag a field by its closed row; the arrows do the same from the keyboard.
    var dragged = null;
    form.addEventListener('dragstart', function (e) {
        var row = e.target.closest ? e.target.closest('details[data-row]') : null;
        dragged = row;
        if (row) { e.dataTransfer.effectAllowed = 'move'; row.classList.add('is-dragging'); }
    });
    form.addEventListener('dragover', function (e) {
        var over = e.target.closest ? e.target.closest('details[data-row]') : null;
        if (!dragged || !over || over === dragged) return;
        e.preventDefault();
        var box = over.getBoundingClientRect();
        if (e.clientY < box.top + box.height / 2) over.before(dragged); else over.after(dragged);
    });
    form.addEventListener('dragend', function () {
        if (dragged) dragged.classList.remove('is-dragging');
        dragged = null;
    });
    form.querySelectorAll('details[data-row]').forEach(syncOptions);
})();
</script>
HTML;
    }
}
