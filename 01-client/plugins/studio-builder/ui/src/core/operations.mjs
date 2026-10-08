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
// node is copied, so untouched sections/blocks keep their identity and the
// UI re-renders just what changed.

import { asList, asObject } from './doc.mjs';

export const OPS = Object.freeze({
  UPDATE_SETTINGS: 'update_settings',
  UPDATE_SEO: 'update_seo',
  INSERT_SECTION: 'insert_section',
  REMOVE_SECTION: 'remove_section',
  MOVE_SECTION: 'move_section',
  DUPLICATE_SECTION: 'duplicate_section',
  UPDATE_SECTION_LABEL: 'update_section_label',
  UPDATE_SECTION_LAYOUT: 'update_section_layout',
  UPDATE_SECTION_VISIBILITY: 'update_section_visibility',
  UPDATE_SECTION_LOCKED: 'update_section_locked',
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
  UPDATE_BLOCK_TAG: 'update_block_tag',
  RESET_BLOCK_STYLE_PROPERTY: 'reset_block_style_property',
  UPDATE_SECTION_STYLE: 'update_section_style',
});

/** Operations that change the tree's shape are sent right away, not debounced. */
export const STRUCTURAL = new Set([
  OPS.INSERT_SECTION, OPS.REMOVE_SECTION, OPS.MOVE_SECTION, OPS.DUPLICATE_SECTION,
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
  [OPS.UPDATE_SECTION_LABEL]: 'section_id',
  [OPS.UPDATE_SECTION_LAYOUT]: 'section_id',
  [OPS.UPDATE_SECTION_VISIBILITY]: 'section_id',
  [OPS.UPDATE_BLOCK_STYLE_STATES]: 'block_id',
  [OPS.UPDATE_BLOCK_TAG]: 'block_id',
  [OPS.UPDATE_SECTION_STYLE]: 'section_id',
};

export const DEFAULT_VISIBILITY = Object.freeze({ auth_state: 'any', devices: ['base', 'sm', 'md', 'lg'] });
export const DEFAULT_BLOCK_STYLE = Object.freeze({
  align: { base: 'left' }, font_token: null, radius_token: null, shadow_token: null,
  spacing_token: null, surface_token: null, text_token: null,
});
export const DEFAULT_SECTION_LAYOUT = Object.freeze({
  background_token: 'surface.primary', columns: { base: 1, md: 12 }, gap: 'md',
  padding_y: { base: 'md', md: 'lg' }, width: 'wide',
});

// ── Builders ────────────────────────────────────────────────────────────────

const op = (name, payload) => ({ op: name, payload });

export const insertSection = (index, section = {}) => op(OPS.INSERT_SECTION, { index, section });
/** A LIVE reference to a global component: the section owns no blocks; the server renders the component's published content. */
export const insertGlobalSection = (index, ref, label = '') => op(OPS.INSERT_SECTION, { index, section: { label, global_ref: ref, blocks: [] } });
export const removeSection = (sectionId) => op(OPS.REMOVE_SECTION, { section_id: sectionId });
export const moveSection = (sectionId, toIndex) => op(OPS.MOVE_SECTION, { section_id: sectionId, to_index: toIndex });
export const updateSectionLayout = (sectionId, layout) => op(OPS.UPDATE_SECTION_LAYOUT, { section_id: sectionId, layout });
export const updateSectionVisibility = (sectionId, visibility) => op(OPS.UPDATE_SECTION_VISIBILITY, { section_id: sectionId, visibility });
export const updateSectionLabel = (sectionId, label) => op(OPS.UPDATE_SECTION_LABEL, { section_id: sectionId, label });
export const duplicateSection = (sectionId) => op(OPS.DUPLICATE_SECTION, { section_id: sectionId });
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
/** Lock or unlock a section (the layer lock; see layerLock.mjs). */
export const updateSectionLocked = (sectionId, locked) => op(OPS.UPDATE_SECTION_LOCKED, { section_id: sectionId, locked: !!locked });
/**
 * Patch a block's editor metadata: `label` (display name; null clears) and/or
 * `locked`. Keys left out are untouched, so these are NOT coalesced in the queue.
 */
export const updateBlockMeta = (blockId, meta) => op(OPS.UPDATE_BLOCK_META, { block_id: blockId, ...meta });
/** Replace a block's interaction states ({hover, focus, active, disabled}); `{}` clears them. */
export const updateBlockStyleStates = (blockId, styleStates) => op(OPS.UPDATE_BLOCK_STYLE_STATES, { block_id: blockId, style_states: styleStates });
/** Choose the block's wrapper element; null (or `div`) is the default. */
export const updateBlockTag = (blockId, tag) => op(OPS.UPDATE_BLOCK_TAG, { block_id: blockId, tag });
/** Replace a section's style ({background, padding}); `{}` clears it. */
export const updateSectionStyle = (sectionId, style) => op(OPS.UPDATE_SECTION_STYLE, { section_id: sectionId, style });
/**
 * Remove ONE style property by dotted path (`typography.size`), from the block's style or, with `state`,
 * from one interaction state. Not coalesced: each reset is its own step.
 */
export const resetBlockStyleProperty = (blockId, property, state = null) => op(OPS.RESET_BLOCK_STYLE_PROPERTY, state ? { block_id: blockId, property, state } : { block_id: blockId, property });
export const updateSettings = (settings) => op(OPS.UPDATE_SETTINGS, { settings });
export const updateSeo = (seo) => op(OPS.UPDATE_SEO, { seo });

// ── Id bookkeeping ──────────────────────────────────────────────────────────

const ID_KEYS = ['section_id', 'block_id', 'parent_id'];

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
    case OPS.INSERT_SECTION: {
      const given = asObject(p.section);
      const section = {
        blocks: asList(given.blocks),
        global_ref: given.global_ref ?? null,
        id: ctx.provisionalId || provisionalId('sec'),
        label: given.label ?? 'New Section',
        layout: Object.keys(asObject(given.layout)).length ? given.layout : DEFAULT_SECTION_LAYOUT,
        visibility: Object.keys(asObject(given.visibility)).length ? given.visibility : DEFAULT_VISIBILITY,
      };
      const sections = [...asList(doc.sections)];
      sections.splice(clamp(p.index, sections.length), 0, section);
      return { ...doc, sections };
    }
    case OPS.REMOVE_SECTION: {
      const sections = asList(doc.sections);
      const out = sections.filter((s) => s.id !== p.section_id);
      if (out.length === sections.length) throw notFound(p.section_id);
      return { ...doc, sections: out };
    }
    case OPS.MOVE_SECTION: {
      const sections = [...asList(doc.sections)];
      const from = sections.findIndex((s) => s.id === p.section_id);
      if (from < 0) throw notFound(p.section_id);
      const [moved] = sections.splice(from, 1);
      sections.splice(clamp(p.to_index, sections.length), 0, moved);
      return { ...doc, sections };
    }
    case OPS.UPDATE_SECTION_LABEL: {
      let hit = false;
      const sections = asList(doc.sections).map((s) => {
        if (s.id !== p.section_id) return s;
        hit = true;
        return { ...s, label: String(p.label ?? '') };
      });
      if (!hit) throw notFound(p.section_id);
      return { ...doc, sections };
    }
    case OPS.DUPLICATE_SECTION: {
      const sections = [...asList(doc.sections)];
      const from = sections.findIndex((s) => s.id === p.section_id);
      if (from < 0) throw notFound(p.section_id);
      const source = sections[from];
      const cloned = cloneWithProvisionalIds(source, 'sec');
      cloned.label = `${source.label || 'Section'} (Copy)`;
      sections.splice(from + 1, 0, cloned);
      return { ...doc, sections };
    }
    case OPS.UPDATE_SECTION_LAYOUT:
    case OPS.UPDATE_SECTION_VISIBILITY: {
      const field = operation.op === OPS.UPDATE_SECTION_LAYOUT ? 'layout' : 'visibility';
      let hit = false;
      const sections = asList(doc.sections).map((s) => {
        if (s.id !== p.section_id) return s;
        hit = true;
        return { ...s, [field]: asObject(p[field]) };
      });
      if (!hit) throw notFound(p.section_id);
      return { ...doc, sections };
    }
    case OPS.UPDATE_SECTION_LOCKED: {
      let hit = false;
      const sections = asList(doc.sections).map((s) => {
        if (s.id !== p.section_id) return s;
        hit = true;
        const { locked: _drop, ...rest } = s;
        return p.locked === true ? { ...rest, locked: true } : rest;
      });
      if (!hit) throw notFound(p.section_id);
      return { ...doc, sections };
    }
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
      const block = {
        bindings: Object.keys(asObject(given.bindings)).length ? given.bindings : {},
        children: [],
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
            out.push(cloneWithProvisionalIds(b, 'blk'));
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
      const sections = asList(doc.sections).map((s) => {
        if (hit) return s;
        const nextBlocks = cloneBlockTree(s.blocks);
        return hit ? { ...s, blocks: nextBlocks } : s;
      });
      if (!hit) throw notFound(p.block_id);
      return { ...doc, sections };
    }
    case OPS.UPDATE_BLOCK_PROPS:
    case OPS.UPDATE_BLOCK_STYLE:
    case OPS.UPDATE_BLOCK_VISIBILITY:
    case OPS.UPDATE_BLOCK_BINDINGS:
    case OPS.UPDATE_BLOCK_ANIMATION:
    case OPS.UPDATE_BLOCK_INTERACTIONS: {
      const field = operation.op.replace('update_block_', '');
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
    case OPS.UPDATE_BLOCK_TAG: {
      if (p.tag !== null && p.tag !== undefined && !BLOCK_TAGS.includes(p.tag)) throw new Error(`Invalid tag ${p.tag}`);
      let hit = false;
      const next = mapBlocks(doc, (b) => {
        if (b.id !== p.block_id) return b;
        hit = true;
        const { tag: _old, ...rest } = b;
        return p.tag && p.tag !== 'div' ? { ...rest, tag: p.tag } : rest;
      });
      if (!hit) throw notFound(p.block_id);
      return next;
    }
    case OPS.UPDATE_SECTION_STYLE: {
      let hit = false;
      const sections = asList(doc.sections).map((s) => {
        if (s.id !== p.section_id) return s;
        hit = true;
        const { style: _old, ...rest } = s;
        const style = asObject(p.style);
        return Object.keys(style).length ? { ...rest, style } : rest;
      });
      if (!hit) throw notFound(p.section_id);
      return { ...doc, sections };
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
const BLOCK_TAGS = ['div', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'figure'];
const STATES = ['hover', 'focus', 'active', 'disabled'];
const STATE_KEYS = ['color', 'typography', 'background', 'border', 'shadow', 'opacity', 'effects'];
const STYLE_KEYS = [
  'align', 'surface_token', 'text_token', 'spacing_token', 'radius_token', 'shadow_token', 'font_token', 'typography', 'color',
  'background', 'spacing', 'border', 'shadow', 'dimensions', 'opacity', 'z_index', 'layout', 'position', 'effects', 'margin', 'padding',
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
  let changed = false;
  const sections = asList(doc.sections).map((s) => {
    const blocks = mapList(s.blocks);
    if (blocks === s.blocks) return s;
    changed = true;
    return { ...s, blocks };
  });
  return changed ? { ...doc, sections } : doc;
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
  const sections = asList(doc.sections).map((s) => {
    if (removed) return s;
    const blocks = strip(s.blocks);
    return blocks === s.blocks ? s : { ...s, blocks };
  });
  return { doc: removed ? { ...doc, sections } : doc, removed };
}

function insertInto(doc, parentId, index, block) {
  let inserted = false;
  const sections = asList(doc.sections).map((s) => {
    if (inserted) return s;
    if (s.id === parentId) {
      inserted = true;
      const blocks = [...asList(s.blocks)];
      blocks.splice(clamp(index, blocks.length), 0, block);
      return { ...s, blocks };
    }
    return s;
  });
  if (inserted) return { ...doc, sections };
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

function cloneWithProvisionalIds(node, prefix = 'blk') {
  const cloned = JSON.parse(JSON.stringify(node));
  const remint = (item, pfx) => {
    item.id = provisionalId(pfx);
    if (Array.isArray(item.blocks)) {
      item.blocks.forEach((child) => remint(child, 'blk'));
    }
    if (Array.isArray(item.children)) {
      item.children.forEach((child) => remint(child, 'blk'));
    }
  };
  remint(cloned, prefix);
  return cloned;
}
