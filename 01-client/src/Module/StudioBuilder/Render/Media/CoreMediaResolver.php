<?php
/**
 * Kohevo Studio (studio-builder) — Media resolver backed by the core Media library.
 *
 * Uses the existing `Slate\Services\Media\Media` service (validated in Phase 3
 * as the tenant-scoped, integer-keyed media authority) — no parallel media
 * table, no direct `media_files` SQL:
 *
 *  - `Media::get($id)` is scoped by `current_tenant_id()`, so another tenant's
 *    id resolves to null exactly like a missing one.
 *  - The row's `path` must pass `Media::isManagedPath()` (a file under
 *    `/uploads/<managed folder>/`, no traversal, no backslashes, and NOT an
 *    absolute `http(s)://` URL) — so a media row can never smuggle an arbitrary
 *    external URL or a filesystem path into rendered output.
 *  - Only `kind = image` rows resolve for image slots.
 *  - The served URL comes from `Media::url()` (the install's own URL scheme).
 *
 * Deliberately NOT memoized: a resolver can outlive a media change in a
 * long-lived process (cron, worker, tests), and a stale hit would re-emit a
 * deleted or re-pointed asset into a freshly recompiled artifact.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Media;

use Slate\Services\Media\Media;
use Slate\Tenancy\TenantContext;

final class CoreMediaResolver implements MediaResolverInterface
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        $tenantId = $this->tenants->id();
        if ($mediaId <= 0 || $tenantId <= 0 || !$this->tenants->isScoped()) {
            return null;
        }
        $resolved = null;
        try {
            $row = class_exists(Media::class) ? Media::get($mediaId) : null;
            if (
                is_array($row)
                && (int) ($row['id'] ?? 0) === $mediaId
                && ($row['kind'] ?? '') === 'image'
                && Media::isManagedPath((string) ($row['path'] ?? ''))
            ) {
                $url = Media::url((string) $row['path']);
                if ($url !== '' && preg_match('~^(https?://[^\s"\'<>]+|/[^/\s"\'<>][^\s"\'<>]*)$~i', $url) === 1) {
                    $resolved = new ResolvedMedia(
                        $mediaId,
                        $url,
                        isset($row['width']) && is_int($row['width']) && $row['width'] > 0 ? $row['width'] : null,
                        isset($row['height']) && is_int($row['height']) && $row['height'] > 0 ? $row['height'] : null,
                    );
                }
            }
        } catch (\Throwable $ignored) {
            $resolved = null;
        }

        return $resolved;
    }
}
