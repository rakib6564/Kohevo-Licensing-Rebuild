// Dynamic tags: which settings can take one, the tags each can take, and how the stored map (block.dynamic) changes.
//
// The server's DynamicTags owns the list and the rules; like the meta fields this list arrives once in the boot payload.

import { asObject } from './doc.mjs';

let tags = [];

/** `list` is boot.dynamicTags: [{tag, group, label, kinds, setting}]. */
export function setDynamicTags(list) {
  tags = (Array.isArray(list) ? list : []).filter((t) => t && typeof t.tag === 'string' && Array.isArray(t.kinds));
}

export const getDynamicTags = () => tags;

/** What a setting holds, as the tag kinds name it, or '' when it cannot take a tag. Mirrors DynamicTags::takesTags. */
export function dynamicKind(field) {
  if (!field || field.pattern || field.allowed_values || field.format || field.choices || field.key === 'highlight') return '';
  switch (field.type) {
    case 'string': case 'text': case 'rich_text': return 'text';
    case 'url': return 'url';
    case 'image': case 'media_ref': case 'media': return 'image';
    case 'number': return 'number';
    default: return '';
  }
}

/** The tags a kind of setting can take, grouped in the order the groups first appear: [{label, tags}]. */
export function tagGroups(kind, query = '', list = tags) {
  const q = query.trim().toLowerCase();
  const groups = new Map();
  for (const t of list) {
    if (!t.kinds.includes(kind) || (q && !`${t.group} ${t.label}`.toLowerCase().includes(q))) continue;
    if (!groups.has(t.group)) groups.set(t.group, { label: t.group, tags: [] });
    groups.get(t.group).tags.push(t);
  }
  return [...groups.values()];
}

export const tagOf = (tag, list = tags) => list.find((t) => t.tag === tag) || null;

/** The map with `path` set to a tag, or cleared by null. */
export function withTag(dynamic, path, config) {
  const { [path]: _old, ...rest } = asObject(dynamic);
  return config ? { ...rest, [path]: config } : rest;
}

/**
 * Tags on repeater rows follow their rows. `order` lists, for each new row, the old row it was (`rows.1.label` -> ...);
 * a row left out takes its tags with it.
 */
export function moveRowTags(dynamic, rowsPath, order) {
  const prefix = `${rowsPath}.`;
  const out = {};
  for (const [path, config] of Object.entries(asObject(dynamic))) {
    if (!path.startsWith(prefix)) { out[path] = config; continue; }
    const [index, ...rest] = path.slice(prefix.length).split('.');
    const at = order.indexOf(Number(index));
    if (at >= 0) out[[`${prefix}${at}`, ...rest].join('.')] = config;
  }
  return out;
}
