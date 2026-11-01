<?php
/**
 * Kohevo Studio — saved Google answers, so opening the screen does not spend the site's quota. Holds report figures only.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Insights;

interface InsightsCache
{
    /** @return array<string, mixed>|null */
    public function get(string $key): ?array;

    /** @param array<string, mixed> $value */
    public function set(string $key, array $value, int $ttl): void;

    public function clear(): void;
}
