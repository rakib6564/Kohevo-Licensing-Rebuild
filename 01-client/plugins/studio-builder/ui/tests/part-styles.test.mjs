// Part styles (BlockParts.php): the editor writes one part's partial style, drops what is empty, and the operation
// replaces `style_parts` as the server's applier does.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { writePart } from '../src/core/partStyles.mjs';
import * as ops from '../src/core/operations.mjs';
import { block, doc, topContainer } from './helpers.mjs';

test('writePart sets one part and prunes empty groups and parts', () => {
  const one = writePart({}, 'tab', { color: '#111', typography: { size: '15px', weight: undefined }, padding: {} });
  assert.deepEqual(one, { tab: { color: '#111', typography: { size: '15px' } } });
  const two = writePart(one, 'tab:hover', { background: { color: '#eee' } });
  assert.deepEqual(Object.keys(two), ['tab', 'tab:hover']);
  assert.deepEqual(writePart(two, 'tab', { typography: { size: '' } }), { 'tab:hover': { background: { color: '#eee' } } });
  assert.deepEqual(one, { tab: { color: '#111', typography: { size: '15px' } } }, 'never mutates');
});

test('the operation sets and clears style_parts on the block', () => {
  const b = block('core.tabs', { items: [] });
  const d = doc([topContainer([b])]);
  const set = ops.applyLocal(d, ops.updateBlockStyleParts(b.id, { panel: { padding: { top: '24px' } } }));
  assert.deepEqual(set.containers[0].children[0].style_parts, { panel: { padding: { top: '24px' } } });
  const cleared = ops.applyLocal(set, ops.updateBlockStyleParts(b.id, {}));
  assert.equal('style_parts' in cleared.containers[0].children[0], false);
});
