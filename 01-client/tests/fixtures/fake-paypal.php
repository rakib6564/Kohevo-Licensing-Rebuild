<?php
/**
 * A stand-in for the PayPal REST calls the payment tests make (developer tool; never shipped or deployed).
 *
 *   FAKE_PAYPAL_STATE=/tmp/dir php -S 127.0.0.1:12113 tests/fixtures/fake-paypal.php
 *
 * PayPalPaymentAPI::apiBase() honours PAYPAL_API_BASE only for a plain-http loopback address, so a real install can
 * never be pointed here. State lives in FAKE_PAYPAL_STATE: objects.json and requests.log (one JSON line per request,
 * with its PayPal-Request-Id and whether it was a replay). Like PayPal, a POST that repeats a PayPal-Request-Id gets
 * the first answer back and changes nothing.
 *
 * Credentials: client id `fake-client-id-0000000000`, secret `fake-secret`; anything else gets 401.
 * Webhook verification answers SUCCESS only when paypal-transmission-sig is `good`.
 *
 * Test-only behaviour:
 *   POST /__test/approve/{orderId}        the buyer approves the order (status APPROVED, payer set)
 *   POST /__test/activate/{subId}         the buyer approves the subscription (status ACTIVE)
 *   POST /__test/decline_next_capture     the next capture answers 422 INSTRUMENT_DECLINED
 *   POST /__test/fail_next_capture        the next capture answers 500 INTERNAL_SERVER_ERROR (nothing captured)
 *   POST /__test/expire_tokens            every issued token now answers 401
 */

$dir = rtrim((string) getenv('FAKE_PAYPAL_STATE'), '/');
if ($dir === '' || !is_dir($dir)) { http_response_code(500); echo '{"message":"FAKE_PAYPAL_STATE not set"}'; return true; }

$method = $_SERVER['REQUEST_METHOD'];
$path   = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw    = (string) file_get_contents('php://input');
$body   = json_decode($raw, true) ?: [];
$reqId  = (string) ($_SERVER['HTTP_PAYPAL_REQUEST_ID'] ?? '');
$auth   = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

$fp = fopen($dir . '/lock', 'c');
flock($fp, LOCK_EX);

$load = function () use ($dir): array {
    $f = $dir . '/objects.json';
    return is_file($f) ? json_decode((string) file_get_contents($f), true)
        : ['run' => strtoupper(bin2hex(random_bytes(3))), 'seq' => 0, 'tokens' => [], 'orders' => [], 'captures' => [],
           'refunds' => [], 'subs' => [], 'sales' => [], 'plans' => [], 'products' => [], 'idem' => [], 'decline_next' => false];
};
$db = $load();
$save = function () use (&$db, $dir): void { file_put_contents($dir . '/objects.json', json_encode($db, JSON_PRETTY_PRINT)); };
$send = function (int $status, array $out) use ($fp): bool {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($out);
    flock($fp, LOCK_UN);
    return true;
};
$log = function (bool $replay) use ($dir, $method, $path, $reqId, $body, $raw): void {
    file_put_contents($dir . '/requests.log', json_encode(['method' => $method, 'path' => $path, 'request_id' => $reqId,
        'replay' => $replay, 'body' => $body] + ($path === '/v1/notifications/verify-webhook-signature' ? ['raw' => $raw] : [])) . "\n", FILE_APPEND);
};
$id   = function (string $prefix) use (&$db): string { return $prefix . $db['run'] . str_pad((string) (++$db['seq']), 5, '0', STR_PAD_LEFT); };
$err  = fn (int $s, string $name, string $issue, string $msg) => [$s, ['name' => $name, 'message' => $msg, 'details' => [['issue' => $issue, 'description' => $msg]]]];
$base = 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');

if ($method === 'POST' && $reqId !== '' && isset($db['idem'][$reqId])) {
    $log(true);
    [$st, $out] = $db['idem'][$reqId];
    return $send($st, $out);
}
$log(false);

[$status, $out] = [404, ['name' => 'RESOURCE_NOT_FOUND', 'message' => 'no route']];
$m = [];

// ── test hooks and OAuth need no bearer token ──
if ($method === 'POST' && $path === '/v1/oauth2/token') {
    $basic = base64_decode(substr($auth, 6));
    if (!str_starts_with($auth, 'Basic ') || $basic !== 'fake-client-id-0000000000:fake-secret') {
        [$status, $out] = [401, ['error' => 'invalid_client', 'error_description' => 'Client Authentication failed']];
    } else {
        $tok = 'A21AA' . bin2hex(random_bytes(8));
        $db['tokens'][$tok] = true;
        [$status, $out] = [200, ['access_token' => $tok, 'token_type' => 'Bearer', 'expires_in' => 32400]];
    }
} elseif ($method === 'POST' && preg_match('#^/__test/approve/([A-Z0-9]+)$#', $path, $m) && isset($db['orders'][$m[1]])) {
    $db['orders'][$m[1]]['status'] = 'APPROVED';
    $db['orders'][$m[1]]['payer'] = ['email_address' => 'buyer@example.test', 'payer_id' => 'PAYER1'];
    [$status, $out] = [200, ['ok' => true]];
} elseif ($method === 'POST' && preg_match('#^/__test/activate/(I-[A-Z0-9]+)$#', $path, $m) && isset($db['subs'][$m[1]])) {
    $db['subs'][$m[1]]['status'] = 'ACTIVE';
    [$status, $out] = [200, ['ok' => true]];
} elseif ($method === 'POST' && $path === '/__test/fail_next_capture') {
    $db['fail_next'] = true;
    [$status, $out] = [200, ['ok' => true]];
} elseif ($method === 'POST' && $path === '/__test/decline_next_capture') {
    $db['decline_next'] = true;
    [$status, $out] = [200, ['ok' => true]];
} elseif ($method === 'POST' && $path === '/__test/expire_tokens') {
    $db['tokens'] = [];
    [$status, $out] = [200, ['ok' => true]];
} elseif (!str_starts_with($auth, 'Bearer ') || !isset($db['tokens'][substr($auth, 7)])) {
    [$status, $out] = [401, ['error' => 'invalid_token', 'error_description' => 'Token signature verification failed']];

// ── Orders ──
} elseif ($method === 'POST' && $path === '/v2/checkout/orders') {
    $oid = $id('ORD');
    $ctx = $body['payment_source']['paypal']['experience_context'] ?? [];
    $db['orders'][$oid] = [
        'id' => $oid, 'intent' => $body['intent'] ?? '', 'status' => 'PAYER_ACTION_REQUIRED',
        'purchase_units' => $body['purchase_units'] ?? [], 'experience_context' => $ctx,
        'links' => [['rel' => 'payer-action', 'href' => $base . '/checkoutnow?token=' . $oid, 'method' => 'GET']],
    ];
    [$status, $out] = [201, $db['orders'][$oid]];
} elseif ($method === 'GET' && preg_match('#^/v2/checkout/orders/([A-Z0-9]+)$#', $path, $m)) {
    [$status, $out] = isset($db['orders'][$m[1]]) ? [200, $db['orders'][$m[1]]] : $err(404, 'RESOURCE_NOT_FOUND', 'INVALID_RESOURCE_ID', 'order not found');
} elseif ($method === 'POST' && preg_match('#^/v2/checkout/orders/([A-Z0-9]+)/capture$#', $path, $m)) {
    $o = $db['orders'][$m[1]] ?? null;
    if ($o === null) {
        [$status, $out] = $err(404, 'RESOURCE_NOT_FOUND', 'INVALID_RESOURCE_ID', 'order not found');
    } elseif ($o['status'] === 'COMPLETED') {
        [$status, $out] = $err(422, 'UNPROCESSABLE_ENTITY', 'ORDER_ALREADY_CAPTURED', 'Order already captured.');
    } elseif ($o['status'] !== 'APPROVED') {
        [$status, $out] = $err(422, 'UNPROCESSABLE_ENTITY', 'ORDER_NOT_APPROVED', 'Payer has not yet approved the Order for payment.');
    } elseif (!empty($db['fail_next'])) {
        $db['fail_next'] = false;
        [$status, $out] = $err(500, 'INTERNAL_SERVER_ERROR', 'INTERNAL_SERVICE_ERROR', 'An internal service error has occurred.');
    } elseif ($db['decline_next']) {
        $db['decline_next'] = false;
        [$status, $out] = $err(422, 'UNPROCESSABLE_ENTITY', 'INSTRUMENT_DECLINED', 'The instrument presented was declined.');
    } else {
        $cid = $id('CAP');
        $amount = $o['purchase_units'][0]['amount'];
        $cap = ['id' => $cid, 'status' => 'COMPLETED', 'amount' => ['currency_code' => $amount['currency_code'], 'value' => $amount['value']],
                'supplementary_data' => ['related_ids' => ['order_id' => $o['id']]]];
        $db['captures'][$cid] = $cap + ['refunded' => 0.0];
        $o['status'] = 'COMPLETED';
        $o['purchase_units'][0]['payments'] = ['captures' => [$cap]];
        $db['orders'][$o['id']] = $o;
        [$status, $out] = [201, $o];
    }
} elseif ($method === 'POST' && preg_match('#^/v2/payments/captures/([A-Z0-9]+)/refund$#', $path, $m)) {
    $c = $db['captures'][$m[1]] ?? null;
    if ($c === null) {
        [$status, $out] = $err(404, 'RESOURCE_NOT_FOUND', 'INVALID_RESOURCE_ID', 'capture not found');
    } else {
        $left = (float) $c['amount']['value'] - $c['refunded'];
        $amt  = isset($body['amount']['value']) ? (float) $body['amount']['value'] : $left;
        if ($amt <= 0 || $amt > $left + 0.0001) {
            [$status, $out] = $err(422, 'UNPROCESSABLE_ENTITY', 'REFUND_AMOUNT_EXCEEDED', 'Refund amount exceeded.');
        } else {
            $rid = $id('REF');
            $db['captures'][$m[1]]['refunded'] = $c['refunded'] + $amt;
            $db['refunds'][$rid] = ['capture' => $m[1], 'value' => $amt];
            [$status, $out] = [201, ['id' => $rid, 'status' => 'COMPLETED',
                'amount' => ['currency_code' => $c['amount']['currency_code'], 'value' => number_format($amt, 2, '.', '')]]];
        }
    }

// ── Subscriptions ──
} elseif ($method === 'POST' && $path === '/v1/catalogs/products') {
    $pid = $id('PROD-');
    $db['products'][$pid] = $body;
    [$status, $out] = [201, ['id' => $pid] + $body];
} elseif ($method === 'POST' && $path === '/v1/billing/plans') {
    $plid = $id('P-');
    $db['plans'][$plid] = $body;
    [$status, $out] = [201, ['id' => $plid, 'status' => 'ACTIVE'] + $body];
} elseif ($method === 'POST' && $path === '/v1/billing/subscriptions') {
    if (!isset($db['plans'][$body['plan_id'] ?? ''])) {
        [$status, $out] = $err(422, 'UNPROCESSABLE_ENTITY', 'INVALID_PLAN', 'plan not found');
    } else {
        $sid = $id('I-');
        $db['subs'][$sid] = ['id' => $sid, 'status' => 'APPROVAL_PENDING', 'plan_id' => $body['plan_id'],
            'custom_id' => $body['custom_id'] ?? '', 'subscriber' => $body['subscriber'] ?? ['email_address' => 'buyer@example.test'],
            'application_context' => $body['application_context'] ?? [],
            'links' => [['rel' => 'approve', 'href' => $base . '/webapps/billing/subscriptions?ba_token=BA-' . $sid, 'method' => 'GET']]];
        [$status, $out] = [201, $db['subs'][$sid]];
    }
} elseif ($method === 'GET' && preg_match('#^/v1/billing/subscriptions/(I-[A-Z0-9]+)$#', $path, $m)) {
    [$status, $out] = isset($db['subs'][$m[1]]) ? [200, $db['subs'][$m[1]]] : $err(404, 'RESOURCE_NOT_FOUND', 'INVALID_RESOURCE_ID', 'subscription not found');
} elseif ($method === 'POST' && preg_match('#^/v1/payments/sale/([A-Z0-9]+)/refund$#', $path, $m)) {
    $rid = $id('SREF');
    $db['refunds'][$rid] = ['sale' => $m[1], 'value' => $body['amount']['total'] ?? 'full'];
    [$status, $out] = [201, ['id' => $rid, 'state' => 'completed']];

// ── Webhooks ──
} elseif ($method === 'POST' && $path === '/v1/notifications/verify-webhook-signature') {
    $ok = ($body['transmission_sig'] ?? '') === 'good' && ($body['webhook_id'] ?? '') !== '' && is_array($body['webhook_event'] ?? null);
    [$status, $out] = [200, ['verification_status' => $ok ? 'SUCCESS' : 'FAILURE']];
}

if ($method === 'POST' && $reqId !== '' && $status < 500) {
    $db['idem'][$reqId] = [$status, $out];
}
$save();
return $send($status, $out);
