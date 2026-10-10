// The client half of the server's style compiler, so a style edit shows on the canvas the moment it is made.
//
// The server (`StyleSurface::declarations`, `DocumentRenderer::buildInlineStyles` / `styleClasses`, `RenderCollector`)
// stays the single source of truth: it writes the saved page and the editor's repaint after a save. This file only
// predicts what that render will say, for the block styles an author is editing, so nothing waits on a round trip.
// `tests/live-style.test.mjs` and `tests/unit/StudioBuilderLiveStyleParityTest.php` both pin it against
// `tests/fixtures/live-style.json`, whose expected values are written by the PHP renderer itself.
//
// Anything this file cannot predict exactly (a style key it does not know, a background image, interaction states,
// a token the theme does not have) reports `covered: false`, and the canvas falls back to the server's render.

import { isMeasure, surfaceAccepts } from './styleSurface.mjs';
import {
  isBorderWidth, isColor, isFontFamily, isGradient, isLength, isLetterSpacing, isLengthList, isLineHeight, isShadow, isToken,
} from './styleValues.mjs';

export const SIDES = ['top', 'right', 'bottom', 'left'];
const CORNER_CSS = { tl: 'top-left', tr: 'top-right', br: 'bottom-right', bl: 'bottom-left' };

const JUSTIFY = { start: 'flex-start', center: 'center', end: 'flex-end', between: 'space-between', around: 'space-around', evenly: 'space-evenly' };
const ALIGN = { start: 'flex-start', center: 'center', end: 'flex-end', stretch: 'stretch', baseline: 'baseline' };
const POSITIONS = {
  center: 'center', top: 'top', bottom: 'bottom', left: 'left', right: 'right',
  'top-left': 'top left', 'top-right': 'top right', 'bottom-left': 'bottom left', 'bottom-right': 'bottom right',
};

export const FONT_WEIGHTS = ['normal', 'medium', 'semibold', 'bold', 'extrabold', '100', '200', '300', '400', '500', '600', '700', '800', '900'];
export const TEXT_TRANSFORMS = ['none', 'uppercase', 'lowercase', 'capitalize'];
export const BORDER_STYLES = ['none', 'solid', 'dashed', 'dotted', 'double'];
export const SHADOW_PRESETS = ['none', 'sm', 'md', 'lg', 'xl', '2xl', 'inner'];
export const RADIUS_PRESETS = ['none', 'sm', 'md', 'lg', 'xl', '2xl', 'full'];
const Z_MIN = -999;
const Z_MAX = 999;

const BLENDS = ['normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten'];
const CURSORS = ['auto', 'default', 'pointer', 'text', 'move', 'not-allowed', 'grab'];
const EASINGS = ['ease', 'ease-in', 'ease-out', 'ease-in-out', 'linear'];
const FILTER = { blur: [0, 50, 'px'], brightness: [0, 300, '%'], contrast: [0, 300, '%'], saturate: [0, 300, '%'], grayscale: [0, 100, '%'] };

/** token class infix => CSS property (RenderCollector::TOKEN_UTILITIES). */
const TOKEN_PROPS = { bd: 'border-color', bg: 'background-color', fg: 'color', font: 'font-family', pad: 'padding', rad: 'border-radius', shd: 'box-shadow' };
/** style key => token class infix (DocumentRenderer::STYLE_UTILITIES). */
const TOKEN_KEYS = { font_token: 'font', radius_token: 'rad', shadow_token: 'shd', spacing_token: 'pad', surface_token: 'bg', text_token: 'fg' };

export const DEVICE_QUERIES = { tablet: '(max-width:1023.98px)', mobile: '(max-width:767.98px)' };

/** Every top-level `style` key this file knows how to predict. */
const HANDLED_KEYS = new Set([
  'layout', 'position', 'margin', 'padding', 'dimensions', 'background', 'border', 'typography', 'color', 'shadow', 'effects',
  'opacity', 'z_index', 'align', ...Object.keys(TOKEN_KEYS),
]);

const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const inRange = (v, min, max) => typeof v === 'number' && Number.isFinite(v) && v >= min && v <= max;

/** Server-formatted number: at most 3 decimals, no trailing zeros, never an exponent. */
export function num(n) {
  const s = Number(n).toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
  return s === '' || s === '-0' ? '0' : s;
}

const ok = (path, v) => surfaceAccepts(path, v);

// ── scoped rule: StyleSurface::declarations ─────────────────────────────────

const LAYOUT = [
  ['display', 'display'], ['direction', 'flex-direction'], ['wrap', 'flex-wrap'], ['justify', 'justify-content', JUSTIFY], ['align', 'align-items', ALIGN],
  ['gap', 'gap'], ['row_gap', 'row-gap'], ['column_gap', 'column-gap'], ['order', 'order'], ['grow', 'flex-grow'], ['shrink', 'flex-shrink'],
  ['basis', 'flex-basis'], ['columns', 'grid-template-columns', null, 'repeat(%d,minmax(0,1fr))'], ['rows', 'grid-template-rows', null, 'repeat(%d,minmax(0,1fr))'],
];
const DIMENSIONS = [
  ['min_width', 'min-width'], ['max_height', 'max-height'], ['aspect_ratio', 'aspect-ratio'], ['overflow', 'overflow'], ['object_fit', 'object-fit'],
  ['object_position', 'object-position', POSITIONS],
];
const TYPO_EXTRAS = [
  ['style', 'font-style'], ['decoration', 'text-decoration-line'], ['decoration_style', 'text-decoration-style'], ['decoration_color', 'text-decoration-color'],
  ['decoration_thickness', 'text-decoration-thickness'], ['decoration_offset', 'text-underline-offset'],
];

function emitTable(group, values, table, out) {
  if (!isObj(values)) return;
  for (const [field, prop, map, template] of table) {
    if (!(field in values) || !ok(`${group}.${field}`, values[field])) continue;
    const v = values[field];
    let css;
    if (map) css = map[v];
    else if (template) css = template.replace('%d', String(v));
    else css = typeof v === 'string' ? v.trim() : String(v);
    out.push(`${prop}:${css}`);
  }
}

function emitSides(group, values, out) {
  if (!isObj(values)) return;
  for (const side of SIDES) {
    if (side in values && ok(`${group}.${side}`, values[side])) out.push(`${group}-${side}:${String(values[side]).trim()}`);
  }
}

function shadowValue(sh) {
  for (const required of ['x', 'y', 'color']) {
    if (!(required in sh) || !ok(`shadow.${required}`, sh[required])) return null;
  }
  const parts = [];
  if (sh.inset === true) parts.push('inset');
  for (const f of ['x', 'y', 'blur', 'spread']) {
    if (f in sh && ok(`shadow.${f}`, sh[f])) parts.push(String(sh[f]).trim());
    else if ((f === 'blur' || f === 'spread') && 'spread' in sh && f === 'blur') parts.push('0');
  }
  parts.push(String(sh.color).trim());
  return parts.join(' ');
}

function emitEffects(fx, out) {
  const t = isObj(fx.transform) ? fx.transform : {};
  const tx = 'translate_x' in t && ok('effects.transform.translate_x', t.translate_x) ? String(t.translate_x).trim() : null;
  const ty = 'translate_y' in t && ok('effects.transform.translate_y', t.translate_y) ? String(t.translate_y).trim() : null;
  const fn = [];
  if (tx !== null || ty !== null) fn.push(`translate(${tx ?? '0'},${ty ?? '0'})`);
  if (inRange(t.rotate, -360, 360)) fn.push(`rotate(${num(t.rotate)}deg)`);
  if (inRange(t.scale, 0, 5)) fn.push(`scale(${num(t.scale)})`);
  const sx = inRange(t.skew_x, -90, 90) ? num(t.skew_x) : null;
  const sy = inRange(t.skew_y, -90, 90) ? num(t.skew_y) : null;
  if (sx !== null || sy !== null) fn.push(`skew(${sx ?? '0'}deg,${sy ?? '0'}deg)`);
  if (fn.length) out.push(`transform:${fn.join(' ')}`);

  const f = isObj(fx.filter) ? fx.filter : {};
  const filters = [];
  for (const [name, [min, max, unit]] of Object.entries(FILTER)) {
    if (inRange(f[name], min, max)) filters.push(`${name}(${num(f[name])}${unit})`);
  }
  if (filters.length) out.push(`filter:${filters.join(' ')}`);
  if (inRange(fx.backdrop_blur, 0, 50)) {
    const b = `blur(${num(fx.backdrop_blur)}px)`;
    out.push(`-webkit-backdrop-filter:${b}`, `backdrop-filter:${b}`);
  }
  if (typeof fx.blend === 'string' && BLENDS.includes(fx.blend)) out.push(`mix-blend-mode:${fx.blend}`);
  const tr = isObj(fx.transition) ? fx.transition : {};
  if (Object.keys(tr).length) {
    const ms = Number.isInteger(tr.duration_ms) && tr.duration_ms >= 0 && tr.duration_ms <= 4000 ? tr.duration_ms : 200;
    const ease = typeof tr.easing === 'string' && EASINGS.includes(tr.easing) ? tr.easing : 'ease';
    out.push(`transition:all ${ms}ms ${ease}`);
  }
  if (typeof fx.cursor === 'string' && CURSORS.includes(fx.cursor)) out.push(`cursor:${fx.cursor}`);
}

/** The block-scoped rule's declarations. */
export function scopedDeclarations(style) {
  const out = [];
  emitTable('layout', style.layout, LAYOUT, out);
  if (isObj(style.position)) {
    if ('mode' in style.position && ok('position.mode', style.position.mode)) out.push(`position:${style.position.mode}`);
    for (const side of SIDES) if (side in style.position && ok(`position.${side}`, style.position[side])) out.push(`${side}:${String(style.position[side]).trim()}`);
  }
  emitSides('margin', style.margin, out);
  emitSides('padding', style.padding, out);
  emitTable('dimensions', style.dimensions, DIMENSIONS, out);
  if (isObj(style.border)) {
    for (const side of SIDES) {
      const b = style.border[side];
      if (!isObj(b)) continue;
      if ('width' in b && ok(`border.${side}.width`, b.width)) out.push(`border-${side}-width:${String(b.width).trim()}`);
      if ('style' in b && ok(`border.${side}.style`, b.style)) out.push(`border-${side}-style:${b.style}`);
      if ('color' in b && ok(`border.${side}.color`, b.color)) out.push(`border-${side}-color:${b.color}`);
    }
    if (isObj(style.border.radius_corners)) {
      for (const [short, long] of Object.entries(CORNER_CSS)) {
        if (short in style.border.radius_corners && ok(`border.radius_corners.${short}`, style.border.radius_corners[short])) {
          out.push(`border-${long}-radius:${String(style.border.radius_corners[short]).trim()}`);
        }
      }
    }
  }
  emitTable('typography', style.typography, TYPO_EXTRAS, out);
  if (isObj(style.shadow)) {
    const shadow = shadowValue(style.shadow);
    if (shadow !== null) out.push(`box-shadow:${shadow}`);
  }
  if (isObj(style.effects)) emitEffects(style.effects, out);
  return out;
}

// ── inline style: DocumentRenderer::buildInlineStyles ───────────────────────

const scalar = (v) => typeof v === 'string' || typeof v === 'number';

export function inlineDeclarations(style) {
  const rules = [];
  const emit = (prop, value, pass) => { if (pass && scalar(value)) rules.push(`${prop}:${value}`); };
  const isStr = (v) => typeof v === 'string';

  if (isObj(style.typography)) {
    const typo = style.typography;
    if (isStr(typo.size)) emit('font-size', typo.size, isLength(typo.size));
    if (isStr(typo.color) && !typo.color.startsWith('text.') && !typo.color.startsWith('color.')) emit('color', typo.color, isColor(typo.color));
    if (typo.line_height !== undefined && typo.line_height !== null) emit('line-height', typo.line_height, isLineHeight(typo.line_height));
    if (isStr(typo.letter_spacing)) emit('letter-spacing', typo.letter_spacing, isLetterSpacing(typo.letter_spacing));
    if (isStr(typo.font_family) && !isToken(typo.font_family)) emit('font-family', typo.font_family, isFontFamily(typo.font_family));
  }
  if (isStr(style.color) && !style.color.startsWith('text.') && !style.color.startsWith('color.')) emit('color', style.color, isColor(style.color));

  const bg = style.background;
  if (isStr(bg) && !bg.startsWith('surface.') && !bg.startsWith('color.')) {
    emit('background', bg, isColor(bg) || isGradient(bg));
  } else if (isObj(bg)) {
    if (isStr(bg.color) && !isToken(bg.color)) emit('background-color', bg.color, isColor(bg.color));
    if (isStr(bg.gradient)) emit('background-image', bg.gradient, isGradient(bg.gradient));
  }

  if (isObj(style.border)) {
    const b = style.border;
    if (b.width !== undefined && b.width !== null) emit('border-width', b.width, isBorderWidth(b.width));
    if (isStr(b.color) && !isToken(b.color)) emit('border-color', b.color, isColor(b.color));
    if (b.radius !== undefined && b.radius !== null && !RADIUS_PRESETS.includes(String(b.radius))) emit('border-radius', b.radius, isLengthList(b.radius));
  }
  if (isStr(style.shadow) && !SHADOW_PRESETS.includes(style.shadow)) emit('box-shadow', style.shadow, isShadow(style.shadow));

  if (isObj(style.dimensions)) {
    for (const dim of ['width', 'height', 'min_height', 'max_width']) {
      if (style.dimensions[dim] !== undefined && style.dimensions[dim] !== null) emit(dim.replace('_', '-'), style.dimensions[dim], isLength(style.dimensions[dim]));
    }
  }
  if (typeof style.opacity === 'number' && style.opacity >= 0 && style.opacity <= 1) rules.push(`opacity:${style.opacity}`);
  if (Number.isInteger(style.z_index)) rules.push(`z-index:${Math.max(Z_MIN, Math.min(Z_MAX, style.z_index))}`);
  return rules;
}

// ── classes: DocumentRenderer::styleClasses and ::typographyMarkers ─────────

/** The classes a block's style puts on its wrapper, apart from alignment and motion. `tokens` says which theme tokens exist. */
export function styleClasses(style, tokens) {
  const classes = [];
  for (const [key, kind] of Object.entries(TOKEN_KEYS)) {
    const ref = style[key];
    if (typeof ref === 'string' && tokens && tokens.has(ref)) classes.push(`sb-${kind}--${ref.replace(/\./g, '-')}`);
  }
  const typo = isObj(style.typography) ? style.typography : {};
  if (typo.weight !== undefined && FONT_WEIGHTS.includes(String(typo.weight))) classes.push(`sb-font-${typo.weight}`);
  if (typo.transform !== undefined && TEXT_TRANSFORMS.includes(String(typo.transform))) classes.push(`sb-${typo.transform}`);
  const border = isObj(style.border) ? style.border : {};
  if (border.radius !== undefined && RADIUS_PRESETS.includes(String(border.radius))) classes.push(`sb-radius-${border.radius}`);
  if (border.style !== undefined && BORDER_STYLES.includes(String(border.style))) classes.push(`sb-border-${border.style}`);
  if (typeof style.shadow === 'string' && SHADOW_PRESETS.includes(style.shadow)) classes.push(`sb-shadow-${style.shadow}`);
  return classes;
}

export function typographyMarkers(block) {
  const style = isObj(block.style) ? block.style : {};
  const typo = isObj(style.typography) ? style.typography : {};
  const set = (v) => typeof v === 'string' && v !== '';
  const out = [];
  let size = typeof typo.size === 'string' && isLength(typo.size);
  for (const device of ['tablet', 'mobile']) {
    const s = block.responsive && block.responsive[device] && block.responsive[device].style;
    const v = s && s.typography && s.typography.size;
    size = size || (typeof v === 'string' && isLength(v));
  }
  if (size) out.push('sb-ty-size');
  if (set(typo.color) || set(style.color)) out.push('sb-ty-color');
  if (typo.line_height !== undefined && typo.line_height !== null && isLineHeight(typo.line_height)) out.push('sb-ty-lh');
  if (typeof typo.letter_spacing === 'string' && isLetterSpacing(typo.letter_spacing)) out.push('sb-ty-ls');
  if (set(typo.font_family)) out.push('sb-ty-ff');
  if (typo.weight !== undefined && FONT_WEIGHTS.includes(String(typo.weight))) out.push('sb-ty-fw');
  if (typo.transform !== undefined && TEXT_TRANSFORMS.includes(String(typo.transform))) out.push('sb-ty-tt');
  return out;
}

/** Classes this file owns on a wrapper: a live update removes these (and only these) before adding the new set. */
export const MANAGED_CLASS = /^(sb-x-[0-9a-f]{16}|sb-(font|rad|shd|pad|bg|fg|bd)--[\w-]+|sb-font-(normal|medium|semibold|bold|extrabold|\d{3})|sb-(none|uppercase|lowercase|capitalize)|sb-radius-[\w]+|sb-border-(none|solid|dashed|dotted|double)|sb-shadow-[\w]+|sb-ty-[a-z]+)$/;

// ── per-device overrides: StyleSurface::responsiveRules ─────────────────────

export function responsiveDeclarations(responsive) {
  const rules = [];
  if (!isObj(responsive)) return rules;
  for (const [device, query] of Object.entries(DEVICE_QUERIES)) {
    const style = responsive[device] && responsive[device].style;
    if (!isObj(style)) continue;
    const out = [];
    if (isObj(style.typography) && isMeasure(style.typography.size, false)) out.push(`font-size:${style.typography.size.trim()}`);
    emitSides('padding', style.padding, out);
    emitSides('margin', style.margin, out);
    if (isObj(style.layout)) {
      for (const [field, prop] of [['gap', 'gap'], ['row_gap', 'row-gap'], ['column_gap', 'column-gap']]) {
        if (field in style.layout && ok(`layout.${field}`, style.layout[field])) out.push(`${prop}:${String(style.layout[field]).trim()}`);
      }
    }
    if (out.length) rules.push({ query, declarations: out.map((d) => `${d} !important`) });
  }
  return rules;
}

// ── the whole block ─────────────────────────────────────────────────────────

/**
 * What the server's render will put on a block for its `style` and per-device overrides.
 * `covered` is false when the block's style has something this file cannot predict exactly.
 */
export function compileBlockStyle(block, tokens) {
  const style = isObj(block && block.style) ? block.style : {};
  let covered = true;
  for (const key of Object.keys(style)) {
    if (!HANDLED_KEYS.has(key) && style[key] !== null && style[key] !== undefined && style[key] !== '') covered = false;
  }
  if (isObj(style.background) && style.background.image !== undefined && style.background.image !== null) covered = false;
  for (const key of Object.keys(TOKEN_KEYS)) {
    if (typeof style[key] === 'string' && style[key] !== '' && !(tokens && tokens.has(style[key]))) covered = false;
  }
  if (isObj(block && block.style_states) && Object.keys(block.style_states).length) covered = false;
  return {
    covered,
    scoped: scopedDeclarations(style),
    inline: inlineDeclarations(style),
    classes: [...styleClasses(style, tokens), ...typographyMarkers(block || {})],
    media: responsiveDeclarations(block && block.responsive),
    reduceMotion: isObj(style.effects) && isObj(style.effects.transition) && Object.keys(style.effects.transition).length > 0,
  };
}

// ── a section's own style: StyleSurface::sectionDeclarations ────────────────

export function compileSectionStyle(section) {
  const style = isObj(section && section.style) ? section.style : {};
  let covered = true;
  for (const key of Object.keys(style)) {
    if (!['background', 'padding'].includes(key) && style[key] !== null && style[key] !== undefined && style[key] !== '') covered = false;
  }
  const bg = isObj(style.background) ? style.background : {};
  if (bg.image !== undefined && bg.image !== null) covered = false;
  const scoped = [];
  if ('color' in bg && typeof bg.color === 'string' && isColor(bg.color) && !isToken(bg.color.trim())) scoped.push(`background-color:${String(bg.color).trim()}`);
  if ('gradient' in bg && !isObj(bg.image) && isGradient(bg.gradient)) scoped.push(`background-image:${String(bg.gradient).trim()}`);
  emitSides('padding', style.padding, scoped);
  return { covered, scoped };
}

// ── CSS text for the canvas ─────────────────────────────────────────────────

const esc = (id) => String(id).replace(/[^\w-]/g, '');

/** The rule text for one node: `[data-sb-node]` twice so it outweighs any class rule the server wrote. */
export function ruleText(id, compiled) {
  const sel = `[data-sb-node="${esc(id)}"][data-sb-node]`;
  let css = compiled.scoped.length ? `${sel}{${compiled.scoped.join(';')}}` : '';
  if (compiled.reduceMotion && compiled.scoped.length) css += `@media (prefers-reduced-motion:reduce){${sel}{transition:none}}`;
  for (const m of compiled.media || []) css += `@media ${m.query}{${sel}{${m.declarations.join(';')}}}`;
  return css;
}

/** The global rule behind a token class, so a token the page has not used yet still paints. */
export function tokenClassRules(classes) {
  const out = [];
  for (const c of classes) {
    const m = /^sb-(bd|bg|fg|font|pad|rad|shd)--([\w-]+)$/.exec(c);
    if (m) out.push(`.${c}{${TOKEN_PROPS[m[1]]}:var(--sb-${m[2]})}`);
  }
  return out;
}
