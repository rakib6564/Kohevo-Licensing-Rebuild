<?php
/**
 * Kohevo Studio (studio-builder) — Tenant Scope Violation Exception.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Exception;

final class StudioTenantScopeException extends StudioException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'A valid scoped TenantContext is required for Kohevo Studio operations.',
        array $details = [],
    ) {
        parent::__construct($message, 'STUDIO_TENANT_SCOPE_REQUIRED', 403, $details);
    }
}
