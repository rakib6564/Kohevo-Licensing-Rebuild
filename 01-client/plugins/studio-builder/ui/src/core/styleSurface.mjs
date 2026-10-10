// The client half of `StyleSurface.php`: the closed table of style fields added in B2-P3 and how each is
// validated. The Inspector's controls read their option lists from here and refuse what the server refuses, so
// an author sees an inline error instead of a block that goes "unavailable". `tests/style-surface.test.mjs` and
// `tests/unit/StudioBuilderStyleSurfaceParityTest.php` both pin this file against `fixtures/style-surface.json`.

import { isColor, isLength, isToken } from './styleValues.mjs';

export const SIDES = Object.freeze(['top', 'right', 'bottom', 'left']);
export const CORNERS = Object.freeze(['tl', 'tr', 'br', 'bl']);

export const OPTIONS = Object.freeze({
  display: ['block', 'flex', 'grid', 'inline-block', 'inline-flex'],
  direction: ['row', 'row-reverse', 'column', 'column-reverse'],
  wrap: ['nowrap', 'wrap', 'wrap-reverse'],
  justify: ['start', 'center', 'end', 'between', 'around', 'evenly'],
  align: ['start', 'center', 'end', 'stretch', 'baseline'],
  position: ['static', 'relative', 'absolute', 'sticky'],
  overflow: ['visible', 'hidden', 'auto', 'scroll'],
  objectFit: ['cover', 'contain', 'fill', 'none', 'scale-down'],
  objectPosition: ['center', 'top', 'bottom', 'left', 'right', 'top-left', 'top-right', 'bottom-left', 'bottom-right'],
  borderStyle: ['none', 'solid', 'dashed', 'dotted', 'double'],
  fontStyle: ['normal', 'italic'],
  decoration: ['none', 'underline', 'line-through', 'overline'],
  decorationStyle: ['solid', 'dashed', 'dotted', 'wavy', 'double'],
  blend: ['normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten'],
  cursor: ['auto', 'default', 'pointer', 'text', 'move', 'not-allowed', 'grab'],
  easing: ['ease', 'ease-in', 'ease-out', 'ease-in-out', 'linear'],
  states: ['hover', 'focus', 'active', 'disabled'],
  tags: ['div', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'figure'],
});

/** Numeric `effects` groups: field => [min, max, unit]. */
export const TRANSFORM = Object.freeze({ rotate: [-360, 360, 'deg'], scale: [0, 5, ''], skew_x: [-90, 90, 'deg'], skew_y: [-90, 90, 'deg'] });
export const FILTER = Object.freeze({ blur: [0, 50, 'px'], brightness: [0, 300, '%'], contrast: [0, 300, '%'], saturate: [0, 300, '%'], grayscale: [0, 100, '%'] });

const inRange = (v, min, max) => typeof v === 'number' && Number.isFinite(v) && v >= min && v <= max;

/** A length: a string with a unit (or zero), no CSS-wide keywords; `auto` only where the property takes it. */
export function isMeasure(v, allowAuto = false) {
  if (typeof v !== 'string') return false;
  const bare = v.trim();
  if (/^-?(\d+\.?\d*|\.\d+)$/.test(bare) && Number(bare) !== 0) return false;
  if (bare.toLowerCase() === 'auto') return allowAuto;
  if (['none', 'inherit', 'initial', 'unset', 'fit-content', 'min-content', 'max-content'].includes(bare.toLowerCase())) return false;
  return isLength(v);
}

const enumOf = (list) => (v) => typeof v === 'string' && list.includes(v);
const measure = (auto) => (v) => isMeasure(v, auto);
const padding = (v) => isMeasure(v, false) && !v.trim().startsWith('-');
const colour = (v) => typeof v === 'string' && isColor(v) && !isToken(v.trim());

/** The token categories that hold a colour (StyleSurface::COLOUR_TOKEN_CATEGORIES). */
export const COLOUR_TOKEN_CATEGORIES = Object.freeze(['surface', 'text', 'color', 'border']);

/** A theme token that holds a colour: `color.accent`, `text.muted`, `border.default`. */
export function isColourToken(v) {
  return typeof v === 'string' && isToken(v) && COLOUR_TOKEN_CATEGORIES.includes(v.split('.')[0]);
}

/** What goes in the CSS for a colour that may be a token: the theme's custom property, or the colour as written. */
export function colourCss(v) {
  const text = String(v).trim();
  return isColourToken(text) ? `var(--sb-${text.replace(/\./g, '-')})` : text;
}

/** A literal colour, or a theme colour token (StyleSurface's `tcolor`). */
const tcolour = (v) => typeof v === 'string' && (isColourToken(v.trim()) || colour(v));
const int = (min, max) => (v) => Number.isInteger(v) && v >= min && v <= max;
const num = (min, max) => (v) => inRange(v, min, max);
const ratio = (v) => typeof v === 'string' && /^[1-9][0-9]{0,2}\/[1-9][0-9]{0,2}$/.test(v);

/** path => validator. A path is the style key plus the field, e.g. `layout.gap`, `margin.top`, `border.top.width`. */
const TABLE = {};
const add = (path, check) => { TABLE[path] = check; };

add('layout.display', enumOf(OPTIONS.display));
add('layout.direction', enumOf(OPTIONS.direction));
add('layout.wrap', enumOf(OPTIONS.wrap));
add('layout.justify', enumOf(OPTIONS.justify));
add('layout.align', enumOf(OPTIONS.align));
for (const f of ['gap', 'row_gap', 'column_gap']) add(`layout.${f}`, measure(false));
add('layout.basis', measure(true));
add('layout.order', int(-99, 99));
add('layout.grow', int(0, 10));
add('layout.shrink', int(0, 10));
add('layout.columns', int(1, 12));
add('layout.rows', int(1, 12));

add('position.mode', enumOf(OPTIONS.position));
for (const s of SIDES) {
  add(`position.${s}`, measure(true));
  add(`margin.${s}`, measure(true));
  add(`padding.${s}`, padding);
  add(`border.${s}.width`, measure(false));
  add(`border.${s}.style`, enumOf(OPTIONS.borderStyle));
  add(`border.${s}.color`, tcolour);
}
for (const c of CORNERS) add(`border.radius_corners.${c}`, measure(false));

add('dimensions.min_width', measure(true));
add('dimensions.max_height', measure(true));
add('dimensions.aspect_ratio', ratio);
add('dimensions.overflow', enumOf(OPTIONS.overflow));
add('dimensions.object_fit', enumOf(OPTIONS.objectFit));
add('dimensions.object_position', enumOf(OPTIONS.objectPosition));

add('typography.style', enumOf(OPTIONS.fontStyle));
add('typography.decoration', enumOf(OPTIONS.decoration));
add('typography.decoration_style', enumOf(OPTIONS.decorationStyle));
add('typography.decoration_color', tcolour);
add('typography.decoration_thickness', measure(false));
add('typography.decoration_offset', measure(false));

for (const f of ['x', 'y', 'blur', 'spread']) add(`shadow.${f}`, measure(false));
add('shadow.color', tcolour);

for (const [f, [min, max]] of Object.entries(TRANSFORM)) add(`effects.transform.${f}`, num(min, max));
add('effects.transform.translate_x', measure(false));
add('effects.transform.translate_y', measure(false));
for (const [f, [min, max]] of Object.entries(FILTER)) add(`effects.filter.${f}`, num(min, max));
add('effects.backdrop_blur', num(0, 50));
add('effects.blend', enumOf(OPTIONS.blend));
add('effects.cursor', enumOf(OPTIONS.cursor));
add('effects.transition.duration_ms', int(0, 4000));
add('effects.transition.easing', enumOf(OPTIONS.easing));

/** True when `value` is acceptable at `path` (an unknown path is never acceptable). */
export function surfaceAccepts(path, value) {
  const check = TABLE[path];
  return typeof check === 'function' && check(value) === true;
}

export const SURFACE_PATHS = Object.freeze(Object.keys(TABLE));

/** Like `acceptsDraft`: empty means "unset"; otherwise the surface decides. */
export function acceptsSurfaceDraft(path, value) {
  if (value === undefined || value === null || value === '') return true;
  return surfaceAccepts(path, value);
}

// ── Immutable path helpers ───────────────────────────────────────────────────

const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

/** The value at a dotted path, or undefined. */
export function getPath(obj, path) {
  let node = obj;
  for (const part of String(path).split('.')) {
    if (!isObj(node) || !(part in node)) return undefined;
    node = node[part];
  }
  return node;
}

/**
 * A copy of `obj` with `path` set to `value`. `undefined` removes the key, and any object that ends up
 * empty is removed with it, so clearing the last field of a group clears the group (no `"layout": {}` shells).
 */
export function setPath(obj, path, value) {
  const parts = String(path).split('.');
  const walk = (node, i) => {
    const base = isObj(node) ? { ...node } : {};
    const key = parts[i];
    if (i === parts.length - 1) {
      if (value === undefined) delete base[key];
      else base[key] = value;
    } else {
      const child = walk(base[key], i + 1);
      if (Object.keys(child).length === 0) delete base[key];
      else base[key] = child;
    }
    return base;
  };
  return walk(obj, 0);
}

/** Apply several [path, value] pairs in order. */
export function setPaths(obj, pairs) {
  return pairs.reduce((acc, [path, value]) => setPath(acc, path, value), obj);
}
