// Behavioural test for plugins/booking/assets/js/embed.js — runs the real script in a sandbox with a fake window.
// usage: node embed-host.test.js <path-to-embed.js>   → prints one JSON object of results.
const vm = require('vm'), fs = require('fs');
const src = fs.readFileSync(process.argv[2], 'utf8');
const SCRIPT_SRC = 'https://app.example/plugins/booking/assets/js/embed.js';

function env(iframeSrc, { dataAttr = true, top = 0 } = {}) {
  const listeners = {}, posts = [];
  const iframeWin = { postMessage: (m, o) => posts.push([m, o]) };
  const iframe = {
    contentWindow: iframeWin, style: {}, attrs: {}, scrolled: null,
    getAttribute(n) { return n === 'src' ? iframeSrc : (n in this.attrs ? this.attrs[n] : null); },
    hasAttribute(n) { return n === 'data-kohevo-booking' && dataAttr; },
    setAttribute(n, v) { this.attrs[n] = v; },
    getBoundingClientRect() { return { top: iframe.__top }; },
    scrollIntoView(o) { iframe.scrolled = o; },
  };
  iframe.__top = top;
  const window = { addEventListener: (t, f) => { listeners[t] = f; }, innerHeight: 800, matchMedia: () => ({ matches: false }) };
  const document = { currentScript: { src: SCRIPT_SRC }, getElementsByTagName: () => [iframe] };
  vm.runInNewContext(src, { window, document, location: { href: 'https://host.example/page' }, URL, Math, Number, isFinite });
  const send = (data, o = {}) => listeners.message({ data, source: 'source' in o ? o.source : iframeWin, origin: 'origin' in o ? o.origin : 'https://app.example' });
  return { iframe, iframeWin, posts, send, window };
}

const r = {};
{ const e = env('https://app.example/book?embed=1');
  e.send({ type: 'kohevo-booking-height', height: 512 });
  r.appliesHeight = e.iframe.style.height === '512px'; r.dropsMinHeight = e.iframe.style.minHeight === '0'; r.noScrollAttr = e.iframe.attrs.scrolling === 'no'; }
{ const e = env('https://app.example/book?embed=1');
  e.send({ type: 'cb-booking-height', height: 300 }); r.acceptsLegacyMessageName = e.iframe.style.height === '300px'; }
{ const e = env('https://app.example/book?embed=1');
  e.send({ type: 'kohevo-booking-height', height: 400 }, { origin: 'https://evil.example' });
  r.ignoresWrongOrigin = e.iframe.style.height === undefined; }
{ const e = env('https://app.example/book?embed=1');
  e.send({ type: 'kohevo-booking-height', height: 400 }, { source: { other: true } });
  r.ignoresOtherWindow = e.iframe.style.height === undefined; }
{ const e = env('https://app.example/book?embed=1'); const bad = [10, 49, 20001, 1e9, NaN, Infinity, -5, '<img onerror=x>', null, {}, [], undefined];
  bad.forEach(h => e.send({ type: 'kohevo-booking-height', height: h }));
  r.ignoresBadHeights = e.iframe.style.height === undefined; }
{ const e = env('https://app.example/book?embed=1');
  e.send('kohevo-booking-height'); e.send(null); e.send({ type: 'something-else', height: 400 });
  r.ignoresOtherMessages = e.iframe.style.height === undefined; }
{ const e = env('https://app.example/book?embed=1', { top: -200 });
  e.send({ type: 'kohevo-booking-height', height: 400, first: true });
  r.handshakeSentToIframeOrigin = e.posts.length === 1 && e.posts[0][0].type === 'kohevo-booking-host' && e.posts[0][1] === 'https://app.example';
  r.noScrollOnVeryFirstLoad = e.iframe.scrolled === null;
  e.send({ type: 'kohevo-booking-height', height: 500, first: true });        // a second page load in the same frame = a new step
  r.scrollsBackWhenTopIsOutOfView = !!e.iframe.scrolled && e.iframe.scrolled.block === 'start'; }
{ const e = env('https://app.example/book?embed=1', { top: 40 });
  e.send({ type: 'kohevo-booking-height', height: 400, first: true }); e.send({ type: 'kohevo-booking-height', height: 500, first: true });
  r.doesNotJumpWhenAlreadyInView = e.iframe.scrolled === null; }
{ const e = env('https://app.example/book/manage?token=1', { dataAttr: false });
  e.send({ type: 'kohevo-booking-height', height: 350 }); r.detectsLegacyIframeWithoutAttribute = e.iframe.style.height === '350px'; }
{ const e = env('https://elsewhere.example/page', { dataAttr: false });
  e.send({ type: 'kohevo-booking-height', height: 350 }, { origin: 'https://elsewhere.example' }); r.ignoresUnrelatedIframes = e.iframe.style.height === undefined; }
{ const e = env('https://app.example/book?embed=1'); let again = true;
  try { vm.runInNewContext(src, { window: { __kohevoBookingEmbed: true, addEventListener() { again = false; } }, document: { currentScript: { src: SCRIPT_SRC } }, location: { href: 'https://host.example/' }, URL, Math, Number, isFinite }); } catch (x) {}
  r.loadingTwiceRegistersOnce = again === true; }
console.log(JSON.stringify(r));
