<?php
/**
 * Shop — where Stripe Checkout sends the visitor back after paying.
 *
 * `?order=<ref>&key=<access key>&session_id=<cs_…>`: the order must be the visitor's (ref and key), and the session
 * must be the one Stripe made for it; it is read back from Stripe, and a paid one marks the order paid unless the
 * webhook already did. The cart is emptied, and the visitor lands on the order-received page.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
slate_public_entry('shop');
require_once __DIR__ . '/_shop.php';
shop_public_boot();

$order = ShopStoreAPI::confirmReturn((string) ($_GET['order'] ?? ''), (string) ($_GET['key'] ?? ''), (string) ($_GET['session_id'] ?? ''));
if ($order === null) {
    shop_html(shop_page(__('shop_order', 'Order'), '<p>' . e(__('shop_err_order_link', 'This order link is not valid.')) . '</p>'), 404);
}
shop_redirect(ShopStoreAPI::confirmationUrl($order));
