<?php
/** Storefront — review cart, change quantities, proceed to checkout. */
if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }
if (!RestaurantAPI::onlineOrderingEnabled()) sf_closed('This restaurant is not taking online orders right now.');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (csrf_verify()) {
        $cart = sf_cart();
        $i = (int)($_POST['i'] ?? -1);
        switch ($_POST['_action'] ?? '') {
            case 'qty':
                if (isset($cart[$i])) {
                    $q = (int)($_POST['qty'] ?? 1);
                    if ($q < 1) unset($cart[$i]); else $cart[$i]['qty'] = $q;
                    sf_cart_set($cart);
                }
                break;
            case 'remove':
                if (isset($cart[$i])) { unset($cart[$i]); sf_cart_set($cart); }
                break;
            case 'clear':
                sf_cart_clear();
                break;
        }
    }
    header('Location: ' . sf_url('cart')); exit;
}

$cart = sf_cart();
sf_header('Cart');
echo '<h1 class="sf-h1">Your order</h1>';

if (!$cart) {
    echo '<div class="sf-card sf-muted">Your cart is empty. <a href="' . e(sf_url()) . '">Browse the menu →</a></div>';
    sf_footer(); return;
}

echo '<div class="sf-card">';
foreach ($cart as $i => $line) {
    $item = sf_item((int)$line['item_id']);
    if (!$item) continue;
    $unit = sf_line_unit_cents($line);
    $qty  = max(1, (int)$line['qty']);
    $mods = sf_line_mod_names($line);
    echo '<div class="sf-item"><div><div class="nm">' . e($item['name']) . '</div>';
    if ($mods)  echo '<div class="ds">' . e(implode(', ', $mods)) . '</div>';
    if (trim((string)($line['notes'] ?? '')) !== '') echo '<div class="ds">' . sf_icon('note', 14) . ' ' . e($line['notes']) . '</div>';
    echo '<div style="margin-top:6px" class="sf-row">';
    echo '<form method="post" class="sf-row">' . csrf_field()
       . '<input type="hidden" name="_action" value="qty"><input type="hidden" name="i" value="' . (int)$i . '">'
       . '<input class="sf-qty" type="number" name="qty" value="' . $qty . '" min="0" step="1" onchange="this.form.submit()">'
       . '</form>';
    echo '<form method="post" style="margin:0">' . csrf_field()
       . '<input type="hidden" name="_action" value="remove"><input type="hidden" name="i" value="' . (int)$i . '">'
       . '<button class="sf-btn sf-btn-ghost" type="submit">Remove</button></form>';
    echo '</div></div>';
    echo '<div class="pr">' . sf_money($unit * $qty) . '</div></div>';
}
echo '</div>';

echo '<div class="sf-card"><div class="sf-totrow grand"><span>Subtotal</span><span>' . sf_money(sf_cart_subtotal()) . '</span></div>'
   . '<p class="sf-muted" style="font-size:.85rem;margin:6px 0 0">Tax' . (RestaurantAPI::serviceChargeAuto() ? ' and service charge' : '') . ' calculated at checkout.</p></div>';

echo '<div class="sf-row" style="margin-top:14px">'
   . '<form method="post" style="margin:0">' . csrf_field() . '<input type="hidden" name="_action" value="clear">'
   . '<button class="sf-btn sf-btn-ghost" type="submit">Clear</button></form>'
   . '<a class="sf-btn" style="margin-left:auto" href="' . e(sf_url('checkout')) . '">Checkout →</a></div>';
sf_footer();
