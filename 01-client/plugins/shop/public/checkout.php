<?php
/**
 * Shop — the checkout endpoint.
 *
 *   GET   the Checkout widget's fragment (`X-Studio-Fragment: 1`), or a plain checkout page without JavaScript;
 *         `?cancelled=1` (back from Stripe without paying) shows a note, and the cart is still there
 *   POST  the checkout form (CSRF-checked): the order is created pending payment and the visitor goes to Stripe
 *         Checkout (`{redirect}` for studio-runtime.js, a 303 otherwise). Errors come back as the form again.
 *
 * Guests check out with a name and an email; a signed-in customer's order is linked to their account.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
slate_public_entry('shop');
require_once __DIR__ . '/_shop.php';
shop_public_boot();

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$fragment = shop_is_fragment();

if ($method === 'GET') {
    $notice = !empty($_GET['cancelled']) ? __('shop_payment_cancelled', 'Payment was cancelled. Your cart is still here.') : '';
    $html = shop_view_checkout(ShopStoreAPI::cartTotals(), [], [], $notice);
    shop_html($fragment ? $html : shop_page(__('shop_checkout', 'Checkout'), $html));
}

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$json = shop_wants_json();
$fail = static function (array $errors, int $status) use ($json): never {
    $html = shop_view_checkout(ShopStoreAPI::cartTotals((string) ($_POST['delivery'] ?? '')), $errors, $_POST);
    $json ? shop_json(['ok' => false, 'html' => $html], $status) : shop_html(shop_page(__('shop_checkout', 'Checkout'), $html), $status);
};

if (!csrf_verify() || !shop_same_site()) {
    $fail(['_' => __('shop_err_security', 'Security check failed. Please reload the page.')], 403);
}

$customer = class_exists('Auth') && method_exists('Auth', 'customer') ? Auth::customer() : null;
$placed = ShopStoreAPI::placeOrder($_POST, is_array($customer) && !empty($customer['id']) ? (int) $customer['id'] : null);
if (!$placed['ok']) {
    $fail($placed['errors'] ?? [], 422);
}

$pay = ShopStoreAPI::startPayment($placed['order']);
if (!$pay['ok']) {
    ShopStoreAPI::cancel((int) $placed['order']['id']);
    $fail(['_' => (string) $pay['error']], 503);
}

$json ? shop_json(['ok' => true, 'redirect' => $pay['url']]) : shop_redirect($pay['url']);
