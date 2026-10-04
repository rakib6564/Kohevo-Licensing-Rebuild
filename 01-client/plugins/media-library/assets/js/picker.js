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
 * avoid re-hitting the API on every open. Call MediaPicker.invalidateCache()
 * if needed.
 */

(function () {
    'use strict';

    function deriveApiPath() {
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
            return '/plugins/media-library/admin/api.php';
        }
        var src = scriptEl.src.split('?')[0].split('#')[0];
        var suffix = '/assets/js/picker.js';
        var i2 = src.lastIndexOf(suffix);
        if (i2 === -1) return '/plugins/media-library/admin/api.php';
        return src.slice(0, i2) + '/admin/api.php';
    }

    var apiPath = deriveApiPath();
    var cacheByType = {};
    var cacheLoadedAt = {};
    var CACHE_TTL_MS = 60000;
    var currentType = '';

    // ──────────────────────────────────────────────────────────
    // Modal DOM (built once, reused)
    // ──────────────────────────────────────────────────────────

    var modalEl = null;
    var selectedItem = null;
    var currentMode = 'single';
    var currentCallback = null;
    var metaSaveTimeout = null;

    function buildModal() {
        if (modalEl) return modalEl;

        var overlay = document.createElement('div');
        overlay.className = 'mlp-overlay';
        overlay.innerHTML =
            '<div class="mlp-modal" role="dialog" aria-modal="true" aria-label="Media library">' +
              '<div class="mlp-header">' +
                '<div class="mlp-title">Select media</div>' +
                '<button type="button" class="mlp-close" aria-label="Close">' +
                  '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>' +
                '</button>' +
              '</div>' +
              '<div class="mlp-toolbar">' +
                '<label class="mlp-upload-label" title="Upload media">' +
                  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>' +
                  '<span>Upload</span>' +
                  '<input type="file" class="mlp-upload-input" accept="image/*,application/pdf" multiple style="display:none;">' +
                '</label>' +
                '<span class="mlp-upload-progress"></span>' +
                '<input type="search" class="mlp-search" placeholder="Search by filename, title, or alt text...">' +
              '</div>' +
              '<div class="mlp-content mlp-body">' +
                '<div class="mlp-main">' +
                  '<div class="mlp-loading">Loading...</div>' +
                  '<div class="mlp-grid" style="display:none;"></div>' +
                  '<div class="mlp-empty" style="display:none;">No media found.</div>' +
                '</div>' +
                '<div class="mlp-sidebar">' +
                  '<div class="mlp-sidebar-preview">' +
                    '<img src="" alt="" loading="lazy">' +
                  '</div>' +
                  '<div class="mlp-sidebar-info">' +
                    '<div class="mlp-sidebar-filename"></div>' +
                    '<div class="mlp-sidebar-meta-details"></div>' +
                  '</div>' +
                  '<div class="mlp-meta-group">' +
                    '<label class="mlp-meta-label">Alt text <span class="mlp-meta-hint">for SEO & accessibility</span></label>' +
                    '<input type="text" class="mlp-meta-input mlp-input-alt" placeholder="Describe image for screen readers...">' +
                  '</div>' +
                  '<div class="mlp-meta-group">' +
                    '<label class="mlp-meta-label">Title <span class="mlp-meta-hint">media title</span></label>' +
                    '<input type="text" class="mlp-meta-input mlp-input-title" placeholder="Image title...">' +
                  '</div>' +
                  '<div class="mlp-meta-group">' +
                    '<label class="mlp-meta-label">Description <span class="mlp-meta-hint">caption or notes</span></label>' +
                    '<textarea class="mlp-meta-input mlp-meta-textarea mlp-input-desc" placeholder="Image description or caption..."></textarea>' +
                  '</div>' +
                  '<div class="mlp-meta-save-status"></div>' +
                '</div>' +
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

        // Metadata fields input listeners (debounced auto-save)
        var altIn = overlay.querySelector('.mlp-input-alt');
        var titleIn = overlay.querySelector('.mlp-input-title');
        var descIn = overlay.querySelector('.mlp-input-desc');

        [altIn, titleIn, descIn].forEach(function (inp) {
            if (inp) inp.addEventListener('input', queueMetaSave);
        });

        // File upload input listener
        var fileInput = overlay.querySelector('.mlp-upload-input');
        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files.length) {
                handleUpload(fileInput.files);
                fileInput.value = '';
            }
        });

        // Drag and drop listeners on main container
        var mainEl = overlay.querySelector('.mlp-main');
        ['dragenter', 'dragover'].forEach(function (ev) {
            mainEl.addEventListener(ev, function (e) {
                e.preventDefault();
                e.stopPropagation();
                mainEl.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            mainEl.addEventListener(ev, function (e) {
                e.preventDefault();
                e.stopPropagation();
                mainEl.classList.remove('is-dragover');
            });
        });
        mainEl.addEventListener('drop', function (e) {
            var dt = e.dataTransfer;
            if (dt && dt.files && dt.files.length) {
                handleUpload(dt.files);
            }
        });

        return overlay;
    }

    function handleUpload(fileList) {
        if (!fileList || !fileList.length) return;
        var progressEl = modalEl.querySelector('.mlp-upload-progress');
        if (progressEl) {
            progressEl.style.display = 'inline-block';
            progressEl.style.color = '#4f46e5';
            progressEl.textContent = 'Uploading ' + fileList.length + ' file(s)...';
        }

        var uploads = [];
        for (var i = 0; i < fileList.length; i++) {
            (function (file) {
                var fd = new FormData();
                fd.append('file', file);
                uploads.push(
                    fetch(apiPath + '?action=upload', {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: fd
                    }).then(function (r) {
                        return r.json().then(function (d) {
                            if (!r.ok || !d.ok) throw new Error(d && d.error ? d.error : 'HTTP ' + r.status);
                            return d.item;
                        });
                    })
                );
            })(fileList[i]);
        }

        Promise.all(uploads).then(function (newItems) {
            if (progressEl) {
                progressEl.style.color = '#10b981';
                progressEl.textContent = 'Upload complete';
                setTimeout(function () {
                    if (progressEl && progressEl.textContent === 'Upload complete') {
                        progressEl.style.display = 'none';
                    }
                }, 2500);
            }

            var typeKey = currentType || '';
            if (!cacheByType[typeKey]) cacheByType[typeKey] = [];
            if (!cacheByType['']) cacheByType[''] = [];

            newItems.forEach(function (item) {
                cacheByType[typeKey].unshift(item);
                if (typeKey !== '') cacheByType[''].unshift(item);
            });

            var searchVal = modalEl.querySelector('.mlp-search').value;
            var filtered = filterItems(cacheByType[typeKey] || [], searchVal);
            renderGrid(filtered);

            // Auto-select the first uploaded item
            if (newItems.length > 0) {
                var firstCard = modalEl.querySelector('.mlp-grid .mlp-item');
                selectItem(newItems[0], firstCard);
            }
        }).catch(function (err) {
            console.error('MediaPicker upload error:', err);
            if (progressEl) {
                progressEl.style.color = '#ef4444';
                progressEl.textContent = 'Upload failed: ' + (err && err.message ? err.message : 'Error');
            }
        });
    }

    function queueMetaSave() {
        if (!selectedItem) return;
        var sidebar = modalEl ? modalEl.querySelector('.mlp-sidebar') : null;
        if (!sidebar) return;

        var altIn = sidebar.querySelector('.mlp-input-alt');
        var titleIn = sidebar.querySelector('.mlp-input-title');
        var descIn = sidebar.querySelector('.mlp-input-desc');
        var statusEl = sidebar.querySelector('.mlp-meta-save-status');

        if (altIn) selectedItem.alt_text = altIn.value;
        if (titleIn) selectedItem.title = titleIn.value;
        if (descIn) selectedItem.description = descIn.value;
        selectedItem.alt = selectedItem.alt_text;
        selectedItem.caption = selectedItem.description;

        if (statusEl) {
            statusEl.style.color = '#64748b';
            statusEl.textContent = 'Saving...';
        }

        clearTimeout(metaSaveTimeout);
        metaSaveTimeout = setTimeout(function () {
            flushMetaSave();
        }, 400);
    }

    function flushMetaSave() {
        clearTimeout(metaSaveTimeout);
        metaSaveTimeout = null;
        if (!selectedItem || !selectedItem.id) return;

        var sidebar = modalEl ? modalEl.querySelector('.mlp-sidebar') : null;
        var statusEl = sidebar ? sidebar.querySelector('.mlp-meta-save-status') : null;

        var fd = new FormData();
        fd.append('id', selectedItem.id);
        fd.append('alt_text', selectedItem.alt_text || '');
        fd.append('title', selectedItem.title || '');
        fd.append('description', selectedItem.description || '');

        fetch(apiPath + '?action=update_meta', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        }).then(function (r) {
            return r.json();
        }).then(function (data) {
            if (data && data.ok) {
                if (statusEl) {
                    statusEl.style.color = '#10b981';
                    statusEl.textContent = 'Saved';
                    setTimeout(function () {
                        if (statusEl && statusEl.textContent === 'Saved') statusEl.textContent = '';
                    }, 2000);
                }
            } else {
                if (statusEl) {
                    statusEl.style.color = '#ef4444';
                    statusEl.textContent = (data && data.error) ? data.error : 'Save failed';
                }
            }
        }).catch(function (err) {
            if (statusEl) {
                statusEl.style.color = '#ef4444';
                statusEl.textContent = 'Save failed';
            }
        });
    }

    function selectItem(it, card) {
        var grid = modalEl.querySelector('.mlp-grid');
        grid.querySelectorAll('.mlp-item').forEach(function (n) {
            n.classList.remove('is-selected');
        });
        if (card) card.classList.add('is-selected');
        selectedItem = it;
        modalEl.querySelector('.mlp-pick').disabled = false;

        var sidebar = modalEl.querySelector('.mlp-sidebar');
        if (!sidebar) return;
        sidebar.classList.add('is-active');

        var previewImg = sidebar.querySelector('.mlp-sidebar-preview img');
        if (previewImg) {
            previewImg.src = it.url || it.path || '';
            previewImg.alt = it.alt_text || '';
        }

        var filenameEl = sidebar.querySelector('.mlp-sidebar-filename');
        if (filenameEl) {
            filenameEl.textContent = it.original_name || it.filename || '';
            filenameEl.title = it.original_name || it.filename || '';
        }

        var detailsEl = sidebar.querySelector('.mlp-sidebar-meta-details');
        if (detailsEl) {
            var dim = (it.width && it.height) ? (it.width + ' × ' + it.height) : '';
            var kb = Math.round((it.size_bytes || 0) / 1024);
            var parts = [];
            if (dim) parts.push(dim);
            if (kb) parts.push(kb + ' KB');
            if (it.mime) parts.push(it.mime);
            detailsEl.textContent = parts.join(' · ');
        }

        var altIn = sidebar.querySelector('.mlp-input-alt');
        var titleIn = sidebar.querySelector('.mlp-input-title');
        var descIn = sidebar.querySelector('.mlp-input-desc');
        var statusEl = sidebar.querySelector('.mlp-meta-save-status');

        if (altIn) altIn.value = it.alt_text || '';
        if (titleIn) titleIn.value = it.title || '';
        if (descIn) descIn.value = it.description || '';
        if (statusEl) statusEl.textContent = '';
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

        var progressEl = modalEl.querySelector('.mlp-upload-progress');
        if (progressEl) progressEl.style.display = 'none';

        var sidebar = modalEl.querySelector('.mlp-sidebar');
        if (sidebar) sidebar.classList.remove('is-active');

        loadItems().then(function (items) {
            renderGrid(items);
        }).catch(function (err) {
            console.error('MediaPicker: API request failed', err, 'URL was:', apiPath);
            var body = modalEl.querySelector('.mlp-main') || modalEl.querySelector('.mlp-body');
            body.querySelector('.mlp-loading').style.display = 'none';
            body.querySelector('.mlp-grid').style.display = 'none';
            var empty = body.querySelector('.mlp-empty');
            empty.style.display = 'block';
            empty.textContent = 'Failed to load media: ' + (err && err.message || 'unknown error');
        });
    }

    function close() {
        if (metaSaveTimeout) flushMetaSave();
        if (modalEl) {
            modalEl.classList.remove('is-open');
            var sidebar = modalEl.querySelector('.mlp-sidebar');
            if (sidebar) sidebar.classList.remove('is-active');
        }
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
            var name = (it.original_name || '').toLowerCase();
            var alt = (it.alt_text || '').toLowerCase();
            var title = (it.title || '').toLowerCase();
            var desc = (it.description || '').toLowerCase();
            return name.indexOf(query) !== -1 ||
                   alt.indexOf(query) !== -1 ||
                   title.indexOf(query) !== -1 ||
                   desc.indexOf(query) !== -1;
        });
    }

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
            .replace(/'/g, '&lt;')
            .replace(/>/g, '&gt;');
    }
    function truncate(s, n) {
        s = String(s == null ? '' : s);
        if (s.length <= n) return s;
        return s.slice(0, n - 1) + '\u2026';
    }

    function renderGrid(items) {
        var main = modalEl.querySelector('.mlp-main');
        var loading = main.querySelector('.mlp-loading');
        var grid = main.querySelector('.mlp-grid');
        var empty = main.querySelector('.mlp-empty');
        var status = modalEl.querySelector('.mlp-status');

        loading.style.display = 'none';

        if (!items.length) {
            grid.style.display = 'none';
            empty.style.display = 'block';
            empty.textContent = 'No media found.';
            status.textContent = '';
            var sidebar = modalEl.querySelector('.mlp-sidebar');
            if (sidebar) sidebar.classList.remove('is-active');
            modalEl.querySelector('.mlp-pick').disabled = true;
            return;
        }
        empty.style.display = 'none';
        grid.style.display = 'grid';
        status.textContent = items.length + ' item(s)';

        grid.innerHTML = '';
        items.forEach(function (it) {
            var card = document.createElement('div');
            card.className = 'mlp-item' + (selectedItem && selectedItem.id === it.id ? ' is-selected' : '');
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
                selectItem(it, card);
            });
            card.addEventListener('dblclick', function () {
                selectItem(it, card);
                commitPick(selectedItem);
                close();
            });
            grid.appendChild(card);
        });
    }

    function commitPick(item) {
        if (!item) return;

        var sidebar = modalEl ? modalEl.querySelector('.mlp-sidebar') : null;
        if (sidebar && sidebar.classList.contains('is-active')) {
            var altIn = sidebar.querySelector('.mlp-input-alt');
            var titleIn = sidebar.querySelector('.mlp-input-title');
            var descIn = sidebar.querySelector('.mlp-input-desc');
            if (altIn) item.alt_text = altIn.value;
            if (titleIn) item.title = titleIn.value;
            if (descIn) item.description = descIn.value;
        }

        item.alt = item.alt_text || '';
        item.caption = item.description || '';

        flushMetaSave();

        if (currentCallback) {
            try { currentCallback(item.path, item); }
            catch (e) { console.error('MediaPicker callback threw', e); }
        }
    }

    // ──────────────────────────────────────────────────────────
    // Auto-wire data-attribute triggers
    // ──────────────────────────────────────────────────────────

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
                    var nameAttr = input.getAttribute('data-mlp-array-name')
                                || input.getAttribute('name')
                                || '';
                    if (!nameAttr) return;
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = nameAttr;
                    hidden.value = path;
                    hidden.setAttribute('data-mlp-appended', '1');
                    input.parentNode.insertBefore(hidden, input.nextSibling);

                    var ev = new CustomEvent('mlp:append', {
                        detail: {path: path, name: nameAttr, item: item}
                    });
                    input.dispatchEvent(ev);
                } else {
                    input.value = path;
                    input.dispatchEvent(new Event('change', {bubbles: true}));

                    // Sync alt tag input if present
                    var altTargetId = btn.getAttribute('data-mlp-alt-target');
                    var altInput = altTargetId ? document.getElementById(altTargetId) : (document.getElementById(targetId + '_alt') || document.getElementById(targetId + '-alt'));
                    if (altInput && (item.alt_text || item.alt)) {
                        altInput.value = item.alt_text || item.alt || '';
                        altInput.dispatchEvent(new Event('change', {bubbles: true}));
                    }

                    // Sync title input if present
                    var titleTargetId = btn.getAttribute('data-mlp-title-target');
                    var titleInput = titleTargetId ? document.getElementById(titleTargetId) : (document.getElementById(targetId + '_title') || document.getElementById(targetId + '-title'));
                    if (titleInput && item.title) {
                        titleInput.value = item.title || '';
                        titleInput.dispatchEvent(new Event('change', {bubbles: true}));
                    }

                    // Sync preview image if present
                    var preview = document.getElementById(targetId + '-preview') || document.getElementById(targetId + '_preview');
                    if (preview && preview.tagName === 'IMG') {
                        preview.src = item.url || path;
                        if (item.alt_text) preview.alt = item.alt_text;
                        if (item.title) preview.title = item.title;
                        preview.style.display = '';
                    }
                }
            }
        });
    });

    window.MediaPicker = {
        open: open,
        close: close,
        invalidateCache: invalidateCache,
    };

    function slateOpen(opts) {
        opts = opts || {};
        var t = opts.types || opts.type || 'all';
        currentType = (t === 'image' || t === 'document') ? t : '';
        var cb = opts.onPick || null;
        open({
            mode: opts.multiple ? 'append' : 'single',
            onPick: function (path, item) {
                currentType = '';
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
