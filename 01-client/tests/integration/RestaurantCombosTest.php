<?php
/**
 * Restaurant — combos (Phase 2 item 2.1, shipped in Phase 3): building a
 * combo and its validation, pricing (combo price + deltas, taxed), slot
 * rules enforced the same way for the POS (addLine) and the storefront
 * cart (validateLineChoices / lineUnitCents), the same totals online and in
 * the POS, and the picks reaching the kitchen ticket and the receipt.
 *
 * Every row it creates is deleted and every setting it changes is restored.
 */

declare(strict_types=1);

require_once SLATE_ROOT . '/plugins/restaurant/Restaurant.php';
require_once SLATE_ROOT . '/plugins/restaurant/includes/receipt.php';

$rcb = ['orders' => [], 'items' => [], 'settings' => [], 'maxCustomer' => 0];

$rcbSet = function (string $key, ?string $value) use (&$rcb): void {
    if (!array_key_exists($key, $rcb['settings'])) $rcb['settings'][$key] = Database::setting($key);
    if ($value === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
    else Database::setSetting($key, $value);
};

try {
    $rcbSet('restaurant.currency', 'USD');
    $rcbSet('restaurant.service_charge_auto', '0');
    $manifest = json_decode((string) file_get_contents(SLATE_ROOT . '/plugins/restaurant/plugin.json'), true);
    (new Restaurant('restaurant', $manifest, SLATE_ROOT . '/plugins/restaurant'))->boot();
    $tid = current_tenant_id();
    $rcb['maxCustomer'] = (int) Database::value("SELECT COALESCE(MAX(id),0) FROM restaurant_customers");
    $item = function (string $name, int $price, array $extra = []) use (&$rcb, $tid): int {
        return $rcb['items'][] = Database::insert('restaurant_items', $extra + [
            'tenant_id' => $tid, 'name' => $name, 'slug' => 'rcb-' . bin2hex(random_bytes(4)),
            'price_cents' => $price, 'tax_rate' => 10, 'is_active' => 1]);
    };
    $plate = $item('RCB Plate', 1500);
    $slaw  = $item('RCB Slaw', 450);
    $mac   = $item('RCB Mac', 500);
    $beans = $item('RCB Beans', 400);
    $tea   = $item('RCB Tea', 300);
    $lemon = $item('RCB Lemonade', 350);
    $gone  = $item('RCB Retired', 300, ['is_active' => 0]);
    $other = $item('RCB Other combo', 900);

    $slots = [
        ['label' => 'Sides', 'min_pick' => 2, 'max_pick' => 2, 'choices' => [
            ['item_id' => $slaw], ['item_id' => $mac, 'price_delta_cents' => 100], ['item_id' => $beans]]],
        ['label' => 'Drink', 'min_pick' => 1, 'max_pick' => 1, 'choices' => [
            ['item_id' => $tea], ['item_id' => $lemon, 'price_delta_cents' => 50]]],
    ];
    // choice id by [slot label, item id]
    $choice = fn(string $slot, int $itemId): int => (int) Database::value(
        "SELECT ch.id FROM restaurant_combo_choices ch JOIN restaurant_combo_slots s ON s.id = ch.slot_id
          WHERE s.combo_item_id = ? AND s.label = ? AND ch.item_id = ?", [$plate, $slot, $itemId]);

    unit('combo setup is validated: no self, no combo inside a combo, active items only, sane picks; nothing half-written', function () use ($plate, $slaw, $gone, $other, $tea, $slots): void {
        $bad = [
            [['label' => 'Sides', 'min_pick' => 1, 'max_pick' => 1, 'choices' => [['item_id' => $plate]]]], 'cannot contain itself',
            [['label' => 'Sides', 'min_pick' => 1, 'max_pick' => 1, 'choices' => [['item_id' => $gone]]]], 'not active',
            [['label' => 'Sides', 'min_pick' => 3, 'max_pick' => 2, 'choices' => [['item_id' => $slaw]]]], 'minimum',
            [['label' => 'Sides', 'min_pick' => 2, 'max_pick' => 2, 'choices' => [['item_id' => $slaw]]]], 'more than the items',
            [['label' => '', 'min_pick' => 1, 'max_pick' => 1, 'choices' => [['item_id' => $slaw]]]], 'name',
            [['label' => 'Sides', 'min_pick' => 1, 'max_pick' => 1, 'choices' => []]], 'at least one',
            [['label' => 'Sides', 'min_pick' => 1, 'max_pick' => 1, 'choices' => [['item_id' => $slaw], ['item_id' => $slaw]]]], 'once per slot',
        ];
        for ($i = 0; $i < count($bad); $i += 2) {
            $msg = '';
            try { RestaurantAPI::saveCombo($plate, $bad[$i]); } catch (\RuntimeException $e) { $msg = $e->getMessage(); }
            assert_true(str_contains($msg, $bad[$i + 1]), 'expected "' . $bad[$i + 1] . '", got "' . $msg . '"');
        }
        assert_eq(0, (int) Database::value("SELECT is_combo FROM restaurant_items WHERE id = ?", [$plate]), 'a refused save writes nothing');
        assert_eq(0, (int) Database::value("SELECT COUNT(*) FROM restaurant_combo_slots WHERE combo_item_id = ?", [$plate]));

        RestaurantAPI::saveCombo($other, [['label' => 'Pick', 'min_pick' => 1, 'max_pick' => 1, 'choices' => [['item_id' => $tea]]]]);
        $msg = '';
        try { RestaurantAPI::saveCombo($plate, [['label' => 'Sides', 'min_pick' => 1, 'max_pick' => 1, 'choices' => [['item_id' => $other]]]]); }
        catch (\RuntimeException $e) { $msg = $e->getMessage(); }
        assert_true(str_contains($msg, 'combos cannot be put inside combos'), $msg);
        $msg = '';
        try { RestaurantAPI::saveCombo($tea, [['label' => 'X', 'min_pick' => 1, 'max_pick' => 1, 'choices' => [['item_id' => $slaw]]]]); }
        catch (\RuntimeException $e) { $msg = $e->getMessage(); }
        assert_true(str_contains($msg, 'inside another combo'), 'an item offered in a combo cannot become one: ' . $msg);

        RestaurantAPI::saveCombo($plate, $slots);
        $got = RestaurantAPI::getComboSlots($plate);
        assert_eq(['Sides', 'Drink'], array_column($got, 'label'));
        assert_eq([3, 2], [count($got[0]['choices']), count($got[1]['choices'])]);
    });

    unit('combo pricing: combo price plus the pick deltas, taxed on the line, times the quantity', function () use (&$rcb, $plate, $mac, $slaw, $lemon, $choice): void {
        $picks = [$choice('Sides', $mac), $choice('Sides', $slaw), $choice('Drink', $lemon)];
        assert_eq(1650, RestaurantAPI::lineUnitCents($plate, [], $picks), '1500 + 100 + 50');
        $o = RestaurantAPI::openOrder(['type' => 'takeout']);
        $rcb['orders'][] = $o;
        RestaurantAPI::addLine($o, ['item_id' => $plate, 'qty' => 2, 'combo' => $picks]);
        $ord = Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$o]);
        assert_eq([3300, 330, 3630], [(int)$ord['subtotal_cents'], (int)$ord['tax_cents'], (int)$ord['total_cents']]);
        $line = Database::row("SELECT * FROM restaurant_order_items WHERE order_id = ?", [$o]);
        $comp = Database::rows("SELECT kind, name_snapshot, price_delta_cents FROM restaurant_order_item_modifiers WHERE order_item_id = ? ORDER BY id", [(int)$line['id']]);
        assert_eq([['component', 'Sides: RCB Slaw', 0], ['component', 'Sides: RCB Mac', 100], ['component', 'Drink: RCB Lemonade', 50]],
            array_map(fn($c) => [$c['kind'], $c['name_snapshot'], (int)$c['price_delta_cents']], $comp));
        assert_eq(150, (int)$line['modifiers_total_cents']);
    });

    unit('combo slot rules are enforced the same for the POS and the storefront cart', function () use (&$rcb, $plate, $mac, $slaw, $tea, $lemon, $beans, $choice): void {
        $cases = [
            [[$choice('Sides', $mac), $choice('Drink', $tea)], 'Choose at least 2 for: Sides'],
            [[$choice('Sides', $mac), $choice('Sides', $slaw)], 'Choose at least 1 for: Drink'],
            [[$choice('Sides', $mac), $choice('Sides', $slaw), $choice('Sides', $beans), $choice('Drink', $tea)], 'Choose at most 2 for: Sides'],
            [[$choice('Sides', $mac), $choice('Sides', $slaw), $choice('Drink', $tea), $choice('Drink', $lemon)], 'Choose at most 1 for: Drink'],
            [[$choice('Sides', $mac), $choice('Sides', $slaw), $choice('Drink', $tea), 999999], 'not part of this combo'],
        ];
        $o = RestaurantAPI::openOrder(['type' => 'takeout']);
        $rcb['orders'][] = $o;
        foreach ($cases as [$picks, $want]) {
            $pos = $cart = '';
            try { RestaurantAPI::addLine($o, ['item_id' => $plate, 'combo' => $picks]); } catch (\RuntimeException $e) { $pos = $e->getMessage(); }
            try { RestaurantAPI::lineUnitCents($plate, [], $picks); } catch (\RuntimeException $e) { $cart = $e->getMessage(); }
            assert_true(str_contains($pos, $want), 'POS: ' . $pos);
            assert_eq($pos, $cart, 'the storefront cart refuses with the same message');
        }
        Database::update('restaurant_items', ['is_86' => 1], 'id = ?', [$tea]);
        $msg = '';
        try { RestaurantAPI::addLine($o, ['item_id' => $plate, 'combo' => [$choice('Sides', $mac), $choice('Sides', $slaw), $choice('Drink', $tea)]]); }
        catch (\RuntimeException $e) { $msg = $e->getMessage(); }
        assert_true(str_contains($msg, 'RCB Tea is sold out'), $msg);
        Database::update('restaurant_items', ['is_86' => 0], 'id = ?', [$tea]);
        assert_eq(0, (int) Database::value("SELECT COUNT(*) FROM restaurant_order_items WHERE order_id = ?", [$o]), 'no refused line was stored');
    });

    unit('combo ordered online and in the POS gives identical totals', function () use (&$rcb, $plate, $mac, $beans, $tea, $choice): void {
        $picks = [$choice('Sides', $mac), $choice('Sides', $beans), $choice('Drink', $tea)];
        $online = RestaurantAPI::createOnlineOrder([['item_id' => $plate, 'qty' => 3, 'combo' => $picks]],
            ['name' => 'RCB Guest', 'phone' => '555-0123-777'], ['type' => 'takeout']);
        $rcb['orders'][] = $online['order_id'];
        $pos = RestaurantAPI::openOrder(['type' => 'takeout']);
        $rcb['orders'][] = $pos;
        RestaurantAPI::addLine($pos, ['item_id' => $plate, 'qty' => 3, 'combo' => $picks]);
        $pick = fn(int $id) => array_map('intval', array_values(array_intersect_key(
            Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$id]), array_flip(['subtotal_cents', 'tax_cents', 'total_cents']))));
        assert_eq([4800, 480, 5280], $pick($online['order_id']), '(1500 + 100) × 3, 10% tax');
        assert_eq($pick($online['order_id']), $pick($pos));
    });

    unit('combo line takes a line discount, splits with its picks, and the kitchen ticket and receipt list the picks', function () use (&$rcb, $plate, $mac, $slaw, $lemon, $choice): void {
        $o = RestaurantAPI::openOrder(['type' => 'dine_in']);
        $rcb['orders'][] = $o;
        $line = RestaurantAPI::addLine($o, ['item_id' => $plate, 'qty' => 2, 'combo' => [$choice('Sides', $mac), $choice('Sides', $slaw), $choice('Drink', $lemon)]]);
        RestaurantAPI::setLineDiscount($line, 'pct', 10, 'RCB staff');
        $ord = Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$o]);
        assert_eq([2970, 297], [(int)$ord['subtotal_cents'], (int)$ord['tax_cents']], '3300 less 10%, taxed after');
        $new = RestaurantAPI::splitLine($line, 1, 2);
        assert_eq(3, (int) Database::value("SELECT COUNT(*) FROM restaurant_order_item_modifiers WHERE order_item_id = ? AND kind = 'component'", [$new]), 'picks copied');
        RestaurantAPI::sendToKitchen($o);
        $ticket = array_values(array_filter(RestaurantAPI::kitchenTickets(), fn($t) => (int)$t['id'] === $o))[0] ?? null;
        assert_true($ticket !== null, 'on the kitchen display');
        assert_eq(['Sides: RCB Slaw', 'Sides: RCB Mac', 'Drink: RCB Lemonade'], $ticket['lines'][0]['modifiers']);
        RestaurantAPI::setKitchenStatus($line, 'ready');
        assert_eq('ready', Database::value("SELECT kitchen_status FROM restaurant_order_items WHERE id = ?", [$line]));
        $html = restaurant_receipt_html(RestaurantAPI::getOrder($o));
        foreach (['RCB Plate', 'Sides: RCB Mac', 'Drink: RCB Lemonade'] as $s) assert_true(str_contains($html, $s), 'receipt shows ' . $s);
    });

    unit('deleting an item removes it from every combo; deleting a combo removes its slots', function () use ($plate, $beans, $other): void {
        RestaurantAPI::deleteItem($beans);
        assert_eq(0, (int) Database::value("SELECT COUNT(*) FROM restaurant_combo_choices WHERE item_id = ?", [$beans]));
        RestaurantAPI::deleteItem($other);
        assert_eq(0, (int) Database::value("SELECT COUNT(*) FROM restaurant_combo_slots WHERE combo_item_id = ?", [$other]));
        RestaurantAPI::saveCombo($plate, []);
        assert_eq([0, 0], [(int) Database::value("SELECT is_combo FROM restaurant_items WHERE id = ?", [$plate]),
            (int) Database::value("SELECT COUNT(*) FROM restaurant_combo_slots WHERE combo_item_id = ?", [$plate])], 'no slots: a plain item again');
    });
} finally {
    foreach ($rcb['orders'] as $oid) {
        foreach (array_column(Database::rows("SELECT id FROM restaurant_order_items WHERE order_id = ?", [$oid]), 'id') as $lid) {
            Database::delete('restaurant_order_item_modifiers', 'order_item_id = ?', [$lid]);
        }
        Database::delete('restaurant_order_items', 'order_id = ?', [$oid]);
        Database::delete('restaurant_payments', 'order_id = ?', [$oid]);
        Database::delete('restaurant_orders', 'id = ?', [$oid]);
    }
    foreach ($rcb['items'] as $iid) {
        foreach (array_column(Database::rows("SELECT id FROM restaurant_combo_slots WHERE combo_item_id = ?", [$iid]), 'id') as $sid) {
            Database::delete('restaurant_combo_choices', 'slot_id = ?', [$sid]);
        }
        Database::delete('restaurant_combo_slots', 'combo_item_id = ?', [$iid]);
        Database::delete('restaurant_combo_choices', 'item_id = ?', [$iid]);
        Database::delete('restaurant_items', 'id = ?', [$iid]);
    }
    Database::query("DELETE FROM restaurant_customers WHERE id > ?", [$rcb['maxCustomer']]);
    foreach ($rcb['settings'] as $key => $orig) {
        if ($orig === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $key]);
        else Database::setSetting($key, $orig);
    }
}
