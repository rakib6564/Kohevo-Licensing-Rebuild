<?php
/**
 * Kohevo Studio (studio-builder) — Render Execution Context.
 *
 * Carries WHO a render is for and under WHICH runtime rules, so the same
 * pipeline can serve three explicitly separated audiences:
 *
 *  | mode    | actor                | data context                        |
 *  |---------|----------------------|-------------------------------------|
 *  | Editor  | authenticated author | actor permissions (studio-builder.*)|
 *  | Preview | authenticated author | actor permissions (studio-builder.*)|
 *  | Public  | none (anonymous)     | public-marked providers only        |
 *
 * The public context is NOT a fake admin: it holds no actor and no
 * permissions. Its provider rule is structural — only providers implementing
 * `PublicDataProviderInterface` whose required permission is the read-level
 * `studio-builder.view` may run — so public rendering never passes through the
 * authenticated mutation command pipeline.
 *
 * Entitlement is always checked live for the context's tenant
 * (`EntitlementService::canAccess()`, which has no test/CLI bypass); a test may
 * inject a deterministic check instead.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Exception\StudioAuthenticationException;
use Slate\Module\StudioBuilder\Exception\StudioTenantScopeException;
use Slate\Module\StudioBuilder\Provider\DataProviderInterface;
use Slate\Module\StudioBuilder\Provider\PublicDataProviderInterface;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Licensing\EntitlementService;

final class RenderContext
{
    private readonly \Closure $entitlementCheck;

    private function __construct(
        public readonly RenderMode $mode,
        public readonly int $tenantId,
        public readonly SiteContext $site,
        public readonly ?StudioActor $actor,
        ?\Closure $entitlementCheck,
    ) {
        if ($tenantId <= 0) {
            throw new StudioTenantScopeException();
        }
        if ($mode->isPrivate() && ($actor === null || !$actor->isAuthenticated())) {
            throw new StudioAuthenticationException();
        }
        $this->entitlementCheck = $entitlementCheck
            ?? static fn(string $moduleKey): bool => EntitlementService::canAccess($tenantId, $moduleKey);
    }

    public static function forPublic(int $tenantId, SiteContext $site, ?\Closure $entitlementCheck = null): self
    {
        return new self(RenderMode::Public, $tenantId, $site, null, $entitlementCheck);
    }

    public static function forPreview(int $tenantId, SiteContext $site, StudioActor $actor, ?\Closure $entitlementCheck = null): self
    {
        return new self(RenderMode::Preview, $tenantId, $site, $actor, $entitlementCheck);
    }

    public static function forEditor(int $tenantId, SiteContext $site, StudioActor $actor, ?\Closure $entitlementCheck = null): self
    {
        return new self(RenderMode::Editor, $tenantId, $site, $actor, $entitlementCheck);
    }

    /** Live entitlement check for this context's tenant; any licensing failure is a denial. */
    public function isEntitled(string $moduleKey): bool
    {
        try {
            return (bool) ($this->entitlementCheck)($moduleKey);
        } catch (\Throwable $ignored) {
            return false; // licensing failure must never grant access
        }
    }

    /** @return callable(string): bool */
    public function entitlementPredicate(): callable
    {
        return fn(string $moduleKey): bool => $this->isEntitled($moduleKey);
    }

    /**
     * The permission predicate handed to `DataProviderRegistry::resolve()` for
     * one specific provider in this context.
     *
     * @return callable(string): bool
     */
    public function providerPermissionPredicate(DataProviderInterface $provider): callable
    {
        if ($this->mode->isPublic()) {
            $publicSafe = $provider instanceof PublicDataProviderInterface;
            return static fn(string $permission): bool => $publicSafe && $permission === StudioPermissions::VIEW;
        }
        $actor = $this->actor;
        return static fn(string $permission): bool => $actor !== null && $actor->can($permission);
    }

    /**
     * Whether a node with `visibility.auth_state` is part of this audience's output.
     * Public output is always rendered for the anonymous audience (it is shared
     * and cacheable), so `authenticated`-only content is never included in it.
     * Authoring contexts show everything.
     */
    public function includesAuthState(string $authState): bool
    {
        if (!$this->mode->isPublic()) {
            return true;
        }
        return $authState !== 'authenticated';
    }

    /** Editor-only node metadata (`data-sb-node`) for canvas selection. */
    public function emitsNodeMetadata(): bool
    {
        return $this->mode === RenderMode::Editor;
    }

    /** Visible diagnostics for unavailable blocks — authoring contexts only. */
    public function showsDiagnostics(): bool
    {
        return $this->mode->isPrivate();
    }
}
