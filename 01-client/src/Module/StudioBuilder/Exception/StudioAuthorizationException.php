<?php
/**
 * Kohevo Studio (studio-builder) — RBAC Authorization Exception.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Exception;

final class StudioAuthorizationException extends StudioException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $permission,
        string $message = '',
        array $details = [],
    ) {
        $msg = $message !== ''
            ? $message
            : "Missing required Kohevo Studio permission '{$permission}'.";
        parent::__construct(
            $msg,
            'STUDIO_PERMISSION_DENIED',
            403,
            array_merge(['required_permission' => $permission], $details)
        );
    }
}
