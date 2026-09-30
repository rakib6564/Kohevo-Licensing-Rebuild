<?php
/**
 * Kohevo Studio (studio-builder) — Throttle for security-denial audit rows.
 *
 * A denial is audited so an operator can see a signed-in user being refused
 * (CSRF / cross-site POST, missing permission, missing entitlement, rate
 * limit). An automated client can produce thousands of refusals a minute, so
 * one audit row is written per denial class per window and the rows that were
 * skipped in between are counted onto the next one — the log keeps the signal
 * without letting a refused client grow the audit table at request rate.
 *
 * Pure: the caller owns the state array (the builder keeps it in the session).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

final class StudioDenialAudit
{
    public const WINDOW_SECONDS = 60;

    /**
     * Write one `studio.denied.<code>` audit row through the platform's AuditLog
     * unless this class was already audited inside the window. The meta is
     * exactly what the caller passes (the API passes status/method/action/
     * request id — never a body, header, token or secret).
     *
     * @param array<string, array{t: int, n: int}>|null $state
     * @param array<string, mixed>                      $meta
     * @return bool whether a row was written
     */
    public static function record(?array &$state, string $code, array $meta, ?int $now = null): bool
    {
        $skipped = self::admit($state, $code, $now ?? time());
        if ($skipped === null) {
            return false;
        }
        if ($skipped > 0) {
            $meta['suppressed_since_last'] = $skipped;
        }
        \AuditLog::record('studio.denied.' . $code, '', $meta);
        return true;
    }

    /**
     * @param array<string, array{t: int, n: int}>|null $state per-denial-code throttle state (updated in place)
     * @return int|null null = skip this denial; otherwise the number of denials of this class skipped since the last row
     */
    public static function admit(?array &$state, string $code, int $now): ?int
    {
        $state = is_array($state) ? $state : [];
        $entry = $state[$code] ?? null;
        if (is_array($entry) && isset($entry['t'], $entry['n']) && $now - (int) $entry['t'] < self::WINDOW_SECONDS && $now >= (int) $entry['t']) {
            $state[$code]['n'] = (int) $entry['n'] + 1;
            return null;
        }
        $skipped = is_array($entry) ? (int) ($entry['n'] ?? 0) : 0;
        $state[$code] = ['t' => $now, 'n' => 0];
        return $skipped;
    }
}
