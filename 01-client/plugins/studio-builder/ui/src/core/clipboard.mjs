// The cross-page clipboard: copy a section or block as JSON, paste it anywhere (another page included).
//
// The text on the system clipboard is untrusted: anything can put anything there. So a paste never uses it as-is.
// `parseEnvelope` checks the envelope and the shape of the tree, keeps only the keys a section or block may carry,
// and refuses anything over the size, depth or node caps. Ids never travel: they are dropped on copy and fresh
// provisional ids are minted on paste. What is left still goes through `planPaste` (the same structural rules as an
// insert from the palette) and then through the normal operations pipeline, where the server has the last word.

import { asList, asObject, blockDefinition, canContain, canInsertSection, countBlocks, findNode, isGlobalSection, sectionsOf, subtreeHeight } from './doc.mjs';
import { effectivelyLocked, lockIndex } from './layerLock.mjs';
import { provisionalId } from './operations.mjs';

export const ENVELOPE_KEY = 'kohevoStudio';
export const ENVELOPE_VERSION = 1;
export const MAX_CLIPBOARD_BYTES = 262144;
const MAX_NODES = 600;
const MAX_TREE_DEPTH = 12;
const MAX_JSON_DEPTH = 32;

const BLOCK_OBJECT_KEYS = ['props', 'bindings', 'style', 'visibility', 'responsive', 'attributes', 'metadata', 'interactions', 'animation', 'style_states'];
const BLOCK_ANY_KEYS = ['classNames', 'conditions'];
const SECTION_OBJECT_KEYS = ['layout', 'visibility', 'style', 'animation', 'interactions'];
const UNSAFE_KEYS = new Set(['__proto__', 'constructor', 'prototype']);
const TYPE_PATTERN = /^[A-Za-z0-9_.-]{1,128}$/;

const utf8Length = (text) => (typeof TextEncoder !== 'undefined' ? new TextEncoder().encode(text).length : text.length);

/** A copy of plain JSON data without the keys that could poison an object; null when it is not plain JSON. */
function cleanJson(value, depth = 0) {
  if (depth > MAX_JSON_DEPTH) return undefined;
  if (value === null || ['string', 'boolean'].includes(typeof value)) return value;
  if (typeof value === 'number') return Number.isFinite(value) ? value : undefined;
  if (Array.isArray(value)) {
    const out = [];
    for (const item of value) {
      const clean = cleanJson(item, depth + 1);
      if (clean === undefined) return undefined;
      out.push(clean);
    }
    return out;
  }
  if (typeof value === 'object') {
    const out = {};
    for (const [k, v] of Object.entries(value)) {
      if (UNSAFE_KEYS.has(k)) continue;
      const clean = cleanJson(v, depth + 1);
      if (clean === undefined) return undefined;
      out[k] = clean;
    }
    return out;
  }
  return undefined;
}

/** PHP encodes an empty object as `[]`, so an empty list is as good as an object here. */
const isObjectish = (v) => (v !== null && typeof v === 'object' && !Array.isArray(v)) || (Array.isArray(v) && v.length === 0);

/** A block as the clipboard keeps it (no ids, no lock), or null when it is not a block. `budget.left` caps the tree. */
function sanitizeBlock(raw, budget, depth) {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw) || depth > MAX_TREE_DEPTH) return null;
  if (typeof raw.type !== 'string' || !TYPE_PATTERN.test(raw.type)) return null;
  budget.left -= 1;
  if (budget.left < 0) return null;
  const out = { type: raw.type };
  for (const key of BLOCK_OBJECT_KEYS) {
    if (raw[key] === undefined || raw[key] === null) continue;
    if (!isObjectish(raw[key])) return null;
    const clean = cleanJson(raw[key]);
    if (clean === undefined) return null;
    out[key] = clean;
  }
  for (const key of BLOCK_ANY_KEYS) {
    if (raw[key] === undefined || raw[key] === null) continue;
    const clean = cleanJson(raw[key]);
    if (clean === undefined) return null;
    out[key] = clean;
  }
  if (typeof raw.tag === 'string') out.tag = raw.tag.slice(0, 32);
  if (out.metadata && !Array.isArray(out.metadata)) delete out.metadata.locked; // a pasted copy starts unlocked
  if (Array.isArray(out.metadata)) delete out.metadata;
  const kids = [];
  if (raw.children !== undefined && raw.children !== null) {
    if (!Array.isArray(raw.children)) return null;
    for (const child of raw.children) {
      const clean = sanitizeBlock(child, budget, depth + 1);
      if (!clean) return null;
      kids.push(clean);
    }
  }
  out.children = kids;
  return out;
}

function sanitizeSection(raw, budget) {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return null;
  const out = { label: typeof raw.label === 'string' ? raw.label.slice(0, 200) : '' };
  for (const key of SECTION_OBJECT_KEYS) {
    if (raw[key] === undefined || raw[key] === null) continue;
    if (!isObjectish(raw[key])) return null;
    const clean = cleanJson(raw[key]);
    if (clean === undefined) return null;
    out[key] = clean;
  }
  if (typeof raw.global_ref === 'string' && raw.global_ref !== '') out.global_ref = raw.global_ref.slice(0, 191);
  out.blocks = [];
  if (raw.blocks !== undefined && raw.blocks !== null) {
    if (!Array.isArray(raw.blocks)) return null;
    for (const block of raw.blocks) {
      const clean = sanitizeBlock(block, budget, 1);
      if (!clean) return null;
      out.blocks.push(clean);
    }
  }
  return out;
}

/** The node a copy keeps: the tree without ids and without locks. Null when it is not a valid section/block. */
export function sanitizeNode(kind, node) {
  const budget = { left: MAX_NODES };
  if (kind === 'block') return sanitizeBlock(node, budget, 1);
  if (kind === 'section') return sanitizeSection(node, budget);
  return null;
}

/** The envelope for a section or block (a node taken from the document, ids and all). Null when it cannot be copied. */
export function buildEnvelope(kind, node) {
  const clean = sanitizeNode(kind, node);
  return clean ? { [ENVELOPE_KEY]: ENVELOPE_VERSION, kind, node: clean } : null;
}

export const serializeEnvelope = (envelope) => JSON.stringify(envelope);

/**
 * Read clipboard text. Never throws.
 * @returns {{ok:true, envelope:object}|{ok:false, reasonKey:string}} reasonKey is a UI message key
 */
export function parseEnvelope(text) {
  if (typeof text !== 'string' || text.trim() === '') return { ok: false, reasonKey: 'clip_empty' };
  if (utf8Length(text) > MAX_CLIPBOARD_BYTES) return { ok: false, reasonKey: 'clip_too_big' };
  const trimmed = text.trim();
  if (trimmed[0] !== '{') return { ok: false, reasonKey: 'clip_foreign' };
  let data;
  try { data = JSON.parse(trimmed); } catch { return { ok: false, reasonKey: 'clip_foreign' }; }
  if (!data || typeof data !== 'object' || Array.isArray(data) || data[ENVELOPE_KEY] !== ENVELOPE_VERSION) return { ok: false, reasonKey: 'clip_foreign' };
  if (data.kind !== 'block' && data.kind !== 'section') return { ok: false, reasonKey: 'clip_invalid' };
  const envelope = buildEnvelope(data.kind, data.node);
  return envelope ? { ok: true, envelope } : { ok: false, reasonKey: 'clip_invalid' };
}

/** A copy of the node with a fresh provisional id on every section and block in it. */
export function regenerateIds(node, kind) {
  const remint = (item, prefix) => {
    const next = { ...item, id: provisionalId(prefix) };
    if (Array.isArray(item.blocks)) next.blocks = item.blocks.map((b) => remint(b, 'blk'));
    if (Array.isArray(item.children)) next.children = item.children.map((b) => remint(b, 'blk'));
    return next;
  };
  return remint(node, kind === 'section' ? 'sec' : 'blk');
}

export function countNodes(node) {
  return 1 + asList(node.blocks).concat(asList(node.children)).reduce((n, child) => n + countNodes(child), 0);
}

/** Why a pasted block tree cannot exist on this site (unknown or unavailable type, a child its parent does not allow). */
function treeProblem(manifest, block, parentDef) {
  const def = blockDefinition(manifest, block.type);
  if (!def) return 'clip_unavailable';
  if (def.locked === true || def.disabled === true) return 'clip_unavailable';
  if (parentDef) {
    const allowed = asList(parentDef.allowed_child_types);
    if (!parentDef.allows_children || (allowed.length && !allowed.includes(block.type))) return 'clip_not_allowed';
  }
  const kids = asList(block.children);
  if (kids.length && !def.allows_children) return 'clip_not_allowed';
  for (const kid of kids) {
    const problem = treeProblem(manifest, kid, def);
    if (problem) return problem;
  }
  return null;
}

const fail = (reasonKey) => ({ ok: false, reasonKey });

/**
 * Where a clipboard envelope would land, or why it cannot. Pure; the same structural rules as the palette.
 * `mode` is 'after' (next to the target) or 'inside' (at the end of the target). A section always lands between
 * sections, so its mode does not matter; a block aimed at a section lands at the end of it.
 * @returns {{ok:true, kind:'block', parentId:string, index:number}
 *   | {ok:true, kind:'block', newSection:true, sectionIndex:number}
 *   | {ok:true, kind:'section', index:number}
 *   | {ok:false, reasonKey:string}}
 */
export function planPaste({ doc, manifest, envelope, targetId = null, mode = 'after' }) {
  if (!envelope || (envelope.kind !== 'block' && envelope.kind !== 'section')) return fail('clip_invalid');
  const node = envelope.node;
  const limits = asObject(manifest && manifest.limits);
  const maxBlocks = limits.max_blocks || 250;
  const target = targetId ? findNode(doc, targetId) : null;
  const incoming = envelope.kind === 'block' ? countNodes(node) : countNodes(node) - 1;
  if (countBlocks(doc) + incoming > maxBlocks) return fail('clip_blocks_limit');

  if (envelope.kind === 'section') {
    if (!canInsertSection(doc, manifest)) return fail('clip_sections_limit');
    const maxDepth = limits.max_nesting_depth || 6;
    for (const block of asList(node.blocks)) {
      const problem = treeProblem(manifest, block, null);
      if (problem) return fail(problem);
      if (subtreeHeight(block) > maxDepth) return fail('clip_not_allowed');
    }
    const sections = sectionsOf(doc);
    if (!target) return { ok: true, kind: 'section', index: sections.length };
    const at = sections.findIndex((s) => s.id === target.sectionId);
    return { ok: true, kind: 'section', index: at < 0 ? sections.length : at + 1 };
  }

  const problem = treeProblem(manifest, node, null);
  if (problem) return fail(problem);
  let dest = null;
  if (target && target.kind === 'block' && mode === 'after') dest = { parentId: target.parentId, index: target.index + 1 };
  else if (target && target.kind === 'block') dest = { parentId: target.node.id, index: asList(target.node.children).length };
  else if (target) dest = { parentId: target.node.id, index: asList(target.node.blocks).length };
  else {
    const open = sectionsOf(doc).filter((s) => !isGlobalSection(s));
    if (!open.length) return canInsertSection(doc, manifest) ? { ok: true, kind: 'block', newSection: true, sectionIndex: sectionsOf(doc).length } : fail('clip_sections_limit');
    const last = open[open.length - 1];
    dest = { parentId: last.id, index: asList(last.blocks).length };
  }
  const parent = findNode(doc, dest.parentId);
  if (!parent) return fail('clip_not_allowed');
  if (parent.kind === 'section' && isGlobalSection(parent.node)) return fail('clip_global');
  if (effectivelyLocked(lockIndex(doc), dest.parentId)) return fail('clip_locked');
  if (!canContain(doc, manifest, dest.parentId, node.type, subtreeHeight(node))) return fail('clip_not_allowed');
  return { ok: true, kind: 'block', parentId: dest.parentId, index: dest.index };
}

// ── The clipboard itself ───────────────────────────────────────────────────

/** What the last Copy/Cut in this tab kept: the fallback when the browser refuses the clipboard API. */
let memory = null;

export const peekMemory = () => memory;
export const clearMemory = () => { memory = null; };

/**
 * Put an envelope on the clipboard: always in memory, and on the system clipboard when the browser allows it.
 * @returns {Promise<{ok:true, system:boolean}|{ok:false, reasonKey:string}>}
 */
export async function writeClipboard(envelope, nav = typeof navigator !== 'undefined' ? navigator : null) {
  const text = serializeEnvelope(envelope);
  if (utf8Length(text) > MAX_CLIPBOARD_BYTES) return { ok: false, reasonKey: 'clip_too_big' };
  memory = envelope;
  try {
    if (nav && nav.clipboard && typeof nav.clipboard.writeText === 'function') {
      await nav.clipboard.writeText(text);
      return { ok: true, system: true };
    }
  } catch { /* refused: the in-memory copy still serves a paste in this tab */ }
  return { ok: true, system: false };
}

/**
 * The envelope to paste: the system clipboard when it can be read (so a copy from another tab or window works),
 * else the in-memory copy. Text that is not Studio content is refused, never mixed with an older in-memory copy.
 * @returns {Promise<{ok:true, envelope:object}|{ok:false, reasonKey:string}>}
 */
export async function readClipboard(nav = typeof navigator !== 'undefined' ? navigator : null) {
  let text = null;
  try {
    if (nav && nav.clipboard && typeof nav.clipboard.readText === 'function') text = await nav.clipboard.readText();
  } catch { text = null; }
  if (typeof text === 'string') {
    const parsed = parseEnvelope(text);
    if (parsed.ok || text.trim() !== '' || !memory) return parsed;
  }
  return memory ? parseEnvelope(serializeEnvelope(memory)) : { ok: false, reasonKey: text === null ? 'clip_unreadable' : 'clip_empty' };
}
