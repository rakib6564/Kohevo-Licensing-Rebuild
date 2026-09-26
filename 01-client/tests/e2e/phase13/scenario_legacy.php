<?php
// Phase 13 E2E — existing Solaya / legacy handling, over real HTTP against the
// Phase 12 harness (run.sh starts both servers on fresh p12_* databases).
// Starts from its own fresh licensed install (scenario_install.php), so it can
// run after any other scenario. See docs/03-implementation/PHASE-13-LEGACY-HANDLING.md.
declare(strict_types=1);
require __DIR__ . '/../phase12/lib.php';

const CENTRAL = 'http://127.0.0.1:8091';

function env_set(string $key, ?string $value): void {
    $path = E2E_DIR . '/client/.env';
    $body = preg_replace('/^' . preg_quote($key, '/') . '=.*\n?/m', '', (string) file_get_contents($path));
    if ($value !== null) $body = rtrim($body, "\n") . "\n$key=$value\n";
    file_put_contents($path, $body);
}

function chttp(string $method, string $path, array $post = [], string $jar = 'cjar-p13.txt'): array {
    $ch = curl_init(CENTRAL . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => E2E_DIR . '/' . $jar, CURLOPT_COOKIEFILE => E2E_DIR . '/' . $jar, CURLOPT_TIMEOUT => 30]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $h = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return ['status' => $status, 'body' => substr($raw, $h)];
}

/** Every module surface, with the admin session. */
function module_surfaces(): array {
    return [
        'forms_admin'      => admin_get('/plugins/forms/admin/index.php')['status'],
        'booking_admin'    => admin_get('/plugins/booking/admin/index.php')['status'],
        'membership_admin' => admin_get('/plugins/membership/admin/index.php')['status'],
        'membership_ajax'  => http('GET', '/plugins/membership/admin/index.php', [], 'jar-admin.txt', ['X-Requested-With: XMLHttpRequest'])['status'],
        'booking_post'     => http('POST', '/plugins/booking/admin/services.php', ['_action' => 'save', 'name' => 'P13'], 'jar-admin.txt')['status'],
        'book_public'      => http('GET', '/book', [], 'jar-anon.txt')['status'],
        'booking_api'      => http('GET', '/api/v1.php?_route_path=booking/services', [], 'jar-anon.txt')['status'],
    ];
}

// ── Fresh licensed install (forms + booking entitled, membership not) ──
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../phase12/scenario_install.php'), $out, $rc);
check($rc === 0, 'setup: fresh licensed install (scenario_install)', implode(' | ', array_slice($out, -3)));
$state = json_decode((string) file_get_contents(E2E_DIR . '/state.json'), true);
$lid = (int) $state['valid']['license_id'];
$iid = (string) $state['iid'];
client_php('\PluginLoader::installFromDisk("membership"); echo "ok";'); // active, but NOT entitled remotely
admin_login();

// The installer's admin is role_id 1, so Auth::isPlatformSuperAdmin() is true and
// the dashboard shows the platform overview. On a fresh install (no legacy tables)
// it must still render completely — before Phase 13 it fataled half-way on `licenses`.
$d = admin_get('/admin/');
check($d['status'] === 200 && str_contains($d['body'], '</html>') && str_contains($d['body'], 'data-license-summary'), 'fresh install: super-admin dashboard renders completely (no legacy-table query)');

// ── Upgrade migration: the product migration set, incl. the legacy tables ──
$payloadBefore = (string) cval('SELECT raw_payload FROM remote_license_cache');
$migrate = fn() => trim((string) shell_exec('cd ' . escapeshellarg(E2E_DIR . '/client') . ' && ' . escapeshellarg(PHP_BINARY) . ' bin/migrate migrate 2>&1'));
$m1 = $migrate();
check((int) cval("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='p12_client' AND table_name IN ('licenses','platform_plans','plan_entitlements')") === 3, 'upgrade: legacy tables provisioned by bin/migrate', substr($m1, -120));
check(str_contains($migrate(), 'Nothing to migrate'), 'upgrade: replay is a no-op');
check(cval('SELECT installation_id FROM installation_identity WHERE singleton_id = 1') === $iid, 'upgrade: installation identity unchanged');
check(cval('SELECT raw_payload FROM remote_license_cache') === $payloadBefore, 'upgrade: signed cache unchanged');

// Legacy data granting EVERYTHING: local plan (all modules + white_label), assigned, active local license.
client_php('
    $plan = \Slate\Services\Licensing\PlanService::save(null, ["name" => "P13 Legacy All", "slug" => "p13-legacy-all"], ["forms","membership","booking","white_label"]);
    Database::query("UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?", [$plan, current_tenant_id()]);
    \Slate\Services\Licensing\LicenseService::issue(current_tenant_id(), $plan, ["status" => "active"]);
    echo "ok";');
check((int) cval("SELECT COUNT(*) FROM licenses WHERE status='active'") === 1, 'setup: active legacy local license on an all-module local plan');

// ── Legacy mode on a licensed install ─────────────────────────────────
env_set('LICENSE_COMPAT_MODE', 'legacy');
env_set('LICENSE_COMPAT_UNTIL', gmdate('Y-m-d', time() + 30 * 86400));
$s = module_surfaces();
check($s['forms_admin'] === 200 && $s['booking_admin'] === 200, '[modern + legacy flag] signed grants still work', json_encode($s));
check($s['membership_admin'] === 403 && $s['membership_ajax'] === 403, '[modern + legacy flag] membership (legacy-only grant) blocked, page and AJAX', json_encode($s));

$key = env_value('LICENSE_KEY');
env_set('LICENSE_KEY', null); // remote authority incomplete → authorityMode() = legacy
$s = module_surfaces();
check($s['forms_admin'] === 403 && $s['booking_admin'] === 403 && $s['membership_admin'] === 403, '[legacy authority] every module admin blocked despite legacy grants', json_encode($s));
check($s['membership_ajax'] === 403 && $s['booking_post'] === 403, '[legacy authority] AJAX and POST blocked', json_encode($s));
check($s['book_public'] === 404, '[legacy authority] booking public route 404', json_encode($s));
check($s['booking_api'] === 403, '[legacy authority] booking API 403', json_encode($s));
$d = admin_get('/admin/');
check($d['status'] === 200 && !str_contains($d['body'], 'Your plan') && !str_contains($d['body'], 'P13 Legacy All'), '[legacy authority] Core open; no legacy plan presented as the license');
$lp = admin_get('/admin/license.php');
check($lp['status'] === 200 && str_contains($lp['body'], 'optional modules cannot be enabled'), '[legacy authority] License page explains modules are off');

// ── Locked by central: legacy mode changes nothing ────────────────────
env_set('LICENSE_KEY', $key);
central('suspend', (string) $lid);
check(str_contains(license_check(), 'Check-in succeeded'), '[suspended] signed suspended state delivered');
foreach (['with LICENSE_KEY' => $key, 'legacy authority' => null] as $label => $k) {
    env_set('LICENSE_KEY', $k);
    check(locked(admin_get('/admin/')), "[suspended, $label] dashboard locked");
    check(locked(admin_get('/admin/licenses.php')) && locked(admin_get('/admin/plans.php')), "[suspended, $label] legacy platform pages locked (super admin)");
    check(locked(http('POST', '/admin/licenses.php', ['_action' => 'issue', 'status' => 'active'], 'jar-admin.txt')), "[suspended, $label] legacy license POST locked");
    check(locked(admin_get('/plugins/forms/admin/index.php')), "[suspended, $label] module admin locked");
    $api = http('GET', '/api/v1.php?_route_path=booking/services', [], 'jar-anon.txt');
    check($api['status'] === 403 && str_contains($api['body'], 'LICENSE_INACTIVE'), "[suspended, $label] API locked");
    $ajax = http('GET', '/admin/index.php', [], 'jar-admin.txt', ['X-Requested-With: XMLHttpRequest']);
    check($ajax['status'] === 403 && str_contains($ajax['body'], 'license_inactive'), "[suspended, $label] AJAX locked");
    check(locked(http('GET', '/', [], 'jar-anon.txt')), "[suspended, $label] public site locked");
    check(admin_get('/admin/license.php')['status'] === 200, "[suspended, $label] License page reachable");
}
$cron = http('GET', '/cron.php', [], 'jar-anon.txt', ['X-Cron-Key: ' . env_value('CRON_SECRET')]);
check($cron['status'] === 200 && str_contains($cron['body'], '"ok":true'), '[suspended, legacy authority] cron.php runs its own secret-gated dispatch', $cron['status'] . ' ' . substr($cron['body'], 0, 80));
check(cval('SELECT status FROM remote_license_cache') === 'suspended', '[suspended] cron did not alter the signed cache');
check((int) cval("SELECT COUNT(*) FROM licenses WHERE status='active'") === 1, '[suspended] legacy local license untouched by cron');
env_set('LICENSE_KEY', $key);
central('unsuspend', (string) $lid);
check(str_contains(license_check(), 'Check-in succeeded'), '[unsuspended] check-in');
check(admin_get('/admin/')['status'] === 200, '[unsuspended] open again');

// ── An existing installation from before the rebuild ──────────────────
// The shape of the one real Solaya dump: installed, an installation_identity
// row from the old code, no remote_license_cache table, no LICENSE_KEY.
$users = (int) cval('SELECT COUNT(*) FROM users');
client_db()->exec('DROP TABLE remote_license_cache');
client_db()->exec("DELETE FROM migrations WHERE migration IN ('0022_remote_license_cache','0024_remote_license_metadata','0025_remote_license_cache_installation_id','0026_remote_license_cache_signed_payload')");
env_set('LICENSE_KEY', null);
foreach (['no compat flag' => null, 'LICENSE_COMPAT_MODE=legacy' => 'legacy'] as $label => $mode) {
    env_set('LICENSE_COMPAT_MODE', $mode);
    check(locked(admin_get('/admin/')), "[old install, $label] dashboard locked");
    check(locked(admin_get('/plugins/forms/admin/index.php')), "[old install, $label] module admin locked");
    check(locked(admin_get('/admin/tenants.php')), "[old install, $label] legacy platform page locked");
    check(locked(http('GET', '/', [], 'jar-anon.txt')), "[old install, $label] public site locked");
    check(http('GET', '/admin/login.php', [], 'jar-anon.txt')['status'] === 200, "[old install, $label] login reachable");
    [$step] = installer_step('jar-p13-installer.txt');
    check($step === 99, "[old install, $label] installer refuses to run again (no new-install path)", "step $step");
}
$lp = admin_get('/admin/license.php');
check($lp['status'] === 200, '[old install] License page reachable', (string) $lp['status']);
$bindingsBefore = (int) zval('SELECT COUNT(*) FROM licensing_installations');
$r = http('POST', '/admin/license.php', ['license_key' => 'NOT-A-REAL-KEY-0000', '_csrf' => csrf($lp['body'])], 'jar-admin.txt');
check(!str_contains($r['body'], 'now unlocked'), '[old install] an unknown key unlocks nothing');
check(locked(admin_get('/admin/')), '[old install] still locked');
check((int) zval('SELECT COUNT(*) FROM licensing_installations') === $bindingsBefore, '[old install] no central binding created');
check(cval('SELECT installation_id FROM installation_identity WHERE singleton_id = 1') === $iid, '[old install] identity neither replaced nor fabricated');
check((int) cval('SELECT COUNT(*) FROM users') === $users && (int) cval("SELECT COUNT(*) FROM licenses") === 1, '[old install] application and legacy data intact');

// Upgrading its schema alone does not license it either.
$m = $migrate();
check((int) cval("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='p12_client' AND table_name='remote_license_cache' AND column_name IN ('installation_id','raw_payload','raw_signature','verified_at')") === 4,
    '[old install, migrated] 0022/0024–0026 build the signed cache table on the old schema', substr($m, -160));
check((int) cval('SELECT COUNT(*) FROM remote_license_cache') === 0, '[old install, migrated] cache table empty — nothing fabricated');
check(cval('SELECT installation_id FROM installation_identity WHERE singleton_id = 1') === $iid, '[old install, migrated] identity unchanged');
check((int) cval('SELECT COUNT(*) FROM users') === $users && (int) cval("SELECT COUNT(*) FROM licenses") === 1, '[old install, migrated] data intact');
check(locked(admin_get('/admin/')), '[old install, migrated] still locked (no signed state)');
env_set('LICENSE_COMPAT_MODE', null);
env_set('LICENSE_COMPAT_UNTIL', null);
env_set('LICENSE_KEY', $key);

// ── Central legacy screens over HTTP ──────────────────────────────────
$hash = password_hash('central-pass-p13', PASSWORD_DEFAULT);
central_db()->exec("DELETE FROM users WHERE email = 'p13owner@central.test'");
central_db()->prepare("INSERT INTO users (tenant_id,email,password_hash,name,role_id,status) VALUES (1,?,?,'P13 Owner',1,'active')")->execute(['p13owner@central.test', $hash]);
@unlink(E2E_DIR . '/cjar-p13.txt');
$l = chttp('GET', '/admin/login.php');
chttp('POST', '/admin/login.php', ['email' => 'p13owner@central.test', 'password' => 'central-pass-p13', '_csrf' => csrf($l['body'])]);
$productId = (int) zval("SELECT id FROM licensing_products WHERE slug = 'kohevo'");
$clientId = (int) (zval('SELECT id FROM licensing_clients ORDER BY id LIMIT 1') ?: 0);
if ($clientId === 0) { central_db()->exec("INSERT INTO licensing_clients (name) VALUES ('P13 client')"); $clientId = (int) zval('SELECT MAX(id) FROM licensing_clients'); }
// A pre-existing legacy license (the only way one can exist now), suspended.
central_db()->prepare("INSERT INTO licensing_installs (client_id,product_id,label,domain,domain_normalized,license_key_hash,status,expires_at,activation_limit) VALUES (?,?,?,?,?,?,'suspended','2030-06-01 00:00:00',1)")
    ->execute([$clientId, $productId, 'P13 legacy', 'p13-legacy.test', 'p13-legacy.test', hash('sha256', 'p13-legacy-key')]);
$legacyId = (int) zval("SELECT id FROM licensing_installs WHERE domain = 'p13-legacy.test'");
$installs = zval('SELECT COUNT(*) FROM licensing_installs');

$page = chttp('GET', '/plugins/licensing/admin/installs.php');
check($page['status'] === 200 && !str_contains($page['body'], 'value="issue"'), 'central: legacy list renders without an issue form', (string) $page['status']);
chttp('POST', '/plugins/licensing/admin/installs.php', ['_action' => 'issue', 'client_id' => $clientId, 'product_id' => $productId, 'domain' => 'x.test', 'status' => 'active', '_csrf' => csrf($page['body'])]);
check(zval('SELECT COUNT(*) FROM licensing_installs') === $installs, 'central: legacy issue POST creates nothing');
chttp('POST', '/plugins/licensing/admin/installs.php', ['_action' => 'set_status', 'id' => $legacyId, 'status' => 'active', '_csrf' => csrf($page['body'])]);
check(zval('SELECT status FROM licensing_installs WHERE id = ?', [$legacyId]) === 'suspended', 'central: legacy reactivation refused');
$detail = chttp('GET', "/plugins/licensing/admin/install.php?id=$legacyId");
$keyHash = zval('SELECT license_key_hash FROM licensing_installs WHERE id = ?', [$legacyId]);
chttp('POST', "/plugins/licensing/admin/install.php?id=$legacyId", ['_action' => 'regenerate', '_csrf' => csrf($detail['body'])]);
chttp('POST', "/plugins/licensing/admin/install.php?id=$legacyId", ['_action' => 'update', 'label' => 'P13 legacy', 'expires_at' => '2040-01-01', 'activation_limit' => '10', '_csrf' => csrf($detail['body'])]);
$row = central_db()->query("SELECT license_key_hash, expires_at, activation_limit FROM licensing_installs WHERE id = $legacyId")->fetch(PDO::FETCH_ASSOC);
check($row['license_key_hash'] === $keyHash && $row['expires_at'] === '2030-06-01 00:00:00' && (int) $row['activation_limit'] === 1, 'central: re-key, extension and limit raise refused', json_encode($row));
chttp('POST', '/plugins/licensing/admin/installs.php', ['_action' => 'set_status', 'id' => $legacyId, 'status' => 'revoked', '_csrf' => csrf($page['body'])]);
check(zval('SELECT status FROM licensing_installs WHERE id = ?', [$legacyId]) === 'revoked', 'central: legacy revoke still applies');

// Legacy check-in over real HTTP: a revoked legacy key gets no signed state.
$ch = curl_init(CENTRAL . '/licensing/check');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['product' => 'kohevo', 'license_key' => 'p13-legacy-key', 'install_id' => str_repeat('9', 32), 'domain' => 'p13-legacy.test', 'app_version' => '1'])]);
$body = (string) curl_exec($ch);
check((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 403 && !str_contains($body, 'signature'), 'central: revoked legacy key gets 403, no signed payload', $body);

// Back to a clean licensed state for anything that runs after this.
check(str_contains(license_check(), 'Check-in succeeded'), 'teardown: an explicit check-in with the central-issued key licenses it again');
check(admin_get('/admin/')['status'] === 200, 'teardown: open again');
summary();
