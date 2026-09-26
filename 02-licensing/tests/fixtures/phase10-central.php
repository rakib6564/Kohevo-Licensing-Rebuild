<?php
/**
 * Phase 10 test fixture: drives the REAL Central Server (LicensingAPI,
 * LicenseService, InstallationService) against this checkout's throwaway
 * test database, one command per process, so the client's synchronization
 * suite (01-client/tests/integration/Phase10SynchronizationTest.php) can
 * run genuine end-to-end check-ins — real binding, real lifecycle, real
 * signing — without an HTTP server. Output is a single JSON document.
 *
 *   reset <secretKeyB64>                 drop + recreate licensing schema, store that signing key
 *   issue <jsonOptions>                  issue a commercial license → {license_id, license_key}
 *   checkin                              stdin = request JSON → {http_status, body}
 *   suspend|unsuspend|revoke <id>        lifecycle operations (LicenseService)
 *   renew|extend <id> <datetime>         lifecycle operations (LicenseService)
 *   set-expiry <id> <datetime>           simulate time passing: move expires_at directly
 *   revoke-installation <licenseId>      InstallationService::revoke() on the active binding
 *   teardown                             drop the licensing schema
 *
 * CLI only, test database only.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../guard.php';
slate_require_test_database();
require_once __DIR__ . '/../../plugins/licensing/LicensingAPI.php';
require_once __DIR__ . '/../../plugins/licensing/PlanService.php';
require_once __DIR__ . '/../../plugins/licensing/LicenseService.php';
require_once __DIR__ . '/../../plugins/licensing/InstallationService.php';

function p10c_drop_schema(): void {
    $sql = preg_replace('/^--.*$/m', '', (string) file_get_contents(__DIR__ . '/../../plugins/licensing/uninstall.sql'));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '') Database::query($stmt);
    }
    Database::query("DELETE FROM settings WHERE setting_key IN ('licensing.signing_public_key', 'licensing.signing_secret_key')");
}

$command = (string) ($argv[1] ?? '');
$out = [];

switch ($command) {
    case 'reset':
        p10c_drop_schema();
        LicensingAPI::ensureSchema();
        $secret = base64_decode((string) ($argv[2] ?? ''), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            fwrite(STDERR, "reset needs a base64 Ed25519 secret key\n");
            exit(1);
        }
        LicensingAPI::storeSigningKeypair([
            'public' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)),
            'secret' => base64_encode($secret),
        ]);
        $out = ['public_key' => LicensingAPI::signingPublicKey()];
        break;

    case 'issue':
        $opts = json_decode((string) ($argv[2] ?? '{}'), true) ?: [];
        $productId = Database::value('SELECT id FROM licensing_products WHERE slug = ?', ['kohevo'])
            ?: Database::insert('licensing_products', ['slug' => 'kohevo', 'name' => 'Kohevo']);
        $planId = Database::value('SELECT id FROM licensing_plans WHERE product_id = ? AND slug = ?', [$productId, 'pro'])
            ?: PlanService::create(['product_id' => $productId, 'slug' => 'pro', 'name' => 'Pro']);
        $clientId = Database::insert('licensing_clients', ['name' => 'Phase 10 Client']);
        $license = LicenseService::issue([
            'client_id' => $clientId, 'product_id' => $productId, 'plan_id' => $planId,
            'modules' => $opts['modules'] ?? ['forms', 'booking'],
            'expires_at' => $opts['expires_at'] ?? null,
        ], 'kohevo');
        $out = ['license_id' => $license['id'], 'license_key' => $license['license_key']];
        break;

    case 'checkin':
        $input = json_decode((string) stream_get_contents(STDIN), true);
        $out = LicensingAPI::handleCheckIn(is_array($input) ? $input : [], '203.0.113.10');
        break;

    case 'suspend':   LicenseService::suspend((int) $argv[2], null, 'phase 10 test'); break;
    case 'unsuspend': LicenseService::unsuspend((int) $argv[2], null, 'phase 10 test'); break;
    case 'revoke':    LicenseService::revoke((int) $argv[2], null, 'phase 10 test'); break;
    case 'renew':     LicenseService::renew((int) $argv[2], (string) $argv[3], null, 'phase 10 test'); break;
    case 'extend':    LicenseService::extend((int) $argv[2], (string) $argv[3], null, 'phase 10 test'); break;

    case 'set-expiry':
        Database::update('licensing_licenses', ['expires_at' => (string) $argv[3]], 'id = ?', [(int) $argv[2]]);
        break;

    case 'revoke-installation':
        $active = InstallationService::active((int) $argv[2]);
        if ($active !== null) InstallationService::revoke((int) $active['id'], (int) $argv[2]);
        break;

    case 'license':
        $out = LicenseService::find((int) $argv[2]) ?? [];
        $out['events'] = array_column(LicenseService::events((int) $argv[2]), 'event_type');
        $out['active_installation'] = InstallationService::active((int) $argv[2])['installation_id'] ?? null;
        break;

    case 'teardown':
        p10c_drop_schema();
        break;

    default:
        fwrite(STDERR, "unknown command\n");
        exit(1);
}

echo json_encode($out, JSON_UNESCAPED_SLASHES);
