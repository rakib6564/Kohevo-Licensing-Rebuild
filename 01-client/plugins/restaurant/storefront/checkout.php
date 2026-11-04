<?php
/** Storefront — checkout: contact + fulfilment + payment, creates the order. */
if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }
if (!RestaurantAPI::onlineOrderingEnabled()) sf_closed('This restaurant is not taking online orders right now.');

$cart = sf_cart();
if (!$cart) { header('Location: ' . sf_url('cart')); exit; }

$payOnline   = RestaurantAPI::onlinePayEnabled();
// Each gateway offered for online orders. Stripe takes the card on the page
// (Payment Element); any other gateway sends the customer to its own page.
$gateways    = $payOnline ? RestaurantAPI::onlineGateways() : [];
$inlineCard  = $payOnline && RestaurantAPI::inlineCardAvailable();
$hostedOther = array_values(array_filter(array_keys($gateways), fn ($id) => $id !== 'stripe' || !$inlineCard));
$payMethods  = array_merge($inlineCard ? ['card'] : [], $hostedOther);
$payPickup   = (string) RestaurantAPI::setting('online_pay_pickup', '1') === '1';
$allowDeliv  = (string) RestaurantAPI::setting('online_delivery', '0') === '1';
if (!$payOnline && !$payPickup) sf_closed('No payment method is configured for online orders.');

// Opening hours: when they are on, the customer picks one of the offered pickup times, and with none on offer
// (closed now and no pre-order days) checkout says when ordering opens again.
$hours = RestaurantHours::config();
$now   = RestaurantHours::now();
// A pre-order cart (the items of one pre-order menu) picks from that menu's pickup dates instead; a cart mixing it
// with anything else goes back to the cart page, which explains.
$group  = RestaurantPreorder::cartGroup($cart);
if ($group['kind'] === 'mixed') { header('Location: ' . sf_url('cart')); exit; }
$preCat = $group['kind'] === 'preorder' ? $group['category'] : null;
if ($preCat !== null) {
    $slots = RestaurantPreorder::inWindow($preCat, $now) ? RestaurantPreorder::slots($preCat, $hours, $now) : [];
    if ($slots === []) sf_closed(sprintf(__('restaurant_po_closed_named', 'Pre-orders for %s are closed. Please empty your cart to order from the regular menu.'), (string) $preCat['name']));
} else {
    $slots = $hours['on'] ? RestaurantHours::slots($hours, $now) : [];
    if (!$hours['on'] && RestaurantHours::isClosedDate($hours, $now)) sf_closed(__('restaurant_sf_closed_today', 'We are closed today. Please come back another day.'));
}
$picker = $preCat !== null || $hours['on'];   // pick one of the offered times (validated below), or type a time
if ($preCat === null && $hours['on'] && $slots === []) {
    $st = RestaurantHours::status($hours, $now);
    sf_closed($st['next'] !== null
        ? sprintf(__('restaurant_sf_closed_until', 'We are closed right now. Online ordering opens again %s.'), RestaurantHours::label($st['next'], $now))
        : __('restaurant_sf_closed_now', 'We are not taking online orders right now.'));
}

$flash = null;
$old   = ['name' => '', 'phone' => '', 'email' => '', 'type' => 'takeout', 'address' => '', 'time' => '', 'notes' => ''];

/** Emit a JSON reply for the AJAX (Payment Element) path and stop. */
$co_json = function (array $payload): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload); exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajax     = (stripos((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'fetch') !== false);
    $cardInit = ($_POST['_action'] ?? '') === 'card_init';   // on-page card flow asks for a PaymentIntent
    foreach ($old as $k => $_) $old[$k] = trim((string)($_POST[$k] ?? $old[$k]));
    $method = $cardInit ? 'card' : (string)($_POST['pay_method'] ?? ($payMethods[0] ?? 'pickup'));

    if (!csrf_verify())                         $flash = 'Session expired — please try again.';
    elseif ($old['name'] === '')                $flash = 'Please enter your name.';
    elseif ($old['phone'] === '')               $flash = 'Please enter a phone number so we can reach you.';
    elseif ($old['type'] === 'delivery' && !$allowDeliv) $flash = 'Delivery is not available.';
    elseif ($old['type'] === 'delivery' && $old['address'] === '') $flash = 'Please enter a delivery address.';
    elseif ($method === 'card' && !$inlineCard && !in_array('stripe', $hostedOther, true)) $flash = 'Online card payment is unavailable.';
    elseif ($method !== 'card' && $method !== 'pickup' && !in_array($method, $hostedOther, true)) $flash = 'That payment method is unavailable.';
    elseif ($method === 'pickup' && !$payPickup) $flash = 'Pay at pickup is unavailable.';

    // The chosen time must still be on offer (a page left open past closing, or a hand-made post).
    $timeText = $old['time'];
    if ($flash === null && $picker) {
        $slot = RestaurantHours::pick($slots, $old['time']);
        if ($slot === null) $flash = __('restaurant_sf_time_gone', 'That pickup time is no longer available. Please choose another.');
        // Saved with its date ("Thu Nov 6, 12:15 PM"): the kitchen reads it later, when "Today" would mislead.
        elseif ($preCat !== null) $timeText = RestaurantPreorder::savedText($slot);
        else $timeText = ($slot['value'] === 'asap' ? 'ASAP · ' : '') . $slot['at']->format('D M j, g:i A');
    }

    if ($flash === null) {
        $notes = $old['notes'];
        if ($old['type'] === 'delivery') $notes = trim('Deliver to: ' . $old['address'] . ($notes ? ' · ' . $notes : ''));
        try {
            $res = RestaurantAPI::createOnlineOrder($cart, [
                'name' => $old['name'], 'phone' => $old['phone'], 'email' => $old['email'],
            ], [
                'type' => $old['type'] === 'delivery' ? 'delivery' : 'takeout',
                'requested_time' => $timeText, 'notes' => $notes,
            ]);
        } catch (\Throwable $e) {
            $flash = $e->getMessage();
        }
    }

    if ($flash === null && !empty($res['order_id'])) {
        sf_cart_clear();
        $token = $res['token'];

        // ── On-page card payment (Stripe Payment Element) ──
        if ($cardInit) {
            $pi = RestaurantAPI::beginOnlinePaymentIntent((int)$res['order_id']);
            if (empty($pi['ok'])) $co_json(['ok' => false, 'error' => $pi['error'] ?? 'Could not start payment.']);
            $co_json([
                'ok'            => true,
                'client_secret' => $pi['client_secret'],
                'pk'            => $pi['publishable_key'],
                'return_url'    => sf_url('confirm') . '?token=' . $token,
                'token'         => $token,
            ]);
        }

        // ── Hosted checkout (another gateway, or card with JS disabled) / pay-at-pickup ──
        if ($method !== 'pickup') {
            // 'card' without JS falls back to Stripe's hosted Checkout.
            $gw  = $method === 'card' ? 'stripe' : $method;
            $pay = RestaurantAPI::beginOnlinePayment(
                (int)$res['order_id'],
                sf_url('confirm') . '?token=' . $token . '&gw=' . rawurlencode($gw) . '&ref={PAYMENT_REF}',
                sf_url('confirm') . '?token=' . $token . '&cancelled=1',
                $gw
            );
            if (!empty($pay['ok']) && !empty($pay['url'])) { header('Location: ' . $pay['url']); exit; }
            // The payment could not start: the order stands, to be paid at pickup, so it is confirmed now.
            RestaurantMail::orderConfirmed((int)$res['order_id']);
            header('Location: ' . sf_url('confirm') . '?token=' . $token . '&payerr=1'); exit;
        }
        // Pay at pickup: confirmed as placed (a paid order is confirmed when its payment settles).
        RestaurantMail::orderConfirmed((int)$res['order_id']);
        header('Location: ' . sf_url('confirm') . '?token=' . $token); exit;
    }

    // Validation / order-creation error.
    if ($cardInit) $co_json(['ok' => false, 'error' => $flash ?: 'Could not place your order.']);
}

sf_header('Checkout');
echo '<a class="sf-back" href="' . e(sf_url()) . '">' . sf_icon('arrow', 14) . ' Back to menu</a>';
echo '<h1 class="sf-h1">Checkout</h1>';
echo '<p class="sf-sub">' . sf_cart_count() . ' item' . (sf_cart_count() === 1 ? '' : 's') . ' · review and place your order.</p>';
if ($flash) echo '<div class="sf-flash sf-flash-err">' . sf_icon('alert', 15) . ' ' . e($flash) . '</div>';
?>
<div class="sf-checkout">

  <form id="co-form" method="post">
    <?= csrf_field() ?>

    <div class="sf-card">
        <div class="sf-card-title"><?= sf_icon('note', 16) ?> Your details</div>
        <div class="sf-field"><label for="name">Name *</label>
            <input type="text" id="name" name="name" required maxlength="160" value="<?= e($old['name']) ?>"></div>
        <div class="sf-field"><label for="phone">Phone *</label>
            <input type="tel" id="phone" name="phone" required maxlength="40" value="<?= e($old['phone']) ?>"></div>
        <div class="sf-field" style="margin:0"><label for="email">Email (for your receipt)</label>
            <input type="email" id="email" name="email" maxlength="200" value="<?= e($old['email']) ?>"></div>
    </div>

    <div class="sf-card">
        <div class="sf-card-title"><?= sf_icon('pin', 16) ?> Pickup details</div>
        <?php if ($allowDeliv): ?>
        <div class="sf-field"><label for="type">Order type</label>
            <select id="type" name="type" onchange="document.getElementById('addrField').style.display=this.value==='delivery'?'block':'none'">
                <option value="takeout" <?= $old['type'] !== 'delivery' ? 'selected' : '' ?>>Pickup</option>
                <option value="delivery" <?= $old['type'] === 'delivery' ? 'selected' : '' ?>>Delivery</option>
            </select></div>
        <?php else: /* pickup is the only way: say where, instead of a one-choice list */
            $bs = RestaurantAPI::settings(); $where = trim(implode(', ', array_filter([$bs['addr_line1'], $bs['addr_city']]))); ?>
        <input type="hidden" name="type" value="takeout">
        <?php if ($where !== ''): ?><p class="sf-muted" style="margin:0 0 13px;font-size:.9rem"><?= e(sprintf(__('restaurant_sf_pickup_at', 'Pickup at %s'), $where)) ?></p><?php endif; ?>
        <?php endif; ?>
        <div class="sf-field" id="addrField" style="display:<?= $old['type'] === 'delivery' ? 'block' : 'none' ?>">
            <label for="address">Delivery address</label>
            <input type="text" id="address" name="address" maxlength="255" value="<?= e($old['address']) ?>"></div>
        <?php if ($preCat !== null): ?>
        <div class="sf-flash sf-flash-info sf-po-note" style="margin:0 0 13px"><?= e(RestaurantPreorder::note($preCat, $hours, $now)) ?></div>
        <?php endif; ?>
        <?php if ($picker): ?>
        <div class="sf-field"><label for="time"><?= e(__('restaurant_sf_pickup_time', 'Pickup time')) ?> *</label>
            <select id="time" name="time" required>
                <?php $lastDay = ''; foreach ($slots as $s):
                    $day = $s['value'] === 'asap' ? '' : substr($s['value'], 0, 10);
                    if ($day !== $lastDay) { if ($lastDay !== '') echo '</optgroup>'; echo '<optgroup label="' . e(RestaurantHours::dayLabel($s['at'], $now)) . '">'; $lastDay = $day; } ?>
                    <option value="<?= e($s['value']) ?>" <?= $old['time'] === $s['value'] ? 'selected' : '' ?>><?= e($s['label']) /* with its day: a closed list shows only the chosen option */ ?></option>
                <?php endforeach; if ($lastDay !== '') echo '</optgroup>'; ?>
            </select></div>
        <?php else: ?>
        <div class="sf-field"><label for="time">Requested time</label>
            <input type="text" id="time" name="time" maxlength="40" placeholder="ASAP, or e.g. 6:30 PM" value="<?= e($old['time']) ?>"></div>
        <?php endif; ?>
        <div class="sf-field" style="margin:0"><label for="notes">Order notes</label>
            <input type="text" id="notes" name="notes" maxlength="255" value="<?= e($old['notes']) ?>"></div>
    </div>

    <div class="sf-card" style="margin-bottom:0">
        <div class="sf-card-title"><?= sf_icon('bag', 16) ?> Payment</div>
        <?php foreach ($payMethods as $i => $pm):
            $pmLabel = $pm === 'card' || $pm === 'stripe' ? 'Pay now by card' : 'Pay now with ' . $gateways[$pm]->label(); ?>
            <label class="sf-mopt"><input type="radio" name="pay_method" value="<?= e($pm) ?>" <?= $i === 0 ? 'checked' : '' ?>> <span><?= e($pmLabel) ?></span></label>
        <?php endforeach; ?>
        <?php if ($payPickup): ?>
            <label class="sf-mopt"><input type="radio" name="pay_method" value="pickup" <?= $payMethods ? '' : 'checked' ?>> <span>Pay at pickup</span></label>
        <?php endif; ?>
        <?php if ($inlineCard): ?>
            <div id="sf-pay-element" style="display:none;margin-top:14px"></div>
            <div id="sf-pay-msg" class="sf-flash sf-flash-err" style="display:none;margin:12px 0 0"></div>
        <?php endif; ?>
        <p class="sf-muted" style="font-size:.85rem;margin:12px 0 0">Tax<?= RestaurantAPI::serviceChargeAuto() ? ' and service charge' : '' ?> is added to your total. <?php if ($gateways): ?>Online payments are processed securely by <?= e(implode(' or ', array_map(fn ($g) => $g->label(), $gateways))) ?> — we never see your card details.<?php endif; ?></p>
    </div>
  </form>

  <aside class="sf-summary" data-cart-summary>
    <div class="sf-card" style="margin-bottom:0">
        <div class="sf-card-title"><?= sf_icon('bag', 16) ?> Your order</div>
        <?php foreach ($cart as $line):
            $item = sf_item((int)$line['item_id']); if (!$item) continue;
            $qty  = max(1, (int)$line['qty']);
            $unit = sf_line_unit_cents($line);
            $mods = sf_line_mod_names($line); ?>
            <div class="sf-osum-row">
                <div class="sf-osum-nm"><span class="sf-osum-q"><?= $qty ?>×</span><?= e($item['name']) ?>
                    <?php if ($mods): ?><span class="sf-osum-mod"> · <?= e(implode(', ', $mods)) ?></span><?php endif; ?>
                </div>
                <div class="sf-osum-pr"><?= sf_money($unit * $qty) ?></div>
            </div>
        <?php endforeach; ?>
        <div class="sf-cart-div"></div>
        <div class="sf-totrow"><span class="sf-muted">Subtotal</span><span style="font-weight:700"><?= sf_money(sf_cart_subtotal()) ?></span></div>
        <p class="sf-muted" style="font-size:.8rem;margin:4px 0 14px">Tax<?= RestaurantAPI::serviceChargeAuto() ? ' & service charge' : '' ?> calculated at the next step.</p>
        <button id="sf-place" class="sf-btn sf-btn-block" type="submit" form="co-form">Place order <?= sf_icon('arrow', 16) ?></button>
        <a class="sf-clear" href="<?= e(sf_url()) ?>" style="display:block;text-decoration:none">Add more items</a>
    </div>
  </aside>

</div>
<?php if ($inlineCard): ?>
<script src="https://js.stripe.com/v3"></script>
<script>
(function(){
  var form = document.getElementById('co-form');
  var btn  = document.getElementById('sf-place');
  var wrap = document.getElementById('sf-pay-element');
  var msg  = document.getElementById('sf-pay-msg');
  if (!form || !btn || !window.Stripe) return;
  var INIT_URL = <?= json_encode(sf_url('checkout')) ?>;
  var stripe = null, elements = null, ready = false, returnUrl = '';

  function method(){ var r = form.querySelector('input[name=pay_method]:checked'); return r ? r.value : 'pickup'; }
  function showMsg(t){ if(msg){ msg.textContent = t; msg.style.display = t ? 'block' : 'none'; } }
  function busy(b, label){ btn.disabled = b; if (label) btn.innerHTML = label; }
  function resetCard(){ ready = false; if (elements){ elements = null; } if (wrap){ wrap.style.display='none'; wrap.innerHTML=''; } showMsg(''); relabel(); }
  function relabel(){ if (!ready) btn.innerHTML = (method()==='card') ? 'Continue to payment' : (method()==='pickup' ? 'Place order' : 'Continue to payment'); }

  form.addEventListener('change', function(e){ if (e.target.name === 'pay_method') resetCard(); });
  relabel();

  btn.addEventListener('click', function(e){
    if (method() !== 'card') return;            // pickup → native form submit
    e.preventDefault();
    if (!form.reportValidity()) return;
    if (!ready) initPayment(); else pay();
  });

  function initPayment(){
    busy(true, 'Preparing secure payment…'); showMsg('');
    var fd = new FormData(form); fd.append('_action', 'card_init');
    fetch(INIT_URL, { method:'POST', body:fd, headers:{ 'X-Requested-With':'fetch' } })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok){ busy(false, 'Continue to payment'); showMsg(j.error || 'Could not start payment.'); return; }
        returnUrl = j.return_url;
        stripe = Stripe(j.pk);
        elements = stripe.elements({ clientSecret: j.client_secret, appearance: {
          theme:'flat',
          variables:{ colorPrimary:<?= json_encode(sf_look()['accent']) ?>, colorText:'#1A1109', fontFamily:<?= json_encode(trim(str_replace("'", '', explode(',', sf_look()['font_b'])[0])) . ', system-ui, sans-serif') ?>, borderRadius:'10px', colorDanger:'#9a2310' }
        }});
        var pe = elements.create('payment', { layout:'tabs' });
        pe.mount('#sf-pay-element');
        wrap.style.display = 'block';
        pe.on('ready', function(){ ready = true; busy(false, 'Pay now'); wrap.scrollIntoView({ behavior:'smooth', block:'center' }); });
      })
      .catch(function(){ busy(false, 'Continue to payment'); showMsg('Network error — please retry.'); });
  }

  function pay(){
    busy(true, 'Processing…'); showMsg('');
    stripe.confirmPayment({ elements: elements, confirmParams: { return_url: returnUrl }, redirect: 'if_required' })
      .then(function(res){
        if (res.error){ busy(false, 'Pay now'); showMsg(res.error.message || 'Payment could not be completed.'); return; }
        var pi = res.paymentIntent;
        window.location = returnUrl + (pi && pi.id ? '&payment_intent=' + encodeURIComponent(pi.id) : '');
      })
      .catch(function(){ busy(false, 'Pay now'); showMsg('Payment could not be completed — please retry.'); });
  }
})();
</script>
<?php endif; ?>
<?php
sf_footer();
