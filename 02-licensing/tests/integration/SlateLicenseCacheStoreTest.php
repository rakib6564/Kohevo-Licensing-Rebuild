<?php
/**
 * Phase 4 of the remote license server build: SlateLicenseCacheStore, the
 * Kohevo-specific storage adapter over remote_license_cache (0022
 * migration). RemoteLicenseClient's own logic is covered without a DB in
 * tests/unit/RemoteLicenseClientTest.php via a fake store; this file
 * proves the REAL adapter round-trips correctly, and separately proves
 * the two wired together end to end.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/licensing/LicensingAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/client/RemoteLicenseClient.php';

use Slate\Services\Licensing\SlateLicenseCacheStore;

function slcs_clear(int $tenantId): void {
    Database::query('DELETE FROM remote_license_cache WHERE tenant_id = ?', [$tenantId]);
}

unit('SlateLicenseCacheStore: load() returns null when nothing has ever been saved for this tenant', function () {
    $tenantId = slate_test_tenant(90001);
    slcs_clear($tenantId);
    $store = new SlateLicenseCacheStore($tenantId);
    assert_null($store->load());
});

unit('SlateLicenseCacheStore: save() then load() round-trips every field', function () {
    $tenantId = slate_test_tenant(90002);
    slcs_clear($tenantId);
    try {
        $store = new SlateLicenseCacheStore($tenantId);
        $store->save([
            'status' => 'active', 'plan' => 'pro', 'entitlements' => ['white_label', 'priority_support'],
            'expires_at' => '2027-01-15 00:00:00', 'fetched_at' => '2026-09-21 10:00:00',
        ]);

        $loaded = $store->load();
        assert_eq('active', $loaded['status']);
        assert_eq('pro', $loaded['plan']);
        assert_eq(['white_label', 'priority_support'], $loaded['entitlements']);
        assert_eq('2027-01-15 00:00:00', $loaded['expires_at']);
    } finally {
        slcs_clear($tenantId);
    }
});

unit('SlateLicenseCacheStore: a second save() updates the existing row in place -- one row per tenant, never a duplicate', function () {
    $tenantId = slate_test_tenant(90003);
    slcs_clear($tenantId);
    try {
        $store = new SlateLicenseCacheStore($tenantId);
        $store->save(['status' => 'active', 'plan' => 'pro', 'entitlements' => [], 'expires_at' => null, 'fetched_at' => '2026-09-21 09:00:00']);
        $store->save(['status' => 'suspended', 'plan' => 'pro', 'entitlements' => [], 'expires_at' => null, 'fetched_at' => '2026-09-21 10:00:00']);

        $count = (int) Database::value('SELECT COUNT(*) FROM remote_license_cache WHERE tenant_id = ?', [$tenantId]);
        assert_eq(1, $count);

        $loaded = $store->load();
        assert_eq('suspended', $loaded['status'], 'the second save must overwrite, not sit alongside, the first');
    } finally {
        slcs_clear($tenantId);
    }
});

unit('SlateLicenseCacheStore: two different tenants never see each others cached status', function () {
    $tenantA = slate_test_tenant(90004);
    $tenantB = slate_test_tenant(90005);
    slcs_clear($tenantA);
    slcs_clear($tenantB);
    try {
        (new SlateLicenseCacheStore($tenantA))->save(['status' => 'active', 'plan' => null, 'entitlements' => [], 'expires_at' => null, 'fetched_at' => '2026-09-21 10:00:00']);
        (new SlateLicenseCacheStore($tenantB))->save(['status' => 'suspended', 'plan' => null, 'entitlements' => [], 'expires_at' => null, 'fetched_at' => '2026-09-21 10:00:00']);

        assert_eq('active', (new SlateLicenseCacheStore($tenantA))->load()['status']);
        assert_eq('suspended', (new SlateLicenseCacheStore($tenantB))->load()['status']);
    } finally {
        slcs_clear($tenantA);
        slcs_clear($tenantB);
    }
});

unit('End to end: RemoteLicenseClient + SlateLicenseCacheStore -- a real license server check-in (LicensingAPI::handleCheckIn) lands correctly in the real cache table', function () {
    $tenantId = slate_test_tenant(90006);
    slcs_clear($tenantId);

    // Stand up a real (in-process) copy of the server side: schema, a
    // signing keypair, and one product/client/plan/install row -- then
    // drive the client against LicensingAPI::handleCheckIn() directly
    // (a real HTTP round trip through this exact code is already covered
    // manually in Phase 3's verification and by LicensingCheckInTest.php;
    // this test's job is proving the CLIENT side plugs into it correctly).
    $prop = new ReflectionProperty(LicensingAPI::class, 'schemaChecked');
    $prop->setValue(null, false);
    LicensingAPI::ensureSchema();
    try {
        LicensingAPI::storeSigningKeypair(LicensingAPI::generateSigningKeypair());
        $productId = Database::insert('licensing_products', ['slug' => 'kohevo', 'name' => 'Kohevo']);
        $clientId  = Database::insert('licensing_clients', ['name' => 'E2E Client']);
        $planId    = Database::insert('licensing_plans', [
            'product_id' => $productId, 'slug' => 'pro', 'name' => 'Pro',
            'entitlements_json' => json_encode(['white_label']),
        ]);
        Database::insert('licensing_installs', [
            'client_id' => $clientId, 'product_id' => $productId, 'plan_id' => $planId,
            'domain' => 'e2e-phase4.example', 'license_key_hash' => hash('sha256', 'phase4-e2e-key'),
            'status' => 'active',
        ]);

        $store = new SlateLicenseCacheStore($tenantId);
        $client = new RemoteLicenseClient([
            'server_url' => 'https://unused.example', // the fake transport below never actually reaches out
            'public_key' => (string) LicensingAPI::signingPublicKey(),
            'product'    => 'kohevo',
            'license_key' => 'phase4-e2e-key',
            'install_id' => 'phase4-e2e-install',
            'domain'      => 'e2e-phase4.example',
            'app_version' => '1.0.0',
        ], $store, function (string $url, string $body) {
            $input = json_decode($body, true);
            $result = LicensingAPI::handleCheckIn($input, '203.0.113.55');
            return [$result['http_status'], json_encode($result['body'])];
        });

        $ok = $client->checkIn();
        assert_true($ok);

        $cached = $store->load();
        assert_eq('active', $cached['status']);
        assert_eq('pro', $cached['plan']);
        assert_eq(['white_label'], $cached['entitlements']);
    } finally {
        slcs_clear($tenantId);
        $file = dirname(__DIR__, 2) . '/plugins/licensing/uninstall.sql';
        $sql  = preg_replace('/^--.*$/m', '', (string) file_get_contents($file));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '') Database::query($stmt);
        }
        Database::query("DELETE FROM settings WHERE setting_key LIKE 'licensing.%'");
    }
});
