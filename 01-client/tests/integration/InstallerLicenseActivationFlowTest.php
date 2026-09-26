<?php
/**
 * Phase 5 (client installer rebuild): the installer's state-resolution
 * logic (includes/installer_flow.php) and the License Key -> Central
 * Validation -> Activate -> Admin Account pipeline it drives.
 *
 * install.php itself is a superglobal-driven, HTML-emitting entry point —
 * consistent with how this codebase already tests installer concerns
 * (FreshInstallMigrationTest.php never executes install.php either), these
 * tests exercise the underlying, extracted logic directly: a genuinely
 * fresh, throwaway database (never the shared integration test database),
 * InstallationService's split provisioning methods, and RemoteLicenseClient
 * against a fake transport that returns real Ed25519-signed envelopes.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/license_signing.php';

use Slate\Data\MigrationRunner;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Licensing\SlateLicenseCacheStore;

require_once __DIR__ . '/../../plugins/licensing/client/RemoteLicenseClient.php';
require_once __DIR__ . '/../../includes/installer_flow.php';

/** The exact core migration list install.php's Step 2 applies. */
const ILAF_CORE_MIGRATIONS = [
    '0001_core_init', '0002_identity_core', '0011_login_attempts',
    '0014_tenant_profiles', '0023_installation_identity',
    '0022_remote_license_cache', '0024_remote_license_metadata',
    '0025_remote_license_cache_installation_id', '0026_remote_license_cache_signed_payload',
];

function ilaf_fresh_pdo(string $dbName): \PDO {
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    $root = new \PDO($dsn, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `$dbName`");
    $root->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4");
    $dsn2 = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ";dbname=$dbName;charset=" . DB_CHARSET;
    return new \PDO($dsn2, DB_USER, DB_PASS, [
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
}

function ilaf_drop(string $dbName): void {
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `$dbName`");
}

/** Run a probe against the throwaway PDO, then restore the app connection. */
function ilaf_with_database_pdo(\PDO $pdo, callable $fn): mixed {
    $property = new \ReflectionProperty(\Slate\Data\Database::class, 'pdo');
    $property->setAccessible(true);
    $previous = $property->getValue();
    $property->setValue(null, $pdo);
    // Phase 10: the installer's own trust check (installer_resolve_step())
    // re-verifies the cached payload with LICENSE_SERVER_PUBLIC_KEY, exactly
    // as a real install configured against the test signer would.
    $previousKey = $_ENV['LICENSE_SERVER_PUBLIC_KEY'] ?? null;
    $_ENV['LICENSE_SERVER_PUBLIC_KEY'] = license_test_public_key();
    try {
        return $fn();
    } finally {
        $property->setValue(null, $previous);
        if ($previousKey === null) unset($_ENV['LICENSE_SERVER_PUBLIC_KEY']); else $_ENV['LICENSE_SERVER_PUBLIC_KEY'] = $previousKey;
    }
}

/** A ready-to-use throwaway DB with the installer's Step 2 migrations applied and the tenant autoincrement aligned to this process's real TENANT_ID, so a freshly provisioned tenant's id matches the TENANT_ID constant installer_resolve_step()/SlateLicenseCacheStore hardcode. */
function ilaf_prepared_pdo(string $dbName): \PDO {
    $pdo = ilaf_fresh_pdo($dbName);
    $runner = new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations');
    $runner->migrate(ILAF_CORE_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

/** The Central Server stand-in's keypair (tests/support/license_signing.php) — the key this install is configured to trust. */
function ilaf_keypair(): array {
    return [
        'public' => license_test_public_key(),
        'secret' => base64_encode(license_test_secret_key()),
    ];
}

function ilaf_signed_envelope(array $keypair, array $statusFields): array {
    $payloadJson = (string) json_encode($statusFields, JSON_UNESCAPED_SLASHES);
    $signature   = base64_encode(sodium_crypto_sign_detached($payloadJson, base64_decode($keypair['secret'], true)));
    return ['payload' => $payloadJson, 'signature' => $signature];
}

/** A RemoteLicenseClient wired against a throwaway install's own real installation_id, with a fake transport returning $response for every call. */
function ilaf_client(string $installId, string $publicKey, callable $transport): RemoteLicenseClient {
    return new RemoteLicenseClient([
        'server_url'  => 'https://license.example.test',
        'public_key'  => $publicKey,
        'product'     => 'kohevo',
        'license_key' => 'ILAF-TEST-KEY',
        'install_id'  => $installId,
        'domain'      => 'client.example',
        'app_version' => '1.0.0',
    ], new SlateLicenseCacheStore((int) TENANT_ID), $transport);
}

unit('Phase 5: installer_resolve_step() returns 2 right after a fresh DB with no installation identity yet', function (): void {
    $dbName = 'slate_ilaf_step2_' . slate_test_ns();
    $pdo = ilaf_prepared_pdo($dbName);
    try {
        $step = ilaf_with_database_pdo($pdo, static fn (): int => installer_resolve_step());
        assert_eq(2, $step);
    } finally {
        ilaf_drop($dbName);
    }
});

unit('Phase 5: installer_resolve_step() returns 3 once Step 2 (provisionCore) has run but no license has been activated', function (): void {
    $dbName = 'slate_ilaf_step3_' . slate_test_ns();
    $pdo = ilaf_prepared_pdo($dbName);
    try {
        $step = ilaf_with_database_pdo($pdo, static function (): int {
            InstallationService::provisionCore();
            return installer_resolve_step();
        });
        assert_eq(3, $step);
    } finally {
        ilaf_drop($dbName);
    }
});

unit('Phase 5: an invalid/rejected license check-in leaves resolve_step() at 3 -- retry with a corrected key remains possible (Scenario C)', function (): void {
    $dbName = 'slate_ilaf_reject_' . slate_test_ns();
    $pdo = ilaf_prepared_pdo($dbName);
    try {
        $keypair = ilaf_keypair();
        [$stepAfterReject, $stepAfterGoodKey] = ilaf_with_database_pdo($pdo, static function () use ($keypair): array {
            $core = InstallationService::provisionCore();

            // First attempt: server rejects (wrong key / activation_limit /
            // binding_mismatch all look identical from here -- a non-200).
            $badClient = ilaf_client($core['installation_id'], $keypair['public'], static function () {
                return [403, json_encode(['error' => 'invalid_request'])];
            });
            $badResult = $badClient->checkInDetailed();
            $stepAfterReject = installer_resolve_step();

            // Retry with a "corrected" key -- the server now accepts it.
            $envelope = ilaf_signed_envelope($keypair, [
                'installation_id' => $core['installation_id'],
                'status' => 'active', 'plan' => 'pro', 'entitlements' => ['forms'],
                'expires_at' => null, 'checked_at' => gmdate('c'), 'next_check_after' => 86400,
            ]);
            $goodClient = ilaf_client($core['installation_id'], $keypair['public'], static function () use ($envelope) {
                return [200, json_encode($envelope)];
            });
            assert_true($goodClient->checkInDetailed()['ok'], 'sanity: the retry itself must succeed');
            $stepAfterGoodKey = installer_resolve_step();

            assert_false($badResult['ok']);
            return [$stepAfterReject, $stepAfterGoodKey];
        });

        assert_eq(3, $stepAfterReject, 'a rejected check-in must not advance the installer or corrupt state');
        assert_eq(4, $stepAfterGoodKey, 'a subsequent successful check-in with a valid key must advance to admin creation');
    } finally {
        ilaf_drop($dbName);
    }
});

unit('Phase 5: a network failure during check-in leaves resolve_step() at 3, distinguishable from a rejection', function (): void {
    $dbName = 'slate_ilaf_network_' . slate_test_ns();
    $pdo = ilaf_prepared_pdo($dbName);
    try {
        [$reason, $step] = ilaf_with_database_pdo($pdo, static function (): array {
            $core = InstallationService::provisionCore();
            $client = ilaf_client($core['installation_id'], base64_encode(str_repeat('k', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)), static function () {
                return null; // transport-level failure
            });
            $result = $client->checkInDetailed();
            return [$result['reason'], installer_resolve_step()];
        });

        assert_eq('network', $reason);
        assert_eq(3, $step);
    } finally {
        ilaf_drop($dbName);
    }
});

unit('Phase 5: full happy path -- provisionCore -> successful activation -> resolve=4 -> createAdminAccount -> resolve=5', function (): void {
    $dbName = 'slate_ilaf_happy_' . slate_test_ns();
    $pdo = ilaf_prepared_pdo($dbName);
    try {
        $keypair = ilaf_keypair();
        $steps = ilaf_with_database_pdo($pdo, static function () use ($keypair): array {
            $core = InstallationService::provisionCore();
            $stepBeforeActivation = installer_resolve_step();

            $envelope = ilaf_signed_envelope($keypair, [
                'installation_id' => $core['installation_id'],
                'status' => 'active', 'plan' => 'professional', 'entitlements' => ['forms', 'booking'],
                'expires_at' => null, 'checked_at' => gmdate('c'), 'next_check_after' => 86400,
            ]);
            $client = ilaf_client($core['installation_id'], $keypair['public'], static function () use ($envelope) {
                return [200, json_encode($envelope)];
            });
            assert_true($client->checkInDetailed()['ok']);
            $stepAfterActivation = installer_resolve_step();

            InstallationService::createAdminAccount(
                $core['tenant_id'], 'Happy Path Admin', 'happy-path@example.test',
                password_hash('happy-path-password', PASSWORD_DEFAULT)
            );
            $stepAfterAdmin = installer_resolve_step();

            $store = new SlateLicenseCacheStore((int) TENANT_ID);
            $entitlements = $store->readTrustState()['data']['entitlements'] ?? null;

            return [$stepBeforeActivation, $stepAfterActivation, $stepAfterAdmin, $entitlements];
        });

        assert_eq(3, $steps[0]);
        assert_eq(4, $steps[1]);
        assert_eq(5, $steps[2]);
        assert_eq(['forms', 'booking'], $steps[3], 'the entitlements from the signed payload must be readable for Step 7\'s auto-activation');
    } finally {
        ilaf_drop($dbName);
    }
});

unit('Phase 5: resolve_step() never regresses on a retried Step 2 (Scenario B) -- reusing the same installation_id, not consuming a second activation', function (): void {
    $dbName = 'slate_ilaf_retry_step2_' . slate_test_ns();
    $pdo = ilaf_prepared_pdo($dbName);
    try {
        $result = ilaf_with_database_pdo($pdo, static function (): array {
            $first = InstallationService::provisionCore();
            // Simulates a double-submitted / re-visited Step 2 before the
            // operator ever reaches Step 3.
            $second = InstallationService::provisionCore();
            return [$first, $second, installer_resolve_step()];
        });

        assert_eq($result[0]['installation_id'], $result[1]['installation_id']);
        assert_eq(3, $result[2]);
    } finally {
        ilaf_drop($dbName);
    }
});

unit('Phase 5: a tampered/mismatched cache row (e.g. cloned from another installation) keeps resolve_step() at 3, never silently advancing to admin creation', function (): void {
    $dbName = 'slate_ilaf_tampered_' . slate_test_ns();
    $pdo = ilaf_prepared_pdo($dbName);
    try {
        $step = ilaf_with_database_pdo($pdo, static function (): int {
            InstallationService::provisionCore();
            // A well-formed but WRONG installation_id -- exactly what a
            // copied raw cache row from a different installation looks like.
            license_test_seed_cache((int) TENANT_ID, [
                'status' => 'active', 'plan' => 'pro', 'entitlements' => ['forms'],
                'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
                'installation_id' => str_repeat('9', 32),
            ]);
            return installer_resolve_step();
        });

        assert_eq(3, $step, 'an untrusted cache row must never be read as a genuine activation');
    } finally {
        ilaf_drop($dbName);
    }
});

unit('installer_normalize_license_key(): trims, rejects empty and absurdly long input, accepts a plausible key', function (): void {
    assert_eq('Enter your license key.', installer_normalize_license_key('')['error']);
    assert_eq('Enter your license key.', installer_normalize_license_key('   ')['error']);
    assert_null(installer_normalize_license_key('  KOHEVO-ABCD-1234  ')['error']);
    assert_eq('KOHEVO-ABCD-1234', installer_normalize_license_key('  KOHEVO-ABCD-1234  ')['value']);
    assert_eq('That license key is too long to be valid.', installer_normalize_license_key(str_repeat('a', 201))['error']);
});

unit('installer_domain_from_url(): normalizes default ports away and keeps a custom port', function (): void {
    assert_eq('example.com', installer_domain_from_url('https://example.com'));
    assert_eq('example.com', installer_domain_from_url('https://example.com:443/'));
    assert_eq('example.com', installer_domain_from_url('http://example.com:80'));
    assert_eq('example.com:8443', installer_domain_from_url('https://example.com:8443'));
    assert_eq('localhost:8080', installer_domain_from_url('http://localhost:8080'));
});

unit('installer_set_env_line(): appends a new key and idempotently replaces an existing one without disturbing other lines', function (): void {
    $body = installer_set_env_line("APP_URL=https://example.com\n", 'TENANT_ID', '1');
    assert_eq("APP_URL=https://example.com\nTENANT_ID=1\n", $body);

    $body = installer_set_env_line($body, 'TENANT_ID', '2');
    assert_eq("APP_URL=https://example.com\nTENANT_ID=2\n", $body, 'must replace, not duplicate, the existing line');

    $body = installer_set_env_line($body, 'INSTALLATION_ID', str_repeat('a', 32));
    assert_eq("APP_URL=https://example.com\nTENANT_ID=2\nINSTALLATION_ID=" . str_repeat('a', 32) . "\n", $body);
});

unit('Phase 12 (05 §1 Step 4): a BOUND installation receiving a signed suspended / revoked / expired state is cached for the Guard but never advances past the License step', function (): void {
    $dbName = 'slate_ilaf_inactive_' . slate_test_ns();
    $pdo = ilaf_prepared_pdo($dbName);
    try {
        $keypair = ilaf_keypair();
        $results = ilaf_with_database_pdo($pdo, static function () use ($keypair): array {
            $core = InstallationService::provisionCore();
            $out = [];
            $cases = [
                'suspended'            => ['status' => 'suspended', 'expires_at' => null],
                'revoked'              => ['status' => 'revoked', 'expires_at' => null],
                'expired inside grace' => ['status' => 'expired', 'expires_at' => gmdate('Y-m-d H:i:s', time() - 86400)],
                'expired beyond grace' => ['status' => 'expired', 'expires_at' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)],
                'active past grace'    => ['status' => 'active', 'expires_at' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)],
                'active, expiring in 3 days' => ['status' => 'active', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3 * 86400)],
                'trial'                => ['status' => 'trial', 'expires_at' => null],
            ];
            $sequence = 0;
            foreach ($cases as $label => $fields) {
                \Database::query('DELETE FROM remote_license_cache WHERE tenant_id = ?', [(int) TENANT_ID]);
                $envelope = ilaf_signed_envelope($keypair, [
                    'installation_id' => $core['installation_id'], 'plan' => 'pro', 'entitlements' => ['forms'],
                    'checked_at' => gmdate('c', time() + $sequence++), 'next_check_after' => 86400,
                ] + $fields);
                $client = ilaf_client($core['installation_id'], $keypair['public'], static fn () => [200, json_encode($envelope)]);
                $ok = $client->checkInDetailed()['ok'];
                $trust = (new SlateLicenseCacheStore((int) TENANT_ID))->readTrustState();
                $out[$label] = [$ok, $trust['trusted'], installer_license_usable($trust), installer_resolve_step()];
            }
            return $out;
        });

        foreach (['suspended', 'revoked', 'expired inside grace', 'expired beyond grace', 'active past grace'] as $label) {
            [$ok, $trusted, $usable, $step] = $results[$label];
            assert_true($ok && $trusted, "$label: the genuine signed state is still verified and cached (Guard enforcement)");
            assert_false($usable, "$label: not usable for installation");
            assert_eq(3, $step, "$label: the installer stays on the License step");
        }
        foreach (['active, expiring in 3 days', 'trial'] as $label) {
            [, , $usable, $step] = $results[$label];
            assert_true($usable, "$label: usable");
            assert_eq(4, $step, "$label: advances to admin creation");
        }
    } finally {
        ilaf_drop($dbName);
    }
});

unit('Phase 12 (06 §5.1): the installer persists the verified license key to .env so the unattended check-in can run after install', function (): void {
    $src = (string) file_get_contents(SLATE_ROOT . '/install.php');
    $success = strpos($src, "if (\$result['ok'] && installer_license_usable(\$store->readTrustState())) {");
    $persist = strpos($src, "installer_set_env_line((string) file_get_contents(\$envPath), 'LICENSE_KEY', \$licenseKeyInput['value'])");
    $redirect = $success === false ? false : strpos($src, "'?step=4'", $success);
    assert_true($success !== false, 'step 3 advances only on a usable verified license');
    assert_true($persist !== false && $persist > $success && $persist < $redirect, 'LICENSE_KEY is written on that success path, before the redirect to step 4');
});
