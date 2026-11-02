<?php
/**
 * Restaurant — reports by channel and courier fees (integrations, 0.6.0).
 * Seeds channel orders and courier jobs on two days far from any other data
 * (2032-04-14 and 2032-04-15) and checks Sales by channel and Courier
 * deliveries against figures worked out from the rows themselves: paid
 * orders per channel, a voided (cancelled) channel order, an order whose
 * channel was deleted, and courier jobs in every state, of which only
 * delivered and under-way ones may cost money.
 *
 * Every row it creates is deleted and every setting it changes is restored.
 */

declare(strict_types=1);

require_once SLATE_ROOT . '/plugins/restaurant/Restaurant.php';
require_once SLATE_ROOT . '/plugins/restaurant/includes/reports.php';
require_once SLATE_ROOT . '/plugins/restaurant/includes/delivery.php';

$rrc = ['orders' => [], 'items' => [], 'channels' => [], 'jobs' => [], 'settings' => []];

$rrcSet = function (string $key, ?string $value) use (&$rrc): void {
    if (!array_key_exists($key, $rrc['settings'])) $rrc['settings'][$key] = Database::setting($key);
    if ($value === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
    else Database::setSetting($key, $value);
};

try {
    $rrcSet('restaurant.currency', 'USD');
    $rrcSet('restaurant.service_charge_auto', '0');
    $manifest = json_decode((string) file_get_contents(SLATE_ROOT . '/plugins/restaurant/plugin.json'), true);
    (new Restaurant('restaurant', $manifest, SLATE_ROOT . '/plugins/restaurant'))->boot();
    $tid = current_tenant_id();
    $tag = bin2hex(random_bytes(3));

    $rrc['items'][] = $ribs = Database::insert('restaurant_items', ['tenant_id' => $tid, 'name' => 'RRC Ribs', 'slug' => 'rrc-' . $tag,
        'price_cents' => 2400, 'tax_rate' => 10, 'is_active' => 1]);
    $chA = 'rrc-a-' . $tag; $chB = 'rrc-b-' . $tag; $gone = 'rrc-gone-' . $tag;
    $rrc['channels'][] = Database::insert('restaurant_channels', ['tenant_id' => $tid, 'slug' => $chA, 'name' => 'RRC Marketplace A', 'is_enabled' => 1]);
    $rrc['channels'][] = Database::insert('restaurant_channels', ['tenant_id' => $tid, 'slug' => $chB, 'name' => 'RRC Marketplace B', 'is_enabled' => 1]);

    /** A channel order opened on $day: paid unless $void. Returns [id, total − comp]. */
    $order = function (string $slug, string $day, int $qty, bool $void = false) use (&$rrc, $ribs, $tid): array {
        $id = RestaurantAPI::openOrder(['type' => 'delivery', 'server_id' => null]);
        $rrc['orders'][] = $id;
        RestaurantAPI::addLine($id, ['item_id' => $ribs, 'qty' => $qty]);
        Database::update('restaurant_orders', ['source' => 'channel', 'channel' => $slug, 'opened_at' => $day . ' 12:00:00'], 'id = ? AND tenant_id = ?', [$id, $tid]);
        $total = (int) Database::value("SELECT total_cents FROM restaurant_orders WHERE id = ?", [$id]);
        if ($void) Database::update('restaurant_orders', ['status' => 'voided'], 'id = ?', [$id]);
        else RestaurantAPI::recordCashPayment($id, $total);
        return [$id, $total];
    };
    $job = function (int $orderId, string $provider, string $status, ?int $fee, string $day) use (&$rrc, $tid): void {
        $rrc['jobs'][] = Database::insert('restaurant_delivery_jobs', ['tenant_id' => $tid, 'order_id' => $orderId, 'provider' => $provider, 'attempt' => 1,
            'external_id' => 'rrc-' . bin2hex(random_bytes(5)), 'status' => $status, 'fee_cents' => $fee, 'currency' => 'USD', 'created_at' => $day . ' 13:00:00']);
    };

    [$a1, $ta1] = $order($chA, '2032-04-15', 1);
    [$a2, $ta2] = $order($chA, '2032-04-15', 2);
    [$a0, $ta0] = $order($chA, '2032-04-14', 1);
    [$b1]       = $order($chB, '2032-04-15', 1, true);
    [$g1, $tg1] = $order($gone, '2032-04-15', 3);

    $job($a1, 'doordash_drive', 'delivered', 975, '2032-04-15');
    $job($a2, 'uber_direct', 'picked_up', 899, '2032-04-15');
    $job($a2, 'uber_direct', 'cancelled', 500, '2032-04-15');
    $job($g1, 'doordash_drive', 'quoted', 700, '2032-04-15');
    $job($g1, 'doordash_drive', 'failed', null, '2032-04-15');
    $job($a0, 'uber_direct', 'delivered', 800, '2032-04-14');

    $cur  = RestaurantReports::summary('2032-04-15', '2032-04-15');
    $prev = RestaurantReports::summary('2032-04-14', '2032-04-14');

    unit('reports: sales by channel split channel orders by the channel they came through', function () use ($cur, $prev, $chA, $chB, $gone, $ta1, $ta2, $ta0, $tg1): void {
        assert_eq(['name' => 'RRC Marketplace A', 'orders' => 2, 'sales' => $ta1 + $ta2, 'cancelled' => 0], $cur['channels'][$chA]);
        assert_eq(['name' => 'RRC Marketplace A', 'orders' => 1, 'sales' => $ta0, 'cancelled' => 0], $prev['channels'][$chA]);
        assert_eq(['name' => 'RRC Marketplace B', 'orders' => 0, 'sales' => 0, 'cancelled' => 1], $cur['channels'][$chB], 'a voided channel order is a cancellation, not a sale');
        assert_eq(['name' => $gone, 'orders' => 1, 'sales' => $tg1, 'cancelled' => 0], $cur['channels'][$gone], 'a deleted channel still shows, by its slug');
        assert_true($cur['sources']['channel']['sales'] >= $ta1 + $ta2 + $tg1, 'the per-channel figures sit inside the channel source total');
    });

    unit('reports: courier fees count delivered and under-way jobs only, by provider', function () use ($cur, $prev): void {
        assert_eq(['delivered' => 1, 'active' => 0, 'fees' => 975], $cur['couriers']['doordash_drive'], 'quoted and failed DoorDash jobs cost nothing');
        assert_eq(['delivered' => 0, 'active' => 1, 'fees' => 899], $cur['couriers']['uber_direct'], 'the cancelled Uber job is left out');
        assert_eq([975 + 899, 2], [$cur['courier_fees'], $cur['courier_jobs']]);
        assert_eq([800, 1], [$prev['courier_fees'], $prev['courier_jobs']]);
        assert_true($cur['net_sales'] === $cur['order_totals'] - $cur['comps'] - $cur['refunds'], 'courier fees are not taken out of net sales');
    });

    unit('reports: the channel and courier tables (and their CSV) read the same figures', function () use ($cur, $prev, $ta1, $ta2, $ta0): void {
        $t = RestaurantReports::tables($cur, $prev);
        $row = array_values(array_filter($t['channels']['rows'], fn($r) => $r[0] === 'RRC Marketplace A'))[0];
        assert_eq(['RRC Marketplace A', 2, $ta1 + $ta2, (int) round(($ta1 + $ta2) / 2), 0, $ta0, RestaurantReports::change($ta1 + $ta2, $ta0)], $row);
        $byName = array_column($t['couriers']['rows'], null, 0);
        assert_eq(['DoorDash', 1, 0, 975, 0, null], $byName['DoorDash']);
        assert_eq(['Uber', 0, 1, 899, 800, RestaurantReports::change(899, 800)], $byName['Uber']);
        $sumRow = $t['summary']['rows'][array_search('courier_fees', $t['summary']['keys'], true)];
        assert_eq([1874, 800], [$sumRow[1], $sumRow[2]]);
        $csv = RestaurantReports::tableCsv($t['channels']);
        assert_true(str_contains($csv, 'RRC Marketplace A') && str_contains($csv, number_format(($ta1 + $ta2) / 100, 2, '.', '')), $csv);
    });
} finally {
    foreach ($rrc['jobs'] as $j) Database::delete('restaurant_delivery_jobs', 'id = ?', [$j]);
    foreach ($rrc['orders'] as $oid) {
        foreach (array_column(Database::rows("SELECT id FROM restaurant_order_items WHERE order_id = ?", [$oid]), 'id') as $lid) {
            Database::delete('restaurant_order_item_modifiers', 'order_item_id = ?', [$lid]);
        }
        Database::delete('restaurant_order_items', 'order_id = ?', [$oid]);
        Database::delete('restaurant_payments', 'order_id = ?', [$oid]);
        Database::delete('restaurant_orders', 'id = ?', [$oid]);
    }
    foreach ($rrc['channels'] as $c) Database::delete('restaurant_channels', 'id = ?', [$c]);
    foreach ($rrc['items'] as $i) Database::delete('restaurant_items', 'id = ?', [$i]);
    foreach ($rrc['settings'] as $key => $orig) {
        if ($orig === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
        else Database::setSetting($key, $orig);
    }
}
