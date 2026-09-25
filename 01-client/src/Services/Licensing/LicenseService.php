<?php
/**
 * Slate — LicenseService (Phase 1E C2: the licensing engine).
 *
 * Owns `licenses` (migration 0017). The raw license key is generated with
 * random_bytes() and shown to the caller exactly ONCE, at issue() time —
 * only its SHA-256 hash is ever persisted, so a read of this table (a
 * backup, a misconfigured replica) never discloses a usable key. Every
 * decision here is server-side; there is no public validation endpoint in
 * this phase (admin-provisioned only, per the approved plan).
 *
 * Expiry never destroys data: expired()/effectiveStatus() feed
 * EntitlementService's resolution chain, which degrades a non-active
 * license to a minimal, explicitly-defined feature set rather than denying
 * everything — see EntitlementService::RESTRICTED_STATUSES.
 *
 * Layer: Services — depends on Data (Database) + Audit only.
 */

declare(strict_types=1);

namespace Slate\Services\Licensing;

final class LicenseService
{
    public const STATUSES = ['trial', 'active', 'expired', 'suspended', 'revoked', 'cancelled'];

    /**
     * Issue a new license for a tenant. Returns the RAW key (SLT-XXXX-XXXX-
     * XXXX-XXXX) — the only time it is ever available; the caller must show
     * it to the platform admin immediately, since it cannot be recovered
     * afterward (only its hash is stored).
     */
    public static function issue(int $tenantId, ?int $planId, array $opts = []): array
    {
        $rawKey = self::generateKey();
        $hash   = hash('sha256', $rawKey);

        $status = (string)($opts['status'] ?? 'trial');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'trial';
        }

        // anti-drift-ignore: TENANT — creating a license row for an arbitrary (platform-admin-specified) tenant; not scoped to the caller's own current tenant
        $licenseId = \Database::insert('licenses', [
            'tenant_id'         => $tenantId,
            'license_key_hash'  => $hash,
            'plan_id'           => $planId,
            'status'            => $status,
            'expires_at'        => $opts['expires_at'] ?? null,
            'activation_limit'  => max(1, (int)($opts['activation_limit'] ?? 1)),
            'metadata'          => isset($opts['metadata']) ? json_encode($opts['metadata']) : null,
        ]);

        \AuditLog::record('license.created', "license#$licenseId", ['tenant_id' => $tenantId, 'plan_id' => $planId, 'status' => $status]);

        return ['id' => $licenseId, 'key' => $rawKey];
    }

    /** Cryptographically-random key, formatted SLT-XXXX-XXXX-XXXX-XXXX. */
    private static function generateKey(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I — avoids transcription ambiguity
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $chars = '';
            $bytes = random_bytes(4);
            for ($i = 0; $i < 4; $i++) {
                $chars .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
            }
            $groups[] = $chars;
        }
        return 'SLT-' . implode('-', $groups);
    }

    public static function find(int $licenseId): ?array
    {
        // anti-drift-ignore: TENANT — platform-admin license management is inherently cross-tenant; looked up by the license's own id
        $row = \Database::row("SELECT * FROM licenses WHERE id = ?", [$licenseId]);
        return $row ?: null;
    }

    /** All licenses (optionally filtered), newest first. Platform-only. */
    public static function list(array $filters = []): array
    {
        $where  = [];
        $params = [];
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where[] = 'l.status = ?';
            $params[] = $status;
        }
        $tenantId = (int)($filters['tenant_id'] ?? 0);
        if ($tenantId > 0) {
            $where[] = 'l.tenant_id = ?';
            $params[] = $tenantId;
        }

        $sql = "SELECT l.*, t.name AS tenant_name, p.name AS plan_name
                  FROM licenses l
             LEFT JOIN tenants t ON t.id = l.tenant_id
             LEFT JOIN platform_plans p ON p.id = l.plan_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY l.created_at DESC, l.id DESC';

        // anti-drift-ignore: TENANT — this IS the cross-tenant listing platform admins need
        return \Database::rows($sql, $params);
    }

    /** The tenant's current license, if any (most recently created). */
    public static function forTenant(int $tenantId): ?array
    {
        // anti-drift-ignore: TENANT — looking up BY a specific tenant_id value (platform-admin/entitlement context), not scoped to the caller's own current_tenant_id()
        $row = \Database::row("SELECT * FROM licenses WHERE tenant_id = ? ORDER BY created_at DESC, id DESC LIMIT 1", [$tenantId]);
        return $row ?: null;
    }

    public static function activate(int $licenseId): void
    {
        self::setStatus($licenseId, 'active');
    }

    public static function suspend(int $licenseId, string $reason = ''): void
    {
        self::setStatus($licenseId, 'suspended', $reason);
    }

    public static function revoke(int $licenseId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required to revoke a license.');
        }
        self::setStatus($licenseId, 'revoked', $reason);
    }

    public static function cancel(int $licenseId): void
    {
        self::setStatus($licenseId, 'cancelled');
    }

    /** Extend the expiry by N days (license must already have an expires_at). */
    public static function extend(int $licenseId, int $days): void
    {
        $license = self::find($licenseId);
        if ($license === null) {
            throw new \InvalidArgumentException("No such license: $licenseId");
        }
        $base = !empty($license['expires_at']) ? strtotime((string)$license['expires_at']) : time();
        $newExpiry = date('Y-m-d H:i:s', $base + ($days * 86400));

        // anti-drift-ignore: TENANT — updates a specific license by its own id, for platform-admin management
        \Database::query("UPDATE licenses SET expires_at = ?, updated_at = ? WHERE id = ?", [$newExpiry, \slate_db_now(), $licenseId]);
        \AuditLog::record('license.renewed', "license#$licenseId", ['days' => $days, 'new_expires_at' => $newExpiry]);
    }

    private static function setStatus(int $licenseId, string $status, string $reason = ''): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown license status: $status");
        }
        $license = self::find($licenseId);
        if ($license === null) {
            throw new \InvalidArgumentException("No such license: $licenseId");
        }

        // anti-drift-ignore: TENANT — updates a specific license by its own id, for platform-admin management
        \Database::query(
            "UPDATE licenses SET status = ?, revoke_reason = ?, updated_at = ? WHERE id = ?",
            [$status, $reason !== '' ? $reason : null, \slate_db_now(), $licenseId]
        );

        $action = match ($status) {
            'active'    => 'license.activated',
            'suspended' => 'license.suspended',
            'revoked'   => 'license.revoked',
            'cancelled' => 'license.cancelled',
            default     => 'license.status_changed',
        };
        \AuditLog::record($action, "license#$licenseId", array_filter(['reason' => $reason ?: null]));
    }

    /**
     * The tenant's effective license status for entitlement purposes:
     * 'none' if no license exists, otherwise the stored status — EXCEPT an
     * 'active' or 'trial' license past its own expires_at is reported as
     * 'expired' here (lazily, on read) even before the daily_cron sweep
     * (registerCronJob()) gets to persist that transition. Also updates
     * last_validated_at as a side effect, matching "all licensing decisions
     * must be server-side" — every check is a real read, not a cached flag.
     */
    public static function effectiveStatus(int $tenantId): string
    {
        $license = self::forTenant($tenantId);
        if ($license === null) {
            return 'none';
        }

        // anti-drift-ignore: TENANT — touches a specific license row by its own id, for the license-validation side effect
        \Database::query("UPDATE licenses SET last_validated_at = ? WHERE id = ?", [\slate_db_now(), $license['id']]);

        $status = (string)$license['status'];
        if (in_array($status, ['trial', 'active'], true) && !empty($license['expires_at'])
            && strtotime((string)$license['expires_at']) <= time()) {
            return 'expired';
        }
        return $status;
    }

    /**
     * Sweep every trial/active license past its expires_at to 'expired'.
     * Registered on daily_cron (see LicensingBootstrap) — this is the only
     * place a license transitions to 'expired' in storage; effectiveStatus()
     * above reports it lazily in the meantime so entitlement checks are
     * never stale even between cron runs.
     */
    public static function sweepExpired(): int
    {
        // anti-drift-ignore: TENANT — a platform-wide maintenance sweep across every tenant's licenses, by design
        $expiring = \Database::rows(
            "SELECT id, tenant_id FROM licenses WHERE status IN ('trial','active') AND expires_at IS NOT NULL AND expires_at <= ?",
            [\slate_db_now()]
        );
        foreach ($expiring as $row) {
            // anti-drift-ignore: TENANT — updates a specific license by its own id, part of the same platform-wide sweep
            \Database::query("UPDATE licenses SET status = 'expired', updated_at = ? WHERE id = ?", [\slate_db_now(), $row['id']]);
            \AuditLog::record('license.status_changed', "license#{$row['id']}", ['to' => 'expired', 'reason' => 'expiry sweep']);
        }
        return count($expiring);
    }
}
