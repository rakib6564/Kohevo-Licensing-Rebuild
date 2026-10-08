// The Inspector's section registry (pure).
//
// A block's Inspector is the list of sections that APPLY to it, grouped under three tabs
// (Content, Style, Advanced). Each entry here says where a section lives, when it applies, what
// its collapsed header summarises and whether it opens by default; the component that renders it
// is looked up by id in the Inspector. Keeping this table free of React makes "which sections does
// a heading show, and which does an image?" an ordinary unit test.
//
// `style_capabilities` (from the block definition, i.e. the server's manifest) decides which style
// sections appear, so a block that cannot take a border never shows one.

import { asList, asObject } from './doc.mjs';
import { STYLE_TOKEN_CATEGORIES } from './fields.mjs';

export const TABS = Object.freeze(['content', 'style', 'advanced']);

const has = (ctx, key) => ctx.capabilities.includes(key);
const count = (v) => (Array.isArray(v) ? v.length : Object.keys(asObject(v)).length);

/** Text-led block types, where Typography is the section an author reaches for first. */
const TEXT_TYPES = new Set(['core.heading', 'core.text', 'core.rich_text', 'core.button', 'core.quote', 'core.list', 'core.link']);

/**
 * Pure media blocks: they have no text of their own, so Typography would be a dead section. Every block
 * currently declares the same `style_capabilities` (the server's default), so what a block shows is decided
 * here as well. This hides sections in the editor only: it never changes what the document may contain,
 * because narrowing the server's capabilities would make already-stored styles invalid.
 */
export const MEDIA_TYPES = new Set(['core.image', 'core.video', 'core.gallery']);

/** Types shaped like a button or card: the surface (background, border) is what an author edits first. */
const SURFACE_TYPES = new Set(['core.button', 'core.card']);

/** A block that holds other blocks: how it arranges and spaces them is what an author edits first. */
const isContainer = (ctx) => !!ctx.def && ctx.def.allows_children === true;

/** Where a surface-type section lives: Style for a container or surface block, Advanced for everything else. */
const surfaceTab = (ctx) => (isContainer(ctx) || SURFACE_TYPES.has(ctx.node.type) ? 'style' : 'advanced');

/**
 * Sections in display order, laid out like the reference editors: Content (what it says, and how a container
 * arranges its children), Style (how it looks), Advanced (spacing, stacking, entrance, identifiers, then the
 * rest, collapsed). `tab` is a name or a function of the block, so a container shows Layout under Content while
 * a leaf block keeps it under Advanced. `titleKey` is a UI message key. `summary(ctx)` is a short string for the
 * collapsed header (empty when the section holds nothing). `openFor(ctx)` marks the sections that start open.
 */
export const SECTIONS = Object.freeze([
  { id: 'content', tab: 'content', titleKey: 'section_content', appliesTo: () => true, summary: () => '', openFor: () => true },
  { id: 'data', tab: 'content', titleKey: 'tab_data', appliesTo: (ctx) => asList(ctx.def.binding_slots).length > 0, summary: (ctx) => (count(ctx.node.bindings) ? String(count(ctx.node.bindings)) : ''), openFor: () => false },
  // One group with what every element needs: margin, padding, z-index, entrance animation, CSS ID and classes.
  { id: 'advanced', tab: 'advanced', titleKey: 'section_advanced', keywords: ['margin', 'padding', 'spacing', 'z-index', 'stacking', 'entrance', 'animation', 'css id', 'class'], appliesTo: () => true, summary: (ctx) => advancedSummary(ctx), openFor: () => true },
  { id: 'layout', tab: (ctx) => (isContainer(ctx) ? 'content' : 'advanced'), titleKey: 'section_layout', appliesTo: (ctx) => has(ctx, 'layout'), summary: (ctx) => layoutSummary(ctx.style.layout), openFor: isContainer },

  { id: 'align', tab: 'style', titleKey: 'align', appliesTo: (ctx) => has(ctx, 'align'), summary: (ctx) => alignSummary(ctx.style.align), openFor: () => false },
  { id: 'typography', tab: 'style', titleKey: 'typography', appliesTo: (ctx) => (has(ctx, 'typography') || has(ctx, 'color')) && !MEDIA_TYPES.has(ctx.node.type), summary: (ctx) => typographySummary(ctx.style), openFor: (ctx) => TEXT_TYPES.has(ctx.node.type) },
  { id: 'background', tab: surfaceTab, titleKey: 'background_label', appliesTo: (ctx) => has(ctx, 'background'), summary: (ctx) => backgroundSummary(ctx.style.background), openFor: (ctx) => SURFACE_TYPES.has(ctx.node.type) },
  { id: 'border', tab: surfaceTab, titleKey: 'border', appliesTo: (ctx) => has(ctx, 'border'), summary: (ctx) => borderSummary(ctx.style.border), openFor: (ctx) => SURFACE_TYPES.has(ctx.node.type) },
  { id: 'shadow', tab: 'style', titleKey: 'box_shadow', appliesTo: (ctx) => has(ctx, 'shadow'), summary: (ctx) => (typeof ctx.style.shadow === 'string' ? ctx.style.shadow : (ctx.style.shadow ? '…' : '')), openFor: () => false },
  { id: 'dimensions', tab: (ctx) => (MEDIA_TYPES.has(ctx.node.type) ? 'style' : 'advanced'), titleKey: 'dimensions_label', appliesTo: (ctx) => has(ctx, 'dimensions'), summary: (ctx) => dimensionsSummary(ctx.style.dimensions), openFor: (ctx) => ctx.node.type === 'core.image' },
  { id: 'opacity', tab: 'style', titleKey: 'opacity_label', appliesTo: (ctx) => has(ctx, 'opacity'), summary: (ctx) => (typeof ctx.style.opacity === 'number' ? `${Math.round(ctx.style.opacity * 100)}%` : ''), openFor: () => false },
  { id: 'states', tab: 'style', titleKey: 'section_states', appliesTo: () => true, summary: (ctx) => Object.keys(asObject(ctx.node.style_states)).join(' · '), openFor: () => false },
  { id: 'tokens', tab: 'style', titleKey: 'section_tokens', appliesTo: (ctx) => Object.keys(STYLE_TOKEN_CATEGORIES).some((k) => has(ctx, k)), summary: (ctx) => String(Object.keys(STYLE_TOKEN_CATEGORIES).filter((k) => ctx.style[k]).length || ''), openFor: () => false },

  { id: 'position', tab: 'advanced', titleKey: 'section_position', appliesTo: (ctx) => has(ctx, 'position'), summary: (ctx) => asObject(ctx.style.position).mode || '', openFor: () => false },
  { id: 'effects', tab: 'advanced', titleKey: 'section_effects', appliesTo: (ctx) => has(ctx, 'effects'), summary: (ctx) => Object.keys(asObject(ctx.style.effects)).join(' · '), openFor: () => false },
  { id: 'motion', tab: 'advanced', titleKey: 'section_motion_effects', appliesTo: () => true, summary: (ctx) => motionSummary(ctx.node), openFor: () => false },
  { id: 'responsive', tab: 'advanced', titleKey: 'tab_responsive', appliesTo: () => true, summary: (ctx) => responsiveSummary(ctx.node), openFor: () => false },
  { id: 'identity', tab: 'advanced', titleKey: 'section_a11y', appliesTo: () => true, summary: (ctx) => a11ySummary(ctx.node.attributes), openFor: () => false },
  { id: 'tag', tab: 'advanced', titleKey: 'wrapper_tag', appliesTo: () => true, summary: (ctx) => (typeof ctx.node.tag === 'string' ? ctx.node.tag : ''), openFor: () => false },
  { id: 'attributes', tab: 'advanced', titleKey: 'section_data_attributes', appliesTo: () => true, summary: (ctx) => dataAttributeSummary(ctx.node.attributes), openFor: () => false },
]);

/** The tab a section sits under for this block. */
export function tabOf(section, ctx) {
  return typeof section.tab === 'function' ? section.tab(ctx) : section.tab;
}

function advancedSummary(ctx) {
  const bits = [spacingSummary(ctx.style)];
  if (Number.isInteger(ctx.style.z_index)) bits.push(`z ${ctx.style.z_index}`);
  const id = asObject(ctx.node.attributes).id;
  if (id) bits.push(`#${id}`);
  const n = asList(ctx.node.classNames).length;
  if (n) bits.push(`.${n}`);
  return bits.filter(Boolean).join(' · ');
}
function responsiveSummary(node) {
  const bits = [visibilitySummary(node.visibility)];
  const n = count(node.responsive);
  if (n) bits.push(String(n));
  return bits.filter(Boolean).join(' · ');
}
function a11ySummary(attrs) {
  const a = asObject(attrs);
  return [a.role, a['aria-label'] ? 'aria-label' : ''].filter(Boolean).join(' · ');
}

/**
 * A page section has its own, smaller model than a block (a vocabulary `layout`, a `style` of background and padding, motion
 * and visibility), so it has its own list, shaped the same way: Content (how it arranges), Style (its background) and Advanced
 * (padding, entrance, motion, responsive).
 */
export const SECTION_DEF = Object.freeze({ style_capabilities: Object.freeze(['background', 'padding']), allows_children: true, binding_slots: Object.freeze([]) });

export const PAGE_SECTION_SECTIONS = Object.freeze([
  { id: 'layout', tab: 'content', titleKey: 'section_layout', appliesTo: () => true, summary: (ctx) => pageLayoutSummary(ctx.node.layout), openFor: () => true },
  { id: 'background', tab: 'style', titleKey: 'background_label', appliesTo: () => true, summary: (ctx) => backgroundSummary(ctx.style.background), openFor: () => true },
  { id: 'advanced', tab: 'advanced', titleKey: 'section_advanced', keywords: ['padding', 'spacing', 'entrance', 'animation'], appliesTo: () => true, summary: (ctx) => spacingSummary({ padding: asObject(ctx.style).padding }), openFor: () => true },
  { id: 'motion', tab: 'advanced', titleKey: 'section_motion_effects', appliesTo: () => true, summary: (ctx) => motionSummary(ctx.node), openFor: () => false },
  { id: 'responsive', tab: 'advanced', titleKey: 'tab_responsive', appliesTo: () => true, summary: (ctx) => visibilitySummary(ctx.node.visibility), openFor: () => false },
]);

function pageLayoutSummary(layout) {
  const l = asObject(layout);
  return [l.width, l.gap].filter(Boolean).join(' · ');
}

function layoutSummary(layout) {
  const l = asObject(layout);
  return [l.display, l.direction, l.gap].filter(Boolean).join(' · ');
}
function spacingSummary(style) {
  const parts = [];
  for (const [label, group] of [['m', 'margin'], ['p', 'padding']]) {
    const values = Object.values(asObject(style[group]));
    if (values.length) parts.push(`${label} ${values.every((v) => v === values[0]) ? values[0] : '…'}`);
  }
  return parts.join(' · ');
}
function identitySummary(attrs) {
  const a = asObject(attrs);
  return [a.id ? `#${a.id}` : '', a.role, a['aria-label'] ? 'aria-label' : ''].filter(Boolean).join(' · ');
}
function dataAttributeSummary(attrs) {
  const n = Object.keys(asObject(attrs)).filter((k) => k.startsWith('data-')).length;
  return n ? String(n) : '';
}
function alignSummary(a) {
  const o = asObject(a);
  return typeof a === 'string' ? a : (o.base || '');
}
function typographySummary(style) {
  const t = asObject(style.typography);
  return [t.size, t.weight, typeof style.color === 'string' ? style.color : t.color].filter(Boolean).join(' · ');
}
function backgroundSummary(bg) {
  if (typeof bg === 'string') return bg;
  const b = asObject(bg);
  if (b.image && typeof b.image === 'object') return 'image';
  if (b.gradient) return 'gradient';
  return b.color || '';
}
function borderSummary(border) {
  const b = asObject(border);
  return [b.width, b.style, b.radius].filter(Boolean).join(' · ');
}
function dimensionsSummary(d) {
  const o = asObject(d);
  return [o.width, o.min_height, o.max_width].filter(Boolean).join(' · ');
}
function motionSummary(node) {
  const a = asObject(node.animation).type;
  const trigger = asObject(node.interactions).trigger;
  return [a && a !== 'none' ? a.replace(/_/g, ' ') : '', trigger].filter(Boolean).join(' · ');
}
function visibilitySummary(v) {
  const o = asObject(v);
  const devices = asList(o.devices);
  const parts = [];
  if (devices.length === 0 && 'devices' in o) parts.push('hidden');
  else if (devices.length > 0 && devices.length < 4) parts.push(devices.join(' '));
  if (o.auth_state && o.auth_state !== 'any') parts.push(o.auth_state);
  return parts.join(' · ');
}

/** Everything a section needs to decide for itself, built once per selected block. */
export function inspectorContext(node, def) {
  return {
    node,
    def,
    style: asObject(node.style),
    capabilities: asList(def && def.style_capabilities),
  };
}

/** The sections of one tab that apply to this block, in display order. */
export function sectionsFor(tab, ctx, list = SECTIONS) {
  return list.filter((s) => tabOf(s, ctx) === tab && s.appliesTo(ctx));
}

/** Every applicable section across the tabs, grouped. */
export function applicableSections(ctx, list = SECTIONS) {
  return TABS.map((tab) => ({ tab, sections: sectionsFor(tab, ctx, list) })).filter((g) => g.sections.length > 0);
}

/**
 * The ids that start open for a tab: the ones the registry marks relevant for this block, or - when none is -
 * the first section, so a tab never opens as a wall of closed headers.
 */
export function defaultOpenIds(tab, ctx, registry = SECTIONS) {
  const list = sectionsFor(tab, ctx, registry);
  const flagged = list.filter((s) => s.openFor(ctx)).map((s) => s.id);
  if (flagged.length || list.length === 0) return flagged;
  return [list[0].id];
}

/**
 * Sections whose title (or summary) contains the query, across all tabs. An empty query returns [].
 * `title(s)` turns a section into its displayed title (the caller owns translation).
 */
export function searchSections(ctx, query, title, registry = SECTIONS) {
  const q = String(query || '').trim().toLowerCase();
  if (!q) return [];
  const out = [];
  for (const { sections } of applicableSections(ctx, registry)) {
    for (const s of sections) {
      if (title(s).toLowerCase().includes(q) || s.summary(ctx).toLowerCase().includes(q) || (s.keywords || []).some((k) => k.includes(q))) out.push(s);
    }
  }
  return out;
}

// ── Open / closed state (this editing session only) ─────────────────────────

const overrides = new Map();
const listeners = new Set();

const key = (type, id) => `${type}:${id}`;

/** Whether a section is open: the author's last choice for this block TYPE, else the registry default. */
export function isSectionOpen(type, id, fallback) {
  const k = key(type, id);
  return overrides.has(k) ? overrides.get(k) : fallback;
}

export function setSectionOpen(type, id, open) {
  overrides.set(key(type, id), !!open);
  listeners.forEach((l) => l());
}

export function subscribeSections(listener) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

/** Test hook and "reset layout": forget every choice. */
export function resetSectionState() {
  overrides.clear();
  listeners.forEach((l) => l());
}
