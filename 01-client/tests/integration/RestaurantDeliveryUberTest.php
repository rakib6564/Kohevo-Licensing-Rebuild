<?php
/**
 * Restaurant — Uber Direct courier fulfilment (integrations Part 4).
 * Courier requests go to tests/fixtures/fake-uber.php on a loopback port,
 * a stand-in for Uber Direct's DaaS API v1 and OAuth token endpoint that
 * issues tokens only for the test client credentials, checks the customer
 * id, and has a /_sim endpoint to move deliveries along. Webhooks arrive
 * through RestaurantDelivery::webhook() exactly as the public route passes
 * them. No real Uber account is involved.
 *
 * Every row it creates is deleted and every setting it changes is restored.
 */

declare(strict_types=1);

require_once SLATE_ROOT . '/plugins/restaurant/Restaurant.php';
require_once SLATE_ROOT . '/plugins/restaurant/includes/delivery.php';

$rud = ['items' => [], 'settings' => [], 'maxOrder' => 0, 'maxCustomer' => 0, 'proc' => null, 'state' => '', 'port' => 0,
        'customer' => 'cus-' . bin2hex(random_bytes(6)), 'client' => 'cli-' . bin2hex(random_bytes(6)), 'secret' => bin2hex(random_bytes(16)),
        'hookKey' => bin2hex(random_bytes(16)), 'token' => bin2hex(random_bytes(12))];

$rudSet = function (string $key, ?string $value) use (&$rud): void {
    $full = 'restaurant.' . $key;
    if (!array_key_exists($full, $rud['settings'])) $rud['settings'][$full] = Database::setting($full);
    if ($value === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $full]);
    else Database::setSetting($full, $value);
};
$rudCalls = function () use (&$rud): array {
    $f = $rud['state'] . '/requests.log';
    return is_file($f) ? array_map(fn($l) => json_decode($l, true), file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
};
$rudPost = function (string $path) use (&$rud): void {
    $ch = curl_init('http://127.0.0.1:' . $rud['port'] . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    curl_exec($ch);
};
$rudSim = fn(string $id, string $status, bool $courier = false) => $rudPost('/_sim/' . rawurlencode($id) . '?status=' . $status . ($courier ? '&courier=1' : ''));
/** An Uber delivery-status webhook: delivery_id on top, the delivery in data, signed with $key ('' = unsigned). */
$rudHook = function (array $job, string $claims, ?string $key = null, ?string $token = null) use (&$rud): array {
    $raw = json_encode(['id' => 'evt_' . bin2hex(random_bytes(4)), 'kind' => 'event.delivery_status', 'status' => $claims,
        'delivery_id' => (string)$job['provider_delivery_id'], 'customer_id' => $rud['customer'], 'live_mode' => false,
        'data' => ['id' => (string)$job['provider_delivery_id'], 'status' => $claims, 'external_id' => (string)$job['external_id']]]);
    $key = $key ?? $rud['hookKey'];
    $headers = $key === '' ? [] : ['x-uber-signature' => hash_hmac('sha256', $raw, $key)];
    return RestaurantDelivery::webhook('uber_direct', $token ?? $rud['token'], $raw, $headers);
};

try {
    $rudSet('currency', 'USD');
    $rudSet('service_charge_auto', '0');
    foreach (['biz_name' => 'RUD Smokehouse', 'addr_line1' => '1 Pit Lane', 'addr_line2' => 'Unit B', 'addr_city' => 'Testville', 'addr_state' => 'CA',
              'addr_postal' => '90001', 'addr_country' => 'US', 'biz_phone' => '(555) 010-4242', 'drive_enabled' => '0',
              'uber_enabled' => '1', 'uber_env' => 'test', 'uber_customer_id' => $rud['customer'], 'uber_client_id' => $rud['client'],
              'uber_client_secret' => $rud['secret'], 'uber_webhook_key' => $rud['hookKey'], 'uber_token' => '', 'uber_token_expires' => '0',
              'courier_webhook_token' => $rud['token'], 'courier_auto' => '0'] as $k => $v) $rudSet($k, $v);
    $manifest = json_decode((string) file_get_contents(SLATE_ROOT . '/plugins/restaurant/plugin.json'), true);
    (new Restaurant('restaurant', $manifest, SLATE_ROOT . '/plugins/restaurant'))->boot();
    $tid = current_tenant_id();
    $rud['maxOrder']    = (int) Database::value("SELECT COALESCE(MAX(id),0) FROM restaurant_orders");
    $rud['maxCustomer'] = (int) Database::value("SELECT COALESCE(MAX(id),0) FROM restaurant_customers");

    $rud['state'] = sys_get_temp_dir() . '/slate-fake-uber-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($rud['state'], 0700, true);
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $rud['port'] = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $rud['proc'] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $rud['port'], SLATE_ROOT . '/tests/fixtures/fake-uber.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $rud['state'] . '/server.out', 'a'], 2 => ['file', $rud['state'] . '/server.out', 'a']],
        $pipes, null, ['FAKE_UBER_STATE' => $rud['state'], 'FAKE_UBER_CUSTOMER' => $rud['customer'], 'FAKE_UBER_CLIENT' => $rud['client'],
                       'FAKE_UBER_SECRET' => $rud['secret'], 'PATH' => (string) getenv('PATH')]);
    for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $rud['port'], $en, $es, 0.1); if ($c) { fclose($c); break; } usleep(100000); }
    $rudSet('uber_api_base', 'http://127.0.0.1:' . $rud['port']);
    $rudSet('uber_auth_url', 'http://127.0.0.1:' . $rud['port'] . '/oauth/v2/token');
    RestaurantUberDirect::forgetToken();

    $rud['items'][] = $brisket = Database::insert('restaurant_items', ['tenant_id' => $tid, 'name' => 'RUD Brisket', 'slug' => 'rud-' . bin2hex(random_bytes(4)),
        'price_cents' => 1800, 'tax_rate' => 10, 'is_active' => 1]);
    $order = function (string $address = '12 Fixture Way, Apt 3, Testville, CA 90002', string $phone = '555-0199-777') use ($brisket, $tid): int {
        $cust = RestaurantAPI::findOrCreateCustomer('Alex Placeholder', $phone, '');
        $id = RestaurantAPI::openOrder(['type' => 'delivery', 'source' => 'online', 'customer_id' => $cust, 'server_id' => null,
            'notes' => 'Deliver to: ' . $address . ' · Ring the bell']);
        RestaurantAPI::addLine($id, ['item_id' => $brisket, 'qty' => 2]);
        Database::update('restaurant_orders', ['fulfillment_status' => 'accepted'], 'id = ? AND tenant_id = ?', [$id, $tid]);
        return $id;
    };

    unit('uber: one-line addresses split into Uber\'s parts; odd ones stay street lines', function (): void {
        assert_eq(['street' => ['12 Fixture Way', 'Apt 3'], 'city' => 'Testville', 'state' => 'CA', 'zip' => '90002', 'country' => 'US'],
            RestaurantDelivery::splitAddress('12 Fixture Way, Apt 3, Testville, ca 90002'));
        assert_eq(['street' => ['Somewhere near the park'], 'city' => '', 'state' => '', 'zip' => '', 'country' => 'US'],
            RestaurantDelivery::splitAddress('Somewhere near the park'));
        assert_eq('{"street_address":["1 Pit Lane"],"city":"X","state":"CA","zip_code":"90001","country":"US"}',
            RestaurantUberDirect::address(['street' => ['1 Pit Lane'], 'city' => 'X', 'state' => 'CA', 'zip' => '90001', 'country' => 'US']));
    });

    unit('uber: a quote fetches a token with the client credentials and sends JSON addresses; nothing is booked', function () use (&$rud, $order, $rudCalls): void {
        $oid = $order();
        $r = RestaurantDelivery::quote($oid, 'uber_direct');
        assert_true($r['ok'], json_encode($r));
        $j = $r['job'];
        assert_eq(['quoted', 899, 'USD'], [$j['status'], (int)$j['fee_cents'], $j['currency']]);
        assert_true(str_starts_with((string)$j['provider_delivery_id'], 'dqt_') && RestaurantDelivery::quoteLive($j) && $j['dropoff_eta'] !== null, 'quote id, expiry and times kept');
        $calls = $rudCalls();
        assert_eq(['/oauth/v2/token', 'client_credentials', 'eats.deliveries', true], [$calls[0]['path'], $calls[0]['grant'], $calls[0]['scope'], $calls[0]['creds']]);
        $q = array_values(array_filter($calls, fn($c) => str_ends_with($c['path'], '/delivery_quotes')))[0];
        assert_eq(['valid', '/v1/customers/' . $rud['customer'] . '/delivery_quotes'], [$q['auth'], $q['path']]);
        $b = json_decode($q['body'], true);
        assert_eq(['street_address' => ['1 Pit Lane', 'Unit B'], 'city' => 'Testville', 'state' => 'CA', 'zip_code' => '90001', 'country' => 'US'], json_decode($b['pickup_address'], true));
        assert_eq(['street_address' => ['12 Fixture Way', 'Apt 3'], 'city' => 'Testville', 'state' => 'CA', 'zip_code' => '90002', 'country' => 'US'], json_decode($b['dropoff_address'], true));
        assert_eq(['+15550104242', '+15550199777', 3600], [$b['pickup_phone_number'], $b['dropoff_phone_number'], $b['manifest_total_value']]);
        assert_true(!str_contains(json_encode($calls), $rud['secret']), 'the client secret is never logged or sent to the API');
        $rud['oidA'] = $oid; $rud['jobA'] = (int)$j['id'];
    });

    unit('uber: confirming creates the delivery with the quote id, our id and the items; the token is reused', function () use (&$rud, $rudCalls): void {
        $r = RestaurantDelivery::accept($rud['jobA']);
        assert_true($r['ok'], json_encode($r));
        $j = $r['job'];
        assert_eq('requested', $j['status']);
        assert_true(str_starts_with((string)$j['provider_delivery_id'], 'del_') && str_starts_with((string)$j['tracking_url'], 'https://'), 'Uber delivery id and tracking stored');
        $calls = $rudCalls();
        $create = array_values(array_filter($calls, fn($c) => str_ends_with($c['path'], '/deliveries') && $c['method'] === 'POST'))[0];
        $b = json_decode($create['body'], true);
        assert_eq([$j['external_id'], 'RUD Smokehouse', 'Alex Placeholder', 'Ring the bell', [['name' => 'RUD Brisket', 'quantity' => 2, 'size' => 'small', 'price' => 1800]]],
            [$b['external_id'], $b['pickup_name'], $b['dropoff_name'], $b['dropoff_notes'], $b['manifest_items']]);
        assert_true(str_starts_with($b['quote_id'], 'dqt_'), 'quote id sent');
        assert_eq(1, count(array_filter($calls, fn($c) => $c['path'] === '/oauth/v2/token')), 'one token for both calls');
    });

    unit('uber: signed webhooks are hints — the delivery is re-read from Uber and only that is applied', function () use (&$rud, $rudHook, $rudSim): void {
        $job = fn() => RestaurantDelivery::job($rud['jobA']);
        $del = (string)$job()['provider_delivery_id'];
        assert_eq([401, 'requested'], [$rudHook($job(), 'delivered', null, 'wrong-token')[0], $job()['status']], 'a wrong URL token reads nothing');
        assert_eq([401, 'requested'], [$rudHook($job(), 'delivered', 'not-the-key')[0], $job()['status']], 'a bad signature reads nothing');
        assert_eq([401, 'requested'], [$rudHook($job(), 'delivered', '')[0], $job()['status']], 'an unsigned webhook is refused while a key is saved');
        $rudSim($del, 'pending', true);
        [$code] = $rudHook($job(), 'delivered');
        assert_eq([200, 'courier_assigned', 'Robin K.'], [$code, $job()['status'], $job()['courier_name']], 'the body claimed delivered; Uber says a courier is on it');
        $rudSim($del, 'pickup');
        $rudHook($job(), 'pickup');
        assert_eq('enroute_pickup', $job()['status']);
        $rudSim($del, 'dropoff');
        $rudHook($job(), 'dropoff');
        assert_eq('picked_up', $job()['status']);
        $rudSim($del, 'delivered');
        $rudHook($job(), 'delivered');
        assert_eq(['delivered', 'completed'], [$job()['status'], Database::value("SELECT fulfillment_status FROM restaurant_orders WHERE id = ?", [$rud['oidA']])]);
        $rudSim($del, 'pickup');
        $rudHook($job(), 'pickup');
        assert_eq('delivered', $job()['status'], 'a stale webhook changes nothing');
        $unknown = ['provider_delivery_id' => 'del_unknown', 'external_id' => 'kv-unknown'];
        assert_eq([200, true], [$rudHook($unknown, 'pending')[0], $rudHook($unknown, 'pending')[1]['ignored'] ?? null], 'an unknown delivery is acknowledged and ignored');
    });

    unit('uber: without a signing key the URL token and the re-fetch still protect the hook', function () use (&$rud, $order, $rudHook, $rudSet): void {
        $rudSet('uber_webhook_key', '');
        $r = RestaurantDelivery::request($order(), 'uber_direct');
        assert_true($r['ok'], json_encode($r));
        [$code] = $rudHook($r['job'], 'delivered', '');
        assert_eq([200, 'requested'], [$code, RestaurantDelivery::job((int)$r['job']['id'])['status']], 'accepted, but Uber still says pending');
        $rudSet('uber_webhook_key', $rud['hookKey']);
    });

    unit('uber: a courier can be cancelled while pending; once one is on it Uber\'s refusal is shown', function () use ($order, $rudSim): void {
        $r = RestaurantDelivery::request($order(), 'uber_direct');
        $c = RestaurantDelivery::cancel((int)$r['job']['id']);
        assert_eq([true, 'cancelled'], [$c['ok'], $c['job']['status']]);

        $r = RestaurantDelivery::request($order(), 'uber_direct');
        $rudSim((string)$r['job']['provider_delivery_id'], 'pickup');
        RestaurantDelivery::sync((int)$r['job']['id']);
        $c = RestaurantDelivery::cancel((int)$r['job']['id']);
        assert_true(!$c['ok'] && str_contains($c['error'], 'cannot be canceled') && str_contains($c['error'], 'merchant portal'), $c['error'] ?? '');
        assert_eq('enroute_pickup', RestaurantDelivery::job((int)$r['job']['id'])['status'], 'the job is unchanged');
    });

    unit('uber: an undeliverable address is shown to staff, recorded on the job, and leaves the order alone', function () use ($order): void {
        $oid = $order('99 Far Away Rd NOCOVER, Elsewhere, CA 90999');
        $before = Database::row("SELECT status, fulfillment_status, notes, total_cents FROM restaurant_orders WHERE id = ?", [$oid]);
        $r = RestaurantDelivery::quote($oid, 'uber_direct');
        assert_true(!$r['ok'] && str_contains($r['error'], 'not in a deliverable area'), $r['error'] ?? '');
        assert_eq('failed', $r['job']['status']);
        assert_eq($before, Database::row("SELECT status, fulfillment_status, notes, total_cents FROM restaurant_orders WHERE id = ?", [$oid]), 'order untouched');
    });

    unit('uber: a revoked token is replaced once; bad credentials reach staff as Uber\'s error', function () use ($order, $rudPost, $rudSet, $rudCalls, &$rud): void {
        $rudPost('/_revoke');
        $before = count(array_filter($rudCalls(), fn($c) => $c['path'] === '/oauth/v2/token'));
        $r = RestaurantDelivery::quote($order(), 'uber_direct');
        assert_true($r['ok'], json_encode($r));
        assert_eq($before + 1, count(array_filter($rudCalls(), fn($c) => $c['path'] === '/oauth/v2/token')), 'a 401 fetched one new token');

        $rudSet('uber_client_secret', 'wrong-' . bin2hex(random_bytes(4)));
        RestaurantUberDirect::forgetToken();
        $r = RestaurantDelivery::quote($order(), 'uber_direct');
        assert_true(!$r['ok'] && str_contains($r['error'], 'invalid_client'), $r['error'] ?? '');
        assert_true(!RestaurantUberDirect::checkCredentials()['ok'], 'Check the credentials fails too');
        $rudSet('uber_client_secret', $rud['secret']);
        RestaurantUberDirect::forgetToken();
        assert_true(RestaurantUberDirect::checkCredentials()['ok'], 'and passes with the right secret');
    });

    unit('uber: both providers are offered when both are on, and the view names Uber', function () use (&$rud, $rudSet): void {
        assert_eq(['uber_direct'], array_keys(RestaurantDelivery::enabledProviders()));
        $rudSet('drive_enabled', '1'); $rudSet('drive_developer_id', 'd'); $rudSet('drive_key_id', 'k'); $rudSet('drive_signing_secret', 'c2VjcmV0');
        assert_eq(['doordash_drive', 'uber_direct'], array_keys(RestaurantDelivery::enabledProviders()));
        $rudSet('drive_enabled', '0');
        $v = RestaurantDelivery::view(RestaurantDelivery::job($rud['jobA']));
        assert_eq(['Uber', 'delivered'], [$v['provider'], $v['status']]);
        assert_true(!str_contains(json_encode($v), $rud['secret']) && !str_contains(json_encode($v), $rud['hookKey']), 'no secrets');
    });
} finally {
    $oids = array_column(Database::rows("SELECT id FROM restaurant_orders WHERE id > ?", [$rud['maxOrder']]), 'id');
    foreach ($oids as $oid) {
        foreach (array_column(Database::rows("SELECT id FROM restaurant_order_items WHERE order_id = ?", [$oid]), 'id') as $lid) {
            Database::delete('restaurant_order_item_modifiers', 'order_item_id = ?', [$lid]);
        }
        Database::delete('restaurant_order_items', 'order_id = ?', [$oid]);
        Database::delete('restaurant_payments', 'order_id = ?', [$oid]);
        Database::delete('restaurant_delivery_jobs', 'order_id = ?', [$oid]);
        Database::delete('restaurant_orders', 'id = ?', [$oid]);
    }
    Database::query("DELETE FROM restaurant_customers WHERE id > ?", [$rud['maxCustomer']]);
    foreach ($rud['items'] as $i) Database::delete('restaurant_items', 'id = ?', [$i]);
    foreach ($rud['settings'] as $key => $orig) {
        if ($orig === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
        else Database::setSetting($key, $orig);
    }
    if ($rud['proc']) { proc_terminate($rud['proc']); proc_close($rud['proc']); }
    if ($rud['state']) { array_map('unlink', glob($rud['state'] . '/*') ?: []); @rmdir($rud['state']); }
}
