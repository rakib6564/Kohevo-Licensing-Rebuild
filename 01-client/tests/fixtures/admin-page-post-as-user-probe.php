<?php
/**
 * Sibling to admin-page-post-probe.php, for the one case that fixture can't
 * cover: acting as a SPECIFIC, already-existing user id (that fixture always
 * mints its own random fake id, so it can't be used to test a self-referential
 * action like "can a platform admin remove their OWN platform-admin access").
 *
 * The caller is responsible for the given user id actually existing in
 * `users` (and, if relevant, already being a `platform_admins` member) —
 * this fixture does no setup beyond standing up a real, validated session
 * for that exact id.
 *
 * Usage: php admin-page-post-as-user-probe.php <page> <postFieldsJson> <userId>
 * Prints: STATUS <code>\n<html…>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$page     = $argv[1] ?? '';
$postJson = $argv[2] ?? '{}';
$userId   = (int) ($argv[3] ?? 0);
if ($page === '' || $userId <= 0) { fwrite(STDERR, "usage: admin-page-post-as-user-probe.php <page> <postFieldsJson> <userId>\n"); exit(2); }

$root = dirname(__DIR__, 2);
$file = $root . '/' . ltrim($page, '/');
if (!is_file($file)) { fwrite(STDERR, "no such page: {$file}\n"); exit(2); }

// Phase 6 (Global License Guard) test toggle — see
// docs/02-architecture/06-GLOBAL-LICENSE-GUARD.md §3a. By default this
// probe bypasses the Guard exactly like every other non-licensing
// integration test (SLATE_TESTING, D19 LOCKED) so existing suites are
// unaffected by its addition. GlobalLicenseGuardTest.php sets
// SLATE_LICENSE_GUARD_LIVE=1 to instead exercise the real Guard end-to-end.
if (getenv('SLATE_LICENSE_GUARD_LIVE') !== '1') {
    define('SLATE_TESTING', true);
}

// Set BEFORE config.php loads (not after) so a request-level check made at
// boot time — the Global License Guard — sees the same simulated request
// context a real HTTP request would already have from its first line.
$_GET  = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/' . ltrim($page, '/');
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SCRIPT_NAME']    = '/' . ltrim($page, '/');

// Buffering and the STATUS-line shutdown reporter must be registered BEFORE
// config.php loads, not after — a request-level check made at boot time
// (the Global License Guard, Phase 6) can itself call exit() from inside
// config.php's own require chain, before this fixture would otherwise reach
// this point.
ob_start();
$shutdownDone = false;
register_shutdown_function(static function () use (&$shutdownDone, $userId): void {
    if ($shutdownDone) return;
    $shutdownDone = true;
    $body = ob_get_length() !== false ? (string) ob_get_clean() : '';
    $code = http_response_code();
    fwrite(STDOUT, 'STATUS ' . ($code === false ? 200 : (int) $code) . "\n");
    fwrite(STDOUT, $body);
    try {
        \Database::query('DELETE FROM admin_sessions WHERE user_id = ?', [$userId]);
    } catch (\Throwable $e) {
        // best-effort; a leaked probe session row is harmless and namespaced by its random id
    }
});

require $root . '/config.php';

use Slate\Services\Auth\Auth;
use Slate\Services\Auth\SessionRepository;
use Slate\Tenancy\TenantContext;

$row = Database::row("SELECT id, tenant_id, role_id FROM users WHERE id = ?", [$userId]);
if (!$row) {
    // Usage error, not a probe result — skip the STATUS-line reporter
    // entirely so this still exits exactly as it did before the shutdown
    // function had to be registered earlier (to also catch a Guard exit
    // during config.php's own load, above).
    $shutdownDone = true;
    ob_end_clean();
    fwrite(STDERR, "no such user id: {$userId}\n");
    exit(2);
}

session_id('adminasuserprobe_' . bin2hex(random_bytes(12)));
Auth::startSession();
$_SESSION['slate_user'] = [
    'id'        => (int) $row['id'],
    'tenant_id' => (int) $row['tenant_id'],
    'email'     => 'as-user-probe@example.test',
    'role_id'   => (int) $row['role_id'],
];
(new SessionRepository(new TenantContext()))->register(
    (int) $row['id'], session_id(), 'As-user probe', '127.0.0.1', 'ProbeAgent/1'
);

$fields = json_decode($postJson, true);
if (!is_array($fields)) $fields = [];
$fields['_csrf'] = csrf_token();

$_POST = $fields;

ob_start();
$shutdownDone = false;
register_shutdown_function(static function () use (&$shutdownDone, $userId): void {
    if ($shutdownDone) return;
    $shutdownDone = true;
    $body = ob_get_length() !== false ? (string) ob_get_clean() : '';
    $code = http_response_code();
    fwrite(STDOUT, 'STATUS ' . ($code === false ? 200 : (int) $code) . "\n");
    fwrite(STDOUT, $body);
    try {
        \Database::query('DELETE FROM admin_sessions WHERE user_id = ?', [$userId]);
    } catch (\Throwable $e) {
        // best-effort; a leaked probe session row is harmless and namespaced by its random id
    }
});

require $file;
