<?php
/**
 * Kohevo Studio (studio-builder) — A compiled body with its dynamic nodes resolved for one request.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Compile;

final class FilledPage
{
    public function __construct(
        public readonly string $html,
        public readonly string $extraCss,
    ) {}
}
