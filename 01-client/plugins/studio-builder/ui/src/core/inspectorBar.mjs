// What the Inspector's Reset / Discard / Apply bar does to one block, as plain functions over the document.
//
//   Reset    the node's look goes back to inherited (content is kept): style, per-device overrides and states. A
//            Container keeps its box (Boxed or Full width, and how wide), which is structure more than look.
//   Discard  the node goes back to how it was when it was selected: every editable part that differs is restored.
//   Apply    saves now instead of waiting for the autosave (the editor does that; nothing here).
//
// Both return operations, so they ride the normal save and are one undo step (queued together they leave in one batch).

import * as ops from './operations.mjs';
import { CONTAINER_TYPE, asObject } from './doc.mjs';

/** The parts of a node an author edits in the Inspector, each with the operation that sets it. */
const PARTS = [
  ['props', ops.updateBlockProps, {}],
  ['style', ops.updateBlockStyle, {}],
  ['responsive', ops.updateBlockResponsive, {}],
  ['style_states', ops.updateBlockStyleStates, {}],
  ['style_parts', ops.updateBlockStyleParts, {}],
  ['classNames', ops.updateBlockClassNames, []],
  ['attributes', ops.updateBlockAttributes, {}],
  ['visibility', ops.updateBlockVisibility, {}],
  ['animation', ops.updateBlockAnimation, {}],
  ['interactions', ops.updateBlockInteractions, {}],
];

/** The Container's box: what a Reset leaves in place. */
const BOX_KEYS = ['content_width', 'content_size', 'content_max', 'gutter'];

/** The part of the node's style that a Reset keeps. */
function keptStyle(node) {
  if (asObject(node).type !== CONTAINER_TYPE) return {};
  const layout = asObject(asObject(node.style).layout);
  const box = Object.fromEntries(BOX_KEYS.filter((k) => layout[k] !== undefined).map((k) => [k, layout[k]]));
  return Object.keys(box).length ? { layout: box } : {};
}

const same = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);
const empty = (v) => v === undefined || v === null || (Array.isArray(v) ? v.length === 0 : Object.keys(asObject(v)).length === 0);

/** A copy of what the Inspector can change on `node`, to compare against later. */
export function editableState(node) {
  const out = {};
  for (const [key] of PARTS) out[key] = node && node[key] !== undefined ? JSON.parse(JSON.stringify(node[key])) : undefined;
  return out;
}

/** True when the node carries any look the bar's Reset would clear. */
export function hasStyle(node) {
  return !empty(node && node.style) && !same(node.style, keptStyle(node)) || !empty(node && node.responsive) || !empty(node && node.style_states) || !empty(node && node.style_parts);
}

/** Operations that clear the node's look. */
export function resetOps(node) {
  const out = [];
  if (!empty(node.style) && !same(node.style, keptStyle(node))) out.push(ops.updateBlockStyle(node.id, keptStyle(node)));
  if (!empty(node.responsive)) out.push(ops.updateBlockResponsive(node.id, {}));
  if (!empty(node.style_states)) out.push(ops.updateBlockStyleStates(node.id, {}));
  if (!empty(node.style_parts)) out.push(ops.updateBlockStyleParts(node.id, {}));
  return out;
}

/** True when the node differs from `baseline` (an `editableState`). */
export function hasChanged(node, baseline) {
  if (!node || !baseline) return false;
  return PARTS.some(([key]) => !same(node[key], baseline[key]) && !(empty(node[key]) && empty(baseline[key])));
}

/** Operations that put every changed part back to `baseline`. */
export function discardOps(node, baseline) {
  const out = [];
  for (const [key, make, blank] of PARTS) {
    if (same(node[key], baseline[key]) || (empty(node[key]) && empty(baseline[key]))) continue;
    out.push(make(node.id, baseline[key] === undefined ? blank : baseline[key]));
  }
  return out;
}
