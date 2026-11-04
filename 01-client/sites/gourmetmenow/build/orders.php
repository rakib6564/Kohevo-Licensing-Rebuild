<?php
// Gourmet Me Now — the old shop's orders and customers, from data/orders.json (made on the WooCommerce site with
// plugins/shop/tools/woo-export-orders.php; it holds customers' details, so it is gitignored and never committed).
// Run after catalog.php, so lines link to the imported products and flavours. Safe to rerun: orders already here
// are skipped. No email is sent and stock does not move. Failed (card-testing) orders are not in the export.
//   cd <site> && php <bundle>/build/orders.php
require_once __DIR__ . '/lib.php';
require_once SLATE_ROOT . '/plugins/shop/ShopOrderImport.php';

$file = dirname(__DIR__) . '/data/orders.json';
if (!is_file($file)) {
    echo "  orders: no data/orders.json, nothing imported\n";
    return;
}
$read = ShopOrderImport::read((string) file_get_contents($file));
if (!$read['ok']) {
    fwrite(STDERR, '  orders: ' . $read['error'] . "\n");
    return;
}
$plan = ShopOrderImport::plan($read['orders']);
$res = ShopOrderImport::run($read['orders']);
printf("  orders: %d imported (%d lines, %d not linked to a product), %d already here, %d left out; customers: %d new, %d existing\n",
    $res['created'], $res['lines'], $res['unmatched'], $res['existing'], $res['skipped'], $res['customers_created'], $res['customers_linked']);
foreach (array_merge($plan['warnings'], $res['errors']) as $w) echo '    - ', $w, "\n";
