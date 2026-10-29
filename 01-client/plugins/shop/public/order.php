<?php
/**
 * Shop — an order's confirmation.
 *
 * `?order=<ref>&key=<access key>`: the Order confirmation widget's fragment (`X-Studio-Fragment: 1`; the widget passes
 * on the ref and key of the order-received page's own address), or a plain page without JavaScript. Ref and key must
 * both match; anything else is the same "not valid" answer, so a ref alone reveals nothing.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
slate_public_entry('shop');
require_once __DIR__ . '/_shop.php';
shop_public_boot();

$order = ShopStoreAPI::orderByRefKey((string) ($_GET['order'] ?? ''), (string) ($_GET['key'] ?? ''));
if ($order === null) {
    $html = '<div class="sb-shop-order sb-shop-order--missing"><p>' . e(__('shop_err_order_link', 'This order link is not valid.')) . '</p></div>';
    shop_html(shop_is_fragment() ? $html : shop_page(__('shop_order', 'Order'), $html), shop_is_fragment() ? 200 : 404);
}
$html = shop_view_order($order);
shop_html(shop_is_fragment() ? $html : shop_page(sprintf(__('shop_order_x', 'Order %s'), $order['ref']), $html));
