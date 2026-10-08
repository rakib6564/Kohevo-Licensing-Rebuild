import { test } from 'node:test';
import assert from 'node:assert/strict';
import { copyNames, failureDetail, isValidSlug, listablePages, pageChips, renameChanges } from '../src/core/pages.mjs';

const page = (over = {}) => ({ id: 1, title: 'About us', slug: 'about', page_type: 'page', status: 'draft', route_mode: 'standalone', is_published: false, has_unpublished_changes: false, ...over });

test('only pages and landing pages are navigable; partials, components and archived pages are not', () => {
  const list = listablePages([page(), page({ id: 2, page_type: 'landing' }), page({ id: 3, page_type: 'header_partial' }), page({ id: 4, page_type: 'section_preset' }), page({ id: 5, status: 'archived' }), null]);
  assert.deepEqual(list.map((p) => p.id), [1, 2]);
  assert.deepEqual(listablePages(undefined), []);
});

test('status chips: draft, published, published with changes, and the homepage marker', () => {
  assert.deepEqual(pageChips(page()), ['draft']);
  assert.deepEqual(pageChips(page({ is_published: true })), ['published']);
  assert.deepEqual(pageChips(page({ is_published: true, has_unpublished_changes: true })), ['changes']);
  assert.deepEqual(pageChips(page({ is_published: true, route_mode: 'homepage' })), ['homepage', 'published']);
  assert.deepEqual(pageChips(page({ has_unpublished_changes: true })), ['draft'], 'an unpublished page is a draft however many edits it has');
});

test('a copy gets a suffixed title and a slug that is valid and different', () => {
  const c = copyNames(page(), ' (copy)');
  assert.equal(c.title, 'About us (copy)');
  assert.equal(c.slug, 'about-copy');
  assert.ok(isValidSlug(c.slug));
  assert.equal(copyNames(page({ title: 'x'.repeat(255) }), ' (copy)').title.length, 255, 'never longer than the server accepts');
  assert.ok(isValidSlug(copyNames(page({ slug: '', title: 'Café Menü' }), '').slug), 'falls back to the title');
});

test('slug validation mirrors the server shape', () => {
  for (const ok of ['a', 'about', 'a-b-c', 'a1', '2024-sale']) assert.ok(isValidSlug(ok), ok);
  for (const bad of ['', '-a', 'a-', 'A', 'a b', 'a_b', 'é', 'a'.repeat(192)]) assert.ok(!isValidSlug(bad), bad);
});

test('a rename sends only what changed', () => {
  assert.deepEqual(renameChanges(page(), 'About us', 'about'), {});
  assert.deepEqual(renameChanges(page(), 'About Kohevo', 'about'), { title: 'About Kohevo' });
  assert.deepEqual(renameChanges(page(), 'About us', 'team'), { slug: 'team' });
  assert.deepEqual(renameChanges(page(), 'Team', 'team'), { title: 'Team', slug: 'team' });
});

test('the first validation message is surfaced, otherwise nothing', () => {
  assert.equal(failureDetail({ details: { errors: [{ message: 'A page with slug exists.' }] } }), 'A page with slug exists.');
  assert.equal(failureDetail({ code: 'server_error' }), '');
  assert.equal(failureDetail(null), '');
});
