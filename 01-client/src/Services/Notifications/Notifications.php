<?php
/**
 * Slate — lightweight admin notification store.
 *
 * The public static API is retained for legacy controllers and plugins. Storage
 * is delegated to NotificationRepository, whose base Repository provides the
 * structural tenant predicate and tenant_id insert stamping.
 */

declare(strict_types=1);

namespace Slate\Services\Notifications;

use Slate\Tenancy\TenantContext;

class Notifications
{
    private static bool $schemaChecked = false;
    private static ?NotificationRepository $repository = null;

    public static function ensureSchema(): void
    {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        try {
            \Database::get()->exec(
                "CREATE TABLE IF NOT EXISTS `slate_notifications` (
                    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
                    `title`      VARCHAR(200) NOT NULL,
                    `body`       VARCHAR(500) NULL,
                    `url`        VARCHAR(500) NULL,
                    `icon`       VARCHAR(40)  NULL,
                    `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
                    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_tenant_read` (`tenant_id`, `is_read`, `id`),
                    KEY `idx_tenant_created` (`tenant_id`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            slate_log('Notifications: ensure schema failed: ' . $e->getMessage(), 'error');
        }
    }

    private static function repository(): NotificationRepository
    {
        return self::$repository ??= new NotificationRepository(new TenantContext());
    }

    /** Push a notification. $opts: body, url, icon. */
    public static function add(string $title, array $opts = []): void
    {
        $title = trim($title);
        if ($title === '') return;
        self::ensureSchema();
        try {
            self::repository()->insert([
                'title' => mb_substr($title, 0, 200),
                'body'  => isset($opts['body']) ? mb_substr((string)$opts['body'], 0, 500) : null,
                'url'   => isset($opts['url'])  ? mb_substr((string)$opts['url'], 0, 500)  : null,
                'icon'  => isset($opts['icon']) ? mb_substr((string)$opts['icon'], 0, 40)  : null,
            ]);
        } catch (\Throwable $e) {
            slate_log('Notifications: add failed: ' . $e->getMessage(), 'error');
        }
    }

    public static function unreadCount(): int
    {
        self::ensureSchema();
        try { return self::repository()->unreadCount(); } catch (\Throwable $e) { return 0; }
    }

    /** Most recent notifications (newest first). */
    public static function recent(int $limit = 12): array
    {
        self::ensureSchema();
        $limit = max(1, min(50, $limit));
        try { return self::repository()->recent($limit); } catch (\Throwable $e) { return []; }
    }

    /** Paginated feed (newest first) for the full notifications page. */
    public static function all(int $limit = 30, int $offset = 0): array
    {
        self::ensureSchema();
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);
        try { return self::repository()->paginated($limit, $offset); } catch (\Throwable $e) { return []; }
    }

    /** Total notifications for the current tenant (for pagination). */
    public static function total(): int
    {
        self::ensureSchema();
        try { return self::repository()->count(); } catch (\Throwable $e) { return 0; }
    }

    /** Fetch one notification (tenant-scoped) or null. */
    public static function get(int $id): ?array
    {
        self::ensureSchema();
        try {
            $row = self::repository()->find($id);
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) { return null; }
    }

    /** Mark a single notification read (or unread when $read is false). */
    public static function markRead(int $id, bool $read = true): void
    {
        self::ensureSchema();
        try { self::repository()->markRead($id, $read); }
        catch (\Throwable $e) { slate_log('Notifications: markRead failed: ' . $e->getMessage(), 'error'); }
    }

    /** Delete a single notification (tenant-scoped). */
    public static function delete(int $id): void
    {
        self::ensureSchema();
        try { self::repository()->delete($id); }
        catch (\Throwable $e) { slate_log('Notifications: delete failed: ' . $e->getMessage(), 'error'); }
    }

    /** Delete every notification for the current tenant. */
    public static function deleteAll(): void
    {
        self::ensureSchema();
        try { self::repository()->deleteAll(); }
        catch (\Throwable $e) { slate_log('Notifications: deleteAll failed: ' . $e->getMessage(), 'error'); }
    }

    public static function markAllRead(): void
    {
        self::ensureSchema();
        try { self::repository()->markAllRead(); }
        catch (\Throwable $e) { slate_log('Notifications: markAllRead failed: ' . $e->getMessage(), 'error'); }
    }

    /** Trim old read notifications so the table doesn't grow unbounded. */
    public static function prune(int $keepDays = 30): void
    {
        self::ensureSchema();
        try {
            $days = max(1, $keepDays);
            // created_at is written by MySQL (DEFAULT CURRENT_TIMESTAMP), so the
            // cutoff has to come from the same clock. PHP runs UTC here and MySQL
            // runs SYSTEM, and a PHP-built cutoff sat that offset ahead — quietly
            // deleting notifications four hours younger than $keepDays allows.
            $cutoff = (string)\Database::value('SELECT NOW() - INTERVAL ? DAY', [$days]);
            self::repository()->pruneReadBefore($cutoff);
        } catch (\Throwable $e) { /* best effort */ }
    }
}
