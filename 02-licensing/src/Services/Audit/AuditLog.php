<?php
/**
 * Slate — AuditLog.
 *
 * Tenant-scoped append-only log of significant actions. Used by the shell for
 * plugin install/activate/uninstall events, login attempts, settings changes,
 * and by plugins for their own audit trails. Reads are admin-only (audit.view).
 *
 * Phase 1 A3: migrated from includes/AuditLog.php into Slate\Services\Audit. The
 * old global name `AuditLog` resolves via a class_alias in
 * src/compat/aliases.php. Behavior identical — only the cross-class static calls
 * (`Auth::`, `Database::`) are qualified to the global aliases (`\Auth::`,
 * `\Database::`).
 */

declare(strict_types=1);

namespace Slate\Services\Audit;

class AuditLog
{
    public static function record(string $action, string $target = '', array $meta = []): int
    {
        try {
            $userId = class_exists('Auth') ? \Auth::userId() : null;
            $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
            return \Database::insert('audit_log', [
                'tenant_id' => current_tenant_id(),
                'user_id'   => $userId,
                'action'    => mb_substr($action, 0, 120),
                'target'    => $target !== '' ? mb_substr($target, 0, 190) : null,
                'meta_json' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                'ip'        => $ip,
            ]);
        } catch (\Throwable $e) {
            // Audit write must not fail callers. Log and move on.
            slate_log('Audit write failed: ' . $e->getMessage(), 'error');
            return 0;
        }
    }

    public static function recent(int $limit = 100, ?int $tenantId = null): array
    {
        if ($tenantId === null) $tenantId = current_tenant_id();
        $limit = max(1, min(1000, $limit));
        return \Database::rows(
            "SELECT al.*, u.name AS user_name, u.email AS user_email
             FROM audit_log al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.tenant_id = ?
             ORDER BY al.created_at DESC
             LIMIT $limit",
            [$tenantId]
        );
    }

    public static function search(array $filters = []): array
    {
        $tenantId = $filters['tenant_id'] ?? current_tenant_id();
        $action   = $filters['action']    ?? null;
        $userId   = $filters['user_id']   ?? null;
        $from     = $filters['from']      ?? null;
        $to       = $filters['to']        ?? null;
        $limit    = max(1, min(1000, (int)($filters['limit'] ?? 100)));

        $where  = ['al.tenant_id = ?'];
        $params = [$tenantId];

        if ($action) { $where[] = 'al.action = ?';      $params[] = $action; }
        if ($userId) { $where[] = 'al.user_id = ?';     $params[] = $userId; }
        if ($from)   { $where[] = 'al.created_at >= ?'; $params[] = $from; }
        if ($to)     { $where[] = 'al.created_at <= ?'; $params[] = $to; }

        $sql = "SELECT al.*, u.name AS user_name, u.email AS user_email
                FROM audit_log al
                LEFT JOIN users u ON u.id = al.user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY al.created_at DESC LIMIT $limit";

        return \Database::rows($sql, $params);
    }
}
