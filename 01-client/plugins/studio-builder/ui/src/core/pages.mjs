// Pure helpers for the Pages tab: which pages are listed, their status chips, and the names of copies.

import { slugify } from './library.mjs';
import { asList } from './doc.mjs';

/** Page types an author navigates between. Header/footer partials, components and presets are not pages. */
export const NAVIGABLE_PAGE_TYPES = ['page', 'landing'];

export const listablePages = (pages) => asList(pages).filter((p) => p && NAVIGABLE_PAGE_TYPES.includes(p.page_type) && p.status !== 'archived');

/** Status chips for one page, as message-key suffixes, in display order. */
export function pageChips(page) {
  const chips = [];
  if (page.route_mode === 'homepage') chips.push('homepage');
  if (page.is_published) chips.push(page.has_unpublished_changes ? 'changes' : 'published');
  else chips.push('draft');
  return chips;
}

/** Title and slug suggested for a copy: "About (copy)" and "about-copy". */
export function copyNames(page, suffix) {
  return { title: `${page.title}${suffix}`.slice(0, 255), slug: slugify(`${page.slug || page.title}-copy`, 191) };
}

export const isValidSlug = (slug) => /^[a-z0-9]([a-z0-9-]{0,189}[a-z0-9])?$/.test(String(slug || ''));

/** The first server-side validation message of a failed call, else the error's own message. */
export function failureDetail(error) {
  const detail = error && error.details && Array.isArray(error.details.errors) ? error.details.errors[0] : null;
  return detail && detail.message ? detail.message : '';
}

/** Which fields a rename actually changes (the API refuses an empty change). */
export function renameChanges(page, title, slug) {
  const changes = {};
  if (title !== page.title) changes.title = title;
  if (slug !== page.slug) changes.slug = slug;
  return changes;
}

/**
 * What a header or footer region of the current page resolves to, from the chrome binding the server returns:
 * hidden on this page, the page's own partial, the site-wide partial, or the built-in markup.
 */
export function partialRef(binding) {
  if (!binding) return null;
  const ref = (p) => (p ? { id: Number(p.id), title: String(p.title || '') } : null);
  if (binding.resolved === 'hidden') return { kind: 'hidden', page: null };
  if (binding.resolved === 'custom' && binding.custom) return { kind: 'custom', page: ref(binding.custom) };
  if (binding.resolved === 'site' && binding.site) return { kind: 'site', page: ref(binding.site) };
  return { kind: 'builtin', page: null };
}
