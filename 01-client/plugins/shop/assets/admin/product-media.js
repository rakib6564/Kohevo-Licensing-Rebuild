/* Shop — product image manager (admin product form).
 *
 * The "Image addresses" textarea (name="images", one address per line, the first is the product image) stays the
 * only field that is saved. This script draws the product image and the gallery from it and writes every change back:
 * choose, upload or add several at once from the Media library (window.SlateMedia), make product image, remove,
 * drag to reorder (or the arrow buttons, for the keyboard). Each variant's image field gets a thumbnail and a
 * Choose button. Without this script, or without the Media library, the textarea works as before.
 */
(function () {
    'use strict';

    function ready(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

    ready(function () {
        var root = document.querySelector('[data-shop-media]');
        if (!root) return;
        var ta = root.querySelector('textarea[name="images"]');
        var ui = root.querySelector('.shop-media-ui');
        if (!ta || !ui) return;

        var base = root.getAttribute('data-base') || '';
        var max = parseInt(root.getAttribute('data-max'), 10) || 12;
        var L = {};
        try { L = JSON.parse(root.getAttribute('data-labels') || '{}'); } catch (e) { L = {}; }
        var picker = root.getAttribute('data-picker') === '1' && !!window.SlateMedia;

        /* ── the list, kept in the textarea ── */
        function list() {
            return ta.value.split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
        }
        function save(arr) {
            ta.value = arr.join('\n');
            ta.dispatchEvent(new Event('change', { bubbles: true }));
            draw();
        }
        // A library path as the Shop stores it: a site path with one leading slash, or an http(s) address as it is.
        function norm(path) {
            path = String(path || '').trim();
            if (/^https?:\/\//i.test(path)) return path;
            return '/' + path.replace(/^\/+/, '');
        }
        function src(u) { return /^\/(?!\/)/.test(u) ? base + u : u; }

        function el(tag, cls, text) {
            var n = document.createElement(tag);
            if (cls) n.className = cls;
            if (text != null) n.textContent = text;
            return n;
        }
        function btn(cls, label, icon, onClick) {
            var b = el('button', 'shop-media-btn ' + cls);
            b.type = 'button';
            b.title = label;
            b.setAttribute('aria-label', label);
            b.innerHTML = icon;
            b.addEventListener('click', function (e) { e.preventDefault(); onClick(); });
            return b;
        }
        var ICON = {
            x: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
            star: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/></svg>',
            left: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>',
            right: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>',
            plus: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
            image: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/></svg>'
        };
        function img(u) {
            var i = el('img');
            i.src = src(u);
            i.alt = '';
            i.loading = 'lazy';
            i.addEventListener('error', function () {
                var miss = el('span', 'shop-media-missing', L.broken || 'Image not found');
                miss.title = u;
                if (i.parentNode) i.parentNode.replaceChild(miss, i);
            });
            return i;
        }

        /* ── picking ── */
        function pickMany() {
            if (!picker) return;
            window.SlateMedia.open({ types: 'image', multiple: true, onPick: function (item) {
                var arr = list();
                var p = norm(item.path || item.url);
                if (arr.indexOf(p) !== -1) return;
                if (arr.length >= max) { warn(L.full); return; }
                arr.push(p);
                save(arr);
            } });
        }
        function pickMain() {
            if (!picker) return;
            window.SlateMedia.open({ types: 'image', onPick: function (item) {
                var p = norm(item.path || item.url);
                var arr = list();
                var at = arr.indexOf(p);
                if (at === 0) return;
                if (at > 0) { arr.splice(at, 1); arr.unshift(p); }   // a gallery photo becomes the product image
                else if (arr.length) arr[0] = p;                       // replace the product image
                else arr.push(p);                                      // the first image
                save(arr);
            } });
        }
        var warnEl = null;
        function warn(msg) {
            if (!msg) return;
            if (!warnEl) { warnEl = el('div', 'field-error'); warnEl.setAttribute('role', 'alert'); ui.appendChild(warnEl); }
            warnEl.textContent = msg;
        }

        function move(from, to) {
            var arr = list();
            if (to < 0 || to >= arr.length || from === to) return;
            var it = arr.splice(from, 1)[0];
            arr.splice(to, 0, it);
            save(arr);
        }
        function removeAt(i) {
            var arr = list();
            arr.splice(i, 1);
            save(arr);
        }

        /* ── drawing ── */
        var dragFrom = -1;
        function draw() {
            var arr = list();
            ui.innerHTML = '';
            warnEl = null;
            ui.hidden = false;

            // Product image
            var mainCol = el('div', 'shop-media-main');
            mainCol.appendChild(el('div', 'shop-media-label', L.main || 'Product image'));
            var slot = el('div', 'shop-media-slot' + (arr.length ? '' : ' is-empty'));
            slot.setAttribute('data-index', '0');
            if (arr.length) {
                slot.appendChild(img(arr[0]));
            } else {
                var empty = el('div', 'shop-media-empty');
                empty.innerHTML = ICON.image;
                empty.appendChild(el('span', '', L.empty || 'No image yet'));
                slot.appendChild(empty);
            }
            dropTarget(slot, 0);
            mainCol.appendChild(slot);
            var acts = el('div', 'shop-media-actions');
            if (picker) {
                var choose = el('button', 'btn btn-sm', arr.length ? (L.replace || 'Replace') : (L.choose || 'Choose image'));
                choose.type = 'button';
                choose.addEventListener('click', function (e) { e.preventDefault(); pickMain(); });
                acts.appendChild(choose);
            }
            if (arr.length) {
                var rm = el('button', 'btn btn-sm btn-ghost', L.remove || 'Remove');
                rm.type = 'button';
                rm.addEventListener('click', function (e) { e.preventDefault(); removeAt(0); });
                acts.appendChild(rm);
            }
            mainCol.appendChild(acts);
            mainCol.appendChild(el('div', 'field-hint', L.main_hint || ''));
            ui.appendChild(mainCol);

            // Gallery
            var galCol = el('div', 'shop-media-gallery');
            galCol.appendChild(el('div', 'shop-media-label', (L.gallery || 'Gallery') + (arr.length > 1 ? ' (' + (arr.length - 1) + ')' : '')));
            var grid = el('div', 'shop-media-grid');
            arr.forEach(function (u, i) {
                if (i === 0) return;
                var tile = el('div', 'shop-media-tile');
                tile.draggable = true;
                tile.setAttribute('data-index', String(i));
                tile.appendChild(img(u));
                var bar = el('div', 'shop-media-tilebar');
                bar.appendChild(btn('is-move', L.left || 'Move earlier', ICON.left, function () { move(i, i - 1); }));
                bar.appendChild(btn('is-main', L.make_main || 'Make product image', ICON.star, function () { move(i, 0); }));
                bar.appendChild(btn('is-move', L.right || 'Move later', ICON.right, function () { move(i, i + 1); }));
                bar.appendChild(btn('is-remove', L.remove || 'Remove', ICON.x, function () { removeAt(i); }));
                tile.appendChild(bar);
                tile.addEventListener('dragstart', function (e) {
                    dragFrom = i;
                    tile.classList.add('is-dragging');
                    try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(i)); } catch (x) {}
                });
                tile.addEventListener('dragend', function () { tile.classList.remove('is-dragging'); dragFrom = -1; });
                dropTarget(tile, i);
                grid.appendChild(tile);
            });
            if (picker && arr.length < max) {
                var add = el('button', 'shop-media-add');
                add.type = 'button';
                add.innerHTML = ICON.plus;
                add.appendChild(el('span', '', L.add || 'Add images'));
                add.addEventListener('click', function (e) { e.preventDefault(); pickMany(); });
                grid.appendChild(add);
            }
            galCol.appendChild(grid);
            galCol.appendChild(el('div', 'field-hint', L.gallery_hint || ''));
            ui.appendChild(galCol);
        }
        function dropTarget(node, index) {
            node.addEventListener('dragover', function (e) {
                if (dragFrom < 0) return;
                e.preventDefault();
                node.classList.add('is-over');
            });
            node.addEventListener('dragleave', function () { node.classList.remove('is-over'); });
            node.addEventListener('drop', function (e) {
                e.preventDefault();
                node.classList.remove('is-over');
                if (dragFrom >= 0) move(dragFrom, index);
                dragFrom = -1;
            });
        }

        ta.addEventListener('input', draw);
        draw();

        /* ── variant images: thumbnail + Choose ── */
        document.querySelectorAll('input[data-shop-variant-image]').forEach(function (input) {
            var wrap = el('div', 'shop-variant-img');
            input.parentNode.insertBefore(wrap, input);
            var thumb = el('span', 'shop-variant-thumb');
            wrap.appendChild(thumb);
            wrap.appendChild(input);
            function show() {
                thumb.innerHTML = '';
                var v = input.value.trim();
                if (v) thumb.appendChild(img(v));
            }
            if (picker) {
                var b = el('button', 'btn btn-sm', L.choose || 'Choose image');
                b.type = 'button';
                b.setAttribute('aria-label', (L.choose || 'Choose image') + ' — ' + input.getAttribute('data-shop-variant-image'));
                b.addEventListener('click', function (e) {
                    e.preventDefault();
                    window.SlateMedia.open({ types: 'image', onPick: function (item) { input.value = norm(item.path || item.url); show(); input.dispatchEvent(new Event('input', { bubbles: true })); } });
                });
                wrap.appendChild(b);
            }
            input.addEventListener('input', show);
            show();
        });
    });
})();
