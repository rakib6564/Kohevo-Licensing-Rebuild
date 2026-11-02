<?php
/**
 * A stand-in for Uber Direct (DaaS API v1 and its OAuth token endpoint), for
 * tests. Run with `php -S 127.0.0.1:<port> tests/fixtures/fake-uber.php` and
 * FAKE_UBER_STATE=<dir>, FAKE_UBER_CUSTOMER=<customer id>,
 * FAKE_UBER_CLIENT=<client id>, FAKE_UBER_SECRET=<client secret>.
 *
 * Tokens are issued only for those client credentials with
 * grant_type=client_credentials and scope eats.deliveries; every API call
 * must carry one (else 401) and name the right customer (else 403). Each
 * request is appended to <dir>/requests.log (the Authorization header
 * reduced to valid/invalid). Quotes live in <dir>/q-<id>.json, deliveries
 * in <dir>/d-<id>.json.
 *
 *   POST /oauth/v2/token                                 form: client_id, client_secret, grant_type, scope
 *   POST /v1/customers/{cid}/delivery_quotes             fee 899 usd; a drop-off containing NOCOVER answers
 *                                                        400 address_undeliverable
 *   POST /v1/customers/{cid}/deliveries                  needs a known quote_id → pending delivery del_…
 *   GET  /v1/customers/{cid}/deliveries/{id}             current state
 *   POST /v1/customers/{cid}/deliveries/{id}/cancel      only while pending without a courier
 *   POST /_sim/{id}?status=…                             set the status (no auth); a courier joins from pickup on
 *   POST /_revoke                                        forget every issued token (no auth)
 */
$dir = (string) getenv('FAKE_UBER_STATE');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$raw = (string) file_get_contents('php://input');
header('Content-Type: application/json');

$answer = function (int $code, array $body) { http_response_code($code); echo json_encode($body); exit; };
$err = fn(int $code, string $c, string $m, array $meta = []) => $answer($code, ['kind' => 'error', 'code' => $c, 'message' => $m] + ($meta ? ['metadata' => $meta] : []));
$safe = fn(string $id) => preg_replace('/[^A-Za-z0-9_-]/', '_', $id);
$load = fn(string $f) => is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
$put = fn(string $f, array $d) => file_put_contents($f, json_encode($d));
$tokens = $dir . '/tokens.json';
$iso = fn(int $t) => gmdate('Y-m-d\TH:i:s\Z', $t);

if ($method === 'POST' && $path === '/_revoke') { @unlink($tokens); $answer(200, ['ok' => true]); }
if (preg_match('#^/_sim/([^/]+)$#', $path, $m)) {
    $d = $load($dir . '/d-' . $safe(urldecode($m[1])) . '.json');
    if (!$d) $answer(404, ['code' => 'not_found']);
    $d['status'] = (string)($_GET['status'] ?? $d['status']);
    if (in_array($d['status'], ['pickup', 'pickup_complete', 'dropoff', 'delivered'], true) || isset($_GET['courier'])) $d['courier'] = ['name' => 'Robin K.', 'vehicle_type' => 'car'];
    $d['updated'] = $iso(time());
    $put($dir . '/d-' . $safe($d['id']) . '.json', $d);
    $answer(200, $d);
}

if ($method === 'POST' && $path === '/oauth/v2/token') {
    parse_str($raw, $f);
    $ok = ($f['client_id'] ?? '') === getenv('FAKE_UBER_CLIENT') && ($f['client_secret'] ?? '') === getenv('FAKE_UBER_SECRET');
    file_put_contents($dir . '/requests.log', json_encode(['method' => $method, 'path' => $path, 'grant' => $f['grant_type'] ?? '', 'scope' => $f['scope'] ?? '', 'creds' => $ok]) . "\n", FILE_APPEND | LOCK_EX);
    if (!$ok) $answer(401, ['error' => 'invalid_client']);
    if (($f['grant_type'] ?? '') !== 'client_credentials' || ($f['scope'] ?? '') !== 'eats.deliveries') $answer(400, ['error' => 'invalid_scope']);
    $t = 'tok_' . bin2hex(random_bytes(12));
    $all = $load($tokens) ?: [];
    $all[] = $t;
    $put($tokens, $all);
    $answer(200, ['access_token' => $t, 'token_type' => 'Bearer', 'expires_in' => 2592000, 'scope' => 'eats.deliveries']);
}

$bearer = preg_match('/^Bearer\s+(\S+)$/', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $t) ? $t[1] : '';
$auth = $bearer !== '' && in_array($bearer, $load($tokens) ?: [], true);
file_put_contents($dir . '/requests.log', json_encode(['method' => $method, 'path' => $path, 'auth' => $auth ? 'valid' : 'invalid', 'body' => $raw]) . "\n", FILE_APPEND | LOCK_EX);
if (!$auth) $err(401, 'unauthorized', 'The supplied token is invalid or expired.');
if (!preg_match('#^/v1/customers/([^/]+)(/.*)$#', $path, $m)) $err(404, 'not_found', 'No route');
if (urldecode($m[1]) !== getenv('FAKE_UBER_CUSTOMER')) $err(403, 'customer_not_found', 'Customer does not exist or is not linked to this application.');
$sub = $m[2];
$b = json_decode($raw, true) ?: [];

if ($method === 'POST' && $sub === '/delivery_quotes') {
    foreach (['pickup_address', 'dropoff_address'] as $k) {
        $a = json_decode((string)($b[$k] ?? ''), true);
        if (!is_array($a) || empty($a['street_address'])) $err(400, 'invalid_params', 'The parameters of your request were invalid.', [$k => 'must be a JSON address with street_address']);
    }
    if (str_contains((string)$b['dropoff_address'], 'NOCOVER')) $err(400, 'address_undeliverable', 'The specified location is not in a deliverable area.');
    $q = ['kind' => 'delivery_quote', 'id' => 'dqt_' . bin2hex(random_bytes(8)), 'created' => $iso(time()), 'expires' => $iso(time() + 900),
          'fee' => 899, 'currency' => 'usd', 'currency_type' => 'USD', 'dropoff_eta' => $iso(time() + 2700), 'duration' => 45, 'pickup_duration' => 12,
          '_request' => $b];
    $put($dir . '/q-' . $safe($q['id']) . '.json', $q);
    $answer(200, array_diff_key($q, ['_request' => 1]));
}
if ($method === 'POST' && $sub === '/deliveries') {
    $q = $load($dir . '/q-' . $safe((string)($b['quote_id'] ?? '')) . '.json');
    if (!$q) $err(400, 'invalid_params', 'The parameters of your request were invalid.', ['quote_id' => 'unknown or expired quote']);
    foreach (['pickup_name', 'pickup_address', 'pickup_phone_number', 'dropoff_name', 'dropoff_address', 'dropoff_phone_number', 'manifest_items'] as $k) {
        if (empty($b[$k])) $err(400, 'invalid_params', 'The parameters of your request were invalid.', [$k => 'required']);
    }
    $id = 'del_' . bin2hex(random_bytes(8));
    $d = ['kind' => 'delivery', 'id' => $id, 'quote_id' => $q['id'], 'status' => 'pending', 'external_id' => (string)($b['external_id'] ?? ''),
          'fee' => $q['fee'], 'currency' => 'usd', 'tracking_url' => 'https://delivery.uber.example.test/orders/' . $id,
          'pickup_eta' => $iso(time() + 720), 'dropoff_eta' => $q['dropoff_eta'], 'created' => $iso(time()), 'updated' => $iso(time()),
          'manifest_items' => $b['manifest_items'], 'courier' => null];
    $put($dir . '/d-' . $id . '.json', $d);
    $answer(200, $d);
}
if ($method === 'GET' && preg_match('#^/deliveries/([^/]+)$#', $sub, $mm)) {
    $d = $load($dir . '/d-' . $safe(urldecode($mm[1])) . '.json');
    if (!$d) $err(404, 'delivery_not_found', 'The requested delivery does not exist.');
    $answer(200, $d);
}
if ($method === 'POST' && preg_match('#^/deliveries/([^/]+)/cancel$#', $sub, $mm)) {
    $d = $load($dir . '/d-' . $safe(urldecode($mm[1])) . '.json');
    if (!$d) $err(404, 'delivery_not_found', 'The requested delivery does not exist.');
    if ($d['status'] !== 'pending' || !empty($d['courier'])) $err(400, 'noncancelable_delivery', 'Delivery cannot be canceled.');
    $d['status'] = 'canceled';
    $put($dir . '/d-' . $safe($d['id']) . '.json', $d);
    $answer(200, $d);
}
$err(404, 'not_found', 'No route');
