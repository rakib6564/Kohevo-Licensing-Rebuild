<?php
/**
 * Reading the signed-in user must not end an open transaction.
 *
 * Auth::check() builds a SessionRepository on every call, and its
 * constructor used to run CREATE TABLE IF NOT EXISTS admin_sessions each
 * time. MySQL commits any open transaction on DDL, so a plugin that began a
 * transaction and then audited (AuditLog::record() -> Auth::userId()) had
 * its work committed halfway, and its own commit()/rollBack() threw "There
 * is no active transaction". Found building restaurant channel intake.
 *
 * Signs in the same way as StripePermissionSplitTest.php: a real
 * admin_sessions row for the current PHP session, so Auth::check() takes
 * the full validation path.
 */

declare(strict_types=1);

use Slate\Services\Audit\AuditLog;
use Slate\Services\Auth\Auth;
use Slate\Services\Auth\SessionRepository;
use Slate\Tenancy\TenantContext;

unit('Auth::user() and AuditLog::record() keep an open transaction open', function () {
    $tid = current_tenant_id();
    $admin = Database::row("SELECT id FROM users WHERE tenant_id = ? AND status = 'active' AND role_id > 0 LIMIT 1", [$tid]);
    if ($admin === null) throw new RuntimeException('no active admin in the test fixture');
    $userId = (int) $admin['id'];

    Auth::startSession();
    $prior = $_SESSION['slate_user'] ?? null;
    $_SESSION['slate_user'] = ['id' => $userId, 'tenant_id' => $tid, 'email' => 'aitt@example.test', 'role_id' => 1];
    Auth::invalidatePermCache();
    $sid = (new SessionRepository(new TenantContext()))->register($userId, session_id(), 'AITT test', '127.0.0.1', 'SlateTest/1');

    $action = '__probe.aitt.' . bin2hex(random_bytes(4));
    $pdo = Database::get();
    try {
        $pdo->beginTransaction();
        try {
            assert_eq($userId, (int) (Auth::user()['id'] ?? 0), 'signed-in user is read inside the transaction');
            assert_true($pdo->inTransaction(), 'transaction still open after Auth::user()');

            $auditId = AuditLog::record($action, 'aitt');
            assert_true($auditId > 0, 'audit row written inside the transaction');
            assert_eq($userId, (int) Database::value('SELECT user_id FROM audit_log WHERE id = ?', [$auditId]), 'audit row carries the signed-in user');
            assert_true($pdo->inTransaction(), 'transaction still open after AuditLog::record()');

            // Many calls per request, as a real page makes.
            for ($i = 0; $i < 5; $i++) { Auth::userId(); Auth::check(); }
            assert_true($pdo->inTransaction(), 'transaction still open after repeated auth reads');
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
        assert_eq(0, (int) Database::value('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]), 'rollback discards the audit row');
    } finally {
        Database::query('DELETE FROM audit_log WHERE action = ?', [$action]);
        Database::query('DELETE FROM admin_sessions WHERE id = ? AND tenant_id = ?', [$sid, $tid]);
        if ($prior !== null) { $_SESSION['slate_user'] = $prior; } else { unset($_SESSION['slate_user']); }
        Auth::invalidatePermCache();
    }
});
