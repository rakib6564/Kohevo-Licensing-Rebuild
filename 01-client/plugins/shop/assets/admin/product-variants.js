/* Shop — variants accordion (admin product form).
 *
 * The rows are native <details>, so they open and close without this script. It adds: Expand all / Collapse all, and
 * keeps each row's summary (photo, price, stock, Not sold) in step with what is typed inside it. Nothing here touches
 * what is submitted: every input keeps its name and stays in the form whether its row is open or closed.
 */
(function () {
    'use strict';

    function ready(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

    ready(function () {
        var root = document.querySelector('[data-shop-vlist]');
        if (!root) return;
        var base = root.getAttribute('data-base') || '';
        var pos = root.getAttribute('data-pos') === 'after' ? 'after' : 'before';
        var sym = root.getAttribute('data-sym') || '';
        var labelStock = root.getAttribute('data-label-stock') || 'Stock';
        var untracked = root.getAttribute('data-label-untracked') || '';
        var lang = document.documentElement.getAttribute('lang') || undefined;

        function all() { return root.querySelectorAll('details'); }
        root.querySelectorAll('[data-vl]').forEach(function (b) {
            b.addEventListener('click', function () {
                var open = b.getAttribute('data-vl') === 'open';
                all().forEach(function (d) { d.open = open; });
            });
        });

        function money(text) {
            var n = parseFloat(String(text).replace(',', '.').replace(/[^0-9.\-]/g, ''));
            if (isNaN(n)) return null;
            var amount;
            try { amount = new Intl.NumberFormat(lang, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n); }
            catch (e) { amount = n.toFixed(2); }
            return pos === 'after' ? amount + ' ' + sym : sym + amount;
        }

        function imgSrc(v) {
            v = (v || '').trim();
            return /^\/(?!\/)/.test(v) ? base + v : v;
        }

        root.querySelectorAll('[data-shop-variant]').forEach(function (row) {
            var inputs = {};
            row.querySelectorAll('[data-vs-in]').forEach(function (i) { inputs[i.getAttribute('data-vs-in')] = i; });
            var chip = function (k) { return row.querySelector('[data-vs="' + k + '"]'); };
            var thumb = row.querySelector('[data-vs-thumb]');

            function refresh() {
                var price = chip('price'), stock = chip('stock'), off = chip('off');
                if (price && inputs.price) {
                    var m = money(inputs.price.value !== '' ? inputs.price.value : inputs.price.placeholder);
                    if (m) price.textContent = m;
                }
                if (stock && inputs.stock) {
                    stock.textContent = inputs.stock.value === '' ? untracked : labelStock + ': ' + inputs.stock.value;
                }
                if (off && inputs.enabled) off.hidden = inputs.enabled.checked;
                if (thumb && inputs.image) {
                    var src = imgSrc(inputs.image.value);
                    var cur = thumb.querySelector('img');
                    if (!src) { if (cur) cur.remove(); }
                    else if (cur) { if (cur.getAttribute('src') !== src) cur.setAttribute('src', src); }
                    else { var im = document.createElement('img'); im.alt = ''; im.src = src; thumb.appendChild(im); }
                }
            }
            row.addEventListener('input', refresh);
            row.addEventListener('change', refresh);
        });

        // A field that fails validation on save is in a closed row: open it so the message is not hidden.
        document.addEventListener('invalid', function (e) {
            var d = e.target && e.target.closest ? e.target.closest('details') : null;
            while (d) { d.open = true; d = d.parentElement ? d.parentElement.closest('details') : null; }
        }, true);
    });
})();
