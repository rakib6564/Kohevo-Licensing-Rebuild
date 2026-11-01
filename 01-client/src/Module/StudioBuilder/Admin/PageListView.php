<?php
/**
 * Kohevo Studio — the admin "Studio pages" list, rendered with the shared
 * Kohevo expandable data-row component (slate_data_row / .data-list).
 *
 * Presentation only: it receives the page views StudioApplicationService
 * already produced and the two resolved URLs, so it carries no query, no
 * permission decision and no Studio state. The markup, classes, chevron and
 * expand/collapse script are the platform's own (includes/ui_components.php);
 * Studio adds none.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Admin;

final class PageListView
{
    /**
     * @param list<array<string, mixed>> $pages   StudioEditorViews::page() rows
     * @param bool                       $canEdit whether "Open builder" is offered
     * @param bool                       $canDelete whether the selection bar offers Delete (the list sits inside a POST form that carries `ids[]`)
     * @param string                     $settingsUrl the page-settings screen (`?id=` is appended); empty offers no link
     * @param array<int, string>         $typeLabels page id => its content type's name: the row shows that name as its
     *                                               type and offers Fields (the settings screen's content-type card)
     * @param list<array{title: string, source: string, prefix: string, suffix: string, sortable: bool, field: ?array<string, mixed>}> $columns a content type's admin columns (`columns()`)
     * @param array<int, array<string, mixed>> $values page id => field values, for those columns
     * @param array{base?: array<string, mixed>, key?: string, dir?: string} $sort the list's query and current sort, for the column heads
     * @return string the .data-list markup (no script tag; see slate_data_list_script())
     */
    public static function render(array $pages, bool $canEdit, string $builderUrl, string $previewUrl, bool $canDelete = false, string $settingsUrl = '', array $typeLabels = [], array $columns = [], array $values = [], array $sort = []): string
    {
        if (!function_exists('slate_data_row')) {
            require_once dirname(__DIR__, 4) . '/includes/ui_components.php';
        }

        if ($pages === []) {
            return '<div class="data-list" data-single-open></div>';
        }

        ob_start();
        echo '<div class="data-list is-columnar" data-single-open id="studio-page-list">';
        echo self::selectBar($canDelete, 'delete', \__('studio_delete_pages', 'Delete'), \__('studio_delete_pages_confirm', 'Delete %d selected page(s)? They leave your site and this list. Their history is kept.'));
        \slate_data_list_head(
            ['main' => \__('title', 'Title'), 'value' => \__('studio_page_type', 'Type'), 'badge' => \__('status', 'Status'), 'cells_html' => array_map(static fn(array $c): string => self::columnHead($c, $sort), $columns)],
            ['select_all' => \__('studio_select_all_pages', 'Select all pages')]
        );
        foreach ($pages as $p) {
            $id          = (int) $p['id'];
            $published   = !empty($p['is_published']);
            $hasChanges  = $published && !empty($p['has_unpublished_changes']);
            $title       = (string) $p['title'];
            $scheduled   = ($p['status'] ?? '') === 'scheduled';
            $statusLabel = self::statusLabel($p);
            $typeLabel   = $typeLabels[$id] ?? self::kindLabel((string) $p['page_type']);

            $detail = [
                'address' => ['label' => \__('studio_address', 'Address'), 'html' => '<code>' . \e((string) $p['public_path']) . '</code>'],
                'type'    => ['label' => \__('studio_page_type', 'Type'), 'value' => $typeLabel],
                'status'  => ['label' => \__('status', 'Status'), 'value' => $statusLabel . ($hasChanges ? ' · ' . \__('studio_unpublished_changes', 'unpublished changes') : '')],
            ];
            if (!empty($p['updated_at'])) {
                $detail['updated'] = ['label' => \__('updated', 'Updated'), 'value' => (string) $p['updated_at']];
            }

            $actions = '';
            if ($canEdit) {
                $actions .= '<a class="btn btn-sm btn-primary" href="' . \e($builderUrl . '?page=' . $id) . '">'
                    . \e(\__('studio_open_builder', 'Open builder')) . '</a> ';
            }
            if ($settingsUrl !== '') {
                $actions .= '<a class="btn btn-sm btn-ghost" href="' . \e($settingsUrl . '?id=' . $id) . '">'
                    . \e(\__('studio_page_settings', 'Settings')) . '</a> ';
            }
            if ($settingsUrl !== '' && isset($typeLabels[$id])) {
                $actions .= '<a class="btn btn-sm btn-ghost" href="' . \e($settingsUrl . '?id=' . $id . '#sb-item-form') . '">'
                    . \e(\__('studio_item_fields', 'Fields')) . '</a> ';
            }
            $actions .= '<a class="btn btn-sm btn-ghost" target="_blank" rel="noopener" href="' . \e($previewUrl . '?page=' . $id) . '">'
                . \e(\__('studio_preview', 'Preview')) . '</a>';

            \slate_data_row([
                'select'       => ['name' => 'ids[]', 'value' => (string) $id, 'label' => sprintf(\__('studio_select_page', 'Select page %s'), $title)],
                'avatar'       => $title,
                'avatar_color' => $published ? 'success' : 'muted',
                'title'        => $title,
                'meta'         => (string) $p['public_path'],
                'value'        => $columns !== [] ? null : $typeLabel,
                'cells'        => array_map(static fn(array $c): string => self::cell($c, $p, $values[$id] ?? []), $columns),
                'badge'        => [$statusLabel, $published ? 'active' : ($scheduled ? 'info' : 'warning')],
                'detail'       => $detail,
                'actions'      => $actions,
            ]);
        }
        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * A type's admin columns ready to show: the title each one reads, and its field.
     *
     * @param list<array<string, mixed>> $stored
     * @param array<string, array<string, mixed>> $fields field key => field
     * @return list<array{title: string, source: string, prefix: string, suffix: string, sortable: bool, field: ?array<string, mixed>}>
     */
    public static function columns(array $stored, array $fields): array
    {
        $out = [];
        foreach ($stored as $c) {
            $source = (string) ($c['source'] ?? '');
            $field = $fields[$source] ?? null;
            $builtIn = match ($source) {
                '_status'  => \__('status', 'Status'),
                '_date'    => \__('studio_item_publish_date', 'Publish date'),
                '_updated' => \__('updated', 'Updated'),
                '_slug'    => \__('studio_address', 'Address'),
                default    => null,
            };
            if ($field === null && $builtIn === null) {
                continue; // a field deleted since
            }
            $title = trim((string) ($c['label'] ?? ''));
            $out[] = [
                'title'    => $title !== '' ? $title : ($builtIn ?? (string) ($field['label'] ?? $source)),
                'source'   => $source,
                'prefix'   => (string) ($c['prefix'] ?? ''),
                'suffix'   => (string) ($c['suffix'] ?? ''),
                'sortable' => !empty($c['sortable']),
                'field'    => $field,
            ];
        }
        return $out;
    }

    /** A filter's title: its own, else its field's label. @param array<string, mixed> $filter @param array<string, array<string, mixed>> $fields */
    public static function filterTitle(array $filter, array $fields): string
    {
        $title = trim((string) ($filter['label'] ?? ''));
        return $title !== '' ? $title : (string) ($fields[$filter['source'] ?? '']['label'] ?? $filter['source'] ?? '');
    }

    /**
     * What each admin filter offers: a choice field's choices, Yes and No for a toggle, else the values saved so far.
     * A filter on a field deleted since offers nothing and is left out.
     *
     * @param list<array<string, mixed>> $filters
     * @param array<string, array<string, mixed>> $fields
     * @param array<int, array<string, mixed>> $values
     * @return array<string, array<string, string>> field key => value => text
     */
    public static function filterChoices(array $filters, array $fields, array $values): array
    {
        $out = [];
        foreach ($filters as $f) {
            $key = (string) ($f['source'] ?? '');
            $field = $fields[$key] ?? null;
            if ($field === null) {
                continue;
            }
            $choices = is_array($field['options']['choices'] ?? null) ? $field['options']['choices'] : [];
            if (($field['type'] ?? '') === 'boolean') {
                $out[$key] = ['1' => \__('studio_yes', 'Yes'), '0' => \__('studio_no', 'No')];
            } elseif ($choices !== []) {
                $out[$key] = array_column($choices, 'label', 'value');
            } else {
                $seen = [];
                foreach ($values as $row) {
                    foreach (self::valueWords($row[$key] ?? null) as $word) {
                        $seen[$word] = mb_substr($word, 0, 60);
                    }
                }
                uksort($seen, 'strnatcasecmp');
                $out[$key] = array_slice($seen, 0, 100, true);
            }
        }
        return $out;
    }

    /** A saved value as the words a filter compares: one for a scalar, one per entry for a list. @return list<string> */
    public static function valueWords(mixed $value): array
    {
        if (is_bool($value)) {
            return [$value ? '1' : '0'];
        }
        if (is_scalar($value)) {
            return (string) $value === '' ? [] : [(string) $value];
        }
        return is_array($value) ? array_values(array_filter(array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $value), static fn(string $v): bool => $v !== '')) : [];
    }

    /**
     * The rows sorted by one column; an empty value goes last either way.
     *
     * @param list<array<string, mixed>> $pages
     * @param array<int, array<string, mixed>> $values
     * @return list<array<string, mixed>>
     */
    public static function sorted(array $pages, string $source, string $dir, array $values): array
    {
        $of = static function (array $p) use ($source, $values): string {
            $raw = match ($source) {
                '_status'  => (string) ($p['status'] ?? ''),
                '_date'    => (string) ($p['published_at'] ?? ''),
                '_updated' => (string) ($p['updated_at'] ?? ''),
                '_slug'    => (string) ($p['public_path'] ?? ''),
                default    => $values[(int) $p['id']][$source] ?? '',
            };
            return implode(' ', self::valueWords($raw));
        };
        $sign = $dir === 'desc' ? -1 : 1;
        usort($pages, static function (array $a, array $b) use ($of, $sign): int {
            [$x, $y] = [$of($a), $of($b)];
            if (($x === '') !== ($y === '')) {
                return $x === '' ? 1 : -1;
            }
            return $sign * strnatcasecmp($x, $y);
        });
        return $pages;
    }

    /** @param array<string, mixed> $column @param array{base?: array<string, mixed>, key?: string, dir?: string} $sort */
    private static function columnHead(array $column, array $sort): string
    {
        $title = \e((string) $column['title']);
        if (empty($column['sortable'])) {
            return $title;
        }
        $active = ($sort['key'] ?? '') === $column['source'];
        $dir = $active && ($sort['dir'] ?? 'asc') === 'asc' ? 'desc' : 'asc';
        return '<a href="pages.php?' . \e(http_build_query(($sort['base'] ?? []) + ['sort' => $column['source'], 'dir' => $dir])) . '">' . $title
            . ($active ? (($sort['dir'] ?? 'asc') === 'asc' ? ' ↑' : ' ↓') : '') . '</a>';
    }

    /** @param array<string, mixed> $page */
    private static function statusLabel(array $page): string
    {
        return !empty($page['is_published']) ? \__('studio_status_published', 'Published')
            : (($page['status'] ?? '') === 'scheduled' ? \__('studio_status_scheduled', 'Scheduled') : \__('studio_status_draft', 'Draft'));
    }

    /** One row's value for one admin column, as text. @param array<string, mixed> $column @param array<string, mixed> $page @param array<string, mixed> $values */
    private static function cell(array $column, array $page, array $values): string
    {
        $source = (string) $column['source'];
        $text = match ($source) {
            '_status'  => self::statusLabel($page),
            '_date'    => substr((string) ($page['published_at'] ?? ''), 0, 10),
            '_updated' => substr((string) ($page['updated_at'] ?? ''), 0, 10),
            '_slug'    => (string) ($page['public_path'] ?? ''),
            default    => self::fieldText($column['field'] ?? [], $values[$source] ?? null),
        };
        return $text === '' ? '—' : (string) $column['prefix'] . $text . (string) $column['suffix'];
    }

    /** A saved field value as a person reads it: a choice's label, Yes or No for a toggle. @param array<string, mixed> $field */
    private static function fieldText(array $field, mixed $value): string
    {
        if (($field['type'] ?? '') === 'boolean') {
            return $value === null ? '' : ($value ? \__('studio_yes', 'Yes') : \__('studio_no', 'No'));
        }
        $labels = is_array($field['options']['choices'] ?? null) ? array_column($field['options']['choices'], 'label', 'value') : [];
        return mb_substr(implode(', ', array_map(static fn(string $w): string => (string) ($labels[$w] ?? $w), self::valueWords($value))), 0, 80);
    }

    /** A page kind as one word that fits the list's type pill: "Header", "Template", not the stored `header_partial` or `system`. */
    public static function kindLabel(string $pageType): string
    {
        return match ($pageType) {
            'page'           => \__('studio_kind_page', 'Page'),
            'landing'        => \__('studio_kind_landing', 'Landing'),
            'header_partial' => \__('studio_kind_header', 'Header'),
            'footer_partial' => \__('studio_kind_footer', 'Footer'),
            'system'         => \__('studio_kind_template', 'Template'),
            default          => $pageType,
        };
    }

    /**
     * The bar that appears over a list once rows are ticked: how many, Clear, and (when `$canDelete`) one submit button
     * named `bulk_action`. `$confirm` asks first; `%d` is replaced by the number ticked. Reused by any list of
     * `slate_data_row`s with a select box.
     */
    public static function selectBar(bool $canDelete, string $action, string $label, string $confirm): string
    {
        return '<div class="data-list-selectbar" role="status"><span><span class="data-list-selected-count">0</span> '
            . \e(\__('studio_selected', 'selected')) . '</span>'
            . '<button type="button" class="btn btn-sm btn-ghost data-list-clear">' . \e(\__('studio_clear_selection', 'Clear selection')) . '</button>'
            . ($canDelete
                ? '<button type="submit" name="bulk_action" value="' . \e($action) . '" class="btn btn-sm btn-danger data-list-delete" style="margin-left:auto"'
                    . ' data-confirm="' . \e($confirm) . '">' . \e($label) . '</button>'
                : '')
            . '</div>';
    }

    /**
     * Selection behaviour (select-all, indeterminate state, count bar). Row
     * expansion is the platform's slate_data_list_script(); this only tracks
     * checkboxes, which live beside the toggle buttons, never inside them.
     */
    public static function selectionScript(string $listId = 'studio-page-list'): string
    {
        $js = <<<'HTML'
<script>
(function () {
    var list = document.getElementById('__LIST_ID__');
    if (!list) return;
    var all = list.querySelector('.data-list-select-all');
    var bar = list.querySelector('.data-list-selectbar');
    var count = list.querySelector('.data-list-selected-count');
    var boxes = function () { return Array.prototype.slice.call(list.querySelectorAll('.data-row-select')); };
    function sync() {
        var on = boxes().filter(function (b) { return b.checked; });
        count.textContent = on.length;
        bar.classList.toggle('is-active', on.length > 0);
        boxes().forEach(function (b) { b.closest('.data-row').classList.toggle('is-selected', b.checked); });
        all.checked = on.length > 0 && on.length === boxes().length;
        all.indeterminate = on.length > 0 && on.length < boxes().length;
    }
    all.addEventListener('change', function () { boxes().forEach(function (b) { b.checked = all.checked; }); sync(); });
    list.addEventListener('change', function (e) { if (e.target.classList.contains('data-row-select')) sync(); });
    var del = list.querySelector('.data-list-delete');
    if (del) del.closest('form').addEventListener('submit', function (e) {
        if (e.submitter && e.submitter !== del) return; // a row's own button (Restore, Delete one) is not the bulk Delete
        var n = boxes().filter(function (b) { return b.checked; }).length;
        if (n === 0 || !window.confirm(del.getAttribute('data-confirm').replace('%d', n))) e.preventDefault();
    });
    list.querySelector('.data-list-clear').addEventListener('click', function () { boxes().forEach(function (b) { b.checked = false; }); sync(); all.focus(); });
    sync();
})();
</script>
HTML;
        return str_replace('__LIST_ID__', \e($listId), $js);
    }
}
