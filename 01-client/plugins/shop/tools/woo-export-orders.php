<?php
/**
 * Shop — export WooCommerce orders for Kohevo's order importer (Shop › Import › Orders).
 *
 * Run it on the WooCommerce site with WP-CLI; it only reads:
 *
 *   wp --skip-themes eval-file woo-export-orders.php > orders.json
 *   wp --skip-themes eval-file woo-export-orders.php all > orders.json   (failed and draft orders too)
 *
 * The file holds customers' names, addresses, emails and phone numbers: keep it out of version control and delete it
 * once it is imported. Its shape is WooCommerce's REST API order (GET /wp-json/wc/v3/orders), plus `notes` (the
 * order notes, private and to the customer) and `refunds` with their amounts, so a REST export works as well.
 */
// Command line only: it ships inside the Shop plugin, whose folder a web server may reach.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!function_exists('wc_get_orders')) {
    fwrite(STDERR, "WooCommerce is not active on this site.\n");
    return;
}
$all = in_array('all', (array) ($args ?? []), true);
$statuses = $all ? array_keys(wc_get_order_statuses()) : ['wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded', 'wc-cancelled', 'wc-pending'];

$money = static fn($v): string => wc_format_decimal((string) $v, 2);
$date = static fn($d): ?string => $d instanceof WC_DateTime ? gmdate('Y-m-d\TH:i:s', $d->getTimestamp()) : null;
$address = static function (WC_Order $o, string $type): array {
    $keys = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];
    if ($type === 'billing') { $keys[] = 'email'; $keys[] = 'phone'; }
    if ($type === 'shipping') { $keys[] = 'phone'; }
    $out = [];
    foreach ($keys as $k) {
        $m = 'get_' . $type . '_' . $k;
        $out[$k] = method_exists($o, $m) ? (string) $o->$m() : '';
    }
    return $out;
};

$out = [];
$page = 1;
do {
    $ids = wc_get_orders(['status' => $statuses, 'type' => 'shop_order', 'limit' => 200, 'page' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids']);
    foreach ($ids as $id) {
        $o = wc_get_order($id);
        if (!$o instanceof WC_Order) continue;
        $lines = [];
        foreach ($o->get_items('line_item') as $item) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            $meta = [];
            foreach ($item->get_formatted_meta_data('_', true) as $m) {
                $meta[] = ['key' => wp_strip_all_tags((string) $m->display_key), 'value' => wp_strip_all_tags((string) $m->display_value)];
            }
            $lines[] = [
                'id' => $item->get_id(),
                'name' => $item->get_name(),
                'product_id' => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'quantity' => $item->get_quantity(),
                'sku' => $product ? (string) $product->get_sku() : '',
                'subtotal' => $money($item->get_subtotal()),
                'total' => $money($item->get_total()),
                'total_tax' => $money($item->get_total_tax()),
                'meta_data' => $meta,
            ];
        }
        $shipping = [];
        foreach ($o->get_items('shipping') as $s) {
            $shipping[] = ['method_title' => $s->get_method_title(), 'method_id' => $s->get_method_id(), 'total' => $money($s->get_total())];
        }
        $fees = [];
        foreach ($o->get_items('fee') as $f) $fees[] = ['name' => $f->get_name(), 'total' => $money($f->get_total())];
        $coupons = [];
        foreach ($o->get_items('coupon') as $c) $coupons[] = ['code' => $c->get_code(), 'discount' => $money($c->get_discount())];
        $refunds = [];
        foreach ($o->get_refunds() as $r) $refunds[] = ['id' => $r->get_id(), 'reason' => $r->get_reason(), 'total' => $money(-1 * abs((float) $r->get_amount())), 'date_created_gmt' => $date($r->get_date_created())];
        $notes = [];
        foreach (wc_get_order_notes(['order_id' => $o->get_id(), 'order' => 'ASC']) as $n) {
            $notes[] = ['date_created_gmt' => $date($n->date_created), 'note' => wp_strip_all_tags((string) $n->content), 'customer_note' => (bool) $n->customer_note, 'added_by' => (string) $n->added_by];
        }
        $out[] = [
            'id' => $o->get_id(),
            'number' => (string) $o->get_order_number(),
            'status' => $o->get_status(),
            'currency' => $o->get_currency(),
            'prices_include_tax' => $o->get_prices_include_tax(),
            'date_created_gmt' => $date($o->get_date_created()),
            'date_paid_gmt' => $date($o->get_date_paid()),
            'date_completed_gmt' => $date($o->get_date_completed()),
            'customer_id' => $o->get_customer_id(),
            'customer_note' => $o->get_customer_note(),
            'billing' => $address($o, 'billing'),
            'shipping' => $address($o, 'shipping'),
            'payment_method' => $o->get_payment_method(),
            'payment_method_title' => $o->get_payment_method_title(),
            'transaction_id' => $o->get_transaction_id(),
            'discount_total' => $money($o->get_discount_total()),
            'shipping_total' => $money($o->get_shipping_total()),
            'total_tax' => $money($o->get_total_tax()),
            'total' => $money($o->get_total()),
            'line_items' => $lines,
            'shipping_lines' => $shipping,
            'fee_lines' => $fees,
            'coupon_lines' => $coupons,
            'refunds' => $refunds,
            'notes' => $notes,
        ];
    }
    $page++;
} while (count($ids) === 200);

echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
