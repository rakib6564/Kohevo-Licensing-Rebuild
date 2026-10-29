// Display conditions (Theme Builder): where a header, footer or template applies.
//
// The server stores them in `settings.conditions` as `{ rules: [...] }` and `TemplateConditionMatcher` reads them. This file
// is the pure side of the dialog that edits them: stored rules -> editable rows -> stored rules, plus the checks the dialog
// shows. A rule the dialog cannot express (written by hand or by an older screen) is kept as a "custom" row and saved back
// unchanged, so opening and saving the dialog never loses a condition.

import { asList, asObject } from './doc.mjs';

export const MAX_RULES = 30;
export const MAX_VALUE = 100;

/** Kinds a row can have, in menu order; `input` is what the row asks for besides include/exclude. */
export const KINDS = Object.freeze([
  { id: 'entire_site', input: null },
  { id: 'front_page', input: null },
  { id: 'singular', input: 'content_type' },
  { id: 'category', input: 'text' },
  { id: 'tag', input: 'text' },
  { id: 'author', input: 'number' },
  { id: 'id', input: 'number' },
  { id: 'slug', input: 'text' },
  { id: 'archive', input: 'optional_text' },
  { id: '404', input: null },
  { id: 'search', input: null },
]);

export const CONTENT_TYPES = Object.freeze(['all', 'page', 'post', 'portfolio', 'services']);

// More content types from the boot payload (`boot.contentTypes`): the site's own types (Types & fields) and the
// ones a plugin keeps in its own tables (Shop's products). Each is offered with its own name.
let extraTypes = [];

/** `list` is boot.contentTypes: [{key, label}]. Malformed entries and the built-in keys are dropped. */
export function setContentTypes(list) {
  const seen = new Set(CONTENT_TYPES);
  extraTypes = (Array.isArray(list) ? list : [])
    .filter((c) => c && typeof c.key === 'string' && /^[a-z][a-z0-9_]{0,31}$/.test(c.key) && !seen.has(c.key) && seen.add(c.key))
    .map((c) => ({ key: c.key, label: typeof c.label === 'string' && c.label !== '' ? c.label : c.key }));
}

/** Every content type key a `singular` row may name. */
export const contentTypes = () => [...CONTENT_TYPES, ...extraTypes.map((c) => c.key)];

/** The name of an extra content type, or null for a built-in one (those are translated messages). */
export const contentTypeLabel = (key) => (extraTypes.find((c) => c.key === key) || {}).label || null;

const KIND_IDS = new Set(KINDS.map((k) => k.id));

/**
 * The action a stored rule carries: `action`, or the older `type` when that says include/exclude (a `type` that names
 * a scope is an include). An action the server does not know (a misspelled "excludes") is null: the server ignores
 * that rule, so the dialog keeps it as a custom row instead of showing it as an include.
 */
function actionOf(rule) {
  const known = (a) => (a === 'include' || a === 'exclude' ? a : null);
  if (rule.action !== undefined) return known(String(rule.action).trim().toLowerCase());
  if (rule.type === undefined) return 'include';
  const type = String(rule.type).trim().toLowerCase();
  if (known(type)) return type;
  return String(rule.condition ?? rule.scope ?? '').trim() !== '' ? null : 'include';
}

/** The scope a stored rule names (`condition`, `scope`, or a `type` that is not include/exclude). */
function scopeOf(rule) {
  const direct = String(rule.condition ?? rule.scope ?? '').trim().toLowerCase();
  if (direct !== '') return direct;
  const type = String(rule.type ?? '').trim().toLowerCase();
  return type === 'include' || type === 'exclude' ? '' : type;
}

/** Stored conditions (`{rules}`, a list, or a single rule) as a list of rule objects. */
export function rulesOf(conditions) {
  if (conditions === null || conditions === undefined) return [];
  if (Array.isArray(conditions)) return conditions.filter((r) => r && typeof r === 'object' && !Array.isArray(r));
  const obj = asObject(conditions);
  if (Array.isArray(obj.rules)) return rulesOf(obj.rules);
  return Object.keys(obj).length ? [obj] : [];
}

/** One stored rule as an editable row. */
export function toRow(rule) {
  const action = actionOf(rule);
  if (action === null) return { action: 'include', kind: 'custom', value: '', raw: rule };
  const scope = scopeOf(rule);
  const value = rule.value ?? '';
  const row = (kind, v = '') => ({ action, kind, value: String(v) });
  if (scope === '' || scope === 'entire_site' || scope === 'all' || scope === 'global') return row('entire_site');
  if (scope === 'front_page' || scope === 'home') return row('front_page');
  if (scope === 'singular' || scope === 'post_type' || scope === 'page_type') {
    const v = String(rule.value ?? rule.post_type ?? 'all').toLowerCase();
    return contentTypes().includes(v) && !rule.field ? row('singular', v) : { action, kind: 'custom', value: '', raw: rule };
  }
  if (scope === 'category') return row('category', rule.value ?? rule.category ?? '');
  if (scope === 'tag') return row('tag', rule.value ?? rule.tag ?? '');
  if (scope === 'author') return row('author', rule.value ?? rule.author_id ?? '');
  if (scope === 'singular_id' || scope === 'id') return row('id', rule.value ?? rule.id ?? '');
  if (scope === 'slug') return row('slug', rule.value ?? rule.slug ?? '');
  if (scope === 'archive') {
    const term = rule.term ?? '';
    const v = String(value).toLowerCase();
    return term !== '' ? row('archive', term) : (v === '' || v === 'all' ? row('archive', '') : { action, kind: 'custom', value: '', raw: rule });
  }
  if (scope === '404' || scope === 'not_found') return row('404');
  if (scope === 'search') return row('search');
  return { action, kind: 'custom', value: '', raw: rule };
}

export const toRows = (conditions) => rulesOf(conditions).map(toRow);

/** One row as the stored rule the matcher reads. */
export function fromRow(row) {
  if (row.kind === 'custom') return row.raw;
  const action = row.action === 'exclude' ? 'exclude' : 'include';
  const text = String(row.value ?? '').trim();
  switch (row.kind) {
    case 'singular': return { action, condition: 'singular', value: contentTypes().includes(text) ? text : 'all' };
    case 'category': case 'tag': case 'slug': return { action, condition: row.kind, value: text };
    case 'author': case 'id': return { action, condition: row.kind, value: Number.parseInt(text, 10) };
    case 'archive': return text === '' ? { action, condition: 'archive', value: 'all' } : { action, condition: 'archive', term: text };
    default: return { action, condition: KIND_IDS.has(row.kind) ? row.kind : 'entire_site' };
  }
}

export const fromRows = (rows) => ({ rules: asList(rows).map(fromRow) });

/** A row's problem as a message key, or null. */
export function rowProblem(row) {
  if (row.kind === 'custom') return null;
  const kind = KINDS.find((k) => k.id === row.kind);
  if (!kind) return 'cond_problem_kind';
  const text = String(row.value ?? '').trim();
  if (kind.input === 'text' && text === '') return 'cond_problem_value';
  if (kind.input === 'number' && !/^[1-9][0-9]{0,9}$/.test(text)) return 'cond_problem_number';
  if (text.length > MAX_VALUE) return 'cond_problem_long';
  return null;
}

/** Problems of the whole set: too many rows, a bad row, or only exclusions (they never select anything alone). */
export function setProblem(rows) {
  const list = asList(rows);
  if (list.length > MAX_RULES) return 'cond_problem_many';
  if (list.some((r) => rowProblem(r))) return 'cond_problem_row';
  if (list.length > 0 && !list.some((r) => r.action === 'include')) return 'cond_needs_include';
  return null;
}

/** A rule set as one comparable text: the same rules in any order and letter case give the same key ('' for none). */
export function conditionsKey(conditions) {
  return toRows(conditions)
    .map((r) => (r.kind === 'custom' ? `custom|${JSON.stringify(r.raw)}` : `${r.action}|${r.kind}|${String(r.value ?? '').trim().toLowerCase()}`))
    .sort()
    .join('\n');
}

/**
 * The published templates of the same role (`peers` from the server) whose conditions are the same as these: they
 * would tie with this one, and the site shows the one published most recently (ThemeTemplateResolver).
 */
export function sameConditionsAs(conditions, peers) {
  const key = conditionsKey(conditions);
  if (key === '') return [];
  return asList(peers).filter((p) => p && conditionsKey(p.conditions) === key);
}
