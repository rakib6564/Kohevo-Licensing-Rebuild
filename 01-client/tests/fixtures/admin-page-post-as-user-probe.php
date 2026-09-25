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

require $root . '/config.php';

use Slate\Services\Auth\Auth;
use Slate\Services\Auth\SessionRepository;
use Slate\Tenancy\TenantContext;

$row = Database::row("SELECT id, tenant_id, role_id FROM users WHERE id = ?", [$userId]);
if (!$row) { fwrite(STDERR, "no such user id: {$userId}\n"); exit(2); }

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
$_GET  = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/' . ltrim($page, '/');
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SCRIPT_NAME']    = '/' . ltrim($page, '/');

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
