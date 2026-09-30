<?php
/**
 * Kohevo Studio (studio-builder) — Dynamic Data Provider Contract.
 *
 * A provider is a thin, transport-neutral adapter from a Studio document
 * binding (target architecture §8, e.g. `{"provider": "booking.services"}`)
 * onto an EXISTING Kohevo business service — never a repository, never raw
 * SQL, never a second implementation of business logic. See
 * `Slate\Module\StudioBuilder\Provider\DataProviderRegistry` for the checked
 * execution pipeline (entitlement → permission → parameter validation →
 * bounded execution) every provider runs through.
 *
 * Providers must never:
 * - accept an arbitrary table/SQL/repository/class/method name from a caller;
 * - expose a raw database row unnecessarily (map to a stable, minimal shape);
 * - return an unbounded result set (`maxResults()` is enforced by the registry
 *   as a hard backstop, independent of what the provider's own query does).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Provider;

use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Tenancy\TenantContext;

interface DataProviderInterface
{
    /**
     * Namespaced provider key (e.g. 'booking.services'), matching
     * `CanonicalDocumentSchema::PROVIDER_KEY_PATTERN`.
     */
    public function key(): string;

    /**
     * Declarative schema for this provider's `params`. Reuses `FieldSchema` —
     * the same typed, fail-closed validation Studio block props already get.
     */
    public function parameterSchema(): FieldSchema;

    /**
     * Commercial module entitlement key required to call this provider
     * (e.g. 'booking'), or null if no entitlement beyond `studio-builder`
     * itself is required.
     */
    public function requiredEntitlement(): ?string;

    /**
     * Studio RBAC permission key required to call this provider (typically
     * `StudioPermissions::VIEW` for a read-only provider).
     */
    public function requiredPermission(): string;

    /**
     * Hard upper bound on the number of result rows this provider may ever
     * return. Enforced by `DataProviderRegistry` regardless of what the
     * provider itself does internally.
     */
    public function maxResults(): int;

    /**
     * Execute the provider within the given (already-scoped) tenant context
     * and validated, normalized parameters. Must call an existing Kohevo
     * business service (e.g. `BookingAPI::getActiveServices()`) — never a
     * repository or raw SQL — and return a list of stable-shape, transport-
     * neutral rows (no PHP objects, closures, or raw database columns beyond
     * what the shape explicitly declares).
     *
     * @param array<string, mixed> $params Already validated against parameterSchema().
     * @return list<array<string, mixed>>
     */
    public function execute(TenantContext $tenants, array $params): array;
}
