<?php
// Phase 12 E2E — fresh installation + installer recovery, over real HTTP.
declare(strict_types=1);
require __DIR__ . '/lib.php';


fresh_client();

// Licenses on the real central server, each in the state its name says.
function bind_elsewhere(array $lic, string $installId): void {
    $req = json_encode(['product' => 'kohevo', 'license_key' => $lic['license_key'], 'install_id' => $installId, 'domain' => 'elsewhere.test', 'app_version' => '1.0.0']);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CENTRAL_FIXTURE) . ' checkin';
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $req); fclose($pipes[0]); stream_get_contents($pipes[1]); proc_close($p);
}
$valid      = central('issue', json_encode(['modules' => ['forms', 'booking']]));
$suspended  = central('issue', json_encode(['modules' => ['forms']]));
bind_elsewhere($suspended, bin2hex(random_bytes(16)));
central('suspend', (string) $suspended['license_id']);
$takenActive = central('issue', json_encode(['modules' => ['forms']]));
bind_elsewhere($takenActive, bin2hex(random_bytes(16)));
$revoked    = central('issue', json_encode(['modules' => ['forms']]));
central('revoke', (string) $revoked['license_id']);
check((central('license', (string) $suspended['license_id'])['status'] ?? '') === 'suspended', 'setup: suspended license is suspended (bound elsewhere)');
check((central('license', (string) $revoked['license_id'])['status'] ?? '') === 'revoked', 'setup: revoked license is revoked');

// ── Step 1: database configuration ────────────────────────────────────
[$s] = installer_step();
check($s === 1, 'fresh client resolves to step 1', "got $s");
$r = installer_post(1, ['db_host' => '127.0.0.1', 'db_name' => 'no_such_db_p12', 'db_user' => P12_DB_USER, 'db_pass' => P12_DB_PASS, 'app_url' => CLIENT]);
check(!is_file(E2E_DIR . '/client/.env'), 'bad DB credentials write no .env');
check(str_contains($r['body'], 'Could not connect'), 'bad DB credentials show a connection error');
step1();
check(env_value('DB_NAME') === 'p12_client' && env_value('APP_SECRET') !== null && env_value('CRON_SECRET') !== null, 'step 1 writes .env with generated APP_SECRET/CRON_SECRET');
check((string) env_value('INSTALLATION_ID') === '', 'no installation id before step 2');

// Skip-ahead attempts before step 2
$r = http('GET', '/install.php?step=4');
check(str_contains($r['location'], 'step=2'), 'GET ?step=4 before install is redirected to step 2', $r['location']);
$r = http('POST', '/install.php?step=4', ['name' => 'X', 'email' => 'x@example.test', 'password' => 'password123']);
check((int) cval("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='p12_client' AND table_name='users'") === 0, 'a forged step-4 POST at step 2 creates nothing');

// ── Step 2: install application ───────────────────────────────────────
installer_post(2, []);
[$s] = installer_step();
check($s === 3, 'after step 2 the installer is at the license step', "got $s");
$iid = (string) cval('SELECT installation_id FROM installation_identity WHERE singleton_id = 1');
check((bool) preg_match('/^[a-f0-9]{32}$/', $iid), 'installation id is 32 lowercase hex', $iid);
check(env_value('INSTALLATION_ID') === $iid, 'installation id persisted to .env and DB identically (D18)');
check((int) cval('SELECT COUNT(*) FROM installation_identity') === 1, 'exactly one installation identity row');
check((int) cval('SELECT COUNT(*) FROM users') === 0, 'no admin account after step 2');
check(!installed(), 'no .installed after step 2');

// Retry step 2 after the database is dropped mid-install: same id reused
client_db()->exec('DROP DATABASE p12_client'); client_db()->exec('CREATE DATABASE p12_client CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); client_db()->exec('USE p12_client');
[$s] = installer_step();
check($s === 2, 'DB lost after step 2: installer returns to step 2', "got $s");
installer_post(2, []);
check((string) cval('SELECT installation_id FROM installation_identity WHERE singleton_id = 1') === $iid, 'retry after DB loss reuses the SAME installation id');

// ── Step 3: license key (failure paths) ───────────────────────────────
$r = http('POST', '/install.php?step=3', ['license_key' => $valid['license_key'], '_csrf' => 'forged']);
check((int) cval('SELECT COUNT(*) FROM remote_license_cache') === 0, 'step 3 with a bad CSRF token does nothing');
$r = http('POST', '/install.php?step=4', ['name' => 'X', 'email' => 'x@example.test', 'password' => 'password123', '_csrf' => csrf(installer_step()[1])]);
check((int) cval('SELECT COUNT(*) FROM users') === 0, 'admin cannot be created before activation (forged step-4 POST at step 3)');

$cases = [
    'invalid key'   => 'KOHEVO-NOT-A-REAL-KEY-0000',
    'malformed key' => str_repeat('x', 250),
    'suspended license (bound to another installation)' => $suspended['license_key'],
    'active license already bound to another installation (1 license = 1 installation)' => $takenActive['license_key'],
    'revoked license (never activated)'   => $revoked['license_key'],
];
foreach ($cases as $label => $key) {
    installer_post(3, ['license_key' => $key]);
    [$s] = installer_step();
    check($s === 3, "$label: installer stays on step 3", "got step $s");
    check((int) cval('SELECT COUNT(*) FROM users') === 0, "$label: no admin account");
}
check((int) zval('SELECT COUNT(*) FROM licensing_installations WHERE installation_id = ?', [$iid]) === 0, 'no rejected key created a central binding for this installation');

// Central unavailable during activation
central_stop();
$r = installer_post(3, ['license_key' => $valid['license_key']]);
check(str_contains($r['body'], 'Could not reach the licensing server'), 'central down: distinct "could not reach" message');
check(installer_step()[0] === 3, 'central down: stays on step 3');
central_start();

// ── Step 3: valid key → activation ────────────────────────────────────
installer_post(3, ['license_key' => $valid['license_key']]);
[$s] = installer_step();
check($s === 4, 'valid key: installer advances to admin creation', "got $s");
$lic = central('license', (string) $valid['license_id']);
check(($lic['active_installation'] ?? null) === $iid, 'central binds the license to THIS installation id');
check(($lic['status'] ?? null) === 'active', 'central license is active after activation');
check((int) cval('SELECT COUNT(*) FROM remote_license_cache WHERE raw_signature IS NOT NULL AND installation_id = ?', [$iid]) === 1, 'client cache holds one signed, installation-bound row');
check(env_value('LICENSE_KEY') === $valid['license_key'], 'verified license key persisted to .env for the unattended check-in (D3)');

// Duplicate activation request (refresh / resubmit) → no duplicate binding
installer_post(3, ['license_key' => $valid['license_key']]); // ignored: step is 4 now
check((int) zval('SELECT COUNT(*) FROM licensing_installations WHERE license_id = ?', [$valid['license_id']]) === 1, 'resubmitting activation creates no second installation row');
[$s] = installer_step('jar-new-session.txt');
check($s === 4, 'a new browser session resumes at step 4 (interruption after activation)', "got $s");

// ── Step 4: admin ─────────────────────────────────────────────────────
installer_post(4, ['name' => 'Short', 'email' => 'bad', 'password' => 'short']);
check((int) cval('SELECT COUNT(*) FROM users') === 0, 'invalid admin input creates nothing');
installer_post(4, ['name' => 'P12 Admin', 'email' => 'admin@p12.test', 'password' => 'correct-horse-p12']);
check((int) cval('SELECT COUNT(*) FROM users') === 1, 'exactly one admin created');
check(!installed(), '.installed not written before Finish');
installer_post(4, ['name' => 'Second', 'email' => 'second@p12.test', 'password' => 'correct-horse-p12']);
check((int) cval('SELECT COUNT(*) FROM users') === 1, 'a replayed step-4 POST creates no second admin');

// ── Step 5: finish ────────────────────────────────────────────────────
installer_post(5, ['_action' => 'finish']);
check(installed(), '.installed written after Finish');
$active = client_db()->query("SELECT slug FROM plugins WHERE status='active' ORDER BY slug")->fetchAll(PDO::FETCH_COLUMN);
check(in_array('forms', $active, true) && in_array('booking', $active, true), 'entitled modules (forms, booking) activated', implode(',', $active));
check(!in_array('membership', $active, true), 'unentitled module (membership) NOT activated', implode(',', $active));
[$s] = installer_step();
check($s === 99, 'installer reports "already installed" afterwards');

// ── Dashboard ─────────────────────────────────────────────────────────
$login = http('GET', '/admin/login.php', [], 'jar-admin.txt');
$r = http('POST', '/admin/login.php', ['email' => 'admin@p12.test', 'password' => 'correct-horse-p12', '_csrf' => csrf($login['body'])], 'jar-admin.txt');
check($r['status'] === 302, 'admin login succeeds', (string) $r['status']);
$d = http('GET', '/admin/', [], 'jar-admin.txt');
check($d['status'] === 200 && !str_contains($d['body'], 'License inactive'), 'dashboard renders for a licensed installation', (string) $d['status']);
check(str_contains(license_check(), 'Check-in succeeded'), 'the unattended check-in (bin/license-check.php) works right after install (D3)');
file_put_contents(E2E_DIR . '/state.json', json_encode(['iid' => $iid, 'valid' => $valid]));

summary();
