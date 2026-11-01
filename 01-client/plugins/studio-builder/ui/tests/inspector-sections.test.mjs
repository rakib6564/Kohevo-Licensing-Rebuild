import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  SECTIONS, TABS, applicableSections, defaultOpenIds, inspectorContext, isSectionOpen, resetSectionState,
  searchSections, sectionsFor, setSectionOpen, subscribeSections, statesFor, titleKeyOf,
} from '../src/core/inspectorSections.mjs';
import { isExtraSection } from '../src/core/inspectorSections.mjs';

const manifest = JSON.parse(readFileSync(new URL('./fixtures/manifest.json', import.meta.url), 'utf8'));
const def = (type) => manifest.blocks.find((b) => b.type === type);
const ctxOf = (type, extra = {}) => inspectorContext({ id: 'blk_1', type, style: {}, ...extra }, def(type));
const ids = (tab, ctx) => sectionsFor(tab, ctx).map((s) => s.id);

test('the registry has unique ids, known tabs and a title key for every section', () => {
  assert.equal(new Set(SECTIONS.map((s) => s.id)).size, SECTIONS.length);
  for (const s of SECTIONS) {
    assert.ok(typeof s.tab === 'function' || TABS.includes(s.tab), s.id);
    assert.ok(s.titleKey, s.id);
    assert.equal(typeof s.appliesTo, 'function');
    assert.equal(typeof s.summary, 'function');
    assert.equal(typeof s.openFor, 'function');
  }
});

test('a text block shows content, the full style stack and the advanced fields, in order', () => {
  const ctx = ctxOf('core.quote');
  assert.deepEqual(ids('content', ctx), ['content']);
  assert.deepEqual(ids('style', ctx), ['align', 'typography', 'background', 'border', 'shadow', 'opacity', 'parts', 'states', 'tokens']);
  // Background and Border are Style for every block; a leaf block keeps Layout and Dimensions under Advanced, after the one group of everyday fields.
  assert.deepEqual(ids('advanced', ctx), ['advanced', 'motion', 'transform', 'responsive', 'attributes', 'custom_css', 'effects', 'identity', 'tag', 'layout', 'dimensions']);
});

test('a block with no text of its own has no Typography section, a text block does; a map has no Background', () => {
  assert.ok(!ids('style', ctxOf('core.map')).includes('background'));
  assert.ok(ids('style', ctxOf('core.divider')).includes('background'));
  for (const type of ['core.image', 'core.video', 'core.gallery', 'core.divider', 'core.spacer', 'core.icon', 'core.map']) {
    assert.ok(!ids('style', ctxOf(type)).includes('typography'), `${type} has no typography`);
  }
  for (const type of ['core.quote', 'core.list']) {
    assert.ok(ids('style', ctxOf(type)).includes('typography'), `${type} has typography`);
  }
  // A Text Editor's and a Text's typography is in their own Style section.
  for (const type of ['core.rich_text', 'core.text']) assert.ok(ids('style', ctxOf(type)).includes('text_style'), `${type} has its own section`);
  // A heading's and a button's typography is in their own Style section.
  assert.ok(ids('style', ctxOf('core.heading')).includes('heading_style'));
  assert.ok(ids('style', ctxOf('core.button')).includes('button_style'));
});

test('a block whose definition narrows style_capabilities hides the sections it cannot take', () => {
  const narrow = inspectorContext({ id: 'blk_1', type: 'x.y', style: {} }, { style_capabilities: ['align', 'opacity'], binding_slots: [] });
  assert.deepEqual(ids('style', narrow), ['align', 'opacity', 'states']);
  const bare = inspectorContext({ id: 'blk_1', type: 'x.y', style: {} }, { style_capabilities: [], binding_slots: [] });
  assert.deepEqual(ids('style', bare), ['states']); // states needs no capability
  assert.deepEqual(ids('advanced', bare), ['advanced', 'motion', 'responsive', 'attributes', 'custom_css', 'identity', 'tag']); // nor do the everyday fields
});

test('the Data section appears only for a block with binding slots', () => {
  const withSlots = manifest.blocks.find((b) => (b.binding_slots || []).length > 0);
  assert.ok(withSlots, 'the manifest has a bound block');
  assert.ok(ids('content', inspectorContext({ id: 'blk_1', type: withSlots.type, style: {} }, withSlots)).includes('data'));
  assert.ok(!ids('content', ctxOf('core.heading')).includes('data'));
});

test('sections start open where they matter: content always; typography for text; an image\'s own Image section', () => {
  assert.deepEqual(defaultOpenIds('content', ctxOf('core.heading')), ['content']);
  assert.deepEqual(defaultOpenIds('style', ctxOf('core.quote')), ['typography']);
  assert.deepEqual(defaultOpenIds('style', ctxOf('core.image')), ['image_style']);
  assert.deepEqual(defaultOpenIds('advanced', ctxOf('core.heading')), ['advanced']);
});

test('a tab with nothing flagged opens its first section, never a wall of closed headers', () => {
  const ctx = ctxOf('layout.flex');
  assert.deepEqual(defaultOpenIds('content', ctx), ['content']); // a container's own Layout props; flex/grid options stay closed
  assert.deepEqual(defaultOpenIds('content', ctxOf('core.container')), ['container']); // the Container section
  assert.deepEqual(defaultOpenIds('style', inspectorContext({ id: 'b', type: 'x.y', style: {} }, { style_capabilities: [] })), ['states']);
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
  assert.equal(summary('advanced'), 'z 5 · .2');
  assert.equal(summary('dimensions'), '100%');
  assert.equal(summary('align'), 'center');
  assert.equal(summary('motion'), 'fade up · hover');
  assert.equal(summary('attributes'), '1');
  assert.equal(summary('responsive'), 'base md');
});

test('search finds sections by title or summary across tabs, case-insensitively, and an empty query finds none', () => {
  const ctx = ctxOf('core.quote', { classNames: ['hero-title'] });
  const title = (s) => ({ typography: 'Typography', section_advanced: 'Advanced' }[s.titleKey] || s.titleKey);
  assert.deepEqual(searchSections(ctx, '', title), []);
  assert.deepEqual(searchSections(ctx, '   ', title), []);
  assert.deepEqual(searchSections(ctx, 'TYPO', title).map((s) => s.id), ['typography']);
  assert.ok(searchSections(ctx, 'adv', title).map((s) => s.id).includes('advanced'));
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

test('each kind of block opens the sections an author reaches for first', () => {
  assert.deepEqual(defaultOpenIds('style', ctxOf('core.button')), ['button_style']);
  assert.deepEqual(defaultOpenIds('content', ctxOf('layout.grid')), ['content']);
  assert.deepEqual(defaultOpenIds('content', ctxOf('core.image')), ['content']);
  assert.deepEqual(defaultOpenIds('style', ctxOf('core.image')), ['image_style']);
  assert.deepEqual(defaultOpenIds('style', ctxOf('core.icon')), ['icon_style']);
  assert.deepEqual(defaultOpenIds('style', ctxOf('core.rich_text')), ['text_style']);
  assert.deepEqual(defaultOpenIds('style', ctxOf('core.heading')), ['heading_style']);
  assert.deepEqual(defaultOpenIds('content', ctxOf('core.card')), ['content']);
  assert.deepEqual(defaultOpenIds('style', ctxOf('core.card')), ['background', 'border', 'dimensions']);
});

test('a top-level Container gets the one Container inspector: same tabs, same sections, as a nested one', () => {
  const nested = ctxOf('core.container');
  const top = inspectorContext({ id: 'blk_top', type: 'core.container', tag: 'section', style: { layout: { content_width: 'boxed', gutter: 'site' } }, metadata: { label: 'Hero' } }, nested.def);
  for (const tab of TABS) assert.deepEqual(sectionsFor(tab, top).map((s) => s.id), sectionsFor(tab, nested).map((s) => s.id), tab);
  assert.deepEqual(defaultOpenIds('advanced', top), defaultOpenIds('advanced', nested));
  assert.ok(searchSections(top, 'radius', (s) => s.titleKey).some((s) => s.id === 'border'), 'its border, sides and corners included');
});

test('a container shows its flex and grid options under Content (closed) and titles its props Layout; a leaf block keeps Layout under Advanced', () => {
  const container = ctxOf('layout.flex');
  assert.ok(ids('content', container).includes('layout'));
  assert.ok(!defaultOpenIds('content', container).includes('layout'));
  assert.equal(titleKeyOf(SECTIONS.find((x) => x.id === 'content'), container), 'section_layout');
  assert.equal(titleKeyOf(SECTIONS.find((x) => x.id === 'layout'), container), 'section_flex_grid');
  assert.equal(titleKeyOf(SECTIONS.find((x) => x.id === 'layout'), ctxOf('core.heading')), 'section_layout');
  assert.ok(!ids('advanced', container).includes('layout'));
  assert.ok(ids('advanced', ctxOf('core.heading')).includes('layout'));
});

test('a Button and a Heading have one Style section each, like the reference editor, holding the sections it replaces', () => {
  assert.deepEqual(ids('style', ctxOf('core.button')), ['button_style', 'opacity', 'parts', 'states', 'tokens']);
  assert.deepEqual(ids('style', ctxOf('core.heading')), ['heading_style', 'background', 'border', 'shadow', 'opacity', 'parts', 'states', 'tokens']);
  assert.equal(titleKeyOf(SECTIONS.find((x) => x.id === 'button_style'), ctxOf('core.button')), 'section_button');
  const title = (s) => ({ section_heading: 'Heading' }[s.titleKey] || s.titleKey);
  assert.ok(searchSections(ctxOf('core.heading'), 'stroke', title).map((s) => s.id).includes('heading_style'), 'its keywords find it');
});

test('the Container block has one Container section under Content, like the reference editor, and the panes it replaces step aside', () => {
  const box = ctxOf('core.container');
  assert.deepEqual(ids('content', box), ['container']);
  assert.equal(titleKeyOf(SECTIONS.find((x) => x.id === 'container'), box), 'section_container');
  // How it sits in its own parent stays, under Advanced; the HTML element is in the Container section.
  assert.ok(!ids('advanced', box).includes('layout'), 'inside Advanced\'s own Layout section');
  assert.deepEqual(ids('style', box).slice(0, 4), ['background', 'overlay', 'border', 'shape_divider'], 'the reference\'s order');
  assert.deepEqual(ids('advanced', box).slice(0, 6), ['advanced', 'motion', 'transform', 'responsive', 'attributes', 'custom_css'], 'the reference\'s order');
  assert.ok(isExtraSection(SECTIONS.find((x) => x.id === 'effects'), box), 'Kohevo\'s own sections sit under More options');
  assert.ok(!ids('advanced', box).includes('tag'));
  // Text alignment and typography are not container options, but a value already set keeps its section.
  assert.ok(!ids('style', box).includes('align'));
  assert.ok(!ids('style', ctxOf('core.container', { style: { align: { base: 'left' } } })).includes('align'), 'the default alignment is not a setting');
  assert.ok(!ids('style', box).includes('typography'));
  const styled = ctxOf('core.container', { style: { align: { base: 'center' }, typography: { size: '2rem' } } });
  assert.ok(ids('style', styled).includes('align'));
  assert.ok(ids('style', styled).includes('typography'));
  assert.ok(!defaultOpenIds('style', box).includes('dimensions'));
  const summary = SECTIONS.find((x) => x.id === 'container').summary;
  assert.equal(summary(box), 'flex');
  assert.equal(summary(ctxOf('core.container', { style: { layout: { display: 'grid', content_width: 'boxed', content_max: '1300px' } } })), 'grid · 1300px');
  // Other containers keep their panes.
  assert.ok(!ids('content', ctxOf('layout.flex')).includes('container'));
});

test('Style Alignment is the only one a text block has; a menu or divider keeps its own in Content', () => {
  const text = inspectorContext({ id: 'blk_1', type: 'core.quote', style: {} }, { style_capabilities: ['align', 'opacity'], binding_slots: [], field_schema: [{ key: 'align', type: 'enum', allowed_values: ['left', 'center', 'right'] }] });
  const flex = inspectorContext({ id: 'blk_2', type: 'layout.flex', style: {} }, { style_capabilities: ['align'], binding_slots: [], allows_children: true, field_schema: [{ key: 'align', type: 'enum', allowed_values: ['start', 'center', 'end', 'stretch'] }] });
  const heading = inspectorContext({ id: 'blk_3', type: 'core.heading', style: {} }, { style_capabilities: ['align'], binding_slots: [], field_schema: [{ key: 'text', type: 'string' }] });
  const align = SECTIONS.find((s) => s.id === 'align');
  assert.equal(titleKeyOf(align, text), 'align');
  assert.equal(titleKeyOf(align, flex), 'align_text');
  assert.equal(titleKeyOf(align, heading), 'align');
  assert.ok(ids('style', text).includes('align'));
  const line = inspectorContext({ id: 'blk_4', type: 'core.divider', style: {} }, { style_capabilities: ['align'], binding_slots: [], field_schema: [] });
  assert.ok(!ids('style', line).includes('align'));
});

test('States: every block offers Hover; only blocks a visitor can focus or press offer Focus and Active; a stored state still shows', () => {
  assert.deepEqual(statesFor('core.heading', undefined), ['hover']);
  assert.deepEqual(statesFor('core.image', {}), ['hover']);
  assert.deepEqual(statesFor('core.button', {}), ['hover', 'focus', 'active']);
  assert.deepEqual(statesFor('core.heading', { disabled: { opacity: 0.5 }, hover: { opacity: 1 } }), ['hover', 'disabled']);
});

test('a video or gallery has its size with its other settings: Dimensions is on the Content tab, after Content', () => {
  for (const type of ['core.video', 'core.gallery']) {
    const ctx = ctxOf(type);
    const content = ids('content', ctx);
    assert.ok(content.includes('dimensions'), `${type}: Dimensions on Content`);
    assert.ok(content.indexOf('content') < content.indexOf('dimensions'), `${type}: after the block's own fields`);
    assert.ok(!ids('style', ctx).includes('dimensions') && !ids('advanced', ctx).includes('dimensions'), `${type}: only once`);
  }
  assert.ok(ids('style', ctxOf('core.card')).includes('dimensions'), 'a container keeps it under Style');
  const image = ctxOf('core.image');
  assert.ok(!['content', 'style', 'advanced'].some((tab) => ids(tab, image).includes('dimensions')), 'an image has it inside its own Image section');
  assert.ok(ids('advanced', ctxOf('core.heading')).includes('dimensions'), 'a text block keeps it under Advanced');
});

test('a Text Editor, a Text, an Image and an Icon have one Style section each, like the reference editor, holding the sections it replaces', () => {
  assert.deepEqual(ids('style', ctxOf('core.rich_text')), ['text_style', 'background', 'border', 'shadow', 'opacity', 'parts', 'states', 'tokens']);
  assert.deepEqual(ids('style', ctxOf('core.text')), ['text_style', 'background', 'border', 'shadow', 'opacity', 'states', 'tokens']);
  assert.deepEqual(ids('style', ctxOf('core.image')), ['image_style', 'background', 'states', 'tokens']);
  assert.ok(ids('style', ctxOf('core.icon')).includes('icon_style'));
  const section = (id) => SECTIONS.find((x) => x.id === id);
  assert.equal(titleKeyOf(section('text_style'), ctxOf('core.rich_text')), 'section_text_editor');
  assert.equal(titleKeyOf(section('text_style'), ctxOf('core.text')), 'section_text');
  assert.equal(titleKeyOf(section('content'), ctxOf('core.image')), 'section_image');
  assert.equal(titleKeyOf(section('content'), ctxOf('core.icon')), 'section_icon');
  const title = (s) => ({ section_image: 'Image' }[s.titleKey] || s.titleKey);
  assert.ok(searchSections(ctxOf('core.image'), 'filters', title).map((s) => s.id).includes('image_style'), 'its keywords find it');
});

test('an Icon\'s alignment is its own field under Content: the generic Style Alignment shows only while it holds a value', () => {
  assert.ok(!ids('style', ctxOf('core.icon')).includes('align'));
  assert.ok(!ids('style', ctxOf('core.icon', { style: { align: { base: 'left' } } })).includes('align'), 'the default left is not a value');
  assert.ok(ids('style', ctxOf('core.icon', { style: { align: { base: 'center' } } })).includes('align'), 'a stored alignment is never hidden');
});

test('a Container has a Background Overlay and a Shape Divider section under Style, after Background; other blocks do not', () => {
  const style = ids('style', ctxOf('core.container'));
  assert.ok(style.indexOf('background') < style.indexOf('overlay') && style.indexOf('overlay') < style.indexOf('border') && style.indexOf('border') < style.indexOf('shape_divider'), style.join(' '));
  for (const type of ['layout.flex', 'core.card', 'core.heading']) {
    assert.ok(!ids('style', ctxOf(type)).includes('overlay') && !ids('style', ctxOf(type)).includes('shape_divider'), type);
  }
  const section = (id) => SECTIONS.find((x) => x.id === id);
  assert.equal(section('shape_divider').summary(ctxOf('core.container', { props: { shape_top: 'wave', shape_bottom: 'none' } })), 'wave');
  assert.equal(section('overlay').summary(ctxOf('core.container', { style: { overlay: { gradient: 'linear-gradient(90deg, #000000, #ffffff)' } } })), 'gradient');
});

test('a widget with named parts has a Parts section in Style; one without has none', () => {
  for (const type of ['core.tabs', 'core.accordion', 'core.stats', 'core.form', 'core.query_loop', 'core.button', 'core.gallery', 'core.nav_menu', 'core.list', 'core.heading']) {
    assert.ok(ids('style', ctxOf(type)).includes('parts'), `${type} has parts`);
  }
  for (const type of ['core.text', 'core.image']) assert.ok(!ids('style', ctxOf(type)).includes('parts'), `${type} has none`);
});
