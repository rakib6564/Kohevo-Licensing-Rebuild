<?php
/**
 * Shop — the cart endpoint.
 *
 *   GET  ?view=mini           the Mini cart widget's fragment
 *   GET  ?view=cart (default) the Cart widget's fragment; without `X-Studio-Fragment` a plain cart page (no JavaScript)
 *   GET  Accept: application/json   {count, subtotal_cents, currency}
 *   POST action=add           product_id, qty[, variant_id | opt[]][, return]: from an Add to cart button on a cached
 *                             Studio page, so it carries no CSRF token; it must come from this site (Origin/Referer)
 *                             and only ever adds a published product to the visitor's own cart. A product with
 *                             variants needs the variant (its id, or one value per attribute in `opt[]`).
 *   POST action=update|remove line (or product_id)[, qty]: from the cart's own forms, CSRF-checked
 *
 * A JSON caller (studio-runtime.js) gets {ok, message, count[, html]}; a plain form post is sent back with a 303.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
slate_public_entry('shop');
require_once __DIR__ . '/_shop.php';
shop_public_boot();

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $view = (string) ($_GET['view'] ?? 'cart');
    $totals = ShopStoreAPI::cartTotals();
    if (shop_wants_json() && !shop_is_fragment()) {
        shop_json(['count' => $totals['count'], 'subtotal_cents' => $totals['subtotal_cents'], 'currency' => $totals['currency']]);
    }
    if ($view === 'mini') {
        $label = is_string($_GET['label'] ?? null) ? mb_substr(trim($_GET['label']), 0, 40) : '';
        shop_html(shop_view_mini($totals, ($_GET['total'] ?? '1') !== '0', $label));
    }
    $html = shop_view_cart($totals);
    shop_html(shop_is_fragment() ? $html : shop_page(__('shop_cart', 'Cart'), $html));
}

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$productId = (int) ($_POST['product_id'] ?? 0);
$json = shop_wants_json();

if ($action === 'add') {
    if (!shop_same_site()) {
        $json ? shop_json(['ok' => false, 'message' => __('shop_err_security', 'Security check failed. Please reload the page.')], 403) : shop_html('Forbidden', 403);
    }
    $options = is_array($_POST['opt'] ?? null) ? array_map(static fn($v): string => is_string($v) ? $v : '', $_POST['opt']) : [];
    $res = ShopStoreAPI::cartAdd($productId, max(1, min(ShopStoreAPI::MAX_QTY, (int) ($_POST['qty'] ?? 1))), (int) ($_POST['variant_id'] ?? 0), $options);
    $count = ShopStoreAPI::cartTotals()['count'];
    if ($json) {
        shop_json([
            'ok'      => $res['ok'],
            'message' => $res['ok'] ? __('shop_added', 'Added to your cart.') : (string) $res['error'],
            'count'   => $count,
        ], $res['ok'] ? 200 : 422);
    }
    // Back to the page the button was on (a path of this site), else the cart.
    $back = shop_return_path($_POST['return'] ?? null);
    $site = parse_url((string) SLATE_URL);
    $origin = ($site['scheme'] ?? 'https') . '://' . ($site['host'] ?? '') . (isset($site['port']) ? ':' . $site['port'] : '');
    shop_redirect($back !== null ? $origin . $back : ShopStoreAPI::pageUrl('cart_page'));
}

if ($action === 'update' || $action === 'remove') {
    if (!csrf_verify()) {
        $json ? shop_json(['ok' => false, 'message' => __('shop_err_security', 'Security check failed. Please reload the page.')], 403) : shop_html('Forbidden', 403);
    }
    $line = is_string($_POST['line'] ?? null) ? $_POST['line'] : (string) $productId;
    ShopStoreAPI::cartSet($line, $action === 'remove' ? 0 : max(0, (int) ($_POST['qty'] ?? 0)));
    $totals = ShopStoreAPI::cartTotals();
    if ($json) {
        shop_json(['ok' => true, 'html' => shop_view_cart($totals, __('shop_cart_updated', 'Your cart was updated.')), 'count' => $totals['count']]);
    }
    shop_redirect(ShopStoreAPI::pageUrl('cart_page'));
}

$json ? shop_json(['ok' => false, 'message' => 'Unknown action.'], 400) : shop_html('Bad request', 400);
