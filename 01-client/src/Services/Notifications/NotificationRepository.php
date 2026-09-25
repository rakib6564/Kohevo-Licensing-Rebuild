<?php
/**
 * Slate — tenant-scoped notification repository.
 *
 * All notification queries inherit the Repository tenant predicate and insert
 * stamping. The legacy Notifications facade remains responsible for the
 * best-effort schema bootstrap and compatibility surface.
 */

declare(strict_types=1);

namespace Slate\Services\Notifications;

use Slate\Data\Repository;

final class NotificationRepository extends Repository
{
    protected string $table = 'slate_notifications';

    public function unreadCount(): int
    {
        return $this->query()->where('is_read', 0)->count();
    }

    public function recent(int $limit): array
    {
        return $this->query()->orderBy('id', 'DESC')->limit($limit)->get();
    }

    public function paginated(int $limit, int $offset): array
    {
        return $this->query()->orderBy('id', 'DESC')->limit($limit)->offset($offset)->get();
    }

    public function markRead(int $id, bool $read): int
    {
        return $this->query()->where('id', $id)->update(['is_read' => $read ? 1 : 0]);
    }

    public function markAllRead(): int
    {
        return $this->query()->where('is_read', 0)->update(['is_read' => 1]);
    }

    public function deleteAll(): int
    {
        return $this->query()->delete();
    }

    public function pruneReadBefore(string $cutoff): int
    {
        return $this->query()
            ->where('is_read', 1)
            ->where('created_at', '<', $cutoff)
            ->delete();
    }
}
