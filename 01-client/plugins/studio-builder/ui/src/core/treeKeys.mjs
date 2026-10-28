// Kohevo Studio builder — plain ArrowLeft / ArrowRight in the Layers tree (the WAI-ARIA tree pattern).
//
//   ArrowRight  on a collapsed parent: expand it; on an expanded one: go to its first child.
//   ArrowLeft   on an expanded parent: collapse it; otherwise: go to the row's parent.

/**
 * @param {string} key  'ArrowLeft' | 'ArrowRight'
 * @param {{id: string, parentId?: string|null, hasChildren?: boolean}} row
 * @param {Set<string>} collapsed  ids of collapsed rows
 * @param {Array<{id: string, parentId?: string|null}>} visibleRows  rows in display order
 * @returns {{expand: string}|{collapse: string}|{focus: string}|null}
 */
export function treeArrow(key, row, collapsed, visibleRows) {
  if (!row) return null;
  const open = row.hasChildren && !collapsed.has(row.id);
  if (key === 'ArrowRight') {
    if (!row.hasChildren) return null;
    if (!open) return { expand: row.id };
    const child = visibleRows.find((r) => r.parentId === row.id);
    return child ? { focus: child.id } : null;
  }
  if (key === 'ArrowLeft') {
    if (open) return { collapse: row.id };
    return row.parentId && visibleRows.some((r) => r.id === row.parentId) ? { focus: row.parentId } : null;
  }
  return null;
}
