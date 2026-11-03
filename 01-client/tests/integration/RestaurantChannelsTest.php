<?php
/**
 * Restaurant — channel intake, SKU mapping, status pushback and the menu
 * feed (integrations Part 1). Orders come in through
 * RestaurantChannels::intake() exactly as the public route passes them
 * (raw body + signature header); status callbacks go out to
 * tests/fixtures/fake-channel.php on a loopback port, which records each
 * request so the test can check its signature and body.
 *
 * Every row it creates is deleted and every setting it changes is restored.
 */

declare(strict_types=1);

require_once SLATE_ROOT . '/plugins/restaurant/Restaurant.php';
require_once SLATE_ROOT . '/plugins/restaurant/includes/channels.php';

$rch = ['items' => [], 'groups' => [], 'settings' => [], 'maxOrder' => 0, 'maxCustomer' => 0, 'maxNote' => 0, 'proc' => null, 'state' => '', 'port' => 0,
        'slug' => 'rch-' . bin2hex(random_bytes(3)), 'secret' => '', 'menuEvents' => []];

$rchSet = function (string $key, ?string $value) use (&$rch): void {
    if (!array_key_exists($key, $rch['settings'])) $rch['settings'][$key] = Database::setting($key);
    if ($value === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
    else Database::setSetting($key, $value);
};
/** Send a canonical order the way the public route does: raw JSON body + signature. */
$rchSend = function (array $order, ?string $secret = null) use (&$rch): array {
    $raw = json_encode($order);
    return RestaurantChannels::intake($rch['slug'], $raw, RestaurantChannels::sign($raw, $secret ?? $rch['secret']));
};
$rchCalls = function () use (&$rch): array {
    $f = $rch['state'] . '/requests.log';
    return is_file($f) ? array_map(fn($l) => json_decode($l, true), file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
};
$rchOrders = fn(): int => (int) Database::value("SELECT COUNT(*) FROM restaurant_orders WHERE id > ?", [$rch['maxOrder']]);

try {
    $rchSet('restaurant.currency', 'USD');
    $rchSet('restaurant.service_charge_auto', '0');
    $rchSet('restaurant.feed_token', null);
    $manifest = json_decode((string) file_get_contents(SLATE_ROOT . '/plugins/restaurant/plugin.json'), true);
    (new Restaurant('restaurant', $manifest, SLATE_ROOT . '/plugins/restaurant'))->boot();
    Hook::addAction('restaurant_menu_changed', function ($what, $ref = null) use (&$rch) { $rch['menuEvents'][] = $what; }, 10, 2);
    $tid = current_tenant_id();
    $rch['maxOrder']    = (int) Database::value("SELECT COALESCE(MAX(id),0) FROM restaurant_orders");
    $rch['maxCustomer'] = (int) Database::value("SELECT COALESCE(MAX(id),0) FROM restaurant_customers");
    Notifications::ensureSchema();
    $rch['maxNote']     = (int) Database::value("SELECT COALESCE(MAX(id),0) FROM slate_notifications");

    // The fake channel's status endpoint.
    $rch['state'] = sys_get_temp_dir() . '/slate-fake-channel-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($rch['state'], 0700, true);
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $rch['port'] = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $rch['proc'] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $rch['port'], SLATE_ROOT . '/tests/fixtures/fake-channel.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $rch['state'] . '/server.out', 'a'], 2 => ['file', $rch['state'] . '/server.out', 'a']],
        $pipes, null, ['FAKE_CHANNEL_STATE' => $rch['state'], 'PATH' => (string) getenv('PATH')]);
    for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $rch['port'], $en, $es, 0.1); if ($c) { fclose($c); break; } usleep(100000); }
    $cb = 'http://127.0.0.1:' . $rch['port'] . '/status';

    $item = function (string $name, int $price) use (&$rch, $tid): int {
        return $rch['items'][] = Database::insert('restaurant_items', ['tenant_id' => $tid, 'name' => $name, 'slug' => 'rch-' . bin2hex(random_bytes(4)),
            'price_cents' => $price, 'tax_rate' => 10, 'is_active' => 1]);
    };
    $brisket = $item('RCH Brisket', 1800);
    $tea     = $item('RCH Tea', 300);
    $rch['groups'][] = $g = Database::insert('restaurant_modifier_groups', ['tenant_id' => $tid, 'name' => 'RCH Sauce', 'min_select' => 0, 'max_select' => 1]);
    $hot = Database::insert('restaurant_modifiers', ['tenant_id' => $tid, 'group_id' => $g, 'name' => 'Hot', 'price_delta_cents' => 50]);
    Database::insert('restaurant_item_modifier_groups', ['tenant_id' => $tid, 'item_id' => $brisket, 'group_id' => $g]);

    RestaurantChannels::saveChannel($rch['slug'], ['name' => 'RCH Aggregator', 'is_enabled' => 1, 'callback_url' => $cb]);
    try { $rch['secret'] = RestaurantChannels::rotateSecret($rch['slug']); }
    catch (\RuntimeException $e) {   // no APP_SECRET here: store a plain test secret the same reader understands
        $rch['secret'] = 'chs_test_' . bin2hex(random_bytes(8));
        Database::update('restaurant_channels', ['secret' => $rch['secret']], 'tenant_id = ? AND slug = ?', [$tid, $rch['slug']]);
    }
    RestaurantChannels::saveMapping($rch['slug'], 'BRISKET', $brisket, ['SAUCE-HOT' => ['type' => 'modifier', 'id' => $hot]]);

    $order = fn(string $ext, array $extra = []) => $extra + [
        'external_order_id' => $ext, 'type' => 'delivery',
        'customer' => ['name' => 'RCH Guest', 'phone' => '555-0199-321'],
        'delivery_address' => ['line1' => '1 Test St', 'city' => 'Springfield'],
        'items' => [['external_sku' => 'BRISKET', 'qty' => 2, 'modifiers' => [['external_sku' => 'SAUCE-HOT']], 'notes' => 'extra bark']],
        'totals' => ['total_cents' => 4070],                                    // (1800 + 50) × 2 = 3700 + 10% tax
    ];

    unit('channels migration: tables exist and orders accept the channel source', function (): void {
        foreach (['restaurant_channels', 'restaurant_item_mappings', 'restaurant_channel_orders', 'restaurant_channel_events'] as $t) {
            assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t]), $t);
        }
        $type = (string) Database::value("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurant_orders' AND COLUMN_NAME = 'source'");
        assert_true(str_contains($type, "'channel'") && str_contains($type, "'online'") && str_contains($type, "'pos'"), $type);
    });

    unit('intake: a bad signature or a switched-off channel changes nothing', function () use (&$rch, $rchSend, $rchOrders, $order): void {
        [$code] = $rchSend($order('RCH-BAD'), 'not-the-secret');
        assert_eq(401, $code);
        [$code] = RestaurantChannels::intake($rch['slug'], json_encode($order('RCH-BAD')), '');
        assert_eq(401, $code, 'no signature');
        assert_eq(0, (int) Database::value("SELECT COUNT(*) FROM restaurant_channel_orders WHERE channel = ?", [$rch['slug']]));
        assert_eq(0, $rchOrders());
        [$code] = RestaurantChannels::intake('rch-none', '{}', 'sha256=x');
        assert_eq(404, $code, 'unknown channel');
    });

    unit('intake: a signed order is created once through the order engine, and a replay returns the first answer', function () use (&$rch, $rchSend, $rchOrders, $order): void {
        [$code, $body] = $rchSend($order('RCH-1001'));
        assert_eq(200, $code, json_encode($body));
        assert_true($body['ok'] && $body['order_no'] !== '', 'order number returned');
        $o = Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$body['order_id']]);
        assert_eq(['channel', $rch['slug'], 'RCH-1001', 'delivery', 'new', 3700, 370, 4070],
            [$o['source'], $o['channel'], $o['external_ref'], $o['type'], $o['fulfillment_status'], (int)$o['subtotal_cents'], (int)$o['tax_cents'], (int)$o['total_cents']]);
        assert_true(str_contains((string)$o['notes'], 'Deliver to: 1 Test St, Springfield'), (string)$o['notes']);
        assert_eq(null, $o['flag_reason'], 'totals agree');
        $line = Database::row("SELECT * FROM restaurant_order_items WHERE order_id = ?", [$body['order_id']]);
        assert_eq([2, 'extra bark', 'Hot'], [(int)$line['qty'], $line['notes'],
            Database::value("SELECT name_snapshot FROM restaurant_order_item_modifiers WHERE order_item_id = ?", [(int)$line['id']])]);

        [$code2, $body2] = $rchSend($order('RCH-1001', ['items' => [['external_sku' => 'BRISKET', 'qty' => 9]]]));
        assert_eq([200, $body['order_no'], true], [$code2, $body2['order_no'], $body2['replay']], 'a replay, even with a different body, gives the first answer');
        assert_eq(1, $rchOrders(), 'one order');
    });

    unit('intake: an unmapped SKU rejects the whole order and alerts staff; after mapping, retry creates it', function () use (&$rch, $rchSend, $rchOrders, $order, $tea): void {
        $before = $rchOrders();
        [$code, $body] = $rchSend($order('RCH-1002', ['items' => [['external_sku' => 'BRISKET', 'qty' => 1], ['external_sku' => 'TEA-L', 'qty' => 1]], 'totals' => []]));
        assert_eq([422, ['TEA-L']], [$code, $body['unmapped']], json_encode($body));
        assert_eq($before, $rchOrders(), 'nothing created, not even the mapped line');
        $row = Database::row("SELECT * FROM restaurant_channel_orders WHERE channel = ? AND external_order_id = 'RCH-1002'", [$rch['slug']]);
        assert_eq('rejected', $row['intake_status']);
        assert_true((bool) Database::value("SELECT 1 FROM slate_notifications WHERE id > ? AND title LIKE '%RCH-1002%'", [$rch['maxNote']]), 'staff notified');
        assert_true((bool) Database::value("SELECT 1 FROM audit_log WHERE action = 'restaurant.channel_order_rejected' AND target = 'RCH-1002'"), 'audited');
        [$again] = $rchSend($order('RCH-1002'));
        assert_eq(422, $again, 'a replay keeps the recorded rejection');
        assert_eq(1, RestaurantChannels::unmappedCount($rch['slug']), 'TEA-L shows as to-map');

        RestaurantChannels::saveMapping($rch['slug'], 'TEA-L', $tea);
        assert_eq(0, RestaurantChannels::unmappedCount($rch['slug']));
        [$code, $body] = RestaurantChannels::retry((int)$row['id']);
        assert_eq(200, $code, json_encode($body));
        assert_eq(2, (int) Database::value("SELECT COUNT(*) FROM restaurant_order_items WHERE order_id = ?", [$body['order_id']]));
        [$replay, $rb] = $rchSend($order('RCH-1002'));
        assert_eq([200, $body['order_no']], [$replay, $rb['order_no']], 'once created, a replay gets the created answer');
    });

    unit('intake: an unmapped modifier SKU also rejects; a line the menu refuses (86) leaves no order behind', function () use (&$rch, $rchSend, $rchOrders, $order, $brisket): void {
        [$code, $body] = $rchSend($order('RCH-1003', ['items' => [['external_sku' => 'BRISKET', 'modifiers' => [['external_sku' => 'SAUCE-XXX']]]]]));
        assert_eq([422, ['SAUCE-XXX']], [$code, $body['unmapped']]);
        $before = $rchOrders();
        RestaurantAPI::set86($brisket, true);
        [$code, $body] = $rchSend($order('RCH-1004'));
        RestaurantAPI::set86($brisket, false);
        assert_eq(422, $code);
        assert_true(str_contains($body['error'], "86"), $body['error']);
        assert_eq($before, $rchOrders(), 'the half-built order was rolled back');
    });

    unit('intake: a channel total more than a cent off ours is accepted but flagged and audited', function () use ($rchSend, $order): void {
        [, $ok] = $rchSend($order('RCH-1005', ['totals' => ['total_cents' => 4071]]));
        assert_eq(null, Database::value("SELECT flag_reason FROM restaurant_orders WHERE id = ?", [$ok['order_id']]), 'one cent is rounding');
        [$code, $b] = $rchSend($order('RCH-1006', ['totals' => ['total_cents' => 3900]]));
        assert_eq(200, $code);
        $flag = (string) Database::value("SELECT flag_reason FROM restaurant_orders WHERE id = ?", [$b['order_id']]);
        assert_true(str_contains($flag, '$39.00') && str_contains($flag, '$40.70'), $flag);
        assert_true((bool) Database::value("SELECT 1 FROM audit_log WHERE action = 'restaurant.channel_total_mismatch' AND target = ?", [(string)$b['order_id']]));
    });

    unit('acceptance: a channel order through the kitchen, with a signed status callback at every step', function () use (&$rch, $rchSend, $rchCalls, $order): void {
        [, $b] = $rchSend($order('RCH-2001', ['callback_url' => 'http://127.0.0.1:' . $rch['port'] . '/orders/2001']));
        $id = (int)$b['order_id'];
        $start = count($rchCalls());
        RestaurantAPI::setFulfillment($id, 'accepted');
        RestaurantAPI::sendToKitchen($id);
        assert_true((bool) array_filter(RestaurantAPI::kitchenTickets(), fn($t) => (int)$t['id'] === $id), 'on the kitchen display');
        RestaurantAPI::setFulfillment($id, 'preparing');
        RestaurantAPI::bumpOrder($id, 'ready');
        RestaurantAPI::setFulfillment($id, 'ready');
        RestaurantAPI::setFulfillment($id, 'ready');            // no change: no callback
        RestaurantAPI::setFulfillment($id, 'completed');
        $calls = array_slice($rchCalls(), $start);
        assert_eq(['accepted', 'preparing', 'ready', 'completed'], array_map(fn($c) => json_decode($c['body'], true)['status'], $calls));
        foreach ($calls as $c) {
            assert_eq('/orders/2001', $c['path'], 'the order\'s own callback URL wins');
            assert_eq(RestaurantChannels::sign($c['body'], $rch['secret']), $c['signature'], 'signed with the channel secret');
            $p = json_decode($c['body'], true);
            assert_eq(['RCH-2001', $b['order_no']], [$p['external_order_id'], $p['order_no']]);
        }
        assert_eq(4, (int) Database::value("SELECT COUNT(*) FROM restaurant_channel_events e JOIN restaurant_channel_orders co ON co.id = e.channel_order_id
                                               WHERE co.external_order_id = 'RCH-2001' AND co.channel = ? AND e.http_status = 200", [$rch['slug']]));
    });

    unit('pushback: no URL means no call (logged); a failing channel is logged and never breaks the board', function () use (&$rch, $rchSend, $rchCalls, $order, $tid): void {
        Database::update('restaurant_channels', ['callback_url' => 'http://127.0.0.1:' . $rch['port'] . '/fail?status=503'], 'tenant_id = ? AND slug = ?', [$tid, $rch['slug']]);
        [, $b] = $rchSend($order('RCH-2002'));
        RestaurantAPI::setFulfillment((int)$b['order_id'], 'cancelled');
        assert_eq('cancelled', Database::value("SELECT fulfillment_status FROM restaurant_orders WHERE id = ?", [$b['order_id']]), 'the change stands');
        assert_eq([503, 'HTTP 503'], array_values(array_map(fn($v) => $v === null ? null : (is_numeric($v) ? (int)$v : $v),
            Database::row("SELECT e.http_status, e.error FROM restaurant_channel_events e JOIN restaurant_channel_orders co ON co.id = e.channel_order_id WHERE co.external_order_id = 'RCH-2002' AND co.channel = ?", [$rch['slug']]))));
        assert_false(RestaurantChannels::allowedUrl('http://example.com/hook'), 'plain http only to this machine');
        assert_true(RestaurantChannels::allowedUrl('https://example.com/hook'));
    });

    unit('menu feed: needs the token, carries the menu, and follows an 86 with a new etag; menu changes fire the hook', function () use (&$rch, $brisket): void {
        assert_false(RestaurantChannels::feedAuthorised(''), 'no token yet: closed');
        $t = RestaurantChannels::rotateFeedToken();
        assert_false(RestaurantChannels::feedAuthorised('mft_wrong'));
        assert_true(RestaurantChannels::feedAuthorised($t));
        $a = RestaurantChannels::menuFeed();
        $find = fn(array $f) => array_values(array_filter($f['items'], fn($i) => $i['id'] === $brisket))[0];
        $it = $find($a);
        assert_eq([1800, false], [$it['price_cents'], $it['is_86']]);
        assert_true(in_array((int)$rch['groups'][0], $it['modifier_group_ids'], true), 'linked modifier group');
        $rch['menuEvents'] = [];
        RestaurantAPI::set86($brisket, true);
        $b = RestaurantChannels::menuFeed();
        assert_eq(true, $find($b)['is_86']);
        assert_true($a['etag'] !== $b['etag'], 'etag moved');
        assert_eq(['item_86'], $rch['menuEvents']);
        RestaurantAPI::set86($brisket, false);
    });
} finally {
    $oids = array_column(Database::rows("SELECT id FROM restaurant_orders WHERE id > ?", [$rch['maxOrder']]), 'id');
    foreach ($oids as $oid) {
        foreach (array_column(Database::rows("SELECT id FROM restaurant_order_items WHERE order_id = ?", [$oid]), 'id') as $lid) {
            Database::delete('restaurant_order_item_modifiers', 'order_item_id = ?', [$lid]);
        }
        Database::delete('restaurant_order_items', 'order_id = ?', [$oid]);
        Database::delete('restaurant_payments', 'order_id = ?', [$oid]);
        Database::delete('restaurant_orders', 'id = ?', [$oid]);
    }
    $cos = array_column(Database::rows("SELECT id FROM restaurant_channel_orders WHERE channel = ?", [$rch['slug']]), 'id');
    foreach ($cos as $co) Database::delete('restaurant_channel_events', 'channel_order_id = ?', [$co]);
    Database::query("DELETE FROM restaurant_channel_orders WHERE channel = ?", [$rch['slug']]);
    Database::query("DELETE FROM restaurant_item_mappings WHERE channel = ?", [$rch['slug']]);
    Database::query("DELETE FROM restaurant_channels WHERE slug = ?", [$rch['slug']]);
    Database::query("DELETE FROM restaurant_customers WHERE id > ?", [$rch['maxCustomer']]);
    Database::query("DELETE FROM slate_notifications WHERE id > ?", [$rch['maxNote']]);
    foreach ($rch['groups'] as $g) { Database::delete('restaurant_modifiers', 'group_id = ?', [$g]); Database::delete('restaurant_item_modifier_groups', 'group_id = ?', [$g]); Database::delete('restaurant_modifier_groups', 'id = ?', [$g]); }
    foreach ($rch['items'] as $i) Database::delete('restaurant_items', 'id = ?', [$i]);
    foreach ($rch['settings'] as $key => $orig) {
        if ($orig === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
        else Database::setSetting($key, $orig);
    }
    if ($rch['proc']) { proc_terminate($rch['proc']); proc_close($rch['proc']); }
    if ($rch['state']) { array_map('unlink', glob($rch['state'] . '/*') ?: []); @rmdir($rch['state']); }
}
