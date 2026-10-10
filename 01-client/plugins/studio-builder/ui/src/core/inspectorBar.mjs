// What the Inspector's Reset / Discard / Apply bar does to one block, as plain functions over the document.
//
//   Reset    the node's look goes back to inherited (content is kept). A block: style, per-device overrides and states.
//            A section: its style (background, padding) and its theme background.
//   Discard  the node goes back to how it was when it was selected: every editable part that differs is restored.
//   Apply    saves now instead of waiting for the autosave (the editor does that; nothing here).
//
// Both return operations, so they ride the normal save and are one undo step (queued together they leave in one batch).

import * as ops from './operations.mjs';
import { asObject } from './doc.mjs';

/** The parts of a node an author edits in the Inspector, each with the operation that sets it. */
const PARTS = {
  block: [
    ['props', ops.updateBlockProps, {}],
    ['style', ops.updateBlockStyle, {}],
    ['responsive', ops.updateBlockResponsive, {}],
    ['style_states', ops.updateBlockStyleStates, {}],
    ['classNames', ops.updateBlockClassNames, []],
    ['attributes', ops.updateBlockAttributes, {}],
    ['visibility', ops.updateBlockVisibility, {}],
  ],
  section: [
    ['layout', ops.updateSectionLayout, {}],
    ['style', ops.updateSectionStyle, {}],
    ['visibility', ops.updateSectionVisibility, {}],
    ['animation', ops.updateSectionAnimation, {}],
    ['interactions', ops.updateSectionInteractions, {}],
  ],
};

const same = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);
const empty = (v) => v === undefined || v === null || (Array.isArray(v) ? v.length === 0 : Object.keys(asObject(v)).length === 0);

/** A copy of what the Inspector can change on `node`, to compare against later. */
export function editableState(node, kind = 'block') {
  const out = {};
  for (const [key] of PARTS[kind]) out[key] = node && node[key] !== undefined ? JSON.parse(JSON.stringify(node[key])) : undefined;
  return out;
}

/** True when the node carries any look the bar's Reset would clear. */
export function hasStyle(node, kind = 'block') {
  if (kind === 'section') return !empty(node && node.style) || !!asObject(node && node.layout).background_token;
  return !empty(node && node.style) || !empty(node && node.responsive) || !empty(node && node.style_states);
}

/** Operations that clear the node's look. */
export function resetOps(node, kind = 'block') {
  const out = [];
  if (kind === 'section') {
    if (!empty(node.style)) out.push(ops.updateSectionStyle(node.id, {}));
    if (asObject(node.layout).background_token) {
      const layout = { ...asObject(node.layout) };
      delete layout.background_token;
      out.push(ops.updateSectionLayout(node.id, layout));
    }
    return out;
  }
  if (!empty(node.style)) out.push(ops.updateBlockStyle(node.id, {}));
  if (!empty(node.responsive)) out.push(ops.updateBlockResponsive(node.id, {}));
  if (!empty(node.style_states)) out.push(ops.updateBlockStyleStates(node.id, {}));
  return out;
}

/** True when the node differs from `baseline` (an `editableState`). */
export function hasChanged(node, baseline, kind = 'block') {
  if (!node || !baseline) return false;
  return PARTS[kind].some(([key]) => !same(node[key], baseline[key]) && !(empty(node[key]) && empty(baseline[key])));
}

/** Operations that put every changed part back to `baseline`. */
export function discardOps(node, baseline, kind = 'block') {
  const out = [];
  for (const [key, make, blank] of PARTS[kind]) {
    if (same(node[key], baseline[key]) || (empty(node[key]) && empty(baseline[key]))) continue;
    out.push(make(node.id, baseline[key] === undefined ? blank : baseline[key]));
  }
  return out;
}
