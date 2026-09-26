<?php
// D1 reproduction: a bound installation reinstalls (DB wiped, .env kept) while its license is
// suspended / revoked / expired-beyond-grace. Central returns a signed state for the bound
// installation (11 §7); the installer must still refuse to continue (05 §1 Step 4).
declare(strict_types=1);
require __DIR__ . '/lib.php';
$cases = [
    'suspended' => fn (int $id) => central('suspend', (string) $id),
    'revoked'   => fn (int $id) => central('revoke', (string) $id),
    'expired beyond grace' => fn (int $id) => central('set-expiry', (string) $id, gmdate('Y-m-d H:i:s', time() - 30 * 86400)),
];
foreach ($cases as $label => $apply) {
    fresh_client();
    $lic = central('issue', json_encode(['modules' => ['forms']]));
    step1(); installer_post(2, []);
    $iid = env_value('INSTALLATION_ID');
    installer_post(3, ['license_key' => $lic['license_key']]);
    check(installer_step()[0] === 4, "[$label] setup: first install activated normally");
    $apply((int) $lic['license_id']);

    // Reinstall: wipe the database and the marker, keep .env (INSTALLATION_ID reused, D18)
    client_db()->exec('DROP DATABASE p12_client'); client_db()->exec('CREATE DATABASE p12_client CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); client_db()->exec('USE p12_client');
    @unlink(E2E_DIR . '/client/.installed');
    installer_post(2, []);
    check(env_value('INSTALLATION_ID') === $iid && cval('SELECT installation_id FROM installation_identity') === $iid, "[$label] reinstall reuses the bound installation id");
    $r = installer_post(3, ['license_key' => $lic['license_key']]);
    [$s] = installer_step();
    $signed = json_decode((string) cval('SELECT raw_payload FROM remote_license_cache'), true);
    echo "     signed status received: " . ($signed['status'] ?? 'none') . "\n";
    check($s === 3, "[$label] installer stays on the license step", "got step $s");
    $a = installer_post(4, ['name' => 'Re Admin', 'email' => 're@p12.test', 'password' => 'correct-horse-p12']);
    check((int) cval('SELECT COUNT(*) FROM users') === 0, "[$label] no admin account can be created");
    installer_post(5, ['_action' => 'finish']);
    check(!installed(), "[$label] installation cannot be finished");
}
summary();
