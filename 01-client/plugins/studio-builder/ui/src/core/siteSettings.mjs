// Site settings: the design tokens grouped the way an author thinks about them (colours, fonts, corners,
// shadows, spacing) rather than as one flat list. Pure helpers; the panel and the dialog share them.

import { asList } from './doc.mjs';

/** Display order. `categories` are the server's token categories; `swatch` groups draw a colour chip. */
export const SITE_GROUPS = Object.freeze([
  { id: 'colors', titleKey: 'ss_group_colors', categories: ['surface', 'text', 'color', 'border'], swatch: true },
  { id: 'fonts', titleKey: 'ss_group_fonts', categories: ['font'], fonts: true },
  { id: 'shape', titleKey: 'ss_group_shape', categories: ['radius'] },
  { id: 'shadows', titleKey: 'ss_group_shadows', categories: ['shadow'] },
  { id: 'spacing', titleKey: 'ss_group_spacing', categories: ['space'] },
]);

/** Font stacks that pass the server's font pattern (letters, digits, space, comma, quotes, hyphen). */
export const FONT_PRESETS = Object.freeze([
  "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
  "Inter, system-ui, sans-serif",
  "Helvetica Neue, Arial, sans-serif",
  "Georgia, 'Times New Roman', serif",
  "Menlo, Consolas, monospace",
]);

/** Tokens split into the display groups; a category nobody listed lands in a trailing "other" group. */
export function groupTokens(tokens) {
  const list = asList(tokens);
  const known = new Set(SITE_GROUPS.flatMap((g) => g.categories));
  const groups = SITE_GROUPS.map((g) => ({
    ...g,
    tokens: g.categories.flatMap((c) => list.filter((tk) => tk.category === c)),
  })).filter((g) => g.tokens.length);
  const rest = list.filter((tk) => !known.has(tk.category));
  if (rest.length) groups.push({ id: 'other', titleKey: 'ss_group_other', categories: [], tokens: rest });
  return groups;
}

/** `surface.primary` -> { category: 'surface', name: 'primary' }. */
export function splitRef(ref) {
  const i = String(ref).indexOf('.');
  return i < 0 ? { category: '', name: String(ref) } : { category: ref.slice(0, i), name: ref.slice(i + 1) };
}

/** The message key for a token's friendly name, e.g. `ss_tk_surface_primary`; the caller falls back to the ref. */
export const tokenNameKey = (ref) => `ss_tk_${String(ref).replace(/[^a-z0-9]+/gi, '_')}`;
