// A block's HTML attributes, as the Advanced tab edits them (pure).
//
// The server keeps a closed list (`CanonicalDocumentSchema::blockAttributeIssue`): `id`, `role`, `tabindex`,
// `title`, `lang`, `aria-*` and `data-*` (never `data-sb-*`), values a string or number. This mirrors it so the
// editor refuses what the server refuses; `tests/fixtures/block-attributes.json` is checked by both sides.

import { asList, asObject, sectionsOf } from './doc.mjs';

export const NAMES = Object.freeze(['id', 'role', 'tabindex', 'title', 'lang']);
export const PREFIXES = Object.freeze(['aria-', 'data-']);
export const RESERVED_PREFIX = 'data-sb-';
export const MAX_VALUE = 2000;

/** Roles offered in the picker. The server accepts any role string; these are the ones worth choosing from. */
export const ROLES = Object.freeze(['article', 'banner', 'complementary', 'contentinfo', 'group', 'list', 'listitem', 'main', 'navigation', 'note', 'region', 'search', 'presentation']);

/** Why an attribute is refused, or null when the server would accept it. */
export function attributeIssue(name, value) {
  if (typeof name !== 'string' || !/^[a-z][a-z0-9-]*$/.test(name) || name.length > 64) return 'name';
  if (name.startsWith(RESERVED_PREFIX)) return 'reserved';
  const allowed = NAMES.includes(name) || PREFIXES.some((p) => name.startsWith(p) && name.length > p.length);
  if (!allowed) return 'not_allowed';
  if (typeof value !== 'string' && typeof value !== 'number') return 'value';
  if (String(value).length > MAX_VALUE) return 'too_long';
  return null;
}

/** An element id the editor will write: a letter first, then letters, digits, `_` and `-`. */
export function idFormatOk(id) {
  return typeof id === 'string' && /^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(id);
}

/** A CSS class token the renderer will keep (Html::classAttr). */
export function classTokenOk(token) {
  return typeof token === 'string' && /^[a-zA-Z0-9_-]+$/.test(token);
}

/** The id of the block that already uses `id` as its HTML id, or null. `exceptId` is the block being edited. */
export function idOwner(doc, id, exceptId) {
  const walk = (blocks) => {
    for (const b of asList(blocks)) {
      if (b && b.id !== exceptId && asObject(b.attributes).id === id) return b.id;
      const inner = walk(b && b.children);
      if (inner) return inner;
    }
    return null;
  };
  for (const section of sectionsOf(doc)) {
    const hit = walk(section.blocks);
    if (hit) return hit;
  }
  return null;
}

/** A block's attributes grouped for the Advanced tab; `other` keeps anything the dedicated fields do not show. */
export function groupAttributes(attrs) {
  const a = asObject(attrs);
  const data = [];
  const other = [];
  for (const [k, v] of Object.entries(a)) {
    if (k === 'id' || k === 'role' || k === 'aria-label') continue;
    (k.startsWith('data-') ? data : other).push([k, String(v)]);
  }
  return {
    id: a.id === undefined ? '' : String(a.id),
    role: a.role === undefined ? '' : String(a.role),
    ariaLabel: a['aria-label'] === undefined ? '' : String(a['aria-label']),
    data,
    other,
  };
}

/** `attrs` with `name` set to `value`, or removed when `value` is empty/undefined. Key order is kept. */
export function withAttribute(attrs, name, value) {
  const next = { ...asObject(attrs) };
  if (value === undefined || value === null || value === '') delete next[name];
  else next[name] = value;
  return next;
}
