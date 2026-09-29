<?php
/**
 * Kohevo Studio (studio-builder) — Authentication Required Exception.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Exception;

final class StudioAuthenticationException extends StudioException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'Authentication is required to perform Kohevo Studio operations.',
        array $details = [],
    ) {
        parent::__construct($message, 'STUDIO_AUTHENTICATION_REQUIRED', 401, $details);
    }
}
