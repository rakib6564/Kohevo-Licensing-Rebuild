// Element Manager helpers: the server lists every block with its usage; the author switches types off or on
// and saves the whole list. Pure functions, shared by the panel and the tests.

import { asList } from './doc.mjs';
import { BLOCK_CATEGORY_ORDER, categoryRank } from './addPanel.mjs';

/** The set of switched-off types the server last reported. */
export const disabledSet = (elements) => new Set(asList(elements).filter((e) => e.disabled).map((e) => e.type));

/** Elements grouped by category in the Add panel's order, filtered by a search over title, type and category. */
export function groupElements(elements, query = '', categoryLabel = (c) => c) {
  const q = String(query || '').trim().toLowerCase();
  const list = asList(elements).filter((e) => !q || `${e.title} ${e.type} ${categoryLabel(e.category)}`.toLowerCase().includes(q));
  const byCat = new Map();
  for (const e of list) {
    if (!byCat.has(e.category)) byCat.set(e.category, []);
    byCat.get(e.category).push(e);
  }
  return [...byCat.entries()]
    .sort((a, b) => categoryRank(a[0]) - categoryRank(b[0]) || String(a[0]).localeCompare(String(b[0])))
    .map(([category, items]) => ({ category, items: items.slice().sort((x, y) => String(x.title).localeCompare(String(y.title))) }));
}

export const usageOf = (element) => {
  const u = (element && element.usage) || {};
  return { blocks: Number(u.blocks) || 0, pages: Number(u.pages) || 0, sample: asList(u.sample) };
};

/** Types nobody uses yet (safe to switch off without touching a page). */
export const unusedTypes = (elements) => asList(elements).filter((e) => usageOf(e).blocks === 0).map((e) => e.type);

/** Whether the working selection differs from what the server has. */
export function isChanged(elements, selection) {
  const saved = disabledSet(elements);
  if (saved.size !== selection.size) return true;
  for (const type of selection) if (!saved.has(type)) return true;
  return false;
}

/** Switched-off types that are still in use somewhere: the author is told those pages keep their blocks. */
export const disabledInUse = (elements, selection) => asList(elements).filter((e) => selection.has(e.type) && usageOf(e).blocks > 0);

export { BLOCK_CATEGORY_ORDER };
