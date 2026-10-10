import { test } from 'node:test';
import assert from 'node:assert/strict';
import { discardOps, editableState, hasChanged, hasStyle, resetOps } from '../src/core/inspectorBar.mjs';

const block = () => ({ id: 'blk_1', type: 'core.heading', props: { text: 'Hi' }, style: { shadow: 'md' }, responsive: { mobile: { hide: true } }, style_states: {}, classNames: [] });

test('reset clears style, device overrides and states, and leaves content', () => {
  const ops = resetOps(block());
  assert.deepEqual(ops.map((o) => o.op), ['update_block_style', 'update_block_responsive']);
  assert.ok(ops.every((o) => o.payload.block_id === 'blk_1'));
  assert.deepEqual(ops[0].payload.style, {});
});

test('a block with no look has nothing to reset', () => {
  const b = { ...block(), style: {}, responsive: {} };
  assert.equal(hasStyle(b), false);
  assert.deepEqual(resetOps(b), []);
  assert.equal(hasStyle(block()), true);
});

test('a block that has not changed since selection has nothing to discard', () => {
  const b = block();
  const base = editableState(b);
  assert.equal(hasChanged(b, base), false);
  assert.deepEqual(discardOps(b, base), []);
});

test('discard puts back every part that changed, and only those', () => {
  const base = editableState(block());
  const edited = { ...block(), props: { text: 'Hello' }, style: { shadow: 'lg', opacity: 0.5 } };
  assert.equal(hasChanged(edited, base), true);
  const ops = discardOps(edited, base);
  assert.deepEqual(ops.map((o) => o.op), ['update_block_props', 'update_block_style']);
  assert.deepEqual(ops[0].payload.props, { text: 'Hi' });
  assert.deepEqual(ops[1].payload.style, { shadow: 'md' });
});

test('a part that did not exist at selection is cleared again, and empty-vs-missing is no change', () => {
  const plain = { id: 'blk_1', type: 'core.text', props: {} };
  const base = editableState(plain);
  assert.equal(hasChanged({ ...plain, style: {} }, base), false);
  const styled = { ...plain, style: { opacity: 0.5 } };
  assert.deepEqual(discardOps(styled, base).map((o) => o.payload.style), [{}]);
});

test('the baseline is a copy, so later edits do not move it', () => {
  const b = block();
  const base = editableState(b);
  b.style.shadow = 'xl';
  assert.equal(base.style.shadow, 'md');
});

const section = () => ({ id: 'sec_1', label: '', layout: { width: 'wide', gap: 'md', background_token: 'surface.alt' }, style: { background: { color: '#fff' } }, visibility: {}, blocks: [] });

test('a section reset clears its style and theme background, and keeps how it arranges', () => {
  const sec = section();
  assert.equal(hasStyle(sec, 'section'), true);
  const ops = resetOps(sec, 'section');
  assert.deepEqual(ops.map((o) => o.op), ['update_section_style', 'update_section_layout']);
  assert.deepEqual(ops[0].payload.style, {});
  assert.deepEqual(ops[1].payload.layout, { width: 'wide', gap: 'md' });
  assert.equal(hasStyle({ id: 's', layout: { width: 'wide' }, style: {} }, 'section'), false);
  assert.deepEqual(resetOps({ id: 's', layout: { width: 'wide' }, style: {} }, 'section'), []);
});

test('a section discard restores the layout, style and visibility that changed', () => {
  const base = editableState(section(), 'section');
  const edited = { ...section(), layout: { width: 'narrow', gap: 'md' }, style: {} };
  assert.equal(hasChanged(edited, base, 'section'), true);
  const ops = discardOps(edited, base, 'section');
  assert.deepEqual(ops.map((o) => o.op), ['update_section_layout', 'update_section_style']);
  assert.deepEqual(ops[0].payload.layout, section().layout);
  assert.deepEqual(ops[1].payload.style, section().style);
  assert.equal(hasChanged(section(), base, 'section'), false);
});
