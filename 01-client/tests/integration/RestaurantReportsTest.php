<?php
/**
 * Restaurant — reports and payment reconciliation (Phase 3). Seeds a known
 * set of orders, payments and refunds on two days far from any other data
 * (2031-03-09 and 2031-03-10), then checks every headline figure against
 * sums worked out by hand below, including a refunded card payment, a
 * declined one, a comped check, a voided check and an unpaid online order.
 *
 * Card payments run against tests/fixtures/fake-stripe.php. Every row it
 * creates is deleted and every setting it changes is restored.
 */

declare(strict_types=1);

require_once SLATE_ROOT . '/plugins/stripe-payment/StripePaymentAPI.php';
require_once SLATE_ROOT . '/plugins/stripe-payment/StripeTerminalAPI.php';
require_once SLATE_ROOT . '/plugins/restaurant/Restaurant.php';
require_once SLATE_ROOT . '/plugins/restaurant/includes/reports.php';
require_once SLATE_ROOT . '/tests/support/fake_stripe.php';

$rr = ['orders' => [], 'items' => [], 'cats' => [], 'readers' => [], 'customers' => [], 'settings' => [], 'stripe' => null, 'o' => []];

$rrSet = function (string $key, ?string $value) use (&$rr): void {
    if (!array_key_exists($key, $rr['settings'])) $rr['settings'][$key] = Database::setting($key);
    if ($value === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
    else Database::setSetting($key, $value);
};

try {
    $rr['stripe'] = fake_stripe_start();
    $rrSet('restaurant.currency', 'USD');
    $rrSet('restaurant.service_charge_auto', '0');
    $manifest = json_decode((string) file_get_contents(SLATE_ROOT . '/plugins/restaurant/plugin.json'), true);
    (new Restaurant('restaurant', $manifest, SLATE_ROOT . '/plugins/restaurant'))->boot();
    $tid = current_tenant_id();

    $rr['cats']['mains'] = Database::insert('restaurant_menu_categories', ['tenant_id' => $tid, 'name' => 'RR Mains', 'slug' => 'rr-mains-' . bin2hex(random_bytes(3))]);
    $rr['cats']['sides'] = Database::insert('restaurant_menu_categories', ['tenant_id' => $tid, 'name' => 'RR Sides', 'slug' => 'rr-sides-' . bin2hex(random_bytes(3))]);
    $item = function (string $name, int $price, ?int $cat) use (&$rr, $tid): int {
        return $rr['items'][] = Database::insert('restaurant_items', [
            'tenant_id' => $tid, 'category_id' => $cat, 'name' => $name, 'slug' => 'rr-' . bin2hex(random_bytes(4)),
            'price_cents' => $price, 'tax_rate' => 10, 'is_active' => 1]);
    };
    $A = $item('RR Brisket', 1800, $rr['cats']['mains']);
    $B = $item('RR Ribs', 2400, $rr['cats']['mains']);
    $C = $item('RR Slaw', 450, $rr['cats']['sides']);
    $D = $item('RR Tea', 300, null);
    $rr['readers'][] = $reader = Database::insert('restaurant_readers', ['tenant_id' => $tid, 'label' => 'RR reader', 'stripe_reader_id' => 'tmr_fake_rr']);

    $open = function (array $args, array $lines) use (&$rr): int {
        $id = RestaurantAPI::openOrder($args);
        $rr['orders'][] = $id;
        foreach ($lines as [$i, $q]) RestaurantAPI::addLine($id, ['item_id' => $i, 'qty' => $q]);
        return $id;
    };
    $ord  = fn(int $id): array => Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$id]);
    $card = function (int $orderId, int $amount, int $tip, bool $decline = false) use ($reader): int {
        $res = RestaurantAPI::beginTerminalPayment($orderId, $reader, $amount, $tip);
        if (empty($res['ok'])) throw new \Exception('begin failed: ' . ($res['error'] ?? '?'));
        StripePaymentAPI::httpRequest('POST', '/v1/test_helpers/terminal/readers/tmr_fake_rr/present_payment_method',
            $decline ? ['card_present' => ['number' => '4000000000000002']] : []);
        RestaurantAPI::pollTerminalPayment((int)$res['payment_id']);
        return (int)$res['payment_id'];
    };

    // ── 2031-03-10 ──
    // O1 dine-in, 3 guests: 2 brisket + slaw with 10% off the slaw.
    //    lines 3600 + 405, tax 360 + 41 = 401, total 4406; cash with a 6.00 tip → 5006.
    $o1 = $open(['type' => 'dine_in', 'guest_count' => 3], [[$A, 2], [$C, 1]]);
    $slaw = (int) Database::value("SELECT id FROM restaurant_order_items WHERE order_id = ? AND item_id = ?", [$o1, $C]);
    RestaurantAPI::setLineDiscount($slaw, 'pct', 10, 'RR regular');
    RestaurantAPI::recordCashPayment($o1, 4406, 600);
    // O2 takeout: ribs, 4.00 off the order → 2400 − 400 + 240 = 2240; card with a 3.00 tip → 2540, then 5.00 refunded.
    $o2 = $open(['type' => 'takeout'], [[$B, 1]]);
    RestaurantAPI::setDiscount($o2, 400, 'RR promo');
    $p2 = $card($o2, 2240, 300);
    $refund = RestaurantAPI::refundPayment($p2, 500, 'RR cold');
    // O3 online: brisket + tea with a 10% service charge → 2100 + 210 service + 210 tax = 2520, paid online by card.
    $o3 = $open(['type' => 'takeout', 'source' => 'online'], [[$A, 1], [$D, 1]]);
    Database::update('restaurant_orders', ['service_charge_pct' => 10], 'id = ?', [$o3]);
    RestaurantAPI::recalcOrder($o3);
    Database::insert('restaurant_payments', ['tenant_id' => $tid, 'order_id' => $o3, 'method' => 'other', 'amount_cents' => 2520,
        'status' => 'paid', 'stripe_pi_id' => 'pi_rr_online_' . bin2hex(random_bytes(3))]);
    RestaurantAPI::syncOrderPayment($o3);
    RestaurantAPI::setFulfillment($o3, 'completed');
    // O4 voided: never a sale.
    $o4 = $open(['type' => 'takeout'], [[$B, 1]]);
    RestaurantAPI::voidOrder($o4, 'RR mistake');
    // O5 dine-in, 2 guests: slaw 450 + 45 tax = 495, comped in full.
    $o5 = $open(['type' => 'dine_in', 'guest_count' => 2], [[$C, 1]]);
    RestaurantAPI::compOrder($o5, 'RR birthday');
    // O6 online, not paid: not a sale, but an online order in the "new" state.
    $o6 = $open(['type' => 'takeout', 'source' => 'online'], [[$D, 1]]);
    // O8 takeout: tea 300 + 30 tax = 330; one declined card, then a card with a 0.50 tip → 380.
    $o8 = $open(['type' => 'takeout'], [[$D, 1]]);
    $card($o8, 330, 50, true);
    $card($o8, 330, 50);
    // ── 2031-03-09 ── P1 dine-in, 2 guests: ribs 2400 + 240 = 2640 in cash.
    $p1 = $open(['type' => 'dine_in', 'guest_count' => 2], [[$B, 1]]);
    RestaurantAPI::recordCashPayment($p1, 2640, 0);

    $day = [$o1, $o2, $o3, $o4, $o5, $o6, $o8];
    $in  = implode(',', $day);
    Database::query("UPDATE restaurant_orders SET opened_at = '2031-03-10 12:00:00', closed_at = IF(closed_at IS NULL, NULL, '2031-03-10 13:00:00') WHERE id IN ($in)");
    Database::query("UPDATE restaurant_payments SET created_at = '2031-03-10 12:30:00' WHERE order_id IN ($in)");
    Database::query("UPDATE restaurant_refunds SET created_at = '2031-03-10 20:00:00' WHERE order_id IN ($in)");
    Database::query("UPDATE restaurant_orders SET opened_at = '2031-03-09 18:00:00', closed_at = '2031-03-09 19:00:00' WHERE id = ?", [$p1]);
    Database::query("UPDATE restaurant_payments SET created_at = '2031-03-09 18:30:00' WHERE order_id = ?", [$p1]);
    $rr['o'] = compact('o1', 'o2', 'o3', 'o5', 'o8', 'p2');

    unit('reports seed: the checks themselves add up as worked out by hand', function () use ($ord, $o1, $o2, $o3, $o5, $o8, $refund): void {
        assert_eq([5006, 'paid'], [(int)$ord($o1)['total_cents'], $ord($o1)['status']]);
        assert_eq([2540, 'paid', 500], [(int)$ord($o2)['total_cents'], $ord($o2)['status'], (int)$ord($o2)['refunded_cents']]);
        assert_true(!empty($refund['ok']), 'refund ok');
        assert_eq([2520, 'paid', 210], [(int)$ord($o3)['total_cents'], $ord($o3)['status'], (int)$ord($o3)['service_charge_cents']]);
        assert_eq([495, 'closed', 495], [(int)$ord($o5)['total_cents'], $ord($o5)['status'], (int)$ord($o5)['comp_cents']]);
        assert_eq([380, 'paid'], [(int)$ord($o8)['total_cents'], $ord($o8)['status']]);
    });

    unit('reports headline figures for 2031-03-10 match the hand-computed sums', function (): void {
        $s = RestaurantReports::summary('2031-03-10', '2031-03-10');
        $want = [
            'orders' => 5,             // O1 O2 O3 O5 O8 — not the void O4, not the unpaid O6
            'gross_sales' => 9300,     // 3600 + 450 + 2400 + 1800 + 300 + 450 + 300
            'discounts' => 445,        // 45 slaw line + 400 order
            'service' => 210,
            'tax' => 926,              // 401 + 240 + 210 + 45 + 30
            'tips' => 950, 'tips_cash' => 600, 'tips_card' => 350,
            'order_totals' => 10941,   // 5006 + 2540 + 2520 + 495 + 380
            'comps' => 495, 'refunds' => 500, 'refund_count' => 1,
            'net_sales' => 9946,       // 10941 − 495 − 500
            'avg_order' => 2089,       // (10941 − 495) / 5 = 2089.2
            'covers' => 5,             // dine-in guests 3 + 2
            'voided' => 1,
        ];
        foreach ($want as $k => $v) assert_eq($v, $s[$k], $k);
        assert_eq($s['order_totals'], $s['gross_sales'] - $s['discounts'] + $s['service'] + $s['tax'] + $s['tips'], 'totals bridge');
    });

    unit('reports net sales equals the order totals less comps and refunds, and the payment mix reconciles to it', function (): void {
        $s = RestaurantReports::summary('2031-03-10', '2031-03-10');
        $sumTotals = (int) Database::value("SELECT SUM(total_cents) FROM restaurant_orders WHERE status IN ('paid','closed') AND opened_at BETWEEN '2031-03-10 00:00:00' AND '2031-03-10 23:59:59'");
        $sumRefunds = (int) Database::value("SELECT SUM(amount_cents) FROM restaurant_refunds WHERE status = 'succeeded' AND created_at BETWEEN '2031-03-10 00:00:00' AND '2031-03-10 23:59:59'");
        assert_eq($sumTotals - $s['comps'] - $sumRefunds, $s['net_sales']);
        $m = $s['methods'];
        assert_eq(['count' => 1, 'collected' => 5006, 'refunds' => 0, 'net' => 5006], $m['cash']);
        assert_eq(['count' => 2, 'collected' => 2920, 'refunds' => 500, 'net' => 2420], $m['terminal'], 'declined card not counted');
        assert_eq(['count' => 1, 'collected' => 2520, 'refunds' => 0, 'net' => 2520], $m['online']);
        assert_eq(0, $m['other']['count']);
        assert_eq($s['net_sales'], array_sum(array_column($m, 'net')), 'every cent collected less refunds is the net sales (comp collected nothing)');
    });

    unit('reports top items, categories, sources and online fulfilment', function (): void {
        $s = RestaurantReports::summary('2031-03-10', '2031-03-10');
        $pairs = fn(array $rows) => array_map(fn($r) => [$r['name'], $r['qty'], $r['revenue']], $rows);
        assert_eq([['RR Brisket', 3, 5400], ['RR Slaw', 2, 855], ['RR Tea', 2, 600], ['RR Ribs', 1, 2400]], $pairs($s['items']['by_qty']));
        assert_eq([['RR Brisket', 3, 5400], ['RR Ribs', 1, 2400], ['RR Slaw', 2, 855], ['RR Tea', 2, 600]], $pairs($s['items']['by_revenue']));
        assert_eq([['RR Mains', 4, 7800], ['RR Sides', 2, 855], ['No category', 2, 600]], $pairs($s['categories']));
        assert_eq(['pos' => ['orders' => 4, 'sales' => 7926], 'online' => ['orders' => 1, 'sales' => 2520], 'channel' => ['orders' => 0, 'sales' => 0]], $s['sources']);
        assert_eq(1, $s['fulfilment']['completed']);
        assert_eq(1, $s['fulfilment']['new'], 'the unpaid online order');
    });

    unit('reports compare against the previous day, and the CSV carries the on-screen cells', function (): void {
        $p = RestaurantReports::period('custom', '2031-03-10', '2031-03-10');
        assert_eq(['2031-03-09', '2031-03-09'], [$p['prev_from'], $p['prev_to']]);
        $cur  = RestaurantReports::summary($p['from'], $p['to']);
        $prev = RestaurantReports::summary($p['prev_from'], $p['prev_to']);
        assert_eq([1, 2640, 2640, 2], [$prev['orders'], $prev['order_totals'], $prev['net_sales'], $prev['covers']]);
        $t = RestaurantReports::tables($cur, $prev);
        $net = array_search('net_sales', $t['summary']['keys'], true);
        assert_eq([9946, 2640, 276.7], array_slice($t['summary']['rows'][$net], 1, 3), '(9946 − 2640) / 2640');

        foreach ($t as $key => $table) {
            $lines = array_map(fn($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", RestaurantReports::tableCsv($table))));
            assert_eq(count($table['rows']) + 1, count($lines), $key . ' row count');
            foreach ($table['rows'] as $i => $row) {
                foreach ($row as $j => $v) {
                    $type = $table['columns'][$j][1] === 'mixed' ? $table['types'][$i] : $table['columns'][$j][1];
                    assert_eq(RestaurantReports::csvCell($v, $type), $lines[$i + 1][$j], "{$key} row {$i} col {$j}");
                }
            }
        }
        $csv = array_map(fn($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", RestaurantReports::tableCsv($t['summary']))));
        assert_eq(['Net sales', '99.46', '26.40', '276.7'], $csv[$net + 1]);
    });

    unit('reports periods: week, last week, month and custom, each with its previous period', function (): void {
        $w = RestaurantReports::period('this_week', '', '', '2031-03-12');        // a Wednesday
        assert_eq(['2031-03-10', '2031-03-12', '2031-03-03', '2031-03-05'], [$w['from'], $w['to'], $w['prev_from'], $w['prev_to']]);
        $l = RestaurantReports::period('last_week', '', '', '2031-03-12');
        assert_eq(['2031-03-03', '2031-03-09', '2031-02-24', '2031-03-02'], [$l['from'], $l['to'], $l['prev_from'], $l['prev_to']]);
        $m = RestaurantReports::period('this_month', '', '', '2031-03-31');
        assert_eq(['2031-03-01', '2031-03-31', '2031-02-01', '2031-02-28'], [$m['from'], $m['to'], $m['prev_from'], $m['prev_to']]);
        $c = RestaurantReports::period('custom', '2031-03-10', '2031-03-04');     // reversed on purpose
        assert_eq(['2031-03-04', '2031-03-10', '2031-02-25', '2031-03-03'], [$c['from'], $c['to'], $c['prev_from'], $c['prev_to']]);
        $y = RestaurantReports::period('yesterday', '', '', '2031-03-01');
        assert_eq(['2031-02-28', '2031-02-27'], [$y['from'], $y['prev_from']]);
    });

    unit('reconciliation: one day of terminal payments nets to Σ(amount + tip − refunded) over paid ones', function () use (&$rr): void {
        $f = ['from' => '2031-03-10', 'to' => '2031-03-10', 'method' => 'terminal'];
        $res = RestaurantReports::payments($f);
        assert_eq(3, $res['count'], 'two settled and one declined');
        $hand = 0;
        foreach ($res['rows'] as $r) {
            if (in_array($r['status'], ['paid', 'refunded'], true)) $hand += (int)$r['amount_cents'] + (int)$r['tip_cents'] - (int)$r['refunded_cents'];
        }
        assert_eq(2420, $hand);
        assert_eq(['gross' => 2920, 'refunded' => 500, 'net' => 2420, 'settled' => 2], $res['totals']);
        $csv = array_map(fn($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", RestaurantReports::paymentsCsv($f))));
        assert_eq(['29.20', '5.00', '24.20'], array_slice(end($csv), 6, 3), 'CSV totals row');
        assert_eq(4, count($csv) - 1, 'three rows and the totals');
    });

    unit('reconciliation filters: status, source, order number and PaymentIntent id', function () use (&$rr): void {
        $day = ['from' => '2031-03-10', 'to' => '2031-03-10'];
        $partial = RestaurantReports::payments($day + ['status' => 'partial']);
        assert_eq([1, (int)$rr['o']['p2']], [$partial['count'], (int)$partial['rows'][0]['id']]);
        assert_eq('partial', $partial['rows'][0]['state']);
        assert_eq(1, RestaurantReports::payments($day + ['status' => 'failed'])['count']);
        $online = RestaurantReports::payments($day + ['source' => 'online']);
        assert_eq([1, 'online', 2520], [$online['count'], $online['rows'][0]['method'], $online['totals']['net']]);
        $no = (string) Database::value("SELECT order_no FROM restaurant_orders WHERE id = ?", [$rr['o']['o8']]);
        assert_eq(2, RestaurantReports::payments($day + ['q' => $no])['count'], 'both attempts on O8');
        $pi = (string) Database::value("SELECT stripe_pi_id FROM restaurant_payments WHERE id = ?", [$rr['o']['p2']]);
        assert_eq(1, RestaurantReports::payments(['q' => $pi])['count']);
        $all = RestaurantReports::payments($day);
        assert_eq(['gross' => 10446, 'refunded' => 500, 'net' => 9946], array_slice($all['totals'], 0, 3), 'the whole day nets to the report');
    });
} finally {
    foreach ($rr['orders'] as $oid) {
        foreach (array_column(Database::rows("SELECT id FROM restaurant_order_items WHERE order_id = ?", [$oid]), 'id') as $lid) {
            Database::delete('restaurant_order_item_modifiers', 'order_item_id = ?', [$lid]);
        }
        Database::delete('restaurant_order_items', 'order_id = ?', [$oid]);
        Database::delete('restaurant_refunds', 'order_id = ?', [$oid]);
        Database::delete('restaurant_payments', 'order_id = ?', [$oid]);
        Database::delete('restaurant_orders', 'id = ?', [$oid]);
    }
    foreach ($rr['items'] as $iid) Database::delete('restaurant_items', 'id = ?', [$iid]);
    foreach ($rr['cats'] as $cid) Database::delete('restaurant_menu_categories', 'id = ?', [$cid]);
    foreach ($rr['readers'] as $rid) Database::delete('restaurant_readers', 'id = ?', [$rid]);
    foreach ($rr['settings'] as $key => $orig) {
        if ($orig === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
        else Database::setSetting($key, $orig);
    }
    if ($rr['stripe']) fake_stripe_stop($rr['stripe']);
}
