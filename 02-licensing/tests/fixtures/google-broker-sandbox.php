<?php
/**
 * The real GoogleBroker behind Central's real addresses, with one made-up licence and handoffs in a temp file, for trying a
 * Site Insights sign-in end to end without a Central database. Sandbox only.
 *
 *   GOOGLE_FAKE=http://127.0.0.1:9377 SANDBOX_URL=http://127.0.0.1:9378 SITE_DOMAIN=localhost:8377 \
 *     php -S 127.0.0.1:9378 tests/fixtures/google-broker-sandbox.php
 *
 * The licence key is `sandbox-key` unless SANDBOX_KEY says otherwise; any install id of the right shape passes, on SITE_DOMAIN only.
 */

declare(strict_types=1);

require __DIR__ . '/../../plugins/licensing/GoogleBroker.php';

$fake = rtrim((string) getenv('GOOGLE_FAKE'), '/');
$file = sys_get_temp_dir() . '/google-broker-sandbox.json';
$read = static fn(): array => json_decode((string) @file_get_contents($file), true) ?: [];
$broker = new GoogleBroker(
    'central.apps.googleusercontent.com',
    'central-secret',
    rtrim((string) getenv('SANDBOX_URL'), '/') . '/licensing/google/callback',
    static fn(string $context, string $value): string => hash_hmac('sha256', $context . '|' . $value, 'sandbox-signing-key'),
    static function (string $url, array $fields): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true]);
        $body = curl_exec($ch);
        return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => (string) $body];
    },
    static function (string $hash, string $installation, array $tokens, int $expires) use ($read, $file): void {
        file_put_contents($file, json_encode([$hash => [$installation, $tokens, $expires]] + $read()));
    },
    static function (string $hash, string $installation, int $now) use ($read, $file): ?array {
        $all = $read();
        $row = $all[$hash] ?? null;
        if ($row === null || $row[0] !== $installation || $row[2] < $now) {
            return null;
        }
        unset($all[$hash]);
        file_put_contents($file, json_encode($all));
        return $row[1];
    },
    $fake . '/accounts.google.com/o/oauth2/v2/auth',
    $fake . '/oauth2.googleapis.com/token',
);

$action = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
if ($action === 'callback') {
    $result = $broker->callback($_GET, time());
    http_response_code($result['status']);
    if (isset($result['redirect'])) {
        header('Location: ' . $result['redirect']);
    }
    echo $result['message'] ?? '';
    exit;
}
$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
$domain = (string) ($input['domain'] ?? '');
$licensed = ($input['license_key'] ?? '') === (getenv('SANDBOX_KEY') ?: 'sandbox-key') && $domain === getenv('SITE_DOMAIN') && preg_match('/^[a-f0-9]{32}$/', (string) ($input['install_id'] ?? '')) === 1;
$site = ['installation_id' => (string) ($input['install_id'] ?? ''), 'domain' => $domain];
$result = !$licensed ? ['status' => 403, 'body' => ['error' => 'invalid_request']] : match ($action) {
    'start' => $broker->start($site, (string) ($input['return_url'] ?? ''), (string) ($input['site_state'] ?? ''), time()),
    'claim' => $broker->claim($site, (string) ($input['handoff'] ?? ''), time()),
    'refresh' => $broker->refresh($site, (string) ($input['refresh_token'] ?? '')),
    default => ['status' => 404, 'body' => ['error' => 'not_found']],
};
http_response_code($result['status']);
header('Content-Type: application/json');
echo json_encode($result['body']);
