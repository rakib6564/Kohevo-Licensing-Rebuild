<?php
/**
 * Kohevo Studio — the items before and after one item of a content type, for "Next project" links.
 *
 * Items are taken in a Query Loop's default order (newest first): "next" is the one listed after this item, "previous"
 * the one before it, and both wrap around, so the last item's next is the first. Only published items of the same
 * content type count; a page that is not an item has neither.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Meta;

use Slate\Data\Database;

final class AdjacentItems
{
    /** @var array<string, array{prev: ?array{id: int, title: string, url: string}, next: ?array{id: int, title: string, url: string}}> */
    private static array $cache = [];

    /** @return array{prev: ?array{id: int, title: string, url: string}, next: ?array{id: int, title: string, url: string}} */
    public static function of(int $tenantId, int $pageId): array
    {
        $none = ['prev' => null, 'next' => null];
        if ($tenantId <= 0 || $pageId <= 0) {
            return $none;
        }
        $key = $tenantId . ':' . $pageId;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $type = PageMeta::read($tenantId, $pageId)['type'];
        $ids = $type !== '' ? PageMeta::pageIdsOfType($tenantId, $type) : [];
        if (count($ids) < 2) {
            return self::$cache[$key] = $none;
        }
        $rows = Database::all(
            "SELECT id, title, slug FROM studiobuilder_pages
             WHERE tenant_id = ? AND status = 'published' AND published_revision_id IS NOT NULL
               AND page_type IN ('page', 'landing') AND slug NOT LIKE 'template-%'
               AND id IN (" . implode(',', array_map('intval', array_slice($ids, 0, 2000))) . ')
             ORDER BY published_at DESC, id DESC',
            [$tenantId]
        );
        return self::$cache[$key] = self::around($tenantId, $rows, $pageId);
    }

    /**
     * The neighbours of `$pageId` in `$rows` (already in order), wrapping round.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{prev: ?array{id: int, title: string, url: string}, next: ?array{id: int, title: string, url: string}}
     */
    public static function around(int $tenantId, array $rows, int $pageId): array
    {
        $at = null;
        foreach (array_values($rows) as $i => $row) {
            if ((int) $row['id'] === $pageId) {
                $at = $i;
                break;
            }
        }
        $n = count($rows);
        if ($at === null || $n < 2) {
            return ['prev' => null, 'next' => null];
        }
        $rows = array_values($rows);
        $item = static fn(array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'url' => PageRoutes::path($tenantId, (int) $row['id'], (string) $row['slug'], ''),
        ];
        return ['prev' => $item($rows[($at - 1 + $n) % $n]), 'next' => $item($rows[($at + 1) % $n])];
    }

    /** For tests: forget what was looked up. */
    public static function reset(): void
    {
        self::$cache = [];
    }
}
