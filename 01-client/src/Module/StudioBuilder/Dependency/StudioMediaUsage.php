<?php
/**
 * Kohevo Studio — the media a Studio page uses, reported to the media library.
 *
 * `Media::delete()` refuses a file whose usage count is above zero, and the
 * count comes from `media_usage` rows plus the `media_usage` Hook filter
 * (a path => count map). Studio already records every media reference of
 * every revision in `studiobuilder_dependencies`, so it answers the filter
 * from there instead of writing rows: a file counts once for each
 * non-archived page whose CURRENT draft or CURRENT published revision uses
 * it (an image, a background image, the share image). Old revisions do not
 * hold a file, the same rule the archive guard uses for components.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Dependency;

use Slate\Data\Database;

final class StudioMediaUsage
{
    /** The SQL behind the filter: path => number of pages, for one tenant. */
    public const SQL = "SELECT f.path AS path, COUNT(DISTINCT d.page_id) AS c
          FROM studiobuilder_dependencies d
          INNER JOIN studiobuilder_pages p
                  ON p.tenant_id = d.tenant_id
                 AND p.id = d.page_id
                 AND p.status <> 'archived'
                 AND (p.active_draft_revision_id = d.revision_id OR p.published_revision_id = d.revision_id)
          INNER JOIN media_files f
                  ON f.tenant_id = d.tenant_id
                 AND f.id = CAST(d.dependency_key AS UNSIGNED)
         WHERE d.tenant_id = ? AND d.dependency_type = 'media'
         GROUP BY f.path";

    /** The pages behind one file's count: the same rule as SQL, for one media id. */
    public const DETAILS_SQL = "SELECT DISTINCT p.id, p.title, p.page_type, p.status
          FROM studiobuilder_dependencies d
          INNER JOIN studiobuilder_pages p
                  ON p.tenant_id = d.tenant_id
                 AND p.id = d.page_id
                 AND p.status <> 'archived'
                 AND (p.active_draft_revision_id = d.revision_id OR p.published_revision_id = d.revision_id)
         WHERE d.tenant_id = ? AND d.dependency_type = 'media' AND d.dependency_key = ?
         ORDER BY p.title, p.id
         LIMIT 200";

    /**
     * `media_usage_details` Hook filter: list the Studio pages that use one file, with a link to the editor.
     *
     * @param list<array<string, string>> $list
     * @param array<string, mixed> $record a decorated Media record
     * @return list<array<string, string>>
     */
    public static function details(array $list, array $record = []): array
    {
        $mediaId = (int) ($record['id'] ?? 0);
        if ($mediaId <= 0 || !function_exists('current_tenant_id')) {
            return $list;
        }
        try {
            $rows = Database::rows(self::DETAILS_SQL, [(int) current_tenant_id(), (string) $mediaId]);
        } catch (\Throwable) {
            return $list;
        }
        return array_merge($list, self::detailRows($rows));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{source: string, label: string, url: string}>
     */
    public static function detailRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            $out[] = [
                'source' => 'Studio',
                'label'  => ($title !== '' ? $title : '#' . $id) . ' (' . (string) ($row['page_type'] ?? 'page') . ', ' . (string) ($row['status'] ?? '') . ')',
                'url'    => function_exists('plugin_url') && defined('SLATE_URL') ? plugin_url('studio-builder', 'admin/builder.php?page=' . $id) : '',
            ];
        }
        return $out;
    }

    /**
     * `media_usage` Hook filter: add Studio's page references to the map.
     *
     * @param array<string, int> $map
     * @return array<string, int>
     */
    public static function filter(array $map): array
    {
        if (!function_exists('current_tenant_id')) {
            return $map;
        }
        try {
            $rows = Database::rows(self::SQL, [(int) current_tenant_id()]);
        } catch (\Throwable) {
            // The Studio tables may not exist yet; never break the media library.
            return $map;
        }
        return self::merge($map, $rows);
    }

    /**
     * @param array<string, int> $map
     * @param list<array{path: mixed, c: mixed}> $rows
     * @return array<string, int>
     */
    public static function merge(array $map, array $rows): array
    {
        foreach ($rows as $row) {
            $path = (string) ($row['path'] ?? '');
            $count = (int) ($row['c'] ?? 0);
            if ($path === '' || $count <= 0) {
                continue;
            }
            $map[$path] = (int) ($map[$path] ?? 0) + $count;
        }
        return $map;
    }
}
