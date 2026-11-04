<?php
/**
 * Restaurant — online order emails (0.8.0): the customer confirmation and the kitchen "new order" email go out once
 * per order, however many times the order is settled (Stripe webhook + return page, a re-sync, a direct call), the
 * optional "ready" email once, and nothing for POS orders, blank settings or customers without an email. Also the
 * pre-order cart rule against real category rows.
 *
 * Mail is captured through RestaurantMail::$transport: nothing is sent. Every address is synthetic (example.test).
 * Every row it creates is deleted and every setting it changes is restored.
 */

declare(strict_types=1);

require_once SLATE_ROOT . '/plugins/restaurant/Restaurant.php';
require_once SLATE_ROOT . '/plugins/restaurant/includes/preorder.php';

$roe = ['orders' => [], 'items' => [], 'cats' => [], 'settings' => [], 'maxCustomer' => 0, 'mail' => []];

$roeSet = function (string $key, ?string $value) use (&$roe): void {
    if (!array_key_exists($key, $roe['settings'])) $roe['settings'][$key] = Database::setting($key);
    if ($value === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
    else Database::setSetting($key, $value);
};

try {
    $roeSet('restaurant.currency', 'USD');
    $roeSet('restaurant.service_charge_auto', '0');
    $roeSet('restaurant.biz_name', 'Test Smokehouse');
    $roeSet('restaurant.addr_line1', '1 Test Street');
    $roeSet('restaurant.online_notify_emails', 'kitchen@example.test, owner@example.test');
    $roeSet('restaurant.online_ready_email', '1');
    $manifest = json_decode((string) file_get_contents(SLATE_ROOT . '/plugins/restaurant/plugin.json'), true);
    (new Restaurant('restaurant', $manifest, SLATE_ROOT . '/plugins/restaurant'))->boot();
    RestaurantMail::$transport = function (string $to, string $subject, string $html, string $toName = '') use (&$roe): bool {
        $roe['mail'][] = ['to' => $to, 'subject' => $subject, 'html' => $html];
        return true;
    };
    $tid = current_tenant_id();
    $roe['maxCustomer'] = (int) Database::value("SELECT COALESCE(MAX(id),0) FROM restaurant_customers");
    $brisket = $roe['items'][] = Database::insert('restaurant_items', [
        'tenant_id' => $tid, 'name' => 'ROE Brisket Plate', 'slug' => 'roe-' . bin2hex(random_bytes(4)),
        'price_cents' => 1800, 'tax_rate' => 10, 'is_active' => 1]);
    $mailTo = function (string $to) use (&$roe): array {
        return array_values(array_filter($roe['mail'], fn(array $m): bool => $m['to'] === $to));
    };

    $online = function (string $email, array $meta = []) use (&$roe, $brisket): array {
        $res = RestaurantAPI::createOnlineOrder([['item_id' => $brisket, 'qty' => 2, 'notes' => 'extra bark']],
            ['name' => 'Test Guest', 'phone' => '555-01' . random_int(10, 99), 'email' => $email], $meta + ['type' => 'takeout', 'requested_time' => 'Wed Nov 25, 11:00 AM']);
        $roe['orders'][] = (int) $res['order_id'];
        return $res;
    };

    unit('order emails: a paid online order emails the customer and each kitchen address once, however often it settles', function () use (&$roe, $online, $mailTo, $tid): void {
        $roe['mail'] = [];
        $res = $online('guest.paid@example.test');
        $oid = (int) $res['order_id'];
        $order = RestaurantAPI::getOrder($oid);
        assert_eq('guest.paid@example.test', $order['contact_email'], 'the order keeps the email typed at checkout');
        assert_eq([], $roe['mail'], 'nothing is sent before payment');

        $pid = Database::insert('restaurant_payments', ['tenant_id' => $tid, 'order_id' => $oid, 'method' => 'other',
            'amount_cents' => (int) $order['total_cents'], 'tip_cents' => 0, 'status' => 'pending']);
        RestaurantAPI::markPaymentPaid($pid);          // the webhook
        RestaurantAPI::markPaymentPaid($pid);          // the return page, a moment later
        RestaurantAPI::syncOrderPayment($oid);         // a re-sync
        RestaurantMail::orderConfirmed($oid);          // and a direct call (a replayed hook)
        Hook::doAction('restaurant_order_paid', $oid);

        assert_eq('paid', (string) Database::value("SELECT status FROM restaurant_orders WHERE id = ?", [$oid]));
        assert_eq(3, count($roe['mail']), 'one customer + two kitchen emails in all');
        $cust = $mailTo('guest.paid@example.test');
        assert_eq(1, count($cust), 'the customer once');
        assert_eq(1, count($mailTo('kitchen@example.test')), 'the kitchen once');
        assert_eq(1, count($mailTo('owner@example.test')), 'the owner once');

        $html = $cust[0]['html'];
        assert_true(str_contains($cust[0]['subject'], (string) $order['order_no']), 'subject has the order number');
        assert_true(str_contains($cust[0]['subject'], 'Test Smokehouse'), 'subject has the restaurant name');
        foreach (['ROE Brisket Plate', 'extra bark', 'Wed Nov 25, 11:00 AM', '1 Test Street', '/order/confirm?token=' . $res['token'], '$39.60', 'Paid'] as $want) {
            assert_true(str_contains($html, $want), 'customer email has ' . $want);
        }
        $k = $mailTo('kitchen@example.test')[0];
        assert_true(str_contains($k['subject'], 'Wed Nov 25, 11:00 AM'), 'kitchen subject has the pickup time');
        foreach (['PAID online', 'Test Guest', '555-01', 'guest.paid@example.test', 'extra bark', '2×'] as $want) {
            assert_true(str_contains($k['html'], $want), 'kitchen email has ' . $want);
        }
        assert_true(Database::value("SELECT customer_emailed_at FROM restaurant_orders WHERE id = ?", [$oid]) !== null, 'stamped');
    });

    unit('order emails: pay at pickup confirms when placed; paying later at the counter sends nothing more', function () use (&$roe, $online, $mailTo): void {
        $roe['mail'] = [];
        $oid = (int) $online('guest.pickup@example.test')['order_id'];
        $sent = RestaurantMail::orderConfirmed($oid);
        assert_eq(['customer' => true, 'kitchen' => 2], $sent);
        $c = $mailTo('guest.pickup@example.test');
        assert_true(str_contains($c[0]['html'], 'Due at pickup'), 'the customer is told to pay at pickup');
        assert_true(str_contains($mailTo('kitchen@example.test')[0]['html'], 'DUE at pickup'), 'the kitchen sees it is unpaid');
        $total = (int) Database::value("SELECT total_cents FROM restaurant_orders WHERE id = ?", [$oid]);
        RestaurantAPI::recordCashPayment($oid, $total);
        assert_eq('paid', (string) Database::value("SELECT status FROM restaurant_orders WHERE id = ?", [$oid]));
        assert_eq(3, count($roe['mail']), 'no second confirmation when paid at pickup');
    });

    unit('order emails: no customer email without an address; no kitchen email when the setting is blank', function () use (&$roe, $online, $roeSet): void {
        $roe['mail'] = [];
        $oid = (int) $online('')['order_id'];
        assert_eq(['customer' => false, 'kitchen' => 2], RestaurantMail::orderConfirmed($oid));
        // A returning customer (same phone) who leaves the email blank this time is not emailed at an old address.
        $phone = (string) Database::value("SELECT c.phone FROM restaurant_orders o JOIN restaurant_customers c ON c.id = o.customer_id WHERE o.id = ?", [$roe['orders'][0]]);
        $back = RestaurantAPI::createOnlineOrder([['item_id' => (int) $roe['items'][0], 'qty' => 1]], ['name' => 'Test Guest', 'phone' => $phone, 'email' => ''], ['type' => 'takeout']);
        $roe['orders'][] = (int) $back['order_id'];
        assert_eq(['customer' => false, 'kitchen' => 2], RestaurantMail::orderConfirmed((int) $back['order_id']));
        $roeSet('restaurant.online_notify_emails', '');
        $oid2 = (int) $online('guest.nokitchen@example.test')['order_id'];
        assert_eq(['customer' => true, 'kitchen' => 0], RestaurantMail::orderConfirmed($oid2));
        $roeSet('restaurant.online_notify_emails', 'kitchen@example.test, owner@example.test');
        assert_eq(['customer' => false, 'kitchen' => 0], RestaurantMail::orderConfirmed($oid2), 'turning the setting back on later does not resend');
        assert_eq(5, count($roe['mail']));
    });

    unit('order emails: POS orders send nothing', function () use (&$roe, $brisket): void {
        $roe['mail'] = [];
        $oid = RestaurantAPI::openOrder(['type' => 'takeout']);
        $roe['orders'][] = $oid;
        RestaurantAPI::addLine($oid, ['item_id' => $brisket, 'qty' => 1]);
        RestaurantAPI::recordCashPayment($oid, (int) Database::value("SELECT total_cents FROM restaurant_orders WHERE id = ?", [$oid]));
        assert_eq(['customer' => false, 'kitchen' => 0], RestaurantMail::orderConfirmed($oid));
        assert_eq([], $roe['mail']);
    });

    unit('order emails: "ready" goes once when staff mark the order ready, and only with the setting on', function () use (&$roe, $online, $mailTo, $roeSet): void {
        $oid = (int) $online('guest.ready@example.test', ['type' => 'delivery', 'notes' => 'Deliver to: 9 Sample Road · ring twice'])['order_id'];
        RestaurantMail::orderConfirmed($oid);
        $first = $mailTo('guest.ready@example.test')[0]['html'];
        assert_true(str_contains($first, '9 Sample Road') && str_contains($first, 'ring twice'), 'delivery address and note split out');
        $roe['mail'] = [];
        RestaurantAPI::setFulfillment($oid, 'preparing');
        assert_eq([], $roe['mail'], 'nothing while preparing');
        RestaurantAPI::setFulfillment($oid, 'ready');
        RestaurantAPI::setFulfillment($oid, 'ready');
        RestaurantAPI::setFulfillment($oid, 'preparing');
        RestaurantAPI::setFulfillment($oid, 'ready');
        $r = $mailTo('guest.ready@example.test');
        assert_eq(1, count($r), 'ready email once, even when moved back and forth');
        assert_true(str_contains($r[0]['subject'], 'is ready'));
        assert_eq(1, count($roe['mail']), 'the kitchen is not emailed about ready');

        $roeSet('restaurant.online_ready_email', '0');
        $roe['mail'] = [];
        $oid2 = (int) $online('guest.noready@example.test')['order_id'];
        RestaurantAPI::setFulfillment($oid2, 'ready');
        assert_eq([], $roe['mail'], 'setting off: no ready email');
        $roeSet('restaurant.online_ready_email', '1');
    });

    unit('pre-order: real category rows keep a pre-order cart apart from regular items', function () use (&$roe, $brisket, $tid): void {
        $now = RestaurantHours::now();
        $cat = $roe['cats'][] = Database::insert('restaurant_menu_categories', [
            'tenant_id' => $tid, 'name' => 'ROE Holiday', 'slug' => 'roe-hol-' . bin2hex(random_bytes(3)), 'is_active' => 1,
            'preorder' => 1, 'preorder_from' => $now->modify('-1 day')->format('Y-m-d H:i:s'),
            'preorder_until' => $now->modify('+5 days')->format('Y-m-d H:i:s'),
            'preorder_dates' => $now->modify('+7 days')->format('Y-m-d') . ' 10:00-12:00']);
        $turkey = $roe['items'][] = Database::insert('restaurant_items', [
            'tenant_id' => $tid, 'category_id' => $cat, 'name' => 'ROE Smoked Turkey', 'slug' => 'roe-' . bin2hex(random_bytes(4)),
            'price_cents' => 6500, 'tax_rate' => 0, 'is_active' => 1]);
        $hours = RestaurantHours::config();
        assert_eq(null, RestaurantPreorder::addError([], $turkey, $hours, $now), 'an open pre-order item goes into an empty cart');
        assert_eq(RestaurantPreorder::mixMessage(), RestaurantPreorder::addError([['item_id' => $brisket]], $turkey, $hours, $now), 'not next to a regular item');
        assert_eq(RestaurantPreorder::mixMessage(), RestaurantPreorder::addError([['item_id' => $turkey]], $brisket, $hours, $now), 'nor the other way round');
        $g = RestaurantPreorder::cartGroup([['item_id' => $turkey], ['item_id' => $turkey]]);
        assert_eq(['preorder', $cat], [$g['kind'], (int) $g['category']['id']]);
        $slots = RestaurantPreorder::slots($g['category'], $hours, $now);
        assert_eq($now->modify('+7 days')->format('Y-m-d') . ' 10:00', $slots[0]['value'], 'only the pickup date is offered');

        Database::update('restaurant_menu_categories', ['preorder_until' => $now->modify('-1 hour')->format('Y-m-d H:i:s')], 'id = ?', [$cat]);
        $closed = Database::row("SELECT * FROM restaurant_menu_categories WHERE id = ?", [$cat]);
        assert_true(!RestaurantPreorder::isOpen($closed, $hours, $now), 'past its until time it is closed');
    });
} finally {
    RestaurantMail::$transport = null;
    foreach ($roe['orders'] as $oid) {
        foreach (array_column(Database::rows("SELECT id FROM restaurant_order_items WHERE order_id = ?", [$oid]), 'id') as $lid) {
            Database::delete('restaurant_order_item_modifiers', 'order_item_id = ?', [$lid]);
        }
        Database::delete('restaurant_order_items', 'order_id = ?', [$oid]);
        Database::delete('restaurant_payments', 'order_id = ?', [$oid]);
        Database::delete('restaurant_orders', 'id = ?', [$oid]);
    }
    foreach ($roe['items'] as $iid) Database::delete('restaurant_items', 'id = ?', [$iid]);
    foreach ($roe['cats'] as $cid) Database::delete('restaurant_menu_categories', 'id = ?', [$cid]);
    Database::query("DELETE FROM restaurant_customers WHERE id > ?", [$roe['maxCustomer']]);
    foreach ($roe['settings'] as $key => $orig) {
        if ($orig === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
        else Database::setSetting($key, $orig);
    }
}
