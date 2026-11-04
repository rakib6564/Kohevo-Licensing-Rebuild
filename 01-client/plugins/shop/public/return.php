<?php
/**
 * Shop — where the payment gateway sends the visitor back after paying.
 *
 * `?order=<ref>&key=<access key>&gw=<gateway>&ref=<payment ref>`: the order must be the visitor's (ref and key), and
 * the payment ref must be the one its checkout was given; the payment is confirmed with the gateway, and a paid one
 * marks the order paid unless the webhook already did. The cart is emptied, and the visitor lands on the
 * order-received page. (`session_id=` is the same thing for a Stripe checkout started before the payment layer.)
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
slate_public_entry('shop');
require_once __DIR__ . '/_shop.php';
shop_public_boot();

$paymentRef = (string) ($_GET['ref'] ?? ($_GET['session_id'] ?? ''));
$gateway    = (string) ($_GET['gw'] ?? 'stripe');
$order = ShopStoreAPI::confirmReturn((string) ($_GET['order'] ?? ''), (string) ($_GET['key'] ?? ''), $paymentRef, $gateway);
if ($order === null) {
    shop_html(shop_page(__('shop_order', 'Order'), '<p>' . e(__('shop_err_order_link', 'This order link is not valid.')) . '</p>'), 404);
}
shop_redirect(ShopStoreAPI::confirmationUrl($order));
