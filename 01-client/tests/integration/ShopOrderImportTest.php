<?php
/**
 * Shop order importer: a WooCommerce order export (synthetic, built here) becomes Shop orders and guest customers.
 * Checks the plan (failed orders left out, customers counted once per email, unknown products named), the import
 * (references, statuses, dates, addresses, pickup, money, coupon, notes and payment details for the owner, lines
 * linked to the imported product and flavour by WooCommerce id and by SKU, one guest customer per email mirrored into
 * Contacts), a second run skipping what is already there, failed orders on request, and an existing account linked
 * rather than changed.
 *
 * Every row written is removed. No network, no email.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/shop/ShopStoreAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/shop/ShopImport.php';
require_once dirname(__DIR__, 2) . '/plugins/shop/ShopOrderImport.php';

$soiDomain = '@order-import.example.test';
$soiFetch = static fn(string $url): array => ['ok' => false, 'error' => 'not fetched in tests'];
$soiOrder = static function (int $id, string $status, string $email, array $lines, array $more = []): array {
    return $more + [
        'id' => $id, 'number' => (string) $id, 'status' => $status, 'currency' => 'USD', 'prices_include_tax' => false,
        'date_created_gmt' => '2025-03-01T18:30:00', 'date_paid_gmt' => null, 'date_completed_gmt' => null,
        'customer_note' => '',
        'billing' => ['first_name' => 'Test', 'last_name' => 'Buyer' . $id, 'company' => '', 'address_1' => $id . ' Sample Road', 'address_2' => '', 'city' => 'Testville',
            'state' => 'CA', 'postcode' => '90001', 'country' => 'US', 'email' => $email, 'phone' => '555-0100'],
        'shipping' => ['first_name' => '', 'last_name' => '', 'company' => '', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'postcode' => '', 'country' => ''],
        'payment_method' => 'stripe', 'payment_method_title' => 'Credit card', 'transaction_id' => 'ch_test_' . $id,
        'discount_total' => '0.00', 'shipping_total' => '0.00', 'total_tax' => '0.00', 'total' => '0.00',
        'line_items' => $lines, 'shipping_lines' => [], 'fee_lines' => [], 'coupon_lines' => [], 'refunds' => [], 'notes' => [],
    ];
};
$soiRow = static fn(string $ref): ?array => Database::row("SELECT * FROM shop_orders WHERE tenant_id = ? AND ref = ?", [current_tenant_id(), $ref]);
$soiItems = static fn(int $orderId): array => Database::rows("SELECT * FROM shop_order_items WHERE order_id = ? ORDER BY id", [$orderId]);

try {
    ShopStoreAPI::ensureSchema();
    // The products the orders were for, imported the way a real migration does it (WooCommerce ids kept).
    $read = ShopImport::read((string) json_encode([[
        'id' => 9201, 'name' => 'Order Test Brittle', 'slug' => 'order-test-brittle', 'type' => 'variable', 'status' => 'publish', 'sku' => 'OTB',
        'description' => '', 'short_description' => '', 'categories' => [], 'tags' => [], 'images' => [],
        'attributes' => [['name' => 'Flavour', 'options' => ['Plain', 'Chili'], 'variation' => true]],
        'variations' => [
            ['id' => 9211, 'sku' => 'OTB-P', 'regular_price' => '9.97', 'status' => 'publish', 'attributes' => [['name' => 'Flavour', 'option' => 'Plain']]],
            ['id' => 9212, 'sku' => 'OTB-C', 'regular_price' => '10.97', 'status' => 'publish', 'attributes' => [['name' => 'Flavour', 'option' => 'Chili']]],
        ],
    ], [
        'id' => 9202, 'name' => 'Order Test Seasoning', 'slug' => 'order-test-seasoning', 'type' => 'simple', 'status' => 'publish', 'sku' => 'OTS', 'regular_price' => '7.50',
        'description' => '', 'short_description' => '', 'categories' => [], 'tags' => [], 'images' => [],
    ]]), 'products.json');
    $imported = ShopImport::run($read['items'], ['images' => 'link', 'fetch' => $soiFetch]);
    assert_eq(2, $imported['created'], 'test products imported');

    $a = 'buyer.one' . $soiDomain;
    $b = 'buyer.two' . $soiDomain;
    $orders = [
        $soiOrder(9301, 'completed', $a, [
            ['id' => 1, 'name' => 'Order Test Brittle - Chili', 'product_id' => 9201, 'variation_id' => 9212, 'quantity' => 2, 'sku' => 'OTB-C', 'subtotal' => '21.94', 'total' => '19.75', 'meta_data' => [['key' => 'Flavour', 'value' => 'Chili']]],
            ['id' => 2, 'name' => 'Order Test Seasoning', 'product_id' => 0, 'variation_id' => 0, 'quantity' => 1, 'sku' => 'OTS', 'subtotal' => '7.50', 'total' => '7.50', 'meta_data' => [['key' => '_reduced_stock', 'value' => '1']]],
        ], [
            'date_created_gmt' => '2024-01-21T23:24:02', 'date_paid_gmt' => '2024-01-21T23:25:00', 'date_completed_gmt' => '2024-01-23T17:00:00',
            'customer_note' => 'Gift wrap please',
            'shipping' => ['first_name' => 'Gift', 'last_name' => 'Recipient', 'company' => 'Sample Co', 'address_1' => '9 Other Street', 'address_2' => 'Unit 2', 'city' => 'Elsewhere', 'state' => 'NV', 'postcode' => '89001', 'country' => 'us'],
            'discount_total' => '2.19', 'shipping_total' => '8.50', 'total' => '35.75',
            'shipping_lines' => [['method_title' => 'Priority Mail&#0174; (USPS)', 'method_id' => 'wf_easypost_id', 'total' => '8.50']],
            'coupon_lines' => [['code' => 'welcome10', 'discount' => '2.19']],
            'notes' => [['date_created_gmt' => '2024-01-23T17:00:00', 'note' => 'Tracking 9400TEST000', 'customer_note' => true, 'added_by' => 'system']],
        ]),
        $soiOrder(9302, 'processing', strtoupper($a), [
            ['id' => 3, 'name' => 'Order Test Brittle - Plain', 'product_id' => 9201, 'variation_id' => 9211, 'quantity' => 1, 'sku' => 'OTB-P', 'subtotal' => '9.97', 'total' => '9.97', 'meta_data' => []],
        ], ['date_created_gmt' => '2025-11-23T04:02:38', 'date_paid_gmt' => '2025-11-23T04:03:00', 'total' => '9.97',
            'shipping_lines' => [['method_title' => 'Local pickup', 'method_id' => 'local_pickup', 'total' => '0.00']]]),
        $soiOrder(9303, 'failed', 'card.tester' . $soiDomain, [
            ['id' => 4, 'name' => 'Order Test Seasoning', 'product_id' => 9202, 'variation_id' => 0, 'quantity' => 1, 'sku' => 'OTS', 'subtotal' => '7.50', 'total' => '7.50'],
        ], ['total' => '7.50']),
        $soiOrder(9304, 'cancelled', $b, [
            ['id' => 5, 'name' => 'A Retired Gift Box', 'product_id' => 555, 'variation_id' => 0, 'quantity' => 1, 'sku' => '', 'subtotal' => '30.00', 'total' => '30.00'],
        ], ['total' => '30.00']),
        $soiOrder(9305, 'refunded', $b, [
            ['id' => 6, 'name' => 'Order Test Seasoning', 'product_id' => 9202, 'variation_id' => 0, 'quantity' => 3, 'sku' => 'OTS', 'subtotal' => '22.50', 'total' => '22.50'],
        ], ['total' => '22.50', 'date_paid_gmt' => '2025-02-01T10:00:00', 'refunds' => [['id' => 7, 'reason' => 'Arrived broken', 'total' => '-22.50']]]),
    ];
    $json = (string) json_encode($orders);

    unit('order import: the file is read and the plan leaves failed orders out', function () use ($json): void {
        $read = ShopOrderImport::read($json);
        assert_true($read['ok'], $read['error']);
        assert_eq(5, count($read['orders']));
        $plan = ShopOrderImport::plan($read['orders']);
        assert_eq([4, 1, 0, 2, 2], [$plan['import'], $plan['skipped'], $plan['existing'], $plan['customers'], $plan['new_customers']], 'one customer per email, whatever its case');
        assert_eq(['completed' => 1, 'processing' => 1, 'cancelled' => 1, 'refunded' => 1], $plan['by_status']);
        assert_eq(3575 + 997 + 3000 + 2250, $plan['total_cents']);
        assert_true(str_contains(implode(' ', $plan['warnings']), 'A Retired Gift Box'), 'a line for a product that is not here is named');
        assert_true(!ShopOrderImport::read('not json')['ok'] && !ShopOrderImport::read('[{"id":1}]')['ok'], 'other files are refused');
    });

    unit('order import: orders keep their reference, status, dates, addresses, money and notes', function () use ($json, $soiRow, $soiItems, $a): void {
        $res = ShopOrderImport::run(ShopOrderImport::read($json)['orders']);
        assert_eq([4, 0, 1, 2, 0, 5, 1], [$res['created'], $res['existing'], $res['skipped'], $res['customers_created'], $res['customers_linked'], $res['lines'], $res['unmatched']]);
        assert_eq([], $res['errors']);

        $o = $soiRow('WC-9301');
        assert_true($o !== null, 'the WooCommerce number is the reference');
        assert_eq(['fulfilled', 'shipping', 'USD', $a], [$o['status'], $o['delivery'], $o['currency'], $o['email']]);
        assert_eq(['Test Buyer9301', '555-0100', '9 Other Street', 'Sample Co, Unit 2', 'Elsewhere', 'NV', '89001', 'US'],
            [$o['name'], $o['phone'], $o['address_line1'], $o['address_line2'], $o['city'], $o['region'], $o['postal_code'], $o['country']], 'the shipping address wins over billing');
        assert_eq([2944, 850, 219, 0, 3575, 'WELCOME10', 'Priority Mail® (USPS)', 'Gift wrap please'],
            [(int) $o['subtotal_cents'], (int) $o['shipping_cents'], (int) $o['discount_cents'], (int) $o['tax_cents'], (int) $o['total_cents'], $o['coupon_code'], $o['shipping_method'], $o['notes']]);
        assert_eq(date('Y-m-d H:i:s', strtotime('2024-01-21 23:24:02 UTC')), $o['created_at'], 'the order date is kept (GMT to server time)');
        assert_eq(date('Y-m-d H:i:s', strtotime('2024-01-23 17:00:00 UTC')), $o['fulfilled_at']);
        assert_true($o['paid_at'] !== null && strlen((string) $o['access_key']) === 40, 'paid, with its own confirmation key');
        foreach (['#9301', 'Credit card (ch_test_9301)', '(to customer) Tracking 9400TEST000'] as $needle) {
            assert_true(str_contains((string) $o['admin_notes'], $needle), 'admin notes hold: ' . $needle);
        }

        $items = $soiItems((int) $o['id']);
        $variant = Database::row("SELECT * FROM shop_variants WHERE tenant_id = ? AND external_ref = 'woo:9212'", [current_tenant_id()]);
        assert_eq([(int) $variant['product_id'], (int) $variant['id'], 2, 1097, 2194, 'Flavour: Chili'],
            [(int) $items[0]['product_id'], (int) $items[0]['variant_id'], (int) $items[0]['qty'], (int) $items[0]['unit_price_cents'], (int) $items[0]['line_total_cents'], $items[0]['options_label']], 'the line is the imported flavour');
        $seasoning = Database::value("SELECT id FROM shop_products WHERE tenant_id = ? AND external_ref = 'woo:9202'", [current_tenant_id()]);
        assert_eq([(int) $seasoning, null, null], [(int) $items[1]['product_id'], $items[1]['variant_id'], $items[1]['options_label']], 'matched by SKU; hidden meta is not an option');

        $pickup = $soiRow('WC-9302');
        assert_eq(['paid', 'pickup', (int) $o['customer_id']], [$pickup['status'], $pickup['delivery'], (int) $pickup['customer_id']], 'the same email is the same customer');
        $cancelled = $soiRow('WC-9304');
        assert_eq(['cancelled', null], [$cancelled['status'], $cancelled['paid_at']]);
        assert_eq([null, 'A Retired Gift Box', 3000], [$soiItems((int) $cancelled['id'])[0]['product_id'], $soiItems((int) $cancelled['id'])[0]['title'], (int) $soiItems((int) $cancelled['id'])[0]['line_total_cents']], 'an unknown product keeps its line');
        $refunded = $soiRow('WC-9305');
        assert_eq('refunded', $refunded['status']);
        assert_true(str_contains((string) $refunded['admin_notes'], 'Refund: 22.50 Arrived broken'));
        assert_eq(null, $soiRow('WC-9303'), 'failed orders are left out');
    });

    unit('order import: each email becomes one guest customer, in Contacts too', function () use ($a, $soiRow): void {
        $c = Database::row("SELECT * FROM customers WHERE tenant_id = ? AND email = ?", [current_tenant_id(), $a]);
        assert_true($c !== null);
        assert_eq(['guest', null, 'Test Buyer9301', '555-0100', (int) $soiRow('WC-9301')['customer_id']], [$c['status'], $c['password_hash'], $c['name'], $c['phone'], (int) $c['id']]);
        assert_eq(date('Y-m-d H:i:s', strtotime('2024-01-21 23:24:02 UTC')), $c['created_at'], 'customer since their first order');
        assert_true(Database::value("SELECT 1 FROM contacts WHERE id = ?", [(int) $c['id']]) !== null, 'mirrored into Contacts');
    });

    unit('order import: the same file again adds nothing; failed orders come in as cancelled on request', function () use ($json, $soiRow): void {
        $orders = ShopOrderImport::read($json)['orders'];
        $again = ShopOrderImport::run($orders);
        assert_eq([0, 4, 1, 0], [$again['created'], $again['existing'], $again['skipped'], $again['customers_created']]);
        $failed = ShopOrderImport::run($orders, ['include_failed' => true]);
        assert_eq([1, 4], [$failed['created'], $failed['existing']]);
        assert_eq('cancelled', $soiRow('WC-9303')['status']);
    });

    unit('order import: an existing customer account is linked, never changed', function () use ($soiOrder, $soiDomain, $soiRow): void {
        $email = 'member' . $soiDomain;
        $id = Database::insert('customers', ['tenant_id' => current_tenant_id(), 'email' => $email, 'password_hash' => 'x', 'name' => 'Real Member', 'status' => 'active', 'email_verified' => 1]);
        $res = ShopOrderImport::run([$soiOrder(9306, 'completed', $email, [['id' => 8, 'name' => 'Thing', 'product_id' => 0, 'quantity' => 1, 'subtotal' => '5.00', 'total' => '5.00']], ['total' => '5.00'])]);
        assert_eq([1, 0, 1], [$res['created'], $res['customers_created'], $res['customers_linked']]);
        assert_eq((int) $id, (int) $soiRow('WC-9306')['customer_id']);
        assert_eq(['Real Member', 'active', 'x'], array_values(Database::row("SELECT name, status, password_hash FROM customers WHERE id = ?", [$id])));
    });
} finally {
    $tid = current_tenant_id();
    foreach (Database::rows("SELECT id FROM shop_orders WHERE tenant_id = ? AND ref LIKE 'WC-93%'", [$tid]) as $r) {
        Database::query("DELETE FROM shop_order_items WHERE order_id = ?", [(int) $r['id']]);
        Database::query("DELETE FROM shop_orders WHERE id = ?", [(int) $r['id']]);
    }
    foreach (Database::rows("SELECT id FROM customers WHERE tenant_id = ? AND email LIKE ?", [$tid, '%' . $soiDomain]) as $r) {
        foreach (['contact_emails' => 'contact_id', 'contact_phones' => 'contact_id', 'identities' => 'contact_id', 'contacts' => 'id'] as $table => $col) {
            try { Database::query("DELETE FROM `$table` WHERE `$col` = ?", [(int) $r['id']]); } catch (\Throwable $e) { /* table not in this install */ }
        }
        Database::query("DELETE FROM customers WHERE id = ?", [(int) $r['id']]);
    }
    foreach (Database::rows("SELECT id FROM shop_products WHERE tenant_id = ? AND external_ref IN ('woo:9201', 'woo:9202')", [$tid]) as $r) ShopStoreAPI::deleteProduct((int) $r['id']);
    ShopStoreAPI::pruneTags();
}
