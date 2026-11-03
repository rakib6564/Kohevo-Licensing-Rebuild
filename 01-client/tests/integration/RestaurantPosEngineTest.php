<?php
/**
 * Restaurant — POS engine (Phase 2): line and percentage discounts, per-check
 * totals, split and merge, comp and reopen, cash tendered, the kitchen
 * display lifecycle, seats, guest lookup and 86-ing from the register.
 *
 * Card payments run against tests/fixtures/fake-stripe.php (see
 * tests/support/fake_stripe.php). Every restaurant row it creates is deleted
 * and every setting it changes is restored.
 */

declare(strict_types=1);

require_once SLATE_ROOT . '/plugins/stripe-payment/StripePaymentAPI.php';
require_once SLATE_ROOT . '/plugins/stripe-payment/StripeTerminalAPI.php';
require_once SLATE_ROOT . '/plugins/restaurant/Restaurant.php';
require_once SLATE_ROOT . '/tests/support/fake_stripe.php';

$rpe = ['orders' => [], 'items' => [], 'tables' => [], 'readers' => [], 'customers' => [], 'settings' => [], 'stripe' => null];

$rpeSet = function (string $key, ?string $value) use (&$rpe): void {
    if (!array_key_exists($key, $rpe['settings'])) $rpe['settings'][$key] = Database::setting($key);
    if ($value === null) {
        Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
    } else {
        Database::setSetting($key, $value);
    }
};
/** Open a check; $lines = [[itemIndex, qty], …]. */
$rpeOrder = function (array $lines, array $args = []) use (&$rpe): int {
    $id = RestaurantAPI::openOrder($args + ['type' => 'takeout']);
    $rpe['orders'][] = $id;
    foreach ($lines as [$i, $q]) RestaurantAPI::addLine($id, ['item_id' => $rpe['items'][$i], 'qty' => $q]);
    return $id;
};
$rpeOrd   = fn(int $id): array => Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$id]);
$rpeLines = fn(int $id): array => Database::rows("SELECT * FROM restaurant_order_items WHERE order_id = ? ORDER BY id", [$id]);

try {
    $rpe['stripe'] = fake_stripe_start();
    $rpeSet('restaurant.currency', 'USD');
    $rpeSet('restaurant.service_charge_auto', '0');
    $manifest = json_decode((string) file_get_contents(SLATE_ROOT . '/plugins/restaurant/plugin.json'), true);
    (new Restaurant('restaurant', $manifest, SLATE_ROOT . '/plugins/restaurant'))->boot();

    $tid = current_tenant_id();
    foreach ([['RPE Brisket', 1800], ['RPE Ribs', 2400], ['RPE Slaw', 450], ['RPE Tea', 300]] as [$name, $price]) {
        $rpe['items'][] = Database::insert('restaurant_items', [
            'tenant_id' => $tid, 'name' => $name, 'slug' => 'rpe-' . bin2hex(random_bytes(4)),
            'price_cents' => $price, 'tax_rate' => 10, 'is_active' => 1,
        ]);
    }
    $rpe['tables'][] = Database::insert('restaurant_tables', ['tenant_id' => $tid, 'label' => 'RPE-1', 'seats' => 4]);
    $rpe['tables'][] = Database::insert('restaurant_tables', ['tenant_id' => $tid, 'label' => 'RPE-2', 'seats' => 2]);
    $rpe['readers'][] = Database::insert('restaurant_readers', [
        'tenant_id' => $tid, 'label' => 'RPE reader', 'stripe_reader_id' => 'tmr_fake_rpe',
    ]);

    unit('restaurant 0.4.0 migration: discount, comp, kitchen-time and cash-tendered columns exist', function (): void {
        $cols = fn(string $t) => array_column(Database::rows(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t]),
            'COLUMN_NAME');
        foreach (['discount_fixed_cents', 'discount_pct', 'discount_reason', 'comp_cents', 'comp_reason'] as $c) {
            assert_true(in_array($c, $cols('restaurant_orders'), true), 'orders.' . $c);
        }
        foreach (['discount_cents', 'discount_pct', 'sent_at', 'ready_at', 'served_at'] as $c) {
            assert_true(in_array($c, $cols('restaurant_order_items'), true), 'order_items.' . $c);
        }
        assert_true(in_array('tendered_cents', $cols('restaurant_payments'), true), 'payments.tendered_cents');
    });

    unit('line discounts (amount and percent) lower the line, and tax is on what is left', function () use ($rpeOrder, $rpeOrd, $rpeLines): void {
        $o = $rpeOrder([[0, 1], [1, 1]]);                       // 1800 + 2400
        [$a, $b] = $rpeLines($o);
        RestaurantAPI::setLineDiscount((int)$a['id'], 'amount', 500, 'Burnt ends short');
        RestaurantAPI::setLineDiscount((int)$b['id'], 'pct', 25, 'Staff');
        [$a, $b] = $rpeLines($o);
        assert_eq(1300, (int)$a['line_total_cents']);
        assert_eq(500, (int)$a['discount_cents']);
        assert_eq(1800, (int)$b['line_total_cents']);
        assert_eq('Staff', $b['discount_reason']);
        $ord = $rpeOrd($o);
        assert_eq(3100, (int)$ord['subtotal_cents']);
        assert_eq(310, (int)$ord['tax_cents'], 'tax on the discounted lines');
        assert_eq(3410, (int)$ord['total_cents']);

        RestaurantAPI::setLineDiscount((int)$a['id'], 'amount', 99999, 'too much');
        assert_eq(0, (int)$rpeLines($o)[0]['line_total_cents'], 'a line discount never goes below zero');
        RestaurantAPI::setLineDiscount((int)$a['id'], 'none', 0);
        assert_eq(1800, (int)$rpeLines($o)[0]['line_total_cents'], 'removed');
    });

    unit('percentage order discount replaces a fixed one and keeps its reason; the fixed amount survives a smaller subtotal', function () use ($rpeOrder, $rpeOrd, $rpeLines): void {
        $o = $rpeOrder([[0, 2]]);                               // 3600
        RestaurantAPI::setDiscountPct($o, 10, 'Happy hour');
        $ord = $rpeOrd($o);
        assert_eq(360, (int)$ord['discount_cents']);
        assert_eq('Happy hour', $ord['discount_reason']);
        assert_eq(3600 - 360 + 360, (int)$ord['total_cents'], 'tax 10% of 3600 stays on the lines');

        RestaurantAPI::setDiscount($o, 3000, 'Manager');
        RestaurantAPI::setLineQty((int)$rpeLines($o)[0]['id'], 1);  // subtotal drops to 1800
        assert_eq(1800, (int)$rpeOrd($o)['discount_cents'], 'capped at the subtotal');
        RestaurantAPI::setLineQty((int)$rpeLines($o)[0]['id'], 2);
        assert_eq(3000, (int)$rpeOrd($o)['discount_cents'], 'and comes back: the typed-in amount is kept');
        assert_eq(0.0, (float)$rpeOrd($o)['discount_pct']);
    });

    unit('acceptance: split a 4-line check into two, pay one in cash and one by card, both settle and the table frees', function () use ($rpeOrder, $rpeOrd, $rpeLines, &$rpe): void {
        $o = $rpeOrder([[0, 1], [1, 1], [2, 1], [3, 1]], ['type' => 'dine_in', 'table_id' => $rpe['tables'][0], 'guest_count' => 2]);
        assert_eq('seated', Database::value("SELECT status FROM restaurant_tables WHERE id = ?", [$rpe['tables'][0]]));
        RestaurantAPI::setDiscountPct($o, 10, 'Promo');
        $lines = $rpeLines($o);
        $two = RestaurantAPI::nextCheckNo($o);
        assert_eq(2, $two);
        RestaurantAPI::moveLineToCheck((int)$lines[1]['id'], $two);
        RestaurantAPI::moveLineToCheck((int)$lines[3]['id'], $two);

        $order  = RestaurantAPI::getOrder($o);
        $checks = $order['checks'];
        assert_eq(2, count($checks));
        assert_eq((int)$order['total_cents'], $checks[0]['total'] + $checks[1]['total'], 'checks add up to the order');
        assert_eq((int)$order['discount_cents'], $checks[0]['discount'] + $checks[1]['discount'], 'discount shared out exactly');
        assert_eq(2250, $checks[0]['subtotal']);
        assert_eq(2700, $checks[1]['subtotal']);

        $pid = RestaurantAPI::recordCashPayment($o, $checks[0]['balance'], 0, 5000, 1);
        $pay = Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$pid]);
        assert_eq(1, (int)$pay['check_no']);
        assert_eq(5000 - $checks[0]['balance'], (int)$pay['change_cents'], 'change recorded');
        assert_eq('open', $rpeOrd($o)['status'], 'one check paid, order still open');

        assert_throws(\RuntimeException::class, fn() => RestaurantAPI::moveLineToCheck((int)$lines[0]['id'], 2),
            'a paid check is locked');

        $res = RestaurantAPI::beginTerminalPayment($o, $rpe['readers'][0], $checks[1]['balance'], 300, 2);
        assert_true($res['ok'], $res['error'] ?? '');
        StripeTerminalAPI::simulatePresentCard('tmr_fake_rpe');
        assert_eq('paid', RestaurantAPI::pollTerminalPayment($res['payment_id'])['state']);
        assert_eq(2, (int)Database::value("SELECT check_no FROM restaurant_payments WHERE id = ?", [$res['payment_id']]));

        $ord = $rpeOrd($o);
        assert_eq('paid', $ord['status'], 'both checks settled');
        assert_eq((int)$ord['total_cents'], (int)$ord['paid_cents']);
        $after = RestaurantAPI::getOrder($o)['checks'];
        assert_eq(0, $after[0]['balance'] + $after[1]['balance']);
        assert_eq(300, $after[1]['tip'], 'tip belongs to check 2');
        assert_eq('dirty', Database::value("SELECT status FROM restaurant_tables WHERE id = ?", [$rpe['tables'][0]]), 'table freed');
    });

    unit('split a line by quantity: the new line copies price, modifiers and kitchen state; totals are unchanged', function () use ($rpeOrder, $rpeOrd, $rpeLines): void {
        $o = $rpeOrder([[3, 3]]);                               // 3 teas
        RestaurantAPI::sendToKitchen($o);
        $before = (int)$rpeOrd($o)['total_cents'];
        $line = $rpeLines($o)[0];
        $new  = RestaurantAPI::splitLine((int)$line['id'], 2, 2);
        $lines = $rpeLines($o);
        assert_eq(2, count($lines));
        assert_eq(1, (int)$lines[0]['qty']);
        assert_eq(2, (int)$lines[1]['qty']);
        assert_eq(2, (int)$lines[1]['check_no']);
        assert_eq('sent', $lines[1]['kitchen_status']);
        assert_eq($new, (int)$lines[1]['id']);
        assert_eq($before, (int)$rpeOrd($o)['total_cents']);
        assert_throws(\RuntimeException::class, fn() => RestaurantAPI::splitLine((int)$line['id'], 1, 3), 'cannot split a single unit');
    });

    unit('merge two open checks on the same table; refuse different tables and a source that holds a payment', function () use ($rpeOrder, $rpeOrd, $rpeLines, &$rpe): void {
        $t = $rpe['tables'][1];
        $a = $rpeOrder([[0, 1]], ['type' => 'dine_in', 'table_id' => $t, 'guest_count' => 2]);
        $b = $rpeOrder([[1, 1], [2, 1]], ['type' => 'dine_in', 'table_id' => $t, 'guest_count' => 1]);
        RestaurantAPI::sendToKitchen($b);
        RestaurantAPI::mergeOrders($a, $b);
        assert_eq(3, count($rpeLines($a)));
        assert_eq(0, count($rpeLines($b)));
        assert_eq('voided', $rpeOrd($b)['status']);
        assert_true(str_contains((string)$rpeOrd($b)['notes'], 'Merged into #'), 'source points at the target');
        assert_eq(3, (int)$rpeOrd($a)['guest_count']);
        assert_eq('sent', $rpeOrd($a)['status'], 'a fired line keeps the merged check sent');
        assert_eq((1800 + 2400 + 450) * 110 / 100, (int)$rpeOrd($a)['total_cents']);
        assert_eq([1, 2, 2], array_map(fn($l) => (int)$l['check_no'], $rpeLines($a)), 'merged lines keep their own check');

        $c = $rpeOrder([[0, 1]], ['type' => 'dine_in', 'table_id' => $rpe['tables'][0]]);
        assert_throws(\RuntimeException::class, fn() => RestaurantAPI::mergeOrders($a, $c), 'different tables');
        $d = $rpeOrder([[3, 1]], ['type' => 'dine_in', 'table_id' => $t]);
        RestaurantAPI::recordCashPayment($d, 100);
        assert_throws(\RuntimeException::class, fn() => RestaurantAPI::mergeOrders($a, $d), 'source with a payment');
    });

    unit('close refuses an underpaid check; comp closes it with the amount and reason; reopen undoes the comp', function () use ($rpeOrder, $rpeOrd): void {
        $o = $rpeOrder([[0, 1]]);                               // 1980
        RestaurantAPI::recordCashPayment($o, 1000);
        assert_throws(\RuntimeException::class, fn() => RestaurantAPI::closeOrder($o), 'underpaid');
        assert_eq('open', $rpeOrd($o)['status']);
        assert_throws(\RuntimeException::class, fn() => RestaurantAPI::compOrder($o, '  '), 'a comp needs a reason');

        RestaurantAPI::compOrder($o, 'Long wait');
        $ord = $rpeOrd($o);
        assert_eq('closed', $ord['status']);
        assert_eq(980, (int)$ord['comp_cents']);
        assert_eq('Long wait', $ord['comp_reason']);

        RestaurantAPI::reopenOrder($o, 'Added dessert');
        $ord = $rpeOrd($o);
        assert_eq('open', $ord['status']);
        assert_eq(0, (int)$ord['comp_cents']);
        assert_null($ord['closed_at']);
        assert_throws(\RuntimeException::class, fn() => RestaurantAPI::reopenOrder($o), 'only paid or closed');

        $paid = $rpeOrder([[3, 1]]);
        RestaurantAPI::recordCashPayment($paid, 330);
        RestaurantAPI::closeOrder($paid);
        assert_eq('closed', $rpeOrd($paid)['status'], 'a covered check closes');
    });

    unit('cash tendered: change is stored; less than amount plus tip is refused', function () use ($rpeOrder): void {
        $o = $rpeOrder([[2, 1]]);                               // 495
        assert_throws(\RuntimeException::class, fn() => RestaurantAPI::recordCashPayment($o, 495, 100, 500));
        $pid = RestaurantAPI::recordCashPayment($o, 495, 100, 1000);
        $p = Database::row("SELECT * FROM restaurant_payments WHERE id = ?", [$pid]);
        assert_eq(1000, (int)$p['tendered_cents']);
        assert_eq(405, (int)$p['change_cents']);
    });

    unit('kitchen display: new → sent → ready → served, with times; illegal steps refused; ticket leaves when served', function () use ($rpeOrder, $rpeLines): void {
        $o = $rpeOrder([[0, 1], [3, 2]], ['type' => 'dine_in']);
        $ticket = fn() => array_values(array_filter(RestaurantAPI::kitchenTickets(), fn($t) => (int)$t['id'] === $o));
        assert_eq([], $ticket(), 'not on the display before it is sent');
        RestaurantAPI::sendToKitchen($o);
        $l = $rpeLines($o);
        assert_true($l[0]['sent_at'] !== null, 'sent_at stamped');
        $t = $ticket();
        assert_eq(1, count($t));
        assert_eq(2, count($t[0]['lines']));

        assert_throws(\RuntimeException::class, fn() => RestaurantAPI::setKitchenStatus((int)$l[0]['id'], 'served'), 'sent cannot jump to served');
        RestaurantAPI::setKitchenStatus((int)$l[0]['id'], 'ready');
        RestaurantAPI::setKitchenStatus((int)$l[0]['id'], 'sent');
        assert_null($rpeLines($o)[0]['ready_at'], 'recall clears ready time');
        assert_eq(2, RestaurantAPI::bumpOrder($o, 'ready'));
        assert_true($rpeLines($o)[1]['ready_at'] !== null);
        assert_eq(2, RestaurantAPI::bumpOrder($o, 'served'));
        $l = $rpeLines($o);
        assert_eq('served', $l[0]['kitchen_status']);
        assert_true($l[1]['served_at'] !== null);
        assert_eq([], $ticket(), 'served tickets leave the display');
    });

    unit('seats, guest lookup and 86 from the register', function () use ($rpeOrder, $rpeLines, &$rpe): void {
        $o = $rpeOrder([[0, 1]], ['type' => 'dine_in']);
        $line = (int)$rpeLines($o)[0]['id'];
        RestaurantAPI::setLineSeat($line, 3);
        assert_eq(3, (int)$rpeLines($o)[0]['seat']);
        RestaurantAPI::setLineSeat($line, null);
        assert_null($rpeLines($o)[0]['seat']);

        $cid = RestaurantAPI::findOrCreateCustomer('Rpe Guestperson', '+1555019' . random_int(1000, 9999));
        $rpe['customers'][] = $cid;
        $found = RestaurantAPI::searchCustomers('Guestperson');
        assert_true(in_array($cid, array_map('intval', array_column($found, 'id')), true), 'found by name');
        assert_eq([], RestaurantAPI::searchCustomers('%'), 'too short / wildcard alone finds nothing');
        RestaurantAPI::updateOrderMeta($o, ['customer_id' => $cid, 'notes' => 'Birthday']);
        $ord = RestaurantAPI::getOrder($o);
        assert_eq('Rpe Guestperson', $ord['customer_name']);
        assert_eq('Birthday', $ord['notes']);

        RestaurantAPI::set86($rpe['items'][1], true);
        assert_throws(\RuntimeException::class, fn() => RestaurantAPI::addLine($o, ['item_id' => $rpe['items'][1]]), '86d item refused');
        RestaurantAPI::set86($rpe['items'][1], false);
        RestaurantAPI::addLine($o, ['item_id' => $rpe['items'][1]]);
        assert_eq(2, count($rpeLines($o)));
    });

    unit('floor map lists tables with their open checks', function () use ($rpeOrder, &$rpe): void {
        $o = $rpeOrder([[3, 1]], ['type' => 'dine_in', 'table_id' => $rpe['tables'][1]]);
        $map = RestaurantAPI::floorMap();
        $t = array_values(array_filter($map, fn($r) => (int)$r['id'] === $rpe['tables'][1]))[0] ?? null;
        assert_true($t !== null, 'table on the map');
        assert_true(in_array($o, array_map(fn($x) => (int)$x['id'], $t['orders']), true), 'its open check is listed');
        RestaurantAPI::setTableStatus($rpe['tables'][1], 'dirty');
        RestaurantAPI::setTableStatus($rpe['tables'][1], 'bogus');
        assert_eq('dirty', Database::value("SELECT status FROM restaurant_tables WHERE id = ?", [$rpe['tables'][1]]));
    });
} finally {
    foreach ($rpe['orders'] as $oid) {
        foreach (array_column(Database::rows("SELECT id FROM restaurant_order_items WHERE order_id = ?", [$oid]), 'id') as $lid) {
            Database::delete('restaurant_order_item_modifiers', 'order_item_id = ?', [$lid]);
        }
        Database::delete('restaurant_order_items', 'order_id = ?', [$oid]);
        Database::delete('restaurant_refunds', 'order_id = ?', [$oid]);
        Database::delete('restaurant_payments', 'order_id = ?', [$oid]);
        Database::delete('restaurant_orders', 'id = ?', [$oid]);
    }
    foreach ($rpe['items'] as $id) Database::delete('restaurant_items', 'id = ?', [$id]);
    foreach ($rpe['tables'] as $id) Database::delete('restaurant_tables', 'id = ?', [$id]);
    foreach ($rpe['readers'] as $id) Database::delete('restaurant_readers', 'id = ?', [$id]);
    foreach ($rpe['customers'] as $id) Database::delete('restaurant_customers', 'id = ?', [$id]);
    foreach ($rpe['settings'] as $key => $orig) {
        if ($orig === null) {
            Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
        } else {
            Database::setSetting($key, $orig);
        }
    }
    if ($rpe['stripe']) fake_stripe_stop($rpe['stripe']);
}
