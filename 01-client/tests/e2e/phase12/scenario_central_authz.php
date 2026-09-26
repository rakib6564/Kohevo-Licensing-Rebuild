<?php
// Phase 12 E2E — central licensing admin: authentication, capability, CSRF, IDOR, audit history.
declare(strict_types=1);
require __DIR__ . '/lib.php';

const CENTRAL = 'http://127.0.0.1:8091';

function chttp(string $method, string $path, array $post = [], string $jar = 'cjar.txt'): array {
    $ch = curl_init(CENTRAL . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => E2E_DIR . '/' . $jar, CURLOPT_COOKIEFILE => E2E_DIR . '/' . $jar, CURLOPT_TIMEOUT => 30]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $h = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $h), $m);
    return ['status' => $status, 'location' => $m[1] ?? '', 'body' => substr($raw, $h)];
}
function clogin(string $email, string $jar): void {
    @unlink(E2E_DIR . '/' . $jar);
    $l = chttp('GET', '/admin/login.php', [], $jar);
    chttp('POST', '/admin/login.php', ['email' => $email, 'password' => 'central-pass-p12', '_csrf' => csrf($l['body'])], $jar);
}
function status(int $id): string { return (string) zval('SELECT status FROM licensing_licenses WHERE id = ?', [$id]); }

$hash = password_hash('central-pass-p12', PASSWORD_DEFAULT);
central_db()->exec("DELETE FROM users WHERE email IN ('owner@central.test','manager@central.test')");
central_db()->prepare('INSERT INTO users (tenant_id,email,password_hash,name,role_id,status) VALUES (1,?,?,?,1,\'active\'),(1,?,?,?,2,\'active\')')
    ->execute(['owner@central.test', $hash, 'Owner', 'manager@central.test', $hash, 'Manager']);
$ownerId = (int) zval("SELECT id FROM users WHERE email='owner@central.test'");

$lic = central('issue', json_encode(['modules' => ['forms']]));
$lid = (int) $lic['license_id'];
$req = json_encode(['product' => 'kohevo', 'license_key' => $lic['license_key'], 'install_id' => bin2hex(random_bytes(16)), 'domain' => 'authz.test', 'app_version' => '1']);
$p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CENTRAL_FIXTURE) . ' checkin', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fwrite($pipes[0], $req); fclose($pipes[0]); stream_get_contents($pipes[1]); proc_close($p);
check(status($lid) === 'active', 'setup: license activated');
$page = "/plugins/licensing/admin/license.php?id=$lid";

// Anonymous
$r = chttp('GET', $page, [], 'cjar-anon.txt');
check($r['status'] === 302 && str_contains($r['location'], 'login'), 'anonymous: redirected to login', $r['status'] . ' ' . $r['location']);
chttp('POST', $page, ['_action' => 'suspend', 'reason' => 'x'], 'cjar-anon.txt');
check(status($lid) === 'active', 'anonymous POST suspend: no effect');

// Authenticated, lacking licensing.manage (Manager role)
clogin('manager@central.test', 'cjar-mgr.txt');
check(chttp('GET', '/admin/', [], 'cjar-mgr.txt')['status'] === 200, 'manager: logged in');
foreach (['license.php?id=' . $lid, 'licenses.php', 'plans.php', 'installs.php', 'clients.php', 'products.php'] as $pg) {
    check(chttp('GET', "/plugins/licensing/admin/$pg", [], 'cjar-mgr.txt')['status'] === 403, "manager: GET $pg refused (403)");
}
$mgrPage = chttp('GET', '/admin/', [], 'cjar-mgr.txt');
foreach (['suspend' => ['reason' => 'x'], 'revoke' => ['reason' => 'x'], 'extend' => ['new_expires_at' => '2031-01-01'], 'set_modules' => ['modules' => ['booking', 'membership']]] as $act => $f) {
    chttp('POST', $page, ['_action' => $act, '_csrf' => csrf($mgrPage['body'])] + $f, 'cjar-mgr.txt');
}
check(status($lid) === 'active', 'manager: lifecycle POSTs (suspend/revoke/extend) have no effect');
check(zval('SELECT GROUP_CONCAT(module_key) FROM licensing_license_modules WHERE license_id = ?', [$lid]) === 'forms', 'manager: entitlement POST has no effect');

// Owner (licensing.manage): bad CSRF refused, valid CSRF works
clogin('owner@central.test', 'cjar-own.txt');
$view = chttp('GET', $page, [], 'cjar-own.txt');
check($view['status'] === 200, 'owner: license page renders');
chttp('POST', $page, ['_action' => 'suspend', 'reason' => 'csrf test', '_csrf' => 'forged'], 'cjar-own.txt');
check(status($lid) === 'active', 'owner with forged CSRF: suspend refused');
chttp('POST', $page, ['_action' => 'suspend', 'reason' => 'p12 authz', '_csrf' => csrf($view['body'])], 'cjar-own.txt');
check(status($lid) === 'suspended', 'owner with valid CSRF: suspend works');
$ev = central_db()->prepare("SELECT actor_type, actor_id, reason FROM licensing_license_events WHERE license_id = ? AND event_type = 'suspend' ORDER BY id DESC LIMIT 1");
$ev->execute([$lid]); $ev = $ev->fetch(PDO::FETCH_ASSOC);
check(($ev['actor_id'] ?? null) == $ownerId && ($ev['reason'] ?? '') === 'p12 authz', 'suspend event records the acting admin and reason', json_encode($ev));
chttp('GET', "/plugins/licensing/admin/license.php?id=$lid", [], 'cjar-own.txt');
chttp('GET', '/admin/login.php', [], 'cjar-own.txt'); // noop
$view = chttp('GET', $page, [], 'cjar-own.txt');
chttp('POST', $page, ['_action' => 'set_modules', 'modules' => ['forms', 'core', 'nonsense'], '_csrf' => csrf($view['body'])], 'cjar-own.txt');
$mods = (string) zval('SELECT GROUP_CONCAT(module_key ORDER BY module_key) FROM licensing_license_modules WHERE license_id = ?', [$lid]);
check(!str_contains($mods, 'core') && !str_contains($mods, 'nonsense'), 'owner: Core / unknown module keys can never be granted', $mods);

// IDOR: another license's installation cannot be revoked through this license's page
$other = central('issue', json_encode(['modules' => ['forms']]));
$req = json_encode(['product' => 'kohevo', 'license_key' => $other['license_key'], 'install_id' => bin2hex(random_bytes(16)), 'domain' => 'other-authz.test', 'app_version' => '1']);
$p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CENTRAL_FIXTURE) . ' checkin', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fwrite($pipes[0], $req); fclose($pipes[0]); stream_get_contents($pipes[1]); proc_close($p);
$otherInst = (int) zval("SELECT id FROM licensing_installations WHERE license_id = ? AND status = 'active'", [$other['license_id']]);
$view = chttp('GET', $page, [], 'cjar-own.txt');
chttp('POST', $page, ['_action' => 'revoke_installation', 'installation_id' => $otherInst, '_csrf' => csrf($view['body'])], 'cjar-own.txt');
check(zval('SELECT status FROM licensing_installations WHERE id = ?', [$otherInst]) === 'active', "IDOR: another license's installation cannot be revoked via this license's page");
check(chttp('GET', '/plugins/licensing/admin/license.php?id=999999', [], 'cjar-own.txt')['status'] === 404, 'unknown license id: 404');

// Revoked is terminal
$view = chttp('GET', $page, [], 'cjar-own.txt');
chttp('POST', $page, ['_action' => 'unsuspend', '_csrf' => csrf($view['body'])], 'cjar-own.txt');
$view = chttp('GET', $page, [], 'cjar-own.txt');
chttp('POST', $page, ['_action' => 'revoke', 'reason' => 'terminal test', '_csrf' => csrf($view['body'])], 'cjar-own.txt');
check(status($lid) === 'revoked', 'owner: revoke works');
foreach (['unsuspend' => [], 'renew' => ['new_expires_at' => '2031-01-01'], 'extend' => ['new_expires_at' => '2031-01-01']] as $act => $f) {
    $view = chttp('GET', $page, [], 'cjar-own.txt');
    chttp('POST', $page, ['_action' => $act, '_csrf' => csrf($view['body'])] + $f, 'cjar-own.txt');
}
check(status($lid) === 'revoked', 'revoked is terminal: unsuspend/renew/extend refused');
$events = central('license', (string) $lid)['events'] ?? [];
check($events === array_values(array_filter($events)) && in_array('create', $events, true) && in_array('revoke', $events, true), 'event history intact (create … revoke)', implode(',', $events));

summary();
