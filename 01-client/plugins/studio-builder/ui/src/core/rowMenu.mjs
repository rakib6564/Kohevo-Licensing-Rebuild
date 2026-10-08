// What the Layers row menu offers for one row, and whether each entry is available right now.
// Pure: the component only renders this list and runs the chosen action.

import { blockMoveTarget, canMoveBlock } from './doc.mjs';
import { effectivelyLocked, isLocked } from './layerLock.mjs';
import { saveScopesFor } from './library.mjs';

/** Entries in menu order. `labelKey` is a UI message key; `reasonKey` explains a disabled entry. */
export function rowMenuItems({ row, doc, manifest, locks, pageType = 'page', canSaveToLibrary = false }) {
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

/** The next enabled index when arrowing through the menu (wraps; null when nothing is enabled). */
export function nextEnabled(items, from, step) {
  const n = items.length;
  for (let i = 1; i <= n; i += 1) {
    const idx = (((from + step * i) % n) + n) % n;
    if (!items[idx].disabled) return idx;
  }
  return null;
}
