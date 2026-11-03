<?php
/**
 * Kohevo Studio (studio-builder) — A media reference resolved to a servable asset.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Media;

final class ResolvedMedia
{
    public function __construct(
        public readonly int $mediaId,
        public readonly string $url,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        /** The library's alt text: what a block shows when its own alt is empty. */
        public readonly string $alt = '',
    ) {}
}
