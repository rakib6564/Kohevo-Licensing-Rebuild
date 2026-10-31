// What a style field really shows on the page, and where that comes from, so the Inspector never says "Inherit" over a
// visible colour. A block's look can come from its own style, from the part that is its main element (a Button's
// `style_parts.button`, whose rules are !important and so beat the block's style), from a button variant, or from the
// stylesheet. A state with no value of its own looks like Normal.
//
// The stored document is only read here; `colourFieldProps` also says where an edit has to go to show at all.

import { asObject } from './doc.mjs';
import { writePart } from './partStyles.mjs';
import { getPath, isColourToken, setPaths } from './styleSurface.mjs';
import { tokenLabel } from './tokenPicker.mjs';

/** The part that styles a block's own main element. Any other part is a piece inside it. */
const MAIN_PARTS = Object.freeze({ 'core.button': 'button' });
/** Part states (BlockParts::STATES) a colour field edits; `active` and `disabled` exist only on `style_states`. */
const PART_STATES = Object.freeze(['hover', 'focus']);

/** `.sb-button--<variant>` in StudioStylesheet: the theme colour each one paints. Null: no paint of its own. */
const VARIANTS = Object.freeze({
  primary: { text: 'text.inverse', background: 'surface.accent', border: 'color.accent' },
  secondary: { text: 'text.primary', background: 'surface.secondary', border: 'surface.secondary' },
  outline: { text: 'color.accent', background: null, border: 'color.accent' },
  ghost: { text: 'color.accent', background: null, border: null },
});

const PATHS = Object.freeze({ text: 'color', background: 'background.color', border: 'border.color' });
const COMPUTED = Object.freeze({ text: 'color', background: 'backgroundColor', border: 'borderColor' });

const present = (v) => typeof v === 'string' && v.trim() !== '';

/** The colour a style (or part, or state) holds for a field, theme token keys included; undefined when it has none. */
function ownColour(style, field) {
  const s = asObject(style);
  if (field === 'text') return [s.text_token, getPath(s, 'typography.color'), s.color].find(present);
  if (field === 'background') return [s.surface_token, getPath(s, 'background.color'), typeof s.background === 'string' ? s.background : undefined].find(present);
  return [s.border_token, getPath(s, 'border.color')].find(present);
}

/** `rgb(233, 0, 138)` as `#e9008a`; a colour with alpha, or anything else, is returned as it came. */
export function cssToHex(css) {
  const m = /^rgb\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)\s*\)$/.exec(String(css || '').trim());
  return m ? `#${m.slice(1, 4).map((n) => Number(n).toString(16).padStart(2, '0')).join('')}` : String(css || '').trim();
}

const transparent = (css) => css === 'transparent' || /^rgba\(.*,\s*0\)$/.test(String(css || '').replace(/\s/g, ''));

function describe(raw, source, detail, tokens) {
  const token = isColourToken(raw) ? raw : null;
  if (!token) return { raw, css: raw, label: raw, token: null, source, detail };
  const known = (tokens || []).find((tk) => tk.ref === token);
  return { raw, css: known && known.value ? known.value : `var(--sb-${token.replace(/\./g, '-')})`, label: tokenLabel(token), token, source, detail };
}

function fromStylesheet(field, ctx) {
  const css = ctx.computed && ctx.computed[COMPUTED[field]];
  if (!css || transparent(css)) return null;
  const hex = cssToHex(css);
  const match = (ctx.tokens || []).find((tk) => String(tk.value).toLowerCase() === hex.toLowerCase());
  return { raw: hex, css: hex, label: match ? tokenLabel(match.ref) : hex, token: match ? match.ref : null, source: 'stylesheet', detail: null };
}

/** The Normal look of a field: the main part, the block's own style, its variant, then the stylesheet. */
function normalColour(block, field, ctx) {
  const main = MAIN_PARTS[block.type];
  const part = main ? ownColour(asObject(block.style_parts)[main], field) : undefined;
  if (part) return describe(part, 'part', ctx.partLabel || main, ctx.tokens);
  const own = ownColour(block.style, field);
  if (own) return describe(own, 'block', null, ctx.tokens);
  const variant = block.type === 'core.button' ? VARIANTS[asObject(block.props).variant || 'primary'] : null;
  if (variant) return variant[field] ? describe(variant[field], 'variant', asObject(block.props).variant || 'primary', ctx.tokens) : null;
  return fromStylesheet(field, ctx);
}

/**
 * What `field` ('text', 'background' or 'border') looks like in `state` ('normal', 'hover', 'focus', 'active', 'disabled').
 * `ctx`: { tokens, computed (the canvas element's computed colours, if known), partLabel }.
 *
 * @returns {{raw: string, css: string, label: string, token: ?string, source: string, detail: ?string}|null}
 *   source: 'block' | 'state' (stored on the block) | 'part' | 'variant' | 'stylesheet' | 'normal' (a state showing Normal)
 */
export function effectiveColour(block, field, state, ctx = {}) {
  if (state === 'normal') return normalColour(block, field, ctx);
  const main = MAIN_PARTS[block.type];
  const parts = asObject(block.style_parts);
  const statePart = main && PART_STATES.includes(state) ? ownColour(parts[`${main}:${state}`], field) : undefined;
  if (statePart) return describe(statePart, 'part', ctx.partLabel || main, ctx.tokens);
  // A part's rule is !important on the element itself: a state on the block's wrapper cannot beat it.
  const shadowed = main && ownColour(parts[main], field);
  const own = shadowed ? undefined : ownColour(asObject(block.style_states)[state], field);
  if (own) return describe(own, 'state', null, ctx.tokens);
  const normal = normalColour(block, field, ctx);
  return normal ? { ...normal, source: 'normal' } : null;
}

/** The `style_parts` key an edit of this field must go to, or null when the block's own style or state takes it. */
function partKeyFor(block, field, state) {
  const main = MAIN_PARTS[block.type];
  if (!main) return null;
  const parts = asObject(block.style_parts);
  if (state === 'normal') return ownColour(parts[main], field) ? main : null;
  if (!PART_STATES.includes(state)) return null;
  return ownColour(parts[`${main}:${state}`], field) || ownColour(parts[main], field) ? `${main}:${state}` : null;
}

/**
 * The props of a block's ColorField: always what it `effective`ly shows, and, when a part holds that colour, the value
 * and writes of that part in place of the block's own (an edit to the block's style would never show).
 */
export function colourFieldProps(block, field, state, ctx, onParts) {
  const effective = effectiveColour(block, field, state, ctx);
  const key = onParts ? partKeyFor(block, field, state) : null;
  if (!key) return { effective };
  const parts = asObject(block.style_parts);
  const partial = asObject(parts[key]);
  const current = ownColour(partial, field);
  const put = (v) => onParts(writePart(parts, key, setPaths(partial, field === 'text' ? [['typography.color', undefined], ['color', v]] : [[PATHS[field], v]])));
  return {
    effective,
    value: isColourToken(current) ? undefined : current,
    token: isColourToken(current) ? current : null,
    tokens: ctx.tokens,
    onChange: put,
    onToken: (ref) => put(ref ?? undefined),
  };
}

/** A non-colour field (`typography.size`, `border.radius`...) held by the main part: its value and how to change it. */
export function partField(block, path, ctx, onParts) {
  const main = MAIN_PARTS[block.type];
  const parts = asObject(block.style_parts);
  const partial = main ? asObject(parts[main]) : {};
  const value = getPath(partial, path);
  if (value === undefined || value === null || !onParts) return null;
  return { value, hintDetail: ctx.partLabel || main, put: (v) => onParts(writePart(parts, main, setPaths(partial, [[path, v]]))) };
}

/** The element of a block that carries its look, for reading computed styles off the canvas. */
export function mainElement(node) {
  const button = node.querySelector('.sb-button');
  if (button) return button;
  const first = node.firstElementChild;
  return first && !first.hasAttribute('data-sb-node') ? first : node;
}
