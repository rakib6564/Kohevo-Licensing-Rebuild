<?php
/**
 * A stand-in for the Stripe calls the Restaurant money tests make (developer tool; never shipped or deployed).
 *
 *   FAKE_STRIPE_STATE=/tmp/dir php -S 127.0.0.1:12112 tests/fixtures/fake-stripe.php
 *
 * StripePaymentAPI::apiBase() honours STRIPE_API_BASE only for a plain-http loopback address, so a real install can
 * never be pointed here. State lives in FAKE_STRIPE_STATE: objects.json (PaymentIntents, Checkout sessions, refunds,
 * readers), requests.log (one JSON line per request, with its Idempotency-Key and whether it was a replay) and
 * idempotency.json. Like Stripe, a POST that repeats an Idempotency-Key gets the first answer back and changes nothing.
 *
 * Test-only behaviour:
 *   POST /v1/test_helpers/terminal/readers/{id}/present_payment_method   card_present[number]=4000000000000002 declines
 *   POST /__test/fail_next_refund                                        the next new refund answers 402
 */

$dir = rtrim((string) getenv('FAKE_STRIPE_STATE'), '/');
if ($dir === '' || !is_dir($dir)) { http_response_code(500); echo '{"error":{"message":"FAKE_STRIPE_STATE not set"}}'; return true; }

$method = $_SERVER['REQUEST_METHOD'];
$path   = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$params = $method === 'POST' ? $_POST : $_GET;
$idem   = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');

$fp = fopen($dir . '/lock', 'c');
flock($fp, LOCK_EX);

$load = function (string $name, $default) use ($dir) {
    $f = $dir . '/' . $name;
    return is_file($f) ? json_decode((string) file_get_contents($f), true) : $default;
};
$save = function (string $name, $data) use ($dir): void {
    file_put_contents($dir . '/' . $name, json_encode($data, JSON_PRETTY_PRINT));
};
$log = function (bool $replay) use ($dir, $method, $path, $params, $idem): void {
    file_put_contents($dir . '/requests.log', json_encode([
        'method' => $method, 'path' => $path, 'idempotency_key' => $idem, 'replay' => $replay, 'params' => $params,
    ]) . "\n", FILE_APPEND);
};
$send = function (int $status, array $body) use ($fp): bool {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    flock($fp, LOCK_UN);
    return true;
};

$keys = $load('idempotency.json', []);
if ($method === 'POST' && $idem !== '' && isset($keys[$idem])) {
    $log(true);
    return $send($keys[$idem]['status'], $keys[$idem]['body']);
}
$log(false);

// Ids carry a per-state run token: rows the code under test keys on a PaymentIntent id (stripepayment_charges)
// must never meet an id left over from an earlier run.
$db  = $load('objects.json', ['run' => bin2hex(random_bytes(3)), 'seq' => 0, 'pi' => [], 'cs' => [], 're' => [],
                              'readers' => [], 'fail_next_refund' => false]);
$id  = function (string $prefix) use (&$db): string { return $prefix . '_fake' . $db['run'] . '_' . (++$db['seq']); };
$err = fn(string $msg) => ['error' => ['message' => $msg]];
$meta = function (array $p): array {
    $m = [];
    foreach (($p['metadata'] ?? []) as $k => $v) $m[$k] = (string) $v;
    return $m;
};

$status = 200;
$body   = null;
$m      = [];

if ($method === 'POST' && $path === '/__test/fail_next_refund') {
    $db['fail_next_refund'] = true;
    $body = ['ok' => true];
} elseif ($method === 'POST' && $path === '/v1/payment_intents') {
    $pid = $id('pi');
    $body = $db['pi'][$pid] = [
        'id' => $pid, 'object' => 'payment_intent', 'amount' => (int) ($params['amount'] ?? 0),
        'amount_received' => 0, 'currency' => (string) ($params['currency'] ?? 'usd'),
        'capture_method' => (string) ($params['capture_method'] ?? 'automatic'),
        'payment_method_types' => array_values((array) ($params['payment_method_types'] ?? ['card'])),
        'status' => 'requires_payment_method', 'client_secret' => $pid . '_secret_fake',
        'metadata' => $meta($params), 'latest_charge' => null, 'last_payment_error' => null, 'refunded' => 0,
    ];
} elseif (preg_match('#^/v1/payment_intents/([A-Za-z0-9_]+)$#', $path, $m) && $method === 'GET') {
    [$status, $body] = isset($db['pi'][$m[1]]) ? [200, $db['pi'][$m[1]]] : [404, $err('No such payment_intent')];
} elseif (preg_match('#^/v1/payment_intents/([A-Za-z0-9_]+)/capture$#', $path, $m) && $method === 'POST') {
    $pi = $db['pi'][$m[1]] ?? null;
    if (!$pi) { [$status, $body] = [404, $err('No such payment_intent')]; }
    elseif ($pi['status'] !== 'requires_capture') { [$status, $body] = [400, $err('This PaymentIntent could not be captured because it has a status of ' . $pi['status'] . '.')]; }
    else {
        $pi['status'] = 'succeeded';
        $pi['amount_received'] = $pi['amount'];
        $pi['latest_charge'] = $id('ch');
        $body = $db['pi'][$m[1]] = $pi;
    }
} elseif (preg_match('#^/v1/payment_intents/([A-Za-z0-9_]+)/cancel$#', $path, $m) && $method === 'POST') {
    $pi = $db['pi'][$m[1]] ?? null;
    if (!$pi) { [$status, $body] = [404, $err('No such payment_intent')]; }
    elseif ($pi['status'] === 'succeeded') { [$status, $body] = [400, $err('You cannot cancel this PaymentIntent because it has a status of succeeded.')]; }
    else { $pi['status'] = 'canceled'; $body = $db['pi'][$m[1]] = $pi; }
} elseif (preg_match('#^/v1/terminal/readers/([A-Za-z0-9_]+)/process_payment_intent$#', $path, $m) && $method === 'POST') {
    $piId = (string) ($params['payment_intent'] ?? '');
    if (!isset($db['pi'][$piId])) { [$status, $body] = [404, $err('No such payment_intent')]; }
    else {
        $db['readers'][$m[1]] = $piId;
        $body = ['id' => $m[1], 'object' => 'terminal.reader',
                 'action' => ['type' => 'process_payment_intent', 'status' => 'in_progress',
                              'process_payment_intent' => ['payment_intent' => $piId]]];
    }
} elseif (preg_match('#^/v1/terminal/readers/([A-Za-z0-9_]+)/cancel_action$#', $path, $m) && $method === 'POST') {
    unset($db['readers'][$m[1]]);
    $body = ['id' => $m[1], 'object' => 'terminal.reader', 'action' => null];
} elseif (preg_match('#^/v1/test_helpers/terminal/readers/([A-Za-z0-9_]+)/present_payment_method$#', $path, $m) && $method === 'POST') {
    $piId = $db['readers'][$m[1]] ?? '';
    if ($piId === '' || !isset($db['pi'][$piId])) { [$status, $body] = [400, $err('Reader has no action in progress.')]; }
    else {
        $declined = (string) ($params['card_present']['number'] ?? '') === '4000000000000002';
        if ($declined) {
            $db['pi'][$piId]['status'] = 'requires_payment_method';
            $db['pi'][$piId]['last_payment_error'] = ['code' => 'card_declined', 'message' => 'Your card was declined.'];
        } else {
            $db['pi'][$piId]['status'] = 'requires_capture';
        }
        unset($db['readers'][$m[1]]);
        $body = ['id' => $m[1], 'object' => 'terminal.reader', 'action' => ['status' => $declined ? 'failed' : 'succeeded']];
    }
} elseif ($method === 'POST' && $path === '/v1/refunds') {
    $pi = $db['pi'][(string) ($params['payment_intent'] ?? '')] ?? null;
    $remaining = $pi ? (int) $pi['amount_received'] - (int) $pi['refunded'] : 0;
    $amount = isset($params['amount']) ? (int) $params['amount'] : $remaining;
    if ($db['fail_next_refund']) {
        $db['fail_next_refund'] = false;
        [$status, $body] = [402, $err('Insufficient funds in Stripe account.')];
    } elseif (!$pi || $pi['status'] !== 'succeeded') { [$status, $body] = [400, $err('This PaymentIntent has no charge to refund.')]; }
    elseif ($amount <= 0 || $amount > $remaining) { [$status, $body] = [400, $err('Refund amount is greater than unrefunded amount on charge.')]; }
    else {
        $db['pi'][$pi['id']]['refunded'] += $amount;
        $rid = $id('re');
        $body = $db['re'][$rid] = ['id' => $rid, 'object' => 'refund', 'amount' => $amount,
                                   'payment_intent' => $pi['id'], 'status' => 'succeeded'];
    }
} elseif ($method === 'POST' && $path === '/v1/checkout/sessions') {
    $amount = 0;
    foreach (($params['line_items'] ?? []) as $li) {
        $amount += (int) ($li['quantity'] ?? 1) * (int) ($li['price_data']['unit_amount'] ?? 0);
    }
    $sid = $id('cs');
    $body = $db['cs'][$sid] = ['id' => $sid, 'object' => 'checkout.session', 'status' => 'open',
        'payment_status' => 'unpaid', 'amount_total' => $amount, 'payment_intent' => null,
        'metadata' => $meta($params), 'url' => 'https://checkout.stripe.test/' . $sid];
} elseif (preg_match('#^/v1/checkout/sessions/([A-Za-z0-9_]+)$#', $path, $m) && $method === 'GET') {
    [$status, $body] = isset($db['cs'][$m[1]]) ? [200, $db['cs'][$m[1]]] : [404, $err('No such checkout session')];
} else {
    [$status, $body] = [404, $err('Unrecognized request URL (' . $method . ' ' . $path . ') in the fake.')];
}

$save('objects.json', $db);
// Stripe keeps the answer for a key once the endpoint has run, errors included.
if ($method === 'POST' && $idem !== '') {
    $keys[$idem] = ['status' => $status, 'body' => $body];
    $save('idempotency.json', $keys);
}
return $send($status, $body);
