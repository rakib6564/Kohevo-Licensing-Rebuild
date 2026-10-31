// The real markup for a block the author has just inserted, fetched while the save is still on its way.
//
// `core/liveStructure.mjs` puts a placeholder where the node goes; the server renders it (`render_block`,
// read-only, nothing stored) and this file turns that page into the one element to swap in, with the document's own ids and
// only the CSS rules that element's classes need. The server's render after the save then replaces it like everything else.

import { CONTAINER_TYPE, asList } from './doc.mjs';

const ATTR = 'data-sb-node';
const FRAGMENT_SHEET = 'sbx-frag-';

function preorder(node, out = []) {
  out.push(node.id);
  for (const c of asList(node.children)) preorder(c, out);
  return out;
}

/** CSS rules from `cssText` that mention any of `classes` (the rest of the page's CSS is already on the canvas). */
export function rulesFor(cssText, classes, Sheet = globalThis.CSSStyleSheet) {
  if (!cssText || !classes.length || typeof Sheet !== 'function') return '';
  let sheet;
  try { sheet = new Sheet(); sheet.replaceSync(cssText); } catch { return ''; }
  const wanted = classes.map((c) => `.${c}`);
  const keep = [];
  for (const rule of Array.from(sheet.cssRules)) {
    const text = rule.cssText;
    if (wanted.some((w) => text.includes(w))) keep.push(text);
  }
  return keep.join('\n');
}

const UNAVAILABLE = /class="[^"]*\bsb-unavailable\b/;

/**
 * Turn the server's page for one block into an element of the open canvas.
 * @param {Document} page   the parsed `render_block` response page
 * @param {string} html     the same page as text (to tell an "unavailable" notice from a render)
 * @param {object} node     the block as the document has it now (its ids are the ones the element gets)
 * @param {Document} canvasDoc  the open canvas document
 * @returns {{element: Element, css: string} | null}  null when the render is unusable (the placeholder stays)
 */
export function adoptFromPage(page, html, node, canvasDoc) {
  if (!page || !node || !canvasDoc || UNAVAILABLE.test(html || '')) return null;
  const main = page.querySelector('#main'); // <main>, or the header/footer wrapper of a header or footer page
  if (!main) return null;
  const ids = preorder(node);
  // A Container is the page's only content. Any other block is the only child of the fresh Container it needs at the top level.
  const found = Array.from(main.querySelectorAll(`[${ATTR}]`));
  const els = node.type === CONTAINER_TYPE ? found : found.slice(1);
  if (!els.length || els.length !== ids.length) return null;
  const root = els[0];
  els.forEach((e, i) => e.setAttribute(ATTR, ids[i]));

  const element = canvasDoc.importNode(root, true);
  const classes = new Set();
  for (const e of [element, ...Array.from(element.querySelectorAll('[class]'))]) {
    for (const c of Array.from(e.classList)) if (/^sb-x-[0-9a-f]{16}$/.test(c) || /^sb-(bd|bg|fg|font|pad|rad|shd)--[\w-]+$/.test(c)) classes.add(c);
  }
  const cssText = Array.from(page.querySelectorAll('style')).map((st) => st.textContent).join('\n');
  return { element, css: rulesFor(cssText, [...classes]) };
}

/** The same, from the response text. */
export function adoptFragment(html, node, canvasDoc, Parser = globalThis.DOMParser) {
  if (!html || typeof Parser !== 'function') return null;
  return adoptFromPage(new Parser().parseFromString(html, 'text/html'), html, node, canvasDoc);
}

/** Swap a placeholder for the block's real element, and keep the CSS rules it needs until the server's render replaces them. */
export function applyFragment(canvasDoc, placeholder, fragment, key) {
  if (!placeholder || !placeholder.parentNode || !fragment) return false;
  placeholder.parentNode.replaceChild(fragment.element, placeholder);
  if (fragment.css && canvasDoc.head) {
    const id = `${FRAGMENT_SHEET}${String(key).replace(/[^\w-]/g, '')}`;
    // A block shown again (each edit of a server-rendered block) keeps one sheet: the newest render's rules.
    const old = canvasDoc.getElementById ? canvasDoc.getElementById(id) : null;
    if (old) old.remove();
    const style = canvasDoc.createElement('style');
    style.id = id;
    style.setAttribute('data-sbx-owned', '1');
    style.textContent = fragment.css;
    canvasDoc.head.appendChild(style);
  }
  return true;
}

/** The server's render has been merged in: it carries these rules itself. */
export function clearFragments(canvasDoc) {
  if (!canvasDoc || !canvasDoc.querySelectorAll) return;
  for (const s of Array.from(canvasDoc.querySelectorAll(`style[id^="${FRAGMENT_SHEET}"]`))) s.remove();
}
