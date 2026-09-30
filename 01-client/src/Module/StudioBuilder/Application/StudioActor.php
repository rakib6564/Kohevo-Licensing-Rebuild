<?php
/**
 * Kohevo Studio (studio-builder) — Authenticated Actor Value Object.
 *
 * The explicit, testable stand-in for "who is calling this command." Every
 * `StudioApplicationService` command takes one of these instead of reaching
 * into global session state (`\Auth::`) itself — that keeps the application
 * boundary fully unit-testable (authenticated/unauthenticated, entitled/not,
 * every permission combination) without faking a PHP session, and keeps
 * `\Auth::` a single, explicit call site (`fromCurrentSession()`) rather than
 * scattered throughout the command layer.
 *
 * A guest actor (`StudioActor::guest()`) is a valid, constructible value —
 * "no one is logged in" is data, not a null the caller has to special-case.
 *
 * Phase 7 (AI + MCP) makes the actor's ORIGIN explicit. There is no `isAI`
 * boolean; every actor carries exactly one origin and one principal:
 *
 *   origin            principal            user_id               super-admin
 *   ─────────────────────────────────────────────────────────────────────────
 *   session           the user             the signed-in user    session semantics
 *   mcp_token         the MCP token id     the token's issuer    never
 *   admin_assistant   the signed-in user   the signed-in user    session semantics
 *
 * An MCP-token actor's permissions are the token's Studio scopes INTERSECTED
 * with the issuing user's CURRENT Studio permissions (computed by the caller,
 * see `Mcp\IssuerAuthority`), and it can never be a super admin: a token is
 * at most as powerful as the human who issued it, never more. An admin
 * assistant delegates for the signed-in human with that human's current
 * permissions — the origin only changes how the write is RECORDED
 * (`ai_operation` revisions, audit attribution), never what it may do.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Application;

use Slate\Module\StudioBuilder\StudioPermissions;

final class StudioActor
{
    /** A human working in the Builder (or any web session). */
    public const ORIGIN_SESSION = 'session';
    /** An external MCP client authenticated with a gateway bearer token. */
    public const ORIGIN_MCP_TOKEN = 'mcp_token';
    /** The in-app admin AI assistant acting for the signed-in human. */
    public const ORIGIN_ADMIN_ASSISTANT = 'admin_assistant';

    public const ORIGINS = [
        self::ORIGIN_SESSION,
        self::ORIGIN_MCP_TOKEN,
        self::ORIGIN_ADMIN_ASSISTANT,
    ];

    /** Origins whose draft writes are recorded as `ai_operation` revisions. */
    public const AI_ORIGINS = [
        self::ORIGIN_MCP_TOKEN,
        self::ORIGIN_ADMIN_ASSISTANT,
    ];

    /**
     * @param list<string> $permissions Granted `studio-builder.*` permission keys.
     */
    private function __construct(
        public readonly ?int $userId,
        private readonly array $permissions,
        private readonly bool $isSuperAdmin,
        private readonly string $origin,
        private readonly ?int $principalId,
        private readonly ?int $tokenId,
    ) {}

    /**
     * A human session actor (origin `session`). Unchanged Phase 3 signature.
     *
     * @param list<string> $permissions
     */
    public static function authenticated(int $userId, array $permissions = [], bool $isSuperAdmin = false): self
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('StudioActor::authenticated() requires a positive userId; use StudioActor::guest() for no actor.');
        }
        return new self($userId, self::normalizePermissions($permissions), $isSuperAdmin, self::ORIGIN_SESSION, $userId, null);
    }

    /**
     * An explicit "not logged in" actor. `isAuthenticated()` is false and
     * `can()` is always false, regardless of any permission list — a guest
     * cannot be granted permissions.
     */
    public static function guest(): self
    {
        return new self(null, [], false, self::ORIGIN_SESSION, null, null);
    }

    /**
     * An external MCP client (origin `mcp_token`). The principal is the token;
     * the delegating user is the token's issuer; `$effectivePermissions` must
     * already be the intersection of the token's Studio scopes and the
     * issuer's CURRENT Studio permissions (never widened here). A token actor
     * is never a super admin, whatever the issuer is.
     *
     * @param list<string> $effectivePermissions
     */
    public static function forMcpToken(int $tokenId, int $issuerUserId, array $effectivePermissions): self
    {
        if ($tokenId <= 0) {
            throw new \InvalidArgumentException('StudioActor::forMcpToken() requires a positive tokenId.');
        }
        if ($issuerUserId <= 0) {
            throw new \InvalidArgumentException('StudioActor::forMcpToken() requires the positive id of the issuing user.');
        }
        return new self($issuerUserId, self::normalizePermissions($effectivePermissions), false, self::ORIGIN_MCP_TOKEN, $tokenId, $tokenId);
    }

    /**
     * The same human, acting through the admin AI assistant (origin
     * `admin_assistant`): identical identity, permissions and super-admin
     * semantics — only the recorded origin differs. A guest stays a guest.
     */
    public function asAdminAssistant(): self
    {
        if (!$this->isAuthenticated()) {
            return self::guest();
        }
        if ($this->origin === self::ORIGIN_MCP_TOKEN) {
            throw new \LogicException('An MCP-token actor cannot be re-labelled as the admin assistant.');
        }
        return new self($this->userId, $this->permissions, $this->isSuperAdmin, self::ORIGIN_ADMIN_ASSISTANT, $this->userId, null);
    }

    /**
     * Bridge to the real, session-backed `\Auth` for production callers
     * (the builder's HTTP controllers, the admin assistant). Resolves the
     * current admin's granted `studio-builder.*` permissions via `\Auth::can()`
     * one key at a time — `\Auth` has no bulk "permissions for this key
     * prefix" query, and the Studio permission set is fixed and small (5
     * keys), so this is exact rather than a guess.
     */
    public static function fromCurrentSession(): self
    {
        if (!class_exists('Auth') || !\Auth::check()) {
            return self::guest();
        }
        $userId = \Auth::userId();
        if ($userId === null) {
            return self::guest();
        }
        $granted = [];
        foreach (StudioPermissions::ALL as $perm) {
            if (\Auth::can($perm)) {
                $granted[] = $perm;
            }
        }
        return self::authenticated($userId, $granted, \Auth::isSuperAdmin());
    }

    public function isAuthenticated(): bool
    {
        return $this->userId !== null;
    }

    public function can(string $permission): bool
    {
        if (!$this->isAuthenticated()) {
            return false;
        }
        if ($this->isSuperAdmin) {
            return true;
        }
        return in_array($permission, $this->permissions, true);
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function origin(): string
    {
        return $this->origin;
    }

    /** The token id (mcp_token) or the user id (session / admin_assistant); null for a guest. */
    public function principalId(): ?int
    {
        return $this->principalId;
    }

    /** The MCP token this actor authenticates with, or null for every other origin. */
    public function tokenId(): ?int
    {
        return $this->tokenId;
    }

    public function isSuperAdmin(): bool
    {
        return $this->isAuthenticated() && $this->isSuperAdmin;
    }

    /** True for the origins whose draft writes are recorded as `ai_operation` revisions. */
    public function isAiOrigin(): bool
    {
        return in_array($this->origin, self::AI_ORIGINS, true);
    }

    /**
     * The explicit attribution every Studio audit event carries: who
     * (delegating user), through what (origin) and, for an external client,
     * which token. Never contains a secret.
     *
     * @return array{origin: string, user_id: ?int, token_id: ?int}
     */
    public function auditAttribution(): array
    {
        return [
            'origin'   => $this->origin,
            'user_id'  => $this->userId,
            'token_id' => $this->tokenId,
        ];
    }

    /**
     * @param array<mixed> $permissions
     * @return list<string>
     */
    private static function normalizePermissions(array $permissions): array
    {
        $out = [];
        foreach ($permissions as $perm) {
            if (is_string($perm) && $perm !== '' && !in_array($perm, $out, true)) {
                $out[] = $perm;
            }
        }
        return $out;
    }
}
