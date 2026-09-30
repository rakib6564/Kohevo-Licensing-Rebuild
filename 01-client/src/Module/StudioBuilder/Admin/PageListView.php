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

        ob_start();
        echo '<div class="data-list" data-single-open>';
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
}
