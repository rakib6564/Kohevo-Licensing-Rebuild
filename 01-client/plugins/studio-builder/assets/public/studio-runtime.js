/*!
 * Kohevo Studio — Interaction Runtime (Phase 2a prototype)
 * ---------------------------------------------------------------------------
 * The single, tenant-independent script that makes a Studio page interactive.
 *
 * Design constraints this file is written against:
 *   1. PROGRESSIVE ENHANCEMENT. Every feature degrades to working, readable,
 *      navigable HTML when JS is absent. A modal is a link to a fragment, an
 *      accordion is a list of headings, a carousel is a stack of slides.
 *   2. NO FRAMEWORK, NO BUILD. Vanilla ES2018, ~12KB, no dependencies. Ships as
 *      a static asset with a content hash for cache busting.
 *   3. SERVER REMAINS AUTHORITATIVE. This file never persists anything and
 *      never invents document state. It only reads the data-sb-* attributes the
 *      renderer already emits.
 *   4. COMPOSITOR-ONLY ANIMATION. Only `transform` and `opacity` are animated.
 *      No layout/paint properties are touched per frame, so scroll stays smooth
 *      on the shared-hosting target (cPanel / CloudLinux) without GPU compositing.
 *   5. ACCESSIBILITY IS NOT OPTIONAL. Focus trapping, aria-expanded/selected/
 *      hidden bookkeeping, Escape-to-close, keyboard parity, and a first-class
 *      `prefers-reduced-motion` contract.
 *
 * The A/B decision toggle (used by prototype/index.html, and the same flag a
 * future settings toggle would use) is `[data-sb-runtime="off"]` on <html>.
 * When off, every feature is inert AND every mutation this file made is undone,
 * so the page renders exactly like a today's Studio page.
 */

(function (global) {
  'use strict';

  /* ── Config ──────────────────────────────────────────────────────────── */

  var CONFIG = {
    // Scroll-reveal enters when the element is this far up the viewport.
    revealMargin: '0px 0px -12% 0px',
    // Counters finish faster than a full second; long counts feel sluggish.
    counterDuration: 1600,
    // Carousel honours this before auto-advancing.
    carouselInterval: 5200,
    // Smooth anchor scrolling duration, must match studio-tokens.css.
    anchorDuration: 480,
    // Focusable selector used by the overlay focus trap.
    FOCUSABLE: [
      'a[href]', 'button:not([disabled])', 'input:not([disabled]):not([type="hidden"])',
      'select:not([disabled])', 'textarea:not([disabled])', '[tabindex]:not([tabindex="-1"])',
      '[contenteditable="true"]'
    ].join(', ')
  };

  /* ── Tiny utilities (no framework, no polyfills) ──────────────────────── */

  function qsa(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }
  function qs(root, sel) { return root.querySelector(sel); }

  function on(el, type, fn, opts) {
    el.addEventListener(type, fn, opts);
    return function () { el.removeEventListener(type, fn, opts); };
  }

  function clamp(n, min, max) { return Math.min(max, Math.max(min, n)); }

  function uid(prefix) {
    uid._n = (uid._n || 0) + 1;
    return 'sb-' + prefix + '-' + uid._n;
  }

  function uniqueId() {
    if (global.crypto && global.crypto.randomUUID) return global.crypto.randomUUID();
    return uid('u');
  }

  /** Honour both the OS setting and the in-page demo toggle. */
  function reducedMotion() {
    var forced = document.documentElement.getAttribute('data-sb-motion');
    if (forced === 'reduce') return true;
    if (forced === 'full') return false;
    return global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  /** easeOutExpo — fast start, long soft settle. Reads as "responsive". */
  function easeOutExpo(t) { return t === 1 ? 1 : 1 - Math.pow(2, -10 * t); }

  function prefersHover() {
    return global.matchMedia ? global.matchMedia('(hover: hover)').matches : true;
  }

/* ── Lifecycle registry ──────────────────────────────────────────────────
   * Every feature registers an instance and returns a teardown closure.
   * `StudioRuntime.disable()` runs them all in reverse, which is what makes the
   * A/B toggle honest: after disabling, the DOM is byte-for-byte what a
   * no-JS Studio page looks like.
   */

  var instances = [];
  var booted = false;

  function mount(teardown) {
    instances.push(teardown);
    return teardown;
  }

  function teardownAll() {
    for (var i = instances.length - 1; i >= 0; i--) {
      try { instances[i](); } catch (e) { /* a broken feature must not block the rest */ }
    }
    instances = [];
  }

  /* ── Feature: scroll reveal ──────────────────────────────────────────────
   * The renderer already emits `.sb-animate-*` classes; those run on load.
   * Scroll reveal is the intersection-driven variant. The element gets
   * `data-sb-in` when it enters, which the stylesheet transitions on.
   */

  function initReveal(root) {
    var els = qsa(root, '[data-sb-reveal]');
    if (!els.length) return;

    // No-JS / reduced motion: everything is simply visible.
    if (reducedMotion() || !('IntersectionObserver' in global)) {
      els.forEach(function (el) { el.setAttribute('data-sb-in', ''); });
      return;
    }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var el = entry.target;
        var delay = parseFloat(el.getAttribute('data-sb-reveal-delay') || '0') * 1000;
        if (delay > 0) global.setTimeout(function () { el.setAttribute('data-sb-in', ''); }, delay);
        else el.setAttribute('data-sb-in', '');
        io.unobserve(el); // reveal is one-way; never replay on scroll-up
      });
    }, { rootMargin: CONFIG.revealMargin, threshold: 0.01 });

    els.forEach(function (el) { io.observe(el); });
    mount(function () {
      io.disconnect();
      els.forEach(function (el) { el.removeAttribute('data-sb-in'); });
    });
  }

  /* ── Feature: stagger ────────────────────────────────────────────────────
   * A node marked `data-sb-stagger` (interactions.stagger) plays the entrance animations inside it in turn: each
   * animated block gets its place (`--i`, the stylesheet multiplies it by `--sb-stagger-step`), and all of them wait,
   * paused, until the node comes into view (`is-in`). A block inside a nested group belongs to that group.
   * Without this file the animations play on load, as before; with reduced motion the stylesheet stops them anyway.
   */
  function initStagger(root) {
    var groups = qsa(root, '[data-sb-stagger]');
    if (!groups.length) return;
    var io = ('IntersectionObserver' in global) && !reducedMotion()
      ? new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          entry.target.classList.add('is-in');
          io.unobserve(entry.target); // one-way, same contract as scroll reveal
        });
      }, { rootMargin: CONFIG.revealMargin, threshold: 0.05 })
      : null;
    var marked = [];
    groups.forEach(function (group) {
      qsa(group, '[class*="sb-animate-"]').filter(function (el) {
        return el.parentElement && el.parentElement.closest('[data-sb-stagger]') === group;
      }).forEach(function (el, i) {
        if (el.style.getPropertyValue('--i')) return;
        el.style.setProperty('--i', String(i));
        marked.push(el);
      });
      if (io) io.observe(group); else group.classList.add('is-in');
    });
    mount(function () {
      if (io) io.disconnect();
      groups.forEach(function (group) { group.classList.remove('is-in'); });
      marked.forEach(function (el) { el.style.removeProperty('--i'); });
    });
  }

  /* ── Feature: "scroll into view" without scroll timelines ────────────────
   * Where the browser has `animation-timeline: view()` the stylesheet ties the animation to the scroll. Elsewhere
   * (Firefox, older Safari) the animation would run on load, out of sight; here it waits (`data-sb-wait`, paused in
   * the stylesheet) until the block comes into view, then plays once.
   */
  function initViewportFallback(root) {
    var timelines = global.CSS && global.CSS.supports && global.CSS.supports('animation-timeline: view()');
    if (timelines || reducedMotion() || !('IntersectionObserver' in global)) return;
    var els = qsa(root, '.sb-interaction-viewport-enter[class*="sb-animate-"]').filter(function (el) {
      return !el.closest('[data-sb-stagger]');
    });
    if (!els.length) return;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.removeAttribute('data-sb-wait');
        io.unobserve(entry.target);
      });
    }, { rootMargin: CONFIG.revealMargin, threshold: 0.01 });
    els.forEach(function (el) { el.setAttribute('data-sb-wait', ''); io.observe(el); });
    mount(function () {
      io.disconnect();
      els.forEach(function (el) { el.removeAttribute('data-sb-wait'); });
    });
  }

  /* ── Feature: animated counter ───────────────────────────────────────────
   * Counts to `data-sb-to` when scrolled into view, honouring the reduced
   * motion contract by snapping straight to the final value.
   */

  function initCounters(root) {
    var els = qsa(root, '[data-sb-counter]');
    if (!els.length) return;

    function render(el, value) {
      var decimals = parseInt(el.getAttribute('data-sb-decimals') || '0', 10);
      var prefix = el.getAttribute('data-sb-prefix') || '';
      var suffix = el.getAttribute('data-sb-suffix') || '';
      el.textContent = prefix + value.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + suffix;
    }

    els.forEach(function (el) {
      var to = parseFloat(el.getAttribute('data-sb-to') || '0');
      var duration = parseInt(el.getAttribute('data-sb-duration') || CONFIG.counterDuration, 10);

      // Remember the authored text so disable() can put the page back exactly
      // as the server rendered it — the A/B toggle depends on this.
      el.__sbText = el.textContent;
      render(el, 0);

      if (reducedMotion() || !('IntersectionObserver' in global)) {
        render(el, to);
        return;
      }

      var frame = null;   // tracked so teardown can cancel an in-flight count
      var safety = null;  // timer that guarantees the final value is reached
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          io.unobserve(entry.target);
          var start = null;
          function tick(ts) {
            if (start === null) start = ts;
            var p = clamp((ts - start) / duration, 0, 1);
            render(el, to * easeOutExpo(p));
            if (p < 1) frame = global.requestAnimationFrame(tick);
          }
          frame = global.requestAnimationFrame(tick);

          /* requestAnimationFrame is throttled to zero in a background tab and
           * can be starved entirely under headless/virtual-time rendering. An
           * earlier build left the counter frozen on a partial value forever in
           * both cases. This timer guarantees the authored value always lands,
           * and is cleared by the teardown below. */
          global.clearTimeout(safety);
          safety = global.setTimeout(function () {
            if (frame) global.cancelAnimationFrame(frame);
            frame = null;
            render(el, to);
          }, duration + 250);
        });
      }, { threshold: 0.4 });

      io.observe(el);
      io.__frame = null;

      // Undo: cancel the count, disconnect the observer, restore the authored
      // value. Without cancelling the rAF the animation keeps writing to the
      // element after teardown and overwrites what we just restored.
      mount(function () {
        io.disconnect();
        global.clearTimeout(safety);
        if (frame) global.cancelAnimationFrame(frame);
        if (el.__sbText !== undefined) el.textContent = el.__sbText;
      });
    });
  }

  /* ── Feature: parallax ───────────────────────────────────────────────────
   * Single shared rAF loop, transform-only so it stays off the layout path.
   *   [data-sb-parallax]     the element itself moves by a share of its distance from the viewport centre;
   *   [data-sb-parallax-bg]  its background layer (::after, see .sb-parallax-bg) moves inside the node, through the
   *                          room the stylesheet gives it, via the --sb-parallax-y custom property.
   * A negative value moves the other way. [data-sb-parallax-off] names the devices it stays still on.
   */

  var DEVICE_QUERIES = {
    desktop: '(min-width: 1024px)',
    tablet: '(min-width: 768px) and (max-width: 1023.98px)',
    mobile: '(max-width: 767.98px)'
  };

  /** True when `el` names the current device in its `attr` list (space-separated). */
  function offOnThisDevice(el, attr) {
    var list = (el.getAttribute(attr) || '').split(/\s+/);
    for (var i = 0; i < list.length; i++) {
      var q = DEVICE_QUERIES[list[i]];
      if (q && global.matchMedia && global.matchMedia(q).matches) return true;
    }
    return false;
  }

  function initParallax(root) {
    var els = qsa(root, '[data-sb-parallax]');
    var layers = qsa(root, '[data-sb-parallax-bg]');
    if ((!els.length && !layers.length) || reducedMotion()) return;

    var ticking = false;
    function update() {
      ticking = false;
      var vh = global.innerHeight || 1;
      els.forEach(function (el) {
        if (offOnThisDevice(el, 'data-sb-parallax-off')) { el.style.transform = ''; return; }
        var rect = el.getBoundingClientRect();
        if (rect.bottom < -200 || rect.top > vh + 200) return; // offscreen: skip
        var speed = parseFloat(el.getAttribute('data-sb-parallax') || '0.2');
        var centre = rect.top + rect.height / 2 - vh / 2;
        var shift = -centre * speed;
        el.style.transform = 'translate3d(0,' + shift.toFixed(2) + 'px,0)';
      });
      layers.forEach(function (el) {
        if (offOnThisDevice(el, 'data-sb-parallax-off')) { el.style.removeProperty('--sb-parallax-y'); return; }
        var rect = el.getBoundingClientRect();
        if (rect.bottom < 0 || rect.top > vh) return;
        var speed = parseFloat(el.getAttribute('data-sb-parallax-bg') || '0.3');
        // 0 as the node enters at the bottom, 1 as it leaves at the top; the layer has |speed|/2 of the node's
        // height spare above and below, and travels through it.
        var progress = clamp((vh - rect.top) / (vh + rect.height), 0, 1);
        var room = rect.height * Math.abs(speed) / 2;
        var shift = (progress - 0.5) * 2 * room * (speed < 0 ? -1 : 1);
        el.style.setProperty('--sb-parallax-y', shift.toFixed(2) + 'px');
      });
    }
    function onScroll() {
      if (ticking) return;
      ticking = true;
      global.requestAnimationFrame(update);
    }
    global.addEventListener('scroll', onScroll, { passive: true });
    global.addEventListener('resize', onScroll, { passive: true });
    update();

    mount(function () {
      global.removeEventListener('scroll', onScroll);
      global.removeEventListener('resize', onScroll);
      els.forEach(function (el) { el.style.transform = ''; });
      layers.forEach(function (el) { el.style.removeProperty('--sb-parallax-y'); });
    });
  }
  /** `y:3 o:5` as {y: 3, o: 5}: the levels the renderer wrote. */
  function effectLevels(el, attr) {
    var out = {};
    (el.getAttribute(attr) || '').split(/\s+/).forEach(function (pair) {
      var kv = pair.split(':');
      if (kv.length === 2) out[kv[0]] = parseFloat(kv[1]) || 0;
    });
    return out;
  }

  /* ── Feature: scrolling effects ───────────────────────────────
   * [data-sb-scroll] holds a level per effect, -10 to 10. Progress runs from -1 as the node enters at the bottom to 1
   * as it leaves at the top; the individual translate, rotate and scale properties leave any transform of its own alone.
   */
  function initScrollEffects(root) {
    var els = qsa(root, '[data-sb-scroll]');
    if (!els.length || reducedMotion()) return;
    var ticking = false;
    function clear(el) {
      ['translate', 'rotate', 'scale', 'opacity', 'filter'].forEach(function (p) { el.style.removeProperty(p); });
    }
    function update() {
      ticking = false;
      var vh = global.innerHeight || 1;
      els.forEach(function (el) {
        if (offOnThisDevice(el, 'data-sb-scroll-off')) { clear(el); return; }
        var rect = el.getBoundingClientRect();
        var p = clamp((vh - rect.top) / (vh + rect.height), 0, 1) * 2 - 1;
        var l = effectLevels(el, 'data-sb-scroll');
        if (l.y || l.x) el.style.translate = ((l.x || 0) * p * -12).toFixed(1) + 'px ' + ((l.y || 0) * p * -12).toFixed(1) + 'px';
        if (l.r) el.style.rotate = (l.r * p * 3).toFixed(2) + 'deg';
        if (l.s) el.style.scale = (1 + l.s * p * 0.03).toFixed(3);
        // Clearest in the middle of the screen, as in "fade in, then out".
        if (l.o) el.style.opacity = (1 - Math.abs(p) * l.o / 10).toFixed(3);
        if (l.b) el.style.filter = 'blur(' + (Math.abs(p) * l.b).toFixed(1) + 'px)';
      });
    }
    function onScroll() {
      if (ticking) return;
      ticking = true;
      global.requestAnimationFrame(update);
    }
    global.addEventListener('scroll', onScroll, { passive: true });
    global.addEventListener('resize', onScroll, { passive: true });
    update();
    mount(function () {
      global.removeEventListener('scroll', onScroll);
      global.removeEventListener('resize', onScroll);
      els.forEach(clear);
    });
  }

  /* ── Feature: mouse effects ───────────────────────────────────
   * [data-sb-mouse]: t follows the pointer across the page, i tilts towards it while it is over the node. Pointers that
   * can hover only, as in the reference, so phones stay still.
   */
  function initMouseEffects(root) {
    var els = qsa(root, '[data-sb-mouse]');
    if (!els.length || reducedMotion() || !global.matchMedia || !global.matchMedia('(hover: hover)').matches) return;
    // Magnet (m): near the node the pointer pulls it towards itself, eased each frame; it settles back when the pointer
    // leaves. The node is measured where it would be without the pull, so it never runs away from the pointer.
    var magnets = els.filter(function (el) { return effectLevels(el, 'data-sb-mouse').m; }).map(function (el) {
      return { el: el, level: effectLevels(el, 'data-sb-mouse').m, x: 0, y: 0, tx: 0, ty: 0 };
    });
    var raf = 0;
    function settle() {
      raf = 0;
      var moving = false;
      magnets.forEach(function (g) {
        g.x += (g.tx - g.x) * 0.18;
        g.y += (g.ty - g.y) * 0.18;
        if (Math.abs(g.tx - g.x) < 0.1 && Math.abs(g.ty - g.y) < 0.1) { g.x = g.tx; g.y = g.ty; } else moving = true;
        if (g.x === 0 && g.y === 0) g.el.style.removeProperty('translate');
        else g.el.style.translate = g.x.toFixed(2) + 'px ' + g.y.toFixed(2) + 'px';
      });
      if (moving) raf = global.requestAnimationFrame(settle);
    }
    function onMove(e) {
      var w = global.innerWidth || 1;
      var h = global.innerHeight || 1;
      magnets.forEach(function (g) {
        var r = g.el.getBoundingClientRect();
        var left = r.left - g.x, top = r.top - g.y, reach = 24 + g.level * 3;
        var near = e.clientX >= left - reach && e.clientX <= left + r.width + reach && e.clientY >= top - reach && e.clientY <= top + r.height + reach;
        var max = g.level * 3;
        g.tx = near ? clamp((e.clientX - left - r.width / 2) * g.level * 0.04, -max, max) : 0;
        g.ty = near ? clamp((e.clientY - top - r.height / 2) * g.level * 0.04, -max, max) : 0;
      });
      if (magnets.length && !raf) raf = global.requestAnimationFrame(settle);
      els.forEach(function (el) {
        var l = effectLevels(el, 'data-sb-mouse');
        if (l.t && !l.m) el.style.translate = ((e.clientX / w - 0.5) * l.t * 8).toFixed(1) + 'px ' + ((e.clientY / h - 0.5) * l.t * 8).toFixed(1) + 'px';
        if (l.i) {
          var r = el.getBoundingClientRect();
          var over = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
          var rx = over ? (0.5 - (e.clientY - r.top) / r.height) * l.i * 3 : 0;
          var ry = over ? ((e.clientX - r.left) / r.width - 0.5) * l.i * 3 : 0;
          el.style.transform = 'perspective(800px) rotateX(' + rx.toFixed(2) + 'deg) rotateY(' + ry.toFixed(2) + 'deg)';
        }
      });
    }
    function onLeave() { magnets.forEach(function (g) { g.tx = 0; g.ty = 0; }); if (magnets.length && !raf) raf = global.requestAnimationFrame(settle); }
    global.addEventListener('mousemove', onMove, { passive: true });
    document.addEventListener('mouseleave', onLeave);
    mount(function () {
      global.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseleave', onLeave);
      if (raf) global.cancelAnimationFrame(raf);
      els.forEach(function (el) { el.style.removeProperty('translate'); el.style.removeProperty('transform'); });
    });
  }

/* ── Feature: page colours ───────────────────────────────────────────────
   * [data-sb-page-colors] (interactions.page_colors) carries `--sb-page-bg` / `--sb-page-fg`. The section crossing the
   * middle of the screen gives the page its colours: they are set on <html> as `--sb-paged-bg` / `--sb-paged-fg` and the
   * stylesheet blends the body to them. Above the first such section the page keeps its own colours; past one, it keeps
   * the last until another takes over.
   * [data-sb-adapt] (interactions.adapt, a header) takes the colours of the section beneath its bottom edge, inline.
   */
  function initPageColors(root) {
    var sections = qsa(root, '[data-sb-page-colors]');
    if (!sections.length) return;
    var html = document.documentElement;
    function colours(el) {
      var cs = global.getComputedStyle(el);
      return { bg: cs.getPropertyValue('--sb-page-bg').trim(), fg: cs.getPropertyValue('--sb-page-fg').trim() };
    }
    var list = sections.map(function (el) { return { el: el, c: colours(el) }; });
    var adapters = qsa(root, '[data-sb-adapt]').map(function (el) { return { el: el, hit: null }; });
    // The innermost themed section spanning the line y, leaving out any that holds `skip`.
    function at(y, skip) {
      var hit = null;
      list.forEach(function (s) {
        if (skip && s.el.contains(skip)) return;
        var r = s.el.getBoundingClientRect();
        if (r.height > 0 && r.top <= y && r.bottom > y && (!hit || hit.el.contains(s.el))) hit = s;
      });
      return hit;
    }
    function setVar(name, attr, value) {
      if (value) { html.style.setProperty(name, value); html.setAttribute(attr, ''); }
      else { html.style.removeProperty(name); html.removeAttribute(attr); }
    }
    var current = null;
    var ticking = false;
    function update() {
      ticking = false;
      var mid = (global.innerHeight || 1) / 2;
      var hit = at(mid, null);
      if (!hit) {
        var first = Infinity;
        list.forEach(function (s) { first = Math.min(first, s.el.getBoundingClientRect().top); });
        hit = first > mid ? null : current;
      }
      if (hit !== current) {
        current = hit;
        setVar('--sb-paged-bg', 'data-sb-paged-bg', hit && hit.c.bg);
        setVar('--sb-paged-fg', 'data-sb-paged-fg', hit && hit.c.fg);
      }
      adapters.forEach(function (a) {
        var r = a.el.getBoundingClientRect();
        var under = at(Math.max(0, r.bottom + 1), a.el);
        if (under === a.hit) return;
        a.hit = under;
        if (under && under.c.bg) a.el.style.setProperty('background-color', under.c.bg, 'important'); else a.el.style.removeProperty('background-color');
        if (under && under.c.fg) a.el.style.setProperty('color', under.c.fg, 'important'); else a.el.style.removeProperty('color');
      });
    }
    function onScroll() {
      if (ticking) return;
      ticking = true;
      global.requestAnimationFrame(update);
    }
    html.setAttribute('data-sb-page-colors', '');
    var offs = [on(global, 'scroll', onScroll, { passive: true }), on(global, 'resize', onScroll, { passive: true })];
    update();
    mount(function () {
      offs.forEach(function (off) { off(); });
      html.removeAttribute('data-sb-page-colors');
      setVar('--sb-paged-bg', 'data-sb-paged-bg', '');
      setVar('--sb-paged-fg', 'data-sb-paged-fg', '');
      adapters.forEach(function (a) { a.el.style.removeProperty('background-color'); a.el.style.removeProperty('color'); });
    });
  }

  /** Make a node a containing block for something this file adds inside it; returns the undo. */
  function positioned(el) {
    if (global.getComputedStyle(el).position !== 'static') return function () {};
    el.style.position = 'relative';
    return function () { el.style.removeProperty('position'); };
  }

  /* ── Feature: scroll progress bar ────────────────────────────────────────
   * [data-sb-progress="top|bottom"] (interactions.progress) gets a bar along that edge; `--sb-page-progress` on <html>
   * runs from 0 at the top of the page to 1 at the bottom, and the stylesheet scales the bar by it. It reports where
   * the reader is, so it stays on with reduced motion.
   */
  function initProgress(root) {
    var els = qsa(root, '[data-sb-progress]');
    if (!els.length) return;
    var html = document.documentElement;
    var undo = els.map(function (el) {
      var bar = document.createElement('span');
      bar.className = 'sb-progress-bar';
      bar.setAttribute('aria-hidden', 'true');
      el.appendChild(bar);
      var unposition = positioned(el);
      return function () { bar.remove(); unposition(); };
    });
    var ticking = false;
    function update() {
      ticking = false;
      var max = html.scrollHeight - (global.innerHeight || 0);
      html.style.setProperty('--sb-page-progress', (max > 0 ? clamp((global.pageYOffset || 0) / max, 0, 1) : 0).toFixed(4));
    }
    function onScroll() {
      if (ticking) return;
      ticking = true;
      global.requestAnimationFrame(update);
    }
    var offs = [on(global, 'scroll', onScroll, { passive: true }), on(global, 'resize', onScroll, { passive: true })];
    update();
    mount(function () {
      offs.forEach(function (off) { off(); });
      undo.forEach(function (fn) { fn(); });
      html.style.removeProperty('--sb-page-progress');
    });
  }

  /**
   * Wrap each word of `el` in `span.sb-w`, inside its own elements (links, highlights) too, and return them in order. A
   * rotating word ([data-sb-rotate]) is one unit: it is wrapped whole, so it can keep changing its own text.
   */
  function splitWords(el) {
    var units = [];
    function wrap(node) {
      var w = document.createElement('span');
      w.className = 'sb-w';
      node.parentNode.insertBefore(w, node);
      w.appendChild(node);
      units.push(w);
    }
    function splitText(text) {
      var parts = text.nodeValue.split(/(\s+)/);
      var frag = document.createDocumentFragment();
      parts.forEach(function (part) {
        if (!part) return;
        if (/^\s+$/.test(part)) { frag.appendChild(document.createTextNode(part)); return; }
        var w = document.createElement('span');
        w.className = 'sb-w';
        w.textContent = part;
        frag.appendChild(w);
        units.push(w);
      });
      text.parentNode.replaceChild(frag, text);
    }
    (function walk(node) {
      var child = node.firstChild;
      while (child) {
        var next = child.nextSibling;
        if (child.nodeType === 3) {
          if (child.nodeValue.trim()) splitText(child);
        } else if (child.nodeType === 1) {
          if (child.hasAttribute('data-sb-rotate')) wrap(child);
          else if (!/^(script|style|svg|br|img|video|iframe|input|textarea|select|button)$/i.test(child.nodeName)) walk(child);
        }
        child = next;
      }
    })(el);
    return units;
  }

  /** Undo splitWords (and the masks around the words): the text is back as it was. */
  function unsplitWords(el) {
    qsa(el, '.sb-wm, span.sb-w').forEach(function (w) {
      while (w.firstChild) w.parentNode.insertBefore(w.firstChild, w);
      w.remove();
    });
    el.normalize();
  }

  /* ── Feature: text effects ───────────────────────────────────────────────
   * [data-sb-text] (interactions.text):
   *   words:<dim>  every word starts at <dim>% opacity and lights up, in reading order, as the node crosses the screen.
   *   lines:<ms>   every word rises from its own mask as the node comes into view, a line (<ms> apart) at a time; once
   *                it has risen the text is put back as it was, so a later resize cannot leave words mid-line.
   * Reduced motion: neither runs, and the stylesheet never holds the text back.
   */
  function initTextEffects(root) {
    var els = qsa(root, '[data-sb-text]');
    if (!els.length || reducedMotion()) return;
    var offs = [];
    var lighters = [];
    var io = 'IntersectionObserver' in global ? new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        io.unobserve(entry.target);
        rise(entry.target);
      });
    }, { threshold: 0.2 }) : null;

    function rise(el) {
      var units = el.__sbUnits || [];
      var line = -1, lastTop = -Infinity;
      units.forEach(function (u) {
        var top = u.getBoundingClientRect().top;
        if (top > lastTop + 2) { line++; lastTop = top; }
        u.style.setProperty('--sb-line', String(line));
      });
      void el.offsetWidth;
      el.setAttribute('data-sb-lines-in', '');
      var step = parseInt((el.getAttribute('data-sb-text') || '').split(':')[1], 10) || 0;
      el.__sbDone = global.setTimeout(function () {
        unsplitWords(el);
        el.removeAttribute('data-sb-lines-in');
      }, 1000 + Math.max(0, line) * step);
    }

    els.forEach(function (el) {
      var parts = (el.getAttribute('data-sb-text') || '').split(':');
      var units = splitWords(el);
      el.setAttribute('data-sb-split', '');
      if (parts[0] === 'lines') {
        el.style.setProperty('--sb-line-step', (parseInt(parts[1], 10) || 0) + 'ms');
        units.forEach(function (u) {
          var mask = document.createElement('span');
          mask.className = 'sb-wm';
          u.parentNode.insertBefore(mask, u);
          mask.appendChild(u);
        });
        el.__sbUnits = units;
        if (io) io.observe(el); else unsplitWords(el);
      } else {
        lighters.push({ el: el, units: units, dim: String(clamp(parseInt(parts[1], 10) || 18, 5, 60) / 100) });
      }
    });

    var ticking = false;
    function light() {
      ticking = false;
      var vh = global.innerHeight || 1;
      lighters.forEach(function (l) {
        var r = l.el.getBoundingClientRect();
        var p = clamp((vh * 0.85 - r.top) / (vh * 0.5 + r.height * 0.5), 0, 1);
        var lit = Math.round(p * l.units.length);
        l.units.forEach(function (u, i) { u.style.opacity = i < lit ? '' : l.dim; });
      });
    }
    function onScroll() {
      if (ticking) return;
      ticking = true;
      global.requestAnimationFrame(light);
    }
    if (lighters.length) {
      offs.push(on(global, 'scroll', onScroll, { passive: true }), on(global, 'resize', onScroll, { passive: true }));
      light();
    }
    mount(function () {
      if (io) io.disconnect();
      offs.forEach(function (off) { off(); });
      els.forEach(function (el) {
        global.clearTimeout(el.__sbDone);
        unsplitWords(el);
        el.removeAttribute('data-sb-split');
        el.removeAttribute('data-sb-lines-in');
        el.style.removeProperty('--sb-line-step');
        delete el.__sbUnits;
      });
    });
  }

  /* ── Feature: rotating words ─────────────────────────────────────────────
   * [data-sb-rotate] (a Dual Color Heading's coloured part) changes to each of its other words in turn, every
   * `data-sb-rotate-ms`: the word slides up and out, the next slides in. It waits while the tab is hidden. Reduced
   * motion: the part keeps its own word, as it does without this file.
   */
  function initRotate(root) {
    var els = qsa(root, '[data-sb-rotate]');
    if (!els.length || reducedMotion()) return;
    var timers = [];
    var undo = [];
    els.forEach(function (el) {
      var original = el.textContent;
      var words = [original].concat((el.getAttribute('data-sb-rotate') || '').split('|')).filter(function (w) { return w.trim() !== ''; });
      if (words.length < 2) return;
      var ms = clamp(parseInt(el.getAttribute('data-sb-rotate-ms'), 10) || 2800, 1000, 10000);
      var i = 0;
      el.style.display = 'inline-block';
      function swap(word) {
        if (!el.animate) { el.textContent = word; return; }
        var out = el.animate([{ transform: 'translateY(0)', opacity: 1 }, { transform: 'translateY(-0.45em)', opacity: 0 }], { duration: 340, easing: 'cubic-bezier(.6,0,.8,.2)', fill: 'forwards' });
        out.onfinish = function () {
          el.textContent = word;
          var next = el.animate([{ transform: 'translateY(0.45em)', opacity: 0 }, { transform: 'translateY(0)', opacity: 1 }], { duration: 560, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'both' });
          out.cancel();
          next.onfinish = function () { next.cancel(); };
        };
      }
      timers.push(global.setInterval(function () {
        if (document.hidden) return;
        i = (i + 1) % words.length;
        swap(words[i]);
      }, ms));
      undo.push(function () {
        if (el.getAnimations) el.getAnimations().forEach(function (a) { a.cancel(); });
        el.textContent = original;
        el.style.removeProperty('display');
      });
    });
    mount(function () {
      timers.forEach(function (t) { global.clearInterval(t); });
      undo.forEach(function (fn) { fn(); });
    });
  }

  /* ── Feature: pointer glow ───────────────────────────────────────────────
   * [data-sb-glow] (interactions.glow) gets a layer behind its content, lit around the pointer (`--sb-mx`, `--sb-my`)
   * while the pointer is over it. Pointers that can hover only; nothing with reduced motion.
   */
  function initGlow(root) {
    var els = qsa(root, '[data-sb-glow]');
    if (!els.length || reducedMotion() || !prefersHover()) return;
    var undo = els.map(function (el) {
      var layer = document.createElement('span');
      layer.className = 'sb-glow';
      layer.setAttribute('aria-hidden', 'true');
      el.appendChild(layer);
      var unposition = positioned(el);
      var off = on(el, 'pointermove', function (e) {
        var r = el.getBoundingClientRect();
        el.style.setProperty('--sb-mx', (e.clientX - r.left).toFixed(0) + 'px');
        el.style.setProperty('--sb-my', (e.clientY - r.top).toFixed(0) + 'px');
      }, { passive: true });
      return function () {
        off();
        layer.remove();
        unposition();
        el.style.removeProperty('--sb-mx');
        el.style.removeProperty('--sb-my');
      };
    });
    mount(function () { undo.forEach(function (fn) { fn(); }); });
  }

  /* ── Feature: smooth scrolling ───────────────────────────────────────────
   * `data-sb-smooth` on <html> (the site's Smooth scrolling setting): a mouse wheel moves the page towards where it
   * would have jumped, easing a little each frame. Everything else keeps the browser's own scrolling: touch, the
   * keyboard, the scroll bar, a box that scrolls by itself, an open dialog, a zoom gesture. Fine pointers only, and
   * never with reduced motion.
   */
  function initSmoothScroll() {
    var html = document.documentElement;
    if (!html.hasAttribute('data-sb-smooth') || reducedMotion() || !global.matchMedia || !global.matchMedia('(pointer: fine)').matches) return;
    var target = global.pageYOffset || 0, current = target, set = target, raf = 0;
    var behavior = html.style.scrollBehavior;
    html.style.scrollBehavior = 'auto'; // a smooth scroll-behavior would animate every step again
    function maxScroll() { return Math.max(0, html.scrollHeight - (global.innerHeight || 0)); }
    function scrollsItself(node, dy) {
      for (; node && node.nodeType === 1 && node !== document.body && node !== html; node = node.parentElement) {
        var oy = global.getComputedStyle(node).overflowY;
        if (/(auto|scroll|overlay)/.test(oy) && node.scrollHeight > node.clientHeight + 1
          && (dy < 0 ? node.scrollTop > 0 : node.scrollTop + node.clientHeight < node.scrollHeight - 1)) return true;
      }
      return false;
    }
    function frame() {
      raf = 0;
      // Something else moved the page (the scroll bar, a key, a link): it wins, and the easing stops.
      if (Math.abs((global.pageYOffset || 0) - set) > 2) { target = current = global.pageYOffset || 0; return; }
      current += (target - current) * 0.12;
      if (Math.abs(target - current) < 0.5) current = target;
      set = Math.round(current);
      global.scrollTo(0, current);
      if (current !== target) raf = global.requestAnimationFrame(frame);
    }
    function onWheel(e) {
      if (e.defaultPrevented || e.ctrlKey || e.metaKey) return;
      var dy = e.deltaY;
      if (!dy || Math.abs(e.deltaX) > Math.abs(dy) || openOverlays.length) return;
      if (global.getComputedStyle(html).overflowY === 'hidden' || global.getComputedStyle(document.body).overflowY === 'hidden') return;
      if (scrollsItself(e.target, dy)) return;
      if (e.deltaMode === 1) dy *= 16; else if (e.deltaMode === 2) dy *= global.innerHeight || 1;
      if (!raf) { target = current = set = global.pageYOffset || 0; }
      target = clamp(target + dy, 0, maxScroll());
      e.preventDefault();
      if (!raf) raf = global.requestAnimationFrame(frame);
    }
    var off = on(global, 'wheel', onWheel, { passive: false });
    mount(function () {
      off();
      if (raf) global.cancelAnimationFrame(raf);
      html.style.scrollBehavior = behavior;
    });
  }


/** Move a node's children into a new flex track (inside `frame` when given), keeping the node's own gap. Returns the
   *  track and the undo, which puts the children back where they were. */
  function trackChildren(el, className, frame) {
    var gap = global.getComputedStyle(el).columnGap;
    var track = document.createElement('div');
    track.className = className;
    if (gap && gap !== 'normal') track.style.gap = gap;
    // Blocks only: a Container's background video, slideshow and shape dividers (and layers this file added) stay put.
    var kids = blockChildren(el);
    var anchor = kids.length ? kids[kids.length - 1].nextSibling : null;
    kids.forEach(function (k) { track.appendChild(k); });
    (frame || el).appendChild(track);
    el.insertBefore(frame || track, anchor);
    return {
      track: track,
      undo: function () {
        kids.forEach(function (k) { el.insertBefore(k, frame || track); });
        (frame || track).remove();
      }
    };
  }

  /** A node's child blocks, in order. */
  function blockChildren(el) {
    return Array.prototype.filter.call(el.children, function (k) { return k.classList.contains('sb-block'); });
  }

  /** A copy of a node for a loop: hidden from assistive technology and the tab order, with no ids to repeat. */
  function loopCopy(node) {
    var copy = node.cloneNode(true);
    copy.setAttribute('aria-hidden', 'true');
    copy.setAttribute('data-sb-copy', '');
    if (copy.removeAttribute) copy.removeAttribute('id');
    qsa(copy, '[id]').forEach(function (n) { n.removeAttribute('id'); });
    qsa(copy, CONFIG.FOCUSABLE).forEach(function (n) { n.setAttribute('tabindex', '-1'); });
    return copy;
  }

  /* ── Feature: marquee of blocks ──────────────────────────────────────────
   * [data-sb-ticker="<speed 1-10> <left|right> [scroll] [pause]"] (interactions.marquee): the node's children run past
   * in an endless line. The runtime puts them in a track and repeats them (copies hidden from screen readers and the
   * tab order, but still clickable) until the line is wider than the node plus one set. `scroll` speeds the line up
   * with the page's scroll and turns it with the scroll's direction; `pause` slows it while the pointer is over it. It
   * only runs while on screen. Reduced motion: nothing moves and the node keeps its author's layout.
   */
  function initTicker(root) {
    var els = qsa(root, '[data-sb-ticker]').filter(function (el) { return !el.hasAttribute('data-sb-horizontal'); });
    if (!els.length || reducedMotion()) return;
    var undo = [];
    var lines = els.map(function (el) {
      var parts = (el.getAttribute('data-sb-ticker') || '').split(/\s+/);
      var built = trackChildren(el, 'sb-ticker-track');
      var originals = Array.prototype.slice.call(built.track.children);
      el.classList.add('is-live');
      undo.push(function () { qsa(built.track, '[data-sb-copy]').forEach(function (c) { c.remove(); }); built.undo(); el.classList.remove('is-live'); });
      var line = {
        el: el, track: built.track, originals: originals, set: 0, x: 0, slow: 1, hover: false, seen: true,
        speed: clamp(parseInt(parts[0], 10) || 4, 1, 10), dir: parts[1] === 'right' ? -1 : 1,
        scroll: parts.indexOf('scroll') !== -1, pause: parts.indexOf('pause') !== -1
      };
      if (line.pause) {
        undo.push(on(el, 'pointerenter', function () { line.hover = true; }));
        undo.push(on(el, 'pointerleave', function () { line.hover = false; }));
      }
      return line;
    });
    // One set's width is where the first copy starts; fill the track until a set can scroll away with no gap.
    function measure(line) {
      qsa(line.track, '[data-sb-copy]').forEach(function (c) { c.remove(); });
      if (!line.originals.length) return;
      var copies = 0;
      do {
        line.originals.forEach(function (n) { line.track.appendChild(loopCopy(n)); });
        copies++;
        var first = line.track.children[line.originals.length];
        line.set = first.offsetLeft - line.originals[0].offsetLeft;
      } while (line.set > 0 && line.track.scrollWidth < line.el.clientWidth + line.set * 2 && copies < 20);
    }
    lines.forEach(measure);
    var io = 'IntersectionObserver' in global ? new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        lines.forEach(function (l) { if (l.el === entry.target) l.seen = entry.isIntersecting; });
      });
    }, { rootMargin: '200px 0px' }) : null;
    if (io) lines.forEach(function (l) { io.observe(l.el); });

    var lastY = global.pageYOffset || 0, velocity = 0, scrollDir = 1, prev = 0, raf = 0;
    undo.push(on(global, 'scroll', function () {
      var y = global.pageYOffset || 0, d = y - lastY;
      lastY = y;
      velocity += d;
      if (Math.abs(d) > 1) scrollDir = d > 0 ? 1 : -1;
    }, { passive: true }));
    var resizeTimer = 0;
    undo.push(on(global, 'resize', function () {
      global.clearTimeout(resizeTimer);
      resizeTimer = global.setTimeout(function () { lines.forEach(measure); }, 150);
    }, { passive: true }));
    lines.forEach(function (l) {
      qsa(l.track, 'img').forEach(function (img) { if (!img.complete) undo.push(on(img, 'load', function () { measure(l); })); });
    });

    function frame(now) {
      var dt = prev ? Math.min(50, now - prev) / 16.67 : 1;
      prev = now;
      var v = velocity;
      velocity *= 0.86;
      lines.forEach(function (l) {
        if (!l.seen || l.set <= 0) return;
        l.slow += ((l.hover ? 0.15 : 1) - l.slow) * 0.08;
        var boost = l.scroll ? Math.min(18, Math.abs(v) * 0.25) : 0;
        var turn = l.scroll ? scrollDir : 1;
        l.x -= (l.speed * 0.25 + boost) * l.slow * dt * l.dir * turn;
        if (l.x <= -l.set) l.x += l.set;
        if (l.x > 0) l.x -= l.set;
        l.track.style.transform = 'translate3d(' + l.x.toFixed(1) + 'px,0,0)';
      });
      raf = global.requestAnimationFrame(frame);
    }
    raf = global.requestAnimationFrame(frame);
    mount(function () {
      global.cancelAnimationFrame(raf);
      global.clearTimeout(resizeTimer);
      if (io) io.disconnect();
      undo.forEach(function (fn) { fn(); });
    });
  }

  /* ── Feature: sideways scroll ────────────────────────────────────────────
   * [data-sb-horizontal] (interactions.horizontal): on screens at least 1024px wide and 560px tall, the node grows tall
   * by the width its children overflow, a frame inside it pins (`--sb-hs-offset` below the top), and scrolling the page
   * moves the children sideways through the frame. Smaller screens, and reduced motion, keep the author's layout.
   */
  function initHorizontal(root) {
    var els = qsa(root, '[data-sb-horizontal]');
    if (!els.length || reducedMotion() || !global.matchMedia) return;
    var query = global.matchMedia('(min-width: 1024px) and (min-height: 560px)');
    var live = [];
    function build() {
      els.forEach(function (el) {
        var pin = document.createElement('div');
        pin.className = 'sb-hs-pin';
        var built = trackChildren(el, 'sb-hs-track', pin);
        var item = { el: el, pin: pin, track: built.track, undo: built.undo, distance: 0, offset: 0 };
        live.push(item);
        qsa(built.track, 'img').forEach(function (img) { if (!img.complete) img.addEventListener('load', layout, { once: true }); });
      });
      layout();
    }
    function teardown() {
      live.forEach(function (item) {
        item.undo();
        item.el.style.removeProperty('height');
      });
      live = [];
    }
    function layout() {
      var vw = document.documentElement.clientWidth, vh = global.innerHeight || 1;
      live.forEach(function (item) {
        item.offset = parseFloat(global.getComputedStyle(item.el).getPropertyValue('--sb-hs-offset')) || 0;
        var frameH = vh - item.offset;
        item.pin.style.height = frameH + 'px';
        item.distance = Math.max(0, item.track.scrollWidth - item.pin.clientWidth);
        item.el.style.height = (frameH + item.distance) + 'px';
      });
      update();
    }
    function update() {
      live.forEach(function (item) {
        var r = item.el.getBoundingClientRect();
        var travel = item.el.offsetHeight - item.pin.offsetHeight;
        var p = travel > 0 ? clamp((item.offset - r.top) / travel, 0, 1) : 0;
        item.track.style.transform = 'translate3d(' + (-p * item.distance).toFixed(1) + 'px,0,0)';
      });
    }
    var ticking = false;
    function onScroll() {
      if (ticking) return;
      ticking = true;
      global.requestAnimationFrame(function () { ticking = false; update(); });
    }
    function onChange() {
      if (query.matches && !live.length) build();
      else if (!query.matches && live.length) teardown();
      else layout();
    }
    if (query.matches) build();
    var offs = [on(global, 'scroll', onScroll, { passive: true }), on(global, 'resize', onChange, { passive: true })];
    mount(function () {
      offs.forEach(function (off) { off(); });
      teardown();
    });
  }

  /* ── Feature: hover image ────────────────────────────────────────────────
   * [data-sb-hover-image] (interactions.hover_image): on screens at least 900px wide with a mouse, each child row's
   * first picture is hidden in the row (`is-live`), and while the pointer is over a row its picture follows the
   * pointer, easing behind it and leaning with its speed. Touch screens and reduced motion keep the pictures in the rows.
   */
  function initHoverImage(root) {
    var els = qsa(root, '[data-sb-hover-image]');
    if (!els.length || reducedMotion() || !global.matchMedia || !global.matchMedia('(pointer: fine) and (min-width: 900px)').matches) return;
    var float = document.createElement('div');
    float.className = 'sb-hover-float';
    float.setAttribute('aria-hidden', 'true');
    var pic = document.createElement('img');
    pic.alt = '';
    float.appendChild(pic);
    document.body.appendChild(float);
    var offs = [];
    var px = 0, py = 0, x = 0, y = 0, showing = false, raf = 0;
    els.forEach(function (el) {
      el.classList.add('is-live');
      float.style.setProperty('--sb-hover-size', global.getComputedStyle(el).getPropertyValue('--sb-hover-size'));
      blockChildren(el).forEach(function (row) {
        var img = qs(row, '.sb-block--core-image img') || qs(row, 'img');
        if (!img) return;
        offs.push(on(row, 'pointerenter', function (e) {
          float.style.setProperty('--sb-hover-size', global.getComputedStyle(el).getPropertyValue('--sb-hover-size'));
          pic.src = img.currentSrc || img.src;
          if (!showing) { x = px = e.clientX; y = py = e.clientY; }
          showing = true;
          float.classList.add('is-on');
          if (!raf) raf = global.requestAnimationFrame(follow);
        }));
        offs.push(on(row, 'pointerleave', function () { showing = false; float.classList.remove('is-on'); }));
      });
    });
    offs.push(on(global, 'pointermove', function (e) { px = e.clientX; py = e.clientY; }, { passive: true }));
    function follow() {
      raf = 0;
      var dx = px - x;
      x += dx * 0.14;
      y += (py - y) * 0.14;
      var lean = clamp(dx * 0.04, -7, 7);
      float.style.transform = 'translate3d(' + (x - float.offsetWidth / 2).toFixed(1) + 'px,' + (y - float.offsetHeight / 2).toFixed(1) + 'px,0) rotate(' + lean.toFixed(2) + 'deg)';
      if (showing || Math.abs(dx) > 0.5) raf = global.requestAnimationFrame(follow);
    }
    mount(function () {
      offs.forEach(function (off) { off(); });
      if (raf) global.cancelAnimationFrame(raf);
      float.remove();
      els.forEach(function (el) { el.classList.remove('is-live'); });
    });
  }


/* ── Feature: timeline ───────────────────────────────────────────────────
   * [data-sb-timeline] (layout.timeline): the line fills as the page scrolls, up to a point 72% down the screen
   * (`--sb-tl-p`, 0 to 1), and each step that point has passed is marked (`is-passed`). Reduced motion: full at once.
   */
  function initTimeline(root) {
    var els = qsa(root, '[data-sb-timeline]');
    if (!els.length) return;
    var still = reducedMotion();
    var steps = els.map(function (el) {
      var list = qs(el, '.sb-timeline__steps');
      return list ? Array.prototype.filter.call(list.children, function (k) { return k.classList.contains('sb-block'); }) : [];
    });
    var ticking = false;
    function update() {
      ticking = false;
      var line = (global.innerHeight || 1) * 0.72;
      els.forEach(function (el, n) {
        var r = el.getBoundingClientRect();
        el.style.setProperty('--sb-tl-p', still ? '1' : clamp((line - r.top) / Math.max(1, r.height), 0, 1).toFixed(3));
        steps[n].forEach(function (step) { step.classList.toggle('is-passed', still || step.getBoundingClientRect().top < line); });
      });
    }
    function onScroll() {
      if (ticking) return;
      ticking = true;
      global.requestAnimationFrame(update);
    }
    var offs = [on(global, 'scroll', onScroll, { passive: true }), on(global, 'resize', onScroll, { passive: true })];
    update();
    mount(function () {
      offs.forEach(function (off) { off(); });
      els.forEach(function (el, n) {
        el.style.removeProperty('--sb-tl-p');
        steps[n].forEach(function (step) { step.classList.remove('is-passed'); });
      });
    });
  }

  /* ── Feature: progress bars ──────────────────────────────────────────────
   * [data-sb-progress-bars] (core.progress): the stylesheet holds the bars empty until `is-in`, set once the block is
   * well into view, and they grow to their values. Reduced motion or no observer: shown at once.
   */
  function initProgressBars(root) {
    var els = qsa(root, '[data-sb-progress-bars]');
    if (!els.length) return;
    if (reducedMotion() || !('IntersectionObserver' in global)) {
      els.forEach(function (el) { el.classList.add('is-in'); });
      mount(function () { els.forEach(function (el) { el.classList.remove('is-in'); }); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-in');
        io.unobserve(entry.target);
      });
    }, { rootMargin: CONFIG.revealMargin, threshold: 0.2 });
    els.forEach(function (el) { io.observe(el); });
    mount(function () {
      io.disconnect();
      els.forEach(function (el) { el.classList.remove('is-in'); });
    });
  }


/* ── Feature: chapter nav (scrollspy) ───────────────────────────────────
   * [data-sb-spy] (interactions.spy): of the node's links to sections of this page, the one whose section was the last to
   * reach a line 35% down the screen is marked current (`aria-current="location"`, `is-current`), so a menu's "current
   * link" style follows the reader. Before the first section, none is.
   */
  function initSpy(root) {
    var els = qsa(root, '[data-sb-spy]');
    if (!els.length) return;
    var here = location.pathname.replace(/\/+$/, '') || '/';
    var groups = els.map(function (el) {
      var links = [];
      qsa(el, 'a[href*="#"]').forEach(function (a) {
        var url;
        try { url = new URL(a.getAttribute('href'), location.href); } catch (err) { return; }
        if (url.origin !== location.origin || (url.pathname.replace(/\/+$/, '') || '/') !== here || url.hash.length < 2) return;
        var target;
        try { target = document.getElementById(decodeURIComponent(url.hash.slice(1))); } catch (err) { return; }
        if (!target) return;
        // A link to this page is marked "page" by the menu's own feature; here it means a place on the page instead.
        a.removeAttribute('aria-current');
        a.classList.remove('is-current');
        links.push({ a: a, target: target });
      });
      return links;
    });
    var ticking = false;
    function update() {
      ticking = false;
      var line = (global.innerHeight || 1) * 0.35;
      groups.forEach(function (links) {
        var current = null;
        links.forEach(function (l) { if (l.target.getBoundingClientRect().top <= line) current = l.a; });
        links.forEach(function (l) {
          if (l.a === current) { l.a.setAttribute('aria-current', 'location'); l.a.classList.add('is-current'); }
          else { l.a.removeAttribute('aria-current'); l.a.classList.remove('is-current'); }
        });
      });
    }
    function onScroll() {
      if (ticking) return;
      ticking = true;
      global.requestAnimationFrame(update);
    }
    var offs = [on(global, 'scroll', onScroll, { passive: true }), on(global, 'resize', onScroll, { passive: true })];
    update();
    mount(function () {
      offs.forEach(function (off) { off(); });
      groups.forEach(function (links) { links.forEach(function (l) { l.a.removeAttribute('aria-current'); l.a.classList.remove('is-current'); }); });
    });
  }

  /* ── Feature: copy to clipboard ──────────────────────────────────────────
   * [data-sb-copy] (a Button's "Copy this text when clicked"): a click copies the text and, for two seconds, the label
   * reads `data-sb-copied` (announced to screen readers). Where the browser refuses to copy, the link is followed as
   * usual, which is also what happens without this file.
   */
  function initCopy(root) {
    var els = qsa(root, '[data-sb-copy]');
    if (!els.length || !global.navigator || !navigator.clipboard || !navigator.clipboard.writeText) return;
    var status = document.createElement('span');
    status.className = 'sb-sr-status';
    status.setAttribute('role', 'status');
    status.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap';
    document.body.appendChild(status);
    var offs = [];
    var timers = [];
    els.forEach(function (el) {
      var label = qs(el, '.sb-button__label') || el;
      var original = label.textContent;
      offs.push(function () { label.textContent = original; el.classList.remove('is-copied'); });
      offs.push(on(el, 'click', function (e) {
        e.preventDefault();
        var href = el.tagName === 'A' ? el.href : '';
        navigator.clipboard.writeText(el.getAttribute('data-sb-copy') || '').then(function () {
          var done = el.getAttribute('data-sb-copied') || 'Copied';
          label.textContent = done;
          status.textContent = done;
          el.classList.add('is-copied');
          global.clearTimeout(el.__sbCopied);
          el.__sbCopied = global.setTimeout(function () { label.textContent = original; el.classList.remove('is-copied'); status.textContent = ''; }, 2000);
          timers.push(el.__sbCopied);
        }, function () {
          if (href) global.location.href = href;
        });
      }));
    });
    mount(function () {
      offs.forEach(function (off) { off(); });
      timers.forEach(function (t) { global.clearTimeout(t); });
      status.remove();
    });
  }


/* ── Feature: Query Loop filter chips ────────────────────────────────────
   * `[data-sb-filter-local]`: the loop holds every item, so a chip shows its term's items in place (the others are
   * hidden, their `data-sb-terms` not holding it) instead of loading `?filter=…`; the address follows, so a reload or a
   * shared link shows the same. A loop with more items than one page keeps its links.
   */

  function initLoopFilters(root) {
    var loops = qsa(root, '[data-sb-filter-local]');
    if (!loops.length) return;
    var offs = [];
    loops.forEach(function (loop) {
      var chips = qsa(loop, '[data-sb-filter-term]');
      var items = qsa(loop, '[data-sb-terms]');
      var server = items.map(function (it) { return it.hidden; });
      var serverChip = chips.filter(function (c) { return c.hasAttribute('aria-current'); })[0] || null;
      function show(term) {
        chips.forEach(function (c) {
          if (c.getAttribute('data-sb-filter-term') === term) c.setAttribute('aria-current', 'true');
          else c.removeAttribute('aria-current');
        });
        items.forEach(function (it) {
          var terms = [];
          try { terms = JSON.parse(it.getAttribute('data-sb-terms') || '[]'); } catch (e) { terms = []; }
          it.hidden = term !== '' && terms.indexOf(term) === -1;
        });
      }
      chips.forEach(function (chip) {
        offs.push(on(chip, 'click', function (e) {
          if (e.metaKey || e.ctrlKey || e.shiftKey || e.button > 0) return;
          e.preventDefault();
          var term = chip.getAttribute('data-sb-filter-term') || '';
          show(term);
          if (global.history && history.replaceState && global.URL) {
            var url = new URL(global.location.href);
            if (term) url.searchParams.set('filter', term); else url.searchParams.delete('filter');
            history.replaceState(history.state, '', url.pathname + url.search + url.hash);
          }
        }));
      });
      offs.push(function () {
        items.forEach(function (it, i) { it.hidden = server[i]; });
        chips.forEach(function (c) { if (c === serverChip) c.setAttribute('aria-current', 'true'); else c.removeAttribute('aria-current'); });
      });
    });
    mount(function () { offs.forEach(function (off) { off(); }); });
  }

  /* ── Feature: Query Loop items in a dialog ───────────────────────────────
   * `[data-sb-loop-dialog]`: a click on an item's link (to its `data-sb-item-url`) opens that item's page in a modal
   * <dialog> over this one instead of leaving: the runtime fetches the page and shows its `<main>`, adding the styles
   * that page needs. Previous / Next walk the loop's visible items; the address follows the item (Back closes the
   * dialog), Escape and the close button go back to the list. A modified click, a failed fetch, or a browser without
   * <dialog> just follows the link. Scripts on the item's page do not run in the dialog.
   */

  function initLoopDialogs(root) {
    var loops = qsa(root, '[data-sb-loop-dialog]');
    if (!loops.length || !global.fetch || !global.DOMParser || typeof global.HTMLDialogElement !== 'function') return;
    var offs = [];
    var added = [];
    var cache = {};

    function sameOrigin(url) {
      try { return new URL(url, global.location.href).origin === global.location.origin; } catch (e) { return false; }
    }
    function load(url) {
      if (!cache[url]) {
        cache[url] = fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html' } })
          .then(function (r) { if (!r.ok) throw new Error('item ' + r.status); return r.text(); })
          .then(function (html) { return new DOMParser().parseFromString(html, 'text/html'); })
          .catch(function (err) { delete cache[url]; throw err; });
      }
      return cache[url];
    }
    // The item page's own styles that this page lacks, added once.
    function adoptStyles(doc) {
      qsa(doc.head || doc, 'style, link[rel="stylesheet"]').forEach(function (node) {
        var key = node.tagName === 'LINK' ? 'l:' + node.href : 's:' + node.textContent.length + ':' + node.textContent.slice(0, 200);
        var exists = node.tagName === 'LINK'
          ? qsa(document.head, 'link[rel="stylesheet"]').some(function (l) { return l.href === node.href; })
          : qsa(document.head, 'style').some(function (st) { return st.textContent === node.textContent; });
        if (exists || added.some(function (a) { return a.key === key; })) return;
        var copy = document.importNode(node, true);
        copy.setAttribute('data-sb-dialog-css', '');
        document.head.appendChild(copy);
        added.push({ key: key, node: copy });
      });
    }

    loops.forEach(function (loop) {
      var dialog = document.createElement('dialog');
      dialog.className = 'sb-loop-dialog';
      var bar = document.createElement('div');
      bar.className = 'sb-loop-dialog__bar';
      var button = function (cls, text) { var b = document.createElement('button'); b.type = 'button'; b.className = cls; b.textContent = text; bar.appendChild(b); return b; };
      var prev = button('sb-loop-dialog__prev', '← ' + (loop.getAttribute('data-sb-label-prev') || 'Previous'));
      var next = button('sb-loop-dialog__next', (loop.getAttribute('data-sb-label-next') || 'Next') + ' →');
      var close = button('sb-loop-dialog__close', loop.getAttribute('data-sb-label-close') || 'Close');
      var body = document.createElement('div');
      body.className = 'sb-loop-dialog__body';
      dialog.appendChild(bar);
      dialog.appendChild(body);
      document.body.appendChild(dialog);
      var current = -1;
      var opener = null;
      // The item's address is pushed once when the dialog opens (Previous / Next replace it), so Back closes it.
      var pushed = false;
      var byHistory = false;

      function visible() {
        return qsa(loop, '[data-sb-item-url]').filter(function (it) { return !it.hidden && it.getAttribute('data-sb-item-url'); });
      }
      function show(index, push) {
        var list = visible();
        if (index < 0 || index >= list.length) return;
        current = index;
        var url = list[index].getAttribute('data-sb-item-url');
        prev.disabled = index === 0;
        next.disabled = index === list.length - 1;
        body.setAttribute('aria-busy', 'true');
        if (!dialog.open) dialog.showModal();
        if (global.history && history.pushState) {
          if (push) { history.pushState({ sbLoopDialog: true }, '', url); pushed = true; }
          else if (pushed) history.replaceState({ sbLoopDialog: true }, '', url);
        }
        load(url).then(function (doc) {
          if (current !== index) return;
          adoptStyles(doc);
          var main = doc.querySelector('main#main') || doc.querySelector('main') || doc.body;
          body.innerHTML = main ? main.innerHTML : '';
          var title = doc.querySelector('h1');
          dialog.setAttribute('aria-label', (title ? title.textContent : doc.title || '').trim());
          body.removeAttribute('aria-busy');
          dialog.scrollTop = 0;
          close.focus();
        }, function () {
          global.location.assign(url);
        });
      }
      function shut() {
        if (dialog.open) dialog.close();
      }
      offs.push(on(dialog, 'close', function () {
        body.innerHTML = '';
        current = -1;
        if (pushed && !byHistory) history.back();
        pushed = false;
        byHistory = false;
        if (opener && opener.focus) opener.focus();
      }));
      offs.push(on(dialog, 'click', function (e) { if (e.target === dialog) shut(); }));
      offs.push(on(prev, 'click', function () { show(current - 1, false); }));
      offs.push(on(next, 'click', function () { show(current + 1, false); }));
      offs.push(on(close, 'click', function () { shut(); }));
      offs.push(on(global, 'popstate', function () { if (dialog.open) { byHistory = true; dialog.close(); } }));
      offs.push(on(loop, 'click', function (e) {
        if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button > 0) return;
        var link = e.target.closest ? e.target.closest('a[href]') : null;
        var item = link && link.closest('[data-sb-item-url]');
        if (!item || !loop.contains(item) || !sameOrigin(link.href)) return;
        var target = new URL(item.getAttribute('data-sb-item-url'), global.location.href);
        if (new URL(link.href).pathname !== target.pathname) return;
        e.preventDefault();
        opener = link;
        show(visible().indexOf(item), true);
      }));
      offs.push(function () { if (dialog.open) dialog.close(); dialog.remove(); });
    });
    mount(function () {
      offs.forEach(function (off) { off(); });
      added.forEach(function (a) { a.node.remove(); });
    });
  }

/* ── Feature: wave text ──────────────────────────────────────────────────
   * `[data-sb-wave="low,high,radius"]`: the text is split into letters (words kept whole so lines break as before; the
   * full text stays for screen readers in a hidden copy). With a mouse, each letter's weight rises from `low` toward
   * `high` as the pointer comes within `radius` px of it; on a touch screen the stylesheet rolls the weight through the
   * letters. Needs a variable font to look smooth; a static font jumps between its weights. Off with reduced motion.
   */

  function initWave(root) {
    var els = qsa(root, '[data-sb-wave]');
    if (!els.length || reducedMotion()) return;
    var offs = [];
    var waves = [];
    els.forEach(function (el) {
      var parts = (el.getAttribute('data-sb-wave') || '').split(',').map(Number);
      var low = clamp(parts[0] || 300, 100, 900), high = clamp(parts[1] || 800, 100, 900), radius = clamp(parts[2] || 160, 40, 600);
      var original = el.innerHTML;
      var i = 0;
      var hide = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap';
      // Each word: its text for screen readers, and its letters (hidden from them) for the eye. Tags stay where they were.
      var splitText = function (node) {
        var frag = document.createDocumentFragment();
        node.nodeValue.split(/(\s+)/).forEach(function (chunk) {
          if (!chunk) return;
          if (/^\s+$/.test(chunk)) { frag.appendChild(document.createTextNode(chunk)); return; }
          var word = document.createElement('span');
          word.className = 'sb-wave__w';
          var sr = document.createElement('span');
          sr.style.cssText = hide;
          sr.textContent = chunk;
          var shown = document.createElement('span');
          shown.setAttribute('aria-hidden', 'true');
          Array.from(chunk).forEach(function (ch) {
            var l = document.createElement('span');
            l.className = 'sb-wave__l';
            l.textContent = ch;
            l.style.setProperty('--i', String(i++));
            shown.appendChild(l);
          });
          word.appendChild(sr);
          word.appendChild(shown);
          frag.appendChild(word);
        });
        node.parentNode.replaceChild(frag, node);
      };
      (function walk(node) {
        var child = node.firstChild;
        while (child) {
          var next = child.nextSibling;
          if (child.nodeType === 3) {
            if (child.nodeValue.trim()) splitText(child);
          } else if (child.nodeType === 1 && !/^(script|style|svg|br|img|video|iframe|input|textarea|select|button)$/i.test(child.nodeName)) {
            walk(child);
          }
          child = next;
        }
      })(el);
      el.setAttribute('data-sb-wave-on', '');
      waves.push({ el: el, letters: qsa(el, '.sb-wave__l'), low: low, high: high, radius: radius });
      offs.push(function () { el.innerHTML = original; el.removeAttribute('data-sb-wave-on'); });
    });

    if (global.matchMedia && global.matchMedia('(hover: hover) and (pointer: fine)').matches) {
      var x = -1e4, y = -1e4, frame = 0;
      var draw = function () {
        frame = 0;
        waves.forEach(function (w) {
          var r = w.el.getBoundingClientRect();
          var near = x > r.left - w.radius && x < r.right + w.radius && y > r.top - w.radius && y < r.bottom + w.radius;
          if (!near && !w.lit) return;
          w.lit = near;
          w.letters.forEach(function (l) {
            if (!near) { l.style.fontWeight = ''; return; }
            var b = l.getBoundingClientRect();
            var d = Math.hypot(x - (b.left + b.width / 2), y - (b.top + b.height / 2));
            l.style.fontWeight = String(Math.round(w.low + (w.high - w.low) * Math.max(0, 1 - d / w.radius)));
          });
        });
      };
      offs.push(on(global, 'pointermove', function (e) { x = e.clientX; y = e.clientY; if (!frame) frame = global.requestAnimationFrame(draw); }, { passive: true }));
      offs.push(on(document, 'pointerleave', function () { x = y = -1e4; if (!frame) frame = global.requestAnimationFrame(draw); }));
      offs.push(function () { if (frame) global.cancelAnimationFrame(frame); });
    } else {
      document.documentElement.setAttribute('data-sb-wave-auto', '');
      offs.push(function () { document.documentElement.removeAttribute('data-sb-wave-auto'); });
    }
    mount(function () { offs.forEach(function (off) { off(); }); });
  }

/* ── Feature: message widget ─────────────────────────────────────────────
   * `[data-sb-msg]`: the launcher opens and closes its panel (the link's `#id` and `:target` already do without this),
   * Escape closes it and gives focus back, a topic chip is a radio (no script needed). It opens by itself after
   * `data-sb-open-after` seconds or `data-sb-open-scroll` % of the page, once; a visitor who closed it is not asked
   * again (`data-sb-msg-remember`, kept in localStorage). The online dot and text follow the owner's hours in their
   * time zone, checked every minute, as the cached page's own answer goes stale.
   */

  /** Weekday (1 = Monday) and minute of the day now in a time zone, or null when the browser does not know it. */
  function zonedNow(zone) {
    try {
      var parts = new Intl.DateTimeFormat('en-GB', { timeZone: zone, weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date());
      var get = function (type) { var p = parts.filter(function (x) { return x.type === type; })[0]; return p ? p.value : ''; };
      var day = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].indexOf(get('weekday')) + 1;
      return day ? { day: day, minute: (parseInt(get('hour'), 10) % 24) * 60 + parseInt(get('minute'), 10) } : null;
    } catch (e) { return null; }
  }

  /** Same rule as MessageWidgetRenderer::isOnline: an end before the start runs past midnight. */
  function onlineAt(now, hours, days) {
    var m = /^(\d\d):(\d\d)-(\d\d):(\d\d)$/.exec(hours || '');
    if (!now || !m) return null;
    var from = +m[1] * 60 + +m[2];
    var to = +m[3] * 60 + +m[4];
    var on = function (d) { return days.indexOf(d) !== -1; };
    if (from === to) return on(now.day);
    if (from < to) return on(now.day) && now.minute >= from && now.minute < to;
    return (on(now.day) && now.minute >= from) || (on(now.day === 1 ? 7 : now.day - 1) && now.minute < to);
  }

  function initMessageWidgets(root) {
    var widgets = qsa(root, '[data-sb-msg]').filter(function (w) { return !w.classList.contains('sb-msg--editor'); });
    if (!widgets.length) return;
    var offs = [];
    var timers = [];
    widgets.forEach(function (w) {
      var panel = qs(w, '.sb-msg__panel');
      var launcher = qs(w, '[data-sb-msg-open]');
      if (!panel || !launcher) return;
      var key = 'sb-msg-closed:' + panel.id;
      var remember = w.hasAttribute('data-sb-msg-remember');
      var closedBefore = false;
      try { closedBefore = remember && global.localStorage.getItem(key) === '1'; } catch (e) { closedBefore = false; }

      function open(focus) {
        panel.hidden = false;
        launcher.setAttribute('aria-expanded', 'true');
        w.classList.add('is-open');
        if (focus) { var first = qs(panel, 'input:not([type="hidden"]):not([tabindex="-1"]),textarea'); if (first) first.focus(); }
      }
      function close(byVisitor) {
        panel.hidden = true;
        launcher.setAttribute('aria-expanded', 'false');
        w.classList.remove('is-open');
        if (global.location.hash === '#' + panel.id && global.history && history.replaceState) history.replaceState(null, '', global.location.pathname + global.location.search);
        if (byVisitor && remember) { try { global.localStorage.setItem(key, '1'); } catch (e) { /* this view only */ } }
      }
      if (global.location.hash === '#' + panel.id) open(false);
      var server = { online: w.classList.contains('is-online'), text: null };
      offs.push(function () {
        panel.hidden = true;
        launcher.setAttribute('aria-expanded', 'false');
        w.classList.remove('is-open');
        w.classList.toggle('is-online', server.online);
        w.classList.toggle('is-away', !server.online);
        if (server.text !== null && text) text.textContent = server.text;
      });

      offs.push(on(launcher, 'click', function (e) {
        e.preventDefault();
        if (panel.hidden) open(true); else { close(true); launcher.focus(); }
      }));
      qsa(panel, '[data-sb-msg-close]').forEach(function (b) {
        offs.push(on(b, 'click', function (e) { e.preventDefault(); close(true); launcher.focus(); }));
      });
      offs.push(on(document, 'keydown', function (e) {
        if (e.key === 'Escape' && !panel.hidden) { close(true); launcher.focus(); }
      }));

      // Opening by itself: once, and never for a visitor who closed it.
      var auto = function () { if (panel.hidden && !closedBefore && !w.__sbAutoOpened) { w.__sbAutoOpened = true; open(false); } };
      var delay = parseInt(w.getAttribute('data-sb-open-after') || '0', 10);
      if (delay > 0 && !closedBefore) timers.push(global.setTimeout(auto, delay * 1000));
      var share = parseInt(w.getAttribute('data-sb-open-scroll') || '0', 10);
      if (share > 0 && !closedBefore) {
        offs.push(on(global, 'scroll', function () {
          var max = document.documentElement.scrollHeight - global.innerHeight;
          if (max > 0 && global.scrollY / max * 100 >= share) auto();
        }, { passive: true }));
      }

      // Online status, in the owner's zone.
      var zone = w.getAttribute('data-sb-tz') || '';
      var days = (w.getAttribute('data-sb-days') || '').split(',').map(Number);
      var status = qs(w, '[data-sb-msg-status]');
      var text = status && qs(status, '[data-sb-msg-status-text]');
      var who = w.getAttribute('data-sb-who') || '';
      if (text) server.text = text.textContent;
      var tick = function () {
        var online = onlineAt(zonedNow(zone), w.getAttribute('data-sb-hours'), days);
        if (online === null) return;
        w.classList.toggle('is-online', online);
        w.classList.toggle('is-away', !online);
        if (text) text.textContent = (who ? who + ' · ' : '') + status.getAttribute(online ? 'data-sb-online' : 'data-sb-away');
      };
      tick();
      timers.push(global.setInterval(tick, 60000));
    });
    mount(function () {
      offs.forEach(function (off) { off(); });
      timers.forEach(function (t) { global.clearTimeout(t); global.clearInterval(t); });
    });
  }

  /* ── Feature: local time ─────────────────────────────────────────────────
   * `[data-sb-clock]` shows the time in its `data-sb-tz`, ticking each second (or minute); the server's time stays
   * when the browser does not know the zone.
   */

  function initClocks(root) {
    var clocks = qsa(root, '[data-sb-clock]');
    if (!clocks.length || !global.Intl) return;
    var timers = [];
    clocks.forEach(function (el) {
      var seconds = el.hasAttribute('data-sb-seconds');
      var fmt;
      try {
        fmt = new Intl.DateTimeFormat('en-US', { timeZone: el.getAttribute('data-sb-tz') || undefined, hour: el.hasAttribute('data-sb-h12') ? 'numeric' : '2-digit', minute: '2-digit', second: seconds ? '2-digit' : undefined, hourCycle: el.hasAttribute('data-sb-h12') ? 'h12' : 'h23' });
      } catch (e) { return; }
      var draw = function () { el.textContent = fmt.format(new Date()); };
      draw();
      timers.push(global.setInterval(draw, seconds ? 1000 : 15000));
    });
    mount(function () { timers.forEach(function (t) { global.clearInterval(t); }); });
  }


/* ── Feature: click and scroll interaction triggers ──────────────────────
   * The renderer marks a block with `data-sb-interaction-trigger`.
   *   click:  each click (re)plays the block's chosen animation: data-sb-play is removed, a reflow restarts
   *           the animation, and it is set again. The stylesheet holds the animation until then.
   *   scroll: where the browser has no scroll timelines (animation-timeline: view()), a block with no
   *           animation of its own drifts against the scroll here instead, transform-only.
   * Reduced motion: nothing plays and nothing moves.
   */

  function initInteractions(root) {
    var clicks = qsa(root, '[data-sb-interaction-trigger="click"]');
    var offs = [];
    if (!reducedMotion()) {
      clicks.forEach(function (el) {
        offs.push(on(el, 'click', function () {
          el.removeAttribute('data-sb-play');
          void el.offsetWidth; // restart the animation, also when it is clicked again mid-play
          el.setAttribute('data-sb-play', '');
        }));
        offs.push(on(el, 'animationend', function () { el.removeAttribute('data-sb-play'); }));
      });
    }

    var timelines = global.CSS && global.CSS.supports && global.CSS.supports('animation-timeline: view()');
    var drifters = (timelines || reducedMotion()) ? [] : qsa(root, '[data-sb-interaction-trigger="scroll"]').filter(function (el) {
      return !/(^|\s)sb-animate-/.test(el.className || '');
    });
    var ticking = false;
    function update() {
      ticking = false;
      var vh = global.innerHeight || 1;
      drifters.forEach(function (el) {
        var rect = el.getBoundingClientRect();
        if (rect.bottom < 0 || rect.top > vh) return;
        // 0 as the block enters at the bottom, 1 as it leaves at the top: the same range as the CSS rule.
        var progress = clamp((vh - rect.top) / (vh + rect.height), 0, 1);
        el.style.transform = 'translate3d(0,' + (32 - 64 * progress).toFixed(2) + 'px,0)';
      });
    }
    function onScroll() {
      if (ticking) return;
      ticking = true;
      global.requestAnimationFrame(update);
    }
    if (drifters.length) {
      offs.push(on(global, 'scroll', onScroll, { passive: true }));
      offs.push(on(global, 'resize', onScroll, { passive: true }));
      update();
    }

    if (!offs.length) return;
    mount(function () {
      offs.forEach(function (off) { off(); });
      clicks.forEach(function (el) { el.removeAttribute('data-sb-play'); });
      drifters.forEach(function (el) { el.style.transform = ''; });
    });
  }

/* ── Feature: overlay focus trap ────────────────────────────────────────
   * Shared by modal, offcanvas and lightbox. The current Kohevo modal relies on
   * CSS `:target`, which cannot trap focus, cannot close on Escape, and leaves
   * aria-hidden="true" permanently attached to a visible dialog. This is the fix.
   */

  var openOverlays = [];

  /**
   * Confine Tab to `container` and hand Escape to `onEscape`.
   *
   * `onEscape` must be the overlay's real close function. An earlier version
   * closed a local scope here instead, which tore down the key handler and
   * restored focus but left the dialog on screen — Escape looked like it did
   * something while the modal stayed open.
   */
  function trapFocus(container, onEscape) {
    function focusables() {
      return qsa(container, CONFIG.FOCUSABLE).filter(function (el) {
        return el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement;
      });
    }

    function onKey(e) {
      if (e.key === 'Escape' || e.key === 'Esc') {
        e.preventDefault();
        onEscape();
        return;
      }
      if (e.key !== 'Tab') return;
      var list = focusables();
      if (!list.length) { e.preventDefault(); return; }
      var first = list[0];
      var last = list[list.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    // Move focus inside on open, and remember where it came from.
    //
    // The target is resolved INSIDE the timeout, not here. `trapFocus` runs
    // before openOverlay sets `data-sb-open`, so at this point the panel is
    // still `display:none`; measuring now rejects every descendant for being
    // 0x0 and focus never lands in the dialog at all. Deferring past the
    // attribute write lets the visibility filter see the laid-out dialog.
    var previous = document.activeElement;
    global.setTimeout(function () {
      var initial = qs(container, '[data-sb-autofocus]') || focusables()[0] || container;
      // A dialog with nothing focusable inside still has to receive focus, or
      // Tab escapes to the page behind. tabindex="-1" makes it programmatically
      // focusable without adding it to the tab order.
      if (initial === container && !container.hasAttribute('tabindex')) {
        container.setAttribute('tabindex', '-1');
      }
      try { initial.focus(); } catch (err) { /* detached */ }
    }, 0);
    document.addEventListener('keydown', onKey, true);

    return function release() {
      document.removeEventListener('keydown', onKey, true);
      try { if (previous && previous.focus) previous.focus(); } catch (err) { /* detached */ }
    };
  }

  function openOverlay(el, trigger) {
    if (el.hasAttribute('data-sb-open')) return;

    // Read before close() is declared: close() needs the scroll offset that was
    // captured when the lock was applied, not wherever the page happens to be
    // when Escape is finally pressed.
    var lockedY = global.scrollY;

    // Declared first so the focus trap and the click-away handler both have a
    // complete close() to call, including the Escape path inside the trap.
    var release = null;
    function close() {
      if (!el.hasAttribute('data-sb-open')) return;
      el.removeAttribute('data-sb-open');
      // Triggers (and the lightbox) open a panel only when it has no close() of its own, so a closed panel must not
      // keep this one: a stale close() is a no-op, and the panel would never open again.
      if (el.__sbClose === close) delete el.__sbClose;
      // The no-JS fallback opens a panel with `:target`, so a hash that still names it would keep it on screen.
      clearOverlayHash(el);
      // Closed state is aria-hidden="true". Open state removes the attribute
      // entirely — aria-hidden="false" is redundant and is announced oddly by
      // some screen readers.
      el.setAttribute('aria-hidden', 'true');
      if (trigger && trigger.setAttribute) trigger.setAttribute('aria-expanded', 'false');

      // Drop this overlay from the stack BEFORE testing it, otherwise the last
      // overlay to close still sees a non-empty stack and never releases the
      // page scroll lock.
      var i = openOverlays.findIndex(function (o) { return o.el === el; });
      if (i !== -1) openOverlays.splice(i, 1);

      if (!openOverlays.length) {
        document.documentElement.removeAttribute('data-sb-overlay-open');
        // The stylesheet locks via this class on <html>; the inline styles
        // cleared below are the other half. Both go together or the page is
        // left unscrollable with the dialog already gone.
        document.documentElement.classList.remove('sb-scroll-lock');
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.width = '';
        global.scrollTo(0, lockedY);
      }
      if (release) release();
    }

    release = trapFocus(el, close);

    el.setAttribute('data-sb-open', '');
    el.removeAttribute('aria-hidden');
    document.documentElement.setAttribute('data-sb-overlay-open', '');

    // Lock the page behind the overlay without causing a layout shift. The class is
    // what the stylesheet hooks; the inline styles preserve the scroll position
    // that `overflow:hidden` alone would lose on iOS.
    document.documentElement.classList.add('sb-scroll-lock');
    document.body.style.position = 'fixed';
    document.body.style.top = '-' + lockedY + 'px';
    document.body.style.width = '100%';

    if (trigger && trigger.setAttribute) trigger.setAttribute('aria-expanded', 'true');
    openOverlays.push({ el: el, close: close });
    el.__sbClose = close;
    el.dispatchEvent(new CustomEvent('sb:open', { bubbles: true }));
    return close;
  }

  /** Drop `#<panel id>` from the address without navigating or scrolling (see the `:target` fallback). */
  function clearOverlayHash(el) {
    if (!el.id || global.location.hash !== '#' + el.id || !global.history || !global.history.replaceState) return;
    try {
      global.history.replaceState(global.history.state, '', global.location.pathname + global.location.search);
    } catch (err) { /* a sandboxed frame may refuse; the panel still closes once the hash changes */ }
  }

  function closeAllOverlays() {
    openOverlays.slice().reverse().forEach(function (o) { o.close(); });
  }
/* ── Feature: modal + offcanvas ──────────────────────────────────────────
   * One mechanism, two geometries — which is how ModalRenderer and
   * OffcanvasRenderer already share the data-sb-* convention.
   */

  function initOverlays(root) {
    var teardowns = [];

    // Panels are matched by CLASS as well as attribute: `ModalRenderer` and
    // `OffcanvasRenderer` already emit `.sb-modal` / `.sb-offcanvas` (plus the
    // full role/aria set), and compiled pages are cached — so requiring a
    // `data-sb-modal` attribute the current renderers never write would leave
    // every already-compiled page un-enhanced until it was invalidated.
    qsa(root, '[data-sb-modal], [data-sb-offcanvas], .sb-modal, .sb-offcanvas').forEach(function (el) {
      if (!el.id) el.id = uniqueId();
      el.setAttribute('role', 'dialog');
      el.setAttribute('aria-modal', 'true');
      // Progressive enhancement: with no JS the trigger stays a real fragment
      // link, and :target still reveals the panel.
      el.setAttribute('aria-hidden', 'true');
      el.setAttribute('data-sb-overlay', '');

      // `position: fixed` is relative to the nearest ancestor with a transform, filter or backdrop-filter. A panel inside
      // an animated block, or inside a sticky blurred header, would be clipped to that block instead of covering the page.
      // So it lives on <body> while the runtime is on, and goes back where the author put it when the runtime is disabled.
      var home = el.parentNode;
      var marker = home && home !== document.body && document.createComment('sb-overlay');
      // Out of its block the block's part styles (Panel, Close button...) would no longer match: they are written under
      // the block's scoped class, and also under `[data-sb-scope~="<that class>"]`, which the panel carries while moved.
      var owner = home && home.closest && home.closest('.sb-block');
      var scope = owner ? Array.prototype.filter.call(owner.classList, function (c) { return /^sb-x-[0-9a-f]{16}$/.test(c); }).join(' ') : '';
      if (marker) {
        home.insertBefore(marker, el);
        document.body.appendChild(el);
        if (scope) el.setAttribute('data-sb-scope', scope);
      }

      var off = [];
      off.push(on(el, 'click', function (e) {
        // Closers: the renderers emit kind-scoped attributes (`data-sb-modal-close` /
        // `data-sb-offcanvas-close`) and a bare `.sb-modal__backdrop`, so all of
        // them must be accepted.
        var closer = e.target.closest('[data-sb-close], [data-sb-modal-close], [data-sb-offcanvas-close], .sb-modal__backdrop, .sb-offcanvas__backdrop');
        if (!closer || !el.contains(closer)) return;
        e.preventDefault();
        if (el.__sbClose) el.__sbClose();
      }));

      // Kind is decided by whichever signal is present. Class matching means an
      // element can arrive as a bare `.sb-modal`, so testing only the
      // `data-sb-modal` attribute would silently label every such panel
      // "offcanvas" and then wire up offcanvas triggers for it.
      var kind = el.hasAttribute('data-sb-modal') || el.classList.contains('sb-modal')
        ? 'modal'
        : 'offcanvas';
      var openAttr = 'data-sb-' + kind + '-open';

      // Accept the kind-specific open attribute, and the generic one the
      // prototype used, so a trigger keeps working whichever it was written
      // with. Generic triggers are wired to the panel they actually name.
      // Only the triggers that name THIS panel: every panel on the page runs this block, so an unfiltered
      // list wires each trigger once per panel, and the second handler closes what the first one opened.
      var names = function (t, attr) { return document.getElementById(t.getAttribute(attr)) === el; };
      var triggers = qsa(root, '[' + openAttr + ']').filter(function (t) { return names(t, openAttr); });
      if (kind === 'modal') triggers = triggers.concat(qsa(root, '[data-sb-offcanvas-open]').filter(function (t) {
        return document.getElementById(t.getAttribute('data-sb-offcanvas-open')) === el;
      }));
      else triggers = triggers.concat(qsa(root, '[data-sb-modal-open]').filter(function (t) {
        return document.getElementById(t.getAttribute('data-sb-modal-open')) === el;
      }));

      triggers.forEach(function (trigger) {
        var target = trigger.getAttribute(openAttr);
        trigger.setAttribute('href', '#' + target);
        trigger.setAttribute('aria-controls', target);
        trigger.setAttribute('aria-haspopup', 'dialog');
        if (trigger.getAttribute('aria-expanded') === null) trigger.setAttribute('aria-expanded', 'false');

        off.push(on(trigger, 'click', function (e) {
          var targetEl = document.getElementById(target);
          if (!targetEl) return; // leave native fragment navigation alone
          e.preventDefault();
          if (targetEl.__sbClose) targetEl.__sbClose();
          else openOverlay(targetEl, trigger);
        }));
      });

      teardowns.push(function () {
        off.forEach(function (un) { un(); });
        if (el.__sbClose) el.__sbClose();
        el.removeAttribute('data-sb-open');
        el.setAttribute('aria-hidden', 'true');
        el.removeAttribute('data-sb-scope');
        if (marker && marker.parentNode) {
          marker.parentNode.insertBefore(el, marker);
          marker.parentNode.removeChild(marker);
        }
      });
    });

    if (!teardowns.length) return;

    // A hash that names a panel (a click before this script ran, a reload, a shared link) shows it through `:target`,
    // which the runtime cannot close. Take it over: open the panel the normal way and drop the hash, so every close
    // path works.
    function adoptHash() {
      var id = (global.location.hash || '').slice(1);
      if (!id) return;
      var el = null;
      try { el = document.getElementById(decodeURIComponent(id)); } catch (err) { return; }
      if (!el || !el.hasAttribute('data-sb-overlay')) return;
      clearOverlayHash(el);
      var esc = global.CSS && global.CSS.escape ? global.CSS.escape(el.id) : el.id;
      if (!el.__sbClose) openOverlay(el, document.querySelector('[aria-controls="' + esc + '"]'));
    }
    // `:target` is only the no-JS fallback. Once the runtime owns a panel (`data-sb-overlay`), `data-sb-open` alone
    // decides whether it shows: removing the hash with replaceState does not update `:target`, so without this rule a
    // panel the address once named would stay on screen after it closed.
    var guard = document.createElement('style');
    guard.setAttribute('data-sb-overlay-guard', '');
    guard.textContent = '.sb-modal[data-sb-overlay]:target:not([data-sb-open]),'
      + '.sb-offcanvas[data-sb-overlay]:target:not([data-sb-open]){display:none}';
    (document.head || document.documentElement).appendChild(guard);

    adoptHash();
    var offHash = on(global, 'hashchange', adoptHash);

    mount(function () {
      offHash();
      if (guard.parentNode) guard.parentNode.removeChild(guard);
      closeAllOverlays();
      teardowns.forEach(function (fn) { fn(); });
      qsa(root, '[aria-haspopup="dialog"]').forEach(function (el) {
        el.setAttribute('aria-expanded', 'false');
      });
    });
  }

  /* ── Feature: gallery layouts and paging ──────────────────────────────────
   * `GalleryRenderer` prints every image with the first page showing, so a page without
   * this feature still shows the first page; a <noscript> rule in the block shows the rest.
   * Masonry places each image in the shortest column, then stretches every column a little so
   * they all end level. Justified only needs each image's shape, which CSS turns into rows.
   */
  function initGalleries(root) {
    qsa(root, '.sb-gallery[data-sb-gallery]').forEach(function (grid) {
      var layout = grid.getAttribute('data-sb-gallery');
      var box = grid.parentNode;
      var all = qsa(grid, '.sb-gallery__item');
      if (grid.getAttribute('data-sb-order') === 'random') {
        for (var s = all.length - 1; s > 0; s--) {
          var pick = Math.floor(Math.random() * (s + 1));
          var held = all[s]; all[s] = all[pick]; all[pick] = held;
        }
        all.forEach(function (fig) { grid.appendChild(fig); });
      }
      var perPage = parseInt(grid.getAttribute('data-sb-per-page') || '0', 10) || all.length;
      if (layout === 'mosaic') perPage = Math.max(5, Math.round(perPage / 5) * 5);
      var more = qs(box, '.sb-gallery__more');
      var button = more ? qs(more, '.sb-gallery__more-btn') : null;
      var count = more ? qs(more, '.sb-gallery__count') : null;
      var filters = qsa(box, '.sb-gallery__filter');
      var shown = perPage;
      var filter = '';
      var off = [];
      var frame = 0;

      function matching() {
        return all.filter(function (f) { return filter === '' || f.getAttribute('data-sb-cat') === filter; });
      }
      function ratio(fig) {
        var img = qs(fig, 'img');
        return img && img.naturalWidth > 0 ? img.naturalWidth / img.naturalHeight : 0;
      }
      function placeMasonry(items) {
        var style = global.getComputedStyle(grid);
        var cols = style.gridTemplateColumns.split(' ').filter(Boolean).length || 1;
        var gap = parseFloat(style.columnGap) || 0;
        if (grid.clientWidth <= 0) return;
        var width = (grid.clientWidth - gap * (cols - 1)) / cols;
        var columns = [];
        for (var c = 0; c < cols; c++) columns.push({ h: 0, items: [] });
        items.forEach(function (fig) {
          var r = ratio(fig) || parseFloat(fig.style.getPropertyValue('--sb-gal-r')) || 1.5;
          var h = width / r;
          var shortest = columns.reduce(function (a, b) { return b.h < a.h ? b : a; });
          shortest.items.push({ fig: fig, h: h });
          shortest.h += h + gap;
        });
        var tallest = columns.reduce(function (a, b) { return b.h > a.h ? b : a; }).h;
        grid.classList.add('is-placed');
        columns.forEach(function (col, i) {
          var used = col.h - gap * col.items.length;
          var stretch = used > 0 ? (tallest - gap * col.items.length) / used : 1;
          var top = 1;
          col.items.forEach(function (entry) {
            var span = Math.round(entry.h * stretch);
            entry.fig.style.gridColumn = String(i + 1);
            entry.fig.style.gridRow = top + ' / span ' + span;
            top += span + Math.round(gap);
          });
        });
      }
      function arrange() {
        frame = 0;
        var list = matching();
        var visible = list.slice(0, shown);
        all.forEach(function (f) {
          var inFilter = list.indexOf(f) >= 0;
          f.classList.toggle('is-filtered', !inFilter);
          f.classList.toggle('is-more', inFilter && visible.indexOf(f) < 0);
        });
        visible.forEach(function (f, i) {
          var img = qs(f, 'img');
          if (img) img.loading = 'eager';
          var r = ratio(f);
          if (layout === 'justified' && r > 0) f.style.setProperty('--sb-gal-r', r.toFixed(3));
          if (layout === 'mosaic') f.classList.toggle('is-big', i % 10 === 0 || i % 10 === 6);
        });
        if (layout === 'masonry') placeMasonry(visible);
        if (layout === 'justified') {
          grid.classList.remove('is-ragged');
          var last = visible[visible.length - 1];
          var limit = parseFloat(global.getComputedStyle(grid).getPropertyValue('--sb-gal-h')) || 240;
          if (last && last.offsetHeight > limit * 1.7) grid.classList.add('is-ragged');
        }
        if (more) {
          more.hidden = list.length <= shown;
          if (count) count.textContent = 'Showing ' + visible.length + ' of ' + list.length;
        }
      }
      function schedule() {
        if (!frame) frame = global.requestAnimationFrame(arrange);
      }
      function nextPage() {
        shown += perPage;
        arrange();
      }

      off.push(on(grid, 'load', schedule, true));
      off.push(on(global, 'resize', schedule, { passive: true }));
      if (button) off.push(on(button, 'click', nextPage));
      filters.forEach(function (b) {
        off.push(on(b, 'click', function () {
          filter = b.getAttribute('data-sb-filter') || '';
          shown = perPage;
          filters.forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
          arrange();
        }));
      });
      var observer = null;
      if (more && grid.getAttribute('data-sb-pagination') === 'infinite' && 'IntersectionObserver' in global) {
        observer = new global.IntersectionObserver(function (entries) {
          if (entries.some(function (e) { return e.isIntersecting; })) nextPage();
        }, { rootMargin: '300px 0px' });
        observer.observe(more);
      }
      arrange();

      mount(function () {
        off.forEach(function (fn) { if (typeof fn === 'function') fn(); });
        if (observer) observer.disconnect();
        if (frame) global.cancelAnimationFrame(frame);
      });
    });
  }

  /* ── Feature: gallery lightbox ────────────────────────────────────────────
   * `GalleryRenderer` emits plain `<figure class="sb-gallery__item"><img …></figure>`
   * markup and no lightbox container, so this feature BUILDS one at runtime
   * rather than requiring the renderer to emit it.
   *
   * Building rather than templating is deliberate: compiled pages are cached, so
   * a renderer change would leave every already-compiled gallery un-enhanced
   * until its compilation was invalidated. Synthesising the overlay here means
   * existing pages get the behaviour for free, and a page without the runtime
   * still shows a perfectly good static grid.
   */
  function initGalleryLightbox(root) {
    var figures = qsa(root, '.sb-gallery:not([data-sb-no-lightbox]) .sb-gallery__item').filter(function (f) { return !!qs(f, 'img'); });
    if (figures.length < 1) return;

    // A gallery small enough to see at once is not a gallery.
    if (figures.length < 2) return;

    var doc = root.ownerDocument || global.document;
    if (!doc || !doc.createElement) return;

    var lb = doc.createElement('div');
    lb.className = 'sb-lightbox';
    lb.setAttribute('data-sb-lightbox', '');
    lb.setAttribute('role', 'dialog');
    lb.setAttribute('aria-modal', 'true');
    // Labels come from the gallery (translated by the server); the English ones are for markup that has none.
    var galleryEl = figures[0].closest ? figures[0].closest('.sb-gallery') : null;
    var glabel = function (name, fallback) {
      var v = (galleryEl && galleryEl.getAttribute('data-sb-label-' + name)) || fallback;
      return String(v).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    };
    lb.setAttribute('aria-label', (galleryEl && galleryEl.getAttribute('data-sb-label-viewer')) || 'Image viewer');
    lb.setAttribute('data-sb-overlay', '');
    lb.innerHTML =
      '<button type="button" class="sb-lightbox__nav sb-lightbox__nav--prev" data-sb-lb-prev aria-label="' + glabel('prev', 'Previous image') + '">&lsaquo;</button>'
      + '<figure class="sb-lightbox__figure">'
      + '<img class="sb-lightbox__image" alt="">'
      + '<figcaption class="sb-lightbox__caption" data-sb-lightbox-caption></figcaption>'
      + '</figure>'
      + '<button type="button" class="sb-lightbox__nav sb-lightbox__nav--next" data-sb-lb-next aria-label="' + glabel('next', 'Next image') + '">&rsaquo;</button>'
      + '<button type="button" class="sb-lightbox__close" data-sb-close aria-label="' + glabel('close', 'Close') + '">&times;</button>';
    (doc.body || root).appendChild(lb);

    // Hand the freshly built container to the existing lightbox feature.
    lb.setAttribute('data-sb-lightbox-host', '');
    figures.forEach(function (fig) {
      fig.setAttribute('data-sb-lightbox-item', '');
      fig.setAttribute('role', 'button');
      fig.setAttribute('tabindex', '0');
    });

    // The node we appended and the attributes we stamped on the figures are
    // ours, so disable() has to take them back out — otherwise the A/B toggle
    // leaves an orphan overlay in <body> and the "static" page is permanently
    // marked enhanced.
    mount(function () {
      if (lb.__sbClose) lb.__sbClose();
      if (lb.parentNode) lb.parentNode.removeChild(lb);
      figures.forEach(function (fig) {
        fig.removeAttribute('data-sb-lightbox-item');
        fig.removeAttribute('role');
        fig.removeAttribute('tabindex');
      });
    });
  }

  /* ── Feature: lightbox ───────────────────────────────────────────────────
   * Upgrades gallery items into a keyboard-navigable full-screen viewer,
   * reusing the same overlay machinery.
   */

  function initLightbox(root) {
    var lb = qs(root, '[data-sb-lightbox]');
    var items = qsa(root, '[data-sb-lightbox-item]');
    if (!lb || !items.length) return;

    var img = qs(lb, 'img');
    var cap = qs(lb, '[data-sb-lightbox-caption]');
    var index = 0;

    function show(i) {
      var item = items[i];
      if (!item) return;
      index = (i + items.length) % items.length;
      var thumb = qs(item, 'img');
      var source = item.getAttribute('data-sb-full') || (thumb && thumb.getAttribute('src'));
      if (img && source) {
        img.src = source;
        img.alt = item.getAttribute('data-sb-alt') || (thumb && thumb.alt) || '';
      }
      if (cap) cap.textContent = item.getAttribute('data-sb-caption') || '';
      if (!lb.__sbClose) openOverlay(lb, null);
    }

    var off = items.map(function (item, i) {
      item.setAttribute('tabindex', '0');
      item.setAttribute('role', 'button');
      var activate = function (e) { e.preventDefault(); show(i); };
      return [
        on(item, 'click', activate),
        on(item, 'keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') activate(e);
        })
      ].reduce(function (acc, fn) { acc.push(fn); return acc; }, []);
    }).reduce(function (a, b) { return a.concat(b); }, []);

    off.push(on(lb, 'click', function (e) {
      if (e.target.closest('[data-sb-lb-prev]')) { e.preventDefault(); show(index - 1); return; }
      if (e.target.closest('[data-sb-lb-next]')) { e.preventDefault(); show(index + 1); return; }
      // The close button, or the dark backdrop around the picture. Modals and off-canvas panels get these from their own
      // set-up; the lightbox is neither, so without this only Escape closed it and the × did nothing.
      if (e.target.closest('[data-sb-close]') || e.target === lb) {
        e.preventDefault();
        if (lb.__sbClose) lb.__sbClose();
      }
    }));
    off.push(on(document, 'keydown', function (e) {
      if (!lb.hasAttribute('data-sb-open')) return;
      if (e.key === 'ArrowLeft') show(index - 1);
      if (e.key === 'ArrowRight') show(index + 1);
    }));

    mount(function () { off.forEach(function (un) { un(); }); });
  }
  /* ── Feature: audio ─────────────────────────────────────
   * A provider link is a card whose player waits in a <template>; the play
   * button swaps it in, so nothing loads from the provider before the visitor
   * asks. A self-hosted file plays in the card's <audio>, driven by its button
   * and progress bar. With no JS the card keeps its link to the source.
   */

  function audioTime(s) {
    if (!isFinite(s)) return '0:00';
    var m = Math.floor(s / 60);
    var r = Math.floor(s % 60);
    return m + ':' + (r < 10 ? '0' : '') + r;
  }

  function initAudio(root) {
    qsa(root, '[data-sb-audio]').forEach(function (block) {
      var button = qs(block, '[data-sb-audio-play]');
      var file = qs(block, '[data-sb-audio-file]');
      if (!button) return;
      var off = [];
      if (file) {
        var seek = qs(block, '[data-sb-audio-seek]');
        var time = qs(block, '[data-sb-audio-time]');
        var show = function () {
          if (seek && file.duration) seek.value = String((file.currentTime / file.duration) * 100);
          if (time) time.textContent = audioTime(file.currentTime) + ' / ' + audioTime(file.duration);
        };
        var mark = function () {
          if (file.paused) block.removeAttribute('data-sb-audio-playing');
          else block.setAttribute('data-sb-audio-playing', '');
        };
        off.push(on(button, 'click', function () {
          if (file.paused) { var p = file.play(); if (p && p.catch) p.catch(mark); } else { file.pause(); }
        }));
        off.push(on(file, 'play', mark), on(file, 'pause', mark), on(file, 'ended', mark), on(file, 'timeupdate', show), on(file, 'loadedmetadata', show));
        if (seek) off.push(on(seek, 'input', function () { if (file.duration) file.currentTime = (Number(seek.value) / 100) * file.duration; }));
      } else {
        off.push(on(button, 'click', function () {
          var tpl = qs(block, 'template[data-sb-audio-player]');
          var player = tpl && tpl.content && tpl.content.firstElementChild;
          if (!player) return;
          var frame = document.importNode(player, true);
          tpl.parentNode.replaceChild(frame, tpl);
          button.hidden = true;
          block.setAttribute('data-sb-audio-open', '');
          frame.focus();
        }));
      }
      mount(function () { off.forEach(function (un) { un(); }); });
    });
  }

/* ── Feature: video overlay ─────────────────────────────────────────────
   * A video with an image overlay is a poster button plus the player the
   * server rendered inside a <template>. A click swaps the poster for that
   * player in place, or opens it in a lightbox; the visitor never leaves the
   * page. Closing the lightbox empties it, which stops playback. With no JS
   * the poster stays a poster.
   */

  function playerFrom(block) {
    var tpl = qs(block, 'template[data-sb-video-player]');
    if (!tpl || !tpl.content) return null;
    var node = tpl.content.firstElementChild;
    return node ? document.importNode(node, true) : null;
  }

  function startPlayer(player) {
    // A self-hosted file: the click is the visitor's gesture, so play() is allowed with sound.
    if (player && player.tagName === 'VIDEO' && player.play) {
      try {
        var p = player.play();
        if (p && p.catch) p.catch(function () { /* the browser said no; the controls are still there */ });
      } catch (err) { /* old browser */ }
    }
  }

  function initVideos(root) {
    var blocks = qsa(root, '[data-sb-video]');
    if (!blocks.length) return;
    var doc = root.ownerDocument || global.document;
    var lb = null;

    function lightbox() {
      if (lb) return lb;
      lb = doc.createElement('div');
      lb.className = 'sb-lightbox sb-video-lightbox';
      lb.setAttribute('role', 'dialog');
      lb.setAttribute('aria-modal', 'true');
      var vlabel = function (name, fallback) {
        var v = blocks[0].getAttribute('data-sb-label-' + name) || fallback;
        return String(v).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
      };
      lb.setAttribute('aria-label', blocks[0].getAttribute('data-sb-label-video') || 'Video');
      lb.setAttribute('aria-hidden', 'true');
      lb.setAttribute('data-sb-overlay', '');
      lb.innerHTML = '<div class="sb-video-wrapper sb-video-lightbox__frame" data-sb-video-frame></div>'
        + '<button type="button" class="sb-lightbox__close" data-sb-close aria-label="' + vlabel('close', 'Close') + '">&times;</button>';
      (doc.body || root).appendChild(lb);
      lb.addEventListener('click', function (e) {
        if (e.target.closest('[data-sb-close]') || e.target === lb) {
          e.preventDefault();
          if (lb.__sbClose) lb.__sbClose();
        }
      });
      // However it closes (×, backdrop, Escape), the player goes, so the sound stops with it.
      new MutationObserver(function () {
        if (!lb.hasAttribute('data-sb-open')) {
          var frame = qs(lb, '[data-sb-video-frame]');
          if (frame) frame.innerHTML = '';
        }
      }).observe(lb, { attributes: true, attributeFilter: ['data-sb-open'] });
      return lb;
    }

    var swapped = [];
    var off = blocks.map(function (block) {
      var button = qs(block, '[data-sb-video-play]');
      if (!button) return function () {};
      return on(button, 'click', function (e) {
        e.preventDefault();
        var player = playerFrom(block);
        if (!player) return;
        if (block.hasAttribute('data-sb-video-lightbox')) {
          var box = lightbox();
          var frame = qs(box, '[data-sb-video-frame]');
          frame.className = 'sb-video-wrapper sb-video-lightbox__frame sb-video--aspect-' + (block.getAttribute('data-sb-video-aspect') || '16-9');
          frame.innerHTML = '';
          frame.appendChild(player);
          openOverlay(box, button);
          startPlayer(player);
          return;
        }
        // In place: the player takes the poster's spot, and keyboard focus moves onto it.
        button.parentNode.replaceChild(player, button);
        block.setAttribute('data-sb-video-playing', '');
        swapped.push({ block: block, button: button, player: player });
        if (player.focus) { try { player.focus(); } catch (err) { /* detached */ } }
        startPlayer(player);
      });
    });

    mount(function () {
      off.forEach(function (un) { un(); });
      swapped.forEach(function (s) {
        if (s.player.parentNode) s.player.parentNode.replaceChild(s.button, s.player);
        s.block.removeAttribute('data-sb-video-playing');
      });
      if (lb) {
        if (lb.__sbClose) lb.__sbClose();
        if (lb.parentNode) lb.parentNode.removeChild(lb);
      }
    });
  }

/* ── Feature: tabs ──────────────────────────────────────────────────────
   * Full APG keyboard support: arrows move and activate, Home/End jump,
   * roving tabindex keeps a single tab stop for the whole strip.
   */

  function initTabs(root) {
    var groups = qsa(root, '[data-sb-tabs]');
    var teardowns = [];

    groups.forEach(function (group) {
      // Its own tabs and panels only: a panel of Tabs with blocks can hold another tabs block.
      var mine = function (el) { return el.closest('[data-sb-tabs]') === group; };
      var tabs = qsa(group, '[data-sb-tab]').filter(mine);
      var panels = qsa(group, '[data-sb-panel]').filter(mine);
      if (tabs.length < 2) return;

      tabs.forEach(function (tab, i) {
        if (!tab.id) tab.id = uid('tab');
        var panelKey = tab.getAttribute('data-sb-tab');
        var panel = panels.filter(function (p) { return p.getAttribute('data-sb-panel') === panelKey; })[0];
        if (!panel) return;
        if (!panel.id) panel.id = uid('panel');
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-controls', panel.id);
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('tabindex', '0');
        panel.setAttribute('aria-labelledby', tab.id);
      });

      function select(next, focus) {
        tabs.forEach(function (tab, i) {
          var key = tab.getAttribute('data-sb-tab');
          var panel = panels.filter(function (p) { return p.getAttribute('data-sb-panel') === key; })[0];
          var active = i === next;
          tab.setAttribute('aria-selected', active ? 'true' : 'false');
          tab.setAttribute('tabindex', active ? '0' : '-1');
          if (tab.classList) tab.classList.toggle('is-active', active);
          if (!panel) return;
          panel.setAttribute('aria-hidden', active ? 'false' : 'true');
          if (panel.classList) panel.classList.toggle('is-active', active);
          panel.hidden = !active;
          // A Tabs with blocks panel sits in its tab block's wrapper, which may have padding of its own: hide that too.
          var wrap = panel.parentElement;
          if (wrap && wrap.classList.contains('sb-block--layout-tab')) wrap.hidden = !active;
        });
        if (focus) tabs[next].focus();
      }

      var off = [];
      tabs.forEach(function (tab, i) {
        off.push(on(tab, 'click', function () { select(i, false); }));
        off.push(on(tab, 'keydown', function (e) {
          var map = { ArrowRight: i + 1, ArrowLeft: i - 1, ArrowDown: i + 1, ArrowUp: i - 1 };
          var next;
          if (e.key === 'Home') next = 0;
          else if (e.key === 'End') next = tabs.length - 1;
          else if (map[e.key] !== undefined) next = (map[e.key] + tabs.length) % tabs.length;
          else return;
          e.preventDefault();
          select(next, true);
        }));
      });

      var initial = Math.max(0, tabs.findIndex(function (t) { return t.getAttribute('aria-selected') === 'true'; }));
      select(initial, false);

      // Autoplay (Tabs with blocks): while on screen, the chosen tab's bar fills over `data-sb-tabs-autoplay` ms, then the
      // next tab is chosen. The pointer over the tabs (with `data-sb-tabs-pause`) or focus inside holds it; choosing a
      // tab starts its time again. Reduced motion: no autoplay.
      var every = parseInt(group.getAttribute('data-sb-tabs-autoplay') || '0', 10);
      if (every > 0 && !reducedMotion()) {
        var current = initial, elapsed = 0, seen = false, held = false, last = 0, raf = 0;
        var pause = group.hasAttribute('data-sb-tabs-pause');
        var bar = function (i) { return qs(tabs[i], '.sb-tabset__bar > i'); };
        var paint = function () {
          tabs.forEach(function (t, i) { var b = bar(i); if (b) b.style.transform = 'scaleX(' + (i === current ? Math.min(1, elapsed / every) : 0).toFixed(3) + ')'; });
        };
        var tick = function (now) {
          var dt = last ? Math.min(100, now - last) : 0;
          last = now;
          if (seen && !held && !document.hidden) {
            elapsed += dt;
            if (elapsed >= every) { elapsed = 0; current = (current + 1) % tabs.length; select(current, false); }
            paint();
          }
          raf = global.requestAnimationFrame(tick);
        };
        tabs.forEach(function (tab, i) { off.push(on(tab, 'click', function () { current = i; elapsed = 0; paint(); })); });
        off.push(on(group, 'keydown', function () {
          global.setTimeout(function () { var i = tabs.indexOf(document.activeElement); if (i !== -1) { current = i; elapsed = 0; paint(); } }, 0);
        }));
        if (pause) {
          off.push(on(group, 'pointerenter', function () { held = true; }));
          off.push(on(group, 'pointerleave', function () { held = group.contains(document.activeElement); }));
        }
        off.push(on(group, 'focusin', function () { held = true; }));
        off.push(on(group, 'focusout', function (e) { if (!group.contains(e.relatedTarget)) held = false; }));
        var io = 'IntersectionObserver' in global ? new IntersectionObserver(function (es) { seen = es[0].isIntersecting; }, { threshold: 0.35 }) : null;
        if (io) io.observe(group); else seen = true;
        raf = global.requestAnimationFrame(tick);
        off.push(function () {
          global.cancelAnimationFrame(raf);
          if (io) io.disconnect();
          tabs.forEach(function (t, i) { var b = bar(i); if (b) b.style.removeProperty('transform'); });
        });
      }
      teardowns.push(function () { off.forEach(function (un) { un(); }); });
    });

    if (teardowns.length) mount(function () { teardowns.forEach(function (fn) { fn(); }); });
  }

  /* ── Feature: accordion ──────────────────────────────────────────────────
   * Single-open by default (radio semantics); data-sb-allow-multiple switches
   * to independent disclosure, which is what most marketing FAQs want.
   */

  function initAccordion(root) {
    var groups = qsa(root, '[data-sb-accordion]');
    var teardowns = [];

    groups.forEach(function (group) {
      var multiple = group.hasAttribute('data-sb-allow-multiple');
      // Its own rows only (an answer of an Accordion with blocks can hold another accordion). The core Accordion's rows
      // and those of an Accordion with blocks (the row is the block's wrapper) share the class.
      var items = qsa(group, '.sb-accordion__item').filter(function (el) { return el.closest('[data-sb-accordion]') === group; });
      if (!items.length) return;

      function panelOf(item) { return qs(item, ':scope > [data-sb-accordion-panel]'); }
      function buttonOf(item) { return qs(item, ':scope > .sb-accordion__heading [data-sb-accordion-button]'); }

      function setState(item, open) {
        var panel = panelOf(item);
        var button = buttonOf(item);
        if (panel) {
          panel.hidden = !open;
          panel.setAttribute('aria-hidden', open ? 'false' : 'true');
          // Height animation needs a real pixel value; fall back to instant.
          if (!reducedMotion() && panel.scrollHeight) {
            panel.style.height = open ? panel.scrollHeight + 'px' : panel.style.height || '0px';
            if (!open) {
              global.setTimeout(function () { if (panel.hidden) panel.style.height = '0px'; }, 260);
            }
          } else {
            panel.style.height = open ? 'auto' : '0px';
          }
        }
        if (button) {
          button.setAttribute('aria-expanded', open ? 'true' : 'false');
          if (button.classList) button.classList.toggle('is-open', open);
        }
        if (item.classList) item.classList.toggle('is-open', open);
      }

      var off = [];
      items.forEach(function (item, i) {
        var button = buttonOf(item);
        var panel = panelOf(item);
        if (!button || !panel) return;
        if (!button.id) button.id = uid('acc');
        if (!panel.id) panel.id = uid('accp');
        button.setAttribute('aria-controls', panel.id);
        panel.setAttribute('role', 'region');
        panel.setAttribute('aria-labelledby', button.id);
        // The server's state: the first row of a core Accordion, or each row of an Accordion with blocks as it is set.
        setState(item, button.getAttribute('aria-expanded') === 'true');

        off.push(on(button, 'click', function () {
          var isOpen = button.getAttribute('aria-expanded') === 'true';
          if (!multiple && !isOpen) {
            items.forEach(function (other) { if (other !== item) setState(other, false); });
          }
          setState(item, !isOpen);
        }));
      });

      teardowns.push(function () { off.forEach(function (un) { un(); }); });
    });

    if (teardowns.length) mount(function () { teardowns.forEach(function (fn) { fn(); }); });
  }
/* ── Feature: carousel ───────────────────────────────────────────────────
   * Transform-only track, autoplay that pauses on hover/focus/visibility and
   * on reduced motion, pointer + keyboard + touch support.
   */

  function initCarousel(root) {
    var groups = qsa(root, '[data-sb-carousel]');
    var teardowns = [];

    groups.forEach(function (group) {
      var track = qs(group, '[data-sb-carousel-track]');
      var slides = qsa(group, '[data-sb-slide]');
      if (!track || slides.length < 2) return;

      // Slides per view can differ per device (.sb-dpv sets --sb-pv in its media queries); the attribute is the desktop count.
      function perViewNow() {
        var css = parseInt(global.getComputedStyle(group).getPropertyValue('--sb-pv'), 10);
        return css > 0 ? css : parseInt(group.getAttribute('data-sb-per-view') || '1', 10);
      }
      var perView = perViewNow();
      var loop = group.hasAttribute('data-sb-loop');
      var auto = parseInt(group.getAttribute('data-sb-autoplay') || '0', 10);
      var index = 0;
      var timer = null;
      var dots = [];

      // Labels come from the server (translated); the English ones are only for markup that has none.
      var label = function (name, fallback) { return group.getAttribute('data-sb-label-' + name) || fallback; };
      var paused = false;
      group.setAttribute('role', 'region');
      if (!group.hasAttribute('aria-roledescription')) group.setAttribute('aria-roledescription', 'carousel');
      slides.forEach(function (s, i) {
        s.setAttribute('role', 'group');
        s.setAttribute('aria-roledescription', label('slide', 'slide'));
        s.setAttribute('aria-label', label('slide-of', '%1$s of %2$s').replace('%1$s', String(i + 1)).replace('%2$s', String(slides.length)));
        if (!s.id) s.id = uid('slide');
      });

      var max = Math.max(0, slides.length - perView);

      // How far the track moves to bring slide `i` to the start: measured, so the gap between slides and any
      // margin a theme gives them are counted (a percentage of the track's width left them out and each step fell short).
      function offsetOf(i) { return slides[i].offsetLeft - slides[0].offsetLeft; }
      function place(extra) { track.style.transform = 'translate3d(' + (extra - offsetOf(index)) + 'px,0,0)'; }

      function render() {
        place(0);
        slides.forEach(function (s, i) {
          var visible = i >= index && i < index + perView;
          s.setAttribute('aria-hidden', visible ? 'false' : 'true');
          if (s.classList) s.classList.toggle('is-active', i === index);
          // Keep off-screen slides out of the tab order.
          qsa(s, 'a,button,input,select,textarea,[tabindex]').forEach(function (el) {
            if (visible && el.getAttribute('data-sb-tabindex') === null) el.removeAttribute('tabindex');
            else if (!visible) el.setAttribute('tabindex', '-1');
          });
        });
        dots.forEach(function (d, i) {
          d.setAttribute('aria-current', i === index ? 'true' : 'false');
          if (d.classList) d.classList.toggle('is-active', i === index);
        });
      }

      function go(next) {
        index = loop ? (next + max + 1) % (max + 1) : clamp(next, 0, max);
        render();
      }

      var off = [];
      off.push(on(group, 'click', function (e) {
        if (e.target.closest('[data-sb-car-prev]')) { e.preventDefault(); stop(); go(index - 1); restart(); }
        if (e.target.closest('[data-sb-car-next]')) { e.preventDefault(); stop(); go(index + 1); restart(); }
        var dot = e.target.closest('[data-sb-car-dot]');
        if (dot) { e.preventDefault(); stop(); go(parseInt(dot.getAttribute('data-sb-car-dot'), 10)); restart(); }
        var pause = e.target.closest('[data-sb-car-pause]');
        if (pause) {
          e.preventDefault();
          paused = !paused;
          pause.setAttribute('aria-pressed', paused ? 'true' : 'false');
          pause.setAttribute('aria-label', paused ? (pause.getAttribute('data-sb-label-play') || 'Play slideshow') : (pause.getAttribute('data-sb-label-pause') || 'Pause slideshow'));
          pause.innerHTML = paused ? '&#9654;' : '&#10073;&#10073;';
          if (paused) stop(); else restart();
        }
      }));
      off.push(on(group, 'keydown', function (e) {
        if (e.key === 'ArrowLeft') { e.preventDefault(); go(index - 1); }
        if (e.key === 'ArrowRight') { e.preventDefault(); go(index + 1); }
      }));

      // Touch: horizontal swipe only, so vertical page scroll is untouched.
      var startX = null, startY = null, delta = 0;
      off.push(on(track, 'touchstart', function (e) {
        startX = e.touches[0].clientX; startY = e.touches[0].clientY; delta = 0; stop();
      }, { passive: true }));
      off.push(on(track, 'touchmove', function (e) {
        if (startX === null) return;
        delta = e.touches[0].clientX - startX;
        var dy = e.touches[0].clientY - startY;
        // Only take over once the gesture is clearly horizontal.
        if (Math.abs(delta) > 8 && Math.abs(delta) > Math.abs(dy)) {
          place(delta);
        }
      }, { passive: true }));
      off.push(on(track, 'touchend', function () {
        if (startX === null) return;
        var threshold = track.offsetWidth * 0.15;
        if (Math.abs(delta) > threshold) go(index + (delta < 0 ? 1 : -1));
        else render();
        startX = null; delta = 0; restart();
      }));
// Dots
      // The server renders one dot per slide; with several slides per view there are fewer positions, so the
      // dots are rebuilt then, with the server's (translated) label.
      var dotsHost = qs(group, '[data-sb-car-dots]');
      function buildDots() {
        if (!dotsHost) return;
        var existing = qsa(dotsHost, '[data-sb-car-dot]');
        if (existing.length === max + 1) {
          dots = existing;
          return;
        }
        dotsHost.innerHTML = '';
        dots = [];
        for (var i = 0; i <= max; i++) {
          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'sb-carousel__dot';
          b.setAttribute('data-sb-car-dot', String(i));
          b.setAttribute('aria-label', label('go', 'Go to slide') + ' ' + (i + 1));
          dotsHost.appendChild(b);
          dots.push(b);
        }
      }
      buildDots();
      group.setAttribute('data-sb-car-ready', '');

      function stop() { if (timer) { global.clearInterval(timer); timer = null; } }
      function restart() {
        stop();
        if (!auto || reducedMotion() || !loop || paused) return;
        timer = global.setInterval(function () { go(index + 1); }, auto || CONFIG.carouselInterval);
      }

      // Never autoplay while the tab is hidden.
      var onVisibility = function () { document.hidden ? stop() : restart(); };
      document.addEventListener('visibilitychange', onVisibility);
      if (prefersHover()) {
        off.push(on(group, 'mouseenter', stop));
        off.push(on(group, 'mouseleave', restart));
        off.push(on(group, 'focusin', stop));
        off.push(on(group, 'focusout', restart));
      }

      // Slide widths follow the carousel's, so the offset is measured again when the window changes size.
      // A new device size can also change the slides per view, and with it the positions and dots.
      off.push(on(global, 'resize', function () {
        var next = perViewNow();
        if (next !== perView) {
          perView = next;
          max = Math.max(0, slides.length - perView);
          index = clamp(index, 0, max);
          buildDots();
          render();
        }
        if (startX === null) place(0);
      }, { passive: true }));

      render();
      restart();
      teardowns.push(function () {
        stop();
        document.removeEventListener('visibilitychange', onVisibility);
        off.forEach(function (un) { un(); });
        track.style.transform = '';
        group.removeAttribute('data-sb-car-ready');
      });
    });

    if (teardowns.length) mount(function () { teardowns.forEach(function (fn) { fn(); }); });
  }

  /* ── Feature: marquee ───────────────────────────────────────────────────
   * Duplicates its content once and translates by -50%, giving a seamless
   * CSS-only loop. The clone is aria-hidden so screen readers hear it once.
   */

  function initMarquee(root) {
    var els = qsa(root, '[data-sb-marquee]');
    if (!els.length || reducedMotion()) return;

    var teardowns = [];
    els.forEach(function (el) {
      var inner = qs(el, '[data-sb-marquee-track]') || el;
      var clone = inner.cloneNode(true);
      clone.setAttribute('aria-hidden', 'true');
      clone.setAttribute('data-sb-marquee-clone', '');
      inner.parentNode.appendChild(clone);
      var speed = parseFloat(el.getAttribute('data-sb-marquee-speed') || '40');
      inner.style.setProperty('--sb-marquee-duration', speed + 's');
      clone.style.setProperty('--sb-marquee-duration', speed + 's');
      teardowns.push(function () { if (clone.parentNode) clone.parentNode.removeChild(clone); });
    });

    if (teardowns.length) mount(function () { teardowns.forEach(function (fn) { fn(); }); });
  }

/* ── Feature: sticky ──────────────────────────────────────────────────────
   * Every [data-sb-sticky] node is already `position: sticky` in the stylesheet, so it sticks without this file.
   * This only marks the stuck state (`is-stuck`, for the shadow and the stuck background) and, with the `hide`
   * effect, slides a top-sticky node away while the page scrolls down and back as soon as it scrolls up.
   * One rAF-throttled scroll listener reads every node first, then writes, so there is no layout thrash.
   */

  function initSticky(root) {
    var nodes = qsa(root, '[data-sb-sticky]');
    if (!nodes.length) return;

    var lastY = global.pageYOffset || 0;
    var ticking = false;
    function update() {
      ticking = false;
      var y = global.pageYOffset || 0;
      var vh = global.innerHeight || 1;
      var down = y > lastY + 2;
      var up = y < lastY - 2;
      var states = nodes.map(function (el) {
        var style = global.getComputedStyle(el);
        if (style.position !== 'sticky') return { el: el, stuck: false, hide: false };
        var rect = el.getBoundingClientRect();
        var edge = el.getAttribute('data-sb-sticky');
        var offset = parseFloat(style.getPropertyValue('--sb-sticky-offset')) || 0;
        // The admin bar moves every top-sticky node down by its own height.
        var bar = parseFloat(global.getComputedStyle(document.documentElement).getPropertyValue('--kohevo-bar-h')) || 0;
        var stuck = edge === 'bottom'
          ? rect.bottom >= vh - offset - 1 && y + vh < document.documentElement.scrollHeight - 1
          : y > 0 && rect.top <= offset + bar + 1;
        var hides = stuck && edge !== 'bottom' && /(^|\s)hide(\s|$)/.test(el.getAttribute('data-sb-sticky-effect') || '');
        var hidden = el.classList.contains('is-hidden');
        if (hides && down && y > rect.height + offset) hidden = true;
        else if (!hides || up) hidden = false;
        return { el: el, stuck: stuck, hide: hidden };
      });
      states.forEach(function (s) {
        s.el.classList.toggle('is-stuck', s.stuck);
        s.el.classList.toggle('is-hidden', s.hide && !reducedMotion());
        if (s.stuck) s.el.setAttribute('data-sb-stuck', ''); else s.el.removeAttribute('data-sb-stuck');
        // Two thresholds: the shorter header shortens the page, which must not undo the scroll that shrank it.
        if (/(^|\s)shrink(\s|$)/.test(s.el.getAttribute('data-sb-sticky-effect') || '')) {
          if (s.stuck && y > 48) s.el.classList.add('is-shrunk');
          else if (y < 8) s.el.classList.remove('is-shrunk');
        }
      });
      lastY = y;
    }
    function onScroll() {
      if (ticking) return;
      ticking = true;
      global.requestAnimationFrame(update);
    }
    var offs = [on(global, 'scroll', onScroll, { passive: true }), on(global, 'resize', onScroll, { passive: true })];
    // Keyboard users must never lose a hidden header: focus inside brings it back.
    nodes.forEach(function (el) {
      offs.push(on(el, 'focusin', function () { el.classList.remove('is-hidden'); }));
    });
    update();

    mount(function () {
      offs.forEach(function (off) { off(); });
      nodes.forEach(function (el) {
        el.classList.remove('is-stuck', 'is-hidden', 'is-shrunk');
        el.removeAttribute('data-sb-stuck');
      });
    });
  }

  /* ── Feature: forms ───────────────────────────────────────────────────────
   * A Studio form ([data-sb-form-slug]) posts to the Forms module. The page is cached and has no session, so when the
   * visitor first touches the form this asks Forms for a CSRF token and a time-trap stamp (GET <action>/token), then
   * submits in place with `Accept: application/json` and shows Forms' answer: the success message (or its redirect),
   * or each field's error beside the field. Without this script the post still reaches Forms, which shows its own page.
   */

  /* ── Feature: fields filled from the page address ─────────────────────────
   * A field with data-sb-url-param takes its value from the page address (contact/?reason=cours), so a link can open a
   * form already pointed at the right subject. data-sb-url-values maps a value to its option or readable text. A list
   * (select, radio group) only ever takes one of its own options: an unknown value leaves its default. A text field
   * takes every named value (mapped or as typed, cut to 120 characters), joined with " – ", and stays editable. A field
   * the visitor already changed (the browser restoring it) is left alone.
   */

  function initUrlFill(root) {
    if (!global.URLSearchParams) return;
    var query = new URLSearchParams(global.location.search);
    qsa(root, '[data-sb-url-param]').forEach(function (el) {
      var map = {};
      try { map = JSON.parse(el.getAttribute('data-sb-url-values') || '{}') || {}; } catch (e) { map = {}; }
      var found = el.getAttribute('data-sb-url-param').split(',').map(function (p) {
        var raw = query.get(p.trim());
        if (raw == null) return null;
        raw = raw.replace(/[\u0000-\u001f\u007f]/g, ' ').replace(/\s+/g, ' ').trim();
        if (raw === '') return null;
        var key = raw.toLowerCase();
        return Object.prototype.hasOwnProperty.call(map, key) ? { text: map[key], mapped: true } : { text: raw.slice(0, 120), mapped: false };
      }).filter(Boolean);
      if (!found.length) return;

      if (el.tagName === 'FIELDSET') {
        var pick = found.filter(function (f) { return f.mapped; })[0];
        if (!pick) return;
        qsa(el, 'input[type="radio"]').forEach(function (r) { r.checked = r.value === pick.text; });
        return;
      }
      if (el.tagName === 'SELECT') {
        var choice = found.filter(function (f) { return f.mapped; })[0];
        if (!choice || el.value !== (qs(el, 'option[selected]') || el.options[0] || {}).value) return;
        if (Array.prototype.some.call(el.options, function (o) { return o.value === choice.text; })) el.value = choice.text;
        return;
      }
      if (el.value !== el.defaultValue) return;
      el.value = found.map(function (f) { return f.text; }).join(' – ');
    });
  }

  function initForms(root) {
    var forms = qsa(root, 'form[data-sb-form-slug]');
    if (!forms.length || !global.fetch || !global.FormData) return;
    var offs = [];

    forms.forEach(function (form) {
      // An inline form keeps its status line just after it, outside the form.
      var after = form.nextElementSibling;
      var status = qs(form, '[data-sb-form-status]') || (after && after.hasAttribute('data-sb-form-status') ? after : null);
      var button = qs(form, 'button[type="submit"]');
      var back = qs(form, '[data-sb-return-to]');
      if (back) back.value = global.location.pathname + global.location.search;
      var token = null;

      function hidden(name, value) {
        var input = form.querySelector('input[type="hidden"][name="' + name + '"]');
        if (!input) {
          input = document.createElement('input');
          input.type = 'hidden';
          input.name = name;
          form.appendChild(input);
        }
        input.value = value;
      }
      function getToken() {
        if (!token) {
          token = fetch(form.action.replace(/\/?$/, '/token'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error('token ' + r.status); return r.json(); })
            .then(function (j) { hidden('_csrf', j.csrf || ''); hidden('_fts', j.fts || ''); return j; })
            .catch(function (err) { token = null; throw err; });
        }
        return token;
      }
      function say(text, kind) {
        if (!status) return;
        status.textContent = text;
        status.hidden = !text;
        status.setAttribute('data-sb-state', kind || '');
      }
      function clearErrors() {
        qsa(form, '[data-sb-field]').forEach(function (group) {
          group.classList.remove('is-invalid');
          var msg = qs(group, '[data-sb-field-error]');
          if (msg) { msg.textContent = ''; msg.hidden = true; }
          qsa(group, 'input,select,textarea').forEach(function (el) { el.removeAttribute('aria-invalid'); });
        });
      }
      function showErrors(errors) {
        var first = null;
        Object.keys(errors || {}).forEach(function (name) {
          var group = form.querySelector('[data-sb-field="' + name.replace(/"/g, '') + '"]');
          if (!group) return;
          group.classList.add('is-invalid');
          var msg = qs(group, '[data-sb-field-error]');
          if (msg) { msg.textContent = String(errors[name]); msg.hidden = false; }
          var input = qs(group, 'input,select,textarea');
          if (input) {
            input.setAttribute('aria-invalid', 'true');
            if (msg) {
              if (!msg.id) msg.id = uid('err');
              var by = (input.getAttribute('aria-describedby') || '').split(' ').filter(function (x) { return x && x !== msg.id; });
              by.push(msg.id);
              input.setAttribute('aria-describedby', by.join(' '));
            }
            if (!first) first = input;
          }
        });
        if (first) first.focus();
      }

      offs.push(on(form, 'focusin', function () { getToken().catch(function () {}); }));
      offs.push(on(form, 'submit', function (e) {
        e.preventDefault();
        if (form.getAttribute('aria-busy') === 'true') return;
        clearErrors();
        form.setAttribute('aria-busy', 'true');
        if (button) button.disabled = true;
        say(form.getAttribute('data-sb-label-sending') || 'Sending…', 'busy');
        getToken()
          .then(function () {
            return fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } });
          })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (j && j.ok) {
              if (j.redirect) { global.location.assign(j.redirect); return; }
              form.reset();
              if (back) back.value = global.location.pathname + global.location.search;
              say(j.message || (status && status.getAttribute('data-sb-success')) || '', 'success');
              token = null; // a new stamp for the next submit
              return;
            }
            showErrors(j && j.errors);
            say((j && j.message) || '', 'error');
          })
          .catch(function () {
            say(form.getAttribute('data-sb-label-failed') || 'The form could not be sent.', 'error');
            token = null;
          })
          .then(function () {
            form.removeAttribute('aria-busy');
            if (button) button.disabled = false;
          });
      }));
    });

    mount(function () { offs.forEach(function (off) { off(); }); });
  }

  /* ── Feature: countdown ──────────────────────────────────────────────────
   * The element's existing text is the no-JS fallback, so the degraded state
   * is a sensible static label rather than a blank gap.
   */

  /**
   * Fragments: the parts of a page that belong to one visitor (a Shop cart, its checkout) on a page that is cached
   * for everyone. `[data-sb-fragment="<same-origin url>"]` is filled with the HTML that URL answers for this visitor
   * (their session cookie goes with it); whatever it held before (a plain link) stays when the fetch fails or there
   * is no JavaScript. A form inside a fragment, or one marked `data-sb-fragment-form`, is sent in place: the JSON
   * answer may carry `html` (the fragment's new content), `message`, and `redirect` (this site, or https, e.g. Stripe
   * Checkout). After a send, every fragment of the same `data-sb-fragment-group` is fetched again (the mini cart
   * after an add to cart). Inserted HTML is never executed: it is markup only.
   */
  function sameOrigin(url) {
    try { return new URL(url, global.location.href).origin === global.location.origin; } catch (e) { return false; }
  }

  /**
   * `[data-sb-turnstile="<site key>"]`: a Cloudflare Turnstile check (Shop checkout protection). A fragment's scripts
   * do not run, so the runtime loads Cloudflare's script on first need (explicit rendering) and renders each widget
   * once; the widget adds its `cf-turnstile-response` field to the form it sits in.
   */
  var turnstileQueue = [];
  function mountChallenges(root) {
    var boxes = qsa(root || document, '[data-sb-turnstile]').filter(function (el) { return !el.__sbTurnstile; });
    if (!boxes.length) return;
    boxes.forEach(function (el) { el.__sbTurnstile = true; turnstileQueue.push(el); });
    function renderAll() {
      while (turnstileQueue.length) {
        var el = turnstileQueue.shift();
        if (!document.documentElement.contains(el)) continue;
        try { global.turnstile.render(el, { sitekey: el.getAttribute('data-sb-turnstile') }); } catch (e) { /* the server says what is missing */ }
      }
    }
    if (global.turnstile && global.turnstile.render) { renderAll(); return; }
    if (document.getElementById('sb-turnstile-api')) return;
    global.__sbTurnstileReady = renderAll;
    var s = document.createElement('script');
    s.id = 'sb-turnstile-api';
    s.async = true;
    s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=__sbTurnstileReady';
    document.head.appendChild(s);
  }

  function initFragments(root) {
    var slots = qsa(root, '[data-sb-fragment]');
    var forms = qsa(root, 'form[data-sb-fragment-form]');
    if (!slots.length && !forms.length) return;
    if (!global.fetch || !global.FormData) return;

    // Only the newest content for a slot may land: a slower, older answer must not overwrite it.
    function nextTurn(slot) {
      slot.__sbFragmentTurn = (slot.__sbFragmentTurn || 0) + 1;
      return slot.__sbFragmentTurn;
    }

    function load(slot) {
      var url = slot.getAttribute('data-sb-fragment');
      if (!url || !sameOrigin(url)) return;
      // `data-sb-fragment-query="order,key"`: pass on those parameters of the page's own address (an order link).
      var names = (slot.getAttribute('data-sb-fragment-query') || '').split(',').filter(Boolean);
      if (names.length && global.URLSearchParams) {
        var target = new URL(url, global.location.href);
        var here = new URLSearchParams(global.location.search);
        names.forEach(function (n) { if (here.has(n)) target.searchParams.set(n, here.get(n)); });
        url = target.toString();
      }
      slot.setAttribute('data-sb-fragment-state', 'loading');
      var turn = nextTurn(slot);
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Studio-Fragment': '1' } })
        .then(function (res) {
          var type = res.headers.get('Content-Type') || '';
          if (!res.ok || type.indexOf('text/html') !== 0) throw new Error('fragment ' + res.status);
          return res.text();
        })
        .then(function (html) {
          if (slot.__sbFragmentTurn !== turn) return;
          slot.innerHTML = html;
          slot.setAttribute('data-sb-fragment-state', 'ready');
          mountChallenges(slot);
        })
        .catch(function () { slot.setAttribute('data-sb-fragment-state', 'error'); });
    }

    // `fresh` is a slot that already holds the answer (and any message in it): reloading it would wipe that message.
    function refresh(group, fresh) {
      if (!group) return;
      qsa(document, '[data-sb-fragment][data-sb-fragment-group]').forEach(function (slot) {
        if (slot !== fresh && slot.getAttribute('data-sb-fragment-group') === group) load(slot);
      });
    }

    // What the visitor can type or choose in a form, as name => value (a radio group by its checked value). Hidden
    // fields (tokens, stamps) are the server's and are never carried over.
    function formValues(form) {
      var out = {};
      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name || /^(hidden|submit|button|reset|file|image)$/i.test(el.type || '')) return;
        if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; else if (!(el.name in out)) out[el.name] = null; return; }
        out[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value;
      });
      return out;
    }

    // An answer replaces the form with the server's copy. What the visitor changed while it was on its way (a postal
    // code typed after the state was picked) is put back, and the field they were in keeps the cursor.
    function keepTyped(slot, typed, activeName) {
      var form = qs(slot, 'form');
      if (!form) return;
      Object.keys(typed).forEach(function (name) {
        var v = typed[name];
        Array.prototype.forEach.call(form.elements, function (el) {
          if (el.name !== name) return;
          if (el.type === 'radio') el.checked = v !== null && el.value === v;
          else if (el.type === 'checkbox') el.checked = v === '1';
          else el.value = v;
        });
      });
      if (activeName) {
        var again = form.elements.namedItem(activeName);
        if (again && again.focus && !again.length) {
          again.focus();
          try { if (typeof again.value === 'string' && again.setSelectionRange) again.setSelectionRange(again.value.length, again.value.length); } catch (e) { /* not a text field */ }
        }
      }
    }

    function onSubmit(event) {
      var form = event.target;
      if (!form || form.tagName !== 'FORM') return;
      var slot = form.closest('[data-sb-fragment]');
      if (!slot && !form.hasAttribute('data-sb-fragment-form')) return;
      // The attributes, not form.action / form.method: a field named "action" or "method" shadows those properties.
      var action = new URL(form.getAttribute('action') || global.location.href, global.location.href).href;
      if (!sameOrigin(action) || (form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
      event.preventDefault();
      if (form.getAttribute('aria-busy') === 'true') {
        // A quote asked for while one is on its way is sent once that answer lands (the visitor's last change counts).
        // Anything else (placing the order) is not repeated on its own.
        if (event.submitter && event.submitter.hasAttribute && event.submitter.hasAttribute('data-sb-requote-button')) form.__sbRequoteAgain = true;
        return;
      }
      form.setAttribute('aria-busy', 'true');
      var sent = formValues(form);
      var requoteAfter = null;
      var body = new FormData(form);
      if (event.submitter && event.submitter.name) body.append(event.submitter.name, event.submitter.value);
      var note = qs(form, '[data-sb-fragment-message]');
      fetch(action, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Studio-Fragment': '1' } })
        .then(function (res) { return res.json().catch(function () { return {}; }).then(function (data) { return { ok: res.ok, data: data || {} }; }); })
        .then(function (r) {
          var data = r.data;
          if (typeof data.redirect === 'string' && (sameOrigin(data.redirect) || /^https:\/\//i.test(data.redirect))) {
            global.location.assign(data.redirect);
            return;
          }
          var fresh = slot && typeof data.html === 'string' ? slot : null;
          if (fresh) {
            var now = formValues(form);
            var typed = {};
            Object.keys(now).forEach(function (k) { if (now[k] !== sent[k]) typed[k] = now[k]; });
            var active = global.document.activeElement;
            var activeName = active && active.name && form.contains(active) ? active.name : null;
            nextTurn(fresh); fresh.innerHTML = data.html; mountChallenges(fresh);
            keepTyped(fresh, typed, activeName);
            // Changed since this quote was sent, or asked for again while it was on its way: quote once more.
            var newForm = qs(fresh, 'form');
            var quoteField = newForm && Object.keys(typed).some(function (k) {
              var el = newForm.elements.namedItem(k);
              el = el && el.length && !el.tagName ? el[0] : el;
              return !!(el && el.hasAttribute && el.hasAttribute('data-sb-requote'));
            });
            if (newForm && (form.__sbRequoteAgain || quoteField)) requoteAfter = newForm;
          }
          if (note && typeof data.message === 'string') {
            note.textContent = data.message;
            note.setAttribute('data-sb-state', r.ok ? 'ok' : 'error');
          }
          var group = form.getAttribute('data-sb-fragment-group') || (slot && slot.getAttribute('data-sb-fragment-group'));
          if (r.ok) refresh(group, fresh);
        })
        .catch(function () {
          if (note) { note.textContent = note.getAttribute('data-sb-fragment-error') || ''; note.setAttribute('data-sb-state', 'error'); }
        })
        .then(function () {
          form.removeAttribute('aria-busy');
          form.__sbRequoteAgain = false;
          var next = requoteAfter;
          requoteAfter = null;
          var button = next && qs(next, '[data-sb-requote-button]');
          if (button) { if (typeof next.requestSubmit === 'function') next.requestSubmit(button); else button.click(); }
        });
    }

    // `[data-sb-requote]`: changing this field sends its form again with the form's `[data-sb-requote-button]` (a
    // checkout's address quotes shipping and tax again). Typed text waits until the visitor leaves the field.
    function onRequote(event) {
      var field = event.target;
      if (!field || !field.hasAttribute || !field.hasAttribute('data-sb-requote') || !field.form) return;
      var form = field.form;
      if (!form.closest('[data-sb-fragment]') && !form.hasAttribute('data-sb-fragment-form')) return;
      var button = qs(form, '[data-sb-requote-button]');
      if (!button || form.getAttribute('aria-busy') === 'true') return;
      if (typeof form.requestSubmit === 'function') form.requestSubmit(button); else button.click();
    }

    slots.forEach(load);
    mountChallenges(root);
    mount(on(document, 'submit', onSubmit));
    mount(on(document, 'change', onRequote));
  }

  /**
   * Product grid tools. A `form[data-sb-autosubmit]` (sort, category) is sent as soon as a `[data-sb-autosubmit-field]`
   * changes; the form is marked `data-sb-autosubmit="on"` so its button, needed without JavaScript, can step aside.
   * An `a[data-sb-load-more="<grid>"]` (a link to the next page) fetches that page and appends its cards to the grid
   * `[data-sb-load-grid="<grid>"]`, then points at the page after, or goes when there is none. Without this script both
   * are a plain form and a plain link.
   */
  function initShopGrid(root) {
    qsa(root, 'form[data-sb-autosubmit]').forEach(function (form) {
      form.setAttribute('data-sb-autosubmit', 'on');
      var off = on(form, 'change', function (event) {
        var t = event.target;
        if (!t || !t.hasAttribute || !t.hasAttribute('data-sb-autosubmit-field')) return;
        if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
      });
      // Empty fields stay out of the address (`?sort=price_asc`, not `?q=&sort=price_asc`).
      var offSubmit = on(form, 'submit', function () {
        qsa(form, 'input[name],select[name]').forEach(function (f) {
          if (f.value === '' && !f.disabled) { f.disabled = true; global.setTimeout(function () { f.disabled = false; }, 0); }
        });
      });
      mount(function () { off(); offSubmit(); form.setAttribute('data-sb-autosubmit', ''); });
    });
    // A product's thumbnails: picking one shows it as the main photo.
    qsa(root, '[data-sb-shop-gallery]').forEach(function (gallery) {
      var main = qs(gallery, '.sb-shop-gallery__main img');
      if (!main) return;
      var off = on(gallery, 'click', function (event) {
        var thumb = event.target && event.target.closest ? event.target.closest('[data-sb-thumb]') : null;
        if (!thumb || !gallery.contains(thumb)) return;
        main.removeAttribute('srcset');
        main.setAttribute('src', thumb.getAttribute('data-sb-thumb'));
        qsa(gallery, '[data-sb-thumb]').forEach(function (t) { if (t === thumb) t.setAttribute('aria-current', 'true'); else t.removeAttribute('aria-current'); });
      });
      mount(off);
    });
    if (!global.fetch || !global.DOMParser) return;
    qsa(root, 'a[data-sb-load-more]').forEach(function (link) {
      var off = on(link, 'click', function (event) {
        var key = link.getAttribute('data-sb-load-more');
        var grid = key ? qs(document, '[data-sb-load-grid="' + key.replace(/["\\]/g, '') + '"]') : null;
        var href = link.getAttribute('href');
        if (!grid || !href || !sameOriginUrl(href)) return;
        event.preventDefault();
        if (link.getAttribute('aria-busy') === 'true') return;
        link.setAttribute('aria-busy', 'true');
        fetch(href, { credentials: 'same-origin', headers: { Accept: 'text/html' } })
          .then(function (res) { if (!res.ok) throw new Error('page ' + res.status); return res.text(); })
          .then(function (html) {
            var doc = new global.DOMParser().parseFromString(html, 'text/html');
            var more = qs(doc, '[data-sb-load-grid="' + key.replace(/["\\]/g, '') + '"]');
            if (!more) throw new Error('no grid');
            Array.prototype.slice.call(more.children).forEach(function (card) { grid.appendChild(document.importNode(card, true)); });
            var next = qs(doc, 'a[data-sb-load-more="' + key.replace(/["\\]/g, '') + '"]');
            if (next) {
              link.setAttribute('href', next.getAttribute('href'));
              link.removeAttribute('aria-busy');
            } else {
              var wrap = link.closest('.sb-shop-more') || link;
              wrap.parentNode.removeChild(wrap);
            }
          })
          .catch(function () { global.location.assign(href); });
      });
      mount(off);
    });
  }

  function sameOriginUrl(url) {
    try { return new URL(url, global.location.href).origin === global.location.origin; } catch (e) { return false; }
  }

  /**
   * Option pickers for a product with variants: `form[data-sb-variants]` lists the combinations sold as
   * {"v": [[id, [value per picker], in stock (1|0), price text], ...]}; each `select[data-sb-variant-attr]` picks one
   * value. A value that leads to no combination in stock (with the other choices) is disabled, the chosen combination's
   * id goes in `input[name=variant_id]`, its price in `[data-sb-variant-price]`, and the submit button stays off until
   * the choice names a combination in stock. A row's fifth entry, when there is one, is that combination's photo: it
   * takes the place of the main picture of the product's gallery (`[data-sb-shop-gallery="<product id>"]`) until the
   * choice changes or is reset. Without this script the form still posts the chosen values and the server resolves them.
   */
  function initVariants(root) {
    var forms = qsa(root, 'form[data-sb-variants]');
    if (!forms.length) return;
    forms.forEach(function (form) {
      var rows;
      try { rows = JSON.parse(form.getAttribute('data-sb-variants') || '{}').v; } catch (e) { return; }
      if (!Array.isArray(rows)) return;
      var selects = qsa(form, 'select[data-sb-variant-attr]');
      var idField = qs(form, 'input[name="variant_id"]');
      if (!selects.length || !idField) return;
      var button = qs(form, '[data-sb-variant-submit]');
      var price = qs(form, '[data-sb-variant-price]');
      var reset = qs(form, '[data-sb-variant-reset]');
      var pid = qs(form, 'input[name="product_id"]');
      var gallery = pid ? qs(document, '[data-sb-shop-gallery="' + String(pid.value).replace(/[^0-9]/g, '') + '"]') : null;
      var mainImg = gallery ? qs(gallery, '.sb-shop-gallery__main img') : null;
      var firstSrc = mainImg ? mainImg.getAttribute('src') : '';
      var firstSrcset = mainImg ? mainImg.getAttribute('srcset') : null;
      function showPhoto(src) {
        if (!mainImg) return;
        var next = src || firstSrc;
        if (mainImg.getAttribute('src') === next) return;
        if (src) mainImg.removeAttribute('srcset'); else if (firstSrcset) mainImg.setAttribute('srcset', firstSrcset);
        mainImg.setAttribute('src', next);
        gallery.setAttribute('data-sb-variant-photo', src ? 'on' : 'off');
      }
      function fits(row, values) {
        for (var i = 0; i < values.length; i++) {
          if (values[i] !== '' && row[1][i] !== values[i]) return false;
        }
        return true;
      }
      function update() {
        var values = selects.map(function (s) { return s.value; });
        selects.forEach(function (s, i) {
          Array.prototype.forEach.call(s.options, function (o) {
            if (o.value === '') return;
            var test = values.slice();
            test[i] = o.value;
            o.disabled = !rows.some(function (r) { return r[2] && fits(r, test); });
          });
        });
        var match = null;
        if (values.indexOf('') === -1) rows.forEach(function (r) { if (fits(r, values)) match = r; });
        var ok = !!(match && match[2]);
        idField.value = ok ? String(match[0]) : '';
        if (button) button.disabled = !ok;
        if (price) { price.textContent = match ? String(match[3]) : ''; price.hidden = !match; }
        // The photo of the chosen combination, or of the only one left that fits what is chosen so far.
        var photo = match && match[4] ? match[4] : '';
        if (!photo && values.join('') !== '') {
          var fitting = rows.filter(function (r) { return fits(r, values) && r[4]; });
          var same = fitting.length && fitting.every(function (r) { return r[4] === fitting[0][4]; });
          if (same) photo = fitting[0][4];
        }
        showPhoto(photo);
        if (reset) reset.hidden = values.join('') === '';
        form.setAttribute('data-sb-variant-state', ok ? 'ready' : (match ? 'sold-out' : 'choose'));
      }
      var off = selects.map(function (s) { return on(s, 'change', update); });
      if (reset) {
        off.push(on(reset, 'click', function () {
          selects.forEach(function (s) { s.value = ''; });
          update();
        }));
      }
      // A link that names a choice (`?option=Maple Bacon`, from a Product Options widget) makes it.
      try {
        var wanted = global.URLSearchParams ? new URLSearchParams(global.location.search).get('option') : null;
        if (wanted) {
          selects.some(function (sel) {
            var hit = Array.prototype.some.call(sel.options, function (o) { return o.value === wanted && !o.disabled; });
            if (hit) sel.value = wanted;
            return hit;
          });
        }
      } catch (e) { /* a malformed address: nothing chosen */ }
      update();
      mount(function () {
        off.forEach(function (un) { un(); });
        if (button) button.disabled = false;
        showPhoto('');
        form.removeAttribute('data-sb-variant-state');
      });
    });
  }

  /** When a visitor's evergreen timer ends. It starts on their first view and survives a reload; a new length restarts it. */
  function evergreenEnd(key, length) {
    var name = 'sb-countdown-' + key;
    if (key) {
      try {
        var saved = JSON.parse(global.localStorage.getItem(name));
        if (saved && saved.length === length && saved.end > 0) return saved.end;
      } catch (e) { /* no storage, or an unreadable entry: start again */ }
    }
    var end = Date.now() + length * 1000;
    if (key) {
      try { global.localStorage.setItem(name, JSON.stringify({ length: length, end: end })); } catch (e) { /* keep the timer for this view */ }
    }
    return end;
  }

  /** A finished countdown's action, set by the renderer on the public page only. */
  function expire(el) {
    var action = el.getAttribute('data-sb-countdown-expire');
    if (action === 'hide') {
      el.setAttribute('hidden', '');
    } else if (action === 'redirect') {
      var url = new URL(el.getAttribute('data-sb-href'), global.location.href);
      // A page that redirects to itself would reload for ever.
      if (/^https?:$/.test(url.protocol) && url.pathname !== global.location.pathname) global.location.replace(url.href);
    }
  }

  function initCountdown(root) {
    var els = qsa(root, '[data-sb-countdown]');
    if (!els.length) return;

    var timers = [];
    var live = [];
    els.forEach(function (el) {
      var length = parseInt(el.getAttribute('data-sb-countdown-evergreen'), 10);
      var target = length > 0
        ? evergreenEnd(el.getAttribute('data-sb-countdown-key'), length)
        : new Date(el.getAttribute('data-sb-countdown')).getTime();
      if (isNaN(target)) return;
      var parts = {
        d: qs(el, '[data-sb-cd="d"]'), h: qs(el, '[data-sb-cd="h"]'),
        m: qs(el, '[data-sb-cd="m"]'), s: qs(el, '[data-sb-cd="s"]')
      };
      function pad(n) { return n < 10 ? '0' + n : String(n); }

      function tick() {
        var diff = target - Date.now();
        if (diff <= 0) {
          el.setAttribute('data-sb-finished', '');
          ['d', 'h', 'm', 's'].forEach(function (k) { if (parts[k]) parts[k].textContent = '00'; });
          expire(el);
          return false;
        }
        // A unit that is switched off passes its time down: without days, hours count past 24.
        var secs = Math.floor(diff / 1000);
        [['d', 86400], ['h', 3600], ['m', 60], ['s', 1]].forEach(function (u) {
          if (!parts[u[0]]) return;
          parts[u[0]].textContent = pad(Math.floor(secs / u[1]));
          secs %= u[1];
        });
        return true;
      }

      tick();
      // The units are hidden by the stylesheet until this attribute is set, so a visitor without JS sees the
      // static date line instead of a frozen "00:00:00:00".
      el.setAttribute('data-sb-live', '');
      var handle = global.setInterval(function () {
        if (document.hidden) return; // don't burn cycles on a background tab
        if (!tick()) global.clearInterval(handle);
      }, 1000);
      timers.push(handle);
      live.push(el);
    });

    mount(function () {
      timers.forEach(function (t) { global.clearInterval(t); });
      // Back to what the server rendered, which is what the A/B toggle relies on.
      live.forEach(function (el) {
        el.removeAttribute('data-sb-live');
        el.removeAttribute('data-sb-finished');
        el.removeAttribute('hidden');
        qsa(el, '[data-sb-cd]').forEach(function (n) { n.textContent = '00'; });
      });
    });
  }

  /* ── Feature: smooth anchor scroll ──────────────────────────────────────
   * Upgrades in-page fragment links only, and honours reduced motion by
   * letting the browser perform its default instant jump.
   */

  function initAnchors(root) {
    var off = [];
    qsa(root, 'a[href^="#"]').forEach(function (a) {
      var hash = a.getAttribute('href');
      if (!hash || hash.length < 2) return;
      off.push(on(a, 'click', function (e) {
        if (reducedMotion()) return;
        var target;
        try { target = document.querySelector(hash); } catch (err) { return; }
        if (!target) return;
        e.preventDefault();
        var top = target.getBoundingClientRect().top + global.scrollY - 72;
        var start = global.scrollY;
        var t0 = null;
        function step(ts) {
          if (t0 === null) t0 = ts;
          var p = clamp((ts - t0) / CONFIG.anchorDuration, 0, 1);
          global.scrollTo(0, start + (top - start) * easeOutExpo(p));
          if (p < 1) global.requestAnimationFrame(step);
          else if (history.replaceState) history.replaceState(null, '', hash);
        }
        global.requestAnimationFrame(step);
        // Move focus for keyboard users without adding a visible tab stop.
        if (!target.hasAttribute('tabindex') && /^(H[1-6]|SECTION|MAIN)$/.test(target.tagName)) {
          target.setAttribute('tabindex', '-1');
        }
        target.focus({ preventScroll: true });
      }));
    });

    if (off.length) mount(function () { off.forEach(function (un) { un(); }); });
  }

  /* A menu's link to the page being viewed. The header is rendered once for every page, so the server cannot mark it;
   * this does, so `aria-current` tells a screen reader where the visitor is and the menu's "Current page link" part
   * (BlockParts) can style it. A trailing slash and the query do not count; a link to another site never matches.
   */
  function initCurrentLinks(root) {
    var here = location.pathname.replace(/\/+$/, '') || '/';
    var marked = [];
    var parents = [];
    qsa(root, 'a.sb-nav__link[href]').forEach(function (a) {
      if (a.hasAttribute('aria-current')) return;
      var url;
      try { url = new URL(a.getAttribute('href'), location.href); } catch (err) { return; }
      if (url.origin !== location.origin) return;
      if ((url.pathname.replace(/\/+$/, '') || '/') !== here) return;
      a.setAttribute('aria-current', 'page');
      a.classList.add('is-current');
      marked.push(a);
      var parent = a.closest('.sb-nav__sub');
      while (parent) {
        var li = parent.parentNode;
        li.classList.add('is-current-parent');
        parents.push(li);
        parent = li.parentNode.closest('.sb-nav__sub');
      }
    });
    if (marked.length) {
      mount(function () {
        marked.forEach(function (a) { a.removeAttribute('aria-current'); a.classList.remove('is-current'); });
        parents.forEach(function (li) { li.classList.remove('is-current-parent'); });
      });
    }
  }

  /* A menu folded behind its button right now (the bar only shows below the menu's breakpoint). */
  function navFolded(el) {
    var nav = el.closest('[data-sb-nav-toggle]');
    var bar = nav && qs(nav, '.sb-nav__bar');
    return !!bar && global.getComputedStyle(bar).display !== 'none';
  }

  /* Whether a submenu item drops down as a card: a horizontal menu that is not folded. */
  function navFlyout(li) {
    return !!li.closest('.sb-nav--horizontal') && !navFolded(li);
  }

  /* ── Feature: the menu button ────────────────────────────────────────────
   * NavMenuRenderer's checkbox is the open state, so the stylesheet has one rule for it with or without this file. This
   * hands the control to the real button (aria-expanded), and adds what CSS cannot do: Escape and a click elsewhere close
   * the menu, a link closes it, an off-canvas panel holds focus and the page behind stays put, and a full-width
   * dropdown measures the window. Growing past the breakpoint closes it.
   */
  function initNavToggles(root) {
    var off = [];
    var closers = [];
    qsa(root, '[data-sb-nav-toggle]').forEach(function (nav) {
      var burger = qs(nav, '.sb-nav__burger');
      var check = qs(nav, '.sb-nav__check');
      var list = qs(nav, '.sb-nav__list');
      var backdrop = qs(nav, '.sb-nav__backdrop');
      if (!burger || !check || !list) return;
      // A side panel and a full-screen menu cover the page: they hold focus and keep it from scrolling.
      var side = nav.classList.contains('sb-nav--menu-offcanvas') || nav.classList.contains('sb-nav--menu-fullscreen');
      var release = null;
      burger.removeAttribute('tabindex');

      // A full-width dropdown spans the window, and any dropdown starts at the header's bottom edge, not the button's.
      function place() {
        if (side) return;
        var rect = nav.getBoundingClientRect();
        var header = nav.closest('.sb-site-header');
        var section = header && (nav.closest('.sb-top') || header);
        if (section) nav.style.setProperty('--sb-nav-top', (section.getBoundingClientRect().bottom - rect.top) + 'px');
        if (nav.classList.contains('sb-nav--menu-full')) {
          nav.style.setProperty('--sb-nav-left', (-rect.left) + 'px');
          nav.style.setProperty('--sb-nav-vw', document.documentElement.clientWidth + 'px');
        }
      }
      function close(refocus) {
        if (!check.checked) return;
        check.checked = false;
        burger.setAttribute('aria-expanded', 'false');
        qsa(list, '.sb-nav__item--parent[data-open]').forEach(function (li) {
          li.removeAttribute('data-open');
          var btn = li.querySelector(':scope > .sb-nav__toggle, :scope > .sb-nav__parent');
          if (btn) btn.setAttribute('aria-expanded', 'false');
        });
        var autofocus = qs(list, '[data-sb-autofocus]');
        if (autofocus) autofocus.removeAttribute('data-sb-autofocus');
        if (side) document.documentElement.classList.remove('sb-scroll-lock');
        if (release) { var free = release; release = null; free(); }
        if (refocus && !side) burger.focus();
      }
      function open() {
        place();
        check.checked = true;
        burger.setAttribute('aria-expanded', 'true');
        if (side) {
          document.documentElement.classList.add('sb-scroll-lock');
          var first = qs(list, 'a, button');
          if (first) first.setAttribute('data-sb-autofocus', '');
          release = trapFocus(nav, function () { close(true); });
        }
      }
      closers.push(close);
      off.push(on(burger, 'click', function () { if (check.checked) close(true); else open(); }));
      if (backdrop) off.push(on(backdrop, 'click', function (e) { e.preventDefault(); close(true); }));
      off.push(on(list, 'click', function (e) { if (e.target.closest('a')) close(false); }));
      off.push(on(nav, 'keydown', function (e) {
        if ((e.key === 'Escape' || e.key === 'Esc') && check.checked && !side) { e.stopPropagation(); close(true); }
      }));
      off.push(on(nav, 'focusout', function (e) {
        if (!side && check.checked && e.relatedTarget && !nav.contains(e.relatedTarget)) close(false);
      }));
      off.push(on(document, 'click', function (e) { if (!nav.contains(e.target)) close(false); }));
      off.push(on(global, 'resize', function () {
        if (!check.checked) return;
        if (navFolded(nav)) place(); else close(false);
      }, { passive: true }));
      closers.push(function () { burger.setAttribute('tabindex', '-1'); });
    });
    if (closers.length) {
      mount(function () {
        closers.forEach(function (fn) { fn(false); });
        off.forEach(function (un) { un(); });
      });
    }
  }

  /* ── Feature: mega menu panels ───────────────────────────────────────────
   * A panel starts at the header's bottom edge and spans the window. Without this file it keeps the menu's own width.
   */
  function initMegaMenus(root) {
    var off = [];
    var navs = qsa(root, '[data-sb-mega]');
    navs.forEach(function (nav) {
      function place() {
        var rect = nav.getBoundingClientRect();
        var header = nav.closest('.sb-site-header');
        var section = header && (nav.closest('.sb-top') || header);
        if (section) nav.style.setProperty('--sb-mega-top', (section.getBoundingClientRect().bottom - rect.top) + 'px');
        nav.style.setProperty('--sb-mega-left', (-rect.left) + 'px');
        nav.style.setProperty('--sb-mega-vw', document.documentElement.clientWidth + 'px');
      }
      place();
      off.push(on(nav, 'mouseover', place));
      off.push(on(nav, 'focusin', place));
      off.push(on(global, 'resize', place, { passive: true }));
    });
    if (navs.length) {
      mount(function () {
        off.forEach(function (un) { un(); });
        navs.forEach(function (nav) { ['--sb-mega-top', '--sb-mega-left', '--sb-mega-vw'].forEach(function (name) { nav.style.removeProperty(name); }); });
      });
    }
  }

  /* ── Feature: menu submenus ──────────────────────────────────────────────
   * NavMenuRenderer's submenus open on hover and keyboard focus with CSS alone; this adds the click and keyboard toggle.
   * Its button (the arrow, or an item with no address of its own) sets `aria-expanded` and `data-open` on the item;
   * opening one closes its open siblings, and Escape or a click outside the menu closes them (Escape returns focus
   * to the button; `data-closed` keeps a horizontal submenu shut while focus is still inside it).
   */
  function initSubmenus(root) {
    var off = [];
    var items = qsa(root, '.sb-nav__item--parent');
    if (!items.length) return;
    function set(li, open) {
      var btn = li.querySelector(':scope > .sb-nav__toggle, :scope > .sb-nav__parent');
      if (open) { li.setAttribute('data-open', ''); li.removeAttribute('data-closed'); } else { li.removeAttribute('data-open'); }
      if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (!open) qsa(li, '.sb-nav__item--parent[data-open]').forEach(function (child) { set(child, false); });
    }
    // A submenu that would run past the right edge of the window opens the other way (`data-flip`).
    function fit(li) {
      var sub = li.querySelector(':scope > .sb-nav__sub');
      if (!sub || sub.classList.contains('sb-nav__mega')) return;
      li.removeAttribute('data-flip');
      if (sub.getBoundingClientRect().right > document.documentElement.clientWidth - 8) li.setAttribute('data-flip', '');
    }
    function closeSiblings(li) {
      if (!li.parentNode) return;
      Array.prototype.forEach.call(li.parentNode.children, function (sib) {
        if (sib === li) return;
        if (sib.hasAttribute('data-open')) set(sib, false);
        // Focus left on its button (it was clicked) would keep it showing (:focus-within).
        if (sib.classList.contains('sb-nav__item--parent') && sib.contains(document.activeElement)) sib.setAttribute('data-closed', '');
      });
    }
    items.forEach(function (li) {
      var btn = li.querySelector(':scope > .sb-nav__toggle, :scope > .sb-nav__parent');
      if (!btn) return;
      off.push(on(btn, 'click', function (e) {
        e.preventDefault();
        var open = !li.hasAttribute('data-open');
        if (open) closeSiblings(li);
        set(li, open);
        if (!open) li.setAttribute('data-closed', '');
      }));
      // Pointing at another item of a horizontal menu shows only its submenu: one opened by a click closes.
      off.push(on(li, 'mouseenter', function () {
        li.removeAttribute('data-closed');
        if (navFlyout(li)) { closeSiblings(li); fit(li); }
      }));
      off.push(on(li, 'focusin', function () { if (navFlyout(li)) fit(li); }));
      off.push(on(li, 'mouseleave', function () { li.removeAttribute('data-closed'); }));
      off.push(on(li, 'focusout', function (e) {
        if (!li.contains(e.relatedTarget)) { li.removeAttribute('data-closed'); if (navFlyout(li)) set(li, false); }
      }));
      off.push(on(li, 'keydown', function (e) {
        if (e.key !== 'Escape' && e.key !== 'Esc') return;
        if (!li.hasAttribute('data-open') && (!li.matches(':focus-within') || !navFlyout(li))) return;
        e.stopPropagation();
        set(li, false);
        li.setAttribute('data-closed', '');
        btn.focus();
      }));
    });
    off.push(on(document, 'click', function (e) {
      qsa(root, '.sb-nav__item--parent[data-open]').forEach(function (li) { if (!li.contains(e.target)) set(li, false); });
    }));
    mount(function () {
      off.forEach(function (un) { un(); });
      items.forEach(function (li) { set(li, false); li.removeAttribute('data-closed'); li.removeAttribute('data-flip'); });
    });
  }

/* ── Boot / teardown ─────────────────────────────────────────────────────
   * `StudioRuntime.mount()` and `.disable()` are what the A/B toggle calls.
   * disable() must leave the page byte-identical to a no-JS Studio render,
   * which is what makes the prototype honest as a decision artefact.
   */

  var FEATURES = [
    ['reveal', initReveal],
    ['stagger', initStagger],
    ['viewport-fallback', initViewportFallback],
    ['counters', initCounters],
    ['parallax', initParallax],
    ['scroll-effects', initScrollEffects],
    ['mouse-effects', initMouseEffects],
    ['page-colors', initPageColors],
    ['progress', initProgress],
    ['text-effects', initTextEffects],
    ['wave', initWave],
    ['rotate', initRotate],
    ['glow', initGlow],
    ['smooth-scroll', initSmoothScroll],
    ['ticker', initTicker],
    ['horizontal', initHorizontal],
    ['hover-image', initHoverImage],
    ['timeline', initTimeline],
    ['progress-bars', initProgressBars],
    ['interactions', initInteractions],
    ['sticky', initSticky],
    ['overlays', initOverlays],
    ['galleries', initGalleries],
    ['galleryLightbox', initGalleryLightbox],
    ['lightbox', initLightbox],
    ['videos', initVideos],
    ['audio', initAudio],
    ['tabs', initTabs],
    ['accordion', initAccordion],
    ['carousel', initCarousel],
    ['marquee', initMarquee],
    ['countdown', initCountdown],
    ['urlFill', initUrlFill],
    ['forms', initForms],
    ['loopFilters', initLoopFilters],
    ['loopDialogs', initLoopDialogs],
    ['messageWidgets', initMessageWidgets],
    ['clocks', initClocks],
    ['fragments', initFragments],
    ['variants', initVariants],
    ['shopGrid', initShopGrid],
    ['currentLinks', initCurrentLinks],
    ['spy', initSpy],
    ['copy', initCopy],
    ['navToggles', initNavToggles],
    ['megaMenus', initMegaMenus],
    ['submenus', initSubmenus],
    ['anchors', initAnchors]
  ];

  function enabled() {
    return document.documentElement.getAttribute('data-sb-runtime') !== 'off';
  }

  var StudioRuntime = {
    version: '0.1.0-prototype',
    features: FEATURES.map(function (f) { return f[0]; }),

    /** Start every feature. Safe to call again after disable(). */
    mount: function (root) {
      if (booted) return StudioRuntime;
      booted = true;
      if (!enabled()) return StudioRuntime;
      var scope = root || document;
      FEATURES.forEach(function (f) {
        try {
          f[1](scope);
        } catch (err) {
          // One broken feature must never take down the page.
          if (global.console && console.warn) console.warn('[studio-runtime] ' + f[0] + ' failed:', err);
        }
      });
      document.documentElement.setAttribute('data-sb-ready', '');
      return StudioRuntime;
    },

    /** Undo every mutation this file made. */
    disable: function () {
      teardownAll();
      booted = false;
      document.documentElement.removeAttribute('data-sb-ready');
      closeAllOverlays();
      return StudioRuntime;
    },

    /** Re-evaluate the reduced-motion contract in place, without remounting. */
    refreshMotion: function () {
      if (!booted) return StudioRuntime;
      StudioRuntime.disable();
      return StudioRuntime.mount();
    },

    isMounted: function () { return booted; }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { StudioRuntime.mount(); });
  } else {
    StudioRuntime.mount();
  }

  global.StudioRuntime = StudioRuntime;

  /* ── Admin bar ────────────────────────────────────────────────────────────
   * This page is the same for everyone, so it holds no sign of who is viewing it. A signed-in admin's browser carries
   * a hint cookie (not proof of anything): only then is the bar's script fetched, and it asks the server for the bar.
   * The script takes this file's `?v=`.
   */
  var runtimeScript = document.currentScript;
  if (runtimeScript && runtimeScript.src && document.body && document.body.classList.contains('sb-body--public')
    && /(?:^|;\s*)kohevo_admin_hint=1(?:;|$)/.test(document.cookie)) {
    var bar = document.createElement('script');
    bar.src = runtimeScript.src.replace(/plugins\/studio-builder\/assets\/public\/studio-runtime\.js/, 'assets/js/front-admin-bar.js');
    document.head.appendChild(bar);
  }
}(window));