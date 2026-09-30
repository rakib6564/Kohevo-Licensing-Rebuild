<?php
/**
 * Kohevo Studio (studio-builder) — The per-request correlation id.
 *
 * One opaque random token per PHP request, generated lazily the first time
 * Studio needs to log or report a failure. It ties a generic error response
 * (the `X-Request-Id` header of a Studio error) to the server-side log lines
 * and audit rows of that same request.
 *
 * It is NEVER derived from client input (an inbound `X-Request-Id` is ignored:
 * it would be log-injection / spoofing surface), carries no tenant, page,
 * user or time information, and is not an identifier for any Studio object.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

final class StudioRequestId
{
    private static ?string $id = null;

    /** 16 lowercase hex characters (64 random bits). */
    public static function current(): string
    {
        return self::$id ??= bin2hex(random_bytes(8));
    }

    /** Test seam only: forget the id so the next call mints a new one. */
    public static function reset(): void
    {
        self::$id = null;
    }
}
