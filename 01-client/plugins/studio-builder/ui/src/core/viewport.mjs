// Kohevo Studio builder — responsive editing viewports.
//
// The builder uses the CANONICAL breakpoint vocabulary (base, sm, md, lg —
// CanonicalDocumentSchema::ALLOWED_BREAKPOINTS) with the Phase 4 renderer's
// thresholds (StudioStylesheet: base < 640 <= sm < 768 <= md < 1024 <= lg).
// A viewport only changes the canvas frame width, and therefore which
// breakpoint the server-rendered CSS applies; it never forks the document.

export const BREAKPOINTS = Object.freeze(['base', 'sm', 'md', 'lg']);
export const BREAKPOINT_MIN_WIDTH = Object.freeze({ base: 0, sm: 640, md: 768, lg: 1024 });

export const VIEWPORTS = Object.freeze([
  Object.freeze({ key: 'desktop', label: 'Desktop', width: 1280, breakpoint: 'lg' }),
  Object.freeze({ key: 'tablet', label: 'Tablet', width: 820, breakpoint: 'md' }),
  Object.freeze({ key: 'mobile', label: 'Mobile', width: 390, breakpoint: 'base' }),
]);

export function breakpointForWidth(width) {
  let bp = 'base';
  for (const key of BREAKPOINTS) {
    if (width >= BREAKPOINT_MIN_WIDTH[key]) bp = key;
  }
  return bp;
}

export function viewportByKey(key) {
  return VIEWPORTS.find((v) => v.key === key) || VIEWPORTS[0];
}

/**
 * The value a responsive map resolves to at a breakpoint (mobile-first
 * cascade: the nearest defined breakpoint at or below it).
 */
export function resolveResponsive(map, breakpoint) {
  if (map === null || typeof map !== 'object' || Array.isArray(map)) return map;
  let value;
  for (const bp of BREAKPOINTS) {
    if (Object.prototype.hasOwnProperty.call(map, bp)) value = map[bp];
    if (bp === breakpoint) break;
  }
  return value;
}
