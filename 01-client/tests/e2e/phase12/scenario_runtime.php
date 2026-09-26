<?php
// Phase 12 E2E — runtime on the installed client from scenario_install.php:
// real central lifecycle changes → real bin/license-check.php → real HTTP.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$state = json_decode((string) file_get_contents(E2E_DIR . '/state.json'), true);
$lid = (int) $state['valid']['license_id'];
admin_login();

function surfaces(): array {
    return [
        'booking_admin'  => admin_get('/plugins/booking/admin/index.php')['status'],
        'forms_admin'    => admin_get('/plugins/forms/admin/index.php')['status'],
        'membership_admin' => admin_get('/plugins/membership/admin/index.php')['status'],
        'book_public'    => http('GET', '/book', [], 'jar-anon.txt')['status'],
        'pay_intent'     => http('GET', '/plugins/booking/public/pay-intent.php', [], 'jar-anon.txt')['status'],
        'booking_api'    => http('GET', '/api/v1.php?_route_path=booking/services', [], 'jar-anon.txt')['status'],
        'dashboard'      => admin_get('/admin/')['status'],
    ];
}
function show(array $s): string { return json_encode($s); }

// ── Baseline (forms + booking entitled, membership not) ───────────────
check(str_contains(license_check(), 'Check-in succeeded'), 'baseline check-in');
$s = surfaces();
check($s['dashboard'] === 200 && $s['booking_admin'] === 200 && $s['forms_admin'] === 200, 'baseline: dashboard, booking and forms admin work', show($s));
check($s['book_public'] === 200 && $s['pay_intent'] === 405, 'baseline: booking public + payment endpoint reach their own handlers', show($s));
check($s['booking_api'] !== 403 && $s['booking_api'] !== 404, 'baseline: booking API reaches authentication (not the module guard)', show($s));

// ── Module downgrade / upgrade (plugin stays installed + active) ──────
foreach (['booking' => ['booking_admin', 'book_public', 'pay_intent'], 'forms' => ['forms_admin']] as $module => $keys) {
    $other = $module === 'booking' ? 'forms_admin' : 'booking_admin';
    modules('revoke', $lid, $module);
    license_check();
    $active = client_db()->query("SELECT status FROM plugins WHERE slug = " . client_db()->quote($module))->fetchColumn();
    check($active === 'active', "[$module downgrade] plugin remains installed and active on the client");
    $s = surfaces();
    foreach ($keys as $k) check(in_array($s[$k], [403, 404], true), "[$module downgrade] $k blocked", show($s));
    if ($module === 'booking') check($s['booking_api'] === 403, "[$module downgrade] booking API blocked by the module guard", show($s));
    check($s[$other] === 200, "[$module downgrade] the other module keeps working (no cross-inheritance)", show($s));
    check($s['dashboard'] === 200, "[$module downgrade] Core dashboard keeps working", show($s));
    modules('grant', $lid, $module);
    license_check();
    $s = surfaces();
    foreach ($keys as $k) check(!in_array($s[$k], [403, 404], true), "[$module upgrade] $k restored", show($s));
}

// Membership: grant → activate plugin → works → revoke → blocked → restore
modules('grant', $lid, 'membership');
license_check();
client_php('\PluginLoader::installFromDisk("membership"); echo "ok";');
check(surfaces()['membership_admin'] === 200, '[membership upgrade] entitled + activated membership admin works');
modules('revoke', $lid, 'membership');
license_check();
check(surfaces()['membership_admin'] === 403, '[membership downgrade] membership admin blocked while plugin stays active');
check(surfaces()['forms_admin'] === 200 && surfaces()['booking_admin'] === 200, '[membership downgrade] forms + booking unaffected');
modules('grant', $lid, 'membership');
license_check();
check(surfaces()['membership_admin'] === 200, '[membership restore] membership admin works again');

// ── Lifecycle propagation + Global lock whitelist ─────────────────────
function lock_matrix(string $label, bool $expectLocked): void {
    $dash = admin_get('/admin/');
    check(locked($dash) === $expectLocked, "[$label] admin dashboard " . ($expectLocked ? 'LOCKED' : 'open'), (string) $dash['status']);
    if ($expectLocked) {
        check(locked(http('GET', '/', [], 'jar-anon.txt')), "[$label] public site locked");
        check(locked(admin_get('/plugins/forms/admin/index.php')), "[$label] direct plugin admin PHP locked");
        $api = http('GET', '/api/v1.php?_route_path=booking/services', [], 'jar-anon.txt');
        check($api['status'] === 403 && str_contains($api['body'], 'LICENSE_INACTIVE'), "[$label] API locked with a JSON error", $api['status'] . ' ' . substr($api['body'], 0, 80));
        $ajax = http('GET', '/admin/index.php', [], 'jar-admin.txt', ['X-Requested-With: XMLHttpRequest']);
        check($ajax['status'] === 403 && str_contains($ajax['body'], 'license_inactive'), "[$label] AJAX locked with a JSON error");
        check(http('GET', '/admin/license.php', [], 'jar-admin.txt')['status'] === 200, "[$label] whitelisted License page reachable");
        check(http('GET', '/admin/login.php', [], 'jar-anon.txt')['status'] === 200, "[$label] whitelisted login reachable");
        $cron = http('GET', '/cron.php', [], 'jar-anon.txt');
        check($cron['status'] === 403 && !str_contains($cron['body'], 'License inactive'), "[$label] cron.php answers its own secret check, not the lock page");
        check(locked(http('GET', '/admin/tenants.php', [], 'jar-admin.txt')), "[$label] platform-admin page locked for the super admin");
    }
}

central('suspend', (string) $lid);
check(str_contains(license_check(), 'Check-in succeeded'), 'suspend: signed suspended state delivered');
check(cval('SELECT status FROM remote_license_cache') === 'suspended', 'suspend: client cache holds signed suspended');
lock_matrix('suspended', true);
central('unsuspend', (string) $lid);
license_check();
lock_matrix('unsuspended', false);

// Expiry → grace (warning) → beyond grace → renew
central('set-expiry', (string) $lid, gmdate('Y-m-d H:i:s', time() + 3 * 86400));
license_check();
$d = admin_get('/admin/');
check($d['status'] === 200 && stripos($d['body'], 'expire') !== false, 'expiring soon (3 days): usable, with an expiry warning');
central('set-expiry', (string) $lid, gmdate('Y-m-d H:i:s', time() - 2 * 86400));
license_check();
$d = admin_get('/admin/');
check(cval('SELECT status FROM remote_license_cache') === 'expired', 'expired 2 days ago: central delivers signed expired');
check($d['status'] === 200 && stripos($d['body'], 'grace') !== false, 'grace (expired 2 days ago): usable, with a grace warning', (string) $d['status']);
$pub = http('GET', '/', [], 'jar-anon.txt');
check($pub['status'] === 200 && !str_contains($pub['body'], 'License inactive'), 'grace (expired 2 days ago): the public site stays open for visitors', (string) $pub['status']);
central('set-expiry', (string) $lid, gmdate('Y-m-d H:i:s', time() - 8 * 86400));
license_check();
lock_matrix('expired beyond grace', true);
central('renew', (string) $lid, gmdate('Y-m-d H:i:s', time() + 365 * 86400));
license_check();
lock_matrix('renewed', false);
central('extend', (string) $lid, gmdate('Y-m-d H:i:s', time() + 400 * 86400));
license_check();
lock_matrix('extended', false);
$events = central('license', (string) $lid)['events'] ?? [];
foreach (['activate', 'suspend', 'expire', 'renew', 'extend'] as $e) {
    check(in_array($e, $events, true), "central event history contains '$e'", implode(',', $events));
}

// ── Central unavailable: last trusted state survives ──────────────────
central_stop();
$out = license_check();
check(!str_contains($out, 'succeeded'), 'central down: check-in fails', $out);
lock_matrix('central down, recent trusted state', false);
central_start();

// ── Cache tampering at runtime ────────────────────────────────────────
foreach ([
    'status'        => "status = 'active'",
    'entitlements'  => "entitlements = '[\"forms\",\"booking\",\"membership\",\"white_label\"]'",
    'expires_at'    => "expires_at = '2099-01-01 00:00:00'",
    'plan'          => "plan = 'enterprise'",
    'installation_id' => "installation_id = '" . str_repeat('e', 32) . "'",
    'raw_signature' => "raw_signature = '" . base64_encode(random_bytes(64)) . "'",
    'raw_payload'   => "raw_payload = REPLACE(raw_payload, '\"forms\"', '\"membership\"')",
    'fetched_at (backwards: stale)' => "fetched_at = '2000-01-01 00:00:00'",
] as $col => $set) {
    license_check();
    client_db()->exec("UPDATE remote_license_cache SET $set");
    if ($col === 'status') client_db()->exec("UPDATE remote_license_cache SET status = 'suspended'"); // differs from the signed 'active'
    lock_matrix("tampered $col", true);
}
check(str_contains(license_check(), 'Check-in succeeded'), 'a real check-in replaces a tampered row');
lock_matrix('after recovery check-in', false);

// Revoke last (terminal)
central('revoke', (string) $lid);
license_check();
lock_matrix('revoked', true);

summary();
