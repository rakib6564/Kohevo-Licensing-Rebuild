// A readable name for a stored option value ("md" -> "Medium", "half_screen" -> "Half screen"). Known values
// have translated messages (`opt_<value>`); anything else is humanised so the Inspector never shows a raw
// code word. The stored value itself is never changed: this is display only.

import { t } from './messages.mjs';

export const optionKey = (value) => `opt_${String(value).toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '')}`;

/** "arrow-right" -> "Arrow right", "16:9" -> "16:9", "index,follow" -> "Index, follow". */
export function humanize(value) {
  const text = String(value).replace(/[_-]+/g, ' ').replace(/,\s*/g, ', ').trim();
  return text ? text.charAt(0).toUpperCase() + text.slice(1) : '';
}

export function optionLabel(value) {
  if (value === null || value === undefined || value === '') return '';
  const key = optionKey(value);
  const text = t(key);
  return text && text !== key ? text : humanize(value);
}
