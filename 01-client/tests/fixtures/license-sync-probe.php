<?php
/**
 * Runs slate_license_sync_run() once, in its own process, against this
 * checkout's test database. The check-in transport is the REAL Central
 * Server logic (02-licensing/tests/fixtures/phase10-central.php, one process
 * per call) — or, when no central fixture path is given, a dead network.
 *
 *   php license-sync-probe.php <auto|force> [<path to phase10-central.php>]
 *
 * Prints one JSON line: the sync result plus the trusted cache's expiry.
 * CLI only, test database only. License env comes from the caller's
 * environment (LICENSE_SERVER_URL, LICENSE_SERVER_PUBLIC_KEY, LICENSE_PRODUCT,
 * LICENSE_KEY), exactly as a real install reads it from .env.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../guard.php';
slate_require_test_database();

$mode    = (string) ($argv[1] ?? 'auto');
$central = (string) ($argv[2] ?? '');

$transport = static function (string $url, string $body) use ($central): ?array {
    if ($central === '') return null; // network down
    $cmd  = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($central) . ' checkin';
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $body);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $res = json_decode($out, true);
    if (!is_array($res)) return null;
    return [(int) $res['http_status'], (string) json_encode($res['body'], JSON_UNESCAPED_SLASHES)];
};

$result = slate_license_sync_run($mode === 'force', $transport);
$trust  = (new \Slate\Services\Licensing\SlateLicenseCacheStore((int) TENANT_ID))->readTrustState();
echo json_encode($result + [
    'trusted'    => $trust['trusted'],
    'expires_at' => $trust['data']['expires_at'] ?? null,
    'plan'       => $trust['data']['plan'] ?? null,
]), "\n";
