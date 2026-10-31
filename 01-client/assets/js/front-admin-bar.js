/*!
 * Kohevo — the admin bar over public pages.
 *
 * Loaded only when the hint cookie is present (studio-runtime.js on Studio pages,
 * FrontAdminBar::scriptTag() elsewhere), so a visitor never downloads it. The cookie
 * proves nothing: the bar itself comes from admin/front-bar.php, which answers from
 * the real session. It lives in a shadow root, so the site's CSS and this file's stay
 * apart, except for the page offset below.
 */
(function () {
  'use strict';

  var doc = document;
  var root = doc.documentElement;
  var script = doc.currentScript;
  var body = doc.body;

  // Never inside a frame (form and booking embeds, the builder), in Preview, or on an embed address.
  if (!script || !body || window.top !== window.self) return;
  if (/[?&]embed=1(&|$)/.test(location.search) || /(^|\s)sb-body--(preview|editor)(\s|$)/.test(body.className)) return;
  if (doc.getElementById('kohevo-admin-bar')) return;

  var endpoint = script.src.replace(/assets\/js\/.*$/, 'admin/front-bar.php');
  var ON = 'kohevo-has-bar';

  // The page moves down by the bar. Sticky Studio sections keep their own offset, and a device that turned sticky
  // off keeps its `top:auto`. Modals and off-canvas panels sit above the bar (z-index 9999 and up).
  var PAGE_CSS = 'html.' + ON + '{--kohevo-bar-h:36px;margin-top:var(--kohevo-bar-h)!important;position:relative;scroll-padding-top:var(--kohevo-bar-h)}'
    + '@media (max-width:782px){html.' + ON + '{--kohevo-bar-h:46px}}'
    + '@media print{html.' + ON + '{margin-top:0!important}#kohevo-admin-bar{display:none}}';
  [['desktop', '(min-width:1024px)'], ['tablet', '(min-width:768px) and (max-width:1023.98px)'], ['mobile', '(max-width:767.98px)']].forEach(function (d) {
    PAGE_CSS += '@media ' + d[1] + '{html.' + ON + ' .sb-sticky--top:not(.sb-sticky-off-' + d[0] + '){top:calc(var(--sb-sticky-offset,0px) + var(--kohevo-bar-h))}}';
  });

  var BAR_CSS = ':host{position:fixed;top:0;left:0;right:0;z-index:9000;height:var(--kohevo-bar-h,36px);display:block}'
    + '*{box-sizing:border-box}'
    + '.bar{display:flex;align-items:stretch;gap:2px;height:100%;padding:0 8px;background:#15171c;color:#eceef2;font:400 13px/1 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased}'
    + 'ul{list-style:none;margin:0;padding:0}'
    + '.always,.links{display:flex;align-items:stretch;min-width:0}'
    + '.links{flex:1}'
    + 'a,button{display:flex;align-items:center;gap:6px;height:100%;padding:0 10px;border:0;background:none;color:inherit;font:inherit;text-decoration:none;white-space:nowrap;cursor:pointer}'
    + 'a:hover,button:hover,[aria-expanded="true"]{background:#2a2e37;color:#fff}'
    + 'a:focus-visible,button:focus-visible{outline:2px solid #fff;outline-offset:-2px}'
    + '.brand img{display:block;height:18px;width:auto}'
    + '.site{font-weight:600;max-width:200px;overflow:hidden;text-overflow:ellipsis;display:block;line-height:var(--kohevo-bar-h,36px)}'
    + '.primary{color:#fff;font-weight:600}'
    + '.count{min-width:18px;padding:3px 5px;border-radius:9px;background:#e5484d;color:#fff;font-size:11px;font-weight:600;text-align:center}'
    + '.menu{position:relative}'
    + '.menu>ul{position:absolute;top:100%;left:0;min-width:180px;padding:4px 0;background:#23262e;box-shadow:0 8px 20px rgba(0,0,0,.35);border-radius:0 0 6px 6px}'
    + '.menu>ul[hidden]{display:none}'
    + '.menu>ul a{height:34px;padding:0 14px}'
    + '.edit{display:flex}.edit>button{padding:0 6px}'
    + '.edit>ul{min-width:280px;max-height:calc(100vh - var(--kohevo-bar-h,36px));overflow-y:auto}'
    + '.row{justify-content:space-between;gap:16px}.title{overflow:hidden;text-overflow:ellipsis;max-width:220px;padding:2px 0}'
    + '.tag{flex:none;padding:3px 6px;border-radius:3px;background:#3a3f4b;color:#d5d8df;font-size:11px}'
    + '.sep{height:1px;margin:4px 0;background:#3a3f4b}'
    + '.user{margin-left:auto}'
    + '.user>ul{left:auto;right:0}'
    + '.avatar{display:flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#4b5160;color:#fff;font-size:11px;font-weight:600}'
    + '.name{max-width:160px;overflow:hidden;text-overflow:ellipsis;padding:2px 0}'
    + '.caret{opacity:.7}'
    + '.toggle{display:none}'
    + '.sr{position:absolute;width:1px;height:1px;margin:-1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap}'
    + '@media (max-width:782px){'
    +   '.bar{padding:0 4px;font-size:14px}a,button{padding:0 12px}.site,.name{display:none}.toggle{display:flex;margin-left:auto}.user{margin-left:0}'
    +   '.links{display:none;position:absolute;top:100%;left:0;right:0;flex-direction:column;max-height:calc(100dvh - var(--kohevo-bar-h,46px));overflow-y:auto;padding:4px 0;background:#23262e;box-shadow:0 8px 20px rgba(0,0,0,.35)}'
    +   '.links.open{display:flex}.links a,.links button{width:100%;height:44px}'
    +   '.links .menu>ul{position:static;box-shadow:none;padding:0 0 4px 12px;background:none}.links .menu>ul a{height:44px}'
    +   '.user>ul a{height:44px}'
    +   '.edit{position:static}.edit>ul{left:0;right:0;min-width:0;max-height:calc(100dvh - var(--kohevo-bar-h,46px));border-radius:0}.edit>ul a{height:44px}.title{max-width:none}'
    + '}'
    + '@media (max-width:360px){.brand img{height:15px}}';

  function barHeight() {
    return parseFloat(getComputedStyle(root).getPropertyValue('--kohevo-bar-h')) || 0;
  }

  var host = null;
  var style = doc.createElement('style');
  style.textContent = PAGE_CSS;

  function reserve() {
    doc.head.appendChild(style);
    root.classList.add(ON);
  }

  function remove() {
    root.classList.remove(ON);
    if (style.parentNode) style.parentNode.removeChild(style);
    if (host) host.remove();
    host = null;
  }

  /* Headers the stylesheet cannot know about (custom CSS, plugins): a short fixed or sticky strip at the very top
   * moves below the bar. Anything tall is an overlay or a side panel and stays put. */
  function pushDownHeaders() {
    var bar = barHeight();
    var tall = window.innerHeight * 0.6;
    Array.prototype.forEach.call(body.querySelectorAll('*'), function (el) {
      if (el === host || el.classList.contains('sb-sticky')) return;
      var cs = getComputedStyle(el);
      if (cs.position !== 'fixed' && cs.position !== 'sticky') return;
      var top = parseFloat(cs.top);
      if (isNaN(top) || top >= bar) return;
      var height = el.getBoundingClientRect().height;
      if (!height || height > tall) return;
      el.style.setProperty('top', 'calc(' + top + 'px + var(--kohevo-bar-h))', 'important');
    });
  }

  function closeMenus(except) {
    Array.prototype.forEach.call(host.shadowRoot.querySelectorAll('[data-kb-menu][aria-expanded="true"]'), function (btn) {
      if (btn === except) return;
      btn.setAttribute('aria-expanded', 'false');
      btn.nextElementSibling.hidden = true;
    });
  }

  function setMenu(btn, open) {
    closeMenus(btn);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.nextElementSibling.hidden = !open;
  }

  function setLinks(toggle, links, open) {
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    links.classList.toggle('open', open);
  }

  function wire(shadow) {
    var toggle = shadow.querySelector('[data-kb-toggle]');
    var links = shadow.getElementById('kb-links');

    toggle.addEventListener('click', function () {
      setLinks(toggle, links, toggle.getAttribute('aria-expanded') !== 'true');
    });

    Array.prototype.forEach.call(shadow.querySelectorAll('[data-kb-menu]'), function (btn) {
      btn.addEventListener('click', function () {
        setMenu(btn, btn.getAttribute('aria-expanded') !== 'true');
      });
    });

    shadow.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        var open = shadow.querySelector('[data-kb-menu][aria-expanded="true"]');
        if (open) { closeMenus(); open.focus(); }
        else if (toggle.getAttribute('aria-expanded') === 'true') { setLinks(toggle, links, false); toggle.focus(); }
        return;
      }
      var menu = e.target.closest('.menu');
      if (!menu || (e.key !== 'ArrowDown' && e.key !== 'ArrowUp')) return;
      setMenu(menu.querySelector('[data-kb-menu]'), true);
      var items = Array.prototype.slice.call(menu.querySelectorAll(':scope > ul a'));
      var at = items.indexOf(e.target);
      var next = e.key === 'ArrowDown' ? at + 1 : (at < 0 ? items.length : at) - 1;
      items[(next + items.length) % items.length].focus();
      e.preventDefault();
    });

    // Tabbing out of a menu closes it, so nothing stays open behind the focus.
    shadow.addEventListener('focusout', function (e) {
      var menu = e.target.closest('.menu');
      if (menu && e.relatedTarget && !menu.contains(e.relatedTarget)) closeMenus();
    });

    doc.addEventListener('click', function (e) {
      if (!host || e.composedPath().indexOf(host) !== -1) return;
      closeMenus();
      setLinks(toggle, links, false);
    });
  }

  function mount(html) {
    host = doc.createElement('div');
    host.id = 'kohevo-admin-bar';
    var shadow = host.attachShadow({ mode: 'open' });
    shadow.innerHTML = '<style>' + BAR_CSS + '</style>' + html;
    body.insertBefore(host, body.firstChild);
    wire(shadow);
    pushDownHeaders();
    window.addEventListener('load', pushDownHeaders, { once: true });
  }

  function load() {
    return fetch(endpoint + '?path=' + encodeURIComponent(location.pathname), {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    }).then(function (res) {
      return res.ok ? res.json() : { html: '' };
    }).catch(function () {
      return { html: '' };
    });
  }

  reserve();
  load().then(function (data) {
    if (data.html) mount(data.html);
    else remove();
  });

  // Back after logging out: the page comes out of the browser's memory with the bar still on it.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted || !host) return;
    load().then(function (data) {
      if (!data.html) remove();
    });
  });
})();
