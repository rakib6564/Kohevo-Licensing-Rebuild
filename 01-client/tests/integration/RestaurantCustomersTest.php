<?php
/**
 * Restaurant — customer history, archiving, and CSV import / export
 * (Phase 3). History is checked against sums worked out by hand; the import
 * runs a 423-row file built here with the awkward rows real exports have:
 * blank names, a name that is an email, a ".con" typo, names wrapped over
 * two lines, $ amounts, both date formats, three bad rows and two rows that
 * must update rather than duplicate. The round trip runs in a scratch tenant.
 *
 * Every row it creates is deleted and every setting it changes is restored.
 */

declare(strict_types=1);

require_once SLATE_ROOT . '/plugins/restaurant/Restaurant.php';
require_once SLATE_ROOT . '/plugins/restaurant/includes/customers.php';

$rct = ['orders' => [], 'items' => [], 'settings' => [], 'maxCustomer' => 0, 'scratch' => 0, 'tenantBefore' => null];

$rctSet = function (string $key, ?string $value) use (&$rct): void {
    if (!array_key_exists($key, $rct['settings'])) $rct['settings'][$key] = Database::setting($key);
    if ($value === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
    else Database::setSetting($key, $value);
};

/** The 423-row import file. Rows 4 and 5 hold a line break inside the name, so later rows start one line further down each. */
$rctFile = function (): string {
    $fh = fopen('php://temp', 'w+');
    fputcsv($fh, ['name', 'MEMBER ID', 'Email', 'Phone', 'Avg. Spend', 'Total Spend', 'Last Visit', 'First Seen', 'Loyalty points'], ',', '"', '');
    for ($k = 1; $k <= 423; $k++) {
        $name  = $k % 10 === 0 ? '' : 'RCT Guest ' . $k;
        $email = 'rct' . $k . '@example.com';
        $phone = sprintf('(555) 01%d-%04d', intdiv($k, 1000), $k);
        $ref   = $k % 3 === 0 ? '' : 'RCT-M' . $k;
        $avg   = '$20.00'; $total = '$' . number_format(20 * ($k % 5 + 1), 2); // 1–5 visits of 20.00
        $last  = $k % 2 ? '3/9/2024' : '2024-03-09';
        $first = $k % 2 ? '2021-01-15' : '1/15/2021';
        if ($k === 2)  $name = 'rct.name.is.email@example.com';
        if ($k === 3)  $email = 'rct3@gmail.con';
        if ($k === 4)  $name = "Mary\nAnn Wrapped";
        if ($k === 5)  $name = "  Jean   Paul\nDe La  Croix ";
        if ($k === 10) { $total = '$1,234.50'; $avg = '$411.50'; }
        if ($k === 50) $email = 'bob@';
        if ($k === 60) $last = '13/45/2024';
        if ($k === 70) $total = 'about forty';
        if ($k === 100) $phone = '555.010.0099';          // row 99's phone, written another way
        if ($k === 200) { $phone = '+1 555 019 9001'; $total = '100'; $avg = '50'; $last = '2030-12-31'; $first = '5/5/2020'; }
        fputcsv($fh, [$name, $ref, $email, $phone, $avg, $total, $last, $first, (string)($k * 7)], ',', '"', '');
    }
    rewind($fh);
    return (string) stream_get_contents($fh);
};

try {
    $rctSet('restaurant.currency', 'USD');
    $rctSet('restaurant.service_charge_auto', '0');
    $manifest = json_decode((string) file_get_contents(SLATE_ROOT . '/plugins/restaurant/plugin.json'), true);
    (new Restaurant('restaurant', $manifest, SLATE_ROOT . '/plugins/restaurant'))->boot();
    $tid = current_tenant_id();
    $rct['maxCustomer'] = (int) Database::value("SELECT COALESCE(MAX(id),0) FROM restaurant_customers");
    $rct['items'][] = $item = Database::insert('restaurant_items', [
        'tenant_id' => $tid, 'name' => 'RCT Plate', 'slug' => 'rct-' . bin2hex(random_bytes(4)), 'price_cents' => 1000, 'tax_rate' => 10, 'is_active' => 1]);

    // A regular with three paid orders (one partly refunded), plus a void and an open check that never count.
    $cust = RestaurantAPI::findOrCreateCustomer('RCT Regular', '555-019-9001', 'rct.regular@example.com');
    $order = function (int $qty, string $day, ?callable $after = null) use (&$rct, $item, $cust): int {
        $id = RestaurantAPI::openOrder(['type' => 'takeout', 'customer_id' => $cust]);
        $rct['orders'][] = $id;
        RestaurantAPI::addLine($id, ['item_id' => $item, 'qty' => $qty]);
        if ($after) $after($id);
        Database::query("UPDATE restaurant_orders SET opened_at = ?, closed_at = IF(closed_at IS NULL, NULL, ?) WHERE id = ?",
            [$day . ' 12:00:00', $day . ' 13:00:00', $id]);
        return $id;
    };
    $cash = fn(int $total) => fn(int $id) => RestaurantAPI::recordCashPayment($id, $total, 0);
    $order(2, '2031-04-01', $cash(2200));
    $order(3, '2031-04-05', function (int $id) {
        $pid = RestaurantAPI::recordCashPayment($id, 3300, 0);
        RestaurantAPI::refundPayment($pid, 500, 'RCT short');
    });
    $order(1, '2031-04-09', $cash(1100));
    $order(4, '2031-04-10', fn(int $id) => RestaurantAPI::voidOrder($id, 'RCT void'));
    $order(1, '2031-04-11');

    unit('customer history: spend net of refunds, average, last visit and first seen match the hand sums', function () use ($cust): void {
        $c = RestaurantCustomers::get($cust);
        assert_eq(3, $c['orders'], 'the void and the open check do not count');
        assert_eq(6100, $c['spend'], '2200 + (3300 − 500) + 1100');
        assert_eq(2033, $c['avg'], '6100 / 3');
        assert_eq('2031-04-09', $c['last_visit']);
        assert_eq('2031-04-01', $c['first_seen']);
    });

    unit('customer list: search by a phone written another way, and sort by spend and last visit', function () use ($cust): void {
        $hit = RestaurantCustomers::list(['q' => '(555) 019-9001']);
        assert_eq([$cust], array_map(fn($r) => (int)$r['id'], $hit['rows']));
        $other = RestaurantAPI::findOrCreateCustomer('RCT Newcomer', '555-019-9002');
        $bySpend = array_map(fn($r) => (int)$r['id'], RestaurantCustomers::list(['q' => 'RCT', 'sort' => 'spend', 'dir' => 'desc'])['rows']);
        assert_eq([$cust, $other], $bySpend);
        $byVisit = array_map(fn($r) => (int)$r['id'], RestaurantCustomers::list(['q' => 'RCT', 'sort' => 'visit', 'dir' => 'asc'])['rows']);
        assert_eq([$other, $cust], $byVisit, 'never visited sorts first going up');
        assert_eq('deleted', RestaurantCustomers::delete($other), 'no orders: removed outright');
        assert_eq(null, Database::row("SELECT id FROM restaurant_customers WHERE id = ?", [$other]));
    });

    unit('import dry run: 423 rows, every bad row named by its file line, nothing written', function () use ($rctFile): void {
        $before = (int) Database::value("SELECT COUNT(*) FROM restaurant_customers WHERE tenant_id = ?", [current_tenant_id()]);
        $plan = RestaurantCustomers::plan($rctFile());
        assert_null($plan['fatal']);
        assert_eq(420, count($plan['rows']));
        assert_eq([418, 2], [$plan['creates'], $plan['updates']], 'row 100 updates row 99; row 200 updates the regular');
        assert_eq([53, 63, 73], array_column($plan['errors'], 0), 'rows 50, 60, 70 sit two lines lower because of the wrapped names');
        assert_true(str_contains($plan['errors'][0][1], 'bob@'), $plan['errors'][0][1]);
        assert_true(str_contains($plan['errors'][1][1], 'Last Visit'), $plan['errors'][1][1]);
        assert_true(str_contains($plan['errors'][2][1], 'Total Spend'), $plan['errors'][2][1]);
        assert_eq($before, (int) Database::value("SELECT COUNT(*) FROM restaurant_customers WHERE tenant_id = ?", [current_tenant_id()]));
    });

    unit('import writes the good rows: blank and email names kept, typo kept, wrapped names joined, no duplicate phones', function () use ($rctFile, $cust): void {
        $res = RestaurantCustomers::import($rctFile());
        assert_eq(420, $res['written']);
        $tid = current_tenant_id();
        $by = fn(string $ref) => Database::row("SELECT * FROM restaurant_customers WHERE tenant_id = ? AND member_ref = ?", [$tid, $ref]);
        assert_eq('', (string) Database::value("SELECT name FROM restaurant_customers WHERE tenant_id = ? AND email = 'rct20@example.com'", [$tid]), 'blank name stays blank');
        assert_eq('rct20@example.com', RestaurantCustomers::displayName(Database::row("SELECT * FROM restaurant_customers WHERE tenant_id = ? AND email = 'rct20@example.com'", [$tid])), 'shown by email');
        assert_eq('rct.name.is.email@example.com', $by('RCT-M2')['name']);
        assert_eq('rct3@gmail.con', (string) Database::value("SELECT email FROM restaurant_customers WHERE tenant_id = ? AND phone LIKE '%0003'", [$tid]));
        assert_eq('Mary Ann Wrapped', $by('RCT-M4')['name']);
        assert_eq('Jean Paul De La Croix', $by('RCT-M5')['name']);
        assert_eq(null, $by('RCT-M50'), 'the bad rows are not written');
        $phones = array_map(fn($p) => RestaurantCustomers::normalizePhone($p),
            array_column(Database::rows("SELECT phone FROM restaurant_customers WHERE tenant_id = ? AND phone IS NOT NULL", [$tid]), 'phone'));
        assert_eq(count($phones), count(array_unique($phones)), 'no duplicate phones');
        assert_eq('5550100099', RestaurantCustomers::normalizePhone($by('RCT-M100')['phone'] ?? $by('RCT-M100')['phone']), 'row 100 landed on row 99');
        $c10 = RestaurantCustomers::get((int)$by('RCT-M10')['id']);
        assert_eq([123450, 3, 41150, '2024-03-09', '2021-01-15'], [$c10['spend'], $c10['orders'], $c10['avg'], $c10['last_visit'], $c10['first_seen']]);
        $reg = RestaurantCustomers::get($cust);
        assert_eq('RCT-M200', $reg['member_ref']);
        assert_eq('RCT Regular', $reg['name'], 'a blank Name in the file never erases one');
        assert_eq([16100, 5, 3220, '2031-04-09', '2020-05-05'], [$reg['spend'], $reg['orders'], $reg['avg'], $reg['last_visit'], $reg['first_seen']],
            'imported history merges: totals add, last visit the later, first seen the earlier');
    });

    unit('re-importing the same file updates instead of duplicating, and history is not counted twice', function () use ($rctFile, $cust): void {
        $tid = current_tenant_id();
        $count = (int) Database::value("SELECT COUNT(*) FROM restaurant_customers WHERE tenant_id = ?", [$tid]);
        $res = RestaurantCustomers::import($rctFile());
        assert_eq([0, 420], [$res['creates'], $res['updates']]);
        assert_eq($count, (int) Database::value("SELECT COUNT(*) FROM restaurant_customers WHERE tenant_id = ?", [$tid]));
        assert_eq(16100, RestaurantCustomers::get($cust)['spend']);
        $audit = Database::row("SELECT * FROM audit_log WHERE action = 'restaurant.customers_imported' ORDER BY id DESC LIMIT 1");
        assert_true((bool)$audit, 'the import is in the audit log');
    });

    unit('export then import into an empty install gives back the same file', function () use (&$rct): void {
        $a = RestaurantCustomers::exportCsv();
        assert_eq(implode(',', RestaurantCustomers::COLUMNS), str_replace('"', '', strtok($a, "\n")), 'same columns as the import');
        $rct['tenantBefore'] = $GLOBALS['SLATE_TENANT_OVERRIDE'] ?? null;
        $rct['scratch'] = 900000 + random_int(1000, 9999);
        $GLOBALS['SLATE_TENANT_OVERRIDE'] = $rct['scratch'];
        $res = RestaurantCustomers::import($a);
        assert_eq([[], 0], [$res['errors'], $res['updates']], 'an export imports cleanly');
        $b = RestaurantCustomers::exportCsv();
        $rows = fn(string $csv) => array_map(fn($l) => str_getcsv($l, ',', '"', ''), preg_split('/\r?\n(?=(?:[^"]*"[^"]*")*[^"]*$)/', trim($csv)));
        $sorted = function (array $r) { $h = array_shift($r); sort($r); return array_merge([$h], $r); };
        assert_eq($sorted($rows($a)), $sorted($rows($b)));
    });

    unit('deleting a customer with orders archives them: orders and history stay, the guest lookup hides them', function () use (&$rct, $cust): void {
        if ($rct['scratch']) { $GLOBALS['SLATE_TENANT_OVERRIDE'] = $rct['tenantBefore']; }
        assert_eq('archived', RestaurantCustomers::delete($cust));
        assert_eq(5, (int) Database::value("SELECT COUNT(*) FROM restaurant_orders WHERE customer_id = ?", [$cust]), 'orders still point at them');
        $c = RestaurantCustomers::get($cust);
        assert_eq([0, 16100, 3], [(int)$c['is_active'], $c['spend'], count(array_filter(RestaurantCustomers::recentOrders($cust), fn($o) => in_array($o['status'], ['paid','closed'], true)))]);
        assert_eq([], RestaurantAPI::searchCustomers('RCT Regular'));
        assert_eq(0, RestaurantCustomers::list(['q' => 'RCT Regular'])['count']);
        assert_eq(1, RestaurantCustomers::list(['q' => 'RCT Regular', 'archived' => true])['count']);
        $o = RestaurantAPI::getOrder($rct['orders'][0]);
        assert_eq('RCT Regular', $o['customer_name'], 'the order page still names them');
        RestaurantCustomers::restore($cust);
        assert_eq(1, count(RestaurantAPI::searchCustomers('RCT Regular')));
    });
} finally {
    if ($rct['scratch']) {
        $GLOBALS['SLATE_TENANT_OVERRIDE'] = $rct['tenantBefore'];
        Database::query("DELETE FROM restaurant_customers WHERE tenant_id = ?", [$rct['scratch']]);
    }
    foreach ($rct['orders'] as $oid) {
        foreach (array_column(Database::rows("SELECT id FROM restaurant_order_items WHERE order_id = ?", [$oid]), 'id') as $lid) {
            Database::delete('restaurant_order_item_modifiers', 'order_item_id = ?', [$lid]);
        }
        Database::delete('restaurant_order_items', 'order_id = ?', [$oid]);
        Database::delete('restaurant_refunds', 'order_id = ?', [$oid]);
        Database::delete('restaurant_payments', 'order_id = ?', [$oid]);
        Database::delete('restaurant_orders', 'id = ?', [$oid]);
    }
    Database::query("DELETE FROM restaurant_customers WHERE id > ?", [$rct['maxCustomer']]);
    foreach ($rct['items'] as $iid) Database::delete('restaurant_items', 'id = ?', [$iid]);
    foreach ($rct['settings'] as $key => $orig) {
        if ($orig === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
        else Database::setSetting($key, $orig);
    }
}
