<?php
/**
 * Kohevo Studio (studio-builder) — Transport-safe builder views of Studio rows.
 *
 * The ONLY shapes of a page / revision / template / component the builder
 * ever receives. Explicit allowlists: `tenant_id`, author/audit columns beyond
 * the creator id, and revision/template `document_json` never leave the
 * server through these views (the working document is sent separately,
 * decoded, and only to an actor authorized to edit that page).
 *
 * A page view carries no `uuid`. A Global Component view (Phase 6) exposes
 * the component page's uuid as `ref` — it IS the value a consuming section
 * stores in `global_ref`, opaque, tenant-scoped on every lookup, and only
 * ever returned to an authenticated, entitled actor of that tenant.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Application;

use Slate\Module\StudioBuilder\Domain\PageAddress;

final class StudioEditorViews
{
    /**
     * @param array<string, mixed> $row studiobuilder_pages row
     * @return array<string, mixed>
     */
    public static function page(array $row): array
    {
        $page = PageAddress::fromRow($row);
        return [
            'active_draft_revision_id' => $page->activeDraftRevisionId,
            'has_unpublished_changes'  => $page->hasUnpublishedChanges(),
            'id'                       => (int) $page->id,
            'is_published'             => $page->isPublished(),
            'page_type'                => $page->pageType,
            'public_path'              => $page->routeMode === 'homepage' ? '/' : '/' . $page->slug,
            'published_at'             => $page->publishedAt,
            'published_revision_id'    => $page->publishedRevisionId,
            'route_mode'               => $page->routeMode,
            'slug'                     => $page->slug,
            'status'                   => $page->status,
            'title'                    => $page->title,
            'updated_at'               => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row studiobuilder_revisions row
     * @return array<string, mixed>
     */
    public static function revision(array $row): array
    {
        return [
            'created_at'         => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'created_by'         => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'id'                 => (int) $row['id'],
            'parent_revision_id' => isset($row['parent_revision_id']) ? (int) $row['parent_revision_id'] : null,
            'revision_kind'      => (string) $row['revision_kind'],
            'revision_number'    => (int) $row['revision_number'],
            'summary'            => isset($row['summary']) ? (string) $row['summary'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row studiobuilder_templates row
     * @param null|array{sections: int, blocks: int, block_labels: list<string>} $summary
     * @return array<string, mixed>
     */
    public static function template(array $row, ?string $thumbnailUrl = null, ?array $summary = null): array
    {
        return [
            'category'      => (string) ($row['category'] ?? ''),
            'description'   => isset($row['description']) ? (string) $row['description'] : null,
            'is_system'     => !empty($row['is_system']),
            'name'          => (string) ($row['name'] ?? ''),
            'summary'       => $summary,
            'template_key'  => (string) ($row['template_key'] ?? ''),
            'template_type' => (string) ($row['template_type'] ?? ''),
            'thumbnail_url' => $thumbnailUrl,
            'updated_at'    => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * A Global Component: a `section_preset` page plus its reference key and usage.
     *
     * @param array<string, mixed> $row studiobuilder_pages row (page_type = section_preset)
     * @return array<string, mixed>
     */
    public static function component(array $row, int $usageCount = 0): array
    {
        $view = self::page($row);
        unset($view['public_path'], $view['route_mode']);
        $view['ref']         = (string) $row['uuid'];
        $view['usage_count'] = max(0, $usageCount);
        ksort($view, SORT_STRING);
        return $view;
    }
}
