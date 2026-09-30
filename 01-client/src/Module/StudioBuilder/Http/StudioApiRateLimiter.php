<?php
/**
 * Kohevo Studio (studio-builder) — Per-session fixed-window rate limit for the builder API.
 *
 * The platform has no general-purpose request limiter (only the login
 * throttle), and the builder's own traffic is naturally bounded (debounced
 * autosave, one in-flight command at a time), so a small per-session window
 * is enough to stop a runaway or scripted client from hammering the revision
 * table. The bucket lives in the caller's storage (the PHP session in
 * production) — no new table. Time here is only a window counter, never
 * persisted to a datetime column.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Http;

final class StudioApiRateLimiter
{
    public const WINDOW_SECONDS  = 60;
    public const MAX_COMMANDS    = 180;
    public const MAX_QUERIES     = 600;
    /** Package export/import (Phase 8A): heavier requests, a smaller budget on top of the method bucket. */
    public const MAX_PACKAGES    = 30;
    public const PACKAGE_ACTIONS = ['export_package', 'import_package'];

    /**
     * @param \Closure(): int $clock seconds
     */
    public function __construct(private readonly \Closure $clock) {}

    public static function system(): self
    {
        return new self(static fn(): int => time());
    }

    /**
     * Record one request in `$bucket`; returns false when the window is exhausted.
     *
     * @param mixed $bucket storage slot (e.g. `$_SESSION['studio_api_rate']`), rewritten in place
     */
    public function hit(mixed &$bucket, string $method, string $action = ''): bool
    {
        $now = ($this->clock)();
        $kind = $method === 'POST' ? 'commands' : 'queries';
        $limit = $kind === 'commands' ? self::MAX_COMMANDS : self::MAX_QUERIES;

        if (!is_array($bucket) || !is_int($bucket['start'] ?? null) || $now - $bucket['start'] >= self::WINDOW_SECONDS || $now < $bucket['start']) {
            $bucket = ['start' => $now, 'commands' => 0, 'queries' => 0, 'packages' => 0];
        }
        $count = is_int($bucket[$kind] ?? null) ? $bucket[$kind] : 0;
        if ($count >= $limit) {
            return false;
        }
        if (in_array($action, self::PACKAGE_ACTIONS, true)) {
            $packages = is_int($bucket['packages'] ?? null) ? $bucket['packages'] : 0;
            if ($packages >= self::MAX_PACKAGES) {
                return false;
            }
            $bucket['packages'] = $packages + 1;
        }
        $bucket[$kind] = $count + 1;
        return true;
    }
}
