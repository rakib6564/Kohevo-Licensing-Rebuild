// Choosing a theme token for a colour or spacing field, as plain style → style functions.
//
// The renderer paints a token only from its own key (`text_token`, `surface_token`, `spacing_token`: a utility class that
// reads the theme's custom property). A token ref typed into `typography.color` or `background.color` is skipped, so
// the picker writes those keys, and it removes the literal they would otherwise fight with (a literal is emitted
// inline or in the block's own rule, and would win over the class). Picking a literal removes the token again.

import { asObject } from './doc.mjs';

const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

function withToken(style, key, ref) {
  const next = { ...style };
  if (ref) next[key] = ref; else delete next[key];
  return next;
}

/** A theme text colour (or none): the literal text colour, wherever it is written, goes. */
export function setTextToken(style, ref) {
  const next = withToken(style, 'text_token', ref || null);
  if (ref) {
    delete next.color;
    if (isObj(next.typography)) {
      const typo = { ...next.typography };
      delete typo.color;
      if (Object.keys(typo).length === 0) delete next.typography; else next.typography = typo;
    }
  }
  return next;
}

/** A literal text colour (or none): it lives in `typography.color`, an older flat `color` is folded into it, the token goes. */
export function setTextLiteral(style, value) {
  const next = { ...style };
  delete next.color;
  delete next.text_token;
  const typo = { ...asObject(style.typography) };
  if (value === undefined) delete typo.color; else typo.color = value;
  if (Object.keys(typo).length === 0) delete next.typography; else next.typography = typo;
  return next;
}

/** A theme surface colour (or none): the literal background colour goes; an image or gradient stays. */
export function setSurfaceToken(style, ref) {
  const next = withToken(style, 'surface_token', ref || null);
  if (ref) {
    if (isObj(next.background)) {
      const bg = { ...next.background };
      delete bg.color;
      if (Object.keys(bg).length === 0) delete next.background; else next.background = bg;
    } else if (typeof next.background === 'string' && !/gradient\(/i.test(next.background)) {
      delete next.background;
    }
  }
  return next;
}

/** A theme padding (or none): the four literal paddings go. */
export function setSpacingToken(style, ref) {
  const next = withToken(style, 'spacing_token', ref || null);
  if (ref) delete next.padding;
  return next;
}

/** A theme border colour (or none): the literal border colour goes. */
export function setBorderToken(style, ref) {
  const next = withToken(style, 'border_token', ref || null);
  if (ref && isObj(next.border)) {
    const border = { ...next.border };
    delete border.color;
    if (Object.keys(border).length === 0) delete next.border; else next.border = border;
  }
  return next;
}

/** A literal border colour (or none): the theme border colour goes. */
export function setBorderLiteral(style, value) {
  const next = { ...style };
  delete next.border_token;
  const border = { ...asObject(style.border) };
  if (value === undefined) delete border.color; else border.color = value;
  if (Object.keys(border).length === 0) delete next.border; else next.border = border;
  return next;
}
