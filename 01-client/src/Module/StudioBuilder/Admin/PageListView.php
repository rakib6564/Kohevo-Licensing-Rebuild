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
     * @return string the .data-list markup (no script tag; see slate_data_list_script())
     */
    public static function render(array $pages, bool $canEdit, string $builderUrl, string $previewUrl): string
    {
        if (!function_exists('slate_data_row')) {
            require_once dirname(__DIR__, 4) . '/includes/ui_components.php';
        }

        if ($pages === []) {
            return '<div class="data-list" data-single-open></div>';
        }

        ob_start();
        echo '<div class="data-list is-columnar" data-single-open id="studio-page-list">';
        echo '<div class="data-list-selectbar" role="status"><span><span class="data-list-selected-count">0</span> '
            . \e(\__('studio_selected', 'selected')) . '</span>'
            . '<button type="button" class="btn btn-sm btn-ghost data-list-clear">' . \e(\__('studio_clear_selection', 'Clear selection')) . '</button></div>';
        \slate_data_list_head(
            ['main' => \__('title', 'Title'), 'value' => \__('studio_page_type', 'Type'), 'badge' => \__('status', 'Status')],
            ['select_all' => \__('studio_select_all_pages', 'Select all pages')]
        );
        foreach ($pages as $p) {
            $id          = (int) $p['id'];
            $published   = !empty($p['is_published']);
            $hasChanges  = $published && !empty($p['has_unpublished_changes']);
            $title       = (string) $p['title'];
            $statusLabel = $published ? \__('studio_status_published', 'Published') : \__('studio_status_draft', 'Draft');

            $detail = [
                'address' => ['label' => \__('studio_address', 'Address'), 'html' => '<code>' . \e((string) $p['public_path']) . '</code>'],
                'type'    => ['label' => \__('studio_page_type', 'Type'), 'value' => (string) $p['page_type']],
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
            $actions .= '<a class="btn btn-sm btn-ghost" target="_blank" rel="noopener" href="' . \e($previewUrl . '?page=' . $id) . '">'
                . \e(\__('studio_preview', 'Preview')) . '</a>';

            \slate_data_row([
                'select'       => ['name' => 'ids[]', 'value' => (string) $id, 'label' => sprintf(\__('studio_select_page', 'Select page %s'), $title)],
                'avatar'       => $title,
                'avatar_color' => $published ? 'success' : 'muted',
                'title'        => $title,
                'meta'         => (string) $p['public_path'],
                'value'        => (string) $p['page_type'],
                'badge'        => [$statusLabel, $published ? 'active' : 'warning'],
                'detail'       => $detail,
                'actions'      => $actions,
            ]);
        }
        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * Selection behaviour (select-all, indeterminate state, count bar). Row
     * expansion is the platform's slate_data_list_script(); this only tracks
     * checkboxes, which live beside the toggle buttons, never inside them.
     */
    public static function selectionScript(): string
    {
        return <<<'HTML'
<script>
(function () {
    var list = document.getElementById('studio-page-list');
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
    list.querySelector('.data-list-clear').addEventListener('click', function () { boxes().forEach(function (b) { b.checked = false; }); sync(); all.focus(); });
    sync();
})();
</script>
HTML;
    }
}
