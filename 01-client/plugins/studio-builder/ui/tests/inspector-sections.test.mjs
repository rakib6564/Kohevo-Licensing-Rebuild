import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  SECTIONS, TABS, applicableSections, defaultOpenIds, inspectorContext, isSectionOpen, resetSectionState,
  searchSections, sectionsFor, setSectionOpen, subscribeSections,
} from '../src/core/inspectorSections.mjs';

const manifest = JSON.parse(readFileSync(new URL('./fixtures/manifest.json', import.meta.url), 'utf8'));
const def = (type) => manifest.blocks.find((b) => b.type === type);
const ctxOf = (type, extra = {}) => inspectorContext({ id: 'blk_1', type, style: {}, ...extra }, def(type));
const ids = (tab, ctx) => sectionsFor(tab, ctx).map((s) => s.id);

test('the registry has unique ids, known tabs and a title key for every section', () => {
  assert.equal(new Set(SECTIONS.map((s) => s.id)).size, SECTIONS.length);
  for (const s of SECTIONS) {
    assert.ok(TABS.includes(s.tab), s.id);
    assert.ok(s.titleKey, s.id);
    assert.equal(typeof s.appliesTo, 'function');
    assert.equal(typeof s.summary, 'function');
    assert.equal(typeof s.openFor, 'function');
  }
});

test('a heading shows content, the full style stack and the advanced fields, in order', () => {
  const ctx = ctxOf('core.heading');
  assert.deepEqual(ids('content', ctx), ['content']);
  assert.deepEqual(ids('style', ctx), ['align', 'typography', 'background', 'border', 'shadow', 'dimensions', 'opacity', 'tokens', 'motion', 'visibility', 'responsive']);
  assert.deepEqual(ids('advanced', ctx), ['classes', 'stacking', 'attributes']);
});

test('a media block has no Typography section, a text block does', () => {
  for (const type of ['core.image', 'core.video', 'core.gallery']) {
    assert.ok(!ids('style', ctxOf(type)).includes('typography'), `${type} has no typography`);
  }
  for (const type of ['core.heading', 'core.text', 'core.button', 'core.quote']) {
    assert.ok(ids('style', ctxOf(type)).includes('typography'), `${type} has typography`);
  }
});

test('a block whose definition narrows style_capabilities hides the sections it cannot take', () => {
  const narrow = inspectorContext({ id: 'blk_1', type: 'x.y', style: {} }, { style_capabilities: ['align', 'opacity'], binding_slots: [] });
  assert.deepEqual(ids('style', narrow), ['align', 'opacity', 'motion', 'visibility', 'responsive']);
  const bare = inspectorContext({ id: 'blk_1', type: 'x.y', style: {} }, { style_capabilities: [], binding_slots: [] });
  assert.deepEqual(ids('style', bare), ['motion', 'visibility', 'responsive']);
});

test('the Data section appears only for a block with binding slots', () => {
  const withSlots = manifest.blocks.find((b) => (b.binding_slots || []).length > 0);
  assert.ok(withSlots, 'the manifest has a bound block');
  assert.ok(ids('content', inspectorContext({ id: 'blk_1', type: withSlots.type, style: {} }, withSlots)).includes('data'));
  assert.ok(!ids('content', ctxOf('core.heading')).includes('data'));
});

test('sections start open where they matter: content always; typography for text; dimensions for an image', () => {
  assert.deepEqual(defaultOpenIds('content', ctxOf('core.heading')), ['content']);
  assert.deepEqual(defaultOpenIds('style', ctxOf('core.heading')), ['typography']);
  assert.deepEqual(defaultOpenIds('style', ctxOf('core.image')), ['dimensions']);
  assert.deepEqual(defaultOpenIds('advanced', ctxOf('core.heading')), ['classes']);
});

test('a tab with nothing flagged opens its first section, never a wall of closed headers', () => {
  const ctx = ctxOf('core.container');
  assert.deepEqual(defaultOpenIds('style', ctx), ['align']);
  assert.deepEqual(defaultOpenIds('style', inspectorContext({ id: 'b', type: 'x.y', style: {} }, { style_capabilities: [] })), ['motion']);
});

test('summaries describe what is set, and are empty when nothing is', () => {
  const plain = ctxOf('core.heading');
  for (const s of SECTIONS.filter((x) => x.appliesTo(plain))) assert.equal(s.summary(plain), '', `${s.id} is empty on an untouched block`);
  const styled = ctxOf('core.heading', {
    style: { typography: { size: '2rem', weight: '600' }, color: '#e8734a', background: { gradient: 'linear-gradient(red, blue)' }, border: { width: '2px', style: 'solid' }, opacity: 0.9, z_index: 5, dimensions: { width: '100%' }, align: { base: 'center' } },
    animation: { type: 'fade_up' }, interactions: { trigger: 'hover' }, classNames: ['a', 'b'], attributes: { 'data-x': '1' },
    visibility: { auth_state: 'any', devices: ['base', 'md'] },
  });
  const summary = (id) => SECTIONS.find((s) => s.id === id).summary(styled);
  assert.equal(summary('typography'), '2rem · 600 · #e8734a');
  assert.equal(summary('background'), 'gradient');
  assert.equal(summary('border'), '2px · solid');
  assert.equal(summary('opacity'), '90%');
  assert.equal(summary('stacking'), '5');
  assert.equal(summary('dimensions'), '100%');
  assert.equal(summary('align'), 'center');
  assert.equal(summary('motion'), 'fade up · hover');
  assert.equal(summary('classes'), '2');
  assert.equal(summary('attributes'), '1');
  assert.equal(summary('visibility'), 'base md');
});

test('search finds sections by title or summary across tabs, case-insensitively, and an empty query finds none', () => {
  const ctx = ctxOf('core.heading', { classNames: ['hero-title'] });
  const title = (s) => ({ typography: 'Typography', classes_label: 'Classes' }[s.titleKey] || s.titleKey);
  assert.deepEqual(searchSections(ctx, '', title), []);
  assert.deepEqual(searchSections(ctx, '   ', title), []);
  assert.deepEqual(searchSections(ctx, 'TYPO', title).map((s) => s.id), ['typography']);
  assert.ok(searchSections(ctx, 'class', title).map((s) => s.id).includes('classes'));
  assert.deepEqual(searchSections(ctx, 'zzz', title), []);
});

test('open/closed choices are remembered per block type for the session, notify subscribers, and reset', () => {
  resetSectionState();
  assert.equal(isSectionOpen('core.heading', 'border', false), false);
  let calls = 0;
  const off = subscribeSections(() => { calls += 1; });
  setSectionOpen('core.heading', 'border', true);
  assert.equal(isSectionOpen('core.heading', 'border', false), true);
  assert.equal(isSectionOpen('core.image', 'border', false), false, 'another type is unaffected');
  setSectionOpen('core.heading', 'typography', false);
  assert.equal(isSectionOpen('core.heading', 'typography', true), false, 'a closed choice beats an open default');
  off();
  setSectionOpen('core.heading', 'shadow', true);
  assert.equal(calls, 2, 'an unsubscribed listener is not called');
  resetSectionState();
  assert.equal(isSectionOpen('core.heading', 'border', false), false);
});

test('applicableSections groups the non-empty tabs', () => {
  const groups = applicableSections(ctxOf('core.heading'));
  assert.deepEqual(groups.map((g) => g.tab), ['content', 'style', 'advanced']);
});
