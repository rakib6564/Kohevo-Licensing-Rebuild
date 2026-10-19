// In-place text editing on the canvas, driven by what each block DECLARES (`inline_text` in the manifest).
//
// A declaration names the prop an element renders and the class selector of that element, so the canvas never
// guesses from tag names ("the first <p>") or prop names ("text, else title, else label"). A block that
// declares nothing is not editable in place; an author uses the Inspector for it.

import { asList, asObject, blockDefinition } from './doc.mjs';
import { getPath, setPath } from './styleSurface.mjs';

/**
 * The inline text declarations of a block: [{prop, selector, multiline, unless?}], or []. A declaration with `unless`
 * is dropped while that prop is set (a nav menu chosen on the Menus screen shows that menu's links, not the block's).
 */
export function inlineSpecsFor(manifest, node) {
  const def = node && node.type ? blockDefinition(manifest, node.type) : null;
  const props = asObject(node && node.props);
  return asList(def && def.inline_text).filter((s) => s && typeof s.prop === 'string' && typeof s.selector === 'string'
    && !(typeof s.unless === 'string' && props[s.unless] !== undefined && props[s.unless] !== null && props[s.unless] !== ''));
}

/** `items.*.label` → ['items', 'label']: a field of every repeater item, or null for a plain prop. */
const itemPath = (prop) => {
  const m = /^([a-z][a-z0-9_]*)\.\*\.([a-z][a-z0-9_]*)$/i.exec(String(prop));
  return m ? [m[1], m[2]] : null;
};

/** `items.2.label` → ['items', 2, 'label'], or null. */
const indexedPath = (prop) => {
  const m = /^([a-z][a-z0-9_]*)\.(\d+)\.([a-z][a-z0-9_]*)$/i.exec(String(prop));
  return m ? [m[1], Number(m[2]), m[3]] : null;
};

/**
 * The declared element under `target` (or, with no target, the first declared element that exists) inside a block's
 * canvas element. Returns {spec, el} or null.
 */
export function resolveInlineTarget(nodeEl, specs, target = null) {
  if (!nodeEl || typeof nodeEl.querySelector !== 'function') return null;
  for (const spec of specs) {
    // A repeater field: the n-th matching element renders item n, so the hit names that item (`items.2.label`).
    if (itemPath(spec.prop) && typeof nodeEl.querySelectorAll === 'function') {
      const els = Array.from(nodeEl.querySelectorAll(spec.selector));
      const i = target === null ? (els.length ? 0 : -1)
        : els.findIndex((el) => el === target || (typeof el.contains === 'function' && el.contains(target)));
      if (i >= 0) return { spec: { ...spec, prop: spec.prop.replace('.*.', `.${i}.`) }, el: els[i] };
      continue;
    }
    const el = nodeEl.querySelector(spec.selector);
    if (!el) continue;
    if (target === null || el === target || (typeof el.contains === 'function' && el.contains(target))) return { spec, el };
  }
  return null;
}

/**
 * `props` with the declared prop set to `text`: a `link.label` prop writes into the link object, and `items.2.label`
 * into that item of the list (which stays a list). An index past the end changes nothing.
 */
export function propsWithInlineText(props, prop, text) {
  const base = asObject(props);
  const indexed = indexedPath(prop);
  if (indexed) {
    const [list, i, field] = indexed;
    const items = asList(base[list]);
    if (i >= items.length) return base;
    return { ...base, [list]: items.map((item, n) => (n === i ? { ...asObject(item), [field]: text } : item)) };
  }
  return setPath(base, prop, text);
}

/** The current value of a declared prop (for tests and for "did it change?"). */
export function inlineTextValue(props, prop) {
  const indexed = indexedPath(prop);
  const v = indexed ? asObject(asList(asObject(props)[indexed[0]])[indexed[1]])[indexed[2]] : getPath(asObject(props), prop);
  return typeof v === 'string' ? v : '';
}
