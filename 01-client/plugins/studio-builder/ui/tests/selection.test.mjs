// Selection model, overlay geometry, sheet snapping and the mobile-shell threshold.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  EMPTY_SELECTION, selectOnly, clearSelection, toggleSelected, selectRange, clickSelect,
  setHover, setFocus, remapSelection, pruneSelection, actionIds, isSelected, selectionCount,
} from '../src/core/selection.mjs';
import {
  rect, frameScale, toOverlayRect, intersects, clipTo, unionRect, placeFloating, selectionCorners, stepId,
} from '../src/core/overlayGeometry.mjs';
import { snapHeights, dragHeight, settle } from '../src/core/sheetSnap.mjs';
import { MOBILE_SHELL_MAX_WIDTH, isMobileShellWidth } from '../src/core/shellState.mjs';

const rows = [
  { id: 's1', parentId: null },
  { id: 'b1', parentId: 's1' },
  { id: 'b2', parentId: 'b1' },
  { id: 'b3', parentId: 's1' },
  { id: 's2', parentId: null },
];

// ── selection ──────────────────────────────────────────────────────────────

test('selection starts empty and is immutable', () => {
  assert.deepEqual(EMPTY_SELECTION, { primary: null, ids: [], hover: null, focus: null });
  assert.throws(() => { 'use strict'; EMPTY_SELECTION.ids.push('x'); }, TypeError);
  const next = selectOnly(EMPTY_SELECTION, 'b1');
  assert.equal(EMPTY_SELECTION.primary, null, 'the original is untouched');
  assert.deepEqual(next, { primary: 'b1', ids: ['b1'], hover: null, focus: null });
});

test('selectOnly replaces the selection, keeps hover/focus, and a falsy id clears it', () => {
  let sel = setFocus(setHover(selectOnly(EMPTY_SELECTION, 'b1'), 'b3'), 'b1');
  sel = selectOnly(sel, 's1');
  assert.deepEqual(sel, { primary: 's1', ids: ['s1'], hover: 'b3', focus: 'b1' });
  assert.deepEqual(selectOnly(sel, null).ids, []);
  assert.equal(clearSelection(sel).primary, null);
});

test('toggle adds, removes, and hands the primary to the latest survivor', () => {
  let sel = selectOnly(EMPTY_SELECTION, 'b1');
  sel = toggleSelected(sel, 'b3');
  assert.deepEqual([sel.primary, sel.ids], ['b3', ['b1', 'b3']]);
  sel = toggleSelected(sel, 'b3');
  assert.deepEqual([sel.primary, sel.ids], ['b1', ['b1']]);
  sel = toggleSelected(sel, 'b1');
  assert.deepEqual([sel.primary, sel.ids], [null, []]);
  assert.equal(toggleSelected(sel, null), sel, 'a falsy id is a no-op');
});

test('shift selects the visual range from the primary and keeps it when the anchor is gone', () => {
  const sel = selectRange(selectOnly(EMPTY_SELECTION, 'b1'), rows, 's2');
  assert.deepEqual(sel.ids, ['b1', 'b2', 'b3', 's2']);
  assert.equal(sel.primary, 's2');
  const back = selectRange(selectOnly(EMPTY_SELECTION, 's2'), rows, 'b2');
  assert.deepEqual(back.ids, ['b2', 'b3', 's2']);
  const orphan = selectRange(selectOnly(EMPTY_SELECTION, 'gone'), rows, 'b3');
  assert.deepEqual(orphan.ids, ['b3'], 'anchor missing from the rows → just the clicked row');
  const base = selectOnly(EMPTY_SELECTION, 'b1');
  assert.equal(selectRange(base, rows, 'nope'), base, 'an unknown target leaves the selection alone');
});

test('clickSelect routes plain / toggle / shift the same for canvas and Layers', () => {
  const one = clickSelect(EMPTY_SELECTION, rows, 'b1');
  assert.deepEqual(one.ids, ['b1']);
  assert.deepEqual(clickSelect(one, rows, 'b3', { toggle: true }).ids, ['b1', 'b3']);
  assert.deepEqual(clickSelect(one, rows, 'b3', { shift: true }).ids, ['b1', 'b2', 'b3']);
  assert.deepEqual(clickSelect(EMPTY_SELECTION, rows, 'b3', { shift: true }).ids, ['b3'], 'shift with no anchor selects just that node');
  assert.deepEqual(clickSelect(one, rows, 'b3').ids, ['b3']);
});

test('hover and focus are independent of the selection and do not churn identity', () => {
  const sel = selectOnly(EMPTY_SELECTION, 'b1');
  assert.equal(setHover(sel, null), sel);
  assert.equal(setFocus(sel, null), sel);
  const hovered = setHover(sel, 'b3');
  assert.equal(hovered.hover, 'b3');
  assert.equal(setHover(hovered, 'b3'), hovered);
  assert.deepEqual(hovered.ids, ['b1']);
});

test('provisional tmp_ ids are remapped everywhere once the server confirms', () => {
  let sel = toggleSelected(selectOnly(EMPTY_SELECTION, 'tmp_a'), 'b1');
  sel = setFocus(setHover(sel, 'tmp_a'), 'tmp_a');
  const mapped = remapSelection(sel, new Map([['tmp_a', 'blk_real']]));
  assert.deepEqual(mapped, { primary: 'b1', ids: ['blk_real', 'b1'], hover: 'blk_real', focus: 'blk_real' });
  assert.equal(remapSelection(sel, new Map()), sel);
  assert.equal(remapSelection(sel, null), sel);
});

test('pruning removes deleted nodes; the latest survivor becomes primary; nothing left → empty', () => {
  const sel = setHover(toggleSelected(toggleSelected(selectOnly(EMPTY_SELECTION, 'b1'), 'b2'), 'b3'), 'b3');
  const alive = new Set(['b1', 'b2']);
  const pruned = pruneSelection(sel, (id) => alive.has(id));
  assert.deepEqual([pruned.primary, pruned.ids, pruned.hover], ['b2', ['b1', 'b2'], null]);
  assert.deepEqual(pruneSelection(pruned, () => false), { primary: null, ids: [], hover: null, focus: null });
  assert.equal(pruneSelection(pruned, () => true), pruned, 'nothing removed → same object');
});

test('bulk actions touch only top-level selected nodes', () => {
  const sel = clickSelect(clickSelect(selectOnly(EMPTY_SELECTION, 's1'), rows, 'b2', { toggle: true }), rows, 's2', { toggle: true });
  assert.deepEqual(actionIds(sel, rows), ['s1', 's2'], 'b2 is covered by its selected ancestor s1');
  assert.equal(isSelected(sel, 'b2'), true);
  assert.equal(selectionCount(sel), 3);
});

// ── overlay geometry ───────────────────────────────────────────────────────

test('frame scale is the rendered/layout width ratio and defaults to 1', () => {
  assert.equal(frameScale({ width: 195 }, 390), 0.5);
  assert.equal(frameScale(null, 390), 1);
  assert.equal(frameScale({ width: 200 }, 0), 1);
});

test('a node rect maps into overlay coordinates through frame offset and scale', () => {
  const node = rect(100, 50, 200, 80);
  const frame = rect(320, 120, 640, 900); // iframe in the parent, rendered at 0.5 of a 1280 layout
  const container = rect(300, 100, 800, 700);
  assert.deepEqual(toOverlayRect(node, frame, container, 0.5), rect(20 + 50, 20 + 25, 100, 40));
  assert.deepEqual(toOverlayRect(node, frame, container), rect(20 + 100, 20 + 50, 200, 80));
});

test('intersection, clipping and union', () => {
  const a = rect(0, 0, 100, 100);
  assert.equal(intersects(a, rect(90, 90, 50, 50)), true);
  assert.equal(intersects(a, rect(100, 0, 50, 50)), false, 'touching edges do not overlap');
  assert.deepEqual(clipTo(rect(-20, 10, 60, 30), a), rect(0, 10, 40, 30));
  assert.equal(clipTo(rect(200, 200, 10, 10), a), null);
  assert.deepEqual(unionRect([rect(10, 10, 20, 20), null, rect(50, 5, 10, 10)]), rect(10, 5, 50, 25));
  assert.equal(unionRect([]), null);
});

test('floating toolbar sits above, flips below, then inside; never leaves the bounds', () => {
  const bounds = rect(0, 0, 800, 600);
  const bar = { width: 200, height: 32 };
  assert.deepEqual(placeFloating(rect(100, 100, 300, 80), bar, bounds), { left: 100, top: 62, placement: 'above' });
  assert.deepEqual(placeFloating(rect(100, 10, 300, 80), bar, bounds), { left: 100, top: 96, placement: 'below' });
  const tall = placeFloating(rect(100, 0, 300, 600), bar, bounds);
  assert.equal(tall.placement, 'inside');
  assert.ok(tall.top >= 0 && tall.top + 32 <= 600);
  // horizontal clamp, and right alignment
  assert.equal(placeFloating(rect(700, 100, 90, 80), bar, bounds).left, 600);
  assert.equal(placeFloating(rect(-50, 100, 100, 80), bar, bounds).left, 0);
  assert.equal(placeFloating(rect(100, 100, 300, 80), bar, bounds, { align: 'right' }).left, 200);
});

test('selection corners are visual markers at the four corners', () => {
  assert.deepEqual(selectionCorners(rect(10, 20, 100, 50)), [
    { x: 10, y: 20 }, { x: 110, y: 20 }, { x: 10, y: 70 }, { x: 110, y: 70 },
  ]);
});

test('roving tabindex stepping clamps at both ends and starts sensibly', () => {
  const order = ['a', 'b', 'c'];
  assert.equal(stepId(order, 'a', 1), 'b');
  assert.equal(stepId(order, 'c', 1), 'c');
  assert.equal(stepId(order, 'a', -1), 'a');
  assert.equal(stepId(order, null, 1), 'a');
  assert.equal(stepId(order, 'zz', -1), 'c');
  assert.equal(stepId([], 'a', 1), null);
});

// ── sheet snapping ─────────────────────────────────────────────────────────

test('snap heights are ascending, deduplicated and bounded', () => {
  assert.deepEqual(snapHeights(800), [320, 560, 736]);
  assert.deepEqual(snapHeights(300), [160, 210, 276].filter((h) => h <= 300));
  assert.deepEqual(snapHeights(200, [0.1, 0.2, 0.3], { minHeight: 160 }), [160]);
});

test('dragging follows the finger and never exceeds the top snap', () => {
  const snaps = [320, 560, 736];
  assert.equal(dragHeight(560, 100, snaps), 460);
  assert.equal(dragHeight(560, -400, snaps), 736);
  assert.equal(dragHeight(320, 900, snaps), 0);
});

test('release settles on the nearest snap, honours flings, and closes when dragged low', () => {
  const snaps = [320, 560, 736];
  assert.equal(settle(500, 0, snaps), 560);
  assert.equal(settle(340, 0, snaps), 320);
  assert.equal(settle(500, 0.9, snaps), 320, 'fast downward fling → next snap down');
  assert.equal(settle(500, -0.9, snaps), 560, 'fast upward fling → next snap up');
  assert.equal(settle(700, -0.9, snaps), 736);
  assert.equal(settle(100, 0, snaps), null, 'dragged below half the lowest snap closes');
  assert.equal(settle(320, 0.9, snaps), null, 'flinging down from the lowest snap closes');
});

// ── mobile shell threshold ─────────────────────────────────────────────────

test('the mobile shell threshold is one named constant', () => {
  assert.equal(MOBILE_SHELL_MAX_WIDTH, 860);
  assert.equal(isMobileShellWidth(390), true);
  assert.equal(isMobileShellWidth(860), true);
  assert.equal(isMobileShellWidth(861), false);
  assert.equal(isMobileShellWidth(NaN), false);
  assert.equal(isMobileShellWidth(undefined), false);
});
