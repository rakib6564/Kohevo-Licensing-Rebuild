// Moves the canvas to the document's new structure the moment an author inserts, moves, duplicates or deletes a node,
// instead of waiting for the save and the server's render.
//
//   delete           the element goes.
//   move / reorder   the elements are re-seated among their siblings.
//   duplicate/paste  the source element is cloned (the render of identical content is identical), ids rewritten.
//   new block        a placeholder stands in until the server's markup is merged in.
//   id remap         a provisional id the server has replaced is renamed on its element, never rebuilt.
//
// The server's render still follows (the editor morphs it in after the save) and replaces all of this, so nothing here
// is trusted for long. It only acts where it can see the structure is what the document says: a parent whose children
// do not sit together under one wrapper element (tabs, columns with cells) is left for that render, and the result says so.

import { asList } from './doc.mjs';
import { isProvisionalId } from './operations.mjs';

const ATTR = 'data-sb-node';
const ROOT = '\u0000root';
const esc = (id) => String(id).replace(/[^\w-]/g, '');

/** id => {node, parentId, kind}; kids: parentId => ordered ids. */
function indexDoc(doc) {
  const map = new Map();
  const kids = new Map();
  const walkBlocks = (blocks, parentId) => {
    const list = [];
    for (const b of asList(blocks)) {
      if (!b || !b.id) continue;
      map.set(b.id, { node: b, parentId, kind: 'block' });
      list.push(b.id);
      walkBlocks(b.children, b.id);
    }
    kids.set(parentId, list);
  };
  const roots = [];
  for (const s of asList(doc && doc.sections)) {
    if (!s || !s.id) continue;
    map.set(s.id, { node: s, parentId: ROOT, kind: 'section' });
    roots.push(s.id);
    walkBlocks(s.blocks, s.id);
  }
  kids.set(ROOT, roots);
  return { map, kids };
}

/** Everything about a node but its ids, so two nodes that would render alike compare equal. */
function signature(node) {
  const { id, children, blocks, ...rest } = node; // eslint-disable-line no-unused-vars
  return JSON.stringify([rest, asList(blocks !== undefined ? blocks : children).map(signature)]);
}

function preorder(node, out = []) {
  out.push(node.id);
  for (const c of asList(node.blocks !== undefined ? node.blocks : node.children)) preorder(c, out);
  return out;
}

const sameList = (a, b) => a.length === b.length && a.every((v, i) => v === b[i]);

function detach(el) {
  if (el.remove) el.remove();
  else if (el.parentNode) el.parentNode.removeChild(el);
}

/** A copy of an element the author can drop in: no selection or hover state, no editing state. */
function cleanClone(source, ids) {
  const copy = source.cloneNode(true);
  const nodes = [copy, ...Array.from(copy.querySelectorAll ? copy.querySelectorAll(`[${ATTR}]`) : [])];
  if (nodes.length !== ids.length) return null;
  nodes.forEach((n, i) => {
    n.setAttribute(ATTR, ids[i]);
    for (const c of Array.from(n.classList || [])) if (/^sbx-/.test(c)) n.classList.remove(c);
    for (const a of Array.from(n.attributes || [])) if (a.name === 'contenteditable' || a.name.startsWith('data-sbx-')) n.removeAttribute(a.name);
  });
  return copy;
}

function placeholder(canvasDoc, node) {
  const el = canvasDoc.createElement('div');
  el.setAttribute(ATTR, node.id);
  el.setAttribute('data-sb-type', node.type || 'section');
  el.setAttribute('class', 'sb-block sbx-pending');
  el.setAttribute('style', 'min-height:3.5rem;display:flex;align-items:center;justify-content:center;border:1px dashed #8b5cf6;border-radius:6px;color:#8b5cf6;font:500 13px/1 system-ui,sans-serif;opacity:.7');
  el.textContent = String(node.type || 'section').split('.').pop().replace(/_/g, ' ');
  return el;
}

/**
 * Bring the canvas from `prevDoc`'s structure to `nextDoc`'s.
 * @returns {{changed: number, complete: boolean, pending: Element[]}} `complete` is false when part of it was left for the
 *   server's render; `pending` are the placeholders standing in for new blocks (their real markup can be fetched now).
 */
export function syncLiveStructure(canvasDoc, prevDoc, nextDoc) {
  if (!canvasDoc || !canvasDoc.querySelector || !prevDoc || !nextDoc) return { changed: 0, complete: false, pending: [] };
  const P = indexDoc(prevDoc);
  const N = indexDoc(nextDoc);
  const el = (id) => canvasDoc.querySelector(`[${ATTR}="${esc(id)}"]`);
  let changed = 0;
  let complete = true;
  const pending = [];

  const added = new Set([...N.map.keys()].filter((id) => !P.map.has(id)));
  const removed = new Set([...P.map.keys()].filter((id) => !N.map.has(id)));
  if (!added.size && !removed.size) {
    // nothing came or went; a reorder or a move to another parent is still possible
    let order = false;
    for (const [pid, list] of N.kids) if (!sameList(list, P.kids.get(pid) || [])) { order = true; break; }
    if (!order) return { changed: 0, complete: true, pending: [] };
  }
  const addedTop = [...added].filter((id) => !added.has(N.map.get(id).parentId));

  // A provisional id the server has replaced: the same node under a new id. Rename it where it stands.
  const renamedTo = new Map(); // old id => new id
  const handled = new Set();
  for (const id of removed) {
    if (!isProvisionalId(id) || renamedTo.has(id)) continue;
    const entry = P.map.get(id);
    if (removed.has(entry.parentId) && isProvisionalId(entry.parentId)) continue; // a descendant: renamed with its top node
    const sig = signature(entry.node);
    const match = addedTop.find((a) => !handled.has(a) && signature(N.map.get(a).node) === sig);
    if (!match) continue;
    const from = preorder(entry.node);
    const to = preorder(N.map.get(match).node);
    if (from.length !== to.length) continue;
    const els = from.map(el);
    if (els.some((e) => !e)) continue;
    els.forEach((e, i) => { e.setAttribute(ATTR, to[i]); renamedTo.set(from[i], to[i]); });
    handled.add(match);
    changed += 1;
  }

  // Deletions.
  for (const id of removed) {
    if (renamedTo.has(id)) continue;
    const e = el(id);
    if (e) { detach(e); changed += 1; }
  }

  // New nodes: a clone of identical content that is already on the canvas, else a placeholder.
  const fresh = new Map();
  for (const id of addedTop) {
    if (handled.has(id)) continue;
    const node = N.map.get(id).node;
    const sig = signature(node);
    const ids = preorder(node);
    let copy = null;
    for (const [otherId, entry] of N.map) {
      if (added.has(otherId) || entry.node.type !== node.type || signature(entry.node) !== sig) continue;
      const source = el(otherId);
      if (source) { copy = cleanClone(source, ids); if (copy) break; }
    }
    const stand = copy || placeholder(canvasDoc, node);
    if (!copy && N.map.get(id).kind === 'block') pending.push(stand);
    fresh.set(id, stand);
  }

  // Order: every parent that gained or was reordered gets its child elements seated in the document's order.
  const freshEls = new Set(fresh.values());
  for (const [pid, list] of N.kids) {
    if (added.has(pid)) continue; // a new parent brings its children with it (the clone or the placeholder)
    const before = (P.kids.get(pid) || []).map((id) => renamedTo.get(id) || id);
    const kept = before.filter((id) => list.includes(id));
    if (sameList(list, kept)) continue; // nothing arrived and nothing changed place (a deletion alone needs no reseating)
    const elems = list.map((id) => fresh.get(id) || el(id));
    const present = elems.filter(Boolean);
    if (present.length !== list.length) complete = false;
    if (!present.length) continue;

    // The wrapper the children sit in: where the children that stayed are, which must be one element.
    const stayers = list.filter((id) => before.includes(id) && !fresh.has(id)).map(el).filter((e) => e && e.parentNode);
    const containers = new Set(stayers.map((e) => e.parentNode));
    if (containers.size !== 1) { complete = false; continue; }
    const Q = [...containers][0];
    if (pid !== ROOT) {
      const parentEl = el(pid);
      const owner = Q.closest ? Q.closest(`[${ATTR}]`) : null;
      if (!parentEl || owner !== parentEl) { complete = false; continue; }
    }
    const members = new Set(present);
    const marker = canvasDoc.createComment ? canvasDoc.createComment('') : null;
    if (!marker) { complete = false; continue; }
    const firstMember = Array.from(Q.childNodes).find((n) => members.has(n));
    Q.insertBefore(marker, firstMember || null);
    for (const e of present) Q.insertBefore(e, marker);
    detach(marker);
    if (present.some((e) => freshEls.has(e))) changed += 1;
    changed += 1;
  }

  return { changed, complete, pending };
}
