<?php
/**
 * Shop — the checkout endpoint.
 *
 *   GET   the Checkout widget's fragment (`X-Studio-Fragment: 1`), or a plain checkout page without JavaScript;
 *         `?cancelled=1` (back from the payment page without paying) shows a note, and the cart is still there
 *   POST  the checkout form (CSRF-checked), by the button pressed (`action`):
 *           pay (default)  the order is created pending payment and the visitor goes to the chosen gateway's payment
 *                          page (`{redirect}` for studio-runtime.js, a 303 otherwise); errors come back as the form
 *           quote          the form again with the shipping rates and tax for the address typed
 *           coupon         apply the code typed; coupon_remove takes it off
 *
 * Guests check out with a name and an email; a signed-in customer's order is linked to their account.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
slate_public_entry('shop');
require_once __DIR__ . '/_shop.php';
require_once dirname(__DIR__) . '/ShopGuard.php';
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
$address = shop_posted_address($_POST);
$delivery = (string) ($_POST['delivery'] ?? '');
$rateId = is_string($_POST['shipping_rate'] ?? null) ? $_POST['shipping_rate'] : '';
$totalsNow = static fn(): array => ShopStoreAPI::cartTotals($delivery, $address, $rateId, (string) $address['email']);
$answer = static function (array $errors, int $status, string $notice = '') use ($json, $totalsNow): never {
    $html = shop_view_checkout($totalsNow(), $errors, $_POST, $notice);
    $json ? shop_json(['ok' => $status < 400, 'html' => $html], $status) : shop_html(shop_page(__('shop_checkout', 'Checkout'), $html), $status);
};

if (!csrf_verify() || !shop_same_site()) {
    $answer(['_' => __('shop_err_security', 'Security check failed. Please reload the page.')], 403);
}

$action = (string) ($_POST['action'] ?? 'pay');
if ($action === 'quote') {
    // The rates and tax for the address typed so far.
    $answer([], 200);
}
if ($action === 'coupon' && !ShopGuard::allowCoupon($_SERVER)) {
    $answer(['coupon' => __('shop_guard_coupon', 'Too many codes were tried. Please wait a few minutes.')], 429);
}
if ($action === 'coupon' || $action === 'coupon_remove') {
    if ($action === 'coupon_remove') {
        ShopCoupons::setSessionCode('');
        $answer([], 200, __('shop_coupon_removed_msg', 'The code was removed.'));
    }
    $res = ShopCoupons::apply((string) ($_POST['coupon'] ?? ''), ShopStoreAPI::cartLines(), (string) $address['email']);
    $res['ok'] ? $answer([], 200, $res['message']) : $answer(['coupon' => $res['message']], 200);
}

$customer = class_exists('Auth') && method_exists('Auth', 'customer') ? Auth::customer() : null;
// Card testing and bots: once the form is valid, before any order or payment page is made (ShopGuard).
$placed = ShopStoreAPI::placeOrder($_POST, is_array($customer) && !empty($customer['id']) ? (int) $customer['id'] : null,
    static fn(): array => ShopGuard::check($_POST, $_SERVER));
if (!$placed['ok']) {
    $answer($placed['errors'] ?? [], (int) ($placed['status'] ?? 422));
}

$pay = ShopStoreAPI::startPayment($placed['order'], isset($_POST['payment_gateway']) ? (string) $_POST['payment_gateway'] : null);
if (!$pay['ok']) {
    ShopStoreAPI::cancel((int) $placed['order']['id']);
    $answer(['_' => (string) $pay['error']], 503);
}

$json ? shop_json(['ok' => true, 'redirect' => $pay['url']]) : shop_redirect($pay['url']);
