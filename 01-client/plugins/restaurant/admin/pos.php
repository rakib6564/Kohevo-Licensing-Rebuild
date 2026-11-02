<?php
/**
 * Restaurant — POS register.
 *
 * The browser point-of-sale. One self-contained page: a GET renders the
 * register shell (menu grid · open checks · the live check), and a POST with
 * `_ajax=1` is a JSON endpoint that drives the RestaurantAPI order engine
 * (open / add line / qty / void / discount / tip / send / cash + Stripe
 * Terminal tender / close / void). All money is integer cents end to end.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';

Auth::require();
Auth::requirePerm('restaurant.pos');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$tid = current_tenant_id();

// ── AJAX endpoint ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    $fail = function (string $msg) { echo json_encode(['ok' => false, 'error' => $msg]); exit; };
    if (!csrf_verify()) $fail('Security check failed — reload the register.');

    $action = (string)($_POST['action'] ?? '');
    $orderId = (int)($_POST['order_id'] ?? 0);

    // Confirm an order id belongs to this tenant before any mutation.
    $ownOrder = function (int $id) use ($tid): bool {
        if ($id <= 0) return false;
        return (bool) Database::value(
            "SELECT 1 FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$id, $tid]);
    };

    try {
        switch ($action) {
            case 'open':
                $newId = RestaurantAPI::openOrder([
                    'type'        => (string)($_POST['type'] ?? 'dine_in'),
                    'table_id'    => (int)($_POST['table_id'] ?? 0),
                    'guest_count' => (int)($_POST['guest_count'] ?? 1),
                    'notes'       => (string)($_POST['notes'] ?? ''),
                ]);
                echo json_encode(['ok' => true, 'order' => RestaurantAPI::getOrder($newId)]);
                break;

            case 'order':
                if (!$ownOrder($orderId)) $fail('Check not found.');
                echo json_encode(['ok' => true, 'order' => RestaurantAPI::getOrder($orderId)]);
                break;

            case 'item_mods':
                echo json_encode(['ok' => true, 'groups' => RestaurantAPI::getItemModifierGroups((int)($_POST['item_id'] ?? 0))]);
                break;

            case 'add_line':
                if (!$ownOrder($orderId)) $fail('Check not found.');
                $mods = $_POST['modifiers'] ?? [];
                if (is_string($mods)) $mods = array_filter(explode(',', $mods));
                RestaurantAPI::addLine($orderId, [
                    'item_id'   => (int)($_POST['item_id'] ?? 0),
                    'qty'       => (int)($_POST['qty'] ?? 1),
                    'modifiers' => array_map('intval', (array)$mods),
                    'notes'     => (string)($_POST['notes'] ?? ''),
                ]);
                echo json_encode(['ok' => true, 'order' => RestaurantAPI::getOrder($orderId)]);
                break;

            case 'line_qty':
                $line = Database::row("SELECT order_id FROM restaurant_order_items WHERE id = ? AND tenant_id = ?",
                    [(int)($_POST['line_id'] ?? 0), $tid]);
                if (!$line) $fail('Line not found.');
                RestaurantAPI::setLineQty((int)$_POST['line_id'], (int)($_POST['qty'] ?? 1));
                echo json_encode(['ok' => true, 'order' => RestaurantAPI::getOrder((int)$line['order_id'])]);
                break;

            case 'remove_line':
                $line = Database::row("SELECT order_id FROM restaurant_order_items WHERE id = ? AND tenant_id = ?",
                    [(int)($_POST['line_id'] ?? 0), $tid]);
                if (!$line) $fail('Line not found.');
                RestaurantAPI::removeLine((int)$_POST['line_id']);
                echo json_encode(['ok' => true, 'order' => RestaurantAPI::getOrder((int)$line['order_id'])]);
                break;

            case 'discount':
                if (!$ownOrder($orderId)) $fail('Check not found.');
                RestaurantAPI::setDiscount($orderId, (int)round((float)($_POST['amount'] ?? 0) * 100));
                echo json_encode(['ok' => true, 'order' => RestaurantAPI::getOrder($orderId)]);
                break;

            case 'tip':
                if (!$ownOrder($orderId)) $fail('Check not found.');
                RestaurantAPI::setTip($orderId, (int)round((float)($_POST['amount'] ?? 0) * 100));
                echo json_encode(['ok' => true, 'order' => RestaurantAPI::getOrder($orderId)]);
                break;

            case 'send':
                if (!$ownOrder($orderId)) $fail('Check not found.');
                RestaurantAPI::sendToKitchen($orderId);
                echo json_encode(['ok' => true, 'order' => RestaurantAPI::getOrder($orderId)]);
                break;

            case 'cash':
                if (!$ownOrder($orderId)) $fail('Check not found.');
                RestaurantAPI::recordCashPayment(
                    $orderId,
                    (int)round((float)($_POST['amount'] ?? 0) * 100),
                    (int)round((float)($_POST['tip'] ?? 0) * 100)
                );
                echo json_encode(['ok' => true, 'order' => RestaurantAPI::getOrder($orderId)]);
                break;

            case 'terminal_begin':
                if (!$ownOrder($orderId)) $fail('Check not found.');
                $res = RestaurantAPI::beginTerminalPayment(
                    $orderId,
                    (int)($_POST['reader_id'] ?? 0),
                    (int)round((float)($_POST['amount'] ?? 0) * 100),
                    (int)round((float)($_POST['tip'] ?? 0) * 100)
                );
                echo json_encode($res);
                break;

            case 'terminal_poll':
                $pid = (int)($_POST['payment_id'] ?? 0);
                $pay = Database::row("SELECT order_id FROM restaurant_payments WHERE id = ? AND tenant_id = ?", [$pid, $tid]);
                if (!$pay) $fail('Payment not found.');
                $res = RestaurantAPI::pollTerminalPayment($pid);
                $res['order'] = RestaurantAPI::getOrder((int)$pay['order_id']);
                echo json_encode($res);
                break;

            case 'terminal_cancel':
                $pid = (int)($_POST['payment_id'] ?? 0);
                $pay = Database::row("SELECT order_id FROM restaurant_payments WHERE id = ? AND tenant_id = ?", [$pid, $tid]);
                if (!$pay) $fail('Payment not found.');
                RestaurantAPI::cancelTerminalPayment($pid);
                echo json_encode(['ok' => true, 'order' => RestaurantAPI::getOrder((int)$pay['order_id'])]);
                break;

            case 'close':
                if (!$ownOrder($orderId)) $fail('Check not found.');
                RestaurantAPI::closeOrder($orderId);
                echo json_encode(['ok' => true, 'order' => RestaurantAPI::getOrder($orderId)]);
                break;

            case 'void':
                if (!$ownOrder($orderId)) $fail('Check not found.');
                if (!Auth::can('restaurant.manage_orders') && !Auth::isSuperAdmin()) $fail('Not allowed to void.');
                RestaurantAPI::voidOrder($orderId, (string)($_POST['reason'] ?? ''));
                echo json_encode(['ok' => true, 'order' => RestaurantAPI::getOrder($orderId)]);
                break;

            case 'refresh':
                echo json_encode([
                    'ok'     => true,
                    'open'   => RestaurantAPI::getOpenOrders(),
                    'tables' => Database::rows(
                        "SELECT t.id, t.label, t.seats, t.status, s.name AS section
                           FROM restaurant_tables t
                           LEFT JOIN restaurant_sections s ON s.id = t.section_id
                          WHERE t.tenant_id = ? AND t.is_active = 1
                          ORDER BY s.sort_order, t.label", [$tid]),
                ]);
                break;

            default:
                $fail('Unknown action.');
        }
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Bootstrap data for the initial render ──────────────────────────────────
$menu       = RestaurantAPI::getPosMenu();
$openOrders = RestaurantAPI::getOpenOrders();
$tables     = Database::rows(
    "SELECT t.id, t.label, t.seats, t.status, s.name AS section
       FROM restaurant_tables t
       LEFT JOIN restaurant_sections s ON s.id = t.section_id
      WHERE t.tenant_id = ? AND t.is_active = 1
      ORDER BY s.sort_order, t.label", [$tid]);

// Which items carry modifier groups → the register knows when to prompt.
$modItemIds = array_map('intval', array_column(Database::rows(
    "SELECT DISTINCT item_id FROM restaurant_item_modifier_groups WHERE tenant_id = ?", [$tid]), 'item_id'));

$sym = trim(preg_replace('/[0-9.,\s]/u', '', RestaurantAPI::money(0)));

$boot = [
    'menu'        => $menu,
    'openOrders'  => $openOrders,
    'tables'      => $tables,
    'modItemIds'  => $modItemIds,
    'readers'     => RestaurantAPI::readers(),
    'terminal'    => RestaurantAPI::terminalAvailable(),
    'tipPresets'  => RestaurantAPI::tipPresets(),
    'currencySym' => $sym ?: '$',
    'canVoid'     => Auth::can('restaurant.manage_orders') || Auth::isSuperAdmin(),
    'endpoint'    => plugin_url('restaurant', 'admin/pos.php'),
    'csrf'        => csrf_token(),
];

$pageTitle  = 'POS register';
$currentNav = 'restaurant-pos';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => 'POS register'],
]); ?>

<style>
.rpos { display:grid; grid-template-columns: 240px 1fr 360px; gap:14px; align-items:start;
        --rpos-line:var(--border, #e5e7eb); }
@media (max-width:1100px){ .rpos{ grid-template-columns:1fr; } }
.rpos-col { background:var(--surface,#fff); border:1px solid var(--rpos-line); border-radius:12px; overflow:hidden; }
.rpos-col-h { padding:10px 14px; font-weight:600; font-size:.82rem; text-transform:uppercase; letter-spacing:.04em;
              color:var(--muted,#6b7280); border-bottom:1px solid var(--rpos-line); display:flex; justify-content:space-between; align-items:center; }
/* Open checks */
.rpos-checks { max-height:74vh; overflow:auto; }
.rpos-check { width:100%; text-align:left; padding:10px 14px; border:0; border-bottom:1px solid var(--rpos-line);
              background:transparent; cursor:pointer; display:block; }
.rpos-check:hover { background:var(--surface-2,#f9fafb); }
.rpos-check.is-active { background:var(--primary-50,#eff6ff); box-shadow:inset 3px 0 0 var(--primary,#2563eb); }
.rpos-check b { display:block; font-size:.95rem; }
.rpos-check small { color:var(--muted,#6b7280); }
.rpos-new { margin:10px 14px; }
/* Menu */
.rpos-cats { display:flex; gap:6px; flex-wrap:wrap; padding:10px 14px; border-bottom:1px solid var(--rpos-line); }
.rpos-cat { padding:6px 12px; border:1px solid var(--rpos-line); background:var(--surface,#fff); border-radius:999px;
            cursor:pointer; font-size:.85rem; }
.rpos-cat.is-active { background:var(--primary,#2563eb); color:#fff; border-color:var(--primary,#2563eb); }
.rpos-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:10px; padding:14px;
             max-height:70vh; overflow:auto; }
.rpos-item { border:1px solid var(--rpos-line); border-radius:10px; padding:12px 10px; cursor:pointer;
             text-align:left; background:var(--surface,#fff); min-height:78px; display:flex; flex-direction:column;
             justify-content:space-between; }
.rpos-item:hover { border-color:var(--primary,#2563eb); }
.rpos-item.is-86 { opacity:.5; cursor:not-allowed; }
.rpos-item .nm { font-weight:600; font-size:.9rem; line-height:1.2; }
.rpos-item .pr { color:var(--muted,#6b7280); font-size:.85rem; margin-top:6px; }
.rpos-item .tag86 { color:var(--danger,#dc2626); font-weight:700; font-size:.7rem; }
/* Check */
.rpos-lines { max-height:48vh; overflow:auto; padding:6px 0; }
.rpos-line { display:flex; gap:8px; padding:8px 14px; border-bottom:1px solid var(--rpos-line); align-items:flex-start; }
.rpos-line.is-void { opacity:.5; text-decoration:line-through; }
.rpos-line .ln-main { flex:1; min-width:0; }
.rpos-line .ln-name { font-weight:600; font-size:.9rem; }
.rpos-line .ln-mods { color:var(--muted,#6b7280); font-size:.8rem; }
.rpos-line .ln-amt { font-variant-numeric:tabular-nums; font-weight:600; white-space:nowrap; }
.rpos-qty { display:inline-flex; align-items:center; gap:6px; margin-top:4px; }
.rpos-qty button { width:24px; height:24px; border:1px solid var(--rpos-line); background:var(--surface,#fff);
                   border-radius:6px; cursor:pointer; font-weight:700; line-height:1; }
.rpos-totals { padding:10px 14px; border-top:1px solid var(--rpos-line); }
.rpos-totals .row { display:flex; justify-content:space-between; padding:2px 0; font-size:.9rem; }
.rpos-totals .row.grand { font-size:1.15rem; font-weight:700; border-top:1px solid var(--rpos-line);
                          margin-top:6px; padding-top:8px; }
.rpos-totals .row.bal { color:var(--danger,#dc2626); font-weight:600; }
.rpos-actions { display:grid; grid-template-columns:1fr 1fr; gap:8px; padding:12px 14px; }
.rpos-actions .full { grid-column:1 / -1; }
.rpos-empty { padding:30px 14px; text-align:center; color:var(--muted,#6b7280); }
.rpos-badge { font-size:.7rem; padding:2px 7px; border-radius:999px; background:var(--surface-2,#f3f4f6); }
/* Modal */
.rpos-modal { position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; align-items:center;
              justify-content:center; z-index:1000; padding:16px; }
.rpos-modal.open { display:flex; }
.rpos-sheet { background:var(--surface,#fff); border-radius:14px; max-width:440px; width:100%; max-height:88vh;
              overflow:auto; padding:18px; }
.rpos-sheet h3 { margin:0 0 12px; }
.rpos-mgroup { margin-bottom:14px; }
.rpos-mgroup > b { display:block; margin-bottom:6px; }
.rpos-mopt { display:flex; align-items:center; gap:8px; padding:6px 0; }
.rpos-paybtns { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin:10px 0; }
.rpos-tipbtns { display:flex; gap:6px; flex-wrap:wrap; margin:8px 0; }
.rpos-tipbtns button { flex:1; }
#rposToast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:#111827; color:#fff;
             padding:10px 18px; border-radius:8px; z-index:2000; display:none; }
</style>

<div class="rpos">
    <!-- Open checks -->
    <div class="rpos-col">
        <div class="rpos-col-h"><span>Open checks</span><span id="rposCheckCount" class="rpos-badge">0</span></div>
        <button class="btn btn-primary rpos-new" id="rposNewBtn" style="width:calc(100% - 28px);">+ New check</button>
        <div class="rpos-checks" id="rposChecks"></div>
    </div>

    <!-- Menu -->
    <div class="rpos-col">
        <div class="rpos-col-h"><span>Menu</span></div>
        <div class="rpos-cats" id="rposCats"></div>
        <div class="rpos-grid" id="rposGrid"></div>
    </div>

    <!-- Current check -->
    <div class="rpos-col">
        <div class="rpos-col-h"><span id="rposCheckTitle">Check</span><span id="rposCheckStatus" class="rpos-badge"></span></div>
        <div id="rposCheckBody">
            <div class="rpos-empty">Select or start a check to begin.</div>
        </div>
    </div>
</div>

<!-- New-check modal -->
<div class="rpos-modal" id="rposNewModal">
    <div class="rpos-sheet">
        <h3>New check</h3>
        <div class="field">
            <label class="field-label">Type</label>
            <select id="rposNewType">
                <option value="dine_in">Dine in</option>
                <option value="takeout">Takeout</option>
                <option value="delivery">Delivery</option>
            </select>
        </div>
        <div class="field" id="rposTableField">
            <label class="field-label">Table</label>
            <select id="rposNewTable"><option value="">— none —</option></select>
        </div>
        <div class="field">
            <label class="field-label">Guests</label>
            <input type="number" id="rposNewGuests" value="2" min="1" step="1">
        </div>
        <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:8px;">
            <button class="btn btn-ghost" data-close>Cancel</button>
            <button class="btn btn-primary" id="rposNewConfirm">Open check</button>
        </div>
    </div>
</div>

<!-- Modifier modal -->
<div class="rpos-modal" id="rposModModal">
    <div class="rpos-sheet">
        <h3 id="rposModTitle">Options</h3>
        <div id="rposModBody"></div>
        <div class="field">
            <label class="field-label">Notes (optional)</label>
            <input type="text" id="rposModNotes" maxlength="255" placeholder="e.g. no onions">
        </div>
        <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:8px;">
            <button class="btn btn-ghost" data-close>Cancel</button>
            <button class="btn btn-primary" id="rposModConfirm">Add to check</button>
        </div>
    </div>
</div>

<!-- Payment modal -->
<div class="rpos-modal" id="rposPayModal">
    <div class="rpos-sheet">
        <h3>Take payment</h3>
        <div class="row" style="display:flex; justify-content:space-between; font-size:1.1rem; margin-bottom:6px;">
            <span>Balance due</span><b id="rposPayBalance">—</b>
        </div>
        <div class="field">
            <label class="field-label">Add tip</label>
            <div class="rpos-tipbtns" id="rposTipBtns"></div>
            <input type="number" id="rposPayTip" value="0" min="0" step="0.01">
        </div>
        <div class="rpos-paybtns">
            <button class="btn" id="rposPayCashBtn">Cash</button>
            <button class="btn" id="rposPayCardBtn">Card / Terminal</button>
        </div>
        <div id="rposPayCash" style="display:none;">
            <div class="field">
                <label class="field-label">Cash tendered</label>
                <input type="number" id="rposCashAmount" min="0" step="0.01">
            </div>
            <div id="rposChange" style="margin:6px 0; font-weight:600;"></div>
            <button class="btn btn-primary" style="width:100%;" id="rposCashConfirm">Record cash payment</button>
        </div>
        <div id="rposPayCard" style="display:none;">
            <div class="field" id="rposReaderField">
                <label class="field-label">Card reader</label>
                <select id="rposReader"></select>
            </div>
            <div id="rposCardStatus" style="margin:8px 0; color:var(--muted,#6b7280);"></div>
            <button class="btn btn-primary" style="width:100%;" id="rposCardConfirm">Charge on reader</button>
            <button class="btn btn-ghost" style="width:100%; margin-top:6px; display:none;" id="rposCardCancel">Cancel charge</button>
        </div>
        <div style="text-align:right; margin-top:10px;">
            <button class="btn btn-ghost" data-close>Close</button>
        </div>
    </div>
</div>

<div id="rposToast"></div>

<script>
(function () {
    "use strict";
    const BOOT = <?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const SYM = BOOT.currencySym;
    const modItems = new Set(BOOT.modItemIds.map(Number));
    let state = { orders: BOOT.openOrders || [], current: null, cat: 'all', terminalPayId: null, pollTimer: null };

    const $ = (id) => document.getElementById(id);
    const fmt = (c) => (c < 0 ? '-' : '') + SYM + (Math.abs(Number(c) || 0) / 100).toFixed(2);
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[m]));

    function toast(msg) {
        const t = $('rposToast'); t.textContent = msg; t.style.display = 'block';
        clearTimeout(t._tmo); t._tmo = setTimeout(() => t.style.display = 'none', 2600);
    }

    async function api(action, data) {
        const body = new URLSearchParams();
        body.set('_ajax', '1'); body.set('_csrf', BOOT.csrf); body.set('action', action);
        for (const k in (data || {})) {
            if (Array.isArray(data[k])) data[k].forEach(v => body.append(k + '[]', v));
            else body.set(k, data[k]);
        }
        const r = await fetch(BOOT.endpoint, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body });
        let j; try { j = await r.json(); } catch (e) { throw new Error('Bad response from server.'); }
        return j;
    }

    // ── Open-checks rail ───────────────────────────────────────
    function renderChecks() {
        const wrap = $('rposChecks');
        $('rposCheckCount').textContent = state.orders.length;
        if (!state.orders.length) { wrap.innerHTML = '<div class="rpos-empty">No open checks.</div>'; return; }
        wrap.innerHTML = state.orders.map(o => {
            const where = o.table_label ? ('Table ' + esc(o.table_label))
                : (o.type === 'takeout' ? 'Takeout' : o.type === 'delivery' ? 'Delivery' : 'Dine in');
            const active = state.current && state.current.id == o.id ? ' is-active' : '';
            return `<button class="rpos-check${active}" data-oid="${o.id}">
                <b>#${esc(o.order_no)}</b>
                <small>${where} · ${fmt(o.total_cents)}${o.status === 'sent' ? ' · sent' : ''}</small>
            </button>`;
        }).join('');
        wrap.querySelectorAll('[data-oid]').forEach(b =>
            b.onclick = () => loadOrder(Number(b.dataset.oid)));
    }

    // ── Menu ───────────────────────────────────────────────────
    function renderCats() {
        const cats = [{ id: 'all', name: 'All' }].concat(BOOT.menu.categories.map(c => ({ id: String(c.id), name: c.name })));
        $('rposCats').innerHTML = cats.map(c =>
            `<div class="rpos-cat${state.cat == c.id ? ' is-active' : ''}" data-cat="${esc(c.id)}">${esc(c.name)}</div>`).join('');
        $('rposCats').querySelectorAll('[data-cat]').forEach(el =>
            el.onclick = () => { state.cat = el.dataset.cat; renderCats(); renderGrid(); });
    }
    function renderGrid() {
        const items = BOOT.menu.items.filter(i => state.cat === 'all' || String(i.category_id) === state.cat);
        if (!items.length) { $('rposGrid').innerHTML = '<div class="rpos-empty">No items in this category.</div>'; return; }
        $('rposGrid').innerHTML = items.map(i => {
            const off = Number(i.is_86) === 1;
            return `<button class="rpos-item${off ? ' is-86' : ''}" data-item="${i.id}" ${off ? 'disabled' : ''}>
                <span class="nm">${esc(i.name)}</span>
                <span class="pr">${off ? '<span class="tag86">86’d</span>' : fmt(i.price_cents)}</span>
            </button>`;
        }).join('');
        $('rposGrid').querySelectorAll('[data-item]').forEach(b =>
            b.onclick = () => addItem(Number(b.dataset.item)));
    }

    // ── Current check ──────────────────────────────────────────
    async function loadOrder(id) {
        const j = await api('order', { order_id: id });
        if (!j.ok) return toast(j.error || 'Could not load check.');
        setCurrent(j.order);
    }
    function setCurrent(order) {
        state.current = order;
        // keep the rail in sync
        const idx = state.orders.findIndex(o => o.id == order.id);
        const isOpen = ['open', 'sent'].includes(order.status);
        if (isOpen) { if (idx >= 0) state.orders[idx] = order; else state.orders.push(order); }
        else if (idx >= 0) state.orders.splice(idx, 1);
        renderChecks();
        renderCheck();
    }
    function renderCheck() {
        const o = state.current, body = $('rposCheckBody');
        if (!o) { body.innerHTML = '<div class="rpos-empty">Select or start a check to begin.</div>';
                  $('rposCheckTitle').textContent = 'Check'; $('rposCheckStatus').textContent = ''; return; }
        const where = o.table_label ? ('Table ' + esc(o.table_label)) : (o.type === 'takeout' ? 'Takeout' : o.type === 'delivery' ? 'Delivery' : 'Dine in');
        $('rposCheckTitle').textContent = '#' + o.order_no;
        $('rposCheckStatus').textContent = o.status;

        const balance = Math.max(0, Number(o.total_cents) - Number(o.paid_cents));
        const editable = ['open', 'sent'].includes(o.status);
        const lines = (o.items || []).map(it => {
            const voided = it.kitchen_status === 'void';
            const mods = (it.modifiers || []).map(m => esc(m.name_snapshot) +
                (Number(m.price_delta_cents) ? ' (' + fmt(m.price_delta_cents) + ')' : '')).join(', ');
            const qtyCtl = (editable && !voided)
                ? `<span class="rpos-qty">
                     <button data-qd="${it.id}">−</button><span>${it.qty}</span><button data-qi="${it.id}">+</button>
                     <button data-rm="${it.id}" title="Remove" style="margin-left:6px;color:var(--danger,#dc2626);">×</button>
                   </span>` : `<span class="rpos-qty"><span>×${it.qty}</span></span>`;
            return `<div class="rpos-line${voided ? ' is-void' : ''}">
                <div class="ln-main">
                    <div class="ln-name">${esc(it.name_snapshot)} ${it.kitchen_status === 'new' ? '' : '<span class="rpos-badge">'+esc(it.kitchen_status)+'</span>'}</div>
                    ${mods ? `<div class="ln-mods">${mods}</div>` : ''}
                    ${it.notes ? `<div class="ln-mods"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg> ${esc(it.notes)}</div>` : ''}
                    ${qtyCtl}
                </div>
                <div class="ln-amt">${fmt(it.line_total_cents)}</div>
            </div>`;
        }).join('');

        body.innerHTML = `
            <div style="padding:8px 14px; color:var(--muted,#6b7280); font-size:.85rem;">
                ${where} · ${o.guest_count} guest(s)${o.customer_name ? ' · ' + esc(o.customer_name) : ''}
            </div>
            <div class="rpos-lines">${lines || '<div class="rpos-empty">No items yet — tap the menu.</div>'}</div>
            <div class="rpos-totals">
                <div class="row"><span>Subtotal</span><span>${fmt(o.subtotal_cents)}</span></div>
                ${Number(o.discount_cents) ? `<div class="row"><span>Discount</span><span>-${fmt(o.discount_cents)}</span></div>` : ''}
                ${Number(o.service_charge_cents) ? `<div class="row"><span>Service</span><span>${fmt(o.service_charge_cents)}</span></div>` : ''}
                <div class="row"><span>Tax</span><span>${fmt(o.tax_cents)}</span></div>
                ${Number(o.tip_cents) ? `<div class="row"><span>Tip</span><span>${fmt(o.tip_cents)}</span></div>` : ''}
                <div class="row grand"><span>Total</span><span>${fmt(o.total_cents)}</span></div>
                ${Number(o.paid_cents) ? `<div class="row"><span>Paid</span><span>${fmt(o.paid_cents)}</span></div>` : ''}
                ${balance > 0 && Number(o.paid_cents) ? `<div class="row bal"><span>Balance</span><span>${fmt(balance)}</span></div>` : ''}
            </div>
            ${renderActions(o, editable, balance)}`;

        body.querySelectorAll('[data-qd]').forEach(b => b.onclick = () => changeQty(b.dataset.qd, -1));
        body.querySelectorAll('[data-qi]').forEach(b => b.onclick = () => changeQty(b.dataset.qi, 1));
        body.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => removeLine(b.dataset.rm));
        const bind = (id, fn) => { const el = $(id); if (el) el.onclick = fn; };
        bind('actSend', sendKitchen); bind('actDiscount', doDiscount); bind('actPay', openPay);
        bind('actVoid', voidOrder); bind('actClose', closeOrder);
    }
    function renderActions(o, editable, balance) {
        if (o.status === 'paid' || o.status === 'closed' || o.status === 'voided')
            return `<div class="rpos-actions"><div class="full" style="text-align:center;color:var(--muted,#6b7280);">Check ${o.status}.</div></div>`;
        const hasNew = (o.items || []).some(it => it.kitchen_status === 'new');
        return `<div class="rpos-actions">
            <button class="btn" id="actSend" ${hasNew ? '' : 'disabled'}>Send to kitchen</button>
            <button class="btn" id="actDiscount">Discount</button>
            <button class="btn btn-primary full" id="actPay" ${Number(o.total_cents) > 0 ? '' : 'disabled'}>Pay ${fmt(balance)}</button>
            ${BOOT.canVoid ? '<button class="btn btn-danger" id="actVoid">Void check</button>' : '<span></span>'}
            <button class="btn btn-ghost" id="actClose">Close check</button>
        </div>`;
    }

    // ── Mutations ──────────────────────────────────────────────
    async function changeQty(lineId, delta) {
        const it = (state.current.items || []).find(x => x.id == lineId); if (!it) return;
        const q = Number(it.qty) + delta;
        if (q < 1) return removeLine(lineId);
        const j = await api('line_qty', { line_id: lineId, qty: q });
        j.ok ? setCurrent(j.order) : toast(j.error);
    }
    async function removeLine(lineId) {
        const j = await api('remove_line', { line_id: lineId });
        j.ok ? setCurrent(j.order) : toast(j.error);
    }
    async function addItem(itemId) {
        if (!state.current) return toast('Start or select a check first.');
        if (!['open', 'sent'].includes(state.current.status)) return toast('This check is no longer open.');
        if (modItems.has(itemId)) return openModModal(itemId);
        const j = await api('add_line', { order_id: state.current.id, item_id: itemId, qty: 1 });
        j.ok ? setCurrent(j.order) : toast(j.error);
    }
    async function sendKitchen() {
        const j = await api('send', { order_id: state.current.id });
        j.ok ? (setCurrent(j.order), toast('Sent to kitchen.')) : toast(j.error);
    }
    async function doDiscount() {
        const v = prompt('Discount amount (' + SYM + '):', '0'); if (v == null) return;
        const j = await api('discount', { order_id: state.current.id, amount: parseFloat(v) || 0 });
        j.ok ? setCurrent(j.order) : toast(j.error);
    }
    async function voidOrder() {
        if (!confirm('Void this entire check? This cannot be undone.')) return;
        const reason = prompt('Reason (optional):', '') || '';
        const j = await api('void', { order_id: state.current.id, reason });
        j.ok ? (setCurrent(j.order), toast('Check voided.')) : toast(j.error);
    }
    async function closeOrder() {
        if (!confirm('Close this check without further payment?')) return;
        const j = await api('close', { order_id: state.current.id });
        j.ok ? (setCurrent(j.order), toast('Check closed.')) : toast(j.error);
    }

    // ── New check ──────────────────────────────────────────────
    function openNewModal() {
        const sel = $('rposNewTable');
        const open = (BOOT.tables || []).filter(t => t.status !== 'seated');
        sel.innerHTML = '<option value="">— none —</option>' + open.map(t =>
            `<option value="${t.id}">${esc(t.label)}${t.section ? ' (' + esc(t.section) + ')' : ''} · ${t.seats} seats${t.status === 'dirty' ? ' · dirty' : ''}</option>`).join('');
        $('rposNewType').value = 'dine_in'; $('rposNewGuests').value = 2;
        toggleTableField();
        openModal('rposNewModal');
    }
    function toggleTableField() {
        $('rposTableField').style.display = $('rposNewType').value === 'dine_in' ? '' : 'none';
    }
    async function confirmNew() {
        const type = $('rposNewType').value;
        const j = await api('open', {
            type, table_id: type === 'dine_in' ? ($('rposNewTable').value || 0) : 0,
            guest_count: $('rposNewGuests').value || 1
        });
        if (!j.ok) return toast(j.error || 'Could not open check.');
        closeModal('rposNewModal');
        // mark table seated locally
        const tid = $('rposNewTable').value;
        if (tid) { const t = (BOOT.tables || []).find(x => x.id == tid); if (t) t.status = 'seated'; }
        setCurrent(j.order);
    }

    // ── Modifier modal ─────────────────────────────────────────
    let modCtx = { itemId: 0 };
    async function openModModal(itemId) {
        const j = await api('item_mods', { item_id: itemId });
        if (!j.ok) return toast(j.error);
        const item = BOOT.menu.items.find(i => i.id == itemId);
        modCtx = { itemId };
        $('rposModTitle').textContent = item ? item.name : 'Options';
        $('rposModNotes').value = '';
        $('rposModBody').innerHTML = (j.groups || []).map((g, gi) => {
            const single = Number(g.max_select) === 1;
            const opts = (g.modifiers || []).map(m => `
                <label class="rpos-mopt">
                    <input type="${single ? 'radio' : 'checkbox'}" name="mg${gi}" value="${m.id}" data-group="${gi}">
                    <span>${esc(m.name)}</span>
                    <span style="margin-left:auto;color:var(--muted,#6b7280);">${Number(m.price_delta_cents) ? fmt(m.price_delta_cents) : ''}</span>
                </label>`).join('');
            const req = Number(g.is_required) ? ' <span style="color:var(--danger,#dc2626)">*</span>' : '';
            const rule = (g.min_select || g.max_select) ? `<small style="color:var(--muted,#6b7280)"> (choose ${g.min_select || 0}–${g.max_select || '∞'})</small>` : '';
            return `<div class="rpos-mgroup" data-gi="${gi}"><b>${esc(g.name)}${req}${rule}</b>${opts || '<small>No options.</small>'}</div>`;
        }).join('') || '<p>No options for this item.</p>';
        openModal('rposModModal');
    }
    async function confirmMod() {
        const picked = Array.from($('rposModBody').querySelectorAll('input:checked')).map(i => i.value);
        const j = await api('add_line', {
            order_id: state.current.id, item_id: modCtx.itemId, qty: 1,
            modifiers: picked, notes: $('rposModNotes').value || ''
        });
        if (!j.ok) return toast(j.error);
        closeModal('rposModModal'); setCurrent(j.order);
    }

    // ── Payment ────────────────────────────────────────────────
    function openPay() {
        const o = state.current;
        const balance = Math.max(0, Number(o.total_cents) - Number(o.paid_cents));
        $('rposPayBalance').textContent = fmt(balance);
        $('rposPayBalance').dataset.cents = balance;
        $('rposPayTip').value = '0';
        $('rposCashAmount').value = (balance / 100).toFixed(2);
        $('rposPayCash').style.display = 'none'; $('rposPayCard').style.display = 'none';
        $('rposChange').textContent = '';
        // tip presets (percent of pre-tip total excl. existing tip)
        const base = Math.max(0, Number(o.total_cents) - Number(o.tip_cents) - Number(o.paid_cents));
        $('rposTipBtns').innerHTML = '<button class="btn btn-sm" data-tip="0">No tip</button>' +
            (BOOT.tipPresets || []).map(p =>
                `<button class="btn btn-sm" data-tip="${(base * p / 100 / 100).toFixed(2)}">${p}%</button>`).join('');
        $('rposTipBtns').querySelectorAll('[data-tip]').forEach(b => b.onclick = () => {
            $('rposPayTip').value = b.dataset.tip; recalcChange();
        });
        // readers
        const rsel = $('rposReader');
        if (BOOT.terminal && (BOOT.readers || []).length) {
            rsel.innerHTML = BOOT.readers.map(r => `<option value="${r.id}">${esc(r.label)}</option>`).join('');
            $('rposPayCardBtn').disabled = false;
            $('rposPayCardBtn').title = '';
        } else {
            $('rposPayCardBtn').disabled = true;
            $('rposPayCardBtn').title = 'No Stripe Terminal reader configured.';
        }
        resetCardUI();
        openModal('rposPayModal');
    }
    function recalcChange() {
        const bal = Number($('rposPayBalance').dataset.cents);
        const tip = Math.round((parseFloat($('rposPayTip').value) || 0) * 100);
        const tendered = Math.round((parseFloat($('rposCashAmount').value) || 0) * 100);
        const due = bal + tip;
        const change = tendered - due;
        $('rposChange').textContent = change >= 0 ? 'Change: ' + fmt(change) : 'Still owed: ' + fmt(-change);
    }
    async function payCash() {
        const o = state.current;
        const bal = Number($('rposPayBalance').dataset.cents);
        const tip = parseFloat($('rposPayTip').value) || 0;
        const tendered = parseFloat($('rposCashAmount').value) || 0;
        // amount applied to the check is the balance; overpayment is change, not recorded.
        const j = await api('cash', { order_id: o.id, amount: (bal / 100).toFixed(2), tip });
        if (!j.ok) return toast(j.error);
        toast('Cash recorded.'); closeModal('rposPayModal'); setCurrent(j.order);
    }
    function resetCardUI() {
        clearInterval(state.pollTimer); state.pollTimer = null; state.terminalPayId = null;
        $('rposCardStatus').textContent = ''; $('rposCardConfirm').disabled = false;
        $('rposCardConfirm').style.display = ''; $('rposCardCancel').style.display = 'none';
    }
    async function payCardBegin() {
        const o = state.current;
        const bal = Number($('rposPayBalance').dataset.cents);
        const tip = parseFloat($('rposPayTip').value) || 0;
        $('rposCardConfirm').disabled = true;
        $('rposCardStatus').textContent = 'Sending to reader…';
        const j = await api('terminal_begin', { order_id: o.id, reader_id: $('rposReader').value, amount: (bal / 100).toFixed(2), tip });
        if (!j.ok) { $('rposCardStatus').textContent = j.error || 'Could not start charge.'; $('rposCardConfirm').disabled = false; return; }
        state.terminalPayId = j.payment_id;
        $('rposCardStatus').textContent = j.simulated ? 'Simulated reader — completing…' : 'Present card on the reader…';
        $('rposCardConfirm').style.display = 'none'; $('rposCardCancel').style.display = '';
        pollCard();
    }
    function pollCard() {
        clearInterval(state.pollTimer);
        state.pollTimer = setInterval(async () => {
            if (!state.terminalPayId) return;
            const j = await api('terminal_poll', { payment_id: state.terminalPayId });
            if (j.order) setCurrent(j.order);
            if (j.state === 'paid') {
                clearInterval(state.pollTimer); toast('Card approved.'); resetCardUI(); closeModal('rposPayModal');
            } else if (j.state === 'failed') {
                clearInterval(state.pollTimer);
                $('rposCardStatus').textContent = j.error || 'Payment failed.';
                $('rposCardConfirm').style.display = ''; $('rposCardConfirm').disabled = false;
                $('rposCardCancel').style.display = 'none'; state.terminalPayId = null;
            }
        }, 2000);
    }
    async function cancelCard() {
        if (!state.terminalPayId) return resetCardUI();
        clearInterval(state.pollTimer);
        const j = await api('terminal_cancel', { payment_id: state.terminalPayId });
        if (j.order) setCurrent(j.order);
        resetCardUI(); $('rposCardStatus').textContent = 'Charge cancelled.';
    }

    // ── Modal helpers ──────────────────────────────────────────
    function openModal(id) { $(id).classList.add('open'); }
    function closeModal(id) { $(id).classList.remove('open'); if (id === 'rposPayModal') resetCardUI(); }
    document.querySelectorAll('.rpos-modal').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
        m.querySelectorAll('[data-close]').forEach(b => b.onclick = () => closeModal(m.id));
    });

    // ── Wire up ────────────────────────────────────────────────
    $('rposNewBtn').onclick = openNewModal;
    $('rposNewType').onchange = toggleTableField;
    $('rposNewConfirm').onclick = confirmNew;
    $('rposModConfirm').onclick = confirmMod;
    $('rposPayCashBtn').onclick = () => { $('rposPayCash').style.display = ''; $('rposPayCard').style.display = 'none'; recalcChange(); };
    $('rposPayCardBtn').onclick = () => { $('rposPayCard').style.display = ''; $('rposPayCash').style.display = 'none'; };
    $('rposCashAmount').oninput = recalcChange;
    $('rposPayTip').oninput = recalcChange;
    $('rposCashConfirm').onclick = payCash;
    $('rposCardConfirm').onclick = payCardBegin;
    $('rposCardCancel').onclick = cancelCard;

    renderChecks(); renderCats(); renderGrid(); renderCheck();
})();
</script>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
