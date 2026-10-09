import test from 'node:test';
import assert from 'node:assert/strict';
import { disabledInUse, disabledSet, groupElements, isChanged, unusedTypes, usageOf } from '../src/core/elementManager.mjs';
import { blockInsertState, blocksWithVariants, isOffered, searchAll } from '../src/core/addPanel.mjs';

const el = (type, category, extra = {}) => ({ type, title: type.split('.')[1], category, disabled: false, usage: { blocks: 0, pages: 0, sample: [] }, ...extra });
const elements = [
  el('core.heading', 'content', { usage: { blocks: 4, pages: 2, sample: ['Home', 'About'] } }),
  el('core.button', 'content', { disabled: true, usage: { blocks: 1, pages: 1, sample: ['Home'] } }),
  el('layout.flex', 'layout'),
  el('core.countdown', 'content', { disabled: true }),
  el('form.field', 'forms'),
];

test('elements are grouped in the Add panel order and sorted by title', () => {
  const groups = groupElements(elements);
  assert.deepEqual(groups.map((g) => g.category), ['layout', 'content', 'forms']);
  assert.deepEqual(groups[1].items.map((e) => e.type), ['core.button', 'core.countdown', 'core.heading']);
});

test('search matches title, type and category label', () => {
  assert.deepEqual(groupElements(elements, 'flex').flatMap((g) => g.items.map((e) => e.type)), ['layout.flex']);
  assert.deepEqual(groupElements(elements, 'FORMULAIRE', (c) => (c === 'forms' ? 'Formulaires' : c)).flatMap((g) => g.items.map((e) => e.type)), ['form.field']);
  assert.deepEqual(groupElements(elements, 'zzz'), []);
});

test('the selection starts from what the server reports and change is detected both ways', () => {
  const sel = disabledSet(elements);
  assert.deepEqual([...sel].sort(), ['core.button', 'core.countdown']);
  assert.equal(isChanged(elements, new Set(sel)), false);
  assert.equal(isChanged(elements, new Set([...sel, 'layout.flex'])), true);
  assert.equal(isChanged(elements, new Set(['core.button'])), true);
});

test('unused types and switched-off-but-used types are reported', () => {
  assert.deepEqual(unusedTypes(elements).sort(), ['core.countdown', 'form.field', 'layout.flex']);
  assert.deepEqual(disabledInUse(elements, new Set(['core.button', 'core.heading', 'layout.flex'])).map((e) => e.type), ['core.heading', 'core.button']);
  assert.deepEqual(usageOf({}), { blocks: 0, pages: 0, sample: [] });
});

test('a switched-off block is not offered, not searchable and cannot be inserted', () => {
  const manifest = {
    blocks: [
      { type: 'core.heading', title: 'Heading', category: 'content', allows_children: false },
      { type: 'core.button', title: 'Button', category: 'content', disabled: true },
    ],
    variants: [{ key: 'btn-primary', type: 'core.button', title: 'Primary button', description: '', icon: 'x', category: 'content', props: {} }],
    limits: { max_blocks: 250 },
  };
  assert.equal(isOffered(manifest.blocks[1]), false);
  assert.deepEqual(blocksWithVariants(manifest).map((b) => b.type), ['core.heading']);
  assert.equal(searchAll({ blocks: manifest.blocks }, 'button').total, 0);
  assert.equal(searchAll({ blocks: manifest.blocks }, 'heading').total, 1);
  const doc = { sections: [] };
  assert.deepEqual(blockInsertState(doc, manifest, null, 'core.button'), { ok: false, reason: 'disabled' });
});
