// In-place text editing on the canvas, driven by what each block DECLARES (`inline_text` in the manifest).
//
// A declaration names the prop an element renders and the class selector of that element, so the canvas never
// guesses from tag names ("the first <p>") or prop names ("text, else title, else label"). A block that
// declares nothing is not editable in place; an author uses the Inspector for it.

import { asList, asObject, blockDefinition } from './doc.mjs';
import { getPath, setPath } from './styleSurface.mjs';

/** The inline text declarations of a block: [{prop, selector, multiline}], or []. */
export function inlineSpecsFor(manifest, node) {
  const def = node && node.type ? blockDefinition(manifest, node.type) : null;
  return asList(def && def.inline_text).filter((s) => s && typeof s.prop === 'string' && typeof s.selector === 'string');
}

/**
 * The declared element under `target` (or, with no target, the first declared element that exists) inside a block's
 * canvas element. Returns {spec, el} or null.
 */
export function resolveInlineTarget(nodeEl, specs, target = null) {
  if (!nodeEl || typeof nodeEl.querySelector !== 'function') return null;
  for (const spec of specs) {
    const el = nodeEl.querySelector(spec.selector);
    if (!el) continue;
    if (target === null || el === target || (typeof el.contains === 'function' && el.contains(target))) return { spec, el };
  }
  return null;
}

/** `props` with the declared prop set to `text` (a `link.label` prop writes into the link object). */
export function propsWithInlineText(props, prop, text) {
  return setPath(asObject(props), prop, text);
}

/** The current value of a declared prop (for tests and for "did it change?"). */
export function inlineTextValue(props, prop) {
  const v = getPath(asObject(props), prop);
  return typeof v === 'string' ? v : '';
}
