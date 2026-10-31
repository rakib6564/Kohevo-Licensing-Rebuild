// Kohevo Studio builder — canonical document operations.
//
// Every edit the builder makes is one of the server's `DocumentOperation`s
// ({op, payload}, the vocabulary of DocumentOperationApplier.php) — there is no
// second mutation protocol. `applyLocal` mirrors the server applier ONLY to
// show the edit optimistically; the result is never persisted from here. The
// server re-applies the same operation to its own copy, validates, normalizes
// and stores a revision, and the builder then replaces its copy with the
// server's document (reconciliation).
//
// Updates are immutable with structural sharing: only the path to the edited
// node is copied, so untouched blocks keep their identity and the
// UI re-renders just what changed.

import { CONTAINER_TYPE, asList, asObject } from './doc.mjs';
import { isLikelySafeUrl } from './fields.mjs';

export const OPS = Object.freeze({
  UPDATE_SETTINGS: 'update_settings',
  UPDATE_SEO: 'update_seo',
  INSERT_BLOCK: 'insert_block',
  REMOVE_BLOCK: 'remove_block',
  MOVE_BLOCK: 'move_block',
  DUPLICATE_BLOCK: 'duplicate_block',
  UPDATE_BLOCK_PROPS: 'update_block_props',
  UPDATE_BLOCK_STYLE: 'update_block_style',
  UPDATE_BLOCK_VISIBILITY: 'update_block_visibility',
  UPDATE_BLOCK_BINDINGS: 'update_block_bindings',
  UPDATE_BLOCK_RESPONSIVE: 'update_block_responsive',
  UPDATE_BLOCK_CLASS_NAMES: 'update_block_class_names',
  UPDATE_BLOCK_ATTRIBUTES: 'update_block_attributes',
  UPDATE_BLOCK_ANIMATION: 'update_block_animation',
  UPDATE_BLOCK_INTERACTIONS: 'update_block_interactions',
  UPDATE_BLOCK_META: 'update_block_meta',
  UPDATE_BLOCK_STYLE_STATES: 'update_block_style_states',
  UPDATE_BLOCK_STYLE_PARTS: 'update_block_style_parts',
  UPDATE_BLOCK_TAG: 'update_block_tag',
  UPDATE_BLOCK_CUSTOM_CSS: 'update_block_custom_css',
  RESET_BLOCK_STYLE_PROPERTY: 'reset_block_style_property',
});

/** Operations that change the tree's shape are sent right away, not debounced. */
export const STRUCTURAL = new Set([
  OPS.INSERT_BLOCK, OPS.REMOVE_BLOCK, OPS.MOVE_BLOCK, OPS.DUPLICATE_BLOCK,
]);

/** Whole-value replace updates (last write wins → consecutive ones coalesce). */
const REPLACE_TARGET = {
  [OPS.UPDATE_BLOCK_PROPS]: 'block_id',
  [OPS.UPDATE_BLOCK_STYLE]: 'block_id',
  [OPS.UPDATE_BLOCK_VISIBILITY]: 'block_id',
  [OPS.UPDATE_BLOCK_BINDINGS]: 'block_id',
  [OPS.UPDATE_BLOCK_RESPONSIVE]: 'block_id',
  [OPS.UPDATE_BLOCK_CLASS_NAMES]: 'block_id',
  [OPS.UPDATE_BLOCK_ATTRIBUTES]: 'block_id',
  [OPS.UPDATE_BLOCK_ANIMATION]: 'block_id',
  [OPS.UPDATE_BLOCK_INTERACTIONS]: 'block_id',
  [OPS.UPDATE_BLOCK_STYLE_STATES]: 'block_id',
  [OPS.UPDATE_BLOCK_STYLE_PARTS]: 'block_id',
  [OPS.UPDATE_BLOCK_TAG]: 'block_id',
};

export const DEFAULT_VISIBILITY = Object.freeze({ auth_state: 'any', devices: ['base', 'sm', 'md', 'lg'] });
export const DEFAULT_BLOCK_STYLE = Object.freeze({
  align: { base: 'left' }, font_token: null, radius_token: null, shadow_token: null,
  spacing_token: null, surface_token: null, text_token: null,
});
/** A new Container as the server's `CanonicalDocumentSchema::newContainer` writes one (the server mints the id). */
export function newContainer(overrides = {}) {
  return {
    type: CONTAINER_TYPE, tag: 'section', props: {}, style: { surface_token: 'surface.primary', layout: { content_width: 'boxed', gutter: 'site' } },
    visibility: DEFAULT_VISIBILITY, bindings: {}, children: [], ...overrides,
  };
}

/** A whole block for a subtree (a new Container's child): a subtree is stored as given, so nothing may be left to the server's defaults. */
export function newBlock(def, props = null, bindings = null) {
  return {
    type: def.type, version: def.version, props: props || asObject(def.default_props), style: DEFAULT_BLOCK_STYLE,
    visibility: DEFAULT_VISIBILITY, bindings: bindings || {}, children: [],
  };
}

// ── Builders ────────────────────────────────────────────────────────────────

const op = (name, payload) => ({ op: name, payload });

/** A new top-level Container, with the server's defaults. `overrides.children` carries a subtree in the same one step. */
export const insertContainer = (index, overrides = {}) => insertBlock(null, index, newContainer(overrides));
/** A LIVE reference to a global component: the Container owns no children; the server renders the component's published content. */
export const insertGlobalContainer = (index, ref, label = '') => insertBlock(null, index, { ...newContainer({ global_ref: ref }), ...(label ? { metadata: { label } } : {}) });
export const insertBlock = (parentId, index, block) => op(OPS.INSERT_BLOCK, { parent_id: parentId, index, block });
export const removeBlock = (blockId) => op(OPS.REMOVE_BLOCK, { block_id: blockId });
export const moveBlock = (blockId, parentId, index) => op(OPS.MOVE_BLOCK, { block_id: blockId, parent_id: parentId, index });
export const duplicateBlock = (blockId) => op(OPS.DUPLICATE_BLOCK, { block_id: blockId });
export const updateBlockProps = (blockId, props) => op(OPS.UPDATE_BLOCK_PROPS, { block_id: blockId, props });
export const updateBlockStyle = (blockId, style) => op(OPS.UPDATE_BLOCK_STYLE, { block_id: blockId, style });
export const updateBlockVisibility = (blockId, visibility) => op(OPS.UPDATE_BLOCK_VISIBILITY, { block_id: blockId, visibility });
export const updateBlockBindings = (blockId, bindings) => op(OPS.UPDATE_BLOCK_BINDINGS, { block_id: blockId, bindings });
export const updateBlockResponsive = (blockId, responsive) => op(OPS.UPDATE_BLOCK_RESPONSIVE, { block_id: blockId, responsive });
export const updateBlockClassNames = (blockId, classNames) => op(OPS.UPDATE_BLOCK_CLASS_NAMES, { block_id: blockId, classNames, class_names: classNames });
export const updateBlockAttributes = (blockId, attributes) => op(OPS.UPDATE_BLOCK_ATTRIBUTES, { block_id: blockId, attributes });

/**
 * Motion: `animation` is the entrance preset (a `DocumentValidator` enum of
 * fade_in / fade_up / fade_down / scale_up / slide_in / none) plus optional
 * per-block `duration_ms`, `delay_ms` and `easing`. `interactions` holds the
 * `trigger` and an optional hover `animation` under the same vocabulary.
 * Both payloads are whole-value replaces, so the inspector sends the complete
 * object every time and consecutive edits coalesce in the queue.
 */
export const updateBlockAnimation = (blockId, animation) => op(OPS.UPDATE_BLOCK_ANIMATION, { block_id: blockId, animation });
export const updateBlockInteractions = (blockId, interactions) => op(OPS.UPDATE_BLOCK_INTERACTIONS, { block_id: blockId, interactions });
/**
 * Patch a block's editor metadata: `label` (display name; null clears) and/or
 * `locked`. Keys left out are untouched, so these are NOT coalesced in the queue.
 */
export const updateBlockMeta = (blockId, meta) => op(OPS.UPDATE_BLOCK_META, { block_id: blockId, ...meta });
/** Replace a block's interaction states ({hover, focus, active, disabled}); `{}` clears them. */
export const updateBlockStyleStates = (blockId, styleStates) => op(OPS.UPDATE_BLOCK_STYLE_STATES, { block_id: blockId, style_states: styleStates });
/** The styles of a widget's parts (BlockParts.php): `{ <part>: partial, '<part>:hover': partial }`; `{}` clears them. */
export const updateBlockStyleParts = (blockId, styleParts) => op(OPS.UPDATE_BLOCK_STYLE_PARTS, { block_id: blockId, style_parts: styleParts });
/** Choose the block's wrapper element; null (or `div`) is the default. */
/** `link` ({url, new_tab}) is only for the `a` tag: where the linked wrapper points. */
export const updateBlockCustomCss = (blockId, css) => op(OPS.UPDATE_BLOCK_CUSTOM_CSS, { block_id: blockId, css });
export const updateBlockTag = (blockId, tag, link) => op(OPS.UPDATE_BLOCK_TAG, link ? { block_id: blockId, tag, link } : { block_id: blockId, tag });
/**
 * Remove ONE style property by dotted path (`typography.size`), from the block's style or, with `state`,
 * from one interaction state. Not coalesced: each reset is its own step.
 */
export const resetBlockStyleProperty = (blockId, property, state = null) => op(OPS.RESET_BLOCK_STYLE_PROPERTY, state ? { block_id: blockId, property, state } : { block_id: blockId, property });
export const updateSettings = (settings) => op(OPS.UPDATE_SETTINGS, { settings });
export const updateSeo = (seo) => op(OPS.UPDATE_SEO, { seo });

/**
 * The one operation that puts a new `block` ({type, props?, bindings?}) at `where` ({parentId, index}). Only a Container can
 * sit at the top level: a widget there gets its own, in the same operation, so the two are one undo step.
 */
export function insertOperation(def, where, block) {
  if (where.parentId !== null) return insertBlock(where.parentId, where.index, block);
  if (block.type === CONTAINER_TYPE) return insertContainer(where.index, block.props ? { props: block.props } : {});
  return insertContainer(where.index, { children: [newBlock(def, block.props, block.bindings)] });
}

/** Where the new block itself landed: inside the wrapping Container when `insertOperation` made one. */
export const insertedId = (where, type, provisionalId) => (where.parentId === null && type !== CONTAINER_TYPE ? `${provisionalId}_0` : provisionalId);

// ── Id bookkeeping ──────────────────────────────────────────────────────────

// Keys an inserted block may carry besides its core fields (the server keeps the same list).
const INSERT_BLOCK_EXTRAS = ['responsive', 'attributes', 'classNames', 'conditions', 'metadata', 'interactions', 'animation', 'style_states', 'style_parts', 'tag', 'global_ref'];

const ID_KEYS = ['block_id', 'parent_id'];

/** Ids an operation references (targets and parents). */
export function referencedIds(operation) {
  const p = asObject(operation && operation.payload);
  return ID_KEYS.filter((k) => typeof p[k] === 'string').map((k) => p[k]);
}

/** Rewrite provisional ids to server ids once the server has minted them. */
export function remapIds(operation, map) {
  const p = asObject(operation.payload);
  let changed = false;
  const next = { ...p };
  for (const k of ID_KEYS) {
    if (typeof p[k] === 'string' && map.has(p[k])) {
      next[k] = map.get(p[k]);
      changed = true;
    }
  }
  return changed ? { op: operation.op, payload: next } : operation;
}

export const isProvisionalId = (id) => typeof id === 'string' && id.startsWith('tmp_');

let tmpCounter = 0;
export function provisionalId(kind) {
  tmpCounter += 1;
  return `tmp_${kind}_${Date.now().toString(36)}${tmpCounter.toString(36)}`;
}

/**
 * Coalesce a new operation into the pending queue: a whole-value update of the
 * same target as the LAST queued operation replaces it (only the final value
 * matters, and it avoids sending every keystroke).
 */
export function enqueueCoalesced(queue, entry) {
  const key = REPLACE_TARGET[entry.op.op];
  const last = queue[queue.length - 1];
  // Settings / SEO are shallow merges on the server: consecutive patches merge.
  const merge = { [OPS.UPDATE_SETTINGS]: 'settings', [OPS.UPDATE_SEO]: 'seo' }[entry.op.op];
  if (merge && last && last.op.op === entry.op.op) {
    const payload = { [merge]: { ...asObject(last.op.payload[merge]), ...asObject(entry.op.payload[merge]) } };
    return [...queue.slice(0, -1), { ...entry, op: { op: entry.op.op, payload } }];
  }
  if (key && last && last.op.op === entry.op.op && asObject(last.op.payload)[key] === asObject(entry.op.payload)[key]) {
    return [...queue.slice(0, -1), { ...entry, label: entry.label || last.label }];
  }
  return [...queue, entry];
}

// ── Local (optimistic) application ──────────────────────────────────────────

/**
 * Apply one operation to a document copy. `ctx.provisionalId` names an
 * inserted node until the server mints its real id; `ctx.manifest` supplies
 * insert defaults. Throws on a missing target — the caller drops the edit.
 */
export function applyLocal(doc, operation, ctx = {}) {
  const p = asObject(operation.payload);
  switch (operation.op) {
    case OPS.UPDATE_SETTINGS:
      return { ...doc, settings: { ...asObject(doc.settings), ...asObject(p.settings) } };
    case OPS.UPDATE_SEO:
      return { ...doc, seo: { ...asObject(doc.seo), ...asObject(p.seo) } };
    case OPS.UPDATE_BLOCK_META: {
      let hit = false;
      const next = mapBlocks(doc, (b) => {
        if (b.id !== p.block_id) return b;
        hit = true;
        const meta = { ...asObject(b.metadata) };
        if ('label' in p) {
          const label = typeof p.label === 'string' ? p.label.trim() : '';
          if (label) meta.label = label; else delete meta.label;
        }
        if ('locked' in p) {
          if (p.locked === true) meta.locked = true; else delete meta.locked;
        }
        const { metadata: _old, ...rest } = b;
        return Object.keys(meta).length ? { ...rest, metadata: meta } : rest;
      });
      if (!hit) throw notFound(p.block_id);
      return next;
    }
    case OPS.INSERT_BLOCK: {
      const given = asObject(p.block);
      const def = ctx.manifest ? asList(ctx.manifest.blocks).find((b) => b.type === given.type) : null;
      // A preset or a pasted block carries a subtree and optional keys: keep them (descendants get provisional ids).
      const extras = {};
      for (const key of INSERT_BLOCK_EXTRAS) if (given[key] !== undefined) extras[key] = given[key];
      const block = {
        ...extras,
        bindings: Object.keys(asObject(given.bindings)).length ? given.bindings : {},
        children: def && def.allows_children ? asList(given.children).map((c, i) => cloneWithProvisionalIds(c, 'blk', ctx.provisionalId ? `${ctx.provisionalId}_${i}` : null)) : [],
        id: ctx.provisionalId || provisionalId('blk'),
        props: Object.keys(asObject(given.props)).length ? given.props : asObject(def && def.default_props),
        style: Object.keys(asObject(given.style)).length ? given.style : DEFAULT_BLOCK_STYLE,
        type: given.type,
        version: def ? def.version : 1,
        visibility: Object.keys(asObject(given.visibility)).length ? given.visibility : DEFAULT_VISIBILITY,
      };
      return insertInto(doc, p.parent_id, p.index, block);
    }
    case OPS.REMOVE_BLOCK: {
      const { doc: next, removed } = extract(doc, p.block_id);
      if (!removed) throw notFound(p.block_id);
      return next;
    }
    case OPS.MOVE_BLOCK: {
      const { doc: next, removed } = extract(doc, p.block_id);
      if (!removed) throw notFound(p.block_id);
      return insertInto(next, p.parent_id, p.index, removed);
    }
    case OPS.DUPLICATE_BLOCK: {
      let hit = false;
      const cloneBlockTree = (blocks) => {
        const out = [];
        for (const b of asList(blocks)) {
          out.push(b);
          if (b.id === p.block_id) {
            hit = true;
            out.push(cloneWithProvisionalIds(b, 'blk', ctx.provisionalId || null));
            continue;
          }
          if (b.children && b.children.length) {
            const nextKids = cloneBlockTree(b.children);
            if (hit) {
              out[out.length - 1] = { ...b, children: nextKids };
            }
          }
        }
        return out;
      };
      const containers = cloneBlockTree(doc.containers);
      if (!hit) throw notFound(p.block_id);
      return { ...doc, containers };
    }
    case OPS.UPDATE_BLOCK_PROPS:
    case OPS.UPDATE_BLOCK_STYLE:
    case OPS.UPDATE_BLOCK_VISIBILITY:
    case OPS.UPDATE_BLOCK_BINDINGS:
    case OPS.UPDATE_BLOCK_ANIMATION:
    case OPS.UPDATE_BLOCK_INTERACTIONS: {
      const field = operation.op.replace('update_block_', '');
      if (p[field] === null || typeof p[field] !== 'object') throw new Error(`${field} must be an object`); // DocumentOperationApplier::requireObject
      let hit = false;
      const next = mapBlocks(doc, (b) => {
        if (b.id !== p.block_id) return b;
        hit = true;
        return { ...b, [field]: asObject(p[field]) };
      });
      if (!hit) throw notFound(p.block_id);
      return next;
    }
    case OPS.UPDATE_BLOCK_RESPONSIVE: {
      let hit = false;
      const next = mapBlocks(doc, (b) => {
        if (b.id !== p.block_id) return b;
        hit = true;
        return { ...b, responsive: asObject(p.responsive) };
      });
      if (!hit) throw notFound(p.block_id);
      return next;
    }
    case OPS.UPDATE_BLOCK_CLASS_NAMES: {
      let hit = false;
      const next = mapBlocks(doc, (b) => {
        if (b.id !== p.block_id) return b;
        hit = true;
        return { ...b, classNames: asList(p.class_names) };
      });
      if (!hit) throw notFound(p.block_id);
      return next;
    }
    case OPS.UPDATE_BLOCK_ATTRIBUTES: {
      let hit = false;
      const next = mapBlocks(doc, (b) => {
        if (b.id !== p.block_id) return b;
        hit = true;
        return { ...b, attributes: asObject(p.attributes) };
      });
      if (!hit) throw notFound(p.block_id);
      return next;
    }
    case OPS.UPDATE_BLOCK_STYLE_STATES: {
      let hit = false;
      const next = mapBlocks(doc, (b) => {
        if (b.id !== p.block_id) return b;
        hit = true;
        const { style_states: _old, ...rest } = b;
        const states = asObject(p.style_states);
        return Object.keys(states).length ? { ...rest, style_states: states } : rest;
      });
      if (!hit) throw notFound(p.block_id);
      return next;
    }
    case OPS.UPDATE_BLOCK_STYLE_PARTS: {
      let hit = false;
      const next = mapBlocks(doc, (b) => {
        if (b.id !== p.block_id) return b;
        hit = true;
        const { style_parts: _old, ...rest } = b;
        const parts = asObject(p.style_parts);
        return Object.keys(parts).length ? { ...rest, style_parts: parts } : rest;
      });
      if (!hit) throw notFound(p.block_id);
      return next;
    }
    case OPS.UPDATE_BLOCK_TAG: {
      if (p.tag !== null && p.tag !== undefined && !BLOCK_TAGS.includes(p.tag)) throw new Error(`Invalid tag ${p.tag}`);
      const link = p.link ?? null;
      if (link !== null) {
        if (p.tag !== 'a') throw new Error('A link is only for the a tag');
        const issue = blockLinkIssue(link);
        if (issue) throw new Error(issue);
      }
      let hit = false;
      const next = mapBlocks(doc, (b) => {
        if (b.id !== p.block_id) return b;
        hit = true;
        const { tag: _old, link: _oldLink, ...rest } = b;
        const tagged = p.tag && p.tag !== 'div' ? { ...rest, tag: p.tag } : rest;
        return p.tag === 'a' && link ? { ...tagged, link } : tagged;
      });
      if (!hit) throw notFound(p.block_id);
      return next;
    }
    case OPS.RESET_BLOCK_STYLE_PROPERTY: {
      if (typeof p.property !== 'string' || !PROPERTY_PATH.test(p.property)) throw new Error('Invalid style property path');
      if (p.state != null && !STATES.includes(p.state)) throw new Error(`Invalid state ${p.state}`);
      const segments = p.property.split('.');
      if (!(p.state == null ? STYLE_KEYS : STATE_KEYS).includes(segments[0])) throw new Error(`${segments[0]} is not a resettable style property`);
      let hit = false;
      const next = mapBlocks(doc, (b) => {
        if (b.id !== p.block_id) return b;
        hit = true;
        if (p.state == null) return { ...b, style: unsetPath(asObject(b.style), segments) };
        const states = { ...asObject(b.style_states) };
        if (states[p.state]) {
          const partial = unsetPath(asObject(states[p.state]), segments);
          if (Object.keys(partial).length) states[p.state] = partial; else delete states[p.state];
        }
        const { style_states: _old, ...rest } = b;
        return Object.keys(states).length ? { ...rest, style_states: states } : rest;
      });
      if (!hit) throw notFound(p.block_id);
      return next;
    }
    default:
      throw new Error(`Unsupported operation ${operation.op}`);
  }
}

function clamp(index, length) {
  return Math.max(0, Math.min(Number.isInteger(index) ? index : length, length));
}

function notFound(id) {
  const err = new Error(`No node ${id} in the document`);
  err.code = 'operation_target_not_found';
  return err;
}

/** Map every block (depth-first), copying only the paths that changed. */
// Mirrors CanonicalDocumentSchema::ALLOWED_BLOCK_TAGS / ALLOWED_STYLE_KEYS and StyleSurface::STATE_KEYS / STATE_SELECTORS.
const BLOCK_TAGS = ['div', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'figure', 'main', 'a'];

/** Mirrors CanonicalDocumentSchema::blockLinkIssue: why a linked wrapper's `{url, new_tab}` is refused, or ''. */
export function blockLinkIssue(link) {
  if (!link || typeof link !== 'object' || Array.isArray(link)) return 'A link must be an object {url, new_tab}.';
  if (Object.keys(link).some((k) => k !== 'url' && k !== 'new_tab')) return 'A link holds only url and new_tab.';
  if (!isLikelySafeUrl(link.url)) return 'The link needs a safe URL (https, mailto, tel, a path or an #anchor).';
  if ('new_tab' in link && typeof link.new_tab !== 'boolean') return 'new_tab must be true or false.';
  return '';
}
const STATES = ['hover', 'focus', 'active', 'disabled'];
const STATE_KEYS = ['color', 'typography', 'background', 'border', 'shadow', 'opacity', 'effects', 'overlay'];
const STYLE_KEYS = [
  'align', 'surface_token', 'text_token', 'spacing_token', 'radius_token', 'shadow_token', 'font_token', 'border_token', 'typography', 'color',
  'background', 'border', 'shadow', 'dimensions', 'opacity', 'z_index', 'layout', 'position', 'effects', 'margin', 'padding', 'text_shadow', 'overlay',
];
const PROPERTY_PATH = /^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){0,3}$/;

/** Remove a dotted path from a plain object, dropping parents that become empty. */
function unsetPath(tree, segments) {
  const [head, ...rest] = segments;
  if (!(head in tree)) return tree;
  const { [head]: child, ...others } = tree;
  if (rest.length === 0) return others;
  if (child && typeof child === 'object' && !Array.isArray(child)) {
    const next = unsetPath(child, rest);
    return Object.keys(next).length ? { ...others, [head]: next } : others;
  }
  return tree;
}

function mapBlocks(doc, fn) {
  const mapList = (blocks) => {
    let changed = false;
    const out = asList(blocks).map((b) => {
      let nb = fn(b);
      const kids = asList(nb.children);
      if (kids.length) {
        const mappedKids = mapList(kids);
        if (mappedKids !== kids) nb = { ...nb, children: mappedKids };
      }
      if (nb !== b) changed = true;
      return nb;
    });
    return changed ? out : blocks;
  };
  const containers = mapList(doc.containers);
  return containers === doc.containers ? doc : { ...doc, containers };
}

function extract(doc, blockId) {
  let removed = null;
  const strip = (blocks) => {
    const list = asList(blocks);
    const idx = list.findIndex((b) => b.id === blockId);
    if (idx >= 0) {
      removed = list[idx];
      return list.filter((_, i) => i !== idx);
    }
    let changed = false;
    const out = list.map((b) => {
      if (removed) return b;
      const kids = asList(b.children);
      if (!kids.length) return b;
      const nk = strip(kids);
      if (nk === kids) return b;
      changed = true;
      return { ...b, children: nk };
    });
    return changed ? out : blocks;
  };
  const containers = strip(doc.containers);
  return { doc: removed ? { ...doc, containers } : doc, removed };
}

function insertInto(doc, parentId, index, block) {
  if (parentId === null || parentId === undefined) {
    if (block.type !== CONTAINER_TYPE) throw new Error('Only a Container can sit at the top level of a page');
    const containers = [...asList(doc.containers)];
    containers.splice(clamp(index, containers.length), 0, block);
    return { ...doc, containers };
  }
  let inserted = false;
  const next = mapBlocks(doc, (b) => {
    if (inserted || b.id !== parentId) return b;
    inserted = true;
    const children = [...asList(b.children)];
    children.splice(clamp(index, children.length), 0, block);
    return { ...b, children };
  });
  if (!inserted) throw notFound(parentId);
  return next;
}

// With `rootId` (the entry's provisional id) every id in the copy is derived from it by position
// (`<root>_0_2`), so the copy keeps the same ids each time the working document is rebuilt: an edit
// made to a pasted or duplicated child before the server answered still finds its target, and the
// sync engine can map each one to the id the server minted for it.
function cloneWithProvisionalIds(node, prefix = 'blk', rootId = null) {
  const cloned = JSON.parse(JSON.stringify(node));
  const remint = (item, pfx, id) => {
    item.id = id || provisionalId(pfx);
    if (Array.isArray(item.children)) {
      item.children.forEach((child, i) => remint(child, 'blk', id ? `${id}_${i}` : null));
    }
  };
  remint(cloned, prefix, rootId);
  return cloned;
}
