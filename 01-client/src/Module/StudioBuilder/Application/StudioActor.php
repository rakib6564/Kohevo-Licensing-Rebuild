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
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Application;

use Slate\Module\StudioBuilder\StudioPermissions;

final class StudioActor
{
    /**
     * @param list<string> $permissions Granted `studio-builder.*` permission keys.
     */
    private function __construct(
        public readonly ?int $userId,
        private readonly array $permissions,
        private readonly bool $isSuperAdmin,
    ) {}

    /**
     * @param list<string> $permissions
     */
    public static function authenticated(int $userId, array $permissions = [], bool $isSuperAdmin = false): self
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('StudioActor::authenticated() requires a positive userId; use StudioActor::guest() for no actor.');
        }
        return new self($userId, array_values($permissions), $isSuperAdmin);
    }

    /**
     * An explicit "not logged in" actor. `isAuthenticated()` is false and
     * `can()` is always false, regardless of any permission list — a guest
     * cannot be granted permissions.
     */
    public static function guest(): self
    {
        return new self(null, [], false);
    }

    /**
     * Bridge to the real, session-backed `\Auth` for production callers
     * (a future HTTP controller or MCP tool). Resolves the current admin's
     * granted `studio-builder.*` permissions via `\Auth::can()` one key at a
     * time — `\Auth` has no bulk "permissions for this key prefix" query, and
     * the Studio permission set is fixed and small (5 keys), so this is exact
     * rather than a guess.
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
}
