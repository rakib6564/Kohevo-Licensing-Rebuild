<?php
/**
 * Kohevo Studio (studio-builder) — Media Resolution Contract.
 *
 * The ONLY way a block renderer obtains an asset URL: by handing over the
 * integer `media_id` from a validated `media_ref`. Implementations resolve it
 * against the CURRENT tenant only and return null for anything missing,
 * foreign, non-image, or not a managed upload path.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Media;

interface MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia;
}
