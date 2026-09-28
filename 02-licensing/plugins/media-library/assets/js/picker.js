/* Media library picker modal.
 *
 * Usage from any admin page:
 *
 *   <script src="/plugins/media-library/assets/js/picker.js"></script>
 *   <link rel="stylesheet" href="/plugins/media-library/assets/css/picker.css">
 *
 * Then either:
 *
 *   (a) Add data-mlp-target="someInputId" data-mlp-mode="single|append"
 *       to a <button>. Clicking it opens the picker. Picking sets the
 *       input's value (single) or appends a hidden gallery field
 *       (append) and fires a 'change' event.
 *
 *   (b) Call MediaPicker.open({mode: 'single', onPick: function(path, item) {...}})
 *       programmatically.
 *
 * The picker is a singleton — only one modal exists in the DOM, reused
 * across calls. The list of media is cached for the page's lifetime to
 * avoid re-hitting the API on every open (you'd hit it twice in a row
 * when bouncing between fields). If you need to refresh after an upload
 * elsewhere, call MediaPicker.invalidateCache().
 */

(function () {
    'use strict';

    // Auto-derive the API URL from this script's own location. The
    // hardcoded '/plugins/media-library/admin/api.php' breaks any
    // install that lives in a subdirectory (e.g. /slate/) because it
    // resolves against the document root, not the install root.
    //
    // The script tag's src tells us where we are:
    //   https://example.com/slate/plugins/media-library/assets/js/picker.js
    // From that we strip the trailing /assets/js/picker.js and replace
    // it with /admin/api.php:
    //   https://example.com/slate/plugins/media-library/admin/api.php
    function deriveApiPath() {
        // document.currentScript is null in some inline-script edge
        // cases, but in normal <script src="...picker.js"> loads it
        // points to our script element. Fall back to searching all
        // scripts if needed.
        var scriptEl = document.currentScript;
        if (!scriptEl) {
            var all = document.getElementsByTagName('script');
            for (var i = 0; i < all.length; i++) {
                if (all[i].src && all[i].src.indexOf('media-library/assets/js/picker.js') !== -1) {
                    scriptEl = all[i];
                    break;
                }
            }
        }
        if (!scriptEl || !scriptEl.src) {
            // Last-ditch: relative path, won't survive a nested install
            // but at least the dev environment works.
            return '/plugins/media-library/admin/api.php';
        }
        // Strip any query string (e.g. ?ver=1.0.0) before path-munging.
        var src = scriptEl.src.split('?')[0].split('#')[0];
        // Replace the last segment .../assets/js/picker.js → /admin/api.php.
        // We do this via the explicit suffix so we don't accidentally
        // match an unrelated 'picker.js' somewhere else in the path.
        var suffix = '/assets/js/picker.js';
        var i2 = src.lastIndexOf(suffix);
        if (i2 === -1) return '/plugins/media-library/admin/api.php';
        return src.slice(0, i2) + '/admin/api.php';
    }

    var apiPath = deriveApiPath();
    // Cache is keyed by type filter ('' | 'image' | 'document') so the
    // SlateMedia type scoping doesn't show a stale all-types list.
    var cacheByType = {};
    var cacheLoadedAt = {};
    var CACHE_TTL_MS = 60000;  // 1 minute — stale enough to be lazy, fresh enough to be useful
    var currentType = '';      // active type filter for the next open()

    // ──────────────────────────────────────────────────────────
    // Modal DOM (built once, reused)
    // ──────────────────────────────────────────────────────────

    var modalEl = null;
    var selectedItem = null;
    var currentMode = 'single';
    var currentCallback = null;

    function buildModal() {
        if (modalEl) return modalEl;

        var overlay = document.createElement('div');
        overlay.className = 'mlp-overlay';
        overlay.innerHTML =
            '<div class="mlp-modal" role="dialog" aria-modal="true" aria-label="Media library">' +
              '<div class="mlp-header">' +
                '<div class="mlp-title">Select media</div>' +
                '<button type="button" class="mlp-close" aria-label="Close">\u00d7</button>' +
              '</div>' +
              '<div class="mlp-toolbar">' +
                '<input type="search" class="mlp-search" placeholder="Search by filename...">' +
              '</div>' +
              '<div class="mlp-body">' +
                '<div class="mlp-loading">Loading...</div>' +
                '<div class="mlp-grid" style="display:none;"></div>' +
                '<div class="mlp-empty" style="display:none;">No media found.</div>' +
              '</div>' +
              '<div class="mlp-footer">' +
                '<div class="mlp-status"></div>' +
                '<div class="mlp-actions">' +
                  '<button type="button" class="mlp-btn mlp-cancel">Cancel</button>' +
                  '<button type="button" class="mlp-btn mlp-btn-primary mlp-pick" disabled>Use this</button>' +
                '</div>' +
              '</div>' +
            '</div>';

        document.body.appendChild(overlay);
        modalEl = overlay;

        // Close handlers
        overlay.querySelector('.mlp-close').addEventListener('click', close);
        overlay.querySelector('.mlp-cancel').addEventListener('click', close);
        overlay.addEventListener('click', function (e) {
            // Click on the overlay backdrop (outside the modal) closes.
            if (e.target === overlay) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('is-open')) close();
        });

        // Search
        var search = overlay.querySelector('.mlp-search');
        search.addEventListener('input', function () {
            renderGrid(filterItems(cacheByType[currentType || ''] || [], search.value));
        });

        // Pick (commits the selection)
        overlay.querySelector('.mlp-pick').addEventListener('click', function () {
            if (!selectedItem) return;
            commitPick(selectedItem);
            close();
        });

        return overlay;
    }

    function open(opts) {
        opts = opts || {};
        currentMode = opts.mode || 'single';
        currentCallback = opts.onPick || null;
        selectedItem = null;

        buildModal();
        modalEl.classList.add('is-open');
        modalEl.querySelector('.mlp-pick').disabled = true;
        modalEl.querySelector('.mlp-search').value = '';

        loadItems().then(function (items) {
            renderGrid(items);
        }).catch(function (err) {
            console.error('MediaPicker: API request failed', err, 'URL was:', apiPath);
            var body = modalEl.querySelector('.mlp-body');
            body.querySelector('.mlp-loading').style.display = 'none';
            body.querySelector('.mlp-grid').style.display = 'none';
            var empty = body.querySelector('.mlp-empty');
            empty.style.display = 'block';
            empty.textContent = 'Failed to load media: ' + (err && err.message || 'unknown error');
        });
    }

    function close() {
        if (modalEl) modalEl.classList.remove('is-open');
        selectedItem = null;
        currentCallback = null;
    }

    function loadItems() {
        var type = currentType || '';
        var fresh = cacheByType[type] && (Date.now() - (cacheLoadedAt[type] || 0)) < CACHE_TTL_MS;
        if (fresh) return Promise.resolve(cacheByType[type]);

        var url = apiPath + '?action=list' + (type ? '&type=' + encodeURIComponent(type) : '');
        return fetch(url, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).then(function (data) {
            if (!data.ok) throw new Error(data.error || 'API error');
            cacheByType[type] = data.items || [];
            cacheLoadedAt[type] = Date.now();
            return cacheByType[type];
        });
    }

    function invalidateCache() { cacheByType = {}; cacheLoadedAt = {}; }

    function filterItems(items, query) {
        query = (query || '').trim().toLowerCase();
        if (!query) return items;
        return items.filter(function (it) {
            return (it.original_name || '').toLowerCase().indexOf(query) !== -1;
        });
    }

    // Tiny HTML escapers — we build cards with innerHTML so any string
    // that came from the server (filenames, captions) must be escaped.
    // We don't trust the server output here; an admin could upload a
    // file named "<img onerror=...>.png" and the API would echo that
    // filename back to us.
    function escAttr(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }
    function escText(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }
    function truncate(s, n) {
        s = String(s == null ? '' : s);
        if (s.length <= n) return s;
        return s.slice(0, n - 1) + '\u2026';
    }

    function renderGrid(items) {
        var body = modalEl.querySelector('.mlp-body');
        var loading = body.querySelector('.mlp-loading');
        var grid = body.querySelector('.mlp-grid');
        var empty = body.querySelector('.mlp-empty');
        var status = modalEl.querySelector('.mlp-status');

        loading.style.display = 'none';

        if (!items.length) {
            grid.style.display = 'none';
            empty.style.display = 'block';
            empty.textContent = 'No media match.';
            status.textContent = '';
            return;
        }
        empty.style.display = 'none';
        grid.style.display = 'grid';
        status.textContent = items.length + ' item(s)';

        grid.innerHTML = '';
        items.forEach(function (it) {
            var card = document.createElement('div');
            card.className = 'mlp-item';
            card.setAttribute('data-path', it.path);
            var dim = (it.width && it.height) ? (it.width + '×' + it.height) : '';
            var kb = Math.round((it.size_bytes || 0) / 1024);
            card.innerHTML =
                '<div class="mlp-thumb"><img src="' + escAttr(it.url) + '" alt="" loading="lazy"></div>' +
                '<div class="mlp-item-meta">' +
                  '<div class="mlp-item-name" title="' + escAttr(it.original_name) + '">' +
                    escText(truncate(it.original_name, 22)) +
                  '</div>' +
                  '<div>' + escText(dim) + (dim ? ' · ' : '') + kb + ' KB</div>' +
                '</div>';
            card.addEventListener('click', function () {
                // Select this card. Single-click selects, the "Use this"
                // button (or double-click) commits.
                grid.querySelectorAll('.mlp-item').forEach(function (n) {
                    n.classList.remove('is-selected');
                });
                card.classList.add('is-selected');
                selectedItem = it;
                modalEl.querySelector('.mlp-pick').disabled = false;
            });
            card.addEventListener('dblclick', function () {
                selectedItem = it;
                commitPick(selectedItem);
                close();
            });
            grid.appendChild(card);
        });
    }

    function commitPick(item) {
        if (currentCallback) {
            try { currentCallback(item.path, item); }
            catch (e) { console.error('MediaPicker callback threw', e); }
        }
    }

    // ──────────────────────────────────────────────────────────
    // Auto-wire data-attribute triggers
    // ──────────────────────────────────────────────────────────

    // When a button has data-mlp-target="someInputId", clicking it opens
    // the picker, and the chosen path is written into the input by id.
    // For data-mlp-mode="append" (typical for galleries) we instead add
    // a new hidden input alongside the target (named the same as the
    // target's name attribute) — this is how the Shop gallery accepts
    // an array of existing paths.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-mlp-target]');
        if (!btn) return;
        e.preventDefault();

        var targetId = btn.getAttribute('data-mlp-target');
        var mode     = btn.getAttribute('data-mlp-mode') || 'single';
        var input    = document.getElementById(targetId);
        if (!input) {
            console.warn('MediaPicker: no input with id "' + targetId + '"');
            return;
        }

        open({
            mode: mode,
            onPick: function (path, item) {
                if (mode === 'append') {
                    // For galleries — append a hidden input with the
                    // gallery's bracket-array name.
                    var nameAttr = input.getAttribute('data-mlp-array-name')
                                || input.getAttribute('name')
                                || '';
                    if (!nameAttr) return;
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = nameAttr;
                    hidden.value = path;
                    // Mark it so a future "remove" can find it.
                    hidden.setAttribute('data-mlp-appended', '1');
                    input.parentNode.insertBefore(hidden, input.nextSibling);

                    // Trigger an event the surrounding page can react to.
                    var ev = new CustomEvent('mlp:append', {
                        detail: {path: path, name: nameAttr}
                    });
                    input.dispatchEvent(ev);
                } else {
                    // Single: replace the input's value.
                    input.value = path;
                    input.dispatchEvent(new Event('change', {bubbles: true}));

                    // If there's a preview <img> with id="<targetId>-preview",
                    // update it. We use item.url (absolute) rather than
                    // path (relative-from-root) because the admin pages
                    // live at /admin/... and a relative /uploads/... path
                    // would otherwise resolve incorrectly in nested
                    // contexts.
                    var preview = document.getElementById(targetId + '-preview');
                    if (preview && preview.tagName === 'IMG') {
                        preview.src = item.url || path;
                        preview.style.display = '';
                    }
                }
            }
        });
    });

    // Expose to window for programmatic use.
    window.MediaPicker = {
        open: open,
        close: close,
        invalidateCache: invalidateCache,
    };

    // ──────────────────────────────────────────────────────────
    // SlateMedia — the modern core API any plugin can use.
    //
    //   SlateMedia.open({
    //     types:    'image' | 'document' | 'all'   (default 'all'),
    //     multiple: false,                          (true → gallery/append mode)
    //     onPick:   function (record) { ... }       (full media record)
    //   });
    //
    // The record is the full object from the API: {id, url, path, mime,
    // kind, original_name, width, height, size_bytes, in_use, ...}.
    // ──────────────────────────────────────────────────────────
    function slateOpen(opts) {
        opts = opts || {};
        var t = opts.types || opts.type || 'all';
        currentType = (t === 'image' || t === 'document') ? t : '';
        var cb = opts.onPick || null;
        open({
            mode: opts.multiple ? 'append' : 'single',
            onPick: function (path, item) {
                currentType = '';                // reset for subsequent opens
                if (cb) { try { cb(item); } catch (e) { console.error('SlateMedia onPick threw', e); } }
            }
        });
    }
    window.SlateMedia = {
        open: slateOpen,
        close: close,
        invalidateCache: invalidateCache,
    };
})();
