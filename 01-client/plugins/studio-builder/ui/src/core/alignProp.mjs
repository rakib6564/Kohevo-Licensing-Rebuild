// Quote, Link, Text and Post Title have an older `align` prop beside the Style tab's Alignment. Style is the one control;
// these keep a block that only has the prop showing and editing what it really has.

import { asObject } from './doc.mjs';

export const ALIGN_PROP_TYPES = new Set(['core.text', 'core.quote', 'core.link', 'theme.post_title']);

/** The alignment per breakpoint. The prop counts only with no Style value; `left` is its default and paints nothing. */
export function effectiveAlign(props, style) {
  const align = asObject(style).align;
  const own = typeof align === 'string' ? { base: align } : asObject(align);
  if (Object.keys(own).length) return own;
  const prop = asObject(props).align;
  return prop && prop !== 'left' ? { base: prop } : null;
}

/** A centred or right prop sits on the element itself, so it would beat the Style value. */
export const alignPropBlocks = (props) => {
  const prop = asObject(props).align;
  return !!prop && prop !== 'left';
};
