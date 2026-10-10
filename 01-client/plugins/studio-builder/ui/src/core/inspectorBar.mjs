// What the Inspector's Reset / Discard / Apply bar does to one block, as plain functions over the document.
//
//   Reset    the block's look goes back to inherited: style, per-device overrides and states are cleared (content is kept).
//   Discard  the block goes back to how it was when it was selected: every editable part that differs is restored.
//   Apply    saves now instead of waiting for the autosave (the editor does that; nothing here).
//
// Both return operations, so they ride the normal save and are one undo step (queued together they leave in one batch).

import * as ops from './operations.mjs';
import { asObject } from './doc.mjs';

/** The parts of a block an author edits in the Inspector, each with the operation that sets it. */
const PARTS = [
  ['props', ops.updateBlockProps, {}],
  ['style', ops.updateBlockStyle, {}],
  ['responsive', ops.updateBlockResponsive, {}],
  ['style_states', ops.updateBlockStyleStates, {}],
  ['classNames', ops.updateBlockClassNames, []],
  ['attributes', ops.updateBlockAttributes, {}],
  ['visibility', ops.updateBlockVisibility, {}],
];

const same = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);
const empty = (v) => v === undefined || v === null || (Array.isArray(v) ? v.length === 0 : Object.keys(asObject(v)).length === 0);

/** A copy of what the Inspector can change on `block`, to compare against later. */
export function editableState(block) {
  const out = {};
  for (const [key] of PARTS) out[key] = block && block[key] !== undefined ? JSON.parse(JSON.stringify(block[key])) : undefined;
  return out;
}

/** True when the block carries any style, device override or state. */
export function hasStyle(block) {
  return !empty(block && block.style) || !empty(block && block.responsive) || !empty(block && block.style_states);
}

/** Operations that clear the block's look. */
export function resetOps(block) {
  const out = [];
  if (!empty(block.style)) out.push(ops.updateBlockStyle(block.id, {}));
  if (!empty(block.responsive)) out.push(ops.updateBlockResponsive(block.id, {}));
  if (!empty(block.style_states)) out.push(ops.updateBlockStyleStates(block.id, {}));
  return out;
}

/** True when the block differs from `baseline` (an `editableState`). */
export function hasChanged(block, baseline) {
  if (!block || !baseline) return false;
  return PARTS.some(([key]) => !same(block[key], baseline[key]) && !(empty(block[key]) && empty(baseline[key])));
}

/** Operations that put every changed part back to `baseline`. */
export function discardOps(block, baseline) {
  const out = [];
  for (const [key, make, blank] of PARTS) {
    if (same(block[key], baseline[key]) || (empty(block[key]) && empty(baseline[key]))) continue;
    out.push(make(block.id, baseline[key] === undefined ? blank : baseline[key]));
  }
  return out;
}
