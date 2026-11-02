<?php
/**
 * A stand-in for an order channel's status endpoint, for tests. Run with
 * `php -S 127.0.0.1:<port> tests/fixtures/fake-channel.php` and
 * FAKE_CHANNEL_STATE=<dir>: every request is appended to <dir>/requests.log
 * as one JSON line (method, path, signature and authorization headers, raw
 * body). Answers 200 {"ok":true}, or the status in ?status= (to test a
 * failing channel). POST …/oauth/token stands in for an aggregator's token
 * endpoint (Deliverect's client-credentials call): it answers a test token
 * that expires in an hour, or the status in <dir>/token-status if present.
 */
$dir = (string) getenv('FAKE_CHANNEL_STATE');
$body = (string) file_get_contents('php://input');
if ($dir !== '') {
    file_put_contents($dir . '/requests.log', json_encode([
        'method'    => $_SERVER['REQUEST_METHOD'] ?? '',
        'path'      => parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
        'signature' => (string)($_SERVER['HTTP_X_CHANNEL_SIGNATURE'] ?? ''),
        'authorization' => (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
        'body'      => $body,
    ]) . "\n", FILE_APPEND | LOCK_EX);
}
header('Content-Type: application/json');
if (str_ends_with((string) parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/oauth/token')) {
    $forced = $dir !== '' && is_file($dir . '/token-status') ? (int) file_get_contents($dir . '/token-status') : 0;
    if ($forced) { http_response_code($forced); echo json_encode(['error' => 'access_denied']); exit; }
    echo json_encode(['access_token' => 'tok_test_' . bin2hex(random_bytes(6)), 'token_type' => 'Bearer', 'expires_at' => time() + 3600]);
    exit;
}
$status = (int)($_GET['status'] ?? 200);
http_response_code($status >= 100 && $status < 600 ? $status : 200);
echo json_encode(['ok' => $status < 400]);
