// Kohevo Studio builder — template library / global component helpers.
//
// Pure functions over the transport-safe views the server returns
// (StudioEditorViews::template / ::component) and the manifest vocabulary.
// Copy vs reference semantics are decided by the SERVER; these helpers only
// pick which server command a library action maps to.

import { asList, findNode, isGlobalSection, sectionsOf } from './doc.mjs';

/** Human-safe slug for template keys / component identifiers (lowercase, hyphens). */
export function slugify(text, max = 60) {
  return String(text || '')
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, max)
    .replace(/-+$/g, '');
}

export const TEMPLATE_GROUPS = Object.freeze([
  { type: 'page_template', label: 'library_page_templates' },
  { type: 'section_preset', label: 'library_section_presets' },
  { type: 'block_preset', label: 'library_block_presets' },
  { type: 'header_preset', label: 'library_header_presets' },
  { type: 'footer_preset', label: 'library_footer_presets' },
]);

/** Which template groups make sense for the page being edited. */
export function templateGroupsFor(pageType) {
  return TEMPLATE_GROUPS.filter((g) => {
    if (g.type === 'header_preset') return pageType === 'header_partial';
    if (g.type === 'footer_preset') return pageType === 'footer_partial';
    if (g.type === 'page_template') return ['page', 'landing', 'system'].includes(pageType);
    return true;
  });
}

export function groupTemplates(templates, pageType) {
  const groups = templateGroupsFor(pageType).map((g) => ({ ...g, items: [] }));
  for (const t of asList(templates)) {
    const g = groups.find((x) => x.type === t.template_type);
    if (g) g.items.push(t);
  }
  return groups;
}

/**
 * What "Save as template" can produce for the current page/selection:
 * the whole document (page/header/footer templates) or the selected
 * section/block as a preset. The server re-checks every choice.
 */
export function saveScopesFor(doc, pageType, selectedId) {
  const scopes = [];
  if (['page', 'landing', 'system'].includes(pageType)) scopes.push({ type: 'page_template', label: 'template_scope_page', nodeId: null });
  if (pageType === 'header_partial') scopes.push({ type: 'header_preset', label: 'template_scope_header', nodeId: null });
  if (pageType === 'footer_partial') scopes.push({ type: 'footer_preset', label: 'template_scope_footer', nodeId: null });
  const sel = selectedId ? findNode(doc, selectedId) : null;
  if (sel && sel.kind === 'section' && !isGlobalSection(sel.node)) scopes.push({ type: 'section_preset', label: 'template_scope_section', nodeId: sel.node.id });
  if (sel && sel.kind === 'block') scopes.push({ type: 'block_preset', label: 'template_scope_block', nodeId: sel.node.id });
  return scopes;
}

/**
 * Where an inserted preset goes, from the current selection: a section preset
 * after the selected section (or at the end); a block preset at the block
 * insertion point (parent + index) — null when the page needs a section first.
 */
export function insertTargetFor(doc, template, selectedId, insertionPoint) {
  const sel = selectedId ? findNode(doc, selectedId) : null;
  if (template.template_type === 'block_preset') {
    const where = insertionPoint(doc, selectedId);
    return where ? { index: where.index, parent_id: where.parentId } : null;
  }
  const sections = sectionsOf(doc);
  if (sel && sel.kind === 'section') return { index: sel.index + 1, parent_id: null };
  if (sel && sel.kind === 'block') {
    const idx = sections.findIndex((s) => s.id === sel.sectionId);
    return { index: (idx >= 0 ? idx : sections.length - 1) + 1, parent_id: null };
  }
  return { index: sections.length, parent_id: null };
}

/** The section index a new global reference is inserted at (after the selection). */
export function referenceIndexFor(doc, selectedId) {
  const sel = selectedId ? findNode(doc, selectedId) : null;
  const sections = sectionsOf(doc);
  if (sel && sel.kind === 'section') return sel.index + 1;
  if (sel && sel.kind === 'block') {
    const idx = sections.findIndex((s) => s.id === sel.sectionId);
    return (idx >= 0 ? idx : sections.length - 1) + 1;
  }
  return sections.length;
}

export function componentByRef(components, ref) {
  return asList(components).find((c) => c.ref === ref) || null;
}

/** Categories of tokens whose value is a colour (rendered with a swatch / colour picker). */
export const COLOR_CATEGORIES = Object.freeze(['color', 'surface', 'text', 'border']);

export function isHexColor(value) {
  return typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value.trim());
}
