import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  BLOCK_CATEGORY_ORDER, blockInsertState, groupBlocks, isComponentBlock, isElementBlock, presetInsertState, reasonKey, searchAll, variantCards, blocksWithVariants, isLocked, isOffered,
} from '../src/core/addPanel.mjs';
import { isDynamicBlock } from '../src/components/blockKinds.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const manifest = JSON.parse(readFileSync(join(here, 'fixtures', 'manifest.json'), 'utf8'));
const blocks = manifest.blocks;
const emptyDoc = { document_type: 'page', sections: [] };
const oneSection = { document_type: 'page', sections: [{ id: 'sec_a', blocks: [], layout: {} }] };

test('every block is reachable from a tab, and Elements never overlap Components', () => {
  for (const b of blocks) {
    const tabs = [isElementBlock(b), isComponentBlock(b), isDynamicBlock(b)].filter(Boolean).length;
    assert.ok(tabs >= 1, `${b.type} is reachable`);
    assert.ok(!(isElementBlock(b) && isComponentBlock(b)), `${b.type} is not in both Elements and Components`);
  }
  assert.ok(blocks.filter(isElementBlock).length >= 15, 'a real Elements set');
  assert.deepEqual(blocks.filter(isComponentBlock).map((b) => b.type).sort(), ['booking.embed', 'booking.services', 'forms.embed', 'forms.form_card', 'membership.plans']);
});

test('groupBlocks orders categories as designed and keeps unknown ones last', () => {
  const groups = groupBlocks(blocks.filter(isElementBlock));
  const cats = groups.map((g) => g.category);
  for (let i = 1; i < cats.length; i++) {
    const r = (c) => (BLOCK_CATEGORY_ORDER.indexOf(c) === -1 ? 99 : BLOCK_CATEGORY_ORDER.indexOf(c));
    assert.ok(r(cats[i - 1]) <= r(cats[i]), `${cats[i - 1]} before ${cats[i]}`);
  }
  const odd = groupBlocks([{ type: 'x.y', title: 'Odd', category: 'zzz' }, { type: 'core.text', title: 'Text', category: 'content' }]);
  assert.deepEqual(odd.map((g) => g.category), ['content', 'zzz']);
});

test('groupBlocks filters by title, description, category label and type', () => {
  const find = (q) => groupBlocks(blocks, q).flatMap((g) => g.items.map((b) => b.type));
  assert.ok(find('hero').includes('core.hero'));
  assert.ok(find('core.accordion').includes('core.accordion'));
  assert.ok(find('expand').includes('core.accordion'), 'matches the description');
  assert.deepEqual(find('zzzz-nothing'), []);
  assert.ok(groupBlocks(blocks, 'mise en page', (c) => (c === 'layout' ? 'Mise en page' : c)).length > 0, 'matches the translated category label');
});

test('insertion state: ok on an empty page (a section is created), refused at the limits with a reason', () => {
  assert.deepEqual(blockInsertState(emptyDoc, manifest, null, 'core.heading'), { ok: true });
  assert.deepEqual(blockInsertState(oneSection, manifest, 'sec_a', 'core.heading'), { ok: true });
  // the only section is a global reference (it owns no blocks), so a new section would be needed — but the limit is reached
  const onlyGlobal = { document_type: 'page', sections: [{ id: 'sec_g', global_ref: '0f0f0f0f-0f0f-4f0f-8f0f-0f0f0f0f0f0f', blocks: [], layout: {} }] };
  const noSections = { ...manifest, limits: { ...manifest.limits, max_sections: 1 } };
  assert.deepEqual(blockInsertState(onlyGlobal, noSections, null, 'core.heading'), { ok: false, reason: 'sections_limit' });
  const tiny = { ...manifest, limits: { ...manifest.limits, max_blocks: 1 } };
  const full = { document_type: 'page', sections: [{ id: 'sec_a', layout: {}, blocks: [{ id: 'blk_a', type: 'core.text', props: {}, children: [] }] }] };
  assert.deepEqual(blockInsertState(full, tiny, 'sec_a', 'core.heading'), { ok: false, reason: 'blocks_limit' });
  assert.deepEqual(presetInsertState(onlyGlobal, noSections), { ok: false, reason: 'sections_limit' });
  assert.deepEqual(presetInsertState(oneSection, manifest), { ok: true });
  assert.equal(reasonKey('blocks_limit'), 'pal_reason_blocks_limit');
});

test('search spans presets, elements and components; empty query returns nothing', () => {
  const presets = [{ template_key: 'p1', name: 'Pricing', description: 'Three plans', category: 'pricing' }];
  const components = [{ title: 'Site footer', slug: 'footer' }];
  assert.equal(searchAll({ blocks, presets, components }, '').total, 0);
  const r = searchAll({ blocks, presets, components }, 'pric');
  assert.deepEqual(r.presets.map((p) => p.template_key), ['p1']);
  const r2 = searchAll({ blocks, presets, components }, 'footer');
  assert.deepEqual(r2.components.map((c) => c.kind), ['global']);
  const r3 = searchAll({ blocks, presets, components }, 'booking');
  assert.ok(r3.components.some((c) => c.kind === 'block' && c.block.type === 'booking.services'));
  assert.ok(!r3.elements.some((b) => b.type === 'booking.services'));
  assert.equal(r3.total, r3.presets.length + r3.elements.length + r3.dynamic.length + r3.components.length);
  const r4 = searchAll({ blocks, presets, components }, 'post title');
  assert.ok(r4.dynamic.some((b) => b.type === 'theme.post_title'), 'data-bound blocks are searchable too');
  assert.ok(!r4.elements.some((b) => b.type === 'theme.post_title'));
});

test('variants become cards of the real block: its copy, its rules, plus ready-set props', () => {
  const cards = variantCards(manifest);
  assert.equal(cards.length, manifest.variants.length, 'one card per variant');
  for (const c of cards) {
    const base = blocks.find((b) => b.type === c.type);
    assert.ok(base, `${c.variantKey} points at a real block`);
    assert.equal(c.allows_children, base.allows_children, 'the block\'s own capabilities');
    assert.ok(c.variantKey && c.variantProps && Object.keys(c.variantProps).length, 'carries its props');
    assert.ok(isElementBlock(c), `${c.variantKey} is listed under Elements`);
  }
  const cols3 = cards.find((c) => c.variantKey === 'columns-3');
  assert.equal(cols3.type, 'layout.grid');
  assert.equal(cols3.variantProps.columns, 3);
});

test('a variant of a block the tenant is not offered is not shown', () => {
  const trimmed = { ...manifest, blocks: blocks.filter((b) => b.type !== 'layout.grid') };
  const keys = variantCards(trimmed).map((c) => c.variantKey);
  assert.ok(!keys.some((k) => k.startsWith('columns-')), 'no columns without a grid');
  assert.ok(keys.includes('stack'), 'the others stay');
});

test('variants group with their category and are found by search', () => {
  const groups = groupBlocks(blocksWithVariants(manifest).filter(isElementBlock));
  const layout = groups.find((g) => g.category === 'layout').items;
  assert.ok(layout.some((b) => b.variantKey === 'columns-2') && layout.some((b) => b.type === 'layout.grid' && !b.variantKey), 'layout holds the grid and its columns');
  assert.ok(groups.find((g) => g.category === 'forms').items.some((b) => b.variantKey === 'textarea'));
  const found = searchAll({ blocks: blocksWithVariants(manifest) }, 'columns');
  assert.ok(found.elements.filter((b) => b.variantKey).length >= 3, 'three column variants');
  assert.ok(searchAll({ blocks: blocksWithVariants(manifest) }, 'dropdown').elements.some((b) => b.variantKey === 'select'));
});

test('a variant obeys the same insertion rules as its block', () => {
  const card = variantCards(manifest).find((c) => c.variantKey === 'columns-2');
  assert.deepEqual(blockInsertState(oneSection, manifest, null, card.type), { ok: true });
  const tiny = { ...manifest, limits: { ...manifest.limits, max_blocks: 1 } };
  const full = { document_type: 'page', sections: [{ id: 'sec_a', layout: {}, blocks: [{ id: 'blk_a', type: 'core.text', props: {}, children: [] }] }] };
  assert.equal(blockInsertState(full, tiny, 'sec_a', card.type).reason, 'blocks_limit');
});

test('a locked module block is listed but never offered or insertable', () => {
  const locked = { type: 'coaching.demo_locked', title: 'Locked demo', category: 'business', locked: true, locked_module: 'coaching' };
  const m = { ...manifest, blocks: [...manifest.blocks, locked] };
  assert.equal(isLocked(locked), true);
  assert.equal(isOffered(locked), false);
  assert.ok(!blocksWithVariants(m).some((b) => b.type === 'coaching.demo_locked'), 'locked blocks stay out of the Elements tab and search');
  assert.deepEqual(blockInsertState(oneSection, m, 'sec_a', 'coaching.demo_locked'), { ok: false, reason: 'locked' });
  assert.equal(reasonKey('locked'), 'pal_reason_locked');
});
