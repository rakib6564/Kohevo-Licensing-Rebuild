// Layer lock rules — the client mirror of the server's `LayerLock.php`.
//
// A section carries `locked: true`; a block carries `metadata.locked: true`. A
// node is *effectively* locked when it or any ancestor is. While locked, its
// content cannot be edited, moved, renamed or removed and nothing can be
// inserted into it; a node that CONTAINS a locked descendant cannot be removed
// either. Unlocking is accepted only from the outermost lock. Duplicating stays
// allowed. The server enforces the same rules for every caller; this mirror
// exists so the builder can refuse an edit up front (and say why) instead of
// showing it and having the server revert it.

import { asList, asObject } from './doc.mjs';
import { OPS } from './operations.mjs';

/** True when the node itself (not its ancestors) is locked. */
export function isLocked(node) {
  return !!node && (node.locked === true || asObject(node.metadata).locked === true);
}

/** id → { parent, locked } for every section and block. */
export function lockIndex(doc) {
  const index = new Map();
  const walk = (blocks, parent) => {
    for (const b of asList(blocks)) {
      index.set(b.id, { parent, locked: isLocked(b) });
      walk(b.children, b.id);
    }
  };
  for (const s of asList(doc && doc.sections)) {
    index.set(s.id, { parent: null, locked: isLocked(s) });
    walk(s.blocks, s.id);
  }
  return index;
}

export function effectivelyLocked(index, id) {
  for (let cur = id; cur != null && index.has(cur); cur = index.get(cur).parent) {
    if (index.get(cur).locked) return true;
  }
  return false;
}

/** Locked by an ancestor (not by the node itself). */
export function ancestorLocked(index, id) {
  const entry = index.get(id);
  return !!entry && entry.parent != null && effectivelyLocked(index, entry.parent);
}

export function hasLockedDescendant(index, id) {
  for (const [nodeId, entry] of index) {
    if (!entry.locked || nodeId === id) continue;
    for (let cur = entry.parent; cur != null && index.has(cur); cur = index.get(cur).parent) {
      if (cur === id) return true;
    }
  }
  return false;
}

const BLOCK_EDIT = new Set([
  OPS.UPDATE_BLOCK_PROPS, OPS.UPDATE_BLOCK_STYLE, OPS.UPDATE_BLOCK_VISIBILITY, OPS.UPDATE_BLOCK_BINDINGS,
  OPS.UPDATE_BLOCK_RESPONSIVE, OPS.UPDATE_BLOCK_CLASS_NAMES, OPS.UPDATE_BLOCK_ATTRIBUTES,
  OPS.UPDATE_BLOCK_INTERACTIONS, OPS.UPDATE_BLOCK_ANIMATION,
  OPS.UPDATE_BLOCK_STYLE_STATES, OPS.UPDATE_BLOCK_TAG, OPS.RESET_BLOCK_STYLE_PROPERTY,
]);
const SECTION_EDIT = new Set([
  OPS.UPDATE_SECTION_LABEL, OPS.UPDATE_SECTION_LAYOUT, OPS.UPDATE_SECTION_VISIBILITY, OPS.UPDATE_SECTION_STYLE, OPS.UPDATE_SECTION_ANIMATION, OPS.UPDATE_SECTION_INTERACTIONS, OPS.MOVE_SECTION,
]);

/**
 * Why the lock forbids `operation` on `doc`, or null when it is allowed.
 * Unknown ids (e.g. a provisional id) are never "locked".
 */
export function lockViolation(doc, operation) {
  const p = asObject(operation && operation.payload);
  const name = operation && operation.op;
  const touches = BLOCK_EDIT.has(name) || SECTION_EDIT.has(name)
    || [OPS.INSERT_BLOCK, OPS.MOVE_BLOCK, OPS.REMOVE_BLOCK, OPS.REMOVE_SECTION, OPS.UPDATE_BLOCK_META].includes(name);
  if (!touches) return null;

  const index = lockIndex(doc);
  const locked = (id) => typeof id === 'string' && index.has(id) && effectivelyLocked(index, id);
  const holds = (id) => typeof id === 'string' && index.has(id) && (effectivelyLocked(index, id) || hasLockedDescendant(index, id));

  if (BLOCK_EDIT.has(name)) return locked(p.block_id) ? 'locked' : null;
  if (SECTION_EDIT.has(name)) return locked(p.section_id) ? 'locked' : null;
  switch (name) {
    case OPS.INSERT_BLOCK: return locked(p.parent_id) ? 'locked' : null;
    case OPS.MOVE_BLOCK: return locked(p.block_id) || locked(p.parent_id) ? 'locked' : null;
    case OPS.REMOVE_BLOCK: return holds(p.block_id) ? 'locked' : null;
    case OPS.REMOVE_SECTION: return holds(p.section_id) ? 'locked' : null;
    case OPS.UPDATE_BLOCK_META: {
      const changesMore = Object.keys(p).some((k) => k !== 'block_id' && k !== 'locked');
      if (changesMore && locked(p.block_id)) return 'locked';
      if ('locked' in p && index.has(p.block_id) && ancestorLocked(index, p.block_id)) return 'locked';
      return null;
    }
    default: return null;
  }
}
