(function () {
    document.addEventListener('click', function (e) {
        var toggle = e.target.closest('.mlt-switcher-toggle');
        var open = document.querySelector('.mlt-switcher.is-open');
        if (toggle) {
            var widget = toggle.closest('.mlt-switcher');
            if (widget.dataset.justDragged === '1') { widget.dataset.justDragged = ''; return; }
            if (open && open !== widget) open.classList.remove('is-open');
            widget.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', widget.classList.contains('is-open') ? 'true' : 'false');
            return;
        }
        if (open && !e.target.closest('.mlt-switcher')) open.classList.remove('is-open');
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var open = document.querySelector('.mlt-switcher.is-open');
            if (open) open.classList.remove('is-open');
        }
    });

    // ── Drag to reposition — same mechanic as the AI assistant widget:
    // free placement anywhere on screen, remembered per browser, with a
    // small movement threshold so a plain click still opens the dropdown
    // instead of being swallowed as an accidental drag. Works for both an
    // admin viewing the dashboard and a frontend/customer-facing visitor —
    // whoever is looking at the page can drag it to wherever suits them;
    // the site-wide switcher_position setting is just the starting point. ──
    function initDrag(widget) {
        var toggle = widget.querySelector('.mlt-switcher-toggle');
        if (!toggle) return;
        var POS_KEY = 'mlt_switcher_pos';
        var dragging = false, moved = false, startX = 0, startY = 0, startLeft = 0, startTop = 0;

        function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

        function applyPos(left, top) {
            var w = widget.offsetWidth || 46, h = widget.offsetHeight || 34;
            left = clamp(left, 6, window.innerWidth - w - 6);
            top  = clamp(top, 6, window.innerHeight - h - 6);
            widget.style.left = left + 'px';
            widget.style.top  = top + 'px';
            widget.style.right = 'auto';
            widget.style.bottom = 'auto';
        }

        try {
            var saved = JSON.parse(localStorage.getItem(POS_KEY) || 'null');
            if (saved && typeof saved.left === 'number') applyPos(saved.left, saved.top);
        } catch (e) {}

        function pointerXY(e) {
            if (e.touches && e.touches.length) return { x: e.touches[0].clientX, y: e.touches[0].clientY };
            return { x: e.clientX, y: e.clientY };
        }

        function dragStart(e) {
            dragging = true; moved = false;
            var p = pointerXY(e);
            startX = p.x; startY = p.y;
            var r = widget.getBoundingClientRect();
            startLeft = r.left; startTop = r.top;
        }
        function dragMove(e) {
            if (!dragging) return;
            var p = pointerXY(e);
            var dx = p.x - startX, dy = p.y - startY;
            if (Math.abs(dx) > 4 || Math.abs(dy) > 4) moved = true;
            if (moved) {
                e.preventDefault();
                widget.classList.remove('is-open'); // don't drag a widget with its menu hanging open
                applyPos(startLeft + dx, startTop + dy);
            }
        }
        function dragEnd() {
            if (!dragging) return;
            dragging = false;
            if (moved) {
                widget.dataset.justDragged = '1'; // suppress the click-to-toggle this same gesture would otherwise fire
                var r = widget.getBoundingClientRect();
                try { localStorage.setItem(POS_KEY, JSON.stringify({ left: r.left, top: r.top })); } catch (e) {}
            }
        }

        toggle.addEventListener('mousedown', dragStart);
        document.addEventListener('mousemove', dragMove);
        document.addEventListener('mouseup', dragEnd);
        toggle.addEventListener('touchstart', dragStart, { passive: true });
        document.addEventListener('touchmove', dragMove, { passive: false });
        document.addEventListener('touchend', dragEnd);
    }

    document.querySelectorAll('.mlt-switcher').forEach(initDrag);
})();
