<?php
/**
 * Kohevo Studio (studio-builder) — Optimistic Concurrency & Lock Conflict Exception.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Exception;

final class StudioConcurrencyException extends StudioException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'Kohevo Studio revision or lock conflict detected.',
        array $details = [],
    ) {
        parent::__construct($message, 'STUDIO_CONCURRENCY_CONFLICT', 409, $details);
    }
}
