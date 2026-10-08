// Shell state rules: view modes, multi-select helpers and keyboard inset.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  VIEW_MODES, isEditing, normalizeViewMode, visitorPreviewUrl,
  rangeIds, nextPicked, topLevelIds, keyboardInset,
} from '../src/core/shellState.mjs';

const rows = [
  { id: 's1', parentId: null },
  { id: 'b1', parentId: 's1' },
  { id: 'b2', parentId: 'b1' },
  { id: 'b3', parentId: 's1' },
  { id: 's2', parentId: null },
];

test('only edit mode is interactive and unknown modes fall back to edit', () => {
  assert.deepEqual(VIEW_MODES, ['edit', 'preview', 'visitor']);
  assert.equal(isEditing('edit'), true);
  assert.equal(isEditing('preview'), false);
  assert.equal(isEditing('visitor'), false);
  assert.equal(normalizeViewMode('visitor'), 'visitor');
  assert.equal(normalizeViewMode('nope'), 'edit');
  assert.equal(normalizeViewMode(undefined), 'edit');
});

test('visitor preview targets the authorized preview endpoint for this page', () => {
  assert.equal(visitorPreviewUrl({ previewUrl: '/p/preview.php', pageId: 7 }), '/p/preview.php?page=7');
});

test('rangeIds is inclusive, order-independent and tolerant of a missing anchor', () => {
  assert.deepEqual(rangeIds(rows, 'b1', 'b3'), ['b1', 'b2', 'b3']);
  assert.deepEqual(rangeIds(rows, 'b3', 'b1'), ['b1', 'b2', 'b3']);
  assert.deepEqual(rangeIds(rows, 'gone', 'b3'), ['b3']);
  assert.deepEqual(rangeIds(rows, 'b1', 'gone'), []);
});

test('nextPicked: plain click replaces, toggle adds and removes, shift ranges from the anchor', () => {
  const start = new Set(['b1']);
  assert.deepEqual([...nextPicked(start, rows, 'b1', 'b3')], ['b3']);
  assert.deepEqual([...nextPicked(start, rows, 'b1', 'b3', { toggle: true })].sort(), ['b1', 'b3']);
  assert.deepEqual([...nextPicked(new Set(['b1', 'b3']), rows, 'b1', 'b3', { toggle: true })], ['b1']);
  assert.deepEqual([...nextPicked(start, rows, 'b1', 's2', { shift: true })], ['b1', 'b2', 'b3', 's2']);
  // Shift with no anchor behaves like a plain click.
  assert.deepEqual([...nextPicked(new Set(), rows, null, 'b2', { shift: true })], ['b2']);
});

test('topLevelIds drops rows already covered by a picked ancestor', () => {
  assert.deepEqual(topLevelIds(rows, new Set(['s1', 'b1', 'b2', 's2'])), ['s1', 's2']);
  assert.deepEqual(topLevelIds(rows, new Set(['b1', 'b2'])), ['b1']);
  assert.deepEqual(topLevelIds(rows, new Set(['b2', 'b3'])), ['b2', 'b3']);
});

test('keyboardInset reports the covered height only when it is keyboard-sized', () => {
  assert.equal(keyboardInset(800, { height: 800, offsetTop: 0 }), 0);
  // URL-bar collapse is not a keyboard.
  assert.equal(keyboardInset(800, { height: 740, offsetTop: 0 }), 0);
  assert.equal(keyboardInset(800, { height: 480, offsetTop: 0 }), 320);
  // iOS pans the visual viewport: offsetTop counts as uncovered area above.
  assert.equal(keyboardInset(800, { height: 480, offsetTop: 100 }), 220);
  assert.equal(keyboardInset(800, null), 0);
  assert.equal(keyboardInset(NaN, { height: 1, offsetTop: 0 }), 0);
});
