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
      render(el, 0);

      if (reducedMotion() || !('IntersectionObserver' in global)) {
        render(el, to);
        return;
      }

      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          io.unobserve(entry.target);
          var start = null;
          function tick(ts) {
            if (start === null) start = ts;
            var p = clamp((ts - start) / duration, 0, 1);
            render(el, to * easeOutExpo(p));
            if (p < 1) global.requestAnimationFrame(tick);
          }
          global.requestAnimationFrame(tick);
        });
      }, { threshold: 0.4 });

      io.observe(el);
    });

    mount(function () { els.forEach(function (el) { el.removeAttribute('data-sb-counted'); }); });
  }

  /* ── Feature: parallax ───────────────────────────────────────────────────
   * Single shared rAF loop. Translates by a percentage of the element's
   * travel, transform-only so it stays off the layout path.
   */

  function initParallax(root) {
    var els = qsa(root, '[data-sb-parallax]');
    if (!els.length || reducedMotion()) return;

    var ticking = false;
    function update() {
      ticking = false;
      var vh = global.innerHeight || 1;
      els.forEach(function (el) {
        var rect = el.getBoundingClientRect();
        if (rect.bottom < -200 || rect.top > vh + 200) return; // offscreen: skip
        var speed = parseFloat(el.getAttribute('data-sb-parallax') || '0.2');
        var centre = rect.top + rect.height / 2 - vh / 2;
        var shift = -centre * speed;
        el.style.transform = 'translate3d(0,' + shift.toFixed(2) + 'px,0)';
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
    });
  }
/* ── Feature: overlay focus trap ────────────────────────────────────────
   * Shared by modal, offcanvas and lightbox. The current Kohevo modal relies on
   * CSS `:target`, which cannot trap focus, cannot close on Escape, and leaves
   * aria-hidden="true" permanently attached to a visible dialog. This is the fix.
   */

  var openOverlays = [];

  function trapFocus(container) {
    function focusables() {
      return qsa(container, CONFIG.FOCUSABLE).filter(function (el) {
        return el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement;
      });
    }

    function onKey(e) {
      if (e.key === 'Escape') { e.preventDefault(); close(); return; }
      if (e.key !== 'Tab') return;
      var list = focusables();
      if (!list.length) { e.preventDefault(); return; }
      var first = list[0];
      var last = list[list.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    // Move focus inside on open, and remember where it came from.
    var previous = document.activeElement;
    var initial = qs(container, '[data-sb-autofocus]') || focusables()[0] || container;
    global.setTimeout(function () { try { initial.focus(); } catch (err) { /* detached */ } }, 0);
    document.addEventListener('keydown', onKey, true);

    function close() {
      document.removeEventListener('keydown', onKey, true);
      try { if (previous && previous.focus) previous.focus(); } catch (err) { /* detached */ }
    }
    return close;
  }

  function openOverlay(el, trigger) {
    if (el.hasAttribute('data-sb-open')) return;

    var release = trapFocus(el);
    el.setAttribute('data-sb-open', '');
    el.removeAttribute('aria-hidden');
    document.documentElement.setAttribute('data-sb-overlay-open', '');

    // Lock the page behind the overlay without causing a layout shift.
    var scrollY = global.scrollY;
    document.body.style.position = 'fixed';
    document.body.style.top = '-' + scrollY + 'px';
    document.body.style.width = '100%';

    function close() {
      if (!el.hasAttribute('data-sb-open')) return;
      el.removeAttribute('data-sb-open');
      el.setAttribute('aria-hidden', 'true');
      if (trigger && trigger.setAttribute) trigger.setAttribute('aria-expanded', 'false');
      if (!openOverlays.length) {
        document.documentElement.removeAttribute('data-sb-overlay-open');
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.width = '';
        global.scrollTo(0, scrollY);
      }
      release();
    }

    if (trigger && trigger.setAttribute) trigger.setAttribute('aria-expanded', 'true');
    openOverlays.push({ el: el, close: close });
    el.__sbClose = close;
    el.dispatchEvent(new CustomEvent('sb:open'));
    return close;
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

    qsa(root, '[data-sb-modal], [data-sb-offcanvas]').forEach(function (el) {
      if (!el.id) el.id = uniqueId();
      el.setAttribute('role', 'dialog');
      el.setAttribute('aria-modal', 'true');
      // Progressive enhancement: with no JS the trigger stays a real fragment
      // link, and :target still reveals the panel.
      el.setAttribute('aria-hidden', 'true');
      el.setAttribute('data-sb-overlay', '');

      var off = [];
      off.push(on(el, 'click', function (e) {
        var closer = e.target.closest('[data-sb-close], .sb-modal__backdrop, .sb-offcanvas__backdrop');
        if (!closer || !el.contains(closer)) return;
        e.preventDefault();
        if (el.__sbClose) el.__sbClose();
      }));

      var kind = el.hasAttribute('data-sb-modal') ? 'modal' : 'offcanvas';
      var openAttr = 'data-sb-' + kind + '-open';
      var baseAttr = 'data-sb-' + kind;

      qsa(root, '[' + openAttr + ']').forEach(function (trigger) {
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

      void baseAttr;
      teardowns.push(function () {
        off.forEach(function (un) { un(); });
        if (el.__sbClose) el.__sbClose();
        el.removeAttribute('data-sb-open');
        el.setAttribute('aria-hidden', 'true');
      });
    });

    if (!teardowns.length) return;
    mount(function () {
      closeAllOverlays();
      teardowns.forEach(function (fn) { fn(); });
      qsa(root, '[aria-haspopup="dialog"]').forEach(function (el) {
        el.setAttribute('aria-expanded', 'false');
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
      if (e.target.closest('[data-sb-lb-prev]')) { e.preventDefault(); show(index - 1); }
      if (e.target.closest('[data-sb-lb-next]')) { e.preventDefault(); show(index + 1); }
    }));
    off.push(on(document, 'keydown', function (e) {
      if (!lb.hasAttribute('data-sb-open')) return;
      if (e.key === 'ArrowLeft') show(index - 1);
      if (e.key === 'ArrowRight') show(index + 1);
    }));

    mount(function () { off.forEach(function (un) { un(); }); });
  }
/* ── Feature: tabs ──────────────────────────────────────────────────────
   * Full APG keyboard support: arrows move and activate, Home/End jump,
   * roving tabindex keeps a single tab stop for the whole strip.
   */

  function initTabs(root) {
    var groups = qsa(root, '[data-sb-tabs]');
    var teardowns = [];

    groups.forEach(function (group) {
      var tabs = qsa(group, '[data-sb-tab]');
      var panels = qsa(group, '[data-sb-panel]');
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
      var items = qsa(group, '[data-sb-accordion-item]');
      if (!items.length) return;

      function panelOf(item) { return qs(item, '[data-sb-accordion-panel]'); }
      function buttonOf(item) { return qs(item, '[data-sb-accordion-button]'); }

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
        setState(item, i === 0);

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

      var perView = parseInt(group.getAttribute('data-sb-per-view') || '1', 10);
      var loop = group.hasAttribute('data-sb-loop');
      var auto = parseInt(group.getAttribute('data-sb-autoplay') || '0', 10);
      var index = 0;
      var timer = null;
      var dots = [];

      group.setAttribute('role', 'region');
      group.setAttribute('aria-roledescription', 'carousel');
      slides.forEach(function (s, i) {
        s.setAttribute('role', 'group');
        s.setAttribute('aria-roledescription', 'slide');
        s.setAttribute('aria-label', (i + 1) + ' of ' + slides.length);
        if (!s.id) s.id = uid('slide');
      });

      var max = Math.max(0, slides.length - perView);

      function render() {
        var offset = -(index * (100 / perView));
        track.style.transform = 'translate3d(' + offset + '%,0,0)';
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
          d.setAttribute('aria-selected', i === index ? 'true' : 'false');
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
          track.style.transform = 'translate3d(calc(' + -(index * (100 / perView)) + '% + ' + delta + 'px),0,0)';
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
      var dotsHost = qs(group, '[data-sb-car-dots]');
      if (dotsHost) {
        dotsHost.innerHTML = '';
        for (var i = 0; i <= max; i++) {
          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'sb-carousel__dot';
          b.setAttribute('data-sb-car-dot', String(i));
          b.setAttribute('role', 'tab');
          b.setAttribute('aria-label', 'Go to slide ' + (i + 1));
          dotsHost.appendChild(b);
          dots.push(b);
        }
      }

      function stop() { if (timer) { global.clearInterval(timer); timer = null; } }
      function restart() {
        stop();
        if (!auto || reducedMotion() || !loop) return;
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

      render();
      restart();
      teardowns.push(function () {
        stop();
        document.removeEventListener('visibilitychange', onVisibility);
        off.forEach(function (un) { un(); });
        track.style.transform = '';
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

/* ── Feature: sticky header ───────────────────────────────────────────────
   * Adds a shadow + blur once the header has scrolled past its trigger point.
   * Uses an IntersectionObserver sentinel rather than a scroll listener, so the
   * main thread is never touched during scroll.
   */

  function initStickyHeader(root) {
    var header = qs(root, '[data-sb-sticky]');
    if (!header) return;

    var sentinel = document.createElement('div');
    sentinel.setAttribute('data-sb-sticky-sentinel', '');
    sentinel.setAttribute('aria-hidden', 'true');
    header.parentNode.insertBefore(sentinel, header);

    var off = [];
    if ('IntersectionObserver' in global) {
      var io = new IntersectionObserver(function (entries) {
        var stuck = !entries[0].isIntersecting;
        header.setAttribute('data-sb-stuck', stuck ? '' : 'off');
        header.classList.toggle('is-stuck', stuck);
      }, { threshold: 0 });
      io.observe(sentinel);
      off.push(function () { io.disconnect(); });
    }

    mount(function () {
      off.forEach(function (fn) { fn(); });
      header.removeAttribute('data-sb-stuck');
      header.classList.remove('is-stuck');
      if (sentinel.parentNode) sentinel.parentNode.removeChild(sentinel);
    });
  }

  /* ── Feature: countdown ──────────────────────────────────────────────────
   * The element's existing text is the no-JS fallback, so the degraded state
   * is a sensible static label rather than a blank gap.
   */

  function initCountdown(root) {
    var els = qsa(root, '[data-sb-countdown]');
    if (!els.length) return;

    var timers = [];
    els.forEach(function (el) {
      var target = new Date(el.getAttribute('data-sb-countdown')).getTime();
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
          return false;
        }
        var secs = Math.floor(diff / 1000);
        if (parts.d) parts.d.textContent = pad(Math.floor(secs / 86400));
        if (parts.h) parts.h.textContent = pad(Math.floor((secs % 86400) / 3600));
        if (parts.m) parts.m.textContent = pad(Math.floor((secs % 3600) / 60));
        if (parts.s) parts.s.textContent = pad(secs % 60);
        return true;
      }

      tick();
      var handle = global.setInterval(function () {
        if (document.hidden) return; // don't burn cycles on a background tab
        if (!tick()) global.clearInterval(handle);
      }, 1000);
      timers.push(handle);
    });

    mount(function () { timers.forEach(function (t) { global.clearInterval(t); }); });
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

/* ── Boot / teardown ─────────────────────────────────────────────────────
   * `StudioRuntime.mount()` and `.disable()` are what the A/B toggle calls.
   * disable() must leave the page byte-identical to a no-JS Studio render,
   * which is what makes the prototype honest as a decision artefact.
   */

  var FEATURES = [
    ['reveal', initReveal],
    ['counters', initCounters],
    ['parallax', initParallax],
    ['stickyHeader', initStickyHeader],
    ['overlays', initOverlays],
    ['lightbox', initLightbox],
    ['tabs', initTabs],
    ['accordion', initAccordion],
    ['carousel', initCarousel],
    ['marquee', initMarquee],
    ['countdown', initCountdown],
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
}(window));