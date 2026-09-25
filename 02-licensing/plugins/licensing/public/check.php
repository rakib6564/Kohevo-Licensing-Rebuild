<?php
/**
 * Licensing plugin — public check-in endpoint (POST /licensing/check).
 *
 * A thin HTTP shim only. All logic lives in LicensingAPI::handleCheckIn()
 * so it stays testable without a live server. Everything here is wrapped
 * so a bug can never surface as an HTML stack trace to a public caller —
 * a public JSON endpoint's error responses are load-bearing (a client
 * install parses them), not a debugging aid.
 */

if (!defined('SLATE_ROOT')) {
    require_once dirname(__DIR__, 3) . '/config.php';
}
slate_public_entry('licensing');

header('Content-Type: application/json; charset=utf-8');

try {
    $raw   = (string) file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request']);
        exit;
    }

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $result = LicensingAPI::handleCheckIn($input, $ip);

    http_response_code((int) $result['http_status']);
    echo json_encode($result['body']);
} catch (\Throwable $e) {
    slate_log('Licensing /licensing/check fatal: ' . $e->getMessage(), 'error');
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
}
