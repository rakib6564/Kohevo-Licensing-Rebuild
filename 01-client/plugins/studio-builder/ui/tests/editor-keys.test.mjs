// Editor plumbing from the 2026-10-26 audit: shortcuts (also from the canvas frame), the Layers tree's arrow keys,
// the canvas repaint while typing, and the edit lock's lifetime.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { shortcutFor, isTyping } from '../src/core/shortcuts.mjs';
import { treeArrow } from '../src/core/treeKeys.mjs';
import { repaintNext } from '../src/core/canvasMorph.mjs';
import { EditLock } from '../src/core/lock.mjs';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

const key = (k, extra = {}) => ({ key: k, target: { tagName: 'DIV', isContentEditable: false }, ...extra });

test('shortcuts: save, undo, redo, duplicate, structure and Escape', () => {
  assert.equal(shortcutFor(key('s', { ctrlKey: true })), 'save');
  assert.equal(shortcutFor(key('S', { metaKey: true, shiftKey: true })), 'save');
  assert.equal(shortcutFor(key('z', { metaKey: true })), 'undo');
  assert.equal(shortcutFor(key('z', { ctrlKey: true, shiftKey: true })), 'redo');
  assert.equal(shortcutFor(key('y', { ctrlKey: true })), 'redo');
  assert.equal(shortcutFor(key('i', { ctrlKey: true })), 'structure');
  assert.equal(shortcutFor(key('d', { ctrlKey: true })), null, 'nothing to duplicate');
  assert.equal(shortcutFor(key('d', { ctrlKey: true }), { hasSelection: true }), 'duplicate');
  assert.equal(shortcutFor(key('Escape'), { hasSelection: true }), 'deselect');
  assert.equal(shortcutFor(key('Escape')), null);
  assert.equal(shortcutFor(key('z')), null, 'a plain letter is not a shortcut');
});

test('shortcuts: while typing only Save works (fields and text edited on the canvas)', () => {
  const field = { tagName: 'INPUT' };
  const inline = { tagName: 'H2', isContentEditable: true };
  assert.equal(isTyping(field), true);
  assert.equal(isTyping(inline), true);
  assert.equal(shortcutFor(key('z', { ctrlKey: true, target: field })), null);
  assert.equal(shortcutFor(key('Escape', { target: inline }), { hasSelection: true }), null);
  assert.equal(shortcutFor(key('s', { ctrlKey: true, target: inline })), 'save');
});

test('shortcuts: a key from the canvas frame (another document) is read the same way', () => {
  // The frame's own elements: no instanceof checks, only tagName / isContentEditable.
  const frameBody = { tagName: 'BODY', isContentEditable: false, ownerDocument: { defaultView: {} } };
  assert.equal(shortcutFor({ key: 'z', metaKey: true, target: frameBody }), 'undo');
  assert.equal(shortcutFor({ key: 'd', ctrlKey: true, target: frameBody }, { hasSelection: true }), 'duplicate');
});

test('Layers tree: plain ArrowRight expands then enters, ArrowLeft collapses then goes up', () => {
  const rows = [
    { id: 's1', parentId: null, hasChildren: true },
    { id: 'b1', parentId: 's1', hasChildren: true },
    { id: 'b2', parentId: 'b1', hasChildren: false },
  ];
  assert.deepEqual(treeArrow('ArrowRight', rows[0], new Set(['s1']), rows.slice(0, 1)), { expand: 's1' });
  assert.deepEqual(treeArrow('ArrowRight', rows[0], new Set(), rows), { focus: 'b1' });
  assert.equal(treeArrow('ArrowRight', rows[2], new Set(), rows), null, 'a leaf has nothing to open');
  assert.deepEqual(treeArrow('ArrowLeft', rows[1], new Set(), rows), { collapse: 'b1' });
  assert.deepEqual(treeArrow('ArrowLeft', rows[1], new Set(['b1']), rows), { focus: 's1' });
  assert.deepEqual(treeArrow('ArrowLeft', rows[2], new Set(), rows), { focus: 'b1' });
  assert.equal(treeArrow('ArrowLeft', { id: 's1', parentId: null, hasChildren: true }, new Set(['s1']), rows), null);
});

test('canvas repaint: typing on the canvas waits for as long as it lasts, never reloads the frame', () => {
  assert.equal(repaintNext('busy'), 'wait');
  assert.equal(repaintNext(true), 'done');
  assert.equal(repaintNext(false), 'reload');
});

test('no placeholder actions or tenant-specific code ship in the editor', () => {
  const src = (p) => readFileSync(join(here, '..', 'src', p), 'utf8');
  const more = src('components/MoreBottomSheet.jsx');
  assert.doesNotMatch(more, /\balert\(|window\.confirm\(/, 'every More card runs a real action');
  assert.doesNotMatch(more, /action: \(\) => \{ onClose\(\); \}/, 'no card that only closes the sheet');
  assert.doesNotMatch(src('core/canvasLiveSync.mjs'), /rakib|hasan/i);
});

test('edit lock: stopped before the first acquire answered, it never starts a heartbeat and gives the lock back', async () => {
  const calls = [];
  let intervals = 0;
  let answer;
  const transport = {
    lockAcquire: () => new Promise((r) => { answer = r; }),
    lockRefresh: async () => ({ ok: true, data: { lock: {} } }),
    lockRelease: async (p, tk) => { calls.push(['release', p, tk]); return { ok: true, data: {} }; },
  };
  const lock = new EditLock({ transport, pageId: 3, setIntervalImpl: () => { intervals += 1; return intervals; }, clearIntervalImpl: () => {} });
  const started = lock.start();
  lock.stop();
  answer({ ok: true, data: { lock: { held: true, lock_token: 'late', other_editor: false } } });
  await started;
  assert.equal(intervals, 0, 'no heartbeat left running');
  assert.deepEqual(calls, [['release', 3, 'late']], 'the lock granted meanwhile is released');
});
