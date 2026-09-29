<?php
/**
 * Kohevo Studio (studio-builder) — Commercial Entitlement Exception.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Exception;

final class StudioEntitlementException extends StudioException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'Kohevo Studio is not enabled or entitled for this installation.',
        array $details = [],
    ) {
        parent::__construct($message, 'STUDIO_NOT_ENTITLED', 403, $details);
    }
}
