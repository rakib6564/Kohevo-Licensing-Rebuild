<?php
/**
 * Kohevo Studio (studio-builder) — The CURRENT Studio permissions of the
 * human who issued an MCP token (Phase 7).
 *
 * `\Auth::can()` answers only for the signed-in session. An external MCP
 * call has no session: the token carries `created_by`, and the token's
 * Studio authority must be re-derived from that user's permissions AT CALL
 * TIME (not at token creation) so that demoting, suspending or deleting the
 * issuer immediately shrinks what the token can do, and promoting the issuer
 * never widens a token beyond its own scopes. The resolution mirrors
 * `\Auth::can()` exactly: legacy Super Admin (role 1) and platform admins
 * hold every permission; anyone else holds the `granted` rows of their role
 * in THEIR tenant. A user who is not active in the token's tenant holds
 * nothing (fail closed). Super-admin status is deliberately NOT returned as
 * a flag — a token actor is never a super admin; the issuer's status only
 * ever yields the bounded Studio permission list.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Mcp;

use Slate\Module\StudioBuilder\StudioPermissions;

final class IssuerAuthority
{
    private function __construct() {}

    /**
     * @return list<string> the issuer's current `studio-builder.*` permissions in `$tenantId`
     */
    public static function studioPermissionsForUser(int $userId, int $tenantId): array
    {
        if ($userId <= 0 || $tenantId <= 0 || !class_exists('Database')) {
            return [];
        }
        $user = \Database::row(
            "SELECT id, role_id FROM users WHERE id = ? AND tenant_id = ? AND status = 'active'",
            [$userId, $tenantId]
        );
        if (!is_array($user) || (int) ($user['id'] ?? 0) !== $userId) {
            return [];
        }
        $roleId = (int) ($user['role_id'] ?? 0);
        if ($roleId === 1 || self::isPlatformAdmin($userId)) {
            return StudioPermissions::ALL;
        }
        if ($roleId <= 0) {
            return [];
        }
        $rows = \Database::rows(
            "SELECT rp.perm_key
               FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id AND r.tenant_id = ?
              WHERE rp.role_id = ? AND rp.granted = 1",
            [$tenantId, $roleId]
        );
        $granted = array_fill_keys(array_map('strval', array_column($rows, 'perm_key')), true);
        return array_values(array_filter(StudioPermissions::ALL, static fn(string $perm): bool => isset($granted[$perm])));
    }

    private static function isPlatformAdmin(int $userId): bool
    {
        try {
            return (bool) \Database::value('SELECT 1 FROM platform_admins WHERE user_id = ? LIMIT 1', [$userId]);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
