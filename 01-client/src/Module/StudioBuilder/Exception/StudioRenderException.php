<?php
/**
 * Kohevo Studio (studio-builder) — Render / Compilation Failure Exception.
 *
 * Raised when a canonical document cannot be turned into output at all (an
 * undecodable document, an invalid document envelope, a compiler failure).
 * Individual bad blocks never raise this — they degrade to a non-executable
 * "unavailable" fallback instead. The message and details are for logs and
 * authorized preview only; the public runtime never echoes them.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Exception;

final class StudioRenderException extends StudioException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'The Studio document could not be rendered.',
        array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'STUDIO_RENDER_FAILED', 500, $details, $previous);
    }
}
