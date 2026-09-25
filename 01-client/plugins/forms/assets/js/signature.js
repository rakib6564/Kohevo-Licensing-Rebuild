/**
 * Slate Forms — signature pad.
 *
 * Progressive enhancement for every <div data-sig> rendered by
 * FormsAPI::renderField(). Two modes:
 *   - Draw: freehand on a <canvas> with mouse or finger.
 *   - Type: render a typed name into the same canvas in a script face.
 *
 * Either way the canvas is serialized to a PNG data URL and written into
 * the hidden [data-sig-value] input, which the server decodes and stores.
 * No external dependencies.
 */
(function () {
    'use strict';

    function initPad(pad) {
        var canvas  = pad.querySelector('.sig-canvas');
        var valueEl = pad.querySelector('[data-sig-value]');
        var modeEl  = pad.querySelector('[data-sig-mode]');
        var nameEl  = pad.querySelector('[data-sig-name]');
        var typeIn  = pad.querySelector('.sig-type-input');
        var clearBt = pad.querySelector('[data-sig-clear]');
        var modeBts = pad.querySelectorAll('[data-sig-mode-btn]');
        if (!canvas || !valueEl) return;

        var ctx     = canvas.getContext('2d');
        var mode    = 'draw';
        var hasInk  = false;
        var drawing = false;
        var last    = null;

        ctx.lineWidth   = 2.5;
        ctx.lineCap     = 'round';
        ctx.lineJoin    = 'round';
        ctx.strokeStyle = '#111827';

        pad.classList.add('mode-draw');

        function clearCanvas() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }

        // Map a pointer event to canvas-pixel coordinates (the canvas is
        // displayed at a CSS size that differs from its backing resolution).
        function posOf(e) {
            var r  = canvas.getBoundingClientRect();
            var pt = (e.touches && e.touches[0]) ? e.touches[0] : e;
            return {
                x: (pt.clientX - r.left) * (canvas.width  / r.width),
                y: (pt.clientY - r.top)  * (canvas.height / r.height)
            };
        }

        function commit() {
            if (hasInk) {
                valueEl.value = canvas.toDataURL('image/png');
            } else {
                valueEl.value = '';
            }
            modeEl.value = mode;
            if (nameEl) nameEl.value = (mode === 'type' && typeIn) ? typeIn.value.trim() : '';
            pad.classList.toggle('is-empty', !hasInk);
            // Clear the "required" error as soon as something is signed. (commit
            // sets valueEl.value directly, which doesn't fire a change event, so
            // we can't rely on the change listener below.)
            if (hasInk) pad.classList.remove('sig-invalid');
        }

        // ---- Draw mode ----
        function startDraw(e) {
            if (mode !== 'draw') return;
            drawing = true;
            last = posOf(e);
            e.preventDefault();
        }
        function moveDraw(e) {
            if (!drawing || mode !== 'draw') return;
            var p = posOf(e);
            ctx.beginPath();
            ctx.moveTo(last.x, last.y);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            last = p;
            hasInk = true;
            e.preventDefault();
        }
        function endDraw() {
            if (!drawing) return;
            drawing = false;
            commit();
        }

        canvas.addEventListener('mousedown', startDraw);
        canvas.addEventListener('mousemove', moveDraw);
        window.addEventListener('mouseup', endDraw);
        canvas.addEventListener('touchstart', startDraw, { passive: false });
        canvas.addEventListener('touchmove', moveDraw, { passive: false });
        canvas.addEventListener('touchend', endDraw);

        // ---- Type mode ----
        function renderTyped() {
            clearCanvas();
            var text = typeIn ? typeIn.value.trim() : '';
            if (!text) { hasInk = false; commit(); return; }
            ctx.fillStyle    = '#111827';
            ctx.textBaseline = 'middle';
            ctx.textAlign    = 'center';
            // Shrink the face until the name fits the pad width.
            var size = 64;
            do {
                ctx.font = '600 ' + size + 'px "Segoe Script","Brush Script MT","Snell Roundhand",cursive';
                size -= 4;
            } while (ctx.measureText(text).width > canvas.width - 40 && size > 16);
            // Draw a touch below centre so the script clears the name input at the top.
            ctx.fillText(text, canvas.width / 2, canvas.height * 0.62);
            hasInk = true;
            commit();
        }
        if (typeIn) typeIn.addEventListener('input', renderTyped);

        // ---- Mode switch ----
        function setMode(next) {
            mode = next;
            pad.classList.toggle('mode-type', next === 'type');
            pad.classList.toggle('mode-draw', next !== 'type');
            Array.prototype.forEach.call(modeBts, function (b) {
                b.classList.toggle('is-active', b.getAttribute('data-sig-mode-btn') === next);
            });
            // Slide the segmented-toggle indicator. The CSS reads this attr
            // on `.sig-seg` and translates the white pill to the active half.
            var seg = pad.querySelector('.sig-seg');
            if (seg) seg.setAttribute('data-sig-active', next);
            if (typeIn) typeIn.hidden = (next !== 'type');
            clearCanvas();
            hasInk = false;
            if (next === 'type') { renderTyped(); typeIn && typeIn.focus(); }
            else { commit(); }
        }
        Array.prototype.forEach.call(modeBts, function (b) {
            b.addEventListener('click', function () { setMode(b.getAttribute('data-sig-mode-btn')); });
        });

        // ---- Clear ----
        if (clearBt) clearBt.addEventListener('click', function () {
            clearCanvas();
            if (typeIn) typeIn.value = '';
            hasInk = false;
            commit();
        });

        // ---- Upload ----
        // Read a PNG/JPEG, fit it inside the canvas, and treat the result as
        // the drawn signature. Snaps to Draw mode so the value pipeline is
        // identical to a mouse/finger drawing.
        var upIn = pad.querySelector('.sig-upload-input');
        if (upIn) upIn.addEventListener('change', function () {
            var file = upIn.files && upIn.files[0]; if (!file) return;
            var rd = new FileReader();
            rd.onload = function () {
                var img = new Image();
                img.onload = function () {
                    setMode('draw');
                    clearCanvas();
                    var r = Math.min(canvas.width / img.width, canvas.height / img.height);
                    var w = img.width * r, h = img.height * r;
                    ctx.drawImage(img, (canvas.width - w) / 2, (canvas.height - h) / 2, w, h);
                    hasInk = true;
                    commit();
                };
                img.src = rd.result;
            };
            rd.readAsDataURL(file);
            upIn.value = '';   // reset so re-selecting the same file refires change
        });

        // ---- Required guard on submit ----
        var form = pad.closest('form');
        if (form && valueEl.hasAttribute('data-sig-required')) {
            form.addEventListener('submit', function (e) {
                if (!valueEl.value) {
                    e.preventDefault();
                    pad.classList.add('sig-invalid');
                    pad.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }
            });
            valueEl.addEventListener('change', function () { pad.classList.remove('sig-invalid'); });
        }

        // Restore a value the server echoed back (e.g. after another field
        // failed validation) so the signature survives the round-trip.
        if (valueEl.value && valueEl.value.indexOf('data:image/png') === 0) {
            var img = new Image();
            img.onload = function () {
                clearCanvas();
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                hasInk = true;
                pad.classList.remove('is-empty');
            };
            img.src = valueEl.value;
        } else {
            pad.classList.add('is-empty');
        }
    }

    function boot() {
        var pads = document.querySelectorAll('[data-sig]');
        Array.prototype.forEach.call(pads, function (p) {
            if (!p.dataset.sigReady) { p.dataset.sigReady = '1'; initPad(p); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
