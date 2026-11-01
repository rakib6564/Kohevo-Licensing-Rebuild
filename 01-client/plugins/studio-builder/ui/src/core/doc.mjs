// Kohevo Studio builder — read-only helpers over the canonical document tree.
//
// The builder never owns the document: it holds a transient copy of the
// server's working revision (plus not-yet-confirmed local operations). These
// helpers only answer structural questions about that copy — where a node is,
// what may be dropped where — using the SAME rules the server enforces
// (DocumentOperationApplier + DocumentValidator). The server stays the final
// authority; these checks exist so the UI never offers a move it would reject.

export const CONTAINER_TYPE = 'core.container';

/** PHP encodes an empty JSON object as `[]`; treat any non-object as `{}`. */
export function asObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

export function asList(value) {
  return Array.isArray(value) ? value : [];
}

export function containersOf(doc) {
  return asList(doc && doc.containers);
}

/**
 * Depth-first walk: calls visit(node, info) for every block, the top-level Containers (parentId null, depth 1) first
 * of their subtrees. `topId` is the top-level Container the node sits in.
 */
export function walk(doc, visit) {
  const walkBlocks = (blocks, parentId, depth, topId) => {
    asList(blocks).forEach((block, index) => {
      const top = topId || block.id;
      visit(block, { kind: 'block', parentId, index, depth, topId: top });
      walkBlocks(block.children, block.id, depth + 1, top);
    });
  };
  walkBlocks(containersOf(doc), null, 1, null);
}

/** Every block in document order as `{id, parentId}` (range selection, bulk actions). */
export function documentRows(doc) {
  const rows = [];
  walk(doc, (node, info) => { if (node && node.id) rows.push({ id: node.id, parentId: info.parentId }); });
  return rows;
}

/** @returns {null | {kind, node, parentId, index, depth, topId}} */
export function findNode(doc, id) {
  if (!id) return null;
  let found = null;
  walk(doc, (node, info) => {
    if (!found && node && node.id === id) found = { ...info, node };
  });
  return found;
}

export function nodeIds(doc) {
  const ids = new Set();
  walk(doc, (node) => { if (node && node.id) ids.add(node.id); });
  return ids;
}

export function countBlocks(doc) {
  let n = 0;
  walk(doc, () => { n += 1; });
  return n;
}

/** The ordered child list of a parent (null: the top level). */
export function childrenOf(doc, parentId) {
  if (parentId === null || parentId === undefined) return containersOf(doc);
  const parent = findNode(doc, parentId);
  return parent ? asList(parent.node.children) : [];
}

/** Height of a block subtree (a leaf block is 1). */
export function subtreeHeight(block) {
  const kids = asList(block && block.children);
  return 1 + (kids.length ? Math.max(...kids.map(subtreeHeight)) : 0);
}

export function isDescendant(doc, ancestorId, candidateId) {
  const ancestor = findNode(doc, ancestorId);
  if (!ancestor) return false;
  let hit = false;
  const scan = (blocks) => asList(blocks).forEach((b) => {
    if (hit) return;
    if (b.id === candidateId) { hit = true; return; }
    scan(b.children);
  });
  scan(ancestor.node.children);
  return hit;
}

/**
 * Can a block of `type` (with a subtree of `height`) live directly inside
 * `parentId`? Mirrors the server: the parent is null (the top level, Containers
 * only) or a registered block whose manifest allows children and (when it lists
 * any) this type; a reference owns no children; the resulting depth, counted
 * from the top Container at 1, must stay within limits.max_nesting_depth.
 */
export function canContain(doc, manifest, parentId, type, height = 1) {
  const maxDepth = (manifest.limits && manifest.limits.max_nesting_depth) || 7;
  if (parentId === null || parentId === undefined) return type === CONTAINER_TYPE && height <= maxDepth;
  const parent = findNode(doc, parentId);
  if (!parent || isGlobalContainer(parent.node)) return false;
  const def = blockDefinition(manifest, parent.node.type);
  if (!def || !def.allows_children) return false;
  const allowed = asList(def.allowed_child_types);
  if (allowed.length && !allowed.includes(type)) return false;
  return parent.depth + height <= maxDepth;
}

/** Whether moving block `blockId` into `parentId` (null: the top level) is structurally valid. */
export function canMoveBlock(doc, manifest, blockId, parentId) {
  const moving = findNode(doc, blockId);
  if (!moving) return false;
  if (parentId === blockId || (parentId !== null && parentId !== undefined && isDescendant(doc, blockId, parentId))) return false;
  if (isGlobalContainer(moving.node) && parentId !== null) return false; // a reference stays at the top level
  const isTop = parentId === null || parentId === undefined;
  if (isTop && moving.parentId !== null && !canInsertContainer(doc, manifest)) return false;
  return canContain(doc, manifest, parentId, moving.node.type, subtreeHeight(moving.node));
}

export function canInsertBlock(doc, manifest, parentId, type) {
  const max = (manifest.limits && manifest.limits.max_blocks) || 300;
  if ((parentId === null || parentId === undefined) && !canInsertContainer(doc, manifest)) return false;
  return !!blockDefinition(manifest, type) && countBlocks(doc) < max && canContain(doc, manifest, parentId, type, 1);
}

export function canInsertContainer(doc, manifest) {
  const max = (manifest.limits && manifest.limits.max_containers) || 50;
  return containersOf(doc).length < max;
}

export function blockDefinition(manifest, type) {
  return asList(manifest && manifest.blocks).find((b) => b.type === type) || null;
}

/**
 * Keyboard move targets. Up/down swap with the neighbouring sibling; at the
 * edge of a top-level Container the block continues into the adjacent one; at
 * the edge of a nested Container it steps out next to that Container. Every
 * candidate is checked with canMoveBlock before being offered.
 *
 * @returns {null | {parentId, index}} a `move_block` destination (parentId null: the top level)
 */
export function blockMoveTarget(doc, manifest, blockId, direction) {
  const info = findNode(doc, blockId);
  if (!info) return null;
  const siblings = childrenOf(doc, info.parentId);
  if (direction === 'up' && info.index > 0) return { parentId: info.parentId, index: info.index - 1 };
  if (direction === 'down' && info.index < siblings.length - 1) return { parentId: info.parentId, index: info.index + 1 };
  if (info.parentId === null) return null;

  const parent = findNode(doc, info.parentId);
  if (parent.parentId !== null) {
    // Step out of the Container, before (up) or after (down) it.
    const grand = parent.parentId;
    const target = { parentId: grand, index: direction === 'up' ? parent.index : parent.index + 1 };
    return canMoveBlock(doc, manifest, blockId, grand) ? target : null;
  }
  // Cross into the previous / next top-level Container.
  const next = containersOf(doc)[parent.index + (direction === 'up' ? -1 : 1)];
  if (!next || !canMoveBlock(doc, manifest, blockId, next.id)) return null;
  return { parentId: next.id, index: direction === 'up' ? asList(next.children).length : 0 };
}

/** Indent: move into the previous sibling when it is a container that accepts this block. */
export function blockIndentTarget(doc, manifest, blockId) {
  const info = findNode(doc, blockId);
  if (!info || info.index === 0) return null;
  const prev = childrenOf(doc, info.parentId)[info.index - 1];
  if (!prev || !canMoveBlock(doc, manifest, blockId, prev.id)) return null;
  return { parentId: prev.id, index: asList(prev.children).length };
}

/** Outdent: move out of the containing block, right after it (to the top level for a Container only). */
export function blockOutdentTarget(doc, manifest, blockId) {
  const info = findNode(doc, blockId);
  if (!info || info.parentId === null) return null;
  const parent = findNode(doc, info.parentId);
  if (!parent || !canMoveBlock(doc, manifest, blockId, parent.parentId)) return null;
  return { parentId: parent.parentId, index: parent.index + 1 };
}

/**
 * Where "Insert" from the palette puts a new block: inside the selected
 * container (at the end), after the selected block (same parent), else at the
 * end of the last Container; a new Container with nothing selected goes at the
 * end of the page. Returns null when a Container must be created to hold it.
 */
export function insertionPoint(doc, manifest, selectedId, type) {
  const sel = selectedId ? findNode(doc, selectedId) : null;
  if (sel) {
    const def = blockDefinition(manifest, sel.node.type);
    if (def && def.allows_children && canContain(doc, manifest, sel.node.id, type)) {
      return { parentId: sel.node.id, index: asList(sel.node.children).length };
    }
    if (canContain(doc, manifest, sel.parentId, type)) return { parentId: sel.parentId, index: sel.index + 1 };
  }
  if (type === CONTAINER_TYPE) return { parentId: null, index: containersOf(doc).length };
  const open = containersOf(doc).filter((c) => !isGlobalContainer(c));
  if (!open.length) return null;
  const last = open[open.length - 1];
  return { parentId: last.id, index: asList(last.children).length };
}

/** A top-level Container that references a global component: it owns no children of its own. */
export function isGlobalContainer(node) {
  return !!(node && typeof node.global_ref === 'string' && node.global_ref !== '');
}

/** The index a new top-level Container takes to land right after the selection's top-level Container (or at the end). */
export function indexAfter(doc, selectedId) {
  const sel = selectedId ? findNode(doc, selectedId) : null;
  const containers = containersOf(doc);
  if (!sel) return containers.length;
  return containers.findIndex((c) => c.id === sel.topId) + 1;
}

/** A short human label for a node (outline rows, announcements). */
export function nodeLabel(node, manifest) {
  // A name the author gave this layer (block metadata) wins over the generated "Type: hint".
  const custom = asObject(node && node.metadata).label;
  const named = typeof custom === 'string' && custom.trim() !== '';
  if (isGlobalContainer(node)) return `${named ? custom : 'Container'} (global)`;
  if (named) return custom;
  const def = blockDefinition(manifest, node && node.type);
  const base = def ? def.label : (node && node.type) || 'Block';
  const props = asObject(node && node.props);
  const hint = [props.heading, props.text, props.title, props.caption, props.link && props.link.label]
    .find((v) => typeof v === 'string' && v.trim() !== '');
  return hint ? `${base}: ${hint.length > 40 ? hint.slice(0, 39) + '…' : hint}` : base;
}

/**
 * Return an array of ancestors from the top-level Container down to the target node.
 * Each entry: { id, kind, label }
 */
export function ancestorPath(doc, id, manifest) {
  if (!id) return [];
  const path = [];
  let curr = findNode(doc, id);
  while (curr) {
    path.unshift({
      id: curr.node.id,
      kind: curr.kind,
      label: nodeLabel(curr.node, manifest),
    });
    curr = curr.parentId ? findNode(doc, curr.parentId) : null;
  }
  return path;
}
