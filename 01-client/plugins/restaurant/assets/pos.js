/*
 * Restaurant — full-screen POS register (admin/pos.php).
 *
 * Vanilla JS, no build step. Everything goes through the page's JSON endpoint
 * (POST _ajax=1), which drives RestaurantAPI; the server answers with the
 * fresh order, so the screen always shows what was stored. Money is integer
 * cents throughout; amounts typed on the keypad are sent as decimal strings.
 */
(function () {
    'use strict';
    const B = window.POS_BOOT;
    const $ = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    /** Translated string; %s / %1$s placeholders filled in order. */
    function T(key) {
        const args = Array.prototype.slice.call(arguments, 1);
        let i = 0;
        return String(B.t[key] || key).replace(/%(\d+\$)?s/g, (m, pos) => esc(pos ? args[parseInt(pos, 10) - 1] : args[i++]));
    }
    const money = (c) => (Number(c) < 0 ? '-' : '') + B.currencySym + (Math.abs(Number(c) || 0) / 100).toFixed(2);
    const toCents = (v) => Math.round((parseFloat(String(v).replace(',', '.')) || 0) * 100);
    const svg = (d) => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>';
    const ICON = {
        plus: svg('<path d="M12 5v14M5 12h14"/>'), minus: svg('<path d="M5 12h14"/>'), x: svg('<path d="M18 6L6 18M6 6l12 12"/>'),
        user: svg('<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a7 7 0 0 1 16 0v1"/>'), note: svg('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>'),
        more: svg('<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>'),
        print: svg('<path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>'),
        fire: svg('<path d="M12 22c4 0 7-3 7-7 0-5-5-7-5-12-3 2-6 6-6 9-1-1-2-2-2-4-2 2-1 6-1 7 0 4 3 7 7 7z"/>'),
        tag: svg('<path d="M20.6 13.4L13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7" cy="7" r="1.5"/>'),
        split: svg('<path d="M16 3h5v5M8 3H3v5M21 3l-7 7M3 3l7 7M12 22v-8"/>'),
        card: svg('<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>'),
        cash: svg('<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>'),
        back: svg('<path d="M15 18l-6-6 6-6"/>')
    };
    const STATUS = { open: T('restaurant_pos_st_open'), sent: T('restaurant_pos_st_sent'), paid: T('restaurant_pos_st_paid'),
                     closed: T('restaurant_pos_st_closed'), voided: T('restaurant_pos_st_voided') };
    const KITCHEN = { sent: T('restaurant_pos_k_sent'), ready: T('restaurant_pos_k_ready'), served: T('restaurant_pos_k_served'), void: T('restaurant_pos_k_void') };
    const TYPES = { dine_in: T('restaurant_rcpt_dine_in'), takeout: T('restaurant_rcpt_takeout'), delivery: T('restaurant_rcpt_delivery') };

    const state = {
        view: 'register', orders: B.openOrders || [], recent: [], floor: B.floor || [], menu: B.menu,
        current: null, check: 0 /* 0 = all */, extraChecks: [], seat: 0, cat: 'all', q: '', rail: 'all',
        section: 'all', poll: null, busy: false
    };
    const modItems = new Set((B.modItemIds || []).map(Number));

    // ── Server ───────────────────────────────────────────────
    async function api(action, data) {
        const body = new URLSearchParams();
        body.set('_ajax', '1'); body.set('_csrf', B.csrf); body.set('action', action);
        Object.keys(data || {}).forEach((k) => {
            const v = data[k];
            if (Array.isArray(v)) v.forEach((x) => body.append(k + '[]', x));
            else if (v !== undefined && v !== null) body.set(k, v);
        });
        let r;
        try {
            r = await fetch(B.urls.endpoint, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body, credentials: 'same-origin' });
        } catch (e) { return { ok: false, error: T('restaurant_pos_offline') }; }
        try { return await r.json(); } catch (e) { return { ok: false, error: T('restaurant_pos_bad_reply') }; }
    }
    /** Run an order mutation; on success the returned order becomes current. */
    async function act(action, data, okMsg) {
        if (state.busy) return null;
        state.busy = true;
        const j = await api(action, Object.assign({ order_id: state.current ? state.current.id : 0 }, data || {}));
        state.busy = false;
        if (!j.ok) { err(j.error); return null; }
        if (j.order) setCurrent(j.order);
        if (okMsg) toast(okMsg);
        return j;
    }

    // ── Toast + modal sheet ──────────────────────────────────
    /** msg is HTML built with T() (arguments already escaped); server text goes through err(). */
    function toast(msg, kind) {
        const t = $('#posToast');
        t.innerHTML = msg; t.className = 'pos-toast' + (kind ? ' is-' + kind : ''); t.hidden = false;
        clearTimeout(t._tmo); t._tmo = setTimeout(() => { t.hidden = true; }, kind === 'error' ? 4200 : 2400);
    }
    const err = (m) => toast(esc(m || B.t.restaurant_pos_failed), 'error');
    let sheetClose = null;
    function sheet(title, html, mount, opts) {
        const m = $('#posModal'), s = $('#posSheet');
        s.className = 'pos-sheet' + (opts && opts.wide ? ' is-wide' : '');
        s.innerHTML = '<header class="pos-sheet-h"><h2>' + esc(title) + '</h2><button type="button" class="pos-icon-btn" data-close aria-label="' + T('restaurant_pos_close') + '">' + ICON.x + '</button></header>'
            + '<div class="pos-sheet-b">' + html + '</div>';
        m.hidden = false;
        sheetClose = opts && opts.onClose;
        $('[data-close]', s).onclick = closeSheet;
        if (mount) mount(s);
        const f = $('[autofocus]', s); if (f) setTimeout(() => f.focus(), 30);
    }
    function closeSheet() {
        $('#posModal').hidden = true; $('#posSheet').innerHTML = '';
        if (sheetClose) { const c = sheetClose; sheetClose = null; c(); }
    }
    $('#posModal').addEventListener('click', (e) => { if (e.target.id === 'posModal') closeSheet(); });
    const sheetOpen = () => !$('#posModal').hidden;

    /** A numeric keypad bound to a display; returns {value()} in the typed decimal form. */
    function keypad(root, initial, onChange) {
        let v = initial != null ? String(initial) : '';
        const disp = $('.pos-kp-display', root);
        const render = () => { disp.textContent = v === '' ? '0' : v; if (onChange) onChange(v); };
        $$('.pos-kp button', root).forEach((b) => b.onclick = () => press(b.dataset.k));
        function press(k) {
            if (k === 'C') v = '';
            else if (k === '<') v = v.slice(0, -1);
            else if (k === '.') { if (!v.includes('.')) v = (v || '0') + '.'; }
            else if (/^\d$/.test(k)) { if (!/\.\d\d$/.test(v)) v = (v === '0' ? '' : v) + k; }
            render();
        }
        root._kpPress = press;
        render();
        return { value: () => v, set: (x) => { v = String(x); render(); } };
    }
    const keypadHtml = (label) => '<div class="pos-kp-wrap"><div class="pos-kp-label">' + esc(label) + '</div><div class="pos-kp-display">0</div><div class="pos-kp">'
        + ['1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '0', '<'].map((k) => '<button type="button" data-k="' + k + '">' + (k === '<' ? '⌫' : k) + '</button>').join('')
        + '</div></div>';
    document.addEventListener('keydown', (e) => {
        const s = $('#posSheet');
        if (!sheetOpen() || !s._kpPress || e.target.matches('input, textarea, select')) return;
        if (/^[0-9.]$/.test(e.key)) { s._kpPress(e.key); e.preventDefault(); }
        else if (e.key === 'Backspace') { s._kpPress('<'); e.preventDefault(); }
    });

    // ── Helpers on the current order ─────────────────────────
    const isOpen = (o) => o && (o.status === 'open' || o.status === 'sent');
    const where = (o) => o.table_label ? T('restaurant_rcpt_table') + ' ' + o.table_label : (TYPES[o.type] || o.type);
    const checksOf = (o) => {
        const nums = new Set((o.checks || []).map((c) => Number(c.check_no)));
        state.extraChecks.forEach((n) => nums.add(n));
        return Array.from(nums).sort((a, b) => a - b);
    };
    const checkRow = (o, no) => (o.checks || []).find((c) => Number(c.check_no) === no)
        || { check_no: no, subtotal: 0, discount: 0, service: 0, tax: 0, tip: 0, total: 0, paid: 0, balance: 0, lines: 0 };
    const orderBalance = (o) => Math.max(0, Number(o.total_cents) - Number(o.paid_cents) - Number(o.comp_cents || 0));
    const elapsed = (ts) => {
        if (!ts) return '';
        const mins = Math.max(0, Math.round((Date.now() - new Date(String(ts).replace(' ', 'T')).getTime()) / 60000));
        return mins < 60 ? mins + 'm' : Math.floor(mins / 60) + 'h' + String(mins % 60).padStart(2, '0');
    };

    function setCurrent(order) {
        const changed = !state.current || state.current.id !== order.id;
        state.current = order;
        if (changed) { state.check = 0; state.extraChecks = []; state.seat = 0; }
        const nums = checksOf(order);
        if (state.check && !nums.includes(state.check)) state.check = 0;
        const idx = state.orders.findIndex((o) => o.id == order.id);
        if (isOpen(order)) { if (idx >= 0) state.orders[idx] = order; else state.orders.push(order); }
        else if (idx >= 0) state.orders.splice(idx, 1);
        renderRail(); renderCheck();
        if (state.view === 'tables') refresh();
    }
    async function loadOrder(id) {
        const j = await api('order', { order_id: id });
        if (!j.ok) return err(j.error);
        setCurrent(j.order); mobile('check');
    }

    // ── Rail ─────────────────────────────────────────────────
    function renderRail() {
        const list = state.orders.filter((o) => state.rail === 'all' || (state.rail === 'dine_in' ? o.type === 'dine_in' : o.type !== 'dine_in'));
        $('#posChecks').innerHTML = list.length ? list.map((o) => {
            const active = state.current && state.current.id == o.id ? ' is-active' : '';
            return '<button type="button" class="pos-rail-item' + active + '" data-oid="' + o.id + '">'
                + '<span class="pos-rail-top"><b>#' + esc(o.order_no) + '</b><span>' + money(o.total_cents) + '</span></span>'
                + '<span class="pos-rail-sub"><span>' + esc(where(o)) + (o.source === 'online' ? ' · ' + T('restaurant_pos_online') : '') + '</span>'
                + '<span class="pos-pill is-' + esc(o.status) + '">' + esc(STATUS[o.status] || o.status) + ' · ' + elapsed(o.opened_at) + '</span></span></button>';
        }).join('') : '<div class="pos-empty">' + T('restaurant_pos_no_open') + '</div>';
        $$('#posChecks [data-oid]').forEach((b) => b.onclick = () => loadOrder(Number(b.dataset.oid)));
        $('#posRecent').innerHTML = state.recent.length ? state.recent.map((o) =>
            '<button type="button" class="pos-rail-item is-closed" data-oid="' + o.id + '"><span class="pos-rail-top"><b>#' + esc(o.order_no) + '</b><span>' + money(o.total_cents) + '</span></span>'
            + '<span class="pos-rail-sub"><span>' + esc(where(o)) + '</span><span class="pos-pill is-' + esc(o.status) + '">' + esc(STATUS[o.status] || o.status) + '</span></span></button>').join('')
            : '<div class="pos-empty">' + T('restaurant_pos_none') + '</div>';
        $$('#posRecent [data-oid]').forEach((b) => b.onclick = () => loadOrder(Number(b.dataset.oid)));
    }
    $$('#posRailFilter button').forEach((b) => b.onclick = () => {
        state.rail = b.dataset.f; $$('#posRailFilter button').forEach((x) => x.classList.toggle('is-active', x === b)); renderRail();
    });

    // ── Menu ─────────────────────────────────────────────────
    const photo = (p) => !p ? '' : (/^https?:\/\//.test(p) ? p : (p.charAt(0) === '/' ? B.urls.media + p : B.urls.media + '/' + p));
    function renderCats() {
        const cats = [{ id: 'all', name: T('restaurant_pos_all') }].concat(state.menu.categories.map((c) => ({ id: String(c.id), name: c.name })));
        $('#posCats').innerHTML = cats.map((c) => '<button type="button" class="pos-chip' + (state.cat == c.id ? ' is-active' : '') + '" data-cat="' + esc(c.id) + '">' + esc(c.name) + '</button>').join('');
        $$('#posCats [data-cat]').forEach((b) => b.onclick = () => { state.cat = b.dataset.cat; renderCats(); renderGrid(); });
    }
    function renderGrid() {
        const q = state.q.trim().toLowerCase();
        const items = state.menu.items.filter((i) => (q ? (i.name + ' ' + (i.description || '')).toLowerCase().includes(q) : (state.cat === 'all' || String(i.category_id) === state.cat)));
        $('#posGrid').innerHTML = items.length ? items.map((i) => {
            const off = Number(i.is_86) === 1, img = photo(i.photo_path);
            return '<button type="button" class="pos-item' + (off ? ' is-86' : '') + (img ? ' has-photo' : '') + '" data-item="' + i.id + '">'
                + (img ? '<span class="pos-item-img" style="background-image:url(\'' + esc(img).replace(/'/g, '%27') + '\')"></span>' : '<span class="pos-item-mono">' + esc(i.name.slice(0, 2)) + '</span>')
                + '<span class="pos-item-name">' + esc(i.name) + '</span>'
                + '<span class="pos-item-price">' + (off ? '<span class="pos-pill is-voided">86</span>' : money(i.price_cents)) + (modItems.has(Number(i.id)) ? '<span class="pos-item-mods">+</span>' : '') + '</span></button>';
        }).join('') : '<div class="pos-empty">' + (q ? T('restaurant_pos_no_match', state.q) : T('restaurant_pos_no_items')) + '</div>';
        $$('#posGrid [data-item]').forEach((b) => {
            const id = Number(b.dataset.item);
            let timer = null, long = false;
            b.addEventListener('pointerdown', () => { long = false; timer = setTimeout(() => { long = true; itemSheet(id); }, 550); });
            ['pointerup', 'pointerleave', 'pointercancel'].forEach((ev) => b.addEventListener(ev, () => clearTimeout(timer)));
            b.addEventListener('contextmenu', (e) => { e.preventDefault(); clearTimeout(timer); itemSheet(id); });
            b.addEventListener('click', () => { if (!long) addItem(id); });
        });
    }
    ['#posSearch', '#posSearch2'].forEach((sel) => {
    $(sel).addEventListener('input', (e) => { state.q = e.target.value; renderGrid(); });
    $(sel).addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { const first = $('#posGrid [data-item]:not(.is-86)'); if (first) first.click(); }
        if (e.key === 'Escape') { e.target.value = ''; state.q = ''; renderGrid(); e.target.blur(); }
    });
    });

    function itemSheet(id) {
        const it = state.menu.items.find((i) => i.id == id); if (!it) return;
        const off = Number(it.is_86) === 1;
        sheet(it.name, '<p class="pos-muted">' + (off ? T('restaurant_pos_86_on') : T('restaurant_pos_86_off')) + '</p>'
            + '<div class="pos-row"><button type="button" class="pos-btn ' + (off ? 'pos-btn-primary' : 'pos-btn-danger') + '" id="pos86">' + (off ? T('restaurant_pos_un86') : T('restaurant_pos_do86')) + '</button></div>',
            (s) => { $('#pos86', s).onclick = async () => {
                const j = await api('item_86', { item_id: id, on: off ? '' : '1' });
                if (!j.ok) return err(j.error);
                state.menu = j.menu; renderGrid(); closeSheet(); toast(off ? T('restaurant_pos_un86_done', it.name) : T('restaurant_pos_86_done', it.name));
            }; });
    }

    async function addItem(itemId, opts) {
        const it = state.menu.items.find((i) => i.id == itemId);
        if (it && Number(it.is_86) === 1) return itemSheet(itemId);
        if (!state.current) return newCheckSheet({ thenAdd: itemId });
        if (!isOpen(state.current)) return toast(T('restaurant_err_not_open'), 'error');
        if (modItems.has(Number(itemId)) && !opts) return modSheet(itemId);
        const j = await act('add_line', Object.assign({ item_id: itemId, qty: 1, seat: state.seat || 0, check_no: state.check || 1 }, opts || {}));
        if (j && it) flash(it.name);
    }
    function flash(name) { const t = $('#posMobileTotal'); toast(T('restaurant_pos_added', name)); if (t) t.classList.add('is-bump'); setTimeout(() => t && t.classList.remove('is-bump'), 400); }

    async function modSheet(itemId) {
        const j = await api('item_mods', { item_id: itemId });
        if (!j.ok) return err(j.error);
        const it = state.menu.items.find((i) => i.id == itemId) || { name: '' };
        let qty = 1;
        const base = Number(it.price_cents) || 0;
        const rule = (min, max) => !(Number(min) || Number(max)) ? '' : ' <small>' + (Number(min) === Number(max) ? T('restaurant_pos_choose_n', max) : T('restaurant_pos_choose', min || 0, Number(max) || '∞')) + '</small>';
        // Combo slots first ("Sides", "Drink"), then the item's modifier groups; both follow the same min/max rules.
        const slots = (j.slots || []).map((sl) => {
            const single = Number(sl.max_pick) === 1;
            return '<fieldset class="pos-mods" data-min="' + Number(sl.min_pick) + '" data-max="' + Number(sl.max_pick) + '" data-name="' + esc(sl.label) + '"><legend>' + esc(sl.label)
                + (Number(sl.min_pick) > 0 ? ' <em>*</em>' : '') + rule(sl.min_pick, sl.max_pick) + '</legend>'
                + (sl.choices || []).map((c) => {
                    const off = Number(c.is_86) === 1;
                    return '<label class="pos-mod' + (off ? ' is-86' : '') + '"><input type="' + (single ? 'radio' : 'checkbox') + '" name="cs' + sl.id + '" value="' + c.id + '" data-kind="combo" data-delta="' + Number(c.price_delta_cents) + '"' + (off ? ' disabled' : '') + '><span>' + esc(c.name) + (off ? ' · 86' : '') + '</span>'
                        + '<span class="pos-muted">' + (Number(c.price_delta_cents) ? '+' + money(c.price_delta_cents) : '') + '</span></label>';
                }).join('') + '</fieldset>';
        }).join('');
        const groups = (j.groups || []).map((g, gi) => {
            const single = Number(g.max_select) === 1;
            const min = Number(g.is_required) ? Math.max(1, Number(g.min_select) || 0) : 0;
            return '<fieldset class="pos-mods" data-min="' + min + '" data-max="' + Number(g.max_select) + '" data-name="' + esc(g.name) + '"><legend>' + esc(g.name) + (Number(g.is_required) ? ' <em>*</em>' : '') + rule(g.min_select, g.max_select) + '</legend>'
                + (g.modifiers || []).map((m) => '<label class="pos-mod"><input type="' + (single ? 'radio' : 'checkbox') + '" name="mg' + gi + '" value="' + m.id + '" data-kind="mod" data-delta="' + Number(m.price_delta_cents) + '"><span>' + esc(m.name) + '</span>'
                    + '<span class="pos-muted">' + (Number(m.price_delta_cents) ? money(m.price_delta_cents) : '') + '</span></label>').join('') + '</fieldset>';
        }).join('');
        sheet(it.name, slots + groups + '<label class="pos-field"><span>' + T('restaurant_pos_note') + '</span><input type="text" id="posModNote" maxlength="255" placeholder="' + T('restaurant_pos_note_ph') + '"></label>'
            + '<div class="pos-row pos-row-end"><div class="pos-stepper"><button type="button" data-q="-1">' + ICON.minus + '</button><span id="posModQty">1</span><button type="button" data-q="1">' + ICON.plus + '</button></div>'
            + '<button type="button" class="pos-btn pos-btn-primary" id="posModAdd">' + T('restaurant_pos_add') + ' · <span id="posModTotal">' + money(base) + '</span></button></div>', (s) => {
            const total = () => { const unit = base + $$('input:checked', s).reduce((a, i) => a + (Number(i.dataset.delta) || 0), 0); $('#posModTotal', s).textContent = money(unit * qty); };
            $$('[data-q]', s).forEach((b) => b.onclick = () => { qty = Math.max(1, qty + Number(b.dataset.q)); $('#posModQty', s).textContent = qty; total(); });
            s.addEventListener('change', total);
            $('#posModAdd', s).onclick = async () => {
                // Check every slot and group here too, so the server's refusal is the rare case.
                for (const fs of $$('fieldset.pos-mods', s)) {
                    const n = $$('input:checked', fs).length, min = Number(fs.dataset.min) || 0, max = Number(fs.dataset.max) || 0;
                    if (n < min) return toast(T('restaurant_pos_pick_min', min, fs.dataset.name), 'error');
                    if (max > 0 && n > max) return toast(T('restaurant_pos_pick_max', max, fs.dataset.name), 'error');
                }
                const mods = $$('input[data-kind="mod"]:checked', s).map((i) => i.value);
                const combo = $$('input[data-kind="combo"]:checked', s).map((i) => i.value);
                const note = $('#posModNote', s) ? $('#posModNote', s).value : '';
                closeSheet();
                const r = await act('add_line', { item_id: itemId, qty, modifiers: mods, combo, notes: note, seat: state.seat || 0, check_no: state.check || 1 });
                if (r) flash(it.name);
            };
        });
    }

    // ── Check panel ──────────────────────────────────────────
    function renderCheck() {
        const o = state.current, box = $('#posCheck');
        $('#posMobileTotal').textContent = o ? money(o.total_cents) : '';
        if (!o) {
            box.innerHTML = '<div class="pos-check-empty">' + ICON.note + '<p>' + T('restaurant_pos_pick_check') + '</p><button type="button" class="pos-btn pos-btn-primary" id="posNew2">' + T('restaurant_pos_new_check') + '</button></div>';
            $('#posNew2').onclick = () => newCheckSheet();
            return;
        }
        const open = isOpen(o), nums = checksOf(o), multi = nums.length > 1;
        const items = (o.items || []).filter((it) => !state.check || Number(it.check_no) === state.check);
        const guests = Math.max(1, Number(o.guest_count) || 1);
        const cr = state.check ? checkRow(o, state.check) : null;
        const sum = cr ? { sub: cr.subtotal, disc: cr.discount, svc: cr.service, tax: cr.tax, tip: cr.tip, total: cr.total, paid: cr.paid, bal: cr.balance }
                       : { sub: o.subtotal_cents, disc: o.discount_cents, svc: o.service_charge_cents, tax: o.tax_cents, tip: o.tip_cents, total: o.total_cents, paid: o.paid_cents, bal: orderBalance(o) };

        const lines = items.map((it) => {
            const v = it.kitchen_status === 'void';
            const mods = (it.modifiers || []).map((m) => esc(m.name_snapshot) + (Number(m.price_delta_cents) ? ' ' + money(m.price_delta_cents) : '')).join(', ');
            const disc = Number(it.discount_cents) > 0 ? '<div class="pos-line-disc">' + ICON.tag + ' ' + (Number(it.discount_pct) >= 100 ? T('restaurant_pos_comped') : T('restaurant_pos_discount'))
                + (it.discount_reason ? ' · ' + esc(it.discount_reason) : '') + ' <b>-' + money(it.discount_cents) + '</b></div>' : '';
            return '<button type="button" class="pos-line' + (v ? ' is-void' : '') + '" data-line="' + it.id + '"' + (open && !v ? '' : ' disabled') + '>'
                + '<span class="pos-line-qty">' + it.qty + '</span>'
                + '<span class="pos-line-main"><span class="pos-line-name">' + esc(it.name_snapshot)
                + (it.seat ? ' <span class="pos-seat">S' + it.seat + '</span>' : '')
                + (multi && !state.check ? ' <span class="pos-seat is-check">#' + it.check_no + '</span>' : '')
                + (it.kitchen_status !== 'new' ? ' <span class="pos-pill is-k-' + esc(it.kitchen_status) + '">' + esc(KITCHEN[it.kitchen_status] || it.kitchen_status) + '</span>' : '')
                + '</span>' + (mods ? '<span class="pos-line-mods">' + mods + '</span>' : '')
                + (it.notes ? '<span class="pos-line-mods">' + ICON.note + ' ' + esc(it.notes) + '</span>' : '') + disc + '</span>'
                + '<span class="pos-line-amt">' + money(it.line_total_cents) + '</span></button>';
        }).join('');

        const tabs = multi || state.extraChecks.length ? '<div class="pos-checktabs">'
            + '<button type="button" class="' + (!state.check ? 'is-active' : '') + '" data-ck="0">' + T('restaurant_pos_all') + '</button>'
            + nums.map((n) => { const c = checkRow(o, n); return '<button type="button" class="' + (state.check === n ? 'is-active' : '') + '" data-ck="' + n + '">'
                + T('restaurant_pos_check_n', n) + '<small>' + (c.total && !c.balance ? '✓' : money(c.balance)) + '</small></button>'; }).join('')
            + (open ? '<button type="button" data-ck="new" title="' + T('restaurant_pos_add_check') + '">' + ICON.plus + '</button>' : '') + '</div>' : '';

        const seats = open && o.type === 'dine_in' ? '<div class="pos-seats"><span>' + T('restaurant_pos_seat') + '</span>'
            + '<button type="button" class="' + (!state.seat ? 'is-active' : '') + '" data-seat="0">—</button>'
            + Array.from({ length: guests }, (_, i) => '<button type="button" class="' + (state.seat === i + 1 ? 'is-active' : '') + '" data-seat="' + (i + 1) + '">' + (i + 1) + '</button>').join('') + '</div>' : '';

        const hasNew = (o.items || []).some((it) => it.kitchen_status === 'new');
        const statusLine = !open ? '<div class="pos-check-closed">' + esc(STATUS[o.status] || o.status)
            + (Number(o.comp_cents) ? ' · ' + T('restaurant_pos_comped') + ' ' + money(o.comp_cents) + (o.comp_reason ? ' (' + esc(o.comp_reason) + ')' : '') : '') + '</div>' : '';

        box.innerHTML = '<header class="pos-check-h">'
            + '<div><h2>#' + esc(o.order_no) + '</h2><p>' + esc(where(o)) + ' · <button type="button" class="pos-link" data-act="guests">' + T('restaurant_pos_guests_n', guests) + '</button>'
            + (o.source === 'online' ? ' · ' + T('restaurant_pos_online') : '') + '</p></div>'
            + '<span class="pos-pill is-' + esc(o.status) + '">' + esc(STATUS[o.status] || o.status) + '</span></header>'
            + '<div class="pos-check-meta"><button type="button" class="pos-chip' + (o.customer_name ? ' is-set' : '') + '" data-act="customer">' + ICON.user + ' ' + (o.customer_name ? esc(o.customer_name) : T('restaurant_pos_add_guest')) + '</button>'
            + '<button type="button" class="pos-chip' + (o.notes ? ' is-set' : '') + '" data-act="notes">' + ICON.note + ' ' + (o.notes ? esc(String(o.notes).slice(0, 28)) : T('restaurant_pos_add_note')) + '</button></div>'
            + tabs + seats
            + '<div class="pos-lines">' + (lines || '<div class="pos-empty">' + (state.check ? T('restaurant_pos_check_empty') : T('restaurant_pos_tap_menu')) + '</div>') + '</div>'
            + '<div class="pos-totals">'
            + row(T('restaurant_rcpt_subtotal'), money(sum.sub))
            + (Number(sum.disc) ? row(T('restaurant_rcpt_discount') + (o.discount_reason ? ' · ' + esc(o.discount_reason) : '') + (Number(o.discount_pct) ? ' (' + Number(o.discount_pct) + '%)' : ''), '-' + money(sum.disc), 'is-disc') : '')
            + (Number(sum.svc) ? row(T('restaurant_rcpt_service'), money(sum.svc)) : '')
            + row(T('restaurant_rcpt_tax'), money(sum.tax))
            + (Number(sum.tip) ? row(T('restaurant_rcpt_tip'), money(sum.tip)) : '')
            + row(T('restaurant_rcpt_total'), money(sum.total), 'is-grand')
            + (Number(sum.paid) ? row(T('restaurant_rcpt_paid'), money(sum.paid)) : '')
            + (Number(sum.paid) && sum.bal > 0 ? row(T('restaurant_rcpt_balance'), money(sum.bal), 'is-bal') : '')
            + '</div>' + statusLine
            + '<div class="pos-actions">' + (open
                ? '<button type="button" class="pos-btn" data-act="send"' + (hasNew ? '' : ' disabled') + '>' + ICON.fire + T('restaurant_pos_send') + '<kbd>S</kbd></button>'
                  + '<button type="button" class="pos-btn" data-act="discount">' + ICON.tag + T('restaurant_pos_discount') + '</button>'
                  + '<button type="button" class="pos-btn" data-act="split">' + ICON.split + T('restaurant_pos_split') + '</button>'
                  + '<button type="button" class="pos-btn" data-act="more">' + ICON.more + T('restaurant_pos_more') + '</button>'
                  + '<button type="button" class="pos-btn pos-btn-primary pos-pay" data-act="pay"' + (Number(o.total_cents) > 0 ? '' : ' disabled') + '>'
                  + T('restaurant_pos_pay') + ' <b>' + money(sum.bal) + '</b><kbd>P</kbd></button>'
                : '<a class="pos-btn" href="' + B.urls.receipt + '?id=' + o.id + '&print=1" target="_blank" rel="noopener">' + ICON.print + T('restaurant_rcpt_print') + '</a>'
                  + '<a class="pos-btn" href="' + B.urls.order + '?id=' + o.id + '">' + T('restaurant_pos_details') + '</a>'
                  + (B.perm.manager && (o.status === 'paid' || o.status === 'closed') ? '<button type="button" class="pos-btn" data-act="reopen">' + T('restaurant_pos_reopen') + '</button>' : '')
                  + '<button type="button" class="pos-btn pos-btn-primary" data-act="newcheck">' + T('restaurant_pos_new_check') + '</button>')
            + '</div>';

        $$('[data-line]', box).forEach((b) => b.onclick = () => lineSheet(Number(b.dataset.line)));
        $$('[data-ck]', box).forEach((b) => b.onclick = () => {
            if (b.dataset.ck === 'new') { const n = Math.max(0, ...nums) + 1; state.extraChecks.push(n); state.check = n; }
            else state.check = Number(b.dataset.ck);
            renderCheck();
        });
        $$('[data-seat]', box).forEach((b) => b.onclick = () => { state.seat = Number(b.dataset.seat); renderCheck(); });
        $$('[data-act]', box).forEach((b) => b.onclick = () => ACTIONS[b.dataset.act] && ACTIONS[b.dataset.act]());
    }
    const row = (k, v, cls) => '<div class="pos-total' + (cls ? ' ' + cls : '') + '"><span>' + k + '</span><span>' + v + '</span></div>';

    // ── Check actions ────────────────────────────────────────
    const ACTIONS = {
        send: () => act('send', {}, T('restaurant_pos_sent')),
        discount: discountSheet,
        split: splitSheet,
        more: moreSheet,
        pay: paySheet,
        customer: guestSheet,
        notes: notesSheet,
        guests: guestCountSheet,
        reopen: () => reasonSheet(T('restaurant_pos_reopen'), T('restaurant_pos_reopen_help'), false, (r) => act('reopen', { reason: r }, T('restaurant_pos_reopened'))),
        newcheck: () => newCheckSheet()
    };

    function lineSheet(lineId) {
        const o = state.current, it = (o.items || []).find((x) => x.id == lineId); if (!it) return;
        const nums = checksOf(o), guests = Math.max(1, Number(o.guest_count) || 1), qty = Number(it.qty);
        const otherChecks = nums.filter((n) => n !== Number(it.check_no)).concat([Math.max(0, ...nums) + 1]);
        sheet(it.name_snapshot, '<div class="pos-line-sheet">'
            + '<section><h3>' + T('restaurant_pos_qty') + '</h3><div class="pos-stepper is-big"><button type="button" data-lq="-1">' + ICON.minus + '</button><span>' + qty + '</span><button type="button" data-lq="1">' + ICON.plus + '</button></div></section>'
            + (o.type === 'dine_in' ? '<section><h3>' + T('restaurant_pos_seat') + '</h3><div class="pos-seats">'
                + '<button type="button" data-ls="0" class="' + (!it.seat ? 'is-active' : '') + '">—</button>'
                + Array.from({ length: Math.max(guests, Number(it.seat) || 0) }, (_, i) => '<button type="button" data-ls="' + (i + 1) + '" class="' + (Number(it.seat) === i + 1 ? 'is-active' : '') + '">' + (i + 1) + '</button>').join('') + '</div></section>' : '')
            + '<section><h3>' + T('restaurant_pos_move_to') + '</h3><div class="pos-seats">' + otherChecks.map((n) => '<button type="button" data-lm="' + n + '">' + T('restaurant_pos_check_n', n) + (nums.includes(n) ? '' : ' ' + T('restaurant_pos_new_tag')) + '</button>').join('') + '</div>'
            + (qty > 1 ? '<p class="pos-muted">' + T('restaurant_pos_split_line_help') + '</p><div class="pos-row"><div class="pos-stepper"><button type="button" data-sq="-1">' + ICON.minus + '</button><span id="posSplitQty">1</span><button type="button" data-sq="1">' + ICON.plus + '</button></div>'
                + otherChecks.map((n) => '<button type="button" class="pos-btn" data-lsplit="' + n + '">' + T('restaurant_pos_split_to', n) + '</button>').join('') + '</div>' : '') + '</section>'
            + '<section><h3>' + T('restaurant_pos_discount') + '</h3><div class="pos-row">'
            + '<button type="button" class="pos-btn" data-ld="1">' + ICON.tag + T('restaurant_pos_discount_line') + '</button>'
            + (Number(it.discount_cents) ? '<button type="button" class="pos-btn" data-ldx="1">' + T('restaurant_pos_remove_discount') + '</button>' : '')
            + (B.perm.manager ? '<button type="button" class="pos-btn" data-lc="1">' + T('restaurant_pos_comp_item') + '</button>' : '') + '</div></section>'
            + '<section class="pos-row">'
            + (it.kitchen_status === 'ready' ? '<button type="button" class="pos-btn pos-btn-primary" data-lserved="1">' + T('restaurant_pos_mark_served') + '</button>' : '')
            + '<button type="button" class="pos-btn pos-btn-danger" data-lrm="1">' + (it.kitchen_status === 'new' ? T('restaurant_pos_remove') : T('restaurant_pos_void_item')) + '</button></section>'
            + '</div>', (s) => {
            let sq = 1;
            const done = (j) => { if (j) closeSheet(); };
            $$('[data-lq]', s).forEach((b) => b.onclick = async () => {
                const q = qty + Number(b.dataset.lq);
                done(q < 1 ? await act('remove_line', { line_id: lineId }) : await act('line_qty', { line_id: lineId, qty: q }));
            });
            $$('[data-ls]', s).forEach((b) => b.onclick = async () => done(await act('line_seat', { line_id: lineId, seat: b.dataset.ls })));
            $$('[data-lm]', s).forEach((b) => b.onclick = async () => { const n = Number(b.dataset.lm); const j = await act('line_move', { line_id: lineId, check_no: n }); if (j) { state.check = n; renderCheck(); } done(j); });
            $$('[data-sq]', s).forEach((b) => b.onclick = () => { sq = Math.min(qty - 1, Math.max(1, sq + Number(b.dataset.sq))); $('#posSplitQty', s).textContent = sq; });
            $$('[data-lsplit]', s).forEach((b) => b.onclick = async () => done(await act('line_split', { line_id: lineId, qty: sq, check_no: b.dataset.lsplit }, T('restaurant_pos_split_done'))));
            const ld = $('[data-ld]', s); if (ld) ld.onclick = () => discountSheet(lineId);
            const ldx = $('[data-ldx]', s); if (ldx) ldx.onclick = async () => done(await act('line_discount', { line_id: lineId, type: 'none', value: 0 }));
            const lc = $('[data-lc]', s); if (lc) lc.onclick = () => reasonSheet(T('restaurant_pos_comp_item'), T('restaurant_pos_comp_item_help'), true, (r) => act('line_comp', { line_id: lineId, reason: r }, T('restaurant_pos_comped')));
            const lsv = $('[data-lserved]', s); if (lsv) lsv.onclick = async () => done(await act('line_served', { line_id: lineId }));
            $('[data-lrm]', s).onclick = async () => {
                if (it.kitchen_status !== 'new' && !confirm(T('restaurant_pos_void_item_confirm'))) return;
                done(await act('remove_line', { line_id: lineId }));
            };
        });
    }

    const REASONS = ['restaurant_pos_r_promo', 'restaurant_pos_r_staff', 'restaurant_pos_r_manager', 'restaurant_pos_r_complaint', 'restaurant_pos_r_regular'];
    function discountSheet(lineId) {
        const o = state.current, line = lineId ? (o.items || []).find((x) => x.id == lineId) : null;
        let type = 'pct';
        sheet(line ? T('restaurant_pos_discount_on', line.name_snapshot) : T('restaurant_pos_discount_check'),
            '<div class="pos-seg is-wide" id="posDiscType"><button type="button" class="is-active" data-t="pct">%</button><button type="button" data-t="amount">' + esc(B.currencySym) + '</button></div>'
            + '<div class="pos-quick" id="posDiscQuick">' + [10, 15, 20, 25, 50, 100].map((p) => '<button type="button" data-v="' + p + '">' + p + '%</button>').join('') + '</div>'
            + keypadHtml(T('restaurant_pos_value'))
            + '<div class="pos-quick">' + REASONS.map((k) => '<button type="button" data-r="' + esc(T(k)) + '">' + T(k) + '</button>').join('') + '</div>'
            + '<label class="pos-field"><span>' + T('restaurant_refund_reason') + '</span><input type="text" id="posDiscReason" maxlength="160"></label>'
            + '<div class="pos-row pos-row-end">' + (!line && (Number(o.discount_cents) || Number(o.discount_pct)) ? '<button type="button" class="pos-btn" id="posDiscClear">' + T('restaurant_pos_remove_discount') + '</button>' : '')
            + '<button type="button" class="pos-btn pos-btn-primary" id="posDiscApply">' + T('restaurant_pos_apply') + '</button></div>', (s) => {
            const kp = keypad(s, '');
            $$('#posDiscType button', s).forEach((b) => b.onclick = () => {
                type = b.dataset.t; $$('#posDiscType button', s).forEach((x) => x.classList.toggle('is-active', x === b));
                $('#posDiscQuick', s).hidden = type !== 'pct'; kp.set('');
            });
            $$('#posDiscQuick [data-v]', s).forEach((b) => b.onclick = () => kp.set(b.dataset.v));
            $$('[data-r]', s).forEach((b) => b.onclick = () => { $('#posDiscReason', s).value = b.dataset.r; });
            const clr = $('#posDiscClear', s); if (clr) clr.onclick = async () => { if (await act('discount', { type: 'amount', value: 0 })) closeSheet(); };
            $('#posDiscApply', s).onclick = async () => {
                const v = kp.value(), reason = $('#posDiscReason', s).value.trim();
                if (type === 'pct' && Number(v) > 100) return toast(T('restaurant_pos_pct_max'), 'error');
                const j = line ? await act('line_discount', { line_id: line.id, type, value: v, reason })
                               : await act('discount', { type, value: v, reason });
                if (j) { closeSheet(); toast(T('restaurant_pos_discount_done')); }
            };
        });
    }

    function splitSheet() {
        const o = state.current, nums = checksOf(o), next = Math.max(0, ...nums) + 1;
        sheet(T('restaurant_pos_split'), '<p class="pos-muted">' + T('restaurant_pos_split_help') + '</p>'
            + '<div class="pos-split-grid">' + (o.items || []).filter((it) => it.kitchen_status !== 'void').map((it) =>
                '<div class="pos-split-line"><span><b>' + it.qty + '×</b> ' + esc(it.name_snapshot) + (it.seat ? ' <span class="pos-seat">S' + it.seat + '</span>' : '') + '</span><span class="pos-seats">'
                + nums.concat(nums.includes(next) ? [] : [next]).map((n) => '<button type="button" data-mv="' + it.id + ':' + n + '" class="' + (Number(it.check_no) === n ? 'is-active' : '') + '">' + n + '</button>').join('')
                + '</span></div>').join('') + '</div>'
            + (o.type === 'dine_in' && Number(o.guest_count) > 1 ? '<div class="pos-row"><button type="button" class="pos-btn" id="posBySeat">' + T('restaurant_pos_split_seat') + '</button></div>' : '')
            + '<div class="pos-row pos-row-end"><button type="button" class="pos-btn pos-btn-primary" data-close>' + T('restaurant_pos_done') + '</button></div>', (s) => {
            $$('[data-mv]', s).forEach((b) => b.onclick = async () => {
                const [line, n] = b.dataset.mv.split(':').map(Number);
                if (await act('line_move', { line_id: line, check_no: n })) splitSheet();
            });
            const bs = $('#posBySeat', s); if (bs) bs.onclick = async () => {
                for (const it of (state.current.items || [])) {
                    if (it.kitchen_status === 'void' || !it.seat || Number(it.check_no) === Number(it.seat)) continue;
                    const j = await act('line_move', { line_id: it.id, check_no: it.seat });
                    if (!j) return;
                }
                toast(T('restaurant_pos_split_done')); splitSheet();
            };
            $$('[data-close]', s).forEach((b) => b.onclick = closeSheet);
        });
    }

    function moreSheet() {
        const o = state.current;
        const others = state.orders.filter((x) => x.id != o.id && (!o.table_id || !x.table_id || x.table_id == o.table_id));
        sheet(T('restaurant_pos_more'), '<div class="pos-menu-list">'
            + '<a class="pos-btn" href="' + B.urls.receipt + '?id=' + o.id + '&print=1" target="_blank" rel="noopener">' + ICON.print + T('restaurant_pos_print_check') + '</a>'
            + (others.length ? '<button type="button" class="pos-btn" id="posMerge">' + T('restaurant_pos_merge') + '</button>' : '')
            + '<button type="button" class="pos-btn" id="posClose">' + T('restaurant_pos_close_check') + '</button>'
            + (B.perm.manager ? '<button type="button" class="pos-btn" id="posComp">' + T('restaurant_pos_comp_check') + '</button>'
                + '<button type="button" class="pos-btn pos-btn-danger" id="posVoid">' + T('restaurant_pos_void_check') + '</button>' : '')
            + '<a class="pos-btn" href="' + B.urls.order + '?id=' + o.id + '">' + T('restaurant_pos_details') + '</a></div>', (s) => {
            const mg = $('#posMerge', s); if (mg) mg.onclick = () => sheet(T('restaurant_pos_merge'), '<p class="pos-muted">' + T('restaurant_pos_merge_help', o.order_no) + '</p><div class="pos-menu-list">'
                + others.map((x) => '<button type="button" class="pos-btn" data-src="' + x.id + '">#' + esc(x.order_no) + ' · ' + esc(where(x)) + ' · ' + money(x.total_cents) + '</button>').join('') + '</div>', (s2) => {
                $$('[data-src]', s2).forEach((b) => b.onclick = async () => { if (await act('merge', { source_id: b.dataset.src }, T('restaurant_pos_merged'))) { closeSheet(); refresh(); } });
            });
            $('#posClose', s).onclick = async () => { if (await act('close', {}, T('restaurant_pos_closed'))) closeSheet(); };
            const cp = $('#posComp', s); if (cp) cp.onclick = () => reasonSheet(T('restaurant_pos_comp_check'), T('restaurant_pos_comp_check_help', money(orderBalance(o))), true,
                (r) => act('comp', { reason: r }, T('restaurant_pos_comped')));
            const vd = $('#posVoid', s); if (vd) vd.onclick = () => reasonSheet(T('restaurant_pos_void_check'), T('restaurant_pos_void_help'), false,
                (r) => act('void', { reason: r }, T('restaurant_pos_voided')));
        });
    }

    function reasonSheet(title, help, required, run) {
        sheet(title, '<p class="pos-muted">' + help + '</p><div class="pos-quick">' + REASONS.map((k) => '<button type="button" data-r="' + esc(T(k)) + '">' + T(k) + '</button>').join('') + '</div>'
            + '<label class="pos-field"><span>' + T('restaurant_refund_reason') + (required ? ' *' : '') + '</span><input type="text" id="posReason" maxlength="160" autofocus></label>'
            + '<div class="pos-row pos-row-end"><button type="button" class="pos-btn pos-btn-primary" id="posReasonOk">' + T('restaurant_pos_confirm') + '</button></div>', (s) => {
            $$('[data-r]', s).forEach((b) => b.onclick = () => { $('#posReason', s).value = b.dataset.r; });
            $('#posReasonOk', s).onclick = async () => {
                const r = $('#posReason', s).value.trim();
                if (required && !r) return toast(T('restaurant_err_reason'), 'error');
                if (await run(r)) closeSheet();
            };
        });
    }

    function notesSheet() {
        const o = state.current;
        sheet(T('restaurant_pos_order_note'), '<label class="pos-field"><span>' + T('restaurant_pos_note') + '</span><textarea id="posNotes" maxlength="255" rows="3" autofocus>' + esc(o.notes || '') + '</textarea></label>'
            + '<div class="pos-row pos-row-end"><button type="button" class="pos-btn pos-btn-primary" id="posNotesOk">' + T('restaurant_pos_save') + '</button></div>', (s) => {
            $('#posNotesOk', s).onclick = async () => { if (await act('meta', { notes: $('#posNotes', s).value })) closeSheet(); };
        });
    }
    function guestCountSheet() {
        const o = state.current; let n = Math.max(1, Number(o.guest_count) || 1);
        sheet(T('restaurant_pos_guest_count'), '<div class="pos-row"><div class="pos-stepper is-big"><button type="button" data-g="-1">' + ICON.minus + '</button><span id="posGc">' + n + '</span><button type="button" data-g="1">' + ICON.plus + '</button></div></div>'
            + '<div class="pos-row pos-row-end"><button type="button" class="pos-btn pos-btn-primary" id="posGcOk">' + T('restaurant_pos_save') + '</button></div>', (s) => {
            $$('[data-g]', s).forEach((b) => b.onclick = () => { n = Math.max(1, Math.min(99, n + Number(b.dataset.g))); $('#posGc', s).textContent = n; });
            $('#posGcOk', s).onclick = async () => { if (await act('meta', { guest_count: n })) closeSheet(); };
        });
    }

    function guestSheet() {
        const o = state.current;
        sheet(T('restaurant_pos_guest'), (o.customer_name ? '<div class="pos-row pos-guest-now"><span>' + ICON.user + ' <b>' + esc(o.customer_name) + '</b> ' + esc(o.customer_phone || '') + '</span><button type="button" class="pos-btn" id="posGuestClear">' + T('restaurant_pos_remove') + '</button></div>' : '')
            + '<label class="pos-field"><span>' + T('restaurant_pos_find_guest') + '</span><input type="search" id="posGuestQ" autocomplete="off" placeholder="' + T('restaurant_pos_find_guest_ph') + '" autofocus></label>'
            + '<div class="pos-menu-list" id="posGuestHits"></div>'
            + '<details class="pos-new-guest"><summary>' + T('restaurant_pos_new_guest') + '</summary>'
            + '<label class="pos-field"><span>' + T('restaurant_pos_name') + '</span><input type="text" id="posGName" maxlength="160"></label>'
            + '<label class="pos-field"><span>' + T('restaurant_pos_phone') + '</span><input type="tel" id="posGPhone" maxlength="40"></label>'
            + '<label class="pos-field"><span>' + T('restaurant_pos_email') + '</span><input type="email" id="posGEmail" maxlength="200"></label>'
            + '<div class="pos-row pos-row-end"><button type="button" class="pos-btn pos-btn-primary" id="posGSave">' + T('restaurant_pos_attach') + '</button></div></details>', (s) => {
            let t = null;
            $('#posGuestQ', s).oninput = (e) => { clearTimeout(t); t = setTimeout(async () => {
                const j = await api('customer_search', { q: e.target.value });
                $('#posGuestHits', s).innerHTML = (j.customers || []).map((c) => '<button type="button" class="pos-btn" data-cid="' + c.id + '">' + ICON.user + ' <b>' + esc(c.name) + '</b> <span class="pos-muted">' + esc(c.phone || c.email || '') + '</span></button>').join('')
                    || (e.target.value.trim().length > 1 ? '<div class="pos-empty">' + T('restaurant_pos_no_guest') + '</div>' : '');
                $$('[data-cid]', s).forEach((b) => b.onclick = async () => { if (await act('set_customer', { customer_id: b.dataset.cid })) closeSheet(); });
            }, 220); };
            const clr = $('#posGuestClear', s); if (clr) clr.onclick = async () => { if (await act('set_customer', { customer_id: 0 })) closeSheet(); };
            $('#posGSave', s).onclick = async () => {
                const name = $('#posGName', s).value.trim(), phone = $('#posGPhone', s).value.trim();
                if (!name && !phone) return toast(T('restaurant_pos_guest_need'), 'error');
                if (await act('set_customer', { customer_id: 0, name, phone, email: $('#posGEmail', s).value.trim() })) closeSheet();
            };
        });
    }

    // ── New check ────────────────────────────────────────────
    function newCheckSheet(opts) {
        opts = opts || {};
        let type = opts.tableId ? 'dine_in' : 'dine_in', table = opts.tableId || 0, guests = 2;
        const free = state.floor.filter((t) => t.status !== 'seated' || t.id == table);
        sheet(T('restaurant_pos_new_check'), '<div class="pos-seg is-wide" id="posNewType">'
            + Object.keys(TYPES).map((k) => '<button type="button" data-t="' + k + '" class="' + (k === type ? 'is-active' : '') + '">' + esc(TYPES[k]) + '</button>').join('') + '</div>'
            + '<div id="posNewTables"><h3>' + T('restaurant_rcpt_table') + '</h3><div class="pos-table-pick">'
            + '<button type="button" data-tb="0" class="' + (!table ? 'is-active' : '') + '">' + T('restaurant_pos_no_table') + '</button>'
            + free.map((t) => '<button type="button" data-tb="' + t.id + '" class="is-' + esc(t.status) + (t.id == table ? ' is-active' : '') + '"><b>' + esc(t.label) + '</b><small>' + t.seats + ' · ' + esc(t.section || '') + '</small></button>').join('')
            + '</div></div><h3>' + T('restaurant_pos_guest_count') + '</h3><div class="pos-row"><div class="pos-stepper is-big"><button type="button" data-g="-1">' + ICON.minus + '</button><span id="posNg">' + guests + '</span><button type="button" data-g="1">' + ICON.plus + '</button></div></div>'
            + '<div class="pos-row pos-row-end"><button type="button" class="pos-btn pos-btn-primary" id="posNewOk">' + T('restaurant_pos_open_check') + '<kbd>↵</kbd></button></div>', (s) => {
            const sync = () => { $('#posNewTables', s).hidden = type !== 'dine_in'; };
            $$('#posNewType button', s).forEach((b) => b.onclick = () => { type = b.dataset.t; $$('#posNewType button', s).forEach((x) => x.classList.toggle('is-active', x === b)); sync(); });
            $$('[data-tb]', s).forEach((b) => b.onclick = () => { table = Number(b.dataset.tb); $$('[data-tb]', s).forEach((x) => x.classList.toggle('is-active', x === b));
                const t = state.floor.find((x) => x.id == table); if (t) { guests = Math.min(guests, t.seats) || t.seats; $('#posNg', s).textContent = guests; } });
            $$('[data-g]', s).forEach((b) => b.onclick = () => { guests = Math.max(1, Math.min(99, guests + Number(b.dataset.g))); $('#posNg', s).textContent = guests; });
            const go = async () => {
                const j = await api('open', { type, table_id: type === 'dine_in' ? table : 0, guest_count: guests });
                if (!j.ok) return err(j.error);
                closeSheet(); setCurrent(j.order); setView('register'); refresh();
                if (opts.thenAdd) addItem(opts.thenAdd);
            };
            $('#posNewOk', s).onclick = go;
            s._enter = go;
            sync();
        });
    }

    // ── Pay ──────────────────────────────────────────────────
    function paySheet() {
        const o = state.current, nums = checksOf(o).filter((n) => checkRow(o, n).lines > 0 || checkRow(o, n).paid > 0);
        let check = state.check && nums.includes(state.check) ? state.check : (nums.length > 1 ? (nums.find((n) => checkRow(o, n).balance > 0) || nums[0]) : 1);
        let method = 'cash', tip = 0, kp = null, payId = null;
        const due = () => nums.length > 1 ? checkRow(state.current, check).balance : orderBalance(state.current);
        const base = () => {
            const c = nums.length > 1 ? checkRow(state.current, check) : null;
            return c ? Math.max(0, c.total - c.tip - c.paid) : Math.max(0, Number(state.current.total_cents) - Number(state.current.tip_cents) - Number(state.current.paid_cents));
        };
        const readers = B.terminal && (B.readers || []).length;
        sheet(T('restaurant_pos_pay'), (nums.length > 1 ? '<div class="pos-seg is-wide" id="posPayCheck">' + nums.map((n) => { const c = checkRow(o, n);
                return '<button type="button" data-ck="' + n + '" class="' + (n === check ? 'is-active' : '') + '"' + (c.balance > 0 ? '' : ' disabled') + '>' + T('restaurant_pos_check_n', n) + ' · ' + (c.balance > 0 ? money(c.balance) : '✓') + '</button>'; }).join('') + '</div>' : '')
            + '<div class="pos-pay-due"><span>' + T('restaurant_pos_due') + '</span><b id="posDue"></b></div>'
            + '<h3>' + T('restaurant_rcpt_tip') + '</h3><div class="pos-quick" id="posTips"><button type="button" data-tip="0" class="is-active">' + T('restaurant_pos_no_tip') + '</button>'
            + (B.tipPresets || []).map((p) => '<button type="button" data-pct="' + p + '">' + p + '%</button>').join('') + '<button type="button" data-tip="custom">' + T('restaurant_pos_custom') + '</button></div>'
            + '<div class="pos-seg is-wide" id="posMethod"><button type="button" data-m="cash" class="is-active">' + ICON.cash + T('restaurant_rcpt_cash') + '</button>'
            + '<button type="button" data-m="card"' + (readers ? '' : ' disabled title="' + T('restaurant_pos_no_reader') + '"') + '>' + ICON.card + T('restaurant_rcpt_card') + '</button></div>'
            + '<div id="posPayCash"><div class="pos-quick" id="posBills"></div>' + keypadHtml(T('restaurant_pos_tendered')) + '<div class="pos-change" id="posChange"></div>'
            + '<button type="button" class="pos-btn pos-btn-primary pos-btn-xl" id="posCashOk">' + T('restaurant_pos_take_cash') + '<kbd>↵</kbd></button></div>'
            + '<div id="posPayCard" hidden>' + (readers ? '<label class="pos-field"><span>' + T('restaurant_pos_reader') + '</span><select id="posReader">' + B.readers.map((r) => '<option value="' + r.id + '">' + esc(r.label) + '</option>').join('') + '</select></label>' : '')
            + '<div class="pos-card-status" id="posCardStatus"></div><div class="pos-row pos-row-end"><button type="button" class="pos-btn" id="posCardCancel" hidden>' + T('restaurant_pos_cancel_charge') + '</button>'
            + '<button type="button" class="pos-btn pos-btn-primary pos-btn-xl" id="posCardGo">' + T('restaurant_pos_charge_reader') + '</button></div></div>', (s) => {
            const paint = () => {
                $('#posDue', s).textContent = money(due() + tip);
                const total = due() + tip, steps = [total, Math.ceil(total / 500) * 500, Math.ceil(total / 1000) * 1000, Math.ceil(total / 2000) * 2000, 5000, 10000]
                    .filter((v, i, a) => v >= total && a.indexOf(v) === i).slice(0, 5);
                $('#posBills', s).innerHTML = steps.map((v, i) => '<button type="button" data-bill="' + v + '">' + (i === 0 ? T('restaurant_pos_exact') + ' ' : '') + money(v) + '</button>').join('');
                $$('[data-bill]', s).forEach((b) => b.onclick = () => kp.set((Number(b.dataset.bill) / 100).toFixed(2)));
                change(kp ? kp.value() : '');
            };
            const change = (v) => {
                const el = $('#posChange', s); if (!el) return;
                const given = toCents(v), total = due() + tip;
                el.className = 'pos-change' + (v === '' ? '' : given >= total ? ' is-ok' : ' is-short');
                el.innerHTML = v === '' ? T('restaurant_pos_tendered_hint') : given >= total ? T('restaurant_pos_change', money(given - total)) : T('restaurant_pos_short', money(total - given));
            };
            kp = keypad($('#posPayCash', s), '', change);
            $$('#posPayCheck [data-ck]', s).forEach((b) => b.onclick = () => { check = Number(b.dataset.ck); $$('#posPayCheck button', s).forEach((x) => x.classList.toggle('is-active', x === b)); tip = 0; setTip($('[data-tip="0"]', s)); paint(); });
            const setTip = (btn) => { $$('#posTips button', s).forEach((x) => x.classList.toggle('is-active', x === btn)); };
            $$('#posTips button', s).forEach((b) => b.onclick = () => {
                if (b.dataset.pct) tip = Math.round(base() * Number(b.dataset.pct) / 100);
                else if (b.dataset.tip === 'custom') { const v = prompt(T('restaurant_pos_tip_amount', B.currencySym), ''); if (v === null) return; tip = toCents(v); }
                else tip = 0;
                setTip(b); paint();
            });
            $$('#posMethod button', s).forEach((b) => b.onclick = () => { method = b.dataset.m; $$('#posMethod button', s).forEach((x) => x.classList.toggle('is-active', x === b));
                $('#posPayCash', s).hidden = method !== 'cash'; $('#posPayCard', s).hidden = method !== 'card'; s._kpPress = method === 'cash' ? $('#posPayCash', s)._kpPress : null; });
            s._kpPress = $('#posPayCash', s)._kpPress;
            const cash = async () => {
                const v = kp.value(), amount = due();
                if (v !== '' && toCents(v) < amount + tip) return toast(T('restaurant_pos_short', money(amount + tip - toCents(v))), 'error');
                const j = await act('cash', { amount: (amount / 100).toFixed(2), tip: (tip / 100).toFixed(2), tendered: v, check_no: check });
                if (!j) return;
                closeSheet();
                const ch = Number(j.change_cents) || 0;
                toast(ch > 0 ? T('restaurant_pos_give_change', money(ch)) : T('restaurant_pos_cash_done'));
                if (ch > 0) sheet(T('restaurant_pos_change_due'), '<div class="pos-change-big">' + money(ch) + '</div><div class="pos-row pos-row-end"><button type="button" class="pos-btn pos-btn-primary" data-close>' + T('restaurant_pos_done') + '</button></div>', (s2) => { $$('[data-close]', s2).forEach((b) => b.onclick = closeSheet); });
            };
            $('#posCashOk', s).onclick = cash;
            s._enter = () => { if (method === 'cash') cash(); };
            const status = (t, kind) => { const el = $('#posCardStatus', s); if (el) { el.innerHTML = t; el.className = 'pos-card-status' + (kind ? ' is-' + kind : ''); } };
            const stopPoll = () => { clearInterval(state.poll); state.poll = null; };
            $('#posCardGo', s).onclick = async () => {
                const btn = $('#posCardGo', s); btn.disabled = true; status(T('restaurant_pos_sending'));
                const j = await api('terminal_begin', { order_id: state.current.id, reader_id: $('#posReader', s).value, amount: (due() / 100).toFixed(2), tip: (tip / 100).toFixed(2), check_no: check });
                if (!j.ok) { btn.disabled = false; return status(esc(j.error || B.t.restaurant_pos_failed), 'error'); }
                payId = j.payment_id; status(j.simulated ? T('restaurant_pos_simulated') : T('restaurant_pos_present_card'), 'wait');
                $('#posCardCancel', s).hidden = false;
                stopPoll();
                state.poll = setInterval(async () => {
                    const r = await api('terminal_poll', { payment_id: payId });
                    if (r.order) setCurrent(r.order);
                    if (r.state === 'paid') { stopPoll(); closeSheet(); toast(T('restaurant_pos_card_ok')); }
                    else if (r.state === 'failed') { stopPoll(); status(esc(r.error || B.t.restaurant_pos_failed), 'error'); btn.disabled = false; $('#posCardCancel', s).hidden = true; payId = null; }
                }, 2000);
            };
            $('#posCardCancel', s).onclick = async () => {
                stopPoll(); if (!payId) return;
                const j = await api('terminal_cancel', { payment_id: payId }); if (j.order) setCurrent(j.order);
                payId = null; status(T('restaurant_pos_cancelled')); $('#posCardGo', s).disabled = false; $('#posCardCancel', s).hidden = true;
            };
            paint();
        }, { onClose: () => { clearInterval(state.poll); state.poll = null; } });
    }

    // ── Tables ───────────────────────────────────────────────
    function renderFloor() {
        const box = $('#posFloor');
        const sections = [];
        state.floor.forEach((t) => { const k = t.section || T('restaurant_pos_no_section'); if (!sections.includes(k)) sections.push(k); });
        const shown = state.floor.filter((t) => state.section === 'all' || (t.section || T('restaurant_pos_no_section')) === state.section);
        const counts = { open: 0, seated: 0, dirty: 0 }; state.floor.forEach((t) => counts[t.status]++);
        const placed = shown.some((t) => Number(t.pos_x) || Number(t.pos_y));
        const tile = (t) => {
            const o = t.orders || [], total = o.reduce((a, x) => a + Number(x.total_cents), 0);
            const style = placed ? ' style="left:' + Math.max(0, Number(t.pos_x)) + 'px;top:' + Math.max(0, Number(t.pos_y)) + 'px"' : '';
            return '<button type="button" class="pos-table is-' + esc(t.status) + (Number(t.seats) > 4 ? ' is-large' : '') + '" data-table="' + t.id + '"' + style + '>'
                + '<b>' + esc(t.label) + '</b><small>' + T('restaurant_pos_seats_n', t.seats) + '</small>'
                + (o.length ? '<span class="pos-table-o">' + money(total) + ' · ' + elapsed(o[0].opened_at) + (o.length > 1 ? ' · ×' + o.length : '') + '</span>' : '')
                + '<span class="pos-table-st">' + T('restaurant_pos_t_' + t.status) + '</span></button>';
        };
        box.innerHTML = '<div class="pos-floor-h"><div class="pos-cats">'
            + ['all'].concat(sections).map((k) => '<button type="button" class="pos-chip' + (state.section === k ? ' is-active' : '') + '" data-sec="' + esc(k) + '">' + (k === 'all' ? T('restaurant_pos_all') : esc(k)) + '</button>').join('')
            + '</div><div class="pos-legend"><span class="is-open">' + T('restaurant_pos_t_open') + ' ' + counts.open + '</span><span class="is-seated">' + T('restaurant_pos_t_seated') + ' ' + counts.seated + '</span><span class="is-dirty">' + T('restaurant_pos_t_dirty') + ' ' + counts.dirty + '</span></div></div>'
            + (state.floor.length ? '<div class="pos-floor-map' + (placed ? ' is-placed' : '') + '">' + shown.map(tile).join('') + '</div>'
                : '<div class="pos-empty">' + T('restaurant_pos_no_tables') + '</div>');
        $$('[data-sec]', box).forEach((b) => b.onclick = () => { state.section = b.dataset.sec; renderFloor(); });
        $$('[data-table]', box).forEach((b) => b.onclick = () => tableTap(Number(b.dataset.table)));
    }
    function tableTap(id) {
        const t = state.floor.find((x) => x.id == id); if (!t) return;
        const o = t.orders || [];
        if (t.status === 'open' && !o.length) return newCheckSheet({ tableId: id });
        if (o.length === 1 && t.status !== 'dirty') { loadOrder(o[0].id); setView('register'); return; }
        sheet(T('restaurant_rcpt_table') + ' ' + t.label, '<div class="pos-menu-list">'
            + o.map((x) => '<button type="button" class="pos-btn" data-oid="' + x.id + '">#' + esc(x.order_no) + ' · ' + money(x.total_cents) + ' · ' + elapsed(x.opened_at) + '</button>').join('')
            + '<button type="button" class="pos-btn pos-btn-primary" id="posTblNew">' + T('restaurant_pos_new_check') + '</button>'
            + (t.status === 'dirty' ? '<button type="button" class="pos-btn" data-ts="open">' + T('restaurant_pos_mark_clean') + '</button>' : '')
            + (t.status === 'open' ? '<button type="button" class="pos-btn" data-ts="dirty">' + T('restaurant_pos_mark_dirty') + '</button>' : '')
            + (t.status === 'seated' && !o.length ? '<button type="button" class="pos-btn" data-ts="open">' + T('restaurant_pos_mark_free') + '</button>' : '') + '</div>', (s) => {
            $$('[data-oid]', s).forEach((b) => b.onclick = () => { closeSheet(); loadOrder(Number(b.dataset.oid)); setView('register'); });
            $('#posTblNew', s).onclick = () => newCheckSheet({ tableId: id });
            $$('[data-ts]', s).forEach((b) => b.onclick = async () => {
                const j = await api('table_status', { table_id: id, status: b.dataset.ts });
                if (!j.ok) return err(j.error);
                state.floor = j.floor; renderFloor(); closeSheet();
            });
        });
    }

    // ── Views, refresh, keyboard ─────────────────────────────
    function setView(v) {
        state.view = v;
        $('#pos').dataset.view = v;
        $$('.pos-tab[data-view]').forEach((b) => b.classList.toggle('is-active', b.dataset.view === v));
        $('#posMenu').hidden = v !== 'register'; $('#posFloor').hidden = v !== 'tables';
        if (v === 'tables') { renderFloor(); refresh(); mobile('main'); }
    }
    $$('.pos-tab[data-view]').forEach((b) => b.onclick = () => setView(b.dataset.view));
    function mobile(pane) {
        $('#pos').dataset.m = pane;
        $$('.pos-mobile-nav button').forEach((b) => b.classList.toggle('is-active', b.dataset.m === pane));
    }
    $$('.pos-mobile-nav button').forEach((b) => b.onclick = () => mobile(b.dataset.m));

    async function refresh() {
        const j = await api('refresh', {});
        if (!j.ok) return;
        state.orders = j.open || []; state.recent = j.recent || []; state.floor = j.floor || [];
        if (state.current && isOpen(state.current)) {
            const fresh = state.orders.find((o) => o.id == state.current.id);
            if (!fresh) { const r = await api('order', { order_id: state.current.id }); if (r.ok) state.current = r.order; renderCheck(); }
        }
        renderRail(); if (state.view === 'tables') renderFloor();
    }

    $('#posNew').onclick = () => newCheckSheet();
    $('#posHelpBtn').onclick = helpSheet;
    $('#posFullBtn').onclick = () => {
        if (document.fullscreenElement) document.exitFullscreen();
        else if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().catch(() => toast(T('restaurant_pos_no_fullscreen'), 'error'));
    };
    function helpSheet() {
        const keys = [['/', 'restaurant_pos_k_search'], ['N', 'restaurant_pos_k_new'], ['R', 'restaurant_pos_k_register'], ['T', 'restaurant_pos_k_tables'],
                      ['S', 'restaurant_pos_k_send'], ['P', 'restaurant_pos_k_pay'], ['0–9 . ⌫', 'restaurant_pos_k_keypad'], ['↵', 'restaurant_pos_k_enter'], ['Esc', 'restaurant_pos_k_esc'], ['?', 'restaurant_pos_k_help']];
        sheet(T('restaurant_pos_shortcuts'), '<dl class="pos-keys">' + keys.map(([k, d]) => '<dt><kbd>' + esc(k) + '</kbd></dt><dd>' + T(d) + '</dd>').join('') + '</dl>'
            + '<p class="pos-muted">' + T('restaurant_pos_longpress') + '</p>');
    }
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { if (sheetOpen()) { closeSheet(); e.preventDefault(); } return; }
        if (sheetOpen()) {
            if (e.key === 'Enter' && $('#posSheet')._enter && !e.target.matches('textarea')) { e.preventDefault(); $('#posSheet')._enter(); }
            return;
        }
        if (e.target.matches('input, textarea, select') || e.metaKey || e.ctrlKey || e.altKey) return;
        const k = e.key.toLowerCase();
        if (k === '/') { e.preventDefault(); setView('register'); $('#posSearch').focus(); }
        else if (k === 'n') newCheckSheet();
        else if (k === 't') setView('tables');
        else if (k === 'r') setView('register');
        else if (k === '?') helpSheet();
        else if (k === 's' && state.current && isOpen(state.current)) ACTIONS.send();
        else if (k === 'p' && state.current && isOpen(state.current) && Number(state.current.total_cents) > 0) paySheet();
    });

    const tick = () => { $('#posClock').textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); };
    tick(); setInterval(tick, 15000);
    setInterval(() => { if (!sheetOpen() && !document.hidden) refresh(); }, 20000);

    renderRail(); renderCats(); renderGrid(); renderCheck(); mobile('main');
    refresh();
    if (B.openId) loadOrder(B.openId);
})();
