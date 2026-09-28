/*! Kohevo booking widget — host-page helper.
 *
 * Paste next to the booking <iframe> on any website (the admin shows the exact snippet):
 *
 *   <iframe src="https://YOUR-SITE/book?embed=1" data-kohevo-booking ...></iframe>
 *   <script src="https://YOUR-SITE/plugins/booking/assets/js/embed.js" async></script>
 *
 * It sizes the iframe to the widget's content (no inner scrollbar, no clipped step) and brings the widget back
 * into view when the visitor moves to the next step. A cross-origin iframe can not measure itself, so the widget
 * reports its height with postMessage and this script applies it.
 *
 * Safety: a message is used only if it comes from the iframe's own window AND from that iframe's origin, the
 * height is clamped to a sane range, and nothing from the message is ever written into the page as markup.
 * Plain ES5, no dependencies.
 */
(function () {
  'use strict';
  if (window.__kohevoBookingEmbed) return;
  window.__kohevoBookingEmbed = true;

  var TYPES = { 'kohevo-booking-height': true, 'cb-booking-height': true }; // second name: older widgets / Content Builder block
  var MIN_H = 50, MAX_H = 20000;

  var script = document.currentScript, scriptOrigin = '';
  try { scriptOrigin = new URL(script.src, location.href).origin; } catch (e) {}

  function originOf(url) { try { return new URL(url, location.href).origin; } catch (e) { return ''; } }

  /** The iframe that sent this message, if it is one of ours. */
  function frameFor(win) {
    var list = document.getElementsByTagName('iframe');
    for (var i = 0; i < list.length; i++) {
      var f = list[i];
      if (f.contentWindow !== win) continue;
      var src = f.getAttribute('src') || '';
      var mine = f.hasAttribute('data-kohevo-booking') ||
                 (scriptOrigin && originOf(src) === scriptOrigin && /^\/book(\/|$)/.test(new URL(src, location.href).pathname));
      return mine ? f : null;
    }
    return null;
  }

  function reducedMotion() {
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  window.addEventListener('message', function (e) {
    var d = e.data;
    if (!d || typeof d !== 'object' || !TYPES[d.type]) return;
    var f = frameFor(e.source);
    if (!f || e.origin !== originOf(f.getAttribute('src'))) return;

    var h = Math.ceil(Number(d.height));
    if (!isFinite(h) || h < MIN_H || h > MAX_H) return;
    f.style.height = h + 'px';
    f.style.minHeight = '0';           // the snippet's min-height only reserves space until the first report
    f.setAttribute('scrolling', 'no');

    if (d.first) {
      // A new page loaded inside the iframe (a new step). Tell it that we are auto-sizing, so it can hide its scrollbar.
      try { e.source.postMessage({ type: 'kohevo-booking-host', v: 1 }, e.origin); } catch (err) {}
      // Moving to another step swaps the whole page: if the widget's top is scrolled out of view, bring it back.
      if (f.__kbSeen) {
        var r = f.getBoundingClientRect();
        if (r.top < 0 || r.top > window.innerHeight * 0.8) {
          f.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
        }
      }
      f.__kbSeen = true;
    }
  });
})();
