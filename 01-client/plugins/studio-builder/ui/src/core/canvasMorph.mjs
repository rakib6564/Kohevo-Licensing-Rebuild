// Kohevo Studio builder — repaint the canvas from the server WITHOUT reloading the frame.
//
// The canvas is the server's Editor-mode render. When an edit changes what only the server can write (scoped style
// rules, a rebuilt element, a moved block), the canvas used to navigate its iframe to a fresh copy: a white flash,
// the scroll position and focus lost, the selection redrawn. Instead the new render is fetched and morphed into the
// frame that is already open: the compiled stylesheet is swapped, elements that are the same are updated in place
// (matched by their `data-sb-node` id), and only what really differs is inserted or removed. The server stays the
// single source of the markup and the CSS; nothing is re-implemented here.
//
// What the editor itself adds to the frame (selection and hover classes, inline-editing state, its own style tags)
// is kept, never "corrected" back to what the server wrote.

const NODE_ATTR = 'data-sb-node';
const EDITOR_CLASS = /^sbx-/;
const KEEP_ATTRS = new Set(['contenteditable', 'spellcheck']);

/** The class list a live element should have: what the server wrote, plus the editor's own `sbx-*` classes. */
export function mergedClass(liveClass, nextClass) {
  const next = String(nextClass || '').split(/\s+/).filter(Boolean);
  const kept = String(liveClass || '').split(/\s+/).filter((c) => EDITOR_CLASS.test(c) && !next.includes(c));
  return [...next, ...kept].join(' ');
}

/** An attribute the editor owns on a live element, which a morph must not remove. */
export const isEditorAttr = (name) => KEEP_ATTRS.has(name) || name.startsWith('data-sbx-');

const keyOf = (el) => (el.nodeType === 1 ? el.getAttribute(NODE_ATTR) : null);
const sameKind = (a, b) => a.nodeType === b.nodeType && (a.nodeType !== 1 || (a.tagName === b.tagName && keyOf(a) === keyOf(b)));

function morphAttributes(live, next) {
  for (const { name, value } of Array.from(next.attributes)) {
    if (name === 'class') {
      const merged = mergedClass(live.getAttribute('class'), value);
      if (live.getAttribute('class') !== merged) live.setAttribute('class', merged);
    } else if (live.getAttribute(name) !== value) {
      live.setAttribute(name, value);
    }
  }
  for (const { name } of Array.from(live.attributes)) {
    if (name === 'class' || next.hasAttribute(name) || isEditorAttr(name)) continue;
    live.removeAttribute(name);
  }
  if (!next.hasAttribute('class') && live.hasAttribute('class')) {
    const kept = mergedClass(live.getAttribute('class'), '');
    if (kept) live.setAttribute('class', kept); else live.removeAttribute('class');
  }
}

function morphNode(live, next) {
  if (live.nodeType === 3 || live.nodeType === 8) {
    if (live.nodeValue !== next.nodeValue) live.nodeValue = next.nodeValue;
    return;
  }
  morphAttributes(live, next);
  // Form controls keep what the visitor typed; the canvas never submits, so the markup's own value wins.
  morphChildren(live, next);
}

/** Morph `live`'s children into `next`'s, matching elements by tag and node id and reusing what is already there. */
export function morphChildren(live, next) {
  const want = Array.from(next.childNodes);
  let cursor = live.firstChild;
  for (const target of want) {
    // an element the editor placed inside the node (the inline-edit bubble) is not the server's: leave it where it is
    while (cursor && cursor.nodeType === 1 && cursor.hasAttribute && cursor.hasAttribute('data-sbx-owned')) cursor = cursor.nextSibling;
    if (cursor && sameKind(cursor, target)) {
      morphNode(cursor, target);
      cursor = cursor.nextSibling;
      continue;
    }
    // the same element further along (a block moved up): bring it here
    const key = keyOf(target);
    let found = null;
    if (key) {
      for (let n = cursor; n; n = n.nextSibling) { if (n.nodeType === 1 && keyOf(n) === key && n.tagName === target.tagName) { found = n; break; } }
    }
    if (found) {
      live.insertBefore(found, cursor);
      morphNode(found, target);
      cursor = found.nextSibling;
      continue;
    }
    const fresh = live.ownerDocument.importNode(target, true);
    live.insertBefore(fresh, cursor);
  }
  while (cursor) {
    const after = cursor.nextSibling;
    if (!(cursor.nodeType === 1 && cursor.hasAttribute && cursor.hasAttribute('data-sbx-owned'))) live.removeChild(cursor);
    cursor = after;
  }
}

/** The head element a server `<style>` / `<link>` corresponds to, or '' for markup the editor owns. */
function headKey(el) {
  if (el.tagName === 'STYLE') {
    if (el.id) return '';
    const tag = el.getAttribute('data-sb');
    return tag ? `style:${tag}` : 'style:css';
  }
  if (el.tagName === 'LINK' && /stylesheet|preconnect|dns-prefetch/i.test(el.getAttribute('rel') || '')) return `link:${el.getAttribute('rel')}:${el.getAttribute('href')}`;
  return '';
}

function morphHead(live, next) {
  const liveByKey = new Map();
  for (const el of Array.from(live.children)) { const k = headKey(el); if (k) liveByKey.set(k, el); }
  const seen = new Set();
  for (const el of Array.from(next.children)) {
    const k = headKey(el);
    if (!k) continue;
    seen.add(k);
    const mine = liveByKey.get(k);
    if (!mine) { live.appendChild(live.ownerDocument.importNode(el, true)); continue; }
    if (el.tagName === 'STYLE' && mine.textContent !== el.textContent) mine.textContent = el.textContent;
  }
  // a server style that is gone (the site's Custom CSS was emptied) goes; links are only ever added
  for (const [k, el] of liveByKey) if (k.startsWith('style:') && !seen.has(k)) el.remove();
}

/**
 * Morph the live canvas document into the freshly fetched render.
 * @param {Document} liveDoc  the iframe's document
 * @param {Document} nextDoc  the parsed server render
 */
export function morphDocument(liveDoc, nextDoc) {
  if (!liveDoc || !liveDoc.body || !nextDoc || !nextDoc.body) throw new Error('canvas morph: no document');
  morphHead(liveDoc.head, nextDoc.head);
  morphAttributes(liveDoc.body, nextDoc.body);
  morphChildren(liveDoc.body, nextDoc.body);
}

/** Is the author typing into the canvas right now (a morph would pull the text out from under the cursor)? */
export const isInlineEditing = (doc) => !!(doc && doc.querySelector && doc.querySelector('.sbx-inline-editing'));
