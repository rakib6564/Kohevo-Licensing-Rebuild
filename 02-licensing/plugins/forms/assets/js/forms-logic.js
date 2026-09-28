/**
 * Slate Forms — public-form logic engine.
 *
 * Progressive enhancement for advanced field behaviour on the live form:
 *   - Conditional visibility  (fields with [data-showif])
 *   - Calculated fields       (fields with [data-formula])   — Phase 3
 *   - Multi-step / wizard      (.forms-steps)                — Phase 2
 *
 * No dependencies. Degrades gracefully: with JS off, every field is shown
 * (the server still skips validation for unmet conditions).
 */
(function () {
    'use strict';

    var form = document.querySelector('.forms-public-card form') || document.querySelector('form');
    if (!form) return;

    var doAnimate  = form.getAttribute('data-animate')  !== '0';
    var doValidate = form.getAttribute('data-validate') !== '0';

    function esc(name) {
        return (window.CSS && CSS.escape) ? CSS.escape(name) : String(name).replace(/"/g, '\\"');
    }

    // Current value of a named field (handles radio groups + checkboxes).
    function fieldValue(name) {
        var els = form.querySelectorAll('[name="' + esc(name) + '"]');
        if (!els.length) return '';
        var el = els[0];
        if (el.type === 'radio') {
            var c = form.querySelector('[name="' + esc(name) + '"]:checked');
            return c ? c.value : '';
        }
        if (el.type === 'checkbox') return el.checked ? (el.value || '1') : '';
        return el.value || '';
    }

    // ── Conditional visibility ──────────────────────────────────
    function conditionMet(c) {
        var cur = String(fieldValue(c.field) || '').trim();
        var v   = String(c.value || '').trim();
        switch (c.op) {
            case 'eq':     return cur === v;
            case 'ne':     return cur !== v;
            case 'filled': return cur !== '';
            case 'empty':  return cur === '';
            case 'gt':     return parseFloat(cur) >  parseFloat(v);
            case 'lt':     return parseFloat(cur) <  parseFloat(v);
        }
        return true;
    }

    var conditional = [];
    Array.prototype.forEach.call(form.querySelectorAll('[data-showif]'), function (el) {
        var c; try { c = JSON.parse(el.getAttribute('data-showif')); } catch (e) { return; }
        if (c && c.field) conditional.push({ el: el, cond: c });
    });

    function applyConditions() {
        conditional.forEach(function (item) {
            var show = conditionMet(item.cond);
            item.el.hidden = !show;
            // Disabled inputs aren't submitted or natively validated, so a
            // hidden required field can never block the submit.
            Array.prototype.forEach.call(item.el.querySelectorAll('input, select, textarea'), function (inp) {
                inp.disabled = !show;
            });
        });
    }

    // ── Calculated fields ───────────────────────────────────────
    var calcInputs = Array.prototype.slice.call(form.querySelectorAll('input[data-formula]'));

    function evalFormula(formula) {
        // Replace {field} refs with numeric values, then evaluate a guarded
        // arithmetic expression (only digits and + - * / ( ) remain).
        var expr = String(formula || '').replace(/\{([a-z0-9_]+)\}/gi, function (_, n) {
            var v = parseFloat(fieldValue(n)); return isFinite(v) ? v : 0;
        });
        if (!/^[0-9+\-*/().\s]*$/.test(expr) || expr.trim() === '') return null;
        try {
            var r = Function('"use strict";return (' + expr + ')')();
            return (typeof r === 'number' && isFinite(r)) ? r : null;
        } catch (e) { return null; }
    }

    function runCalc() {
        calcInputs.forEach(function (inp) {
            var r = evalFormula(inp.getAttribute('data-formula'));
            var val = (r === null) ? '' : (Math.round(r * 100) / 100);
            inp.value = val;
            var out = inp.parentNode && inp.parentNode.querySelector('[data-calc-out]');
            if (out) out.textContent = (val === '' ? '—' : val);
        });
    }

    // ── Wire up ─────────────────────────────────────────────────
    function recompute() {
        applyConditions();
        runCalc();
    }
    form.addEventListener('input', recompute);
    form.addEventListener('change', recompute);

    // ── Scroll-fade hint ────────────────────────────────────────
    // Fade the top/bottom edge of a capped field region when more content lies
    // beyond it (the scrollbar itself is hidden). Classes are styled in CSS.
    function applyScrollFade(box) {
        if (!box) return;
        var can = (box.scrollHeight - box.clientHeight) > 2;
        box.classList.toggle('sf-top', can && box.scrollTop > 1);
        box.classList.toggle('sf-bottom', can && (box.scrollTop + box.clientHeight) < (box.scrollHeight - 1));
    }

    // ── Multi-step wizard (directional slide + staggered entrance) ─
    var wrap = form.querySelector('[data-forms-steps]');
    if (wrap) {
        var stepsEls  = Array.prototype.slice.call(wrap.querySelectorAll('[data-forms-step]'));
        var total     = stepsEls.length;
        var backBtn   = wrap.querySelector('[data-forms-back]');
        var nextBtn   = wrap.querySelector('[data-forms-next]');
        var submitBtn = wrap.querySelector('[data-forms-submit]');
        var numEl     = wrap.querySelector('[data-forms-stepnum]');
        var titleEl   = wrap.querySelector('[data-forms-steptitle]');
        var ring      = wrap.querySelector('[data-forms-ring]');
        var wiztop    = wrap.querySelector('.forms-wiztop');
        var progress  = Array.prototype.slice.call(wrap.querySelectorAll('[data-forms-progress]'));
        var railItems = Array.prototype.slice.call(wrap.querySelectorAll('[data-rail]'));
        var flowSegs  = Array.prototype.slice.call(wrap.querySelectorAll('.forms-flowseg'));
        var fieldWraps = stepsEls.map(function (s) { return s.querySelector('.forms-step-fields'); });
        var cur = 0;

        // Fade the current step's scroll edges when its fields overflow.
        var updateScrollCue = function (i) { applyScrollFade(fieldWraps[i]); };
        fieldWraps.forEach(function (box, i) {
            if (box) box.addEventListener('scroll', function () { updateScrollCue(i); }, { passive: true });
        });
        window.addEventListener('resize', function () { updateScrollCue(cur); });

        var animate = function (el, dir) {
            if (!doAnimate) return;
            el.classList.remove('anim-next', 'anim-prev');
            void el.offsetWidth;                        // force reflow → restart animation
            el.classList.add(dir === 'prev' ? 'anim-prev' : 'anim-next');
            Array.prototype.forEach.call(el.children, function (c, k) {
                c.style.animationDelay = Math.min(k * 45, 340) + 'ms';
            });
        };

        var showStep = function (i, dir) {
            cur = Math.max(0, Math.min(total - 1, i));
            stepsEls.forEach(function (s, j) { s.hidden = (j !== cur); });
            if (backBtn)   backBtn.hidden   = (cur === 0);
            if (nextBtn)   nextBtn.hidden   = (cur === total - 1);
            if (submitBtn) submitBtn.hidden = (cur !== total - 1);
            if (numEl)     numEl.textContent = (cur + 1);
            var stepTitle = stepsEls[cur].getAttribute('data-step-title') || '';
            if (titleEl)   titleEl.textContent = stepTitle;
            // Collapse the top row when it has nothing to show (step 1, no title).
            if (wiztop)    wiztop.hidden = (cur === 0 && stepTitle === '');
            var pct = Math.round(((cur + 1) / total) * 100) + '%';
            progress.forEach(function (p) { p.style.width = pct; });
            if (ring) ring.style.setProperty('--p', Math.round(((cur + 1) / total) * 100));
            railItems.forEach(function (it, j) {
                it.classList.toggle('is-active', j === cur);
                it.classList.toggle('is-done', j < cur);
            });
            flowSegs.forEach(function (sg, j) {
                sg.classList.toggle('is-active', j === cur);
                sg.classList.toggle('is-done', j < cur);
            });
            animate(stepsEls[cur], dir);
            recompute();
            if (stepsEls[cur].hasAttribute('data-forms-summary')) buildSummary();
            fieldWraps[cur] && (fieldWraps[cur].scrollTop = 0);
            updateScrollCue(cur);
            try { wrap.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) {}
        };

        // Build the read-only review list from every visible, named field.
        var buildSummary = function () {
            var body = wrap.querySelector('[data-forms-summary-body]');
            if (!body) return;
            body.textContent = '';
            var fields = wrap.querySelectorAll('[data-forms-step]:not([data-forms-summary]) .field');
            var any = false;
            Array.prototype.forEach.call(fields, function (fld) {
                if (fld.hidden) return;
                var ctrl = fld.querySelector('input[name], select[name], textarea[name]');
                if (!ctrl || ctrl.type === 'hidden') return;
                var name = ctrl.getAttribute('name');
                var labelEl = fld.querySelector('.field-label, .forms-check-label');
                var label = labelEl ? labelEl.textContent.replace(/\s*\*\s*$/, '').trim() : name;
                var checks = fld.querySelectorAll('input[type="checkbox"][name]');
                // International phone → combine the dial code with the number
                // (the <select> sits before the <input>, so don't read it alone).
                var ccSel = fld.querySelector('.forms-intl-cc');
                var numEl = ccSel ? fld.querySelector('.forms-intl input[name]') : null;
                var val;
                if (ccSel && numEl) {
                    var pnum = (numEl.value || '').trim();
                    val = pnum ? (ccSel.value + ' ' + pnum) : '';
                } else if (checks.length > 1) {
                    // Multi-select checkbox group → collect EVERY checked option,
                    // not just the first (was the "only 1 shows" bug).
                    var picked = [];
                    Array.prototype.forEach.call(checks, function (cb) {
                        if (cb.checked) picked.push(cb.value && cb.value !== '1' ? cb.value : 'Yes');
                    });
                    val = picked.join(', ');
                } else if (ctrl.type === 'checkbox') {
                    val = ctrl.checked ? (ctrl.value && ctrl.value !== '1' ? ctrl.value : 'Yes') : '';
                } else {
                    val = fieldValue(name);
                }
                val = (val == null ? '' : String(val)).trim();
                var row = document.createElement('div'); row.className = 'forms-summary-row';
                var k = document.createElement('span'); k.className = 'forms-summary-k'; k.textContent = label;
                var v = document.createElement('span'); v.className = 'forms-summary-v'; v.textContent = (val === '' ? '—' : val);
                row.appendChild(k); row.appendChild(v); body.appendChild(row);
                any = true;
            });
            if (!any) {
                var p = document.createElement('p'); p.className = 'forms-summary-empty';
                p.textContent = 'No answers to review yet.'; body.appendChild(p);
            }
        };

        var stepValid = function (i) {
            var ok = true, nativeFailed = false;
            var inputs = stepsEls[i].querySelectorAll('input, select, textarea');
            for (var k = 0; k < inputs.length; k++) {
                if (inputs[k].disabled) continue;
                if (typeof inputs[k].checkValidity === 'function' && !inputs[k].checkValidity()) {
                    if (ok && inputs[k].reportValidity) inputs[k].reportValidity();
                    markTouched(inputs[k]);
                    ok = false; nativeFailed = true;
                }
            }
            // Signature pads store their value in a HIDDEN input that native
            // validation ignores — so a required-but-empty pad would otherwise
            // sail past "Next". Check each one explicitly.
            var pads = stepsEls[i].querySelectorAll('[data-sig]'), firstBad = null;
            for (var s = 0; s < pads.length; s++) {
                var ve = pads[s].querySelector('[data-sig-value]');
                var req = ve && ve.getAttribute('data-sig-required') === '1';
                if (req && (ve.value || '').trim() === '') {
                    pads[s].classList.add('sig-invalid');
                    if (!firstBad) firstBad = pads[s];
                    ok = false;
                } else if (ve) {
                    pads[s].classList.remove('sig-invalid');
                }
            }
            // Bring the signature into view only if nothing native already grabbed focus.
            if (firstBad && !nativeFailed) {
                try { firstBad.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
            }
            return ok;
        };

        if (nextBtn) nextBtn.addEventListener('click', function () { if (stepValid(cur)) showStep(cur + 1, 'next'); });
        if (backBtn) backBtn.addEventListener('click', function () { showStep(cur - 1, 'prev'); });
        railItems.forEach(function (it, j) { it.addEventListener('click', function () { if (j < cur) showStep(j, 'prev'); }); });

        var errStep = -1;
        stepsEls.forEach(function (s, j) { if (errStep === -1 && s.querySelector('.field-error')) errStep = j; });
        showStep(errStep === -1 ? 0 : errStep, 'next');
    } else {
        // Flat (single-step) form: wire the scroll-fade on its capped region.
        Array.prototype.forEach.call(form.querySelectorAll('.forms-step-fields'), function (box) {
            var upd = function () { applyScrollFade(box); };
            box.addEventListener('scroll', upd, { passive: true });
            window.addEventListener('resize', upd);
            upd();
        });
    }

    // ── Inline validation (live green-check / red as you type) ──
    function vState(inp) {
        if (inp.disabled) return null;
        var v = (inp.value || '').trim();
        if (v === '') return inp.required ? 'invalid' : 'neutral';
        var t = (inp.getAttribute('type') || inp.tagName.toLowerCase());
        if (t === 'email')  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) ? 'valid' : 'invalid';
        if (t === 'url')    return /^https?:\/\/.+/i.test(v) ? 'valid' : 'invalid';
        if (t === 'number') return (!isNaN(parseFloat(v)) && isFinite(v)) ? 'valid' : 'invalid';
        if (t === 'tel')    return /^[0-9+()\-.\s]{4,40}$/.test(v) ? 'valid' : 'invalid';
        return 'valid';
    }
    function vMsg(inp) {
        if ((inp.value || '').trim() === '') return 'This field is required.';
        var t = inp.getAttribute('type');
        if (t === 'email')  return 'Enter a valid email address.';
        if (t === 'url')    return 'Enter a valid URL (https://…).';
        if (t === 'number') return 'Enter a number.';
        if (t === 'tel')    return 'Enter a valid phone number.';
        return 'Please check this field.';
    }
    function applyState(inp) {
        var field = inp.closest && inp.closest('.field');
        if (!field) return;
        var st = vState(inp);
        field.classList.remove('is-valid', 'is-invalid');
        var err = field.querySelector('.js-err');
        if (st === 'valid') {
            field.classList.add('is-valid');
        } else if (st === 'invalid') {
            field.classList.add('is-invalid');
            if (!err) { err = document.createElement('div'); err.className = 'field-error js-err'; field.appendChild(err); }
            err.textContent = vMsg(inp);
            return;
        }
        if (err && err.parentNode) err.parentNode.removeChild(err);
    }
    function markTouched(inp) { if (!doValidate) return; inp.setAttribute('data-touched', '1'); applyState(inp); }

    if (doValidate) {
        Array.prototype.forEach.call(form.querySelectorAll('.field input, .field textarea, .field select'), function (inp) {
            var t = inp.type;
            if (t === 'checkbox' || t === 'radio' || t === 'hidden' || t === 'range' || t === 'file' || t === 'submit' || t === 'button') return;
            inp.addEventListener('blur',  function () { markTouched(inp); });
            inp.addEventListener('input', function () { if (inp.getAttribute('data-touched')) applyState(inp); });
        });
    }

    recompute();
})();
