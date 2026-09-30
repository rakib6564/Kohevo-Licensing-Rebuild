<?php
/**
 * Kohevo Studio (studio-builder) — MCP scope vocabulary and its explicit
 * mapping onto Studio RBAC permissions (Phase 7).
 *
 * A scope is what a gateway token was GRANTED; a permission is what Studio
 * ENFORCES. They are related one-to-one here and nowhere else, and a scope
 * never replaces the permission: an MCP-token actor's effective permissions
 * are `permissionsForScopes(token scopes) ∩ issuer's current permissions`
 * (see `IssuerAuthority` and `StudioMcpAdapter::actorFor()`), so a token is
 * never more capable than the human who issued it, and every call still
 * runs the full application pipeline (tenant → authentication →
 * entitlement → permission).
 *
 * `studio-builder.publish` is registered so the vocabulary is complete and a
 * future, separately approved release can use it; in this release NO tool
 * is granted by it (autonomous publish is disabled — see the tool catalog).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Mcp;

use Slate\Module\StudioBuilder\StudioPermissions;

final class StudioMcpScopes
{
    public const READ    = 'studio-builder.read';
    public const EDIT    = 'studio-builder.edit';
    public const PUBLISH = 'studio-builder.publish';
    public const TOKENS  = 'studio-builder.tokens';
    public const ADMIN   = 'studio-builder.admin';

    /** scope => Studio permission it maps to (exactly one each). */
    public const PERMISSION_FOR_SCOPE = [
        self::READ    => StudioPermissions::VIEW,
        self::EDIT    => StudioPermissions::EDIT,
        self::PUBLISH => StudioPermissions::PUBLISH,
        self::TOKENS  => StudioPermissions::TOKENS,
        self::ADMIN   => StudioPermissions::ADMIN,
    ];

    /** Labels shown in the gateway's token UI. */
    public const LABELS = [
        self::READ    => 'Studio: read pages, revisions, templates, components, tokens, previews and diffs',
        self::EDIT    => 'Studio: create and change DRAFTS (recorded as AI revisions; a human must publish)',
        self::PUBLISH => 'Studio: reserved — grants no tool in this release (AI cannot publish)',
        self::TOKENS  => 'Studio: change the tenant design tokens',
        self::ADMIN   => 'Studio: manage the template library',
    ];

    private function __construct() {}

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::PERMISSION_FOR_SCOPE);
    }

    public static function isStudioScope(string $scope): bool
    {
        return isset(self::PERMISSION_FOR_SCOPE[$scope]);
    }

    /**
     * The Studio permissions a set of granted scopes maps to (unknown scopes
     * contribute nothing; duplicates collapse).
     *
     * @param array<mixed> $scopes
     * @return list<string>
     */
    public static function permissionsForScopes(array $scopes): array
    {
        $out = [];
        foreach ($scopes as $scope) {
            if (is_string($scope) && isset(self::PERMISSION_FOR_SCOPE[$scope])) {
                $perm = self::PERMISSION_FOR_SCOPE[$scope];
                if (!in_array($perm, $out, true)) {
                    $out[] = $perm;
                }
            }
        }
        return $out;
    }
}
