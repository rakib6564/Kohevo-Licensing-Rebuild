<?php
/**
 * Phase 10 — Central side of Central ↔ Client synchronization.
 *
 * docs/02-architecture/11-LICENSING-API-CONTRACT.md §7 (LOCKED, resolves
 * F-02): a request that resolves to an existing, valid (license_key,
 * install_id, domain) binding receives HTTP 200 with a signed envelope for
 * EVERY commercial status — active, expired, suspended, revoked,
 * cancelled — so Suspend/Revoke/Expire reach the client as authoritative
 * signed state. §13: malformed requests, unknown keys, binding/domain
 * mismatches and first activations against a non-activatable license stay
 * uniform anti-enumeration errors with no payload. §2: the payload carries
 * installation_id (from the resolved binding), warning_days and grace_days.
 *
 * Real database, real LicensingAPI::handleCheckIn(), real lifecycle
 * operations through LicenseService — no mocks.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/licensing/LicensingAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/client/LicenseSignatureVerifier.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/PlanService.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/LicenseService.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/InstallationService.php';

function p10s_reset(): void {
    $sql = preg_replace('/^--.*$/m', '', (string) file_get_contents(__DIR__ . '/../../plugins/licensing/uninstall.sql'));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '') Database::query($stmt);
    }
    Database::query("DELETE FROM settings WHERE setting_key IN ('licensing.signing_public_key', 'licensing.signing_secret_key')");
    (new ReflectionProperty(LicensingAPI::class, 'schemaChecked'))->setValue(null, false);
    LicensingAPI::ensureSchema();
    LicensingAPI::storeSigningKeypair(LicensingAPI::generateSigningKeypair());
}

/** @return array{licenseId:int, licenseKey:string} */
function p10s_issue(array $overrides = []): array {
    $productId = Database::value('SELECT id FROM licensing_products WHERE slug = ?', ['kohevo'])
        ?: Database::insert('licensing_products', ['slug' => 'kohevo', 'name' => 'Kohevo']);
    Database::value('SELECT id FROM licensing_products WHERE slug = ?', ['other-product'])
        ?: Database::insert('licensing_products', ['slug' => 'other-product', 'name' => 'Other']);
    $planId = Database::value('SELECT id FROM licensing_plans WHERE product_id = ? AND slug = ?', [$productId, 'pro'])
        ?: PlanService::create(['product_id' => $productId, 'slug' => 'pro', 'name' => 'Pro']);
    $clientId = Database::insert('licensing_clients', ['name' => 'Phase 10 Client']);
    $license = LicenseService::issue(array_merge([
        'client_id' => $clientId, 'product_id' => $productId, 'plan_id' => $planId,
        'modules' => ['forms', 'booking'], 'expires_at' => gmdate('Y-m-d H:i:s', time() + 365 * 86400),
    ], $overrides), 'kohevo');
    return ['licenseId' => (int) $license['id'], 'licenseKey' => $license['license_key']];
}

function p10s_id(string $c): string { return str_repeat($c, 32); }

function p10s_check(string $key, string $installId, string $domain = 'sync.example', string $product = 'kohevo'): array {
    return LicensingAPI::handleCheckIn(['product' => $product, 'license_key' => $key, 'install_id' => $installId, 'domain' => $domain], '203.0.113.20');
}

/** The verified, decoded payload of a 200 response (fails the test if it does not verify). */
function p10s_payload(array $result): array {
    assert_eq(200, $result['http_status'], 'expected a signed 200 envelope');
    $verifier = new LicenseSignatureVerifier((string) LicensingAPI::signingPublicKey());
    assert_true($verifier->verify($result['body']['payload'], $result['body']['signature']), 'signature must verify with the public key');
    return json_decode($result['body']['payload'], true);
}

function p10s_assert_no_payload(array $result, string $context): void {
    assert_false(isset($result['body']['payload']) || isset($result['body']['signature']), "$context: an error response must never carry a payload or signature");
    assert_eq(['error' => 'invalid_request'], $result['body'], "$context: uniform anti-enumeration body");
}

unit('Phase 10 central: an active bound installation receives the full signed contract payload (installation_id, warning_days, grace_days, checked_at)', function () {
    p10s_reset();
    try {
        $f = p10s_issue();
        $p = p10s_payload(p10s_check($f['licenseKey'], p10s_id('a')));
        assert_eq(p10s_id('a'), $p['installation_id']);
        assert_eq('active', $p['status']);
        assert_eq('pro', $p['plan']);
        assert_eq(['booking', 'forms'], $p['entitlements']);
        assert_eq(7, $p['warning_days']);
        assert_eq(7, $p['grace_days']);
        assert_true(is_string($p['checked_at']) && strtotime($p['checked_at']) !== false, 'checked_at is a server timestamp');
        assert_eq(86400, $p['next_check_after']);
        assert_false(str_contains(json_encode($p), $f['licenseKey']), 'the raw license key is never part of the signed state');
    } finally { p10s_reset(); }
});

unit('Phase 10 central (11 §7): suspend on the Central Server → the bound installation receives a signed 200 carrying status=suspended, not a 403', function () {
    p10s_reset();
    try {
        $f = p10s_issue();
        p10s_payload(p10s_check($f['licenseKey'], p10s_id('a')));
        LicenseService::suspend($f['licenseId'], null, 'non-payment');
        $p = p10s_payload(p10s_check($f['licenseKey'], p10s_id('a')));
        assert_eq('suspended', $p['status']);
        assert_eq(p10s_id('a'), $p['installation_id']);
        assert_eq(7, $p['grace_days'], 'window lengths travel with every status');

        LicenseService::unsuspend($f['licenseId'], null);
        assert_eq('active', p10s_payload(p10s_check($f['licenseKey'], p10s_id('a')))['status'], 'unsuspend propagates the same way');
    } finally { p10s_reset(); }
});

unit('Phase 10 central (11 §7): revoke → signed revoked; cancelled → signed cancelled; the installation stays bound (no rebinding, no second activation)', function () {
    p10s_reset();
    try {
        $f = p10s_issue();
        p10s_payload(p10s_check($f['licenseKey'], p10s_id('a')));
        LicenseService::revoke($f['licenseId'], null, 'fraud');
        assert_eq('revoked', p10s_payload(p10s_check($f['licenseKey'], p10s_id('a')))['status']);
        assert_eq('revoked', p10s_payload(p10s_check($f['licenseKey'], p10s_id('a')))['status'], 'repeatable');
        $activates = array_filter(LicenseService::events($f['licenseId']), fn($e) => $e['event_type'] === 'activate');
        assert_eq(1, count($activates), 'delivering a lapsed status is a refresh, never an activation');

        $g = p10s_issue();
        p10s_payload(p10s_check($g['licenseKey'], p10s_id('b')));
        Database::update('licensing_licenses', ['status' => 'cancelled'], 'id = ?', [$g['licenseId']]);
        assert_eq('cancelled', p10s_payload(p10s_check($g['licenseKey'], p10s_id('b')))['status']);
    } finally { p10s_reset(); }
});

unit('Phase 10 central: expiry is derived lazily and delivered signed; renew and extend propagate the new expires_at on the next check-in', function () {
    p10s_reset();
    try {
        $f = p10s_issue();
        p10s_payload(p10s_check($f['licenseKey'], p10s_id('a')));

        $past = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
        Database::update('licensing_licenses', ['expires_at' => $past], 'id = ?', [$f['licenseId']]);
        $p = p10s_payload(p10s_check($f['licenseKey'], p10s_id('a')));
        assert_eq('expired', $p['status'], 'a lapsed license is reported as expired, signed');
        assert_eq($past, $p['expires_at']);
        assert_eq('expired', LicenseService::find($f['licenseId'])['status'], 'the lazy sync persisted the lifecycle transition');

        $renewed = gmdate('Y-m-d H:i:s', time() + 365 * 86400);
        LicenseService::renew($f['licenseId'], $renewed);
        $p = p10s_payload(p10s_check($f['licenseKey'], p10s_id('a')));
        assert_eq('active', $p['status']);
        assert_eq($renewed, $p['expires_at']);

        $extended = gmdate('Y-m-d H:i:s', time() + 400 * 86400);
        LicenseService::extend($f['licenseId'], $extended);
        assert_eq($extended, p10s_payload(p10s_check($f['licenseKey'], p10s_id('a')))['expires_at']);
    } finally { p10s_reset(); }
});

unit('Phase 10 central (11 §13): a NEW installation against a suspended/revoked/expired license gets no payload and never binds', function () {
    p10s_reset();
    try {
        foreach (['suspended', 'revoked', 'expired', 'cancelled'] as $status) {
            $f = p10s_issue(['status' => $status]);
            $r = p10s_check($f['licenseKey'], p10s_id('c'));
            assert_eq(403, $r['http_status'], "unbound + $status");
            p10s_assert_no_payload($r, "unbound + $status");
            assert_null(InstallationService::active($f['licenseId']));
        }
    } finally { p10s_reset(); }
});

unit('Phase 10 central: a bound-but-suspended license still answers a DIFFERENT installation, wrong domain, wrong product and wrong key with uniform errors and no payload', function () {
    p10s_reset();
    try {
        $f = p10s_issue();
        p10s_payload(p10s_check($f['licenseKey'], p10s_id('a')));
        LicenseService::suspend($f['licenseId'], null, 'test');

        $other = p10s_check($f['licenseKey'], p10s_id('d'));
        assert_true(in_array($other['http_status'], [403, 404], true), 'another installation is refused');
        p10s_assert_no_payload($other, 'another installation');

        p10s_assert_no_payload(p10s_check($f['licenseKey'], p10s_id('a'), 'elsewhere.example'), 'domain mismatch');
        p10s_assert_no_payload(p10s_check($f['licenseKey'], p10s_id('a'), 'sync.example', 'other-product'), 'wrong product');
        p10s_assert_no_payload(p10s_check('KOHEVO-0000-0000-0000-0000-0000', p10s_id('a')), 'unknown key');
        assert_eq(p10s_id('a'), InstallationService::active($f['licenseId'])['installation_id'], 'the real binding is untouched');
    } finally { p10s_reset(); }
});

unit('Phase 10 central: an administratively revoked INSTALLATION (binding) gets no payload — the license state is never signed for a dead binding', function () {
    p10s_reset();
    try {
        $f = p10s_issue();
        p10s_payload(p10s_check($f['licenseKey'], p10s_id('a')));
        $active = InstallationService::active($f['licenseId']);
        InstallationService::revoke((int) $active['id'], $f['licenseId']);
        $r = p10s_check($f['licenseKey'], p10s_id('a'));
        assert_eq(404, $r['http_status']);
        p10s_assert_no_payload($r, 'revoked binding');
    } finally { p10s_reset(); }
});

unit('Phase 10 central: malformed install_id shapes are still refused before any lookup (Phase 4 behaviour preserved)', function () {
    p10s_reset();
    try {
        $f = p10s_issue();
        foreach ([strtoupper(p10s_id('a')), p10s_id('a') . 'a', ' ' . p10s_id('a'), str_repeat('g', 32), ''] as $bad) {
            $r = p10s_check($f['licenseKey'], $bad);
            assert_eq(400, $r['http_status'], 'install_id=' . var_export($bad, true));
            p10s_assert_no_payload($r, 'malformed install_id');
        }
        assert_null(InstallationService::active($f['licenseId']));
    } finally { p10s_reset(); }
});

unit('Phase 12 central (11 §7): first activation of a license whose expires_at already passed is signed and stored as expired, never active', function () {
    p10s_reset();
    try {
        foreach (['inside grace' => 86400, 'beyond grace' => 30 * 86400] as $label => $age) {
            $f = p10s_issue(['expires_at' => gmdate('Y-m-d H:i:s', time() - $age)]);
            $installId = bin2hex(random_bytes(16));
            $p = p10s_payload(p10s_check($f['licenseKey'], $installId));
            assert_eq('expired', $p['status'], "$label: the signed payload carries the actual commercial state");
            assert_eq($installId, $p['installation_id']);
            $license = LicenseService::find($f['licenseId']);
            assert_eq('expired', $license['status'], "$label: never stored as active with a past expiry");
            $events = array_column(LicenseService::events($f['licenseId']), 'event_type');
            assert_true(in_array('activate', $events, true) && in_array('expire', $events, true), "$label: activate then expire recorded: " . implode(',', $events));
            LicenseService::renew($f['licenseId'], gmdate('Y-m-d H:i:s', time() + 365 * 86400), null, 'phase 12');
            assert_eq('active', p10s_payload(p10s_check($f['licenseKey'], $installId))['status'], "$label: renewal is reachable and restores active");
        }
        // Unchanged: a future expiry still activates as active.
        $f = p10s_issue();
        assert_eq('active', p10s_payload(p10s_check($f['licenseKey'], bin2hex(random_bytes(16))))['status']);
    } finally { p10s_reset(); }
});
