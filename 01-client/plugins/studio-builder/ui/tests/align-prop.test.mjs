import { test } from 'node:test';
import assert from 'node:assert/strict';
import { alignPropBlocks, effectiveAlign } from '../src/core/alignProp.mjs';

test('the Style alignment is what the block shows, as on the Notre impact eyebrow', () => {
  assert.deepEqual(effectiveAlign({ align: 'left' }, { align: { base: 'center' } }), { base: 'center' });
  assert.deepEqual(effectiveAlign({}, { align: 'right' }), { base: 'right' });
});

test('a centred prop shows when no Style alignment is set; the default left shows nothing', () => {
  assert.deepEqual(effectiveAlign({ align: 'center' }, {}), { base: 'center' });
  assert.equal(effectiveAlign({ align: 'left' }, {}), null);
  assert.equal(alignPropBlocks({ align: 'center' }), true);
  assert.equal(alignPropBlocks({ align: 'left' }), false);
});
