// Add panel logic (B2-P2a): which blocks belong to which tab, how they group, whether a card can
// be inserted right now (and if not, why), and search across every tab. Pure; the components only
// render what this returns. Insertion rules mirror the server (limits and parent/child rules from
// the manifest); the server still refuses anything the UI should have disabled.

import { asList, countBlocks, sectionsOf } from './doc.mjs';
import { canContain, canInsertBlock, canInsertSection, insertionPoint, isGlobalSection } from './doc.mjs';
import { isDynamicBlock } from '../components/blockKinds.mjs';

/** Block categories in display order (manifest `category`); unknown ones sort last. */
export const BLOCK_CATEGORY_ORDER = ['layout', 'content', 'media', 'forms', 'business', 'advanced', 'theme'];

/** Cards shown per category before "View all". */
export const GROUP_PREVIEW = 6;

export const categoryRank = (c) => { const i = BLOCK_CATEGORY_ORDER.indexOf(c); return i === -1 ? BLOCK_CATEGORY_ORDER.length : i; };

/** A block this site has not switched off (Element Manager). A disabled block stays in the manifest so existing blocks still edit. */
export const isOffered = (def) => !!def && def.disabled !== true && def.locked !== true;

/** A block whose module is not active or licensed here: listed (greyed, with the reason), never insertable. */
export const isLocked = (def) => !!def && def.locked === true;

/** Kohevo component blocks (module-backed): the Components tab. */
export const isComponentBlock = (def) => !!def && def.category === 'business';

/** Plain building blocks: the Elements tab (everything that is neither data-bound nor a Kohevo component). */
export const isElementBlock = (def) => !!def && !isDynamicBlock(def) && !isComponentBlock(def);

/**
 * Add-panel cards for the manifest's variants (a ready-set configuration of an existing block type).
 * Each card is the real block's definition with the variant's copy, icon, category and props, so
 * entitlement, limits and insertion rules are the block's own.
 */
export function variantCards(manifest) {
  const defs = new Map(asList(manifest && manifest.blocks).map((b) => [b.type, b]));
  return asList(manifest && manifest.variants)
    .filter((v) => defs.has(v.type) && isOffered(defs.get(v.type)))
    .map((v) => ({ ...defs.get(v.type), title: v.title, label: v.title, description: v.description, icon: v.icon, category: v.category, variantKey: v.key, variantProps: v.props }));
}

/** Blocks plus their variants: what the Elements tab and the cross-tab search list. */
export const blocksWithVariants = (manifest) => [...asList(manifest && manifest.blocks).filter(isOffered), ...variantCards(manifest)];

const titleOf = (b) => b.title || b.label || b.type;

/** Text a block is searched by. */
export const blockSearchText = (b, categoryLabel = (c) => c) => `${titleOf(b)} ${b.description || ''} ${categoryLabel(b.category)} ${b.type}`.toLowerCase();

/** Group blocks by category (display order), filtered by query. @returns {{category:string,items:object[]}[]} */
export function groupBlocks(blocks, query = '', categoryLabel = (c) => c) {
  const q = String(query || '').trim().toLowerCase();
  const list = asList(blocks).filter((b) => !q || blockSearchText(b, categoryLabel).includes(q));
  const groups = new Map();
  for (const b of [...list].sort((a, z) => categoryRank(a.category) - categoryRank(z.category))) {
    if (!groups.has(b.category)) groups.set(b.category, []);
    groups.get(b.category).push(b);
  }
  return [...groups.entries()].map(([category, items]) => ({ category, items }));
}

/**
 * Can a block of `type` be inserted right now, given the selection?
 * @returns {{ok:true}|{ok:false,reason:'blocks_limit'|'sections_limit'|'not_allowed'}}
 */
export function blockInsertState(doc, manifest, selectedId, type) {
  const def = asList(manifest.blocks).find((b) => b.type === type);
  if (def && def.disabled === true) return { ok: false, reason: 'disabled' };
  if (def && def.locked === true) return { ok: false, reason: 'locked' };
  const maxBlocks = (manifest.limits && manifest.limits.max_blocks) || 250;
  if (countBlocks(doc) >= maxBlocks) return { ok: false, reason: 'blocks_limit' };
  const where = insertionPoint(doc, manifest, selectedId, type);
  if (!where) return canInsertSection(doc, manifest) ? { ok: true } : { ok: false, reason: 'sections_limit' }; // a new section is created
  return canInsertBlock(doc, manifest, where.parentId, type) ? { ok: true } : { ok: false, reason: 'not_allowed' };
}

/** A preset inserts whole sections, so only the section limit applies. */
export function presetInsertState(doc, manifest) {
  return canInsertSection(doc, manifest) ? { ok: true } : { ok: false, reason: 'sections_limit' };
}

export const reasonKey = (reason) => `pal_reason_${reason}`;

/**
 * Search every tab at once. Elements and components come from the manifest blocks; presets and
 * global components come from the library. Empty query returns empty lists (tabs show instead).
 */
export function searchAll({ blocks: allBlocks, presets, components }, query, labels = {}) {
  const blocks = asList(allBlocks).filter(isOffered);
  const q = String(query || '').trim().toLowerCase();
  if (!q) return { presets: [], elements: [], dynamic: [], components: [], total: 0 };
  const catLabel = labels.blockCategory || ((c) => c);
  const presetLabel = labels.presetCategory || ((c) => c);
  const outPresets = asList(presets).filter((p) => `${p.name} ${p.description || ''} ${presetLabel(p.category)}`.toLowerCase().includes(q));
  const elements = asList(blocks).filter(isElementBlock).filter((b) => blockSearchText(b, catLabel).includes(q));
  const dynamic = asList(blocks).filter((b) => isDynamicBlock(b) && !isComponentBlock(b)).filter((b) => blockSearchText(b, catLabel).includes(q));
  const comps = [
    ...asList(blocks).filter(isComponentBlock).filter((b) => blockSearchText(b, catLabel).includes(q)).map((b) => ({ kind: 'block', block: b })),
    ...asList(components).filter((c) => `${c.title || ''} ${c.slug || ''}`.toLowerCase().includes(q)).map((c) => ({ kind: 'global', component: c })),
  ];
  return { presets: outPresets, elements, dynamic, components: comps, total: outPresets.length + elements.length + dynamic.length + comps.length };
}

export { canContain, isGlobalSection, sectionsOf };
