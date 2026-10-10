// Kohevo Studio builder — canvas (iframe) bridge.
//
// The canvas document is the server's Editor-mode render (renderForEditor):
// every section/block carries `data-sb-node` + `data-sb-type`. The frame is
// same-origin and sandboxed WITHOUT scripts, so nothing inside it runs; the
// builder (parent) attaches its own listeners to the frame document to
// resolve clicks/hover to node ids and to paint selection outlines. The
// canvas HTML is never read back as content — only node ids are.

import { asList } from './doc.mjs';
import { resolveInlineTarget } from './inlineText.mjs';
export const NODE_ATTR = 'data-sb-node';
export const TYPE_ATTR = 'data-sb-type';
const STYLE_ID = 'sbx-canvas-overlay';
const BAR_CLASS = 'sbx-canvas-action-bar';
const BUBBLE_CLASS = 'sbx-text-bubble';

const OVERLAY_CSS = `
/* An empty layout block would otherwise collapse to a hairline and be impossible to see or pick. */
.sb-grid:empty, .sb-flex:empty, .sb-stack:empty, .sb-card:empty {
  min-height: 56px;
  outline: 1px dashed rgba(139, 92, 246, 0.45);
  outline-offset: -1px;
}
[${NODE_ATTR}] { cursor: default; transition: outline 0.08s ease; }
[${NODE_ATTR}].sbx-hover:not(.sbx-selected) {
  outline: 1.5px dashed rgba(139, 92, 246, 0.7) !important;
  outline-offset: -1px;
  cursor: pointer;
}
[${NODE_ATTR}].sbx-selected {
  position: relative !important;
  outline: 2px solid #8b5cf6 !important;
  outline-offset: -1px;
  box-shadow: 0 0 0 1px rgba(139, 92, 246, 0.4), 0 0 16px rgba(139, 92, 246, 0.3) !important;
}
[${NODE_ATTR}].sbx-selected::before,
[${NODE_ATTR}].sbx-selected::after {
  content: '';
  position: absolute;
  width: 6px;
  height: 6px;
  background: #ffffff;
  border: 1.5px solid #8b5cf6;
  border-radius: 1px;
  z-index: 99998;
  pointer-events: none;
}
[${NODE_ATTR}].sbx-selected::before { top: -3px; left: -3px; }
[${NODE_ATTR}].sbx-selected::after { bottom: -3px; right: -3px; }

[${NODE_ATTR}].sbx-drop-before { box-shadow: inset 0 3px 0 #8b5cf6 !important; }
[${NODE_ATTR}].sbx-drop-after { box-shadow: inset 0 -3px 0 #8b5cf6 !important; }
[${NODE_ATTR}].sbx-drop-inside {
  outline: 2px dashed #8b5cf6 !important;
  outline-offset: -2px;
  background: rgba(139, 92, 246, 0.08) !important;
}
a, button { cursor: default; }

/* Inline text editing */
.sbx-inline-editing {
  outline: 2px solid #38bdf8 !important;
  outline-offset: 2px;
  background: rgba(56, 189, 248, 0.08) !important;
  border-radius: 2px;
  cursor: text !important;
  user-select: text !important;
}

.${BUBBLE_CLASS} {
  position: absolute;
  top: -34px;
  left: 0;
  height: 26px;
  background: #18181b;
  border: 1px solid #3f3f46;
  border-radius: 5px;
  display: inline-flex;
  align-items: center;
  gap: 2px;
  padding: 0 4px;
  z-index: 100000;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.6);
  user-select: none;
}

.${BUBBLE_CLASS}__btn {
  height: 20px;
  min-width: 20px;
  padding: 0 4px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: transparent;
  border: none;
  border-radius: 3px;
  color: #e4e4e7;
  font-size: 11px;
  font-weight: 700;
  cursor: pointer;
}

.${BUBBLE_CLASS}__btn:hover {
  background: #27272a;
  color: #ffffff;
}

.${BUBBLE_CLASS}__btn--done {
  color: #4ade80;
  font-weight: 600;
}
`;

/** Every node id from the target outward, nearest first (a click on content the editor does not own falls back to its owner). */
export function nodeIdChain(target) {
  const ids = [];
  let el = nodeElementFrom(target);
  while (el) {
    ids.push(el.getAttribute(NODE_ATTR));
    el = nodeElementFrom(el.parentElement);
  }
  return ids;
}

/** Nearest element (self or ancestor) carrying a node id. */
export function nodeElementFrom(target) {
  let el = target;
  while (el && el.nodeType === 1) {
    if (el.classList && (typeof el.classList.contains === 'function' ? el.classList.contains(BAR_CLASS) : el.classList.has?.(BAR_CLASS))) return null;
    if (el.classList && (typeof el.classList.contains === 'function' ? el.classList.contains(BUBBLE_CLASS) : el.classList.has?.(BUBBLE_CLASS))) return null;
    if (el.getAttribute && el.getAttribute(NODE_ATTR)) return el;
    el = el.parentElement;
  }
  return null;
}

/**
 * Wire a loaded canvas document. Returns a detach function.
 * @param {Document} doc
 * @param {{onSelect: Function, onHover?: Function, onDrop?: Function, onAction?: Function, onInlineText?: Function, isLocked?: Function, onContextMenu?: Function, onKeyDown?: Function}} handlers
 */
export function attachCanvas(doc, handlers) {
  const { onSelect, onHover, onDrop, onAction, onInlineText, isLocked, inlineSpecs, onContextMenu, onKeyDown } = handlers || {};
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

  let activeEditingEl = null;
  let activeOriginalText = '';
  let activeSpec = null;

  function finishInlineEdit(commit = true) {
    if (!activeEditingEl) return;
    const el = activeEditingEl;
    activeEditingEl = null;

    if (typeof el.removeAttribute === 'function') el.removeAttribute('contenteditable');
    if (el.classList) {
      if (typeof el.classList.remove === 'function') el.classList.remove('sbx-inline-editing');
      else if (typeof el.classList.delete === 'function') el.classList.delete('sbx-inline-editing');
    }
    if (el._sbxKeyHandler) {
      el.removeEventListener('keydown', el._sbxKeyHandler);
      delete el._sbxKeyHandler;
    }
    const bubble = doc.querySelector(`.${BUBBLE_CLASS}`);
    if (bubble && typeof bubble.remove === 'function') bubble.remove();

    const nodeEl = nodeElementFrom(el);
    const nodeId = nodeEl ? nodeEl.getAttribute(NODE_ATTR) : null;
    const spec = activeSpec;
    activeSpec = null;
    const raw = spec && spec.multiline && typeof el.innerText === 'string' ? el.innerText : (el.textContent || '');
    const newText = raw.trim();

    if (commit && nodeId && spec && newText !== activeOriginalText.trim() && onInlineText) {
      onInlineText(nodeId, newText, spec.prop);
    }
  }

  /** Start editing the element a block declares for `target` (nothing happens for a block that declares none). */
  function startDeclaredEdit(nodeEl, nodeId, target = null) {
    const hit = nodeId && inlineSpecs ? resolveInlineTarget(nodeEl, asList(inlineSpecs(nodeId)), target) : null;
    if (hit) startInlineEdit(hit.el, nodeEl, nodeId, hit.spec);
  }

  function startInlineEdit(textEl, nodeEl, nodeId, spec) {
    if (!textEl || !spec || typeof textEl.setAttribute !== 'function') return;
    if (isLocked && nodeId && isLocked(nodeId)) return; // layer lock: no inline editing
    if (activeEditingEl && activeEditingEl !== textEl) {
      finishInlineEdit(true);
    }
    activeEditingEl = textEl;
    activeSpec = spec;
    activeOriginalText = textEl.textContent || '';

    textEl.setAttribute('contenteditable', 'true');
    textEl.setAttribute('spellcheck', 'false');
    if (textEl.classList) {
      if (typeof textEl.classList.add === 'function') textEl.classList.add('sbx-inline-editing');
    }

    try {
      textEl.focus();
      const range = doc.createRange();
      range.selectNodeContents(textEl);
      range.collapse(false);
      const sel = doc.defaultView ? doc.defaultView.getSelection() : null;
      if (sel) {
        sel.removeAllRanges();
        sel.addRange(range);
      }
    } catch (_) {}

    // Inline formatting bubble
    const existingBubble = doc.querySelector(`.${BUBBLE_CLASS}`);
    if (existingBubble && typeof existingBubble.remove === 'function') existingBubble.remove();

    const bubble = doc.createElement('div');
    bubble.className = BUBBLE_CLASS;
    bubble.setAttribute('contenteditable', 'false');
    bubble.innerHTML = `
      <button type="button" class="${BUBBLE_CLASS}__btn ${BUBBLE_CLASS}__btn--done" data-fmt="done" title="Done">✓ Done</button>
    `;

    bubble.addEventListener('click', (ev) => {
      ev.preventDefault();
      ev.stopPropagation();
      const btn = ev.target && ev.target.closest && ev.target.closest(`.${BUBBLE_CLASS}__btn`);
      if (!btn) return;
      const fmt = btn.getAttribute('data-fmt');
      if (fmt === 'done') {
        finishInlineEdit(true);
      }
    });

    if (typeof nodeEl.appendChild === 'function') {
      nodeEl.appendChild(bubble);
    }

    const onKey = (ev) => {
      if (ev.key === 'Escape') {
        ev.preventDefault();
        ev.stopPropagation();
        textEl.textContent = activeOriginalText;
        finishInlineEdit(false);
      } else if (ev.key === 'Enter') {
        const t = (textEl.tagName || '').toLowerCase();
        if (['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'button', 'a'].includes(t) || !ev.shiftKey) {
          ev.preventDefault();
          ev.stopPropagation();
          finishInlineEdit(true);
        }
      }
    };

    textEl.addEventListener('keydown', onKey);
    textEl._sbxKeyHandler = onKey;
  }

  // Expose on doc.defaultView so parent can start inline editing via action toolbar
  if (doc.defaultView) {
    doc.defaultView.sbxStartInlineEdit = (nodeEl, nodeId) => {
      startDeclaredEdit(nodeEl, nodeId);
    };
  }

  const click = (e) => {
    // Check if an action button inside the floating bar was clicked
    const actionBtn = e.target && e.target.closest && e.target.closest(`.${BAR_CLASS}__btn`);
    if (actionBtn) {
      e.preventDefault();
      e.stopPropagation();
      const action = actionBtn.getAttribute('data-action');
      const nodeId = actionBtn.getAttribute('data-node-id');
      if (action === 'edit') {
        const nodeEl = nodeElementFrom(actionBtn) || doc.querySelector(`[${NODE_ATTR}="${nodeId}"]`);
        if (nodeEl) {
          startDeclaredEdit(nodeEl, nodeId);
          return;
        }
      }
      if (onAction) onAction(action, nodeId);
      return;
    }

    // If currently editing inline and clicked inside editing element or bubble, don't stop
    if (activeEditingEl && (activeEditingEl.contains(e.target) || (e.target.closest && e.target.closest(`.${BUBBLE_CLASS}`)))) {
      return;
    }
    if (activeEditingEl) {
      finishInlineEdit(true);
    }

    // The canvas is for selection only: links and buttons never navigate.
    e.preventDefault();
    e.stopPropagation();
    const el = nodeElementFrom(e.target);
    if (el) onSelect(el.getAttribute(NODE_ATTR), el.getAttribute(TYPE_ATTR), { shift: !!e.shiftKey, toggle: !!(e.metaKey || e.ctrlKey) }, nodeIdChain(e.target));
  };

  const dblclick = (e) => {
    if (e.target && e.target.closest && e.target.closest(`.${BAR_CLASS}, .${BUBBLE_CLASS}`)) return;
    const nodeEl = nodeElementFrom(e.target);
    if (!nodeEl) return;
    const nodeId = nodeEl.getAttribute(NODE_ATTR);
    startDeclaredEdit(nodeEl, nodeId, e.target);
  };

  // Right-click (and the long press / Ctrl+click that mean the same): the builder draws its own menu for the node.
  // Text being edited keeps the browser's menu (spelling, paste).
  const contextmenu = (e) => {
    if (e.target && e.target.closest && e.target.closest(`.${BAR_CLASS}, .${BUBBLE_CLASS}`)) return;
    if (activeEditingEl && activeEditingEl.contains(e.target)) return;
    const el = nodeElementFrom(e.target);
    if (!el || !onContextMenu) return;
    e.preventDefault();
    e.stopPropagation();
    onContextMenu(el.getAttribute(NODE_ATTR), { x: e.clientX, y: e.clientY }, nodeIdChain(e.target));
  };

  // Keys pressed while the canvas has focus do not reach the builder's window: hand them over, unless text is being edited.
  const keydown = (e) => {
    if (activeEditingEl || !onKeyDown) return;
    onKeyDown(e);
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
  doc.addEventListener('dblclick', dblclick, true);
  doc.addEventListener('contextmenu', contextmenu, true);
  doc.addEventListener('keydown', keydown, true);
  doc.addEventListener('mouseover', over, true);
  doc.addEventListener('dragover', dragover, true);
  doc.addEventListener('dragleave', dragleave, true);
  doc.addEventListener('drop', drop, true);
  doc.addEventListener('submit', block, true);
  doc.addEventListener('auxclick', block, true);
  doc.addEventListener('dragstart', block, true);
  return () => {
    finishInlineEdit(true);
    doc.removeEventListener('click', click, true);
    doc.removeEventListener('dblclick', dblclick, true);
    doc.removeEventListener('contextmenu', contextmenu, true);
    doc.removeEventListener('keydown', keydown, true);
    doc.removeEventListener('mouseover', over, true);
    doc.removeEventListener('dragover', dragover, true);
    doc.removeEventListener('dragleave', dragleave, true);
    doc.removeEventListener('drop', drop, true);
    doc.removeEventListener('submit', block, true);
    doc.removeEventListener('auxclick', block, true);
    doc.removeEventListener('dragstart', block, true);
  };
}

/** Paint the selection outline on the node(s) and bring the primary into view. */
export function markSelected(doc, nodeId, { scroll = true, ids = null } = {}) {
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
  // Several nodes selected: outline them all; the action bar belongs to the single selection only.
  if (Array.isArray(ids) && ids.length > 1) {
    ids.forEach((id) => {
      const picked = doc.querySelector(`[${NODE_ATTR}="${cssEscape(id)}"]`);
      if (picked && picked.classList && typeof picked.classList.add === 'function') picked.classList.add('sbx-selected');
    });
    return;
  }
  if (!nodeId) return;

  const el = doc.querySelector(`[${NODE_ATTR}="${cssEscape(nodeId)}"]`);
  if (!el) return;

  if (el.classList) {
    if (typeof el.classList.add === 'function') el.classList.add('sbx-selected');
  }

  // The name chip and action toolbar are drawn by the parent (components/CanvasOverlay.jsx);
  // nothing but the selection class is written into the frame.
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
