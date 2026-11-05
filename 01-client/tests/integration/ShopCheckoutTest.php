<?php
/**
 * Shop 0.4.0 checkout: shipping zones and their methods (flat with per-item, free from a subtotal, weight bands,
 * EasyPost carrier rates through a stand-in transport), the flat rate of the settings when there is no zone, tax
 * (included, added at checkout per state and postcode, per tax class, on shipping), coupons (percent, fixed, free
 * shipping, limits, products, sale items), an order snapshotting it all, the gateway's lines, coupon use counted on
 * payment, labels and tracking, order search, and the grid's product search.
 *
 * Every row this file writes is removed and every setting it changes is restored. Data is synthetic; no network.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/shop/ShopStoreAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/shop/ShopImport.php';

$sco = ['products' => [], 'orders' => [], 'zones' => [], 'rates' => [], 'coupons' => [], 'categories' => [], 'settings' => []];
$scoSet = function (string $key, string $value) use (&$sco): void {
    if (!array_key_exists($key, $sco['settings'])) $sco['settings'][$key] = Database::setting('shop.' . $key);
    ShopStoreAPI::setSetting($key, $value);
};
$scoTag = 'sco' . bin2hex(random_bytes(3));

$scoProduct = function (array $in) use (&$sco, $scoTag): int {
    $res = ShopStoreAPI::saveProduct($in + ['price' => '10.00', 'status' => 'published', 'currency' => 'USD', 'slug' => $scoTag . '-' . bin2hex(random_bytes(3))]);
    if (!$res['ok']) throw new RuntimeException('product not saved: ' . json_encode($res['errors'] ?? []));
    $sco['products'][] = (int) $res['id'];
    return (int) $res['id'];
};
$scoCart = function (array $lines): void {
    ShopStoreAPI::cartClear();
    ShopCoupons::setSessionCode('');
    foreach ($lines as $pid => $qty) ShopStoreAPI::cartAdd($pid, $qty);
};
$scoZone = function (array $zone, array $methods) use (&$sco): int {
    $res = ShopShipping::saveZone($zone);
    if (!$res['ok']) throw new RuntimeException('zone not saved: ' . json_encode($res['errors'] ?? []));
    $sco['zones'][] = $res['id'];
    foreach ($methods as $m) {
        $r = ShopShipping::saveMethod($res['id'], $m + ['enabled' => 1]);
        if (!$r['ok']) throw new RuntimeException('method not saved: ' . json_encode($r['errors'] ?? []));
    }
    return $res['id'];
};
$scoCoupon = function (array $in) use (&$sco): int {
    $res = ShopCoupons::save($in + ['enabled' => 1]);
    if (!$res['ok']) throw new RuntimeException('coupon not saved: ' . json_encode($res['errors'] ?? []));
    $sco['coupons'][] = $res['id'];
    return $res['id'];
};
$scoRate = function (array $in) use (&$sco): int {
    $res = ShopTax::saveRate($in);
    if (!$res['ok']) throw new RuntimeException('rate not saved: ' . json_encode($res['errors'] ?? []));
    $sco['rates'][] = $res['id'];
    return $res['id'];
};
$scoAddress = ['address_line1' => '1 Test Way', 'city' => 'Paso Robles', 'region' => 'CA', 'postal_code' => '93446', 'country' => 'US'];

/** A stand-in for EasyPost: records calls and answers shipments with fixed rates and labels. */
$scoCalls = [];
$scoTransport = function (string $method, string $path, ?array $body) use (&$scoCalls): array {
    $scoCalls[] = [$method, $path, $body];
    if ($method === 'POST' && $path === '/shipments') {
        return [201, ['id' => 'shp_test1', 'rates' => [
            ['id' => 'rate_g', 'carrier' => 'USPS', 'service' => 'GroundAdvantage', 'rate' => '6.10', 'currency' => 'USD', 'delivery_days' => 4],
            ['id' => 'rate_p', 'carrier' => 'USPS', 'service' => 'Priority', 'rate' => '9.45', 'currency' => 'USD', 'delivery_days' => 2],
            ['id' => 'rate_u', 'carrier' => 'UPS', 'service' => 'Ground', 'rate' => '11.20', 'currency' => 'USD', 'delivery_days' => 3],
            ['id' => 'rate_c', 'carrier' => 'CanadaPost', 'service' => 'Expedited', 'rate' => '15.00', 'currency' => 'CAD', 'delivery_days' => 5],
        ]], null];
    }
    if ($method === 'POST' && str_ends_with($path, '/buy')) {
        return [200, ['tracking_code' => '9400TEST' . substr((string) ($body['rate']['id'] ?? ''), 5), 'postage_label' => ['label_url' => 'https://easypost-files.example.test/label.png'],
                      'tracker' => ['public_url' => 'https://track.easypost.example.test/abc']], null];
    }
    if ($method === 'GET' && $path === '/carrier_accounts') return [200, [['readable' => 'USPS'], ['readable' => 'UPS']], null];
    return [404, ['error' => ['message' => 'not here']], null];
};

try {
    ShopStoreAPI::ensureSchema();
    if (session_status() !== PHP_SESSION_ACTIVE) $_SESSION = $_SESSION ?? [];
    foreach (['shipping_enabled' => '1', 'pickup_enabled' => '1', 'countries' => 'US, CA', 'currency' => 'USD', 'weight_unit' => 'oz', 'dimension_unit' => 'in',
              'shipping_cents' => '800', 'free_over_cents' => '5000', 'tax_mode' => 'inclusive', 'tax_label' => '', 'box_weight' => '4', 'box_length' => '', 'box_width' => '', 'box_height' => '',
              'from_country' => 'US', 'from_region' => 'CA', 'from_postcode' => '93446', 'from_city' => 'Paso Robles', 'from_street1' => '2 Test Rd'] as $k => $v) {
        $scoSet($k, $v);
    }
    // Start from no zone, no rate, no coupon of this tenant's own (kept aside and put back).
    $sco['kept_zones'] = Database::rows("SELECT * FROM shop_shipping_zones WHERE tenant_id = ?", [current_tenant_id()]);
    $sco['kept_methods'] = Database::rows("SELECT * FROM shop_shipping_methods WHERE tenant_id = ?", [current_tenant_id()]);
    $sco['kept_rates'] = Database::rows("SELECT * FROM shop_tax_rates WHERE tenant_id = ?", [current_tenant_id()]);
    Database::query("DELETE FROM shop_shipping_methods WHERE tenant_id = ?", [current_tenant_id()]);
    Database::query("DELETE FROM shop_shipping_zones WHERE tenant_id = ?", [current_tenant_id()]);
    Database::query("DELETE FROM shop_tax_rates WHERE tenant_id = ?", [current_tenant_id()]);

    $brittle = $scoProduct(['title' => 'Test brittle', 'price' => '16.00', 'weight' => '8', 'sku' => $scoTag . '-B']);
    $rub = $scoProduct(['title' => 'Test rub', 'price' => '9.00', 'weight' => '4', 'tax_class' => 'reduced']);
    $ebook = $scoProduct(['title' => 'Test ebook', 'price' => '12.00', 'product_type' => 'digital']);
    $onSale = $scoProduct(['title' => 'Test sale item', 'price' => '20.00', 'sale_price' => '15.00', 'weight' => '2']);

    unit('shop checkout: the new tables and order columns exist', function (): void {
        foreach (['shop_shipping_zones', 'shop_shipping_methods', 'shop_tax_rates', 'shop_coupons'] as $t) {
            assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t]), $t);
        }
        foreach (['region', 'discount_cents', 'coupon_code', 'tax_cents', 'tax_inclusive', 'shipping_method', 'tracking_number', 'label_url', 'admin_notes'] as $c) {
            assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = ?", [$c]), "shop_orders.$c");
        }
    });

    unit('shop checkout: with no zone the flat rate and free-shipping threshold of the settings apply, as before', function () use ($scoCart, $brittle, $scoAddress): void {
        $scoCart([$brittle => 1]);
        $t = ShopStoreAPI::cartTotals('shipping', $scoAddress);
        assert_eq(800, $t['shipping_cents']);
        assert_eq('flat', $t['shipping_rate']['id']);
        assert_eq(1600 + 800, $t['total_cents']);
        $scoCart([$brittle => 4]);
        assert_eq(0, ShopStoreAPI::cartTotals('shipping', $scoAddress)['shipping_cents'], '64.00 is over 50.00');
        assert_eq(0, ShopStoreAPI::cartTotals('pickup')['shipping_cents']);
    });

    unit('shop checkout: zones match by country, state and postcode, narrow before wide, * last', function (): void {
        assert_true(ShopShipping::postcodeMatches('93446', '93446'));
        assert_true(ShopShipping::postcodeMatches('93446-1234', '934*'));
        assert_true(ShopShipping::postcodeMatches('93446', '93401-93499'));
        assert_false(ShopShipping::postcodeMatches('93500', '93401-93499'));
        assert_true(ShopShipping::postcodeMatches('h2x 1y4', 'H2X*'));
        assert_eq(['US', 'CA'], ShopShipping::codes('us, ca;US'));
        $bad = ShopShipping::saveZone(['name' => 'x', 'countries' => 'USA']);
        assert_false($bad['ok']);
        assert_true(isset($bad['errors']['countries']));
    });

    $local = $scoZone(['name' => 'Local', 'countries' => 'US', 'regions' => 'CA', 'postcodes' => '934*', 'sort' => 0], [
        ['kind' => 'flat', 'label' => 'Local courier', 'cost' => '3.00', 'per_item' => '1.00'],
    ]);
    $us = $scoZone(['name' => 'US', 'countries' => 'US', 'sort' => 1], [
        ['kind' => 'weight', 'label' => 'Standard', 'bands' => "16: 7.50\n48: 12.00\n*: 18.00"],
        ['kind' => 'free', 'label' => 'Free over 75', 'min_subtotal' => '75.00', 'sort' => 1],
        ['kind' => 'carrier', 'label' => '', 'carriers' => 'USPS', 'markup_pct' => '10', 'markup' => '1.00', 'sort' => 2],
    ]);
    $world = $scoZone(['name' => 'Everywhere else', 'countries' => '*', 'sort' => 9], [
        ['kind' => 'flat', 'label' => 'International', 'cost' => '25.00'],
    ]);

    unit('shop checkout: each zone offers its methods, cheapest first', function () use ($scoCart, $brittle, $scoAddress): void {
        $scoCart([$brittle => 2]);
        $t = ShopStoreAPI::cartTotals('shipping', $scoAddress);
        assert_eq(['Local courier'], array_column($t['shipping_rates'], 'label'), '93446 in CA is the local zone');
        assert_eq(300 + 100, $t['shipping_cents'], '3.00 + 1.00 for the second item');
        $t = ShopStoreAPI::cartTotals('shipping', ['country' => 'CA', 'region' => 'QC', 'postal_code' => 'H2X 1Y4']);
        assert_eq(['International'], array_column($t['shipping_rates'], 'label'), 'Canada falls to *');
        assert_eq(2500, $t['shipping_cents']);
    });

    unit('shop checkout: weight bands use the products plus the box; carrier rates wait for a postcode', function () use ($scoCart, $brittle): void {
        ShopShipping::$transport = null;
        $scoCart([$brittle => 1]);  // 8 oz + 4 oz box = 12 oz
        $t = ShopStoreAPI::cartTotals('shipping', ['country' => 'US', 'region' => 'NY']);
        assert_eq(['Standard'], array_column($t['shipping_rates'], 'label'), 'no postcode yet: no carrier rates');
        assert_true($t['shipping_pending'], 'carrier rates are pending');
        assert_eq(750, $t['shipping_cents'], '12 oz is in the first band');
        $scoCart([$brittle => 3]);  // 28 oz
        assert_eq(1200, ShopStoreAPI::cartTotals('shipping', ['country' => 'US', 'region' => 'NY'])['shipping_cents']);
        $scoCart([$brittle => 6]);  // 52 oz, and 96.00: free shipping too
        $t = ShopStoreAPI::cartTotals('shipping', ['country' => 'US', 'region' => 'NY']);
        assert_eq(['Free over 75', 'Standard'], array_column($t['shipping_rates'], 'label'));
        assert_eq(1800, ShopShipping::pick($t['shipping_rates'], $t['shipping_rates'][1]['id'])['cents'], 'over 48 oz: the * band');
    });

    unit('shop checkout: carrier rates come from EasyPost, filtered, marked up, in the shop\'s currency, and cached', function () use ($scoCart, $brittle, &$scoCalls, $scoTransport): void {
        ShopShipping::$transport = $scoTransport;
        $scoCalls = [];
        $scoCart([$brittle => 1]);
        $addr = ['country' => 'US', 'region' => 'NY', 'postal_code' => '10001', 'city' => 'New York', 'address_line1' => '5 Test Ave'];
        $t = ShopStoreAPI::cartTotals('shipping', $addr);
        $carrier = array_values(array_filter($t['shipping_rates'], static fn(array $r): bool => $r['kind'] === 'carrier'));
        assert_eq(['ep:USPS:GroundAdvantage', 'ep:USPS:Priority'], array_column($carrier, 'id'), 'USPS only; UPS and the CAD rate are left out');
        assert_eq((int) round(610 * 1.1) + 100, $carrier[0]['cents'], '6.10 + 10% + 1.00');
        assert_eq('USPS Ground Advantage', $carrier[0]['label']);
        assert_eq(4, $carrier[0]['days']);
        assert_eq(1, count($scoCalls));
        $sent = $scoCalls[0][2]['shipment'];
        assert_eq(12.0, $sent['parcel']['weight'], '8 oz + 4 oz box, in ounces');
        assert_eq('93446', $sent['from_address']['zip']);
        assert_eq('10001', $sent['to_address']['zip']);
        ShopStoreAPI::cartTotals('shipping', $addr, 'ep:USPS:Priority');
        assert_eq(1, count($scoCalls), 'the same address and parcel are not quoted twice');
        $t = ShopStoreAPI::cartTotals('shipping', $addr, 'ep:USPS:Priority');
        assert_eq('ep:USPS:Priority', $t['shipping_rate']['id']);
        assert_eq((int) round(945 * 1.1) + 100, $t['shipping_cents']);
        // An EasyPost failure leaves the other methods, and is never shown raw.
        ShopShipping::$transport = static fn(): array => [500, ['error' => ['message' => 'secret detail']], null];
        $t = ShopStoreAPI::cartTotals('shipping', array_merge($addr, ['postal_code' => '10002']));
        assert_eq(['Standard'], array_column($t['shipping_rates'], 'label'));
        ShopShipping::$transport = $scoTransport;
    });

    unit('shop checkout: an address no zone covers gets no rate and a plain message', function () use ($scoCart, $brittle, $world): void {
        ShopShipping::deleteZone($world);
        $scoCart([$brittle => 1]);
        $t = ShopStoreAPI::cartTotals('shipping', ['country' => 'CA', 'postal_code' => 'H2X 1Y4']);
        assert_eq([], $t['shipping_rates']);
        assert_true(str_contains($t['shipping_error'], 'do not ship'), $t['shipping_error']);
        $placed = ShopStoreAPI::placeOrder(['email' => 'sco@example.test', 'name' => 'Sco Buyer', 'delivery' => 'shipping', 'address_line1' => '1 Rue Test', 'postal_code' => 'H2X 1Y4', 'city' => 'Montréal', 'region' => 'QC', 'country' => 'CA']);
        assert_false($placed['ok']);
        assert_true(isset($placed['errors']['shipping_rate']));
    });

    unit('shop checkout: with selling paused nothing can be added or ordered, and the line says why', function () use ($scoCart, $scoSet, $brittle): void {
        $scoCart([$brittle => 1]);                       // in the cart from before the pause
        $scoSet('closed', '1');
        $scoSet('closed_message', '');
        try {
            assert_true(ShopStoreAPI::closed());
            $add = ShopStoreAPI::cartAdd($brittle, 1);
            assert_false($add['ok']);
            assert_eq('Currently not available.', $add['error']);
            $scoSet('closed_message', 'Back in spring.');
            assert_eq('Back in spring.', ShopStoreAPI::cartAdd($brittle, 1)['error']);
            $placed = ShopStoreAPI::placeOrder(['email' => 'sco@example.test', 'name' => 'Sco Buyer', 'delivery' => 'pickup']);
            assert_false($placed['ok']);
            assert_eq('Back in spring.', $placed['errors']['_'] ?? '');
            assert_eq(409, $placed['status'] ?? 0);
        } finally {
            $scoSet('closed', '0');
        }
        assert_false(ShopStoreAPI::closed());
        assert_true(ShopStoreAPI::cartAdd($brittle, 1)['ok'], 'selling on again: Add to cart works');
    });

    unit('shop checkout: a US address needs its state', function () use ($scoCart, $brittle): void {
        $scoCart([$brittle => 1]);
        $placed = ShopStoreAPI::placeOrder(['email' => 'sco@example.test', 'name' => 'Sco Buyer', 'delivery' => 'shipping', 'address_line1' => '1 Test Way', 'postal_code' => '93446', 'city' => 'Paso Robles', 'country' => 'US']);
        assert_false($placed['ok']);
        assert_true(isset($placed['errors']['region']));
    });

    unit('shop checkout: prices that include tax show the tax inside the total, per class', function () use ($scoCart, $scoRate, $brittle, $rub): void {
        $scoRate(['country' => 'US', 'rate' => '20', 'name' => '', 'shipping' => 1]);
        $scoRate(['country' => 'US', 'rate' => '5', 'tax_class' => 'reduced', 'shipping' => 0]);
        $scoCart([$brittle => 1, $rub => 1]);
        $t = ShopStoreAPI::cartTotals('pickup');
        assert_true($t['tax_inclusive']);
        assert_eq(1600 - (int) round(1600 / 1.2) + 900 - (int) round(900 / 1.05), $t['tax_cents'], '20% inside 16.00, 5% inside 9.00');
        assert_eq(2500, $t['total_cents'], 'nothing is added');
        $rows = array_column(ShopStoreAPI::totalRows($t, 'USD'), null, 'key');
        assert_true(isset($rows['tax_included']) && !isset($rows['tax']), 'an "Includes" note, not a line');
        assert_eq('VAT', ShopTax::label());
    });

    unit('shop checkout: tax added at checkout follows the delivery address, the most specific rate, and shipping when it says so', function () use ($scoCart, $scoRate, $scoSet, $brittle, $rub, $scoAddress): void {
        Database::query("DELETE FROM shop_tax_rates WHERE tenant_id = ?", [current_tenant_id()]);
        $scoSet('tax_mode', 'exclusive');
        $scoRate(['country' => 'US', 'region' => 'CA', 'rate' => '7.25', 'shipping' => 0]);
        $scoRate(['country' => 'US', 'region' => 'CA', 'postcodes' => '934*', 'rate' => '8.75', 'name' => 'SLO County', 'shipping' => 1]);
        $scoRate(['country' => 'US', 'region' => 'NY', 'rate' => '4', 'shipping' => 1]);
        $scoCart([$brittle => 1, $rub => 1]);
        $t = ShopStoreAPI::cartTotals('shipping', $scoAddress);
        assert_false($t['tax_inclusive']);
        // The rub is "reduced", which has no rate here: not taxed. Shipping (local courier 3.00 + 1.00) is.
        assert_eq((int) round(1600 * 0.0875) + (int) round(400 * 0.0875), $t['tax_cents']);
        assert_eq('SLO County', $t['tax_label'], 'the rate\'s own name');
        assert_eq(2500 + 400 + $t['tax_cents'], $t['total_cents'], 'tax is added');
        $ny = ShopStoreAPI::cartTotals('shipping', ['country' => 'US', 'region' => 'NY']);
        assert_eq((int) round(1600 * 0.04) + (int) round($ny['shipping_cents'] * 0.04), $ny['tax_cents']);
        $none = ShopStoreAPI::cartTotals('shipping');
        assert_true($none['tax_pending'], 'no address yet: tax waits for it');
        assert_eq(0, $none['tax_cents']);
        $pickup = ShopStoreAPI::cartTotals('pickup');
        assert_eq((int) round(1600 * 0.0875), $pickup['tax_cents'], 'pickup: the shop\'s own address (93446)');
        assert_eq('Sales tax', ShopTax::label());
        assert_true(str_contains(ShopStoreAPI::cartTaxNote(true), 'calculated at checkout'));
    });

    unit('shop checkout: coupons — percent, fixed, free shipping, minimum, dates, limits, products and sale items', function () use ($scoCart, $scoCoupon, $brittle, $rub, $onSale, $scoAddress): void {
        $scoCoupon(['code' => 'sco10', 'kind' => 'percent', 'value' => '10']);
        $scoCoupon(['code' => 'SCO5', 'kind' => 'fixed', 'value' => '5.00', 'min_subtotal' => '30.00']);
        $scoCoupon(['code' => 'SCOSHIP', 'kind' => 'free_shipping', 'value' => '']);
        $scoCoupon(['code' => 'SCOOLD', 'kind' => 'percent', 'value' => '50', 'ends_at' => '2020-01-01 00:00']);
        $scoCoupon(['code' => 'SCORUB', 'kind' => 'percent', 'value' => '50', 'product_ids' => [$rub]]);
        $scoCoupon(['code' => 'SCONOSALE', 'kind' => 'percent', 'value' => '10', 'exclude_sale' => 1]);
        $dup = ShopCoupons::save(['code' => 'SCO10', 'kind' => 'percent', 'value' => '5', 'enabled' => 1]);
        assert_false($dup['ok'], 'codes are unique, whatever the case');

        $scoCart([$brittle => 1, $rub => 1]);
        $lines = ShopStoreAPI::cartLines();
        assert_true(ShopCoupons::apply('sco10', $lines)['ok'], 'not case-sensitive');
        $t = ShopStoreAPI::cartTotals('shipping', $scoAddress);
        assert_eq(250, $t['discount_cents']);
        assert_eq('SCO10', $t['coupon']);
        assert_eq(250, array_sum(array_column($t['lines'], 'discount_cents')), 'shared across the lines');
        assert_eq((int) round((1600 - 160) * 0.0875) + (int) round(400 * 0.0875), $t['tax_cents'], 'tax on what the brittle really costs');

        $min = ShopCoupons::apply('SCO5', $lines);
        assert_false($min['ok'], '25.00 is under 30.00');
        assert_true(str_contains($min['message'], ShopStoreAPI::money(3000)), $min['message']);
        assert_false(ShopCoupons::apply('SCOOLD', $lines)['ok'], 'expired');
        assert_false(ShopCoupons::apply('NOPE-' . bin2hex(random_bytes(2)), $lines)['ok']);

        assert_true(ShopCoupons::apply('SCORUB', $lines)['ok']);
        assert_eq(450, ShopStoreAPI::cartTotals('pickup')['discount_cents'], 'half of the rub only');

        assert_true(ShopCoupons::apply('SCOSHIP', $lines)['ok']);
        $t = ShopStoreAPI::cartTotals('shipping', $scoAddress);
        assert_eq(0, $t['shipping_cents']);
        assert_true($t['free_shipping']);

        $scoCart([$onSale => 1]);
        assert_false(ShopCoupons::apply('SCONOSALE', ShopStoreAPI::cartLines())['ok'], 'only a sale item in the cart');

        // A coupon that stops fitting drops off with a note.
        $scoCart([$brittle => 2]);
        assert_true(ShopCoupons::apply('SCO5', ShopStoreAPI::cartLines())['ok']);
        ShopStoreAPI::cartSet((string) $brittle, 1);
        $t = ShopStoreAPI::cartTotals('pickup');
        assert_eq(0, $t['discount_cents']);
        assert_true($t['coupon_note'] !== '');
    });

    $orderId = 0;
    unit('shop checkout: the order keeps the coupon, tax, shipping method and state; the gateway gets one line for a discounted order', function () use (&$sco, &$orderId, $scoCart, $brittle, $scoAddress): void {
        $scoCart([$brittle => 2]);
        ShopCoupons::apply('SCO10', ShopStoreAPI::cartLines());
        $placed = ShopStoreAPI::placeOrder(['email' => 'sco@example.test', 'name' => 'Sco Buyer', 'delivery' => 'shipping', 'shipping_rate' => 'nope'] + $scoAddress);
        assert_true($placed['ok'], json_encode($placed['errors'] ?? []) . ' (one rate: picked even when the posted id is stale)');
        $o = $placed['order'];
        $sco['orders'][] = $orderId = (int) $o['id'];
        assert_eq('CA', $o['region']);
        assert_eq('SCO10', $o['coupon_code']);
        assert_eq(320, (int) $o['discount_cents']);
        assert_eq('Local courier', $o['shipping_method']);
        assert_eq(400, (int) $o['shipping_cents']);
        assert_eq(0, (int) $o['tax_inclusive']);
        $tax = (int) round(2880 * 0.0875) + (int) round(400 * 0.0875);
        assert_eq($tax, (int) $o['tax_cents']);
        assert_eq(3200 - 320 + 400 + $tax, (int) $o['total_cents']);
        $lines = ShopStoreAPI::paymentLines($o);
        assert_eq(1, count($lines));
        assert_eq((int) $o['total_cents'], $lines[0]['amount_cents']);
        assert_true(str_contains(ShopStoreAPI::addressText($o), 'Paso Robles, CA 93446'), ShopStoreAPI::addressText($o));
    });

    unit('shop checkout: without a discount the gateway gets the items, shipping and tax that sum to the total', function () use (&$sco, $scoCart, $brittle, $scoAddress): void {
        $scoCart([$brittle => 1]);
        $placed = ShopStoreAPI::placeOrder(['email' => 'sco2@example.test', 'name' => 'Sco Two', 'delivery' => 'shipping'] + $scoAddress);
        assert_true($placed['ok'], json_encode($placed['errors'] ?? []));
        $sco['orders'][] = (int) $placed['order']['id'];
        $lines = ShopStoreAPI::paymentLines($placed['order']);
        assert_eq(['Test brittle', 'Local courier', 'SLO County'], array_column($lines, 'name'));
        assert_eq((int) $placed['order']['total_cents'], array_sum(array_map(static fn(array $l): int => $l['amount_cents'] * $l['quantity'], $lines)));
    });

    unit('shop checkout: a coupon is counted when its order is paid, and its limits hold', function () use (&$orderId, &$sco, $scoCart, $scoCoupon, $brittle): void {
        $before = (int) ShopCoupons::find('SCO10')['used_count'];
        ShopStoreAPI::markPaid($orderId, (int) ShopStoreAPI::order($orderId)['total_cents'], 'USD');
        assert_eq($before + 1, (int) ShopCoupons::find('SCO10')['used_count']);
        $once = $scoCoupon(['code' => 'SCOONCE', 'kind' => 'percent', 'value' => '10', 'per_customer' => '1']);
        Database::update('shop_orders', ['coupon_code' => 'SCOONCE'], 'id = ?', [$orderId]);
        $scoCart([$brittle => 1]);
        $lines = ShopStoreAPI::cartLines();
        assert_false(ShopCoupons::apply('SCOONCE', $lines, 'SCO@example.test')['ok'], 'that email already used it (any case)');
        assert_true(ShopCoupons::apply('SCOONCE', $lines, 'other@example.test')['ok']);
        Database::update('shop_coupons', ['usage_limit' => 3, 'used_count' => 3], 'id = ?', [$once]);
        assert_false(ShopCoupons::apply('SCOONCE', $lines)['ok'], 'used up');
    });

    unit('shop checkout: a label is bought for the rate the customer chose, and tracking reaches the order', function () use (&$sco, &$scoCalls, $scoCart, $brittle, $scoTransport): void {
        ShopShipping::$transport = $scoTransport;
        $scoCart([$brittle => 1]);
        $addr = ['address_line1' => '5 Test Ave', 'city' => 'New York', 'region' => 'NY', 'postal_code' => '10001', 'country' => 'US'];
        $placed = ShopStoreAPI::placeOrder(['email' => 'sco3@example.test', 'name' => 'Sco Three', 'delivery' => 'shipping', 'shipping_rate' => 'ep:USPS:Priority'] + $addr);
        assert_true($placed['ok'], json_encode($placed['errors'] ?? []));
        $id = (int) $placed['order']['id'];
        $sco['orders'][] = $id;
        assert_eq('USPS', $placed['order']['shipping_carrier']);
        assert_eq('Priority', $placed['order']['shipping_service']);
        assert_false(ShopShipping::buyLabel($id)['ok'], 'not paid yet');
        ShopStoreAPI::markPaid($id, (int) $placed['order']['total_cents'], 'USD');
        $scoCalls = [];
        $res = ShopShipping::buyLabel($id);
        assert_true($res['ok'], (string) ($res['error'] ?? ''));
        assert_eq('/shipments/shp_test1/buy', $scoCalls[1][1]);
        assert_eq('rate_p', $scoCalls[1][2]['rate']['id'], 'the Priority rate the customer paid for');
        $o = ShopStoreAPI::order($id);
        assert_eq('9400TESTp', $o['tracking_number']);
        assert_eq('https://track.easypost.example.test/abc', ShopStoreAPI::trackingLink($o));
        assert_false(ShopShipping::buyLabel($id)['ok'], 'one label per order');
        assert_true(ShopStoreAPI::fulfil($id));
        assert_true(ShopStoreAPI::order($id)['shipped_at'] !== null);
        assert_eq('https://tools.usps.com/go/TrackConfirmAction?tLabels=9400', ShopShipping::trackingUrl('USPS', '9400'));
        assert_false(ShopStoreAPI::saveTracking($id, ['url' => 'javascript:alert(1)'])['ok'], 'only https links');
        assert_true(ShopShipping::testConnection()['ok']);
    });

    unit('shop checkout: a zone\'s pickup method makes a pickup order only where that zone is', function () use (&$sco, $local, $scoCart, $brittle, $scoAddress): void {
        $res = ShopShipping::saveMethod($local, ['kind' => 'pickup', 'label' => 'Pick up in Paso Robles', 'cost' => '0', 'enabled' => 1, 'sort' => 5]);
        assert_true($res['ok'], json_encode($res['errors'] ?? []));
        $scoCart([$brittle => 1]);
        $t = ShopStoreAPI::cartTotals('shipping', $scoAddress);
        $pick = array_values(array_filter($t['shipping_rates'], static fn(array $r): bool => $r['kind'] === 'pickup'));
        assert_eq(1, count($pick));
        assert_eq(0, $pick[0]['cents']);
        $ny = ShopStoreAPI::cartTotals('shipping', ['country' => 'US', 'region' => 'NY', 'postal_code' => '10001']);
        assert_eq([], array_values(array_filter($ny['shipping_rates'], static fn(array $r): bool => $r['kind'] === 'pickup')), 'not offered in New York');
        $placed = ShopStoreAPI::placeOrder(['email' => 'sco4@example.test', 'name' => 'Sco Four', 'delivery' => 'shipping', 'shipping_rate' => $pick[0]['id']] + $scoAddress);
        assert_true($placed['ok'], json_encode($placed['errors'] ?? []));
        $sco['orders'][] = (int) $placed['order']['id'];
        assert_eq('pickup', $placed['order']['delivery']);
        assert_eq('Pick up in Paso Robles', ShopStoreAPI::pickupText($placed['order']));
        assert_eq(0, (int) $placed['order']['shipping_cents']);
        assert_false(ShopShipping::buyLabel((int) $placed['order']['id'])['ok'], 'no label for a pickup');
        ShopShipping::deleteMethod((int) $res['id']);
    });

    unit('shop checkout: WooCommerce tax status and class become the Shop\'s tax classes', function (): void {
        assert_eq('zero', ShopImport::taxClass('none', ''));
        assert_eq('', ShopImport::taxClass('taxable', ''));
        assert_eq('', ShopImport::taxClass('shipping', ''));
        assert_eq('reduced', ShopImport::taxClass('taxable', 'reduced-rate'));
        assert_eq('zero', ShopImport::taxClass('taxable', 'Zero rate'));
    });

    unit('shop checkout: orders are found by reference, email, name or tracking', function () use (&$orderId): void {
        $o = ShopStoreAPI::order($orderId);
        assert_eq([$orderId], array_map('intval', array_column(ShopStoreAPI::listOrders('', 50, (string) $o['ref']), 'id')));
        assert_true(in_array($orderId, array_map('intval', array_column(ShopStoreAPI::listOrders('', 50, 'sco@example'), 'id')), true));
        assert_true(in_array('9400TESTp', array_column(ShopStoreAPI::listOrders('', 50, '9400TEST'), 'tracking_number'), true));
        assert_eq([], ShopStoreAPI::listOrders('', 50, '%'), 'a % is a character, not a wildcard');
    });

    unit('shop checkout: nothing to ship has no shipping row; no tax mode shows no tax', function () use ($scoCart, $scoSet, $ebook): void {
        $scoCart([$ebook => 1]);
        $t = ShopStoreAPI::cartTotals();
        assert_eq('none', $t['delivery']);
        $scoSet('tax_mode', 'none');
        $t = ShopStoreAPI::cartTotals();
        assert_eq(0, $t['tax_cents']);
        assert_eq(1200, $t['total_cents']);
        assert_eq('', ShopStoreAPI::cartTaxNote(false));
        $scoSet('tax_mode', 'inclusive');
    });

    unit('shop checkout: the grid\'s search finds products by words in the title, SKU or tags', function () use ($scoTag, $brittle, $rub): void {
        $hits = array_map('intval', array_column(ShopStoreAPI::listProducts(['q' => 'test brittle', 'per_page' => 48])['rows'], 'id'));
        assert_true(in_array($brittle, $hits, true) && !in_array($rub, $hits, true), 'both words must match');
        $sku = array_map('intval', array_column(ShopStoreAPI::listProducts(['q' => $scoTag . '-B', 'per_page' => 48])['rows'], 'id'));
        assert_eq([$brittle], $sku);
        $decl = require dirname(__DIR__, 2) . '/plugins/shop/studio-widgets.php';
        foreach ($decl['blocks'] as $b) if ($b->type() === 'shop.products') $keys = array_column($b->schema()->fields(), 'key');
        assert_true(in_array('show_search', $keys ?? [], true) && in_array('show_sort', $keys ?? [], true));
    });

    unit('shop checkout: the variant picker carries each variant\'s photo for the gallery', function () use (&$sco, $scoTag): void {
        $res = ShopStoreAPI::saveProduct(['title' => 'Test flavours', 'price' => '16.00', 'status' => 'published', 'currency' => 'USD', 'slug' => $scoTag . '-flv', 'attributes' => 'Flavor: Plain, Spicy']);
        assert_true($res['ok'], json_encode($res['errors'] ?? []));
        $pid = (int) $res['id'];
        $sco['products'][] = $pid;
        $key = ShopStoreAPI::optionsKey(['Spicy']);
        ShopStoreAPI::saveVariants($pid, ShopStoreAPI::attributes(ShopStoreAPI::productRow($pid)), [$key => ['image' => '/uploads/media/test/spicy.webp', 'enabled' => 1], ShopStoreAPI::optionsKey(['Plain']) => ['enabled' => 1]]);
        $p = ShopStoreAPI::publicProduct(ShopStoreAPI::productRow($pid), [], ShopStoreAPI::variantRows($pid), []);
        $byValue = [];
        foreach (\Slate\Plugin\Shop\Studio\ShopStudio::variantRows($p) as $r) $byValue[$r[1][0]] = $r;
        assert_eq(5, count($byValue['Spicy']), 'the Spicy row carries a photo');
        assert_true(str_ends_with((string) $byValue['Spicy'][4], '/uploads/media/test/spicy.webp'));
        assert_eq(4, count($byValue['Plain']), 'Plain has none: the gallery keeps the product photo');
    });
    unit('shop grid: layout, card, badges, filter bar and pages are the widget\'s own settings', function () use (&$sco, $scoTag, $scoProduct): void {
        require_once dirname(__DIR__, 2) . '/plugins/shop/studio-widgets.php';
        $a = $scoProduct(['title' => $scoTag . ' Grid sale', 'price' => '20.00', 'sale_price' => '15.00', 'images' => "/uploads/a.webp\n/uploads/b.webp", 'weight' => '2']);
        $scoProduct(['title' => $scoTag . ' Grid plain', 'price' => '9.00']);
        $scoProduct(['title' => $scoTag . ' Grid third', 'price' => '11.00']);
        $render = static function (array $props, array $responsive = [], array $get = []): string {
            $_GET = $get;
            $ctx = \Slate\Module\StudioBuilder\Render\RenderContext::forPublic((int) current_tenant_id(), new \Slate\Module\StudioBuilder\Render\SiteContext('https://example.test', 'T', 'en'), null, []);
            $block = ['id' => 'blk_grid', 'type' => 'shop.products', 'props' => $props + ['tag' => '', 'per_page' => 2, 'order' => 'title']] + ($responsive ? ['responsive' => $responsive] : []);
            $media = new \Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver(new \Slate\Tenancy\TenantContext());
            $html = (new \Slate\Plugin\Shop\Studio\ShopProductsRenderer())->render(new \Slate\Module\StudioBuilder\Render\Block\BlockRenderScope($block, $ctx, $media,
                new \Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme('default', []), new \Slate\Module\StudioBuilder\Render\RenderCollector('nonce')));
            $_GET = [];
            return $html;
        };
        $html = $render(['columns' => 4, 'gap' => 20, 'row_gap' => 40, 'align' => 'center', 'card_style' => 'raised', 'card_radius' => 14, 'image_ratio' => 'portrait',
            'image_hover' => 'second_image', 'title_lines' => '2', 'sale_badge' => 'percent', 'badge_position' => 'top_right', 'button_placement' => 'on_image', 'button_width' => 'full',
            'show_filter' => true, 'show_sort' => true, 'show_search' => true, 'show_count' => true, 'pagination' => 'load_more', 'load_more_label' => 'More treats'],
            ['tablet' => ['props' => ['columns' => 3, 'gap' => 12]], 'mobile' => ['props' => ['columns' => 2, 'align' => 'left']]], ['q' => $scoTag . ' Grid']);
        foreach (['sb-dcols', 'sb-shop-products--card-raised', 'sb-shop-products--ratio-portrait', 'sb-shop-products--hover-second_image', 'sb-shop-products--clamp-2',
                  'sb-shop-products--badge-right', 'sb-shop-products--button-full'] as $class) {
            assert_true(str_contains($html, $class), $class);
        }
        foreach (['--sb-dc-d:4;--sb-dc-t:3;--sb-dc-m:2', '--sb-shop-gx-d:20px;--sb-shop-gx-t:12px', '--sb-shop-gy-d:40px', '--sb-shop-al-d:center;--sb-shop-al-t:center;--sb-shop-al-m:left', '--sb-shop-radius:14px'] as $var) {
            assert_true(str_contains(html_entity_decode($html), $var), $var);
        }
        assert_true(str_contains($html, '−25%'), 'the sale badge as a percentage');
        assert_true(str_contains($html, 'sb-shop-card__image--alt') && str_contains($html, '/uploads/b.webp'), 'the second photo for hover');
        assert_true(str_contains($html, 'sb-shop-card__quick'), 'Add to cart on the image');
        assert_true(str_contains($html, 'class="sb-shop-toolbar"') && str_contains($html, 'data-sb-autosubmit') && str_contains($html, '3 products'), 'one filter bar, with the count');
        assert_true(str_contains($html, 'data-sb-load-more="') && str_contains($html, 'More treats') && str_contains($html, 'page=2'), 'Load more points at page 2');
        assert_true(str_contains($html, 'data-sb-load-grid="'), 'the grid Load more fills');
        $plain = $render(['columns' => 3], [], ['q' => $scoTag . ' Grid']);
        assert_false(str_contains($plain, 'sb-shop-toolbar'), 'no filter bar unless asked');
        assert_true(str_contains($plain, 'sb-shop-products--card-plain') && str_contains($plain, 'Sale<'), 'defaults: plain cards, a Sale badge');
        $pages = $render(['columns' => 3, 'pagination' => 'prev_next', 'show_filter' => true, 'filter_style' => 'dropdown', 'show_search' => true], [], ['q' => $scoTag . ' Grid']);
        assert_true(str_contains($pages, 'Page 1 of 2') && !str_contains($pages, 'aria-current="page"'), 'previous / next with the page, no numbers');
        $compact = $render(['columns' => 3, 'density' => 'compact', 'show_sort' => true], [], ['q' => $scoTag . ' Grid']);
        assert_true(str_contains($compact, 'sb-shop-products--compact') && str_contains($compact, 'sb-shop-toolbar sb-shop-toolbar--compact'), 'the compact size reaches the cards and the filter bar');
        // The product photos: every photo a thumbnail, the first chosen, at the size set per device, on the left.
        $product = \ShopStoreAPI::publicProduct(\ShopStoreAPI::productRow($a));
        $ctx = \Slate\Module\StudioBuilder\Render\RenderContext::forPublic((int) current_tenant_id(), new \Slate\Module\StudioBuilder\Render\SiteContext('https://example.test', 'T', 'en'), null,
            ['single_item' => ['type' => 'product', 'data' => $product]]);
        $block = ['id' => 'blk_gal', 'type' => 'shop.product_image', 'props' => ['ratio' => 'square', 'show_thumbnails' => true, 'thumb_position' => 'left', 'thumb_size' => 64],
                  'responsive' => ['mobile' => ['props' => ['thumb_size' => 48]]]];
        $html = (new \Slate\Plugin\Shop\Studio\ShopProductImageRenderer())->render(new \Slate\Module\StudioBuilder\Render\Block\BlockRenderScope($block, $ctx,
            new \Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver(new \Slate\Tenancy\TenantContext()), new \Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme('default', []), new \Slate\Module\StudioBuilder\Render\RenderCollector('nonce')));
        assert_eq(2, substr_count($html, 'data-sb-thumb='), 'both photos are thumbnails');
        assert_eq(1, substr_count($html, 'aria-current="true"'), 'the first is chosen');
        assert_true(str_contains($html, 'sb-shop-gallery--thumbs-left') && str_contains($html, '--sb-shop-thumb-d:64px') && str_contains($html, '--sb-shop-thumb-m:48px'), 'on the left, sized per device');
    });

    unit('shop options: a product\'s flavours as compact photo chips that open it with the flavour chosen, the rest behind "+N more"', function () use (&$sco, $scoTag): void {
        $res = ShopStoreAPI::saveProduct(['title' => $scoTag . ' Flavours', 'price' => '16.00', 'status' => 'published', 'currency' => 'USD', 'slug' => $scoTag . '-opts', 'attributes' => 'Flavor: Plain, Spicy, Maple, Mint']);
        assert_true($res['ok'], json_encode($res['errors'] ?? []));
        $pid = (int) $res['id'];
        $sco['products'][] = $pid;
        $attrs = ShopStoreAPI::attributes(ShopStoreAPI::productRow($pid));
        ShopStoreAPI::saveVariants($pid, $attrs, [
            ShopStoreAPI::optionsKey(['Plain']) => ['enabled' => 1], ShopStoreAPI::optionsKey(['Spicy']) => ['enabled' => 1, 'image' => '/uploads/spicy.webp', 'price' => '18.00'],
            ShopStoreAPI::optionsKey(['Maple']) => ['enabled' => 1, 'stock' => '0'], ShopStoreAPI::optionsKey(['Mint']) => ['enabled' => 1],
        ]);
        $render = static function (array $props): string {
            $ctx = \Slate\Module\StudioBuilder\Render\RenderContext::forPublic((int) current_tenant_id(), new \Slate\Module\StudioBuilder\Render\SiteContext('https://example.test', 'T', 'en'), null, []);
            return (new \Slate\Plugin\Shop\Studio\ShopProductOptionsRenderer())->render(new \Slate\Module\StudioBuilder\Render\Block\BlockRenderScope(['id' => 'blk_opts', 'type' => 'shop.product_options', 'props' => $props],
                $ctx, new \Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver(new \Slate\Tenancy\TenantContext()), new \Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme('default', []), new \Slate\Module\StudioBuilder\Render\RenderCollector('nonce')));
        };
        $html = $render(['product' => '/product/' . $scoTag . '-opts/', 'show_price' => true, 'visible' => 2]);
        assert_eq(4, substr_count($html, '<a class="sb-shop-option'), 'every flavour, sold out muted');
        assert_true(str_contains($html, '?option=Spicy') && str_contains($html, '/uploads/spicy.webp'), 'a link that chooses it, with its own photo');
        assert_true(str_contains($html, '$18.00') && str_contains($html, '$16.00'), 'prices shown because they differ');
        assert_true(str_contains($html, 'sb-shop-option--sold-out') && str_contains($html, 'sb-shop-options--compact'), 'Maple is sold out; compact by default');
        assert_eq(2, substr_count($html, 'sb-shop-options__item--extra'), 'two folded');
        assert_true(str_contains($html, '+2 more'), 'the "+N more" chip');
        $hidden = $render(['product' => $scoTag . '-opts', 'sold_out' => 'hidden', 'display' => 'tiles']);
        assert_eq(3, substr_count($hidden, '<a class="sb-shop-option'), 'sold out hidden');
        assert_true(str_contains($hidden, 'sb-shop-options--tiles') && !str_contains($hidden, '$18.00'), 'tiles, no prices unless asked');
        assert_eq('', $render(['product' => 'no-such-' . $scoTag]), 'nothing for a product that is not there');
    });

    unit('studio visibility: a block tied to a field goes when the single item shown (a product) leaves it empty', function (): void {
        $has = new \ReflectionMethod(\Slate\Module\StudioBuilder\Render\DocumentRenderer::class, 'fieldHasValue');
        $ctx = static fn(array $data, array $meta = []): \Slate\Module\StudioBuilder\Render\RenderContext => \Slate\Module\StudioBuilder\Render\RenderContext::forPublic((int) current_tenant_id(),
            new \Slate\Module\StudioBuilder\Render\SiteContext('https://example.test', 'T', 'en'), null, ['single_item' => ['type' => 'product', 'data' => $data]] + ($meta ? ['page_meta' => ['values' => $meta]] : []));
        assert_true($has->invoke(null, $ctx(['description' => '<p>Rich and buttery.</p>']), 'description'), 'a product with a description');
        assert_false($has->invoke(null, $ctx(['description' => '']), 'description'), 'empty');
        assert_false($has->invoke(null, $ctx(['description' => '<p>&nbsp;</p>']), 'description'), 'only markup and spaces is empty');
        assert_false($has->invoke(null, $ctx([]), 'description'), 'missing');
        assert_true($has->invoke(null, $ctx([], ['description' => 'from the page']), 'description'), 'a page\'s own field still comes first');
    });

    unit('shop seo: a product keeps its own search title and description, and its page tells Google the price and stock', function () use (&$sco, $scoTag, $scoProduct): void {
        require_once dirname(__DIR__, 2) . '/plugins/shop/studio/ShopProductSingle.php';
        $pid = $scoProduct(['title' => $scoTag . ' Seo Brittle', 'price' => '12.50', 'sku' => strtoupper($scoTag) . '-SEO', 'seo_title' => '  Brittle for Google  ', 'seo_description' => 'Hand-made brittle.']);
        $row = ShopStoreAPI::productRow($pid);
        assert_eq(['Brittle for Google', 'Hand-made brittle.'], [$row['seo_title'], $row['seo_description']]);
        // An update that does not send them (an importer's) keeps them.
        ShopStoreAPI::saveProduct(['title' => $row['title'], 'slug' => $row['slug'], 'sku' => $row['sku'], 'price' => '12.50', 'status' => 'published'], $pid);
        assert_eq('Brittle for Google', ShopStoreAPI::productRow($pid)['seo_title']);

        $item = (new \Slate\Plugin\Shop\Studio\ShopProductSingle())->find((int) current_tenant_id(), (string) $row['slug']);
        assert_eq(['Brittle for Google', 'Hand-made brittle.'], [$item['seo_title'], $item['seo_description']]);
        if (!preg_match('#^https?://#', rtrim((string) SLATE_URL, '/'))) {
            assert_eq([], $item['schema'], 'no site address, no structured data');
            return;
        }
        [$product, $crumbs] = $item['schema'];
        assert_eq(['Product', $scoTag . ' Seo Brittle', strtoupper($scoTag) . '-SEO', 'Hand-made brittle.'], [$product['@type'], $product['name'], $product['sku'], $product['description']]);
        assert_eq(['Offer', 'USD', '12.50', 'https://schema.org/InStock'], [$product['offers']['@type'], $product['offers']['priceCurrency'], $product['offers']['price'], $product['offers']['availability']]);
        assert_eq('BreadcrumbList', $crumbs['@type']);
        assert_eq($product['url'], end($crumbs['itemListElement'])['item'], 'the last crumb is the product');

        // Flavours at different prices: the range, as an AggregateOffer.
        $res = ShopStoreAPI::saveProduct(['title' => $scoTag . ' Seo Flavours', 'price' => '16.00', 'status' => 'published', 'currency' => 'USD', 'slug' => $scoTag . '-seo-flv', 'attributes' => 'Flavor: Plain, Spicy']);
        $fid = (int) $res['id'];
        $sco['products'][] = $fid;
        ShopStoreAPI::saveVariants($fid, ShopStoreAPI::attributes(ShopStoreAPI::productRow($fid)), [
            ShopStoreAPI::optionsKey(['Plain']) => ['enabled' => 1], ShopStoreAPI::optionsKey(['Spicy']) => ['enabled' => 1, 'price' => '18.00'],
        ]);
        $offer = (new \Slate\Plugin\Shop\Studio\ShopProductSingle())->find((int) current_tenant_id(), $scoTag . '-seo-flv')['schema'][0]['offers'];
        assert_eq(['AggregateOffer', '16.00', '18.00', 2], [$offer['@type'], $offer['lowPrice'], $offer['highPrice'], $offer['offerCount']]);
    });
} finally {
    ShopShipping::$transport = null;
    ShopStoreAPI::cartClear();
    ShopCoupons::setSessionCode('');
    foreach ($sco['orders'] as $oid) {
        Database::delete('shop_downloads', 'order_id = ?', [$oid]);
        Database::delete('shop_order_items', 'order_id = ?', [$oid]);
        Database::delete('shop_orders', 'id = ?', [$oid]);
    }
    foreach ($sco['products'] as $pid) {
        Database::delete('shop_order_items', 'product_id = ?', [$pid]);
        ShopStoreAPI::deleteProduct($pid);
    }
    foreach ($sco['coupons'] as $cid) ShopCoupons::delete($cid);
    Database::query("DELETE FROM shop_shipping_methods WHERE tenant_id = ?", [current_tenant_id()]);
    Database::query("DELETE FROM shop_shipping_zones WHERE tenant_id = ?", [current_tenant_id()]);
    Database::query("DELETE FROM shop_tax_rates WHERE tenant_id = ?", [current_tenant_id()]);
    foreach ($sco['kept_zones'] ?? [] as $r) Database::insert('shop_shipping_zones', $r);
    foreach ($sco['kept_methods'] ?? [] as $r) Database::insert('shop_shipping_methods', $r);
    foreach ($sco['kept_rates'] ?? [] as $r) Database::insert('shop_tax_rates', $r);
    foreach ($sco['settings'] as $key => $orig) {
        if ($orig === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), 'shop.' . $key]);
        else Database::setSetting('shop.' . $key, $orig);
    }
}
