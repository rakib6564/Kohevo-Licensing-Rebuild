<?php
/**
 * Kohevo Studio (studio-builder) — A finished render: final HTML plus its mode's cache headers.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

final class RenderResult
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly RenderMode $mode,
        public readonly string $html,
        public readonly array $headers,
        public readonly ?string $etag = null,
    ) {}
}
