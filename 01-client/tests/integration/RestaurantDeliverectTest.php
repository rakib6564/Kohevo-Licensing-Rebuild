<?php
/**
 * Restaurant — the Deliverect channel adapter (integrations Part 2).
 * Synthetic Deliverect-shaped webhooks (tests/fixtures/deliverect/) go
 * through RestaurantChannels::intake() exactly as the public route passes
 * them (raw body + request headers). Status updates go to
 * tests/fixtures/fake-channel.php on a loopback port standing in for
 * Deliverect's API: it answers the client-credentials token call and
 * records every request, so the test can check the bearer token, the path
 * and Deliverect's status codes.
 *
 * Every row it creates is deleted and every setting it changes is restored.
 */

declare(strict_types=1);

require_once SLATE_ROOT . '/plugins/restaurant/Restaurant.php';
require_once SLATE_ROOT . '/plugins/restaurant/includes/channels.php';

$rdl = ['items' => [], 'groups' => [], 'settings' => [], 'maxOrder' => 0, 'maxCustomer' => 0, 'maxNote' => 0, 'proc' => null, 'state' => '', 'port' => 0,
        'slug' => 'rdl-' . bin2hex(random_bytes(3)), 'canon' => 'rdc-' . bin2hex(random_bytes(3)), 'secret' => 'chl-synthetic-01'];

$rdlSet = function (string $key, ?string $value) use (&$rdl): void {
    if (!array_key_exists($key, $rdl['settings'])) $rdl['settings'][$key] = Database::setting($key);
    if ($value === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
    else Database::setSetting($key, $value);
};
$rdlFixture = fn(string $name): array => json_decode((string) file_get_contents(SLATE_ROOT . '/tests/fixtures/deliverect/' . $name), true);
/** Send a Deliverect webhook the way the public route does: raw body + Deliverect's HMAC header. */
$rdlSend = function (array $payload, ?string $secret = null, ?array $headers = null) use (&$rdl): array {
    $raw = json_encode($payload);
    $headers = $headers ?? ['X-Server-Authorization-Hmac-Sha256' => hash_hmac('sha256', $raw, $secret ?? $rdl['secret'])];
    return RestaurantChannels::intake($rdl['slug'], $raw, $headers);
};
$rdlCalls = function () use (&$rdl): array {
    $f = $rdl['state'] . '/requests.log';
    return is_file($f) ? array_map(fn($l) => json_decode($l, true), file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
};
$rdlStatusCalls = fn(): array => array_values(array_filter($rdlCalls(), fn($c) => str_starts_with((string)$c['path'], '/orderStatus/')));
$rdlTokenCalls  = fn(): int => count(array_filter($rdlCalls(), fn($c) => $c['path'] === '/oauth/token'));
$rdlIntake = fn(string $ext): ?array => Database::row("SELECT * FROM restaurant_channel_orders WHERE channel = ? AND external_order_id = ?", [$rdl['slug'], $ext]) ?: null;

try {
    $rdlSet('restaurant.currency', 'USD');
    $rdlSet('restaurant.service_charge_auto', '0');
    $manifest = json_decode((string) file_get_contents(SLATE_ROOT . '/plugins/restaurant/plugin.json'), true);
    (new Restaurant('restaurant', $manifest, SLATE_ROOT . '/plugins/restaurant'))->boot();
    $tid = current_tenant_id();
    $rdl['maxOrder']    = (int) Database::value("SELECT COALESCE(MAX(id),0) FROM restaurant_orders");
    $rdl['maxCustomer'] = (int) Database::value("SELECT COALESCE(MAX(id),0) FROM restaurant_customers");
    Notifications::ensureSchema();
    $rdl['maxNote']     = (int) Database::value("SELECT COALESCE(MAX(id),0) FROM slate_notifications");

    // The stand-in for Deliverect's API.
    $rdl['state'] = sys_get_temp_dir() . '/slate-fake-deliverect-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($rdl['state'], 0700, true);
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $rdl['port'] = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $rdl['proc'] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $rdl['port'], SLATE_ROOT . '/tests/fixtures/fake-channel.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $rdl['state'] . '/server.out', 'a'], 2 => ['file', $rdl['state'] . '/server.out', 'a']],
        $pipes, null, ['FAKE_CHANNEL_STATE' => $rdl['state'], 'PATH' => (string) getenv('PATH')]);
    for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $rdl['port'], $en, $es, 0.1); if ($c) { fclose($c); break; } usleep(100000); }

    $item = function (string $name, int $price) use (&$rdl, $tid): int {
        return $rdl['items'][] = Database::insert('restaurant_items', ['tenant_id' => $tid, 'name' => $name, 'slug' => 'rdl-' . bin2hex(random_bytes(4)),
            'price_cents' => $price, 'tax_rate' => 10, 'is_active' => 1]);
    };
    $brisket = $item('RDL Brisket', 1800);
    $tea     = $item('RDL Tea', 300);
    $rdl['groups'][] = $g = Database::insert('restaurant_modifier_groups', ['tenant_id' => $tid, 'name' => 'RDL Sauce', 'min_select' => 0, 'max_select' => 2]);
    $hot = Database::insert('restaurant_modifiers', ['tenant_id' => $tid, 'group_id' => $g, 'name' => 'Hot', 'price_delta_cents' => 50]);
    $cup = Database::insert('restaurant_modifiers', ['tenant_id' => $tid, 'group_id' => $g, 'name' => 'Cup', 'price_delta_cents' => 25]);
    Database::insert('restaurant_item_modifier_groups', ['tenant_id' => $tid, 'item_id' => $brisket, 'group_id' => $g]);

    // A Deliverect channel (staging secret = the channelLink ID) and a canonical one with the same menu map, to compare.
    RestaurantChannels::saveChannel($rdl['slug'], ['name' => 'RDL Deliverect', 'is_enabled' => 0, 'kind' => 'deliverect']);
    RestaurantChannels::saveChannel($rdl['canon'], ['name' => 'RDL Canonical', 'is_enabled' => 0]);
    foreach ([$rdl['slug'], $rdl['canon']] as $s) {
        try { RestaurantChannels::setSecret($s, $rdl['secret']); }
        catch (\RuntimeException $e) {   // no APP_SECRET here: store the test secret plain; the same reader understands it
            Database::update('restaurant_channels', ['secret' => $rdl['secret']], 'tenant_id = ? AND slug = ?', [$tid, $s]);
        }
        RestaurantChannels::saveChannel($s, ['name' => $s === $rdl['slug'] ? 'RDL Deliverect' : 'RDL Canonical', 'is_enabled' => 1]);
        RestaurantChannels::saveMapping($s, 'BRISKET-PL', $brisket, ['SAUCE-HOT' => ['type' => 'modifier', 'id' => $hot], 'SAUCE-CUP' => ['type' => 'modifier', 'id' => $cup]]);
        RestaurantChannels::saveMapping($s, 'TEA-SW', $tea);
    }
    // API keys written directly (plain values the reader understands), pointing at the stand-in.
    Database::update('restaurant_channels', ['config' => json_encode(['env' => 'staging', 'api_base' => 'http://127.0.0.1:' . $rdl['port'],
        'client_id' => 'cid_test', 'client_secret' => 'cs_test_not_real'])], 'tenant_id = ? AND slug = ?', [$tid, $rdl['slug']]);

    unit('deliverect: the channel type resolves to the Deliverect adapter; others keep the canonical one', function () use (&$rdl): void {
        assert_true(RestaurantChannels::adapter($rdl['slug']) instanceof RestaurantDeliverectAdapter);
        assert_true(RestaurantChannels::adapter($rdl['canon']) instanceof RestaurantChannelBaseAdapter);
        $threw = false;
        try { RestaurantChannels::saveChannel('rdl-bogus', ['name' => 'x', 'kind' => 'no-such-adapter']); } catch (\RuntimeException $e) { $threw = true; }
        assert_true($threw && !RestaurantChannels::channel('rdl-bogus'), 'an unknown type is refused');
    });

    unit('deliverect: translation reads items, sub-items, customer, address, times and the food total', function () use ($rdlFixture): void {
        $c = (new RestaurantDeliverectAdapter())->toCanonical($rdlFixture('order-delivery.json'));
        assert_eq(['65f0c0ffee0000000000d001', 'delivery', '2026-11-02T18:30:00Z', '2026-11-02T18:50:00Z'], [$c['external_order_id'], $c['type'], $c['placed_at'], $c['ready_at']]);
        assert_eq(['name' => 'Sam Testwell', 'phone' => '+15550100123', 'email' => 'sam.testwell@example.test'], $c['customer']);
        assert_eq(['line1' => '42 Fixture Lane', 'line2' => 'Apt 3', 'city' => 'Testville', 'postal_code' => '00042'], $c['delivery_address']);
        assert_eq([['external_sku' => 'BRISKET-PL', 'qty' => 2, 'modifiers' => [['external_sku' => 'SAUCE-HOT']], 'notes' => 'extra bark'],
                   ['external_sku' => 'TEA-SW', 'qty' => 1, 'modifiers' => [], 'notes' => '']], $c['items']);
        // 48.49 paid − 2.99 delivery − 1.50 service − 2.00 tip − 3.00 driver tip + 5.00 channel discount = 44.00 of food and tax.
        assert_eq(['total_cents' => 4400], $c['totals']);
        foreach (['Deliverect #UE812', 'Courier: Uber Eats', 'Ring twice', 'No cutlery'] as $want) assert_true(str_contains($c['notes'], $want), $want . ' in ' . $c['notes']);

        $p = (new RestaurantDeliverectAdapter())->toCanonical($rdlFixture('order-pickup.json'));
        assert_eq(['pickup', null, false, true], [$p['type'], $p['delivery_address'], $p['paid'], $c['paid']]);
        assert_eq([['external_sku' => 'SAUCE-HOT'], ['external_sku' => 'SAUCE-CUP']], $p['items'][0]['modifiers'], 'nested sub-items flatten onto the item');
        assert_eq('2× Sauce cup', $p['items'][0]['notes'], 'a repeated sub-item is written on the line');
        foreach (['TEST ORDER', 'Not paid: collect $20.90'] as $want) assert_true(str_contains($p['notes'], $want), $want . ' in ' . $p['notes']);

        $three = (new RestaurantDeliverectAdapter())->toCanonical(['_id' => 'x', 'decimalDigits' => 3, 'payment' => ['amount' => 12340], 'items' => []]);
        assert_eq(1234, $three['totals']['total_cents'], 'three decimals scale to cents');
        assert_eq(['external_order_id' => '65f0c0ffee0000000000d001', 'action' => 'cancel'],
            array_intersect_key((new RestaurantDeliverectAdapter())->toCanonical($rdlFixture('cancel.json')), ['external_order_id' => 1, 'action' => 1]), 'status 100 is a cancel');
    });

    unit('deliverect: only Deliverect\'s HMAC header is accepted; a refused delivery changes nothing and the channel stays "never heard from"', function () use (&$rdl, $rdlSend, $rdlFixture): void {
        $o = $rdlFixture('order-delivery.json');
        [$code] = $rdlSend($o, 'chl-wrong');
        assert_eq(401, $code, 'wrong secret');
        $raw = json_encode($o);
        [$code] = $rdlSend($o, null, ['X-Channel-Signature' => RestaurantChannels::sign($raw, $rdl['secret'])]);
        assert_eq(401, $code, 'the canonical header is not Deliverect\'s');
        [$code] = $rdlSend($o, null, []);
        assert_eq(401, $code, 'no header');
        assert_eq(0, (int) Database::value("SELECT COUNT(*) FROM restaurant_channel_orders WHERE channel = ?", [$rdl['slug']]));
        assert_eq(null, RestaurantChannels::channel($rdl['slug'])['last_verified_at'], 'no "connected" state before a verified webhook');
    });

    unit('deliverect: a signed order is created once, the same as the canonical order it translates to', function () use (&$rdl, $rdlSend, $rdlFixture): void {
        $o = $rdlFixture('order-delivery.json');
        [$code, $body] = $rdlSend($o);
        assert_eq(200, $code, json_encode($body));
        assert_true(RestaurantChannels::channel($rdl['slug'])['last_verified_at'] !== null, 'verified');
        $ours = Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$body['order_id']]);
        assert_eq(['channel', $rdl['slug'], '65f0c0ffee0000000000d001', 'delivery', 4000, 400, 4400, null],
            [$ours['source'], $ours['channel'], $ours['external_ref'], $ours['type'], (int)$ours['subtotal_cents'], (int)$ours['tax_cents'], (int)$ours['total_cents'], $ours['flag_reason']]);
        assert_true(str_contains((string)$ours['notes'], 'Deliver to: 42 Fixture Lane, Apt 3, Testville, 00042'), (string)$ours['notes']);
        assert_eq(1, (int) Database::value("SELECT channel_paid FROM restaurant_channel_orders WHERE order_id = ?", [$body['order_id']]), 'the marketplace collected payment');

        $canon = (new RestaurantDeliverectAdapter())->toCanonical($o);
        $canon['external_order_id'] = 'RDC-1';
        $raw = json_encode($canon);
        [$cc, $cb] = RestaurantChannels::intake($rdl['canon'], $raw, RestaurantChannels::sign($raw, $rdl['secret']));
        assert_eq(200, $cc, json_encode($cb));
        $theirs = Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$cb['order_id']]);
        $lines = fn(int $id) => Database::rows("SELECT oi.item_id, oi.qty, oi.unit_price_cents, oi.notes,
                (SELECT GROUP_CONCAT(m.name_snapshot ORDER BY m.name_snapshot) FROM restaurant_order_item_modifiers m WHERE m.order_item_id = oi.id) AS mods
              FROM restaurant_order_items oi WHERE oi.order_id = ? ORDER BY oi.id", [$id]);
        assert_eq($lines((int)$cb['order_id']), $lines((int)$body['order_id']), 'same lines as the canonical path');
        assert_eq([$theirs['type'], $theirs['subtotal_cents'], $theirs['tax_cents'], $theirs['total_cents'], $theirs['notes'], $theirs['requested_time']],
                  [$ours['type'], $ours['subtotal_cents'], $ours['tax_cents'], $ours['total_cents'], $ours['notes'], $ours['requested_time']], 'same order');

        [$again, $rb] = $rdlSend($o);
        assert_eq([200, true, $body['order_no']], [$again, $rb['replay'], $rb['order_no']], 'Deliverect retrying the webhook gets the first answer');
    });

    unit('deliverect: fulfilment goes back as Deliverect status codes with a bearer token, one token for every call', function () use (&$rdl, $rdlStatusCalls, $rdlTokenCalls, $rdlIntake): void {
        $oid = (int) $rdlIntake('65f0c0ffee0000000000d001')['order_id'];
        $no = (string) Database::value("SELECT order_no FROM restaurant_orders WHERE id = ?", [$oid]);
        foreach (['accepted', 'preparing', 'ready', 'completed'] as $st) RestaurantAPI::setFulfillment($oid, $st);
        $calls = $rdlStatusCalls();
        assert_eq(4, count($calls), json_encode($calls));
        assert_eq(1, $rdlTokenCalls(), 'the token is fetched once and reused');
        $tok = $calls[0]['authorization'];
        assert_true(str_starts_with($tok, 'Bearer tok_test_'), $tok);
        $codes = [];
        foreach ($calls as $c) {
            assert_eq(['POST', '/orderStatus/65f0c0ffee0000000000d001', $tok], [$c['method'], $c['path'], $c['authorization']]);
            $b = json_decode($c['body'], true);
            assert_eq(['65f0c0ffee0000000000d001', $no], [$b['orderId'], $b['receiptId']]);
            assert_true((bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $b['timeStamp']), $b['timeStamp']);
            $codes[] = $b['status'];
        }
        assert_eq([20, 50, 70, 90], $codes);
        assert_eq(4, (int) Database::value("SELECT COUNT(*) FROM restaurant_channel_events WHERE channel_order_id = ? AND http_status = 200 AND error IS NULL", [(int)$rdlIntake('65f0c0ffee0000000000d001')['id']]), 'each logged');
        $cfg = RestaurantChannels::config($rdl['slug']);
        assert_true(!isset($cfg['token']) || !str_contains(json_encode($cfg), 'tok_test_'), 'a stored token is never in clear');
    });

    unit('deliverect: an unmapped PLU rejects the order and reports Failed (120) to Deliverect; after mapping, retry creates it', function () use (&$rdl, $rdlSend, $rdlFixture, $rdlStatusCalls, $rdlIntake, $tea, $brisket): void {
        $before = count($rdlStatusCalls());
        [$code, $body] = $rdlSend($rdlFixture('order-unmapped.json'));
        assert_eq([422, ['WINGS-6']], [$code, $body['unmapped']], json_encode($body));
        $calls = array_slice($rdlStatusCalls(), $before);
        assert_eq(1, count($calls), 'one Failed update');
        $b = json_decode($calls[0]['body'], true);
        assert_eq(['/orderStatus/65f0c0ffee0000000000d003', 120, ''], [$calls[0]['path'], $b['status'], $b['receiptId']]);
        assert_true(str_contains($b['reason'], 'WINGS-6'), $b['reason']);
        assert_true((bool) Database::value("SELECT 1 FROM slate_notifications WHERE id > ? AND title LIKE '%65f0c0ffee0000000000d003%'", [$rdl['maxNote']]), 'staff notified');

        RestaurantChannels::saveMapping($rdl['slug'], 'WINGS-6', $brisket);
        [$code, $body] = RestaurantChannels::retry((int)$rdlIntake('65f0c0ffee0000000000d003')['id']);
        assert_eq(200, $code, json_encode($body));
        assert_eq($before + 1, count($rdlStatusCalls()), 'no second Failed once it is accepted');
    });

    unit('deliverect: a repeated sub-item is taken once, written on the line, and the order is flagged for its total', function () use ($rdlSend, $rdlFixture): void {
        [$code, $body] = $rdlSend($rdlFixture('order-pickup.json'));
        assert_eq(200, $code, json_encode($body));
        $o = Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$body['order_id']]);
        assert_eq('takeout', $o['type']);
        assert_true($o['flag_reason'] !== null && str_contains((string)$o['flag_reason'], '$20.90'), (string)$o['flag_reason']);
        $line = Database::row("SELECT * FROM restaurant_order_items WHERE order_id = ?", [$body['order_id']]);
        assert_eq(['2× Sauce cup', 2], [$line['notes'], (int) Database::value("SELECT COUNT(*) FROM restaurant_order_item_modifiers WHERE order_item_id = ?", [(int)$line['id']])]);
        assert_true(str_contains((string)$o['notes'], 'Not paid: collect $20.90'), (string)$o['notes']);
        assert_eq(0, (int) Database::value("SELECT channel_paid FROM restaurant_channel_orders WHERE order_id = ?", [$body['order_id']]), 'still to collect');
    });

    unit('deliverect: a channel cancel voids the order and confirms with 110; repeats and completed orders are handled', function () use (&$rdl, $rdlSend, $rdlFixture, $rdlStatusCalls, $rdlIntake): void {
        $cancel = $rdlFixture('cancel.json');
        $cancel['_id'] = '65f0c0ffee0000000000d002';
        $before = count($rdlStatusCalls());
        [$code, $body] = $rdlSend($cancel);
        assert_eq([200, true], [$code, $body['cancelled']], json_encode($body));
        $oid = (int) $rdlIntake('65f0c0ffee0000000000d002')['order_id'];
        $o = Database::row("SELECT status, fulfillment_status FROM restaurant_orders WHERE id = ?", [$oid]);
        assert_eq(['voided', 'cancelled'], [$o['status'], $o['fulfillment_status']]);
        $calls = array_slice($rdlStatusCalls(), $before);
        assert_eq([1, 110], [count($calls), json_decode($calls[0]['body'], true)['status']], 'cancel confirmed once');
        [$code, $body] = $rdlSend($cancel);
        assert_eq([200, true], [$code, $body['replay']], 'a repeated cancel is a no-op');
        assert_eq($before + 1, count($rdlStatusCalls()));
        assert_eq(409, RestaurantChannels::retry((int)$rdlIntake('65f0c0ffee0000000000d002')['id'])[0], 'a cancelled order is not retried');

        [$code] = $rdlSend($rdlFixture('cancel.json'));
        assert_eq(409, $code, 'the completed delivery order cannot be cancelled');
        assert_eq('completed', Database::value("SELECT fulfillment_status FROM restaurant_orders WHERE id = ?", [(int)$rdlIntake('65f0c0ffee0000000000d001')['order_id']]));

        $early = $rdlFixture('cancel.json'); $early['_id'] = '65f0c0ffee0000000000d009';
        [$code] = $rdlSend($early);
        assert_eq(200, $code, 'a cancel before the order is recorded');
        $late = $rdlFixture('order-pickup.json'); $late['_id'] = '65f0c0ffee0000000000d009';
        $orders = (int) Database::value("SELECT COUNT(*) FROM restaurant_orders WHERE id > ?", [$rdl['maxOrder']]);
        [$code] = $rdlSend($late);
        assert_eq(422, $code, 'the order arriving after its cancel is refused');
        assert_eq($orders, (int) Database::value("SELECT COUNT(*) FROM restaurant_orders WHERE id > ?", [$rdl['maxOrder']]), 'and nothing is created');
    });

    unit('deliverect: without API keys, or when the token call fails, the update is logged as not sent', function () use (&$rdl, $rdlIntake): void {
        $tid = current_tenant_id();
        $cfg = RestaurantChannels::config($rdl['slug']);
        $intake = $rdlIntake('65f0c0ffee0000000000d003');
        $oid = (int)$intake['order_id'];
        $last = fn() => Database::row("SELECT * FROM restaurant_channel_events WHERE channel_order_id = ? ORDER BY id DESC LIMIT 1", [(int)$intake['id']]);

        Database::update('restaurant_channels', ['config' => json_encode(['env' => 'staging', 'api_base' => $cfg['api_base']])], 'tenant_id = ? AND slug = ?', [$tid, $rdl['slug']]);
        RestaurantAPI::setFulfillment($oid, 'accepted');
        assert_eq(['accepted', null, 'Deliverect API keys not set'], [$last()['status'], $last()['http_status'], $last()['error']]);

        Database::update('restaurant_channels', ['config' => json_encode(['env' => 'staging', 'api_base' => $cfg['api_base'], 'client_id' => 'cid_test', 'client_secret' => 'cs_test_not_real'])],
            'tenant_id = ? AND slug = ?', [$tid, $rdl['slug']]);
        file_put_contents($rdl['state'] . '/token-status', '401');
        (function () { self::$tokens = []; })->call(new RestaurantDeliverectAdapter());   // forget this process's cached token
        RestaurantAPI::setFulfillment($oid, 'preparing');
        assert_eq('preparing', $last()['status']);
        assert_true(str_starts_with((string)$last()['error'], 'Deliverect token:'), (string)$last()['error']);
        @unlink($rdl['state'] . '/token-status');
    });
} finally {
    $oids = array_column(Database::rows("SELECT id FROM restaurant_orders WHERE id > ?", [$rdl['maxOrder']]), 'id');
    foreach ($oids as $oid) {
        foreach (array_column(Database::rows("SELECT id FROM restaurant_order_items WHERE order_id = ?", [$oid]), 'id') as $lid) {
            Database::delete('restaurant_order_item_modifiers', 'order_item_id = ?', [$lid]);
        }
        Database::delete('restaurant_order_items', 'order_id = ?', [$oid]);
        Database::delete('restaurant_payments', 'order_id = ?', [$oid]);
        Database::delete('restaurant_orders', 'id = ?', [$oid]);
    }
    foreach ([$rdl['slug'], $rdl['canon'], 'rdl-bogus'] as $s) {
        foreach (array_column(Database::rows("SELECT id FROM restaurant_channel_orders WHERE channel = ?", [$s]), 'id') as $co) Database::delete('restaurant_channel_events', 'channel_order_id = ?', [$co]);
        Database::query("DELETE FROM restaurant_channel_orders WHERE channel = ?", [$s]);
        Database::query("DELETE FROM restaurant_item_mappings WHERE channel = ?", [$s]);
        Database::query("DELETE FROM restaurant_channels WHERE slug = ?", [$s]);
    }
    Database::query("DELETE FROM restaurant_customers WHERE id > ?", [$rdl['maxCustomer']]);
    Database::query("DELETE FROM slate_notifications WHERE id > ?", [$rdl['maxNote']]);
    foreach ($rdl['groups'] as $g) { Database::delete('restaurant_modifiers', 'group_id = ?', [$g]); Database::delete('restaurant_item_modifier_groups', 'group_id = ?', [$g]); Database::delete('restaurant_modifier_groups', 'id = ?', [$g]); }
    foreach ($rdl['items'] as $i) Database::delete('restaurant_items', 'id = ?', [$i]);
    foreach ($rdl['settings'] as $key => $orig) {
        if ($orig === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
        else Database::setSetting($key, $orig);
    }
    if ($rdl['proc']) { proc_terminate($rdl['proc']); proc_close($rdl['proc']); }
    if ($rdl['state']) { array_map('unlink', glob($rdl['state'] . '/*') ?: []); @rmdir($rdl['state']); }
}
