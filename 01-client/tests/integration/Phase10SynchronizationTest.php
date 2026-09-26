<?php
/**
 * Phase 10 — Central ↔ Client synchronization, end to end.
 *
 * Every check-in here goes through the client's real RemoteLicenseClient
 * and SlateLicenseCacheStore (this checkout's test database) to the REAL
 * Central Server logic — LicensingAPI::handleCheckIn(), LicenseService,
 * InstallationService — running against 02-licensing's own test database
 * via tests/fixtures/phase10-central.php (one process per call, no HTTP
 * server). The Central Server signs with the shared test key from
 * tests/support/license_signing.php, which is also the public key this
 * install and its probes are configured to trust.
 *
 * Covers (planning.md Phase 10; docs/02-architecture/08 §3–§5, 10 §3–§4,
 * 11 §2/§5–§7/§13, 12 §2.1/§2.2/§2.7):
 *   - activation, refresh, suspension, revocation, expiry/grace, renewal,
 *     extension — each reaching the Guard, ModuleGuard and the License UI
 *   - every rejected response (network, HTTP, malformed, bad signature,
 *     wrong key, wrong installation/product/license, stale/replayed,
 *     store failure) leaving the trusted cache byte-for-byte unchanged
 *   - read-time re-verification of the retained signed payload (F-P9-01)
 *   - ordered, atomic writes under concurrent refreshes
 *   - the additive 0026 migration (fresh, replay, pre-existing row)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/client/RemoteLicenseClient.php';

use Slate\Data\MigrationRunner;
use Slate\Services\Licensing\CommercialLicenseWindow;
use Slate\Services\Licensing\SlateLicenseCacheStore;

const P10_DAY = 86400;
const P10_DOMAIN = 'localhost';

/** Distinct from every other licensing suite's identity. */
function p10_identity(): string { return str_repeat('7', 32); }

function p10_ensure_identity(): void {
    $row = Database::row('SELECT installation_id FROM installation_identity WHERE singleton_id = 1');
    if ($row === null) {
        Database::insert('installation_identity', ['singleton_id' => 1, 'tenant_id' => current_tenant_id(), 'installation_id' => p10_identity()]);
    } elseif ((string) $row['installation_id'] !== p10_identity()) {
        Database::update('installation_identity', ['installation_id' => p10_identity()], 'singleton_id = 1', []);
    }
}

function p10_clear(): void {
    Database::query('DELETE FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]);
}

// ── The real Central Server, one process per call ──────────────────────

/** @return array<string,mixed> */
function p10_central(array $args, ?string $stdin = null): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/02-licensing/tests/fixtures/phase10-central.php');
    foreach ($args as $arg) $cmd .= ' ' . escapeshellarg((string) $arg);
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], (string) $stdin);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    $decoded = json_decode($out, true);
    if ($code !== 0 || !is_array($decoded)) {
        throw new \RuntimeException('central fixture failed: ' . $args[0] . ' ' . trim($err . ' ' . $out));
    }
    return $decoded;
}

/** Fresh central schema signing with the shared test key; issues one license. @return array{license_id:int, license_key:string} */
function p10_central_setup(array $issue = []): array {
    p10_central(['reset', base64_encode(license_test_secret_key())]);
    return p10_central(['issue', json_encode($issue + ['expires_at' => gmdate('Y-m-d H:i:s', time() + 365 * P10_DAY)])]);
}

/** A RemoteLicenseClient transport that is the real Central Server. */
function p10_central_transport(?array &$log = null): callable {
    return static function (string $url, string $body) use (&$log): array {
        $res = p10_central(['checkin'], $body);
        $raw = (string) json_encode($res['body'], JSON_UNESCAPED_SLASHES);
        $log[] = $raw;
        return [(int) $res['http_status'], $raw];
    };
}

function p10_client(string $licenseKey, callable $transport, array $overrides = []): RemoteLicenseClient {
    $publicKey = $overrides['public_key'] ?? license_test_public_key();
    return new RemoteLicenseClient($overrides + [
        'server_url' => 'https://license-phase10.test', 'public_key' => $publicKey, 'product' => 'kohevo',
        'license_key' => $licenseKey, 'install_id' => p10_identity(), 'domain' => P10_DOMAIN, 'app_version' => '10.0.0',
    ], new SlateLicenseCacheStore(current_tenant_id(), license_test_public_key()), $transport);
}

function p10_sync(string $licenseKey): RemoteLicenseClient {
    $client = p10_client($licenseKey, p10_central_transport());
    assert_true($client->checkIn(), 'sync against the real Central Server must succeed (failure=' . var_export($client->lastFailure(), true) . ')');
    return $client;
}

function p10_trust(): array {
    return (new SlateLicenseCacheStore(current_tenant_id(), license_test_public_key()))->readTrustState();
}

function p10_window(): array {
    return CommercialLicenseWindow::evaluate(p10_trust(), time());
}

function p10_row(): ?array {
    return Database::row('SELECT * FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]);
}

/** A response signed by the shared test key (the Central Server stand-in) for arbitrary payload fields. */
function p10_signed_body(array $payload): string {
    $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    return (string) json_encode(['payload' => $json, 'signature' => license_test_sign($json)]);
}

// ── Guard / UI probes (the same shared fixtures every licensing suite uses) ──

function p10_shell(string $fixture, array $args): array {
    $cmd = 'SLATE_LICENSE_GUARD_LIVE=1 '
        . 'LICENSE_SERVER_URL=' . escapeshellarg('https://license-phase10.test') . ' '
        . license_test_env_prefix()
        . 'LICENSE_PRODUCT=' . escapeshellarg('kohevo') . ' '
        . 'LICENSE_KEY=' . escapeshellarg('phase10-probe-key') . ' '
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/' . $fixture);
    foreach ($args as $arg) $cmd .= ' ' . escapeshellarg((string) $arg);
    $out = (string) shell_exec($cmd . ' 2>/dev/null');
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) ($m[1] ?? 0), 'body' => substr($out, strlen($m[0] ?? ''))];
}

function p10_admin(string $page): array {
    return p10_shell('admin-page-probe.php', [$page, '', 1, '0']);
}

function p10_module(string $module): string {
    $cmd = 'SLATE_LICENSE_GUARD_LIVE=1 '
        . 'LICENSE_SERVER_URL=' . escapeshellarg('https://license-phase10.test') . ' '
        . license_test_env_prefix()
        . 'LICENSE_PRODUCT=' . escapeshellarg('kohevo') . ' '
        . 'LICENSE_KEY=' . escapeshellarg('phase10-probe-key') . ' '
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/module-guard-cron-probe.php')
        . ' ' . escapeshellarg($module) . ' 2>/dev/null';
    return trim((string) shell_exec($cmd));
}

function p10_license_state(string $body): ?string {
    return preg_match('/data-license-state="([a-z_]+)"/', $body, $m) ? $m[1] : null;
}

function p10_banner(string $body): ?string {
    return preg_match('/data-license-banner="([a-z_]+)"/', $body, $m) ? $m[1] : null;
}

function p10_teardown(): void {
    p10_clear();
    try { p10_central(['teardown']); } catch (\Throwable $e) {}
}

// ── A. Activation and refresh ────────────────────────────────────────────

unit('Phase 10 sync #19/#1: fresh install activation — central binds this installation, the client stores the exact signed envelope, and the app unlocks', function () {
    p10_ensure_identity();
    p10_clear();
    try {
        $c = p10_central_setup(['modules' => ['forms', 'booking']]);
        assert_eq(403, p10_admin('admin/index.php')['status'], 'precondition: no license state yet → locked');

        p10_sync($c['license_key']);

        $central = p10_central(['license', $c['license_id']]);
        assert_eq('active', $central['status'], 'Unactivated → Active on the Central Server');
        assert_eq(p10_identity(), $central['active_installation'], 'bound to THIS installation');

        $row = p10_row();
        assert_true(is_string($row['raw_payload']) && $row['raw_payload'] !== '', 'raw signed payload retained');
        assert_true(is_string($row['raw_signature']) && $row['raw_signature'] !== '', 'raw signature retained');
        assert_true($row['verified_at'] !== null, 'verified_at recorded');
        assert_eq(p10_identity(), json_decode($row['raw_payload'], true)['installation_id']);
        foreach ($row as $col => $value) {
            assert_false(is_string($value) && str_contains($value, $c['license_key']), "the raw license key is never cached ($col)");
        }

        $trust = p10_trust();
        assert_true($trust['trusted']);
        assert_eq('active', $trust['data']['status']);
        assert_eq(['booking', 'forms'], $trust['data']['entitlements']);
        assert_eq(7, $trust['data']['grace_days']);

        assert_eq(200, p10_admin('admin/index.php')['status'], 'dashboard unlocked');
        assert_eq('ALLOW', p10_module('booking'), 'entitled module enabled');
        assert_eq('DENY', p10_module('membership'), 'unentitled module stays off');

        p10_sync($c['license_key']); // routine refresh
        $events = p10_central(['license', $c['license_id']])['events'];
        assert_eq(1, count(array_filter($events, fn($e) => $e === 'activate')), 'a refresh never re-activates');
    } finally { p10_teardown(); }
});

unit('Phase 10 sync #20: an existing Phase 9 installation (cache row with no signed payload) is untrusted until its next refresh, which restores it', function () {
    p10_ensure_identity();
    p10_clear();
    try {
        $c = p10_central_setup();
        Database::insert('remote_license_cache', [
            'tenant_id' => current_tenant_id(), 'status' => 'active', 'plan' => 'pro',
            'entitlements' => json_encode(['forms', 'booking']), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 100 * P10_DAY),
            'installation_id' => p10_identity(), 'fetched_at' => gmdate('Y-m-d H:i:s'),
            'remote_checked_at' => gmdate('Y-m-d H:i:s'), 'next_check_after' => 86400,
        ]);
        $trust = p10_trust();
        assert_true($trust['found'] && !$trust['trusted'], 'a pre-Phase-10 row is found but not trusted');
        assert_eq('unsigned', $trust['reason']);
        assert_eq(403, p10_admin('admin/index.php')['status'], 'fails closed, never grandfathered');

        p10_sync($c['license_key']);
        assert_true(p10_trust()['trusted'], 'the refresh upgrades the same row in place');
        assert_eq(1, (int) Database::value('SELECT COUNT(*) FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]), 'no duplicate cache row');
        assert_eq(200, p10_admin('admin/index.php')['status']);
    } finally { p10_teardown(); }
});

// ── B. Lifecycle propagation ─────────────────────────────────────────────

unit('Phase 10 sync #3: suspension on the Central Server reaches the client as trusted signed state and locks the app; unsuspend restores it', function () {
    p10_ensure_identity();
    p10_clear();
    try {
        $c = p10_central_setup();
        p10_sync($c['license_key']);
        p10_central(['suspend', $c['license_id']]);

        p10_sync($c['license_key']);
        $trust = p10_trust();
        assert_true($trust['trusted'], 'suspended is itself trusted signed state, not a failed check-in');
        assert_eq('suspended', $trust['data']['status']);
        assert_eq('suspended', p10_window()['reason']);
        assert_eq(403, p10_admin('admin/index.php')['status'], 'application locks');
        assert_eq('suspended', p10_license_state(p10_admin('admin/license.php')['body']), 'recovery screen names the suspension');
        assert_eq('DENY', p10_module('booking'), 'modules follow the lock');

        p10_central(['unsuspend', $c['license_id']]);
        p10_sync($c['license_key']);
        assert_eq(200, p10_admin('admin/index.php')['status'], 'unsuspension propagates on the next sync');
    } finally { p10_teardown(); }
});

unit('Phase 10 sync #4: revocation on the Central Server locks the client with trusted revoked state', function () {
    p10_ensure_identity();
    p10_clear();
    try {
        $c = p10_central_setup();
        p10_sync($c['license_key']);
        p10_central(['revoke', $c['license_id']]);
        p10_sync($c['license_key']);
        assert_eq('revoked', p10_trust()['data']['status']);
        assert_eq('revoked', p10_window()['reason']);
        assert_eq(403, p10_admin('admin/index.php')['status']);
        assert_eq('revoked', p10_license_state(p10_admin('admin/license.php')['body']));
    } finally { p10_teardown(); }
});

unit('Phase 10 sync #2: expiry reaches the client as signed "expired" — CommercialLicenseWindow keeps it in grace, then locks after grace', function () {
    p10_ensure_identity();
    p10_clear();
    try {
        $c = p10_central_setup();
        p10_sync($c['license_key']);

        p10_central(['set-expiry', $c['license_id'], gmdate('Y-m-d H:i:s', time() - 2 * P10_DAY)]);
        p10_sync($c['license_key']);
        assert_eq('expired', p10_trust()['data']['status']);
        $w = p10_window();
        assert_true($w['allowed'], 'within commercial grace');
        assert_eq('grace', $w['phase']);
        $dash = p10_admin('admin/index.php');
        assert_eq(200, $dash['status']);
        assert_eq('grace', p10_banner($dash['body']), 'the header warning reflects synchronized state');

        p10_central(['set-expiry', $c['license_id'], gmdate('Y-m-d H:i:s', time() - 8 * P10_DAY)]);
        p10_sync($c['license_key']);
        assert_eq('expired', p10_window()['reason']);
        assert_eq(403, p10_admin('admin/index.php')['status'], 'past grace → locked');
    } finally { p10_teardown(); }
});

unit('Phase 10 sync #5: renewal after the lock propagates the new expiry and unlocks with no banner', function () {
    p10_ensure_identity();
    p10_clear();
    try {
        $c = p10_central_setup();
        p10_sync($c['license_key']);
        p10_central(['set-expiry', $c['license_id'], gmdate('Y-m-d H:i:s', time() - 8 * P10_DAY)]);
        p10_sync($c['license_key']);
        assert_eq(403, p10_admin('admin/index.php')['status'], 'precondition: locked past grace');

        $renewed = gmdate('Y-m-d H:i:s', time() + 365 * P10_DAY);
        p10_central(['renew', $c['license_id'], $renewed]);
        p10_sync($c['license_key']);
        assert_eq('active', p10_trust()['data']['status']);
        assert_eq($renewed, p10_trust()['data']['expires_at']);
        $dash = p10_admin('admin/index.php');
        assert_eq(200, $dash['status']);
        assert_null(p10_banner($dash['body']));
    } finally { p10_teardown(); }
});

unit('Phase 10 sync #6: an extension received while expiring-soon recalculates the warning window from the new trusted expiry', function () {
    p10_ensure_identity();
    p10_clear();
    try {
        $c = p10_central_setup(['expires_at' => gmdate('Y-m-d H:i:s', time() + 3 * P10_DAY)]);
        p10_sync($c['license_key']);
        assert_eq('expiring_soon', p10_window()['phase']);
        assert_eq('expiring_soon', p10_banner(p10_admin('admin/index.php')['body']));

        p10_central(['extend', $c['license_id'], gmdate('Y-m-d H:i:s', time() + 60 * P10_DAY)]);
        p10_sync($c['license_key']);
        assert_eq('active', p10_window()['phase']);
        assert_null(p10_banner(p10_admin('admin/index.php')['body']));
    } finally { p10_teardown(); }
});

// ── C. Every rejection leaves the trusted cache untouched ────────────────

/** Runs $client->checkIn() and asserts it failed with $category while the cache row stayed byte-for-byte identical. */
function p10_assert_rejected(RemoteLicenseClient $client, string $category, string $context): void {
    $before = p10_row();
    assert_false($client->checkIn(), "$context: must not succeed");
    assert_eq($category, $client->lastFailure(), "$context: failure category");
    assert_eq($before, p10_row(), "$context: the trusted cache row is left exactly as it was");
    assert_true(p10_trust()['trusted'], "$context: the previous state is still trusted");
}

unit('Phase 10 sync #13/#14/#12/#7/#8/#18: network, HTTP, malformed, bad-signature and wrong-key responses are rejected and never replace a valid cache', function () {
    p10_ensure_identity();
    p10_clear();
    try {
        $c = p10_central_setup();
        p10_sync($c['license_key']);
        $key = $c['license_key'];
        $genuine = [];
        p10_client($key, p10_central_transport($genuine))->checkIn();
        $envelope = json_decode(end($genuine), true);

        p10_assert_rejected(p10_client($key, static fn() => null), 'network', 'network down / DNS / timeout');
        assert_eq('network', p10_client($key, static fn() => null)->checkInDetailed()['reason']);
        foreach ([500, 502, 503, 403] as $code) {
            p10_assert_rejected(p10_client($key, static fn() => [$code, '{"error":"server_error"}']), 'http_status', "HTTP $code");
        }
        p10_assert_rejected(p10_client($key, static fn() => [200, '<html>proxy error</html>']), 'malformed_envelope', 'non-JSON body');
        p10_assert_rejected(p10_client($key, static fn() => [200, '{"payload":{"status":"active"},"signature":"x"}']), 'malformed_envelope', 'payload not a string');
        p10_assert_rejected(p10_client($key, static fn() => [200, '{"status":"active"}']), 'malformed_envelope', 'unsigned flat object');

        $tampered = $envelope;
        $tampered['payload'] = str_replace('"status":"active"', '"status":"trial"', $tampered['payload']);
        p10_assert_rejected(p10_client($key, static fn() => [200, json_encode($tampered)]), 'signature_invalid', 'payload altered after signing');
        $badSig = $envelope;
        $badSig['signature'] = base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES));
        p10_assert_rejected(p10_client($key, static fn() => [200, json_encode($badSig)]), 'signature_invalid', 'invalid signature');

        $otherKey = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        p10_assert_rejected(p10_client($key, p10_central_transport(), ['public_key' => $otherKey]), 'signature_invalid', 'client configured with the wrong public key');

        $base = ['installation_id' => p10_identity(), 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['forms'],
                 'expires_at' => '2099-01-01 00:00:00', 'checked_at' => gmdate('c', time() + 60), 'next_check_after' => 86400];
        foreach ([
            'status missing'            => array_diff_key($base, ['status' => 1]),
            'entitlements not a list'   => ['entitlements' => ['forms' => true]] + $base,
            'entitlement not a string'  => ['entitlements' => [1]] + $base,
            'checked_at missing'        => array_diff_key($base, ['checked_at' => 1]),
            'expires_at not a string'   => ['expires_at' => 20990101] + $base,
        ] as $label => $payload) {
            p10_assert_rejected(p10_client($key, static fn() => [200, p10_signed_body($payload)]), 'malformed_payload', "signed but malformed: $label");
        }
    } finally { p10_teardown(); }
});

unit('Phase 10 sync #9/#10/#11: wrong installation, wrong product and wrong license are all rejected; the valid cache survives', function () {
    p10_ensure_identity();
    p10_clear();
    try {
        $c = p10_central_setup();
        p10_sync($c['license_key']);

        // A genuine Central Server answer for a DIFFERENT installation
        // (second license, second installation) replayed to this client.
        $other = p10_central(['issue', '{}']);
        $foreign = p10_central(['checkin'], json_encode([
            'product' => 'kohevo', 'license_key' => $other['license_key'], 'install_id' => str_repeat('8', 32), 'domain' => 'other.example',
        ]));
        assert_eq(200, $foreign['http_status'], 'precondition: the other installation really is licensed');
        $foreignBody = json_encode($foreign['body']);
        p10_assert_rejected(p10_client($c['license_key'], static fn() => [200, $foreignBody]), 'installation_mismatch', "another installation's genuinely signed state");

        foreach (['', 'abc', substr(p10_identity(), 0, 31) . 'A', ' ' . p10_identity(), str_repeat('g', 32)] as $badId) {
            p10_assert_rejected(p10_client($c['license_key'], static fn() => [200, p10_signed_body([
                'installation_id' => $badId, 'status' => 'active', 'entitlements' => [], 'checked_at' => gmdate('c', time() + 60),
            ])]), 'installation_mismatch', 'payload installation_id ' . var_export($badId, true));
        }

        p10_assert_rejected(p10_client($c['license_key'], p10_central_transport(), ['product' => 'other-product']), 'http_status', 'wrong product');
        p10_assert_rejected(p10_client($other['license_key'], p10_central_transport()), 'http_status', "another license's key (binding mismatch)");
        p10_assert_rejected(p10_client('KOHEVO-0000-0000-0000-0000-0000', p10_central_transport()), 'http_status', 'unknown license key');
        p10_assert_rejected(p10_client($c['license_key'], p10_central_transport(), ['domain' => 'moved.example']), 'http_status', 'domain mismatch');
    } finally { p10_teardown(); }
});

unit('Phase 10 sync #15: a stale / replayed older signed response never overwrites newer trusted state (older status, older expiry)', function () {
    p10_ensure_identity();
    p10_clear();
    try {
        $c = p10_central_setup();
        p10_sync($c['license_key']);

        p10_central(['suspend', $c['license_id']]);
        $log = [];
        assert_true(p10_client($c['license_key'], p10_central_transport($log))->checkIn());
        $oldSuspended = end($log);

        sleep(1); // the next signed checked_at is strictly later
        p10_central(['unsuspend', $c['license_id']]);
        p10_central(['extend', $c['license_id'], gmdate('Y-m-d H:i:s', time() + 500 * P10_DAY)]);
        p10_sync($c['license_key']);
        assert_eq('active', p10_trust()['data']['status']);

        p10_assert_rejected(p10_client($c['license_key'], static fn() => [200, $oldSuspended]), 'stale_response', 'replayed older suspended response');
        assert_eq('active', p10_trust()['data']['status'], 'newer status kept');
        assert_eq(200, p10_admin('admin/index.php')['status']);

        $older = p10_signed_body(['installation_id' => p10_identity(), 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['forms'],
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 2 * P10_DAY), 'checked_at' => gmdate('c', time() - 3600), 'next_check_after' => 86400]);
        p10_assert_rejected(p10_client($c['license_key'], static fn() => [200, $older]), 'stale_response', 'older expiry signed earlier');
    } finally { p10_teardown(); }
});

final class P10FailingStore implements LicenseCacheStoreInterface {
    public function load(): ?array { return null; }
    public function save(array $status): void { throw new \RuntimeException('disk full'); }
}

unit('Phase 10 sync #16: a cache write failure is reported as a failed check-in, never escapes, and never leaves a partial row', function () {
    p10_ensure_identity();
    p10_clear();
    try {
        $c = p10_central_setup();
        p10_sync($c['license_key']);
        $before = p10_row();

        $client = new RemoteLicenseClient([
            'server_url' => 'https://license-phase10.test', 'public_key' => license_test_public_key(), 'product' => 'kohevo',
            'license_key' => $c['license_key'], 'install_id' => p10_identity(), 'domain' => P10_DOMAIN,
        ], new P10FailingStore(), p10_central_transport());
        assert_eq(['ok' => false, 'reason' => 'rejected'], $client->checkInDetailed());
        assert_eq('cache_write_failed', $client->lastFailure());

        // A real database-level failure mid-write (strict mode rejects a
        // status longer than the column): the transaction rolls back.
        $tooLong = p10_signed_body(['installation_id' => p10_identity(), 'status' => str_repeat('x', 40), 'entitlements' => [],
            'checked_at' => gmdate('c', time() + 60)]);
        p10_assert_rejected(p10_client($c['license_key'], static fn() => [200, $tooLong]), 'cache_write_failed', 'database write failure');
        assert_eq($before, p10_row());
        assert_false(Database::get()->inTransaction(), 'no transaction left open');
    } finally { p10_teardown(); }
});

// ── D. Cache integrity (F-P9-01): read-time re-verification ─────────────

unit('Phase 10 integrity: direct DB edits of expires_at, status, entitlements, plan or the signed payload are detected at read time and fail closed', function () {
    p10_ensure_identity();
    p10_clear();
    try {
        $c = p10_central_setup(['modules' => ['forms']]);
        p10_sync($c['license_key']);
        p10_central(['set-expiry', $c['license_id'], gmdate('Y-m-d H:i:s', time() - 9 * P10_DAY)]);
        p10_sync($c['license_key']);
        assert_eq('expired', p10_trust()['data']['status'], 'precondition: signed expired');
        assert_eq(403, p10_admin('admin/index.php')['status'], 'precondition: genuinely locked past grace');
        $good = p10_row();

        foreach ([
            'expires_at pushed out'   => ['expires_at' => '2099-01-01 00:00:00'],
            'status flipped'          => ['status' => 'active'],
            'entitlements widened'    => ['entitlements' => json_encode(['forms', 'membership', 'booking'])],
            'plan changed'            => ['plan' => 'enterprise'],
            'remote_checked_at moved' => ['remote_checked_at' => gmdate('Y-m-d H:i:s', time() + 3600)],
        ] as $label => $edit) {
            Database::update('remote_license_cache', $edit, 'tenant_id = ?', [current_tenant_id()]);
            $trust = p10_trust();
            assert_false($trust['trusted'], "$label: untrusted");
            assert_eq('tampered', $trust['reason'], $label);
            assert_eq(403, p10_admin('admin/index.php')['status'], "$label: still locked");
            Database::update('remote_license_cache', array_intersect_key($good, $edit), 'tenant_id = ?', [current_tenant_id()]);
        }

        // Rewriting the signed payload AND every column consistently still
        // fails: the signature no longer verifies.
        $forged = json_decode($good['raw_payload'], true);
        $forged['status'] = 'active';
        $forged['expires_at'] = '2099-01-01 00:00:00';
        Database::update('remote_license_cache', [
            'raw_payload' => json_encode($forged, JSON_UNESCAPED_SLASHES), 'status' => 'active', 'expires_at' => '2099-01-01 00:00:00',
        ], 'tenant_id = ?', [current_tenant_id()]);
        assert_eq('signature_invalid', p10_trust()['reason']);
        assert_eq(403, p10_admin('admin/index.php')['status'], 'a consistently forged row is still locked');

        Database::update('remote_license_cache', ['raw_signature' => null, 'raw_payload' => null], 'tenant_id = ?', [current_tenant_id()]);
        assert_eq('unsigned', p10_trust()['reason'], 'stripping the envelope is not a way back to column trust');
    } finally { p10_teardown(); }
});

unit('Phase 10 integrity: a genuine signed payload copied from another installation is rejected at read time, however the columns are set', function () {
    p10_ensure_identity();
    p10_clear();
    try {
        $c = p10_central_setup();
        p10_sync($c['license_key']);
        $other = p10_central(['issue', '{}']);
        $foreign = p10_central(['checkin'], json_encode([
            'product' => 'kohevo', 'license_key' => $other['license_key'], 'install_id' => str_repeat('8', 32), 'domain' => 'other.example',
        ]));
        $payload = json_decode($foreign['body']['payload'], true);

        foreach ([str_repeat('8', 32), p10_identity()] as $columnId) {
            Database::update('remote_license_cache', [
                'raw_payload' => $foreign['body']['payload'], 'raw_signature' => $foreign['body']['signature'],
                'installation_id' => $columnId, 'status' => $payload['status'], 'plan' => $payload['plan'],
                'entitlements' => json_encode($payload['entitlements']), 'expires_at' => $payload['expires_at'],
            ], 'tenant_id = ?', [current_tenant_id()]);
            $trust = p10_trust();
            assert_false($trust['trusted'], "column installation_id=$columnId");
            assert_eq('installation_mismatch', $trust['reason']);
            assert_eq(403, p10_admin('admin/index.php')['status']);
        }
    } finally { p10_teardown(); }
});

unit('Phase 10 integrity: editing fetched_at forward cannot extend offline tolerance past the signed checked_at; the wrong public key trusts nothing', function () {
    p10_ensure_identity();
    p10_clear();
    try {
        license_test_seed_cache(current_tenant_id(), [
            'status' => 'active', 'plan' => 'pro', 'entitlements' => ['forms'], 'expires_at' => null,
            'fetched_at' => gmdate('Y-m-d H:i:s', time() - 8 * P10_DAY), 'installation_id' => p10_identity(),
        ]);
        assert_eq('stale', p10_window()['reason'], 'precondition: past offline tolerance');
        Database::update('remote_license_cache', ['fetched_at' => gmdate('Y-m-d H:i:s')], 'tenant_id = ?', [current_tenant_id()]);
        assert_eq('stale', p10_window()['reason'], 'a fresh-looking fetched_at does not outrank the signed checked_at');
        assert_eq(403, p10_admin('admin/index.php')['status']);

        license_test_seed_cache(current_tenant_id(), [
            'status' => 'active', 'plan' => 'pro', 'entitlements' => ['forms'], 'expires_at' => null,
            'fetched_at' => gmdate('Y-m-d H:i:s'), 'installation_id' => p10_identity(),
        ]);
        assert_true(p10_trust()['trusted']);
        $otherKey = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        $wrong = (new SlateLicenseCacheStore(current_tenant_id(), $otherKey))->readTrustState();
        assert_eq('signature_invalid', $wrong['reason']);
        $none = (new SlateLicenseCacheStore(current_tenant_id(), ''))->readTrustState();
        assert_false($none['trusted'], 'no configured public key → nothing is trusted');
    } finally { p10_teardown(); }
});

unit('Phase 10 integrity: save() itself refuses anything that is not a verified signed payload', function () {
    p10_ensure_identity();
    p10_clear();
    try {
        $store = new SlateLicenseCacheStore(current_tenant_id(), license_test_public_key());
        $payload = license_test_payload(['status' => 'active', 'installation_id' => p10_identity(), 'fetched_at' => gmdate('Y-m-d H:i:s')]);
        foreach ([
            'no envelope'      => ['status' => 'active', 'installation_id' => p10_identity()],
            'bad signature'    => ['raw_payload' => $payload, 'raw_signature' => base64_encode(str_repeat("\1", SODIUM_CRYPTO_SIGN_BYTES))],
            'malformed signed' => ['raw_payload' => '{"status":"active"}', 'raw_signature' => license_test_sign('{"status":"active"}')],
        ] as $label => $status) {
            $threw = false;
            try { $store->save($status); } catch (\InvalidArgumentException $e) { $threw = true; }
            assert_true($threw, "$label is refused");
            assert_null(p10_row(), "$label wrote nothing");
        }
    } finally { p10_teardown(); }
});

// ── E. Concurrency ───────────────────────────────────────────────────────

unit('Phase 10 sync #17: concurrent refreshes racing on the same row (including the very first insert) end with exactly one row holding the LATEST signed state', function () {
    p10_ensure_identity();
    for ($round = 0; $round < 2; $round++) {
        p10_clear();
        try {
            $base = time();
            $writers = [];
            $startAt = microtime(true) + 1.5;
            foreach ([3, 0, 5, 1, 4, 2] as $offset) {
                $payload = (string) json_encode([
                    'installation_id' => p10_identity(), 'status' => $offset === 5 ? 'suspended' : 'active', 'plan' => 'pro',
                    'entitlements' => ['forms'], 'expires_at' => gmdate('Y-m-d H:i:s', $base + ($offset + 10) * P10_DAY),
                    'checked_at' => gmdate('c', $base + $offset), 'next_check_after' => 86400,
                ], JSON_UNESCAPED_SLASHES);
                $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/phase10-cache-writer.php')
                    . ' ' . escapeshellarg((string) current_tenant_id()) . ' ' . escapeshellarg(license_test_public_key())
                    . ' ' . escapeshellarg(base64_encode($payload)) . ' ' . escapeshellarg(license_test_sign($payload))
                    . ' ' . escapeshellarg(sprintf('%.6f', $startAt));
                $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $writers[] = [$proc, $pipes];
            }
            $results = [];
            foreach ($writers as [$proc, $pipes]) {
                $results[] = trim((string) stream_get_contents($pipes[1]));
                fclose($pipes[1]); fclose($pipes[2]);
                proc_close($proc);
            }
            foreach ($results as $r) {
                assert_true(in_array($r, ['ok', 'stale'], true), "every writer either wins or is refused as stale, never errors (got $r)");
            }
            assert_eq(1, (int) Database::value('SELECT COUNT(*) FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]), 'exactly one cache row');
            $trust = p10_trust();
            assert_true($trust['trusted'], 'the surviving row is internally consistent and verifies');
            assert_eq(gmdate('Y-m-d H:i:s', $base + 5), $trust['data']['remote_checked_at'], 'the latest signed state wins regardless of arrival order');
            assert_eq('suspended', $trust['data']['status']);
        } finally {
            p10_clear();
        }
    }
});

// ── F. Recovery reachable while locked; no request-derived state ──────────

unit('Phase 10: the licensing recovery page and the check-in CLI stay reachable while locked, and the CLI never overwrites a trusted cache on failure', function () {
    p10_ensure_identity();
    p10_clear();
    try {
        $c = p10_central_setup();
        p10_sync($c['license_key']);
        p10_central(['revoke', $c['license_id']]);
        p10_sync($c['license_key']);
        assert_eq(403, p10_admin('admin/index.php')['status']);
        assert_eq(200, p10_admin('admin/license.php')['status'], 'the license recovery screen is whitelisted');

        $before = p10_row();
        // bin/license-check.php against an unreachable server: a failed
        // check-in, the revoked state stays exactly as it was.
        $cmd = 'LICENSE_SERVER_URL=' . escapeshellarg('http://127.0.0.1:9') . ' ' . license_test_env_prefix()
            . 'LICENSE_PRODUCT=kohevo LICENSE_KEY=' . escapeshellarg($c['license_key']) . ' '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/license-check.php') . ' 2>&1';
        $out = (string) shell_exec($cmd);
        assert_true(str_contains($out, 'Check-in failed (network)'), 'the CLI reports the failure category: ' . $out);
        assert_false(str_contains($out, $c['license_key']), 'the CLI never prints the raw license key');
        assert_eq($before, p10_row());
    } finally { p10_teardown(); }
});

// ── G. Migration 0026 ────────────────────────────────────────────────────

function p10_fresh_pdo(string $dbName): \PDO {
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $host = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '');
    $root = new \PDO($host . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `$dbName`");
    $root->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4");
    return new \PDO($host . ";dbname=$dbName;charset=" . DB_CHARSET, DB_USER, DB_PASS, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
}

function p10_drop_db(string $dbName): void {
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $root = new \PDO('mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET, DB_USER, DB_PASS);
    $root->exec("DROP DATABASE IF EXISTS `$dbName`");
}

unit('Phase 10 migration 0026: fresh install, replay, and an upgraded Phase 9 row that keeps its data and becomes untrusted-until-refresh', function () {
    $dbName = 'slate_p10_mig_' . slate_test_ns();
    try {
        $before0026 = ['0001_core_init', '0002_identity_core', '0011_login_attempts', '0014_tenant_profiles',
                       '0023_installation_identity', '0022_remote_license_cache', '0024_remote_license_metadata',
                       '0025_remote_license_cache_installation_id'];
        $pdo = p10_fresh_pdo($dbName);
        $runner = new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations');
        $runner->migrate($before0026);

        $pdo->prepare('INSERT INTO remote_license_cache (tenant_id, status, plan, entitlements, expires_at, fetched_at, installation_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([1, 'active', 'pro', '["forms"]', '2030-01-01 00:00:00', gmdate('Y-m-d H:i:s'), p10_identity()]);

        $runner->migrate(['0026_remote_license_cache_signed_payload']);
        $cols = array_column($pdo->query('SHOW COLUMNS FROM remote_license_cache')->fetchAll(), 'Null', 'Field');
        foreach (['raw_payload', 'raw_signature', 'verified_at'] as $col) {
            assert_true(isset($cols[$col]), "$col added");
            assert_eq('YES', $cols[$col], "$col is nullable (additive)");
        }
        $row = $pdo->query('SELECT * FROM remote_license_cache WHERE tenant_id = 1')->fetch();
        assert_eq('active', $row['status'], 'existing data preserved');
        assert_eq(p10_identity(), $row['installation_id'], 'installation identity preserved');
        assert_eq('["forms"]', str_replace(' ', '', (string) $row['entitlements']), 'entitlements preserved');
        assert_null($row['raw_payload']);

        $runner->migrate(['0026_remote_license_cache_signed_payload']); // replay is a no-op
        assert_eq(1, (int) $pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = '0026_remote_license_cache_signed_payload'")->fetchColumn(), 'recorded once');
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM remote_license_cache')->fetchColumn());

        $dup = false;
        try {
            $pdo->prepare('INSERT INTO remote_license_cache (tenant_id, status, fetched_at) VALUES (1, ?, ?)')->execute(['active', gmdate('Y-m-d H:i:s')]);
        } catch (\PDOException $e) { $dup = true; }
        assert_true($dup, 'the one-row-per-tenant unique constraint still holds');
    } finally {
        p10_drop_db($dbName);
    }
});
