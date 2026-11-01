<?php
/**
 * Kohevo Studio — where one site's Google settings live. SettingsInsightsStore is the tenant's settings; tests use an array.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Insights;

interface InsightsStore
{
    public function get(string $key): string;

    public function set(string $key, string $value): void;
}
