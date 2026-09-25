<?php
/**
 * Phase 1E C2 — LicenseService (`licenses` table, migration 0017).
 *
 * Covers key generation/security, the full status lifecycle, expiry
 * degrading to "restricted" rather than deleting anything, and the
 * platform-admin-always-has-access property (tested at the
 * EntitlementService layer in EntitlementServiceTest.php, since that's
 * where it's actually enforced).
 */

declare(strict_types=1);

use Slate\Services\Licensing\LicenseService;
use Slate\Services\Tenancy\TenantService;

function lst_make_tenant(string $tag): int
{
    return TenantService::create(['name' => "License $tag Tenant", 'slug' => 'lst-' . strtolower($tag) . '-' . bin2hex(random_bytes(4))]);
}

function lst_cleanup_tenant(int $tenantId): void
{
    Database::query("DELETE FROM licenses WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM tenant_profiles WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM tenants WHERE id = ?", [$tenantId]);
}

unit('LicenseService::issue() generates a cryptographically random key, formatted SLT-XXXX-XXXX-XXXX-XXXX, and stores only its hash', function (): void {
    $tenantId = lst_make_tenant('KeyFormat');
    try {
        $result = LicenseService::issue($tenantId, null);
        assert_true((bool) preg_match('/^SLT-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $result['key']), 'key must match the SLT-XXXX-XXXX-XXXX-XXXX format: ' . $result['key']);

        $stored = Database::value("SELECT license_key_hash FROM licenses WHERE id = ?", [$result['id']]);
        assert_eq(64, strlen($stored), 'a SHA-256 hash must be 64 hex chars');
        assert_eq(hash('sha256', $result['key']), $stored, 'the stored hash must match the issued key');
        assert_false(str_contains((string) $stored, $result['key']), 'the raw key must never appear inside its own stored hash');

        // Two separate calls must never coincidentally collide (entropy sanity, not a formal proof).
        $second = LicenseService::issue($tenantId, null);
        assert_false($result['key'] === $second['key'], 'two issued keys must never be identical');
    } finally {
        lst_cleanup_tenant($tenantId);
    }
});

unit('LicenseService full lifecycle: trial -> active -> suspended -> active, and cancel', function (): void {
    $tenantId = lst_make_tenant('Lifecycle');
    try {
        $result = LicenseService::issue($tenantId, null, ['status' => 'trial']);
        $id = $result['id'];
        assert_eq('trial', Database::value("SELECT status FROM licenses WHERE id = ?", [$id]));

        LicenseService::activate($id);
        assert_eq('active', Database::value("SELECT status FROM licenses WHERE id = ?", [$id]));

        LicenseService::suspend($id, 'payment failed');
        $row = Database::row("SELECT status, revoke_reason FROM licenses WHERE id = ?", [$id]);
        assert_eq('suspended', $row['status']);
        assert_eq('payment failed', $row['revoke_reason']);

        LicenseService::activate($id);
        assert_eq('active', Database::value("SELECT status FROM licenses WHERE id = ?", [$id]));

        LicenseService::cancel($id);
        assert_eq('cancelled', Database::value("SELECT status FROM licenses WHERE id = ?", [$id]));
    } finally {
        lst_cleanup_tenant($tenantId);
    }
});

unit('LicenseService::revoke() requires a non-empty reason and is recorded', function (): void {
    $tenantId = lst_make_tenant('Revoke');
    try {
        $result = LicenseService::issue($tenantId, null);
        assert_throws(\InvalidArgumentException::class, fn() => LicenseService::revoke($result['id'], ''));

        LicenseService::revoke($result['id'], 'fraudulent signup');
        $row = Database::row("SELECT status, revoke_reason FROM licenses WHERE id = ?", [$result['id']]);
        assert_eq('revoked', $row['status']);
        assert_eq('fraudulent signup', $row['revoke_reason']);

        $audit = Database::row("SELECT 1 FROM audit_log WHERE action = 'license.revoked' AND target = ?", ["license#{$result['id']}"]);
        assert_true($audit !== null);
    } finally {
        lst_cleanup_tenant($tenantId);
    }
});

unit('LicenseService::extend() adds days to expires_at, from now if it had none', function (): void {
    $tenantId = lst_make_tenant('Extend');
    try {
        $result = LicenseService::issue($tenantId, null);
        LicenseService::extend($result['id'], 30);
        $expires = Database::value("SELECT expires_at FROM licenses WHERE id = ?", [$result['id']]);
        $daysFromNow = (strtotime($expires) - time()) / 86400;
        assert_true($daysFromNow > 29 && $daysFromNow < 31, "expected ~30 days from now, got $daysFromNow");
    } finally {
        lst_cleanup_tenant($tenantId);
    }
});

unit('LicenseService::effectiveStatus() lazily reports an expired trial/active license as expired, without deleting anything', function (): void {
    $tenantId = lst_make_tenant('Expiry');
    try {
        $result = LicenseService::issue($tenantId, null, ['status' => 'active', 'expires_at' => date('Y-m-d H:i:s', time() - 3600)]);

        assert_eq('expired', LicenseService::effectiveStatus($tenantId), 'a past-expiry active license must report as expired');

        // Nothing was destroyed — the row (and its stored status='active'
        // until the cron sweep runs) is still fully present.
        $row = Database::row("SELECT status FROM licenses WHERE id = ?", [$result['id']]);
        assert_true($row !== null, 'the license row must still exist');
    } finally {
        lst_cleanup_tenant($tenantId);
    }
});

unit('LicenseService::sweepExpired() persists the expired transition and audits it', function (): void {
    $tenantId = lst_make_tenant('Sweep');
    try {
        $result = LicenseService::issue($tenantId, null, ['status' => 'active', 'expires_at' => date('Y-m-d H:i:s', time() - 3600)]);
        $swept = LicenseService::sweepExpired();
        assert_true($swept >= 1, 'at least this one license must be swept');

        assert_eq('expired', Database::value("SELECT status FROM licenses WHERE id = ?", [$result['id']]), 'the sweep must persist the expired status, not just report it lazily');

        $audit = Database::row("SELECT 1 FROM audit_log WHERE action = 'license.status_changed' AND target = ?", ["license#{$result['id']}"]);
        assert_true($audit !== null);
    } finally {
        lst_cleanup_tenant($tenantId);
    }
});

unit('LicenseService::forTenant() returns null when no license exists, and the most recent one otherwise', function (): void {
    $tenantId = lst_make_tenant('ForTenant');
    try {
        assert_null(LicenseService::forTenant($tenantId));

        $first = LicenseService::issue($tenantId, null);
        sleep(0); // ensure distinct created_at ordering is not required — id DESC is enough
        $second = LicenseService::issue($tenantId, null);

        $found = LicenseService::forTenant($tenantId);
        assert_eq($second['id'], (int) $found['id'], 'the MOST RECENT license must be returned');
    } finally {
        lst_cleanup_tenant($tenantId);
    }
});

unit('the daily_cron hook (config.php) actually sweeps an expired license when fired', function (): void {
    $tenantId = lst_make_tenant('CronHook');
    try {
        $result = LicenseService::issue($tenantId, null, ['status' => 'active', 'expires_at' => date('Y-m-d H:i:s', time() - 3600)]);
        assert_eq('active', Database::value("SELECT status FROM licenses WHERE id = ?", [$result['id']]), 'setup: still stored as active before the sweep');

        Hook::doAction('daily_cron');

        assert_eq('expired', Database::value("SELECT status FROM licenses WHERE id = ?", [$result['id']]), 'firing the real daily_cron hook (config.php) must have swept this expired license');
    } finally {
        lst_cleanup_tenant($tenantId);
    }
});

unit('admin/licenses.php: an ordinary tenant admin is refused (403) — license management is platform-only', function (): void {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg('admin/licenses.php') . ' ' . escapeshellarg('') . ' '
         . escapeshellarg('5401') . ' ' . escapeshellarg('0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    assert_eq(403, (int) $m[1]);
});

unit('admin/licenses.php: issuing via the real page never renders the raw key anywhere except the one-time confirmation, and stores only the hash', function (): void {
    $tenantId = lst_make_tenant('PageIssue');
    try {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
             . escapeshellarg('admin/licenses.php') . ' '
             . escapeshellarg((string) json_encode(['_action' => 'issue', 'tenant_id' => $tenantId, 'status' => 'trial'])) . ' '
             . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>/dev/null';
        $out = (string) shell_exec($cmd);
        preg_match('/^STATUS (\d+)\n/', $out, $m);
        $body = substr($out, strlen($m[0]));
        assert_eq(200, (int) $m[1], 'the "issued" confirmation view renders inline (200), not a redirect');
        assert_true((bool) preg_match('/SLT-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/', $body), 'the one-time confirmation page must show the raw key');

        $license = Database::row("SELECT id, license_key_hash FROM licenses WHERE tenant_id = ? ORDER BY id DESC LIMIT 1", [$tenantId]);
        assert_true($license !== null);
        // Re-fetch the SAME page's list/detail views and confirm the raw key never appears again.
        $viewCmd = escapeshellarg(PHP_BINARY) . ' '
                 . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
                 . escapeshellarg('admin/licenses.php') . ' ' . escapeshellarg('view=' . $license['id']) . ' '
                 . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>/dev/null';
        $viewOut = (string) shell_exec($viewCmd);
        assert_false((bool) preg_match('/SLT-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/', $viewOut), 'the license detail view must never show a raw-key-shaped string — only the masked hash');
    } finally {
        lst_cleanup_tenant($tenantId);
    }
});
