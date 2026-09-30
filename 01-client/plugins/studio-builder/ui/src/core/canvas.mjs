// Kohevo Studio builder — canvas (iframe) bridge.
//
// The canvas document is the server's Editor-mode render (renderForEditor):
// every section/block carries `data-sb-node` + `data-sb-type`. The frame is
// same-origin and sandboxed WITHOUT scripts, so nothing inside it runs; the
// builder (parent) attaches its own listeners to the frame document to
// resolve clicks/hover to node ids and to paint selection outlines. The
// canvas HTML is never read back as content — only node ids are.

export const NODE_ATTR = 'data-sb-node';
export const TYPE_ATTR = 'data-sb-type';
const STYLE_ID = 'sbx-canvas-overlay';

const OVERLAY_CSS = `
[${NODE_ATTR}]{cursor:default}
[${NODE_ATTR}].sbx-hover{outline:1px dashed #6366f1;outline-offset:-1px}
[${NODE_ATTR}].sbx-selected{outline:2px solid #4f46e5 !important;outline-offset:-2px}
a,button{cursor:default}
`;

/** Nearest element (self or ancestor) carrying a node id. */
export function nodeElementFrom(target) {
  let el = target;
  while (el && el.nodeType === 1) {
    if (el.getAttribute && el.getAttribute(NODE_ATTR)) return el;
    el = el.parentElement;
  }
  return null;
}

/**
 * Wire a loaded canvas document. Returns a detach function.
 * @param {Document} doc
 * @param {{onSelect: Function, onHover?: Function}} handlers
 */
export function attachCanvas(doc, { onSelect, onHover }) {
  if (!doc || !doc.body) return () => {};
  if (!doc.getElementById(STYLE_ID)) {
    const style = doc.createElement('style');
    style.id = STYLE_ID;
    style.textContent = OVERLAY_CSS;
    (doc.head || doc.body).appendChild(style);
  }
  let hovered = null;
  const click = (e) => {
    // The canvas is for selection only: links and buttons never navigate.
    e.preventDefault();
    e.stopPropagation();
    const el = nodeElementFrom(e.target);
    if (el) onSelect(el.getAttribute(NODE_ATTR), el.getAttribute(TYPE_ATTR));
  };
  const over = (e) => {
    const el = nodeElementFrom(e.target);
    if (el === hovered) return;
    if (hovered) hovered.classList.remove('sbx-hover');
    hovered = el;
    if (el) el.classList.add('sbx-hover');
    if (onHover) onHover(el ? el.getAttribute(NODE_ATTR) : null);
  };
  const block = (e) => e.preventDefault();
  doc.addEventListener('click', click, true);
  doc.addEventListener('mouseover', over, true);
  doc.addEventListener('submit', block, true);
  doc.addEventListener('auxclick', block, true);
  doc.addEventListener('dragstart', block, true);
  return () => {
    doc.removeEventListener('click', click, true);
    doc.removeEventListener('mouseover', over, true);
    doc.removeEventListener('submit', block, true);
    doc.removeEventListener('auxclick', block, true);
    doc.removeEventListener('dragstart', block, true);
  };
}

/** Paint the selection outline and bring the node into view. */
export function markSelected(doc, nodeId, { scroll = true } = {}) {
  if (!doc || !doc.querySelectorAll) return;
  doc.querySelectorAll('.sbx-selected').forEach((el) => el.classList.remove('sbx-selected'));
  if (!nodeId) return;
  const el = doc.querySelector(`[${NODE_ATTR}="${cssEscape(nodeId)}"]`);
  if (!el) return;
  el.classList.add('sbx-selected');
  if (scroll && el.scrollIntoView) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function cssEscape(id) {
  return String(id).replace(/[^a-zA-Z0-9_-]/g, (c) => `\\${c}`);
}
