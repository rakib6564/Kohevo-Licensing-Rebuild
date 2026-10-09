// The real size behind a size word: "Medium" alone means 1rem for a Gap and 2rem for Padding, so the Inspector
// shows "Medium · 16px". The table (optionValues.json) is shared with the server parity test, which checks every row against the
// stylesheet the renderer actually emits, so a label can never claim a size the page does not use.

import { optionLabel } from './optionLabels.mjs';
import { t } from './messages.mjs';
import data from './optionValues.json' with { type: 'json' };

export const OPTION_VALUES = data;

/** ".75rem" -> "12px", "100vh" -> "full height", "none" -> "no limit", "0" -> "0". */
export function formatSize(raw, rootPx = 16) {
  const v = String(raw).trim();
  if (v === 'none' || v === '100%') return t('size_no_limit');
  if (v === '0') return '0';
  let m = /^(-?\d*\.?\d+)rem$/.exec(v);
  if (m) return `${Math.round(Number(m[1]) * rootPx * 100) / 100}px`;
  m = /^(-?\d*\.?\d+)vh$/.exec(v);
  if (m) return Number(m[1]) === 100 ? t('size_full_screen') : t('size_percent_screen', { n: Number(m[1]) });
  m = /^(-?\d*\.?\d+)vw$/.exec(v);
  if (m) return t('size_percent_width', { n: Number(m[1]) });
  return v;
}

/** The table name for a field ("core.card.padding" -> "card_padding"), or null for a field with no size words. */
export const tableFor = (fieldId) => OPTION_VALUES.fields[fieldId] || null;

/** "Medium · 16px" for a known size value of `fieldId`, otherwise the plain readable name. */
export function sizedLabel(fieldId, value) {
  const base = optionLabel(value);
  const table = tableFor(fieldId);
  const row = table && OPTION_VALUES.tables[table];
  const raw = row && Object.prototype.hasOwnProperty.call(row.values, value) ? row.values[value] : null;
  return raw === null ? base : `${base} · ${formatSize(raw)}`;
}

/** A label function bound to one field, for selects that take `labelOf`. */
export const sizedLabeller = (fieldId) => (value) => sizedLabel(fieldId, value);
