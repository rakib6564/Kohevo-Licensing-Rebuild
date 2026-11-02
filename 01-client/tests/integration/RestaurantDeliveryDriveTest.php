<?php
/**
 * Restaurant — DoorDash Drive courier fulfilment (integrations Part 3).
 * Courier requests go to tests/fixtures/fake-drive.php on a loopback port,
 * a stand-in for Drive API v2 that checks every JWT (header, claims,
 * expiry, signature with the test secret) and has a /_sim endpoint playing
 * DoorDash's Delivery Simulator. Webhooks arrive through
 * RestaurantDelivery::webhook() exactly as the public route passes them.
 * No real DoorDash account is involved.
 *
 * Every row it creates is deleted and every setting it changes is restored.
 */

declare(strict_types=1);

require_once SLATE_ROOT . '/plugins/restaurant/Restaurant.php';
require_once SLATE_ROOT . '/plugins/restaurant/includes/delivery.php';

$rdd = ['items' => [], 'settings' => [], 'maxOrder' => 0, 'maxCustomer' => 0, 'maxNote' => 0, 'proc' => null, 'state' => '', 'port' => 0,
        'secret' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), 'dev' => 'dev-' . bin2hex(random_bytes(4)),
        'key' => 'key-' . bin2hex(random_bytes(4)), 'token' => bin2hex(random_bytes(12))];

$rddSet = function (string $key, ?string $value) use (&$rdd): void {
    $full = 'restaurant.' . $key;
    if (!array_key_exists($full, $rdd['settings'])) $rdd['settings'][$full] = Database::setting($full);
    if ($value === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $full]);
    else Database::setSetting($full, $value);
};
$rddCalls = function () use (&$rdd): array {
    $f = $rdd['state'] . '/requests.log';
    return is_file($f) ? array_map(fn($l) => json_decode($l, true), file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
};
$rddSim = function (string $ext, string $status) use (&$rdd): void {
    $ch = curl_init('http://127.0.0.1:' . $rdd['port'] . '/_sim/' . rawurlencode($ext) . '?status=' . $status);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    curl_exec($ch);
};
$rddHook = fn(array $body, ?string $token = null) => RestaurantDelivery::webhook('doordash_drive', $token ?? $rdd['token'], json_encode($body));

try {
    $rddSet('currency', 'USD');
    $rddSet('service_charge_auto', '0');
    foreach (['biz_name' => 'RDD Smokehouse', 'addr_line1' => '1 Pit Lane', 'addr_line2' => '', 'addr_city' => 'Testville', 'addr_state' => 'CA',
              'addr_postal' => '90001', 'biz_phone' => '(555) 010-4242', 'drive_enabled' => '1', 'drive_env' => 'sandbox',
              'drive_developer_id' => $rdd['dev'], 'drive_key_id' => $rdd['key'], 'drive_signing_secret' => $rdd['secret'],
              'courier_webhook_token' => $rdd['token'], 'courier_auto' => '0'] as $k => $v) $rddSet($k, $v);
    $manifest = json_decode((string) file_get_contents(SLATE_ROOT . '/plugins/restaurant/plugin.json'), true);
    (new Restaurant('restaurant', $manifest, SLATE_ROOT . '/plugins/restaurant'))->boot();
    $tid = current_tenant_id();
    $rdd['maxOrder']    = (int) Database::value("SELECT COALESCE(MAX(id),0) FROM restaurant_orders");
    $rdd['maxCustomer'] = (int) Database::value("SELECT COALESCE(MAX(id),0) FROM restaurant_customers");
    Notifications::ensureSchema();
    $rdd['maxNote']     = (int) Database::value("SELECT COALESCE(MAX(id),0) FROM slate_notifications");

    $rdd['state'] = sys_get_temp_dir() . '/slate-fake-drive-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($rdd['state'], 0700, true);
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $rdd['port'] = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $rdd['proc'] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $rdd['port'], SLATE_ROOT . '/tests/fixtures/fake-drive.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $rdd['state'] . '/server.out', 'a'], 2 => ['file', $rdd['state'] . '/server.out', 'a']],
        $pipes, null, ['FAKE_DRIVE_STATE' => $rdd['state'], 'FAKE_DRIVE_SECRET' => $rdd['secret'], 'FAKE_DRIVE_DEV' => $rdd['dev'],
                       'FAKE_DRIVE_KEY' => $rdd['key'], 'PATH' => (string) getenv('PATH')]);
    for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $rdd['port'], $en, $es, 0.1); if ($c) { fclose($c); break; } usleep(100000); }
    $rddSet('drive_api_base', 'http://127.0.0.1:' . $rdd['port']);

    $rdd['items'][] = $brisket = Database::insert('restaurant_items', ['tenant_id' => $tid, 'name' => 'RDD Brisket', 'slug' => 'rdd-' . bin2hex(random_bytes(4)),
        'price_cents' => 1800, 'tax_rate' => 10, 'is_active' => 1]);
    /** A delivery order as checkout makes it: the address is the "Deliver to:" prefix of the notes. */
    $order = function (string $address = '12 Fixture Way, Testville, CA 90002', string $source = 'online', string $phone = '555-0199-777') use ($brisket, $tid): int {
        $cust = RestaurantAPI::findOrCreateCustomer('Alex Placeholder', $phone, '');
        $id = RestaurantAPI::openOrder(['type' => 'delivery', 'source' => $source, 'customer_id' => $cust, 'server_id' => null,
            'notes' => 'Deliver to: ' . $address . ' · Ring the bell']);
        RestaurantAPI::addLine($id, ['item_id' => $brisket, 'qty' => 2]);
        Database::update('restaurant_orders', ['fulfillment_status' => 'accepted'], 'id = ? AND tenant_id = ?', [$id, $tid]);
        return $id;
    };

    unit('drive: the JWT matches DoorDash\'s format and is signed with the decoded secret', function () use (&$rdd): void {
        $now = time();
        [$h, $p, $s] = explode('.', RestaurantDoorDashDrive::jwt($now));
        $d = fn($x) => base64_decode(strtr($x, '-_', '+/'));
        assert_eq(['alg' => 'HS256', 'typ' => 'JWT', 'dd-ver' => 'DD-JWT-V1'], json_decode($d($h), true));
        assert_eq(['aud' => 'doordash', 'iss' => $rdd['dev'], 'kid' => $rdd['key'], 'iat' => $now, 'exp' => $now + 300], json_decode($d($p), true));
        assert_true(hash_equals(hash_hmac('sha256', $h . '.' . $p, $d($rdd['secret']), true), $d($s)), 'HMAC-SHA256 over header.payload with the base64url-decoded secret');
        assert_true(!str_contains($h . $p . $s, '='), 'base64url without padding');
    });

    unit('drive: a quote sends the pickup, the parsed drop-off and E.164 phones; nothing is booked', function () use (&$rdd, $order, $rddCalls): void {
        $oid = $order();
        $r = RestaurantDelivery::quote($oid, 'doordash_drive');
        assert_true($r['ok'], json_encode($r));
        $j = $r['job'];
        assert_eq(['quoted', 975, 'USD', 'kv' . current_tenant_id() . '-' . $oid . '-1'], [$j['status'], (int)$j['fee_cents'], $j['currency'], $j['external_id']]);
        assert_true($j['pickup_eta'] !== null && $j['dropoff_eta'] !== null && RestaurantDelivery::quoteLive($j), 'times and a live quote');
        $call = array_values(array_filter($rddCalls(), fn($c) => $c['path'] === '/drive/v2/quotes'))[0];
        assert_eq('valid', $call['jwt'], 'the stand-in accepted our JWT');
        $b = json_decode($call['body'], true);
        assert_eq(['1 Pit Lane, Testville, CA 90001', 'RDD Smokehouse', '+15550104242', '12 Fixture Way, Testville, CA 90002', '+15550199777', 'Alex', 'Placeholder', 'Ring the bell', 3600],
            [$b['pickup_address'], $b['pickup_business_name'], $b['pickup_phone_number'], $b['dropoff_address'], $b['dropoff_phone_number'],
             $b['dropoff_contact_given_name'], $b['dropoff_contact_family_name'], $b['dropoff_instructions'], $b['order_value']]);
        $rdd['oidA'] = $oid; $rdd['jobA'] = (int)$j['id'];
    });

    unit('drive: confirming books the courier; a second request on the order is refused', function () use (&$rdd): void {
        $r = RestaurantDelivery::accept($rdd['jobA']);
        assert_true($r['ok'], json_encode($r));
        assert_eq('requested', $r['job']['status']);
        assert_true(str_starts_with((string)$r['job']['tracking_url'], 'https://doordash.example.test/track/') && $r['job']['provider_delivery_id'] !== '', 'tracking URL and support reference stored');
        $again = RestaurantDelivery::quote($rdd['oidA'], 'doordash_drive');
        assert_true(!$again['ok'] && str_contains($again['error'], 'already'), 'one courier per order');
    });

    unit('drive: webhooks are hints — the delivery is re-read from DoorDash and only that is applied', function () use (&$rdd, $rddHook, $rddSim): void {
        $job = fn() => RestaurantDelivery::job($rdd['jobA']);
        $ext = $job()['external_id'];
        assert_eq([401, 'requested'], [$rddHook(['external_delivery_id' => $ext], 'wrong-token')[0], $job()['status']], 'a wrong URL token reads nothing');
        $rddSim($ext, 'confirmed');
        [$code] = $rddHook(['external_delivery_id' => $ext, 'event_name' => 'DASHER_DROPPED_OFF', 'delivery_status' => 'delivered']);
        assert_eq([200, 'courier_assigned', 'Sam T.'], [$code, $job()['status'], $job()['courier_name']], 'the body claimed delivered; DoorDash says confirmed');
        $rddSim($ext, 'picked_up');
        $rddHook(['external_delivery_id' => $ext]);
        assert_eq('picked_up', $job()['status']);
        $rddSim($ext, 'delivered');
        $rddHook(['external_delivery_id' => $ext]);
        assert_eq(['delivered', 'completed'], [$job()['status'], Database::value("SELECT fulfillment_status FROM restaurant_orders WHERE id = ?", [$rdd['oidA']])]);
        $rddHook(['external_delivery_id' => $ext]);
        $rddSim($ext, 'picked_up');
        $rddHook(['external_delivery_id' => $ext]);
        assert_eq('delivered', $job()['status'], 'duplicate and stale webhooks change nothing');
        assert_eq([200, true], [$rddHook(['external_delivery_id' => 'kv-unknown'])[0], $rddHook(['external_delivery_id' => 'kv-unknown'])[1]['ignored']], 'an unknown delivery is acknowledged and ignored');
    });

    unit('drive: a courier can be cancelled until a Dasher is assigned; after that DoorDash\'s refusal is shown', function () use ($order, $rddSim): void {
        $r = RestaurantDelivery::request($order(), 'doordash_drive');
        assert_true($r['ok'], json_encode($r));
        $c = RestaurantDelivery::cancel((int)$r['job']['id']);
        assert_eq([true, 'cancelled'], [$c['ok'], $c['job']['status']]);

        $r = RestaurantDelivery::request($order(), 'doordash_drive');
        $rddSim((string)$r['job']['external_id'], 'confirmed');
        RestaurantDelivery::sync((int)$r['job']['id']);
        $c = RestaurantDelivery::cancel((int)$r['job']['id']);
        assert_true(!$c['ok'] && str_contains($c['error'], 'cannot be cancelled') && str_contains($c['error'], 'merchant portal'), $c['error'] ?? '');
        assert_eq('courier_assigned', RestaurantDelivery::job((int)$r['job']['id'])['status'], 'the job is unchanged');
    });

    unit('drive: a provider error is shown to staff, recorded on the job, and leaves the order alone', function () use ($order): void {
        $oid = $order('99 Far Away Rd NOCOVER, Elsewhere, CA 90999');
        $before = Database::row("SELECT status, fulfillment_status, notes, total_cents FROM restaurant_orders WHERE id = ?", [$oid]);
        $r = RestaurantDelivery::quote($oid, 'doordash_drive');
        assert_true(!$r['ok'] && str_contains($r['error'], 'outside of the delivery area'), $r['error'] ?? '');
        assert_eq('failed', $r['job']['status']);
        assert_true(str_contains((string)$r['job']['error'], 'outside of the delivery area'), 'recorded');
        assert_eq($before, Database::row("SELECT status, fulfillment_status, notes, total_cents FROM restaurant_orders WHERE id = ?", [$oid]), 'order untouched');

        $noAddr = $order(''); Database::update('restaurant_orders', ['notes' => 'Ring the bell'], 'id = ?', [$noAddr]);
        $r = RestaurantDelivery::quote($noAddr, 'doordash_drive');
        assert_true(!$r['ok'] && str_contains($r['error'], 'no delivery address'), 'refused before calling DoorDash');
    });

    unit('drive: an expired quote cannot be confirmed; asking again makes a new attempt id', function () use ($order): void {
        $oid = $order();
        $q = RestaurantDelivery::quote($oid, 'doordash_drive');
        Database::update('restaurant_delivery_jobs', ['quote_expires_at' => date('Y-m-d H:i:s', time() - 5)], 'id = ?', [(int)$q['job']['id']]);
        $a = RestaurantDelivery::accept((int)$q['job']['id']);
        assert_true(!$a['ok'] && str_contains($a['error'], 'expired'), $a['error'] ?? '');
        $q2 = RestaurantDelivery::quote($oid, 'doordash_drive');
        assert_eq([true, 'kv' . current_tenant_id() . '-' . $oid . '-2'], [$q2['ok'], $q2['job']['external_id']]);
    });

    unit('drive: a bad signing secret is refused by DoorDash and the message reaches staff', function () use ($order, $rddSet): void {
        $rddSet('drive_signing_secret', rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
        $r = RestaurantDelivery::quote($order(), 'doordash_drive');
        assert_true(!$r['ok'] && str_contains($r['error'], 'JWT signature'), $r['error'] ?? '');
    });

    unit('drive: auto-request books a courier when an online delivery order is paid, never for a channel order', function () use (&$rdd, $order, $rddSet): void {
        $rddSet('drive_signing_secret', $rdd['secret']);
        $rddSet('courier_auto', '1');
        $oid = $order();
        RestaurantAPI::recordCashPayment($oid, (int) Database::value("SELECT total_cents FROM restaurant_orders WHERE id = ?", [$oid]));
        assert_eq('requested', RestaurantDelivery::latestJob($oid)['status'] ?? null, 'paid → courier requested');
        $ch = $order('12 Fixture Way, Testville, CA 90002', 'channel');
        RestaurantAPI::recordCashPayment($ch, (int) Database::value("SELECT total_cents FROM restaurant_orders WHERE id = ?", [$ch]));
        assert_eq(null, RestaurantDelivery::latestJob($ch), 'channel orders are left to staff');
        $rddSet('courier_auto', '0');
    });

    unit('drive: the job view for staff and customers has the tracking link and no secrets', function () use (&$rdd): void {
        $v = RestaurantDelivery::view(RestaurantDelivery::job($rdd['jobA']));
        assert_eq(['DoorDash', 'delivered', 'Delivered'], [$v['provider'], $v['status'], $v['label']]);
        assert_true(str_starts_with($v['tracking_url'], 'https://') && !isset($v['raw_last_payload']), 'tracking, no raw payload');
        assert_true(!str_contains(json_encode($v), $rdd['secret']), 'no secret');
    });
} finally {
    $oids = array_column(Database::rows("SELECT id FROM restaurant_orders WHERE id > ?", [$rdd['maxOrder']]), 'id');
    foreach ($oids as $oid) {
        foreach (array_column(Database::rows("SELECT id FROM restaurant_order_items WHERE order_id = ?", [$oid]), 'id') as $lid) {
            Database::delete('restaurant_order_item_modifiers', 'order_item_id = ?', [$lid]);
        }
        Database::delete('restaurant_order_items', 'order_id = ?', [$oid]);
        Database::delete('restaurant_payments', 'order_id = ?', [$oid]);
        Database::delete('restaurant_delivery_jobs', 'order_id = ?', [$oid]);
        Database::delete('restaurant_orders', 'id = ?', [$oid]);
    }
    Database::query("DELETE FROM restaurant_customers WHERE id > ?", [$rdd['maxCustomer']]);
    Database::query("DELETE FROM slate_notifications WHERE id > ?", [$rdd['maxNote']]);
    foreach ($rdd['items'] as $i) Database::delete('restaurant_items', 'id = ?', [$i]);
    foreach ($rdd['settings'] as $key => $orig) {
        if ($orig === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
        else Database::setSetting($key, $orig);
    }
    if ($rdd['proc']) { proc_terminate($rdd['proc']); proc_close($rdd['proc']); }
    if ($rdd['state']) { array_map('unlink', glob($rdd['state'] . '/*') ?: []); @rmdir($rdd['state']); }
}
