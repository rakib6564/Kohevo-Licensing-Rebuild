// Kohevo Studio builder — read-only helpers over the canonical document tree.
//
// The builder never owns the document: it holds a transient copy of the
// server's working revision (plus not-yet-confirmed local operations). These
// helpers only answer structural questions about that copy — where a node is,
// what may be dropped where — using the SAME rules the server enforces
// (DocumentOperationApplier + DocumentValidator). The server stays the final
// authority; these checks exist so the UI never offers a move it would reject.

/** PHP encodes an empty JSON object as `[]`; treat any non-object as `{}`. */
export function asObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

export function asList(value) {
  return Array.isArray(value) ? value : [];
}

export function sectionsOf(doc) {
  return asList(doc && doc.sections);
}

/** Depth-first walk: calls visit(node, info) for every section and block. */
export function walk(doc, visit) {
  const sections = sectionsOf(doc);
  sections.forEach((section, sIndex) => {
    visit(section, { kind: 'section', parentId: null, index: sIndex, depth: 0, sectionId: section.id });
    const walkBlocks = (blocks, parentId, depth) => {
      asList(blocks).forEach((block, index) => {
        visit(block, { kind: 'block', parentId, index, depth, sectionId: section.id });
        walkBlocks(block.children, block.id, depth + 1);
      });
    };
    walkBlocks(section.blocks, section.id, 1);
  });
}

/** @returns {null | {kind, node, parentId, index, depth, sectionId}} */
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
  walk(doc, (_node, info) => { if (info.kind === 'block') n += 1; });
  return n;
}

/** The ordered child list of a parent (section → blocks, block → children). */
export function childrenOf(doc, parentId) {
  const parent = findNode(doc, parentId);
  if (!parent) return [];
  return parent.kind === 'section' ? asList(parent.node.blocks) : asList(parent.node.children);
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
  scan(ancestor.kind === 'section' ? ancestor.node.blocks : ancestor.node.children);
  return hit;
}

/**
 * Can a block of `type` (with a subtree of `height`) live directly inside
 * `parentId`? Mirrors the server: the parent is a section, or a registered
 * block whose manifest allows children and (when it lists any) this type;
 * the resulting depth must stay within limits.max_nesting_depth.
 */
export function canContain(doc, manifest, parentId, type, height = 1) {
  const parent = findNode(doc, parentId);
  if (!parent) return false;
  const maxDepth = (manifest.limits && manifest.limits.max_nesting_depth) || 4;
  if (parent.kind === 'section') return height <= maxDepth;
  const def = blockDefinition(manifest, parent.node.type);
  if (!def || !def.allows_children) return false;
  const allowed = asList(def.allowed_child_types);
  if (allowed.length && !allowed.includes(type)) return false;
  return parent.depth + height <= maxDepth;
}

/** Whether moving block `blockId` into `parentId` is structurally valid. */
export function canMoveBlock(doc, manifest, blockId, parentId) {
  const moving = findNode(doc, blockId);
  if (!moving || moving.kind !== 'block') return false;
  if (parentId === blockId || isDescendant(doc, blockId, parentId)) return false;
  return canContain(doc, manifest, parentId, moving.node.type, subtreeHeight(moving.node));
}

export function canInsertBlock(doc, manifest, parentId, type) {
  const max = (manifest.limits && manifest.limits.max_blocks) || 250;
  return !!blockDefinition(manifest, type) && countBlocks(doc) < max && canContain(doc, manifest, parentId, type, 1);
}

export function canInsertSection(doc, manifest) {
  const max = (manifest.limits && manifest.limits.max_sections) || 50;
  return sectionsOf(doc).length < max;
}

export function blockDefinition(manifest, type) {
  return asList(manifest && manifest.blocks).find((b) => b.type === type) || null;
}

/**
 * Keyboard move targets. Up/down swap with the neighbouring sibling; at the
 * edge of a section the block continues into the adjacent section; at the
 * edge of a container it steps out next to the container. Every candidate is
 * checked with canMoveBlock before being offered.
 *
 * @returns {null | {parentId, index}} a `move_block` destination
 */
export function blockMoveTarget(doc, manifest, blockId, direction) {
  const info = findNode(doc, blockId);
  if (!info || info.kind !== 'block') return null;
  const siblings = childrenOf(doc, info.parentId);
  const parent = findNode(doc, info.parentId);
  if (direction === 'up' && info.index > 0) return { parentId: info.parentId, index: info.index - 1 };
  if (direction === 'down' && info.index < siblings.length - 1) return { parentId: info.parentId, index: info.index + 1 };

  if (parent.kind === 'block') {
    // Step out of the container, before (up) or after (down) it.
    const grand = parent.parentId;
    const target = { parentId: grand, index: direction === 'up' ? parent.index : parent.index + 1 };
    return canMoveBlock(doc, manifest, blockId, grand) ? target : null;
  }
  // Cross into the previous / next section.
  const sections = sectionsOf(doc);
  const next = sections[parent.index + (direction === 'up' ? -1 : 1)];
  if (!next) return null;
  return { parentId: next.id, index: direction === 'up' ? asList(next.blocks).length : 0 };
}

/** Indent: move into the previous sibling when it is a container that accepts this block. */
export function blockIndentTarget(doc, manifest, blockId) {
  const info = findNode(doc, blockId);
  if (!info || info.kind !== 'block' || info.index === 0) return null;
  const prev = childrenOf(doc, info.parentId)[info.index - 1];
  if (!prev || !canMoveBlock(doc, manifest, blockId, prev.id)) return null;
  return { parentId: prev.id, index: asList(prev.children).length };
}

/** Outdent: move out of the containing block, right after it. */
export function blockOutdentTarget(doc, manifest, blockId) {
  const info = findNode(doc, blockId);
  if (!info || info.kind !== 'block') return null;
  const parent = findNode(doc, info.parentId);
  if (!parent || parent.kind !== 'block') return null;
  if (!canMoveBlock(doc, manifest, blockId, parent.parentId)) return null;
  return { parentId: parent.parentId, index: parent.index + 1 };
}

/**
 * Where "Insert" from the palette puts a new block: after the selected block
 * (same parent), inside the selected container/section (at the end), else at
 * the end of the last section. Returns null when a section must be created first.
 */
export function insertionPoint(doc, manifest, selectedId, type) {
  const sel = selectedId ? findNode(doc, selectedId) : null;
  if (sel && sel.kind === 'section') return { parentId: sel.node.id, index: asList(sel.node.blocks).length };
  if (sel && sel.kind === 'block') {
    const def = blockDefinition(manifest, sel.node.type);
    if (def && def.allows_children && canContain(doc, manifest, sel.node.id, type)) {
      return { parentId: sel.node.id, index: asList(sel.node.children).length };
    }
    if (canContain(doc, manifest, sel.parentId, type)) return { parentId: sel.parentId, index: sel.index + 1 };
  }
  const sections = sectionsOf(doc);
  if (!sections.length) return null;
  const last = sections[sections.length - 1];
  return { parentId: last.id, index: asList(last.blocks).length };
}

/** A short human label for a node (outline rows, announcements). */
export function nodeLabel(node, manifest, kind) {
  if (kind === 'section' || (node && Array.isArray(node.blocks))) {
    return (node && node.label) || 'Section';
  }
  const def = blockDefinition(manifest, node && node.type);
  const base = def ? def.label : (node && node.type) || 'Block';
  const props = asObject(node && node.props);
  const hint = [props.heading, props.text, props.title, props.caption, props.link && props.link.label]
    .find((v) => typeof v === 'string' && v.trim() !== '');
  return hint ? `${base}: ${hint.length > 40 ? hint.slice(0, 39) + '…' : hint}` : base;
}
