<?php
/**
 * A stand-in for DoorDash Drive API v2, for tests. Run with
 * `php -S 127.0.0.1:<port> tests/fixtures/fake-drive.php` and
 * FAKE_DRIVE_STATE=<dir>, FAKE_DRIVE_SECRET=<base64url signing secret>,
 * FAKE_DRIVE_DEV=<developer id>, FAKE_DRIVE_KEY=<key id>.
 *
 * Every API call must carry a valid Drive JWT (HS256, dd-ver DD-JWT-V1,
 * aud doordash, iss/kid matching, exp at most 30 minutes after iat, signed
 * with the decoded secret), else 401. Each request is appended to
 * <dir>/requests.log with its decoded claims. Deliveries live in
 * <dir>/d-<id>.json.
 *
 *   POST /drive/v2/quotes                       quote (fee 975 USD); a drop-off address containing
 *                                               NOCOVER answers 422 like an out-of-area address
 *   POST /drive/v2/quotes/{id}/accept           quote → created, with tracking_url and support_reference
 *   GET  /drive/v2/deliveries/{id}              current state
 *   PUT  /drive/v2/deliveries/{id}/cancel       only while created (409 once a Dasher is assigned)
 *   POST /_sim/{id}?status=…                    the Delivery Simulator: set the status (no auth)
 */
$dir = (string) getenv('FAKE_DRIVE_STATE');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$raw = (string) file_get_contents('php://input');
header('Content-Type: application/json');

$b64d = fn(string $s) => base64_decode(strtr($s, '-_', '+/'), true);
$answer = function (int $code, array $body) { http_response_code($code); echo json_encode($body); exit; };
$file = fn(string $id) => $dir . '/d-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $id) . '.json';
$load = fn(string $id) => is_file($file($id)) ? json_decode((string) file_get_contents($file($id)), true) : null;
$save = function (array $d) use ($file) { file_put_contents($file($d['external_delivery_id']), json_encode($d)); };

if (preg_match('#^/_sim/([^/]+)$#', $path, $m)) {
    $d = $load(urldecode($m[1]));
    if (!$d) $answer(404, ['code' => 'not_found']);
    $d['delivery_status'] = (string)($_GET['status'] ?? $d['delivery_status']);
    if (in_array($d['delivery_status'], ['confirmed', 'enroute_to_pickup', 'picked_up', 'delivered'], true)) $d['dasher_name'] = 'Sam T.';
    $save($d);
    $answer(200, $d);
}

// The JWT.
$claims = null; $why = 'missing';
if (preg_match('/^Bearer\s+([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $t)) {
    $head = json_decode((string) $b64d($t[1]), true); $claims = json_decode((string) $b64d($t[2]), true);
    $sig = hash_hmac('sha256', $t[1] . '.' . $t[2], (string) $b64d((string) getenv('FAKE_DRIVE_SECRET')), true);
    $now = time();
    $why = match (true) {
        ($head['alg'] ?? '') !== 'HS256' || ($head['dd-ver'] ?? '') !== 'DD-JWT-V1' => 'header',
        !hash_equals($sig, (string) $b64d($t[3])) => 'signature',
        ($claims['aud'] ?? '') !== 'doordash' || ($claims['iss'] ?? '') !== getenv('FAKE_DRIVE_DEV') || ($claims['kid'] ?? '') !== getenv('FAKE_DRIVE_KEY') => 'claims',
        !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 5 || !is_int($claims['exp'] ?? null) || $claims['exp'] - $claims['iat'] > 1800 || $claims['exp'] < $now => 'time',
        default => '',
    };
}
if ($dir !== '') file_put_contents($dir . '/requests.log', json_encode(['method' => $method, 'path' => $path, 'jwt' => $why === '' ? 'valid' : $why,
    'claims' => $claims, 'body' => $raw]) . "\n", FILE_APPEND | LOCK_EX);
if ($why !== '') $answer(401, ['code' => 'authentication_error', 'message' => 'JWT ' . $why]);

if ($method === 'POST' && $path === '/drive/v2/quotes') {
    $b = json_decode($raw, true) ?: [];
    if (str_contains((string)($b['dropoff_address'] ?? ''), 'NOCOVER')) {
        $answer(422, ['code' => 'validation_error', 'message' => 'Validation Failed',
            'field_errors' => [['field' => 'dropoff_address', 'error' => 'Delivery address is outside of the delivery area']]]);
    }
    foreach (['external_delivery_id', 'pickup_address', 'pickup_phone_number', 'dropoff_address', 'dropoff_phone_number'] as $f) {
        if (empty($b[$f])) $answer(400, ['code' => 'validation_error', 'message' => 'Validation Failed', 'field_errors' => [['field' => $f, 'error' => 'required']]]);
    }
    if ($load($b['external_delivery_id'])) $answer(409, ['code' => 'duplicate_delivery_id', 'message' => 'Delivery with this external_delivery_id already exists']);
    $d = $b + ['delivery_status' => 'quote', 'fee' => 975, 'currency' => 'USD',
        'pickup_time_estimated' => gmdate('Y-m-d\TH:i:s\Z', time() + 900), 'dropoff_time_estimated' => gmdate('Y-m-d\TH:i:s\Z', time() + 2400)];
    $save($d);
    $answer(200, $d);
}
if ($method === 'POST' && preg_match('#^/drive/v2/quotes/([^/]+)/accept$#', $path, $m)) {
    $d = $load(urldecode($m[1]));
    if (!$d) $answer(404, ['code' => 'not_found', 'message' => 'Quote not found']);
    if ($d['delivery_status'] !== 'quote') $answer(409, ['code' => 'quote_already_accepted', 'message' => 'Quote was already accepted']);
    $d['delivery_status'] = 'created';
    $d['tracking_url'] = 'https://doordash.example.test/track/' . rawurlencode($d['external_delivery_id']);
    $d['support_reference'] = (string) random_int(10000, 99999);
    $save($d);
    $answer(200, $d);
}
if ($method === 'GET' && preg_match('#^/drive/v2/deliveries/([^/]+)$#', $path, $m)) {
    $d = $load(urldecode($m[1]));
    if (!$d) $answer(404, ['code' => 'not_found', 'message' => 'Delivery not found']);
    $answer(200, $d);
}
if ($method === 'PUT' && preg_match('#^/drive/v2/deliveries/([^/]+)/cancel$#', $path, $m)) {
    $d = $load(urldecode($m[1]));
    if (!$d) $answer(404, ['code' => 'not_found', 'message' => 'Delivery not found']);
    if ($d['delivery_status'] !== 'created') $answer(409, ['code' => 'cannot_be_cancelled', 'message' => 'Delivery cannot be cancelled after a Dasher is assigned']);
    $d['delivery_status'] = 'cancelled'; $d['cancellation_reason'] = 'cancelled_by_creator';
    $save($d);
    $answer(200, $d);
}
$answer(404, ['code' => 'not_found', 'message' => 'No route']);
