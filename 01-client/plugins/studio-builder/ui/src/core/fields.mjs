// Kohevo Studio builder — metadata-driven field model.
//
// Property controls are generated from the server's transport-safe
// FieldSchema manifest (BlockDefinitionInterface::toEditorManifest()) — there
// is no second, hand-written property catalogue in the UI. This module maps a
// field descriptor to a control kind, coerces raw input into the canonical
// value type, and gives early (advisory) validation hints. The server's
// FieldSchema/DocumentValidator remains the only authority.

import { asList, asObject } from './doc.mjs';

export const CONTROL = Object.freeze({
  string: 'text', text: 'textarea', rich_text: 'richtext', number: 'number', boolean: 'checkbox',
  enum: 'select', url: 'url', image: 'url', media_ref: 'media', media: 'media', token_ref: 'token', link: 'link',
  repeater: 'repeater', object: 'object',
});

export function controlFor(field) {
  return CONTROL[field && field.type] || null;
}

/** Style keys → the token categories that make sense for them. */
export const STYLE_TOKEN_CATEGORIES = Object.freeze({
  surface_token: ['surface', 'color'],
  text_token: ['text', 'color'],
  spacing_token: ['space'],
  radius_token: ['radius'],
  shadow_token: ['shadow'],
  font_token: ['font'],
});

export function tokensFor(manifest, categories = null) {
  const all = asList(manifest && manifest.tokens);
  return categories ? all.filter((t) => categories.includes(t.category)) : all;
}

/** Convert a control's raw input into the canonical value for the field type. */
export function coerce(field, raw) {
  switch (field.type) {
    case 'number': {
      if (raw === '' || raw === null || raw === undefined) return null;
      const n = Number(raw);
      if (!Number.isFinite(n)) return null;
      return field.integer_only ? Math.trunc(n) : n;
    }
    case 'boolean':
      return !!raw;
    case 'string':
      // Single-line fields: newlines are not allowed by the server schema.
      return String(raw ?? '').replace(/[\r\n]+/g, ' ');
    case 'url':
    case 'token_ref':
      return raw === '' || raw === null || raw === undefined ? null : String(raw);
    default:
      return raw;
  }
}

const DANGEROUS_SCHEME = /^\s*(javascript|data|vbscript|file|blob|about):/i;

/** Advisory, client-side check. Returns a message key or null. */
export function hint(field, value) {
  const empty = value === null || value === undefined || value === '';
  if (field.required && empty) return 'field_required';
  if (empty) return null;
  switch (field.type) {
    case 'string':
    case 'text':
      if (typeof field.max_length === 'number' && String(value).length > field.max_length) return 'field_too_long';
      if (typeof field.min_length === 'number' && String(value).length < field.min_length) return 'field_too_short';
      return null;
    case 'number':
      if (typeof value !== 'number') return 'field_number';
      if (field.integer_only && !Number.isInteger(value)) return 'field_integer';
      if (typeof field.min === 'number' && value < field.min) return 'field_too_small';
      if (typeof field.max === 'number' && value > field.max) return 'field_too_large';
      return null;
    case 'url':
      return isLikelySafeUrl(value) ? null : 'field_url';
    case 'link': {
      const link = asObject(value);
      if (!link.label || !String(link.label).trim()) return 'field_link_label';
      return isLikelySafeUrl(link.href) ? null : 'field_url';
    }
    case 'media_ref':
      return Number.isInteger(asObject(value).media_id) && asObject(value).media_id > 0 ? null : 'field_media';
    case 'repeater':
      return typeof field.max_items === 'number' && asList(value).length > field.max_items ? 'field_too_many' : null;
    default:
      return null;
  }
}

/** Mirrors FieldSchema::isSafeUrl() closely enough to warn early. */
export function isLikelySafeUrl(value) {
  if (typeof value !== 'string') return false;
  const v = value.trim();
  if (!v || v.length > 2048 || DANGEROUS_SCHEME.test(v.replace(/\s+/g, ''))) return false;
  if (v.startsWith('/')) return !v.startsWith('//');
  if (v.startsWith('#')) return /^#[A-Za-z0-9_.-]+$/.test(v);
  return /^https?:\/\/[^\s/$.?#].[^\s]*$/i.test(v) || /^mailto:[^\s@]+@[^\s@]+\.[^\s@]+$/i.test(v) || /^tel:\+?[0-9() -]{3,32}$/.test(v);
}

/** A fresh item for a repeater, from its item schema's defaults. */
export function newRepeaterItem(itemSchema) {
  const item = {};
  for (const f of asList(itemSchema)) {
    item[f.key] = f.default === undefined ? null : clone(f.default);
  }
  return item;
}

/**
 * Fields the user must fill BEFORE a block can be inserted: required fields
 * whose declared default is not a valid value (e.g. core.image's media,
 * core.button's link). Everything else is inserted with its defaults.
 */
export function setupFields(blockManifest) {
  return asList(blockManifest && blockManifest.field_schema).filter((f) => f.required && hint(f, f.default) !== null);
}

/**
 * Default bindings for a module block: each declared slot bound to its
 * provider when that provider takes no required parameters; otherwise the
 * slot is returned in `needsParams` for the insert dialog.
 */
export function defaultBindings(blockManifest, manifest) {
  const bindings = {};
  const needsParams = [];
  for (const slot of asList(blockManifest && blockManifest.binding_slots)) {
    const provider = asList(manifest && manifest.providers).find((p) => p.key === slot.provider);
    if (!provider) continue;
    const required = asList(provider.params).filter((f) => f.required);
    if (required.length) needsParams.push({ slot: slot.slot, provider: slot.provider, params: asList(provider.params) });
    else bindings[slot.slot] = { provider: slot.provider };
  }
  return { bindings, needsParams };
}

export function clone(value) {
  return value === null || typeof value !== 'object' ? value : JSON.parse(JSON.stringify(value));
}
