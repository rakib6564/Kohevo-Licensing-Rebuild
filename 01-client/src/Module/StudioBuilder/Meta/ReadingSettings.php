<?php
/**
 * Kohevo Studio — Reading settings: what the home page shows, which page lists the posts, how many items an archive
 * lists, and what the site's feed holds. The hidden-from-search choice stays in `studio_seo_robots`.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Meta;

use Slate\Data\Database;

final class ReadingSettings
{
    public const SHOW_ON_HOME = 'studio_reading_home';
    public const POSTS_TYPE = 'studio_reading_posts_type';
    public const POSTS_PAGE = 'studio_reading_posts_page';
    public const ARCHIVE_ITEMS = 'studio_reading_archive_items';
    public const FEED_ITEMS = 'studio_reading_feed_items';
    public const FEED_CONTENT = 'studio_reading_feed_content';

    public const ALL = [self::SHOW_ON_HOME, self::POSTS_TYPE, self::POSTS_PAGE, self::ARCHIVE_ITEMS, self::FEED_ITEMS, self::FEED_CONTENT];

    public const MAX_ARCHIVE_ITEMS = 50;
    public const MAX_FEED_ITEMS = 50;

    /** @return array{home: string, posts_type: string, posts_page: int, archive_items: int, feed_items: int, feed_content: string} */
    public static function read(int $tenantId): array
    {
        $get = static function (string $key) use ($tenantId): string {
            try {
                return (string) Database::setting($key, $tenantId);
            } catch (\Throwable) {
                return '';
            }
        };
        return [
            'home'          => $get(self::SHOW_ON_HOME) === 'posts' ? 'posts' : 'page',
            'posts_type'    => $get(self::POSTS_TYPE),
            'posts_page'    => max(0, (int) $get(self::POSTS_PAGE)),
            'archive_items' => max(0, min(self::MAX_ARCHIVE_ITEMS, (int) $get(self::ARCHIVE_ITEMS))),
            'feed_items'    => ($n = (int) $get(self::FEED_ITEMS)) > 0 ? min(self::MAX_FEED_ITEMS, $n) : 10,
            'feed_content'  => $get(self::FEED_CONTENT) === 'excerpt' ? 'excerpt' : 'full',
        ];
    }
}
