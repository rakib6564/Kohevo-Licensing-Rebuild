// Selection model — framework-free, immutable.
//
//   { primary, ids, hover, focus }
//
//   primary  the node the Inspector and breadcrumb describe (null = nothing)
//   ids      every selected node, in the order they were picked
//   hover    the node under the pointer (canvas or Layers)
//   focus    the node holding keyboard focus in the overlay / Layers
//
// Actions (duplicate, delete, lock, hide, move) operate on `ids`; `actionIds`
// drops any id whose ancestor is also selected, because acting on the ancestor
// already covers it.

import { rangeIds, topLevelIds } from './shellState.mjs';

export const EMPTY_SELECTION = Object.freeze({ primary: null, ids: Object.freeze([]), hover: null, focus: null });

const make = (primary, ids, hover, focus) => Object.freeze({ primary, ids: Object.freeze(ids), hover, focus });

/** Replace the selection with one node (or clear it with a falsy id). Hover and focus are kept. */
export function selectOnly(sel, id) {
  return id ? make(id, [id], sel.hover, sel.focus) : make(null, [], sel.hover, sel.focus);
}

export function clearSelection(sel) {
  return selectOnly(sel, null);
}

/** Ctrl / Cmd click: add the node, or remove it when already selected. */
export function toggleSelected(sel, id) {
  if (!id) return sel;
  if (sel.ids.includes(id)) {
    const ids = sel.ids.filter((x) => x !== id);
    const primary = sel.primary === id ? (ids[ids.length - 1] ?? null) : sel.primary;
    return make(primary, ids, sel.hover, sel.focus);
  }
  return make(id, [...sel.ids, id], sel.hover, sel.focus);
}

/** Shift click: everything from the current primary to `id` in visual order (`rows` = Layers rows). */
export function selectRange(sel, rows, id) {
  if (!id) return sel;
  const ids = rangeIds(rows, sel.primary, id);
  return ids.length ? make(id, ids, sel.hover, sel.focus) : sel;
}

/** The one entry point for a click, canvas or Layers: plain / toggle / shift. */
export function clickSelect(sel, rows, id, { shift = false, toggle = false } = {}) {
  if (shift && sel.primary) return selectRange(sel, rows, id);
  if (toggle) return toggleSelected(sel, id);
  return selectOnly(sel, id);
}

export function setHover(sel, id) {
  const hover = id || null;
  return sel.hover === hover ? sel : make(sel.primary, sel.ids, hover, sel.focus);
}

export function setFocus(sel, id) {
  const focus = id || null;
  return sel.focus === focus ? sel : make(sel.primary, sel.ids, sel.hover, focus);
}

/** Provisional `tmp_` ids become real ids when the server confirms an insert. */
export function remapSelection(sel, map) {
  if (!map || map.size === 0) return sel;
  const m = (id) => (id && map.has(id) ? map.get(id) : id);
  return make(m(sel.primary), sel.ids.map(m), m(sel.hover), m(sel.focus));
}

/**
 * Drop nodes that no longer exist (deleted, undone, rolled back). When the primary
 * vanishes the most recently picked survivor takes over; with none left, nothing is selected.
 */
export function pruneSelection(sel, exists) {
  const keep = (id) => (id && exists(id) ? id : null);
  const ids = sel.ids.filter((id) => exists(id));
  const primary = keep(sel.primary) ?? (ids[ids.length - 1] ?? null);
  const hover = keep(sel.hover);
  const focus = keep(sel.focus);
  if (ids.length === sel.ids.length && primary === sel.primary && hover === sel.hover && focus === sel.focus) return sel;
  return make(primary, ids, hover, focus);
}

/** Nodes a bulk action should touch: selected ids minus those covered by a selected ancestor. */
export function actionIds(sel, rows) {
  return topLevelIds(rows, new Set(sel.ids));
}

export function isSelected(sel, id) {
  return sel.ids.includes(id);
}

export function selectionCount(sel) {
  return sel.ids.length;
}
