// Theme token picker helpers: friendly names and groups, so the picker shows "Primary surface" under
// "Surfaces" with a colour chip instead of `surface.primary` under `surface`.

import { asList } from './doc.mjs';
import { t } from './messages.mjs';
import { splitRef, tokenNameKey } from './siteSettings.mjs';
import { humanize } from './optionLabels.mjs';

export const COLOR_CATS = Object.freeze(['surface', 'text', 'color', 'border']);

/** The author-facing name of a token; a token the pack doesn't know is humanised, never shown as its ref. */
export function tokenLabel(ref) {
  const key = tokenNameKey(ref);
  const text = t(key);
  if (text && text !== key) return text;
  const { category, name } = splitRef(ref);
  return humanize(name || category);
}

/** The heading a category of tokens sits under. */
export function categoryHeading(category) {
  for (const key of [`ss_cat_${category}`, `tok_cat_${category}`]) {
    const text = t(key);
    if (text && text !== key) return text;
  }
  return humanize(category);
}

/** Tokens grouped by category, in the order the categories first appear in `tokens`. */
export function groupForPicker(tokens) {
  const groups = [];
  for (const tk of asList(tokens)) {
    let g = groups.find((x) => x.category === tk.category);
    if (!g) { g = { category: tk.category, heading: categoryHeading(tk.category), items: [] }; groups.push(g); }
    g.items.push({ ref: tk.ref, label: tokenLabel(tk.ref), value: tk.value ?? '', category: tk.category });
  }
  return groups;
}

/** What a sample of a token looks like: a CSS declaration for the chip, by category. */
export function sampleStyle(category, value) {
  const v = String(value || '');
  if (COLOR_CATS.includes(category)) return { background: v };
  if (category === 'radius') return { borderRadius: v.split(/\s+/)[0] || '0', border: '2px solid currentColor', background: 'transparent' };
  if (category === 'shadow') return { boxShadow: v === 'none' ? 'none' : v };
  if (category === 'font') return { fontFamily: v };
  if (category === 'space') return { width: v.split(/\s+/)[0] || '0' };
  return {};
}
