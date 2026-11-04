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
require_once dirname(__DIR__) . '/ShopGuard.php';

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
        . '<p><a href="' . e(rtrim((string) SLATE_URL, '/') . '/') . '">← ' . e($site) . '</a></p><h1>' . e($title) . '</h1>' . $body . \Slate\Services\Admin\FrontAdminBar::scriptTag() . '</body></html>';
}

function shop_money(int $cents, string $currency): string {
    return e(ShopStoreAPI::money($cents, $currency));
}

/** The mini cart: a link to the cart page with the item count (and subtotal); `$label` is the widget's own label. */
function shop_view_mini(array $totals, bool $showTotal = true, string $label = ''): string {
    $count = (int) $totals['count'];
    $items = $count === 1 ? __('shop_one_item', '1 item') : sprintf(__('shop_n_items', '%d items'), $count);
    $name = $label !== '' ? $label : __('shop_cart', 'Cart');
    return '<a class="sb-shop-mini__link" href="' . e(ShopStoreAPI::pageUrl('cart_page')) . '" aria-label="' . e($name . ', ' . $items) . '">'
        . '<span class="sb-shop-mini__label">' . e($name) . '</span>'
        . '<span class="sb-shop-mini__count" data-count="' . $count . '">' . $count . '</span>'
        . ($showTotal && $count > 0 ? '<span class="sb-shop-mini__total">' . shop_money((int) $totals['subtotal_cents'], (string) $totals['currency']) . '</span>' : '')
        . '</a>';
}

/** The cart's "Continue shopping" button, when the Shop settings name a shop page. */
function shop_view_continue(): string {
    $url = ShopStoreAPI::shopPageUrl();
    return $url === '' ? '' : '<p class="sb-button-row"><a class="sb-button sb-button--secondary sb-shop-cart__continue" href="' . e($url) . '">' . e(__('shop_continue_shopping', 'Continue shopping')) . '</a></p>';
}

/** The cart with quantity and remove controls (each a small CSRF-protected form). */
function shop_view_cart(array $totals, string $notice = '', string $couponError = '', string $couponValue = ''): string {
    $totals = shop_totals_defaults($totals);
    if ($totals['lines'] === []) {
        return '<div class="sb-shop-cart sb-shop-cart--empty">'
            . ($notice !== '' ? '<p class="sb-shop-notice" role="status">' . e($notice) . '</p>' : '')
            . '<p class="sb-shop-cart__empty">' . e(__('shop_cart_empty', 'Your cart is empty.')) . '</p>' . shop_view_continue() . '</div>';
    }
    $action = e(ShopStoreAPI::endpoint('cart.php'));
    $rows = '';
    foreach ($totals['lines'] as $line) {
        $pid = (int) $line['product_id'];
        $key = (string) $line['key'];
        $dom = str_replace(':', '-', $key);
        $label = ShopStoreAPI::lineTitle($line['title'], $line['options_label']);
        $qty = (int) $line['qty'];
        $max = (int) $line['max_qty'];
        $rows .= '<tr class="sb-shop-cart__line" data-product="' . $pid . '"' . ($line['variant_id'] > 0 ? ' data-variant="' . (int) $line['variant_id'] . '"' : '') . '>'
            . '<td class="sb-shop-cart__product"><div class="sb-shop-cart__item">'
            . ($line['image'] !== '' ? '<img class="sb-shop-cart__image" src="' . e(ShopStoreAPI::assetUrl($line['image'])) . '" alt="" width="64" height="64" loading="lazy">' : '<span class="sb-shop-cart__image sb-shop-cart__image--none" aria-hidden="true"></span>')
            . '<div class="sb-shop-cart__info">'
            . '<a class="sb-shop-cart__title" href="' . e(rtrim((string) SLATE_URL, '/') . $line['url']) . '">' . e($line['title']) . '</a>'
            . ($line['options_label'] !== '' ? '<span class="sb-shop-cart__options">' . e($line['options_label']) . '</span>' : '')
            // The unit price under the name, for phones, where the Price column is not shown.
            . '<span class="sb-shop-cart__each">' . e(sprintf(__('shop_each', '%s each'), ShopStoreAPI::money((int) $line['unit_cents'], (string) $line['currency']))) . '</span>'
            . ($line['capped'] ? '<span class="sb-shop-cart__note">' . e(sprintf(__('shop_err_only_n', 'Only %d left in stock.'), $max)) . '</span>' : '')
            . '</div></div></td>'
            . '<td class="sb-shop-cart__price">' . shop_money((int) $line['unit_cents'], (string) $line['currency']) . '</td>'
            . '<td class="sb-shop-cart__qty"><form method="post" action="' . $action . '" class="sb-shop-cart__qty-form">' . csrf_field()
            . '<input type="hidden" name="action" value="update"><input type="hidden" name="line" value="' . e($key) . '">'
            . '<label class="sb-shop-sr" for="sb-shop-qty-' . e($dom) . '">' . e(sprintf(__('shop_quantity_of', 'Quantity of %s'), $label)) . '</label>'
            // First in the form, so Enter in the box updates; hidden, as a change saves by itself (data-sb-requote).
            . '<button type="submit" class="sb-shop-cart__update sb-shop-sr" data-sb-requote-button>' . e(__('shop_update', 'Update')) . '</button>'
            . '<span class="sb-shop-cart__stepper">'
            . '<button type="submit" class="sb-shop-cart__step" name="step" value="-1" aria-label="' . e(sprintf(__('shop_qty_less', 'One less %s'), $label)) . '"' . ($qty <= 1 ? ' disabled' : '') . '>−</button>'
            . '<input class="sb-shop-cart__qty-input" id="sb-shop-qty-' . e($dom) . '" type="number" name="qty" min="0" max="' . $max . '" value="' . $qty . '" inputmode="numeric" data-sb-requote>'
            . '<button type="submit" class="sb-shop-cart__step" name="step" value="1" aria-label="' . e(sprintf(__('shop_qty_more', 'One more %s'), $label)) . '"' . ($qty >= $max ? ' disabled' : '') . '>+</button>'
            . '</span></form></td>'
            . '<td class="sb-shop-cart__line-total">' . shop_money((int) $line['line_cents'], (string) $line['currency']) . '</td>'
            . '<td class="sb-shop-cart__remove-cell"><form method="post" action="' . $action . '">' . csrf_field()
            . '<input type="hidden" name="action" value="remove"><input type="hidden" name="line" value="' . e($key) . '">'
            . '<button type="submit" class="sb-shop-cart__remove" aria-label="' . e(sprintf(__('shop_remove_x', 'Remove %s'), $label)) . '">'
            . '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>'
            . '<span class="sb-shop-cart__remove-label">' . e(__('shop_remove', 'Remove')) . '</span></button></form></td>'
            . '</tr>';
    }
    $cur = (string) $totals['currency'];
    if ($couponError === '' && ($totals['coupon_note'] ?? '') !== '') {
        $couponError = $totals['coupon_note'] . ' ' . __('shop_coupon_removed', 'It was removed and your total updated.');
        ShopCoupons::setSessionCode('');
    }
    $freeOver = (int) $totals['free_over_cents'];
    $freeNote = '';
    if ($freeOver > 0 && in_array('shipping', $totals['delivery_options'] ?? ShopStoreAPI::deliveryOptions($totals['lines']), true) && $totals['subtotal_cents'] < $freeOver) {
        $freeNote = '<p class="sb-shop-cart__free">' . e(sprintf(__('shop_free_shipping_from', 'Free shipping from %s.'), ShopStoreAPI::money($freeOver, $cur))) . '</p>';
    }
    return '<div class="sb-shop-cart">'
        . ($notice !== '' ? '<p class="sb-shop-notice" role="status">' . e($notice) . '</p>' : '')
        . '<table class="sb-shop-cart__table"><thead><tr><th scope="col">' . e(__('shop_product', 'Product')) . '</th><th scope="col" class="sb-shop-cart__num">' . e(__('shop_price', 'Price'))
        . '</th><th scope="col" class="sb-shop-cart__qty-head">' . e(__('shop_quantity', 'Quantity')) . '</th><th scope="col" class="sb-shop-cart__num">' . e(__('shop_total', 'Total')) . '</th><th scope="col"><span class="sb-shop-sr">' . e(__('shop_remove', 'Remove')) . '</span></th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>'
        . '<div class="sb-shop-cart__foot">' . shop_view_continue() . '<div class="sb-shop-cart__totals"><p class="sb-shop-cart__subtotal"><span>' . e(__('shop_subtotal', 'Subtotal')) . '</span> <strong>' . shop_money((int) $totals['subtotal_cents'], $cur) . '</strong></p>'
        . ((int) $totals['discount_cents'] > 0 ? '<p class="sb-shop-cart__discount"><span>' . e(sprintf(__('shop_discount_code', 'Discount (%s)'), $totals['coupon'])) . '</span> <strong>−' . shop_money((int) $totals['discount_cents'], $cur) . '</strong></p>' : '')
        . (($coupon = shop_view_coupon($totals, $couponError, $couponValue)) !== '' ? '<form method="post" action="' . $action . '" class="sb-shop-cart__coupon">' . csrf_field() . $coupon . '</form>' : '')
        . ($totals['free_shipping'] ? '<p class="sb-shop-cart__free">' . e(__('shop_coupon_free_shipping_note', 'Your code makes shipping free.')) . '</p>' : '')
        . (($note = ShopStoreAPI::cartTaxNote(ShopStoreAPI::needsShipping($totals['lines']))) !== '' ? '<p class="sb-shop-cart__vat sb-shop-cart__tax-note">' . e($note) . '</p>' : '') . $freeNote
        . '<p class="sb-button-row"><a class="sb-button sb-button--primary sb-shop-cart__checkout" href="' . e(ShopStoreAPI::pageUrl('checkout_page')) . '">' . e(__('shop_go_checkout', 'Proceed to checkout')) . '</a></p></div></div>'
        . '</div>';
}

/** A totals array with every key the views read (callers built before 0.4.0 pass fewer). */
function shop_totals_defaults(array $t): array {
    return $t + [
        'discount_cents' => 0, 'coupon' => '', 'coupon_label' => '', 'coupon_note' => '', 'free_shipping' => false,
        'shipping_rates' => [], 'shipping_rate' => null, 'shipping_pending' => false, 'shipping_error' => '',
        'tax_cents' => 0, 'tax_label' => '', 'tax_inclusive' => true, 'tax_pending' => false, 'tax_rate' => null,
        'total_cents' => (int) ($t['subtotal_cents'] ?? 0) + (int) ($t['shipping_cents'] ?? 0), 'free_over_cents' => 0,
    ];
}

/** The address fields of a checkout post, for quoting shipping and tax before the order exists. */
function shop_posted_address(array $in): array {
    $str = static fn(string $k, int $max): string => mb_substr(trim(str_replace(["\r", "\n", "\0"], ' ', (string) ($in[$k] ?? ''))), 0, $max);
    return [
        'name' => $str('name', 160), 'email' => $str('email', 190), 'phone' => $str('phone', 40),
        'address_line1' => $str('address_line1', 190), 'address_line2' => $str('address_line2', 190), 'postal_code' => $str('postal_code', 20),
        'city' => $str('city', 120), 'country' => strtoupper($str('country', 2)), 'region' => strtoupper($str('region', 80)),
    ];
}

/**
 * The rows under the lines (subtotal, discount, shipping, tax, total) as paragraphs of the checkout summary
 * (`summary`) or of an order confirmation (`order`). The class names are written out whole: the widgets' style parts
 * name them.
 */
function shop_view_total_rows(array $rows, string $where): string {
    [$row, $total, $note] = $where === 'order'
        ? ['sb-shop-order__row', 'sb-shop-order__total', 'sb-shop-order__tax-note']
        : ['sb-shop-summary__row', 'sb-shop-summary__total', 'sb-shop-summary__tax-note'];
    $out = '';
    foreach ($rows as $r) {
        if ($r['key'] === 'total') {
            $out .= '<p class="' . $total . '"><span>' . e($r['label']) . '</span> <strong>' . e($r['text']) . '</strong></p>';
        } elseif ($r['key'] === 'tax_included') {
            $out .= '<p class="' . $note . '">' . e($r['text']) . '</p>';
        } else {
            $out .= '<p class="' . $row . ' ' . $row . '--' . e($r['key']) . '"><span>' . e($r['label']) . '</span> <span>' . e($r['text']) . '</span></p>';
        }
    }
    return $out;
}

/** The coupon box: a code field and Apply, or the applied code with Remove. Its buttons submit the form they sit in. */
function shop_view_coupon(array $totals, string $error = '', string $value = ''): string {
    if ($totals['coupon'] === '' && $error === '' && !ShopCoupons::anyActive()) return '';
    if ($totals['coupon'] !== '') {
        return '<div class="sb-shop-coupon sb-shop-coupon--applied"><p class="sb-shop-coupon__applied"><span class="sb-shop-coupon__code">' . e($totals['coupon']) . '</span> '
            . e($totals['coupon_label']) . ' <button type="submit" name="action" value="coupon_remove" class="sb-shop-coupon__remove" formnovalidate>' . e(__('shop_coupon_remove', 'Remove')) . '</button></p></div>';
    }
    return '<div class="sb-shop-coupon"><label class="sb-shop-coupon__label" for="sb-shop-coupon">' . e(__('shop_coupon_label', 'Discount code')) . '</label>'
        . '<span class="sb-shop-coupon__row"><input id="sb-shop-coupon" class="sb-shop-coupon__input" name="coupon" type="text" autocomplete="off" maxlength="40" value="' . e($value) . '"'
        . ($error !== '' ? ' aria-invalid="true" aria-describedby="sb-shop-err-coupon"' : '') . '>'
        . '<button type="submit" name="action" value="coupon" class="sb-button sb-button--secondary sb-shop-coupon__apply" formnovalidate>' . e(__('shop_coupon_apply', 'Apply')) . '</button></span>'
        . ($error !== '' ? '<span class="sb-shop-error" id="sb-shop-err-coupon">' . e($error) . '</span>' : '') . '</div>';
}

/**
 * The checkout form with the order summary.
 *
 * Its buttons say what a send does (`action`): pay (the default, also on Enter), quote (Update shipping: the rates
 * and tax for the address typed), coupon and coupon_remove. Each answer is the form again, with the totals for what
 * was posted.
 *
 * @param array<string, string> $errors field => message ('_' for the form)
 * @param array<string, mixed>  $old    what was posted
 */
function shop_view_checkout(array $totals, array $errors = [], array $old = [], string $notice = ''): string {
    $totals = shop_totals_defaults($totals);
    if ($totals['lines'] === []) {
        return '<div class="sb-shop-checkout sb-shop-checkout--empty"><p class="sb-shop-cart__empty">' . e(__('shop_cart_empty', 'Your cart is empty.')) . '</p>'
            . '<p class="sb-button-row"><a class="sb-button" href="' . e(ShopStoreAPI::pageUrl('cart_page')) . '">' . e(__('shop_back_to_cart', 'Back to cart')) . '</a></p></div>';
    }
    $cust = class_exists('Auth') && method_exists('Auth', 'customer') ? (Auth::customer() ?: []) : [];
    $val = static fn(string $k, string $fallback = ''): string => e(isset($old[$k]) ? (string) $old[$k] : $fallback);
    $err = static fn(string $k): string => isset($errors[$k]) ? '<span class="sb-shop-error" id="sb-shop-err-' . e($k) . '">' . e($errors[$k]) . '</span>' : '';
    $aria = static fn(string $k): string => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="sb-shop-err-' . e($k) . '"' : '';
    $field = static function (string $k, string $label, string $type = 'text', bool $required = true, string $auto = '', string $fallback = '', string $extra = '') use ($val, $err, $aria): string {
        return '<p class="sb-shop-checkout__field sb-shop-checkout__field--' . e($k) . '"><label for="sb-shop-' . e($k) . '">' . e($label) . ($required ? ' <span aria-hidden="true">*</span>' : '') . '</label>'
            . '<input id="sb-shop-' . e($k) . '" name="' . e($k) . '" type="' . e($type) . '"' . ($required ? ' required' : '') . ($auto !== '' ? ' autocomplete="' . e($auto) . '"' : '')
            . ' value="' . $val($k, $fallback) . '"' . $aria($k) . $extra . '>' . $err($k) . '</p>';
    };
    $options = $totals['delivery_options'] ?? ShopStoreAPI::deliveryOptions($totals['lines']);
    $delivery = (string) $totals['delivery'];
    $cur = (string) $totals['currency'];
    $legacy = !ShopShipping::hasZones();
    // Nothing to ship (digital and service products only): no delivery choice and no address.
    $nothingToShip = $options === ['none'];
    $deliveryHtml = '';
    if ($nothingToShip) {
        $deliveryHtml = '<input type="hidden" name="delivery" value="none">';
    } elseif (count($options) > 1 || $legacy) {
        $deliveryHtml = '<fieldset class="sb-shop-checkout__delivery"><legend>' . e(__('shop_delivery', 'Delivery')) . '</legend>';
        foreach ($options as $opt) {
            if ($opt === 'pickup') {
                $label = ShopStoreAPI::setting('pickup_label') !== '' ? ShopStoreAPI::setting('pickup_label') : __('shop_pickup', 'Pick up (free)');
            } elseif ($legacy) {
                $cost = ShopStoreAPI::shippingFor((int) $totals['subtotal_cents'] - (int) $totals['discount_cents'], 'shipping');
                $label = __('shop_ship_to_me', 'Ship to my address') . ' — ' . ($cost > 0 && !$totals['free_shipping'] ? ShopStoreAPI::money($cost, $cur) : __('shop_free', 'free'));
            } else {
                $label = __('shop_ship_to_me', 'Ship to my address');
            }
            $deliveryHtml .= '<label class="sb-shop-checkout__option"><input type="radio" name="delivery" value="' . e($opt) . '"' . ($opt === $delivery ? ' checked' : '') . ' data-sb-requote> ' . e($label) . '</label>';
        }
        $deliveryHtml .= $err('delivery') . '</fieldset>';
    } else {
        $deliveryHtml = '<input type="hidden" name="delivery" value="' . e($options[0]) . '">';
    }
    $countries = '';
    $country = (string) ($old['country'] ?? (ShopStoreAPI::countries()[0] ?? ''));
    foreach (ShopStoreAPI::countries() as $c) {
        $countries .= '<option value="' . e($c) . '"' . ($country === $c ? ' selected' : '') . '>' . e($c) . '</option>';
    }
    $requote = ' data-sb-requote';
    $address = in_array('shipping', $options, true)
        ? '<fieldset class="sb-shop-checkout__address"><legend>' . e(__('shop_delivery_address', 'Delivery address')) . '</legend>'
            . (count($options) > 1 ? '<p class="sb-shop-checkout__hint">' . e(__('shop_address_hint', 'Needed only when we ship your order.')) . '</p>' : '')
            . $field('address_line1', __('shop_address_line1', 'Street and number'), 'text', false, 'address-line1')
            . $field('address_line2', __('shop_address_line2', 'Apartment, box (optional)'), 'text', false, 'address-line2')
            . $field('city', __('shop_city', 'Town or city'), 'text', false, 'address-level2')
            . $field('region', __('shop_region', 'State or province'), 'text', false, 'address-level1', '', $requote . ' maxlength="80"')
            . $field('postal_code', __('shop_postal_code', 'Postal code'), 'text', false, 'postal-code', '', $requote)
            . '<p class="sb-shop-checkout__field sb-shop-checkout__field--country"><label for="sb-shop-country">' . e(__('shop_country', 'Country')) . '</label><select id="sb-shop-country" name="country" autocomplete="country"' . $aria('country') . $requote . '>' . $countries . '</select>' . $err('country') . '</p>'
            . '</fieldset>'
        : '';
    // Shipping methods for the address typed so far (zones), with a button to quote again.
    $ratesHtml = '';
    if (!$legacy && $delivery === 'shipping') {
        $chosen = (string) ($totals['shipping_rate']['id'] ?? '');
        $ratesHtml = '<fieldset class="sb-shop-checkout__rates" id="sb-shop-rates"><legend>' . e(__('shop_shipping_method', 'Shipping method')) . '</legend>';
        foreach ($totals['shipping_rates'] as $r) {
            $price = $totals['free_shipping'] || (int) $r['cents'] === 0 ? __('shop_free', 'free') : ShopStoreAPI::money((int) $r['cents'], $cur);
            $days = $r['days'] !== null && (int) $r['days'] > 0 ? ' <small class="sb-shop-checkout__days">' . e(sprintf((int) $r['days'] === 1 ? __('shop_rate_one_day', '%d business day') : __('shop_rate_days', '%d business days'), (int) $r['days'])) . '</small>' : '';
            $ratesHtml .= '<label class="sb-shop-checkout__option sb-shop-checkout__rate"><input type="radio" name="shipping_rate" value="' . e($r['id']) . '"' . ($r['id'] === $chosen ? ' checked' : '') . ' data-sb-requote> '
                . '<span class="sb-shop-checkout__rate-name">' . e($r['label']) . '</span> <span class="sb-shop-checkout__rate-price">' . e($price) . '</span>' . $days . '</label>';
        }
        if ($totals['shipping_rates'] === []) {
            $msg = $totals['shipping_error'] !== '' ? $totals['shipping_error'] : __('shop_rates_enter_address', 'Enter your address to see the shipping rates.');
            $ratesHtml .= '<p class="sb-shop-checkout__hint sb-shop-checkout__rates-empty">' . e($msg) . '</p>';
        } elseif ($totals['shipping_pending']) {
            $ratesHtml .= '<p class="sb-shop-checkout__hint">' . e(__('shop_rates_more', 'Enter your postal code for carrier rates.')) . '</p>';
        }
        $ratesHtml .= $err('shipping_rate') . '<p class="sb-shop-checkout__requote"><button type="submit" name="action" value="quote" class="sb-button sb-button--secondary sb-shop-checkout__quote" formnovalidate data-sb-requote-button>'
            . e(__('shop_update_shipping', 'Update shipping')) . '</button></p></fieldset>';
    }
    $summary = '';
    foreach ($totals['lines'] as $line) {
        $summary .= '<li class="sb-shop-summary__line"><span>' . e(ShopStoreAPI::lineTitle($line['title'], $line['options_label'])) . ' × ' . (int) $line['qty'] . '</span> <span>' . shop_money((int) $line['line_cents'], (string) $line['currency']) . '</span></li>';
    }
    $rows = ShopStoreAPI::totalRows($totals, $cur);
    if ($nothingToShip) $rows = array_values(array_filter($rows, static fn(array $r): bool => $r['key'] !== 'shipping'));
    $taxPending = $totals['tax_pending'] ? '<p class="sb-shop-summary__tax-note">' . e(sprintf(__('shop_tax_after_address', '%s is added once we have your address.'), (string) $totals['tax_label'])) . '</p>' : '';
    // Payment: a choice only when the site offers more than one gateway for the shop.
    $gateways = class_exists('Payments') ? Payments::choices('shop') : [];
    $payHtml = '';
    if (count($gateways) > 1) {
        $picked = isset($gateways[$old['payment_gateway'] ?? '']) ? (string) $old['payment_gateway'] : (string) array_key_first($gateways);
        $payHtml = '<fieldset class="sb-shop-checkout__payment"><legend>' . e(__('shop_pay_with', 'Pay with')) . '</legend>';
        foreach ($gateways as $id => $label) {
            $payHtml .= '<label class="sb-shop-checkout__option"><input type="radio" name="payment_gateway" value="' . e($id) . '"' . ($id === $picked ? ' checked' : '') . '> ' . e($label) . '</label>';
        }
        $payHtml .= '</fieldset>';
    }
    $secure = $gateways
        ? sprintf(__('shop_secure_note_gateway', 'You pay securely through %s. Your card details never reach this site.'), implode(' / ', array_map(
            static fn (string $id): string => $id === 'stripe' ? 'Stripe' : ($id === 'paypal' ? 'PayPal' : $id), array_keys($gateways))))
        : __('shop_secure_note_generic', 'Your card details never reach this site.');
    $couponError = $errors['coupon'] ?? '';
    return '<div class="sb-shop-checkout">'
        . ($notice !== '' ? '<p class="sb-shop-notice" role="status">' . e($notice) . '</p>' : '')
        . (isset($errors['_']) ? '<p class="sb-shop-error sb-shop-checkout__error" role="alert">' . e($errors['_']) . '</p>' : '')
        . '<form class="sb-shop-checkout__form" method="post" action="' . e(ShopStoreAPI::endpoint('checkout.php')) . '" novalidate>' . csrf_field()
        . ShopGuard::formFields(is_string($old[ShopGuard::STAMP_FIELD] ?? null) ? $old[ShopGuard::STAMP_FIELD] : '')
        // The first submit button is the one Enter presses: paying, not applying a code or quoting shipping.
        . '<button type="submit" name="action" value="pay" class="sb-shop-sr" tabindex="-1" aria-hidden="true">' . e(__('shop_pay_now', 'Continue to payment')) . '</button>'
        . '<div class="sb-shop-checkout__details"><fieldset class="sb-shop-checkout__contact"><legend>' . e(__('shop_your_details', 'Your details')) . '</legend>'
        . $field('name', __('shop_full_name', 'Full name'), 'text', true, 'name', (string) ($cust['name'] ?? ''))
        . $field('email', __('shop_email', 'Email'), 'email', true, 'email', (string) ($cust['email'] ?? ''))
        . $field('phone', __('shop_phone', 'Phone (optional)'), 'tel', false, 'tel')
        . '</fieldset>' . $deliveryHtml . $address . $ratesHtml . $payHtml
        . '<p class="sb-shop-checkout__field"><label for="sb-shop-notes">' . e(__('shop_notes', 'Order notes (optional)')) . '</label><textarea id="sb-shop-notes" name="notes" rows="3" maxlength="2000">' . $val('notes') . '</textarea></p></div>'
        . '<aside class="sb-shop-summary" aria-label="' . e(__('shop_order_summary', 'Order summary')) . '"><h2 class="sb-shop-summary__title">' . e(__('shop_order_summary', 'Order summary')) . '</h2><ul class="sb-shop-summary__lines">' . $summary . '</ul>'
        . shop_view_coupon($totals, $couponError, $couponError !== '' ? (string) ($old['coupon'] ?? '') : '')
        . shop_view_total_rows($rows, 'summary') . $taxPending
        . ShopGuard::challenge()
        . '<p class="sb-button-row"><button type="submit" name="action" value="pay" class="sb-button sb-button--primary sb-shop-checkout__pay">' . e(__('shop_pay_now', 'Continue to payment')) . '</button></p>'
        . '<p class="sb-shop-checkout__secure">' . e($secure) . '</p></aside>'
        . '</form></div>';
}

/** An order's confirmation: status, lines, totals and where it goes. */
function shop_view_order(array $order): string {
    $cur = (string) $order['currency'];
    $labels = ShopStoreAPI::statusLabels();
    $lines = '';
    foreach (ShopStoreAPI::orderItems((int) $order['id']) as $item) {
        $lines .= '<li class="sb-shop-order__line"><span>' . e(ShopStoreAPI::lineTitle((string) $item['title'], $item['options_label'] ?? null)) . ' × ' . (int) $item['qty'] . '</span> <span>' . shop_money((int) $item['line_total_cents'], $cur) . '</span></li>';
    }
    $paid = in_array($order['status'], ['paid', 'fulfilled'], true);
    $lead = $paid
        ? sprintf(__('shop_order_thanks', 'Thank you, %s. Your order is confirmed and a receipt is on its way to %s.'), $order['name'], $order['email'])
        : ($order['status'] === 'pending_payment'
            ? __('shop_order_processing', 'We are confirming your payment. This page updates when it is done; you can also refresh it.')
            : sprintf(__('shop_order_status_is', 'This order is %s.'), mb_strtolower($labels[$order['status']] ?? $order['status'])));
    $where = match ((string) $order['delivery']) {
        'pickup' => e(ShopStoreAPI::pickupText($order)),
        'none'   => e(__('shop_nothing_to_ship', 'Nothing to ship')),
        default  => e(ShopStoreAPI::addressText($order)),
    };
    $downloads = '';
    foreach (ShopStoreAPI::orderDownloads($order) as $d) {
        $terms = ShopStoreAPI::downloadTerms($d);
        $downloads .= '<li class="sb-shop-order__download">' . ($d['usable']
            ? '<a class="sb-shop-order__download-link" href="' . e($d['url']) . '" rel="nofollow">' . e($d['name']) . '</a>'
            : '<span>' . e($d['name']) . '</span>') . ($terms !== '' ? ' <small>' . e($terms) . '</small>' : '') . '</li>';
    }
    $notes = '';
    if ($paid) {
        foreach (ShopStoreAPI::purchaseNotes((int) $order['id']) as $title => $note) {
            $notes .= '<div class="sb-shop-order__note"><strong>' . e($title) . '</strong><p>' . nl2br(e($note)) . '</p></div>';
        }
    }
    return '<div class="sb-shop-order" data-status="' . e($order['status']) . '">'
        . '<p class="sb-shop-order__lead">' . e($lead) . '</p>'
        . '<dl class="sb-shop-order__meta"><dt>' . e(__('shop_order_number', 'Order number')) . '</dt><dd class="sb-shop-order__ref">' . e($order['ref']) . '</dd>'
        . '<dt>' . e(__('shop_status', 'Status')) . '</dt><dd class="sb-shop-order__status">' . e($labels[$order['status']] ?? $order['status']) . '</dd>'
        . '<dt>' . e(__('shop_delivery', 'Delivery')) . '</dt><dd>' . $where . '</dd>'
        . (!empty($order['shipping_method']) && $order['delivery'] === 'shipping' ? '<dt>' . e(__('shop_shipping_method', 'Shipping method')) . '</dt><dd>' . e((string) $order['shipping_method']) . '</dd>' : '')
        . (!empty($order['tracking_number']) ? '<dt>' . e(__('shop_tracking', 'Tracking')) . '</dt><dd class="sb-shop-order__tracking">' . e(trim((string) $order['tracking_carrier'] . ' ' . $order['tracking_number']))
            . (ShopStoreAPI::trackingLink($order) !== '' ? ' <a href="' . e(ShopStoreAPI::trackingLink($order)) . '" rel="noopener nofollow" target="_blank">' . e(__('shop_track_parcel', 'Track your parcel')) . '</a>' : '') . '</dd>' : '')
        . '</dl>'
        . '<ul class="sb-shop-order__lines">' . $lines . '</ul>'
        . shop_view_total_rows(array_values(array_filter(ShopStoreAPI::totalRows($order, $cur), static fn(array $r): bool => $r['key'] !== 'subtotal' || (int) ($order['discount_cents'] ?? 0) > 0)), 'order')
        . ($downloads !== '' ? '<h3 class="sb-shop-order__downloads-title">' . e(__('shop_your_downloads', 'Your downloads')) . '</h3><ul class="sb-shop-order__downloads">' . $downloads . '</ul>' : '')
        . $notes
        . '</div>';
}
