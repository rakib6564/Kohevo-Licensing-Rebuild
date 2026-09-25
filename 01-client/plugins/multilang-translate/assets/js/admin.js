(function () {
    var wrap = document.querySelector('.mlt-wrap');
    if (!wrap) return;
    var csrf = wrap.dataset.csrf;
    var api = window.MLT_API || 'api.php';

    function toast(msg, ms) {
        var t = document.getElementById('mlt-toast');
        t.textContent = msg;
        t.hidden = false;
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { t.hidden = true; }, ms || 3000);
    }

    function post(action, data, isForm) {
        var body;
        if (isForm) {
            body = data; body.append('action', action); body.append('_csrf', csrf);
        } else {
            body = new URLSearchParams(Object.assign({ action: action, _csrf: csrf }, data || {}));
        }
        return fetch(api, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body }).then(function (response) {
            return response.text().then(function (text) {
                var payload;
                try { payload = text ? JSON.parse(text) : {}; } catch (e) { payload = { ok: false, error: 'Server returned a non-JSON response (HTTP ' + response.status + ').' }; }
                if (!response.ok && !payload.error) payload.error = 'Request failed (HTTP ' + response.status + ').';
                return payload;
            });
        }).catch(function (error) {
            return { ok: false, error: error && error.message ? error.message : 'Network request failed.' };
        });
    }

    function reload() { window.location.reload(); }

    function openModal(id) { document.getElementById(id).hidden = false; }
    function closeModal(el) { el.closest('.mlt-modal').hidden = true; }

    document.querySelectorAll('.mlt-modal [data-close]').forEach(function (btn) {
        btn.addEventListener('click', function () { closeModal(btn); });
    });
    document.querySelectorAll('.mlt-modal').forEach(function (m) {
        m.addEventListener('click', function (e) { if (e.target === m) m.hidden = true; });
    });

    // ── Incremental scan ──────────────────────────────────
    var scanBtn = document.getElementById('mlt-scan-btn');
    var scanPanel = document.getElementById('mlt-scan-progress');
    var scanState = { queue: [], index: 0, total: 0, pages: 0, newStrings: 0, strings: 0, errors: [] };

    function scanText(id, value) { var node = document.getElementById(id); if (node) node.textContent = value; }
    function renderScan(status) {
        var total = scanState.total, processed = Math.min(scanState.index, total);
        var percent = total ? Math.round(processed * 100 / total) : 0;
        var fill = document.getElementById('mlt-scan-progress-fill'), track = document.querySelector('.mlt-progress-track');
        if (fill) fill.style.width = percent + '%';
        if (track) track.setAttribute('aria-valuenow', String(percent));
        scanText('mlt-scan-percent', percent + '%'); scanText('mlt-scan-processed', processed); scanText('mlt-scan-total', total);
        scanText('mlt-scan-pages', scanState.pages); scanText('mlt-scan-new-strings', scanState.newStrings); scanText('mlt-scan-strings', scanState.strings);
        scanText('mlt-scan-status', status);
        var errors = document.getElementById('mlt-scan-errors');
        if (errors) { errors.hidden = !scanState.errors.length; errors.textContent = scanState.errors.join('\\n'); }
    }
    function stopScan(status, message) {
        if (message) scanState.errors.push(message);
        scanBtn.disabled = false; scanBtn.innerHTML = '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg> Scan site'; renderScan(status);
        toast(message || status, 6000);
    }
    function finishScan() {
        scanBtn.disabled = false; scanBtn.innerHTML = '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg> Scan site';
        renderScan(scanState.errors.length ? 'Complete with warnings' : 'Complete');
        toast('Scanned ' + scanState.pages + ' pages and found ' + scanState.newStrings + ' new strings.', 5000);
        setTimeout(reload, 1200);
    }
    function processScanQueue() {
        if (scanState.index >= scanState.total) { finishScan(); return; }
        var batch = scanState.queue.slice(scanState.index, scanState.index + 1);
        post('scan_batch', { items: JSON.stringify(batch) }).then(function (r) {
            if (!r.ok) { stopScan('Stopped — request failed', r.error || 'Scan request failed.'); return; }
            var processed = Number(r.processed || batch.length); scanState.index += processed;
            scanState.pages += Number(r.pages || 0); scanState.newStrings += Number(r.new_strings || 0);
            scanState.strings = Number(r.harvested_strings || scanState.strings);
            (r.errors || []).forEach(function (error) { scanState.errors.push(error); });
            renderScan(scanState.index < scanState.total ? 'Scanning…' : 'Finalizing…');
            setTimeout(processScanQueue, 30);
        });
    }
    function startScan() {
        scanPanel.hidden = false; scanBtn.disabled = true; scanBtn.innerHTML = '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg> Starting…';
        scanState = { queue: [], index: 0, total: 0, pages: 0, newStrings: 0, strings: 0, errors: [] }; renderScan('Discovering URLs…');
        post('scan_start', {}).then(function (r) {
            if (!r.ok) { stopScan('Could not start scan', r.error || 'Scan start failed.'); return; }
            scanState.queue = Array.isArray(r.queue) ? r.queue : []; scanState.total = Number(r.total || scanState.queue.length); scanState.strings = Number(r.initial_strings || 0);
            renderScan(scanState.total ? 'Scanning…' : 'No URLs discovered');
            if (!scanState.total) { finishScan(); return; }
            scanBtn.innerHTML = '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg> Scanning…'; processScanQueue();
        });
    }
    if (scanBtn) scanBtn.addEventListener('click', startScan);

    // ── Scan URLs (pages Scan can't discover on its own) ──
    var scanUrlsBtn = document.getElementById('mlt-scanurls-btn');
    if (scanUrlsBtn) scanUrlsBtn.addEventListener('click', function () { openModal('mlt-modal-scanurls'); });
    var scanUrlsSave = document.getElementById('mlt-scanurls-save');
    if (scanUrlsSave) scanUrlsSave.addEventListener('click', function () {
        var text = document.getElementById('mlt-scanurls-text').value;
        post('save_scan_urls', { urls: text }).then(function (r) {
            if (r.ok) { toast('Saved ' + r.count + ' URL(s) — click Scan site to fetch them.'); document.getElementById('mlt-modal-scanurls').hidden = true; }
            else toast(r.error || 'Could not save URLs');
        });
    });

    // ── Search / filter ──────────────────────────────────
    var search = document.getElementById('mlt-search');
    if (search) {
        var t;
        search.addEventListener('input', function () {
            clearTimeout(t);
            t = setTimeout(function () {
                var u = new URL(window.location.href);
                u.searchParams.set('q', search.value);
                u.searchParams.set('page', '1');
                window.location.href = u.toString();
            }, 500);
        });
    }
    document.querySelectorAll('#mlt-tabs .segmented-item').forEach(function (tab) {
        tab.addEventListener('click', function () {
            var u = new URL(window.location.href);
            u.searchParams.set('filter', tab.dataset.filterValue);
            u.searchParams.set('page', '1');
            window.location.href = u.toString();
        });
    });

    // ── Cell editing (auto-save as draft on blur) ────────
    var pending = [];
    document.querySelectorAll('.mlt-cell').forEach(function (cell) {
        cell.addEventListener('input', function () {
            cell.style.height = 'auto'; cell.style.height = cell.scrollHeight + 'px';
        });
        cell.addEventListener('blur', function () {
            saveCell(cell);
        });
    });

    function saveCell(cell) {
        var sid = cell.dataset.stringId, loc = cell.dataset.locale, text = cell.value;
        post('save_cell', { string_id: sid, locale: loc, text: text }).then(function (r) {
            if (r.ok) {
                cell.className = cell.className.replace(/mlt-status-\w+/, 'mlt-status-' + (text.trim() === '' ? 'blank' : 'draft'));
            } else toast(r.error || 'Save failed');
        });
    }

    var saveBtn = document.getElementById('mlt-save-btn');
    if (saveBtn) saveBtn.addEventListener('click', function () {
        var cells = [];
        document.querySelectorAll('.mlt-cell').forEach(function (c) {
            cells.push({ string_id: c.dataset.stringId, locale: c.dataset.locale, text: c.value });
        });
        post('save_batch', { cells: JSON.stringify(cells) }).then(function (r) {
            toast(r.ok ? ('Saved ' + r.saved + ' cells as draft.') : (r.error || 'Save failed'));
        });
    });

    var publishBtn = document.getElementById('mlt-publish-btn');
    if (publishBtn) publishBtn.addEventListener('click', function () {
        if (!confirm('Publish all draft translations live now?')) return;
        post('publish', {}).then(function (r) {
            toast(r.ok ? ('Published ' + r.published + ' translations.') : (r.error || 'Publish failed'));
            setTimeout(reload, 1000);
        });
    });

    // ── Add language ──────────────────────────────────────
    var addBtn = document.getElementById('mlt-addlang-btn');
    if (addBtn) addBtn.addEventListener('click', function () { openModal('mlt-modal-addlang'); });
    var addConfirm = document.getElementById('mlt-newlang-confirm');
    if (addConfirm) addConfirm.addEventListener('click', function () {
        var code = document.getElementById('mlt-newlang-code').value.trim();
        var name = document.getElementById('mlt-newlang-name').value.trim();
        if (!code) { toast('Enter a language code.'); return; }
        post('add_language', { code: code, name: name }).then(function (r) {
            if (r.ok) reload(); else toast(r.error || 'Could not add language');
        });
    });

    // ── Language column toggle / remove ──────────────────
    document.querySelectorAll('.mlt-lang-toggle').forEach(function (chk) {
        chk.addEventListener('change', function () {
            post('toggle_language', { id: chk.dataset.id, enabled: chk.checked ? '1' : '0' }).then(function (r) {
                if (r.ok) reload(); else toast(r.error || 'Failed');
            });
        });
    });
    document.querySelectorAll('.mlt-lang-remove').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Remove this language column and all its translations?')) return;
            post('delete_language', { id: btn.dataset.id }).then(function (r) {
                if (r.ok) reload(); else toast(r.error || 'Failed');
            });
        });
    });

    // ── Find & replace ────────────────────────────────────
    var frBtn = document.getElementById('mlt-findreplace-btn');
    if (frBtn) frBtn.addEventListener('click', function () { openModal('mlt-modal-findreplace'); });
    var frConfirm = document.getElementById('mlt-fr-confirm');
    if (frConfirm) frConfirm.addEventListener('click', function () {
        post('find_replace', {
            locale: document.getElementById('mlt-fr-locale').value,
            find: document.getElementById('mlt-fr-find').value,
            replace: document.getElementById('mlt-fr-replace').value,
            case_sensitive: document.getElementById('mlt-fr-case').checked ? '1' : ''
        }).then(function (r) {
            if (r.ok) { toast('Replaced in ' + r.replaced + ' rows.'); setTimeout(reload, 1000); }
            else toast(r.error || 'Failed');
        });
    });

    // ── Import ────────────────────────────────────────────
    var impBtn = document.getElementById('mlt-import-btn');
    if (impBtn) impBtn.addEventListener('click', function () { openModal('mlt-modal-import'); });
    var impConfirm = document.getElementById('mlt-import-confirm');
    if (impConfirm) impConfirm.addEventListener('click', function () {
        var file = document.getElementById('mlt-import-file').files[0];
        if (!file) { toast('Choose a CSV file first.'); return; }
        var localeSel = document.getElementById('mlt-import-locale');
        var fd = new FormData();
        fd.append('file', file);
        fd.append('locale', localeSel ? localeSel.value : 'all');
        post('import', fd, true).then(function (r) {
            if (r.ok) { toast('Imported: ' + r.updated + ' updated, ' + r.skipped + ' skipped.'); setTimeout(reload, 1200); }
            else toast(r.error || 'Import failed');
        });
    });

    // ── Export ────────────────────────────────────────────
    var expBtn = document.getElementById('mlt-export-btn');
    if (expBtn) expBtn.addEventListener('click', function () { openModal('mlt-modal-export'); });

    function mltExportSelection() {
        var picked = Array.from(document.querySelectorAll('.mlt-export-lang:checked')).map(function (c) {
            return { code: c.value, label: c.closest('label').textContent.trim() };
        });
        var extraRaw = (document.getElementById('mlt-export-extra-lang') || {}).value || '';
        extraRaw.split(',').map(function (s) { return s.trim().toLowerCase(); }).filter(function (s) {
            return /^[a-z]{2}(-[a-z]{2})?$/.test(s);
        }).forEach(function (code) {
            if (!picked.some(function (p) { return p.code === code; })) picked.push({ code: code, label: code + ' (new)' });
        });
        return picked;
    }

    var expConfirm = document.getElementById('mlt-export-confirm');
    if (expConfirm) expConfirm.addEventListener('click', function (e) {
        var locales = mltExportSelection().map(function (p) { return p.code; });
        var url = new URL(api, window.location.href);
        url.searchParams.set('action', 'export');
        locales.forEach(function (l) { url.searchParams.append('locales[]', l); });
        expConfirm.href = url.toString();
    });

    var expCopyPrompt = document.getElementById('mlt-export-copy-prompt');
    if (expCopyPrompt) expCopyPrompt.addEventListener('click', function () {
        var picked = mltExportSelection();
        if (!picked.length) { toast('Pick at least one language first.'); return; }
        var langList = picked.map(function (p) { return p.label + ' → column header "' + p.code + '"'; }).join('\n  ');
        var prompt =
            'You are translating website UI text from English into one or more languages.\n\n' +
            'Attached is a CSV export with columns: id, source_text, and one blank/partial column per target language:\n  ' + langList + '\n\n' +
            'Instructions:\n' +
            '1. Do NOT change the "id" or "source_text" columns or the row order.\n' +
            '2. For each target language column, fill in the natural, idiomatic translation of that row\'s source_text.\n' +
            '3. If a target column already has text in some rows, leave those rows as-is and only fill in the blanks.\n' +
            '4. Preserve any placeholders, HTML tags, {curly brace} variables, or punctuation exactly as they appear in source_text — translate only the surrounding human-readable text.\n' +
            '5. Keep the same tone as the source (short UI labels stay short; sentences stay sentences).\n' +
            '6. Return the result as a CSV file with the exact same header row and column order, UTF-8 encoded, so it can be re-imported as-is.\n';
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(prompt).then(function () { toast('AI prompt copied — paste it along with the exported CSV.'); },
                function () { window.prompt('Copy this prompt:', prompt); });
        } else {
            window.prompt('Copy this prompt:', prompt);
        }
    });

    // ── Customize switcher ────────────────────────────────
    var czBtn = document.getElementById('mlt-customize-btn');
    if (czBtn) czBtn.addEventListener('click', function () {
        var s = window.MLT_SETTINGS || {};
        document.getElementById('mlt-cz-enabled').checked = !!s.switcher_enabled;
        document.getElementById('mlt-cz-style').value = s.switcher_style || 'dropdown';
        document.getElementById('mlt-cz-position').value = s.switcher_position || 'bottom-right';
        document.getElementById('mlt-cz-bg').value = s.switcher_bg || '#111827';
        document.getElementById('mlt-cz-fg').value = s.switcher_fg || '#ffffff';
        document.getElementById('mlt-cz-accent').value = s.switcher_accent || '#2563eb';
        openModal('mlt-modal-customize');
    });
    var czConfirm = document.getElementById('mlt-cz-confirm');
    if (czConfirm) czConfirm.addEventListener('click', function () {
        post('save_switcher_settings', {
            switcher_enabled: document.getElementById('mlt-cz-enabled').checked ? '1' : '0',
            switcher_style: document.getElementById('mlt-cz-style').value,
            switcher_position: document.getElementById('mlt-cz-position').value,
            switcher_bg: document.getElementById('mlt-cz-bg').value,
            switcher_fg: document.getElementById('mlt-cz-fg').value,
            switcher_accent: document.getElementById('mlt-cz-accent').value
        }).then(function (r) {
            if (r.ok) { toast('Switcher settings saved.'); document.getElementById('mlt-modal-customize').hidden = true; Object.assign(window.MLT_SETTINGS, {}); }
            else toast(r.error || 'Failed');
        });
    });
})();
