<?php
/**
 * Slate — Tenant context.
 *
 * The single source of truth for "which tenant is this request?" (multi-tenancy
 * doc §1). Exactly one tenant is current for the duration of a request. New code
 * asks the injected TenantContext instead of reading $_SERVER, a constant, or the
 * global current_tenant_id() directly.
 *
 * Phase 1 bridge: this WRAPS the existing global tenant mechanism
 * (current_tenant_id() / $GLOBALS['SLATE_TENANT_OVERRIDE'] from includes/helpers.php)
 * rather than reproducing it — so a legacy `current_tenant_id()` call and a new
 * `$tenants->id()` call always agree, including inside a runAs() switch. Depends on
 * nothing but Support (it is an instance, not a static facade — no ambient global
 * state introduced; the legacy global it bridges is the wrapped surface, per
 * platform-foundation §5).
 *
 * Two capabilities beyond reading the id:
 *  - runAs()        — run a callback AS a given tenant (cron sweeps, super-admin
 *                     tooling); both new and legacy reads see the switch.
 *  - withoutScope() — lift automatic tenant scoping for the base Repository. This
 *                     is the mechanism behind Repository::crossTenant(): the only,
 *                     greppable way isolation is deliberately dropped.
 */

declare(strict_types=1);

namespace Slate\Tenancy;

final class TenantContext
{
    /**
     * Re-entrant suspension depth for automatic tenant scoping. 0 = scoping on.
     * Instance state (not a mutable static) so it is container-managed and
     * multi-tenant safe.
     */
    private int $scopeSuspensions = 0;

    /**
     * The id of the current tenant. Delegates to the legacy current_tenant_id()
     * when the shell is booted (so new and legacy code share one answer); falls
     * back to the same resolution logic when running isolated (unit tests, CLI
     * before bootstrap).
     */
    public function id(): int
    {
        if (function_exists('current_tenant_id')) {
            return current_tenant_id();
        }
        // Isolated fallback — mirrors includes/helpers.php current_tenant_id().
        if (!empty($GLOBALS['SLATE_TENANT_OVERRIDE'])) {
            return (int) $GLOBALS['SLATE_TENANT_OVERRIDE'];
        }
        return defined('TENANT_ID') ? TENANT_ID : 1;
    }

    /**
     * Run $fn as $tenantId, restoring the previous tenant afterwards (even if $fn
     * throws). Sets the same global override current_tenant_id() reads, so a
     * legacy query issued inside $fn is scoped to $tenantId too. Returns $fn()'s
     * value.
     */
    public function runAs(int $tenantId, callable $fn): mixed
    {
        $previous = $GLOBALS['SLATE_TENANT_OVERRIDE'] ?? null;
        $GLOBALS['SLATE_TENANT_OVERRIDE'] = $tenantId;
        try {
            return $fn();
        } finally {
            if ($previous === null) {
                unset($GLOBALS['SLATE_TENANT_OVERRIDE']);
            } else {
                $GLOBALS['SLATE_TENANT_OVERRIDE'] = $previous;
            }
        }
    }

    /**
     * Run $fn with automatic tenant scoping suspended, restoring it afterwards
     * (even on throw). Re-entrant. Returns $fn()'s value. Intended to be called
     * only via Repository::crossTenant() so every escape is audited + greppable.
     */
    public function withoutScope(callable $fn): mixed
    {
        $this->scopeSuspensions++;
        try {
            return $fn();
        } finally {
            $this->scopeSuspensions--;
        }
    }

        /**
     * Build the complete request scope for a site and deployment environment.
     * Site and environment are explicit inputs; they are never inferred from a
     * browser request or silently defaulted for application-service calls.
     */
    public function scope(string $siteId, string $environment): ScopeContext
    {
        return new ScopeContext($this->id(), $siteId, $environment);
    }

    /** True when automatic tenant scoping is active (i.e. not inside withoutScope). */
    public function isScoped(): bool {
        return $this->scopeSuspensions === 0;
    }
}
