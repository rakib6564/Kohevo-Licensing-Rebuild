<?php

declare(strict_types=1);

use Slate\Services\Auth\SessionRepository;
use Slate\Services\Auth\Auth;
use Slate\Tenancy\TenantContext;

$tenants = new TenantContext();
$tid = current_tenant_id();
$admin = Database::row("SELECT id FROM users WHERE tenant_id=? AND status='active' AND role_id > 0 LIMIT 1", [$tid]);
if ($admin === null) throw new RuntimeException('Sanitized fixture must contain an active admin for session validation.');
$userId = (int) $admin['id'];
$repo = new SessionRepository($tenants);

unit('admin session inventory is tenant-bound and revocable', function () use ($tenants, $repo, $tid, $userId) {
    Database::query('DELETE FROM admin_sessions WHERE tenant_id=? AND user_id=?', [$tid, $userId]);
    try {
        $first = $repo->register($userId, 'test-session-one-' . $tid, 'Test browser', slate_test_ip('192.0.2.10'), 'SlateTest/1');
        $second = $repo->register($userId, 'test-session-two-' . $tid, 'Second browser', slate_test_ip('192.0.2.11'), 'SlateTest/1');
        assert_true($first > 0 && $second > 0, 'sessions register');
        $rotated = $repo->rotate($userId, 'test-session-one-' . $tid, 'test-session-one-rotated-' . $tid, 'Rotated browser', slate_test_ip('192.0.2.14'), 'SlateTest/1');
        assert_true($rotated > 0, 'session rotation registers replacement');
        assert_true((int)Database::value('SELECT COUNT(*) FROM admin_sessions WHERE id=? AND revoked_at IS NOT NULL', [$first]) === 1, 'prior session is revoked on rotation');
        assert_true(strtotime((string)Database::value('SELECT expires_at FROM admin_sessions WHERE id=?', [$rotated])) > time(), 'rotated session receives fresh expiry');
        assert_eq(2, count($repo->activeForUser($userId)), 'inventory lists active sessions');
        Auth::startSession();
        $_SESSION['slate_user'] = ['id' => $userId, 'tenant_id' => $tid, 'email' => 'admin@example.test', 'role_id' => 1];
        $current = $repo->register($userId, session_id(), 'Current browser', slate_test_ip('192.0.2.12'), 'SlateTest/1');
        $fixedExpiry = (string) Database::value('SELECT expires_at FROM admin_sessions WHERE id=?', [$current]);
        assert_true(Auth::check(), 'registered session passes authenticated check');
        assert_eq($fixedExpiry, (string) Database::value('SELECT expires_at FROM admin_sessions WHERE id=?', [$current]), 'activity does not slide fixed expiry');
        assert_true($repo->revokeById($userId, $current) === 1, 'current session revokes');
        assert_false(Auth::check(), 'revoked session fails subsequent authenticated check');
        unset($_SESSION['slate_user']);
        assert_true($repo->revokeById($userId, $second) === 1, 'one session revokes');
        assert_eq(1, count($repo->activeForUser($userId)), 'revoked session leaves active inventory');
        $other = $tid + 71000;
        assert_eq([], $tenants->runAs($other, fn () => (new SessionRepository($tenants))->activeForUser($userId)), 'other tenant cannot list sessions');
        Auth::startSession();
        $_SESSION['slate_user'] = ['id' => $userId, 'tenant_id' => $tid, 'email' => 'admin@example.test', 'role_id' => 1];
        Database::query('UPDATE admin_sessions SET revoked_at=NULL, expires_at=? WHERE id=? AND tenant_id=?', [date('Y-m-d H:i:s', time() + 3600), $current, $tid]);
        $expiring = $current;
        assert_true(Auth::check(), 'valid session passes and touches last-seen');
        Database::query('UPDATE admin_sessions SET expires_at=? WHERE id=? AND tenant_id=?', [date('Y-m-d H:i:s', time() - 60), $expiring, $tid]);
        assert_false(Auth::check(), 'expired session fails authenticated check');
        unset($_SESSION['slate_user']);
    } finally {
        Database::query('DELETE FROM admin_sessions WHERE tenant_id=? AND user_id=?', [$tid, $userId]);
    }
});
