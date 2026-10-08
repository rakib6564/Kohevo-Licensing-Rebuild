// Canvas overlay geometry — framework-free.
//
// The selection box, name chip and contextual toolbar are drawn in the PARENT
// document, positioned from the rectangles of `[data-sb-node]` elements inside
// the script-less canvas iframe. Nothing is ever written into the frame.
//
// A rect is { left, top, width, height } (right/bottom are derived).

const num = (v) => (Number.isFinite(v) ? v : 0);

export function rect(left, top, width, height) {
  return { left: num(left), top: num(top), width: Math.max(0, num(width)), height: Math.max(0, num(height)) };
}

export const right = (r) => r.left + r.width;
export const bottom = (r) => r.top + r.height;

/**
 * Scale of the canvas frame: how many parent pixels one frame (CSS) pixel takes.
 * `frameRect` is the iframe's getBoundingClientRect() in the parent; `layoutWidth`
 * is the width the page lays out at inside it (iframe.clientWidth). 1 when unknown.
 */
export function frameScale(frameRect, layoutWidth) {
  if (!frameRect || !(layoutWidth > 0) || !(frameRect.width > 0)) return 1;
  return frameRect.width / layoutWidth;
}

/**
 * A node's rectangle (as getBoundingClientRect() returns it INSIDE the frame, so
 * scroll is already applied) expressed in the overlay container's coordinates.
 */
export function toOverlayRect(nodeRect, frameRect, containerRect, scale = 1) {
  return rect(
    frameRect.left - containerRect.left + nodeRect.left * scale,
    frameRect.top - containerRect.top + nodeRect.top * scale,
    nodeRect.width * scale,
    nodeRect.height * scale,
  );
}

export function intersects(a, b) {
  return a.left < right(b) && right(a) > b.left && a.top < bottom(b) && bottom(a) > b.top;
}

/** The part of `r` inside `bounds`, or null when they do not overlap (node scrolled out of view). */
export function clipTo(r, bounds) {
  if (!intersects(r, bounds)) return null;
  const left = Math.max(r.left, bounds.left);
  const top = Math.max(r.top, bounds.top);
  return rect(left, top, Math.min(right(r), right(bounds)) - left, Math.min(bottom(r), bottom(bounds)) - top);
}

/** Smallest rect holding every rect (multi-select outline); null for none. */
export function unionRect(rects) {
  const list = rects.filter(Boolean);
  if (!list.length) return null;
  const left = Math.min(...list.map((r) => r.left));
  const top = Math.min(...list.map((r) => r.top));
  return rect(left, top, Math.max(...list.map(right)) - left, Math.max(...list.map(bottom)) - top);
}

const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), Math.max(lo, hi));

/**
 * Place a floating element (name chip or toolbar) of `size` {width, height} next
 * to `target`, inside `bounds`. Preference: above, aligned to the target's left
 * (`align: 'right'` aligns to its right edge). Flips below when there is no room
 * above, and sits inside the target's top edge when it fits in neither — so it is
 * never clipped by the node's own overflow and never leaves the visible area.
 */
export function placeFloating(target, size, bounds, { gap = 6, align = 'left' } = {}) {
  const maxLeft = right(bounds) - size.width;
  const wanted = align === 'right' ? right(target) - size.width : target.left;
  const left = clamp(wanted, bounds.left, maxLeft);

  const above = target.top - gap - size.height;
  if (above >= bounds.top) return { left, top: above, placement: 'above' };

  const below = bottom(target) + gap;
  if (below + size.height <= bottom(bounds)) return { left, top: below, placement: 'below' };

  return { left, top: clamp(target.top + gap, bounds.top, bottom(bounds) - size.height), placement: 'inside' };
}

/** Four corner markers of the selection box — visual only, they carry no resize behaviour. */
export function selectionCorners(r) {
  return [
    { x: r.left, y: r.top },
    { x: right(r), y: r.top },
    { x: r.left, y: bottom(r) },
    { x: right(r), y: bottom(r) },
  ];
}

/** Next/previous id in `order` for roving-tabindex keyboard navigation; wraps off neither end. */
export function stepId(order, current, delta) {
  if (!order.length) return null;
  const at = order.indexOf(current);
  if (at === -1) return delta >= 0 ? order[0] : order[order.length - 1];
  return order[clamp(at + delta, 0, order.length - 1)];
}
