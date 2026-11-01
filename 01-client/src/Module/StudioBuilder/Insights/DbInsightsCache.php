<?php
/**
 * Kohevo Studio — InsightsCache in `studiobuilder_google_cache`: rows, not files, because the hosts count inodes. An expired
 * row is replaced by the next read of the same report, and the tenant's stale rows go with each write.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Insights;

use Slate\Data\Database;

final class DbInsightsCache implements InsightsCache
{
    public function __construct(private readonly int $tenantId)
    {
    }

    public function get(string $key): ?array
    {
        $raw = Database::value(
            'SELECT payload FROM studiobuilder_google_cache WHERE tenant_id = ? AND cache_key = ? AND expires_at > NOW()',
            [$this->tenantId, hash('sha256', $key)]
        );
        $value = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($value) ? $value : null;
    }

    public function set(string $key, array $value, int $ttl): void
    {
        Database::query('DELETE FROM studiobuilder_google_cache WHERE tenant_id = ? AND expires_at <= NOW()', [$this->tenantId]);
        Database::query(
            'INSERT INTO studiobuilder_google_cache (tenant_id, cache_key, payload, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)',
            [$this->tenantId, hash('sha256', $key), (string) json_encode($value), $ttl]
        );
    }

    public function clear(): void
    {
        Database::query('DELETE FROM studiobuilder_google_cache WHERE tenant_id = ?', [$this->tenantId]);
    }
}
