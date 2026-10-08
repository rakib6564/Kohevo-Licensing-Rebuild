// Bottom-sheet snap logic — framework-free.
//
// A sheet is dragged by its handle between snap heights. Dragging well below the
// lowest snap closes it; a quick fling moves to the next snap in its direction.

export const SNAP_FRACTIONS = Object.freeze([0.4, 0.7, 0.92]);

/** Fling speed (px/ms) above which direction wins over distance. */
export const FLING_VELOCITY = 0.5;

/** Snap heights in px for a container, ascending, within [minHeight, maxHeight]. */
export function snapHeights(containerHeight, fractions = SNAP_FRACTIONS, { minHeight = 160, maxHeight = containerHeight } = {}) {
  const out = [];
  for (const f of fractions) {
    const h = Math.round(Math.min(Math.max(containerHeight * f, minHeight), maxHeight));
    if (!out.includes(h)) out.push(h);
  }
  return out.sort((a, b) => a - b);
}

/** Height while dragging: follows the finger, never above the top snap. `dy` > 0 drags down. */
export function dragHeight(startHeight, dy, snaps) {
  const top = snaps[snaps.length - 1];
  return Math.min(Math.max(startHeight - dy, 0), top);
}

/**
 * Where to settle on release. Returns a snap height, or null to close.
 *   height    current height while released
 *   velocity  px/ms, positive = moving down (shrinking)
 */
export function settle(height, velocity, snaps, { closeBelow = 0.5 } = {}) {
  const lowest = snaps[0];
  if (height < lowest * closeBelow) return null;
  if (velocity > FLING_VELOCITY && height <= lowest) return null;

  if (Math.abs(velocity) > FLING_VELOCITY) {
    const down = velocity > 0;
    const candidates = down ? snaps.filter((s) => s < height) : snaps.filter((s) => s > height);
    if (candidates.length) return down ? candidates[candidates.length - 1] : candidates[0];
    return down ? lowest : snaps[snaps.length - 1];
  }
  return snaps.reduce((best, s) => (Math.abs(s - height) < Math.abs(best - height) ? s : best), snaps[0]);
}
