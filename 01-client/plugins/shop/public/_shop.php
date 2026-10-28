<?php
/**
 * Shop — what the public endpoints share: the prologue, request helpers and the HTML of the cart, mini cart, checkout
 * and order confirmation.
 *
 * Studio pages are cached for everyone and carry no session, so the Cart, Mini cart, Checkout and Order confirmation
 * widgets render a placeholder that studio-runtime.js fills from these endpoints (`X-Studio-Fragment: 1`, the
 * visitor's session cookie attached). The same HTML is served as a plain page when there is no JavaScript. Every
 * class name is a Studio-style `sb-shop-*` name, so the widget's style parts reach the fetched markup.
 */

if (!defined('SLATE_ROOT')) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/ShopStoreAPI.php';

/** The endpoint prologue: Shop active and licensed, never cached, never indexed. */
function shop_public_boot(): void {
    slate_public_entry('shop');
    ModuleGuard::requirePublic('shop');
    header('Cache-Control: private, no-store');
    header('X-Robots-Tag: noindex');
    header('X-Content-Type-Options: nosniff');
}

/** Whether the request came from studio-runtime.js filling or sending a fragment. */
function shop_is_fragment(): bool {
    return ($_SERVER['HTTP_X_STUDIO_FRAGMENT'] ?? '') === '1';
}

function shop_wants_json(): bool {
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

/**
 * A state-changing request from this site: its Origin (or, without one, its Referer) names this site's host. A request
 * with neither is let through; the session cookie is SameSite=Lax, so a cross-site POST carries no cart to change.
 */
function shop_same_site(): bool {
    $own = parse_url((string) SLATE_URL, PHP_URL_HOST);
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        $v = (string) ($_SERVER[$h] ?? '');
        if ($v !== '' && $v !== 'null') {
            return is_string($own) && strcasecmp((string) parse_url($v, PHP_URL_HOST), $own) === 0;
        }
    }
    return true;
}

/** A path on this site to go back to after a plain (no-JS) form post, or null. */
function shop_return_path(mixed $raw): ?string {
    $raw = is_string($raw) ? trim($raw) : '';
    if ($raw === '' || !str_starts_with($raw, '/') || str_starts_with($raw, '//') || preg_match('/[\s\\\\]/', $raw)) return null;
    return $raw;
}

function shop_json(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function shop_html(string $html, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

function shop_redirect(string $url): never {
    header('Location: ' . $url, true, 303);
    exit;
}

/** A plain page around a view, for visitors without JavaScript. */
function shop_page(string $title, string $body): string {
    $site = (string) (Database::setting('site_name') ?: 'Kohevo');
    $lang = class_exists('I18n') && method_exists('I18n', 'locale') ? substr((string) I18n::locale(), 0, 2) : 'en';
    return '<!doctype html><html lang="' . e($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . e($title . ' | ' . $site) . '</title>'
        . '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:860px;margin:0 auto;padding:24px 16px;color:#1b1b1b}'
        . 'table{width:100%;border-collapse:collapse}td,th{padding:8px;border-bottom:1px solid #e5e5e5;text-align:left}'
        . 'input,select,textarea{font:inherit;padding:8px;width:100%;box-sizing:border-box}label{display:block;margin:12px 0 4px;font-weight:600}'
        . '.sb-button{display:inline-block;padding:10px 18px;background:#1b1b1b;color:#fff;border:0;border-radius:4px;text-decoration:none;cursor:pointer}'
        . '.sb-shop-error{color:#b00020}.sb-shop-cart__image{width:56px;height:56px;object-fit:cover}</style></head><body>'
        . '<p><a href="' . e(rtrim((string) SLATE_URL, '/') . '/') . '">← ' . e($site) . '</a></p><h1>' . e($title) . '</h1>' . $body . '</body></html>';
}

function shop_money(int $cents, string $currency): string {
    return e(ShopStoreAPI::money($cents, $currency));
}

/** The mini cart: a link to the cart page with the item count (and subtotal). */
function shop_view_mini(array $totals, bool $showTotal = true): string {
    $count = (int) $totals['count'];
    $label = $count === 1 ? __('shop_one_item', '1 item') : sprintf(__('shop_n_items', '%d items'), $count);
    return '<a class="sb-shop-mini__link" href="' . e(ShopStoreAPI::pageUrl('cart_page')) . '" aria-label="' . e(__('shop_cart', 'Cart') . ', ' . $label) . '">'
        . '<span class="sb-shop-mini__label">' . e(__('shop_cart', 'Cart')) . '</span>'
        . '<span class="sb-shop-mini__count" data-count="' . $count . '">' . $count . '</span>'
        . ($showTotal && $count > 0 ? '<span class="sb-shop-mini__total">' . shop_money((int) $totals['subtotal_cents'], (string) $totals['currency']) . '</span>' : '')
        . '</a>';
}

/** The cart with quantity and remove controls (each a small CSRF-protected form). */
function shop_view_cart(array $totals, string $notice = ''): string {
    if ($totals['lines'] === []) {
        return '<div class="sb-shop-cart sb-shop-cart--empty">'
            . ($notice !== '' ? '<p class="sb-shop-notice" role="status">' . e($notice) . '</p>' : '')
            . '<p class="sb-shop-cart__empty">' . e(__('shop_cart_empty', 'Your cart is empty.')) . '</p></div>';
    }
    $action = e(ShopStoreAPI::endpoint('cart.php'));
    $rows = '';
    foreach ($totals['lines'] as $line) {
        $pid = (int) $line['product_id'];
        $rows .= '<tr class="sb-shop-cart__line" data-product="' . $pid . '">'
            . '<td class="sb-shop-cart__product">'
            . ($line['image'] !== '' ? '<img class="sb-shop-cart__image" src="' . e($line['image']) . '" alt="" width="64" height="64" loading="lazy">' : '')
            . '<a class="sb-shop-cart__title" href="' . e(rtrim((string) SLATE_URL, '/') . $line['url']) . '">' . e($line['title']) . '</a>'
            . ($line['capped'] ? '<span class="sb-shop-cart__note">' . e(sprintf(__('shop_err_only_n', 'Only %d left in stock.'), $line['max_qty'])) . '</span>' : '')
            . '</td>'
            . '<td class="sb-shop-cart__price">' . shop_money((int) $line['unit_cents'], (string) $line['currency']) . '</td>'
            . '<td class="sb-shop-cart__qty"><form method="post" action="' . $action . '" class="sb-shop-cart__qty-form">' . csrf_field()
            . '<input type="hidden" name="action" value="update"><input type="hidden" name="product_id" value="' . $pid . '">'
            . '<label class="sb-shop-sr" for="sb-shop-qty-' . $pid . '">' . e(__('shop_quantity', 'Quantity')) . '</label>'
            . '<input class="sb-shop-cart__qty-input" id="sb-shop-qty-' . $pid . '" type="number" name="qty" min="0" max="' . (int) $line['max_qty'] . '" value="' . (int) $line['qty'] . '" inputmode="numeric">'
            . '<button type="submit" class="sb-shop-cart__update">' . e(__('shop_update', 'Update')) . '</button></form></td>'
            . '<td class="sb-shop-cart__line-total">' . shop_money((int) $line['line_cents'], (string) $line['currency']) . '</td>'
            . '<td class="sb-shop-cart__remove-cell"><form method="post" action="' . $action . '">' . csrf_field()
            . '<input type="hidden" name="action" value="remove"><input type="hidden" name="product_id" value="' . $pid . '">'
            . '<button type="submit" class="sb-shop-cart__remove" aria-label="' . e(sprintf(__('shop_remove_x', 'Remove %s'), $line['title'])) . '">×</button></form></td>'
            . '</tr>';
    }
    $cur = (string) $totals['currency'];
    $freeOver = (int) $totals['free_over_cents'];
    $freeNote = '';
    if ($freeOver > 0 && in_array('shipping', ShopStoreAPI::deliveryOptions(), true) && $totals['subtotal_cents'] < $freeOver) {
        $freeNote = '<p class="sb-shop-cart__free">' . e(sprintf(__('shop_free_shipping_from', 'Free shipping from %s.'), ShopStoreAPI::money($freeOver, $cur))) . '</p>';
    }
    return '<div class="sb-shop-cart">'
        . ($notice !== '' ? '<p class="sb-shop-notice" role="status">' . e($notice) . '</p>' : '')
        . '<table class="sb-shop-cart__table"><thead><tr><th scope="col">' . e(__('shop_product', 'Product')) . '</th><th scope="col">' . e(__('shop_price', 'Price'))
        . '</th><th scope="col">' . e(__('shop_quantity', 'Quantity')) . '</th><th scope="col">' . e(__('shop_total', 'Total')) . '</th><th scope="col"><span class="sb-shop-sr">' . e(__('shop_remove', 'Remove')) . '</span></th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>'
        . '<div class="sb-shop-cart__totals"><p class="sb-shop-cart__subtotal"><span>' . e(__('shop_subtotal', 'Subtotal')) . '</span> <strong>' . shop_money((int) $totals['subtotal_cents'], $cur) . '</strong></p>'
        . '<p class="sb-shop-cart__vat">' . e(__('shop_prices_incl_vat', 'Prices include VAT. Shipping is calculated at checkout.')) . '</p>' . $freeNote
        . '<p class="sb-button-row"><a class="sb-button sb-button--primary sb-shop-cart__checkout" href="' . e(ShopStoreAPI::pageUrl('checkout_page')) . '">' . e(__('shop_go_checkout', 'Proceed to checkout')) . '</a></p></div>'
        . '</div>';
}

/**
 * The checkout form with the order summary.
 *
 * @param array<string, string> $errors field => message ('_' for the form)
 * @param array<string, mixed>  $old    what was posted
 */
function shop_view_checkout(array $totals, array $errors = [], array $old = [], string $notice = ''): string {
    if ($totals['lines'] === []) {
        return '<div class="sb-shop-checkout sb-shop-checkout--empty"><p class="sb-shop-cart__empty">' . e(__('shop_cart_empty', 'Your cart is empty.')) . '</p>'
            . '<p class="sb-button-row"><a class="sb-button" href="' . e(ShopStoreAPI::pageUrl('cart_page')) . '">' . e(__('shop_back_to_cart', 'Back to cart')) . '</a></p></div>';
    }
    $cust = class_exists('Auth') && method_exists('Auth', 'customer') ? (Auth::customer() ?: []) : [];
    $val = static fn(string $k, string $fallback = ''): string => e(isset($old[$k]) ? (string) $old[$k] : $fallback);
    $err = static fn(string $k): string => isset($errors[$k]) ? '<span class="sb-shop-error" id="sb-shop-err-' . e($k) . '">' . e($errors[$k]) . '</span>' : '';
    $aria = static fn(string $k): string => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="sb-shop-err-' . e($k) . '"' : '';
    $field = static function (string $k, string $label, string $type = 'text', bool $required = true, string $auto = '', string $fallback = '') use ($val, $err, $aria): string {
        return '<p class="sb-shop-checkout__field sb-shop-checkout__field--' . e($k) . '"><label for="sb-shop-' . e($k) . '">' . e($label) . ($required ? ' <span aria-hidden="true">*</span>' : '') . '</label>'
            . '<input id="sb-shop-' . e($k) . '" name="' . e($k) . '" type="' . e($type) . '"' . ($required ? ' required' : '') . ($auto !== '' ? ' autocomplete="' . e($auto) . '"' : '')
            . ' value="' . $val($k, $fallback) . '"' . $aria($k) . '>' . $err($k) . '</p>';
    };
    $options = ShopStoreAPI::deliveryOptions();
    $delivery = in_array($old['delivery'] ?? '', $options, true) ? (string) $old['delivery'] : $totals['delivery'];
    $cur = (string) $totals['currency'];
    $deliveryHtml = '<fieldset class="sb-shop-checkout__delivery"><legend>' . e(__('shop_delivery', 'Delivery')) . '</legend>';
    foreach ($options as $opt) {
        $cost = ShopStoreAPI::shippingFor((int) $totals['subtotal_cents'], $opt);
        $label = $opt === 'pickup'
            ? (ShopStoreAPI::setting('pickup_label') !== '' ? ShopStoreAPI::setting('pickup_label') : __('shop_pickup', 'Pick up (free)'))
            : __('shop_ship_to_me', 'Ship to my address') . ' — ' . ($cost > 0 ? ShopStoreAPI::money($cost, $cur) : __('shop_free', 'free'));
        $deliveryHtml .= '<label class="sb-shop-checkout__option"><input type="radio" name="delivery" value="' . e($opt) . '"' . ($opt === $delivery ? ' checked' : '') . '> ' . e($label) . '</label>';
    }
    $deliveryHtml .= $err('delivery') . '</fieldset>';
    $countries = '';
    foreach (ShopStoreAPI::countries() as $c) {
        $countries .= '<option value="' . e($c) . '"' . (($old['country'] ?? '') === $c ? ' selected' : '') . '>' . e($c) . '</option>';
    }
    $address = in_array('shipping', $options, true)
        ? '<fieldset class="sb-shop-checkout__address"><legend>' . e(__('shop_delivery_address', 'Delivery address')) . '</legend>'
            . '<p class="sb-shop-checkout__hint">' . e(__('shop_address_hint', 'Needed only when we ship your order.')) . '</p>'
            . $field('address_line1', __('shop_address_line1', 'Street and number'), 'text', false, 'address-line1')
            . $field('address_line2', __('shop_address_line2', 'Apartment, box (optional)'), 'text', false, 'address-line2')
            . $field('postal_code', __('shop_postal_code', 'Postal code'), 'text', false, 'postal-code')
            . $field('city', __('shop_city', 'Town or city'), 'text', false, 'address-level2')
            . '<p class="sb-shop-checkout__field"><label for="sb-shop-country">' . e(__('shop_country', 'Country')) . '</label><select id="sb-shop-country" name="country" autocomplete="country"' . $aria('country') . '>' . $countries . '</select>' . $err('country') . '</p>'
            . '</fieldset>'
        : '';
    $summary = '';
    foreach ($totals['lines'] as $line) {
        $summary .= '<li class="sb-shop-summary__line"><span>' . e($line['title']) . ' × ' . (int) $line['qty'] . '</span> <span>' . shop_money((int) $line['line_cents'], (string) $line['currency']) . '</span></li>';
    }
    $shipping = ShopStoreAPI::shippingFor((int) $totals['subtotal_cents'], $delivery);
    return '<div class="sb-shop-checkout">'
        . ($notice !== '' ? '<p class="sb-shop-notice" role="status">' . e($notice) . '</p>' : '')
        . (isset($errors['_']) ? '<p class="sb-shop-error sb-shop-checkout__error" role="alert">' . e($errors['_']) . '</p>' : '')
        . '<form class="sb-shop-checkout__form" method="post" action="' . e(ShopStoreAPI::endpoint('checkout.php')) . '" novalidate>' . csrf_field()
        . '<div class="sb-shop-checkout__details"><fieldset class="sb-shop-checkout__contact"><legend>' . e(__('shop_your_details', 'Your details')) . '</legend>'
        . $field('name', __('shop_full_name', 'Full name'), 'text', true, 'name', (string) ($cust['name'] ?? ''))
        . $field('email', __('shop_email', 'Email'), 'email', true, 'email', (string) ($cust['email'] ?? ''))
        . $field('phone', __('shop_phone', 'Phone (optional)'), 'tel', false, 'tel')
        . '</fieldset>' . $deliveryHtml . $address
        . '<p class="sb-shop-checkout__field"><label for="sb-shop-notes">' . e(__('shop_notes', 'Order notes (optional)')) . '</label><textarea id="sb-shop-notes" name="notes" rows="3" maxlength="2000">' . $val('notes') . '</textarea></p></div>'
        . '<aside class="sb-shop-summary" aria-label="' . e(__('shop_order_summary', 'Order summary')) . '"><h2 class="sb-shop-summary__title">' . e(__('shop_order_summary', 'Order summary')) . '</h2><ul class="sb-shop-summary__lines">' . $summary . '</ul>'
        . '<p class="sb-shop-summary__row"><span>' . e(__('shop_subtotal', 'Subtotal')) . '</span> <span>' . shop_money((int) $totals['subtotal_cents'], $cur) . '</span></p>'
        . '<p class="sb-shop-summary__row"><span>' . e(__('shop_shipping', 'Shipping')) . '</span> <span>' . ($shipping > 0 ? shop_money($shipping, $cur) : e(__('shop_free', 'free'))) . '</span></p>'
        . '<p class="sb-shop-summary__total"><span>' . e(__('shop_total_incl_vat', 'Total (incl. VAT)')) . '</span> <strong>' . shop_money((int) $totals['subtotal_cents'] + $shipping, $cur) . '</strong></p>'
        . '<p class="sb-button-row"><button type="submit" class="sb-button sb-button--primary sb-shop-checkout__pay">' . e(__('shop_pay_now', 'Continue to payment')) . '</button></p>'
        . '<p class="sb-shop-checkout__secure">' . e(__('shop_secure_note', 'You pay securely with Stripe. Your card details never reach this site.')) . '</p></aside>'
        . '</form></div>';
}

/** An order's confirmation: status, lines, totals and where it goes. */
function shop_view_order(array $order): string {
    $cur = (string) $order['currency'];
    $labels = ShopStoreAPI::statusLabels();
    $lines = '';
    foreach (ShopStoreAPI::orderItems((int) $order['id']) as $item) {
        $lines .= '<li class="sb-shop-order__line"><span>' . e($item['title']) . ' × ' . (int) $item['qty'] . '</span> <span>' . shop_money((int) $item['line_total_cents'], $cur) . '</span></li>';
    }
    $paid = in_array($order['status'], ['paid', 'fulfilled'], true);
    $lead = $paid
        ? sprintf(__('shop_order_thanks', 'Thank you, %s. Your order is confirmed and a receipt is on its way to %s.'), $order['name'], $order['email'])
        : ($order['status'] === 'pending_payment'
            ? __('shop_order_processing', 'We are confirming your payment. This page updates when it is done; you can also refresh it.')
            : sprintf(__('shop_order_status_is', 'This order is %s.'), mb_strtolower($labels[$order['status']] ?? $order['status'])));
    $where = $order['delivery'] === 'pickup'
        ? e(ShopStoreAPI::setting('pickup_label') !== '' ? ShopStoreAPI::setting('pickup_label') : __('shop_pickup', 'Pick up (free)'))
        : e(implode(', ', array_filter([$order['address_line1'], $order['address_line2'], trim($order['postal_code'] . ' ' . $order['city']), $order['country']])));
    return '<div class="sb-shop-order" data-status="' . e($order['status']) . '">'
        . '<p class="sb-shop-order__lead">' . e($lead) . '</p>'
        . '<dl class="sb-shop-order__meta"><dt>' . e(__('shop_order_number', 'Order number')) . '</dt><dd class="sb-shop-order__ref">' . e($order['ref']) . '</dd>'
        . '<dt>' . e(__('shop_status', 'Status')) . '</dt><dd class="sb-shop-order__status">' . e($labels[$order['status']] ?? $order['status']) . '</dd>'
        . '<dt>' . e(__('shop_delivery', 'Delivery')) . '</dt><dd>' . $where . '</dd></dl>'
        . '<ul class="sb-shop-order__lines">' . $lines . '</ul>'
        . ((int) $order['shipping_cents'] > 0 ? '<p class="sb-shop-order__row"><span>' . e(__('shop_shipping', 'Shipping')) . '</span> <span>' . shop_money((int) $order['shipping_cents'], $cur) . '</span></p>' : '')
        . '<p class="sb-shop-order__total"><span>' . e(__('shop_total_incl_vat', 'Total (incl. VAT)')) . '</span> <strong>' . shop_money((int) $order['total_cents'], $cur) . '</strong></p>'
        . '</div>';
}
