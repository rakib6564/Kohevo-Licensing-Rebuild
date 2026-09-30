<?php
/**
 * Kohevo Studio (studio-builder) — Public render cache identity.
 *
 * The stored artifact is looked up by the database's own tenant-scoped unique
 * key (tenant_id, page_id, compile_mode='published') and is only reused when
 * its revision_id equals the page's CURRENT published_revision_id and its
 * content_hash matches every current compile input. This key names that full
 * identity explicitly — never just a slug:
 *
 *   studio:v1:published:t{tenant}:s{site}:p{page}:r{published revision}:{content hash}
 *
 * so a different tenant, site, page, publish, or dependency change can never
 * map onto the same key. Preview/Editor renders have NO cache key at all:
 * they are never cached anywhere. The ETag published to clients is a hash of
 * this key plus the final HTML — it reveals no internal id.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Cache;

final class StudioCacheKey
{
    public static function published(int $tenantId, string $siteKey, int $pageId, int $revisionId, string $contentHash): string
    {
        if ($tenantId <= 0 || $pageId <= 0 || $revisionId <= 0 || preg_match('/^[a-f0-9]{64}$/', $contentHash) !== 1) {
            throw new \InvalidArgumentException('A public Studio cache key requires tenant, page, published revision and content hash.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $siteKey) !== 1) {
            throw new \InvalidArgumentException('Invalid site key for a Studio cache key.');
        }
        return sprintf('studio:v1:published:t%d:s%s:p%d:r%d:%s', $tenantId, $siteKey, $pageId, $revisionId, $contentHash);
    }

    public static function etag(string $cacheKey, string $html): string
    {
        return hash('sha256', $cacheKey . "\n" . hash('sha256', $html));
    }
}
