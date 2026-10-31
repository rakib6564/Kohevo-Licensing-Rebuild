// The site's widths (Site settings › Layout): the content width a boxed Container keeps to by default,
// and what Narrow, Normal and Wide mean on this site. The manifest carries the resolved tokens, so the Inspector labels
// a width with the size the page really uses ("Wide · 1170px"), not the platform's default.

import { asList } from './doc.mjs';
import { formatSize, sizedLabel } from './optionValues.mjs';
import { optionLabel } from './optionLabels.mjs';

/** Boxed width word => its Layout token. */
export const WIDTH_TOKENS = Object.freeze({ default: 'layout.content', narrow: 'layout.narrow', normal: 'layout.normal', wide: 'layout.wide' });

/** The site's value of a Layout token ("1170px"), or null when the manifest does not carry it. */
export function siteToken(manifest, ref) {
  const tk = asList(manifest && manifest.tokens).find((x) => x && x.ref === ref);
  return tk && typeof tk.value === 'string' && tk.value.trim() !== '' ? tk.value.trim() : null;
}

/** "Wide · 1170px" with this site's value; `full` and `custom` (and a manifest without tokens) keep the plain label. */
export function boxWidthLabel(manifest, width) {
  const ref = WIDTH_TOKENS[width];
  const value = ref ? siteToken(manifest, ref) : null;
  return value ? `${optionLabel(width)} · ${formatSize(value)}` : sizedLabel('container.width', width);
}

/** The site's content width as a bare pixel count ("1280"), for a size field's placeholder; null when it is not in px or rem. */
export function siteContentPx(manifest) {
  const shown = formatSize(siteToken(manifest, 'layout.content') || '80rem');
  const m = /^(\d+(?:\.\d+)?)px$/.exec(shown);
  return m ? m[1] : null;
}

/** What a boxed Container offers, in order: `default` is no `content_size`, and `custom` an own `content_max`. */
export const BOX_WIDTHS = Object.freeze(['default', 'narrow', 'normal', 'wide', 'full', 'custom']);

/** Which of BOX_WIDTHS a Container's `style.layout` says. */
export function boxWidthOf(layout) {
  const l = layout && typeof layout === 'object' ? layout : {};
  if (l.content_max !== undefined) return 'custom';
  return BOX_WIDTHS.includes(l.content_size) ? l.content_size : 'default';
}
