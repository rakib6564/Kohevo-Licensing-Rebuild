<?php
/**
 * Immutable request scope for tenant-owned operations.
 *
 * This value object makes tenant, site, and environment binding explicit at the
 * application-service boundary. It is intentionally independent of the legacy
 * global TenantContext bridge so repositories can require the complete scope.
 */
declare(strict_types=1);

namespace Slate\Tenancy;

use InvalidArgumentException;

final readonly class ScopeContext
{
    public const ENVIRONMENTS = ['development', 'staging', 'production'];

    public function __construct(
        public int $tenantId,
        public string $siteId,
        public string $environment,
    ) {
        if ($tenantId < 1) {
            throw new InvalidArgumentException('tenantId must be a positive integer');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,95}$/', $siteId)) {
            throw new InvalidArgumentException('siteId contains unsupported characters or length');
        }
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new InvalidArgumentException('environment must be development, staging, or production');
        }
    }

    public function key(): string
    {
        return $this->tenantId . ':' . $this->siteId . ':' . $this->environment;
    }

    public function sameAs(self $other): bool
    {
        return $this->tenantId === $other->tenantId
            && $this->siteId === $other->siteId
            && $this->environment === $other->environment;
    }
}
