<?php
/**
 * Phase 10 licensing test helpers: the real Central Server fixture, the
 * client cache and installation identity. Shared by
 * Phase10SynchronizationTest and Phase10ZAutoRefreshTest, which used to
 * borrow them by loading after it alphabetically (a fatal "undefined
 * function" in any other order). Load with require_once.
 */

declare(strict_types=1);

require_once __DIR__ . '/license_signing.php';
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
