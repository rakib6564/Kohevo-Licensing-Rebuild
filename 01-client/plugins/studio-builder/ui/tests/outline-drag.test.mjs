import { test } from 'node:test';
import assert from 'node:assert/strict';
import { autoScrollDelta, dropPositionAt, isSelfOrDescendant } from '../src/core/outlineDrag.mjs';

const top = { top: true, container: true };
const box = { container: true };
const leaf = { container: false };
const widget = { container: false };
const boxed = { container: true };

test('a widget over a top-level Container goes inside it; a Container goes before, after or in its middle', () => {
  assert.equal(dropPositionAt(0.1, top, widget), 'inside', 'a widget cannot sit next to one');
  assert.equal(dropPositionAt(0.9, top, widget), 'inside');
  assert.equal(dropPositionAt(0.2, top, boxed), 'before');
  assert.equal(dropPositionAt(0.8, top, boxed), 'after');
  assert.equal(dropPositionAt(0.5, top, boxed), 'inside', 'Containers nest');
});

test('a container row takes a block on its middle band, other rows split at the half', () => {
  assert.equal(dropPositionAt(0.5, box, widget), 'inside');
  assert.equal(dropPositionAt(0.2, box, widget), 'before');
  assert.equal(dropPositionAt(0.8, box, widget), 'after');
  assert.equal(dropPositionAt(0.5, leaf, widget), 'after', 'a leaf has no inside');
  assert.equal(dropPositionAt(0.4, leaf, widget), 'before');
  assert.equal(dropPositionAt(NaN, leaf, widget), 'after', 'garbage is treated as the middle');
  assert.equal(dropPositionAt(-3, leaf, widget), 'before');
  assert.equal(dropPositionAt(9, leaf, widget), 'after');
});

test('auto-scroll: nothing in the middle, faster the nearer the edge, signed by direction', () => {
  assert.equal(autoScrollDelta(300, 100, 500), 0);
  const slow = autoScrollDelta(140, 100, 500);
  const fast = autoScrollDelta(105, 100, 500);
  assert.ok(slow < 0 && fast < 0 && fast < slow, 'upwards and quicker nearer the top');
  const down = autoScrollDelta(495, 100, 500);
  assert.ok(down > 0 && down <= 18);
  assert.equal(autoScrollDelta(50, 100, 500), -18, 'beyond the edge is full speed');
  assert.equal(autoScrollDelta(900, 100, 500), 18);
  assert.equal(autoScrollDelta(150, 100, 100), 0, 'an empty box never scrolls');
  assert.equal(autoScrollDelta(105, 100, 130, { edge: 48 }), -12, 'a short list uses half its height as the edge band');
});

test('a row, or anything inside it, is never a target for itself', () => {
  const rows = [
    { id: 'a', parentId: null }, { id: 'b', parentId: 'a' }, { id: 'c', parentId: 'b' }, { id: 'd', parentId: null },
  ];
  assert.equal(isSelfOrDescendant(rows, 'a', 'a'), true);
  assert.equal(isSelfOrDescendant(rows, 'a', 'c'), true, 'a grandchild');
  assert.equal(isSelfOrDescendant(rows, 'a', 'd'), false);
  assert.equal(isSelfOrDescendant(rows, 'c', 'a'), false, 'an ancestor is not a descendant');
  assert.equal(isSelfOrDescendant(rows, 'a', 'missing'), false);
});
