<?php
/**
 * Kohevo Studio (studio-builder) — Entity Not Found Exception.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Exception;

final class StudioNotFoundException extends StudioException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'The requested Kohevo Studio resource was not found.',
        array $details = [],
    ) {
        parent::__construct($message, 'STUDIO_NOT_FOUND', 404, $details);
    }
}
