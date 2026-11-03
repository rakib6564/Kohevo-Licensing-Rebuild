<?php
/**
 * Restaurant — online orders queue (live board).
 *
 * Orders placed through the public storefront (/order) as a board with one
 * column per fulfilment state: new → accepted → preparing → ready, plus the
 * last day's completed and cancelled. One click moves an order on (accepting
 * also fires its lines to the kitchen). The board refreshes itself every few
 * seconds and chimes when a new order lands. Links to the full check.
 * Delivery orders can get a courier (DoorDash Drive, Uber Direct): Request courier shows
 * the quote (fee and times) to confirm; the card then follows the courier's
 * status, updated by the provider's webhooks.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';
require_once dirname(__DIR__) . '/includes/delivery.php';

Auth::require();
Auth::requirePerm('restaurant.view');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$tid = current_tenant_id();
$canCourier = Auth::can('restaurant.pos') || Auth::isSuperAdmin();
$couriers = $canCourier ? array_map(fn($p) => $p->label(), RestaurantDelivery::enabledProviders()) : [];

/** The board's orders: everything not finished, plus finished in the last 24 hours. */
$channelNames = array_column(Database::rows("SELECT slug, name FROM restaurant_channels WHERE tenant_id = ?", [$tid]), 'name', 'slug');
$board = function () use ($tid, $channelNames): array {
    $rows = Database::rows(
        "SELECT o.id, co.channel_paid FROM restaurant_orders o
          LEFT JOIN restaurant_channel_orders co ON co.tenant_id = o.tenant_id AND co.order_id = o.id
          WHERE o.tenant_id = ? AND o.source IN ('online','channel')
            AND (o.fulfillment_status NOT IN ('completed','cancelled') OR o.updated_at >= NOW() - INTERVAL 1 DAY)
       ORDER BY o.id DESC LIMIT 150", [$tid]);
    $out = [];
    foreach ($rows as $r) {
        $o = RestaurantAPI::getOrder((int)$r['id']);
        if (!$o) continue;
        $out[] = [
            'id' => (int)$o['id'], 'order_no' => $o['order_no'], 'type' => $o['type'], 'status' => $o['fulfillment_status'],
            'order_status' => $o['status'], 'customer' => (string)($o['customer_name'] ?? ''), 'phone' => (string)($o['customer_phone'] ?? ''),
            'requested' => (string)($o['requested_time'] ?? ''), 'notes' => (string)($o['notes'] ?? ''), 'opened_at' => $o['opened_at'],
            'channel' => $o['source'] === 'channel' ? (string)($channelNames[$o['channel']] ?? $o['channel']) : '', 'flag' => (string)($o['flag_reason'] ?? ''),
            'total' => RestaurantAPI::money((int)$o['total_cents']),
            'paid'  => (int)$o['total_cents'] > 0 && (int)$o['paid_cents'] >= (int)$o['total_cents'],
            'channel_paid' => !empty($r['channel_paid']),   // the channel collected payment; nothing is due here
            'courier' => $o['type'] === 'delivery' ? RestaurantDelivery::view(RestaurantDelivery::latestJob((int)$o['id'])) : null,
            'has_new' => (bool) array_filter($o['items'], fn($i) => $i['kitchen_status'] === 'new'),
            'items' => array_values(array_map(fn($i) => [
                'qty' => (int)$i['qty'], 'name' => $i['name_snapshot'], 'k' => $i['kitchen_status'],
                'mods' => array_column($i['modifiers'] ?? [], 'name_snapshot'), 'notes' => (string)($i['notes'] ?? ''),
            ], array_filter($o['items'], fn($i) => $i['kitchen_status'] !== 'void'))),
        ];
    }
    return $out;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_verify()) { echo json_encode(['ok' => false, 'error' => __('restaurant_err_csrf', 'Security check failed. Reload the page and try again.')]); exit; }
    try {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $action  = (string)($_POST['action'] ?? 'list');
        if ($action !== 'list') {
            if (!Database::value("SELECT 1 FROM restaurant_orders WHERE id = ? AND tenant_id = ? AND source IN ('online','channel')", [$orderId, $tid])) {
                throw new \RuntimeException(__('restaurant_err_check', 'Check not found.'));
            }
            if ($action === 'move') {
                $to = (string)($_POST['to'] ?? '');
                // Accepting an order fires whatever has not gone to the kitchen yet.
                if (in_array($to, ['accepted', 'preparing'], true)) RestaurantAPI::sendToKitchen($orderId);
                RestaurantAPI::setFulfillment($orderId, $to);
            } elseif ($action === 'send') {
                RestaurantAPI::sendToKitchen($orderId);
            } elseif (str_starts_with($action, 'courier_')) {
                if (!$canCourier) throw new \RuntimeException(__('restaurant_cr_err_perm', 'You cannot request couriers.'));
                $job = $action === 'courier_quote' ? null : RestaurantDelivery::job((int)($_POST['job_id'] ?? 0));
                if ($action !== 'courier_quote' && (!$job || (int)$job['order_id'] !== $orderId)) throw new \RuntimeException(__('restaurant_cr_err_job', 'Courier request not found.'));
                $r = match ($action) {
                    'courier_quote'   => RestaurantDelivery::quote($orderId, (string)($_POST['provider'] ?? '')),
                    'courier_accept'  => RestaurantDelivery::accept((int)$job['id']),
                    'courier_cancel'  => RestaurantDelivery::cancel((int)$job['id']),
                    'courier_dismiss' => (function () use ($job) { RestaurantDelivery::dismiss((int)$job['id']); return ['ok' => true]; })(),
                    default           => ['ok' => false, 'error' => __('restaurant_err_action', 'Unknown action.')],
                };
                if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => (string)$r['error'], 'orders' => $board()]); exit; }
            } else {
                throw new \RuntimeException(__('restaurant_err_action', 'Unknown action.'));
            }
        }
        echo json_encode(['ok' => true, 'orders' => $board()]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$orders = $board();
$pageTitle  = __('restaurant_online', 'Online orders');
$currentNav = 'restaurant-online';
require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => __('restaurant_online', 'Online orders')],
]); ?>

<div class="page-header">
    <div><h1><?= e(__('restaurant_online', 'Online orders')) ?></h1>
        <p class="page-header-sub" id="ronSub"></p></div>
    <div class="toolbar">
        <label class="ron-sound"><input type="checkbox" id="ronSound" checked> <?= e(__('restaurant_online_sound', 'Chime on new orders')) ?></label>
        <?php if (RestaurantAPI::onlineOrderingEnabled()): ?>
            <a href="<?= e(rtrim(SLATE_URL, '/')) ?>/order" target="_blank" rel="noopener" class="btn"><?= e(__('restaurant_online_storefront', 'View storefront')) ?> ↗</a>
        <?php else: ?>
            <a href="<?= e(plugin_url('restaurant', 'admin/settings.php')) ?>?tab=online" class="btn btn-primary"><?= e(__('restaurant_online_enable', 'Enable online ordering')) ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if (!RestaurantAPI::onlineOrderingEnabled()): ?>
    <div class="alert alert-info"><?= e(sprintf(__('restaurant_online_off', 'Online ordering is off. Turn it on in Settings to start taking orders at %s.'), rtrim(SLATE_URL, '/') . '/order')) ?></div>
<?php endif; ?>

<div class="ron-board" id="ronBoard"></div>
<details class="ron-done card" id="ronDoneWrap"><summary id="ronDoneSum"></summary><div class="ron-done-list" id="ronDone"></div></details>

<style>
.ron-sound { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-2); margin-right: 8px; }
.ron-board { display: grid; grid-template-columns: repeat(4, minmax(240px, 1fr)); gap: 14px; align-items: start; overflow-x: auto; padding-bottom: 6px; }
.ron-col { background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 10px; min-height: 160px; }
.ron-col > h2 { display: flex; justify-content: space-between; align-items: center; margin: 2px 4px 10px; font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
.ron-col > h2 span { min-width: 24px; text-align: center; padding: 2px 8px; border-radius: 999px; background: var(--surface); color: var(--text); }
.ron-col.is-new > h2 span { background: var(--info); color: #fff; }
.ron-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 10px; overflow: hidden; }
.ron-card.is-fresh { animation: ronPulse 1.6s ease-out 2; border-color: var(--info); }
@keyframes ronPulse { 50% { box-shadow: 0 0 0 4px var(--info-soft); } }
@media (prefers-reduced-motion: reduce) { .ron-card.is-fresh { animation: none; } }
.ron-card header { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; padding: 10px 12px 4px; }
.ron-card header b { font-size: 16px; }
.ron-ch { display: inline-block; margin-left: 4px; padding: 1px 7px; border-radius: 999px; background: var(--info-soft); color: var(--info); font-size: 11px; font-weight: 700; vertical-align: 2px; }
.ron-flag { margin: 0 12px 8px; padding: 6px 9px; border-radius: 8px; background: var(--warning-soft); color: var(--text); font-size: 12.5px; font-weight: 600; }
.ron-age { font-size: 12px; color: var(--muted); font-variant-numeric: tabular-nums; }
.ron-age.is-late { color: var(--danger); font-weight: 700; }
.ron-who { padding: 0 12px; font-size: 13px; color: var(--text-2); }
.ron-items { list-style: none; margin: 8px 0 0; padding: 0 12px; font-size: 13.5px; }
.ron-items li { padding: 3px 0; border-top: 1px dashed var(--border); }
.ron-items small { display: block; color: var(--muted); }
.ron-note { margin: 6px 12px 0; padding: 6px 8px; border-radius: var(--radius-sm); background: var(--warning-soft); font-size: 12.5px; }
.ron-foot { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; padding: 10px 12px; }
.ron-foot .btn-primary { flex: 1; }
.ron-pill { font-size: 11.5px; font-weight: 600; padding: 2px 8px; border-radius: 999px; background: var(--surface-2); }
.ron-pill.is-paid { background: var(--success-soft); color: var(--success); }
.ron-pill.is-due { background: var(--warning-soft); color: var(--warning); }
.ron-courier { margin: 8px 12px 0; padding: 8px 10px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-2); font-size: 12.5px; display: grid; gap: 6px; }
.ron-courier.is-quote { border-color: var(--info); background: var(--info-soft); }
.ron-courier.is-delivered { border-color: var(--success); background: var(--success-soft); }
.ron-courier.is-failed > div:first-child { color: var(--danger); }
.ron-cbtns { display: flex; flex-wrap: wrap; gap: 6px; }
.ron-cbtns .btn-primary { flex: 1; }
.ron-empty { padding: 18px 6px; text-align: center; font-size: 13px; color: var(--muted); }
.ron-done { margin-top: 14px; }
.ron-done summary { padding: 12px 16px; cursor: pointer; font-weight: 600; }
.ron-done-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px; padding: 0 16px 16px; }
@media (max-width: 900px) { .ron-board { grid-template-columns: repeat(4, 82vw); scroll-snap-type: x mandatory; } .ron-col { scroll-snap-align: start; } }
</style>
<script>
(function () {
    const csrf = <?= json_encode(csrf_token()) ?>;
    const orderUrl = <?= json_encode(plugin_url('restaurant', 'admin/order.php')) ?>;
    const receiptUrl = <?= json_encode(plugin_url('restaurant', 'admin/receipt.php')) ?>;
    const T = <?= json_encode(__js([
        'restaurant_online_col_new'       => 'New',
        'restaurant_online_col_accepted'  => 'Accepted',
        'restaurant_online_col_preparing' => 'Preparing',
        'restaurant_online_col_ready'     => 'Ready for pickup',
        'restaurant_online_go_accepted'   => 'Accept and fire',
        'restaurant_online_go_preparing'  => 'Start preparing',
        'restaurant_online_go_ready'      => 'Mark ready',
        'restaurant_online_go_completed'  => 'Picked up',
        'restaurant_online_cancel'        => 'Cancel',
        'restaurant_online_cancel_q'      => 'Cancel this order? Refund any card payment from the order page.',
        'restaurant_online_fire'          => 'Fire new items',
        'restaurant_online_empty'         => 'Nothing here.',
        'restaurant_online_sub'           => '%1$s active · %2$s finished today',
        'restaurant_online_done'          => 'Finished in the last 24 hours (%s)',
        'restaurant_online_for'           => 'for %s',
        'restaurant_online_paid'          => 'Paid',
        'restaurant_online_due'           => 'Due at pickup',
        'restaurant_online_paid_via'      => 'Paid via %s',
        'restaurant_online_completed'     => 'Picked up',
        'restaurant_online_cancelled'     => 'Cancelled',
        'restaurant_pos_details'          => 'Order details',
        'restaurant_rcpt_print'           => 'Print receipt',
        'restaurant_rcpt_takeout'         => 'Takeout',
        'restaurant_rcpt_delivery'        => 'Delivery',
        'restaurant_pos_failed'           => 'That did not work. Try again.',
        'restaurant_cr_request'           => 'Request %s courier',
        'restaurant_cr_confirm'           => 'Confirm courier',
        'restaurant_cr_quote_line'        => '%1$s · pickup %2$s · drop-off %3$s',
        'restaurant_cr_cancel_courier'    => 'Cancel courier',
        'restaurant_cr_cancel_q'          => 'Cancel the courier? The order stays as it is.',
        'restaurant_cr_dismiss'           => 'Not now',
        'restaurant_cr_track'             => 'Track',
    ]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const COLS = ['new', 'accepted', 'preparing', 'ready'];
    const NEXT = { new: 'accepted', accepted: 'preparing', preparing: 'ready', ready: 'completed' };
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    const fmt = (k, ...a) => { let i = 0; return String(T[k] || k).replace(/%(\d+\$)?s/g, (m, p) => esc(p ? a[parseInt(p, 10) - 1] : a[i++])); };
    let orders = <?= json_encode($orders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const COURIERS = <?= json_encode((object)$couriers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    function courier(o) {
        if (o.type !== 'delivery') return '';
        const c = o.courier, keys = Object.keys(COURIERS);
        if (c && c.live_quote) {
            return '<div class="ron-courier is-quote"><div><b>' + esc(c.provider) + '</b> ' + fmt('restaurant_cr_quote_line', c.fee, c.pickup_eta || '—', c.dropoff_eta || '—') + '</div>'
                + '<div class="ron-cbtns"><button type="button" class="btn btn-sm btn-primary" data-courier="courier_accept" data-order="' + o.id + '" data-job="' + c.id + '">' + fmt('restaurant_cr_confirm') + '</button>'
                + '<button type="button" class="btn btn-sm btn-ghost" data-courier="courier_dismiss" data-order="' + o.id + '" data-job="' + c.id + '">' + fmt('restaurant_cr_dismiss') + '</button></div></div>';
        }
        if (c && (c.active || c.status === 'delivered')) {
            return '<div class="ron-courier is-' + esc(c.status) + '"><div><b>' + esc(c.provider) + '</b> · ' + esc(c.label) + (c.courier ? ' · ' + esc(c.courier) : '')
                + (c.dropoff_eta && c.status !== 'delivered' ? ' · ' + fmt('restaurant_online_for', c.dropoff_eta) : '') + '</div>'
                + '<div class="ron-cbtns">' + (c.tracking_url ? '<a class="btn btn-sm" href="' + esc(c.tracking_url) + '" target="_blank" rel="noopener">' + fmt('restaurant_cr_track') + ' ↗</a>' : '')
                + (c.status === 'requested' ? '<button type="button" class="btn btn-sm btn-ghost" data-courier="courier_cancel" data-order="' + o.id + '" data-job="' + c.id + '" data-confirm="1">' + fmt('restaurant_cr_cancel_courier') + '</button>' : '') + '</div></div>';
        }
        if (!keys.length) return c && c.error ? '<div class="ron-courier is-failed"><div>' + esc(c.label) + ': ' + esc(c.error) + '</div></div>' : '';
        return '<div class="ron-courier' + (c && c.error ? ' is-failed' : '') + '">' + (c && c.error && c.error !== 'quote expired' ? '<div>' + esc(c.label) + ': ' + esc(c.error) + '</div>' : '')
            + '<div class="ron-cbtns">' + keys.map((k) => '<button type="button" class="btn btn-sm" data-courier="courier_quote" data-order="' + o.id + '" data-provider="' + esc(k) + '">' + fmt('restaurant_cr_request', COURIERS[k]) + '</button>').join('') + '</div></div>';
    }
    let seen = new Set(orders.map((o) => o.id)), fresh = new Set(), busy = false;

    const age = (ts) => Math.max(0, Math.round((Date.now() - new Date(String(ts).replace(' ', 'T')).getTime()) / 60000));
    function card(o, done) {
        const m = age(o.opened_at);
        return '<article class="ron-card' + (fresh.has(o.id) ? ' is-fresh' : '') + '">'
            + '<header><b>#' + esc(o.order_no) + (o.channel ? ' <span class="ron-ch">' + esc(o.channel) + '</span>' : '') + '</b><span class="ron-age' + (!done && m >= 20 ? ' is-late' : '') + '">' + m + '′</span></header>'
            + '<div class="ron-who"><strong>' + esc(o.customer || '—') + '</strong>' + (o.phone ? ' · <a href="tel:' + esc(o.phone) + '">' + esc(o.phone) + '</a>' : '')
            + '<br>' + esc(T['restaurant_rcpt_' + o.type] || o.type) + (o.requested ? ' · ' + fmt('restaurant_online_for', o.requested) : '') + ' · ' + esc(o.total)
            + ' <span class="ron-pill ' + (o.channel_paid ? 'is-paid">' + fmt('restaurant_online_paid_via', o.channel) : o.paid ? 'is-paid">' + fmt('restaurant_online_paid') : 'is-due">' + fmt('restaurant_online_due')) + '</span></div>'
            + (done ? '' : '<ul class="ron-items">' + o.items.map((i) => '<li><b>' + i.qty + '×</b> ' + esc(i.name) + (i.mods.length ? '<small>' + i.mods.map(esc).join(', ') + '</small>' : '') + (i.notes ? '<small>' + esc(i.notes) + '</small>' : '') + '</li>').join('') + '</ul>')
            + (o.flag && !done ? '<div class="ron-flag">' + esc(o.flag) + '</div>' : '')
            + (o.notes && !done ? '<div class="ron-note">' + esc(o.notes) + '</div>' : '')
            + (!done || (o.courier && o.courier.status === 'delivered') ? courier(o) : '')
            + '<div class="ron-foot">'
            + (done ? '<span class="ron-pill">' + fmt('restaurant_online_' + o.status) + '</span>'
                : '<button type="button" class="btn btn-sm btn-primary" data-move="' + o.id + '" data-to="' + NEXT[o.status] + '">' + fmt('restaurant_online_go_' + NEXT[o.status]) + '</button>'
                  + (o.has_new && o.status !== 'new' ? '<button type="button" class="btn btn-sm" data-send="' + o.id + '">' + fmt('restaurant_online_fire') + '</button>' : '')
                  + '<button type="button" class="btn btn-sm btn-ghost" data-move="' + o.id + '" data-to="cancelled">' + fmt('restaurant_online_cancel') + '</button>')
            + '<a class="btn btn-sm btn-ghost" href="' + orderUrl + '?id=' + o.id + '">' + fmt('restaurant_pos_details') + '</a>'
            + '<a class="btn btn-sm btn-ghost" href="' + receiptUrl + '?id=' + o.id + '" target="_blank" rel="noopener">' + fmt('restaurant_rcpt_print') + '</a>'
            + '</div></article>';
    }
    function render() {
        const live = orders.filter((o) => COLS.includes(o.status)), done = orders.filter((o) => !COLS.includes(o.status));
        document.getElementById('ronBoard').innerHTML = COLS.map((c) => {
            const list = live.filter((o) => o.status === c).sort((a, b) => a.id - b.id);
            return '<section class="ron-col is-' + c + '"><h2>' + fmt('restaurant_online_col_' + c) + '<span>' + list.length + '</span></h2>'
                + (list.length ? list.map((o) => card(o, false)).join('') : '<div class="ron-empty">' + fmt('restaurant_online_empty') + '</div>') + '</section>';
        }).join('');
        document.getElementById('ronDone').innerHTML = done.map((o) => card(o, true)).join('');
        document.getElementById('ronDoneSum').innerHTML = fmt('restaurant_online_done', done.length);
        document.getElementById('ronDoneWrap').hidden = !done.length;
        document.getElementById('ronSub').innerHTML = fmt('restaurant_online_sub', live.length, done.length);
    }
    async function post(data) {
        if (busy) return; busy = true;
        try {
            const r = await fetch(location.pathname, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(Object.assign({ _ajax: '1', _csrf: csrf }, data)) });
            const j = await r.json();
            if (!j.ok) { if (j.orders) { orders = j.orders; render(); } alert(j.error || T.restaurant_pos_failed); return; }
            const incoming = j.orders.filter((o) => !seen.has(o.id));
            incoming.forEach((o) => { seen.add(o.id); fresh.add(o.id); });
            if (incoming.length && data.action === 'list') chime();
            orders = j.orders; render();
            setTimeout(() => { incoming.forEach((o) => fresh.delete(o.id)); }, 4000);
        } catch (e) { /* offline: the next poll tries again */ } finally { busy = false; }
    }
    function chime() {
        if (!document.getElementById('ronSound').checked) return;
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            [0, 0.2, 0.4].forEach((d, i) => { const o = ctx.createOscillator(), g = ctx.createGain(); o.frequency.value = [659, 784, 1046][i];
                o.connect(g); g.connect(ctx.destination); g.gain.setValueAtTime(0.0001, ctx.currentTime + d);
                g.gain.exponentialRampToValueAtTime(0.2, ctx.currentTime + d + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + d + 0.3);
                o.start(ctx.currentTime + d); o.stop(ctx.currentTime + d + 0.32); });
        } catch (e) { /* audio needs a first click on the page */ }
    }
    document.addEventListener('click', (e) => {
        const cr = e.target.closest('[data-courier]');
        if (cr) { if (cr.dataset.confirm && !confirm(T.restaurant_cr_cancel_q)) return;
                  post({ action: cr.dataset.courier, order_id: cr.dataset.order, job_id: cr.dataset.job || '', provider: cr.dataset.provider || '' }); return; }
        const mv = e.target.closest('[data-move]'), sd = e.target.closest('[data-send]');
        if (mv) { if (mv.dataset.to === 'cancelled' && !confirm(T.restaurant_online_cancel_q)) return; post({ action: 'move', order_id: mv.dataset.move, to: mv.dataset.to }); }
        else if (sd) post({ action: 'send', order_id: sd.dataset.send });
    });
    render();
    setInterval(() => { if (!document.hidden) post({ action: 'list' }); }, 8000);
})();
</script>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
