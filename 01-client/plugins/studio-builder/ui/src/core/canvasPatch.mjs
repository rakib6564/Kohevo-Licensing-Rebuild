// Kohevo Studio builder — optimistic canvas patching.
//
// The canvas is the SERVER's Editor-mode render in a script-less iframe, and
// historically it reloaded on every confirmed revision — so a one-word edit
// meant a full frame reload, scroll jump included. This module patches the
// frame's DOM in place for the edits where the mapping is provable, and
// reports "cannot patch" for everything else so the caller falls back to the
// existing reload path.
//
// ── The rule this module is built on ──────────────────────────────────────
//
// NOTHING here guesses at renderer output. Each patch must be *self-validating*:
// we only touch a node when we can prove the element we found is the element the
// server would have written. Concretely:
//
//   • Classes / attributes — derived from a small, explicit list of tokens the
//     builder owns (motion classes, custom classNames, custom attributes, the
//     hide/align classes). These are pure add/remove of a known string, so
//     there is nothing to guess. We mirror `DocumentRenderer::hideClasses()`
//     exactly; anything we cannot mirror verbatim we leave alone.
//   • Text props — patched ONLY when the candidate element's text still equals
//     the OLD prop value. If it doesn't, we found the wrong element and bail.
//
// A patch is never persisted anywhere: the server still owns the document and
// re-applies the same operation. This only changes WHEN the author sees it.
//
// Property-level `style` (token resolution), `bindings`, and anything involving
// providers are deliberately NOT patched — they go through the renderer, whose
// output depends on theme tokens and entitlements we do not reimplement here.

const ANIMATION_PREFIX = 'sb-animate-';
const INTERACTION_PREFIX = 'sb-interaction-';
const HIDE_PREFIX = 'sb-hide-';
const ALIGN_PREFIX = 'sb-align-';
const BREAKPOINTS = ['base', 'sm', 'md', 'lg'];

const NODE_ATTR = 'data-sb-node';
const TRIGGER_ATTR = 'data-sb-interaction-trigger';
const MOTION_VAR_PREFIX = '--sb-anim-';

const EASINGS = new Set(['ease', 'ease-in', 'ease-out', 'linear', 'ease-in-out', 'cubic-bezier(.22,1,.36,1)']);

/**
 * Text-bearing props we know how to patch, and the tags their element may use.
 * Adding a prop here is a promise that `findTextHost` can locate its element —
 * which it verifies at patch time before writing anything.
 */
const TEXT_PROPS = [
  { prop: 'text', types: ['core.heading', 'core.paragraph', 'core.text', 'core.button'], tags: ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'a', 'button'] },
];

function isPlainObject(v) {
  return Boolean(v) && typeof v === 'object' && !Array.isArray(v);
}

function sameValue(a, b) {
  return JSON.stringify(a === undefined ? null : a) === JSON.stringify(b === undefined ? null : b);
}

function alignOf(block) {
  const style = isPlainObject(block.style) ? block.style : {};
  return isPlainObject(style.align) ? style.align : null;
}

/** Everything in `style` except `align` — the keys this module cannot patch. */
function styleExceptAlign(block) {
  const style = isPlainObject(block.style) ? block.style : {};
  const rest = { ...style };
  delete rest.align;
  return rest;
}

/** The tokens `DocumentRenderer` derives from one block, mirrored exactly. */
function derivedClasses(block) {
  const classes = new Set();

  const animType = isPlainObject(block.animation) ? block.animation.type : null;
  if (animType && animType !== 'none') classes.add(ANIMATION_PREFIX + String(animType).replace(/_/g, '-'));

  const trigger = isPlainObject(block.interactions) ? block.interactions.trigger : null;
  if (trigger) classes.add(INTERACTION_PREFIX + String(trigger).replace(/[_\s]/g, '-'));

  const devices = isPlainObject(block.visibility) && Array.isArray(block.visibility.devices)
    ? block.visibility.devices
    : null;
  if (devices) {
    // Mirrors DocumentRenderer::hideClasses() — a breakpoint is hidden only when
    // `visibility.devices` is an explicit array that omits it.
    BREAKPOINTS.forEach((bp) => { if (!devices.includes(bp)) classes.add(HIDE_PREFIX + bp); });
  }

  const align = alignOf(block);
  if (align) {
    Object.entries(align).forEach(([bp, value]) => {
      if (typeof value === 'string' && value) classes.add(`${ALIGN_PREFIX}${bp}-${value}`);
    });
  }

  const custom = Array.isArray(block.classNames) ? block.classNames : [];
  custom.forEach((c) => { if (typeof c === 'string' && c) classes.add(c); });

  return classes;
}

/** Attributes the builder owns on the block element. */
function derivedAttrs(block) {
  const attrs = {};
  const trigger = isPlainObject(block.interactions) ? block.interactions.trigger : null;
  if (trigger) attrs[TRIGGER_ATTR] = String(trigger);

  if (isPlainObject(block.attributes)) {
    Object.entries(block.attributes).forEach(([k, v]) => {
      // Same shape the renderer accepts: a valid attribute name, value stringified.
      if (/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(k)) attrs[k] = String(v);
    });
  }
  return attrs;
}

/**
 * Per-block motion custom properties, mirroring
 * `DocumentRenderer::motionVariables()`. Returned as a full set so the caller can
 * replace them wholesale rather than diffing inline CSS strings.
 */
export function motionVars(block) {
  const out = {};
  const anim = isPlainObject(block.animation) && block.animation.type !== 'none' ? block.animation : null;
  const hover = isPlainObject(block.interactions) && isPlainObject(block.interactions.animation)
    && block.interactions.animation.type !== 'none' ? block.interactions.animation : null;

  [anim, hover].forEach((a) => {
    if (!a) return;
    if (a.duration_ms !== undefined && a.duration_ms !== null && Number.isFinite(Number(a.duration_ms))) {
      out[`${MOTION_VAR_PREFIX}duration`] = `${Math.max(0, Math.min(4000, Math.round(Number(a.duration_ms))))}ms`;
    }
    if (a.delay_ms !== undefined && a.delay_ms !== null && Number.isFinite(Number(a.delay_ms))) {
      out[`${MOTION_VAR_PREFIX}delay`] = `${Math.max(0, Math.min(4000, Math.round(Number(a.delay_ms))))}ms`;
    }
    if (typeof a.easing === 'string' && EASINGS.has(a.easing)) out[`${MOTION_VAR_PREFIX}easing`] = a.easing;
  });
  return out;
}

/**
 * Compute the patch for ONE node, or null when the change cannot be patched
 * safely and the caller must reload.
 *
 * @returns {{classes:{add:string[],remove:string[]}, attrs:{set:Object,remove:string[]},
 *            motionVars:Object, texts:Array}|null}
 */
export function computeNodePatch(prev, next) {
  if (!prev || !next) return null;
  if (prev.type !== next.type) return null; // a type change can change the whole subtree

  // Structural or provider-driven fields: never patched.
  if (!sameValue(prev.children, next.children)) return null;
  if (!sameValue(prev.bindings, next.bindings)) return null;
  if (!sameValue(prev.responsive, next.responsive)) return null;
  // Only `align` is patchable out of `style` (it is a plain class token). Every
  // OTHER style key goes through the renderer's token resolution, so a change
  // anywhere else in `style` must decline — comparing style-with-align-stripped
  // is what catches that, without also rejecting an align-only edit.
  if (!sameValue(styleExceptAlign(prev), styleExceptAlign(next))) return null;

  const before = derivedClasses(prev);
  const after = derivedClasses(next);
  const add = [...after].filter((c) => !before.has(c));
  const remove = [...before].filter((c) => !after.has(c));

  const beforeAttrs = derivedAttrs(prev);
  const afterAttrs = derivedAttrs(next);
  const attrs = { set: {}, remove: [] };
  Object.entries(afterAttrs).forEach(([k, v]) => {
    if (beforeAttrs[k] !== v) attrs.set[k] = v;
  });
  Object.keys(beforeAttrs).forEach((k) => {
    if (!(k in afterAttrs)) attrs.remove.push(k);
  });

  // Text props: located and verified at patch time, not here.
  const texts = [];
  const prevProps = isPlainObject(prev.props) ? prev.props : {};
  const nextProps = isPlainObject(next.props) ? next.props : {};
  TEXT_PROPS.forEach(({ prop, types, tags }) => {
    if (!types.includes(next.type)) return;
    if (sameValue(prevProps[prop], nextProps[prop])) return;
    if (typeof nextProps[prop] !== 'string' || typeof prevProps[prop] !== 'string') return;
    texts.push({ prop, from: prevProps[prop], to: nextProps[prop], tags });
  });

  // Any OTHER prop change we do not know how to render: reload.
  const known = new Set(TEXT_PROPS.map((x) => x.prop));
  const propKeys = new Set([...Object.keys(prevProps), ...Object.keys(nextProps)]);
  for (const k of propKeys) {
    if (known.has(k)) continue;
    if (!sameValue(prevProps[k], nextProps[k])) return null;
  }

  return { classes: { add, remove }, attrs, motionVars: motionVars(next), texts };
}

/** Apply a computed patch to one node element. Returns false if it could not. */
export function applyNodePatch(el, patch) {
  if (!el || !patch) return false;

  patch.classes.remove.forEach((c) => el.classList.remove(c));
  patch.classes.add.forEach((c) => el.classList.add(c));

  patch.attrs.remove.forEach((k) => el.removeAttribute(k));
  Object.entries(patch.attrs.set).forEach(([k, v]) => el.setAttribute(k, v));

  // Motion custom properties are replaced wholesale: these are the ONLY inline
  // custom properties we own, and a leftover --sb-anim-duration would keep
  // animating with a timing the author already removed.
  Array.from(el.style)
    .filter((prop) => prop.startsWith(MOTION_VAR_PREFIX))
    .forEach((prop) => el.style.removeProperty(prop));
  Object.entries(patch.motionVars || {}).forEach(([k, v]) => el.style.setProperty(k, v));

  for (const text of patch.texts) {
    const target = findTextHost(el, text);
    // Self-validation: only write when the element still holds the OLD value.
    // A mismatch means we guessed the wrong node, so the whole patch is refused.
    if (!target || target.textContent !== text.from) return false;
    target.textContent = text.to;
  }

  return true;
}

/**
 * Find the element that carries a block's text.
 *
 * A block renders as `<div class="sb-block">…</div>` wrapping the element that
 * actually holds the text, so for the common case BOTH the wrapper and its
 * single child read back as the same string. Matching the wrapper as well would
 * make every heading look ambiguous and no text edit would ever patch.
 *
 * So the rule is: a unique matching DESCENDANT wins; the element itself is only
 * used when there is no matching descendant AND it has no element children at
 * all (i.e. the text really is a direct child text node). Anything else —
 * several matching descendants, or a wrapper with other element children —
 * is refused rather than guessed.
 */
function findTextHost(el, { tags, from }) {
  const matches = [];
  tags.forEach((tag) => {
    const found = el.querySelectorAll(tag);
    for (let i = 0; i < found.length; i += 1) {
      if (found[i].textContent === from) matches.push(found[i]);
    }
  });

  if (matches.length === 1) return matches[0];
  if (matches.length > 1) return null; // genuinely ambiguous — refuse

  // No matching descendant: only safe when the text is this element's own.
  if (el.children && el.children.length === 0 && el.textContent === from) return el;
  return null;
}

/** All block nodes in a document, as a Map for O(1) comparison. */
export function indexBlocks(doc) {
  const map = new Map();
  const sections = doc && Array.isArray(doc.sections) ? doc.sections : [];
  sections.forEach((section) => {
    const walk = (blocks) => {
      (Array.isArray(blocks) ? blocks : []).forEach((b) => {
        if (b && typeof b.id === 'string') map.set(b.id, b);
        if (b && Array.isArray(b.children)) walk(b.children);
      });
    };
    walk(section && section.blocks);
  });
  return map;
}

function sectionSignature(doc) {
  const sections = doc && Array.isArray(doc.sections) ? doc.sections : [];
  return sections.map((s) => `${s.id}:${(s.blocks || []).length}`).join('|');
}

/**
 * Patch the canvas for the difference between two working documents.
 *
 * @returns {{patched:number, ids:string[], reload:boolean, reason:string}}
 */
export function patchCanvas(canvasDoc, prevDoc, nextDoc) {
  const none = { patched: 0, ids: [], reload: false, reason: '' };
  if (!canvasDoc || !canvasDoc.querySelector) return { ...none, reload: true, reason: 'no-canvas' };

  // Section shape (count / block counts) is the one thing a class patch can
  // never express. Anything structural reloads — same as before this phase.
  if (sectionSignature(prevDoc) !== sectionSignature(nextDoc)) {
    return { ...none, reload: true, reason: 'section-shape' };
  }

  const before = indexBlocks(prevDoc);
  const after = indexBlocks(nextDoc);
  if (before.size !== after.size) return { ...none, reload: true, reason: 'node-count' };

  const ids = [];
  for (const [id, nextBlock] of after) {
    const prevBlock = before.get(id);
    if (!prevBlock) return { ...none, reload: true, reason: 'node-set' };
    if (prevBlock === nextBlock) continue;

    const patch = computeNodePatch(prevBlock, nextBlock);
    if (!patch) return { ...none, reload: true, reason: 'unpatchable-field' };

    const el = canvasDoc.querySelector(`[${NODE_ATTR}="${cssEscape(id)}"]`);
    if (!el) continue; // not rendered here (e.g. a hidden block) — nothing to patch

    if (!applyNodePatch(el, patch)) {
      return { ...none, reload: true, reason: 'text-validation-failed' };
    }
    ids.push(id);
  }

  return { patched: ids.length, ids, reload: false, reason: '' };
}

function cssEscape(id) {
  return String(id).replace(/[^a-zA-Z0-9_-]/g, (c) => `\\${c}`);
}
