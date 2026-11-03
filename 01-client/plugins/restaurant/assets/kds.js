/*
 * Restaurant — kitchen display (admin/kds.php). Polls the page's JSON
 * endpoint every 5 seconds; every tap answers with the fresh ticket list.
 * Ages are measured against the server clock (KDS_BOOT.now) so a kitchen
 * tablet with the wrong time still shows the right minutes.
 */
(function () {
    'use strict';
    const B = window.KDS_BOOT;
    const $ = (s) => document.querySelector(s);
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    const T = (k, a) => String(B.t[k] || k).replace('%s', esc(a));
    const parse = (ts) => new Date(String(ts).replace(' ', 'T')).getTime();
    let tickets = B.tickets || [], filter = 'all', skew = Date.now() - parse(B.now), seen = new Set(tickets.map((t) => t.id)), busy = false;

    const kind = (t) => t.source === 'online' ? 'online' : (t.type === 'dine_in' ? 'dine_in' : 'togo');
    const mins = (ts) => ts ? Math.max(0, Math.floor((Date.now() - skew - parse(ts)) / 60000)) : 0;
    const age = (m) => m >= 20 ? 'is-late' : (m >= 10 ? 'is-warn' : 'is-ok');
    const where = (t) => t.table_label ? T('restaurant_rcpt_table') + ' ' + esc(t.table_label)
        : esc(B.t['restaurant_rcpt_' + t.type] || t.type) + (t.customer_name ? ' · ' + esc(t.customer_name) : '');

    function render() {
        const counts = { all: tickets.length, dine_in: 0, togo: 0, online: 0 };
        tickets.forEach((t) => counts[kind(t)]++);
        document.querySelectorAll('[data-n]').forEach((el) => { el.textContent = counts[el.dataset.n] || ''; });
        const list = tickets.filter((t) => filter === 'all' || kind(t) === filter);
        if (!list.length) { $('#kdsBoard').innerHTML = '<div class="kds-empty">' + T('restaurant_kds_empty') + '</div>'; return; }
        $('#kdsBoard').innerHTML = list.map((t) => {
            const m = mins(t.fired_at), allReady = t.lines.every((l) => l.kitchen_status === 'ready');
            return '<article class="kds-ticket ' + age(m) + (allReady ? ' is-ready' : '') + '">'
                + '<header><div><b>#' + esc(t.order_no) + '</b><span>' + where(t) + (t.source === 'online' ? ' · ' + T('restaurant_pos_online') : '') + '</span></div>'
                + '<time>' + m + '′</time></header>'
                + (t.requested_time ? '<div class="kds-when">' + T('restaurant_kds_for', t.requested_time) + '</div>' : '')
                + (t.notes ? '<div class="kds-note">' + esc(t.notes) + '</div>' : '')
                + '<ul>' + t.lines.map((l) => '<li><button type="button" class="kds-line is-' + esc(l.kitchen_status) + '" data-line="' + l.id + '" data-to="' + (l.kitchen_status === 'ready' ? 'served' : 'ready') + '">'
                    + '<span class="kds-qty">' + l.qty + '</span><span class="kds-item"><b>' + esc(l.name_snapshot) + '</b>'
                    + (l.seat ? ' <em>' + T('restaurant_kds_seat', l.seat) + '</em>' : '')
                    + (l.modifiers.length ? '<small>' + l.modifiers.map(esc).join(' · ') + '</small>' : '')
                    + (l.notes ? '<small class="kds-line-note">' + esc(l.notes) + '</small>' : '') + '</span>'
                    + '<span class="kds-state">' + (l.kitchen_status === 'ready' ? '✓ ' + T('restaurant_pos_k_ready') : T('restaurant_pos_k_sent')) + '</span></button></li>').join('') + '</ul>'
                + '<footer>' + (allReady
                    ? '<button type="button" class="pos-btn" data-recall="' + t.id + '">' + T('restaurant_kds_recall') + '</button><button type="button" class="pos-btn pos-btn-primary" data-ticket="' + t.id + '" data-to="served">' + T('restaurant_kds_served') + '</button>'
                    : '<button type="button" class="pos-btn pos-btn-primary" data-ticket="' + t.id + '" data-to="ready">' + T('restaurant_kds_ready') + '</button>')
                + '</footer></article>';
        }).join('');
    }

    async function post(data) {
        if (busy) return; busy = true;
        const body = new URLSearchParams(Object.assign({ _ajax: '1', _csrf: B.csrf }, data));
        try {
            const r = await fetch(B.endpoint, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const j = await r.json();
            if (!j.ok) { status(esc(j.error || B.t.restaurant_pos_failed), true); return; }
            status('');
            skew = Date.now() - parse(j.now);
            const fresh = j.tickets.filter((t) => !seen.has(t.id));
            fresh.forEach((t) => seen.add(t.id));
            if (fresh.length && data.action === 'tickets') chime();
            tickets = j.tickets; render();
        } catch (e) {
            status(T('restaurant_kds_offline'), true);
        } finally { busy = false; }
    }
    function status(msg, bad) { const s = $('#kdsStatus'); s.innerHTML = msg; s.classList.toggle('is-bad', !!bad); }
    function chime() {
        if (!$('#kdsSound').checked) return;
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            [0, 0.18].forEach((d, i) => {
                const o = ctx.createOscillator(), g = ctx.createGain();
                o.frequency.value = i ? 1046 : 784; o.connect(g); g.connect(ctx.destination);
                g.gain.setValueAtTime(0.0001, ctx.currentTime + d); g.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + d + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + d + 0.25);
                o.start(ctx.currentTime + d); o.stop(ctx.currentTime + d + 0.3);
            });
        } catch (e) { /* audio blocked until the first tap: fine */ }
    }

    $('#kdsBoard').addEventListener('click', (e) => {
        const line = e.target.closest('[data-line]'), tk = e.target.closest('[data-ticket]'), rc = e.target.closest('[data-recall]');
        if (line) post({ action: 'line', line_id: line.dataset.line, to: line.dataset.to });
        else if (tk) post({ action: 'ticket', order_id: tk.dataset.ticket, to: tk.dataset.to });
        else if (rc) post({ action: 'ticket', order_id: rc.dataset.recall, to: 'sent' });
    });
    document.querySelectorAll('#kdsFilter [data-f]').forEach((b) => b.onclick = () => {
        filter = b.dataset.f; document.querySelectorAll('#kdsFilter [data-f]').forEach((x) => x.classList.toggle('is-active', x === b)); render();
    });
    const tick = () => { $('#kdsClock').textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); };
    tick(); setInterval(() => { tick(); render(); }, 30000);
    setInterval(() => { if (!document.hidden) post({ action: 'tickets' }); }, 5000);
    render();
})();
