// Tiny builder for canonical Kohevo Studio documents (sections > blocks), deterministic ids.
import { createHash } from 'node:crypto';
let seed = 'fh';
let n = 0;
export const setSeed = (s) => { seed = s; n = 0; };
const hid = (p) => p + '_' + createHash('sha256').update(`${seed}:${p}:${++n}`).digest('hex').slice(0, 24);
export const DEVICES = ['base', 'sm', 'md', 'lg'];
export function blk(type, props = {}, o = {}) {
  const { children = [], classNames, style = {}, tag, attributes, responsive, animation, label } = o;
  const b = {
    id: hid('blk'), type, version: 1, props,
    style: { align: { base: 'left' }, font_token: null, radius_token: null, shadow_token: null, spacing_token: null, surface_token: null, text_token: null, ...style },
    visibility: { auth_state: 'any', devices: DEVICES }, bindings: {}, children,
  };
  if (classNames) b.classNames = Array.isArray(classNames) ? classNames : classNames.split(/\s+/).filter(Boolean);
  if (tag) b.tag = tag;
  if (attributes) b.attributes = attributes;
  if (responsive) b.responsive = responsive;
  if (animation) b.animation = animation;
  if (label) b.metadata = { label };
  return b;
}
export function sec(label, blocks, layout = {}, o = {}) {
  const s = {
    id: hid('sec'), label, global_ref: null,
    layout: { background_token: 'surface.primary', columns: { base: 1, md: 12 }, gap: 'md', padding_y: { base: 'md', md: 'lg' }, width: 'wide', ...layout },
    visibility: { auth_state: 'any', devices: DEVICES }, blocks,
  };
  if (o.classNames) s.classNames = o.classNames;
  if (o.animation) s.animation = o.animation;
  return s;
}
// shorthands
export const text = (content, o = {}) => blk('core.text', { content, size: o.size || 'base', align: o.align || 'left' }, o);
export const h = (level, t, o = {}) => blk('core.heading', { text: t, level, ...(o.highlight ? { highlight: o.highlight } : {}) }, o);
export const btn = (label, href, o = {}) => blk('core.button', { link: { label, href }, variant: o.variant || 'primary', size: o.size || 'md' }, o);
export const link = (label, href, o = {}) => blk('core.link', { link: { label, href }, style: o.linkStyle || 'plain', align: o.align || 'left' }, { ...o, style: undefined });
export const box = (children, o = {}) => blk('layout.container', { width: o.width || 'full', alignment: o.alignment || 'left', padding: o.padding || 'none' }, { ...o, classNames: o.classNames || 'grp', children });
export const flex = (children, p = {}, o = {}) => blk('layout.flex', { direction: 'row', wrap: 'nowrap', justify: 'start', align: 'start', gap: 'md', ...p }, { ...o, children });
export const grid = (children, p = {}, o = {}) => blk('layout.grid', { columns: 2, gap: 'md', align: 'stretch', ...p }, { ...o, children });
export const list = (items, o = {}) => blk('core.list', { style: o.listStyle || 'none', items: items.map((t) => ({ text: t })) }, o);
export const rich = (html, o = {}) => blk('core.rich_text', { content: html }, o);
