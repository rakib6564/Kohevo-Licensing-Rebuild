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
const BAR_CLASS = 'sbx-canvas-action-bar';

const OVERLAY_CSS = `
[${NODE_ATTR}] { cursor: default; transition: outline 0.08s ease; }
[${NODE_ATTR}].sbx-hover:not(.sbx-selected) {
  outline: 1px dashed rgba(74, 222, 128, 0.45) !important;
  outline-offset: -1px;
}
[${NODE_ATTR}].sbx-selected {
  position: relative !important;
  outline: 1px dashed #86efac !important;
  outline-offset: -1px;
}
[${NODE_ATTR}].sbx-drop-before { box-shadow: inset 0 3px 0 #22c55e !important; }
[${NODE_ATTR}].sbx-drop-after { box-shadow: inset 0 -3px 0 #22c55e !important; }
[${NODE_ATTR}].sbx-drop-inside {
  outline: 2px dashed #22c55e !important;
  outline-offset: -2px;
  background: rgba(34, 197, 94, 0.06) !important;
}
a, button { cursor: default; }

.${BAR_CLASS} {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  height: 32px;
  background: #141722;
  border-top: 1px solid rgba(255, 255, 255, 0.12);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 12px;
  z-index: 99999;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 11px;
  letter-spacing: 0.08em;
  color: #cbd5e1;
  user-select: none;
  box-sizing: border-box;
}

.${BAR_CLASS}__left {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.${BAR_CLASS}__grip {
  color: #64748b;
  font-weight: 700;
  letter-spacing: 1.5px;
  cursor: grab;
}

.${BAR_CLASS}__label {
  color: #cbd5e1;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-transform: uppercase;
}

.${BAR_CLASS}__actions {
  display: flex;
  align-items: center;
  gap: 3px;
  flex: none;
}

.${BAR_CLASS}__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 4px;
  color: #94a3b8;
  cursor: pointer;
  padding: 0;
  transition: all 0.1s ease;
}

.${BAR_CLASS}__btn:hover {
  background: rgba(255, 255, 255, 0.1);
  color: #ffffff;
  border-color: rgba(255, 255, 255, 0.15);
}

.${BAR_CLASS}__btn svg {
  width: 13px;
  height: 13px;
  stroke: currentColor;
  stroke-width: 2;
  fill: none;
  stroke-linecap: round;
  stroke-linejoin: round;
}
`;

/** Nearest element (self or ancestor) carrying a node id. */
export function nodeElementFrom(target) {
  let el = target;
  while (el && el.nodeType === 1) {
    if (el.classList && (typeof el.classList.contains === 'function' ? el.classList.contains(BAR_CLASS) : el.classList.has?.(BAR_CLASS))) return null;
    if (el.getAttribute && el.getAttribute(NODE_ATTR)) return el;
    el = el.parentElement;
  }
  return null;
}

/**
 * Wire a loaded canvas document. Returns a detach function.
 * @param {Document} doc
 * @param {{onSelect: Function, onHover?: Function, onDrop?: Function, onAction?: Function}} handlers
 */
export function attachCanvas(doc, handlers) {
  const { onSelect, onHover, onDrop, onAction } = handlers || {};
  if (!doc || !doc.body) return () => {};
  if (!doc.getElementById(STYLE_ID)) {
    const style = doc.createElement('style');
    style.id = STYLE_ID;
    style.textContent = OVERLAY_CSS;
    (doc.head || doc.body).appendChild(style);
  }
  let hovered = null;
  let dropTarget = null;
  let dropPos = null;

  const click = (e) => {
    // Check if an action button inside the floating bar was clicked
    const actionBtn = e.target && e.target.closest && e.target.closest(`.${BAR_CLASS}__btn`);
    if (actionBtn) {
      e.preventDefault();
      e.stopPropagation();
      const action = actionBtn.getAttribute('data-action');
      const nodeId = actionBtn.getAttribute('data-node-id');
      if (onAction) onAction(action, nodeId);
      return;
    }

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

  const dragover = (e) => {
    const el = nodeElementFrom(e.target);
    if (!el) return;
    e.preventDefault();
    const rect = el.getBoundingClientRect();
    const y = (e.clientY - rect.top) / Math.max(1, rect.height);
    const type = el.getAttribute(TYPE_ATTR) || '';
    const isContainer = type.startsWith('layout.') || (el.tagName && el.tagName.toLowerCase() === 'section');
    const pos = isContainer && y > 0.25 && y < 0.75 ? 'inside' : (y < 0.5 ? 'before' : 'after');

    if (dropTarget !== el || dropPos !== pos) {
      if (dropTarget) {
        dropTarget.classList.remove('sbx-drop-before', 'sbx-drop-after', 'sbx-drop-inside');
      }
      dropTarget = el;
      dropPos = pos;
      dropTarget.classList.add(`sbx-drop-${pos}`);
    }
  };

  const dragleave = (e) => {
    if (dropTarget && (!e.relatedTarget || !doc.body.contains(e.relatedTarget))) {
      dropTarget.classList.remove('sbx-drop-before', 'sbx-drop-after', 'sbx-drop-inside');
      dropTarget = null;
      dropPos = null;
    }
  };

  const drop = (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (!dropTarget) return;
    const targetId = dropTarget.getAttribute(NODE_ATTR);
    const targetType = dropTarget.getAttribute(TYPE_ATTR);
    const pos = dropPos;
    dropTarget.classList.remove('sbx-drop-before', 'sbx-drop-after', 'sbx-drop-inside');
    const transfer = e.dataTransfer;
    dropTarget = null;
    dropPos = null;

    if (onDrop) {
      onDrop({
        targetId,
        targetType,
        position: pos,
        dataTransfer: transfer,
      });
    }
  };

  const block = (e) => e.preventDefault();
  doc.addEventListener('click', click, true);
  doc.addEventListener('mouseover', over, true);
  doc.addEventListener('dragover', dragover, true);
  doc.addEventListener('dragleave', dragleave, true);
  doc.addEventListener('drop', drop, true);
  doc.addEventListener('submit', block, true);
  doc.addEventListener('auxclick', block, true);
  doc.addEventListener('dragstart', block, true);
  return () => {
    doc.removeEventListener('click', click, true);
    doc.removeEventListener('mouseover', over, true);
    doc.removeEventListener('dragover', dragover, true);
    doc.removeEventListener('dragleave', dragleave, true);
    doc.removeEventListener('drop', drop, true);
    doc.removeEventListener('submit', block, true);
    doc.removeEventListener('auxclick', block, true);
    doc.removeEventListener('dragstart', block, true);
  };
}

/** Paint the selection outline, show section action bar, and bring the node into view. */
export function markSelected(doc, nodeId, { scroll = true } = {}) {
  if (!doc || !doc.querySelectorAll) return;
  doc.querySelectorAll('.sbx-selected').forEach((el) => {
    if (el.classList) {
      if (typeof el.classList.remove === 'function') el.classList.remove('sbx-selected');
      else if (typeof el.classList.delete === 'function') el.classList.delete('sbx-selected');
    }
  });
  doc.querySelectorAll(`.${BAR_CLASS}`).forEach((b) => {
    if (typeof b.remove === 'function') b.remove();
  });
  if (!nodeId) return;

  const el = doc.querySelector(`[${NODE_ATTR}="${cssEscape(nodeId)}"]`);
  if (!el) return;

  if (el.classList) {
    if (typeof el.classList.add === 'function') el.classList.add('sbx-selected');
  }

  // If selected element is a section or contains blocks, attach the floating action toolbar
  const isSection = (el.tagName && el.tagName.toLowerCase() === 'section') || ((el.getAttribute && el.getAttribute(TYPE_ATTR)) || '').startsWith('layout.section');
  if (isSection && typeof doc.createElement === 'function' && typeof el.appendChild === 'function') {
    const sections = Array.from(doc.querySelectorAll(`section[${NODE_ATTR}], [${NODE_ATTR}][${TYPE_ATTR}="layout.section"]`));
    const idx = sections.indexOf(el);
    const num = idx >= 0 ? String(idx + 1).padStart(2, '0') : '01';

    const heading = el.querySelector('h1, h2, h3, h4, [data-sb-type="core.heading"]');
    const labelText = (heading ? heading.textContent.trim() : '') || el.getAttribute('data-sb-label') || el.getAttribute(NODE_ATTR);
    const cleanLabel = (labelText || 'SECTION').replace(/\s+/g, ' ').slice(0, 36).toUpperCase();

    const bar = doc.createElement('div');
    bar.className = BAR_CLASS;
    bar.setAttribute('contenteditable', 'false');
    bar.innerHTML = `
      <div class="${BAR_CLASS}__left">
        <span class="${BAR_CLASS}__grip">::</span>
        <span class="${BAR_CLASS}__label">${num} / ${escapeHtml(cleanLabel)}</span>
      </div>
      <div class="${BAR_CLASS}__actions">
        <button type="button" class="${BAR_CLASS}__btn" data-action="up" data-node-id="${escapeHtml(nodeId)}" title="Move up">
          <svg viewBox="0 0 24 24"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
        </button>
        <button type="button" class="${BAR_CLASS}__btn" data-action="down" data-node-id="${escapeHtml(nodeId)}" title="Move down">
          <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
        </button>
        <button type="button" class="${BAR_CLASS}__btn" data-action="duplicate" data-node-id="${escapeHtml(nodeId)}" title="Duplicate">
          <svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
        </button>
        <button type="button" class="${BAR_CLASS}__btn" data-action="remove" data-node-id="${escapeHtml(nodeId)}" title="Remove">
          <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        </button>
      </div>
    `;
    el.appendChild(bar);
  }

  if (scroll && el.scrollIntoView) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, (s) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[s]));
}

function cssEscape(id) {
  return String(id).replace(/[^a-zA-Z0-9_-]/g, (c) => `\\${c}`);
}
