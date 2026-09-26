<?php
/**
 * Phase 10 concurrency probe: one SlateLicenseCacheStore::save() in its own
 * process and its own database connection, released at a shared start
 * instant so several writers genuinely race on the same cache row.
 *
 * Usage: php tests/fixtures/phase10-cache-writer.php <tenantId> <publicKeyB64>
 *            <rawPayloadB64> <rawSignature> <startAtMicrotime>
 * Prints: ok | stale | error:<ExceptionClass>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('SLATE_TESTING', true);
require __DIR__ . '/../../config.php';
require __DIR__ . '/../guard.php';
slate_require_test_database();

[$tenantId, $publicKey, $payloadB64, $signature, $startAt] = array_slice($argv, 1, 5) + ['', '', '', '', '0'];

$store = new \Slate\Services\Licensing\SlateLicenseCacheStore((int) $tenantId, (string) $publicKey);
\Database::get(); // connect before the barrier so every writer starts equal

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $store->save([
        'raw_payload' => (string) base64_decode((string) $payloadB64, true),
        'raw_signature' => (string) $signature,
        'fetched_at' => gmdate('c'),
    ]);
    echo 'ok';
} catch (\LicenseCacheStaleException $e) {
    echo 'stale';
} catch (\Throwable $e) {
    echo 'error:' . get_class($e);
}
