<?php
/**
 * Restaurant — customer history, archiving, and CSV import / export
 * (Phase 3). History is checked against sums worked out by hand.
 *
 * The import runs a synthetic 423-row file in the shape of a real SpotOn
 * customer-directory export (checked against one, which stays out of git):
 * "-" for a blank name, email or phone; 91 blank names; two names that are
 * email addresses; one ".con" email typo kept as-is; Last Visit as
 * "4:56 PM 10/31/2026", or "N/A" for the 48 never seen; First Seen as the
 * channel ("ORD Web", "Counter"…); eight-digit member IDs with leading zeros;
 * "$1,234.50" amounts. Two rows share a phone written differently and one
 * matches a customer already here. A small second file carries the bad rows
 * and wrapped names for the line-number report. The round trip runs in a
 * scratch tenant.
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

/** Member ID of fixture row $k: eight digits, leading zeros kept. */
$rctRef = fn(int $k): string => sprintf('%08d', ($k * 2654435761) % 100000000);

/**
 * The 423-row file in SpotOn's shape. Row 99 and row 100 share a phone
 * (written two ways); row 200 has the regular's phone. Rows 4 and 6 hold a
 * name wrapped over two lines.
 */
$rctFile = function () use ($rctRef): string {
    $channels = ['ORD Web', 'Counter', 'Tablet', 'POS', 'SpotOn App', 'DoorDash Online Ordering'];
    $fh = fopen('php://temp', 'w+');
    fputcsv($fh, ['Name', 'Member ID', 'Email', 'Phone', 'Avg. Spend', 'Total Spend', 'Last Visit', 'First Seen'], ',', '"', '');
    $money = fn(int $c) => '$' . number_format($c / 100, 2);
    for ($k = 1; $k <= 423; $k++) {
        $name  = ($k % 4 === 1 && $k <= 361) ? '-' : 'RCT Guest ' . $k;               // 91 blank
        $email = $k % 7 === 3 ? '-' : 'rct' . $k . '@example.com';
        $phone = ($k % 7 === 0 && $k !== 7) ? '-' : sprintf('(555) 210-%04d', $k);    // 59 without
        if ($k >= 376) { $avg = $total = 0; $last = 'N/A'; }                          // 48 never seen
        else {
            $avg = 1000 + ($k * 37) % 9000; $total = $avg * (1 + $k % 3);
            $last = sprintf('%d:%02d %s %02d/%02d/%04d', 1 + $k % 12, $k % 60, $k % 3 ? 'PM' : 'AM', 1 + $k % 12, 1 + $k % 28, 2024 + $k % 3);
        }
        $avgS = $money($avg); $totalS = $money($total);
        if ($k === 162 || $k === 416) { $name = 'rct.fixture' . $k . '@example.com'; $email = $name; }
        if ($k === 410) $email = 'rct410@example.con';
        if ($k === 4)  $name = "Mary\nAnn Wrapped";
        if ($k === 6)  $name = "  Jean   Paul\nDe La  Croix ";
        if ($k === 10) { $totalS = '$1,234.50'; $avgS = '$411.50'; $last = '4:56 PM 10/31/2026'; }
        if ($k === 100) $phone = '555.210.0099';                                         // row 99's phone, written another way
        if ($k === 200) { $phone = '+1 555 019 9001'; $totalS = '$100.00'; $avgS = '$50.00'; $last = '7:05 PM 12/31/2030'; }
        fputcsv($fh, [$name, $rctRef($k), $email, $phone, $avgS, $totalS, $last, $channels[$k % 6]], ',', '"', '');
    }
    rewind($fh);
    return (string) stream_get_contents($fh);
};

/** A short file with the bad rows: a wrapped name pushes every later row down a line. */
$rctMessy = "Name,Member ID,Email,Phone,Avg. Spend,Total Spend,Last Visit,First Seen\n"
    . "RCT Messy One,RCTX1,one@example.com,(555) 220-0001,$20.00,$20.00,1:00 PM 01/02/2026,Counter\n"
    . "\"Pat\nRivera\",RCTX2,pat@example.com,(555) 220-0002,$20.00,$40.00,2026-01-03,POS\n"
    . "RCT Messy Three,RCTX3,bob@,(555) 220-0003,$20.00,$20.00,N/A,POS\n"
    . "RCT Messy Four,RCTX4,four@example.com,-,$20.00,$20.00,1/4/2026,Tablet\n"
    . "RCT Messy Five,RCTX5,five@example.com,(555) 220-0005,$20.00,$20.00,13/45/2026,Tablet\n"
    . "RCT Messy Six,RCTX6,six@example.com,(555) 220-0006,$20.00,about forty,1/6/2026,Tablet\n"
    . "-,RCTX7,-,-,$0.00,$0.00,N/A,POS\n";

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

    unit('import dry run of the SpotOn-shaped file: 423 rows, all good, nothing written', function () use ($rctFile): void {
        $before = (int) Database::value("SELECT COUNT(*) FROM restaurant_customers WHERE tenant_id = ?", [current_tenant_id()]);
        $plan = RestaurantCustomers::plan($rctFile());
        assert_null($plan['fatal']);
        assert_eq([423, [], 0], [count($plan['rows']), $plan['errors'], $plan['skipped']]);
        assert_eq([421, 2], [$plan['creates'], $plan['updates']], 'row 100 updates row 99; row 200 updates the regular');
        $rows = $plan['rows'];
        assert_eq(91, count(array_filter($rows, fn($r) => $r['name'] === '')), '"-" names are blank');
        assert_eq(2, count(array_filter($rows, fn($r) => str_contains($r['name'], '@'))));
        assert_eq(['rct410@example.con'], array_values(array_filter(array_column($rows, 'email'), fn($e) => str_ends_with($e, '.con'))));
        assert_eq(48, count(array_filter($rows, fn($r) => $r['hist_last_visit'] === null)), 'N/A is no last visit');
        assert_eq(59, count(array_filter($rows, fn($r) => $r['phone'] === '')), '"-" phones are blank');
        assert_eq(423, count(array_filter($rows, fn($r) => $r['first_seen_via'] !== '')), 'First Seen is a channel');
        assert_eq($before, (int) Database::value("SELECT COUNT(*) FROM restaurant_customers WHERE tenant_id = ?", [current_tenant_id()]));
    });

    unit('import dry run names every bad row by its file line and reason; the good rows still go', function () use ($rctMessy): void {
        $plan = RestaurantCustomers::plan($rctMessy);
        assert_eq([5, 7, 8], array_column($plan['errors'], 0), 'the wrapped name on lines 3–4 pushes the rest down');
        assert_true(str_contains($plan['errors'][0][1], 'bob@'), $plan['errors'][0][1]);
        assert_true(str_contains($plan['errors'][1][1], 'Last Visit'), $plan['errors'][1][1]);
        assert_true(str_contains($plan['errors'][2][1], 'Total Spend'), $plan['errors'][2][1]);
        assert_eq(['RCTX1', 'RCTX2', 'RCTX4', 'RCTX7'], array_column($plan['rows'], 'member_ref'));
        assert_eq('Pat Rivera', $plan['rows'][1]['name']);
        assert_eq('Member RCTX7', RestaurantCustomers::displayName($plan['rows'][3]), 'nothing but a member ID: shown by it');
    });

    unit('import writes the SpotOn file: blank and email names kept, typo kept, wrapped names joined, no duplicate phones', function () use ($rctFile, $rctRef, $cust): void {
        $res = RestaurantCustomers::import($rctFile());
        assert_eq(423, $res['written']);
        $tid = current_tenant_id();
        $by = fn(int $k) => Database::row("SELECT * FROM restaurant_customers WHERE tenant_id = ? AND member_ref = ?", [$tid, $rctRef($k)]);
        assert_eq('', $by(1)['name'], 'a "-" name is stored blank, not "Guest"');
        assert_eq('rct1@example.com', RestaurantCustomers::displayName($by(1)), 'and shown by email');
        assert_eq('rct.fixture162@example.com', $by(162)['name']);
        assert_eq('rct410@example.con', $by(410)['email']);
        assert_eq([null, null], [$by(3)['email'], $by(14)['phone']], '"-" email and phone are stored empty');
        assert_eq('Mary Ann Wrapped', $by(4)['name']);
        assert_eq('Jean Paul De La Croix', $by(6)['name']);
        $zero = array_values(array_filter(range(1, 423), fn($k) => str_starts_with($rctRef($k), '0')));
        assert_true(count($zero) > 0 && (bool)$by($zero[0]), 'a member ID with a leading zero is kept as written');
        $phones = array_map(fn($p) => RestaurantCustomers::normalizePhone($p),
            array_column(Database::rows("SELECT phone FROM restaurant_customers WHERE tenant_id = ? AND phone IS NOT NULL", [$tid]), 'phone'));
        assert_eq(count($phones), count(array_unique($phones)), 'no duplicate phones');
        $c10 = RestaurantCustomers::get((int)$by(10)['id']);
        assert_eq([123450, 3, 41150, '2026-10-31', 'SpotOn App'], [$c10['spend'], $c10['orders'], $c10['avg'], $c10['last_visit'], $c10['first_seen_via']]);
        $c99 = RestaurantCustomers::get((int)$by(99)['id']);
        $avg = fn(int $k) => 1000 + ($k * 37) % 9000;
        assert_eq([$avg(99) * 1 + $avg(100) * 2, 3], [$c99['spend'], $c99['orders']], 'two rows on one phone: one customer, both histories added');
        assert_eq(null, $by(100), 'row 100 kept row 99\'s member ID');
        $reg = RestaurantCustomers::get($cust);
        assert_eq([$rctRef(200), 'RCT Regular', 'Tablet'], [$reg['member_ref'], $reg['name'], $reg['first_seen_via']], 'blanks filled in, the name kept');
        assert_eq([16100, 5, 3220, '2031-04-09', '2031-04-01'], [$reg['spend'], $reg['orders'], $reg['avg'], $reg['last_visit'], $reg['first_seen']],
            'imported history merges: totals add, last visit the later');
    });

    unit('re-importing the same file updates instead of duplicating, and history is not counted twice', function () use ($rctFile, $cust): void {
        $tid = current_tenant_id();
        $count = (int) Database::value("SELECT COUNT(*) FROM restaurant_customers WHERE tenant_id = ?", [$tid]);
        $res = RestaurantCustomers::import($rctFile());
        assert_eq([0, 423], [$res['creates'], $res['updates']]);
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
