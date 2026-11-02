<?php
/**
 * A stand-in for an order channel's status endpoint, for tests. Run with
 * `php -S 127.0.0.1:<port> tests/fixtures/fake-channel.php` and
 * FAKE_CHANNEL_STATE=<dir>: every request is appended to <dir>/requests.log
 * as one JSON line (method, path, signature header, raw body). Answers 200
 * {"ok":true}, or the status in ?status= (to test a failing channel).
 */
$dir = (string) getenv('FAKE_CHANNEL_STATE');
$body = (string) file_get_contents('php://input');
if ($dir !== '') {
    file_put_contents($dir . '/requests.log', json_encode([
        'method'    => $_SERVER['REQUEST_METHOD'] ?? '',
        'path'      => parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
        'signature' => (string)($_SERVER['HTTP_X_CHANNEL_SIGNATURE'] ?? ''),
        'body'      => $body,
    ]) . "\n", FILE_APPEND | LOCK_EX);
}
$status = (int)($_GET['status'] ?? 200);
http_response_code($status >= 100 && $status < 600 ? $status : 200);
header('Content-Type: application/json');
echo json_encode(['ok' => $status < 400]);
