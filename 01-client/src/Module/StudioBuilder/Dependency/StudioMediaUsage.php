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
