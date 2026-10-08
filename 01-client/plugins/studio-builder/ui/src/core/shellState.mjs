// Shell state helpers — framework-free rules for the builder chrome.
//
//   view modes   edit (full editing) · preview (Builder chrome kept, canvas
//                read-only) · visitor (chrome minimised, the real preview page)
//   multi-select Outline rows picked with Shift / Ctrl / Cmd
//   keyboard     how much of the layout viewport the on-screen keyboard covers

export const VIEW_MODES = ['edit', 'preview', 'visitor'];

/** Only `edit` lets the canvas select, drag or inline-edit. */
export function isEditing(mode) {
  return mode === 'edit';
}

/** Unknown values fall back to `edit` so a bad state can never lock the editor read-only. */
export function normalizeViewMode(mode) {
  return VIEW_MODES.includes(mode) ? mode : 'edit';
}

/** The authorized preview endpoint for this page (the same one the external Preview link opens). */
export function visitorPreviewUrl(boot) {
  return `${boot.previewUrl}?page=${encodeURIComponent(boot.pageId)}`;
}

/** Ids from `a` to `b` inclusive in visual order; just `[b]` when `a` is absent from the rows. */
export function rangeIds(rows, a, b) {
  const ids = rows.map((r) => r.id);
  const from = ids.indexOf(a);
  const to = ids.indexOf(b);
  if (to === -1) return [];
  if (from === -1) return [b];
  const [lo, hi] = from <= to ? [from, to] : [to, from];
  return ids.slice(lo, hi + 1);
}

/**
 * Next picked set after a row click.
 *   plain click      → just that row
 *   Ctrl / Cmd       → toggle the row
 *   Shift            → range from the anchor
 */
export function nextPicked(picked, rows, anchor, id, { shift = false, toggle = false } = {}) {
  if (shift && anchor) return new Set(rangeIds(rows, anchor, id));
  if (toggle) {
    const next = new Set(picked);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    return next;
  }
  return new Set([id]);
}

/** Drop picked rows whose ancestor is also picked: acting on the ancestor already covers them. */
export function topLevelIds(rows, picked) {
  const byId = new Map(rows.map((r) => [r.id, r]));
  const out = [];
  for (const row of rows) {
    if (!picked.has(row.id)) continue;
    let covered = false;
    let parent = row.parentId ? byId.get(row.parentId) : null;
    while (parent) {
      if (picked.has(parent.id)) { covered = true; break; }
      parent = parent.parentId ? byId.get(parent.parentId) : null;
    }
    if (!covered) out.push(row.id);
  }
  return out;
}

/** Below this the "keyboard" is just browser chrome (URL bar) resizing the viewport. */
const KEYBOARD_THRESHOLD_PX = 120;

/**
 * Pixels of the layout viewport hidden by the on-screen keyboard, from the Visual
 * Viewport API values. 0 when there is no keyboard or no API.
 */
export function keyboardInset(layoutHeight, viewport) {
  if (!viewport || !Number.isFinite(layoutHeight)) return 0;
  const covered = layoutHeight - (viewport.height + (viewport.offsetTop || 0));
  return covered >= KEYBOARD_THRESHOLD_PX ? Math.round(covered) : 0;
}
