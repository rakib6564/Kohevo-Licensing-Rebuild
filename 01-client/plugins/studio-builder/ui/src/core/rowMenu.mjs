// What the Layers row menu offers for one row, and whether each entry is available right now.
// Pure: the component only renders this list and runs the chosen action.

import { blockDefinition, blockMoveTarget, canMoveBlock, childrenOf, findNode, isGlobalSection, sectionsOf } from './doc.mjs';
import { effectivelyLocked, hasLockedDescendant, isLocked } from './layerLock.mjs';
import { saveScopesFor } from './library.mjs';
import { planPaste } from './clipboard.mjs';

/** The row a node has in the Layers tree (the fields the menu reads), for a menu opened from the canvas. */
export function rowFor(doc, id) {
  const info = findNode(doc, id);
  if (!info) return null;
  const setSize = info.kind === 'section' ? sectionsOf(doc).length : childrenOf(doc, info.parentId).length;
  return { id, kind: info.kind, index: info.index, setSize, parentId: info.parentId, node: info.node };
}

/**
 * Whether each Paste entry can land at this row. `envelope` is what the in-tab clipboard holds; with none known (the
 * system clipboard is only read when Paste is chosen) both stay enabled and the choice itself reports the problem.
 */
export function pasteState({ row, doc, manifest, envelope }) {
  const def = row.kind === 'block' ? blockDefinition(manifest, row.node.type) : null;
  const holds = row.kind === 'section' ? !isGlobalSection(row.node) : !!(def && def.allows_children);
  const check = (mode) => {
    if (!envelope) return { disabled: false };
    const plan = planPaste({ doc, manifest, envelope, targetId: row.id, mode });
    return plan.ok ? { disabled: false } : { disabled: true, reasonKey: plan.reasonKey };
  };
  return { after: check('after'), inside: holds ? check('inside') : null };
}

/**
 * Entries in menu order. `labelKey` is a UI message key; `reasonKey` explains a disabled entry. With `paste` (from
 * `pasteState`) the menu also carries Copy, Cut and the Paste entries.
 */
export function rowMenuItems({ row, doc, manifest, locks, pageType = 'page', canSaveToLibrary = false, paste = null }) {
  const own = isLocked(row.node);
  const byAncestor = !own && effectivelyLocked(locks, row.id);
  const locked = own || byAncestor;
  const hidden = !!(row.node.visibility && Array.isArray(row.node.visibility.devices) && row.node.visibility.devices.length === 0);

  let canUp;
  let canDown;
  if (row.kind === 'section') {
    canUp = row.index > 0;
    canDown = row.index < row.setSize - 1;
  } else {
    const up = blockMoveTarget(doc, manifest, row.id, 'up');
    const down = blockMoveTarget(doc, manifest, row.id, 'down');
    canUp = !!up && canMoveBlock(doc, manifest, row.id, up.parentId);
    canDown = !!down && canMoveBlock(doc, manifest, row.id, down.parentId);
  }

  const items = [
    { key: 'rename', labelKey: 'row_rename', disabled: locked, reasonKey: locked ? 'locked_by_parent' : null },
    { key: 'duplicate', labelKey: 'duplicate' },
    ...(paste ? clipboardItems({ row, locks, paste, locked }) : []),
    own
      ? { key: 'unlock', labelKey: 'unlock_layer' }
      : { key: 'lock', labelKey: 'lock_layer', disabled: byAncestor, reasonKey: byAncestor ? 'locked_by_parent' : null },
    { key: hidden ? 'show' : 'hide', labelKey: hidden ? 'show' : 'hide' },
    { key: 'move_up', labelKey: 'row_move_up', disabled: locked || !canUp, reasonKey: locked ? 'locked_by_parent' : null },
    { key: 'move_down', labelKey: 'row_move_down', disabled: locked || !canDown, reasonKey: locked ? 'locked_by_parent' : null },
  ];

  // "Save to library" needs the admin permission and a scope for this very node (a global-component section has none).
  const scopes = saveScopesFor(doc, pageType, row.id);
  if (canSaveToLibrary && scopes.some((s) => s.nodeId === row.id)) items.push({ key: 'save_library', labelKey: 'row_save_library' });

  items.push({ key: 'delete', labelKey: 'remove_item', danger: true, disabled: locked, reasonKey: locked ? 'locked_by_parent' : null });
  return items;
}

// Copy always works; Cut removes, so it follows the same lock rules as Delete (a locked layer, or one holding a locked layer).
function clipboardItems({ row, locks, paste, locked }) {
  const holdsLock = hasLockedDescendant(locks, row.id);
  const cutBlocked = locked || holdsLock;
  const entry = (key, labelKey, state) => ({ key, labelKey, disabled: !!state.disabled, reasonKey: state.reasonKey || null });
  const items = [
    { key: 'copy', labelKey: 'cm_copy' },
    entry('paste_after', 'cm_paste_after', paste.after),
  ];
  if (paste.inside) items.push(entry('paste_inside', 'cm_paste_inside', paste.inside));
  items.push({ key: 'cut', labelKey: 'cm_cut', disabled: cutBlocked, reasonKey: cutBlocked ? 'locked_by_parent' : null });
  return items;
}

/** What the canvas right-click menu offers: Edit, then the shared entries it needs (no rename, move or library entries). */
export const CANVAS_MENU_KEYS = ['duplicate', 'copy', 'paste_after', 'paste_inside', 'cut', 'lock', 'unlock', 'hide', 'show', 'delete'];

export function canvasMenuItems(args) {
  const items = rowMenuItems({ ...args, paste: args.paste || { after: { disabled: false }, inside: null } });
  return [{ key: 'edit', labelKey: 'cm_edit' }, ...items.filter((i) => CANVAS_MENU_KEYS.includes(i.key))];
}

/** The next enabled index when arrowing through the menu (wraps; null when nothing is enabled). */
export function nextEnabled(items, from, step) {
  const n = items.length;
  for (let i = 1; i <= n; i += 1) {
    const idx = (((from + step * i) % n) + n) % n;
    if (!items[idx].disabled) return idx;
  }
  return null;
}
