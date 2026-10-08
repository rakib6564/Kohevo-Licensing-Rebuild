// Pure geometry for dragging a Layers row (mouse, pen or touch): where on the row the pointer is, and
// how fast the list should scroll when the pointer nears its top or bottom edge.

/**
 * Where a drop lands relative to the row under the pointer. `fraction` is the pointer's vertical position
 * inside that row, 0 (top) to 1 (bottom). A section only takes sections before or after it, except that a
 * block dropped on a section goes inside it; a container row takes a block "inside" on its middle band.
 */
export function dropPositionAt(fraction, row, dragged) {
  const y = Math.max(0, Math.min(1, Number.isFinite(fraction) ? fraction : 0.5));
  if (dragged.kind === 'section' || row.kind === 'section') {
    return row.kind === 'section' && dragged.kind !== 'section' ? 'inside' : (y < 0.5 ? 'before' : 'after');
  }
  if (row.container && y > 0.3 && y < 0.7) return 'inside';
  return y < 0.5 ? 'before' : 'after';
}

/**
 * Pixels to scroll per frame: negative near the top edge, positive near the bottom, 0 elsewhere. The speed grows as
 * the pointer gets closer to (or beyond) the edge, up to `max`.
 */
export function autoScrollDelta(pointerY, top, bottom, { edge = 48, max = 18 } = {}) {
  if (!(bottom > top)) return 0;
  const size = Math.min(edge, (bottom - top) / 2);
  if (pointerY < top + size) return -Math.round(Math.min(1, (top + size - pointerY) / size) * max) || -1;
  if (pointerY > bottom - size) return Math.round(Math.min(1, (pointerY - (bottom - size)) / size) * max) || 1;
  return 0;
}

/** Is `candidateId` the dragged row itself or one of its descendants (never a valid drop target)? */
export function isSelfOrDescendant(rows, draggedId, candidateId) {
  if (draggedId === candidateId) return true;
  const byId = new Map(rows.map((r) => [r.id, r]));
  let cur = byId.get(candidateId);
  while (cur && cur.parentId) {
    if (cur.parentId === draggedId) return true;
    cur = byId.get(cur.parentId);
  }
  return false;
}
